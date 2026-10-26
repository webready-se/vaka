<?php

/**
 * Changing sites from the dashboard, for users who see every site: the same
 * rules as the CLI (clean_site_fields()), each error tied to its field, and
 * every change written to the site's history with who made it.
 */

/** The checks a site can have, as the dashboard names them. */
const CHECK_LABELS = ['uptime' => 'Uptime', 'ssl' => 'Certificate', 'domain' => 'Domain expiry', 'exposed' => 'Exposed files', 'headers' => 'Security headers', 'dns' => 'Mail DNS'];

/**
 * A site form's values as clean_site_fields() takes them, and an error for
 * each field it would refuse. `checks` in the form lists the checks that
 * are on; the others are turned off.
 *
 * @return array{input: array, errors: array<string, string>}
 */
function site_form_input(PDO $db, array $form, int $id = 0): array
{
    $text = fn (string $key) => is_scalar($form[$key] ?? null) ? trim((string) $form[$key]) : '';
    $checks = array_values(array_filter((array) ($form['checks'] ?? []), 'is_string'));
    $input = [
        'url' => $text('url'),
        'group' => $text('group'),
        'name' => $text('name'),
        'keyword' => $text('keyword'),
        'interval' => $text('interval'),
        'paused' => !empty($form['paused']),
        'disabled_checks' => implode(',', array_diff(CHECKS, $checks)),
    ];

    $errors = [];
    if ($input['url'] === '') $errors['url'] = 'Enter the address of the site, such as https://shop.example.';
    foreach ($input as $key => $value) {
        if (isset($errors[$key])) continue;
        try {
            $fields = clean_site_fields($db, [$key => $value]);
            if ($key === 'url') assert_url_free($db, $fields['url'], $id);
        } catch (InvalidArgumentException $e) {
            $errors[$key] = $e->getMessage() . '.';
        }
    }
    foreach ($checks as $check) {
        if (!isset(CHECK_LABELS[$check])) $errors['checks'] = "\"{$check}\" is not a check.";
    }
    return ['input' => $input, 'errors' => $errors];
}

/** What changed between two versions of a site, in words for its history: "paused", "Mail DNS turned off". */
function describe_site_changes(PDO $db, array $old, array $new, int $defaultInterval): array
{
    $changes = [];
    if ($old['url'] !== $new['url']) $changes[] = 'address now ' . display_url($new['url']);
    if ($old['group_id'] !== $new['group_id']) $changes[] = $new['group_id'] === null ? 'no group' : 'moved to ' . get_group($db, (int) $new['group_id'])['name'];
    if ($old['name'] !== $new['name']) $changes[] = $new['name'] === '' ? 'name removed' : "named \"{$new['name']}\"";
    if ($old['keyword'] !== $new['keyword']) $changes[] = $new['keyword'] === '' ? 'no longer looks for a text' : "looks for \"{$new['keyword']}\"";
    if ($old['interval_minutes'] !== $new['interval_minutes']) {
        $changes[] = 'checked every ' . ($new['interval_minutes'] ?? $defaultInterval) . ' min' . ($new['interval_minutes'] === null ? ' (the default)' : '');
    }
    if ((bool) $old['paused'] !== (bool) $new['paused']) $changes[] = $new['paused'] ? 'paused' : 'resumed';
    $was = explode(',', $old['disabled_checks']);
    $now = explode(',', $new['disabled_checks']);
    foreach (CHECK_LABELS as $key => $label) {
        if (in_array($key, $now, true) && !in_array($key, $was, true)) $changes[] = "{$label} turned off";
        if (!in_array($key, $now, true) && in_array($key, $was, true)) $changes[] = "{$label} turned on";
    }
    return $changes;
}

/**
 * Add a site ($id null) or change one from the dashboard. Nothing is saved
 * while a field has an error. A change is written to the site's history.
 *
 * @return array{id: ?int, changes: list<string>, errors: array<string, string>}
 */
function save_site_form(PDO $db, array $config, array $form, ?int $id, string $user, int $now): array
{
    $checked = site_form_input($db, $form, $id ?? 0);
    if ($checked['errors']) return ['id' => $id, 'changes' => [], 'errors' => $checked['errors']];

    if ($id === null) {
        $id = add_site($db, $checked['input']);
        add_event($db, $id, 'settings', ['status' => 'changed', 'previous' => '', 'message' => "Added by {$user}"], utc_now($now));
        return ['id' => $id, 'changes' => [], 'errors' => []];
    }
    $old = find_site($db, (string) $id);
    // The form sends every field: pass on only what changed, so the checks that did not change keep their schedule
    $columns = ['url' => 'url', 'group' => 'group_id', 'name' => 'name', 'keyword' => 'keyword', 'interval' => 'interval_minutes', 'paused' => 'paused', 'disabled_checks' => 'disabled_checks'];
    $clean = clean_site_fields($db, $checked['input']);
    $changed = array_filter($checked['input'], fn ($value, $key) => $clean[$columns[$key]] != $old[$columns[$key]], ARRAY_FILTER_USE_BOTH);
    update_site($db, $id, $changed);
    $changes = describe_site_changes($db, $old, find_site($db, (string) $id), $config['uptime_interval']);
    if ($changes) add_event($db, $id, 'settings', ['status' => 'changed', 'previous' => '', 'message' => "Changed by {$user}: " . implode(', ', $changes)], utc_now($now));
    return ['id' => $id, 'changes' => $changes, 'errors' => []];
}

/** Remove a site and its history. Its history goes with it, so the removal is kept in the audit log. Returns the site as people write it. */
function remove_site_form(PDO $db, int $id, string $user, string $ip, int $now): string
{
    $site = find_site($db, (string) $id);
    remove_site($db, $id);
    audit_log($db, $user, 'site_removed ' . $site['url'], $ip, $now);
    return display_url($site['url']);
}

// =====================================================================
// Groups
// =====================================================================

/** Form names for group columns, to tie an error to its field. */
const GROUP_FORM_FIELDS = ['name' => 'name', 'policy' => 'policy', 'delay_minutes' => 'delay', 'slack_webhook' => 'slack', 'webhook_url' => 'webhook', 'email' => 'email'];

/**
 * A group form's values as clean_group_fields() takes them, and an error
 * for each field it would refuse. The webhooks are secrets the browser
 * never gets back in full, so an empty field keeps the current one; a new
 * one replaces it, and `slack_clear` or `webhook_clear` removes it.
 *
 * @return array{input: array, errors: array<string, string>}
 */
function group_form_input(PDO $db, array $form, ?array $current): array
{
    $text = fn (string $key) => is_scalar($form[$key] ?? null) ? trim((string) $form[$key]) : '';
    $input = ['name' => $text('name'), 'policy' => $text('policy') ?: 'immediate', 'delay_minutes' => $text('delay'), 'email' => $text('email')];
    foreach (['slack' => 'slack_webhook', 'webhook' => 'webhook_url'] as $key => $column) {
        if (!empty($form[$key . '_clear'])) $input[$column] = '';
        elseif ($text($key) !== '') $input[$column] = $text($key);
    }

    $errors = [];
    foreach ($input as $column => $value) {
        try {
            $fields = clean_group_fields([$column => $value]);
            if ($column === 'name') assert_group_name_free($db, $fields['name'], (int) ($current['id'] ?? 0));
        } catch (InvalidArgumentException $e) {
            $errors[GROUP_FORM_FIELDS[$column]] = $e->getMessage() . '.';
        }
    }
    if (!isset($errors['policy'], $errors['delay']) && $input['policy'] === 'delayed' && (int) $input['delay_minutes'] < 1) {
        $errors['delay'] ??= 'Say after how many minutes down, from 1 to 1440.';
    }
    // In the order of the form, so the first error is the first field to fix
    $errors = array_filter(array_merge(array_fill_keys(GROUP_FORM_FIELDS, null), $errors));
    return ['input' => $input, 'errors' => $errors];
}

/** What changed in a group, in words for its sites' history. Webhooks are named, never shown. */
function describe_group_changes(array $old, array $new, string $digestTime): array
{
    $changes = [];
    if ($old['name'] !== $new['name']) $changes[] = "renamed to {$new['name']}";
    if (describe_policy($old, $digestTime) !== describe_policy($new, $digestTime)) $changes[] = 'alerts go out ' . describe_policy($new, $digestTime);
    foreach (['slack_webhook' => 'Slack webhook', 'webhook_url' => 'JSON webhook'] as $column => $label) {
        if ($old[$column] === $new[$column]) continue;
        $changes[] = $label . ' ' . match (true) {
            $new[$column] === '' => 'removed',
            $old[$column] === '' => 'added',
            default => 'replaced',
        };
    }
    if ($old['email'] !== $new['email']) $changes[] = $new['email'] === '' ? 'no email' : "email to {$new['email']}";
    return $changes;
}

/** Write a change to the history of every site in a group. */
function group_history(PDO $db, int $groupId, string $message, int $now): void
{
    $stmt = $db->prepare('SELECT id FROM sites WHERE group_id = ?');
    $stmt->execute([$groupId]);
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $siteId) {
        add_event($db, (int) $siteId, 'settings', ['status' => 'changed', 'previous' => '', 'message' => $message], utc_now($now));
    }
}

/**
 * Add a group ($id null) or change one from the dashboard. Nothing is saved
 * while a field has an error. A change goes in the history of its sites.
 *
 * @return array{id: ?int, changes: list<string>, errors: array<string, string>}
 */
function save_group_form(PDO $db, array $config, array $form, ?int $id, string $user, int $now): array
{
    $old = $id === null ? null : get_group($db, $id);
    $checked = group_form_input($db, $form, $old);
    if ($checked['errors']) return ['id' => $id, 'changes' => [], 'errors' => $checked['errors']];

    if ($id === null) return ['id' => add_group($db, $checked['input']), 'changes' => [], 'errors' => []];
    update_group($db, $id, $checked['input']);
    $new = get_group($db, $id);
    // Users limited to the group keep it under its new name (as group:edit does), and never get another group that takes the old name
    if ($new['name'] !== $old['name']) rename_group_in_users($config['users_file'], $old['name'], $new['name']);
    $changes = describe_group_changes($old, $new, $config['digest_time']);
    if ($changes) group_history($db, $id, "Group {$new['name']} changed by {$user}: " . implode(', ', $changes), $now);
    return ['id' => $id, 'changes' => $changes, 'errors' => []];
}

/**
 * Remove a group: its sites stay, without a group, and say so in their
 * history. Kept in the audit log too. Throws while users are limited to
 * the group (see assert_group_unused()).
 *
 * @return array{name: string, sites: int}
 */
function remove_group_form(PDO $db, array $config, int $id, string $user, string $ip, int $now): array
{
    $group = get_group($db, $id);
    assert_group_unused($config['users_file'], $group['name']);
    $sites = (int) $db->query('SELECT COUNT(*) FROM sites WHERE group_id = ' . $id)->fetchColumn();
    group_history($db, $id, "Group {$group['name']} removed by {$user}: no group now, so its alerts go to the defaults in .env", $now);
    remove_group($db, $id);
    audit_log($db, $user, 'group_removed ' . $group['name'], $ip, $now);
    return ['name' => $group['name'], 'sites' => $sites];
}

/** A group as its form shows it: the webhooks masked, never in full. */
function group_form_values(PDO $db, array $group): array
{
    return [
        'id' => $group['id'],
        'name' => $group['name'],
        'policy' => $group['policy'],
        'delay_minutes' => $group['delay_minutes'],
        'slack' => mask_secret($group['slack_webhook']),
        'webhook' => mask_secret($group['webhook_url']),
        'email' => $group['email'],
        'sites' => (int) $db->query('SELECT COUNT(*) FROM sites WHERE group_id = ' . (int) $group['id'])->fetchColumn(),
    ];
}
