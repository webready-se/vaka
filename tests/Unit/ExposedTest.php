<?php

/** A fetch_many() result for the exposed-file tests. */
function probe(int $status, string $body, int $errno = 0, string $url = 'https://shop.example/x'): array
{
    return ['status' => $errno ? 0 : $status, 'headers' => [], 'body' => $body, 'ms' => 50, 'ip' => '203.0.113.10', 'errno' => $errno, 'error' => $errno ? 'Connection timed out' : '', 'url' => $url];
}

/** Every known path answered with $default, except those in $answers. */
function probes(array $answers = [], ?array $default = null): array
{
    $default ??= probe(404, '<!doctype html><title>Not found</title>');
    $responses = [];
    foreach (EXPOSED_FILES as $file) $responses[$file['path']] = $answers[$file['path']] ?? $default;
    return $responses;
}

const SHOP_PAGE = '<!doctype html><html><head><title>Shop</title></head><body><h1>Welcome</h1></body></html>';

test('exposed files are recognised by their content, not by the status code', function () {
    expect(exposed_matches('env', "APP_NAME=Shop\nAPP_KEY=base64:not-a-real-key\nDB_PASSWORD=secret\n"))->toBeTrue()
        ->and(exposed_matches('env', "# comment\nexport DB_HOST=127.0.0.1\nDB_USER = shop\n"))->toBeTrue()
        ->and(exposed_matches('env', SHOP_PAGE))->toBeFalse()
        ->and(exposed_matches('env', "<html><body>\nAPP_NAME=Shop\nAPP_ENV=local\n</body></html>"))->toBeFalse()
        ->and(exposed_matches('env', 'NAME=one line only'))->toBeFalse()
        ->and(exposed_matches('git_head', "ref: refs/heads/main\n"))->toBeTrue()
        ->and(exposed_matches('git_head', str_repeat('a1', 20) . "\n"))->toBeTrue()
        ->and(exposed_matches('git_head', SHOP_PAGE))->toBeFalse()
        ->and(exposed_matches('git_config', "[core]\n\trepositoryformatversion = 0\n\tbare = false\n"))->toBeTrue()
        ->and(exposed_matches('git_config', '<p>[core] is a section</p>'))->toBeFalse()
        ->and(exposed_matches('wp_config', "<?php\ndefine( 'DB_NAME', 'shop' );\ndefine( 'DB_PASSWORD', 'secret' );"))->toBeTrue()
        ->and(exposed_matches('zip', "PK\x03\x04\x14\x00\x00\x00"))->toBeTrue()
        ->and(exposed_matches('zip', SHOP_PAGE))->toBeFalse()
        ->and(exposed_matches('sql', "-- MySQL dump 10.13  Distrib 8.0.36\n--\n-- Host: localhost\n"))->toBeTrue()
        ->and(exposed_matches('sql', "-- phpMyAdmin SQL Dump\n-- version 5.2.1\n"))->toBeTrue()
        ->and(exposed_matches('sql', "SET NAMES utf8mb4;\nCREATE TABLE `orders` (\n  `id` int NOT NULL\n);\n"))->toBeTrue()
        ->and(exposed_matches('sql', '<!doctype html><pre>CREATE TABLE orders</pre>'))->toBeFalse()
        ->and(exposed_matches('gzip', "\x1f\x8b\x08\x00\x00\x00\x00\x00"))->toBeTrue()
        ->and(exposed_matches('gzip', "-- MySQL dump 10.13\n"))->toBeTrue()
        ->and(exposed_matches('phpinfo', '<!DOCTYPE html><html><head><title>PHP 8.3.12 - phpinfo()</title>'))->toBeTrue()
        ->and(exposed_matches('phpinfo', '<table><tr class="h"><td><h1 class="p">PHP Version 7.4.33</h1>'))->toBeTrue()
        ->and(exposed_matches('phpinfo', '<p>Never leave phpinfo() on a live site.</p>'))->toBeFalse()
        ->and(exposed_matches('server_status', '<html><head><title>Apache Status</title></head><body><h1>Apache Server Status for shop.example'))->toBeTrue()
        ->and(exposed_matches('composer_json', "{\n    \"name\": \"shop/site\",\n    \"require\": {\"php\": \"^8.3\"}\n}"))->toBeTrue()
        ->and(exposed_matches('composer_json', '{"error": "not found"}'))->toBeFalse()
        ->and(exposed_matches('composer_lock', "{\n    \"_readme\": [],\n    \"content-hash\": \"abc\",\n    \"packages\": ["))->toBeTrue()
        ->and(exposed_matches('package_json', "{\n  \"name\": \"shop\",\n  \"devDependencies\": {\"vite\": \"^5\"}\n}"))->toBeTrue()
        ->and(exposed_matches('package_json', SHOP_PAGE))->toBeFalse()
        ->and(exposed_matches('ds_store', "\x00\x00\x00\x01Bud1\x00\x00"))->toBeTrue()
        ->and(exposed_matches('ds_store', SHOP_PAGE))->toBeFalse();
});

test('every known file has a signature, a level and plain words', function () {
    foreach (EXPOSED_FILES as $file) {
        expect(exposed_matches($file['match'], SHOP_PAGE))->toBeFalse()
            ->and($file['severity'])->toBeIn(['fail', 'warn'])
            ->and($file['what'])->not->toBe('');
    }
});

test('the files are looked for next to the site, without its query string', function () {
    expect(exposed_base('https://shop.example'))->toBe('https://shop.example/')
        ->and(exposed_base('https://shop.example/blog'))->toBe('https://shop.example/blog/')
        ->and(exposed_base('https://shop.example/blog/?lang=sv'))->toBe('https://shop.example/blog/')
        ->and(exposed_base('https://shop.example/index.php'))->toBe('https://shop.example/')
        ->and(exposed_base('http://127.0.0.1:8080/shop/start.html'))->toBe('http://127.0.0.1:8080/shop/');
});

test('nothing public is ok, and says how many files were checked', function () {
    $result = evaluate_exposed(probes(), probe(404, SHOP_PAGE), 10);
    expect($result['status'])->toBe('ok')
        ->and($result['summary'])->toBe('Nothing that should be private is public (' . count(EXPOSED_FILES) . ' files checked)')
        ->and($result['details']['found'])->toBe([]);
});

test('a site that answers every address with its home page is not exposed', function () {
    $page = probe(200, SHOP_PAGE);
    expect(evaluate_exposed(probes([], $page), $page, 10)['status'])->toBe('ok');
});

test('a public .env is a failure that says what is in it', function () {
    $result = evaluate_exposed(probes(['.env' => probe(200, "APP_KEY=x\nDB_PASSWORD=y\n", 0, 'https://shop.example/.env')]), probe(404, SHOP_PAGE), 10);
    expect($result['status'])->toBe('fail')
        ->and($result['summary'])->toBe('.env is public: the environment file, often with database passwords and API keys')
        ->and($result['details']['problem'])->toBe('exposed')
        ->and($result['details']['found'])->toBe([[
            'label' => '.env',
            'what' => 'the environment file, often with database passwords and API keys',
            'severity' => 'fail',
            'url' => 'https://shop.example/.env',
        ]]);
});

test('several files are listed once each, .git counted once', function () {
    $result = evaluate_exposed(probes([
        '.git/HEAD' => probe(200, "ref: refs/heads/main\n"),
        '.git/config' => probe(200, "[core]\n\trepositoryformatversion = 0\n"),
        'composer.json' => probe(200, '{"require": {"php": "^8.3"}}'),
    ]), probe(404, SHOP_PAGE), 10);
    expect($result['status'])->toBe('fail')
        ->and(array_column($result['details']['found'], 'label'))->toBe(['.git/', 'composer.json'])
        ->and($result['summary'])->toBe('2 files that should be private are public: .git/ and composer.json');
});

test('only files that tell versions and paths are a warning', function () {
    $result = evaluate_exposed(probes(['composer.lock' => probe(200, '{"content-hash": "abc", "packages": []}')]), probe(404, SHOP_PAGE), 10);
    expect($result['status'])->toBe('warn')
        ->and($result['details']['problem'])->toBe('exposed_minor');
});

test('content that every address answers with is not counted as a file', function () {
    // A catch-all that happens to look like a .env: the made-up address answers it too
    $env = probe(200, "APP_NAME=Shop\nAPP_ENV=production\n");
    expect(evaluate_exposed(probes([], $env), $env, 10)['status'])->toBe('ok');
});

test('a file behind an error status is not public', function () {
    expect(evaluate_exposed(probes(['.env' => probe(403, "APP_KEY=x\nDB_PASSWORD=y\n")]), probe(404, SHOP_PAGE), 10)['status'])->toBe('ok');
});

test('a site that cannot be reached is unknown, not an alert', function () {
    $down = probe(0, '', CURLE_OPERATION_TIMEDOUT);
    $result = evaluate_exposed(probes([], $down), $down, 10);
    expect($result['status'])->toBe('unknown')
        ->and($result['summary'])->toBe('Could not check: no answer within 10 s');
});

test('files that did not answer are left out of the count', function () {
    $result = evaluate_exposed(probes(['.env' => probe(0, '', CURLE_OPERATION_TIMEDOUT)]), probe(404, SHOP_PAGE), 10);
    expect($result['status'])->toBe('ok')
        ->and($result['details']['checked'])->toBe(count(EXPOSED_FILES) - 1);
});

function exposed_state(array $overrides = []): array
{
    return $overrides + ['status' => 'ok', 'alerted_status' => 'ok', 'details' => ['found' => []], 'alerted_snapshot' => '[]'];
}

function found(string ...$labels): array
{
    $files = [];
    foreach (EXPOSED_FILES as $file) {
        if (in_array($file['label'], $labels, true)) $files[$file['label']] = ['label' => $file['label'], 'what' => $file['what'], 'severity' => $file['severity'], 'url' => 'https://shop.example/' . $file['path']];
    }
    return ['found' => array_values($files)];
}

test('exposed_notice alerts on each file once, and once more when all is private again', function () {
    $first = exposed_notice(exposed_state(['status' => 'fail', 'alerted_status' => 'pending', 'details' => found('.env')]), null);
    expect($first)->toMatchArray(['action' => 'send', 'kind' => 'exposed', 'snapshot' => '[".env"]'])
        ->and(array_column($first['new'], 'label'))->toBe(['.env']);

    $same = exposed_state(['status' => 'fail', 'alerted_status' => 'fail', 'details' => found('.env'), 'alerted_snapshot' => '[".env"]']);
    expect(exposed_notice($same, null)['action'])->toBe('none');

    $more = exposed_notice(['details' => found('.env', 'composer.json')] + $same, null);
    expect($more['kind'])->toBe('exposed_minor')
        ->and(array_column($more['new'], 'label'))->toBe(['composer.json'])
        ->and($more['still'])->toBe(['.env']);

    $fixed = exposed_notice(exposed_state(['alerted_status' => 'fail', 'alerted_snapshot' => '[".env"]']), null);
    expect($fixed)->toMatchArray(['action' => 'send', 'kind' => 'exposed_fixed', 'snapshot' => '[]']);
});

test('exposed_notice: one file fixed of two is noted quietly, a first clean result is a baseline', function () {
    $partly = exposed_state(['status' => 'fail', 'alerted_status' => 'fail', 'details' => found('.env'), 'alerted_snapshot' => '[".env","composer.json"]']);
    expect(exposed_notice($partly, null))->toMatchArray(['action' => 'mark', 'snapshot' => '[".env"]'])
        ->and(exposed_notice(exposed_state(['alerted_status' => 'pending']), null)['action'])->toBe('mark')
        ->and(exposed_notice(exposed_state(['status' => 'unknown', 'alerted_status' => 'fail', 'alerted_snapshot' => '[".env"]']), null)['action'])->toBe('none')
        ->and(exposed_notice(exposed_state(['status' => 'pending', 'alerted_status' => 'pending']), null)['action'])->toBe('none');
});

test('exposed_notice follows quiet policies, and a delay is for outages only', function () {
    $found = exposed_state(['status' => 'fail', 'alerted_status' => 'ok', 'details' => found('.env')]);
    expect(exposed_notice($found, ['policy' => 'silent'])['action'])->toBe('mark')
        ->and(exposed_notice($found, ['policy' => 'digest'])['action'])->toBe('mark')
        ->and(exposed_notice($found, ['policy' => 'delayed', 'delay_minutes' => 30])['kind'])->toBe('exposed');
});

test('exposed-file alerts read plainly', function () {
    $base = ['check' => 'exposed', 'server_key' => null, 'server' => null, 'still' => []];
    $text = fn (array $notice) => alert_text(bundle_notices([notice($notice + $base)], [])[0]['items'][0]);

    expect($text(['kind' => 'exposed', 'new' => found('.env', '.git/')['found']]))->toBe([
        'headline' => 'shop.example (Customers): .env and .git/ are public',
        'details' => [
            '.env: the environment file, often with database passwords and API keys',
            '.git/: the Git repository: the site\'s source code and its history',
            advice_for('exposed', 0),
        ],
    ]);
    expect($text(['kind' => 'exposed_minor', 'new' => found('composer.json')['found'], 'still' => ['.env']]))->toBe([
        'headline' => 'shop.example (Customers): composer.json is public',
        'details' => ['composer.json: the list of PHP packages the site uses', 'Still public: .env', advice_for('exposed_minor', 0)],
    ]);
    expect($text(['kind' => 'exposed_fixed'])['headline'])->toBe('shop.example (Customers): nothing that should be private is public any more');
});

test('exposed-file alerts have their own emoji and come after outages', function () {
    expect(alert_emoji('exposed'))->toBe(':red_circle:')
        ->and(alert_emoji('exposed_minor'))->toBe(':warning:')
        ->and(alert_emoji('exposed_fixed'))->toBe(':large_green_circle:');

    $bundle = bundle_notices([
        notice(['site_id' => 1, 'kind' => 'exposed', 'check' => 'exposed', 'new' => found('.env')['found'], 'still' => [], 'server_key' => null, 'server' => null]),
        notice(['site_id' => 2, 'kind' => 'down']),
    ], [])[0];
    expect(array_column($bundle['items'], 'kind'))->toBe(['down', 'exposed']);
});
