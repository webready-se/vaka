<?php

/** How often a domain's registration is looked up, and how soon again after a failed lookup. */
const DOMAIN_INTERVAL_SECONDS = 24 * 3600;
const DOMAIN_RETRY_SECONDS = 3600;

/** How long a copy of IANA's RDAP bootstrap file is used before it is fetched again. */
const RDAP_BOOTSTRAP_MAX_AGE = 7 * 86400;

/**
 * The names a host's domain could be registered as, shortest first:
 * shop.example.se gives example.se, then shop.example.se. The first one the
 * registry knows is the registration (example.co.uk comes after co.uk,
 * which no registry has as a domain). An IP address has none.
 */
function registrable_candidates(string $host): array
{
    $host = strtolower(rtrim(trim($host, '[]'), '.'));
    if ($host === '' || filter_var($host, FILTER_VALIDATE_IP) || !str_contains($host, '.')) return [];
    $labels = explode('.', $host);
    $candidates = [];
    for ($i = 2; $i <= count($labels); $i++) $candidates[] = implode('.', array_slice($labels, -$i));
    return $candidates;
}

/** The RDAP server for a domain from IANA's bootstrap file (the longest matching entry), https preferred, or null. */
function rdap_base_url(array $bootstrap, string $domain): ?string
{
    $best = null;
    $length = 0;
    foreach ($bootstrap['services'] ?? [] as $service) {
        [$labels, $urls] = $service + [[], []];
        foreach ($labels as $label) {
            $label = strtolower($label);
            if (strlen($label) <= $length || ($domain !== $label && !str_ends_with($domain, '.' . $label))) continue;
            $https = array_values(array_filter($urls, fn ($url) => str_starts_with($url, 'https://')));
            $best = $https[0] ?? $urls[0] ?? null;
            $length = strlen($label);
        }
    }
    return $best === null ? null : rtrim($best, '/') . '/';
}

/**
 * IANA's RDAP bootstrap file, from a copy in $cacheFile that is at most a
 * week old, else fetched and saved. When IANA cannot be reached an older
 * copy is used; null when there is none.
 */
function load_rdap_bootstrap(string $cacheFile, string $url, array $config, int $now): ?array
{
    $cached = is_file($cacheFile) ? json_decode((string) file_get_contents($cacheFile), true) : null;
    $cached = is_array($cached) && isset($cached['bootstrap']['services']) ? $cached : null;
    if ($cached && $now - (int) ($cached['fetched_at'] ?? 0) < RDAP_BOOTSTRAP_MAX_AGE) return $cached['bootstrap'];

    $response = fetch_many(['bootstrap' => $url], $config['uptime_timeout'], user_agent($config), 1, $config['ssl_ca_file'])['bootstrap'];
    $fresh = $response['errno'] === 0 && $response['status'] === 200 ? json_decode($response['body'], true) : null;
    if (is_array($fresh) && isset($fresh['services'])) {
        if (is_writable(dirname($cacheFile))) file_put_contents($cacheFile, json_encode(['fetched_at' => $now, 'bootstrap' => $fresh]));
        return $fresh;
    }
    return $cached['bootstrap'] ?? null;
}

/** The expiry date and registrar from an RDAP domain answer. */
function parse_rdap_domain(array $json): array
{
    $expires = null;
    foreach ($json['events'] ?? [] as $event) {
        if (($event['eventAction'] ?? '') !== 'expiration') continue;
        $time = strtotime((string) ($event['eventDate'] ?? ''));
        if ($time !== false) $expires = $time;
    }
    $registrar = '';
    foreach ($json['entities'] ?? [] as $entity) {
        if (!in_array('registrar', $entity['roles'] ?? [], true)) continue;
        foreach ($entity['vcardArray'][1] ?? [] as $field) {
            if (($field[0] ?? '') === 'fn') $registrar = substr(trim((string) ($field[3] ?? '')), 0, 100);
        }
    }
    return ['expires' => $expires, 'registrar' => $registrar];
}

/**
 * The expiry date and registrar from a WHOIS answer in the plain
 * "key: value" form of the .se and .nu registry. Nothing else in it is read.
 */
function parse_whois(string $text): array
{
    $found = (bool) preg_match('/^domain:\s*\S/mi', $text);
    $expires = preg_match('/^expires:\s*(\d{4}-\d{2}-\d{2})\s*$/mi', $text, $m) ? strtotime($m[1] . 'T00:00:00Z') : false;
    $registrar = preg_match('/^registrar:\s*(.+?)\s*$/mi', $text, $r) ? substr($r[1], 0, 100) : '';
    return ['found' => $found, 'expires' => $found && $expires !== false ? $expires : null, 'registrar' => $found ? $registrar : ''];
}

/** Ask an RDAP server about a domain. */
function query_rdap(string $base, string $domain, array $config): array
{
    $response = fetch_many(['rdap' => $base . 'domain/' . $domain], $config['uptime_timeout'], user_agent($config), 1, $config['ssl_ca_file'])['rdap'];
    if ($response['errno']) return ['status' => 'error', 'error' => lcfirst(connection_problem($response['errno'], $response['error'], $config['uptime_timeout']))];
    if ($response['status'] === 404) return ['status' => 'not_found'];
    $json = json_decode($response['body'], true);
    if ($response['status'] !== 200 || !is_array($json)) return ['status' => 'error', 'error' => 'the registry answered ' . trim($response['status'] . ' ' . status_text($response['status']))];
    return ['status' => 'found'] + parse_rdap_domain($json);
}

/** Ask a WHOIS server ("host" or "host:port") about a domain. */
function query_whois(string $server, string $domain, int $timeout): array
{
    [$host, $port] = explode(':', $server, 2) + [1 => '43'];
    set_error_handler(fn () => true);
    $socket = stream_socket_client("tcp://{$host}:{$port}", $errno, $errstr, $timeout);
    restore_error_handler();
    if (!$socket) return ['status' => 'error', 'error' => "could not reach {$host}"];

    stream_set_timeout($socket, $timeout);
    fwrite($socket, $domain . "\r\n");
    $answer = '';
    while (!feof($socket) && strlen($answer) < 65536) {
        $chunk = fread($socket, 8192);
        if ($chunk === false || ($chunk === '' && stream_get_meta_data($socket)['timed_out'])) break;
        $answer .= $chunk;
    }
    fclose($socket);
    if ($answer === '') return ['status' => 'error', 'error' => "no answer from {$host} within {$timeout} s"];

    $parsed = parse_whois($answer);
    return $parsed['found'] ? ['status' => 'found', 'expires' => $parsed['expires'], 'registrar' => $parsed['registrar']] : ['status' => 'not_found'];
}

/**
 * When the domain of $host is registered until: from RDAP where the
 * registry has it, else from WHOIS for the registries in WHOIS_SERVERS.
 * $cache keeps answers (and the bootstrap file) for one run, so sites that
 * share a domain cause one lookup.
 *
 * @return array{status: 'found'|'not_found'|'no_registry'|'no_domain'|'error', domain: string, expires: ?int, registrar: string, source: string, error: string}
 */
function lookup_domain(string $host, array $config, int $now, array &$cache = []): array
{
    $candidates = registrable_candidates($host);
    $result = fn (string $status, string $domain, array $more = []) => $more + ['status' => $status, 'domain' => $domain, 'expires' => null, 'registrar' => '', 'source' => '', 'error' => ''];
    if (!$candidates) return $result('no_domain', $host);
    if (isset($cache['domain:' . $candidates[0]])) return $cache['domain:' . $candidates[0]];

    $tld = substr((string) strrchr('.' . $candidates[0], '.'), 1);
    $whois = $config['whois_servers'][$tld] ?? null;
    if (!array_key_exists('bootstrap', $cache)) {
        $cache['bootstrap'] = load_rdap_bootstrap(dirname($config['db_path']) . '/rdap-dns.json', $config['rdap_bootstrap_url'], $config, $now);
    }
    $base = $cache['bootstrap'] ? rdap_base_url($cache['bootstrap'], $candidates[0]) : null;
    if ($base === null && $whois === null) {
        $found = $cache['bootstrap'] === null
            ? $result('error', $candidates[0], ['error' => 'the list of registries could not be loaded'])
            : $result('no_registry', $candidates[0]);
        return $cache['domain:' . $candidates[0]] = $found;
    }

    foreach ($candidates as $domain) {
        $answer = $base !== null ? query_rdap(rdap_base_url($cache['bootstrap'], $domain) ?? $base, $domain, $config) : query_whois($whois, $domain, $config['uptime_timeout']);
        if ($answer['status'] === 'not_found') continue;
        return $cache['domain:' . $candidates[0]] = $result($answer['status'], $domain, ['source' => $base !== null ? 'rdap' : 'whois'] + $answer);
    }
    return $cache['domain:' . $candidates[0]] = $result('not_found', $candidates[0]);
}

/**
 * What a lookup means, in plain words: ok, warn (expires within
 * $warnDays), fail (expired) or unknown (no domain, no registry Vaka can
 * read, or the lookup failed; `problem` is lookup_failed then, to retry).
 *
 * @return array{status: string, summary: string, details: array}
 */
function evaluate_domain(array $lookup, int $now, int $warnDays): array
{
    $domain = $lookup['domain'];
    $unknown = fn (string $summary, string $problem = '') => ['status' => 'unknown', 'summary' => $summary, 'details' => ['domain' => $domain, 'problem' => $problem]];
    $tld = substr((string) strrchr('.' . $domain, '.'), 1);

    if ($lookup['status'] === 'no_domain') return $unknown('No domain to look up: the site is reached by its address');
    if ($lookup['status'] === 'no_registry') return $unknown("The .{$tld} registry publishes no expiry dates that Vaka can read");
    if ($lookup['status'] === 'not_found') return $unknown("The registry does not know {$domain}");
    if ($lookup['status'] === 'error') return $unknown("Could not look up {$domain}: {$lookup['error']}", 'lookup_failed');
    if ($lookup['expires'] === null) return $unknown("The registry does not say when {$domain} expires");

    $date = date('j M Y', $lookup['expires']);
    $daysLeft = (int) floor(($lookup['expires'] - $now) / 86400);
    $details = [
        'domain' => $domain,
        'expires' => utc_now($lookup['expires']),
        'days_left' => $daysLeft,
        'registrar' => $lookup['registrar'],
        'source' => $lookup['source'],
        'problem' => '',
    ];

    if ($now >= $lookup['expires']) {
        return ['status' => 'fail', 'summary' => "{$domain} expired on {$date}", 'details' => ['problem' => 'domain_expired'] + $details];
    }
    if ($daysLeft <= $warnDays) {
        $when = match ($daysLeft) {
            0 => 'today',
            1 => 'tomorrow',
            default => "in {$daysLeft} days",
        };
        return ['status' => 'warn', 'summary' => "{$domain} expires {$when} ({$date})", 'details' => ['problem' => 'domain_expiring'] + $details];
    }
    $with = $lookup['registrar'] !== '' ? ", with {$lookup['registrar']}" : '';
    return ['status' => 'ok', 'summary' => "{$domain} is registered until {$date} ({$daysLeft} days){$with}", 'details' => $details];
}

/** Look up one URL's domain registration, for `php vaka check`. */
function check_domain(string $url, array $config, int $now): array
{
    return evaluate_domain(lookup_domain((string) parse_url($url, PHP_URL_HOST), $config, $now), $now, max([0, ...$config['domain_alert_days']]));
}

/**
 * The domain alert due for one site, if any: expired, renewed (after a
 * warning or after it expired), or expiring when it passes one of
 * $alertDays. Each threshold alerts once per registration period; a renewal
 * starts over. `thresholds` is the thresholds_sent value to store when it
 * is told.
 *
 * @return array{action: 'send'|'mark'|'none', kind: ?string, thresholds: string}
 */
function domain_notice(array $state, ?array $group, array $alertDays): array
{
    $status = $state['status'];
    $alerted = $state['alerted_status'];
    $details = $state['details'];
    $sent = json_decode($state['thresholds_sent'] ?: '[]', true) ?: [];
    $expires = $details['expires'] ?? null;
    $already = ($sent['expires'] ?? null) === $expires ? ($sent['sent'] ?? []) : [];
    $crossed = array_values(array_filter($alertDays, fn ($days) => isset($details['days_left']) && $details['days_left'] <= $days));
    $thresholds = json_encode(['expires' => $expires, 'sent' => array_values(array_unique([...$already, ...$crossed]))]);
    $new = array_diff($crossed, $already);
    $result = fn (string $action, ?string $kind = null) => ['action' => $action, 'kind' => $kind, 'thresholds' => $thresholds];

    if ($status === 'pending' || $status === 'unknown') return $result('none');
    if (in_array($group['policy'] ?? '', ['silent', 'digest'], true)) {
        return $status !== $alerted || $new ? $result('mark') : $result('none');
    }
    if ($alerted === 'pending' && $status === 'ok') return $result('mark');
    if ($status === 'fail') return $alerted !== 'fail' ? $result('send', 'domain_expired') : $result('none');
    if ($alerted === 'fail' || ($status === 'ok' && $alerted === 'warn')) return $result('send', 'domain_renewed');
    if ($new) return $result('send', 'domain_expiring');
    return $status !== $alerted ? $result('mark') : $result('none');
}
