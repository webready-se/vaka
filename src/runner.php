<?php

/** Seconds a run may take before it stops starting slow checks (SSL, domains, headers, exposed files); what is left waits for the next run. */
const RUN_BUDGET_SECONDS = 40;

/**
 * Seconds a run started at $second should wait before checking: until
 * $runAt, past the burst of scheduled jobs that servers start at the top of
 * every minute. Someone running it by hand ($interactive) does not wait.
 */
function run_delay(int $second, int $runAt, bool $interactive): int
{
    return $interactive ? 0 : max(0, $runAt - $second);
}

/**
 * Run $fn while holding an exclusive lock on $lockFile, so cron runs never
 * overlap. Returns $fn's result, or null when another run holds the lock.
 */
function run_locked(string $lockFile, callable $fn): mixed
{
    $dir = dirname($lockFile);
    if (!is_dir($dir)) mkdir($dir, 0750, true);
    $handle = fopen($lockFile, 'c');
    if (!$handle) return null;
    if (!flock($handle, LOCK_EX | LOCK_NB)) {
        fclose($handle);
        return null;
    }
    try {
        return $fn();
    } finally {
        flock($handle, LOCK_UN);
        fclose($handle);
    }
}

/**
 * One run: every check that is due, then housekeeping, then the heartbeat.
 * $now is a Unix timestamp (tests pass their own clock).
 */
function run(PDO $db, array $config, ?int $now = null): array
{
    $now ??= time();
    $started = microtime(true);
    $summary = run_uptime($db, $config, $now);
    $summary['ssl'] = run_ssl($db, $config, $now, $started + RUN_BUDGET_SECONDS);
    $summary['domain'] = run_domain($db, $config, $now, $started + RUN_BUDGET_SECONDS);
    $summary['dns'] = run_dns($db, $config, $now, $started + RUN_BUDGET_SECONDS);
    $summary['headers'] = run_headers($db, $config, $now, $started + RUN_BUDGET_SECONDS);
    $summary['exposed'] = run_exposed($db, $config, $now, $started + RUN_BUDGET_SECONDS);
    $alerts = process_alerts($db, $config, $now);
    $summary['alerts'] = $alerts['alerts'];
    $summary['nowhere'] = $alerts['nowhere'];
    $summary['digests'] = send_digests($db, $config, $now);
    cleanup($db, $config, $now);
    set_meta($db, 'last_run_at', utc_now($now));
    $summary['heartbeat'] = $config['heartbeat_url'] === '' ? null : ping_heartbeat($config['heartbeat_url'], user_agent($config));
    return $summary;
}

/** Active sites with uptime checks on, with their stored uptime state, filtered by $where. */
function uptime_sites(PDO $db, string $where, array $params): array
{
    $stmt = $db->prepare("SELECT s.*, c.status AS state_status, c.summary AS state_summary, c.details AS state_details,
            c.fail_count AS state_fail_count, c.changed_at AS state_changed_at, c.snoozed_until AS snoozed_until
        FROM sites s
        LEFT JOIN check_state c ON c.site_id = s.id AND c.check_name = 'uptime'
        WHERE s.paused = 0
            AND (',' || s.disabled_checks || ',') NOT LIKE '%,uptime,%'
            AND ({$where})
        ORDER BY s.id");
    $stmt->execute($params);
    return $stmt->fetchAll();
}

/** Sites whose uptime check is due. */
function due_uptime_sites(PDO $db, string $now): array
{
    return uptime_sites($db, 'c.next_at IS NULL OR c.next_at <= ?', [$now]);
}

/**
 * The other sites on the servers of sites that just started failing. A
 * failure may be the first sign of a whole server going down; checking its
 * other sites right away confirms them in the same runs, so they end up in
 * one alert instead of trickling in over their intervals.
 */
function server_neighbours(PDO $db, array $failing, array $checkedIds): array
{
    $neighbours = [];
    foreach ($failing as $site) {
        $key = server_key($site);
        if ($key === null) continue;
        $rows = str_starts_with($key, 'label:')
            ? uptime_sites($db, 'lower(s.server) = ?', [substr($key, 6)])
            : uptime_sites($db, "s.ip = ? AND s.cdn = '' AND s.server = ''", [$site['ip']]);
        foreach ($rows as $row) {
            if (!in_array($row['id'], $checkedIds, true)) $neighbours[$row['id']] = $row;
        }
    }
    return array_values($neighbours);
}

/** Check every due site in parallel and store what was found. */
function run_uptime(PDO $db, array $config, int $now): array
{
    $summary = ['checked' => 0, 'up' => 0, 'down' => 0, 'failing' => 0, 'events' => []];
    $sites = due_uptime_sites($db, utc_now($now));
    if (!$sites) return $summary;

    $windows = load_windows($db, $now);
    $failing = check_uptime($db, $config, $sites, $now, $windows, $summary);
    $neighbours = server_neighbours($db, $failing, array_column($sites, 'id'));
    if ($neighbours) check_uptime($db, $config, $neighbours, $now, $windows, $summary);

    return $summary;
}

/**
 * Check $sites in parallel, store the results and add them to $summary.
 * Returns the sites that failed for the first time in a row, in a way that
 * could be their server's (see server_failure()).
 */
function check_uptime(PDO $db, array $config, array $sites, int $now, array $windows, array &$summary): array
{
    $responses = fetch_many(array_column($sites, 'url', 'id'), $config['uptime_timeout'], user_agent($config), 20, $config['ssl_ca_file']);
    $checkedAt = utc_now($now);
    $failing = [];

    $db->beginTransaction();
    $sample = $db->prepare('INSERT INTO samples (site_id, checked_at, status_code, ms, ok, ip) VALUES (?, ?, ?, ?, ?, ?)');
    $where = $db->prepare('UPDATE sites SET ip = ?, cdn = ? WHERE id = ?');
    foreach ($sites as $site) {
        $result = evaluate_uptime($responses[$site['id']], $site['keyword'], $config['uptime_timeout']);
        $state = $site['state_status'] === null
            ? ['status' => 'pending', 'fail_count' => 0, 'changed_at' => null, 'details' => []]
            : ['status' => $site['state_status'], 'fail_count' => $site['state_fail_count'], 'changed_at' => $site['state_changed_at'], 'details' => json_decode($site['state_details'], true) ?: []];
        $next = next_uptime_state($state, $result, $config['down_after_failures'], $checkedAt);

        // A failing site is checked again on the next run, a healthy one in its own minute of its interval
        $nextAt = $next['state']['fail_count'] > 0 ? $now + 60 : next_uptime_slot($site['id'], site_interval($site, $config), $now);
        save_check_state($db, $site['id'], 'uptime', $next['state'], $checkedAt, utc_now($nextAt));
        // A recovery ends a snooze
        if ($next['state']['status'] === 'ok' && $next['state']['fail_count'] === 0 && $site['snoozed_until'] !== null) {
            $db->prepare("UPDATE check_state SET snoozed_until = NULL WHERE site_id = ? AND check_name = 'uptime'")->execute([$site['id']]);
        }
        $sample->execute([$site['id'], $checkedAt, $result['status_code'], $result['ms'], $result['ok'] ? 1 : 0, $result['ip']]);
        // Keep the last known IP when a request gets none (DNS failure), so a down server still groups its sites
        $site['ip'] = $result['ip'] !== '' ? $result['ip'] : $site['ip'];
        $site['cdn'] = $result['cdn'];
        $where->execute([$site['ip'], $site['cdn'], $site['id']]);
        // The first failure in a row, and one that could be the server's: its other sites are checked too
        if ($next['state']['fail_count'] === 1 && server_failure($result)) $failing[] = $site;

        if ($next['event']) {
            add_event($db, $site['id'], 'uptime', $next['event'], $checkedAt, active_silence($site, $windows, $now, $config['timezone']));
            $summary['events'][] = ['url' => $site['url']] + $next['event'];
        }
        $summary['checked']++;
        match (true) {
            $next['state']['status'] === 'fail' => $summary['down']++,
            $next['state']['fail_count'] > 0 => $summary['failing']++,
            default => $summary['up']++,
        };
    }
    $db->commit();

    return $failing;
}

/**
 * HTTPS sites whose certificate check is due. A site that does not answer at
 * all (timeout, DNS, refused) is left out: the uptime check covers it and a
 * handshake would only wait for the timeout. One that is down because of its
 * certificate is checked, since that is what this check explains.
 */
function due_ssl_sites(PDO $db, string $now): array
{
    $stmt = $db->prepare("SELECT s.*, c.status AS state_status, c.changed_at AS state_changed_at, u.status AS uptime_status, u.details AS uptime_details
        FROM sites s
        LEFT JOIN check_state c ON c.site_id = s.id AND c.check_name = 'ssl'
        LEFT JOIN check_state u ON u.site_id = s.id AND u.check_name = 'uptime'
        WHERE s.paused = 0 AND s.url LIKE 'https://%'
            AND (',' || s.disabled_checks || ',') NOT LIKE '%,ssl,%'
            AND (c.next_at IS NULL OR c.next_at <= ?)
        ORDER BY c.next_at IS NOT NULL, c.next_at, s.id");
    $stmt->execute([$now]);
    return array_values(array_filter($stmt->fetchAll(), function (array $site): bool {
        if ($site['uptime_status'] !== 'fail') return true;
        $problem = json_decode((string) $site['uptime_details'], true)['problem'] ?? '';
        return !in_array($problem, ['timeout', 'dns', 'refused', 'connect'], true);
    }));
}

/**
 * Check due certificates one by one (a TLS handshake blocks) until $deadline
 * (microtime). The rest stays due for the next run.
 */
function run_ssl(PDO $db, array $config, int $now, float $deadline): array
{
    $summary = ['checked' => 0, 'left' => 0, 'events' => []];
    $sites = due_ssl_sites($db, utc_now($now));
    $checkedAt = utc_now($now);
    foreach ($sites as $i => $site) {
        if (microtime(true) >= $deadline) {
            $summary['left'] = count($sites) - $i;
            break;
        }
        $result = check_certificate($site['url'], $config, $now, $site['cdn'] ?: null);
        $previous = $site['state_status'] ?? 'pending';
        $changed = $previous !== $result['status'];
        save_check_state($db, $site['id'], 'ssl', [
            'status' => $result['status'],
            'summary' => $result['summary'],
            'details' => $result['details'],
            'fail_count' => 0,
            'changed_at' => $changed ? $checkedAt : $site['state_changed_at'],
        ], $checkedAt, utc_now($now + SSL_INTERVAL_SECONDS));
        if ($changed && $result['status'] !== 'unknown') {
            $event = ['status' => $result['status'], 'previous' => $previous, 'message' => $result['summary']];
            add_event($db, $site['id'], 'ssl', $event, $checkedAt);
            $summary['events'][] = ['url' => $site['url']] + $event;
        }
        $summary['checked']++;
    }
    return $summary;
}

/**
 * Sites whose exposed-file check is due. A site that is down is left out:
 * its answers would say nothing, and it is checked once it is back.
 */
function due_exposed_sites(PDO $db, string $now): array
{
    $stmt = $db->prepare("SELECT s.*, c.status AS state_status, c.summary AS state_summary, c.details AS state_details, c.changed_at AS state_changed_at
        FROM sites s
        LEFT JOIN check_state c ON c.site_id = s.id AND c.check_name = 'exposed'
        LEFT JOIN check_state u ON u.site_id = s.id AND u.check_name = 'uptime'
        WHERE s.paused = 0
            AND (',' || s.disabled_checks || ',') NOT LIKE '%,exposed,%'
            AND (c.next_at IS NULL OR c.next_at <= ?)
            AND (u.status IS NULL OR u.status != 'fail')
        ORDER BY c.next_at IS NOT NULL, c.next_at, s.id");
    $stmt->execute([$now]);
    return $stmt->fetchAll();
}

/**
 * Check due sites for exposed files, a few sites per parallel batch, until
 * $deadline (microtime). The rest stays due for the next run. A change of
 * status, or of which files are public, is recorded in the history.
 */
function run_exposed(PDO $db, array $config, int $now, float $deadline): array
{
    $summary = ['checked' => 0, 'left' => 0, 'events' => []];
    $sites = due_exposed_sites($db, utc_now($now));
    $checkedAt = utc_now($now);
    foreach (array_chunk($sites, EXPOSED_SITES_PER_BATCH) as $i => $batch) {
        if (microtime(true) >= $deadline) {
            $summary['left'] = count($sites) - $i * EXPOSED_SITES_PER_BATCH;
            break;
        }
        $results = check_exposed(array_column($batch, 'url', 'id'), $config);
        foreach ($batch as $site) {
            $result = $results[$site['id']];
            $previous = $site['state_status'] ?? 'pending';
            $before = array_column(json_decode((string) $site['state_details'], true)['found'] ?? [], 'label');
            $changed = $previous !== $result['status'];
            $wait = $result['status'] === 'ok' ? EXPOSED_INTERVAL_SECONDS : EXPOSED_RECHECK_SECONDS;
            save_check_state($db, $site['id'], 'exposed', [
                'status' => $result['status'],
                'summary' => $result['summary'],
                'details' => $result['details'],
                'fail_count' => 0,
                'changed_at' => $changed ? $checkedAt : $site['state_changed_at'],
            ], $checkedAt, utc_now($now + $wait));
            if ($result['status'] !== 'unknown' && ($changed || $before !== array_column($result['details']['found'], 'label'))) {
                $event = ['status' => $result['status'], 'previous' => $previous, 'message' => $result['summary']];
                add_event($db, $site['id'], 'exposed', $event, $checkedAt);
                $summary['events'][] = ['url' => $site['url']] + $event;
            }
            $summary['checked']++;
        }
    }
    return $summary;
}

/** Sites whose domain registration is due. Sites that are down are looked up too: an expired domain may be why. */
function due_domain_sites(PDO $db, string $now): array
{
    $stmt = $db->prepare("SELECT s.*, c.status AS state_status, c.changed_at AS state_changed_at
        FROM sites s
        LEFT JOIN check_state c ON c.site_id = s.id AND c.check_name = 'domain'
        WHERE s.paused = 0
            AND (',' || s.disabled_checks || ',') NOT LIKE '%,domain,%'
            AND (c.next_at IS NULL OR c.next_at <= ?)
        ORDER BY c.next_at IS NOT NULL, c.next_at, s.id");
    $stmt->execute([$now]);
    return $stmt->fetchAll();
}

/**
 * Look up due domains one by one (RDAP and WHOIS are single requests to a
 * registry) until $deadline (microtime). Sites that share a domain cause one
 * lookup. The rest stays due for the next run.
 */
function run_domain(PDO $db, array $config, int $now, float $deadline): array
{
    $summary = ['checked' => 0, 'left' => 0, 'events' => []];
    $sites = due_domain_sites($db, utc_now($now));
    $checkedAt = utc_now($now);
    $cache = [];
    foreach ($sites as $i => $site) {
        if (microtime(true) >= $deadline) {
            $summary['left'] = count($sites) - $i;
            break;
        }
        $lookup = lookup_domain((string) parse_url($site['url'], PHP_URL_HOST), $config, $now, $cache);
        $result = evaluate_domain($lookup, $now, max([0, ...$config['domain_alert_days']]));
        $previous = $site['state_status'] ?? 'pending';
        $changed = $previous !== $result['status'];
        $wait = $result['details']['problem'] === 'lookup_failed' ? DOMAIN_RETRY_SECONDS : DOMAIN_INTERVAL_SECONDS;
        save_check_state($db, $site['id'], 'domain', [
            'status' => $result['status'],
            'summary' => $result['summary'],
            'details' => $result['details'],
            'fail_count' => 0,
            'changed_at' => $changed ? $checkedAt : $site['state_changed_at'],
        ], $checkedAt, utc_now($now + $wait));
        if ($changed && $result['status'] !== 'unknown') {
            $event = ['status' => $result['status'], 'previous' => $previous, 'message' => $result['summary']];
            add_event($db, $site['id'], 'domain', $event, $checkedAt);
            $summary['events'][] = ['url' => $site['url']] + $event;
        }
        $summary['checked']++;
    }
    return $summary;
}

/**
 * Active sites with mail DNS on, by the domain whose mail records they
 * share (see mail_domain()); a site without a domain stands alone. Sites
 * that are down are looked up too: mail does not depend on the web server.
 *
 * @return array<string, list<array>> domain (or "site:<id>") => its sites, each with `mail_domain`
 */
function dns_sites_by_domain(PDO $db): array
{
    $sites = $db->query("SELECT s.*, c.status AS state_status, c.details AS state_details, c.changed_at AS state_changed_at, c.next_at AS state_next_at,
            d.status AS domain_status, d.details AS domain_details
        FROM sites s
        LEFT JOIN check_state c ON c.site_id = s.id AND c.check_name = 'dns'
        LEFT JOIN check_state d ON d.site_id = s.id AND d.check_name = 'domain'
        WHERE s.paused = 0 AND (',' || s.disabled_checks || ',') NOT LIKE '%,dns,%'
        ORDER BY c.next_at IS NOT NULL, c.next_at, s.id")->fetchAll();
    $byDomain = [];
    foreach ($sites as $site) {
        // The domain check's domain counts only when the registry answered
        $checked = in_array($site['domain_status'], ['ok', 'warn', 'fail'], true) ? (json_decode((string) $site['domain_details'], true)['domain'] ?? null) : null;
        $site['mail_domain'] = mail_domain($site['url'], $checked, json_decode((string) $site['state_details'], true)['confirmed_domain'] ?? null);
        $byDomain[$site['mail_domain'] ?? 'site:' . $site['id']][] = $site;
    }
    return $byDomain;
}

/**
 * Read due domains' mail records (MX, SPF, DMARC) one domain at a time
 * until $deadline (microtime). A domain is due when one of its sites is,
 * and then all its sites are looked at together, so they stay in step and
 * a change is one alert. A change is looked at again before it counts
 * (see confirm_mail_dns()); a confirmed one is recorded in the history.
 * $config['dns_resolve'] replaces the resolver in tests.
 */
function run_dns(PDO $db, array $config, int $now, float $deadline): array
{
    $summary = ['checked' => 0, 'left' => 0, 'events' => []];
    $checkedAt = utc_now($now);
    $resolve = $config['dns_resolve'] ?? 'dns_lookup';
    $due = array_values(array_filter(dns_sites_by_domain($db), fn (array $sites) => (bool) array_filter($sites, fn (array $site) => $site['state_next_at'] === null || $site['state_next_at'] <= $checkedAt)));
    foreach ($due as $i => $sites) {
        if (microtime(true) >= $deadline) {
            $summary['left'] = array_sum(array_map('count', array_slice($due, $i)));
            break;
        }
        $domain = $sites[0]['mail_domain'];
        $result = evaluate_mail_dns($domain, $domain === null ? null : lookup_mail_dns($domain, $resolve));
        foreach ($sites as $site) $summary = dns_site_result($db, $site, $result, $now, $summary);
    }
    return $summary;
}

/** Store one site's mail DNS result, and record a confirmed change in its history. */
function dns_site_result(PDO $db, array $site, array $result, int $now, array $summary): array
{
    $checkedAt = utc_now($now);
    $before = $site['state_status'] === null ? null : (json_decode((string) $site['state_details'], true) ?: []);
    $next = confirm_mail_dns($before, $result);
    save_check_state($db, $site['id'], 'dns', [
        'status' => $result['status'],
        'summary' => $result['summary'],
        'details' => $next['details'],
        'fail_count' => 0,
        'changed_at' => $next['change'] !== null ? $checkedAt : $site['state_changed_at'],
    ], $checkedAt, utc_now($now + $next['wait']));
    if ($next['change'] !== null) {
        $event = ['status' => $result['status'], 'previous' => $site['state_status'] ?? 'pending', 'message' => $next['change']];
        add_event($db, $site['id'], 'dns', $event, $checkedAt);
        $summary['events'][] = ['url' => $site['url']] + $event;
    }
    $summary['checked']++;
    return $summary;
}

/** Sites whose security headers are due. A site that is down is left out: its headers would be an error page's. */
function due_headers_sites(PDO $db, string $now): array
{
    $stmt = $db->prepare("SELECT s.*, c.status AS state_status, c.details AS state_details, c.changed_at AS state_changed_at
        FROM sites s
        LEFT JOIN check_state c ON c.site_id = s.id AND c.check_name = 'headers'
        LEFT JOIN check_state u ON u.site_id = s.id AND u.check_name = 'uptime'
        WHERE s.paused = 0
            AND (',' || s.disabled_checks || ',') NOT LIKE '%,headers,%'
            AND (c.next_at IS NULL OR c.next_at <= ?)
            AND (u.status IS NULL OR u.status != 'fail')
        ORDER BY c.next_at IS NOT NULL, c.next_at, s.id");
    $stmt->execute([$now]);
    return $stmt->fetchAll();
}

/**
 * Grade due sites' security headers, in parallel batches, until $deadline
 * (microtime). A change is looked at again before it counts (see
 * confirm_headers()); a confirmed one is recorded in the history.
 */
function run_headers(PDO $db, array $config, int $now, float $deadline): array
{
    $summary = ['checked' => 0, 'left' => 0, 'events' => []];
    $sites = due_headers_sites($db, utc_now($now));
    $checkedAt = utc_now($now);
    foreach (array_chunk($sites, HEADERS_SITES_PER_BATCH) as $i => $batch) {
        if (microtime(true) >= $deadline) {
            $summary['left'] = count($sites) - $i * HEADERS_SITES_PER_BATCH;
            break;
        }
        $responses = fetch_many(array_column($batch, 'url', 'id'), $config['uptime_timeout'], user_agent($config), 20, $config['ssl_ca_file']);
        $files = fetch_files_nosniff($responses, $config);
        foreach ($batch as $site) {
            $result = evaluate_headers($responses[$site['id']], $config['uptime_timeout'], $files[$site['id']] ?? null);
            $before = $site['state_status'] === null ? null : (json_decode((string) $site['state_details'], true) ?: []);
            $next = confirm_headers($before, $result);
            save_check_state($db, $site['id'], 'headers', [
                'status' => $result['status'],
                'summary' => $result['summary'],
                'details' => $next['details'],
                'fail_count' => 0,
                'changed_at' => $next['change'] !== null ? $checkedAt : $site['state_changed_at'],
            ], $checkedAt, utc_now($now + $next['wait']));
            if ($next['change'] !== null) {
                $event = ['status' => $result['status'], 'previous' => $site['state_status'] ?? 'pending', 'message' => $next['change']];
                add_event($db, $site['id'], 'headers', $event, $checkedAt);
                $summary['events'][] = ['url' => $site['url']] + $event;
            }
            $summary['checked']++;
        }
    }
    return $summary;
}

/**
 * "Check again", after a fix: every check of a site comes due, so the next
 * run (within a minute) looks again. Nothing else changes: a change in the
 * headers or mail records still counts after its second look, as always.
 */
function queue_recheck(PDO $db, int $siteId, int $now): void
{
    $db->prepare('UPDATE check_state SET next_at = ? WHERE site_id = ? AND next_at > ?')->execute([utc_now($now), $siteId, utc_now($now)]);
}

function save_check_state(PDO $db, int $siteId, string $check, array $state, string $checkedAt, string $nextAt): void
{
    $db->prepare('INSERT INTO check_state (site_id, check_name, status, summary, details, fail_count, checked_at, next_at, changed_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ON CONFLICT (site_id, check_name) DO UPDATE SET
            status = excluded.status, summary = excluded.summary, details = excluded.details,
            fail_count = excluded.fail_count, checked_at = excluded.checked_at,
            next_at = excluded.next_at, changed_at = excluded.changed_at')
        ->execute([$siteId, $check, $state['status'], $state['summary'], json_encode($state['details']), $state['fail_count'], $checkedAt, $nextAt, $state['changed_at']]);
}

/** Record a change in a site's history; $silencedBy says whether it happened during maintenance or a snooze. */
function add_event(PDO $db, int $siteId, string $check, array $event, string $createdAt, string $silencedBy = ''): void
{
    $db->prepare('INSERT INTO events (site_id, check_name, status, previous, message, silenced_by, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)')
        ->execute([$siteId, $check, $event['status'], $event['previous'], $event['message'], $silencedBy, $createdAt]);
}

/** Once a day: drop uptime samples older than SAMPLES_RETENTION_DAYS and login records older than 30 days. */
function cleanup(PDO $db, array $config, int $now): void
{
    $today = gmdate('Y-m-d', $now);
    if (get_meta($db, 'last_cleanup_on') === $today) return;
    $db->prepare('DELETE FROM samples WHERE checked_at < ?')->execute([utc_now($now - $config['samples_retention_days'] * 86400)]);
    $db->prepare('DELETE FROM audit_log WHERE created_at < ?')->execute([utc_now($now - 30 * 86400)]);
    // Sessions no one has used for as long as one lasts: PHP's own clean-up is
    // off on many servers (Debian and Ubuntu leave it to a cron job that only
    // knows PHP's default directory), and a replaced id leaves a small file
    foreach (glob(dirname($config['db_path']) . '/sessions/sess_*') ?: [] as $file) {
        if (filemtime($file) < $now - $config['session_lifetime']) unlink($file);
    }
    set_meta($db, 'last_cleanup_on', $today);
}

/** Tell the dead man's switch this run happened. A failed ping never fails the run. */
function ping_heartbeat(string $url, string $userAgent): bool
{
    $response = fetch_many(['heartbeat' => $url], 5, $userAgent)['heartbeat'];
    return $response['errno'] === 0 && $response['status'] >= 200 && $response['status'] < 300;
}
