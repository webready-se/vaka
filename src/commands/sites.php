<?php

/** Site fields from --options (only those given). */
function site_options(array $opts): array
{
    $fields = [];
    foreach (['url', 'group', 'name', 'keyword', 'interval', 'server'] as $opt) {
        if (isset($opts[$opt])) $fields[$opt] = $opts[$opt] === true ? '' : $opts[$opt];
    }
    if (isset($opts['pause'])) $fields['paused'] = true;
    if (isset($opts['resume'])) $fields['paused'] = false;
    return $fields;
}

/** Minutes between uptime checks for a site. */
function site_interval(array $site, array $config): int
{
    return $site['interval_minutes'] ?? $config['uptime_interval'];
}

/** "Customers, every 5 min, paused" */
function describe_site(array $site, array $config): string
{
    $parts = [$site['group_name'] ?? 'no group', 'every ' . site_interval($site, $config) . ' min'];
    if ($site['paused']) $parts[] = 'paused';
    return implode(', ', $parts);
}

/** Ask for every site field; $current holds the values Enter keeps when editing. */
function ask_site_fields(PDO $db, array $config, ?array $current = null): array
{
    $fields = ['url' => ask('URL', $current['url'] ?? '')];
    if ($fields['url'] === '') fail('A URL is needed, for example shop.example.');

    $groups = list_groups($db);
    if ($groups) {
        $options = array_column($groups, 'name', 'name') + ['none' => 'No group'];
        $default = $current ? ($current['group_name'] ?? 'none') : null;
        $fields['group'] = choose('Group', $options, $default) ?? 'none';
    }
    $fields['name'] = ask_optional('Name', $current['name'] ?? '', skip: 'Enter to use the domain');
    $fields['keyword'] = ask_optional('Text that must be on the page', $current['keyword'] ?? '');

    $default = (string) ($current['interval_minutes'] ?? $config['uptime_interval']);
    $interval = ask('Check every how many minutes', $default);
    // Keep following UPTIME_INTERVAL unless a site has its own number
    $fields['interval'] = $interval === (string) $config['uptime_interval'] && ($current['interval_minutes'] ?? null) === null ? '' : $interval;

    if ($current) {
        $paused = parse_bool(ask('Paused (yes/no)', $current['paused'] ? 'yes' : 'no'));
        if ($paused === null) fail('Paused is yes or no.');
        $fields['paused'] = $paused;
    }
    return $fields;
}

/** A site with its group's name, by id. */
function site_with_group(PDO $db, int $id): array
{
    $stmt = $db->prepare('SELECT s.*, g.name AS group_name FROM sites s LEFT JOIN groups g ON g.id = s.group_id WHERE s.id = ?');
    $stmt->execute([$id]);
    return $stmt->fetch();
}

/** The site a user means (URL, domain or id), or picked from a list when none is given. */
function pick_site(PDO $db, ?string $ref): array
{
    if ($ref === null) {
        $sites = list_sites($db);
        if (!$sites) fail('No sites yet. Add one with `php vaka site:add <url>`.');
        foreach ($sites as $i => $site) echo sprintf("  %d) %s\n", $i + 1, $site['url']);
        $ref = ask('Site (number, URL or domain)');
        if (ctype_digit($ref) && isset($sites[(int) $ref - 1])) return site_with_group($db, $sites[(int) $ref - 1]['id']);
    }
    $site = find_site($db, $ref);
    if (!$site) fail("No site matches \"{$ref}\". See `php vaka site:list`.");
    return site_with_group($db, $site['id']);
}

/** Check names from an option such as --disable=ssl,dns, each one known. */
function check_names(string|bool $value): array
{
    $names = array_filter(preg_split('/[\s,]+/', strtolower((string) $value)));
    foreach ($names as $name) {
        if (!in_array($name, CHECKS, true)) fail("\"{$name}\" is not a check. The checks are " . implode(', ', CHECKS) . '.');
    }
    return $names;
}

function cmd_site_add(array $config, array $args, array $opts): void
{
    allow_options($opts, ['group', 'name', 'keyword', 'interval', 'server', 'disable', 'pause']);
    $db = get_db($config['db_path']);
    if (isset($args[1])) {
        $fields = ['url' => $args[1]] + site_options($opts);
        if (isset($opts['disable'])) $fields['disabled_checks'] = implode(',', check_names($opts['disable']));
    } else {
        $fields = ask_site_fields($db, $config);
    }

    try {
        $site = site_with_group($db, add_site($db, $fields));
    } catch (InvalidArgumentException $e) {
        fail($e->getMessage() . '.');
    }
    $where = $site['group_name'] !== null ? " to {$site['group_name']}" : '';
    echo "Added {$site['url']}{$where}. Vaka checks it every " . site_interval($site, $config) . " min.\n";
}

function cmd_site_edit(array $config, array $args, array $opts): void
{
    allow_options($opts, ['url', 'group', 'name', 'keyword', 'interval', 'server', 'pause', 'resume', 'disable', 'enable']);
    $db = get_db($config['db_path']);
    $site = pick_site($db, $args[1] ?? null);

    $fields = site_options($opts);
    if (isset($opts['disable']) || isset($opts['enable'])) {
        $disabled = array_merge(array_filter(explode(',', $site['disabled_checks'])), check_names($opts['disable'] ?? ''));
        $fields['disabled_checks'] = implode(',', array_diff($disabled, check_names($opts['enable'] ?? '')));
    }
    if (!$fields) $fields = ask_site_fields($db, $config, $site);

    try {
        update_site($db, $site['id'], $fields);
    } catch (InvalidArgumentException $e) {
        fail($e->getMessage() . '.');
    }
    $site = site_with_group($db, $site['id']);
    echo "Updated {$site['url']}: " . describe_site($site, $config) . ".\n";
}

function cmd_site_remove(array $config, array $args, array $opts): void
{
    allow_options($opts, ['force']);
    $db = get_db($config['db_path']);
    $site = pick_site($db, $args[1] ?? null);

    if (empty($opts['force']) && !confirm("Remove {$site['url']} and its history?")) {
        echo "Nothing removed.\n";
        exit(1);
    }
    remove_site($db, $site['id']);
    echo "Removed {$site['url']}.\n";
}

function cmd_site_list(array $config, array $args, array $opts): void
{
    allow_options($opts, ['group']);
    $db = get_db($config['db_path']);
    $groupId = isset($opts['group']) ? pick_group($db, (string) $opts['group'])['id'] : null;
    $sites = list_sites($db, $groupId);
    if (!$sites) {
        echo "No sites yet. Add one with `php vaka site:add <url>`, or a whole list with `php vaka site:import <file.csv>`.\n";
        return;
    }

    print_table(['Site', 'Group', 'Every', 'Notes'], array_map(function ($site) use ($config) {
        $notes = [];
        if ($site['paused']) $notes[] = 'paused';
        if ($site['name'] !== '') $notes[] = "name \"{$site['name']}\"";
        if ($site['keyword'] !== '') $notes[] = "looks for \"{$site['keyword']}\"";
        if ($site['server'] !== '') $notes[] = "server {$site['server']}";
        if ($site['disabled_checks'] !== '') $notes[] = 'skips ' . str_replace(',', ', ', $site['disabled_checks']);
        return [$site['url'], $site['group_name'] ?? '-', site_interval($site, $config) . ' min', implode('; ', $notes)];
    }, $sites));

    $paused = count(array_filter(array_column($sites, 'paused')));
    echo "\n" . plural(count($sites), 'site', 'sites') . ($paused ? ", {$paused} paused" : '') . ".\n";
}

function cmd_site_import(array $config, array $args, array $opts): void
{
    allow_options($opts, ['dry-run']);
    $path = $args[1] ?? ask('CSV file to import');
    if ($path === '') fail('A file is needed: php vaka site:import <file.csv>');
    if (!is_file($path) || !is_readable($path)) fail("Cannot read {$path}.");

    $parsed = parse_import_csv(file_get_contents($path));
    if ($parsed['errors']) {
        fail('Nothing imported: ' . plural(count($parsed['errors']), 'line needs', 'lines need') . " fixing first.\n  " . implode("\n  ", $parsed['errors']));
    }

    $dryRun = !empty($opts['dry-run']);
    try {
        $summary = import_sites(get_db($config['db_path']), $parsed['rows'], $dryRun);
    } catch (InvalidArgumentException $e) {
        fail('Nothing imported: ' . $e->getMessage() . '.');
    }

    echo 'Read ' . plural(count($parsed['rows']), 'site', 'sites') . ' from ' . basename($path) . ".\n";
    echo '  ' . plural($summary['added'], 'new site', 'new sites') . ($summary['paused'] ? " ({$summary['paused']} paused)" : '') . "\n";
    if ($summary['existing']) echo "  {$summary['existing']} already in Vaka, left unchanged\n";
    if ($summary['new_groups']) {
        echo '  ' . plural(count($summary['new_groups']), 'new group', 'new groups') . ': ' . implode(', ', $summary['new_groups']) . "\n";
    }

    if ($dryRun) {
        echo "Dry run: nothing was saved. Run it again without --dry-run to import.\n";
    } elseif ($summary['new_groups']) {
        $newGroup = ['policy' => 'immediate', 'delay_minutes' => 0, 'slack_webhook' => '', 'webhook_url' => '', 'email' => ''];
        echo 'New groups: ' . lcfirst(describe_alerting($newGroup, $config)) . "\n";
        echo "Change when and where a group is alerted with `php vaka group:edit <name>`.\n";
    }
}
