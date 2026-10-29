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
    'csp' => ['label' => 'CSP', 'what' => 'Limits which scripts may run: the main defence against injected scripts', 'fix' => "Content-Security-Policy-Report-Only: default-src 'self'", 'tip' => "Report-Only blocks nothing, so it is safe to try: open a few pages with the browser's console open, and it lists what the policy would block. Allow what the site needs (another host's scripts go in script-src, for example), then rename the header to Content-Security-Policy."],
    'nosniff' => ['label' => 'X-Content-Type-Options', 'what' => 'Stops browsers from guessing what a file is', 'fix' => 'X-Content-Type-Options: nosniff', 'tip' => ''],
    'frames' => ['label' => 'frame protection', 'what' => 'Stops other sites from showing this one in a frame to trick visitors into clicking', 'fix' => 'X-Frame-Options: SAMEORIGIN', 'tip' => "Or frame-ancestors 'self' in the CSP."],
    'referrer' => ['label' => 'Referrer-Policy', 'what' => 'Keeps full addresses, with search terms and tokens, from leaking to other sites', 'fix' => 'Referrer-Policy: strict-origin-when-cross-origin', 'tip' => ''],
    'permissions' => ['label' => 'Permissions-Policy', 'what' => 'Turns off browser features the site does not use, such as the camera and microphone', 'fix' => 'Permissions-Policy: camera=(), microphone=(), geolocation=()', 'tip' => ''],
];

/** A CSP sent only as Report-Only: tried, not enforced. */
const CSP_REPORT_ONLY_NOTE = 'only as Report-Only, which blocks nothing';

/** How strong each level is, to tell worse from better. */
const HEADER_LEVELS = ['missing' => 0, 'weak' => 1, 'good' => 2];

/** Of each file a page loads, only the start is read: the headers are what counts. */
const FILES_MAX_BYTES = 1024;

/** Why scripts and styles can lack nosniff when the page has it, and where the line goes. */
// Once for all six, since adding them in nginx has the same two traps whichever is missing
const HEADERS_NGINX_TIP = 'Adding them in nginx? Put the add_header lines in the server block, with the always flag, so error pages get them too. A location with an add_header of its own drops all of the server block\'s: keep add_header out of locations, or repeat them all in every such location.';

const FILES_NOSNIFF_TIP ='A web server rule for these files that adds headers of its own, such as Cache-Control, drops the ones set for the whole site (in nginx, a location with its own add_header). Set the headers once for the whole site and keep add_header out of such rules: for caching, nginx\'s expires sets Cache-Control without it. Otherwise every such rule has to repeat every header.';

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
                return isset($headers['content-security-policy-report-only']) ? $result('missing', CSP_REPORT_ONLY_NOTE) : $result('missing');
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
    $levels = shown_levels($levels);
    $inPlace = count(array_filter($levels, fn ($level) => $level !== 'missing'));
    if ($inPlace >= count(SECURITY_HEADERS)) return in_array('weak', $levels, true) ? 'B' : 'A';
    return [5 => 'B', 4 => 'C', 3 => 'D', 2 => 'E'][$inPlace] ?? 'F';
}

/**
 * The levels to compare and store for a graded result: one per protection,
 * as the page has it, plus `nosniff_files` (good or missing) when the
 * scripts and styles it loads were checked. Kept apart from the page's own
 * nosniff, so that first checking the files is a baseline, not a change.
 */
function header_levels(array $details): array
{
    $levels = array_map(fn ($header) => $header['level'], $details['headers'] ?? []);
    if (isset($details['files'])) $levels['nosniff_files'] = $details['files']['missing'] ? 'missing' : 'good';
    return $levels;
}

/** The levels as shown and graded: nosniff is weak when the page has it but its scripts and styles do not. */
function shown_levels(array $levels): array
{
    if (array_is_list($levels)) return $levels;
    $shown = array_intersect_key($levels, SECURITY_HEADERS);
    if (($levels['nosniff_files'] ?? null) === 'missing' && ($shown['nosniff'] ?? null) === 'good') $shown['nosniff'] = 'weak';
    return $shown;
}

/**
 * The files a page loads that are checked for nosniff: its first stylesheet
 * and its first script on the site's own server (see own_host(); a file on
 * a CDN is someone else's). Absolute URLs.
 *
 * @return list<string>
 */
function page_files(string $html, string $pageUrl): array
{
    $found = ['stylesheet' => null, 'script' => null];
    preg_match_all('/<(link|script)\b([^>]*)>/i', $html, $tags, PREG_SET_ORDER);
    foreach ($tags as [, $tag, $source]) {
        $attributes = html_attributes($source);
        if (strtolower($tag) === 'link') {
            $rel = preg_split('/\s+/', strtolower($attributes['rel'] ?? ''), -1, PREG_SPLIT_NO_EMPTY);
            // An alternate stylesheet is not loaded until a visitor picks it
            if (!in_array('stylesheet', $rel, true) || in_array('alternate', $rel, true)) continue;
            [$kind, $ref] = ['stylesheet', $attributes['href'] ?? ''];
        } else {
            [$kind, $ref] = ['script', $attributes['src'] ?? ''];
        }
        if ($found[$kind] !== null) continue;
        $url = resolve_url($ref, $pageUrl);
        if ($url !== null && own_host($url, $pageUrl)) $found[$kind] = $url;
    }
    return array_values(array_filter($found));
}

/**
 * What a page has that a CSP of default-src 'self' would block: the inline
 * scripts that would run (not a data block such as JSON-LD, nor an empty
 * one) and the hosts of scripts from anywhere but the page's own host (a
 * host under it too, unlike own_host(): 'self' is the page's host only).
 * Read from the page's HTML, so scripts it adds later are not seen.
 *
 * @return array{inline: int, hosts: list<string>}
 */
function page_scripts(string $html, string $pageUrl): array
{
    preg_match_all('/<script\b([^>]*)>(.*?)<\/script\s*>/is', (string) preg_replace('/<!--.*?-->/s', '', $html), $scripts, PREG_SET_ORDER);
    $inline = 0;
    $hosts = [];
    foreach ($scripts as [, $source, $body]) {
        $attributes = html_attributes($source);
        if (isset($attributes['src'])) {
            $url = resolve_url($attributes['src'], $pageUrl);
            if ($url !== null && url_host($url) !== url_host($pageUrl)) $hosts[url_host($url)] = true;
        } elseif ($body !== '' && preg_match('/^(?:|module|importmap|speculationrules|.*(?:java|ecma)script.*)$/', strtolower(trim($attributes['type'] ?? '')))) {
            $inline++;
        }
    }
    return ['inline' => $inline, 'hosts' => array_keys($hosts)];
}

/** An HTML tag's attributes, name (lowercase) => value with entities decoded; the first of a name counts, as in browsers. */
function html_attributes(string $source): array
{
    preg_match_all('/([^\s=\/>"\']+)(?:\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s>"\']+)))?/', $source, $matches, PREG_SET_ORDER);
    $attributes = [];
    foreach ($matches as $match) {
        $attributes[strtolower($match[1])] ??= html_entity_decode(($match[2] ?? '') . ($match[3] ?? '') . ($match[4] ?? ''), ENT_QUOTES | ENT_HTML5);
    }
    return $attributes;
}

/** A link on a page as an absolute http(s) URL without its fragment, or null for anything else (data:, javascript:, empty). */
function resolve_url(string $ref, string $base): ?string
{
    $ref = trim((string) preg_replace('/#.*$/s', '', $ref));
    if ($ref === '') return null;
    if (preg_match('#^[a-z][a-z0-9+.-]*:#i', $ref)) return preg_match('#^https?://#i', $ref) ? $ref : null;
    $parts = parse_url($base);
    if (!isset($parts['scheme'], $parts['host'])) return null;
    if (str_starts_with($ref, '//')) return $parts['scheme'] . ':' . $ref;

    $origin = $parts['scheme'] . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');
    $basePath = $parts['path'] ?? '/';
    if (str_starts_with($ref, '?')) return $origin . $basePath . $ref;
    [$path, $query] = array_pad(explode('?', str_starts_with($ref, '/') ? $ref : preg_replace('#/[^/]*$#', '/', $basePath) . $ref, 2), 2, null);
    $segments = [];
    foreach (explode('/', $path) as $segment) {
        if ($segment === '..') {
            if (count($segments) > 1) array_pop($segments);
        } elseif ($segment !== '.') {
            $segments[] = $segment;
        }
    }
    return $origin . implode('/', $segments) . ($query !== null ? "?{$query}" : '');
}

/** A URL's host and port, lowercase. */
function url_host(string $url): string
{
    $parts = parse_url($url);
    return strtolower(($parts['host'] ?? '') . (isset($parts['port']) ? ':' . $parts['port'] : ''));
}

/**
 * Whether a file is on the site's own server: the page's host and port, or
 * a host under the page's (without www.), such as static.shop.example for
 * shop.example or www.shop.example. Never wider than the page's own tree,
 * so no list of public suffixes is needed; a sibling (static. for a page on
 * app.) and anything by IP count only on the very same host.
 */
function own_host(string $url, string $pageUrl): bool
{
    if (url_host($url) === url_host($pageUrl)) return true;
    $file = parse_url($url);
    $page = parse_url($pageUrl);
    if (($file['port'] ?? null) !== ($page['port'] ?? null)) return false;
    $base = (string) preg_replace('/^www\./', '', strtolower($page['host'] ?? ''));
    if ($base === '' || filter_var($base, FILTER_VALIDATE_IP) !== false) return false;
    $host = strtolower($file['host'] ?? '');
    return $host === $base || str_ends_with($host, '.' . $base);
}

/**
 * Whether the files a page loads say nosniff too, from their responses (by
 * the URL asked for): the paths checked, and those without it. Only a file
 * that answered 200 on the same host with something other than a page
 * counts: a missing file can end up at the site's own error or fallback
 * page, with the app's headers. A file is named by its path, with its host
 * when that is not the page's ($pageUrl). Null when no file could tell.
 *
 * @return ?array{checked: list<string>, missing: list<string>}
 */
function files_nosniff(array $responses, string $pageUrl = ''): ?array
{
    $checked = $missing = [];
    foreach ($responses as $url => $response) {
        if ($response['errno'] || $response['status'] !== 200 || url_host($response['url']) !== url_host($url)) continue;
        $type = strtolower(trim(explode(';', $response['headers']['content-type'][0] ?? '')[0]));
        if ($type === 'text/html') continue;
        $name = ($pageUrl !== '' && url_host($url) !== url_host($pageUrl) ? url_host($url) : '') . parse_url($url, PHP_URL_PATH);
        $path = (string) preg_replace('/^(.{119}).+$/us', '$1…', $name);
        $checked[] = $path;
        if (header_level('nosniff', $response['headers'], false)['level'] !== 'good') $missing[] = $path;
    }
    return $checked ? ['checked' => $checked, 'missing' => $missing] : null;
}

/**
 * Fetch the files that pages load (see page_files()) and tell, per page,
 * whether they say nosniff too (see files_nosniff()). A page without nosniff
 * of its own is left out: its files add nothing to what it already shows.
 *
 * @param array<array-key, array> $pages fetch_many() responses
 * @return array<array-key, ?array> by the pages' keys
 */
function fetch_files_nosniff(array $pages, array $config): array
{
    $urls = [];
    foreach ($pages as $key => $page) {
        if ($page['errno'] || $page['status'] >= 400 || header_level('nosniff', $page['headers'], false)['level'] !== 'good') continue;
        foreach (page_files($page['body'], $page['url']) as $i => $url) $urls["{$key}\n{$i}"] = $url;
    }
    if (!$urls) return [];

    $responses = fetch_many($urls, $config['uptime_timeout'], user_agent($config), 20, $config['ssl_ca_file'], FILES_MAX_BYTES);
    // In the page's order (stylesheet, then script), not the order the answers came in
    $byPage = [];
    foreach ($urls as $id => $url) $byPage[explode("\n", (string) $id, 2)[0]][$url] = $responses[$id];
    $results = [];
    foreach ($byPage as $key => $files) $results[$key] = files_nosniff($files, $pages[$key]['url']);
    return $results;
}

/**
 * A site's security headers graded, in plain words: ok (A or B), warn
 * (C to F) or unknown (no answer, or an error page, which says nothing
 * about the site's own headers). $files is what files_nosniff() found for
 * the scripts and styles the page loads; it counts only when the page
 * itself has nosniff.
 *
 * @return array{status: string, summary: string, details: array}
 */
function evaluate_headers(array $response, int $timeout, ?array $files = null): array
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
    $details = ['headers' => $headers];
    if ($files !== null && $headers['nosniff']['level'] === 'good') $details['files'] = $files;
    // For the advice only, never the grade: what a first CSP would block on the page
    if ($headers['csp']['level'] === 'missing') $details['scripts'] = page_scripts($response['body'] ?? '', $response['url']);
    $levels = header_levels($details);
    $grade = headers_grade($levels);

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
    if (($levels['nosniff_files'] ?? null) === 'missing') $summary .= ', X-Content-Type-Options is missing on scripts and styles';

    return ['status' => in_array($grade, ['A', 'B'], true) ? 'ok' : 'warn', 'summary' => $summary, 'details' => ['grade' => $grade] + $details];
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

    $levels = header_levels($result['details']);
    $last = isset($before['headers']) ? header_levels($before) : null;
    $counts = fn (?string $change) => ['details' => $result['details'] + ['confirmed' => $levels], 'wait' => HEADERS_INTERVAL_SECONDS, 'change' => $change];

    if ($confirmed === null) return $counts('First check: ' . lcfirst($result['summary']));
    if ($levels === $confirmed) return $counts(null);
    // A change with nothing to tell (the files checked for the first time, and fine) is taken quietly
    if (($before['unconfirmed'] ?? false) && $levels === $last) return $counts(headers_change($confirmed, $levels) ?: null);
    return ['details' => $result['details'] + ['confirmed' => $confirmed, 'unconfirmed' => true], 'wait' => HEADERS_CONFIRM_SECONDS, 'change' => null];
}

/**
 * What changed between two sets of levels: "Grade B to C: HSTS removed, CSP
 * weaker". Empty when nothing is worth telling.
 */
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
    // The scripts and styles: only whether nosniff came or went there (not whether they could be checked)
    $files = [$from['nosniff_files'] ?? null, $to['nosniff_files'] ?? null];
    if ($files === ['good', 'missing']) $parts[] = 'X-Content-Type-Options removed from scripts and styles';
    if ($files === [null, 'missing']) $parts[] = 'X-Content-Type-Options not on scripts and styles';
    if ($files === ['missing', 'good']) $parts[] = 'X-Content-Type-Options added to scripts and styles';
    if (!$parts) return '';

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
        $item = ['key' => $key] + $header + ($details['headers'][$key] ?? ['level' => 'missing', 'note' => '']);
        if ($key === 'nosniff' && $item['level'] === 'good' && isset($details['files'])) {
            $missing = $details['files']['missing'];
            $item = $missing
                ? ['level' => 'weak', 'note' => 'not on the scripts and styles the page loads: ' . list_words($missing), 'tip' => FILES_NOSNIFF_TIP] + $item
                : ['note' => 'also on the scripts and styles the page loads'] + $item;
        }
        if ($key === 'csp' && $item['level'] === 'missing') $item = csp_item($item, $details['scripts'] ?? null);
        $items[] = $item;
    }
    return $items;
}

/**
 * The CSP item without a CSP in force. Sent only as Report-Only: enforce
 * it once it is clean. Otherwise Report-Only to try first, and what the
 * page has that default-src 'self' would block, so the line is not
 * copied as it is onto a site it would break. No policy is built from
 * the page: Vaka sees one page, and a hash for an inline script changes
 * whenever the script does.
 */
function csp_item(array $item, ?array $scripts): array
{
    if ($item['note'] === CSP_REPORT_ONLY_NOTE) {
        return ['fix' => 'Content-Security-Policy: <the same policy>', 'tip' => "Rename Content-Security-Policy-Report-Only to Content-Security-Policy, keeping its policy, once the browser's console shows nothing it would block that the site needs."] + $item;
    }
    $inline = $scripts['inline'] ?? 0;
    $hosts = $scripts['hosts'] ?? [];
    $from = count($hosts) > 3 ? implode(', ', array_slice($hosts, 0, 3)) . ' and ' . (count($hosts) - 3) . ' more hosts' : list_words($hosts);
    $note = match (true) {
        $inline && $hosts => 'the page has ' . plural($inline, 'inline script', 'inline scripts') . " and scripts from {$from}",
        $inline > 0 => 'the page has ' . plural($inline, 'inline script', 'inline scripts'),
        $hosts !== [] => "the page loads scripts from {$from}",
        default => '',
    };
    return [
        'note' => $note !== '' ? "{$note}, which default-src 'self' would block" : $item['note'],
        'tip' => $item['tip'] . ($inline ? " Inline scripts need a 'sha256-...' hash each in script-src, or a nonce: let the site's build add them, since a hash changes whenever the script does." : ''),
    ] + $item;
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
        // A part not told before (the files, the first time they are checked) is a baseline
        if (!isset($told[$key]) || HEADER_LEVELS[$level] >= HEADER_LEVELS[$told[$key]]) continue;
        $worse[] = $key === 'nosniff_files'
            ? ['key' => $key, 'label' => 'X-Content-Type-Options on scripts and styles', 'change' => 'removed', 'note' => list_words($state['details']['files']['missing'] ?? [])]
            : ['key' => $key, 'label' => SECURITY_HEADERS[$key]['label'], 'change' => $level === 'missing' ? 'removed' : 'weaker', 'note' => $state['details']['headers'][$key]['note'] ?? ''];
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
