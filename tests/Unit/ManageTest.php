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
