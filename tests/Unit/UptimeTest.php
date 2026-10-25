<?php

function response(array $overrides = []): array
{
    return $overrides + ['status' => 200, 'headers' => [], 'body' => '<h1>Welcome to the shop</h1>', 'ms' => 231, 'ip' => '192.0.2.10', 'errno' => 0, 'error' => '', 'url' => 'https://shop.example'];
}

test('a 200 answer is up', function () {
    expect(evaluate_uptime(response(), '', 10))->toBe([
        'ok' => true,
        'summary' => 'Answered 200 OK in 231 ms',
        'status_code' => 200,
        'ms' => 231,
        'ip' => '192.0.2.10',
        'cdn' => '',
    ]);
});

test('a redirect that ends without a location still counts as up', function () {
    expect(evaluate_uptime(response(['status' => 304]), '', 10)['ok'])->toBeTrue();
});

test('an error status is down, in plain words', function () {
    $result = evaluate_uptime(response(['status' => 503]), '', 10);
    expect($result['ok'])->toBeFalse()
        ->and($result['summary'])->toBe('The site answered 503 Service Unavailable');
    expect(evaluate_uptime(response(['status' => 418]), '', 10)['summary'])->toBe('The site answered 418');
});

test('connection problems are explained, not shown as curl codes', function (int $errno, string $error, string $summary) {
    $result = evaluate_uptime(response(['status' => 0, 'errno' => $errno, 'error' => $error, 'body' => '']), '', 10);
    expect($result['ok'])->toBeFalse()
        ->and($result['summary'])->toBe($summary);
})->with([
    'timeout' => [28, 'Operation timed out after 10001 milliseconds', 'No answer within 10 s'],
    'dns' => [6, 'Could not resolve host: shop.example', 'The domain does not resolve (DNS lookup failed)'],
    'refused' => [7, 'Failed to connect to shop.example port 443 after 2 ms: Connection refused', 'The server refused the connection'],
    'unreachable' => [7, "Failed to connect to shop.example port 443 after 75003 ms: Couldn't connect to server", 'Could not connect to the server'],
    'certificate' => [60, 'SSL certificate problem: certificate has expired', 'The certificate is not accepted: SSL certificate problem: certificate has expired'],
    'tls' => [35, 'OpenSSL SSL_connect: SSL_ERROR_SYSCALL', 'The secure connection failed: OpenSSL SSL_connect: SSL_ERROR_SYSCALL'],
    'redirects' => [47, 'Maximum (5) redirects followed', 'Too many redirects (more than 5)'],
    'other' => [56, 'Recv failure: Connection reset by peer', 'Could not reach the site: Recv failure: Connection reset by peer'],
]);

test('a keyword must be on the page, in any case and with entities decoded', function () {
    $page = response(['body' => '<h1>Welcome to the SHOP</h1><p>R&auml;ksm&ouml;rg&aring;s</p>']);
    expect(evaluate_uptime($page, 'welcome to the shop', 10)['ok'])->toBeTrue()
        ->and(evaluate_uptime($page, 'Räksmörgås', 10)['ok'])->toBeTrue();

    $missing = evaluate_uptime($page, 'Add to cart', 10);
    expect($missing['ok'])->toBeFalse()
        ->and($missing['summary'])->toBe('Answered 200 OK, but the page does not contain "Add to cart"');
});

test('the keyword is not looked for on an error page', function () {
    expect(evaluate_uptime(response(['status' => 500]), 'Welcome', 10)['summary'])->toBe('The site answered 500 Internal Server Error');
});

test('detect_cdn names the CDN from its response headers', function (array $headers, string $cdn) {
    expect(detect_cdn($headers))->toBe($cdn);
})->with([
    'cloudflare ray' => [['cf-ray' => ['0123456789abcdef-ARN']], 'Cloudflare'],
    'cloudflare server' => [['server' => ['cloudflare']], 'Cloudflare'],
    'cloudfront' => [['x-amz-cf-id' => ['abc']], 'CloudFront'],
    'fastly' => [['x-served-by' => ['cache-arn1234-ARN']], 'Fastly'],
    'akamai' => [['server' => ['AkamaiGHost']], 'Akamai'],
    'bunny' => [['server' => ['BunnyCDN-DE1-123']], 'Bunny'],
    'none' => [['server' => ['nginx']], ''],
]);

test('the first successful check is a baseline event', function () {
    $next = next_uptime_state(['status' => 'pending', 'fail_count' => 0, 'changed_at' => null, 'details' => []], evaluate_uptime(response(), '', 10), 2, '2026-10-25T12:00:00Z');

    expect($next['state'])->toMatchArray(['status' => 'ok', 'fail_count' => 0, 'changed_at' => '2026-10-25T12:00:00Z', 'summary' => 'Answered 200 OK in 231 ms'])
        ->and($next['event'])->toBe(['status' => 'ok', 'previous' => 'pending', 'message' => 'First check: answered 200 OK in 231 ms']);
});

test('one failure is not down yet', function () {
    $up = ['status' => 'ok', 'fail_count' => 0, 'changed_at' => '2026-10-25T08:00:00Z', 'details' => []];
    $next = next_uptime_state($up, evaluate_uptime(response(['status' => 502]), '', 10), 2, '2026-10-25T12:00:00Z');

    expect($next['state'])->toMatchArray(['status' => 'ok', 'fail_count' => 1, 'changed_at' => '2026-10-25T08:00:00Z', 'summary' => 'Failed once, checking again: the site answered 502 Bad Gateway'])
        ->and($next['state']['details']['failing_since'])->toBe('2026-10-25T12:00:00Z')
        ->and($next['event'])->toBeNull();
});

test('the second failure in a row is down, counted from the first', function () {
    $failing = ['status' => 'ok', 'fail_count' => 1, 'changed_at' => '2026-10-25T08:00:00Z', 'details' => ['failing_since' => '2026-10-25T12:00:00Z']];
    $next = next_uptime_state($failing, evaluate_uptime(response(['status' => 502]), '', 10), 2, '2026-10-25T12:01:00Z');

    expect($next['state'])->toMatchArray(['status' => 'fail', 'fail_count' => 2, 'changed_at' => '2026-10-25T12:00:00Z', 'summary' => 'The site answered 502 Bad Gateway'])
        ->and($next['event'])->toBe(['status' => 'fail', 'previous' => 'ok', 'message' => 'Down: the site answered 502 Bad Gateway']);
});

test('staying down updates the reason without a new event', function () {
    $down = ['status' => 'fail', 'fail_count' => 2, 'changed_at' => '2026-10-25T12:00:00Z', 'details' => ['failing_since' => '2026-10-25T12:00:00Z']];
    $next = next_uptime_state($down, evaluate_uptime(response(['status' => 0, 'errno' => 28, 'error' => 'timeout', 'body' => '']), '', 10), 2, '2026-10-25T12:05:00Z');

    expect($next['state'])->toMatchArray(['status' => 'fail', 'fail_count' => 3, 'changed_at' => '2026-10-25T12:00:00Z', 'summary' => 'No answer within 10 s'])
        ->and($next['event'])->toBeNull();
});

test('coming back up says how long the site was down', function () {
    $down = ['status' => 'fail', 'fail_count' => 14, 'changed_at' => '2026-10-25T12:01:00Z', 'details' => ['failing_since' => '2026-10-25T12:00:00Z']];
    $next = next_uptime_state($down, evaluate_uptime(response(), '', 10), 2, '2026-10-25T12:14:30Z');

    expect($next['state'])->toMatchArray(['status' => 'ok', 'fail_count' => 0, 'changed_at' => '2026-10-25T12:14:30Z'])
        ->and($next['state']['details'])->not->toHaveKey('failing_since')
        ->and($next['event'])->toBe(['status' => 'ok', 'previous' => 'fail', 'message' => 'Up again after 14 min: answered 200 OK in 231 ms']);
});

test('coming back up keeps how long the site was down, for the recovery alert', function () {
    $down = ['status' => 'fail', 'fail_count' => 14, 'changed_at' => '2026-10-25T12:01:00Z', 'details' => ['failing_since' => '2026-10-25T12:00:00Z']];
    $up = next_uptime_state($down, evaluate_uptime(response(), '', 10), 2, '2026-10-25T12:14:30Z');
    expect($up['state']['details']['down_seconds'])->toBe(870);

    // and forgets it on the next healthy check
    $later = next_uptime_state($up['state'], evaluate_uptime(response(), '', 10), 2, '2026-10-25T12:19:30Z');
    expect($later['state']['details'])->not->toHaveKey('down_seconds');
});

test('a failure that recovers before it counts leaves no trace in the events', function () {
    $failing = ['status' => 'ok', 'fail_count' => 1, 'changed_at' => '2026-10-25T08:00:00Z', 'details' => ['failing_since' => '2026-10-25T12:00:00Z']];
    $next = next_uptime_state($failing, evaluate_uptime(response(), '', 10), 2, '2026-10-25T12:01:00Z');

    expect($next['state'])->toMatchArray(['status' => 'ok', 'fail_count' => 0, 'changed_at' => '2026-10-25T08:00:00Z'])
        ->and($next['event'])->toBeNull();
});

test('a first check that fails stays pending until it counts as down', function () {
    $pending = ['status' => 'pending', 'fail_count' => 0, 'changed_at' => null, 'details' => []];
    $once = next_uptime_state($pending, evaluate_uptime(response(['status' => 500]), '', 10), 2, '2026-10-25T12:00:00Z');
    expect($once['state']['status'])->toBe('pending')
        ->and($once['state']['summary'])->toBe('First check failed, checking again: the site answered 500 Internal Server Error');

    $twice = next_uptime_state($once['state'], evaluate_uptime(response(['status' => 500]), '', 10), 2, '2026-10-25T12:01:00Z');
    expect($twice['state']['status'])->toBe('fail')
        ->and($twice['event'])->toBe(['status' => 'fail', 'previous' => 'pending', 'message' => 'Down: the site answered 500 Internal Server Error']);
});

test('human_duration rounds to what a person would say', function () {
    expect(human_duration(20))->toBe('less than a minute')
        ->and(human_duration(90))->toBe('1 min')
        ->and(human_duration(14 * 60 + 30))->toBe('14 min')
        ->and(human_duration(2 * 3600 + 10 * 60))->toBe('2 h 10 min')
        ->and(human_duration(3 * 86400 + 3600))->toBe('3 days 1 h');
});
