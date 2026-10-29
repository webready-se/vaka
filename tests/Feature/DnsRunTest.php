<?php

beforeAll(fn () => $GLOBALS['hooks'] = start_site('hooks', 8797));

afterAll(fn () => stop_site($GLOBALS['hooks']));

beforeEach(function () {
    $dir = temp_dir();
    $this->db = get_db($dir . '/vaka.sqlite');
    $this->log = $dir . '/hooks.log';
    $this->records = [
        'shop.example' => [
            DNS_MX => [['type' => 'MX', 'pri' => 10, 'target' => 'mx1.mail.example']],
            DNS_TXT => [txt('v=spf1 include:_spf.mail.example -all')],
        ],
        '_dmarc.shop.example' => [DNS_TXT => [txt('v=DMARC1; p=reject')]],
    ];
    $this->config = test_config([
        'db_path' => $dir . '/vaka.sqlite',
        'slack_webhook_url' => 'http://127.0.0.1:8797/slack?log=' . urlencode($this->log),
        'webhook_url' => '',
        'alert_email' => [],
        'app_url' => '',
        'dns_resolve' => fake_resolver($this->records),
    ]);
    $this->now = strtotime('2026-10-26T12:00:00Z');
    $this->texts = function (): array {
        if (!file_exists($this->log)) return [];
        return array_map(fn ($line) => json_decode($line, true)['body']['text'], file($this->log, FILE_IGNORE_NEW_LINES));
    };
    $this->state = fn (int $id) => $this->db->query("SELECT * FROM check_state WHERE site_id = {$id} AND check_name = 'dns'")->fetch();
    add_group($this->db, ['name' => 'Customers']);
});

test('the mail DNS check stores what it found and says nothing the first time', function () {
    $shop = add_site($this->db, ['url' => 'https://shop.example', 'group' => 'Customers']);
    $ip = add_site($this->db, ['url' => 'http://192.0.2.10']);

    $summary = run_dns($this->db, $this->config, $this->now, microtime(true) + 10);
    process_alerts($this->db, $this->config, $this->now);

    expect($summary['checked'])->toBe(2)
        ->and(($this->state)($shop))->toMatchArray(['status' => 'ok', 'summary' => 'Mail for shop.example: SPF and DMARC in place', 'next_at' => utc_now($this->now + DNS_INTERVAL_SECONDS)])
        ->and(($this->state)($ip))->toMatchArray(['status' => 'unknown', 'summary' => 'No domain to look up: the site is reached by its address'])
        ->and($this->db->query("SELECT message FROM events WHERE check_name = 'dns'")->fetchAll(PDO::FETCH_COLUMN))->toBe(['First check: mail for shop.example: SPF and DMARC in place'])
        ->and(($this->texts)())->toBe([]);
});

test('a confirmed change is one alert for the domain, however many sites share it', function () {
    $shop = add_site($this->db, ['url' => 'https://shop.example', 'group' => 'Customers']);
    add_site($this->db, ['url' => 'https://www.shop.example/blog', 'group' => 'Customers']);
    run_dns($this->db, $this->config, $this->now, microtime(true) + 10);
    process_alerts($this->db, $this->config, $this->now);

    // Someone points the mail elsewhere and drops DMARC
    $this->records['shop.example'][DNS_MX] = [['type' => 'MX', 'pri' => 10, 'target' => 'mx.evil.example']];
    unset($this->records['_dmarc.shop.example']);
    $later = $this->now + DNS_INTERVAL_SECONDS;
    run_dns($this->db, $this->config, $later, microtime(true) + 10);
    process_alerts($this->db, $this->config, $later);
    expect(($this->texts)())->toBe([])
        ->and(($this->state)($shop)['next_at'])->toBe(utc_now($later + DNS_CONFIRM_SECONDS));

    run_dns($this->db, $this->config, $later + DNS_CONFIRM_SECONDS, microtime(true) + 10);
    process_alerts($this->db, $this->config, $later + DNS_CONFIRM_SECONDS);
    expect(($this->texts)())->toHaveCount(1)
        ->and(($this->texts)()[0])->toContain(':warning: *shop.example (Customers): mail DNS changed*')
        ->and(($this->texts)()[0])->toContain('MX now 10 mx.evil.example (was 10 mx1.mail.example)')
        ->and(($this->texts)()[0])->toContain('DMARC removed (was v=DMARC1; p=reject)')
        ->and(substr_count(($this->texts)()[0], 'mail DNS changed'))->toBe(1)
        ->and(($this->state)($shop)['status'])->toBe('warn')
        // From what was confirmed, not from the first look at the change (already stored as warn)
        ->and($this->db->query("SELECT previous, status FROM events WHERE check_name = 'dns' AND site_id = {$shop} ORDER BY id DESC")->fetch(PDO::FETCH_NUM))->toBe(['ok', 'warn']);

    // Told once: nothing more until it changes again
    run_dns($this->db, $this->config, $later + DNS_CONFIRM_SECONDS + DNS_INTERVAL_SECONDS, microtime(true) + 10);
    process_alerts($this->db, $this->config, $later + DNS_CONFIRM_SECONDS + DNS_INTERVAL_SECONDS);
    expect(($this->texts)())->toHaveCount(1);
});

test('a failed lookup keeps what was known and looks again within the hour', function () {
    $shop = add_site($this->db, ['url' => 'https://shop.example', 'group' => 'Customers']);
    run_dns($this->db, $this->config, $this->now, microtime(true) + 10);
    $this->records['shop.example'][DNS_TXT] = false;

    $later = $this->now + DNS_INTERVAL_SECONDS;
    run_dns($this->db, $this->config, $later, microtime(true) + 10);
    process_alerts($this->db, $this->config, $later);
    $state = ($this->state)($shop);
    expect($state['status'])->toBe('unknown')
        ->and($state['next_at'])->toBe(utc_now($later + DNS_RETRY_SECONDS))
        ->and(json_decode($state['details'], true)['confirmed']['dmarc'])->toBe(['v=DMARC1; p=reject'])
        ->and(($this->texts)())->toBe([]);
});

test('a site with mail DNS turned off is not looked up', function () {
    add_site($this->db, ['url' => 'https://shop.example', 'disabled_checks' => 'dns']);
    expect(run_dns($this->db, $this->config, $this->now, microtime(true) + 10)['checked'])->toBe(0);
});

test('sites that share a domain are looked at together, so a change is one alert even when they were added at different times', function () {
    add_site($this->db, ['url' => 'https://shop.example', 'group' => 'Customers']);
    run_dns($this->db, $this->config, $this->now, microtime(true) + 10);
    process_alerts($this->db, $this->config, $this->now);
    add_site($this->db, ['url' => 'https://www.shop.example/blog', 'group' => 'Customers']);
    run_dns($this->db, $this->config, $this->now + 3600, microtime(true) + 10);
    process_alerts($this->db, $this->config, $this->now + 3600);
    expect($this->db->query("SELECT COUNT(DISTINCT next_at) FROM check_state WHERE check_name = 'dns'")->fetchColumn())->toBe(1);

    $this->records['shop.example'][DNS_MX] = [['type' => 'MX', 'pri' => 10, 'target' => 'mx.other.example']];
    $later = $this->now + 3600 + DNS_INTERVAL_SECONDS;
    foreach ([$later, $later + DNS_CONFIRM_SECONDS] as $t) {
        run_dns($this->db, $this->config, $t, microtime(true) + 10);
        process_alerts($this->db, $this->config, $t);
    }
    expect(($this->texts)())->toHaveCount(1)
        ->and(substr_count(($this->texts)()[0], 'mail DNS changed'))->toBe(1);
});

test('a site that moves to another domain starts over there, without an alert', function () {
    $this->records['other.example'] = [DNS_MX => [['type' => 'MX', 'pri' => 10, 'target' => 'mx.other.example']], DNS_TXT => [txt('v=spf1 -all')]];
    $shop = add_site($this->db, ['url' => 'https://shop.example', 'group' => 'Customers']);
    run_dns($this->db, $this->config, $this->now, microtime(true) + 10);
    process_alerts($this->db, $this->config, $this->now);

    update_site($this->db, $shop, ['url' => 'https://other.example']);
    foreach ([$this->now + 60, $this->now + 60 + DNS_CONFIRM_SECONDS] as $t) {
        run_dns($this->db, $this->config, $t, microtime(true) + 10);
        process_alerts($this->db, $this->config, $t);
    }
    expect(($this->texts)())->toBe([])
        ->and(($this->state)($shop)['summary'])->toBe('Mail for other.example: DMARC is missing');
});

