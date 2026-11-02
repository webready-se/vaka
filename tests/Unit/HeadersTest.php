<?php

/** A fetch_many() result with these response headers (name => value, or a list for repeated headers). */
function answered(array $headers, string $url = 'https://shop.example/', int $status = 200, int $errno = 0): array
{
    $list = [];
    foreach ($headers as $name => $value) $list[strtolower($name)] = (array) $value;
    return ['status' => $errno ? 0 : $status, 'headers' => $list, 'body' => '', 'ms' => 80, 'ip' => '203.0.113.10', 'errno' => $errno, 'error' => $errno ? 'Connection timed out' : '', 'url' => $url];
}

const STRONG_HEADERS = [
    'Strict-Transport-Security' => 'max-age=31536000; includeSubDomains',
    'Content-Security-Policy' => "default-src 'self'; frame-ancestors 'self'",
    'X-Content-Type-Options' => 'nosniff',
    'Referrer-Policy' => 'strict-origin-when-cross-origin',
    'Permissions-Policy' => 'camera=(), microphone=()',
];

/** The level of one protection for these headers. */
function level(string $key, array $headers, string $url = 'https://shop.example/'): array
{
    return header_level($key, answered($headers, $url)['headers'], str_starts_with($url, 'https://'));
}

test('HSTS needs https and a long enough max-age', function () {
    expect(level('hsts', ['Strict-Transport-Security' => 'max-age=31536000']))->toBe(['level' => 'good', 'note' => ''])
        ->and(level('hsts', ['Strict-Transport-Security' => 'max-age="15552000"; preload']))->toBe(['level' => 'good', 'note' => ''])
        ->and(level('hsts', ['Strict-Transport-Security' => 'max-age=2592000']))->toBe(['level' => 'weak', 'note' => 'it lasts only 30 days; six months or more is advised'])
        ->and(level('hsts', ['Strict-Transport-Security' => 'max-age=0']))->toBe(['level' => 'missing', 'note' => 'max-age=0 turns it off'])
        ->and(level('hsts', []))->toBe(['level' => 'missing', 'note' => ''])
        ->and(level('hsts', ['Strict-Transport-Security' => 'max-age=31536000'], 'http://shop.example/'))->toBe(['level' => 'missing', 'note' => 'the site does not use https']);
});

test('a CSP counts when it limits scripts, and is weak when it lets any script run', function () {
    expect(level('csp', ['Content-Security-Policy' => "default-src 'self'"])['level'])->toBe('good')
        ->and(level('csp', ['Content-Security-Policy' => "script-src 'self' 'nonce-abc' 'unsafe-inline'"])['level'])->toBe('good')
        ->and(level('csp', ['Content-Security-Policy' => "script-src 'self' 'unsafe-inline'"]))->toBe(['level' => 'weak', 'note' => "it allows inline scripts ('unsafe-inline')"])
        ->and(level('csp', ['Content-Security-Policy' => "default-src 'self' 'unsafe-eval'"]))->toBe(['level' => 'weak', 'note' => "it allows eval ('unsafe-eval')"])
        ->and(level('csp', ['Content-Security-Policy' => 'script-src * ']))->toBe(['level' => 'weak', 'note' => 'it allows scripts from anywhere'])
        ->and(level('csp', ['Content-Security-Policy' => 'default-src https:']))->toBe(['level' => 'weak', 'note' => 'it allows scripts from anywhere'])
        ->and(level('csp', ['Content-Security-Policy' => "frame-ancestors 'self'; upgrade-insecure-requests"]))->toBe(['level' => 'weak', 'note' => 'it does not limit scripts'])
        ->and(level('csp', ['Content-Security-Policy' => ["frame-ancestors 'self'", "script-src 'self'"]])['level'])->toBe('good')
        ->and(level('csp', ['Content-Security-Policy-Report-Only' => "default-src 'self'"]))->toBe(['level' => 'missing', 'note' => 'only as Report-Only, which blocks nothing'])
        ->and(level('csp', []))->toBe(['level' => 'missing', 'note' => '']);
});

test('frame protection comes from frame-ancestors or X-Frame-Options', function () {
    expect(level('frames', ['Content-Security-Policy' => "frame-ancestors 'none'"])['level'])->toBe('good')
        ->and(level('frames', ['X-Frame-Options' => 'SAMEORIGIN'])['level'])->toBe('good')
        ->and(level('frames', ['X-Frame-Options' => ['deny', 'DENY']])['level'])->toBe('good')
        ->and(level('frames', ['Content-Security-Policy' => 'frame-ancestors *']))->toBe(['level' => 'weak', 'note' => 'frame-ancestors lets any site frame it'])
        ->and(level('frames', ['X-Frame-Options' => 'ALLOW-FROM https://partner.example']))->toBe(['level' => 'missing', 'note' => 'browsers ignore ALLOW-FROM'])
        ->and(level('frames', []))->toBe(['level' => 'missing', 'note' => '']);
});

test('nosniff, Referrer-Policy and Permissions-Policy', function () {
    expect(level('nosniff', ['X-Content-Type-Options' => 'nosniff'])['level'])->toBe('good')
        ->and(level('nosniff', ['X-Content-Type-Options' => 'sniff']))->toBe(['level' => 'missing', 'note' => 'it should be nosniff'])
        ->and(level('referrer', ['Referrer-Policy' => 'no-referrer'])['level'])->toBe('good')
        ->and(level('referrer', ['Referrer-Policy' => 'unsafe-url, strict-origin-when-cross-origin'])['level'])->toBe('good')
        ->and(level('referrer', ['Referrer-Policy' => 'unsafe-url']))->toBe(['level' => 'weak', 'note' => 'unsafe-url sends full addresses to other sites'])
        ->and(level('referrer', ['Referrer-Policy' => 'whatever']))->toBe(['level' => 'missing', 'note' => 'browsers do not know "whatever"'])
        ->and(level('permissions', ['Permissions-Policy' => 'camera=()'])['level'])->toBe('good')
        ->and(level('permissions', ['Feature-Policy' => "camera 'none'"]))->toBe(['level' => 'weak', 'note' => 'only the old Feature-Policy header']);
});

test('the grade counts what is in place, and A needs every one strong', function () {
    $levels = fn (string ...$levels) => array_combine(array_keys(SECURITY_HEADERS), $levels);
    expect(headers_grade($levels('good', 'good', 'good', 'good', 'good', 'good')))->toBe('A')
        ->and(headers_grade($levels('weak', 'good', 'good', 'good', 'good', 'good')))->toBe('B')
        ->and(headers_grade($levels('missing', 'good', 'good', 'good', 'good', 'good')))->toBe('B')
        ->and(headers_grade($levels('missing', 'missing', 'good', 'good', 'good', 'good')))->toBe('C')
        ->and(headers_grade($levels('missing', 'missing', 'missing', 'good', 'weak', 'good')))->toBe('D')
        ->and(headers_grade($levels('missing', 'missing', 'good', 'good', 'missing', 'missing')))->toBe('E')
        ->and(headers_grade($levels('missing', 'missing', 'missing', 'missing', 'missing', 'good')))->toBe('F')
        ->and(headers_grade($levels('missing', 'missing', 'missing', 'missing', 'missing', 'missing')))->toBe('F');
});

test('a site with every protection strong gets an A, and Vaka itself does', function () {
    $strong = evaluate_headers(answered(STRONG_HEADERS + ['X-Frame-Options' => 'DENY']), 10);
    expect($strong['status'])->toBe('ok')
        ->and($strong['summary'])->toBe('Grade A: all 6 in place')
        ->and($strong['details']['grade'])->toBe('A');

    $vaka = evaluate_headers(answered(security_headers(['HTTPS' => 'on']), 'https://vaka.example/'), 10);
    expect($vaka['details']['grade'])->toBe('A');
});

test('the summary says the grade, what is missing and what is weak', function () {
    $typical = evaluate_headers(answered(['X-Frame-Options' => 'SAMEORIGIN', 'X-Content-Type-Options' => 'nosniff']), 10);
    expect($typical['status'])->toBe('warn')
        ->and($typical['summary'])->toBe('Grade E: 2 of 6 in place, missing HSTS, CSP, Referrer-Policy and Permissions-Policy')
        ->and($typical['details']['headers']['csp'])->toBe(['level' => 'missing', 'note' => '']);

    expect(evaluate_headers(answered([]), 10)['summary'])->toBe('Grade F: none of the 6 in place');

    $weak = evaluate_headers(answered(['Strict-Transport-Security' => 'max-age=86400'] + STRONG_HEADERS), 10);
    expect($weak['status'])->toBe('ok')
        ->and($weak['summary'])->toBe('Grade B: all 6 in place, HSTS is weak');
});

test('an error page or no answer is not graded', function () {
    expect(evaluate_headers(answered([], 'https://shop.example/', 503), 10))->toMatchArray(['status' => 'unknown', 'summary' => 'Could not check: the site answered 503 Service Unavailable'])
        ->and(evaluate_headers(answered([], 'https://shop.example/', 0, CURLE_OPERATION_TIMEDOUT), 10)['summary'])->toBe('Could not check: no answer within 10 s');
});

test('a bot check is not graded as the site', function () {
    // Imunify360's page has none of the site's headers: graded, every protection would look removed
    expect(evaluate_headers(['body' => bot_check_page()] + answered([]), 10))->toMatchArray(['status' => 'unknown', 'summary' => "Could not check: Imunify360's bot check answered instead of the site", 'details' => []])
        ->and(evaluate_headers(answered(['cf-mitigated' => 'challenge'], 'https://shop.example/', 403), 10)['summary'])->toBe("Could not check: Cloudflare's bot check answered instead of the site");
});

/** A graded result with these levels (in SECURITY_HEADERS order). */
function graded(string ...$levels): array
{
    $headers = [];
    foreach (array_keys(SECURITY_HEADERS) as $i => $key) $headers[$key] = ['level' => $levels[$i], 'note' => ''];
    $grade = headers_grade(array_column($headers, 'level'));
    return ['status' => in_array($grade, ['A', 'B'], true) ? 'ok' : 'warn', 'summary' => "Grade {$grade}", 'details' => ['grade' => $grade, 'headers' => $headers]];
}

test('a change counts only when the next check, 15 minutes later, agrees', function () {
    $first = confirm_headers(null, graded('missing', 'good', 'good', 'good', 'good', 'good'));
    expect($first['wait'])->toBe(HEADERS_INTERVAL_SECONDS)
        ->and($first['change'])->toBe('First check: grade B')
        ->and($first['details']['confirmed'])->toBe(['hsts' => 'missing', 'csp' => 'good', 'nosniff' => 'good', 'frames' => 'good', 'referrer' => 'good', 'permissions' => 'good']);

    $same = confirm_headers($first['details'], graded('missing', 'good', 'good', 'good', 'good', 'good'));
    expect($same['change'])->toBeNull()->and($same['wait'])->toBe(HEADERS_INTERVAL_SECONDS);

    $worse = graded('missing', 'missing', 'good', 'good', 'good', 'good');
    $seen = confirm_headers($first['details'], $worse);
    expect($seen['wait'])->toBe(HEADERS_CONFIRM_SECONDS)
        ->and($seen['change'])->toBeNull()
        ->and($seen['details']['unconfirmed'])->toBeTrue()
        ->and($seen['details']['confirmed']['csp'])->toBe('good');

    $confirmed = confirm_headers($seen['details'], $worse);
    expect($confirmed['change'])->toBe('Grade B to C: CSP removed')
        ->and($confirmed['details']['confirmed']['csp'])->toBe('missing')
        ->and($confirmed['details'])->not->toHaveKey('unconfirmed');

    // Back to how it was on the recheck: nothing happened
    $flap = confirm_headers($seen['details'], graded('missing', 'good', 'good', 'good', 'good', 'good'));
    expect($flap['change'])->toBeNull()->and($flap['wait'])->toBe(HEADERS_INTERVAL_SECONDS);
});

test('a site that could not be checked keeps what was confirmed', function () {
    $first = confirm_headers(null, graded('good', 'good', 'good', 'good', 'good', 'good'));
    $down = confirm_headers($first['details'], ['status' => 'unknown', 'summary' => 'Could not check', 'details' => []]);
    expect($down['wait'])->toBe(HEADERS_RETRY_SECONDS)
        ->and($down['change'])->toBeNull()
        ->and($down['details']['confirmed'])->toBe($first['details']['confirmed']);
});

test('changes are described in words', function () {
    $from = ['hsts' => 'good', 'csp' => 'good', 'nosniff' => 'good', 'frames' => 'missing', 'referrer' => 'weak', 'permissions' => 'good'];
    expect(headers_change($from, ['hsts' => 'missing', 'csp' => 'weak'] + $from))->toBe('Grade B to C: HSTS removed, CSP weaker')
        ->and(headers_change($from, ['frames' => 'good', 'referrer' => 'good'] + $from))->toBe('Grade B to A: frame protection added, Referrer-Policy stronger')
        ->and(headers_change($from, ['csp' => 'weak', 'referrer' => 'good'] + $from))->toBe('CSP weaker, Referrer-Policy stronger (grade still B)');
});

function headers_state(array $confirmed, string $told, array $overrides = []): array
{
    $levels = array_combine(array_keys(SECURITY_HEADERS), $confirmed);
    $headers = array_map(fn ($level) => ['level' => $level, 'note' => ''], $levels);
    return $overrides + ['status' => 'warn', 'alerted_status' => 'warn', 'details' => ['headers' => $headers, 'confirmed' => $levels], 'alerted_snapshot' => $told];
}

test('headers_notice alerts only when a protection gets worse', function () {
    $told = json_encode(array_combine(array_keys(SECURITY_HEADERS), ['good', 'good', 'good', 'good', 'good', 'good']));

    $state = headers_state(['missing', 'weak', 'good', 'good', 'good', 'good'], $told);
    $state['details']['headers']['csp']['note'] = "it allows inline scripts ('unsafe-inline')";
    $worse = headers_notice($state, null);
    expect($worse)->toMatchArray(['action' => 'send', 'kind' => 'headers_worse', 'from' => 'A', 'to' => 'B'])
        ->and($worse['worse'])->toBe([
            ['key' => 'hsts', 'label' => 'HSTS', 'change' => 'removed', 'note' => ''],
            ['key' => 'csp', 'label' => 'CSP', 'change' => 'weaker', 'note' => "it allows inline scripts ('unsafe-inline')"],
        ]);

    $better = headers_notice(headers_state(['good', 'good', 'good', 'good', 'good', 'good'], json_encode(array_combine(array_keys(SECURITY_HEADERS), ['missing', 'good', 'good', 'good', 'good', 'good']))), null);
    expect($better['action'])->toBe('mark');
    expect(headers_notice(headers_state(['good', 'good', 'good', 'good', 'good', 'good'], $told), null)['action'])->toBe('none');
});

test('headers_notice: a first grade is a baseline, quiet policies and unknown results send nothing', function () {
    $told = json_encode(array_combine(array_keys(SECURITY_HEADERS), ['good', 'good', 'good', 'good', 'good', 'good']));
    $worse = headers_state(['missing', 'good', 'good', 'good', 'good', 'good'], $told);
    expect(headers_notice(headers_state(['missing', 'missing', 'missing', 'good', 'good', 'good'], '[]', ['alerted_status' => 'pending']), null)['action'])->toBe('mark')
        ->and(headers_notice($worse, ['policy' => 'silent'])['action'])->toBe('mark')
        ->and(headers_notice($worse, ['policy' => 'digest'])['action'])->toBe('mark')
        ->and(headers_notice($worse, ['policy' => 'delayed', 'delay_minutes' => 30])['action'])->toBe('send')
        ->and(headers_notice(['status' => 'unknown'] + $worse, null)['action'])->toBe('none');
});

test('a header alert reads plainly', function () {
    $item = bundle_notices([notice([
        'kind' => 'headers_worse', 'check' => 'headers', 'from' => 'A', 'to' => 'B', 'server_key' => null, 'server' => null,
        'worse' => [['label' => 'HSTS', 'change' => 'removed', 'note' => ''], ['label' => 'CSP', 'change' => 'weaker', 'note' => "it allows inline scripts ('unsafe-inline')"]],
    ])], [])[0]['items'][0];
    expect(alert_text($item))->toBe([
        'headline' => 'shop.example (Customers): security headers got worse, grade A to B',
        'details' => ['HSTS was removed', "CSP is weaker: it allows inline scripts ('unsafe-inline')", 'If that was not on purpose, look at the last deploy, the web server or the CDN settings.'],
    ])
        ->and(alert_emoji('headers_worse'))->toBe(':warning:');
});

test('the files checked are the first stylesheet and the first script on the page\'s own host', function () {
    $html = <<<'HTML'
        <!doctype html><html><head>
        <link rel="preconnect" href="https://fonts.example">
        <link rel="icon" href="/favicon.ico">
        <link href='https://cdn.example/lib.css' rel=stylesheet>
        <link rel="alternate stylesheet" href="/contrast.css">
        <LINK REL="Stylesheet" HREF="/build/assets/site-4f2a.css?v=2&amp;x=1#top">
        <link rel="stylesheet" href="/second.css">
        <script>inline()</script>
        <script type="application/ld+json">{"@type": "Organization"}</script>
        <script src="data:text/javascript,1"></script>
        <script src="//shop.example/js/app.js" defer></script>
        <script src="/later.js"></script>
        </head></html>
        HTML;
    expect(page_files($html, 'https://shop.example/'))->toBe(['https://shop.example/build/assets/site-4f2a.css?v=2&x=1', 'https://shop.example/js/app.js'])
        ->and(page_files('<link rel="stylesheet" href="../theme/site.css"><script src="assets/app.js"></script>', 'https://shop.example/blog/post'))
            ->toBe(['https://shop.example/theme/site.css', 'https://shop.example/blog/assets/app.js'])
        ->and(page_files('<script src="/app.js"></script>', 'http://127.0.0.1:8794/shop'))->toBe(['http://127.0.0.1:8794/app.js'])
        // Another host, or another port on the same host, is another server
        ->and(page_files('<script src="https://www.shop.example/app.js"></script><script src="http://127.0.0.1:5173/app.js"></script>', 'http://127.0.0.1:8794/'))->toBe([])
        ->and(page_files('<h1>No files</h1>', 'https://shop.example/'))->toBe([]);
});

test('a host under the page\'s own counts as the site\'s server, a sibling or a CDN does not', function () {
    $static = '<link rel="stylesheet" href="https://static.shop.example/css/site.css"><script src="https://cdn.example/app.js"></script><script src="https://static.shop.example/js/app.js"></script>';
    expect(page_files($static, 'https://shop.example/'))->toBe(['https://static.shop.example/css/site.css', 'https://static.shop.example/js/app.js'])
        ->and(page_files($static, 'https://www.shop.example/'))->toBe(['https://static.shop.example/css/site.css', 'https://static.shop.example/js/app.js'])
        ->and(page_files('<script src="https://www.shop.example/app.js"></script>', 'https://shop.example/'))->toBe(['https://www.shop.example/app.js'])
        // A sibling of the page's host, another port, or an address by IP: not known to be the same server
        ->and(page_files($static, 'https://app.shop.example/'))->toBe([])
        ->and(page_files('<script src="https://static.shop.example:8443/app.js"></script>', 'https://shop.example/'))->toBe([])
        ->and(page_files('<script src="http://static.192.0.2.10/app.js"></script>', 'http://192.0.2.10/'))->toBe([]);

    // A file elsewhere than the page's host is named with its host
    expect(files_nosniff(['https://static.shop.example/js/app.js' => answered(['Content-Type' => 'text/javascript'], 'https://static.shop.example/js/app.js')], 'https://shop.example/'))
        ->toBe(['checked' => ['static.shop.example/js/app.js'], 'missing' => ['static.shop.example/js/app.js']]);
});

test('files_nosniff names the files without nosniff, and leaves out what says nothing about them', function () {
    $file = fn (string $url, array $headers = [], int $status = 200, string $type = 'text/css', ?string $final = null) => answered(['Content-Type' => $type] + $headers, $final ?? $url, $status);
    expect(files_nosniff([
        'https://shop.example/build/site.css?v=2' => $file('https://shop.example/build/site.css?v=2'),
        'https://shop.example/js/app.js' => $file('https://shop.example/js/app.js', ['X-Content-Type-Options' => 'nosniff'], 200, 'text/javascript'),
    ]))->toBe(['checked' => ['/build/site.css', '/js/app.js'], 'missing' => ['/build/site.css']]);

    // A missing file, a page from a fallback route, a redirect to another host and no answer
    expect(files_nosniff([
        'https://shop.example/gone.css' => $file('https://shop.example/gone.css', [], 404, 'text/html'),
        'https://shop.example/app.js' => $file('https://shop.example/app.js', [], 200, 'text/html; charset=utf-8'),
        'https://shop.example/lib.js' => $file('https://shop.example/lib.js', [], 200, 'text/javascript', 'https://cdn.example/lib.js'),
        'https://shop.example/slow.js' => answered([], 'https://shop.example/slow.js', 0, CURLE_OPERATION_TIMEDOUT),
    ]))->toBeNull();
});

test('X-Content-Type-Options is weak when the page has it but its scripts and styles do not', function () {
    $page = answered(STRONG_HEADERS + ['X-Frame-Options' => 'DENY']);
    $lacking = evaluate_headers($page, 10, ['checked' => ['/build/site.css', '/js/app.js'], 'missing' => ['/build/site.css']]);
    expect($lacking['status'])->toBe('ok')
        ->and($lacking['summary'])->toBe('Grade B: all 6 in place, X-Content-Type-Options is missing on scripts and styles')
        ->and($lacking['details']['grade'])->toBe('B')
        ->and($lacking['details']['headers']['nosniff'])->toBe(['level' => 'good', 'note' => ''])
        ->and($lacking['details']['files'])->toBe(['checked' => ['/build/site.css', '/js/app.js'], 'missing' => ['/build/site.css']]);

    $fine = evaluate_headers($page, 10, ['checked' => ['/build/site.css'], 'missing' => []]);
    expect($fine['summary'])->toBe('Grade A: all 6 in place')
        ->and($fine['details']['grade'])->toBe('A');

    // Below an A the grade stays, and the summary still says it
    expect(evaluate_headers(answered(['X-Frame-Options' => 'SAMEORIGIN', 'X-Content-Type-Options' => 'nosniff']), 10, ['checked' => ['/a.css'], 'missing' => ['/a.css']])['summary'])
        ->toBe('Grade E: 2 of 6 in place, missing HSTS, CSP, Referrer-Policy and Permissions-Policy, X-Content-Type-Options is missing on scripts and styles');

    // Without nosniff on the page itself, the files add nothing
    $bare = evaluate_headers(answered([]), 10, ['checked' => ['/a.css'], 'missing' => ['/a.css']]);
    expect($bare['summary'])->toBe('Grade F: none of the 6 in place')
        ->and($bare['details'])->not->toHaveKey('files');
});

test('the files are their own part of a change, so first seeing them is not a protection getting worse', function () {
    $page = answered(STRONG_HEADERS + ['X-Frame-Options' => 'DENY']);
    $good = ['checked' => ['/site.css', '/app.js'], 'missing' => []];
    $lacking = ['checked' => ['/site.css', '/app.js'], 'missing' => ['/site.css', '/app.js']];
    $twice = function (array $before, array $result): array {
        $seen = confirm_headers($before, $result);
        expect($seen['wait'])->toBe(HEADERS_CONFIRM_SECONDS);
        return confirm_headers($seen['details'], $result);
    };

    // Graded before the files were checked
    $old = confirm_headers(null, evaluate_headers($page, 10))['details'];
    expect($old['confirmed'])->not->toHaveKey('nosniff_files');

    $fine = $twice($old, evaluate_headers($page, 10, $good));
    expect($fine['change'])->toBeNull()
        ->and($fine['details']['confirmed']['nosniff_files'])->toBe('good');

    $first = $twice($old, evaluate_headers($page, 10, $lacking));
    expect($first['change'])->toBe('Grade A to B: X-Content-Type-Options not on scripts and styles');

    $lost = $twice($fine['details'], evaluate_headers($page, 10, $lacking));
    expect($lost['change'])->toBe('Grade A to B: X-Content-Type-Options removed from scripts and styles');

    $back = $twice($lost['details'], evaluate_headers($page, 10, $good));
    expect($back['change'])->toBe('Grade B to A: X-Content-Type-Options added to scripts and styles');

    // Gone from the page too: one change, and the files are no longer asked about
    $gone = $twice($fine['details'], evaluate_headers(answered(['X-Content-Type-Options' => 'none'] + STRONG_HEADERS + ['X-Frame-Options' => 'DENY']), 10, null));
    expect($gone['change'])->toBe('Grade A to B: X-Content-Type-Options removed');
});

test('headers_notice: nosniff leaving the scripts and styles alerts, first seeing it missing does not', function () {
    $levels = array_combine(array_keys(SECURITY_HEADERS), ['good', 'good', 'good', 'good', 'good', 'good']);
    $state = fn (string $files, string $told) => [
        'status' => 'ok', 'alerted_status' => 'ok', 'alerted_snapshot' => $told,
        'details' => ['headers' => array_map(fn ($level) => ['level' => $level, 'note' => ''], $levels), 'files' => ['checked' => ['/site.css', '/app.js'], 'missing' => $files === 'missing' ? ['/site.css', '/app.js'] : []], 'confirmed' => $levels + ['nosniff_files' => $files]],
    ];

    $first = headers_notice($state('missing', json_encode($levels)), null);
    expect($first['action'])->toBe('mark')
        ->and(json_decode($first['snapshot'], true)['nosniff_files'])->toBe('missing');

    $lost = headers_notice($state('missing', json_encode($levels + ['nosniff_files' => 'good'])), null);
    expect($lost)->toMatchArray(['action' => 'send', 'kind' => 'headers_worse', 'from' => 'A', 'to' => 'B'])
        ->and($lost['worse'])->toBe([['key' => 'nosniff_files', 'label' => 'X-Content-Type-Options on scripts and styles', 'change' => 'removed', 'note' => '/site.css and /app.js']]);

    expect(headers_notice($state('good', json_encode($levels + ['nosniff_files' => 'missing'])), null)['action'])->toBe('mark');
});

test('the site detail says which files lack nosniff and where the line goes', function () {
    $page = answered(STRONG_HEADERS + ['X-Frame-Options' => 'DENY']);
    $nosniff = fn (?array $files) => array_values(array_filter(header_items(evaluate_headers($page, 10, $files)['details']), fn ($item) => $item['key'] === 'nosniff'))[0];

    expect($nosniff(['checked' => ['/build/site.css', '/js/app.js'], 'missing' => ['/build/site.css', '/js/app.js']]))->toMatchArray([
        'level' => 'weak',
        'note' => 'not on the scripts and styles the page loads: /build/site.css and /js/app.js',
        'fix' => 'X-Content-Type-Options: nosniff',
        'tip' => 'A web server rule for these files that adds headers of its own, such as Cache-Control, drops the ones set for the whole site (in nginx, a location with its own add_header). Set the headers once for the whole site and keep add_header out of such rules: for caching, nginx\'s expires sets Cache-Control without it. Otherwise every such rule has to repeat every header.',
    ])
        ->and($nosniff(['checked' => ['/build/site.css'], 'missing' => []]))->toMatchArray(['level' => 'good', 'note' => 'also on the scripts and styles the page loads'])
        ->and($nosniff(null))->toMatchArray(['level' => 'good', 'note' => '']);
});

test('a header alert for the scripts and styles says where to look', function () {
    $item = bundle_notices([notice([
        'kind' => 'headers_worse', 'check' => 'headers', 'from' => 'A', 'to' => 'B', 'server_key' => null, 'server' => null,
        'worse' => [['key' => 'nosniff_files', 'label' => 'X-Content-Type-Options on scripts and styles', 'change' => 'removed', 'note' => '/site.css and /app.js']],
    ])], [])[0]['items'][0];
    expect(alert_text($item)['details'])->toBe([
        'X-Content-Type-Options on scripts and styles was removed: /site.css and /app.js',
        'A web server rule for those files that adds headers of its own, such as Cache-Control, drops the ones set for the whole site (in nginx, a location with its own add_header). Set the headers once for the whole site and keep add_header out of such rules: for caching, nginx\'s expires sets Cache-Control without it.',
        'If that was not on purpose, look at the last deploy, the web server or the CDN settings.',
    ]);
});

test('the protections come in the order to add them: the safest first, CSP last', function () {
    expect(array_column(header_items(evaluate_headers(answered([]), 10)['details']), 'key'))->toBe(['nosniff', 'referrer', 'frames', 'hsts', 'permissions', 'csp']);
});

// =====================================================================
// CSP: Report-Only first, and what the page has that it would block
// =====================================================================

test('page_scripts counts the inline scripts that would run, starts each, and names the hosts other scripts come from', function () {
    $html = '<!-- <script>old()</script> -->'
        . '<script>document.documentElement.dataset.theme = localStorage.theme || "light"</script>'
        . '<script type="module">import "/app.js"; if (a < b) start()</script>'
        . '<script type="application/ld+json">{"@type": "Organization"}</script>'
        . '<script></script>'
        . '<script src="/app.js"></script>'
        . '<script defer src="https://analytics.example/a.js"></script>'
        . '<script src="//analytics.example/b.js"></script>'
        . '<script src="https://static.shop.example/x.js"></script>'
        . '<SCRIPT TYPE="text/javascript">track()</SCRIPT>';
    expect(page_scripts($html, 'https://shop.example/'))->toMatchArray([
        'inline' => 3,
        'hosts' => ['analytics.example', 'static.shop.example'],
        // The start of each, to find it by in the site's code
        'excerpts' => ['document.documentElement.dataset.theme = localStorage.theme…', 'import "/app.js"; if (a < b) start()', 'track()'],
    ])
        ->and(page_scripts('<script src="/app.js"></script><script src="https://shop.example/b.js"></script>', 'https://shop.example/'))->toMatchArray(['inline' => 0, 'hosts' => [], 'excerpts' => []])
        ->and(page_scripts('<script>a()</script>', 'http://127.0.0.1:8794/'))->toMatchArray(['inline' => 1, 'hosts' => [], 'excerpts' => ['a()']])
        ->and(page_scripts('<script src="http://127.0.0.1:5173/app.js"></script>', 'http://127.0.0.1:8794/'))->toMatchArray(['inline' => 0, 'hosts' => ['127.0.0.1:5173'], 'excerpts' => []]);
});

test('page_scripts starts at most five inline scripts, on one line each', function () {
    $html = "<script>\n    window.dataLayer = window.dataLayer || [];\n    gtag('js', new Date());\n</script>" . str_repeat('<script>b()</script>', 6);
    $scripts = page_scripts($html, 'https://shop.example/');
    expect($scripts['inline'])->toBe(7)
        ->and($scripts['excerpts'])->toBe(["window.dataLayer = window.dataLayer || []; gtag('js', new Da…", 'b()', 'b()', 'b()', 'b()']);
});

test('without a CSP, the suggestion is Report-Only, and the note says what default-src self would block on the page', function () {
    $csp = fn (array $response) => array_values(array_filter(header_items(evaluate_headers($response, 10)['details']), fn ($item) => $item['key'] === 'csp'))[0];
    $page = fn (string $body, array $headers = []) => ['body' => $body] + answered($headers + ['X-Frame-Options' => 'DENY']);

    $plain = $csp($page('<script src="/app.js"></script>'));
    expect($plain['fix'])->toBe("Content-Security-Policy-Report-Only: default-src 'self'")
        ->and($plain['note'])->toBe('')
        ->and($plain['tip'])->toContain("open a few pages with the browser's console open")
        ->and($plain['tip'])->toContain('rename the header to Content-Security-Policy')
        ->and($plain['tip'])->not->toContain('sha256')
        // The one that can break a site
        ->and($plain['tip'])->toContain('fine to do it last');

    $both = $csp($page('<script>theme()</script><script type="module">go()</script><script src="https://analytics.example/a.js"></script>'));
    expect($both['note'])->toBe("the page has 2 inline scripts and scripts from analytics.example, which default-src 'self' would block")
        // Which ones, by their start
        ->and($both['inline'])->toBe(['theme()', 'go()'])
        ->and($plain)->not->toHaveKey('inline')
        ->and($both['fix'])->toBe("Content-Security-Policy-Report-Only: default-src 'self'")
        ->and($both['tip'])->toContain("'sha256-...'")
        ->and($both['tip'])->toContain('changes whenever the script does');

    expect($csp($page('<script>theme()</script>'))['note'])->toBe("the page has 1 inline script, which default-src 'self' would block")
        ->and($csp($page('<script src="https://a.example/1.js"></script><script src="https://b.example/2.js"></script><script src="https://c.example/3.js"></script><script src="https://d.example/4.js"></script><script src="https://e.example/5.js"></script>'))['note'])
        ->toBe("the page loads scripts from a.example, b.example, c.example and 2 more hosts, which default-src 'self' would block");

    // Already there as Report-Only: the step after is to enforce it
    $trying = $csp($page('<script>theme()</script>', ['Content-Security-Policy-Report-Only' => "default-src 'self'"]));
    expect($trying['note'])->toBe('only as Report-Only, which blocks nothing; enforced, it would block 1 inline script on the page')
        ->and($trying['fix'])->toBe('Content-Security-Policy: <the same policy>')
        ->and($trying['tip'])->toContain("once the browser's console shows nothing it would block that the site needs");

    // A CSP in place says nothing about the page's scripts, and stores none
    $enforced = evaluate_headers($page('<script>theme()</script>', ['Content-Security-Policy' => "script-src 'self' 'sha256-abc'"]), 10);
    expect($enforced['details'])->not->toHaveKey('scripts');
});

test('the web server that answered is kept, so the dashboard offers the lines in its form: nginx, Apache (LiteSpeed reads .htaccess), nothing behind a CDN', function () {
    expect(web_server(['server' => ['nginx/1.24.0']]))->toBe('nginx')
        ->and(web_server(['server' => ['openresty']]))->toBe('nginx')
        ->and(web_server(['server' => ['Apache/2.4.58 (Ubuntu)']]))->toBe('apache')
        ->and(web_server(['server' => ['LiteSpeed']]))->toBe('apache')
        ->and(web_server(['server' => ['cloudflare']]))->toBe('')
        ->and(web_server([]))->toBe('');

    $nginx = evaluate_headers(answered(['Server' => 'nginx'] + STRONG_HEADERS), 10);
    expect($nginx['details']['server'])->toBe('nginx')
        ->and(evaluate_headers(answered(STRONG_HEADERS), 10)['details'])->not->toHaveKey('server')
        // For the site detail and the read API, with how to add them in nginx and in Apache
        ->and(headers_advice($nginx['details']))->toMatchArray(['server' => 'nginx', 'tip' => HEADERS_NGINX_TIP, 'tip_apache' => HEADERS_APACHE_TIP])
        ->and(headers_advice($nginx['details'])['items'])->toHaveCount(6)
        ->and(headers_advice([]))->toBe(['items' => [], 'server' => '', 'tip' => HEADERS_NGINX_TIP, 'tip_apache' => HEADERS_APACHE_TIP]);
});

test('a CSP source allows a script URL as browsers read it', function () {
    $page = 'https://shop.example/';
    expect(csp_source_allows("'self'", 'https://shop.example/app.js', $page))->toBeTrue()
        ->and(csp_source_allows("'self'", 'https://cdn.example/app.js', $page))->toBeFalse()
        ->and(csp_source_allows("'self'", 'http://shop.example/app.js', $page))->toBeFalse()
        ->and(csp_source_allows("'self'", 'https://shop.example/app.js', 'http://shop.example/'))->toBeTrue()
        ->and(csp_source_allows("'none'", 'https://shop.example/app.js', $page))->toBeFalse()
        ->and(csp_source_allows('*', 'https://cdn.example/app.js', $page))->toBeTrue()
        ->and(csp_source_allows('https:', 'https://cdn.example/app.js', $page))->toBeTrue()
        ->and(csp_source_allows('https:', 'http://cdn.example/app.js', $page))->toBeFalse()
        ->and(csp_source_allows('http:', 'https://cdn.example/app.js', $page))->toBeTrue()
        ->and(csp_source_allows('https://cdn.example', 'https://cdn.example/a/b.js', $page))->toBeTrue()
        ->and(csp_source_allows('https://cdn.example', 'https://CDN.example/a/b.js', $page))->toBeTrue()
        ->and(csp_source_allows('https://cdn.example', 'http://cdn.example/a/b.js', $page))->toBeFalse()
        ->and(csp_source_allows('cdn.example', 'http://cdn.example/a/b.js', $page))->toBeTrue()
        ->and(csp_source_allows('cdn.example', 'https://static.cdn.example/a.js', $page))->toBeFalse()
        ->and(csp_source_allows('*.cdn.example', 'https://static.cdn.example/a.js', $page))->toBeTrue()
        ->and(csp_source_allows('*.cdn.example', 'https://cdn.example/a.js', $page))->toBeFalse()
        ->and(csp_source_allows('https://cdn.example:8443', 'https://cdn.example:8443/a.js', $page))->toBeTrue()
        ->and(csp_source_allows('https://cdn.example:8443', 'https://cdn.example/a.js', $page))->toBeFalse()
        ->and(csp_source_allows('https://cdn.example:*', 'https://cdn.example:8443/a.js', $page))->toBeTrue()
        ->and(csp_source_allows('https://cdn.example/js/', 'https://cdn.example/js/a.js', $page))->toBeTrue()
        ->and(csp_source_allows('https://cdn.example/js/', 'https://cdn.example/css/a.js', $page))->toBeFalse()
        ->and(csp_source_allows('https://cdn.example/js/a.js', 'https://cdn.example/js/a.js', $page))->toBeTrue()
        ->and(csp_source_allows('https://cdn.example/js/a.js', 'https://cdn.example/js/b.js', $page))->toBeFalse()
        // Keywords and hashes say nothing about a URL
        ->and(csp_source_allows("'unsafe-inline'", 'https://cdn.example/a.js', $page))->toBeFalse()
        ->and(csp_source_allows("'sha256-abc'", 'https://cdn.example/a.js', $page))->toBeFalse();
});

test('page_scripts keeps each script for the CSP comparison: inline ones with a hash, external ones with their URL, and whether each has a nonce', function () {
    $html = '<script>theme()</script><script nonce="r4nd0m">go()</script><script src="/app.js"></script><script src="https://cdn.example/w.js" nonce="r4nd0m"></script><script type="application/ld+json">{}</script>';
    $scripts = page_scripts($html, 'https://shop.example/');
    expect($scripts['inline_scripts'])->toBe([
        ['hash' => 'sha256-' . base64_encode(hash('sha256', 'theme()', true)), 'start' => 'theme()', 'nonce' => false],
        ['hash' => 'sha256-' . base64_encode(hash('sha256', 'go()', true)), 'start' => 'go()', 'nonce' => true],
    ])
        ->and($scripts['external'])->toBe([['url' => 'https://shop.example/app.js', 'nonce' => false], ['url' => 'https://cdn.example/w.js', 'nonce' => true]])
        // As before: the count, the hosts and the starts
        ->and($scripts['inline'])->toBe(2)->and($scripts['hosts'])->toBe(['cdn.example'])->and($scripts['excerpts'])->toBe(['theme()', 'go()']);
});

test('csp_blocked says which of the page\'s scripts the policy stops: hosts not allowed and inline scripts without a matching hash', function () {
    $page = 'https://shop.example/';
    $theme = 'sha256-' . base64_encode(hash('sha256', 'theme()', true));
    $scripts = page_scripts('<script>theme()</script><script>go()</script><script src="/app.js"></script><script src="https://puls.example/?js"></script><script src="https://cdn.example/w.js"></script>', $page);
    $blocked = fn (string ...$policies) => csp_blocked(array_map('csp_directives', $policies), $scripts, $page);

    expect($blocked("default-src 'self'; script-src 'self' https://puls.example"))->toBe(['scripts' => ['https://cdn.example/w.js'], 'inline' => ['theme()', 'go()'], 'inline_total' => 2])
        // A hash lets that inline script through, and then 'unsafe-inline' is ignored, as browsers do
        ->and($blocked("script-src 'self' https://puls.example https://cdn.example '{$theme}'"))->toBe(['scripts' => [], 'inline' => ['go()'], 'inline_total' => 1])
        ->and($blocked("script-src 'self' https://puls.example https://cdn.example '{$theme}' 'unsafe-inline'"))->toBe(['scripts' => [], 'inline' => ['go()'], 'inline_total' => 1])
        ->and($blocked("script-src 'self' 'unsafe-inline' https://puls.example https://cdn.example"))->toBe(['scripts' => [], 'inline' => [], 'inline_total' => 0])
        // No directive for scripts: nothing is stopped. default-src is the fallback, script-src-elem wins over script-src
        ->and($blocked("img-src 'self'"))->toBe(['scripts' => [], 'inline' => [], 'inline_total' => 0])
        ->and($blocked("default-src 'none'")['scripts'])->toBe(['https://shop.example/app.js', 'https://puls.example/?js', 'https://cdn.example/w.js'])
        ->and($blocked("default-src 'none'; script-src 'self' 'unsafe-inline' https:")['scripts'])->toBe([])
        ->and($blocked("script-src 'self' 'unsafe-inline' https:; script-src-elem 'self'")['scripts'])->toBe(['https://puls.example/?js', 'https://cdn.example/w.js'])
        // Several policies all apply: what any of them stops is stopped
        ->and($blocked("script-src 'self' 'unsafe-inline' https:", "script-src 'self' 'unsafe-inline' https://puls.example")['scripts'])->toBe(['https://cdn.example/w.js'])
        ->and($blocked("script-src 'self' 'unsafe-inline' https:", "script-src 'self' 'unsafe-inline' https://puls.example")['inline_total'])->toBe(0)
        // Hashes Vaka does not compute say nothing: taken as allowed rather than wrongly blocked
        ->and($blocked("script-src 'self' https://puls.example https://cdn.example 'sha384-abc'"))->toBe(['scripts' => [], 'inline' => [], 'inline_total' => 0]);

    // With a nonce in the policy, a script with a nonce attribute is taken as allowed (Vaka cannot see the nonce it was sent with)
    $nonced = page_scripts('<script nonce="x">theme()</script><script>go()</script><script src="https://cdn.example/w.js" nonce="x"></script><script src="https://other.example/o.js"></script>', $page);
    expect(csp_blocked([csp_directives("script-src 'nonce-abc' 'self'")], $nonced, $page))->toBe(['scripts' => ['https://other.example/o.js'], 'inline' => ['go()'], 'inline_total' => 1])
        // strict-dynamic: hosts no longer count, a nonce does
        ->and(csp_blocked([csp_directives("script-src 'nonce-abc' 'strict-dynamic' https://other.example")], $nonced, $page))->toBe(['scripts' => ['https://other.example/o.js'], 'inline' => ['go()'], 'inline_total' => 1]);

    // At most ten URLs and five starts are kept; the total still counts
    $many = page_scripts(str_repeat('<script>a()</script>', 7) . implode('', array_map(fn ($i) => "<script src=\"https://cdn{$i}.example/w.js\"></script>", range(1, 12))), $page);
    $capped = csp_blocked([csp_directives("script-src 'self'")], $many, $page);
    expect($capped['scripts'])->toHaveCount(10)->and($capped['inline'])->toHaveCount(5)->and($capped['inline_total'])->toBe(7);
});

test('an enforced CSP that blocks scripts on the page is said in the summary, warns, and is its own part of the levels', function () {
    $page = fn (string $body, array $headers) => ['body' => $body] + answered($headers + array_diff_key(STRONG_HEADERS, ['Content-Security-Policy' => 0]) + ['X-Frame-Options' => 'DENY']);
    $body = '<script>theme()</script><script src="https://cdn.example/w.js"></script><script src="/app.js"></script>';

    $blocking = evaluate_headers($page($body, ['Content-Security-Policy' => "default-src 'self'"]), 10);
    expect($blocking['details']['grade'])->toBe('A')
        ->and($blocking['status'])->toBe('warn')
        ->and($blocking['summary'])->toBe('Grade A: all 6 in place, the CSP blocks 1 inline script and a script from cdn.example on the page')
        ->and($blocking['details']['csp_blocks'])->toBe(['scripts' => ['https://cdn.example/w.js'], 'inline' => ['theme()'], 'inline_total' => 1])
        ->and($blocking['details'])->not->toHaveKey('scripts')
        ->and(header_levels($blocking['details'])['csp_blocks'])->toBe('weak')
        ->and(headers_grade(header_levels($blocking['details'])))->toBe('A');

    $fine = evaluate_headers($page($body, ['Content-Security-Policy' => "default-src 'self'; script-src 'self' https://cdn.example 'sha256-" . base64_encode(hash('sha256', 'theme()', true)) . "'"]), 10);
    expect($fine['status'])->toBe('ok')
        ->and($fine['summary'])->toBe('Grade A: all 6 in place')
        ->and($fine['details']['csp_blocks'])->toBe(['scripts' => [], 'inline' => [], 'inline_total' => 0])
        ->and(header_levels($fine['details'])['csp_blocks'])->toBe('good');

    // Report-Only: nothing is blocked, but what would be is kept for the advice
    $trying = evaluate_headers($page($body, ['Content-Security-Policy-Report-Only' => "default-src 'self'"]), 10);
    expect($trying['status'])->toBe('ok')
        ->and($trying['summary'])->toBe('Grade B: 5 of 6 in place, missing CSP')
        ->and($trying['details']['csp_would_block'])->toBe(['scripts' => ['https://cdn.example/w.js'], 'inline' => ['theme()'], 'inline_total' => 1])
        ->and(header_levels($trying['details']))->not->toHaveKey('csp_blocks')
        ->and($trying['details']['scripts']['inline'])->toBe(1);

    // Without a CSP there is nothing to compare, and the levels have no such part
    expect(header_levels(evaluate_headers($page($body, []), 10)['details']))->not->toHaveKey('csp_blocks');
});

test('the site detail says what the CSP blocks, or what a Report-Only one would, and when it is safe to enforce', function () {
    $csp = fn (array $result) => array_values(array_filter(header_items($result['details']), fn ($item) => $item['key'] === 'csp'))[0];
    $page = fn (string $body, array $headers) => ['body' => $body] + answered($headers + array_diff_key(STRONG_HEADERS, ['Content-Security-Policy' => 0]) + ['X-Frame-Options' => 'DENY']);
    $body = '<script>theme()</script><script>go()</script><script src="https://cdn.example/w.js"></script>';

    $blocking = $csp(evaluate_headers($page($body, ['Content-Security-Policy' => "default-src 'self'"]), 10));
    expect($blocking['level'])->toBe('good')
        ->and($blocking['blocks'])->toBeTrue()
        ->and($blocking['note'])->toBe('it blocks 2 inline scripts and a script from cdn.example on the page')
        ->and($blocking['inline'])->toBe(['theme()', 'go()'])
        ->and($blocking['blocked'])->toBe(['https://cdn.example/w.js'])
        ->and($blocking['fix'])->toBe('')
        ->and($blocking['tip'])->toContain("a host goes in script-src")
        ->and($blocking['tip'])->toContain("'sha256-...' hash");

    $fine = $csp(evaluate_headers($page($body, ['Content-Security-Policy' => "script-src 'self' 'unsafe-inline' https://cdn.example"]), 10));
    expect($fine['blocks'])->toBeFalse()->and($fine)->not->toHaveKey('blocked');

    $trying = $csp(evaluate_headers($page($body, ['Content-Security-Policy-Report-Only' => "default-src 'self'"]), 10));
    expect($trying['level'])->toBe('missing')
        ->and($trying['blocks'])->toBeFalse()
        ->and($trying['note'])->toBe('only as Report-Only, which blocks nothing; enforced, it would block 2 inline scripts and a script from cdn.example on the page')
        ->and($trying['inline'])->toBe(['theme()', 'go()'])
        ->and($trying['blocked'])->toBe(['https://cdn.example/w.js'])
        ->and($trying['fix'])->toBe('Content-Security-Policy: <the same policy>');

    $ready = $csp(evaluate_headers($page($body, ['Content-Security-Policy-Report-Only' => "script-src 'self' 'unsafe-inline' https://cdn.example"]), 10));
    expect($ready['note'])->toBe('only as Report-Only, which blocks nothing; enforced, it would block nothing on the page, so it is safe to rename')
        ->and($ready)->not->toHaveKey('blocked');
});

test('a CSP starting or stopping to block scripts is a change, confirmed like the others, and an alert only when it starts', function () {
    $levels = fn (string $csp, ?string $blocks) => array_combine(array_keys(SECURITY_HEADERS), ['good', $csp, 'good', 'good', 'good', 'good']) + ($blocks ? ['csp_blocks' => $blocks] : []);
    expect(headers_change($levels('good', 'good'), $levels('good', 'weak')))->toBe('CSP blocks scripts on the page (grade still A)')
        ->and(headers_change($levels('good', 'weak'), $levels('good', 'good')))->toBe('CSP no longer blocks scripts on the page (grade still A)')
        ->and(headers_change($levels('missing', null), $levels('good', 'weak')))->toBe('Grade B to A: CSP added, CSP blocks scripts on the page')
        ->and(headers_change($levels('good', 'weak'), $levels('missing', null)))->toBe('Grade A to B: CSP removed')
        ->and(headers_change($levels('good', null), $levels('good', 'good')))->toBe('');

    // A blocking CSP alerts also when it is new: before it, nothing was blocked
    $told = json_encode($levels('missing', null));
    $state = headers_state(['good', 'good', 'good', 'good', 'good', 'good'], $told);
    $state['details']['confirmed']['csp_blocks'] = 'weak';
    $state['details']['csp_blocks'] = ['scripts' => ['https://cdn.example/w.js'], 'inline' => ['theme()'], 'inline_total' => 1];
    $notice = headers_notice($state, null);
    expect($notice)->toMatchArray(['action' => 'send', 'kind' => 'headers_worse', 'from' => 'B', 'to' => 'A'])
        ->and($notice['worse'])->toBe([['key' => 'csp_blocks', 'label' => 'CSP', 'change' => 'blocks', 'note' => '1 inline script and a script from cdn.example']]);
    // Still blocking the same: nothing new to tell; no longer blocking: noted quietly
    expect(headers_notice($state, null)['snapshot'])->toBe(json_encode($state['details']['confirmed']));
    $same = $state;
    $same['alerted_snapshot'] = json_encode($state['details']['confirmed']);
    expect(headers_notice($same, null)['action'])->toBe('none');
    $fixed = $state;
    $fixed['details']['confirmed']['csp_blocks'] = 'good';
    $fixed['alerted_snapshot'] = json_encode($state['details']['confirmed']);
    expect(headers_notice($fixed, null)['action'])->toBe('mark');

    $item = bundle_notices([notice([
        'kind' => 'headers_worse', 'check' => 'headers', 'from' => 'B', 'to' => 'A', 'server_key' => null, 'server' => null,
        'worse' => [['key' => 'csp_blocks', 'label' => 'CSP', 'change' => 'blocks', 'note' => '1 inline script and a script from cdn.example']],
    ])], [])[0]['items'][0];
    expect(alert_text($item))->toBe([
        'headline' => 'shop.example (Customers): security headers got worse, grade B to A',
        'details' => [
            'CSP blocks scripts on the page: 1 inline script and a script from cdn.example',
            "Visitors do not get those scripts. Allow what the page needs (a host goes in script-src, an inline script needs a 'sha256-...' hash there or a file of its own), or take them off the page. The site detail names them.",
            'If that was not on purpose, look at the last deploy, the web server or the CDN settings.',
        ],
    ]);
});
