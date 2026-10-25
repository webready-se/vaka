<?php

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
    $summary = run_uptime($db, $config, $now);
    cleanup($db, $config, $now);
    set_meta($db, 'last_run_at', utc_now($now));
    $summary['heartbeat'] = $config['heartbeat_url'] === '' ? null : ping_heartbeat($config['heartbeat_url'], user_agent($config));
    return $summary;
}

/** Sites whose uptime check is due, with their stored uptime state. */
function due_uptime_sites(PDO $db, string $now): array
{
    $stmt = $db->prepare("SELECT s.*, c.status AS state_status, c.summary AS state_summary, c.details AS state_details,
            c.fail_count AS state_fail_count, c.changed_at AS state_changed_at
        FROM sites s
        LEFT JOIN check_state c ON c.site_id = s.id AND c.check_name = 'uptime'
        WHERE s.paused = 0
            AND (',' || s.disabled_checks || ',') NOT LIKE '%,uptime,%'
            AND (c.next_at IS NULL OR c.next_at <= ?)
        ORDER BY s.id");
    $stmt->execute([$now]);
    return $stmt->fetchAll();
}

/** Check every due site in parallel and store what was found. */
function run_uptime(PDO $db, array $config, int $now): array
{
    $summary = ['checked' => 0, 'up' => 0, 'down' => 0, 'failing' => 0, 'events' => []];
    $sites = due_uptime_sites($db, utc_now($now));
    if (!$sites) return $summary;

    $responses = fetch_many(array_column($sites, 'url', 'id'), $config['uptime_timeout'], user_agent($config));
    $checkedAt = utc_now($now);

    $db->beginTransaction();
    $sample = $db->prepare('INSERT INTO samples (site_id, checked_at, status_code, ms, ok, ip) VALUES (?, ?, ?, ?, ?, ?)');
    $where = $db->prepare('UPDATE sites SET ip = ?, cdn = ? WHERE id = ?');
    foreach ($sites as $site) {
        $result = evaluate_uptime($responses[$site['id']], $site['keyword'], $config['uptime_timeout']);
        $state = $site['state_status'] === null
            ? ['status' => 'pending', 'fail_count' => 0, 'changed_at' => null, 'details' => []]
            : ['status' => $site['state_status'], 'fail_count' => $site['state_fail_count'], 'changed_at' => $site['state_changed_at'], 'details' => json_decode($site['state_details'], true) ?: []];
        $next = next_uptime_state($state, $result, $config['down_after_failures'], $checkedAt);

        // A failing site is checked again on the next run, a healthy one after its interval
        $wait = $next['state']['fail_count'] > 0 ? 60 : site_interval($site, $config) * 60;
        save_check_state($db, $site['id'], 'uptime', $next['state'], $checkedAt, utc_now($now + $wait));
        $sample->execute([$site['id'], $checkedAt, $result['status_code'], $result['ms'], $result['ok'] ? 1 : 0, $result['ip']]);
        // Keep the last known IP when a request gets none (DNS failure), so a down server still groups its sites
        $where->execute([$result['ip'] !== '' ? $result['ip'] : $site['ip'], $result['cdn'], $site['id']]);

        if ($next['event']) {
            add_event($db, $site['id'], 'uptime', $next['event'], $checkedAt);
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

    return $summary;
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

function add_event(PDO $db, int $siteId, string $check, array $event, string $createdAt): void
{
    $db->prepare('INSERT INTO events (site_id, check_name, status, previous, message, created_at) VALUES (?, ?, ?, ?, ?, ?)')
        ->execute([$siteId, $check, $event['status'], $event['previous'], $event['message'], $createdAt]);
}

/** Once a day: drop uptime samples older than SAMPLES_RETENTION_DAYS. */
function cleanup(PDO $db, array $config, int $now): void
{
    $today = gmdate('Y-m-d', $now);
    if (get_meta($db, 'last_cleanup_on') === $today) return;
    $db->prepare('DELETE FROM samples WHERE checked_at < ?')->execute([utc_now($now - $config['samples_retention_days'] * 86400)]);
    set_meta($db, 'last_cleanup_on', $today);
}

/** Tell the dead man's switch this run happened. A failed ping never fails the run. */
function ping_heartbeat(string $url, string $userAgent): bool
{
    $response = fetch_many(['heartbeat' => $url], 5, $userAgent)['heartbeat'];
    return $response['errno'] === 0 && $response['status'] >= 200 && $response['status'] < 300;
}
