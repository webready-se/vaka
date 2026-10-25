<?php

function start_tls(string $cert, int $port): mixed
{
    $dir = __DIR__ . '/../fixtures';
    $proc = proc_open(
        [PHP_BINARY, "{$dir}/tls-server.php", (string) $port, "{$dir}/certs/{$cert}.pem", "{$dir}/certs/{$cert}.key"],
        [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
        $pipes,
    );
    set_error_handler(fn () => true);
    try {
        for ($i = 0; $i < 100; $i++) {
            $sock = fsockopen('127.0.0.1', $port, $errno, $errstr, 0.1);
            if ($sock) {
                fclose($sock);
                return $proc;
            }
            usleep(50_000);
        }
    } finally {
        restore_error_handler();
    }
    throw new RuntimeException("TLS fixture did not start on port {$port}");
}

beforeAll(function () {
    $GLOBALS['tls'] = [start_tls('valid', 8800), start_tls('wrong-name', 8801), start_tls('self-signed', 8802)];
    $GLOBALS['hooks'] = start_site('hooks', 8797);
});

afterAll(function () {
    array_map('stop_site', $GLOBALS['tls']);
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
        'ssl_ca_file' => realpath(__DIR__ . '/../fixtures/certs/ca.pem'),
        'uptime_timeout' => 3,
    ]);
    $this->validTo = certificate_info(file_get_contents(__DIR__ . '/../fixtures/certs/valid.pem'))['valid_to'];
    $this->state = function (string $url): array {
        $stmt = $this->db->prepare("SELECT c.* FROM check_state c JOIN sites s ON s.id = c.site_id WHERE s.url = ? AND c.check_name = 'ssl'");
        $stmt->execute([$url]);
        return $stmt->fetch() ?: [];
    };
    $this->texts = function (): array {
        if (!file_exists($this->log)) return [];
        return array_map(fn ($line) => json_decode($line, true)['body']['text'], file($this->log, FILE_IGNORE_NEW_LINES));
    };
    $this->ssl = fn (int $now, ?float $deadline = null) => run_ssl($this->db, $this->config, $now, $deadline ?? microtime(true) + 30);
});

test('a trusted certificate is ok and says until when', function () {
    add_site($this->db, ['url' => 'https://127.0.0.1:8800']);
    $summary = ($this->ssl)($this->validTo - 90 * 86400);

    expect($summary['checked'])->toBe(1)
        ->and(($this->state)('https://127.0.0.1:8800'))->toMatchArray(['status' => 'ok'])
        ->and(($this->state)('https://127.0.0.1:8800')['summary'])->toContain('(90 days), issued by Vaka Test CA');
});

test('a certificate for another name or signed by no one is not valid', function () {
    add_site($this->db, ['url' => 'https://127.0.0.1:8801']);
    add_site($this->db, ['url' => 'https://127.0.0.1:8802']);
    ($this->ssl)(time());

    expect(($this->state)('https://127.0.0.1:8801'))->toMatchArray(['status' => 'fail', 'summary' => 'The certificate is for other.example, not 127.0.0.1'])
        ->and(($this->state)('https://127.0.0.1:8802'))->toMatchArray(['status' => 'fail', 'summary' => 'The certificate is self-signed, so browsers do not trust it']);
});

test('an expiring certificate alerts once per threshold, and an invalid one alerts', function () {
    add_site($this->db, ['url' => 'https://127.0.0.1:8800']);
    add_site($this->db, ['url' => 'https://127.0.0.1:8801']);

    $now = $this->validTo - 10 * 86400 - 600;
    ($this->ssl)($now);
    process_alerts($this->db, $this->config, $now);
    $texts = ($this->texts)();
    expect($texts)->toHaveCount(1)
        ->and($texts[0])->toContain(':warning: *127.0.0.1:8800: the SSL certificate expires in 10 days*')
        ->and($texts[0])->toContain(':red_circle: *127.0.0.1:8801: the SSL certificate is not valid*')
        ->and($texts[0])->toContain('The certificate is for other.example, not 127.0.0.1');

    // A day later, still above the 7-day threshold: nothing new
    $this->db->exec("UPDATE check_state SET next_at = NULL WHERE check_name = 'ssl'");
    ($this->ssl)($now + 86400);
    process_alerts($this->db, $this->config, $now + 86400);
    expect(($this->texts)())->toHaveCount(1);

    // Three days later it has passed 7 days
    $this->db->exec("UPDATE check_state SET next_at = NULL WHERE check_name = 'ssl'");
    ($this->ssl)($now + 4 * 86400);
    process_alerts($this->db, $this->config, $now + 4 * 86400);
    expect(($this->texts)())->toHaveCount(2)
        ->and(($this->texts)()[1])->toContain('expires in 6 days');
});

test('SSL is checked every six hours, only for https sites, and not while a site does not answer at all', function () {
    add_site($this->db, ['url' => 'https://127.0.0.1:8800']);
    add_site($this->db, ['url' => 'http://127.0.0.1:8797']);
    $silent = add_site($this->db, ['url' => 'https://127.0.0.1:8803']);
    save_check_state($this->db, $silent, 'uptime', ['status' => 'fail', 'summary' => 'The server refused the connection', 'details' => ['problem' => 'refused'], 'fail_count' => 3, 'changed_at' => utc_now()], utc_now(), utc_now());

    $now = $this->validTo - 90 * 86400;
    expect(($this->ssl)($now)['checked'])->toBe(1)
        ->and(($this->ssl)($now + 3600)['checked'])->toBe(0)
        ->and(($this->ssl)($now + 6 * 3600)['checked'])->toBe(1);
});

test('a site that is down because of its certificate still gets the SSL check', function () {
    $id = add_site($this->db, ['url' => 'https://127.0.0.1:8802']);
    save_check_state($this->db, $id, 'uptime', ['status' => 'fail', 'summary' => 'The certificate is not accepted', 'details' => ['problem' => 'certificate'], 'fail_count' => 3, 'changed_at' => utc_now()], utc_now(), utc_now());
    expect(($this->ssl)(time())['checked'])->toBe(1);
});

test('the time budget stops new SSL checks, and what is left stays due', function () {
    add_site($this->db, ['url' => 'https://127.0.0.1:8800']);
    add_site($this->db, ['url' => 'https://127.0.0.1:8801']);
    $summary = ($this->ssl)(time(), microtime(true) - 1);
    expect($summary['checked'])->toBe(0)
        ->and($summary['left'])->toBe(2)
        ->and(($this->ssl)(time())['checked'])->toBe(2);
});

test('php vaka check shows the certificate too', function () {
    $r = vaka(['check', 'https://127.0.0.1:8800'], cli_env() + ['SSL_CA_FILE' => realpath(__DIR__ . '/../fixtures/certs/ca.pem')]);
    expect($r['out'])->toContain('Up: answered 200 OK')
        ->and($r['out'])->toContain('Certificate: valid until')
        ->and($r['out'])->toContain('issued by Vaka Test CA');
});
