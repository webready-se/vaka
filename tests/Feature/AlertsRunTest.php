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
    $this->slack = 'http://127.0.0.1:8797/slack?log=' . urlencode($this->log);
    $this->config = test_config([
        'db_path' => $dir . '/vaka.sqlite',
        'heartbeat_url' => '',
        'slack_webhook_url' => '',
        'webhook_url' => '',
        'alert_email' => [],
        'app_url' => '',
        'timezone' => 'Europe/Stockholm',
        'digest_time' => '08:00',
    ]);
    $this->now = strtotime('2026-10-25T12:00:00Z');
    $this->received = function (string $path = '/slack'): array {
        if (!file_exists($this->log)) return [];
        $lines = array_map(fn ($line) => json_decode($line, true), file($this->log, FILE_IGNORE_NEW_LINES));
        return array_values(array_filter($lines, fn ($line) => $line['path'] === $path));
    };
    $this->texts = fn () => array_map(fn ($line) => $line['body']['text'], ($this->received)());
});

test('a site that goes down is one Slack message, and so is its recovery', function () {
    add_group($this->db, ['name' => 'Customers', 'slack_webhook' => $this->slack]);
    $id = add_site($this->db, ['url' => 'http://127.0.0.1:8792', 'group' => 'Customers']);

    run($this->db, $this->config, $this->now);
    expect(($this->texts)())->toBe([]);

    $summary = run($this->db, $this->config, $this->now + 60);
    expect(($this->texts)())->toHaveCount(1)
        ->and(($this->texts)()[0])->toContain(':red_circle: *127.0.0.1:8792 (Customers) is down*')
        ->and(($this->texts)()[0])->toContain('The site answered 500 Internal Server Error')
        ->and($summary['alerts'][0]['ok'])->toBeTrue();

    run($this->db, $this->config, $this->now + 120);
    expect(($this->texts)())->toHaveCount(1);

    update_site($this->db, $id, ['url' => 'http://127.0.0.1:8791']);
    run($this->db, $this->config, $this->now + 180);
    expect(($this->texts)())->toHaveCount(2)
        ->and(($this->texts)()[1])->toContain(':large_green_circle: *127.0.0.1:8791 (Customers) is up again after 3 min*');
});

test('a site that keeps going down and up gets three messages in all, not one per change', function () {
    add_group($this->db, ['name' => 'Customers', 'slack_webhook' => $this->slack]);
    $id = add_site($this->db, ['url' => 'http://127.0.0.1:8792', 'group' => 'Customers', 'interval' => '1']);
    $flip = fn (string $port) => update_site($this->db, $id, ['url' => "http://127.0.0.1:{$port}"]);

    // Down, up, down, up, down, up, a minute or two apart
    $t = $this->now;
    foreach (['8792', '8792', '8791', '8792', '8792', '8791', '8792', '8792', '8791'] as $port) {
        $flip($port);
        run($this->db, $this->config, $t);
        $t += 60;
    }
    expect(($this->texts)())->toHaveCount(3)
        ->and(($this->texts)()[0])->toContain('is down')
        ->and(($this->texts)()[1])->toContain('is up again')
        ->and(($this->texts)()[2])->toContain(':warning: *127.0.0.1:8792 (Customers) is unstable: down 2 times in 30 min*');

    // Stable for 15 minutes: one message to close it
    for ($i = 0; $i < 16; $i++) {
        run($this->db, $this->config, $t);
        $t += 60;
    }
    expect(($this->texts)())->toHaveCount(4)
        ->and(($this->texts)()[3])->toContain('is stable again: up for 15 min');

    // Back to normal: the next outage is an ordinary alert again
    $flip('8792');
    run($this->db, $this->config, $t + 1800);
    run($this->db, $this->config, $t + 1860);
    expect(($this->texts)())->toHaveCount(5)
        ->and(($this->texts)()[4])->toContain('is down');
});

test('a server going down is one message, not one per site', function () {
    add_group($this->db, ['name' => 'Customers', 'slack_webhook' => $this->slack]);
    $a = add_site($this->db, ['url' => 'http://127.0.0.1:8792/a', 'group' => 'Customers']);
    $b = add_site($this->db, ['url' => 'http://127.0.0.1:8792/b', 'group' => 'Customers']);
    $c = add_site($this->db, ['url' => 'http://127.0.0.1:8792/c', 'group' => 'Customers']);
    $up = add_site($this->db, ['url' => 'http://127.0.0.1:8791', 'group' => 'Customers']);
    // Only /a is due: the others were checked a minute ago and their IP is known
    $healthy = ['status' => 'ok', 'summary' => '', 'details' => [], 'fail_count' => 0, 'changed_at' => utc_now($this->now - 3600)];
    foreach ([$b, $c, $up] as $id) {
        save_check_state($this->db, $id, 'uptime', $healthy, utc_now($this->now - 60), utc_now($this->now + 240));
        $this->db->exec("UPDATE check_state SET alerted_status = 'ok' WHERE site_id = {$id}");
        $this->db->exec("UPDATE sites SET ip = '127.0.0.1' WHERE id = {$id}");
    }

    // /a fails for the first time, so the other sites on its server are checked right away too
    $first = run($this->db, $this->config, $this->now);
    expect($first['checked'])->toBe(4)
        ->and($first['failing'])->toBe(3);

    // A minute later all three are confirmed down in the same run
    run($this->db, $this->config, $this->now + 60);
    expect(($this->texts)())->toHaveCount(1)
        ->and(($this->texts)()[0])->toContain('*3 of 4 sites on server 127.0.0.1 are down*')
        ->and(($this->texts)()[0])->toContain('127.0.0.1:8792/a, 127.0.0.1:8792/b, 127.0.0.1:8792/c');
});

test('the delayed policy waits, and an outage shorter than the delay sends nothing', function () {
    add_group($this->db, ['name' => 'Patient', 'policy' => 'delayed', 'delay_minutes' => '5', 'slack_webhook' => $this->slack]);
    add_site($this->db, ['url' => 'http://127.0.0.1:8792', 'group' => 'Patient']);
    $short = add_site($this->db, ['url' => 'http://127.0.0.1:8791', 'group' => 'Patient', 'keyword' => 'Nowhere on the page']);

    run($this->db, $this->config, $this->now);
    run($this->db, $this->config, $this->now + 60);
    update_site($this->db, $short, ['keyword' => 'Welcome']);
    run($this->db, $this->config, $this->now + 240);
    expect(($this->texts)())->toBe([]);

    run($this->db, $this->config, $this->now + 300);
    expect(($this->texts)())->toHaveCount(1)
        ->and(($this->texts)()[0])->toContain('127.0.0.1:8792 (Patient) is down')
        ->and(($this->texts)()[0])->not->toContain('8791');
});

test('a silent group never sends, but its state moves on', function () {
    add_group($this->db, ['name' => 'Quiet', 'policy' => 'silent', 'slack_webhook' => $this->slack]);
    add_site($this->db, ['url' => 'http://127.0.0.1:8792', 'group' => 'Quiet']);
    run($this->db, $this->config, $this->now);
    run($this->db, $this->config, $this->now + 60);

    expect(($this->texts)())->toBe([])
        ->and($this->db->query('SELECT alerted_status FROM check_state')->fetchColumn())->toBe('fail');
});

test('a destination that fails is tried again on the next run', function () {
    $groupId = add_group($this->db, ['name' => 'Customers', 'slack_webhook' => $this->slack . '&status=500']);
    add_site($this->db, ['url' => 'http://127.0.0.1:8792', 'group' => 'Customers']);
    run($this->db, $this->config, $this->now);
    $summary = run($this->db, $this->config, $this->now + 60);
    expect($summary['alerts'][0]['ok'])->toBeFalse()
        ->and($this->db->query('SELECT alerted_status FROM check_state')->fetchColumn())->not->toBe('fail');

    update_group($this->db, $groupId, ['slack_webhook' => $this->slack]);
    run($this->db, $this->config, $this->now + 120);
    expect(($this->texts)())->toHaveCount(2)
        ->and(($this->texts)()[1])->toContain('is down')
        ->and($this->db->query('SELECT alerted_status FROM check_state')->fetchColumn())->toBe('fail');
});

test('the JSON webhook gets the alerts with stable fields', function () {
    add_group($this->db, ['name' => 'Customers', 'webhook_url' => 'http://127.0.0.1:8797/hook?log=' . urlencode($this->log)]);
    add_site($this->db, ['url' => 'http://127.0.0.1:8792', 'group' => 'Customers']);
    run($this->db, $this->config, $this->now);
    run($this->db, $this->config, $this->now + 60);

    $hook = ($this->received)('/hook');
    expect($hook)->toHaveCount(1)
        ->and($hook[0]['type'])->toBe('application/json')
        ->and($hook[0]['body']['source'])->toBe('vaka')
        ->and($hook[0]['body']['alerts'][0]['kind'])->toBe('down')
        ->and($hook[0]['body']['alerts'][0]['sites'][0]['url'])->toBe('http://127.0.0.1:8792');
});

test('groups with the digest policy get one summary a day instead of live alerts', function () {
    add_group($this->db, ['name' => 'Nightly', 'policy' => 'digest', 'slack_webhook' => $this->slack]);
    add_site($this->db, ['url' => 'http://127.0.0.1:8792', 'group' => 'Nightly']);
    run($this->db, $this->config, $this->now);
    run($this->db, $this->config, $this->now + 60);
    expect(($this->texts)())->toBe([]);

    // 08:30 in Stockholm the next morning
    run($this->db, $this->config, strtotime('2026-10-26T07:30:00Z'));
    expect(($this->texts)())->toHaveCount(1)
        ->and(($this->texts)()[0])->toContain('*Daily summary for Nightly: 1 outage, 1 site still down*')
        ->and(($this->texts)()[0])->toContain('Still down: 127.0.0.1:8792 since');

    run($this->db, $this->config, strtotime('2026-10-26T07:31:00Z'));
    expect(($this->texts)())->toHaveCount(1);
});

test('email alerts go out through mail()', function () {
    $mailbox = temp_dir() . '/mail.txt';
    $env = cli_env() + ['DOWN_AFTER_FAILURES' => '1', 'ALERT_EMAIL' => 'ops@example.com', 'ALERT_FROM' => 'vaka@example.com'];
    vaka(['site:add', 'http://127.0.0.1:8792'], $env);

    $r = vaka(['run'], $env, null, ['sendmail_path' => 'cat >> ' . escapeshellarg($mailbox)]);
    expect($r['exit'])->toBe(0, $r['err'])
        ->and($r['out'])->toContain('Alert sent to ops@example.com: 127.0.0.1:8792 is down');

    $mail = file_get_contents($mailbox);
    expect($mail)->toContain('To: ops@example.com')
        ->and($mail)->toContain('From: vaka@example.com')
        ->and($mail)->toContain('Subject: =?UTF-8?B?' . base64_encode('127.0.0.1:8792 is down') . '?=')
        ->and($mail)->toContain('The site answered 500 Internal Server Error');
});

test('php vaka server:label names a server in server:list and in alerts', function () {
    $env = cli_env() + ['DOWN_AFTER_FAILURES' => '1', 'SLACK_WEBHOOK_URL' => $this->slack];
    vaka(['site:add', 'http://127.0.0.1:8791'], $env);
    vaka(['site:add', 'http://127.0.0.1:8791/second'], $env);
    vaka(['run'], $env);

    expect(vaka(['server:label', '127.0.0.1', 'local-box'], $env)['out'])->toContain('Server 127.0.0.1 is called local-box now');
    $list = vaka(['server:list'], $env)['out'];
    expect($list)->toContain('local-box (127.0.0.1)')
        ->and($list)->toMatch('/local-box \(127\.0\.0\.1\)\s+2/');

    vaka(['site:edit', '127.0.0.1:8791', '--keyword=Not on the page'], $env);
    vaka(['site:edit', '127.0.0.1:8791/second', '--keyword=Not on the page'], $env);
    $r = vaka(['run', ], $env + ['UPTIME_INTERVAL' => '1'], null);
    expect($r['out'])->toContain('Server local-box (127.0.0.1) is not answering: all 2 sites are down');
});

test('php vaka alert:test sends a test message to a group', function () {
    $env = cli_env();
    vaka(['group:add', 'Customers', '--slack=' . $this->slack], $env);

    $r = vaka(['alert:test', 'Customers'], $env);
    expect($r['exit'])->toBe(0, $r['err'])
        ->and($r['out'])->toContain('Sent a test alert to Slack')
        ->and(($this->texts)()[0])->toContain('This is a test alert from Vaka for Customers');
});

test('php vaka alert:test explains a group with nowhere to send', function () {
    $env = cli_env();
    vaka(['group:add', 'Customers'], $env);
    $r = vaka(['alert:test', 'Customers'], $env);
    expect($r['exit'])->toBe(1)
        ->and($r['err'])->toContain('nowhere to send');
});
