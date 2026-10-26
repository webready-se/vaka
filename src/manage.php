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
