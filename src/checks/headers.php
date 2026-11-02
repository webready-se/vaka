<?php

/** How often a site's security headers are graded; a change is confirmed by a second look after HEADERS_CONFIRM_SECONDS. */
const HEADERS_INTERVAL_SECONDS = 6 * 3600;
const HEADERS_CONFIRM_SECONDS = 15 * 60;
const HEADERS_RETRY_SECONDS = 3600;

/** Sites fetched together in one batch; the run budget is checked between batches. */
const HEADERS_SITES_PER_BATCH = 20;

/** HSTS shorter than this (six months) is weak. */
const HSTS_MIN_SECONDS = 15552000;

/** The protections that are graded: what each does, the header that adds it, and a tip. */
const SECURITY_HEADERS = [
    'hsts' => ['label' => 'HSTS', 'what' => 'Makes browsers always use https for the site', 'fix' => 'Strict-Transport-Security: max-age=31536000', 'tip' => 'Add it once every page works over https.'],
    'csp' => ['label' => 'CSP', 'what' => 'Limits which scripts may run: the main defence against injected scripts', 'fix' => "Content-Security-Policy-Report-Only: default-src 'self'", 'tip' => "Report-Only blocks nothing, so it is safe to try: open a few pages with the browser's console open, and it lists what the policy would block. Allow what the site needs (another host's scripts go in script-src, for example), then rename the header to Content-Security-Policy."],
    'nosniff' => ['label' => 'X-Content-Type-Options', 'what' => 'Stops browsers from guessing what a file is', 'fix' => 'X-Content-Type-Options: nosniff', 'tip' => ''],
    'frames' => ['label' => 'frame protection', 'what' => 'Stops other sites from showing this one in a frame to trick visitors into clicking', 'fix' => 'X-Frame-Options: SAMEORIGIN', 'tip' => "Or frame-ancestors 'self' in the CSP."],
    'referrer' => ['label' => 'Referrer-Policy', 'what' => 'Keeps full addresses, with search terms and tokens, from leaking to other sites', 'fix' => 'Referrer-Policy: strict-origin-when-cross-origin', 'tip' => ''],
    'permissions' => ['label' => 'Permissions-Policy', 'what' => 'Turns off browser features the site does not use, such as the camera and microphone', 'fix' => 'Permissions-Policy: camera=(), microphone=(), geolocation=()', 'tip' => ''],
];

/**
 * The order to add the protections in, as the site detail, the read API and
 * the report list them: the safest first. nosniff and Referrer-Policy break
 * nothing; frame protection breaks only a site that others embed; HSTS keeps
 * browsers on https for a year once sent; Permissions-Policy adds little;
 * CSP is the one that can break a site.
 */
const HEADERS_FIX_ORDER = ['nosniff', 'referrer', 'frames', 'hsts', 'permissions', 'csp'];

/** A CSP sent only as Report-Only: tried, not enforced. */
const CSP_REPORT_ONLY_NOTE = 'only as Report-Only, which blocks nothing';

/** What to do about a CSP that blocks parts of the page; the alert adds where they are named. */
const CSP_BLOCKS_TIP = "Visitors do not get them: a blocked script stops working, a blocked style makes the page look broken. Allow what the page needs (a host goes in script-src, style-src, img-src or frame-src; an inline script needs a 'sha256-...' hash there or a file of its own; inline styles need 'unsafe-inline' in style-src, or classes instead), or take them off the page.";

/** How strong each level is, to tell worse from better. */
const HEADER_LEVELS = ['missing' => 0, 'weak' => 1, 'good' => 2];

/** Of each file a page loads, only the start is read: the headers are what counts. */
const FILES_MAX_BYTES = 1024;

/** Why scripts and styles can lack nosniff when the page has it, and where the line goes. */
// Once for all six, since adding them in nginx has the same two traps whichever is missing
const HEADERS_NGINX_TIP = 'Adding them in nginx? Put the add_header lines in the server block, with the always flag, so error pages get them too. A location with an add_header of its own drops all of the server block\'s: keep add_header out of locations, or repeat them all in every such location.';

const HEADERS_APACHE_TIP = 'Adding them in Apache? Put the Header lines in the site\'s configuration, or in a .htaccess file at the site\'s root on shared hosting, with always, so error pages get them too. They need mod_headers (a2enmod headers), which most hosts have on.';

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
 * Whether one CSP source expression allows a script at `url`, as browsers
 * read it: 'self' is the page's origin (https allowed from an http page),
 * a scheme alone matches the scheme (http: allows https too), a host may
 * start with `*.` for its subdomains and carry a port (`*` for any) and a
 * path (a prefix when it ends in `/`, otherwise exact). Keywords, nonces
 * and hashes say nothing about a URL.
 */
function csp_source_allows(string $source, string $url, string $pageUrl): bool
{
    $source = strtolower($source);
    $parts = parse_url($url);
    if (!$parts || !isset($parts['scheme'], $parts['host'])) return false;
    $scheme = strtolower($parts['scheme']);
    $host = strtolower($parts['host']);
    $port = $parts['port'] ?? ($scheme === 'https' ? 443 : 80);
    $path = $parts['path'] ?? '/';

    if ($source === '*') return true;
    if ($source === "'self'") {
        $page = parse_url($pageUrl);
        if (!$page || !isset($page['host'])) return false;
        $pageScheme = strtolower($page['scheme'] ?? 'https');
        $pagePort = $page['port'] ?? ($pageScheme === 'https' ? 443 : 80);
        $sameScheme = $scheme === $pageScheme || ($pageScheme === 'http' && $scheme === 'https');
        return $host === strtolower($page['host']) && $sameScheme && ($port === $pagePort || $pagePort === 80 && $port === 443);
    }
    if (str_starts_with($source, "'")) return false;
    if (preg_match('/^([a-z][a-z0-9+.-]*):$/', $source, $m)) return $scheme === $m[1] || ($m[1] === 'http' && $scheme === 'https');
    if (!preg_match('#^(?:([a-z][a-z0-9+.-]*)://)?(\*\.)?([^/:*]+)(?::(\*|\d+))?(/[^\s]*)?$#', $source, $m)) return false;
    [, $sourceScheme, $wildcard, $sourceHost, $sourcePort, $sourcePath] = $m + array_fill(0, 6, '');
    if ($sourceScheme !== '' && $scheme !== $sourceScheme && !($sourceScheme === 'http' && $scheme === 'https')) return false;
    if ($wildcard !== '' ? !str_ends_with($host, '.' . $sourceHost) : $host !== $sourceHost) return false;
    if ($sourcePort === '*') {
        // Any port
    } elseif ($sourcePort !== '') {
        if ($port !== (int) $sourcePort) return false;
    } elseif ($port !== (($sourceScheme ?: $scheme) === 'https' ? 443 : 80) && !($sourceScheme === 'http' && $port === 443)) {
        return false;
    }
    if ($sourcePath !== '' && (str_ends_with($sourcePath, '/') ? !str_starts_with($path, $sourcePath) : $path !== $sourcePath)) return false;
    return true;
}

/**
 * What a policy stops on the page: scripts (external ones whose URL no
 * source allows, inline ones without a matching hash), stylesheets and
 * <style> blocks the same way against style-src, style="..." attributes
 * (only 'unsafe-inline' lets them through, or 'unsafe-hashes', taken as
 * hashes given), images against img-src and frames against frame-src or
 * child-src, each falling back to default-src. A nonce in the policy lets
 * an element with a nonce attribute through: Vaka cannot see the nonce the
 * page was sent with. Several policies all apply, so what any of them stops
 * is stopped. Only sha256 is computed; a policy with other hashes is taken
 * as allowing its inline code rather than wrongly blocking it. At most ten
 * addresses of each kind and five script starts are kept; the totals count
 * them all.
 *
 * @param list<array> $policies  parsed with csp_directives()
 * @return array{scripts: list<string>, inline: list<string>, inline_total: int, stylesheets: list<string>, style_blocks: int, style_attributes: int, images: list<string>, frames: list<string>}
 */
function csp_blocked(array $policies, array $page, string $pageUrl): array
{
    $blocked = ['scripts' => [], 'inline' => [], 'stylesheets' => [], 'style_blocks' => [], 'images' => [], 'frames' => []];
    $attributesBlocked = false;
    $sources = fn (array $directives, string ...$names) => array_reduce($names, fn ($found, $name) => $found ?? ($directives[$name] ?? null));
    $allows = fn (array $sources, string $url) => (bool) array_filter($sources, fn ($source) => csp_source_allows($source, $url, $pageUrl));
    $nonced = fn (array $sources) => (bool) array_filter($sources, fn ($source) => str_starts_with($source, "'nonce-"));
    $hashes = fn (array $sources) => array_filter($sources, fn ($source) => preg_match("/^'sha(256|384|512)-/", $source));
    // Inline code against a source list: allowed by 'unsafe-inline' (ignored next to a nonce or hash, as browsers do), a nonce, or its hash
    $inlineBlocked = function (array $sources, array $items, bool $strictDynamic = false) use ($nonced, $hashes): array {
        $found = $hashes($sources);
        $otherHashes = (bool) array_filter($found, fn ($source) => !str_starts_with($source, "'sha256-"));
        $allowed = in_array("'unsafe-inline'", $sources, true) && !$nonced($sources) && !$found && !$strictDynamic;
        $blocked = [];
        foreach ($items as $i => $item) {
            if ($allowed || $otherHashes || ($item['nonce'] && $nonced($sources))) continue;
            if (in_array("'" . strtolower($item['hash']) . "'", $sources, true)) continue;
            $blocked[$i] = $item;
        }
        return $blocked;
    };

    foreach ($policies as $directives) {
        $scriptSources = $sources($directives, 'script-src-elem', 'script-src', 'default-src');
        if ($scriptSources !== null) {
            $strictDynamic = in_array("'strict-dynamic'", $scriptSources, true);
            foreach ($page['external'] ?? [] as $script) {
                if ($script['nonce'] && $nonced($scriptSources)) continue;
                if ($strictDynamic || !$allows($scriptSources, $script['url'])) $blocked['scripts'][$script['url']] = true;
            }
            foreach ($inlineBlocked($scriptSources, $page['inline_scripts'] ?? [], $strictDynamic) as $i => $script) $blocked['inline'][$i] = $script['start'];
        }
        $styleSources = $sources($directives, 'style-src-elem', 'style-src', 'default-src');
        if ($styleSources !== null) {
            foreach ($page['stylesheets'] ?? [] as $sheet) {
                if ($sheet['nonce'] && $nonced($styleSources)) continue;
                if (!$allows($styleSources, $sheet['url'])) $blocked['stylesheets'][$sheet['url']] = true;
            }
            foreach ($inlineBlocked($styleSources, $page['style_blocks'] ?? []) as $i => $block) $blocked['style_blocks'][$i] = true;
        }
        $attrSources = $sources($directives, 'style-src-attr', 'style-src', 'default-src');
        if ($attrSources !== null && ($page['style_attributes'] ?? 0) > 0) {
            $allowed = (in_array("'unsafe-inline'", $attrSources, true) && !$nonced($attrSources) && !$hashes($attrSources)) || in_array("'unsafe-hashes'", $attrSources, true);
            if (!$allowed) $attributesBlocked = true;
        }
        $imageSources = $sources($directives, 'img-src', 'default-src');
        if ($imageSources !== null) {
            foreach ($page['images'] ?? [] as $image) if (!$allows($imageSources, $image)) $blocked['images'][$image] = true;
        }
        $frameSources = $sources($directives, 'frame-src', 'child-src', 'default-src');
        if ($frameSources !== null) {
            foreach ($page['frames'] ?? [] as $frame) if (!$allows($frameSources, $frame)) $blocked['frames'][$frame] = true;
        }
    }
    return [
        'scripts' => array_slice(array_keys($blocked['scripts']), 0, 10),
        'inline' => array_slice(array_values($blocked['inline']), 0, 5),
        'inline_total' => count($blocked['inline']),
        'stylesheets' => array_slice(array_keys($blocked['stylesheets']), 0, 10),
        'style_blocks' => count($blocked['style_blocks']),
        'style_attributes' => $attributesBlocked ? (int) ($page['style_attributes'] ?? 0) : 0,
        'images' => array_slice(array_keys($blocked['images']), 0, 10),
        'frames' => array_slice(array_keys($blocked['frames']), 0, 10),
    ];
}

/**
 * What a policy blocks, in words: "2 inline scripts, a script from
 * cdn.example, 15 inline styles and images from cdn.example", or '' for
 * nothing. Inline styles are the <style> blocks and the style attributes.
 */
function csp_blocked_words(array $blocked): string
{
    $hosts = fn (string $key) => array_values(array_unique(array_map('url_host', $blocked[$key] ?? [])));
    $from = fn (string $key, string $one, string $many) => ($hosts($key) ? [(count($hosts($key)) === 1 && count($blocked[$key]) === 1 ? "{$one} from " : "{$many} from ") . list_words($hosts($key))] : []);
    $styles = ($blocked['style_blocks'] ?? 0) + ($blocked['style_attributes'] ?? 0);
    $parts = [
        ...(($blocked['inline_total'] ?? 0) > 0 ? [plural($blocked['inline_total'], 'inline script', 'inline scripts')] : []),
        ...$from('scripts', 'a script', 'scripts'),
        ...($styles > 0 ? [plural($styles, 'inline style', 'inline styles')] : []),
        ...$from('stylesheets', 'a stylesheet', 'stylesheets'),
        ...$from('images', 'an image', 'images'),
        ...$from('frames', 'a frame', 'frames'),
    ];
    return list_words($parts);
}

/** Whether a policy blocks anything on the page. */
function csp_blocks_anything(?array $blocked): bool
{
    if ($blocked === null) return false;
    return $blocked['scripts'] !== [] || $blocked['inline_total'] > 0 || ($blocked['stylesheets'] ?? []) !== [] || ($blocked['style_blocks'] ?? 0) > 0
        || ($blocked['style_attributes'] ?? 0) > 0 || ($blocked['images'] ?? []) !== [] || ($blocked['frames'] ?? []) !== [];
}

/** The blocked stylesheets, images and frames as a list to show, kind and address. */
function csp_blocked_assets(array $blocked): array
{
    $assets = [];
    foreach (['stylesheets' => 'stylesheet', 'images' => 'image', 'frames' => 'frame'] as $key => $kind) {
        foreach ($blocked[$key] ?? [] as $url) $assets[] = ['kind' => $kind, 'url' => $url];
    }
    return $assets;
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
    if (isset($details['csp_blocks'])) $levels['csp_blocks'] = csp_blocks_anything($details['csp_blocks']) ? 'weak' : 'good';
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
 * `excerpts` starts the first five inline scripts (see script_excerpt()),
 * to find them by in the site's code.
 *
 * `inline_scripts` (a sha256 hash of each as sent, its start, whether it has a nonce)
 * and `external` (URL, nonce) are what csp_blocked() compares with a policy, as
 * are the `stylesheets`, the `style_blocks` (hashed), the number of
 * `style_attributes`, the `images` (src and srcset) and the `frames`; at most
 * 50 of each (100 images).
 *
 * @return array{inline: int, hosts: list<string>, excerpts: list<string>, inline_scripts: list<array{hash: string, start: string, nonce: bool}>, external: list<array{url: string, nonce: bool}>, stylesheets: list<array{url: string, nonce: bool}>, style_blocks: list<array{hash: string, nonce: bool}>, style_attributes: int, images: list<string>, frames: list<string>}
 */
function page_scripts(string $html, string $pageUrl): array
{
    $html = (string) preg_replace('/<!--.*?-->/s', '', $html);
    $url = fn (string $ref) => resolve_url(html_entity_decode($ref, ENT_QUOTES | ENT_HTML5), $pageUrl);
    preg_match_all('/<script\b([^>]*)>(.*?)<\/script\s*>/is', $html, $scripts, PREG_SET_ORDER);
    $hosts = [];
    $inline = [];
    $external = [];
    foreach ($scripts as [, $source, $body]) {
        $attributes = html_attributes($source);
        $nonce = ($attributes['nonce'] ?? '') !== '';
        if (isset($attributes['src'])) {
            $resolved = resolve_url($attributes['src'], $pageUrl);
            if ($resolved === null) continue;
            if (url_host($resolved) !== url_host($pageUrl)) $hosts[url_host($resolved)] = true;
            if (count($external) < 50) $external[] = ['url' => $resolved, 'nonce' => $nonce];
        } elseif (trim($body) !== '' && preg_match('/^(?:|module|importmap|speculationrules|.*(?:java|ecma)script.*)$/', strtolower(trim($attributes['type'] ?? '')))) {
            // The hash is of the script exactly as sent, as browsers take it
            if (count($inline) < 50) $inline[] = ['hash' => 'sha256-' . base64_encode(hash('sha256', $body, true)), 'start' => script_excerpt($body), 'nonce' => $nonce];
        }
    }
    // Styles: the stylesheets linked, the <style> blocks (hashed as scripts are) and how many style="..." attributes
    $stylesheets = [];
    preg_match_all('/<link\b([^>]*)>/i', $html, $links);
    foreach ($links[1] as $source) {
        $attributes = html_attributes($source);
        if (!isset($attributes['href']) || !preg_match('/(?:^|\s)stylesheet(?:\s|$)/i', $attributes['rel'] ?? '')) continue;
        $resolved = resolve_url($attributes['href'], $pageUrl);
        if ($resolved !== null && count($stylesheets) < 50) $stylesheets[] = ['url' => $resolved, 'nonce' => ($attributes['nonce'] ?? '') !== ''];
    }
    $blocks = [];
    preg_match_all('/<style\b([^>]*)>(.*?)<\/style\s*>/is', $html, $styles, PREG_SET_ORDER);
    foreach ($styles as [, $source, $body]) {
        if (count($blocks) < 50) $blocks[] = ['hash' => 'sha256-' . base64_encode(hash('sha256', $body, true)), 'nonce' => (html_attributes($source)['nonce'] ?? '') !== ''];
    }
    $attributes = preg_match_all('/<[a-z][^>]*\sstyle\s*=/i', $html);
    // Images (src and every srcset candidate) and frames, by address
    $images = [];
    preg_match_all('/<img\b([^>]*)>/i', $html, $imgs);
    foreach ($imgs[1] as $source) {
        $attrs = html_attributes($source);
        $refs = isset($attrs['src']) ? [$attrs['src']] : [];
        foreach (explode(',', $attrs['srcset'] ?? '') as $candidate) {
            $ref = preg_split('/\s+/', trim($candidate))[0] ?? '';
            if ($ref !== '') $refs[] = $ref;
        }
        foreach ($refs as $ref) {
            $resolved = resolve_url($ref, $pageUrl);
            if ($resolved !== null && count($images) < 100) $images[$resolved] = true;
        }
    }
    $frames = [];
    preg_match_all('/<iframe\b([^>]*)>/i', $html, $iframes);
    foreach ($iframes[1] as $source) {
        $attrs = html_attributes($source);
        $resolved = isset($attrs['src']) ? resolve_url($attrs['src'], $pageUrl) : null;
        if ($resolved !== null && count($frames) < 50) $frames[$resolved] = true;
    }
    return [
        'inline' => count($inline),
        'hosts' => array_keys($hosts),
        'excerpts' => array_column(array_slice($inline, 0, 5), 'start'),
        'inline_scripts' => $inline,
        'external' => $external,
        'stylesheets' => $stylesheets,
        'style_blocks' => $blocks,
        'style_attributes' => $attributes,
        'images' => array_keys($images),
        'frames' => array_keys($frames),
    ];
}

/** The start of a script on one line: at most 60 characters, and "…" when there is more. */
function script_excerpt(string $body): string
{
    // Without /u for text that is not UTF-8
    $line = trim(preg_replace('/\s+/u', ' ', $body) ?? (string) preg_replace('/\s+/', ' ', $body));
    $start = preg_match('/^.{0,60}/su', $line, $match) ? $match[0] : substr($line, 0, 60);
    return strlen($start) < strlen($line) ? rtrim($start) . '…' : $line;
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
    // Its page has none of the site's headers: graded, every protection would look removed
    $by = bot_check($response);
    if ($by !== '') return ['status' => 'unknown', 'summary' => 'Could not check: ' . bot_check_words($by), 'details' => []];
    if ($response['status'] >= 400) {
        return ['status' => 'unknown', 'summary' => 'Could not check: the site answered ' . trim($response['status'] . ' ' . status_text($response['status'])), 'details' => []];
    }

    $https = str_starts_with($response['url'], 'https://');
    $headers = [];
    foreach (array_keys(SECURITY_HEADERS) as $key) $headers[$key] = header_level($key, $response['headers'], $https);
    $details = ['headers' => $headers];
    // What the site sends, for the detail to show
    $details['sent'] = sent_headers($response['headers']);
    // The policy tried as Report-Only, as sent: the line to add is that policy under the name that enforces it
    if (isset($response['headers']['content-security-policy-report-only'])) $details['csp_report_only'] = mb_substr(trim((string) $response['headers']['content-security-policy-report-only'][0]), 0, 1000);
    // For the lines the dashboard offers to paste in, never the grade
    if (($server = web_server($response['headers'])) !== '') $details['server'] = $server;
    if ($files !== null && $headers['nosniff']['level'] === 'good') $details['files'] = $files;
    // The page's scripts against the CSP, never for the grade: what an enforced one blocks (its own part of the
    // levels, so it is confirmed and alerted like a change), what a Report-Only one would, or what a first one would
    $scripts = page_scripts($response['body'] ?? '', $response['url']);
    $policies = fn (string $name) => array_map('csp_directives', $response['headers'][$name] ?? []);
    if ($headers['csp']['level'] !== 'missing') {
        $details['csp_blocks'] = csp_blocked($policies('content-security-policy'), $scripts, $response['url']);
    } else {
        $details['scripts'] = $scripts;
        if (isset($response['headers']['content-security-policy-report-only'])) {
            $details['csp_would_block'] = csp_blocked($policies('content-security-policy-report-only'), $scripts, $response['url']);
        }
    }
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
    $blocks = csp_blocks_anything($details['csp_blocks'] ?? null);
    if ($blocks) $summary .= ', the CSP blocks ' . csp_blocked_words($details['csp_blocks']) . ' on the page';

    return ['status' => headers_status($grade, $blocks), 'summary' => $summary, 'details' => ['grade' => $grade] + $details];
}

/** A grade's status: ok for A or B, warn below, and warn while the CSP blocks parts of the page. */
function headers_status(string $grade, bool $blocks = false): string
{
    return in_array($grade, ['A', 'B'], true) && !$blocks ? 'ok' : 'warn';
}

/**
 * The status of the levels last confirmed, or null before the first. A
 * change counts at its second look, and the first look already stored the
 * new status, so this, not the stored status, is where a change came from.
 */
function headers_confirmed_status(?array $details): ?string
{
    return isset($details['confirmed']) ? headers_status(headers_grade($details['confirmed']), ($details['confirmed']['csp_blocks'] ?? null) === 'weak') : null;
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
    // The CSP against the page: whether it started or stopped blocking scripts
    $blocks = [$from['csp_blocks'] ?? null, $to['csp_blocks'] ?? null];
    if ($blocks[1] === 'weak' && $blocks[0] !== 'weak') $parts[] = 'CSP blocks parts of the page';
    if ($blocks[0] === 'weak' && $blocks[1] === 'good') $parts[] = 'CSP no longer blocks parts of the page';
    if (!$parts) return '';

    $old = headers_grade($from);
    $new = headers_grade($to);
    return $old !== $new ? "Grade {$old} to {$new}: " . implode(', ', $parts) : ucfirst(implode(', ', $parts)) . " (grade still {$new})";
}

/**
 * The web server that answered, from its Server header, when it is one the
 * dashboard has header lines for: 'nginx', 'apache' or ''. LiteSpeed reads
 * Apache's .htaccess. Behind a CDN the header is the CDN's, so '', and the
 * dashboard falls back to the last choice.
 */
function web_server(array $headers): string
{
    $server = strtolower(implode(' ', $headers['server'] ?? []));
    if (preg_match('/\b(nginx|openresty)\b/', $server)) return 'nginx';
    if (preg_match('/\b(apache|litespeed)\b/', $server)) return 'apache';
    return '';
}

/**
 * What the site detail and the read API say about adding the headers: the
 * items, the web server the lines are offered for ('nginx', 'apache' or ''),
 * and how to add them in nginx and in Apache without losing them.
 *
 * @return array{items: list<array>, server: string, tip: string, tip_apache: string}
 */
function headers_advice(array $details): array
{
    return ['items' => header_items($details), 'server' => $details['server'] ?? '', 'tip' => HEADERS_NGINX_TIP, 'tip_apache' => HEADERS_APACHE_TIP];
}

/**
 * What the site sends for each protection, as text, for the site detail to
 * show under "In place" and next to a weak one: the first 500 characters,
 * several values joined. Frame protection from a CSP shows its
 * frame-ancestors directive when there is no X-Frame-Options.
 *
 * @return array<string, string>  protection key => the header as sent
 */
function sent_headers(array $headers): array
{
    $names = ['hsts' => 'strict-transport-security', 'csp' => 'content-security-policy', 'nosniff' => 'x-content-type-options', 'frames' => 'x-frame-options', 'referrer' => 'referrer-policy', 'permissions' => 'permissions-policy'];
    $sent = [];
    foreach ($names as $key => $name) {
        if (isset($headers[$name])) $sent[$key] = mb_substr(trim(implode(', ', array_map('trim', $headers[$name]))), 0, 500);
    }
    if (!isset($sent['frames'])) {
        foreach ($headers['content-security-policy'] ?? [] as $policy) {
            if (preg_match('/(?:^|;)\s*(frame-ancestors[^;]*)/i', $policy, $m)) {
                $sent['frames'] = trim($m[1]);
                break;
            }
        }
    }
    return $sent;
}

/**
 * What to do with an item's line, said above it, as the mail records say
 * "Change the TXT record to:". Empty when there is no line.
 */
function fix_label(array $item): string
{
    if ($item['fix'] === '') return '';
    if ($item['key'] === 'csp') {
        return match (true) {
            $item['report_only'] ?? false => 'Your policy under the name that enforces it:',
            $item['level'] === 'weak' => 'A stricter policy to try first as Report-Only, which blocks nothing:',
            default => 'Try this line first; as Report-Only it blocks nothing:',
        };
    }
    if ($item['key'] === 'nosniff' && $item['level'] === 'weak') return 'The line, for those files too:';
    return $item['level'] === 'weak' ? 'Change it to:' : 'Add this line:';
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
    foreach (HEADERS_FIX_ORDER as $key) {
        $item = ['key' => $key] + SECURITY_HEADERS[$key] + ($details['headers'][$key] ?? ['level' => 'missing', 'note' => '']);
        if ($key === 'nosniff' && $item['level'] === 'good' && isset($details['files'])) {
            $missing = $details['files']['missing'];
            $item = $missing
                ? ['level' => 'weak', 'note' => 'not on the scripts and styles the page loads: ' . list_words($missing), 'tip' => FILES_NOSNIFF_TIP] + $item
                : ['note' => 'also on the scripts and styles the page loads'] + $item;
        }
        if ($key === 'csp') $item = csp_item($item, $details);
        $item['value'] = $details['sent'][$key] ?? '';
        $item['fix_label'] = fix_label($item);
        $items[] = $item;
    }
    return $items;
}

/**
 * The CSP item against the page. In force: whether it blocks scripts on the
 * page (`blocks`), and which (`blocked` URLs, `inline` starts), with what to
 * do. Sent only as Report-Only: what it would block, or that it is safe to
 * rename. Without one: Report-Only to try first, and what the page has that
 * default-src 'self' would block, so the line is not copied as it is onto a
 * site it would break. No policy is built from the page: Vaka sees one page,
 * and a hash for an inline script changes whenever the script does.
 */
function csp_item(array $item, array $details): array
{
    $blocked = $details['csp_blocks'] ?? $details['csp_would_block'] ?? null;
    $blocks = csp_blocks_anything($blocked);
    $named = $blocks ? array_filter(['inline' => $blocked['inline'], 'blocked' => $blocked['scripts'], 'blocked_assets' => csp_blocked_assets($blocked)]) : [];
    if ($item['level'] !== 'missing') {
        if (!$blocks) return ['blocks' => false] + $item;
        return ['blocks' => true, 'note' => ($item['note'] !== '' ? $item['note'] . '. It blocks ' : 'it blocks ') . csp_blocked_words($blocked) . ' on the page', 'fix' => '', 'tip' => CSP_BLOCKS_TIP] + $named + $item;
    }
    if ($item['note'] === CSP_REPORT_ONLY_NOTE) {
        $would = match (true) {
            $blocked === null => '',
            $blocks => '. Enforced, it would block ' . csp_blocked_words($blocked) . ' on the page',
            default => '. Enforced, it would block nothing on the page, so it is safe to rename',
        };
        return ['blocks' => false, 'report_only' => true, 'note' => 'it blocks nothing yet' . $would, 'fix' => 'Content-Security-Policy: ' . ($details['csp_report_only'] ?? '<the same policy>'), 'tip' => "The line above is the policy the site sends as Report-Only, under the name that enforces it. Add it once the browser's console, and the list above, show nothing it would block that the site needs, and remove the Report-Only line."] + $named + $item;
    }
    $scripts = $details['scripts'] ?? null;
    $inline = $scripts['inline'] ?? 0;
    $hosts = $scripts['hosts'] ?? [];
    $from = count($hosts) > 3 ? implode(', ', array_slice($hosts, 0, 3)) . ' and ' . (count($hosts) - 3) . ' more hosts' : list_words($hosts);
    $note = match (true) {
        $inline && $hosts => 'the page has ' . plural($inline, 'inline script', 'inline scripts') . " and scripts from {$from}",
        $inline > 0 => 'the page has ' . plural($inline, 'inline script', 'inline scripts'),
        $hosts !== [] => "the page loads scripts from {$from}",
        default => '',
    };
    $excerpts = $scripts['excerpts'] ?? [];
    return [
        'blocks' => false,
        'note' => $note !== '' ? "{$note}, which default-src 'self' would block" : $item['note'],
        'tip' => 'This is the one that can break a site, so it is fine to do it last. ' . $item['tip']
            . ($inline ? " Inline scripts need a 'sha256-...' hash each in script-src, or a nonce: let the site's build add them, since a hash changes whenever the script does." : ''),
    ] + ($excerpts ? ['inline' => $excerpts] : []) + $item;
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
        // A part not told before (the files, the first time they are checked) is a baseline. Not the CSP blocking
        // scripts: before it, nothing was blocked, so a new CSP that blocks the page's scripts is told
        $before = $told[$key] ?? ($key === 'csp_blocks' && $told ? 'good' : null);
        if ($before === null || HEADER_LEVELS[$level] >= HEADER_LEVELS[$before]) continue;
        $worse[] = match ($key) {
            'nosniff_files' => ['key' => $key, 'label' => 'X-Content-Type-Options on scripts and styles', 'change' => 'removed', 'note' => list_words($state['details']['files']['missing'] ?? [])],
            'csp_blocks' => ['key' => $key, 'label' => 'CSP', 'change' => 'blocks', 'note' => csp_blocked_words($state['details']['csp_blocks'] ?? [])],
            default => ['key' => $key, 'label' => SECURITY_HEADERS[$key]['label'], 'change' => $level === 'missing' ? 'removed' : 'weaker', 'note' => $state['details']['headers'][$key]['note'] ?? ''],
        };
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
