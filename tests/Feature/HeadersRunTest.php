<?php

beforeAll(function () {
    $GLOBALS['headers'] = start_site('headers', 8794);
    $GLOBALS['hooks'] = start_site('hooks', 8797);
});

afterAll(function () {
    stop_site($GLOBALS['headers']);
    stop_site($GLOBALS['hooks']);
});

beforeEach(function () {
    $dir = temp_dir();
    $this->dir = $dir;
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
    $this->state = function (int $id): array {
        $stmt = $this->db->prepare("SELECT * FROM check_state WHERE site_id = ? AND check_name = 'headers'");
        $stmt->execute([$id]);
        return $stmt->fetch() ?: [];
    };
    $this->texts = function (): array {
        if (!file_exists($this->log)) return [];
        return array_map(fn ($line) => json_decode($line, true)['body']['text'], file($this->log, FILE_IGNORE_NEW_LINES));
    };
    $this->headers = fn (int $now, ?float $deadline = null) => run_headers($this->db, $this->config, $now, $deadline ?? microtime(true) + 30);
    $this->moveTo = fn (int $id, string $url) => $this->db->prepare('UPDATE sites SET url = ? WHERE id = ?')->execute([$url, $id]);
});

test('a site is graded every six hours, with what is missing in plain words', function () {
    $id = add_site($this->db, ['url' => 'http://127.0.0.1:8794']);
    expect(($this->headers)($this->now)['checked'])->toBe(1)
        ->and(($this->state)($id))->toMatchArray([
            'status' => 'ok',
            'summary' => 'Grade B: 5 of 6 in place, missing HSTS',
            'next_at' => utc_now($this->now + 6 * 3600),
        ])
        ->and(json_decode(($this->state)($id)['details'], true)['headers']['hsts'])->toBe(['level' => 'missing', 'note' => 'the site does not use https'])
        ->and(($this->headers)($this->now + 3600)['checked'])->toBe(0);
});

test('a protection that disappears alerts once, after a second look', function () {
    $id = add_site($this->db, ['url' => 'http://127.0.0.1:8794']);
    ($this->headers)($this->now);
    process_alerts($this->db, $this->config, $this->now);
    expect(($this->texts)())->toBe([]);

    // A deploy drops the CSP: seen once, looked at again 15 minutes later
    ($this->moveTo)($id, 'http://127.0.0.1:8794/weaker');
    $later = $this->now + 6 * 3600;
    ($this->headers)($later);
    process_alerts($this->db, $this->config, $later);
    expect(($this->texts)())->toBe([])
        ->and(($this->state)($id)['next_at'])->toBe(utc_now($later + 15 * 60));

    ($this->headers)($later + 15 * 60);
    process_alerts($this->db, $this->config, $later + 15 * 60);
    $texts = ($this->texts)();
    expect($texts)->toHaveCount(1)
        ->and($texts[0])->toContain(':warning: *127.0.0.1:8794/weaker: security headers got worse, grade B to C*')
        ->and($texts[0])->toContain('> CSP was removed');

    ($this->headers)($later + 7 * 3600);
    process_alerts($this->db, $this->config, $later + 7 * 3600);
    expect(($this->texts)())->toHaveCount(1);

    $events = $this->db->query("SELECT message FROM events WHERE check_name = 'headers' ORDER BY id")->fetchAll(PDO::FETCH_COLUMN);
    expect($events)->toBe(['First check: grade B: 5 of 6 in place, missing HSTS', 'Grade B to C: CSP removed']);
});

test('a better grade is recorded without a message', function () {
    $id = add_site($this->db, ['url' => 'http://127.0.0.1:8794/weaker']);
    ($this->headers)($this->now);
    process_alerts($this->db, $this->config, $this->now);
    ($this->moveTo)($id, 'http://127.0.0.1:8794');
    ($this->headers)($this->now + 6 * 3600);
    ($this->headers)($this->now + 6 * 3600 + 15 * 60);
    process_alerts($this->db, $this->config, $this->now + 6 * 3600 + 15 * 60);

    expect(($this->texts)())->toBe([])
        ->and(($this->state)($id)['summary'])->toBe('Grade B: 5 of 6 in place, missing HSTS')
        ->and($this->db->query("SELECT message FROM events WHERE check_name = 'headers' ORDER BY id DESC")->fetchColumn())->toBe('Grade C to B: CSP added');
});

test('an error page is not graded, and a site that is down is left alone', function () {
    $error = add_site($this->db, ['url' => 'http://127.0.0.1:8794/error']);
    $down = add_site($this->db, ['url' => 'http://127.0.0.1:8794/down']);
    save_check_state($this->db, $down, 'uptime', ['status' => 'fail', 'summary' => 'No answer within 3 s', 'details' => ['problem' => 'timeout'], 'fail_count' => 3, 'changed_at' => utc_now($this->now)], utc_now($this->now), utc_now($this->now));

    expect(($this->headers)($this->now)['checked'])->toBe(1)
        ->and(($this->state)($error))->toMatchArray(['status' => 'unknown', 'summary' => 'Could not check: the site answered 500 Internal Server Error', 'next_at' => utc_now($this->now + 3600)])
        ->and(($this->state)($down))->toBe([]);
});

test('the time budget stops new checks, and what is left stays due', function () {
    add_site($this->db, ['url' => 'http://127.0.0.1:8794']);
    $summary = ($this->headers)($this->now, microtime(true) - 1);
    expect($summary['checked'])->toBe(0)
        ->and($summary['left'])->toBe(1);
});

test('php vaka check shows the grade', function () {
    $r = vaka(['check', 'http://127.0.0.1:8794'], cli_env());
    expect($r['out'])->toContain('Security headers: grade B: 5 of 6 in place, missing HSTS', $r['out'] . $r['err']);
});

test('Vaka grades its own pages: everything but HSTS over plain http, which only https can have', function () {
    $env = cli_env();
    $web = start_web(8795, $env);
    try {
        $response = fetch_many(['login' => 'http://127.0.0.1:8795/?login'], 3, 'test')['login'];
        expect(evaluate_headers($response, 3)['summary'])->toBe('Grade B: 5 of 6 in place, missing HSTS');
    } finally {
        stop_site($web);
    }
});

test('the scripts and styles a page loads are checked for nosniff too', function () {
    $fine = add_site($this->db, ['url' => 'http://127.0.0.1:8794/with-files']);
    $cached = add_site($this->db, ['url' => 'http://127.0.0.1:8794/cached-files']);
    ($this->headers)($this->now);

    expect(($this->state)($fine)['summary'])->toBe('Grade B: 5 of 6 in place, missing HSTS')
        ->and(json_decode(($this->state)($fine)['details'], true)['files'])->toBe(['checked' => ['/assets/site.css', '/assets/app.js'], 'missing' => []])
        ->and(($this->state)($cached)['summary'])->toBe('Grade B: 5 of 6 in place, missing HSTS, X-Content-Type-Options is missing on scripts and styles')
        ->and(json_decode(($this->state)($cached)['details'], true)['files'])->toBe(['checked' => ['/cached/site.css', '/cached/app.js'], 'missing' => ['/cached/site.css', '/cached/app.js']]);
});

test('nosniff leaving the scripts and styles alerts once, after a second look', function () {
    $id = add_site($this->db, ['url' => 'http://127.0.0.1:8794/with-files']);
    ($this->headers)($this->now);
    process_alerts($this->db, $this->config, $this->now);

    // A cache rule for the files, with a header of its own, drops nosniff from them
    ($this->moveTo)($id, 'http://127.0.0.1:8794/cached-files');
    $later = $this->now + 6 * 3600;
    ($this->headers)($later);
    process_alerts($this->db, $this->config, $later);
    expect(($this->texts)())->toBe([]);

    ($this->headers)($later + 15 * 60);
    process_alerts($this->db, $this->config, $later + 15 * 60);
    $texts = ($this->texts)();
    expect($texts)->toHaveCount(1)
        ->and($texts[0])->toContain('127.0.0.1:8794/cached-files: security headers got worse*')
        ->and($texts[0])->toContain('> X-Content-Type-Options on scripts and styles was removed: /cached/site.css and /cached/app.js')
        ->and($texts[0])->toContain('in nginx, a location with its own add_header');

    $events = $this->db->query("SELECT message FROM events WHERE check_name = 'headers' ORDER BY id")->fetchAll(PDO::FETCH_COLUMN);
    expect($events)->toBe(['First check: grade B: 5 of 6 in place, missing HSTS', 'X-Content-Type-Options removed from scripts and styles (grade still B)']);
});

test('first seeing scripts and styles without nosniff is recorded, not alerted', function () {
    $id = add_site($this->db, ['url' => 'http://127.0.0.1:8794']);
    ($this->headers)($this->now);
    process_alerts($this->db, $this->config, $this->now);

    ($this->moveTo)($id, 'http://127.0.0.1:8794/cached-files');
    $later = $this->now + 6 * 3600;
    ($this->headers)($later);
    ($this->headers)($later + 15 * 60);
    process_alerts($this->db, $this->config, $later + 15 * 60);

    expect(($this->texts)())->toBe([])
        ->and($this->db->query("SELECT message FROM events WHERE check_name = 'headers' ORDER BY id DESC")->fetchColumn())->toBe('X-Content-Type-Options not on scripts and styles (grade still B)');
});

test('php vaka check looks at the scripts and styles too', function () {
    $r = vaka(['check', 'http://127.0.0.1:8794/cached-files'], cli_env());
    expect($r['out'])->toContain('Security headers: grade B: 5 of 6 in place, missing HSTS, X-Content-Type-Options is missing on scripts and styles', $r['out'] . $r['err']);
});
