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
        expect($headers['content-security-policy'] ?? '')->toBe("default-src 'none'; script-src 'self'; style-src 'self'; img-src 'self' data:; font-src 'self'; connect-src 'self'; form-action 'self'; frame-ancestors 'none'; base-uri 'none'")
            ->and($headers['x-content-type-options'] ?? '')->toBe('nosniff')
            ->and($headers['x-frame-options'] ?? '')->toBe('DENY')
            ->and($headers['referrer-policy'] ?? '')->toBe('no-referrer')
            ->and($headers['permissions-policy'] ?? '')->toContain('camera=()');
    }
});

test('pages have no inline script, inline style or inline event handlers', function () {
    $login = ($this->login)('admin', 'admin password');
    $dashboard = http_request('GET', WEB . '/', ['jar' => $login['jar']])['body'];
    expect($dashboard)->toContain('<script src=');
    foreach ([http_request('GET', WEB . '/?login')['body'], $dashboard] as $html) {
        expect($html)->not->toMatch('/<script(?![^>]*\ssrc=)[^>]*>/')
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

test('the login page credits Webready with a link', function () {
    expect(http_request('GET', WEB . '/?login')['body'])->toContain('Made by <a href="https://webready.se">Webready</a> in Sweden');
});

test('what people type is never echoed back as markup', function () {
    $r = ($this->login)('<script>alert(1)</script>', 'x')['response'];
    expect($r['body'])->not->toContain('<script>alert(1)</script>');
});

test('before the first user exists, the login page says how to create one', function () {
    unlink($GLOBALS['web_dir'] . '/users.json');
    $r = http_request('GET', WEB . '/?login');
    expect($r['status'])->toBe(200)
        ->and($r['body'])->toContain('No users yet')
        ->and($r['body'])->toContain('php vaka user:add')
        ->and($r['body'])->toContain('users.json')
        ->and($r['body'])->not->toContain('name="password"');

    // Posting anyway shows the same page and does not count as a failed login
    $r = http_request('POST', WEB . '/?login', ['form' => ['username' => 'admin', 'password' => 'x']]);
    expect($r['body'])->toContain('No users yet')
        ->and((int) $this->db->query('SELECT COUNT(*) FROM audit_log')->fetchColumn())->toBe(0);
});

test('the first steps come with the real cron line and whether alerts are set up, for users who see everything', function () {
    $admin = http_request('GET', WEB . '/?api=overview', ['jar' => ($this->login)('admin', 'admin password')['jar']])['json'];
    expect($admin['setup'])->toBe([
        'php' => php_cli(),
        'dir' => run_dir(dirname(__DIR__, 2)),
        'cron' => cron_line(dirname(__DIR__, 2), php_cli()),
        'alerts' => false,
    ]);
    $anna = http_request('GET', WEB . '/?api=overview', ['jar' => ($this->login)('anna', 'anna password')['jar']])['json'];
    expect($anna['setup'])->toBeNull();
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
    save_check_state($this->db, $shop, 'ssl', ['status' => 'warn', 'summary' => 'The certificate expires in 6 days (31 Oct 2026)', 'details' => ['days_left' => 6, 'valid_to' => '2026-10-31T12:00:00Z', 'problem' => 'expiring'], 'fail_count' => 0, 'changed_at' => utc_now($now)], utc_now($now), utc_now($now + 3600));
    $env = ['label' => '.env', 'what' => 'the environment file, often with database passwords and API keys', 'severity' => 'fail', 'url' => 'https://shop.example/.env'];
    save_check_state($this->db, $shop, 'domain', ['status' => 'warn', 'summary' => 'shop.example expires in 12 days (6 Nov 2026)', 'details' => ['domain' => 'shop.example', 'days_left' => 12, 'expires' => '2026-11-06T00:00:00Z', 'registrar' => 'Example Registrar AB', 'problem' => 'domain_expiring'], 'fail_count' => 0, 'changed_at' => utc_now($now)], utc_now($now), utc_now($now + 86400));
    $graded = evaluate_headers(['status' => 200, 'errno' => 0, 'error' => '', 'url' => 'https://shop.example/', 'headers' => ['x-frame-options' => ['SAMEORIGIN'], 'x-content-type-options' => ['nosniff']]], 10);
    save_check_state($this->db, $shop, 'headers', ['status' => $graded['status'], 'summary' => $graded['summary'], 'details' => $graded['details'], 'fail_count' => 0, 'changed_at' => utc_now($now)], utc_now($now), utc_now($now + 3600));
    save_check_state($this->db, $shop, 'exposed', ['status' => 'fail', 'summary' => '.env is public: the environment file, often with database passwords and API keys', 'details' => ['found' => [$env], 'checked' => 17, 'problem' => 'exposed'], 'fail_count' => 0, 'changed_at' => utc_now($now - 3600)], utc_now($now), utc_now($now + 3600));
    set_meta($this->db, 'last_run_at', utc_now($now - 30));

    $admin = http_request('GET', WEB . '/?api=overview', ['jar' => ($this->login)('admin', 'admin password')['jar']])['json'];
    expect($admin['totals'])->toMatchArray(['sites' => 3, 'down' => 1, 'pending' => 2])
        ->and($admin['run_ok'])->toBeTrue()
        ->and(array_column($admin['groups'], 'name'))->toBe(['Customers', 'Own', null]);
    $site = $admin['groups'][0]['sites'][0];
    expect($site)->toMatchArray(['id' => $shop, 'label' => 'shop.example', 'status' => 'down', 'summary' => 'No answer within 10 s', 'server' => '192.0.2.10'])
        ->and($site['since'])->toBe(utc_now($now - 600))
        ->and($site['advice'])->toStartWith('No answer in time')
        ->and($site['ssl'])->toMatchArray(['status' => 'warn', 'summary' => 'The certificate expires in 6 days (31 Oct 2026)', 'days_left' => 6])
        ->and($site['ssl']['advice'])->toStartWith('Renew the certificate')
        ->and($site['exposed'])->toMatchArray(['status' => 'fail', 'summary' => '.env is public: the environment file, often with database passwords and API keys', 'found' => [$env], 'since' => utc_now($now - 3600)])
        ->and($site['exposed']['advice'])->toStartWith('Remove the files from the web root')
        ->and($site['domain'])->toMatchArray(['status' => 'warn', 'summary' => 'shop.example expires in 12 days (6 Nov 2026)', 'domain' => 'shop.example', 'days_left' => 12, 'registrar' => 'Example Registrar AB'])
        ->and($site['domain']['advice'])->toStartWith('Renew it with the registrar')
        ->and($site['headers'])->toBe(['status' => 'warn', 'grade' => 'E', 'summary' => 'Grade E: 2 of 6 in place, missing HSTS, CSP, Referrer-Policy and Permissions-Policy']);
    expect($admin['groups'][1]['sites'][0]['ssl'])->toBeNull()
        ->and($admin['groups'][1]['sites'][0]['exposed'])->toBeNull()
        ->and($admin['groups'][1]['sites'][0]['headers'])->toBeNull()
        ->and($admin['groups'][1]['sites'][0]['domain'])->toBeNull();

    $detail = http_request('GET', WEB . "/?api=site&id={$shop}", ['jar' => ($this->login)('admin', 'admin password')['jar']])['json'];
    expect($detail['site']['headers']['grade'])->toBe('E')
        ->and(array_column($detail['site']['headers']['items'], 'level', 'key'))->toBe(['hsts' => 'missing', 'csp' => 'missing', 'nosniff' => 'good', 'frames' => 'good', 'referrer' => 'missing', 'permissions' => 'missing'])
        ->and($detail['site']['headers']['items'][1])->toMatchArray(['label' => 'CSP', 'fix' => SECURITY_HEADERS['csp']['fix'], 'what' => SECURITY_HEADERS['csp']['what']]);
    expect($admin['groups'][1]['sites'][0]['advice'])->toBe('');

    $anna = http_request('GET', WEB . '/?api=overview', ['jar' => ($this->login)('anna', 'anna password')['jar']])['json'];
    expect($anna['totals']['sites'])->toBe(1)
        ->and(array_column($anna['groups'], 'name'))->toBe(['Customers']);
});

test('uptime counts from the first check: a site not checked yet has no figures', function () {
    $now = time();
    $new = add_site($this->db, ['url' => 'https://new.example']);
    $fresh = add_site($this->db, ['url' => 'https://fresh.example']);
    $this->db->exec("UPDATE sites SET created_at = '" . utc_now($now - 3 * 86400) . "'");
    // Checked for the first time an hour ago, then down for 6 minutes
    add_event($this->db, $fresh, 'uptime', ['status' => 'ok', 'previous' => 'pending', 'message' => 'First check: answered 200 OK in 90 ms'], utc_now($now - 3600));
    add_event($this->db, $fresh, 'uptime', ['status' => 'fail', 'previous' => 'ok', 'message' => 'Down: no answer within 10 s'], utc_now($now - 1800));
    add_event($this->db, $fresh, 'uptime', ['status' => 'ok', 'previous' => 'fail', 'message' => 'Up again after 6 min'], utc_now($now - 1440));
    save_check_state($this->db, $fresh, 'uptime', ['status' => 'ok', 'summary' => 'Answered 200 OK in 90 ms', 'details' => ['ms' => 90], 'fail_count' => 0, 'changed_at' => utc_now($now - 1440)], utc_now($now - 60), utc_now($now + 240));

    $sites = array_column(http_request('GET', WEB . '/?api=overview', ['jar' => ($this->login)('admin', 'admin password')['jar']])['json']['groups'][0]['sites'], null, 'label');
    expect($sites['new.example'])->toMatchArray(['uptime_24h' => null, 'uptime_30d' => null])
        ->and(array_filter(array_column($sites['new.example']['days'], 'up'), fn ($up) => $up !== null))->toBe([])
        // 6 minutes down out of the hour since the first check, not out of three days
        ->and($sites['fresh.example']['uptime_30d'])->toEqual(90.0);
});

test('site details have the history and response times, and are hidden from users without access', function () {
    add_group($this->db, ['name' => 'Own']);
    $blog = add_site($this->db, ['url' => 'https://blog.example', 'group' => 'Own']);
    $now = time();
    // Watched for two days, so the last 24 hours count in full
    $this->db->exec("UPDATE sites SET created_at = '" . utc_now($now - 2 * 86400) . "' WHERE id = {$blog}");
    add_event($this->db, $blog, 'uptime', ['status' => 'ok', 'previous' => 'pending', 'message' => 'First check: answered 200 OK in 210 ms'], utc_now($now - 2 * 86400));
    add_event($this->db, $blog, 'uptime', ['status' => 'fail', 'previous' => 'ok', 'message' => 'Down: no answer within 10 s'], utc_now($now - 3600));
    add_event($this->db, $blog, 'uptime', ['status' => 'ok', 'previous' => 'fail', 'message' => 'Up again after 12 min: answered 200 OK in 230 ms'], utc_now($now - 2880));
    $this->db->exec("INSERT INTO samples (site_id, checked_at, status_code, ms, ok) VALUES ({$blog}, '" . utc_now($now - 600) . "', 200, 230, 1)");
    save_check_state($this->db, $blog, 'uptime', ['status' => 'ok', 'summary' => 'Answered 200 OK in 230 ms', 'details' => ['ms' => 230], 'fail_count' => 0, 'changed_at' => utc_now($now - 2880)], utc_now($now - 600), utc_now($now + 60));

    $admin = http_request('GET', WEB . "/?api=site&id={$blog}", ['jar' => ($this->login)('admin', 'admin password')['jar']]);
    expect($admin['status'])->toBe(200)
        ->and(array_column($admin['json']['events'], 'message'))->toBe(['Up again after 12 min: answered 200 OK in 230 ms', 'Down: no answer within 10 s', 'First check: answered 200 OK in 210 ms'])
        ->and($admin['json']['samples'][0]['ms'])->toBe(230)
        ->and($admin['json']['uptime']['24h'])->toBeGreaterThan(99.0)->toBeLessThan(99.2)
        ->and($admin['json']['days'])->toHaveCount(30)
        // The outage an hour ago is today, or yesterday just after midnight
        ->and(min(array_column(array_slice($admin['json']['days'], -2), 'up')))->toBeLessThan(100.0);

    $overview = http_request('GET', WEB . '/?api=overview', ['jar' => ($this->login)('admin', 'admin password')['jar']])['json'];
    expect($overview['groups'][0]['sites'][0]['days'])->toHaveCount(30)
        // Watched for two days: 12 minutes down out of 48 hours
        ->and($overview['groups'][0]['sites'][0]['uptime_30d'])->toBe(99.58)
        ->and($overview['last_incident'])->toMatchArray(['label' => 'blog.example', 'message' => 'Down: no answer within 10 s']);

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

// =====================================================================
// The read API (docs/api.md)
// =====================================================================

test('the read API answers a share token with the sites in its scope, in stable fields', function () {
    $customers = add_group($this->db, ['name' => 'Customers']);
    add_group($this->db, ['name' => 'Own']);
    $shop = add_site($this->db, ['url' => 'https://shop.example', 'group' => 'Customers']);
    add_site($this->db, ['url' => 'https://blog.example', 'group' => 'Own']);
    $now = time();
    save_check_state($this->db, $shop, 'uptime', ['status' => 'fail', 'summary' => 'No answer within 10 s', 'details' => ['problem' => 'timeout', 'status_code' => 0], 'fail_count' => 2, 'changed_at' => utc_now($now - 600)], utc_now($now - 30), utc_now($now + 30));
    save_check_state($this->db, $shop, 'ssl', ['status' => 'warn', 'summary' => 'The certificate expires in 6 days (31 Oct 2026)', 'details' => ['days_left' => 6, 'valid_to' => '2026-10-31T12:00:00Z', 'problem' => 'expiring'], 'fail_count' => 0, 'changed_at' => utc_now($now)], utc_now($now), utc_now($now + 3600));
    set_meta($this->db, 'last_run_at', utc_now($now - 30));
    $token = create_share_token($this->db, null, $customers, 'AI assistant', null, $now)['token'];

    $r = http_request('GET', WEB . '/?api&share=' . $token);
    expect($r['status'])->toBe(200)
        ->and($r['headers'])->not->toHaveKey('set-cookie')
        ->and($r['json'])->toMatchArray(['version' => VAKA_VERSION, 'scope' => ['type' => 'group', 'name' => 'Customers'], 'run_ok' => true])
        ->and($r['json'])->not->toHaveKey('setup')
        ->and($r['json']['totals'])->toMatchArray(['sites' => 1, 'down' => 1])
        ->and($r['json']['sites'])->toHaveCount(1);
    $site = $r['json']['sites'][0];
    expect(array_keys($site))->toBe(['url', 'label', 'name', 'group', 'status', 'summary', 'advice', 'since', 'checked_at', 'response_ms', 'server', 'cdn', 'snoozed_until', 'maintenance', 'unstable', 'uptime', 'ssl', 'domain', 'exposed', 'headers'])
        ->and($site)->toMatchArray(['url' => 'https://shop.example', 'label' => 'shop.example', 'group' => 'Customers', 'status' => 'down', 'summary' => 'No answer within 10 s', 'since' => utc_now($now - 600)])
        ->and(array_keys($site['uptime']))->toBe(['24h', '7d', '30d'])
        ->and($site['ssl'])->toMatchArray(['status' => 'warn', 'days_left' => 6, 'valid_to' => '2026-10-31T12:00:00Z']);

    // The same with the token in a header, which keeps it out of URLs and logs
    $r = http_request('GET', WEB . '/?api', ['headers' => ['Authorization: Bearer ' . $token]]);
    expect($r['status'])->toBe(200)
        ->and(array_column($r['json']['sites'], 'label'))->toBe(['shop.example']);
});

test('an unknown, revoked or expired share token is a 404, and a token reads nothing outside its scope', function () {
    $customers = add_group($this->db, ['name' => 'Customers']);
    add_site($this->db, ['url' => 'https://shop.example', 'group' => 'Customers']);
    add_site($this->db, ['url' => 'https://blog.example']);
    $now = time();
    $expired = create_share_token($this->db, null, $customers, '', utc_now($now - 1), $now - 86400)['token'];
    $revoked = create_share_token($this->db, null, $customers, '', null, $now);
    revoke_share_token($this->db, $revoked['id']);
    $token = create_share_token($this->db, null, $customers, '', null, $now)['token'];

    foreach ([str_repeat('0', 64), $expired, $revoked['token'], 'nonsense'] as $bad) {
        $r = http_request('GET', WEB . '/?api&share=' . $bad);
        expect($r['status'])->toBe(404)
            ->and($r['json'])->toBe(['error' => 'No such read token: it may have been revoked or have expired']);
    }
    expect(http_request('GET', WEB . '/?api&site=blog.example&share=' . $token)['status'])->toBe(404)
        ->and(http_request('GET', WEB . '/?api=overview&share=' . $token)['status'])->toBe(401)
        ->and(http_request('POST', WEB . '/?api&share=' . $token)['status'])->toBe(405)
        ->and(http_request('GET', WEB . '/?api')['status'])->toBe(401);
});

test('one site in detail: every check\'s history and response times, and CSV for spreadsheets', function () {
    $shop = add_site($this->db, ['url' => 'https://shop.example']);
    $now = time();
    add_event($this->db, $shop, 'uptime', ['status' => 'ok', 'previous' => 'pending', 'message' => 'First check: answered 200 OK in 210 ms'], utc_now($now - 7200));
    add_event($this->db, $shop, 'ssl', ['status' => 'warn', 'previous' => 'ok', 'message' => 'The certificate expires in 14 days'], utc_now($now - 3600));
    $this->db->exec("INSERT INTO samples (site_id, checked_at, status_code, ms, ok) VALUES ({$shop}, '" . utc_now($now - 600) . "', 200, 230, 1), ({$shop}, '" . utc_now($now - 300) . "', 503, 120, 0), ({$shop}, '" . utc_now($now - 2 * 86400) . "', 200, 250, 1)");
    save_check_state($this->db, $shop, 'uptime', ['status' => 'ok', 'summary' => 'Answered 200 OK in 230 ms', 'details' => ['ms' => 230], 'fail_count' => 0, 'changed_at' => utc_now($now - 7200)], utc_now($now - 60), utc_now($now + 240));
    $token = create_share_token($this->db, $shop, null, '', null, $now)['token'];

    $r = http_request('GET', WEB . '/?api&site=shop.example&share=' . $token);
    expect($r['status'])->toBe(200)
        ->and($r['json']['scope'])->toBe(['type' => 'site', 'name' => 'shop.example'])
        ->and($r['json']['site'])->toMatchArray(['url' => 'https://shop.example', 'status' => 'up', 'response_ms' => 230, 'keyword' => '', 'interval_minutes' => 5])
        ->and(array_column($r['json']['events'], 'check'))->toBe(['ssl', 'uptime'])
        ->and($r['json']['events'][0])->toMatchArray(['status' => 'warn', 'previous' => 'ok', 'message' => 'The certificate expires in 14 days'])
        ->and(array_column($r['json']['samples'], 'ms'))->toBe([230, 120])
        ->and($r['json']['samples'][1])->toMatchArray(['status_code' => 503, 'ok' => false])
        ->and($r['json']['days'])->toHaveCount(30)
        ->and($r['json']['maintenance'])->toBe([]);

    // Further back, up to the 30 days that are kept
    expect(http_request('GET', WEB . '/?api&site=shop.example&hours=72&share=' . $token)['json']['samples'])->toHaveCount(3);

    $csv = http_request('GET', WEB . '/?api&site=shop.example&format=csv&share=' . $token);
    expect($csv['headers']['content-type'])->toBe('text/csv; charset=utf-8')
        ->and(explode("\n", trim($csv['body'])))->toBe(['checked_at,status_code,ms,ok', utc_now($now - 600) . ',200,230,1', utc_now($now - 300) . ',503,120,0']);

    $list = http_request('GET', WEB . '/?api&format=csv&share=' . $token);
    expect(explode("\n", trim($list['body']))[0])->toBe('url,group,status,since,response_ms,uptime_24h,uptime_7d,uptime_30d,ssl_days_left,domain_days_left,headers_grade,exposed')
        ->and(explode("\n", trim($list['body']))[1])->toStartWith('https://shop.example,,up,');
});

test('a logged-in user reads the same API for the sites they may see', function () {
    add_group($this->db, ['name' => 'Customers']);
    add_site($this->db, ['url' => 'https://shop.example', 'group' => 'Customers']);
    add_site($this->db, ['url' => 'https://blog.example']);

    $anna = http_request('GET', WEB . '/?api', ['jar' => ($this->login)('anna', 'anna password')['jar']]);
    expect($anna['status'])->toBe(200)
        ->and($anna['json']['scope'])->toBe(['type' => 'user', 'name' => 'anna'])
        ->and(array_column($anna['json']['sites'], 'label'))->toBe(['shop.example']);
    $admin = http_request('GET', WEB . '/?api', ['jar' => ($this->login)('admin', 'admin password')['jar']]);
    expect(array_column($admin['json']['sites'], 'label'))->toBe(['shop.example', 'blog.example']);
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
