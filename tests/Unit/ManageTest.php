<?php

beforeEach(function () {
    $this->db = get_db(temp_dir() . '/vaka.sqlite');
    add_group($this->db, ['name' => 'Customers']);
    $this->now = strtotime('2026-10-26T12:00:00Z');
});

test('site_form_input: every field the CLI would refuse is named, with why', function () {
    add_site($this->db, ['url' => 'https://shop.example']);
    $checked = site_form_input($this->db, ['url' => 'shop.example', 'group' => 'Nobody', 'interval' => '0', 'name' => str_repeat('x', 101), 'checks' => ['uptime', 'sometimes']]);

    expect(array_keys($checked['errors']))->toBe(['url', 'group', 'name', 'interval', 'checks'])
        ->and($checked['errors']['url'])->toBe('https://shop.example is already watched.')
        ->and($checked['errors']['interval'])->toBe('The interval is a number of minutes between 1 and 1440.')
        ->and($checked['errors']['checks'])->toBe('"sometimes" is not a check.')
        ->and(site_form_input($this->db, ['url' => ' '])['errors']['url'])->toBe('Enter the address of the site, such as https://shop.example.');

    // The checks that are on; the rest is turned off
    expect(site_form_input($this->db, ['url' => 'new.example', 'checks' => ['uptime', 'ssl']])['input']['disabled_checks'])->toBe('domain,exposed,headers,dns')
        ->and(site_form_input($this->db, ['url' => 'new.example', 'checks' => []])['input']['disabled_checks'])->toBe(implode(',', CHECKS));
});

test('save_site_form adds a site, changes it, and writes who did what in its history', function () {
    $added = save_site_form($this->db, test_config(), ['url' => 'shop.example', 'group' => 'Customers', 'checks' => CHECKS], null, 'admin', $this->now);
    expect($added['errors'])->toBe([])
        ->and(find_site($this->db, 'shop.example'))->toMatchArray(['group_id' => find_group($this->db, 'Customers')['id'], 'disabled_checks' => '']);

    $changed = save_site_form($this->db, test_config(), ['url' => 'https://shop.example/start', 'group' => 'Customers', 'name' => 'The shop', 'keyword' => 'Welcome', 'interval' => '', 'checks' => ['uptime', 'ssl', 'domain', 'exposed', 'headers']], $added['id'], 'admin', $this->now + 60);
    expect($changed['changes'])->toBe(['address now shop.example/start', 'named "The shop"', 'looks for "Welcome"', 'Mail DNS turned off']);

    $again = save_site_form($this->db, test_config(), ['url' => 'https://shop.example/start', 'group' => 'Customers', 'name' => 'The shop', 'keyword' => 'Welcome', 'checks' => ['uptime', 'ssl', 'domain', 'exposed', 'headers']], $added['id'], 'admin', $this->now + 120);
    expect($again['changes'])->toBe([]);

    $events = $this->db->query("SELECT message FROM events WHERE check_name = 'settings' ORDER BY id")->fetchAll(PDO::FETCH_COLUMN);
    expect($events)->toBe(['Added by admin', 'Changed by admin: address now shop.example/start, named "The shop", looks for "Welcome", Mail DNS turned off']);
});

test('saving a site without changing what is checked keeps every check on its schedule', function () {
    $saved = save_site_form($this->db, test_config(), ['url' => 'shop.example', 'checks' => CHECKS], null, 'admin', $this->now);
    $schedule = fn () => $this->db->query('SELECT check_name, next_at FROM check_state ORDER BY check_name')->fetchAll(PDO::FETCH_KEY_PAIR);
    foreach (['uptime', 'dns', 'headers'] as $check) {
        save_check_state($this->db, $saved['id'], $check, ['status' => 'ok', 'summary' => '', 'details' => [], 'fail_count' => 0, 'changed_at' => utc_now($this->now)], utc_now($this->now), utc_now($this->now + 3600));
    }
    $before = $schedule();

    // The form always sends every field; only a real change may move a check forward
    save_site_form($this->db, test_config(), ['url' => 'https://shop.example', 'name' => 'The shop', 'checks' => CHECKS], $saved['id'], 'admin', $this->now + 60);
    expect($schedule())->toBe($before);
    save_site_form($this->db, test_config(), ['url' => 'https://shop.example', 'name' => 'The shop', 'keyword' => 'Welcome', 'checks' => CHECKS], $saved['id'], 'admin', $this->now + 120);
    expect($schedule())->toBe(['dns' => $before['dns'], 'headers' => $before['headers'], 'uptime' => null]);
});

test('describe_site_changes says how the interval, the group, pausing and the checks changed', function () {
    $site = find_site($this->db, (string) add_site($this->db, ['url' => 'https://shop.example', 'group' => 'Customers', 'interval' => '2', 'disabled_checks' => 'dns']));
    $after = ['group_id' => null, 'interval_minutes' => null, 'paused' => 1, 'disabled_checks' => 'exposed'] + $site;
    expect(describe_site_changes($this->db, $site, $after, 5))->toBe(['no group', 'checked every 5 min (the default)', 'paused', 'Exposed files turned off', 'Mail DNS turned on']);
});

// =====================================================================
// Groups
// =====================================================================

test('group_form_input: errors per field, and webhooks stay as they are unless a new one is typed or cleared', function () {
    $group = get_group($this->db, find_group($this->db, 'Customers')['id']);
    $this->db->exec("UPDATE groups SET slack_webhook = 'https://hooks.example/services/a/b/secret1234'");
    $group = get_group($this->db, $group['id']);

    $bad = group_form_input($this->db, ['name' => '', 'policy' => 'delayed', 'delay' => '', 'slack' => 'http://hooks.example/x', 'email' => 'nobody'], $group);
    expect(array_keys($bad['errors']))->toBe(['name', 'delay', 'slack', 'email'])
        ->and($bad['errors']['delay'])->toBe('Say after how many minutes down, from 1 to 1440.');

    expect(group_form_input($this->db, ['name' => 'Customers', 'policy' => 'immediate', 'slack' => ''], $group)['input'])->not->toHaveKey('slack_webhook')
        ->and(group_form_input($this->db, ['name' => 'Customers', 'policy' => 'immediate', 'slack_clear' => true], $group)['input']['slack_webhook'])->toBe('')
        ->and(group_form_input($this->db, ['name' => 'Customers', 'policy' => 'immediate', 'slack' => 'https://hooks.example/new'], $group)['input']['slack_webhook'])->toBe('https://hooks.example/new');

    add_group($this->db, ['name' => 'Own']);
    expect(group_form_input($this->db, ['name' => 'own', 'policy' => 'immediate'], $group)['errors']['name'])->toBe('A group called "Own" already exists.');
});

test('renaming a group in the dashboard renames it for the users limited to it, as the CLI does', function () {
    $users = temp_dir() . '/users.json';
    save_users($users, ['anna' => ['password' => 'x', 'groups' => ['Customers', 'Own']], 'admin' => ['password' => 'x', 'groups' => []]]);
    $id = find_group($this->db, 'Customers')['id'];

    save_group_form($this->db, test_config(['users_file' => $users]), ['name' => 'Clients', 'policy' => 'immediate'], $id, 'admin', $this->now);
    expect(load_users($users)['anna']['groups'])->toBe(['Clients', 'Own'])
        ->and(load_users($users)['admin']['groups'])->toBe([]);
});

test('save_group_form tells every site in the group what changed, never the webhook itself', function () {
    $id = find_group($this->db, 'Customers')['id'];
    $shop = add_site($this->db, ['url' => 'https://shop.example', 'group' => 'Customers']);
    $config = test_config(['digest_time' => '08:00']);

    $saved = save_group_form($this->db, $config, ['name' => 'Clients', 'policy' => 'delayed', 'delay' => '10', 'slack' => 'https://hooks.example/services/a/b/secret1234', 'email' => 'ops@example.com'], $id, 'admin', $this->now);
    expect($saved['errors'])->toBe([])
        ->and($saved['changes'])->toBe(['renamed to Clients', 'alerts go out after 10 min down', 'Slack webhook added', 'email to ops@example.com'])
        ->and($this->db->query("SELECT message FROM events WHERE site_id = {$shop} AND check_name = 'settings'")->fetchColumn())
            ->toBe('Group Clients changed by admin: renamed to Clients, alerts go out after 10 min down, Slack webhook added, email to ops@example.com')
        ->and(implode(' ', $this->db->query('SELECT message FROM events')->fetchAll(PDO::FETCH_COLUMN)))->not->toContain('secret1234');

    $users = temp_dir() . '/users.json';
    save_users($users, ['anna' => ['password' => 'x', 'groups' => ['Clients']]]);
    expect(fn () => remove_group_form($this->db, ['users_file' => $users] + $config, $id, 'admin', '192.0.2.1', $this->now))
        ->toThrow(InvalidArgumentException::class, 'Clients cannot be removed while users are limited to it: anna.');
    save_users($users, ['anna' => ['password' => 'x', 'groups' => ['Own']]]);

    $removed = remove_group_form($this->db, ['users_file' => $users] + $config, $id, 'admin', '192.0.2.1', $this->now);
    expect($removed)->toBe(['name' => 'Clients', 'sites' => 1])
        ->and(find_site($this->db, 'shop.example')['group_id'])->toBeNull()
        ->and($this->db->query("SELECT message FROM events WHERE site_id = {$shop} ORDER BY id DESC")->fetchColumn())->toBe('Group Clients removed by admin: no group now, so its alerts go to the defaults in .env');
});

// =====================================================================
// Maintenance windows and servers
// =====================================================================

test('maintenance_form_fields: every day, some days or once, from the form, with plain words for what is missing', function () {
    $tz = 'Europe/Stockholm';
    expect(maintenance_form_fields(['kind' => 'daily', 'start' => '00:00', 'end' => '07:00', 'label' => 'Nightly imports'], $tz))
            ->toMatchArray(['days' => '1,2,3,4,5,6,7', 'start_time' => '00:00', 'end_time' => '07:00', 'label' => 'Nightly imports'])
        ->and(maintenance_form_fields(['kind' => 'days', 'start' => '22:00', 'end' => '02:00', 'days' => ['mon', 'wed']], $tz)['days'])->toBe('1,3')
        ->and(maintenance_form_fields(['kind' => 'once', 'from' => '2026-11-01T22:00', 'to' => '2026-11-02T02:00'], $tz))
            ->toMatchArray(['starts_at' => '2026-11-01T21:00:00Z', 'ends_at' => '2026-11-02T01:00:00Z'])
        ->and(fn () => maintenance_form_fields(['kind' => 'daily', 'start' => '', 'end' => '07:00'], $tz))->toThrow(InvalidArgumentException::class, 'Give the time it starts and the time it ends')
        ->and(fn () => maintenance_form_fields(['kind' => 'days', 'start' => '00:00', 'end' => '07:00', 'days' => []], $tz))->toThrow(InvalidArgumentException::class, 'Tick the days it is for')
        ->and(fn () => maintenance_form_fields(['kind' => 'once', 'from' => '', 'to' => ''], $tz))->toThrow(InvalidArgumentException::class, 'Give when it starts and when it ends');
});

test('a maintenance window added or removed in the dashboard is in the history of the sites it covers', function () {
    $group = find_group($this->db, 'Customers')['id'];
    $shop = add_site($this->db, ['url' => 'https://shop.example', 'group' => 'Customers']);
    $cafe = add_site($this->db, ['url' => 'https://cafe.example', 'group' => 'Customers']);
    $config = test_config(['timezone' => 'Europe/Stockholm']);
    $last = fn (int $site) => $this->db->query("SELECT message FROM events WHERE site_id = {$site} ORDER BY id DESC")->fetchColumn();

    $added = add_maintenance_form($this->db, $config, ['kind' => 'daily', 'start' => '00:00', 'end' => '07:00', 'label' => 'Imports'], null, $group, 'admin', $this->now);
    expect($added['when'])->toBe('every day 00:00-07:00')
        ->and($last($shop))->toBe('Maintenance window added by admin for group Customers: every day 00:00-07:00 (Imports)')
        ->and($last($cafe))->toBe($last($shop));

    $own = add_maintenance_form($this->db, $config, ['kind' => 'daily', 'start' => '12:00', 'end' => '13:00'], $shop, null, 'admin', $this->now);
    expect(remove_maintenance_form($this->db, $config, $own['id'], 'admin', $this->now))->toBe('every day 12:00-13:00')
        ->and($last($shop))->toBe('Maintenance window removed by admin: every day 12:00-13:00')
        ->and(remove_maintenance_form($this->db, $config, 999, 'admin', $this->now))->toBeNull();
});

test('save_server_form names a server and can move every site on it to a group', function () {
    $shop = add_site($this->db, ['url' => 'https://shop.example']);
    $cafe = add_site($this->db, ['url' => 'https://cafe.example', 'group' => 'Customers']);
    $cdn = add_site($this->db, ['url' => 'https://store.example']);
    $this->db->exec("UPDATE sites SET ip = '203.0.113.10'");
    $this->db->exec("UPDATE sites SET cdn = 'Cloudflare' WHERE id = {$cdn}");

    $saved = save_server_form($this->db, ['ip' => '203.0.113.10', 'label' => 'web-1', 'group' => 'Customers'], 'admin', $this->now);
    expect($saved)->toBe(['errors' => [], 'moved' => 1])
        ->and(server_labels($this->db))->toBe(['203.0.113.10' => 'web-1'])
        ->and(find_site($this->db, 'shop.example')['group_id'])->toBe(find_group($this->db, 'Customers')['id'])
        ->and(find_site($this->db, 'store.example')['group_id'])->toBeNull()
        ->and($this->db->query("SELECT message FROM events WHERE site_id = {$shop} ORDER BY id DESC")->fetchColumn())->toBe('Moved to group Customers by admin, with every site on server web-1 (203.0.113.10)');

    expect(save_server_form($this->db, ['ip' => '203.0.113.10', 'label' => '', 'group' => null], 'admin', $this->now)['moved'])->toBe(0)
        ->and(server_labels($this->db))->toBe([])
        ->and(save_server_form($this->db, ['ip' => '203.0.113.10', 'label' => str_repeat('x', 61)], 'admin', $this->now)['errors'])->toHaveKey('label')
        ->and(save_server_form($this->db, ['ip' => '203.0.113.10', 'label' => '', 'group' => 'Nobody'], 'admin', $this->now)['errors'])->toHaveKey('group');
});
