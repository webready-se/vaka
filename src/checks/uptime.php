<?php

/**
 * What one uptime request says about a site, in plain words. Up means a
 * status below 400 and, when a keyword is set, the keyword on the page.
 * `problem` names the kind of failure for advice_for(): timeout, dns,
 * refused, connect, certificate, tls, redirects, http, keyword or other.
 *
 * @return array{ok: bool, summary: string, status_code: int, ms: int, ip: string, cdn: string, problem: string}
 */
function evaluate_uptime(array $response, string $keyword, int $timeout): array
{
    $result = [
        'ok' => false,
        'summary' => '',
        'status_code' => $response['status'],
        'ms' => $response['ms'],
        'ip' => $response['ip'],
        'cdn' => detect_cdn($response['headers']),
        'problem' => '',
    ];

    if ($response['errno']) {
        $result['summary'] = connection_problem($response['errno'], $response['error'], $timeout);
        $result['problem'] = problem_kind($response['errno'], $response['error']);
        return $result;
    }

    $status = $response['status'];
    $answered = trim($status . ' ' . status_text($status));
    if ($status < 100 || $status >= 400) {
        $result['summary'] = "The site answered {$answered}";
        $result['problem'] = 'http';
    } elseif ($keyword !== '' && !page_contains($response['body'], $keyword)) {
        $result['summary'] = "Answered {$answered}, but the page does not contain \"{$keyword}\"";
        $result['problem'] = 'keyword';
    } else {
        $result['ok'] = true;
        $result['summary'] = "Answered {$answered} in {$response['ms']} ms";
    }
    return $result;
}

/** A curl error as something a site owner understands. */
function connection_problem(int $errno, string $error, int $timeout): string
{
    return match ($errno) {
        CURLE_OPERATION_TIMEDOUT => "No answer within {$timeout} s",
        CURLE_COULDNT_RESOLVE_HOST => 'The domain does not resolve (DNS lookup failed)',
        CURLE_COULDNT_CONNECT => str_contains(strtolower($error), 'refused') ? 'The server refused the connection' : 'Could not connect to the server',
        CURLE_SSL_CACERT, CURLE_SSL_PEER_CERTIFICATE, CURLE_SSL_CERTPROBLEM, CURLE_SSL_CACERT_BADFILE, 83, 90, 91 => "The certificate is not accepted: {$error}",
        CURLE_SSL_CONNECT_ERROR => "The secure connection failed: {$error}",
        CURLE_TOO_MANY_REDIRECTS => 'Too many redirects (more than 5)',
        default => "Could not reach the site: {$error}",
    };
}

/** The kind of a connection failure, matching connection_problem(). */
function problem_kind(int $errno, string $error): string
{
    return match ($errno) {
        CURLE_OPERATION_TIMEDOUT => 'timeout',
        CURLE_COULDNT_RESOLVE_HOST => 'dns',
        CURLE_COULDNT_CONNECT => str_contains(strtolower($error), 'refused') ? 'refused' : 'connect',
        CURLE_SSL_CACERT, CURLE_SSL_PEER_CERTIFICATE, CURLE_SSL_CERTPROBLEM, CURLE_SSL_CACERT_BADFILE, 83, 90, 91 => 'certificate',
        CURLE_SSL_CONNECT_ERROR => 'tls',
        CURLE_TOO_MANY_REDIRECTS => 'redirects',
        default => 'other',
    };
}

/** What to look at for a kind of problem, in words a site owner understands. Empty when there is nothing useful to say. */
function advice_for(string $problem, int $status): string
{
    if ($problem === 'http') {
        return match (true) {
            $status >= 500 => 'The site itself answers with an error. Look at its error log, or at what changed in the last deploy.',
            $status === 404 || $status === 410 => 'The page is not there. Was the address changed, or did a deploy remove it?',
            $status === 401 || $status === 403 => 'The site refuses Vaka. Check access rules, a firewall or a password on the site.',
            $status === 429 => 'The site is limiting how often it may be asked. Allow Vaka\'s requests, or check the site less often.',
            default => 'The site answers with an error code instead of the page.',
        };
    }
    return match ($problem) {
        'keyword' => 'The page loads, but the text Vaka looks for is missing. The page may show an error message, or the text has changed.',
        'timeout' => 'No answer in time. The server may be down or overloaded: check it, or ask the host.',
        'dns' => 'The domain does not resolve. Check its DNS records, and that the domain is still registered.',
        'refused' => 'Nothing answers on the server. Is the web server running?',
        'connect' => 'Vaka cannot reach the server. It may be down, or a firewall is in the way.',
        'certificate' => 'Browsers will refuse the site too. Renew or fix its SSL certificate.',
        'tls' => 'The secure connection fails. Check the server\'s SSL setup.',
        'redirects' => 'The site redirects in a loop. Check the redirect rules, for example between www and https.',
        'expired', 'expiring' => 'Renew the certificate. With Let\'s Encrypt, check that the automatic renewal runs (certbot, or the setting at your host).',
        'name' => 'The certificate does not cover this domain. Issue one that includes it.',
        'self_signed' => 'Use a certificate from a trusted authority, such as Let\'s Encrypt (free).',
        'untrusted' => 'Install the full chain: the certificate together with its intermediate certificates.',
        'not_yet' => 'The certificate is not valid yet. Check its start date, and the server\'s clock.',
        'exposed' => 'Remove the files from the web root, or block them in the web server. Then change every password and key they held: assume someone has read them.',
        'exposed_minor' => 'Remove the files from the web root, or block them in the web server. They hold no secrets, but they tell attackers which software and versions the site runs.',
        default => '',
    };
}

/** The reason phrase for the status codes a monitored site is likely to answer. */
function status_text(int $code): string
{
    return [
        200 => 'OK', 201 => 'Created', 202 => 'Accepted', 203 => 'Non-Authoritative Information', 204 => 'No Content',
        301 => 'Moved Permanently', 302 => 'Found', 303 => 'See Other', 304 => 'Not Modified', 307 => 'Temporary Redirect', 308 => 'Permanent Redirect',
        400 => 'Bad Request', 401 => 'Unauthorized', 403 => 'Forbidden', 404 => 'Not Found', 405 => 'Method Not Allowed',
        408 => 'Request Timeout', 410 => 'Gone', 429 => 'Too Many Requests',
        500 => 'Internal Server Error', 501 => 'Not Implemented', 502 => 'Bad Gateway', 503 => 'Service Unavailable', 504 => 'Gateway Timeout',
        520 => 'Web Server Returned an Unknown Error', 521 => 'Web Server Is Down', 522 => 'Connection Timed Out', 523 => 'Origin Is Unreachable', 524 => 'A Timeout Occurred',
    ][$code] ?? '';
}

/** Whether the page shows $keyword: any letter case, HTML entities decoded. */
function page_contains(string $body, string $keyword): bool
{
    $text = html_entity_decode($body, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $find = function_exists('mb_stripos') ? 'mb_stripos' : 'stripos';
    return $find($text, $keyword) !== false || $find($body, $keyword) !== false;
}

/**
 * The CDN in front of a site, from its response headers, or ''. Sites behind
 * a CDN answer on the CDN's shared IPs, so they are not grouped by server.
 */
function detect_cdn(array $headers): string
{
    $server = strtolower(implode(' ', $headers['server'] ?? []));
    if (isset($headers['cf-ray']) || str_contains($server, 'cloudflare')) return 'Cloudflare';
    if (isset($headers['x-amz-cf-id']) || isset($headers['x-amz-cf-pop'])) return 'CloudFront';
    if (isset($headers['x-fastly-request-id']) || preg_match('/\bcache-[a-z0-9-]+/', strtolower(implode(' ', $headers['x-served-by'] ?? [])))) return 'Fastly';
    if (str_contains($server, 'akamai')) return 'Akamai';
    if (str_contains($server, 'bunnycdn')) return 'Bunny';
    return '';
}

/**
 * The uptime state after one more result. A site is down after $downAfter
 * failures in a row, counted from the first of them; until then it keeps
 * its status and is checked again soon. Returns the new state and the event
 * to record, if the status changed.
 *
 * @return array{state: array, event: ?array{status: string, previous: string, message: string}}
 */
function next_uptime_state(array $state, array $result, int $downAfter, string $now): array
{
    $previous = $state['status'];
    $details = $state['details'] ?? [];
    $next = [
        'status' => $previous,
        'summary' => $result['summary'],
        'fail_count' => 0,
        'changed_at' => $state['changed_at'],
        'details' => ['status_code' => $result['status_code'], 'ms' => $result['ms'], 'ip' => $result['ip'], 'cdn' => $result['cdn'], 'problem' => $result['problem']],
    ];

    if ($result['ok']) {
        $next['status'] = 'ok';
        if ($previous === 'ok') return ['state' => $next, 'event' => null];

        $next['changed_at'] = $now;
        $message = 'First check: ';
        if ($previous === 'fail') {
            $next['details']['down_seconds'] = strtotime($now) - strtotime($details['failing_since'] ?? $state['changed_at']);
            $message = 'Up again after ' . human_duration($next['details']['down_seconds']) . ': ';
        }
        return ['state' => $next, 'event' => ['status' => 'ok', 'previous' => $previous, 'message' => $message . lcfirst($result['summary'])]];
    }

    $next['fail_count'] = $state['fail_count'] + 1;
    $next['details']['failing_since'] = $details['failing_since'] ?? $now;

    if ($next['fail_count'] < $downAfter) {
        $failed = match (true) {
            $next['fail_count'] > 1 => "Failed {$next['fail_count']} times",
            $previous === 'pending' => 'First check failed',
            default => 'Failed once',
        };
        $next['summary'] = "{$failed}, checking again: " . lcfirst($result['summary']);
        return ['state' => $next, 'event' => null];
    }

    $next['status'] = 'fail';
    if ($previous === 'fail') return ['state' => $next, 'event' => null];

    $next['changed_at'] = $next['details']['failing_since'];
    return ['state' => $next, 'event' => ['status' => 'fail', 'previous' => $previous, 'message' => 'Down: ' . lcfirst($result['summary'])]];
}

/** A length of time the way a person would say it. */
function human_duration(int $seconds): string
{
    if ($seconds < 60) return 'less than a minute';
    $minutes = intdiv($seconds, 60);
    if ($minutes < 60) return "{$minutes} min";
    $hours = intdiv($minutes, 60);
    if ($hours < 24) return "{$hours} h" . ($minutes % 60 ? ' ' . ($minutes % 60) . ' min' : '');
    $days = intdiv($hours, 24);
    return plural($days, 'day', 'days') . ($hours % 24 ? ' ' . ($hours % 24) . ' h' : '');
}
