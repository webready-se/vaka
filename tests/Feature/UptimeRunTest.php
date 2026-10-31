<?php

beforeAll(function () {
    $GLOBALS['good'] = start_site('good', 8791);
    $GLOBALS['error'] = start_site('error', 8792);
});

afterAll(function () {
    stop_site($GLOBALS['good']);
    stop_site($GLOBALS['error']);
});

beforeEach(function () {
    $dir = temp_dir();
    $this->db = get_db($dir . '/vaka.sqlite');
    $this->config = test_config(['db_path' => $dir . '/vaka.sqlite', 'heartbeat_url' => '']);
    $this->now = strtotime('2026-10-25T12:00:00Z');
    $this->state = function (string $url) {
        $stmt = $this->db->prepare("SELECT c.* FROM check_state c JOIN sites s ON s.id = c.site_id WHERE s.url = ? AND c.check_name = 'uptime'");
        $stmt->execute([normalize_url($url)]);
        return $stmt->fetch();
    };
    $this->events = fn () => $this->db->query("SELECT e.status, e.previous, e.message FROM events e WHERE e.check_name = 'uptime' ORDER BY e.id")->fetchAll();
});

test('a site that answers is up, with its response time and IP recorded', function () {
    add_site($this->db, ['url' => 'http://127.0.0.1:8791']);
    $summary = run($this->db, $this->config, $this->now);

    $state = ($this->state)('http://127.0.0.1:8791');
    expect($summary['checked'])->toBe(1)
        ->and($state['status'])->toBe('ok')
        ->and($state['summary'])->toStartWith('Answered 200 OK in ')
        // In its own minute of the interval (site 1: one past the hour's fives), then every five minutes
        ->and($state['next_at'])->toBe(utc_now(next_uptime_slot(1, 5, $this->now)))
        ->and(find_site($this->db, 'http://127.0.0.1:8791')['ip'])->toBe('127.0.0.1')
        ->and(($this->events)())->toHaveCount(1);

    $sample = $this->db->query('SELECT * FROM samples')->fetch();
    expect($sample['status_code'])->toBe(200)
        ->and($sample['ok'])->toBe(1)
        ->and($sample['ms'])->toBeGreaterThanOrEqual(0)
        ->and($sample['ip'])->toBe('127.0.0.1');
});

test('a site that fails twice in a row is down, and is rechecked every minute meanwhile', function () {
    add_site($this->db, ['url' => 'http://127.0.0.1:8792']);

    run($this->db, $this->config, $this->now);
    $state = ($this->state)('http://127.0.0.1:8792');
    expect($state['status'])->toBe('pending')
        ->and($state['fail_count'])->toBe(1)
        ->and($state['next_at'])->toBe(utc_now($this->now + 60));

    $summary = run($this->db, $this->config, $this->now + 60);
    expect(($this->state)('http://127.0.0.1:8792')['status'])->toBe('fail')
        ->and($summary['down'])->toBe(1)
        ->and(($this->events)())->toBe([['status' => 'fail', 'previous' => 'pending', 'message' => 'Down: the site answered 500 Internal Server Error']]);
});

test('a missing keyword is down, and fixing it is up again', function () {
    $id = add_site($this->db, ['url' => 'http://127.0.0.1:8791', 'keyword' => 'Add to cart']);
    run($this->db, $this->config, $this->now);
    run($this->db, $this->config, $this->now + 60);
    expect(($this->state)('http://127.0.0.1:8791')['summary'])->toBe('Answered 200 OK, but the page does not contain "Add to cart"');

    update_site($this->db, $id, ['keyword' => 'Welcome to the shop']);
    run($this->db, $this->config, $this->now + 120);

    expect(($this->state)('http://127.0.0.1:8791')['status'])->toBe('ok')
        ->and(array_column(($this->events)(), 'message'))->toBe([
            'Down: answered 200 OK, but the page does not contain "Add to cart"',
            'Up again after 2 min: ' . lcfirst(($this->state)('http://127.0.0.1:8791')['summary']),
        ]);
});

test('a site is checked again only when its interval has passed, in a minute of its own', function () {
    add_site($this->db, ['url' => 'http://127.0.0.1:8791']);
    add_site($this->db, ['url' => 'http://127.0.0.1:8791/fast', 'interval' => '1']);

    // 12:00 both; the five-minute one then moves to its own minute (site 1: 12:01, 12:06, ...)
    expect(run($this->db, $this->config, $this->now)['checked'])->toBe(2)
        ->and(run($this->db, $this->config, $this->now + 30)['checked'])->toBe(0)
        ->and(run($this->db, $this->config, $this->now + 60)['checked'])->toBe(2)
        ->and(run($this->db, $this->config, $this->now + 120)['checked'])->toBe(1)
        ->and(run($this->db, $this->config, $this->now + 300)['checked'])->toBe(1)
        ->and(run($this->db, $this->config, $this->now + 360)['checked'])->toBe(2);
});

test('a site that does not answer in time fails without holding up the run', function () {
    add_site($this->db, ['url' => 'http://127.0.0.1:8791/?sleep=3']);
    // The uptime phase only: the slower checks after it (headers, exposed files) wait out their own timeouts too
    $started = microtime(true);
    run_uptime($this->db, ['uptime_timeout' => 1] + $this->config, $this->now);

    expect(microtime(true) - $started)->toBeLessThan(2.5)
        ->and(($this->state)('http://127.0.0.1:8791/?sleep=3')['summary'])->toBe('First check failed, checking again: no answer within 1 s');
});

test('sites are checked in parallel', function () {
    // One slow request per server: a php -S worker may queue several
    // connections and serve them one by one, which would hide whether Vaka
    // itself runs the requests at the same time
    $servers = [];
    foreach ([8793, 8794, 8795, 8796] as $port) {
        $servers[] = start_site('good', $port);
        add_site($this->db, ['url' => "http://127.0.0.1:{$port}/?sleep=1"]);
    }
    try {
        // Only the uptime phase: a whole run also grades headers, which waits for the same slow pages
        $started = microtime(true);
        $summary = run_uptime($this->db, $this->config, $this->now);
        $elapsed = microtime(true) - $started;
    } finally {
        array_map('stop_site', $servers);
    }

    // One at a time would take at least 4 s
    expect($summary['checked'])->toBe(4)
        ->and($summary['up'])->toBe(4)
        ->and($elapsed)->toBeLessThan(2.5);
});

test('redirects are followed, but not forever', function () {
    add_site($this->db, ['url' => 'http://127.0.0.1:8791/redirect']);
    add_site($this->db, ['url' => 'http://127.0.0.1:8791/loop']);
    run($this->db, $this->config, $this->now);

    expect(($this->state)('http://127.0.0.1:8791/redirect')['status'])->toBe('ok')
        ->and(($this->state)('http://127.0.0.1:8791/loop')['summary'])->toBe('First check failed, checking again: too many redirects (more than 5)');
});

test('a site behind a CDN is recognised from its headers', function () {
    add_site($this->db, ['url' => 'http://127.0.0.1:8791/cdn']);
    run($this->db, $this->config, $this->now);
    expect(find_site($this->db, 'http://127.0.0.1:8791/cdn')['cdn'])->toBe('Cloudflare');
});

test('every run pings the heartbeat URL and records when it ran', function () {
    $log = temp_dir() . '/heartbeat.log';
    run($this->db, ['heartbeat_url' => 'http://127.0.0.1:8791/heartbeat?log=' . urlencode($log)] + $this->config, $this->now);

    expect(file_exists($log))->toBeTrue()
        ->and($this->db->query("SELECT value FROM meta WHERE key = 'last_run_at'")->fetchColumn())->toBe(utc_now($this->now));
});

test('a heartbeat URL that does not answer never fails the run', function () {
    add_site($this->db, ['url' => 'http://127.0.0.1:8791']);
    $summary = run($this->db, ['heartbeat_url' => 'http://127.0.0.1:1/'] + $this->config, $this->now);
    expect($summary['checked'])->toBe(1)
        ->and($summary['heartbeat'])->toBeFalse();
});

test('php vaka run checks what is due and says what it found', function () {
    $env = cli_env();
    vaka(['site:add', 'http://127.0.0.1:8791'], $env);
    vaka(['site:add', 'http://127.0.0.1:8792'], $env);

    $r = vaka(['run'], $env);
    expect($r['exit'])->toBe(0, $r['err'])
        ->and($r['out'])->toContain('Checked 2 sites: 1 up, 1 failing (checking again in a minute)');

    $r = vaka(['run'], $env);
    expect($r['out'])->toContain('Nothing was due');
});

test('php vaka check tries a URL once without storing anything', function () {
    $env = cli_env();
    $r = vaka(['check', 'http://127.0.0.1:8792'], $env);
    expect($r['exit'])->toBe(1)
        ->and($r['out'])->toContain('http://127.0.0.1:8792')
        ->and($r['out'])->toContain('Down: the site answered 500 Internal Server Error');

    $r = vaka(['check', 'http://127.0.0.1:8791', '--keyword=Welcome'], $env);
    expect($r['exit'])->toBe(0, $r['err'])
        ->and($r['out'])->toContain('Up: answered 200 OK in')
        ->and(vaka(['site:list'], $env)['out'])->toContain('No sites yet');
});

test('php vaka status shows each site in plain words, by group', function () {
    $env = cli_env();
    vaka(['group:add', 'Customers'], $env);
    vaka(['site:add', 'http://127.0.0.1:8791', '--group=Customers'], $env);
    vaka(['site:add', 'http://127.0.0.1:8792', '--group=Customers'], $env);
    vaka(['site:add', 'http://127.0.0.1:8791/new', '--pause'], $env);
    vaka(['run'], $env);

    $out = vaka(['status'], $env)['out'];
    expect($out)->toContain('Customers')
        ->and($out)->toContain('No group')
        ->and($out)->toMatch('/127\.0\.0\.1:8791\s+up\s+\d+ ms/')
        ->and($out)->toContain('first check failed, checking again')
        ->and($out)->toContain('paused')
        ->and($out)->toContain('3 sites: 1 up, 1 failing, 1 paused');
});

test('a site behind a bot check keeps its status, with no sample, and its headers and files are not graded', function () {
    add_site($this->db, ['url' => 'http://127.0.0.1:8791/botcheck']);
    run($this->db, $this->config, $this->now);

    $state = ($this->state)('http://127.0.0.1:8791/botcheck');
    expect($state)->toMatchArray(['status' => 'pending', 'fail_count' => 0, 'summary' => "Imunify360's bot check answered instead of the site"])
        ->and(json_decode($state['details'], true))->toMatchArray(['blocked' => 'Imunify360', 'blocked_since' => utc_now($this->now), 'from_ip' => ''])
        ->and(($this->events)())->toBe([])
        ->and($this->db->query('SELECT count(*) FROM samples')->fetchColumn())->toBe(0);

    $other = $this->db->query("SELECT check_name, status, summary FROM check_state WHERE check_name IN ('headers', 'exposed') ORDER BY check_name")->fetchAll();
    expect($other)->toBe([
        ['check_name' => 'exposed', 'status' => 'unknown', 'summary' => "Could not check: Imunify360's bot check answered instead of the site"],
        ['check_name' => 'headers', 'status' => 'unknown', 'summary' => "Could not check: Imunify360's bot check answered instead of the site"],
    ]);
});

test('php vaka check says when a bot check answered, rather than up or down', function () {
    $r = vaka(['check', 'http://127.0.0.1:8791/botcheck'], cli_env());
    expect($r['out'])->toContain("Imunify360's bot check answered instead of the site")
        ->and($r['out'])->not->toContain('Down:')
        ->and($r['out'])->not->toContain('Up:');
});

test('php vaka status says when a bot check answers instead of the site', function () {
    $env = cli_env();
    vaka(['site:add', 'http://127.0.0.1:8791/botcheck'], $env);
    vaka(['run'], $env);

    expect(vaka(['status'], $env)['out'])->toMatch("/127\.0\.0\.1:8791\/botcheck\s+not checked yet\s+Imunify360's bot check answered instead of the site since \d/");
});
