<?php

/** How often a site's security headers are graded; a change is confirmed by a second look after HEADERS_CONFIRM_SECONDS. */
const HEADERS_INTERVAL_SECONDS = 6 * 3600;
const HEADERS_CONFIRM_SECONDS = 15 * 60;
const HEADERS_RETRY_SECONDS = 3600;

/** Sites fetched together in one batch; the run budget is checked between batches. */
const HEADERS_SITES_PER_BATCH = 20;

/** HSTS shorter than this (six months) is weak. */
const HSTS_MIN_SECONDS = 15552000;

/** The protections that are graded, in the order they are shown: what each does, the header that adds it, and a tip. */
const SECURITY_HEADERS = [
    'hsts' => ['label' => 'HSTS', 'what' => 'Makes browsers always use https for the site', 'fix' => 'Strict-Transport-Security: max-age=31536000', 'tip' => 'Add it once every page works over https.'],
    'csp' => ['label' => 'CSP', 'what' => 'Limits which scripts may run: the main defence against injected scripts', 'fix' => "Content-Security-Policy: default-src 'self'", 'tip' => 'Try it as Content-Security-Policy-Report-Only first, to see what it would block.'],
    'nosniff' => ['label' => 'X-Content-Type-Options', 'what' => 'Stops browsers from guessing what a file is', 'fix' => 'X-Content-Type-Options: nosniff', 'tip' => ''],
    'frames' => ['label' => 'frame protection', 'what' => 'Stops other sites from showing this one in a frame to trick visitors into clicking', 'fix' => 'X-Frame-Options: SAMEORIGIN', 'tip' => "Or frame-ancestors 'self' in the CSP."],
    'referrer' => ['label' => 'Referrer-Policy', 'what' => 'Keeps full addresses, with search terms and tokens, from leaking to other sites', 'fix' => 'Referrer-Policy: strict-origin-when-cross-origin', 'tip' => ''],
    'permissions' => ['label' => 'Permissions-Policy', 'what' => 'Turns off browser features the site does not use, such as the camera and microphone', 'fix' => 'Permissions-Policy: camera=(), microphone=(), geolocation=()', 'tip' => ''],
];

/** How strong each level is, to tell worse from better. */
const HEADER_LEVELS = ['missing' => 0, 'weak' => 1, 'good' => 2];

/** A Content-Security-Policy as directive => sources, lowercased. */
function csp_directives(string $policy): array
{
    $directives = [];
    foreach (explode(';', strtolower($policy)) as $part) {
        $words = preg_split('/\s+/', trim($part), -1, PREG_SPLIT_NO_EMPTY);
        if ($words) $directives[array_shift($words)] ??= $words;
    }
    return $directives;
}

/**
 * How well one protection is in place, from a response's headers (lowercase
 * name => list of values): good, weak or missing, and why in a few words.
 *
 * @return array{level: string, note: string}
 */
function header_level(string $key, array $headers, bool $https): array
{
    $result = fn (string $level, string $note = '') => ['level' => $level, 'note' => $note];
    $first = fn (string $name) => isset($headers[$name]) ? trim((string) $headers[$name][0]) : null;
    $policies = array_map('csp_directives', $headers['content-security-policy'] ?? []);

    switch ($key) {
        case 'hsts':
            if (!$https) return $result('missing', 'the site does not use https');
            $value = $first('strict-transport-security');
            if ($value === null) return $result('missing');
            if (!preg_match('/max-age\s*=\s*"?(\d+)/i', $value, $m)) return $result('missing', 'it has no max-age');
            $seconds = (int) $m[1];
            if ($seconds === 0) return $result('missing', 'max-age=0 turns it off');
            if ($seconds < HSTS_MIN_SECONDS) return $result('weak', 'it lasts only ' . plural(intdiv($seconds, 86400), 'day', 'days') . '; six months or more is advised');
            return $result('good');

        case 'csp':
            if (!$policies) {
                return isset($headers['content-security-policy-report-only']) ? $result('missing', 'only as Report-Only, which blocks nothing') : $result('missing');
            }
            // Several policies all apply, so the strictest one counts
            $best = null;
            foreach ($policies as $directives) {
                $level = csp_script_level($directives);
                if ($best === null || HEADER_LEVELS[$level['level']] > HEADER_LEVELS[$best['level']]) $best = $level;
            }
            return $best;

        case 'nosniff':
            $value = $first('x-content-type-options');
            if ($value === null) return $result('missing');
            return strtolower($value) === 'nosniff' ? $result('good') : $result('missing', 'it should be nosniff');

        case 'frames':
            foreach ($policies as $directives) {
                if (isset($directives['frame-ancestors'])) {
                    return in_array('*', $directives['frame-ancestors'], true) ? $result('weak', 'frame-ancestors lets any site frame it') : $result('good');
                }
            }
            $values = array_map('strtolower', array_map('trim', explode(',', implode(',', $headers['x-frame-options'] ?? []))));
            if ($values === [''] || !$values) return $result('missing');
            if (array_diff($values, ['deny', 'sameorigin']) === []) return $result('good');
            return str_starts_with($values[0], 'allow-from') ? $result('missing', 'browsers ignore ALLOW-FROM') : $result('missing', 'browsers do not know "' . $values[0] . '"');

        case 'referrer':
            if (!isset($headers['referrer-policy'])) return $result('missing');
            // Browsers use the last value they know, so a list can carry fallbacks
            $tokens = array_map('strtolower', array_map('trim', explode(',', implode(',', $headers['referrer-policy']))));
            $known = array_values(array_intersect($tokens, ['no-referrer', 'same-origin', 'strict-origin', 'strict-origin-when-cross-origin', 'origin', 'origin-when-cross-origin', 'unsafe-url', 'no-referrer-when-downgrade']));
            if (!$known) return $result('missing', 'browsers do not know "' . end($tokens) . '"');
            $policy = end($known);
            return in_array($policy, ['unsafe-url', 'no-referrer-when-downgrade'], true) ? $result('weak', "{$policy} sends full addresses to other sites") : $result('good');

        case 'permissions':
            if (($first('permissions-policy') ?? '') !== '') return $result('good');
            return ($first('feature-policy') ?? '') !== '' ? $result('weak', 'only the old Feature-Policy header') : $result('missing');
    }
    throw new InvalidArgumentException("Unknown header check {$key}");
}

/** How well one CSP limits scripts: weak when it lets inline scripts, eval or any host run. */
function csp_script_level(array $directives): array
{
    $sources = $directives['script-src'] ?? $directives['default-src'] ?? null;
    if ($sources === null) return ['level' => 'weak', 'note' => 'it does not limit scripts'];
    // With a nonce, a hash or strict-dynamic, browsers ignore 'unsafe-inline'
    $pinned = (bool) array_filter($sources, fn ($source) => preg_match("/^'(?:nonce-|sha(?:256|384|512)-|strict-dynamic')/", $source));
    if (in_array("'unsafe-inline'", $sources, true) && !$pinned) return ['level' => 'weak', 'note' => "it allows inline scripts ('unsafe-inline')"];
    if (in_array("'unsafe-eval'", $sources, true)) return ['level' => 'weak', 'note' => "it allows eval ('unsafe-eval')"];
    if (array_intersect($sources, ['*', 'http:', 'https:', 'data:'])) return ['level' => 'weak', 'note' => 'it allows scripts from anywhere'];
    return ['level' => 'good', 'note' => ''];
}

/**
 * The grade for a set of levels: one letter per protection in place (6 A,
 * 5 B, 4 C, 3 D, 2 E, fewer F), and A only when none of them is weak.
 */
function headers_grade(array $levels): string
{
    $inPlace = count(array_filter($levels, fn ($level) => $level !== 'missing'));
    if ($inPlace >= count(SECURITY_HEADERS)) return in_array('weak', $levels, true) ? 'B' : 'A';
    return [5 => 'B', 4 => 'C', 3 => 'D', 2 => 'E'][$inPlace] ?? 'F';
}

/**
 * A site's security headers graded, in plain words: ok (A or B), warn
 * (C to F) or unknown (no answer, or an error page, which says nothing
 * about the site's own headers).
 *
 * @return array{status: string, summary: string, details: array}
 */
function evaluate_headers(array $response, int $timeout): array
{
    if ($response['errno']) {
        return ['status' => 'unknown', 'summary' => 'Could not check: ' . lcfirst(connection_problem($response['errno'], $response['error'], $timeout)), 'details' => []];
    }
    if ($response['status'] >= 400) {
        return ['status' => 'unknown', 'summary' => 'Could not check: the site answered ' . trim($response['status'] . ' ' . status_text($response['status'])), 'details' => []];
    }

    $https = str_starts_with($response['url'], 'https://');
    $headers = [];
    foreach (array_keys(SECURITY_HEADERS) as $key) $headers[$key] = header_level($key, $response['headers'], $https);
    $grade = headers_grade(array_column($headers, 'level'));

    $labels = fn (string $level) => array_values(array_map(fn ($key) => SECURITY_HEADERS[$key]['label'], array_keys(array_filter($headers, fn ($header) => $header['level'] === $level))));
    $missing = $labels('missing');
    $weak = $labels('weak');
    $total = count(SECURITY_HEADERS);
    $summary = "Grade {$grade}: " . match (count($missing)) {
        0 => "all {$total} in place",
        $total => "none of the {$total} in place",
        default => ($total - count($missing)) . " of {$total} in place, missing " . list_words($missing),
    };
    if ($weak) $summary .= ', ' . list_words($weak) . (count($weak) === 1 ? ' is weak' : ' are weak');

    return ['status' => in_array($grade, ['A', 'B'], true) ? 'ok' : 'warn', 'summary' => $summary, 'details' => ['grade' => $grade, 'headers' => $headers]];
}

/**
 * Confirm a change before it counts: a result whose levels differ from the
 * last confirmed ones is looked at again after HEADERS_CONFIRM_SECONDS, and
 * counts only when that look agrees (a CDN node or a deploy halfway through
 * can answer differently once). Returns the details to store, with the
 * `confirmed` levels, when to check next, and the change to record.
 *
 * @return array{details: array, wait: int, change: ?string}
 */
function confirm_headers(?array $before, array $result): array
{
    $confirmed = $before['confirmed'] ?? null;
    if ($result['status'] === 'unknown') {
        return ['details' => $result['details'] + ['confirmed' => $confirmed], 'wait' => HEADERS_RETRY_SECONDS, 'change' => null];
    }

    $levels = array_map(fn ($header) => $header['level'], $result['details']['headers']);
    $last = isset($before['headers']) ? array_map(fn ($header) => $header['level'], $before['headers']) : null;
    $counts = fn (?string $change) => ['details' => $result['details'] + ['confirmed' => $levels], 'wait' => HEADERS_INTERVAL_SECONDS, 'change' => $change];

    if ($confirmed === null) return $counts('First check: ' . lcfirst($result['summary']));
    if ($levels === $confirmed) return $counts(null);
    if (($before['unconfirmed'] ?? false) && $levels === $last) return $counts(headers_change($confirmed, $levels));
    return ['details' => $result['details'] + ['confirmed' => $confirmed, 'unconfirmed' => true], 'wait' => HEADERS_CONFIRM_SECONDS, 'change' => null];
}

/** What changed between two sets of levels: "Grade B to C: HSTS removed, CSP weaker". */
function headers_change(array $from, array $to): string
{
    $parts = [];
    foreach (SECURITY_HEADERS as $key => $header) {
        $before = $from[$key] ?? 'missing';
        $after = $to[$key] ?? 'missing';
        if ($before === $after) continue;
        $parts[] = $header['label'] . ' ' . match (true) {
            $after === 'missing' => 'removed',
            $before === 'missing' => 'added',
            HEADER_LEVELS[$after] < HEADER_LEVELS[$before] => 'weaker',
            default => 'stronger',
        };
    }
    $old = headers_grade($from);
    $new = headers_grade($to);
    return $old !== $new ? "Grade {$old} to {$new}: " . implode(', ', $parts) : ucfirst(implode(', ', $parts)) . " (grade still {$new})";
}

/**
 * Every protection with what it does, how well it is in place and how to
 * add it, for the site detail. Empty when the site could not be graded.
 *
 * @return list<array{key: string, label: string, what: string, fix: string, tip: string, level: string, note: string}>
 */
function header_items(array $details): array
{
    if (!isset($details['headers'])) return [];
    $items = [];
    foreach (SECURITY_HEADERS as $key => $header) {
        $items[] = ['key' => $key] + $header + ($details['headers'][$key] ?? ['level' => 'missing', 'note' => '']);
    }
    return $items;
}

/**
 * The alert due for a site's security headers, if any. Only a protection
 * that is removed or weakened compared with what was last told alerts; a
 * first grade and improvements are noted without a message, so a low grade
 * never alerts by itself. `snapshot` is the levels to store as told.
 *
 * @return array{action: 'send'|'mark'|'none', kind: ?string, snapshot: string, worse: list<array{label: string, change: string, note: string}>, from: ?string, to: ?string}
 */
function headers_notice(array $state, ?array $group): array
{
    $levels = $state['details']['confirmed'] ?? null;
    $told = json_decode($state['alerted_snapshot'] ?: '[]', true) ?: [];
    $worse = [];
    foreach ($levels ?? [] as $key => $level) {
        if (!isset($told[$key]) || HEADER_LEVELS[$level] >= HEADER_LEVELS[$told[$key]]) continue;
        $worse[] = ['label' => SECURITY_HEADERS[$key]['label'], 'change' => $level === 'missing' ? 'removed' : 'weaker', 'note' => $state['details']['headers'][$key]['note'] ?? ''];
    }
    $result = fn (string $action, ?string $kind = null) => [
        'action' => $action,
        'kind' => $kind,
        'snapshot' => json_encode($levels),
        'worse' => $worse,
        'from' => $told ? headers_grade($told) : null,
        'to' => $levels ? headers_grade($levels) : null,
    ];

    if (in_array($state['status'], ['pending', 'unknown'], true) || !$levels || $levels == $told) return $result('none');
    if (!$told) return $result('mark');
    if (in_array($group['policy'] ?? '', ['silent', 'digest'], true)) return $result('mark');
    return $worse ? $result('send', 'headers_worse') : $result('mark');
}
