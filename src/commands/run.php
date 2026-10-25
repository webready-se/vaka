<?php

/** A URL without its scheme, as people say it. */
function display_url(string $url): string
{
    return preg_replace('#^https?://#', '', $url);
}

/** "just now", "3 min ago", "2 h ago". */
function ago(string $utc, int $now): string
{
    $seconds = $now - strtotime($utc);
    return $seconds < 60 ? 'just now' : human_duration($seconds) . ' ago';
}

/** A stored UTC time in TIMEZONE: "14:02" today, "24 Oct 14:02" before that. */
function local_time(string $utc, int $now): string
{
    $time = strtotime($utc);
    return date('Y-m-d', $time) === date('Y-m-d', $now) ? date('H:i', $time) : date('j M H:i', $time);
}

function cmd_run(array $config, array $args, array $opts): void
{
    allow_options($opts, []);
    $db = get_db($config['db_path']);
    $summary = run_locked(dirname($config['db_path']) . '/vaka.lock', fn () => run($db, $config));
    if ($summary === null) {
        echo "Another run is still going, so this one was skipped.\n";
        return;
    }

    if ($summary['checked'] === 0) {
        echo "Nothing was due.\n";
    } else {
        $parts = [];
        if ($summary['up']) $parts[] = "{$summary['up']} up";
        if ($summary['down']) $parts[] = "{$summary['down']} down";
        if ($summary['failing']) $parts[] = "{$summary['failing']} failing (checking again in a minute)";
        echo 'Checked ' . plural($summary['checked'], 'site', 'sites') . ': ' . implode(', ', $parts) . ".\n";
    }
    foreach ($summary['events'] as $event) {
        if ($event['previous'] === 'pending' && $event['status'] === 'ok') continue;
        echo '  ' . display_url($event['url']) . ": {$event['message']}\n";
    }
    if ($summary['heartbeat'] === false) {
        echo "The heartbeat ping to HEARTBEAT_URL did not get through.\n";
    }
}

function cmd_check(array $config, array $args, array $opts): void
{
    allow_options($opts, ['keyword']);
    try {
        $url = normalize_url($args[1] ?? ask('URL to check'));
    } catch (InvalidArgumentException $e) {
        fail($e->getMessage() . '.');
    }
    $keyword = is_string($opts['keyword'] ?? null) ? $opts['keyword'] : '';

    $response = fetch_many(['check' => $url], $config['uptime_timeout'], user_agent($config))['check'];
    $result = evaluate_uptime($response, $keyword, $config['uptime_timeout']);

    echo "{$url}\n";
    if ($result['ok']) {
        $from = $result['cdn'] !== '' ? "behind {$result['cdn']}" : "from {$result['ip']}";
        echo "  Up: " . lcfirst($result['summary']) . ", {$from}\n";
        return;
    }
    echo "  Down: " . lcfirst($result['summary']) . "\n";
    exit(1);
}

function cmd_status(array $config, array $args, array $opts): void
{
    allow_options($opts, []);
    $db = get_db($config['db_path']);
    $now = time();
    $sites = $db->query("SELECT s.*, g.name AS group_name, c.status, c.summary, c.details, c.fail_count, c.checked_at, c.changed_at
        FROM sites s
        LEFT JOIN groups g ON g.id = s.group_id
        LEFT JOIN check_state c ON c.site_id = s.id AND c.check_name = 'uptime'
        ORDER BY s.group_id IS NULL, g.name COLLATE NOCASE, s.url")->fetchAll();

    $lastRun = get_meta($db, 'last_run_at');
    if ($lastRun === null) {
        echo "Vaka has not run yet. Add this line to cron: * * * * * php " . realpath(__DIR__ . '/../../vaka') . " run\n";
    } elseif ($now - strtotime($lastRun) > 300) {
        echo 'Last run ' . ago($lastRun, $now) . ": is cron still running `php vaka run` every minute?\n";
    } else {
        echo 'Last run ' . ago($lastRun, $now) . ".\n";
    }
    if (!$sites) {
        echo "No sites yet. Add one with `php vaka site:add <url>`.\n";
        return;
    }

    $counts = ['up' => 0, 'down' => 0, 'failing' => 0, 'paused' => 0, 'not checked yet' => 0];
    $rows = [];
    foreach ($sites as $site) {
        [$word, $detail] = match (true) {
            (bool) $site['paused'] => ['paused', ''],
            $site['status'] === null || ($site['status'] === 'pending' && !$site['fail_count']) => ['not checked yet', ''],
            $site['status'] === 'fail' => ['DOWN', 'since ' . local_time($site['changed_at'], $now) . ' (' . human_duration($now - strtotime($site['changed_at'])) . '): ' . lcfirst($site['summary'])],
            $site['fail_count'] > 0 => ['failing', lcfirst($site['summary'])],
            default => ['up', (json_decode($site['details'], true)['ms'] ?? '?') . ' ms, checked ' . ago($site['checked_at'], $now)],
        };
        $counts[$word === 'DOWN' ? 'down' : $word]++;
        $rows[] = [$site['group_name'] ?? 'No group', display_url($site['url']), $word, $detail];
    }

    $width = max(array_map(fn ($row) => text_length($row[1]), $rows));
    $group = null;
    foreach ($rows as [$name, $site, $word, $detail]) {
        if ($name !== $group) {
            echo "\n{$name}\n";
            $group = $name;
        }
        echo rtrim('  ' . $site . str_repeat(' ', $width - text_length($site) + 2) . str_pad($word, 9) . $detail) . "\n";
    }

    $parts = [];
    foreach ($counts as $word => $count) {
        if ($count) $parts[] = "{$count} {$word}";
    }
    echo "\n" . plural(count($sites), 'site', 'sites') . ': ' . implode(', ', $parts) . ".\n";
}
