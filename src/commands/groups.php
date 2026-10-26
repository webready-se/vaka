<?php

/** Group fields from --options (only those given). A bare --slack clears it. */
function group_options(array $opts): array
{
    $map = ['name' => 'name', 'policy' => 'policy', 'delay' => 'delay_minutes', 'slack' => 'slack_webhook', 'webhook' => 'webhook_url', 'email' => 'email'];
    $fields = [];
    foreach ($map as $opt => $field) {
        if (isset($opts[$opt])) $fields[$field] = $opts[$opt] === true ? '' : $opts[$opt];
    }
    return $fields;
}

/**
 * Ask for an optional value. When editing, Enter keeps the current value
 * and "-" clears it; secrets are shown masked.
 */
function ask_optional(string $label, string $current = '', bool $secret = false, string $skip = 'Enter to skip'): string
{
    if ($current === '') return prompt("{$label} ({$skip}): ");
    $shown = $secret ? mask_secret($current) : $current;
    $answer = prompt("{$label} [{$shown}] (Enter keeps it, - clears it): ");
    return match ($answer) {
        '' => $current,
        '-' => '',
        default => $answer,
    };
}

/** Ask for every group field; $current holds the values Enter keeps when editing. */
function ask_group_fields(?array $current = null): array
{
    $fields = ['name' => ask('Name', $current['name'] ?? '')];
    $fields['policy'] = choose('When should alerts go out?', [
        'immediate' => 'Right away',
        'delayed' => 'Only when a site has been down for a while',
        'digest' => 'Once a day, as a summary',
        'silent' => 'Never (dashboard only)',
    ], $current['policy'] ?? 'immediate') ?? 'immediate';
    if ($fields['policy'] === 'delayed') {
        $fields['delay_minutes'] = ask('Minutes down before alerting', (string) (($current['delay_minutes'] ?? 0) ?: 10));
    }
    $fields['slack_webhook'] = ask_optional('Slack webhook URL', $current['slack_webhook'] ?? '', true, 'Enter to use the default in .env');
    $fields['webhook_url'] = ask_optional('JSON webhook URL', $current['webhook_url'] ?? '', true);
    $fields['email'] = ask_optional('Email addresses, comma-separated', $current['email'] ?? '');
    return $fields;
}

/** The group a user means, by name, or picked from a list when no name is given. */
function pick_group(PDO $db, ?string $name): array
{
    if ($name !== null) {
        return find_group($db, $name) ?? fail("No group called \"{$name}\". See `php vaka group:list`.");
    }
    $groups = list_groups($db);
    if (!$groups) fail('No groups yet. Add one with `php vaka group:add <name>`.');
    $id = choose('Which group?', array_column(array_map(fn ($group) => [
        'id' => $group['id'],
        'label' => $group['name'] . ' (' . ($group['site_count'] ? plural((int) $group['site_count'], 'site', 'sites') : 'no sites') . ')',
    ], $groups), 'label', 'id'));
    if ($id === null) fail('No group chosen.');
    return get_group($db, (int) $id);
}

/** A name as it can be pasted back into a command. */
function cli_quote(string $value): string
{
    return preg_match('/^[\w.@-]+$/u', $value) ? $value : '"' . str_replace('"', '\"', $value) . '"';
}

function cmd_group_add(array $config, array $args, array $opts): void
{
    allow_options($opts, ['policy', 'delay', 'slack', 'webhook', 'email']);
    $db = get_db($config['db_path']);
    $fields = isset($args[1]) ? ['name' => $args[1]] + group_options($opts) : ask_group_fields();

    try {
        $group = get_group($db, add_group($db, $fields));
    } catch (InvalidArgumentException $e) {
        fail($e->getMessage() . '.');
    }
    echo "Added group {$group['name']}. " . describe_alerting($group, $config) . "\n";
    echo 'Add sites to it with `php vaka site:add <url> --group=' . cli_quote($group['name']) . "`.\n";
}

function cmd_group_edit(array $config, array $args, array $opts): void
{
    allow_options($opts, ['name', 'policy', 'delay', 'slack', 'webhook', 'email']);
    $db = get_db($config['db_path']);
    $group = pick_group($db, $args[1] ?? null);
    $fields = group_options($opts) ?: ask_group_fields($group);

    try {
        update_group($db, $group['id'], $fields);
    } catch (InvalidArgumentException $e) {
        fail($e->getMessage() . '.');
    }
    $renamed = get_group($db, $group['id']);
    if ($renamed['name'] !== $group['name']) rename_group_in_users($config['users_file'], $group['name'], $renamed['name']);
    $group = $renamed;
    echo "Updated group {$group['name']}. " . describe_alerting($group, $config) . "\n";
}

function cmd_group_remove(array $config, array $args, array $opts): void
{
    allow_options($opts, ['force']);
    $db = get_db($config['db_path']);
    $group = pick_group($db, $args[1] ?? null);
    $count = count(list_sites($db, $group['id']));

    if (empty($opts['force'])) {
        $stay = $count ? ' Its ' . plural($count, 'site stays', 'sites stay') . ', without a group.' : '';
        if (!confirm("Remove group {$group['name']}?{$stay}")) {
            echo "Nothing removed.\n";
            exit(1);
        }
    }
    remove_group($db, $group['id']);
    echo "Removed group {$group['name']}." . ($count ? ' ' . plural($count, 'site no longer has', 'sites no longer have') . ' a group.' : '') . "\n";
}

function cmd_group_list(array $config, array $args, array $opts): void
{
    allow_options($opts, []);
    $groups = list_groups(get_db($config['db_path']));
    if (!$groups) {
        echo "No groups yet. Sites without a group alert to the defaults in .env.\n";
        echo "Add one with `php vaka group:add <name>`.\n";
        return;
    }
    print_table(['Group', 'Sites', 'Alerts go out', 'Alerts go to'], array_map(fn ($group) => [
        $group['name'],
        $group['site_count'],
        describe_policy($group, $config['digest_time']),
        describe_destinations($group, $config),
    ], $groups));
}
