<?php

/** Every check Vaka knows, in the order the dashboard shows them. */
const CHECKS = ['uptime', 'ssl', 'domain', 'exposed', 'headers', 'dns'];

/** When a group's alerts go out. */
const POLICIES = ['immediate', 'delayed', 'digest', 'silent'];

// =====================================================================
// URLs
// =====================================================================

/**
 * The one spelling of a URL that Vaka stores and compares: https added when
 * the scheme is missing, scheme and host lowercased, international domains in
 * punycode, no default port, no root slash, no fragment. Throws with a
 * message for the user when the URL cannot be checked.
 */
function normalize_url(string $input): string
{
    $url = trim($input);
    if ($url === '') throw new InvalidArgumentException('The URL is empty');
    if (strlen($url) > 2000) throw new InvalidArgumentException('The URL is too long (2000 characters at most)');
    if (!preg_match('#^[a-z][a-z0-9+.-]*://#i', $url)) $url = 'https://' . $url;

    $parts = parse_url($url);
    if ($parts === false || ($parts['host'] ?? '') === '') {
        throw new InvalidArgumentException("\"{$input}\" is not a valid URL");
    }
    $scheme = strtolower($parts['scheme']);
    if (!in_array($scheme, ['http', 'https'], true)) {
        throw new InvalidArgumentException("Only http or https URLs can be watched (got {$scheme})");
    }
    if (isset($parts['user']) || isset($parts['pass'])) {
        throw new InvalidArgumentException('Leave the user name and password out of the URL: Vaka would store them as plain text');
    }

    $host = rtrim($parts['host'], '.');
    if ($host[0] === '[') {
        if (!filter_var(substr($host, 1, -1), FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            throw new InvalidArgumentException("\"{$input}\" is not a valid URL");
        }
        $host = strtolower($host);
    } elseif (!filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
        if (preg_match('/[^\x00-\x7F]/', $host)) {
            if (!function_exists('idn_to_ascii')) {
                throw new InvalidArgumentException("International domain names need PHP's intl extension (or enter the xn-- form)");
            }
            $host = idn_to_ascii($host, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);
            if ($host === false) throw new InvalidArgumentException("\"{$input}\" is not a valid domain");
        }
        $host = strtolower($host);
        if (!filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME)) {
            throw new InvalidArgumentException("\"{$input}\" is not a valid domain");
        }
        if ($host !== 'localhost' && !str_contains($host, '.')) {
            throw new InvalidArgumentException("\"{$input}\" has no domain (example: shop.example)");
        }
    }

    $port = $parts['port'] ?? null;
    if (($scheme === 'https' && $port === 443) || ($scheme === 'http' && $port === 80)) $port = null;
    $path = $parts['path'] ?? '';
    if ($path === '/') $path = '';
    $query = ($parts['query'] ?? '') !== '' ? '?' . $parts['query'] : '';

    return $scheme . '://' . $host . ($port ? ':' . $port : '') . $path . $query;
}

/** The host of a URL without a leading www. */
function site_host(string $url): string
{
    $host = (string) parse_url($url, PHP_URL_HOST);
    return str_starts_with($host, 'www.') ? substr($host, 4) : $host;
}

/**
 * SQL condition for the sites on a server named by its IP, its label or a
 * server name set by hand on sites; bind the name three times. A site behind
 * a CDN answers on the CDN's addresses, so it is on no server by IP.
 */
const ON_SERVER_SQL = "((s.ip = ? AND s.cdn = '' AND s.server = '') OR lower(s.server) = lower(?)
    OR (s.cdn = '' AND s.server = '' AND s.ip IN (SELECT ip FROM servers WHERE lower(label) = lower(?))))";

/** Every site on a server (see ON_SERVER_SQL), paused ones too, in the order they are shown. */
function sites_on_server(PDO $db, string $server): array
{
    $stmt = $db->prepare('SELECT s.* FROM sites s WHERE ' . ON_SERVER_SQL);
    $stmt->execute([$server, $server, $server]);
    return sort_sites($stmt->fetchAll(), false);
}

/**
 * Sites in the order people read them: by the address as it is shown, so an
 * international domain sorts by its letters (räksmörgås), not by its xn--
 * form. With $byGroup, they stay in their groups in the order the query
 * gave the groups.
 */
function sort_sites(array $sites, bool $byGroup = true): array
{
    $groups = [];
    foreach ($sites as $site) $groups[$byGroup ? ($site['group_id'] ?? 'none') : 'all'] ??= count($groups);
    $key = fn (array $site) => [$groups[$byGroup ? ($site['group_id'] ?? 'none') : 'all'], display_url($site['url'])];
    usort($sites, fn (array $a, array $b) => $key($a) <=> $key($b));
    return $sites;
}

/** A site in a pick list: its address as shown, with its group and whether it is paused. */
function site_choice(array $site): string
{
    return display_url($site['url']) . ' (' . ($site['group_name'] ?? 'no group') . ($site['paused'] ? ', paused' : '') . ')';
}

/** A URL with its scheme, and an international domain as it is written: https://räksmörgås.example/shop. */
function readable_url(string $url): string
{
    $scheme = (string) parse_url($url, PHP_URL_SCHEME);
    return ($scheme !== '' ? "{$scheme}://" : '') . display_url($url);
}

/** A URL without its scheme, as people say it: an international domain as it is written (with intl), not as xn--. */
function display_url(string $url): string
{
    $shown = preg_replace('#^https?://#', '', $url);
    $host = (string) parse_url($url, PHP_URL_HOST);
    if (str_contains($host, 'xn--') && function_exists('idn_to_utf8')) {
        $written = idn_to_utf8($host, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);
        if ($written !== false && str_starts_with($shown, $host)) $shown = $written . substr($shown, strlen($host));
    }
    return $shown;
}

/** Number of characters (not bytes) in a UTF-8 string, without needing mbstring. */
function text_length(string $text): int
{
    return (int) preg_match_all('/./su', $text);
}

/** A trimmed one-line text field, or an exception naming the limit. */
function clean_text(string $value, int $max, string $what): string
{
    $value = trim($value);
    if (preg_match('/[\x00-\x1F\x7F]/', $value)) {
        throw new InvalidArgumentException(ucfirst($what) . ' cannot contain control characters');
    }
    if (text_length($value) > $max) {
        throw new InvalidArgumentException(ucfirst($what) . "s are {$max} characters at most");
    }
    return $value;
}

/** A whole number of minutes, 1-1440, or null for an empty value. */
function clean_minutes(string $value, string $message): ?int
{
    $value = trim($value);
    if ($value === '') return null;
    if (!ctype_digit($value) || (int) $value < 1 || (int) $value > 1440) {
        throw new InvalidArgumentException($message);
    }
    return (int) $value;
}

/** yes/no in the usual spellings, or null when it is neither. */
function parse_bool(string $value): ?bool
{
    $value = strtolower(trim($value));
    if (in_array($value, ['1', 'true', 'yes', 'y', 'on', 'ja', 'j'], true)) return true;
    if (in_array($value, ['0', 'false', 'no', 'n', 'off', 'nej'], true)) return false;
    return null;
}

/** A webhook URL safe to print: its host and last four characters. */
function mask_secret(string $url): string
{
    if ($url === '') return '';
    return parse_url($url, PHP_URL_HOST) . '/...' . substr($url, -4);
}

// =====================================================================
// Groups
// =====================================================================

/**
 * Validated group columns for the fields present in $input (name, policy,
 * delay_minutes, slack_webhook, webhook_url, email).
 */
function clean_group_fields(array $input): array
{
    $fields = [];
    if (array_key_exists('name', $input)) {
        $name = clean_text((string) $input['name'], 60, 'group name');
        if ($name === '') throw new InvalidArgumentException('A group needs a name');
        if (strtolower($name) === 'none') throw new InvalidArgumentException('"none" is reserved: it means no group');
        $fields['name'] = $name;
    }
    if (array_key_exists('policy', $input)) {
        $policy = strtolower(trim((string) $input['policy']));
        if (!in_array($policy, POLICIES, true)) {
            throw new InvalidArgumentException("The alert policy is immediate, delayed, digest or silent (got \"{$input['policy']}\")");
        }
        $fields['policy'] = $policy;
    }
    if (array_key_exists('delay_minutes', $input)) {
        $fields['delay_minutes'] = clean_minutes((string) $input['delay_minutes'], 'The delay is a number of minutes between 1 and 1440') ?? 0;
    }
    if (array_key_exists('slack_webhook', $input)) {
        $url = trim((string) $input['slack_webhook']);
        // Plain http only to this machine, for testing against a local receiver
        $local = in_array(parse_url($url, PHP_URL_HOST), ['127.0.0.1', 'localhost', '[::1]'], true);
        if ($url !== '' && ((!str_starts_with(strtolower($url), 'https://') && !$local) || !filter_var($url, FILTER_VALIDATE_URL))) {
            throw new InvalidArgumentException('The Slack webhook must be an https URL');
        }
        $fields['slack_webhook'] = $url;
    }
    if (array_key_exists('webhook_url', $input)) {
        $url = trim((string) $input['webhook_url']);
        if ($url !== '' && (!preg_match('#^https?://#i', $url) || !filter_var($url, FILTER_VALIDATE_URL))) {
            throw new InvalidArgumentException('The webhook must be an http or https URL');
        }
        $fields['webhook_url'] = $url;
    }
    if (array_key_exists('email', $input)) {
        $addresses = array_values(array_filter(preg_split('/[\s,;]+/', (string) $input['email'])));
        foreach ($addresses as $address) {
            if (!filter_var($address, FILTER_VALIDATE_EMAIL)) {
                throw new InvalidArgumentException("\"{$address}\" is not an email address");
            }
        }
        $fields['email'] = implode(', ', $addresses);
    }
    return $fields;
}

/** Rules that depend on more than one field: only the delayed policy has a delay, and it needs one. */
function finish_group_fields(array $group): array
{
    if ($group['policy'] !== 'delayed') {
        $group['delay_minutes'] = 0;
    } elseif ($group['delay_minutes'] < 1) {
        throw new InvalidArgumentException('The delayed policy needs a delay in minutes (for example --delay=10)');
    }
    return $group;
}

function assert_group_name_free(PDO $db, string $name, int $exceptId = 0): void
{
    $existing = find_group($db, $name);
    if ($existing && $existing['id'] !== $exceptId) {
        throw new InvalidArgumentException("A group called \"{$existing['name']}\" already exists");
    }
}

function add_group(PDO $db, array $input): int
{
    $fields = clean_group_fields($input + ['policy' => 'immediate', 'delay_minutes' => '']);
    if (!isset($fields['name'])) throw new InvalidArgumentException('A group needs a name');
    $fields = finish_group_fields($fields + ['slack_webhook' => '', 'webhook_url' => '', 'email' => '']);
    assert_group_name_free($db, $fields['name']);

    $db->prepare('INSERT INTO groups (name, policy, delay_minutes, slack_webhook, webhook_url, email, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)')
        ->execute([$fields['name'], $fields['policy'], $fields['delay_minutes'], $fields['slack_webhook'], $fields['webhook_url'], $fields['email'], utc_now()]);
    return (int) $db->lastInsertId();
}

function update_group(PDO $db, int $id, array $input): void
{
    $current = get_group($db, $id);
    if (!$current) throw new InvalidArgumentException('That group no longer exists');
    $group = finish_group_fields(clean_group_fields($input) + $current);
    assert_group_name_free($db, $group['name'], $id);

    $db->prepare('UPDATE groups SET name = ?, policy = ?, delay_minutes = ?, slack_webhook = ?, webhook_url = ?, email = ? WHERE id = ?')
        ->execute([$group['name'], $group['policy'], $group['delay_minutes'], $group['slack_webhook'], $group['webhook_url'], $group['email'], $id]);
}

/** Remove a group. Its sites stay, without a group. */
function remove_group(PDO $db, int $id): void
{
    $db->prepare('DELETE FROM groups WHERE id = ?')->execute([$id]);
}

/** A group by name, any case. */
function find_group(PDO $db, string $name): ?array
{
    $stmt = $db->prepare('SELECT * FROM groups WHERE name = ?');
    $stmt->execute([trim($name)]);
    return $stmt->fetch() ?: null;
}

function get_group(PDO $db, int $id): ?array
{
    $stmt = $db->prepare('SELECT * FROM groups WHERE id = ?');
    $stmt->execute([$id]);
    return $stmt->fetch() ?: null;
}

/** Every group by name, with the number of sites in it. */
function list_groups(PDO $db): array
{
    return $db->query('SELECT g.*, COUNT(s.id) AS site_count FROM groups g
        LEFT JOIN sites s ON s.group_id = g.id
        GROUP BY g.id ORDER BY g.name COLLATE NOCASE')->fetchAll();
}

/** When a group's alerts go out, in plain words. */
function describe_policy(array $group, string $digestTime): string
{
    return match ($group['policy']) {
        'delayed' => "after {$group['delay_minutes']} min down",
        'digest' => "as a daily summary at {$digestTime}",
        'silent' => 'never (dashboard only)',
        default => 'right away',
    };
}

/** Alert destinations in words, with webhook URLs masked. */
function destination_words(string $slack, string $webhook, array $emails): array
{
    $to = [];
    if ($slack !== '') $to[] = 'Slack ' . mask_secret($slack);
    if ($webhook !== '') $to[] = 'webhook ' . mask_secret($webhook);
    return [...$to, ...$emails];
}

/** A group's own destinations, or failing that the defaults from .env. */
function group_destinations(array $group, array $config): array
{
    $emails = $group['email'] === '' ? [] : explode(', ', $group['email']);
    $own = destination_words($group['slack_webhook'], $group['webhook_url'], $emails);
    if ($own) return ['own', $own];
    $defaults = destination_words($config['slack_webhook_url'], $config['webhook_url'], $config['alert_email']);
    return [$defaults ? 'defaults' : 'none', $defaults];
}

/** "a", "a and b", "a, b and c" */
function list_words(array $words): string
{
    $last = array_pop($words);
    return $words ? implode(', ', $words) . ' and ' . $last : (string) $last;
}

/** When and where a group's alerts go, as one sentence. */
function describe_alerting(array $group, array $config): string
{
    if ($group['policy'] === 'silent') return 'No alerts are sent: these sites show on the dashboard only.';
    $when = 'Alerts go out ' . describe_policy($group, $config['digest_time']);
    [$source, $to] = group_destinations($group, $config);
    return match ($source) {
        'own' => "{$when}, to " . list_words($to) . '.',
        'defaults' => "{$when}, to the defaults in .env: " . list_words($to) . '.',
        default => "{$when}, but nowhere yet: set SLACK_WEBHOOK_URL in .env, or give the group --slack=<webhook url>.",
    };
}

/** Where a group's alerts go, short enough for a table column. */
function describe_destinations(array $group, array $config): string
{
    if ($group['policy'] === 'silent') return '-';
    [$source, $to] = group_destinations($group, $config);
    return match ($source) {
        'own' => implode(', ', $to),
        'defaults' => 'defaults in .env',
        default => 'nowhere yet',
    };
}

// =====================================================================
// Sites
// =====================================================================

/**
 * Validated site columns for the fields present in $input (url, group,
 * name, keyword, interval, paused, disabled_checks, server).
 */
function clean_site_fields(PDO $db, array $input): array
{
    $fields = [];
    if (array_key_exists('url', $input)) {
        $fields['url'] = normalize_url((string) $input['url']);
    }
    if (array_key_exists('group', $input)) {
        $name = trim((string) $input['group']);
        if ($name === '' || strtolower($name) === 'none') {
            $fields['group_id'] = null;
        } else {
            $group = find_group($db, $name);
            if (!$group) {
                throw new InvalidArgumentException("No group called \"{$name}\". Add it with `php vaka group:add \"{$name}\"` or see `php vaka group:list`");
            }
            $fields['group_id'] = $group['id'];
        }
    }
    if (array_key_exists('name', $input)) $fields['name'] = clean_text((string) $input['name'], 100, 'site name');
    if (array_key_exists('keyword', $input)) $fields['keyword'] = clean_text((string) $input['keyword'], 200, 'keyword');
    if (array_key_exists('server', $input)) $fields['server'] = clean_text((string) $input['server'], 100, 'server name');
    if (array_key_exists('interval', $input)) {
        $fields['interval_minutes'] = clean_minutes((string) $input['interval'], 'The interval is a number of minutes between 1 and 1440');
    }
    if (array_key_exists('paused', $input)) $fields['paused'] = $input['paused'] ? 1 : 0;
    if (array_key_exists('disabled_checks', $input)) {
        $checks = array_values(array_unique(array_filter(preg_split('/[\s,]+/', strtolower((string) $input['disabled_checks'])))));
        foreach ($checks as $check) {
            if (!in_array($check, CHECKS, true)) {
                throw new InvalidArgumentException("\"{$check}\" is not a check. The checks are " . implode(', ', CHECKS));
            }
        }
        sort($checks);
        $fields['disabled_checks'] = implode(',', $checks);
    }
    return $fields;
}

function assert_url_free(PDO $db, string $url, int $exceptId = 0): void
{
    $existing = site_by_url($db, $url);
    if ($existing && $existing['id'] !== $exceptId) {
        throw new InvalidArgumentException("{$url} is already watched");
    }
}

function add_site(PDO $db, array $input): int
{
    if (trim((string) ($input['url'] ?? '')) === '') throw new InvalidArgumentException('A site needs a URL');
    $fields = clean_site_fields($db, $input);
    assert_url_free($db, $fields['url']);
    $fields['created_at'] = utc_now();

    $columns = implode(', ', array_keys($fields));
    $marks = implode(', ', array_fill(0, count($fields), '?'));
    $db->prepare("INSERT INTO sites ({$columns}) VALUES ({$marks})")->execute(array_values($fields));
    return (int) $db->lastInsertId();
}

function update_site(PDO $db, int $id, array $input): void
{
    $fields = clean_site_fields($db, $input);
    if (!$fields) return;
    if (isset($fields['url'])) assert_url_free($db, $fields['url'], $id);

    $set = implode(', ', array_map(fn ($column) => "{$column} = ?", array_keys($fields)));
    $db->prepare("UPDATE sites SET {$set} WHERE id = ?")->execute([...array_values($fields), $id]);

    // A change to what is checked (or a resumed site) is checked on the next run, not after the old interval;
    // a new URL is a new target for every check
    if (isset($fields['url'])) {
        $db->prepare('UPDATE check_state SET next_at = NULL WHERE site_id = ?')->execute([$id]);
    } elseif (array_intersect_key($fields, array_flip(['keyword', 'interval_minutes', 'paused']))) {
        $db->prepare("UPDATE check_state SET next_at = NULL WHERE site_id = ? AND check_name = 'uptime'")->execute([$id]);
    }
}

/** Minutes between uptime checks for a site. */
function site_interval(array $site, array $config): int
{
    return $site['interval_minutes'] ?? $config['uptime_interval'];
}

function remove_site(PDO $db, int $id): void
{
    $db->prepare('DELETE FROM sites WHERE id = ?')->execute([$id]);
}

function site_by_url(PDO $db, string $url): ?array
{
    $stmt = $db->prepare('SELECT * FROM sites WHERE url = ?');
    $stmt->execute([$url]);
    return $stmt->fetch() ?: null;
}

/**
 * The site a user means: an id, a URL in any spelling, or a bare domain
 * (with or without www) when exactly one site has it.
 */
function find_site(PDO $db, string $ref): ?array
{
    $ref = trim($ref);
    if (ctype_digit($ref)) {
        $stmt = $db->prepare('SELECT * FROM sites WHERE id = ?');
        $stmt->execute([(int) $ref]);
        return $stmt->fetch() ?: null;
    }

    try {
        $url = normalize_url($ref);
    } catch (InvalidArgumentException) {
        return null;
    }
    // Typed without a scheme, it may be an http site as well
    $candidates = [$url];
    if (!preg_match('#^[a-z][a-z0-9+.-]*://#i', $ref)) $candidates[] = 'http://' . substr($url, strlen('https://'));
    foreach ($candidates as $candidate) {
        if ($site = site_by_url($db, $candidate)) return $site;
    }

    // A bare domain (port included): the one site on it, or else the one without a path
    if (parse_url($url, PHP_URL_PATH) !== null || parse_url($url, PHP_URL_QUERY) !== null) return null;
    $authority = site_authority($url);
    $matches = array_values(array_filter($db->query('SELECT * FROM sites ORDER BY id')->fetchAll(), fn ($site) => site_authority($site['url']) === $authority));
    if (count($matches) === 1) return $matches[0];
    $roots = array_values(array_filter($matches, fn ($site) => parse_url($site['url'], PHP_URL_PATH) === null && parse_url($site['url'], PHP_URL_QUERY) === null));
    return count($roots) === 1 ? $roots[0] : null;
}

/** Host without www, plus the port when there is one: what people mean by "the domain". */
function site_authority(string $url): string
{
    $port = parse_url($url, PHP_URL_PORT);
    return site_host($url) . ($port ? ':' . $port : '');
}

/** Sites by group name and URL, sites without a group last, with their group's name. */
function list_sites(PDO $db, ?int $groupId = null): array
{
    $where = $groupId === null ? '' : 'WHERE s.group_id = ' . $groupId;
    return sort_sites($db->query("SELECT s.*, g.name AS group_name FROM sites s
        LEFT JOIN groups g ON g.id = s.group_id
        {$where}
        ORDER BY s.group_id IS NULL, g.name COLLATE NOCASE")->fetchAll());
}
