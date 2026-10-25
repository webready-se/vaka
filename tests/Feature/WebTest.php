<?php

const WEB = 'http://127.0.0.1:8798';

beforeAll(function () {
    $GLOBALS['web_dir'] = temp_dir();
    $GLOBALS['web'] = start_web(8798, [
        'DB_PATH' => $GLOBALS['web_dir'] . '/vaka.sqlite',
        'USERS_FILE' => $GLOBALS['web_dir'] . '/users.json',
        'MAX_LOGIN_ATTEMPTS' => '3',
        'LOCKOUT_MINUTES' => '15',
    ]);
});

afterAll(fn () => stop_site($GLOBALS['web']));

beforeEach(function () {
    $dir = $GLOBALS['web_dir'];
    foreach (['vaka.sqlite', 'vaka.sqlite-wal', 'vaka.sqlite-shm', 'users.json'] as $file) {
        if (file_exists("{$dir}/{$file}")) unlink("{$dir}/{$file}");
    }
    $this->db = get_db("{$dir}/vaka.sqlite");
    $hash = fn (string $password) => password_hash($password, PASSWORD_BCRYPT, ['cost' => 4]);
    save_users("{$dir}/users.json", [
        'admin' => ['password' => $hash('admin password'), 'groups' => []],
        'anna' => ['password' => $hash('anna password'), 'groups' => ['Customers']],
    ]);
    $this->jar = fn () => tempnam(sys_get_temp_dir(), 'vaka-jar-');
    $this->login = function (string $user, string $password, ?string $jar = null): array {
        $jar ??= ($this->jar)();
        preg_match('/name="_token" value="([a-f0-9]+)"/', http_request('GET', WEB . '/?login', ['jar' => $jar])['body'], $m);
        $r = http_request('POST', WEB . '/?login', ['jar' => $jar, 'form' => ['username' => $user, 'password' => $password, '_token' => $m[1] ?? '']]);
        return ['jar' => $jar, 'response' => $r];
    };
    $this->csrf = function (string $jar): string {
        preg_match('/<meta name="csrf-token" content="([a-f0-9]+)">/', http_request('GET', WEB . '/', ['jar' => $jar])['body'], $m);
        return $m[1] ?? '';
    };
});

test('health says whether the checks are running', function () {
    $r = http_request('GET', WEB . '/?health');
    expect($r['status'])->toBe(503)
        ->and($r['json']['status'])->toBe('never_run');

    set_meta($this->db, 'last_run_at', utc_now(time() - 30));
    $r = http_request('GET', WEB . '/?health');
    expect($r['status'])->toBe(200)
        ->and($r['json']['status'])->toBe('ok')
        ->and($r['json']['seconds_since_last_run'])->toBeLessThan(60);

    set_meta($this->db, 'last_run_at', utc_now(time() - 3600));
    expect(http_request('GET', WEB . '/?health')['json']['status'])->toBe('stale');
});

test('every page sends strict security headers', function () {
    foreach (['/?login', '/', '/?health', '/?api=overview'] as $path) {
        $headers = http_request('GET', WEB . $path)['headers'];
        expect($headers['content-security-policy'] ?? '')->toBe("default-src 'none'; script-src 'self'; style-src 'self'; img-src 'self' data:; connect-src 'self'; form-action 'self'; frame-ancestors 'none'; base-uri 'none'")
            ->and($headers['x-content-type-options'] ?? '')->toBe('nosniff')
            ->and($headers['x-frame-options'] ?? '')->toBe('DENY')
            ->and($headers['referrer-policy'] ?? '')->toBe('no-referrer')
            ->and($headers['permissions-policy'] ?? '')->toContain('camera=()');
    }
});

test('pages have no inline script, inline style or inline event handlers', function () {
    $login = ($this->login)('admin', 'admin password');
    foreach ([http_request('GET', WEB . '/?login')['body'], http_request('GET', WEB . '/', ['jar' => $login['jar']])['body']] as $html) {
        expect($html)->toContain('<script src=')
            ->and($html)->not->toMatch('/<script(?![^>]*\ssrc=)[^>]*>/')
            ->and($html)->not->toMatch('/\sstyle="/')
            ->and($html)->not->toMatch('/\son[a-z]+="/');
    }
});

test('the dashboard and the API need a login', function () {
    $r = http_request('GET', WEB . '/');
    expect($r['status'])->toBe(302)
        ->and($r['headers']['location'])->toBe('/?login');

    $r = http_request('GET', WEB . '/?api=overview');
    expect($r['status'])->toBe(401)
        ->and($r['json']['error'])->toBe('Log in first');
});

test('logging in sets a strict session cookie and opens the dashboard', function () {
    $login = ($this->login)('admin', 'admin password');
    expect($login['response']['status'])->toBe(302)
        ->and($login['response']['headers']['location'])->toBe('/')
        ->and($login['response']['headers']['set-cookie'])->toContain('HttpOnly')
        ->and($login['response']['headers']['set-cookie'])->toContain('SameSite=Strict');

    $page = http_request('GET', WEB . '/', ['jar' => $login['jar']]);
    expect($page['status'])->toBe(200)
        ->and($page['body'])->toContain('<meta name="csrf-token" content="');
});

test('a wrong password or a missing CSRF token does not log in', function () {
    $wrong = ($this->login)('admin', 'not the password');
    expect($wrong['response']['status'])->toBe(200)
        ->and($wrong['response']['body'])->toContain('Wrong user name or password');

    $jar = ($this->jar)();
    $r = http_request('POST', WEB . '/?login', ['jar' => $jar, 'form' => ['username' => 'admin', 'password' => 'admin password']]);
    expect($r['status'])->toBe(200)
        ->and($r['body'])->toContain('The form expired');
    expect(http_request('GET', WEB . '/', ['jar' => $jar])['status'])->toBe(302);
});

test('too many failed logins lock the IP out, even with a fresh cookie', function () {
    for ($i = 0; $i < 3; $i++) ($this->login)('admin', 'guess ' . $i);
    $r = ($this->login)('admin', 'admin password')['response'];
    expect($r['status'])->toBe(200)
        ->and($r['body'])->toContain('Too many failed attempts')
        ->and((int) $this->db->query("SELECT COUNT(*) FROM audit_log WHERE action = 'login_failed'")->fetchColumn())->toBe(3);
});

test('what people type is never echoed back as markup', function () {
    $r = ($this->login)('<script>alert(1)</script>', 'x')['response'];
    expect($r['body'])->not->toContain('<script>alert(1)</script>');
});

test('the overview lists sites with their state, and a restricted user only sees their groups', function () {
    add_group($this->db, ['name' => 'Customers']);
    add_group($this->db, ['name' => 'Own']);
    $shop = add_site($this->db, ['url' => 'https://shop.example', 'group' => 'Customers']);
    add_site($this->db, ['url' => 'https://blog.example', 'group' => 'Own']);
    add_site($this->db, ['url' => 'https://loose.example']);
    $now = time();
    save_check_state($this->db, $shop, 'uptime', ['status' => 'fail', 'summary' => 'No answer within 10 s', 'details' => ['ip' => '192.0.2.10', 'problem' => 'timeout', 'status_code' => 0], 'fail_count' => 3, 'changed_at' => utc_now($now - 600)], utc_now($now - 30), utc_now($now + 30));
    $this->db->exec("UPDATE sites SET ip = '192.0.2.10' WHERE id = {$shop}");
    set_meta($this->db, 'last_run_at', utc_now($now - 30));

    $admin = http_request('GET', WEB . '/?api=overview', ['jar' => ($this->login)('admin', 'admin password')['jar']])['json'];
    expect($admin['totals'])->toMatchArray(['sites' => 3, 'down' => 1, 'pending' => 2])
        ->and($admin['run_ok'])->toBeTrue()
        ->and(array_column($admin['groups'], 'name'))->toBe(['Customers', 'Own', null]);
    $site = $admin['groups'][0]['sites'][0];
    expect($site)->toMatchArray(['id' => $shop, 'label' => 'shop.example', 'status' => 'down', 'summary' => 'No answer within 10 s', 'server' => '192.0.2.10'])
        ->and($site['since'])->toBe(utc_now($now - 600))
        ->and($site['advice'])->toStartWith('No answer in time');
    expect($admin['groups'][1]['sites'][0]['advice'])->toBe('');

    $anna = http_request('GET', WEB . '/?api=overview', ['jar' => ($this->login)('anna', 'anna password')['jar']])['json'];
    expect($anna['totals']['sites'])->toBe(1)
        ->and(array_column($anna['groups'], 'name'))->toBe(['Customers']);
});

test('site details have the history and response times, and are hidden from users without access', function () {
    add_group($this->db, ['name' => 'Own']);
    $blog = add_site($this->db, ['url' => 'https://blog.example', 'group' => 'Own']);
    $now = time();
    // Watched for two days, so the last 24 hours count in full
    $this->db->exec("UPDATE sites SET created_at = '" . utc_now($now - 2 * 86400) . "' WHERE id = {$blog}");
    add_event($this->db, $blog, 'uptime', ['status' => 'fail', 'previous' => 'ok', 'message' => 'Down: no answer within 10 s'], utc_now($now - 3600));
    add_event($this->db, $blog, 'uptime', ['status' => 'ok', 'previous' => 'fail', 'message' => 'Up again after 12 min: answered 200 OK in 230 ms'], utc_now($now - 2880));
    $this->db->exec("INSERT INTO samples (site_id, checked_at, status_code, ms, ok) VALUES ({$blog}, '" . utc_now($now - 600) . "', 200, 230, 1)");
    save_check_state($this->db, $blog, 'uptime', ['status' => 'ok', 'summary' => 'Answered 200 OK in 230 ms', 'details' => ['ms' => 230], 'fail_count' => 0, 'changed_at' => utc_now($now - 2880)], utc_now($now - 600), utc_now($now + 60));

    $admin = http_request('GET', WEB . "/?api=site&id={$blog}", ['jar' => ($this->login)('admin', 'admin password')['jar']]);
    expect($admin['status'])->toBe(200)
        ->and(array_column($admin['json']['events'], 'message'))->toBe(['Up again after 12 min: answered 200 OK in 230 ms', 'Down: no answer within 10 s'])
        ->and($admin['json']['samples'][0]['ms'])->toBe(230)
        ->and($admin['json']['uptime']['24h'])->toBeGreaterThan(99.0)->toBeLessThan(99.2);

    $anna = http_request('GET', WEB . "/?api=site&id={$blog}", ['jar' => ($this->login)('anna', 'anna password')['jar']]);
    expect($anna['status'])->toBe(404);
});

test('snoozing from the dashboard needs the CSRF token and access to the site', function () {
    add_group($this->db, ['name' => 'Own']);
    $blog = add_site($this->db, ['url' => 'https://blog.example', 'group' => 'Own']);
    save_check_state($this->db, $blog, 'uptime', ['status' => 'fail', 'summary' => 'No answer within 10 s', 'details' => [], 'fail_count' => 2, 'changed_at' => utc_now()], utc_now(), utc_now(time() + 60));

    $jar = ($this->login)('admin', 'admin password')['jar'];
    $r = http_request('POST', WEB . '/?api=snooze', ['jar' => $jar, 'json' => ['site_id' => $blog, 'for' => '4h']]);
    expect($r['status'])->toBe(403);

    $r = http_request('POST', WEB . '/?api=snooze', ['jar' => $jar, 'json' => ['site_id' => $blog, 'for' => '4h'], 'headers' => ['X-CSRF-Token: ' . ($this->csrf)($jar)]]);
    expect($r['status'])->toBe(200)
        ->and($r['json']['site']['snoozed_until'])->not->toBeNull();

    $r = http_request('POST', WEB . '/?api=snooze', ['jar' => $jar, 'json' => ['site_id' => $blog, 'action' => 'unsnooze'], 'headers' => ['X-CSRF-Token: ' . ($this->csrf)($jar)]]);
    expect($r['json']['site']['snoozed_until'])->toBeNull();

    $annaJar = ($this->login)('anna', 'anna password')['jar'];
    $r = http_request('POST', WEB . '/?api=snooze', ['jar' => $annaJar, 'json' => ['site_id' => $blog, 'for' => '1h'], 'headers' => ['X-CSRF-Token: ' . ($this->csrf)($annaJar)]]);
    expect($r['status'])->toBe(404);
});

test('logging out needs the CSRF token and ends the session', function () {
    $jar = ($this->login)('admin', 'admin password')['jar'];
    expect(http_request('GET', WEB . '/?logout', ['jar' => $jar])['status'])->toBe(405);

    $r = http_request('POST', WEB . '/?logout', ['jar' => $jar, 'form' => ['_token' => ($this->csrf)($jar)]]);
    expect($r['status'])->toBe(302)
        ->and($r['headers']['location'])->toBe('/?login')
        ->and(http_request('GET', WEB . '/', ['jar' => $jar])['status'])->toBe(302);
});

test('an unknown page or API call is a 404', function () {
    $jar = ($this->login)('admin', 'admin password')['jar'];
    expect(http_request('GET', WEB . '/?api=nope', ['jar' => $jar])['status'])->toBe(404)
        ->and(http_request('GET', WEB . '/?api=site&id=999', ['jar' => $jar])['status'])->toBe(404);
});
