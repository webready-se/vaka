<?php

test('normalize_url adds https when the scheme is missing', function () {
    expect(normalize_url('shop.example'))->toBe('https://shop.example');
});

test('normalize_url lowercases scheme and host but keeps the path', function () {
    expect(normalize_url('HTTPS://Shop.Example/Path/'))->toBe('https://shop.example/Path/');
});

test('normalize_url drops the root slash, default ports and fragments', function () {
    expect(normalize_url('https://shop.example/'))->toBe('https://shop.example')
        ->and(normalize_url('http://shop.example:80/'))->toBe('http://shop.example')
        ->and(normalize_url('https://shop.example:443/path?q=1#top'))->toBe('https://shop.example/path?q=1');
});

test('normalize_url keeps other ports, query strings and IP hosts', function () {
    expect(normalize_url('https://shop.example:8443'))->toBe('https://shop.example:8443')
        ->and(normalize_url('http://localhost:8089/health?x=1'))->toBe('http://localhost:8089/health?x=1')
        ->and(normalize_url('https://192.0.2.10'))->toBe('https://192.0.2.10')
        ->and(normalize_url('http://[2001:db8::1]:8080/'))->toBe('http://[2001:db8::1]:8080');
});

test('normalize_url strips whitespace and a trailing dot on the host', function () {
    expect(normalize_url("  https://shop.example./  \n"))->toBe('https://shop.example');
});

test('normalize_url converts international domain names to punycode', function () {
    expect(normalize_url('https://räksmörgås.example'))->toBe('https://' . idn_to_ascii('räksmörgås.example'));
})->skip(!function_exists('idn_to_ascii'), 'needs the intl extension');

test('normalize_url rejects what cannot be checked', function (string $input, string $reason) {
    expect(fn () => normalize_url($input))->toThrow(InvalidArgumentException::class, $reason);
})->with([
    'empty' => ['', 'empty'],
    'other scheme' => ['ftp://shop.example', 'http or https'],
    'credentials' => ['https://user:secret@shop.example', 'password'],
    'no domain' => ['shop', 'domain'],
    'space in host' => ['https://exa mple.com', 'not a valid'],
    'too long' => ['https://shop.example/' . str_repeat('a', 2000), 'too long'],
]);

test('site_host gives the host without www', function () {
    expect(site_host('https://www.shop.example/path'))->toBe('shop.example')
        ->and(site_host('http://localhost:8089'))->toBe('localhost');
});
