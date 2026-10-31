<?php

/** Scripts and styles from our own files only: no inline script, no inline style. */
const CSP = "default-src 'none'; script-src 'self'; style-src 'self'; img-src 'self' data:; font-src 'self'; connect-src 'self'; form-action 'self'; frame-ancestors 'none'; base-uri 'none'";

/** /?health reports a stopped cron when the last run is older than this. */
const STALE_RUN_SECONDS = 300;

/** Escape text for HTML (content and attributes). */
function e(?string $text): string
{
    return htmlspecialchars((string) $text, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
}

function security_headers(array $server): array
{
    $headers = [
        'Content-Security-Policy' => CSP,
        'X-Content-Type-Options' => 'nosniff',
        'X-Frame-Options' => 'DENY',
        'Referrer-Policy' => 'no-referrer',
        'Permissions-Policy' => 'camera=(), microphone=(), geolocation=(), payment=()',
        'Cross-Origin-Opener-Policy' => 'same-origin',
    ];
    if (is_https($server)) $headers['Strict-Transport-Security'] = 'max-age=31536000';
    return $headers;
}

function respond(int $status, string $body, string $type, array $headers = []): never
{
    http_response_code($status);
    header("Content-Type: {$type}");
    foreach ($headers as $name => $value) header("{$name}: {$value}");
    echo $body;
    exit;
}

function respond_json(int $status, array $data): never
{
    respond($status, json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), 'application/json; charset=utf-8', ['Cache-Control' => 'no-store']);
}

function redirect(string $to): never
{
    respond(302, '', 'text/plain; charset=utf-8', ['Location' => $to, 'Cache-Control' => 'no-store']);
}

// =====================================================================
// Routing
// =====================================================================

function handle_request(array $config): never
{
    foreach (security_headers($_SERVER) as $name => $value) header("{$name}: {$value}");
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
    $now = time();

    if ($path !== '/' && $path !== '/index.php') respond(404, 'Not found', 'text/plain; charset=utf-8');
    if (isset($_GET['health'])) health($config, $now);
    // The read API with a token needs no session (and sets no cookie)
    $readApi = ($_GET['api'] ?? null) === '';
    if ($readApi && ($token = share_token_from_request($_GET, $_SERVER)) !== '') {
        $db = get_db($config['db_path']);
        $found = find_share_token($db, $token, $now);
        if (!$found) {
            // Whoever sends an expired token had it already, so saying so leaks nothing, and saves hunting for why a script stopped working
            $expired = share_token_expired_on($db, $token, $now);
            if ($expired !== null) respond_json(401, ['error' => 'This read token expired on ' . share_expires_words($expired, $config['timezone']) . '. Create a new one with `php vaka share:create`.']);
            respond_json(404, ['error' => 'No such read token: it may have been revoked']);
        }
        read_api($config, share_scope($found), $method, $now);
    }

    start_session($config);
    if (isset($_GET['login'])) {
        $method === 'POST' ? handle_login($config, $now) : show_login($config);
    }
    if (isset($_GET['logout'])) {
        if ($method !== 'POST') respond(405, 'Log out with the button on the dashboard.', 'text/plain; charset=utf-8', ['Allow' => 'POST']);
        handle_logout((string) ($_POST['_token'] ?? ''));
    }

    $user = current_user($config);
    if (isset($_GET['api'])) {
        if (!$user) respond_json(401, ['error' => $readApi ? 'Send a read token: ?api&share=TOKEN, or the header Authorization: Bearer TOKEN' : 'Log in first']);
        if ($readApi) read_api($config, ['type' => 'user', 'name' => $user['name'], 'where' => group_filter(user_group_ids(get_db($config['db_path']), $user))], $method, $now);
        handle_api($config, $user, (string) $_GET['api'], $method, $now);
    }
    if (!$user) redirect('/?login');
    show_dashboard($config, $user);
}

/** The logged-in user (name plus users.json entry), or null. A removed user is logged out. */
function current_user(array $config): ?array
{
    $name = $_SESSION['user'] ?? null;
    if ($name === null) return null;
    $user = load_users($config['users_file'])[$name] ?? null;
    return $user === null ? null : ['name' => $name] + $user;
}

/** 200 while cron runs, 503 when the last run is too old or there never was one: for an external monitor to watch Vaka. */
function health(array $config, int $now): never
{
    try {
        $last = get_meta(get_db($config['db_path']), 'last_run_at');
    } catch (Throwable) {
        respond_json(503, ['status' => 'error', 'last_run_at' => null, 'seconds_since_last_run' => null]);
    }
    if ($last === null) respond_json(503, ['status' => 'never_run', 'last_run_at' => null, 'seconds_since_last_run' => null]);
    $age = $now - strtotime($last);
    $ok = $age <= STALE_RUN_SECONDS;
    respond_json($ok ? 200 : 503, ['status' => $ok ? 'ok' : 'stale', 'last_run_at' => $last, 'seconds_since_last_run' => $age]);
}

// =====================================================================
// Login
// =====================================================================

function handle_login(array $config, int $now): never
{
    $db = get_db($config['db_path']);
    $ip = client_ip($_SERVER, $config['trusted_proxies']);

    if (!check_csrf((string) ($_POST['_token'] ?? ''))) show_login($config, 'The form expired. Try again.');
    if (is_locked_out($db, $ip, $config['max_login_attempts'], $config['lockout_minutes'], $now)) {
        show_login($config, "Too many failed attempts. Wait {$config['lockout_minutes']} minutes and try again.");
    }

    $users = load_users($config['users_file']);
    // Before the first user exists there is nothing to log in to: no failed attempt to count
    if (!$users) show_login($config);
    $name = strtolower(trim((string) ($_POST['username'] ?? '')));
    $user = $users[$name] ?? null;
    // Verify against a dummy hash for unknown users too, so timing does not tell which names exist
    $hash = $user['password'] ?? '$2y$12$FKKe7nUCFf6k3/.WVUVIRuEPI.d6R0F75pyM5t4qCqSCqQ6kIkfFi';
    if (password_verify((string) ($_POST['password'] ?? ''), $hash) && $user !== null) {
        session_regenerate_id(true);
        $_SESSION = ['user' => $name, 'rotated_at' => time(), 'csrf' => bin2hex(random_bytes(32))];
        audit_log($db, $name, 'login_success', $ip, $now);
        redirect('/');
    }
    audit_log($db, $name, 'login_failed', $ip, $now);
    show_login($config, 'Wrong user name or password.');
}

function handle_logout(string $token): never
{
    if (!check_csrf($token)) redirect('/');
    $_SESSION = [];
    $cookie = session_get_cookie_params();
    setcookie(session_name(), '', ['expires' => 1] + array_intersect_key($cookie, array_flip(['path', 'domain', 'secure', 'httponly', 'samesite'])));
    session_destroy();
    redirect('/?login');
}

function show_login(array $config, ?string $error = null): never
{
    $token = e(csrf_token());
    $version = e(VAKA_VERSION);
    $message = $error === null ? '' : '<p class="login-error" id="login-error" role="alert">' . e($error) . '</p>';
    $described = $error === null ? '' : ' aria-describedby="login-error"';
    $mark = mark_svg('vaka', 'mark');
    $credit = footer_credit($config);
    $panel = load_users($config['users_file']) ? <<<HTML
      <form method="post" action="/?login">
        {$message}
        <input type="hidden" name="_token" value="{$token}">
        <label for="username">User name</label>
        <input id="username" name="username" autocomplete="username" autocapitalize="none" spellcheck="false" required autofocus{$described}>
        <label for="password">Password</label>
        <input id="password" name="password" type="password" autocomplete="current-password" required{$described}>
        <button type="submit">Log in</button>
      </form>
    HTML : <<<HTML
      <div class="login-steps">
        <h2>No users yet</h2>
        <p>Create the first one on the server where Vaka runs:</p>
        <code>php vaka user:add your-name</code>
        <p>No SSH there? Run it on your own computer instead, and upload the <code>users.json</code> it creates next to the <code>vaka</code> file.</p>
        <p>Then reload this page and log in.</p>
      </div>
    HTML;

    respond(200, <<<HTML
    <!doctype html>
    <html lang="en">
    <head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#0f172a">
    <title>Log in to Vaka</title>
    <link rel="icon" href="favicon.svg" type="image/svg+xml">
    <link rel="preload" href="fonts/figtree-latin.woff2" as="font" type="font/woff2" crossorigin>
    <link rel="stylesheet" href="app.css?v={$version}">
    </head>
    <body class="login">
    <main class="login-card">
      {$mark}
      <h1>Vaka</h1>
      <p class="login-tagline">Keeps watch while you sleep.</p>
    {$panel}
      <p class="login-foot">{$credit}</p>
    </main>
    </body>
    </html>
    HTML, 'text/html; charset=utf-8', ['Cache-Control' => 'no-store']);
}

/**
 * A tool's mark from public/marks/ (copied from the Webready design kit),
 * inline, so the CSP needs nothing. With a $label it is an image with that
 * name; without, decoration beside a visible name.
 */
function mark_svg(string $tool, string $class, string $label = ''): string
{
    $svg = trim((string) file_get_contents(__DIR__ . "/../public/marks/{$tool}.svg"));
    $role = $label === '' ? 'aria-hidden="true"' : 'role="img" aria-label="' . e($label) . '"';
    return preg_replace('/^<svg /', '<svg class="' . e($class) . '" ' . $role . ' focusable="false" ', $svg);
}

/**
 * The family credit (design kit docs/layout.md): "Made by Webready in Sweden"
 * and the marks of the tools that are released and public, each a link to
 * its page (never a mark that leads nowhere). A tool joins when it is
 * released, Vaka too. FOOTER_CREDIT=false turns it off.
 */
function footer_credit(array $config): string
{
    if (!$config['footer_credit']) return '';
    $marks = '';
    foreach (['puls' => 'https://github.com/webready-se/puls'] as $tool => $page) {
        $marks .= '<a href="' . $page . '">' . mark_svg($tool, 'credit-mark', ucfirst($tool)) . '</a>';
    }
    return '<span class="credit">Made by <a href="https://webready.se">Webready</a> in Sweden <span class="credit-marks">' . $marks . '</span></span>';
}

function show_dashboard(array $config, array $user): never
{
    $html = (string) file_get_contents(__DIR__ . '/../public/dashboard.html');
    $html = strtr($html, [
        '{{csrf}}' => e(csrf_token()),
        '{{version}}' => e(VAKA_VERSION),
        '{{user}}' => e($user['name']),
        '{{mark}}' => mark_svg('vaka', 'mark'),
        '{{credit}}' => footer_credit($config),
    ]);
    respond(200, $html, 'text/html; charset=utf-8', ['Cache-Control' => 'no-store']);
}

// =====================================================================
// JSON API for the dashboard
// =====================================================================

function handle_api(array $config, array $user, string $endpoint, string $method, int $now): never
{
    $db = get_db($config['db_path']);
    $allowed = user_group_ids($db, $user);

    if ($endpoint === 'overview' && $method === 'GET') respond_json(200, api_overview($db, $config, $allowed, $now));
    if ($endpoint === 'site' && $method === 'GET') {
        $site = visible_site($db, (int) ($_GET['id'] ?? 0), $allowed);
        if (!$site) respond_json(404, ['error' => 'No such site']);
        respond_json(200, api_site($db, $config, $site, $now));
    }
    if ($endpoint === 'snooze' && $method === 'POST') {
        if (!check_csrf((string) ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''))) respond_json(403, ['error' => 'The page expired. Reload it and try again.']);
        $input = json_decode((string) file_get_contents('php://input'), true) ?: [];
        $site = visible_site($db, (int) ($input['site_id'] ?? 0), $allowed);
        if (!$site) respond_json(404, ['error' => 'No such site']);

        if (($input['action'] ?? '') === 'unsnooze') {
            unsnooze_site($db, $site['id'], $now);
        } else {
            try {
                $seconds = parse_snooze((string) ($input['for'] ?? ''));
            } catch (InvalidArgumentException $e) {
                respond_json(422, ['error' => $e->getMessage()]);
            }
            if ($site['status'] !== 'fail' && !$site['fail_count']) respond_json(409, ['error' => display_url($site['url']) . ' is up: there is nothing to snooze']);
            snooze_site($db, $site['id'], $seconds, $now);
        }
        $row = overview_rows($db, 's.id = ' . $site['id'])[0];
        respond_json(200, ['site' => site_summary($db, $config, $row, server_labels($db), load_windows($db, $now), [], $now)]);
    }
    if ($endpoint === 'site-recheck' && $method === 'POST') {
        if (!check_csrf((string) ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''))) respond_json(403, ['error' => 'The page expired. Reload it and try again.']);
        $input = json_decode((string) file_get_contents('php://input'), true) ?: [];
        $site = visible_site($db, (int) ($input['site_id'] ?? 0), $allowed);
        if (!$site) respond_json(404, ['error' => 'No such site']);
        if ($site['paused']) respond_json(409, ['error' => display_url($site['url']) . ' is paused: resume it to check it']);
        queue_recheck($db, (int) $site['id'], $now);
        respond_json(200, ['site' => site_summary($db, $config, visible_site($db, (int) $site['id'], $allowed), server_labels($db), load_windows($db, $now), [], $now)]);
    }
    if ($endpoint === 'group' && $method === 'GET') {
        if ($allowed !== null) respond_json(403, ['error' => 'Only users who see every site can change groups']);
        $group = get_group($db, (int) ($_GET['id'] ?? 0));
        if (!$group) respond_json(404, ['error' => 'No such group']);
        respond_json(200, ['group' => group_form_values($db, $group, $config)]);
    }
    if (in_array($endpoint, ['group-save', 'group-remove', 'alert-test'], true) && $method === 'POST') {
        if (!check_csrf((string) ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''))) respond_json(403, ['error' => 'The page expired. Reload it and try again.']);
        if ($allowed !== null) respond_json(403, ['error' => 'Only users who see every site can change groups']);
        $input = json_decode((string) file_get_contents('php://input'), true) ?: [];
        $id = isset($input['id']) ? (int) $input['id'] : (isset($input['group']) ? (int) $input['group'] : null);
        $group = $id === null ? null : get_group($db, $id);
        if ($id !== null && !$group) respond_json(404, ['error' => 'No such group']);

        if ($endpoint === 'alert-test') {
            $sent = send_test_alert($config, $group);
            if (!$sent) respond_json(422, ['error' => 'There is nowhere to send alerts yet: give the group a Slack webhook, a JSON webhook or an email address, or set defaults in .env']);
            respond_json(200, ['sent' => $sent]);
        }
        if ($endpoint === 'group-remove') {
            if (!$group) respond_json(404, ['error' => 'No such group']);
            try {
                $removed = remove_group_form($db, $config, $id, $user['name'], client_ip($_SERVER, $config['trusted_proxies']), $now);
            } catch (InvalidArgumentException $e) {
                respond_json(409, ['error' => $e->getMessage() . '.']);
            }
            respond_json(200, ['removed' => $removed['name'], 'sites' => $removed['sites']]);
        }
        $saved = save_group_form($db, $config, $input, $id, $user['name'], $now);
        if ($saved['errors']) respond_json(422, ['error' => reset($saved['errors']), 'errors' => $saved['errors']]);
        respond_json(200, ['group' => group_form_values($db, get_group($db, (int) $saved['id']), $config), 'changes' => $saved['changes']]);
    }
    if ($endpoint === 'server' && $method === 'GET') {
        if ($allowed !== null) respond_json(403, ['error' => 'Only users who see every site can name servers']);
        $site = visible_site($db, (int) ($_GET['site'] ?? 0), $allowed);
        $server = $site ? server_form_values($db, $site) : null;
        if (!$server) respond_json(404, ['error' => 'This site is not on a server Vaka can name: it is behind a CDN, has a server set by hand, or has not been checked yet']);
        respond_json(200, ['server' => $server]);
    }
    if (in_array($endpoint, ['server-save', 'maintenance-add', 'maintenance-remove'], true) && $method === 'POST') {
        if (!check_csrf((string) ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''))) respond_json(403, ['error' => 'The page expired. Reload it and try again.']);
        if ($allowed !== null) respond_json(403, ['error' => 'Only users who see every site can change this']);
        $input = json_decode((string) file_get_contents('php://input'), true) ?: [];

        if ($endpoint === 'server-save') {
            $saved = save_server_form($db, $input, $user['name'], $now);
            if ($saved['errors']) respond_json(422, ['error' => reset($saved['errors']), 'errors' => $saved['errors']]);
            respond_json(200, ['moved' => $saved['moved']]);
        }
        if ($endpoint === 'maintenance-remove') {
            $removed = remove_maintenance_form($db, $config, (int) ($input['id'] ?? 0), $user['name'], $now);
            if ($removed === null) respond_json(404, ['error' => 'No such maintenance window']);
            respond_json(200, ['removed' => $removed]);
        }
        $siteId = isset($input['site_id']) ? (int) $input['site_id'] : null;
        $groupId = isset($input['group_id']) ? (int) $input['group_id'] : null;
        if (($siteId === null) === ($groupId === null) || ($siteId !== null && !visible_site($db, $siteId, $allowed)) || ($groupId !== null && !get_group($db, $groupId))) {
            respond_json(404, ['error' => 'No such site or group']);
        }
        try {
            respond_json(200, add_maintenance_form($db, $config, $input, $siteId, $groupId, $user['name'], $now));
        } catch (InvalidArgumentException $e) {
            $field = str_starts_with($e->getMessage(), 'Label') ? 'label' : 'when';
            respond_json(422, ['error' => $e->getMessage() . '.', 'errors' => [$field => $e->getMessage() . '.']]);
        }
    }
    if (($endpoint === 'site-save' || $endpoint === 'site-remove') && $method === 'POST') {
        if (!check_csrf((string) ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''))) respond_json(403, ['error' => 'The page expired. Reload it and try again.']);
        if ($allowed !== null) respond_json(403, ['error' => 'Only users who see every site can change sites']);
        $input = json_decode((string) file_get_contents('php://input'), true) ?: [];
        $id = isset($input['id']) ? (int) $input['id'] : null;
        if (($id !== null || $endpoint === 'site-remove') && !visible_site($db, (int) $id, $allowed)) respond_json(404, ['error' => 'No such site']);

        if ($endpoint === 'site-remove') {
            respond_json(200, ['removed' => remove_site_form($db, (int) $id, $user['name'], client_ip($_SERVER, $config['trusted_proxies']), $now)]);
        }
        $saved = save_site_form($db, $config, $input, $id, $user['name'], $now);
        if ($saved['errors']) respond_json(422, ['error' => reset($saved['errors']), 'errors' => $saved['errors'], 'unresolved' => $saved['unresolved'] ?? false]);
        $row = overview_rows($db, 's.id = ' . (int) $saved['id'])[0];
        respond_json(200, ['site' => site_summary($db, $config, $row, server_labels($db), load_windows($db, $now), [], $now), 'changes' => $saved['changes']]);
    }
    respond_json(404, ['error' => 'No such API call']);
}

/** A site with its uptime state, if the user may see it (null when not, so its existence does not leak). */
function visible_site(PDO $db, int $id, ?array $allowed): ?array
{
    $rows = overview_rows($db, 's.id = ' . $id . ' AND ' . group_filter($allowed));
    return $rows[0] ?? null;
}

/** SQL condition for the sites a user may see. */
function group_filter(?array $allowed): string
{
    if ($allowed === null) return '1 = 1';
    if (!$allowed) return '0 = 1';
    return 's.group_id IN (' . implode(', ', array_map('intval', $allowed)) . ')';
}

function overview_rows(PDO $db, string $where): array
{
    $rows = $db->query("SELECT s.*, g.name AS group_name, g.policy, g.delay_minutes,
            c.status, c.summary, c.details, c.fail_count, c.checked_at, c.next_at AS uptime_next_at, c.changed_at, c.snoozed_until, c.flapping_since,
            cs.status AS ssl_status, cs.summary AS ssl_summary, cs.details AS ssl_details, cs.checked_at AS ssl_checked_at, cs.next_at AS ssl_next_at,
            ce.status AS exposed_status, ce.summary AS exposed_summary, ce.details AS exposed_details, ce.changed_at AS exposed_changed_at, ce.checked_at AS exposed_checked_at, ce.next_at AS exposed_next_at,
            ch.status AS headers_status, ch.summary AS headers_summary, ch.details AS headers_details, ch.checked_at AS headers_checked_at, ch.next_at AS headers_next_at,
            cd.status AS domain_status, cd.summary AS domain_summary, cd.details AS domain_details, cd.checked_at AS domain_checked_at, cd.next_at AS domain_next_at,
            cm.status AS dns_status, cm.summary AS dns_summary, cm.details AS dns_details, cm.checked_at AS dns_checked_at, cm.next_at AS dns_next_at
        FROM sites s
        LEFT JOIN groups g ON g.id = s.group_id
        LEFT JOIN check_state c ON c.site_id = s.id AND c.check_name = 'uptime'
        LEFT JOIN check_state cs ON cs.site_id = s.id AND cs.check_name = 'ssl'
        LEFT JOIN check_state ce ON ce.site_id = s.id AND ce.check_name = 'exposed'
        LEFT JOIN check_state ch ON ch.site_id = s.id AND ch.check_name = 'headers'
        LEFT JOIN check_state cd ON cd.site_id = s.id AND cd.check_name = 'domain'
        LEFT JOIN check_state cm ON cm.site_id = s.id AND cm.check_name = 'dns'
        WHERE {$where}
        ORDER BY s.group_id IS NULL, g.name COLLATE NOCASE")->fetchAll();
    return sort_sites($rows);
}

/** One site as the dashboard shows it. */
function site_summary(PDO $db, array $config, array $row, array $labels, array $windows, array $samples, int $now): array
{
    $details = json_decode((string) ($row['details'] ?? '{}'), true) ?: [];
    $status = match (true) {
        (bool) $row['paused'] => 'paused',
        $row['status'] === null || ($row['status'] === 'pending' && !$row['fail_count']) => 'pending',
        $row['status'] === 'fail' => 'down',
        $row['fail_count'] > 0 => 'failing',
        default => 'up',
    };
    $since = match ($status) {
        'down', 'up' => $row['changed_at'],
        'failing' => $details['failing_since'] ?? null,
        default => null,
    };
    $quiet = active_silence(['id' => $row['id'], 'group_id' => $row['group_id'], 'snoozed_until' => $row['snoozed_until']], $windows, $now, $config['timezone']);
    // When each check last ran and runs next, so a reader can tell whether a result is current
    $when = fn (string $check) => ['checked_at' => $row["{$check}_checked_at"], 'next_at' => $row["{$check}_next_at"]];
    $ssl = null;
    if (in_array($row['ssl_status'], ['ok', 'warn', 'fail'], true)) {
        $sslDetails = json_decode((string) $row['ssl_details'], true) ?: [];
        $ssl = [
            'status' => $row['ssl_status'],
            'summary' => $row['ssl_summary'],
            'days_left' => $sslDetails['days_left'] ?? null,
            'valid_to' => $sslDetails['valid_to'] ?? null,
            'renewed_by' => $sslDetails['renewed_by'] ?? null,
            'advice' => $row['ssl_status'] === 'ok' ? '' : ssl_advice($sslDetails),
        ] + $when('ssl');
    }
    $exposed = null;
    if (in_array($row['exposed_status'], ['ok', 'warn', 'fail'], true)) {
        $exposedDetails = json_decode((string) $row['exposed_details'], true) ?: [];
        $exposed = [
            'status' => $row['exposed_status'],
            'summary' => $row['exposed_summary'],
            'found' => $exposedDetails['found'] ?? [],
            'since' => $row['exposed_changed_at'],
            'advice' => advice_for($exposedDetails['problem'] ?? '', 0),
        ] + $when('exposed');
    }
    $domain = null;
    if (in_array($row['domain_status'], ['ok', 'warn', 'fail', 'unknown'], true)) {
        $domainDetails = json_decode((string) $row['domain_details'], true) ?: [];
        $domain = [
            'status' => $row['domain_status'],
            'summary' => $row['domain_summary'],
            'domain' => $domainDetails['domain'] ?? null,
            'label' => isset($domainDetails['domain']) ? display_domain($domainDetails['domain']) : null,
            'days_left' => $domainDetails['days_left'] ?? null,
            'expires' => $domainDetails['expires'] ?? null,
            'registrar' => $domainDetails['registrar'] ?? '',
            'advice' => in_array($row['domain_status'], ['warn', 'fail'], true) ? advice_for($domainDetails['problem'] ?? '', 0) : '',
        ] + $when('domain');
    }
    // The grade only: what each header does comes with the site detail (see api_site())
    $headers = null;
    if (in_array($row['headers_status'], ['ok', 'warn', 'unknown'], true)) {
        $headers = ['status' => $row['headers_status'], 'grade' => (json_decode((string) $row['headers_details'], true) ?: [])['grade'] ?? null, 'summary' => $row['headers_summary']] + $when('headers');
    }
    // Mail records in words; what each record is comes with the site detail (see api_site())
    $dns = null;
    if (in_array($row['dns_status'], ['ok', 'warn', 'unknown'], true)) {
        $dnsDetails = json_decode((string) $row['dns_details'], true) ?: [];
        $dns = [
            'status' => $row['dns_status'],
            'summary' => $row['dns_summary'],
            'domain' => $dnsDetails['domain'] ?? null,
            'advice' => $row['dns_status'] === 'warn' ? advice_for($dnsDetails['problem'] ?? '', 0) : '',
        ] + $when('dns');
    }
    $serverKey = server_key($row);
    // A bot check answers instead of the site: Vaka cannot see it, and says where to let it through
    $blocked = isset($details['blocked']) && !$row['paused']
        ? ['by' => $details['blocked'], 'since' => $details['blocked_since'] ?? null, 'from_ip' => ($details['from_ip'] ?? '') ?: null]
        : null;

    return [
        'id' => $row['id'],
        'url' => $row['url'],
        'label' => display_url($row['url']),
        'name' => $row['name'],
        'group' => $row['group_name'],
        'status' => $status,
        'summary' => (string) ($row['summary'] ?? ''),
        'advice' => match (true) {
            in_array($status, ['down', 'failing'], true) => advice_for($details['problem'] ?? '', (int) ($details['status_code'] ?? 0)),
            $blocked !== null => advice_for('blocked', 0),
            default => '',
        },
        'since' => $since,
        'checked_at' => $row['checked_at'],
        'next_at' => $status === 'paused' ? null : $row['uptime_next_at'],
        // Behind a bot check the last time measured is not the site's time now
        'ms' => $status === 'up' && $blocked === null ? ($details['ms'] ?? null) : null,
        'server' => $serverKey === null ? null : server_display($row, $labels),
        'cdn' => $row['cdn'] !== '' ? $row['cdn'] : null,
        'snoozed_until' => $quiet === 'snooze' ? $row['snoozed_until'] : null,
        'maintenance' => $quiet === 'maintenance',
        'unstable' => $row['flapping_since'] !== null,
        'blocked' => $blocked,
        'ssl' => $ssl,
        'domain' => $domain,
        'exposed' => $exposed,
        'headers' => $headers,
        'dns' => $dns,
        'uptime_24h' => site_uptime($db, $row, 86400, $now, $config['timezone']),
        'uptime_30d' => site_uptime($db, $row, 30 * 86400, $now, $config['timezone']),
        'days' => daily_uptime($db, $row, $now, 30, $config['timezone']),
        'hours' => hourly_buckets($samples, $now, 24),
    ];
}

function api_overview(PDO $db, array $config, ?array $allowed, int $now): array
{
    $rows = overview_rows($db, group_filter($allowed));
    $samples = [];
    if ($rows) {
        $stmt = $db->prepare('SELECT site_id, checked_at, ms, ok FROM samples WHERE checked_at >= ? AND site_id IN (' . implode(', ', array_map('intval', array_column($rows, 'id'))) . ')');
        $stmt->execute([utc_now($now - 86400)]);
        foreach ($stmt->fetchAll() as $sample) $samples[$sample['site_id']][] = $sample;
    }
    $labels = server_labels($db);
    $windows = load_windows($db, $now);

    $totals = ['sites' => 0, 'up' => 0, 'down' => 0, 'failing' => 0, 'paused' => 0, 'pending' => 0];
    $groups = [];
    // Users who see everything also see empty groups, to fill them
    if ($allowed === null) {
        foreach (list_groups($db) as $group) {
            $groups[$group['id']] = ['id' => $group['id'], 'name' => $group['name'], 'alerting' => describe_policy($group, $config['digest_time']), 'sites' => []];
        }
    }
    foreach ($rows as $row) {
        $site = site_summary($db, $config, $row, $labels, $windows, $samples[$row['id']] ?? [], $now);
        $totals['sites']++;
        $totals[$site['status']]++;
        $key = $row['group_id'] ?? 'none';
        $groups[$key] ??= [
            'id' => $row['group_id'],
            'name' => $row['group_name'],
            'alerting' => $row['group_id'] === null ? 'right away' : describe_policy(['policy' => $row['policy'], 'delay_minutes' => $row['delay_minutes']], $config['digest_time']),
            'sites' => [],
        ];
        $groups[$key]['sites'][] = $site;
    }

    // The latest outage among the visible sites, for "All quiet. Last outage: ..."
    $lastIncident = null;
    if ($rows) {
        $ids = implode(', ', array_map('intval', array_column($rows, 'id')));
        $last = $db->query("SELECT e.site_id, e.message, e.created_at FROM events e WHERE e.site_id IN ({$ids}) AND e.check_name = 'uptime' AND e.status = 'fail' ORDER BY e.created_at DESC, e.id DESC LIMIT 1")->fetch();
        if ($last) {
            $site = array_values(array_filter($rows, fn ($row) => $row['id'] === $last['site_id']))[0];
            $lastIncident = ['site_id' => $last['site_id'], 'label' => display_url($site['url']), 'message' => $last['message'], 'at' => $last['created_at']];
        }
    }

    $lastRun = get_meta($db, 'last_run_at');
    return [
        // The first steps, for users who see everything (only they can run the CLI)
        'setup' => $allowed === null ? [
            'php' => php_cli(),
            'dir' => run_dir(dirname(__DIR__)),
            'cron' => cron_line(dirname(__DIR__), php_cli()),
            'alerts' => alerts_configured($db, $config),
            // For adding and changing sites in the dashboard
            'groups' => array_column(list_groups($db), 'name'),
            'checks' => array_map(fn ($key, $label) => ['key' => $key, 'label' => $label], array_keys(CHECK_LABELS), CHECK_LABELS),
            'interval' => $config['uptime_interval'],
            'digest_time' => $config['digest_time'],
            // A setting Vaka could not use (TIMEZONE, TIME_FORMAT), in words
            'problems' => $config['config_problems'] ?? [],
        ] : null,
        'last_incident' => $lastIncident,
        'generated_at' => utc_now($now),
        'timezone' => $config['timezone'],
        'time_format' => $config['time_format'] ?? '24h',
        'last_run_at' => $lastRun,
        'run_ok' => $lastRun !== null && $now - strtotime($lastRun) <= STALE_RUN_SECONDS,
        'totals' => $totals,
        'groups' => array_values($groups),
    ];
}

function api_site(PDO $db, array $config, array $row, int $now): array
{
    $samples = $db->prepare('SELECT checked_at, status_code, ms, ok FROM samples WHERE site_id = ? AND checked_at >= ? ORDER BY checked_at');
    $samples->execute([$row['id'], utc_now($now - 86400)]);
    $samples = $samples->fetchAll();
    // Uptime and the site's own changes; the other checks show in their own sections
    $events = $db->prepare("SELECT status, previous, message, silenced_by, created_at FROM events WHERE site_id = ? AND check_name IN ('uptime', 'settings') ORDER BY created_at DESC, id DESC LIMIT 100");
    $events->execute([$row['id']]);

    $windows = load_windows($db, $now);
    $site = site_summary($db, $config, $row, server_labels($db), $windows, $samples, $now);
    if ($site['headers'] !== null) $site['headers'] += ['items' => header_items(json_decode((string) $row['headers_details'], true) ?: []), 'tip' => HEADERS_NGINX_TIP];
    if ($site['dns'] !== null) $site['dns']['items'] = dns_items(json_decode((string) $row['dns_details'], true) ?: [], $now, $config['timezone']);

    return [
        'site' => $site + [
            'keyword' => $row['keyword'],
            'interval_minutes' => site_interval($row, $config),
            // As the site's settings have it, for the edit form: null is the default interval
            'interval_custom' => $row['interval_minutes'],
            'checks_off' => array_values(array_filter(explode(',', $row['disabled_checks']))),
            // The server's IP when it can be named (not behind a CDN, not set by hand)
            'server_ip' => str_starts_with((string) server_key($row), 'ip:') ? $row['ip'] : null,
            // For a maintenance window for the site's whole group
            'group_id' => $row['group_id'],
            'created_at' => $row['created_at'],
        ],
        'days' => daily_uptime($db, $row, $now, 30, $config['timezone']),
        'uptime' => [
            '24h' => site_uptime($db, $row, 86400, $now, $config['timezone']),
            '7d' => site_uptime($db, $row, 7 * 86400, $now, $config['timezone']),
            '30d' => site_uptime($db, $row, 30 * 86400, $now, $config['timezone']),
        ],
        'samples' => $samples,
        'events' => $events->fetchAll(),
        'maintenance' => site_maintenance($windows, $row, $config['timezone'], $now, $config['time_format']),
    ];
}

/** The maintenance windows that cover a site (its own and its group's), in words. */
function site_maintenance(array $windows, array $row, string $timezone, int $now, string $timeFormat = '24h'): array
{
    $mine = array_filter($windows, fn ($w) => $w['site_id'] === $row['id'] || ($w['group_id'] !== null && $w['group_id'] === $row['group_id']));
    return array_values(array_map(fn ($w) => [
        'id' => $w['id'],
        'for' => $w['group_id'] !== null ? 'group' : 'site',
        'when' => describe_window($w, $timezone, $timeFormat),
        'label' => $w['label'],
        'active' => window_active($w, $now, $timezone),
    ], $mine));
}

// =====================================================================
// The read API (docs/api.md): stable field names for other tools
// =====================================================================

/** A read token from the Authorization header (kept out of URLs and logs), or from ?share=. */
function share_token_from_request(array $get, array $server): string
{
    $header = (string) ($server['HTTP_AUTHORIZATION'] ?? $server['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
    if (preg_match('/^Bearer\s+(\S+)$/i', trim($header), $m)) return $m[1];
    return is_string($get['share'] ?? null) ? $get['share'] : '';
}

/**
 * Answer the read API for the sites in $scope (see share_scope()): every
 * site, or one in detail with ?site= (a site token's own without it), as
 * JSON, CSV with &format=csv, or Markdown for an AI assistant with &format=md.
 */
function read_api(array $config, array $scope, string $method, int $now): never
{
    if ($method !== 'GET') respond_json(405, ['error' => 'The read API only answers GET']);
    $db = get_db($config['db_path']);
    $format = $_GET['format'] ?? '';
    $csv = $format === 'csv';
    $lastRun = get_meta($db, 'last_run_at');
    $about = [
        'version' => VAKA_VERSION,
        'generated_at' => utc_now($now),
        'timezone' => $config['timezone'],
        'scope' => ['type' => $scope['type'], 'name' => $scope['name']],
        'last_run_at' => $lastRun,
        'run_ok' => $lastRun !== null && $now - strtotime($lastRun) <= STALE_RUN_SECONDS,
    ];

    // A token for one site has nothing else to list, so it answers with its site in detail
    $wanted = is_string($_GET['site'] ?? null) ? $_GET['site'] : null;
    if ($wanted !== null || isset($scope['site_id'])) {
        $siteId = $wanted !== null ? (find_site($db, $wanted)['id'] ?? null) : $scope['site_id'];
        $row = $siteId !== null ? (overview_rows($db, 's.id = ' . (int) $siteId . ' AND ' . $scope['where'])[0] ?? null) : null;
        if (!$row) respond_json(404, ['error' => 'No such site']);
        $detail = read_api_site($db, $config, $row, max(1, min(720, (int) ($_GET['hours'] ?? 24))), $now);
        if ($csv) respond_csv(['checked_at', 'status_code', 'ms', 'ok'], array_map(fn ($s) => [$s['checked_at'], $s['status_code'], $s['ms'], (int) $s['ok']], $detail['samples']));
        if ($format === 'md') respond_markdown(read_api_markdown_site($about, $detail, $config));
        respond_json(200, $about + $detail);
    }

    $labels = server_labels($db);
    $windows = load_windows($db, $now);
    $totals = ['sites' => 0, 'up' => 0, 'down' => 0, 'failing' => 0, 'paused' => 0, 'pending' => 0];
    $sites = [];
    foreach (overview_rows($db, $scope['where']) as $row) {
        $site = api_site_fields($db, $config, site_summary($db, $config, $row, $labels, $windows, [], $now), $row, $now);
        $totals['sites']++;
        $totals[$site['status']]++;
        $sites[] = $site;
    }
    if ($csv) {
        respond_csv(
            ['url', 'group', 'status', 'since', 'response_ms', 'uptime_24h', 'uptime_7d', 'uptime_30d', 'ssl_days_left', 'domain_days_left', 'headers_grade', 'exposed', 'mail_dns'],
            array_map(fn ($s) => [
                $s['url'], $s['group'], $s['status'], $s['since'], $s['response_ms'], $s['uptime']['24h'], $s['uptime']['7d'], $s['uptime']['30d'],
                $s['ssl']['days_left'] ?? null, $s['domain']['days_left'] ?? null, $s['headers']['grade'] ?? null, $s['exposed']['status'] ?? null, $s['dns']['status'] ?? null,
            ], $sites),
        );
    }
    if ($format === 'md') respond_markdown(read_api_markdown_list($about, $totals, $sites, $config));
    respond_json(200, $about + ['totals' => $totals, 'sites' => $sites]);
}

/** A report for an AI assistant (src/report.php), never cached. */
function respond_markdown(string $markdown): never
{
    respond(200, $markdown, 'text/markdown; charset=utf-8', ['Cache-Control' => 'no-store']);
}

/** A site in the read API's field names, which stay the same between versions (docs/api.md). */
function api_site_fields(PDO $db, array $config, array $summary, array $row, int $now): array
{
    return [
        'url' => $summary['url'],
        'label' => $summary['label'],
        'name' => $summary['name'],
        'group' => $summary['group'],
        'status' => $summary['status'],
        'summary' => $summary['summary'],
        'advice' => $summary['advice'],
        'since' => $summary['since'],
        'checked_at' => $summary['checked_at'],
        'next_at' => $summary['next_at'],
        'response_ms' => $summary['ms'],
        'server' => $summary['server'],
        'cdn' => $summary['cdn'],
        'snoozed_until' => $summary['snoozed_until'],
        'maintenance' => $summary['maintenance'],
        'unstable' => $summary['unstable'],
        'blocked' => $summary['blocked'],
        'uptime' => ['24h' => $summary['uptime_24h'], '7d' => site_uptime($db, $row, 7 * 86400, $now, $config['timezone']), '30d' => $summary['uptime_30d']],
        'ssl' => api_pick($summary['ssl'], ['status', 'summary', 'days_left', 'valid_to', 'renewed_by', 'advice', 'checked_at', 'next_at']),
        'domain' => api_pick($summary['domain'], ['status', 'summary', 'domain', 'label', 'days_left', 'expires', 'registrar', 'advice', 'checked_at', 'next_at']),
        'exposed' => api_pick($summary['exposed'], ['status', 'summary', 'found', 'since', 'advice', 'checked_at', 'next_at']),
        'headers' => api_pick($summary['headers'], ['status', 'grade', 'summary', 'checked_at', 'next_at']),
        'dns' => api_pick($summary['dns'], ['status', 'summary', 'domain', 'advice', 'checked_at', 'next_at']),
    ];
}

/** Only $keys of $values, in that order; null stays null. */
function api_pick(?array $values, array $keys): ?array
{
    if ($values === null) return null;
    $picked = [];
    foreach ($keys as $key) $picked[$key] = $values[$key] ?? null;
    return $picked;
}

/** One site in detail: its settings, 30 days, the response times of the last $hours, and the history of every check. */
function read_api_site(PDO $db, array $config, array $row, int $hours, int $now): array
{
    $windows = load_windows($db, $now);
    $site = api_site_fields($db, $config, site_summary($db, $config, $row, server_labels($db), $windows, [], $now), $row, $now) + [
        'keyword' => $row['keyword'],
        'interval_minutes' => site_interval($row, $config),
        'created_at' => $row['created_at'],
    ];
    if ($site['headers'] !== null) {
        $site['headers']['items'] = array_map(fn ($item) => api_pick($item, ['key', 'label', 'level', 'note', 'what', 'fix', 'tip']), header_items(json_decode((string) $row['headers_details'], true) ?: []));
        $site['headers']['tip'] = HEADERS_NGINX_TIP;
    }
    if ($site['dns'] !== null) {
        $site['dns']['items'] = array_map(fn ($item) => api_pick($item, ['key', 'label', 'level', 'records', 'note', 'what', 'fix_label', 'fix', 'tip']), dns_items(json_decode((string) $row['dns_details'], true) ?: [], $now, $config['timezone']));
    }
    $samples = $db->prepare('SELECT checked_at, status_code, ms, ok FROM samples WHERE site_id = ? AND checked_at >= ? ORDER BY checked_at');
    $samples->execute([$row['id'], utc_now($now - $hours * 3600)]);
    $events = $db->prepare('SELECT check_name, status, previous, message, silenced_by, created_at FROM events WHERE site_id = ? ORDER BY created_at DESC, id DESC LIMIT 100');
    $events->execute([$row['id']]);

    return [
        'site' => $site,
        'days' => daily_uptime($db, $row, $now, 30, $config['timezone']),
        'samples' => array_map(fn ($s) => ['checked_at' => $s['checked_at'], 'status_code' => (int) $s['status_code'], 'ms' => (int) $s['ms'], 'ok' => (bool) $s['ok']], $samples->fetchAll()),
        'events' => array_map(fn ($e) => ['check' => $e['check_name']] + array_diff_key($e, ['check_name' => true]), $events->fetchAll()),
        'maintenance' => site_maintenance($windows, $row, $config['timezone'], $now, $config['time_format']),
    ];
}

/** CSV for a spreadsheet; a cell that starts like a formula is quoted, so it stays text. */
function respond_csv(array $header, array $rows): never
{
    $out = fopen('php://temp', 'r+');
    foreach ([$header, ...$rows] as $row) {
        fputcsv($out, array_map(fn ($cell) => is_string($cell) && preg_match('/^[=+\-@\t\r]/', $cell) ? "'{$cell}" : $cell, $row), ',', '"', '', "\n");
    }
    rewind($out);
    respond(200, (string) stream_get_contents($out), 'text/csv; charset=utf-8', ['Cache-Control' => 'no-store']);
}
