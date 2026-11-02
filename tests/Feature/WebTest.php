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
    foreach (['/?login', '/', '/?health', '/?api=overview', '/?manifest'] as $path) {
        $headers = http_request('GET', WEB . $path)['headers'];
        expect($headers['content-security-policy'] ?? '')->toBe("default-src 'none'; script-src 'self'; style-src 'self'; img-src 'self' data:; font-src 'self'; connect-src 'self'; manifest-src 'self'; form-action 'self'; frame-ancestors 'none'; base-uri 'none'")
            ->and($headers['x-content-type-options'] ?? '')->toBe('nosniff')
            ->and($headers['x-frame-options'] ?? '')->toBe('DENY')
            ->and($headers['referrer-policy'] ?? '')->toBe('no-referrer')
            ->and($headers['permissions-policy'] ?? '')->toContain('camera=()');
    }
});

test('Vaka can be added to a phone\'s home screen with its own icon, from the login page or the dashboard', function () {
    // Browsers fetch the manifest without cookies, so it needs no login
    $r = http_request('GET', WEB . '/?manifest');
    expect($r['status'])->toBe(200)
        ->and($r['headers']['content-type'] ?? '')->toContain('application/manifest+json')
        ->and($r['json'])->toMatchArray(['name' => 'Vaka', 'short_name' => 'Vaka', 'start_url' => '/', 'display' => 'standalone'])
        ->and(array_column($r['json']['icons'], 'src'))->toBe(['/icon-192.png', '/icon-512.png', '/icon-512.png']);
    foreach ([180, 192, 512] as $size) {
        $file = dirname(__DIR__, 2) . "/public/icon-{$size}.png";
        expect(getimagesize($file))->toMatchArray([0 => $size, 1 => $size, 'mime' => 'image/png']);
    }

    $login = http_request('GET', WEB . '/?login')['body'];
    $dashboard = http_request('GET', WEB . '/', ['jar' => ($this->login)('admin', 'admin password')['jar']])['body'];
    foreach ([$login, $dashboard] as $page) {
        expect($page)->toContain('<link rel="manifest" href="/?manifest">')
            ->and($page)->toContain('<link rel="apple-touch-icon" href="/icon-180.png">')
            ->and($page)->toContain('<meta name="apple-mobile-web-app-title" content="Vaka">');
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

/** The session id in a cookie jar, and the session's file on the test server. */
function session_cookie(string $jar): string
{
    foreach (file($jar, FILE_IGNORE_NEW_LINES) as $line) {
        $fields = explode("\t", $line);
        if (count($fields) === 7 && $fields[5] === 'vaka') return $fields[6];
    }
    return '';
}

function session_file(string $id): string
{
    return $GLOBALS['web_dir'] . "/sessions/sess_{$id}";
}

/** Log in and make the hourly new session id due; returns the jar and a copy of it that keeps the old id. */
function due_for_new_id(Closure $login): array
{
    $jar = $login('admin', 'admin password')['jar'];
    $old = session_cookie($jar);
    file_put_contents(session_file($old), preg_replace('/rotated_at\|i:\d+;/', 'rotated_at|i:0;', file_get_contents(session_file($old))));
    $stale = tempnam(sys_get_temp_dir(), 'vaka-jar-');
    copy($jar, $stale);
    return [$jar, $stale, $old];
}

test('a request still on the session id that was just replaced stays logged in, on the new id', function () {
    [$jar, $stale, $old] = due_for_new_id($this->login);

    // Two requests sent together: the first gets the new id, the second was already on its way with the old one
    expect(http_request('GET', WEB . '/?api=overview', ['jar' => $jar])['status'])->toBe(200);
    $new = session_cookie($jar);
    expect($new)->not->toBe($old)->and($new)->not->toBe('');

    expect(http_request('GET', WEB . '/?api=overview', ['jar' => $stale])['status'])->toBe(200)
        ->and(session_cookie($stale))->toBe($new)
        ->and(http_request('GET', WEB . '/?api=overview', ['jar' => $jar])['status'])->toBe(200);
});

test('a session id replaced more than a minute ago no longer logs in', function () {
    [$jar, $stale, $old] = due_for_new_id($this->login);
    http_request('GET', WEB . '/?api=overview', ['jar' => $jar]);

    // A minute later the old id counts for nothing: one still in use could be a stolen one
    file_put_contents(session_file($old), preg_replace('/replaced_at\|i:\d+;/', 'replaced_at|i:' . (time() - 120) . ';', file_get_contents(session_file($old))));
    expect(http_request('GET', WEB . '/?api=overview', ['jar' => $stale])['status'])->toBe(401)
        ->and(file_exists(session_file($old)))->toBeFalse()
        ->and(http_request('GET', WEB . '/?api=overview', ['jar' => $jar])['status'])->toBe(200);
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
    expect($admin['setup'])->toMatchArray([
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
    $graded = evaluate_headers(['status' => 200, 'errno' => 0, 'error' => '', 'url' => 'https://shop.example/', 'headers' => ['server' => ['nginx'], 'x-frame-options' => ['SAMEORIGIN'], 'x-content-type-options' => ['nosniff']]], 10);
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
        ->and($site['domain'])->toMatchArray(['status' => 'warn', 'summary' => 'shop.example expires in 12 days (6 Nov 2026)', 'domain' => 'shop.example', 'label' => 'shop.example', 'days_left' => 12, 'registrar' => 'Example Registrar AB'])
        ->and($site['domain']['advice'])->toStartWith('Renew it with the registrar')
        ->and($site['headers'])->toBe(['status' => 'warn', 'grade' => 'E', 'summary' => 'Grade E: 2 of 6 in place, missing HSTS, CSP, Referrer-Policy and Permissions-Policy', 'checked_at' => utc_now($now), 'next_at' => utc_now($now + 3600)]);
    expect($admin['groups'][1]['sites'][0]['ssl'])->toBeNull()
        ->and($admin['groups'][1]['sites'][0]['exposed'])->toBeNull()
        ->and($admin['groups'][1]['sites'][0]['headers'])->toBeNull()
        ->and($admin['groups'][1]['sites'][0]['domain'])->toBeNull();

    $detail = http_request('GET', WEB . "/?api=site&id={$shop}", ['jar' => ($this->login)('admin', 'admin password')['jar']])['json'];
    expect($detail['site']['headers']['grade'])->toBe('E')
        // In the order to add them: the safest first, CSP last
        ->and(array_column($detail['site']['headers']['items'], 'level', 'key'))->toBe(['nosniff' => 'good', 'referrer' => 'missing', 'frames' => 'good', 'hsts' => 'missing', 'permissions' => 'missing', 'csp' => 'missing'])
        ->and($detail['site']['headers']['items'][5])->toMatchArray(['label' => 'CSP', 'fix' => SECURITY_HEADERS['csp']['fix'], 'what' => SECURITY_HEADERS['csp']['what']])
        // Once for all six: how to add them in nginx and in Apache without losing them, and which one the site runs
        ->and($detail['site']['headers'])->toMatchArray(['server' => 'nginx', 'tip' => HEADERS_NGINX_TIP, 'tip_apache' => HEADERS_APACHE_TIP]);
    $token = create_share_token($this->db, $shop, null, '', utc_now(time() + 86400), time())['token'];
    expect(http_request('GET', WEB . '/?api&share=' . $token)['json']['site']['headers'])->toMatchArray(['server' => 'nginx', 'tip' => HEADERS_NGINX_TIP, 'tip_apache' => HEADERS_APACHE_TIP]);
    expect($admin['groups'][1]['sites'][0]['advice'])->toBe('');

    $anna = http_request('GET', WEB . '/?api=overview', ['jar' => ($this->login)('anna', 'anna password')['jar']])['json'];
    expect($anna['totals']['sites'])->toBe(1)
        ->and(array_column($anna['groups'], 'name'))->toBe(['Customers']);
});

test('mail DNS shows in words on the overview, and record by record in the site detail and the read API', function () {
    $shop = add_site($this->db, ['url' => 'https://shop.example']);
    $now = time();
    $result = evaluate_mail_dns('shop.example', ['mx' => ['10 mx1.mail.example'], 'spf' => ['v=spf1 -all'], 'dmarc' => []]);
    save_check_state($this->db, $shop, 'dns', ['status' => $result['status'], 'summary' => $result['summary'], 'details' => confirm_mail_dns(null, $result)['details'], 'fail_count' => 0, 'changed_at' => utc_now($now)], utc_now($now), utc_now($now + 3600));
    $jar = ($this->login)('admin', 'admin password')['jar'];

    $site = http_request('GET', WEB . '/?api=overview', ['jar' => $jar])['json']['groups'][0]['sites'][0];
    expect($site['dns'])->toMatchArray(['status' => 'warn', 'summary' => 'Mail for shop.example: DMARC is missing', 'domain' => 'shop.example'])
        ->and($site['dns']['advice'])->toStartWith('Add a DMARC record');

    $detail = http_request('GET', WEB . "/?api=site&id={$shop}", ['jar' => $jar])['json'];
    expect(array_column($detail['site']['dns']['items'], 'level', 'key'))->toBe(['mx' => 'good', 'spf' => 'good', 'dmarc' => 'missing'])
        ->and($detail['site']['dns']['items'][0]['records'])->toBe(['10 mx1.mail.example']);

    $token = create_share_token($this->db, $shop, null, '', utc_now($now + 86400), $now)['token'];
    $api = http_request('GET', WEB . '/?api&site=shop.example&share=' . $token)['json'];
    expect($api['site']['dns'])->toMatchArray(['status' => 'warn', 'domain' => 'shop.example'])
        ->and(array_keys($api['site']['dns']['items'][2]))->toBe(['key', 'label', 'level', 'records', 'note', 'what', 'fix_label', 'fix', 'tip']);
});

test('a site behind a bot check says so, with what to do and the address to let through, in the overview and the read API', function () {
    $shop = add_site($this->db, ['url' => 'https://shop.example']);
    $now = time();
    $details = ['blocked' => 'Imunify360', 'blocked_since' => utc_now($now - 3600), 'from_ip' => '2001:db8::5', 'status_code' => 200, 'ms' => 231, 'problem' => ''];
    save_check_state($this->db, $shop, 'uptime', ['status' => 'ok', 'summary' => "Imunify360's bot check answered instead of the site", 'details' => $details, 'fail_count' => 0, 'changed_at' => utc_now($now - 86400)], utc_now($now), utc_now($now + 300));
    $jar = ($this->login)('admin', 'admin password')['jar'];

    $site = http_request('GET', WEB . '/?api=overview', ['jar' => $jar])['json']['groups'][0]['sites'][0];
    expect($site['blocked'])->toBe(['by' => 'Imunify360', 'since' => utc_now($now - 3600), 'from_ip' => '2001:db8::5'])
        ->and($site['advice'])->toStartWith('While a bot check answers instead of the site')
        // The last time measured is not the site's time now
        ->and($site['ms'])->toBeNull();

    $token = create_share_token($this->db, $shop, null, '', utc_now($now + 86400), $now)['token'];
    expect(http_request('GET', WEB . '/?api&share=' . $token)['json']['site']['blocked'])->toBe(['by' => 'Imunify360', 'since' => utc_now($now - 3600), 'from_ip' => '2001:db8::5']);

    // Seen again: nothing to say
    save_check_state($this->db, $shop, 'uptime', ['status' => 'ok', 'summary' => 'Answered 200 OK in 90 ms', 'details' => ['status_code' => 200, 'ms' => 90, 'problem' => ''], 'fail_count' => 0, 'changed_at' => utc_now($now - 86400)], utc_now($now), utc_now($now + 300));
    $site = http_request('GET', WEB . '/?api=overview', ['jar' => $jar])['json']['groups'][0]['sites'][0];
    expect($site['blocked'])->toBeNull()
        ->and($site['advice'])->toBe('')
        ->and($site['ms'])->toBe(90);
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
        ->and(min(array_column(array_slice($admin['json']['days'], -2), 'up')))->toBeLessThan(100.0)
        ->and(array_sum(array_column($admin['json']['days'], 'down_seconds')))->toBe(12 * 60)
        // One outage, on two days if midnight fell inside it
        ->and(array_sum(array_column($admin['json']['days'], 'outages')))->toBeGreaterThanOrEqual(1)->toBeLessThanOrEqual(2)
        ->and(array_sum(array_column($admin['json']['days'], 'planned_seconds')))->toBe(0);

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
// Managing sites from the dashboard
// =====================================================================

test('a user who sees every site can add, change and remove a site, and each change is in its history', function () {
    add_group($this->db, ['name' => 'Customers']);
    $jar = ($this->login)('admin', 'admin password')['jar'];
    $csrf = ($this->csrf)($jar);
    $post = fn (string $endpoint, array $body) => http_request('POST', WEB . "/?api={$endpoint}", ['jar' => $jar, 'json' => $body, 'headers' => ['X-CSRF-Token: ' . $csrf]]);
    $count = fn () => (int) $this->db->query('SELECT COUNT(*) FROM sites')->fetchColumn();

    // What the form needs: every group, the checks and the default interval
    $setup = http_request('GET', WEB . '/?api=overview', ['jar' => $jar])['json']['setup'];
    expect($setup['groups'])->toBe(['Customers'])
        ->and(array_column($setup['checks'], 'key'))->toBe(CHECKS)
        ->and($setup['interval'])->toBe(5);

    // Wrong input is refused field by field, and nothing is saved
    $r = $post('site-save', ['url' => '', 'interval' => '0']);
    expect($r['status'])->toBe(422)
        ->and(array_keys($r['json']['errors']))->toBe(['url', 'interval'])
        ->and($r['json']['error'])->toBe('Enter the address of the site, such as https://shop.example.')
        ->and($count())->toBe(0);

    $r = $post('site-save', ['url' => 'shop.example', 'group' => 'Customers', 'keyword' => 'Welcome', 'checks' => ['uptime', 'ssl', 'domain', 'exposed', 'headers']]);
    expect($r['status'])->toBe(200)
        ->and($r['json']['site'])->toMatchArray(['label' => 'shop.example', 'group' => 'Customers', 'status' => 'pending']);
    $id = $r['json']['site']['id'];
    expect($post('site-save', ['url' => 'https://shop.example/'])['json']['errors']['url'])->toBe('https://shop.example is already watched.');

    // An address that is not in DNS waits for a yes: a typo is caught before it alerts
    $typo = $post('site-save', ['url' => 'shop.invalid', 'checks' => CHECKS]);
    expect($typo['status'])->toBe(422)
        ->and($typo['json']['unresolved'])->toBeTrue()
        ->and($typo['json']['errors']['url'])->toStartWith('shop.invalid does not resolve: no DNS record says where it is.')
        ->and(($meant = $post('site-save', ['url' => 'shop.invalid', 'checks' => CHECKS, 'unresolved_ok' => true]))['status'])->toBe(200);
    $post('site-remove', ['id' => $meant['json']['site']['id']]);

    $r = $post('site-save', ['id' => $id, 'url' => 'shop.example', 'group' => '', 'keyword' => '', 'interval' => '2', 'paused' => true, 'checks' => CHECKS]);
    expect($r['status'])->toBe(200)
        ->and($r['json']['changes'])->toBe(['no group', 'no longer looks for a text', 'checked every 2 min', 'paused', 'Mail DNS turned on'])
        ->and($r['json']['site']['status'])->toBe('paused');

    $detail = http_request('GET', WEB . "/?api=site&id={$id}", ['jar' => $jar])['json'];
    expect(array_column($detail['events'], 'message'))->toBe(['Changed by admin: no group, no longer looks for a text, checked every 2 min, paused, Mail DNS turned on', 'Added by admin'])
        ->and($detail['site'])->toMatchArray(['interval_custom' => 2, 'checks_off' => []]);

    $r = $post('site-remove', ['id' => $id]);
    expect($r['status'])->toBe(200)
        ->and($r['json'])->toBe(['removed' => 'shop.example'])
        ->and($count())->toBe(0)
        ->and($this->db->query("SELECT action FROM audit_log WHERE username = 'admin' AND action LIKE 'site_removed%' ORDER BY id DESC")->fetchColumn())->toBe('site_removed https://shop.example');
});

test('only users who see every site may change sites, and only with the CSRF token', function () {
    add_group($this->db, ['name' => 'Customers']);
    $shop = add_site($this->db, ['url' => 'https://shop.example', 'group' => 'Customers']);

    $anna = ($this->login)('anna', 'anna password')['jar'];
    foreach (['site-save' => ['id' => $shop, 'url' => 'shop.example', 'paused' => true], 'site-remove' => ['id' => $shop]] as $endpoint => $body) {
        $r = http_request('POST', WEB . "/?api={$endpoint}", ['jar' => $anna, 'json' => $body, 'headers' => ['X-CSRF-Token: ' . ($this->csrf)($anna)]]);
        expect($r['status'])->toBe(403)
            ->and($r['json']['error'])->toBe('Only users who see every site can change sites');
    }
    $admin = ($this->login)('admin', 'admin password')['jar'];
    expect(http_request('POST', WEB . '/?api=site-remove', ['jar' => $admin, 'json' => ['id' => $shop]])['status'])->toBe(403)
        ->and(http_request('POST', WEB . '/?api=site-remove', ['jar' => $admin, 'json' => ['id' => 999], 'headers' => ['X-CSRF-Token: ' . ($this->csrf)($admin)]])['status'])->toBe(404)
        ->and((int) $this->db->query('SELECT paused FROM sites')->fetchColumn())->toBe(0);
});

test('groups are added, changed and removed in the dashboard, with webhooks masked and a test alert to check them', function () {
    $jar = ($this->login)('admin', 'admin password')['jar'];
    $csrf = ($this->csrf)($jar);
    $post = fn (string $endpoint, array $body) => http_request('POST', WEB . "/?api={$endpoint}", ['jar' => $jar, 'json' => $body, 'headers' => ['X-CSRF-Token: ' . $csrf]]);

    $r = $post('group-save', ['name' => '', 'policy' => 'immediate']);
    expect($r['status'])->toBe(422)->and($r['json']['errors'])->toHaveKey('name');

    // A local address that refuses, so the test alert below fails without going out
    $r = $post('group-save', ['name' => 'Customers', 'policy' => 'delayed', 'delay' => '10', 'slack' => 'http://127.0.0.1:9/hook-secret1234']);
    expect($r['status'])->toBe(200);
    $id = $r['json']['group']['id'];

    // An empty group shows in the overview, so sites can be added to it
    $overview = http_request('GET', WEB . '/?api=overview', ['jar' => $jar])['json'];
    expect($overview['groups'][0])->toMatchArray(['id' => $id, 'name' => 'Customers', 'alerting' => 'after 10 min down', 'sites' => []]);

    $group = http_request('GET', WEB . "/?api=group&id={$id}", ['jar' => $jar]);
    expect($group['status'])->toBe(200)
        ->and($group['json']['group'])->toMatchArray(['name' => 'Customers', 'policy' => 'delayed', 'delay_minutes' => 10, 'slack' => '127.0.0.1/...1234', 'webhook' => '', 'email' => ''])
        ->and($group['body'])->not->toContain('secret1234');

    // Sending a test says where it went, and whether that worked
    $r = $post('alert-test', ['group' => $id]);
    expect($r['status'])->toBe(200)
        ->and($r['json']['sent'])->toBe([['to' => 'Slack 127.0.0.1/...1234', 'ok' => false]]);

    // anna is limited to Customers, so the group stays until that changes
    $r = $post('group-remove', ['id' => $id]);
    expect($r['status'])->toBe(409)
        ->and($r['json']['error'])->toStartWith('Customers cannot be removed while users are limited to it: anna.')
        ->and(find_group($this->db, 'Customers'))->not->toBeNull();
    $users = load_users($GLOBALS['web_dir'] . '/users.json');
    $users['anna']['groups'] = ['Own'];
    save_users($GLOBALS['web_dir'] . '/users.json', $users);

    $r = $post('group-remove', ['id' => $id]);
    expect($r['status'])->toBe(200)
        ->and($r['json'])->toBe(['removed' => 'Customers', 'sites' => 0])
        ->and(find_group($this->db, 'Customers'))->toBeNull();

    $anna = ($this->login)('anna', 'anna password')['jar'];
    expect(http_request('GET', WEB . "/?api=group&id={$id}", ['jar' => $anna])['status'])->toBe(403);
});

test('maintenance windows and server names are set in the dashboard too', function () {
    add_group($this->db, ['name' => 'Customers']);
    $shop = add_site($this->db, ['url' => 'https://shop.example', 'group' => 'Customers']);
    $this->db->exec("UPDATE sites SET ip = '203.0.113.10'");
    $jar = ($this->login)('admin', 'admin password')['jar'];
    $csrf = ($this->csrf)($jar);
    $post = fn (string $endpoint, array $body) => http_request('POST', WEB . "/?api={$endpoint}", ['jar' => $jar, 'json' => $body, 'headers' => ['X-CSRF-Token: ' . $csrf]]);

    $r = $post('maintenance-add', ['site_id' => $shop, 'kind' => 'days', 'start' => '22:00', 'end' => '02:00', 'days' => []]);
    expect($r['status'])->toBe(422)->and($r['json']['errors'])->toBe(['when' => 'Tick the days it is for.']);

    $r = $post('maintenance-add', ['site_id' => $shop, 'kind' => 'days', 'start' => '22:00', 'end' => '02:00', 'days' => ['sat', 'sun'], 'label' => 'Backups']);
    expect($r['status'])->toBe(200)->and($r['json']['when'])->toBe('weekends 22:00-02:00');
    $detail = http_request('GET', WEB . "/?api=site&id={$shop}", ['jar' => $jar])['json'];
    expect($detail['maintenance'][0])->toMatchArray(['when' => 'weekends 22:00-02:00', 'label' => 'Backups', 'for' => 'site'])
        ->and($detail['site']['server_ip'])->toBe('203.0.113.10')
        ->and($detail['site']['group_id'])->toBe(find_group($this->db, 'Customers')['id']);

    $group = find_group($this->db, 'Customers')['id'];
    expect($post('maintenance-add', ['group_id' => $group, 'kind' => 'daily', 'start' => '00:00', 'end' => '07:00'])['status'])->toBe(200)
        ->and(http_request('GET', WEB . "/?api=group&id={$group}", ['jar' => $jar])['json']['group']['maintenance'][0])->toMatchArray(['when' => 'every day 00:00-07:00']);

    $r = $post('maintenance-remove', ['id' => $detail['maintenance'][0]['id']]);
    expect($r['json'])->toBe(['removed' => 'weekends 22:00-02:00']);

    $server = http_request('GET', WEB . "/?api=server&site={$shop}", ['jar' => $jar]);
    expect($server['json']['server'])->toMatchArray(['ip' => '203.0.113.10', 'label' => '', 'sites' => ['shop.example']]);
    $r = $post('server-save', ['ip' => '203.0.113.10', 'label' => 'web-1']);
    expect($r['status'])->toBe(200)
        ->and(http_request('GET', WEB . '/?api=overview', ['jar' => $jar])['json']['groups'][0]['sites'][0]['server'])->toBe('web-1 (203.0.113.10)');

    $anna = ($this->login)('anna', 'anna password')['jar'];
    expect(http_request('POST', WEB . '/?api=server-save', ['jar' => $anna, 'json' => ['ip' => '203.0.113.10', 'label' => 'x'], 'headers' => ['X-CSRF-Token: ' . ($this->csrf)($anna)]])['status'])->toBe(403)
        ->and(http_request('POST', WEB . '/?api=maintenance-add', ['jar' => $anna, 'json' => ['site_id' => $shop, 'kind' => 'daily', 'start' => '00:00', 'end' => '01:00'], 'headers' => ['X-CSRF-Token: ' . ($this->csrf)($anna)]])['status'])->toBe(403);
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
    $token = create_share_token($this->db, null, $customers, 'AI assistant', utc_now($now + 86400), $now)['token'];

    $r = http_request('GET', WEB . '/?api&share=' . $token);
    expect($r['status'])->toBe(200)
        ->and($r['headers'])->not->toHaveKey('set-cookie')
        ->and($r['json'])->toMatchArray(['version' => VAKA_VERSION, 'scope' => ['type' => 'group', 'name' => 'Customers'], 'run_ok' => true])
        ->and($r['json'])->not->toHaveKey('setup')
        ->and($r['json']['totals'])->toMatchArray(['sites' => 1, 'down' => 1])
        ->and($r['json']['sites'])->toHaveCount(1);
    $site = $r['json']['sites'][0];
    expect(array_keys($site))->toBe(['url', 'label', 'name', 'group', 'status', 'summary', 'advice', 'since', 'checked_at', 'next_at', 'response_ms', 'server', 'cdn', 'snoozed_until', 'maintenance', 'unstable', 'blocked', 'uptime', 'ssl', 'domain', 'exposed', 'headers', 'dns'])
        ->and($site)->toMatchArray(['url' => 'https://shop.example', 'label' => 'shop.example', 'group' => 'Customers', 'status' => 'down', 'summary' => 'No answer within 10 s', 'since' => utc_now($now - 600), 'checked_at' => utc_now($now - 30), 'next_at' => utc_now($now + 30)])
        ->and(array_keys($site['uptime']))->toBe(['24h', '7d', '30d'])
        // Each check says when it ran and runs next, so a reader can tell whether it is current
        ->and(array_keys($site['ssl']))->toBe(['status', 'summary', 'days_left', 'valid_to', 'renewed_by', 'advice', 'checked_at', 'next_at'])
        ->and($site['ssl'])->toMatchArray(['status' => 'warn', 'days_left' => 6, 'valid_to' => '2026-10-31T12:00:00Z', 'checked_at' => utc_now($now), 'next_at' => utc_now($now + 3600)]);

    // The same with the token in a header, which keeps it out of URLs and logs
    $r = http_request('GET', WEB . '/?api', ['headers' => ['Authorization: Bearer ' . $token]]);
    expect($r['status'])->toBe(200)
        ->and(array_column($r['json']['sites'], 'label'))->toBe(['shop.example']);
});

test('an unknown or revoked share token is a 404, an expired one says when it expired, and a token reads nothing outside its scope', function () {
    $customers = add_group($this->db, ['name' => 'Customers']);
    add_site($this->db, ['url' => 'https://shop.example', 'group' => 'Customers']);
    add_site($this->db, ['url' => 'https://blog.example']);
    $now = time();
    $expired = create_share_token($this->db, null, $customers, '', '2026-01-26T23:00:00Z', strtotime('2025-10-28T12:00:00Z'))['token'];
    $revoked = create_share_token($this->db, null, $customers, '', utc_now($now + 86400), $now);
    revoke_share_token($this->db, $revoked['id']);
    $token = create_share_token($this->db, null, $customers, '', utc_now($now + 86400), $now)['token'];

    foreach (['vaka_' . str_repeat('0', 64), str_repeat('0', 64), $revoked['token'], 'nonsense'] as $bad) {
        $r = http_request('GET', WEB . '/?api&share=' . $bad);
        expect($r['status'])->toBe(404)
            ->and($r['json'])->toBe(['error' => 'No such read token: it may have been revoked']);
    }
    $r = http_request('GET', WEB . '/?api&share=' . $expired);
    expect($r['status'])->toBe(401)
        ->and($r['json']['error'])->toMatch('/^This read token expired on 2[67] Jan 2026\. Create a new one with `php vaka share:create`\.$/');

    expect(http_request('GET', WEB . '/?api&site=blog.example&share=' . $token)['status'])->toBe(404)
        ->and(http_request('GET', WEB . '/?api=overview&share=' . $token)['status'])->toBe(401)
        ->and(http_request('POST', WEB . '/?api&share=' . $token)['status'])->toBe(405)
        ->and(http_request('GET', WEB . '/?api')['status'])->toBe(401);
});

test('one site in detail: every check\'s history and response times, and CSV for spreadsheets; a site token needs no &site=', function () {
    $shop = add_site($this->db, ['url' => 'https://shop.example']);
    $now = time();
    add_event($this->db, $shop, 'uptime', ['status' => 'ok', 'previous' => 'pending', 'message' => 'First check: answered 200 OK in 210 ms'], utc_now($now - 7200));
    add_event($this->db, $shop, 'ssl', ['status' => 'warn', 'previous' => 'ok', 'message' => 'The certificate expires in 14 days'], utc_now($now - 3600));
    $this->db->exec("INSERT INTO samples (site_id, checked_at, status_code, ms, ok) VALUES ({$shop}, '" . utc_now($now - 600) . "', 200, 230, 1), ({$shop}, '" . utc_now($now - 300) . "', 503, 120, 0), ({$shop}, '" . utc_now($now - 2 * 86400) . "', 200, 250, 1)");
    save_check_state($this->db, $shop, 'uptime', ['status' => 'ok', 'summary' => 'Answered 200 OK in 230 ms', 'details' => ['ms' => 230], 'fail_count' => 0, 'changed_at' => utc_now($now - 7200)], utc_now($now - 60), utc_now($now + 240));
    $token = create_share_token($this->db, $shop, null, '', utc_now($now + 86400), $now)['token'];

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

    // A token for one site has nothing else to list, so it answers with its site in detail
    $own = http_request('GET', WEB . '/?api&share=' . $token)['json'];
    expect(array_diff_key($own, ['generated_at' => 1]))->toBe(array_diff_key($r['json'], ['generated_at' => 1]))
        ->and(http_request('GET', WEB . '/?api&format=csv&share=' . $token)['body'])->toBe($csv['body']);
});

test('&format=md is a report for an AI assistant: one site for a site token, every site for a group token', function () {
    $customers = add_group($this->db, ['name' => 'Customers']);
    $shop = add_site($this->db, ['url' => 'https://shop.example', 'group' => 'Customers']);
    add_site($this->db, ['url' => 'https://cafe.example', 'group' => 'Customers']);
    $now = time();
    save_check_state($this->db, $shop, 'uptime', ['status' => 'fail', 'summary' => 'The site answered 503 Service Unavailable', 'details' => ['problem' => 'http', 'status_code' => 503], 'fail_count' => 2, 'changed_at' => utc_now($now - 600)], utc_now($now - 60), utc_now($now + 60));
    $site = create_share_token($this->db, $shop, null, '', utc_now($now + 86400), $now)['token'];
    $group = create_share_token($this->db, null, $customers, '', utc_now($now + 86400), $now)['token'];

    $r = http_request('GET', WEB . '/?api&format=md', ['headers' => ['Authorization: Bearer ' . $site]]);
    expect($r['status'])->toBe(200)
        ->and($r['headers']['content-type'])->toBe('text/markdown; charset=utf-8')
        ->and($r['headers']['cache-control'])->toBe('no-store')
        ->and($r['body'])->toStartWith("# Vaka report for shop.example\n\n> For the AI assistant reading this:")
        ->and($r['body'])->toContain('## Needs attention (1)')
        ->and($r['body'])->toContain('`The site answered 503 Service Unavailable`');

    $r = http_request('GET', WEB . '/?api&format=md', ['headers' => ['Authorization: Bearer ' . $group]]);
    expect($r['body'])->toStartWith('# Vaka report for group Customers')
        ->and($r['body'])->toContain('| shop.example | down since')
        ->and($r['body'])->toContain('| cafe.example | not checked yet');
    expect(http_request('GET', WEB . '/?api&site=cafe.example&format=md', ['headers' => ['Authorization: Bearer ' . $group]])['body'])->toStartWith('# Vaka report for cafe.example');
});

test('"Check again" makes every check of a site due on the next run; it needs the CSRF token and a site the user may see', function () {
    $customers = add_group($this->db, ['name' => 'Customers']);
    $shop = add_site($this->db, ['url' => 'https://shop.example', 'group' => 'Customers']);
    $blog = add_site($this->db, ['url' => 'https://blog.example']);
    $paused = add_site($this->db, ['url' => 'https://old.example', 'group' => 'Customers']);
    $this->db->exec("UPDATE sites SET paused = 1 WHERE id = {$paused}");
    $now = time();
    foreach (['uptime' => 240, 'ssl' => 6 * 3600, 'headers' => 5 * 3600] as $check => $later) {
        save_check_state($this->db, $shop, $check, ['status' => 'ok', 'summary' => 'Fine', 'details' => [], 'fail_count' => 0, 'changed_at' => utc_now($now - 86400)], utc_now($now - 60), utc_now($now + $later));
    }
    save_check_state($this->db, $blog, 'ssl', ['status' => 'ok', 'summary' => 'Fine', 'details' => [], 'fail_count' => 0, 'changed_at' => utc_now($now)], utc_now($now - 60), utc_now($now + 3600));
    // Like a snooze: anyone who may see the site, restricted users too
    $jar = ($this->login)('anna', 'anna password')['jar'];
    $csrf = ($this->csrf)($jar);
    $recheck = fn (int $id, string $token) => http_request('POST', WEB . '/?api=site-recheck', ['jar' => $jar, 'headers' => ['X-CSRF-Token: ' . $token], 'json' => ['site_id' => $id]]);

    expect($recheck($shop, 'wrong')['status'])->toBe(403)
        ->and($recheck($blog, $csrf)['status'])->toBe(404)
        ->and($recheck($paused, $csrf)['json']['error'])->toBe('old.example is paused: resume it to check it');

    $r = $recheck($shop, $csrf);
    $next = $this->db->query("SELECT check_name, next_at FROM check_state WHERE site_id = {$shop} ORDER BY check_name")->fetchAll(PDO::FETCH_KEY_PAIR);
    expect($r['status'])->toBe(200)
        ->and($r['json']['site']['label'])->toBe('shop.example')
        ->and(array_map(fn ($at) => strtotime($at) <= time(), $next))->toBe(['headers' => true, 'ssl' => true, 'uptime' => true])
        ->and($this->db->query("SELECT next_at FROM check_state WHERE site_id = {$blog}")->fetchColumn())->toBe(utc_now($now + 3600));
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

test('the dashboard\'s files are versioned by their contents, so a deploy is never drawn by the last deploy\'s script', function () {
    $hash = asset_version();
    expect($hash)->toMatch('/^[a-f0-9]{10}$/')
        ->and(http_request('GET', WEB . '/?login')['body'])->toContain("app.css?v={$hash}")
        ->and(http_request('GET', WEB . '/?login')['body'])->not->toContain('?v=' . VAKA_VERSION);
    $dashboard = http_request('GET', WEB . '/', ['jar' => ($this->login)('admin', 'admin password')['jar']])['body'];
    expect($dashboard)->toContain("app.js?v={$hash}")
        ->and($dashboard)->toContain("app.css?v={$hash}")
        ->and($dashboard)->toContain("theme.js?v={$hash}")
        // The footer still says the version
        ->and($dashboard)->toContain('Vaka ' . VAKA_VERSION);
});
