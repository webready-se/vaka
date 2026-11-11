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
        if (count($sites) === 1) {
            echo 'Which site? ' . display_url($sites[0]['url']) . " (the only one)\n";
            return site_with_group($db, $sites[0]['id']);
        }
        echo "Which site?\n";
        foreach ($sites as $i => $site) echo sprintf("  %d) %s\n", $i + 1, site_choice($site));
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

    // An address that is not in DNS is a typo, most often: asked at a terminal, told from a script
    $note = unresolved_note((string) $fields['url'], $config['host_lookup'] ?? null);
    if ($note !== null && stream_isatty(STDIN) && !confirm("{$note} Add it anyway?")) fail('Not added. Check the address, then try again.');
    try {
        $site = site_with_group($db, add_site($db, $fields));
    } catch (InvalidArgumentException $e) {
        fail($e->getMessage() . '.');
    }
    if ($note !== null && !stream_isatty(STDIN)) fwrite(STDERR, "Warning: {$note} Added anyway: check the spelling.\n");
    $where = $site['group_name'] !== null ? " to {$site['group_name']}" : '';
    if ($site['paused']) {
        echo "Added {$site['url']}{$where}, paused: Vaka does not check it until you run `php vaka site:edit " . display_url($site['url']) . " --resume`.\n";
        return;
    }
    echo 'Added ' . readable_url($site['url']) . "{$where}. Vaka checks it every " . site_interval($site, $config) . " min.\n";
}

function cmd_site_edit(array $config, array $args, array $opts): void
{
    allow_options($opts, ['url', 'group', 'name', 'keyword', 'interval', 'server', 'pause', 'resume', 'disable', 'enable']);
    $db = get_db($config['db_path']);
    // A server instead of a site: move all its sites at once
    $target = $args[1] ?? null;
    if ($target !== null && !find_site($db, $target) && ($onServer = sites_on_server($db, $target))) {
        move_server_sites($db, $config, $onServer, $opts);
        return;
    }
    // --group alone, without a site: move several sites at once, picked from a list
    if ($target === null && array_keys($opts) === ['group'] && is_string($opts['group'])) {
        move_picked_sites($db, $config, $opts['group']);
        return;
    }
    $site = pick_site($db, $target);

    $fields = site_options($opts);
    if (isset($opts['disable']) || isset($opts['enable'])) {
        $disabled = array_merge(array_filter(explode(',', $site['disabled_checks'])), check_names($opts['disable'] ?? ''));
        $fields['disabled_checks'] = implode(',', array_diff($disabled, check_names($opts['enable'] ?? '')));
    }
    if (!$fields) $fields = ask_site_fields($db, $config, $site);

    $note = null;
    if (isset($fields['url'])) {
        try {
            $moved = normalize_url((string) $fields['url']) !== $site['url'];
        } catch (InvalidArgumentException) {
            $moved = false;
        }
        $note = $moved ? unresolved_note((string) $fields['url'], $config['host_lookup'] ?? null) : null;
        if ($note !== null && stream_isatty(STDIN) && !confirm("{$note} Change it anyway?")) fail('Not changed. Check the address, then try again.');
    }
    $readers = group_readers($db, $config['users_file'], time());
    try {
        update_site($db, $site['id'], $fields);
    } catch (InvalidArgumentException $e) {
        fail($e->getMessage() . '.');
    }
    if ($note !== null && !stream_isatty(STDIN)) fwrite(STDERR, "Warning: {$note} Changed anyway: check the spelling.\n");
    $updated = site_with_group($db, $site['id']);
    echo 'Updated ' . readable_url($updated['url']) . ': ' . describe_site($updated, $config) . ".\n";
    echo readers_lines(readers_change($readers, [$site['group_id']], $updated['group_id']));
}

/** site:edit <server> --group=<name|none>: every site on a server into a group, or out of one. Nothing else changes for a whole server. */
function move_server_sites(PDO $db, array $config, array $sites, array $opts): void
{
    if (array_keys($opts) !== ['group'] || !is_string($opts['group'])) {
        fail('For a whole server, only --group can change: php vaka site:edit <server> --group=<name|none>. Change other settings one site at a time.');
    }
    move_sites($db, $config, $sites, $opts['group'], ' on server ' . server_display($sites[0], server_labels($db)));
}

/**
 * site:edit --group=<name|none> without a site: move the sites picked from a
 * numbered list (ranges and all). A group that does not exist yet can be
 * added on the way.
 */
function move_picked_sites(PDO $db, array $config, string $group): void
{
    if (strtolower(trim($group)) !== 'none' && !find_group($db, $group)) {
        if (!confirm("There is no group called {$group}. Add it?", true)) fail("No group called \"{$group}\". See `php vaka group:list`.");
        try {
            add_group($db, ['name' => $group]);
        } catch (InvalidArgumentException $e) {
            fail($e->getMessage() . '.');
        }
        echo "Added group {$group}. Change when and where it is alerted with `php vaka group:edit {$group}`.\n";
    }
    $sites = list_sites($db);
    if (!$sites) fail('No sites yet. Add one with `php vaka site:add <url>`.');
    $options = [];
    foreach ($sites as $site) $options[$site['id']] = site_choice($site);
    $picked = choose_many('Which sites?', $options, '0 for all, Enter for none');
    if (!$picked) {
        echo "Nothing moved.\n";
        return;
    }
    move_sites($db, $config, $picked === true ? $sites : array_values(array_filter($sites, fn ($site) => in_array($site['id'], $picked, true))), $group);
}

/** Move $sites into $group (or out of their group with "none"), and say which, and who that shows them to. */
function move_sites(PDO $db, array $config, array $sites, string $group, string $where = ''): void
{
    $readers = group_readers($db, $config['users_file'], time());
    try {
        foreach ($sites as $site) update_site($db, $site['id'], ['group' => $group]);
    } catch (InvalidArgumentException $e) {
        fail($e->getMessage() . '.');
    }
    $names = array_map(fn (array $site) => display_url($site['url']), $sites);
    $more = count($names) - 15;
    $list = $more > 0 ? implode(', ', array_slice($names, 0, 15)) . " and {$more} more" : list_words($names);
    $into = strtolower(trim($group)) === 'none' ? null : find_group($db, $group);
    $to = $into === null
        ? 'out of ' . (count($sites) === 1 ? 'its' : 'their') . ' group'
        : 'to ' . $into['name'];
    echo 'Moved ' . plural(count($sites), 'site', 'sites') . "{$where} {$to}: {$list}.\n";
    echo readers_lines(readers_change($readers, array_column($sites, 'group_id'), $into['id'] ?? null));
}

/**
 * Who a move shows sites to and hides them from, a line each: a login or a
 * read token for a group follows its sites. Nothing when it changes nothing
 * for anyone.
 */
function readers_lines(array $change): string
{
    $lines = $change['gain'] ? 'Now also visible to: ' . implode(', ', $change['gain']) . ".\n" : '';
    return $lines . ($change['lose'] ? 'No longer visible to: ' . implode(', ', $change['lose']) . ".\n" : '');
}

function cmd_site_remove(array $config, array $args, array $opts): void
{
    allow_options($opts, ['force']);
    $db = get_db($config['db_path']);
    $site = pick_site($db, $args[1] ?? null);

    if (empty($opts['force']) && !confirm('Remove ' . readable_url($site['url']) . ' and its history?')) {
        echo "Nothing removed.\n";
        exit(1);
    }
    remove_site($db, $site['id']);
    echo 'Removed ' . readable_url($site['url']) . ".\n";
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
        return [readable_url($site['url']), $site['group_name'] ?? '-', site_interval($site, $config) . ' min', implode('; ', $notes)];
    }, $sites));

    $paused = count(array_filter(array_column($sites, 'paused')));
    echo "\n" . plural(count($sites), 'site', 'sites') . ($paused ? ", {$paused} paused" : '') . ".\n";
}

function cmd_site_import(array $config, array $args, array $opts): void
{
    allow_options($opts, ['dry-run']);
    $path = $args[1] ?? ask('CSV file to import');
    if ($path === '') fail('A file is needed: php vaka site:import <file.csv>');
    if (!is_file($path) || !is_readable($path)) fail(unreadable_file($path, 'site:import'));

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
