<?php

function cmd_alert_test(array $config, array $args, array $opts): void
{
    allow_options($opts, []);
    $db = get_db($config['db_path']);
    $group = isset($args[1]) ? pick_group($db, $args[1]) : null;
    $for = $group ? " for {$group['name']}" : '';

    $destinations = alert_destinations($group, $config);
    if (!$destinations) {
        fail('There is nowhere to send alerts' . ($group ? " for {$group['name']}" : ' for sites without a group')
            . ' yet: set SLACK_WEBHOOK_URL in .env, or use `php vaka group:edit <name> --slack=<webhook url>`.');
    }

    $failed = false;
    foreach (send_test_alert($config, $group) as $sent) {
        if ($sent['ok']) {
            echo "Sent a test alert to {$sent['to']}.\n";
        } else {
            fwrite(STDERR, "Could not send a test alert to {$sent['to']}" . (str_contains($sent['to'], '@') ? ': mail() failed, is a mail server set up?' : '') . "\n");
            $failed = true;
        }
    }
    if ($failed) exit(1);
}

/** Servers as Vaka sees them: sites grouped by the IP they answered on (or a name set by hand). */
function cmd_server_list(array $config, array $args, array $opts): void
{
    allow_options($opts, []);
    $db = get_db($config['db_path']);
    $labels = server_labels($db);
    $servers = [];
    $cdn = [];
    $unknown = 0;
    foreach ($db->query('SELECT ip, cdn, server FROM sites WHERE paused = 0')->fetchAll() as $site) {
        $key = server_key($site);
        if ($key !== null) {
            $servers[$key] ??= [server_display($site, $labels), 0];
            $servers[$key][1]++;
        } elseif ($site['cdn'] !== '') {
            $cdn[$site['cdn']] = ($cdn[$site['cdn']] ?? 0) + 1;
        } else {
            $unknown++;
        }
    }
    if (!$servers && !$cdn && !$unknown) {
        echo "No sites checked yet. Servers show up here after `php vaka run`.\n";
        return;
    }
    if ($servers) {
        uasort($servers, fn ($a, $b) => $b[1] <=> $a[1] ?: strcmp($a[0], $b[0]));
        print_table(['Server', 'Sites'], array_values($servers));
    }
    foreach ($cdn as $name => $count) echo "\nBehind {$name}: " . plural($count, 'site', 'sites') . ', each alerted on its own.';
    if ($unknown) echo "\nNot checked yet: " . plural($unknown, 'site', 'sites') . '.';
    if ($cdn || $unknown) echo "\n";
}

/** A server's IP, picked from the servers Vaka has seen sites answer on (not behind a CDN), with how many sites each has. */
function pick_server_ip(PDO $db): string
{
    $labels = server_labels($db);
    $servers = [];
    foreach ($db->query("SELECT ip FROM sites WHERE paused = 0 AND ip != '' AND cdn = '' AND server = ''")->fetchAll(PDO::FETCH_COLUMN) as $ip) {
        $servers[$ip] = ($servers[$ip] ?? 0) + 1;
    }
    if (!$servers) fail('No servers known yet. They show up after `php vaka run`; or give an IP: php vaka server:label <ip> <name>');
    arsort($servers);
    $options = [];
    foreach ($servers as $ip => $count) {
        $options[$ip] = (isset($labels[$ip]) ? "{$labels[$ip]} ({$ip})" : $ip) . ' (' . plural($count, 'site', 'sites') . ')';
    }
    return (string) choose('Which server?', $options);
}

/** Name a server by its IP, so alerts say "web-1 (203.0.113.10)". An empty label removes the name. */
function cmd_server_label(array $config, array $args, array $opts): void
{
    allow_options($opts, []);
    $db = get_db($config['db_path']);
    $ip = $args[1] ?? pick_server_ip($db);
    if (!filter_var($ip, FILTER_VALIDATE_IP)) fail("\"{$ip}\" is not an IP address.");
    $label = array_key_exists(2, $args) ? $args[2] : ask('Name (Enter removes the name)');
    try {
        $label = clean_text($label, 60, 'server name');
    } catch (InvalidArgumentException $e) {
        fail($e->getMessage() . '.');
    }

    if ($label === '') {
        $db->prepare('DELETE FROM servers WHERE ip = ?')->execute([$ip]);
        echo "Server {$ip} has no name now.\n";
        return;
    }
    $db->prepare('INSERT INTO servers (ip, label) VALUES (?, ?) ON CONFLICT (ip) DO UPDATE SET label = excluded.label')->execute([$ip, $label]);
    echo "Server {$ip} is called {$label} now.\n";
}
