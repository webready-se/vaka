<?php

/** Minimum password length for dashboard users. */
const MIN_PASSWORD_LENGTH = 10;

// =====================================================================
// Users (users.json, managed with php vaka user:*)
// =====================================================================

function load_users(string $file): array
{
    if (!is_file($file)) return [];
    return json_decode((string) file_get_contents($file), true) ?: [];
}

/** Write users.json atomically, readable by the owner only (it holds password hashes). */
function save_users(string $file, array $users): void
{
    ksort($users);
    $dir = dirname($file);
    if (!is_dir($dir)) mkdir($dir, 0750, true);
    $tmp = tempnam($dir, '.users-');
    file_put_contents($tmp, json_encode($users, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
    chmod($tmp, 0600);
    rename($tmp, $file);
}

/** A strong password that is easy to copy: four groups of five, about 99 bits, no look-alike characters (0/o, 1/l/i). */
function generate_password(): string
{
    $alphabet = 'abcdefghjkmnpqrstuvwxyz23456789';
    $groups = [];
    for ($g = 0; $g < 4; $g++) {
        $group = '';
        for ($c = 0; $c < 5; $c++) $group .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        $groups[] = $group;
    }
    return implode('-', $groups);
}

function hash_password(string $password): string
{
    return password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
}

/** A user name: 1-40 lowercase letters, digits, dots, dashes or underscores. */
function clean_username(string $name): string
{
    $name = strtolower(trim($name));
    if (!preg_match('/^[a-z0-9._-]{1,40}$/', $name)) {
        throw new InvalidArgumentException('A user name is 1-40 letters, digits, dots, dashes or underscores');
    }
    return $name;
}

/**
 * The group ids a user may see: null for every site (no groups listed),
 * otherwise the ids of the listed groups that exist. A restricted user never
 * sees sites without a group.
 */
function user_group_ids(PDO $db, array $user): ?array
{
    if (!($user['groups'] ?? [])) return null;
    $ids = [];
    foreach ($user['groups'] as $name) {
        if ($group = find_group($db, $name)) $ids[] = $group['id'];
    }
    return $ids;
}

/** A group was renamed: keep it for the users that had it. */
function rename_group_in_users(string $file, string $old, string $new): void
{
    $users = load_users($file);
    $changed = false;
    foreach ($users as &$user) {
        foreach ($user['groups'] ?? [] as $i => $name) {
            if (strcasecmp($name, $old) === 0) {
                $user['groups'][$i] = $new;
                $changed = true;
            }
        }
    }
    unset($user);
    if ($changed) save_users($file, $users);
}

/** The users limited to a group (by name, any case). */
function users_limited_to(string $file, string $group): array
{
    return array_keys(array_filter(load_users($file), fn (array $user) => (bool) array_filter($user['groups'] ?? [], fn ($name) => strcasecmp((string) $name, $group) === 0)));
}

/**
 * Refuse to remove a group while users are limited to it, as a database
 * refuses to delete a row that others point at: their access would point
 * at a name a later group could take, and taking the name away could leave
 * them with no groups, which means every site.
 */
function assert_group_unused(string $file, string $group): void
{
    $users = users_limited_to($file, $group);
    if (!$users) return;
    throw new InvalidArgumentException("{$group} cannot be removed while users are limited to it: " . list_words($users)
        . '. Give them other groups first with `php vaka user:edit <name> --groups=<groups>` (or `--groups=all` for every site), or remove those users, then remove the group');
}

// =====================================================================
// Client IP (same rules as Puls)
// =====================================================================

/**
 * The visitor's IP. X-Forwarded-For and X-Real-IP count only when the
 * request comes from a trusted proxy; then the last hop that is not itself
 * a trusted proxy wins, so a client cannot spoof its address.
 */
function client_ip(array $server, array $trustedProxies): string
{
    $ip = $server['REMOTE_ADDR'] ?? '';
    $trusted = function (string $addr) use ($trustedProxies): bool {
        foreach ($trustedProxies as $range) {
            if (ip_in_range($addr, $range)) return true;
        }
        return false;
    };
    if (!$trusted($ip)) return $ip;

    $hops = array_values(array_filter(array_map('trim', explode(',', $server['HTTP_X_FORWARDED_FOR'] ?? '')), 'strlen'));
    if (!$hops) {
        $realIp = trim($server['HTTP_X_REAL_IP'] ?? '');
        return filter_var($realIp, FILTER_VALIDATE_IP) !== false ? $realIp : $ip;
    }
    foreach (array_reverse($hops) as $hop) {
        if (filter_var($hop, FILTER_VALIDATE_IP) === false) break;
        $ip = $hop;
        if (!$trusted($hop)) break;
    }
    return $ip;
}

/** Whether $ip is $range: a single address or CIDR, IPv4 or IPv6 (IPv4-mapped IPv6 counts as IPv4). */
function ip_in_range(string $ip, string $range): bool
{
    [$net, $bits] = array_pad(explode('/', $range, 2), 2, null);
    $ipBin = @inet_pton($ip);
    $netBin = @inet_pton($net);
    if ($ipBin === false || $netBin === false) return false;
    if (strlen($ipBin) === 16 && strlen($netBin) === 4 && str_starts_with($ipBin, str_repeat("\0", 10) . "\xff\xff")) {
        $ipBin = substr($ipBin, 12);
    }
    if (strlen($ipBin) !== strlen($netBin)) return false;

    $bits = $bits === null ? strlen($ipBin) * 8 : (int) $bits;
    $bytes = intdiv($bits, 8);
    if (substr($ipBin, 0, $bytes) !== substr($netBin, 0, $bytes)) return false;
    $rest = $bits % 8;
    if ($rest === 0) return true;
    $mask = chr((0xff << (8 - $rest)) & 0xff);
    return ($ipBin[$bytes] & $mask) === ($netBin[$bytes] & $mask);
}

// =====================================================================
// Login lockout, counted per IP in the database (not in the session)
// =====================================================================

function audit_log(PDO $db, string $username, string $action, string $ip, int $now): void
{
    $db->prepare('INSERT INTO audit_log (username, action, ip, created_at) VALUES (?, ?, ?, ?)')
        ->execute([substr($username, 0, 40), $action, $ip, utc_now($now)]);
}

/**
 * Locked out after $max failed logins from this IP within $minutes, counted
 * since its last successful login. Counted in the database, so a client that
 * drops its session cookie is still counted.
 */
function is_locked_out(PDO $db, string $ip, int $max, int $minutes, int $now): bool
{
    $stmt = $db->prepare("SELECT COUNT(*) FROM audit_log
        WHERE ip = ? AND action = 'login_failed' AND created_at >= ?
        AND id > COALESCE((SELECT MAX(id) FROM audit_log WHERE ip = ? AND action = 'login_success'), 0)");
    $stmt->execute([$ip, utc_now($now - $minutes * 60), $ip]);
    return (int) $stmt->fetchColumn() >= $max;
}

// =====================================================================
// Sessions and CSRF
// =====================================================================

function start_session(array $config): void
{
    if (session_status() === PHP_SESSION_ACTIVE) return;

    // A directory of our own, so other PHP apps' session cleanup leaves ours alone
    $path = dirname($config['db_path']) . '/sessions';
    if (!is_dir($path)) mkdir($path, 0700, true);
    session_save_path($path);
    ini_set('session.gc_maxlifetime', (string) $config['session_lifetime']);
    ini_set('session.use_strict_mode', '1');
    session_name('vaka');
    session_set_cookie_params([
        'lifetime' => $config['session_lifetime'],
        'path' => '/',
        'secure' => is_https($_SERVER),
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
    session_start();

    // An id just replaced by the hourly new one (below) leads to it for a
    // minute, for a request that was already on its way with it: two sent at
    // once, or a slow network. Deleting the old session at once logged such a
    // request out, and with strict mode its empty session's cookie then
    // replaced the good one, so the whole browser was logged out.
    if (isset($_SESSION['replaced_at'])) {
        if (time() - (int) $_SESSION['replaced_at'] > SESSION_REPLACED_GRACE_SECONDS) {
            // Too late: an old id still in use could be a stolen one
            session_destroy();
            session_id(session_create_id());
            session_start();
            return;
        }
        $next = (string) $_SESSION['replaced_by'];
        session_write_close();
        session_id($next);
        // Strict mode: if the new session is gone (logged out since), this is an empty one
        session_start();
        return;
    }

    // A new session id every hour makes a stolen one short-lived
    if (!empty($_SESSION['user']) && time() - ($_SESSION['rotated_at'] ?? 0) > 3600) rotate_session();
}

/** How long a replaced session id still leads to the new one. */
const SESSION_REPLACED_GRACE_SECONDS = 60;

/**
 * Move the session to a new id, keeping the old one for a minute as a
 * pointer to it, without the user (as the PHP manual advises for
 * session_regenerate_id() with requests in flight).
 */
function rotate_session(): void
{
    $data = $_SESSION;
    $next = session_create_id();
    $_SESSION = ['replaced_at' => time(), 'replaced_by' => $next];
    session_write_close();
    // The new id is our own, not one a visitor sent, so strict mode may accept it
    ini_set('session.use_strict_mode', '0');
    session_id($next);
    session_start();
    ini_set('session.use_strict_mode', '1');
    $_SESSION = ['rotated_at' => time()] + $data;
}

function is_https(array $server): bool
{
    return ($server['HTTPS'] ?? '') !== '' && strtolower($server['HTTPS']) !== 'off';
}

/** The session's CSRF token, created on first use. */
function csrf_token(): string
{
    return $_SESSION['csrf'] ??= bin2hex(random_bytes(32));
}

function check_csrf(string $token): bool
{
    return isset($_SESSION['csrf']) && $token !== '' && hash_equals($_SESSION['csrf'], $token);
}
