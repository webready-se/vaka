<?php

/** The group or site a command is for (a group name wins): [site_id, group_id, words]. */
function group_or_site(PDO $db, string $target): array
{
    if ($group = find_group($db, $target)) return [null, $group['id'], "group {$group['name']}"];
    if ($site = find_site($db, $target)) return [$site['id'], null, display_url($site['url'])];
    fail("No group or site matches \"{$target}\". See `php vaka group:list` and `php vaka site:list`.");
}

/** A group or a site, picked from a list: groups first. Returns its name as group_or_site() takes it. */
function pick_group_or_site(PDO $db): string
{
    $options = [];
    foreach (list_groups($db) as $group) {
        $options['group:' . $group['name']] = "The group {$group['name']} (" . ($group['site_count'] ? plural((int) $group['site_count'], 'site', 'sites') : 'no sites') . ')';
    }
    foreach (list_sites($db) as $site) {
        $options['site:' . $site['url']] = site_choice($site);
    }
    if (!$options) fail('No groups or sites yet. Add a site with `php vaka site:add <url>`.');
    $choice = choose('For which group or site?', $options);
    if ($choice === null) fail('Nothing chosen.');
    return substr($choice, strpos($choice, ':') + 1);
}

/** Ask when a maintenance window is, as the options maintenance_fields() takes. */
function ask_window(): array
{
    $kind = choose('When?', [
        'daily' => 'Every day at the same time',
        'days' => 'Some days of the week',
        'once' => 'Once',
    ], 'daily');
    if ($kind === 'once') {
        $opts = ['from' => ask('From (YYYY-MM-DD HH:MM)'), 'to' => ask('To (YYYY-MM-DD HH:MM)')];
    } else {
        $opts = ['at' => ask('Time range, e.g. 00:00-07:00')];
        if ($kind === 'days') $opts['days'] = ask('Days, e.g. weekdays or mon,wed,fri');
    }
    $label = ask_optional('Label, e.g. Nightly imports');
    if ($label !== '') $opts['label'] = $label;
    return $opts;
}

function cmd_maintenance_add(array $config, array $args, array $opts): void
{
    allow_options($opts, ['at', 'days', 'from', 'to', 'label']);
    $db = get_db($config['db_path']);
    [$siteId, $groupId, $name] = group_or_site($db, $args[1] ?? pick_group_or_site($db));
    if (!$opts) $opts = ask_window();

    try {
        $fields = maintenance_fields($opts, $config['timezone']);
    } catch (InvalidArgumentException $e) {
        fail($e->getMessage() . '.');
    }
    add_maintenance($db, $siteId, $groupId, $fields);
    echo "Maintenance window added for {$name}: " . describe_window($fields, $config['timezone']) . " ({$config['timezone']}).\n";
    echo "Checks keep running. An outage that starts and ends inside the window is not alerted; a site still down when it ends is.\n";
}

function cmd_maintenance_list(array $config, array $args, array $opts): void
{
    allow_options($opts, []);
    $db = get_db($config['db_path']);
    $windows = list_maintenance($db);
    if (!$windows) {
        echo "No maintenance windows. Add one with `php vaka maintenance:add <group or site> --at=00:00-07:00`.\n";
        return;
    }
    $now = time();
    print_table(['#', 'For', 'When', 'Label', ''], array_map(fn ($window) => [
        $window['id'],
        window_target($window),
        describe_window($window, $config['timezone']),
        $window['label'],
        window_active($window, $now, $config['timezone']) ? 'active now' : (($window['ends_at'] ?? '9') <= utc_now($now) ? 'over' : ''),
    ], $windows));
    echo "\nTimes are in {$config['timezone']}.\n";
}

function cmd_maintenance_remove(array $config, array $args, array $opts): void
{
    allow_options($opts, []);
    $db = get_db($config['db_path']);
    $windows = array_column(list_maintenance($db), null, 'id');
    if (!$windows) fail('There are no maintenance windows to remove.');
    $id = $args[1] ?? choose('Which window?', array_map(fn (array $window) => window_target($window) . ', ' . describe_window($window, $config['timezone']), $windows));
    $window = ctype_digit((string) $id) ? ($windows[(int) $id] ?? null) : null;
    if (!$window) fail("There is no maintenance window {$id}. See `php vaka maintenance:list`.");

    remove_maintenance($db, $window['id']);
    echo 'Removed the maintenance window for ' . window_target($window) . ' (' . describe_window($window, $config['timezone']) . ").\n";
}

/**
 * The sites a snooze command means: one site, or the sites on a server
 * (its IP, its label, or a server name set by hand on sites).
 *
 * @return array{0: list<array>, 1: string, 2: bool} sites with their uptime state, words for them, whether it is a server
 */
function snooze_targets(PDO $db, string $target): array
{
    $select = "SELECT s.*, c.status, c.fail_count, c.snoozed_until FROM sites s
        LEFT JOIN check_state c ON c.site_id = s.id AND c.check_name = 'uptime' WHERE s.paused = 0 AND ";
    if ($site = find_site($db, $target)) {
        $stmt = $db->prepare($select . 's.id = ?');
        $stmt->execute([$site['id']]);
        return [$stmt->fetchAll(), display_url($site['url']), false];
    }
    $stmt = $db->prepare($select . ON_SERVER_SQL);
    $stmt->execute([$target, $target, $target]);
    $sites = sort_sites($stmt->fetchAll(), false);
    if (!$sites) fail("No site or server matches \"{$target}\". See `php vaka site:list` and `php vaka server:list`.");
    return [$sites, 'server ' . server_display($sites[0], server_labels($db)), true];
}

function is_failing(array $site): bool
{
    return $site['status'] === 'fail' || ($site['fail_count'] ?? 0) > 0;
}

function cmd_snooze(array $config, array $args, array $opts): void
{
    allow_options($opts, ['for']);
    $db = get_db($config['db_path']);
    $now = time();

    $target = $args[1] ?? null;
    if ($target === null) {
        $incidents = array_values(array_filter(uptime_sites($db, '1 = 1', []), fn ($site) => $site['state_status'] === 'fail' || $site['state_fail_count'] > 0));
        if (!$incidents) {
            echo "Nothing is down: there is nothing to snooze.\n";
            return;
        }
        $choice = choose('Snooze which site?', array_column(array_map(fn ($site) => ['id' => $site['id'], 'url' => display_url($site['url'])], $incidents), 'url', 'id'));
        if ($choice === null) fail('No site chosen.');
        $target = (string) $choice;
    }
    try {
        $seconds = match (true) {
            is_string($opts['for'] ?? null) => parse_snooze($opts['for']),
            isset($args[1]) => SNOOZE_CHOICES['1h'],
            default => SNOOZE_CHOICES[choose('For how long?', ['1h' => '1 hour', '4h' => '4 hours', '24h' => '24 hours'], '1h') ?? '1h'],
        };
    } catch (InvalidArgumentException $e) {
        fail($e->getMessage() . '.');
    }

    [$sites, $name, $isServer] = snooze_targets($db, $target);
    $failing = array_values(array_filter($sites, 'is_failing'));
    if (!$failing) {
        fail($isServer ? "Nothing on {$name} is down: there is nothing to snooze." : "{$name} is up: there is nothing to snooze.");
    }
    foreach ($failing as $site) snooze_site($db, $site['id'], $seconds, $now);

    $what = $isServer ? plural(count($failing), 'site', 'sites') . " on {$name}" : $name;
    $it = $isServer ? 'they are' : 'it is';
    echo "Snoozed {$what} for " . human_duration($seconds) . ' (until ' . local_time(utc_now($now + $seconds), $now) . ").\n";
    echo "Vaka alerts again if {$it} still down then; a recovery ends the snooze.\n";
}

function cmd_unsnooze(array $config, array $args, array $opts): void
{
    allow_options($opts, []);
    $db = get_db($config['db_path']);
    $target = $args[1] ?? null;
    if ($target === null) {
        $snoozedNow = $db->prepare("SELECT s.url, c.snoozed_until FROM sites s JOIN check_state c ON c.site_id = s.id AND c.check_name = 'uptime' WHERE c.snoozed_until > ?");
        $snoozedNow->execute([utc_now()]);
        $options = [];
        foreach (sort_sites($snoozedNow->fetchAll(), false) as $site) {
            $options[$site['url']] = display_url($site['url']) . ' (until ' . local_time($site['snoozed_until'], time()) . ')';
        }
        if (!$options) {
            echo "Nothing is snoozed right now.\n";
            return;
        }
        $target = choose('Unsnooze which site?', $options);
    }
    [$sites, $name, $isServer] = snooze_targets($db, $target);
    $snoozed = array_filter($sites, fn ($site) => $site['snoozed_until'] !== null && $site['snoozed_until'] > utc_now());
    if (!$snoozed) fail("{$name} is not snoozed.");

    foreach ($snoozed as $site) unsnooze_site($db, $site['id'], time());
    $what = $isServer ? plural(count($snoozed), 'site', 'sites') . " on {$name} are" : "{$name} is";
    echo "{$what} no longer snoozed. Vaka alerts on the next run if " . ($isServer ? 'they are' : 'it is') . " still down.\n";
}
