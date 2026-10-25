<?php

/** How often a site's certificate is checked. */
const SSL_INTERVAL_SECONDS = 6 * 3600;

/**
 * Connect over TLS and capture the server's certificate: first verified the
 * way a browser would, and when that fails, again without verification so an
 * invalid certificate can still be described. $caFile replaces the system's
 * CA bundle (for internal authorities, and for tests).
 *
 * @return array{cert: ?OpenSSLCertificate, verified: bool, error: string}
 */
function fetch_certificate(string $host, int $port, int $timeout, string $caFile = ''): array
{
    $connect = function (bool $verify) use ($host, $port, $timeout, $caFile): array {
        $ssl = [
            'capture_peer_cert' => true,
            'verify_peer' => $verify,
            'verify_peer_name' => $verify,
            'allow_self_signed' => !$verify,
            'peer_name' => $host,
            'SNI_enabled' => true,
        ];
        if ($caFile !== '') $ssl['cafile'] = $caFile;
        $errors = [];
        set_error_handler(function (int $no, string $message) use (&$errors): bool {
            $errors[] = preg_replace('/^stream_socket_client\(\): /', '', $message);
            return true;
        });
        $target = 'ssl://' . (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) ? "[{$host}]" : $host) . ":{$port}";
        $stream = stream_socket_client($target, $errno, $errstr, $timeout, STREAM_CLIENT_CONNECT, stream_context_create(['ssl' => $ssl]));
        restore_error_handler();
        if (!$stream) return [null, trim(implode(' ', $errors) ?: $errstr)];
        $cert = stream_context_get_params($stream)['options']['ssl']['peer_certificate'] ?? null;
        fclose($stream);
        return [$cert, ''];
    };

    [$cert, $error] = $connect(true);
    if ($cert) return ['cert' => $cert, 'verified' => true, 'error' => ''];
    [$cert, $second] = $connect(false);
    return ['cert' => $cert, 'verified' => false, 'error' => $cert ? $error : ($second ?: $error)];
}

/**
 * What a certificate says: the name it was issued to, who issued it, the
 * names it covers and when it is valid.
 *
 * @return array{subject: string, issuer: string, names: list<string>, valid_from: int, valid_to: int, self_signed: bool}
 */
function certificate_info(OpenSSLCertificate|string $cert): array
{
    $info = openssl_x509_parse($cert);
    $names = [];
    foreach (explode(',', (string) ($info['extensions']['subjectAltName'] ?? '')) as $entry) {
        if (preg_match('/^\s*(?:DNS|IP Address):(.+)$/', $entry, $m)) $names[] = strtolower(trim($m[1]));
    }
    $subject = (string) ($info['subject']['CN'] ?? '');
    if (!$names && $subject !== '') $names[] = strtolower($subject);
    return [
        'subject' => $subject,
        // The organisation reads better than the intermediate's name ("Let's Encrypt", not "R10")
        'issuer' => (string) ($info['issuer']['O'] ?? $info['issuer']['CN'] ?? ''),
        'names' => $names,
        'valid_from' => (int) $info['validFrom_time_t'],
        'valid_to' => (int) $info['validTo_time_t'],
        'self_signed' => ($info['subject'] ?? []) == ($info['issuer'] ?? []),
    ];
}

/** Whether a certificate with $names covers $host. A wildcard covers exactly one label. */
function host_matches(string $host, array $names): bool
{
    $host = strtolower($host);
    foreach ($names as $name) {
        if ($name === $host) return true;
        if (str_starts_with($name, '*.') && substr_count($host, '.') === substr_count($name, '.') && str_ends_with($host, substr($name, 1))) {
            return true;
        }
    }
    return false;
}

/**
 * What a site's certificate means for its visitors, in plain words:
 * ok, warn (expires within $warnDays), fail (browsers reject it) or unknown
 * (no certificate could be read; the uptime check covers that).
 *
 * @return array{status: string, summary: string, details: array}
 */
function evaluate_ssl(string $host, array $fetched, ?array $info, int $now, int $warnDays): array
{
    if ($info === null) {
        return ['status' => 'unknown', 'summary' => 'Could not read the certificate: ' . ($fetched['error'] ?: 'no answer'), 'details' => ['problem' => 'unreachable']];
    }
    $date = date('j M Y', $info['valid_to']);
    $daysLeft = (int) floor(($info['valid_to'] - $now) / 86400);
    $details = [
        'valid_to' => utc_now($info['valid_to']),
        'days_left' => $daysLeft,
        'issuer' => $info['issuer'],
        'names' => $info['names'],
        'problem' => '',
    ];

    if (!$fetched['verified']) {
        [$problem, $summary] = match (true) {
            $now >= $info['valid_to'] => ['expired', "The certificate expired on {$date}"],
            $now < $info['valid_from'] => ['not_yet', 'The certificate is not valid until ' . date('j M Y', $info['valid_from'])],
            !host_matches($host, $info['names']) => ['name', 'The certificate is for ' . implode(', ', array_slice($info['names'], 0, 3)) . ", not {$host}"],
            $info['self_signed'] => ['self_signed', 'The certificate is self-signed, so browsers do not trust it'],
            default => ['untrusted', 'Browsers do not trust the certificate: the chain to a known authority is missing or broken'],
        };
        return ['status' => 'fail', 'summary' => $summary, 'details' => ['problem' => $problem] + $details];
    }

    if ($daysLeft <= $warnDays) {
        $when = match ($daysLeft) {
            0 => 'today',
            1 => 'tomorrow',
            default => "in {$daysLeft} days",
        };
        return ['status' => 'warn', 'summary' => "The certificate expires {$when} ({$date})", 'details' => ['problem' => 'expiring'] + $details];
    }
    return ['status' => 'ok', 'summary' => "Valid until {$date} ({$daysLeft} days), issued by {$info['issuer']}", 'details' => $details];
}

/** Check one URL's certificate. */
function check_certificate(string $url, array $config, int $now): array
{
    $host = trim((string) parse_url($url, PHP_URL_HOST), '[]');
    $port = (int) (parse_url($url, PHP_URL_PORT) ?: 443);
    $fetched = fetch_certificate($host, $port, $config['uptime_timeout'], $config['ssl_ca_file']);
    $info = $fetched['cert'] ? certificate_info($fetched['cert']) : null;
    return evaluate_ssl($host, $fetched, $info, $now, max([0, ...$config['ssl_alert_days']]));
}

/**
 * The certificate alert due for one site, if any: invalid, valid again,
 * renewed (after a warning), or expiring when it passes one of $alertDays.
 * Each threshold alerts once per certificate; a renewed certificate starts
 * over. `thresholds` is the thresholds_sent value to store when it is told.
 *
 * @return array{action: 'send'|'mark'|'none', kind: ?string, thresholds: string}
 */
function ssl_notice(array $state, ?array $group, array $alertDays): array
{
    $status = $state['status'];
    $alerted = $state['alerted_status'];
    $details = $state['details'];
    $sent = json_decode($state['thresholds_sent'] ?: '[]', true) ?: [];
    $validTo = $details['valid_to'] ?? null;
    $already = ($sent['valid_to'] ?? null) === $validTo ? ($sent['sent'] ?? []) : [];
    $crossed = array_values(array_filter($alertDays, fn ($days) => isset($details['days_left']) && $details['days_left'] <= $days));
    $thresholds = json_encode(['valid_to' => $validTo, 'sent' => array_values(array_unique([...$already, ...$crossed]))]);
    $new = array_diff($crossed, $already);
    $result = fn (string $action, ?string $kind = null) => ['action' => $action, 'kind' => $kind, 'thresholds' => $thresholds];

    if ($status === 'pending' || $status === 'unknown') return $result('none');
    if (in_array($group['policy'] ?? '', ['silent', 'digest'], true)) {
        return $status !== $alerted || $new ? $result('mark') : $result('none');
    }
    if ($alerted === 'pending' && $status === 'ok') return $result('mark');
    if ($status === 'fail') return $alerted !== 'fail' ? $result('send', 'ssl_invalid') : $result('none');
    if ($alerted === 'fail') return $result('send', 'ssl_fixed');
    if ($status === 'ok' && $alerted === 'warn') return $result('send', 'ssl_renewed');
    if ($new) return $result('send', 'ssl_expiring');
    return $status !== $alerted ? $result('mark') : $result('none');
}
