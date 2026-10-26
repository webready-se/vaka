<?php

beforeAll(function () {
    $GLOBALS['rdapLog'] = sys_get_temp_dir() . '/vaka-rdap-' . getmypid() . '.log';
    $GLOBALS['rdap'] = start_site('rdap', 8796, ['RDAP_LOG' => $GLOBALS['rdapLog']]);
    $GLOBALS['hooks'] = start_site('hooks', 8797);

    $dir = __DIR__ . '/../fixtures';
    assert_port_free(8799);
    $GLOBALS['whois'] = proc_open([PHP_BINARY, "{$dir}/whois-server.php", '8799'], [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
    set_error_handler(fn () => true);
    for ($i = 0; $i < 100 && !($sock = fsockopen('127.0.0.1', 8799, $errno, $errstr, 0.1)); $i++) usleep(50_000);
    restore_error_handler();
    if ($sock) fclose($sock);
});

afterAll(function () {
    stop_site($GLOBALS['rdap']);
    stop_site($GLOBALS['hooks']);
    stop_site($GLOBALS['whois']);
    if (file_exists($GLOBALS['rdapLog'])) unlink($GLOBALS['rdapLog']);
});

beforeEach(function () {
    $dir = temp_dir();
    $this->dir = $dir;
    $this->db = get_db($dir . '/vaka.sqlite');
    $this->log = $dir . '/hooks.log';
    if (file_exists($GLOBALS['rdapLog'])) unlink($GLOBALS['rdapLog']);
    $this->config = test_config([
        'db_path' => $dir . '/vaka.sqlite',
        'heartbeat_url' => '',
        'slack_webhook_url' => 'http://127.0.0.1:8797/slack?log=' . urlencode($this->log),
        'webhook_url' => '',
        'alert_email' => [],
        'app_url' => '',
        'uptime_timeout' => 3,
        'rdap_bootstrap_url' => 'http://127.0.0.1:8796/dns.json',
        'whois_servers' => ['se' => '127.0.0.1:8799'],
        'domain_alert_days' => [30, 14, 7, 1],
    ]);
    // shop.example's registration runs out on 4 Mar 2027 (tests/fixtures/rdap/shop.example.json)
    $this->expires = strtotime('2027-03-04T10:00:00Z');
    $this->state = function (string $url): array {
        $stmt = $this->db->prepare("SELECT c.* FROM check_state c JOIN sites s ON s.id = c.site_id WHERE s.url = ? AND c.check_name = 'domain'");
        $stmt->execute([$url]);
        return $stmt->fetch() ?: [];
    };
    $this->texts = function (): array {
        if (!file_exists($this->log)) return [];
        return array_map(fn ($line) => json_decode($line, true)['body']['text'], file($this->log, FILE_IGNORE_NEW_LINES));
    };
    $this->lookups = fn () => file_exists($GLOBALS['rdapLog']) ? count(preg_grep('#/domain/#', file($GLOBALS['rdapLog']))) : 0;
    $this->domain = fn (int $now, ?float $deadline = null) => run_domain($this->db, $this->config, $now, $deadline ?? microtime(true) + 30);
});

test('a domain with RDAP says until when it is registered, and with whom', function () {
    add_site($this->db, ['url' => 'https://www.shop.example']);
    $now = $this->expires - 100 * 86400;
    expect(($this->domain)($now)['checked'])->toBe(1)
        ->and(($this->state)('https://www.shop.example'))->toMatchArray([
            'status' => 'ok',
            'summary' => 'shop.example is registered until 4 Mar 2027 (100 days), with Example Registrar, Inc.',
            'next_at' => utc_now($now + 86400),
        ])
        ->and(json_decode(($this->state)('https://www.shop.example')['details'], true)['source'])->toBe('rdap')
        ->and(file_exists($this->dir . '/rdap-dns.json'))->toBeTrue();
});

test('a .se domain is looked up in the registry\'s WHOIS', function () {
    add_site($this->db, ['url' => 'https://www.example.se']);
    ($this->domain)(strtotime('2026-10-25T12:00:00Z'));
    $state = ($this->state)('https://www.example.se');
    expect($state['summary'])->toBe('example.se is registered until 13 Sep 2031 (1783 days), with Example Registrar AB')
        ->and(json_decode($state['details'], true)['source'])->toBe('whois');
});

test('what cannot be looked up is unknown, and an IP address has no domain', function () {
    add_site($this->db, ['url' => 'https://missing.example']);
    add_site($this->db, ['url' => 'https://shop.invalid']);
    add_site($this->db, ['url' => 'https://no-expiry.example']);
    add_site($this->db, ['url' => 'http://127.0.0.1:8791']);
    ($this->domain)(time());

    expect(($this->state)('https://missing.example'))->toMatchArray(['status' => 'unknown', 'summary' => 'The registry does not know missing.example'])
        ->and(($this->state)('https://shop.invalid')['summary'])->toBe('The .invalid registry publishes no expiry dates that Vaka can read')
        ->and(($this->state)('https://no-expiry.example')['summary'])->toBe('The registry does not say when no-expiry.example expires')
        ->and(($this->state)('http://127.0.0.1:8791')['summary'])->toBe('No domain to look up: the site is reached by its address')
        ->and($this->db->query("SELECT COUNT(*) FROM events WHERE check_name = 'domain'")->fetchColumn())->toBe(0);
});

test('an expiring domain alerts once per threshold, once for all its sites', function () {
    add_group($this->db, ['name' => 'Customers']);
    add_site($this->db, ['url' => 'https://shop.example', 'group' => 'Customers']);
    add_site($this->db, ['url' => 'https://www.shop.example', 'group' => 'Customers']);

    $now = $this->expires - 20 * 86400 - 600;
    ($this->domain)($now);
    expect(($this->lookups)())->toBe(1);
    process_alerts($this->db, $this->config, $now);
    $texts = ($this->texts)();
    expect($texts)->toHaveCount(1)
        ->and($texts[0])->toContain(':warning: *shop.example (Customers): the domain expires in 20 days*')
        ->and($texts[0])->toContain('> shop.example expires in 20 days (4 Mar 2027)')
        ->and(substr_count($texts[0], 'expires in 20 days*'))->toBe(1);

    // A day later, still above 14 days: nothing new for either site
    ($this->domain)($now + 86400);
    process_alerts($this->db, $this->config, $now + 86400);
    expect(($this->texts)())->toHaveCount(1);

    ($this->domain)($now + 7 * 86400);
    process_alerts($this->db, $this->config, $now + 7 * 86400);
    expect(($this->texts)())->toHaveCount(2)
        ->and(($this->texts)()[1])->toContain('the domain expires in 13 days');
});

test('the bootstrap file is kept for a week, and an old copy is used when IANA cannot be reached', function () {
    $cache = $this->dir . '/rdap-dns.json';
    $now = time();
    expect(load_rdap_bootstrap($cache, 'http://127.0.0.1:8796/dns.json', $this->config, $now)['services'][0][0])->toBe(['example', 'test']);

    // Within a week the copy is used even if the source is gone
    expect(load_rdap_bootstrap($cache, 'http://127.0.0.1:1/dns.json', $this->config, $now + 6 * 86400))->not->toBeNull();
    // After a week it is fetched again; when that fails, the old copy still serves
    expect(load_rdap_bootstrap($cache, 'http://127.0.0.1:1/dns.json', $this->config, $now + 8 * 86400))->not->toBeNull();
    expect(load_rdap_bootstrap($this->dir . '/none.json', 'http://127.0.0.1:1/dns.json', $this->config, $now))->toBeNull();
});

test('the time budget stops new lookups, and what is left stays due', function () {
    add_site($this->db, ['url' => 'https://shop.example']);
    $summary = ($this->domain)(time(), microtime(true) - 1);
    expect($summary['checked'])->toBe(0)
        ->and($summary['left'])->toBe(1);
});

test('check_domain looks up one URL, as php vaka check does', function () {
    expect(check_domain('https://www.shop.example/en', $this->config, $this->expires - 100 * 86400)['summary'])
        ->toBe('shop.example is registered until 4 Mar 2027 (100 days), with Example Registrar, Inc.');
    $r = vaka(['check', 'http://127.0.0.1:8797'], cli_env());
    expect($r['out'])->not->toContain('Domain:');
});
