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
        'tip' => 'A web server rule for these files that adds headers of its own, such as Cache-Control, drops the ones set for the whole site (in nginx, a location with its own add_header). Add the line to that rule too.',
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
        'A web server rule for those files that adds headers of its own, such as Cache-Control, drops the ones set for the whole site (in nginx, a location with its own add_header): add X-Content-Type-Options: nosniff to that rule too.',
        'If that was not on purpose, look at the last deploy, the web server or the CDN settings.',
    ]);
});
