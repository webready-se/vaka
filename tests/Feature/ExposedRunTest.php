<?php

beforeAll(function () {
    $GLOBALS['good'] = start_site('good', 8791);
    $GLOBALS['exposed'] = start_site('exposed', 8793);
    $GLOBALS['hooks'] = start_site('hooks', 8797);
});

afterAll(function () {
    stop_site($GLOBALS['good']);
    stop_site($GLOBALS['exposed']);
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
        'uptime_timeout' => 3,
    ]);
    $this->now = strtotime('2026-10-25T12:00:00Z');
    $this->state = function (string $url): array {
        $stmt = $this->db->prepare("SELECT c.* FROM check_state c JOIN sites s ON s.id = c.site_id WHERE s.url = ? AND c.check_name = 'exposed'");
        $stmt->execute([$url]);
        return $stmt->fetch() ?: [];
    };
    $this->texts = function (): array {
        if (!file_exists($this->log)) return [];
        return array_map(fn ($line) => json_decode($line, true)['body']['text'], file($this->log, FILE_IGNORE_NEW_LINES));
    };
    $this->exposed = fn (int $now, ?float $deadline = null) => run_exposed($this->db, $this->config, $now, $deadline ?? microtime(true) + 30);
});

test('files that should be private are found by their content, and a site that answers everything is not', function () {
    add_site($this->db, ['url' => 'http://127.0.0.1:8793']);
    add_site($this->db, ['url' => 'http://127.0.0.1:8791']);
    $summary = ($this->exposed)($this->now);

    $leaky = ($this->state)('http://127.0.0.1:8793');
    expect($summary['checked'])->toBe(2)
        ->and($leaky['status'])->toBe('fail')
        ->and($leaky['summary'])->toBe('2 files that should be private are public: .env and .git/')
        ->and(array_column(json_decode($leaky['details'], true)['found'], 'url'))->toBe(['http://127.0.0.1:8793/.env', 'http://127.0.0.1:8793/.git/HEAD'])
        ->and(($this->state)('http://127.0.0.1:8791'))->toMatchArray(['status' => 'ok', 'summary' => 'Nothing that should be private is public (' . count(EXPOSED_FILES) . ' files checked)']);
});

test('a site in a folder is checked in its folder', function () {
    add_site($this->db, ['url' => 'http://127.0.0.1:8793/minor']);
    ($this->exposed)($this->now);
    expect(($this->state)('http://127.0.0.1:8793/minor'))->toMatchArray(['status' => 'warn', 'summary' => 'composer.json is public: the list of PHP packages the site uses']);
});

test('a site is checked once a day, and every hour while something is public', function () {
    add_site($this->db, ['url' => 'http://127.0.0.1:8793']);
    add_site($this->db, ['url' => 'http://127.0.0.1:8791']);
    ($this->exposed)($this->now);

    expect(($this->state)('http://127.0.0.1:8793')['next_at'])->toBe(utc_now($this->now + 3600))
        ->and(($this->state)('http://127.0.0.1:8791')['next_at'])->toBe(utc_now($this->now + 86400))
        ->and(($this->exposed)($this->now + 1800)['checked'])->toBe(0)
        ->and(($this->exposed)($this->now + 3600)['checked'])->toBe(1);
});

test('a public file alerts once, and so does the fix', function () {
    $id = add_site($this->db, ['url' => 'http://127.0.0.1:8793']);
    ($this->exposed)($this->now);
    process_alerts($this->db, $this->config, $this->now);
    $texts = ($this->texts)();
    expect($texts)->toHaveCount(1)
        ->and($texts[0])->toContain(':red_circle: *127.0.0.1:8793: .env and .git/ are public*')
        ->and($texts[0])->toContain('> .env: the environment file, often with database passwords and API keys');

    ($this->exposed)($this->now + 3600);
    process_alerts($this->db, $this->config, $this->now + 3600);
    expect(($this->texts)())->toHaveCount(1);

    // The files are taken down: the site's folder now has none
    $this->db->prepare('UPDATE sites SET url = ? WHERE id = ?')->execute(['http://127.0.0.1:8793/fixed', $id]);
    ($this->exposed)($this->now + 7200);
    process_alerts($this->db, $this->config, $this->now + 7200);
    expect(($this->texts)())->toHaveCount(2)
        ->and(($this->texts)()[1])->toContain(':large_green_circle: *127.0.0.1:8793/fixed: nothing that should be private is public any more*');

    $events = $this->db->query("SELECT status, previous, message FROM events WHERE check_name = 'exposed' ORDER BY id")->fetchAll();
    expect($events)->toBe([
        ['status' => 'fail', 'previous' => 'pending', 'message' => '2 files that should be private are public: .env and .git/'],
        ['status' => 'ok', 'previous' => 'fail', 'message' => 'Nothing that should be private is public (' . count(EXPOSED_FILES) . ' files checked)'],
    ]);
});

test('a site that is down, paused or skips the check is left alone', function () {
    $down = add_site($this->db, ['url' => 'http://127.0.0.1:8793']);
    save_check_state($this->db, $down, 'uptime', ['status' => 'fail', 'summary' => 'No answer within 3 s', 'details' => ['problem' => 'timeout'], 'fail_count' => 3, 'changed_at' => utc_now($this->now)], utc_now($this->now), utc_now($this->now));
    add_site($this->db, ['url' => 'http://127.0.0.1:8791', 'paused' => true]);
    add_site($this->db, ['url' => 'http://127.0.0.1:8791/skips', 'disabled_checks' => 'exposed']);

    expect(($this->exposed)($this->now)['checked'])->toBe(0);
});

test('the time budget stops new checks, and what is left stays due', function () {
    add_site($this->db, ['url' => 'http://127.0.0.1:8793']);
    add_site($this->db, ['url' => 'http://127.0.0.1:8791']);
    $summary = ($this->exposed)($this->now, microtime(true) - 1);
    expect($summary['checked'])->toBe(0)
        ->and($summary['left'])->toBe(2)
        ->and(($this->exposed)($this->now)['checked'])->toBe(2);
});

test('php vaka check shows exposed files too', function () {
    $r = vaka(['check', 'http://127.0.0.1:8793'], cli_env());
    expect($r['out'])->toContain('Exposed files: 2 files that should be private are public: .env and .git/', $r['out'] . $r['err']);

    $r = vaka(['check', 'http://127.0.0.1:8791'], cli_env());
    expect($r['out'])->toContain('Exposed files: nothing that should be private is public');
});

test('php vaka status shows the files that are public', function () {
    $env = cli_env();
    vaka(['site:add', 'http://127.0.0.1:8793'], $env);
    vaka(['site:add', 'http://127.0.0.1:8791'], $env);
    vaka(['run'], $env);

    $out = vaka(['status'], $env)['out'];
    expect($out)->toMatch('/127\.0\.0\.1:8793\s+up\s+\d+ ms, checked just now; 2 files that should be private are public: \.env and \.git\//')
        ->and($out)->not->toContain('nothing that should be private');
});
