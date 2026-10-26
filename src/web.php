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
        if (!$user) respond_json(401, ['error' => 'Log in first']);
        handle_api($config, $user, (string) $_GET['api'], $method, $now);
    }
    if (!$user) redirect('/?login');
    show_dashboard($user);
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
    $owl = owl_tile();
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
      {$owl}
      <h1>vaka</h1>
      <p class="login-tagline">Keeps watch while you sleep.</p>
    {$panel}
      <p class="login-foot">Made by <a href="https://webready.se">Webready</a> in Sweden</p>
    </main>
    </body>
    </html>
    HTML, 'text/html; charset=utf-8', ['Cache-Control' => 'no-store']);
}

/** The Vaka mark: a teal tile with the watching owl, inline (no style attributes). */
function owl_tile(): string
{
    return '<svg class="tile" viewBox="0 0 36 36" aria-hidden="true" focusable="false">'
        . '<rect class="tile-bg" width="36" height="36" rx="8"/>'
        . '<circle class="owl-line" cx="12.5" cy="19.5" r="5"/><circle class="owl-line" cx="23.5" cy="19.5" r="5"/>'
        . '<path class="owl-line" d="M6 12.5c3.5-2.3 8.6-2 12 2.2 3.4-4.2 8.5-4.5 12-2.2"/><path class="owl-line" d="M16.4 26.6 18 28.6l1.6-2"/>'
        . '<circle class="owl-pupil" cx="12.5" cy="19.5" r="1.9"/><circle class="owl-pupil" cx="23.5" cy="19.5" r="1.9"/></svg>';
}

function show_dashboard(array $user): never
{
    $html = (string) file_get_contents(__DIR__ . '/../public/dashboard.html');
    $html = strtr($html, [
        '{{csrf}}' => e(csrf_token()),
        '{{version}}' => e(VAKA_VERSION),
        '{{user}}' => e($user['name']),
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
    return $db->query("SELECT s.*, g.name AS group_name, g.policy, g.delay_minutes,
            c.status, c.summary, c.details, c.fail_count, c.checked_at, c.changed_at, c.snoozed_until, c.flapping_since,
            cs.status AS ssl_status, cs.summary AS ssl_summary, cs.details AS ssl_details,
            ce.status AS exposed_status, ce.summary AS exposed_summary, ce.details AS exposed_details, ce.changed_at AS exposed_changed_at,
            ch.status AS headers_status, ch.summary AS headers_summary, ch.details AS headers_details,
            cd.status AS domain_status, cd.summary AS domain_summary, cd.details AS domain_details
        FROM sites s
        LEFT JOIN groups g ON g.id = s.group_id
        LEFT JOIN check_state c ON c.site_id = s.id AND c.check_name = 'uptime'
        LEFT JOIN check_state cs ON cs.site_id = s.id AND cs.check_name = 'ssl'
        LEFT JOIN check_state ce ON ce.site_id = s.id AND ce.check_name = 'exposed'
        LEFT JOIN check_state ch ON ch.site_id = s.id AND ch.check_name = 'headers'
        LEFT JOIN check_state cd ON cd.site_id = s.id AND cd.check_name = 'domain'
        WHERE {$where}
        ORDER BY s.group_id IS NULL, g.name COLLATE NOCASE, s.url")->fetchAll();
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
    $ssl = null;
    if (in_array($row['ssl_status'], ['ok', 'warn', 'fail'], true)) {
        $sslDetails = json_decode((string) $row['ssl_details'], true) ?: [];
        $ssl = [
            'status' => $row['ssl_status'],
            'summary' => $row['ssl_summary'],
            'days_left' => $sslDetails['days_left'] ?? null,
            'valid_to' => $sslDetails['valid_to'] ?? null,
            'advice' => $row['ssl_status'] === 'ok' ? '' : advice_for($sslDetails['problem'] ?? '', 0),
        ];
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
        ];
    }
    $domain = null;
    if (in_array($row['domain_status'], ['ok', 'warn', 'fail', 'unknown'], true)) {
        $domainDetails = json_decode((string) $row['domain_details'], true) ?: [];
        $domain = [
            'status' => $row['domain_status'],
            'summary' => $row['domain_summary'],
            'domain' => $domainDetails['domain'] ?? null,
            'days_left' => $domainDetails['days_left'] ?? null,
            'expires' => $domainDetails['expires'] ?? null,
            'registrar' => $domainDetails['registrar'] ?? '',
            'advice' => in_array($row['domain_status'], ['warn', 'fail'], true) ? advice_for($domainDetails['problem'] ?? '', 0) : '',
        ];
    }
    // The grade only: what each header does comes with the site detail (see api_site())
    $headers = null;
    if (in_array($row['headers_status'], ['ok', 'warn', 'unknown'], true)) {
        $headers = ['status' => $row['headers_status'], 'grade' => (json_decode((string) $row['headers_details'], true) ?: [])['grade'] ?? null, 'summary' => $row['headers_summary']];
    }
    $serverKey = server_key($row);

    return [
        'id' => $row['id'],
        'url' => $row['url'],
        'label' => display_url($row['url']),
        'name' => $row['name'],
        'group' => $row['group_name'],
        'status' => $status,
        'summary' => (string) ($row['summary'] ?? ''),
        'advice' => in_array($status, ['down', 'failing'], true) ? advice_for($details['problem'] ?? '', (int) ($details['status_code'] ?? 0)) : '',
        'since' => $since,
        'checked_at' => $row['checked_at'],
        'ms' => $status === 'up' ? ($details['ms'] ?? null) : null,
        'server' => $serverKey === null ? null : server_display($row, $labels),
        'cdn' => $row['cdn'] !== '' ? $row['cdn'] : null,
        'snoozed_until' => $quiet === 'snooze' ? $row['snoozed_until'] : null,
        'maintenance' => $quiet === 'maintenance',
        'unstable' => $row['flapping_since'] !== null,
        'ssl' => $ssl,
        'domain' => $domain,
        'exposed' => $exposed,
        'headers' => $headers,
        'uptime_24h' => site_uptime($db, $row, 86400, $now),
        'uptime_30d' => site_uptime($db, $row, 30 * 86400, $now),
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
        'setup' => $allowed === null ? ['cron' => cron_line(dirname(__DIR__)), 'alerts' => alerts_configured($db, $config)] : null,
        'last_incident' => $lastIncident,
        'generated_at' => utc_now($now),
        'timezone' => $config['timezone'],
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
    $events = $db->prepare("SELECT status, previous, message, silenced_by, created_at FROM events WHERE site_id = ? AND check_name = 'uptime' ORDER BY created_at DESC, id DESC LIMIT 100");
    $events->execute([$row['id']]);

    $windows = load_windows($db, $now);
    $mine = array_values(array_filter($windows, fn ($w) => $w['site_id'] === $row['id'] || ($w['group_id'] !== null && $w['group_id'] === $row['group_id'])));
    $site = site_summary($db, $config, $row, server_labels($db), $windows, $samples, $now);
    if ($site['headers'] !== null) $site['headers']['items'] = header_items(json_decode((string) $row['headers_details'], true) ?: []);

    return [
        'site' => $site + [
            'keyword' => $row['keyword'],
            'interval_minutes' => site_interval($row, $config),
            'created_at' => $row['created_at'],
        ],
        'days' => daily_uptime($db, $row, $now, 30, $config['timezone']),
        'uptime' => [
            '24h' => site_uptime($db, $row, 86400, $now),
            '7d' => site_uptime($db, $row, 7 * 86400, $now),
            '30d' => site_uptime($db, $row, 30 * 86400, $now),
        ],
        'samples' => $samples,
        'events' => $events->fetchAll(),
        'maintenance' => array_map(fn ($w) => [
            'when' => describe_window($w, $config['timezone']),
            'label' => $w['label'],
            'active' => window_active($w, $now, $config['timezone']),
        ], $mine),
    ];
}
