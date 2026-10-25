<?php

beforeAll(function () {
    $GLOBALS['good'] = start_site('good', 8791);
    $GLOBALS['error'] = start_site('error', 8792);
    $GLOBALS['hooks'] = start_site('hooks', 8797);
});

afterAll(function () {
    stop_site($GLOBALS['good']);
    stop_site($GLOBALS['error']);
    stop_site($GLOBALS['hooks']);
});

beforeEach(function () {
    $dir = temp_dir();
    $this->db = get_db($dir . '/vaka.sqlite');
    $this->log = $dir . '/hooks.log';
    $this->config = test_config([
        'db_path' => $dir . '/vaka.sqlite',
        'heartbeat_url' => '',
        'slack_webhook_url' => 'http://127.0.0.1:8797/slack?log=' . urlencode($this->log),
        'webhook_url' => '',
        'alert_email' => [],
        'app_url' => '',
        'timezone' => 'Europe/Stockholm',
    ]);
    $this->now = strtotime('2026-11-03T02:00:00Z'); // 03:00 in Stockholm
    $this->texts = function (): array {
        if (!file_exists($this->log)) return [];
        return array_map(fn ($line) => json_decode($line, true)['body']['text'], file($this->log, FILE_IGNORE_NEW_LINES));
    };
    $this->group = add_group($this->db, ['name' => 'Nightly']);
});

test('an outage that starts and ends inside a maintenance window sends nothing', function () {
    $id = add_site($this->db, ['url' => 'http://127.0.0.1:8792', 'group' => 'Nightly', 'interval' => '1']);
    add_maintenance($this->db, null, $this->group, maintenance_fields(['at' => '00:00-07:00'], 'Europe/Stockholm'));

    run($this->db, $this->config, $this->now);
    run($this->db, $this->config, $this->now + 60);
    update_site($this->db, $id, ['url' => 'http://127.0.0.1:8791']);
    run($this->db, $this->config, $this->now + 120);

    expect(($this->texts)())->toBe([])
        ->and($this->db->query("SELECT silenced_by FROM events WHERE status = 'fail'")->fetchColumn())->toBe('maintenance');
});

test('a site still down when the window ends is alerted then', function () {
    add_site($this->db, ['url' => 'http://127.0.0.1:8792', 'group' => 'Nightly']);
    // A one-off window that ends five minutes from now
    add_maintenance($this->db, null, $this->group, ['label' => '', 'days' => '', 'start_time' => null, 'end_time' => null, 'starts_at' => utc_now($this->now - 3600), 'ends_at' => utc_now($this->now + 300)]);

    run($this->db, $this->config, $this->now);
    run($this->db, $this->config, $this->now + 60);
    run($this->db, $this->config, $this->now + 240);
    expect(($this->texts)())->toBe([]);

    run($this->db, $this->config, $this->now + 300);
    expect(($this->texts)())->toHaveCount(1)
        ->and(($this->texts)()[0])->toContain('127.0.0.1:8792 (Nightly) is down');
});

test('a window for one site leaves the other sites of its group alone', function () {
    $quiet = add_site($this->db, ['url' => 'http://127.0.0.1:8792/quiet', 'group' => 'Nightly']);
    add_site($this->db, ['url' => 'http://127.0.0.1:8792/loud', 'group' => 'Nightly']);
    add_maintenance($this->db, $quiet, null, maintenance_fields(['at' => '00:00-07:00'], 'Europe/Stockholm'));

    run($this->db, $this->config, $this->now);
    run($this->db, $this->config, $this->now + 60);
    expect(($this->texts)())->toHaveCount(1)
        ->and(($this->texts)()[0])->toContain('/loud')
        ->and(($this->texts)()[0])->not->toContain('/quiet');
});

test('outages during maintenance do not make a site look unstable afterwards', function () {
    $id = add_site($this->db, ['url' => 'http://127.0.0.1:8792', 'group' => 'Nightly', 'interval' => '1']);
    add_maintenance($this->db, null, $this->group, ['label' => '', 'days' => '', 'start_time' => null, 'end_time' => null, 'starts_at' => utc_now($this->now - 60), 'ends_at' => utc_now($this->now + 240)]);

    // Down and up during the window, then down again right after it
    foreach (['8792', '8792', '8791', '8792'] as $i => $port) {
        update_site($this->db, $id, ['url' => "http://127.0.0.1:{$port}"]);
        run($this->db, $this->config, $this->now + $i * 60);
    }
    run($this->db, $this->config, $this->now + 300);

    expect(($this->texts)())->toHaveCount(1)
        ->and(($this->texts)()[0])->toContain('is down')
        ->and(($this->texts)()[0])->not->toContain('unstable');
});

test('a snoozed incident is quiet, and alerts again if it is still down when the snooze ends', function () {
    $id = add_site($this->db, ['url' => 'http://127.0.0.1:8792', 'group' => 'Nightly']);
    run($this->db, $this->config, $this->now);
    run($this->db, $this->config, $this->now + 60);
    expect(($this->texts)())->toHaveCount(1);

    snooze_site($this->db, $id, 3600, $this->now + 120);
    run($this->db, $this->config, $this->now + 180);
    run($this->db, $this->config, $this->now + 3600);
    expect(($this->texts)())->toHaveCount(1);

    run($this->db, $this->config, $this->now + 120 + 3600);
    expect(($this->texts)())->toHaveCount(2)
        ->and(($this->texts)()[1])->toContain('127.0.0.1:8792 (Nightly) is still down after the snooze');

    run($this->db, $this->config, $this->now + 120 + 3660);
    expect(($this->texts)())->toHaveCount(2);
});

test('a recovery ends the snooze and is alerted', function () {
    $id = add_site($this->db, ['url' => 'http://127.0.0.1:8792', 'group' => 'Nightly']);
    run($this->db, $this->config, $this->now);
    run($this->db, $this->config, $this->now + 60);
    snooze_site($this->db, $id, 14400, $this->now + 120);

    update_site($this->db, $id, ['url' => 'http://127.0.0.1:8791']);
    run($this->db, $this->config, $this->now + 180);

    expect(($this->texts)())->toHaveCount(2)
        ->and(($this->texts)()[1])->toContain('is up again')
        ->and($this->db->query('SELECT snoozed_until FROM check_state')->fetchColumn())->toBeNull();
});

test('php vaka maintenance:add, maintenance:list and maintenance:remove', function () {
    $env = cli_env() + ['TIMEZONE' => 'Europe/Stockholm'];
    vaka(['group:add', 'Nightly'], $env);
    vaka(['site:add', 'http://127.0.0.1:8791', '--group=Nightly'], $env);

    $r = vaka(['maintenance:add', 'Nightly', '--at=00:00-07:00', '--label=Imports'], $env);
    expect($r['exit'])->toBe(0, $r['err'])
        ->and($r['out'])->toContain('Maintenance window added for group Nightly: every day 00:00-07:00');

    $r = vaka(['maintenance:add', '127.0.0.1:8791', '--from=2026-11-01 22:00', '--to=2026-11-02 02:00'], $env);
    expect($r['out'])->toContain('for 127.0.0.1:8791: once, 1 Nov 22:00 to 2 Nov 02:00');

    $list = vaka(['maintenance:list'], $env)['out'];
    expect($list)->toContain('group Nightly')
        ->and($list)->toContain('every day 00:00-07:00')
        ->and($list)->toContain('Imports')
        ->and($list)->toContain('127.0.0.1:8791');

    expect(vaka(['maintenance:remove', '1'], $env)['out'])->toContain('Removed the maintenance window for group Nightly')
        ->and(vaka(['maintenance:list'], $env)['out'])->not->toContain('group Nightly');
});

test('php vaka maintenance:add explains a target it cannot find', function () {
    $r = vaka(['maintenance:add', 'nothing.example', '--at=00:00-07:00'], cli_env());
    expect($r['exit'])->toBe(1)
        ->and($r['err'])->toContain('No group or site matches "nothing.example"');
});

test('php vaka snooze snoozes an incident, a server at once, and refuses a site that is up', function () {
    $env = cli_env() + ['DOWN_AFTER_FAILURES' => '1'];
    vaka(['site:add', 'http://127.0.0.1:8792/a'], $env);
    vaka(['site:add', 'http://127.0.0.1:8792/b'], $env);
    vaka(['site:add', 'http://127.0.0.1:8791'], $env);
    vaka(['run'], $env);

    $r = vaka(['snooze', '127.0.0.1:8792/a', '--for=4h'], $env);
    expect($r['exit'])->toBe(0, $r['err'])
        ->and($r['out'])->toContain('Snoozed 127.0.0.1:8792/a for 4 h')
        ->and($r['out'])->toContain('alerts again if it is still down then');

    expect(vaka(['snooze', '127.0.0.1:8791'], $env)['err'])->toContain('127.0.0.1:8791 is up: there is nothing to snooze');

    $r = vaka(['snooze', '127.0.0.1'], $env);
    expect($r['out'])->toContain('Snoozed 2 sites on server 127.0.0.1 for 1 h');

    expect(vaka(['status'], $env)['out'])->toContain('snoozed until');

    $r = vaka(['unsnooze', '127.0.0.1:8792/b'], $env);
    expect($r['out'])->toContain('127.0.0.1:8792/b is no longer snoozed');
});
