<?php

/** A second outage within this many minutes makes a site unstable (flapping). */
const FLAP_WINDOW_MINUTES = 30;
const FLAP_OUTAGES = 2;

/** An unstable site is announced stable, or still down, after this long without a change. */
const STABLE_MINUTES = 15;

/** Sites named in one alert before "and N more". */
const ALERT_SITE_LIST = 15;

// =====================================================================
// Servers
// =====================================================================

/**
 * Which server a site is on, for folding alerts together: a server set by
 * hand, else the IP it answered on. A site behind a CDN shares the CDN's
 * IPs with unrelated sites, so it stands alone (null), as does a site
 * whose IP is not known yet.
 */
function server_key(array $site): ?string
{
    if ($site['server'] !== '') return 'label:' . strtolower($site['server']);
    if ($site['cdn'] !== '' || $site['ip'] === '') return null;
    return 'ip:' . $site['ip'];
}

/** A server as people read it: "web-1 (203.0.113.10)", or the IP or hand-set name alone. */
function server_display(array $site, array $labels): string
{
    if ($site['server'] !== '') return $site['server'];
    $label = $labels[$site['ip']] ?? '';
    return $label !== '' ? "{$label} ({$site['ip']})" : $site['ip'];
}

/** @return array<string, string> ip => label */
function server_labels(PDO $db): array
{
    return array_column($db->query("SELECT ip, label FROM servers WHERE label != ''")->fetchAll(), 'label', 'ip');
}

/** How many active sites each server has, to tell "all 10" from "3 of 10". */
function server_sizes(PDO $db): array
{
    $sizes = [];
    foreach ($db->query('SELECT ip, cdn, server FROM sites WHERE paused = 0')->fetchAll() as $site) {
        $key = server_key($site);
        if ($key !== null) $sizes[$key] = ($sizes[$key] ?? 0) + 1;
    }
    return $sizes;
}

// =====================================================================
// When to alert
// =====================================================================

/**
 * What the group policy says about a check whose status differs from what
 * was last sent: send, wait (delayed policy), quiet (digest or silent:
 * record it as told without sending), baseline (a first check that is up)
 * or none.
 */
function alert_decision(array $state, ?array $group, int $now): string
{
    if ($state['status'] === 'pending' || $state['status'] === $state['alerted_status']) return 'none';
    if ($state['alerted_status'] === 'pending' && $state['status'] === 'ok') return 'baseline';

    $policy = $group['policy'] ?? 'immediate';
    if ($policy === 'silent' || $policy === 'digest') return 'quiet';
    if ($policy === 'delayed' && $state['status'] === 'fail' && $now - strtotime($state['changed_at']) < $group['delay_minutes'] * 60) {
        return 'wait';
    }
    return 'send';
}

/**
 * The notice to send for a check, with flapping taken into account. A
 * second outage within FLAP_WINDOW_MINUTES sends one "unstable" notice
 * instead of "down"; after that nothing is sent until the check has gone
 * STABLE_MINUTES without a change, which sends "stable" (or "down" when it
 * settled down).
 *
 * @return array{action: 'send'|'mark'|'none', kind: ?string}
 */
function notice_for(array $state, string $decision, int $recentOutages, int $now): array
{
    if ($decision === 'baseline' || $decision === 'quiet') return ['action' => 'mark', 'kind' => null];
    if ($decision === 'send' && $state['status'] === 'fail' && ($state['alerted_status'] ?? '') === 'snoozed') {
        return ['action' => 'send', 'kind' => 'still_down'];
    }

    if ($state['flapping_since'] !== null) {
        if ($now - strtotime($state['changed_at']) < STABLE_MINUTES * 60) return ['action' => 'none', 'kind' => null];
        return ['action' => 'send', 'kind' => $state['status'] === 'fail' ? 'down' : 'stable'];
    }

    if ($decision !== 'send') return ['action' => 'none', 'kind' => null];
    if ($state['status'] === 'fail') {
        return ['action' => 'send', 'kind' => $recentOutages >= FLAP_OUTAGES ? 'unstable' : 'down'];
    }
    return ['action' => 'send', 'kind' => 'up'];
}

/**
 * Whether a site that is still down is due a reminder: it was told it is
 * down at least $hours ago, and nothing changed since. Snoozed and unstable
 * sites and groups without live alerts get none; 0 hours turns them off.
 */
function reminder_due(array $state, ?array $group, int $hours, int $now): bool
{
    if ($hours <= 0 || $state['status'] !== 'fail' || $state['alerted_status'] !== 'fail' || $state['flapping_since'] !== null) return false;
    if (in_array($group['policy'] ?? 'immediate', ['digest', 'silent'], true)) return false;
    return $now - strtotime($state['alerted_at']) >= $hours * 3600;
}

/** Outages (changes to down) of a check within the flapping window. */
function recent_outages(PDO $db, int $siteId, string $check, int $now): int
{
    // Outages during maintenance or a snooze were expected, so they do not count
    $stmt = $db->prepare("SELECT COUNT(*) FROM events WHERE site_id = ? AND check_name = ? AND status = 'fail' AND silenced_by = '' AND created_at >= ?");
    $stmt->execute([$siteId, $check, utc_now($now - FLAP_WINDOW_MINUTES * 60)]);
    return (int) $stmt->fetchColumn();
}

/** Where a group's alerts go: its own destinations, or the defaults in .env. */
function alert_destinations(?array $group, array $config): array
{
    $own = $group ? destination_list($group['slack_webhook'], $group['webhook_url'], $group['email'] === '' ? [] : explode(', ', $group['email'])) : [];
    return $own ?: destination_list($config['slack_webhook_url'], $config['webhook_url'], $config['alert_email']);
}

function destination_list(string $slack, string $webhook, array $emails): array
{
    $list = [];
    if ($slack !== '') $list[] = ['type' => 'slack', 'target' => $slack, 'key' => "slack:{$slack}"];
    if ($webhook !== '') $list[] = ['type' => 'webhook', 'target' => $webhook, 'key' => "webhook:{$webhook}"];
    if ($emails) $list[] = ['type' => 'email', 'target' => $emails, 'key' => 'email:' . implode(',', $emails)];
    return $list;
}

/** Whether alerts can go anywhere yet: a default destination in .env, or a group with its own. */
function alerts_configured(PDO $db, array $config): bool
{
    if (destination_list($config['slack_webhook_url'], $config['webhook_url'], $config['alert_email'])) return true;
    return (bool) $db->query("SELECT 1 FROM groups WHERE slack_webhook != '' OR webhook_url != '' OR email != '' LIMIT 1")->fetchColumn();
}

/** A destination in words, secrets masked. */
function describe_destination(array $destination): string
{
    return match ($destination['type']) {
        'slack' => 'Slack ' . mask_secret($destination['target']),
        'webhook' => 'webhook ' . mask_secret($destination['target']),
        default => implode(', ', $destination['target']),
    };
}

// =====================================================================
// Reconcile
// =====================================================================

/**
 * Compare what was last sent with the current state of every uptime check
 * and build the notices that are due. Checks that need no message (a first
 * check that is up, quiet policies, nowhere to send) are marked as told
 * right here.
 *
 * @return array{notices: list<array>, nowhere: int}
 */
function collect_notices(PDO $db, array $config, int $now): array
{
    $rows = $db->query("SELECT c.*, s.url, s.ip, s.cdn, s.server, s.group_id, g.name AS group_name,
            g.policy, g.delay_minutes, g.slack_webhook, g.webhook_url, g.email
        FROM check_state c
        JOIN sites s ON s.id = c.site_id
        LEFT JOIN groups g ON g.id = s.group_id
        WHERE c.check_name = 'uptime' AND s.paused = 0 AND c.status IN ('ok', 'fail')
            AND (c.status != c.alerted_status OR c.flapping_since IS NOT NULL OR c.status = 'fail')
        ORDER BY s.id")->fetchAll();
    $labels = server_labels($db);
    $windows = load_windows($db, $now);
    $notices = [];
    $nowhere = 0;

    foreach ($rows as $row) {
        // Maintenance or a snooze: nothing now; what still differs when it ends is sent then
        $site = ['id' => $row['site_id'], 'group_id' => $row['group_id'], 'snoozed_until' => $row['snoozed_until']];
        if (active_silence($site, $windows, $now, $config['timezone']) !== '') continue;

        $group = $row['group_id'] === null ? null : $row;
        $decision = alert_decision($row, $group, $now);
        if ($row['flapping_since'] !== null && in_array($group['policy'] ?? '', ['silent', 'digest'], true)) $decision = 'quiet';
        $outages = $decision === 'send' && $row['status'] === 'fail' && $row['flapping_since'] === null
            ? recent_outages($db, $row['site_id'], 'uptime', $now)
            : 0;
        $notice = reminder_due($row, $group, $config['remind_every_hours'], $now)
            ? ['action' => 'send', 'kind' => 'reminder']
            : notice_for($row, $decision, $outages, $now);

        if ($notice['action'] === 'mark') {
            mark_alerted($db, $row['site_id'], 'uptime', $row['status'], null, $now);
            continue;
        }
        if ($notice['action'] === 'none') continue;

        $destinations = alert_destinations($group, $config);
        if (!$destinations) {
            mark_alerted($db, $row['site_id'], 'uptime', $row['status'], null, $now);
            $nowhere++;
            continue;
        }
        $key = server_key($row);
        $notices[] = [
            'site_id' => $row['site_id'],
            'check' => 'uptime',
            'status' => $row['status'],
            'url' => $row['url'],
            'group' => $row['group_name'],
            'kind' => $notice['kind'],
            'summary' => $row['summary'],
            'since' => $row['changed_at'],
            'down_seconds' => $notice['kind'] === 'reminder' ? $now - strtotime($row['changed_at']) : json_decode($row['details'], true)['down_seconds'] ?? 0,
            'outages' => $outages,
            'remind_hours' => $config['remind_every_hours'],
            'server_key' => $key,
            'server' => $key === null ? null : server_display($row, $labels),
            'destinations' => $destinations,
        ];
    }
    return ['notices' => $notices, 'nowhere' => $nowhere];
}

function mark_alerted(PDO $db, int $siteId, string $check, string $status, ?string $flappingSince, int $now, ?string $thresholds = null, ?string $snapshot = null): void
{
    $db->prepare('UPDATE check_state SET alerted_status = ?, alerted_at = ?, flapping_since = ?, thresholds_sent = COALESCE(?, thresholds_sent),
            alerted_snapshot = COALESCE(?, alerted_snapshot) WHERE site_id = ? AND check_name = ?')
        ->execute([$status, utc_now($now), $flappingSince, $thresholds, $snapshot, $siteId, $check]);
}

/**
 * Certificate notices that are due (see ssl_notice()). Maintenance windows
 * hold them back like any other alert; a snooze is for outages only.
 *
 * @return array{notices: list<array>, nowhere: int}
 */
function collect_ssl_notices(PDO $db, array $config, int $now): array
{
    $rows = $db->query("SELECT c.*, s.url, s.group_id, g.name AS group_name, g.policy, g.slack_webhook, g.webhook_url, g.email
        FROM check_state c
        JOIN sites s ON s.id = c.site_id
        LEFT JOIN groups g ON g.id = s.group_id
        WHERE c.check_name = 'ssl' AND s.paused = 0 AND c.status IN ('ok', 'warn', 'fail')
        ORDER BY s.id")->fetchAll();
    $windows = load_windows($db, $now);
    $notices = [];
    $nowhere = 0;

    foreach ($rows as $row) {
        if (active_silence(['id' => $row['site_id'], 'group_id' => $row['group_id'], 'snoozed_until' => null], $windows, $now, $config['timezone']) !== '') continue;
        $group = $row['group_id'] === null ? null : $row;
        $details = json_decode($row['details'], true) ?: [];
        $notice = ssl_notice(['details' => $details] + $row, $group, $config['ssl_alert_days']);
        if ($notice['action'] === 'none') continue;

        $destinations = $notice['action'] === 'send' ? alert_destinations($group, $config) : [];
        if (!$destinations) {
            mark_alerted($db, $row['site_id'], 'ssl', $row['status'], null, $now, $notice['thresholds']);
            if ($notice['action'] === 'send') $nowhere++;
            continue;
        }
        $notices[] = [
            'site_id' => $row['site_id'],
            'check' => 'ssl',
            'status' => $row['status'],
            'url' => $row['url'],
            'group' => $row['group_name'],
            'kind' => $notice['kind'],
            'summary' => $row['summary'],
            'since' => $row['changed_at'],
            'down_seconds' => 0,
            'outages' => 0,
            'days_left' => $details['days_left'] ?? null,
            'thresholds' => $notice['thresholds'],
            'server_key' => null,
            'server' => null,
            'destinations' => $destinations,
        ];
    }
    return ['notices' => $notices, 'nowhere' => $nowhere];
}

/**
 * Domain notices that are due (see domain_notice()). Sites that share a
 * domain are one notice per group: the registration is the same.
 * Maintenance windows hold them back like any other alert.
 *
 * @return array{notices: list<array>, nowhere: int}
 */
function collect_domain_notices(PDO $db, array $config, int $now): array
{
    $rows = $db->query("SELECT c.*, s.url, s.group_id, g.name AS group_name, g.policy, g.slack_webhook, g.webhook_url, g.email
        FROM check_state c
        JOIN sites s ON s.id = c.site_id
        LEFT JOIN groups g ON g.id = s.group_id
        WHERE c.check_name = 'domain' AND s.paused = 0 AND c.status IN ('ok', 'warn', 'fail')
        ORDER BY s.id")->fetchAll();
    $windows = load_windows($db, $now);
    $notices = [];
    $byDomain = [];
    $nowhere = 0;

    foreach ($rows as $row) {
        if (active_silence(['id' => $row['site_id'], 'group_id' => $row['group_id'], 'snoozed_until' => null], $windows, $now, $config['timezone']) !== '') continue;
        $group = $row['group_id'] === null ? null : $row;
        $details = json_decode($row['details'], true) ?: [];
        $notice = domain_notice(['details' => $details] + $row, $group, $config['domain_alert_days']);
        if ($notice['action'] === 'none') continue;

        $destinations = $notice['action'] === 'send' ? alert_destinations($group, $config) : [];
        if (!$destinations) {
            mark_alerted($db, $row['site_id'], 'domain', $row['status'], null, $now, $notice['thresholds']);
            if ($notice['action'] === 'send') $nowhere++;
            continue;
        }
        $key = ($row['group_id'] ?? 'none') . '|' . ($details['domain'] ?? '') . '|' . $notice['kind'];
        if (isset($byDomain[$key])) {
            $notices[$byDomain[$key]]['also'][] = ['site_id' => $row['site_id'], 'thresholds' => $notice['thresholds']];
            continue;
        }
        $byDomain[$key] = count($notices);
        $notices[] = [
            'site_id' => $row['site_id'],
            'check' => 'domain',
            'status' => $row['status'],
            'url' => $row['url'],
            'domain' => $details['domain'] ?? '',
            'group' => $row['group_name'],
            'kind' => $notice['kind'],
            'summary' => $row['summary'],
            'since' => $row['changed_at'],
            'down_seconds' => 0,
            'outages' => 0,
            'days_left' => $details['days_left'] ?? null,
            'thresholds' => $notice['thresholds'],
            'also' => [],
            'server_key' => null,
            'server' => null,
            'destinations' => $destinations,
        ];
    }
    return ['notices' => $notices, 'nowhere' => $nowhere];
}

/**
 * Exposed-file notices that are due (see exposed_notice()). Maintenance
 * windows hold them back like any other alert; a snooze is for outages only.
 *
 * @return array{notices: list<array>, nowhere: int}
 */
function collect_exposed_notices(PDO $db, array $config, int $now): array
{
    $rows = $db->query("SELECT c.*, s.url, s.group_id, g.name AS group_name, g.policy, g.slack_webhook, g.webhook_url, g.email
        FROM check_state c
        JOIN sites s ON s.id = c.site_id
        LEFT JOIN groups g ON g.id = s.group_id
        WHERE c.check_name = 'exposed' AND s.paused = 0 AND c.status IN ('ok', 'warn', 'fail')
        ORDER BY s.id")->fetchAll();
    $windows = load_windows($db, $now);
    $notices = [];
    $nowhere = 0;

    foreach ($rows as $row) {
        if (active_silence(['id' => $row['site_id'], 'group_id' => $row['group_id'], 'snoozed_until' => null], $windows, $now, $config['timezone']) !== '') continue;
        $group = $row['group_id'] === null ? null : $row;
        $notice = exposed_notice(['details' => json_decode($row['details'], true) ?: []] + $row, $group);
        if ($notice['action'] === 'none') continue;

        $destinations = $notice['action'] === 'send' ? alert_destinations($group, $config) : [];
        if (!$destinations) {
            mark_alerted($db, $row['site_id'], 'exposed', $row['status'], null, $now, null, $notice['snapshot']);
            if ($notice['action'] === 'send') $nowhere++;
            continue;
        }
        $notices[] = [
            'site_id' => $row['site_id'],
            'check' => 'exposed',
            'status' => $row['status'],
            'url' => $row['url'],
            'group' => $row['group_name'],
            'kind' => $notice['kind'],
            'summary' => $row['summary'],
            'since' => $row['changed_at'],
            'down_seconds' => 0,
            'outages' => 0,
            'new' => $notice['new'],
            'still' => $notice['still'],
            'snapshot' => $notice['snapshot'],
            'server_key' => null,
            'server' => null,
            'destinations' => $destinations,
        ];
    }
    return ['notices' => $notices, 'nowhere' => $nowhere];
}

/**
 * Security header notices that are due (see headers_notice()). Maintenance
 * windows hold them back like any other alert; a snooze is for outages only.
 *
 * @return array{notices: list<array>, nowhere: int}
 */
function collect_headers_notices(PDO $db, array $config, int $now): array
{
    $rows = $db->query("SELECT c.*, s.url, s.group_id, g.name AS group_name, g.policy, g.slack_webhook, g.webhook_url, g.email
        FROM check_state c
        JOIN sites s ON s.id = c.site_id
        LEFT JOIN groups g ON g.id = s.group_id
        WHERE c.check_name = 'headers' AND s.paused = 0 AND c.status IN ('ok', 'warn')
        ORDER BY s.id")->fetchAll();
    $windows = load_windows($db, $now);
    $notices = [];
    $nowhere = 0;

    foreach ($rows as $row) {
        if (active_silence(['id' => $row['site_id'], 'group_id' => $row['group_id'], 'snoozed_until' => null], $windows, $now, $config['timezone']) !== '') continue;
        $group = $row['group_id'] === null ? null : $row;
        $notice = headers_notice(['details' => json_decode($row['details'], true) ?: []] + $row, $group);
        if ($notice['action'] === 'none') continue;

        $destinations = $notice['action'] === 'send' ? alert_destinations($group, $config) : [];
        if (!$destinations) {
            mark_alerted($db, $row['site_id'], 'headers', $row['status'], null, $now, null, $notice['snapshot']);
            if ($notice['action'] === 'send') $nowhere++;
            continue;
        }
        $notices[] = [
            'site_id' => $row['site_id'],
            'check' => 'headers',
            'status' => $row['status'],
            'url' => $row['url'],
            'group' => $row['group_name'],
            'kind' => $notice['kind'],
            'summary' => $row['summary'],
            'since' => $row['changed_at'],
            'down_seconds' => 0,
            'outages' => 0,
            'worse' => $notice['worse'],
            'from' => $notice['from'],
            'to' => $notice['to'],
            'snapshot' => $notice['snapshot'],
            'server_key' => null,
            'server' => null,
            'destinations' => $destinations,
        ];
    }
    return ['notices' => $notices, 'nowhere' => $nowhere];
}

/**
 * Mail DNS notices that are due (see dns_notice()). Sites that share a
 * domain are one notice per group: the records are the same. Maintenance
 * windows hold them back like any other alert; a snooze is for outages only.
 *
 * @return array{notices: list<array>, nowhere: int}
 */
function collect_dns_notices(PDO $db, array $config, int $now): array
{
    $rows = $db->query("SELECT c.*, s.url, s.group_id, g.name AS group_name, g.policy, g.slack_webhook, g.webhook_url, g.email
        FROM check_state c
        JOIN sites s ON s.id = c.site_id
        LEFT JOIN groups g ON g.id = s.group_id
        WHERE c.check_name = 'dns' AND s.paused = 0 AND c.status IN ('ok', 'warn')
        ORDER BY s.id")->fetchAll();
    $windows = load_windows($db, $now);
    $notices = [];
    $byDomain = [];
    $nowhere = 0;

    foreach ($rows as $row) {
        if (active_silence(['id' => $row['site_id'], 'group_id' => $row['group_id'], 'snoozed_until' => null], $windows, $now, $config['timezone']) !== '') continue;
        $group = $row['group_id'] === null ? null : $row;
        $details = json_decode($row['details'], true) ?: [];
        $notice = dns_notice(['details' => $details] + $row, $group);
        if ($notice['action'] === 'none') continue;

        $destinations = $notice['action'] === 'send' ? alert_destinations($group, $config) : [];
        if (!$destinations) {
            mark_alerted($db, $row['site_id'], 'dns', $row['status'], null, $now, null, $notice['snapshot']);
            if ($notice['action'] === 'send') $nowhere++;
            continue;
        }
        $key = ($row['group_id'] ?? 'none') . '|' . ($details['domain'] ?? '') . '|' . $notice['snapshot'];
        if (isset($byDomain[$key])) {
            $notices[$byDomain[$key]]['also'][] = ['site_id' => $row['site_id'], 'thresholds' => null, 'snapshot' => $notice['snapshot']];
            continue;
        }
        $byDomain[$key] = count($notices);
        $notices[] = [
            'site_id' => $row['site_id'],
            'check' => 'dns',
            'status' => $row['status'],
            'url' => $row['url'],
            'domain' => $details['domain'] ?? '',
            'group' => $row['group_name'],
            'kind' => $notice['kind'],
            'summary' => $row['summary'],
            'since' => $row['changed_at'],
            'down_seconds' => 0,
            'outages' => 0,
            'changes' => $notice['changes'],
            'snapshot' => $notice['snapshot'],
            'also' => [],
            'server_key' => null,
            'server' => null,
            'destinations' => $destinations,
        ];
    }
    return ['notices' => $notices, 'nowhere' => $nowhere];
}

/**
 * Send what is due: one message per destination, sites on the same server
 * folded together. A notice counts as told when at least one of its
 * destinations took it; otherwise it is tried again on the next run.
 *
 * @return array{alerts: list<array{destination: string, headlines: list<string>, ok: bool}>, nowhere: int}
 */
function process_alerts(PDO $db, array $config, int $now): array
{
    $collected = collect_notices($db, $config, $now);
    $more = [
        collect_ssl_notices($db, $config, $now),
        collect_domain_notices($db, $config, $now),
        collect_exposed_notices($db, $config, $now),
        collect_headers_notices($db, $config, $now),
        collect_dns_notices($db, $config, $now),
    ];
    $collected = [
        'notices' => array_merge($collected['notices'], ...array_column($more, 'notices')),
        'nowhere' => $collected['nowhere'] + array_sum(array_column($more, 'nowhere')),
    ];
    $results = [];
    $told = [];
    foreach (bundle_notices($collected['notices'], server_sizes($db)) as $bundle) {
        $ok = deliver($bundle['destination'], $bundle, $config, $now);
        $results[] = [
            'destination' => describe_destination($bundle['destination']),
            'headlines' => array_map(fn ($item) => alert_text($item)['headline'], $bundle['items']),
            'ok' => $ok,
        ];
        if (!$ok) continue;
        foreach ($bundle['items'] as $item) {
            foreach ($item['sites'] as $notice) $told[$notice['site_id'] . ':' . $notice['check']] = $notice;
        }
    }
    foreach ($told as $notice) {
        mark_alerted($db, $notice['site_id'], $notice['check'], $notice['status'], $notice['kind'] === 'unstable' ? utc_now($now) : null, $now, $notice['thresholds'] ?? null, $notice['snapshot'] ?? null);
        // Other sites with the same domain were told in the same notice
        foreach ($notice['also'] ?? [] as $also) mark_alerted($db, $also['site_id'], $notice['check'], $notice['status'], null, $now, $also['thresholds'], $also['snapshot'] ?? null);
    }
    return ['alerts' => $results, 'nowhere' => $collected['nowhere']];
}

/**
 * Send a test alert to a group's destinations (or the defaults in .env).
 *
 * @return list<array{to: string, ok: bool}> where it went, secrets masked, and whether it was taken
 */
function send_test_alert(array $config, ?array $group): array
{
    $item = ['kind' => 'test', 'sites' => [], 'text' => [
        'headline' => 'This is a test alert from Vaka' . ($group ? " for {$group['name']}" : ''),
        'details' => ['If you can read this, alerts' . ($group ? ' for this group' : '') . ' reach you here.'],
    ]];
    return array_map(fn (array $destination) => [
        'to' => describe_destination($destination),
        'ok' => deliver($destination, ['destination' => $destination, 'items' => [$item]], $config, time()),
    ], alert_destinations($group, $config));
}

// =====================================================================
// One message, not fifty
// =====================================================================

/**
 * Notices as messages: one bundle per destination, and within it one item
 * per server for sites on the same server that changed the same way.
 * Unstable first, then outages, then recoveries.
 */
function bundle_notices(array $notices, array $serverSizes): array
{
    $byDestination = [];
    foreach ($notices as $notice) {
        foreach ($notice['destinations'] as $destination) {
            $byDestination[$destination['key']]['destination'] = $destination;
            $byDestination[$destination['key']]['notices'][] = $notice;
        }
    }

    $order = ['unstable' => 0, 'still_down' => 1, 'down' => 2, 'reminder' => 3, 'up' => 4, 'stable' => 5, 'ssl_invalid' => 6, 'ssl_expiring' => 7, 'ssl_fixed' => 8, 'ssl_renewed' => 9,
        'domain_expired' => 10, 'domain_expiring' => 11, 'domain_renewed' => 12, 'exposed' => 13, 'exposed_minor' => 14, 'exposed_fixed' => 15, 'headers_worse' => 16, 'dns_changed' => 17];
    $bundles = [];
    foreach ($byDestination as $bundle) {
        $groups = [];
        foreach ($bundle['notices'] as $notice) {
            $groups[$notice['kind'] . '|' . ($notice['server_key'] ?? 'site:' . $notice['site_id'])][] = $notice;
        }
        $items = [];
        foreach ($groups as $sites) {
            $folded = count($sites) > 1;
            $items[] = [
                'kind' => $sites[0]['kind'],
                'server' => $folded ? $sites[0]['server'] : null,
                'total' => $folded ? ($serverSizes[$sites[0]['server_key']] ?? count($sites)) : 1,
                'sites' => $sites,
            ];
        }
        usort($items, fn ($a, $b) => $order[$a['kind']] <=> $order[$b['kind']]);
        $bundles[] = ['destination' => $bundle['destination'], 'items' => $items];
    }
    return $bundles;
}

/**
 * An item in plain words: a headline and detail lines.
 *
 * @return array{headline: string, details: list<string>}
 */
function alert_text(array $item): array
{
    if (isset($item['text'])) return $item['text'];

    $sites = $item['sites'];
    $quiet = 'Vaka stays quiet about it until it has been stable for ' . STABLE_MINUTES . ' min.';
    $window = FLAP_WINDOW_MINUTES . ' min';
    $remind = fn (string $they, string $them) => 'Vaka reminds you every ' . ($sites[0]['remind_hours'] ?? 0) . " h while {$they} down. Snooze {$them} in Vaka if someone is already on it.";

    if ($item['server'] === null) {
        $site = $sites[0];
        $in = $site['group'] !== null ? " ({$site['group']})" : '';
        $who = display_url($site['url']) . $in;
        // Domain alerts are about the registration, which sites can share
        $domain = ($site['domain'] ?? '') !== '' ? display_domain($site['domain']) . $in : $who;
        $after = $site['down_seconds'] > 0 ? ' after ' . human_duration($site['down_seconds']) : '';
        return match ($item['kind']) {
            'down' => ['headline' => "{$who} is down", 'details' => [$site['summary']]],
            'still_down' => ['headline' => "{$who} is still down after the snooze", 'details' => [$site['summary']]],
            'reminder' => ['headline' => "{$who} has been down for " . human_duration($site['down_seconds']), 'details' => [$site['summary'], $remind('it is', 'it')]],
            'up' => ['headline' => "{$who} is up again{$after}", 'details' => []],
            'unstable' => ['headline' => "{$who} is unstable: down {$site['outages']} times in {$window}", 'details' => [$site['summary'], $quiet]],
            'stable' => ['headline' => "{$who} is stable again: up for " . STABLE_MINUTES . ' min', 'details' => []],
            'ssl_invalid' => ['headline' => "{$who}: the SSL certificate is not valid", 'details' => [$site['summary']]],
            'ssl_expiring' => [
                'headline' => "{$who}: the SSL certificate expires " . match ((int) $site['days_left']) {
                    0 => 'today',
                    1 => 'tomorrow',
                    default => "in {$site['days_left']} days",
                },
                'details' => [$site['summary'], 'Renew it before then. With Let\'s Encrypt, check that the automatic renewal runs.'],
            ],
            'ssl_fixed' => ['headline' => "{$who}: the SSL certificate is valid again", 'details' => [$site['summary']]],
            'ssl_renewed' => ['headline' => "{$who}: the SSL certificate was renewed", 'details' => [$site['summary']]],
            'domain_expiring' => [
                'headline' => "{$domain}: the domain expires " . match ((int) $site['days_left']) {
                    0 => 'today',
                    1 => 'tomorrow',
                    default => "in {$site['days_left']} days",
                },
                'details' => [$site['summary'], advice_for('domain_expiring', 0)],
            ],
            'domain_expired' => ['headline' => "{$domain}: the domain has expired", 'details' => [$site['summary'], advice_for('domain_expired', 0)]],
            'domain_renewed' => ['headline' => "{$domain}: the domain was renewed", 'details' => [$site['summary']]],
            'exposed', 'exposed_minor' => exposed_text($who, $site),
            'exposed_fixed' => ['headline' => "{$who}: nothing that should be private is public any more", 'details' => []],
            'headers_worse' => [
                'headline' => "{$who}: security headers got worse" . ($site['from'] !== $site['to'] ? ", grade {$site['from']} to {$site['to']}" : ''),
                'details' => [
                    ...array_map(fn (array $header) => ucfirst($header['label']) . ($header['change'] === 'removed' ? ' was removed' : ' is weaker') . ($header['note'] !== '' ? ": {$header['note']}" : ''), $site['worse']),
                    ...(in_array('nosniff_files', array_column($site['worse'], 'key'), true)
                        ? ['A web server rule for those files that adds headers of its own, such as Cache-Control, drops the ones set for the whole site (in nginx, a location with its own add_header): add X-Content-Type-Options: nosniff to that rule too.']
                        : []),
                    'If that was not on purpose, look at the last deploy, the web server or the CDN settings.',
                ],
            ],
            'dns_changed' => [
                'headline' => "{$domain}: mail DNS changed",
                'details' => [...$site['changes'], 'If that was not you or your DNS provider, someone may have access to the domain: check who can change its DNS.'],
            ],
        };
    }

    $count = count($sites);
    $all = $count >= $item['total'];
    $server = $item['server'];
    $list = site_list($sites);
    $longest = max([0, ...array_column($sites, 'down_seconds')]);
    $after = $longest > 0 ? ' after ' . human_duration($longest) : '';
    $outages = max([0, ...array_column($sites, 'outages')]);

    return match ($item['kind']) {
        'down' => [
            'headline' => $all ? "Server {$server} is not answering: all {$count} sites are down" : "{$count} of {$item['total']} sites on server {$server} are down",
            'details' => site_reasons($sites, $list),
        ],
        'still_down' => [
            'headline' => $all ? "Server {$server}: all {$count} sites are still down after the snooze" : "{$count} of {$item['total']} sites on server {$server} are still down after the snooze",
            'details' => site_reasons($sites, $list),
        ],
        'reminder' => [
            'headline' => ($all ? "Server {$server} is still not answering: all {$count} sites have been down for " : "{$count} of {$item['total']} sites on server {$server} have been down for ") . human_duration($longest),
            'details' => [...site_reasons($sites, $list), $remind('they are', 'them')],
        ],
        'up' => [
            'headline' => $all ? "Server {$server} is back: {$count} sites up again{$after}" : "{$count} sites on server {$server} are up again{$after}",
            'details' => $all ? [] : [$list],
        ],
        'unstable' => [
            'headline' => $all ? "Server {$server} is unstable: all {$count} sites went down {$outages} times in {$window}" : "{$count} of {$item['total']} sites on server {$server} are unstable: down {$outages} times in {$window}",
            'details' => [$list, $quiet],
        ],
        'stable' => [
            'headline' => $all ? "Server {$server} is stable again: {$count} sites up for " . STABLE_MINUTES . ' min' : "{$count} sites on server {$server} are stable again: up for " . STABLE_MINUTES . ' min',
            'details' => $all ? [] : [$list],
        ],
    };
}

/** The files that were found public: each with what it holds, those told before, and what to do. */
function exposed_text(string $who, array $notice): array
{
    $labels = array_column($notice['new'], 'label');
    $details = array_map(fn (array $file) => "{$file['label']}: {$file['what']}", $notice['new']);
    if ($notice['still']) $details[] = 'Still public: ' . list_words($notice['still']);
    $details[] = advice_for($notice['kind'], 0);
    return ['headline' => "{$who}: " . list_words($labels) . (count($labels) === 1 ? ' is public' : ' are public'), 'details' => $details];
}

/** "a.example, b.example and 5 more" */
function site_list(array $notices): string
{
    $names = array_map(fn ($notice) => display_url($notice['url']), $notices);
    $shown = implode(', ', array_slice($names, 0, ALERT_SITE_LIST));
    $more = count($names) - ALERT_SITE_LIST;
    return $more > 0 ? "{$shown} and {$more} more" : $shown;
}

/** One shared reason after the list of sites, or each site with its own reason. */
function site_reasons(array $notices, string $list): array
{
    $reasons = array_unique(array_column($notices, 'summary'));
    if (count($reasons) === 1) return [$list, $reasons[0]];

    $lines = array_map(fn ($notice) => display_url($notice['url']) . ': ' . lcfirst($notice['summary']), array_slice($notices, 0, ALERT_SITE_LIST));
    $more = count($notices) - ALERT_SITE_LIST;
    if ($more > 0) $lines[] = "and {$more} more";
    return $lines;
}

// =====================================================================
// Daily summary
// =====================================================================

/**
 * The daily summary for a digest group: what is still down, then every
 * change since the last summary, in local time.
 */
function digest_text(string $group, array $events, array $stillDown, string $timezone, string $timeFormat = '24h'): array
{
    $tz = new DateTimeZone($timezone);
    $time = fn (string $utc) => clock_text((new DateTimeImmutable($utc))->setTimezone($tz), $timeFormat);
    $outages = count(array_filter($events, fn ($event) => $event['status'] === 'fail' && ($event['check_name'] ?? 'uptime') === 'uptime'));

    $parts = [$outages ? plural($outages, 'outage', 'outages') : 'no outages'];
    if ($stillDown) $parts[] = plural(count($stillDown), 'site still down', 'sites still down');

    $details = [];
    foreach ($stillDown as $site) {
        $details[] = 'Still down: ' . display_url($site['url']) . ' since ' . $time($site['changed_at']) . ': ' . lcfirst($site['summary']);
    }
    foreach ($events as $event) {
        $during = ($event['silenced_by'] ?? '') === 'maintenance' ? ' (during maintenance)' : '';
        $details[] = $time($event['created_at']) . ' ' . display_url($event['url']) . ': ' . lcfirst($event['message']) . $during;
    }
    return ['headline' => "Daily summary for {$group}: " . implode(', ', $parts), 'details' => $details];
}

/**
 * Once a day, after DIGEST_TIME in TIMEZONE: one summary per group with the
 * digest policy, covering everything since the last one. Groups with
 * nothing to report get nothing.
 */
function send_digests(PDO $db, array $config, int $now): array
{
    $local = (new DateTimeImmutable('@' . $now))->setTimezone(new DateTimeZone($config['timezone']));
    $today = $local->format('Y-m-d');
    if ($local->format('H:i') < $config['digest_time'] || get_meta($db, 'last_digest_on') === $today) return [];

    $since = get_meta($db, 'last_digest_at') ?? utc_now($now - 86400);
    $results = [];
    foreach ($db->query("SELECT * FROM groups WHERE policy = 'digest' ORDER BY name")->fetchAll() as $group) {
        $events = $db->prepare("SELECT e.*, s.url FROM events e JOIN sites s ON s.id = e.site_id
            WHERE s.group_id = ? AND e.check_name IN ('uptime', 'ssl', 'domain', 'exposed', 'headers', 'dns') AND e.created_at > ? AND e.created_at <= ?
                AND NOT (e.previous = 'pending' AND (e.status = 'ok' OR e.check_name IN ('headers', 'dns')))
            ORDER BY e.created_at, e.id");
        $events->execute([$group['id'], $since, utc_now($now)]);
        $down = $db->prepare("SELECT s.url, c.summary, c.changed_at FROM check_state c JOIN sites s ON s.id = c.site_id
            WHERE s.group_id = ? AND s.paused = 0 AND c.check_name = 'uptime' AND c.status = 'fail'");
        $down->execute([$group['id']]);
        $events = $events->fetchAll();
        $down = sort_sites($down->fetchAll(), false);
        if (!$events && !$down) continue;

        $item = ['kind' => 'digest', 'text' => digest_text($group['name'], $events, $down, $config['timezone'], $config['time_format']), 'sites' => []];
        foreach (alert_destinations($group, $config) as $destination) {
            $ok = deliver($destination, ['destination' => $destination, 'items' => [$item]], $config, $now);
            $results[] = ['destination' => describe_destination($destination), 'headlines' => [$item['text']['headline']], 'ok' => $ok];
        }
    }
    set_meta($db, 'last_digest_on', $today);
    set_meta($db, 'last_digest_at', utc_now($now));
    return $results;
}
