<?php

function fixture_cert(string $name): string
{
    return file_get_contents(__DIR__ . "/../fixtures/certs/{$name}.pem");
}

function verified(string $name): array
{
    return ['cert' => fixture_cert($name), 'verified' => true, 'error' => ''];
}

function rejected(string $name, string $error = 'error:0A000086:SSL routines::certificate verify failed'): array
{
    return ['cert' => fixture_cert($name), 'verified' => false, 'error' => $error];
}

test('certificate_info reads names, issuer and validity', function () {
    $info = certificate_info(fixture_cert('valid'));
    expect($info['subject'])->toBe('127.0.0.1')
        ->and($info['issuer'])->toBe('Vaka Test CA')
        ->and($info['names'])->toBe(['127.0.0.1', 'localhost'])
        ->and($info['self_signed'])->toBeFalse()
        ->and($info['valid_to'])->toBeGreaterThan($info['valid_from']);

    expect(certificate_info(fixture_cert('self-signed'))['self_signed'])->toBeTrue();
});

test('host_matches follows the certificate name rules', function () {
    expect(host_matches('shop.example', ['shop.example']))->toBeTrue()
        ->and(host_matches('Shop.Example', ['shop.example']))->toBeTrue()
        ->and(host_matches('www.shop.example', ['*.shop.example']))->toBeTrue()
        ->and(host_matches('a.b.shop.example', ['*.shop.example']))->toBeFalse()
        ->and(host_matches('shop.example', ['*.shop.example']))->toBeFalse()
        ->and(host_matches('127.0.0.1', ['127.0.0.1', 'localhost']))->toBeTrue()
        ->and(host_matches('shop.example', ['other.example']))->toBeFalse();
});

test('a trusted certificate far from expiry is ok, with when and who issued it', function () {
    $info = certificate_info(fixture_cert('valid'));
    $now = $info['valid_to'] - 90 * 86400;
    $result = evaluate_ssl('127.0.0.1', verified('valid'), $info, $now, 14);

    expect($result['status'])->toBe('ok')
        ->and($result['summary'])->toBe('Valid until ' . date('j M Y', $info['valid_to']) . ' (90 days), issued by Vaka Test CA')
        ->and($result['details']['days_left'])->toBe(90)
        ->and($result['details']['valid_to'])->toBe(utc_now($info['valid_to']));
});

test('behind Cloudflare the certificate is Cloudflare\'s, which it renews by itself, and the summary says so', function () {
    $info = certificate_info(fixture_cert('valid'));
    $now = $info['valid_to'] - 31 * 86400;
    $result = evaluate_ssl('127.0.0.1', verified('valid'), $info, $now, 14, 'Cloudflare');

    expect($result['status'])->toBe('ok')
        ->and($result['summary'])->toBe("Cloudflare's certificate, which Cloudflare renews by itself: valid until " . date('j M Y', $info['valid_to']) . ' (31 days), issued by Vaka Test CA')
        ->and($result['details']['renewed_by'])->toBe('Cloudflare')
        // Other CDNs may show a certificate of the site's own, so nothing is said for them
        ->and(evaluate_ssl('127.0.0.1', verified('valid'), $info, $now, 14, 'Fastly')['summary'])->toStartWith('Valid until')
        ->and(evaluate_ssl('127.0.0.1', verified('valid'), $info, $now, 14, 'Fastly')['details']['renewed_by'])->toBeNull()
        ->and(evaluate_ssl('127.0.0.1', verified('valid'), $info, $info['valid_to'] - 10 * 86400 - 60, 14, 'Cloudflare'))
        ->toMatchArray(['status' => 'warn', 'summary' => 'The certificate expires in 10 days (' . date('j M Y', $info['valid_to']) . ')']);
});

test('ssl_advice: a Cloudflare certificate that is late to renew points at Cloudflare, not at certbot', function () {
    expect(ssl_advice(['problem' => 'expiring', 'renewed_by' => 'Cloudflare']))->toContain('Cloudflare renews its certificates about 30 days before they expire')
        ->and(ssl_advice(['problem' => 'expired', 'renewed_by' => 'Cloudflare']))->toContain('SSL/TLS')
        ->and(ssl_advice(['problem' => 'expiring']))->toContain("Let's Encrypt")
        ->and(ssl_advice(['problem' => 'name', 'renewed_by' => 'Cloudflare']))->toBe(advice_for('name', 0));
});

test('a certificate close to expiry is a warning that says when', function () {
    $info = certificate_info(fixture_cert('valid'));
    expect(evaluate_ssl('127.0.0.1', verified('valid'), $info, $info['valid_to'] - 10 * 86400 - 60, 14))
        ->toMatchArray(['status' => 'warn', 'summary' => 'The certificate expires in 10 days (' . date('j M Y', $info['valid_to']) . ')'])
        ->and(evaluate_ssl('127.0.0.1', verified('valid'), $info, $info['valid_to'] - 3600, 14)['summary'])->toBe('The certificate expires today (' . date('j M Y', $info['valid_to']) . ')')
        ->and(evaluate_ssl('127.0.0.1', verified('valid'), $info, $info['valid_to'] - 86400 - 60, 14)['summary'])->toStartWith('The certificate expires tomorrow');
});

test('a rejected certificate says why, in plain words', function () {
    $valid = certificate_info(fixture_cert('valid'));
    $expired = evaluate_ssl('127.0.0.1', rejected('valid', 'certificate has expired'), $valid, $valid['valid_to'] + 86400, 14);
    expect($expired['status'])->toBe('fail')
        ->and($expired['summary'])->toBe('The certificate expired on ' . date('j M Y', $valid['valid_to']))
        ->and($expired['details']['problem'])->toBe('expired');

    $wrong = evaluate_ssl('127.0.0.1', rejected('wrong-name'), certificate_info(fixture_cert('wrong-name')), time(), 14);
    expect($wrong)->toMatchArray(['status' => 'fail', 'summary' => 'The certificate is for other.example, not 127.0.0.1'])
        ->and($wrong['details']['problem'])->toBe('name');

    $self = evaluate_ssl('127.0.0.1', rejected('self-signed'), certificate_info(fixture_cert('self-signed')), time(), 14);
    expect($self)->toMatchArray(['status' => 'fail', 'summary' => 'The certificate is self-signed, so browsers do not trust it']);

    $untrusted = evaluate_ssl('127.0.0.1', rejected('valid'), $valid, time(), 14);
    expect($untrusted)->toMatchArray(['status' => 'fail', 'summary' => 'Browsers do not trust the certificate: the chain to a known authority is missing or broken'])
        ->and($untrusted['details']['problem'])->toBe('untrusted');
});

test('no certificate at all is unknown, not an alert', function () {
    $result = evaluate_ssl('shop.example', ['cert' => null, 'verified' => false, 'error' => 'Connection refused'], null, time(), 14);
    expect($result)->toMatchArray(['status' => 'unknown', 'summary' => 'Could not read the certificate: Connection refused']);
});

// =====================================================================
// When to alert about certificates
// =====================================================================

function ssl_state(array $overrides = []): array
{
    return $overrides + ['status' => 'ok', 'alerted_status' => 'ok', 'details' => ['days_left' => 90, 'valid_to' => '2027-01-23T12:00:00Z'], 'thresholds_sent' => '[]'];
}

test('ssl_notice sends each expiry threshold once per certificate', function () {
    $warn = ssl_state(['status' => 'warn', 'details' => ['days_left' => 13, 'valid_to' => '2027-01-23T12:00:00Z']]);
    $first = ssl_notice($warn, null, [14, 7, 1]);
    expect($first)->toMatchArray(['action' => 'send', 'kind' => 'ssl_expiring'])
        ->and(json_decode($first['thresholds'], true))->toBe(['valid_to' => '2027-01-23T12:00:00Z', 'sent' => [14]]);

    $again = ssl_notice(['thresholds_sent' => $first['thresholds'], 'alerted_status' => 'warn'] + $warn, null, [14, 7, 1]);
    expect($again['action'])->toBe('none');

    $seven = ssl_notice(['thresholds_sent' => $first['thresholds'], 'alerted_status' => 'warn', 'details' => ['days_left' => 6, 'valid_to' => '2027-01-23T12:00:00Z']] + $warn, null, [14, 7, 1]);
    expect($seven['kind'])->toBe('ssl_expiring')
        ->and(json_decode($seven['thresholds'], true)['sent'])->toBe([14, 7]);
});

test('ssl_notice sends one alert when it first finds a certificate already past several thresholds', function () {
    $notice = ssl_notice(ssl_state(['status' => 'warn', 'alerted_status' => 'pending', 'details' => ['days_left' => 3, 'valid_to' => '2027-01-23T12:00:00Z']]), null, [14, 7, 1]);
    expect($notice['kind'])->toBe('ssl_expiring')
        ->and(json_decode($notice['thresholds'], true)['sent'])->toBe([14, 7]);
});

test('ssl_notice: invalid, valid again, renewed, and quiet policies', function () {
    expect(ssl_notice(ssl_state(['status' => 'fail']), null, [14, 7, 1])['kind'])->toBe('ssl_invalid')
        ->and(ssl_notice(ssl_state(['alerted_status' => 'fail']), null, [14, 7, 1])['kind'])->toBe('ssl_fixed')
        ->and(ssl_notice(ssl_state(['alerted_status' => 'warn', 'thresholds_sent' => '{"valid_to":"2026-11-01T00:00:00Z","sent":[14,7]}']), null, [14, 7, 1])['kind'])->toBe('ssl_renewed')
        ->and(ssl_notice(ssl_state(['alerted_status' => 'pending']), null, [14, 7, 1])['action'])->toBe('mark')
        ->and(ssl_notice(ssl_state(['status' => 'fail']), ['policy' => 'silent'], [14, 7, 1])['action'])->toBe('mark')
        ->and(ssl_notice(ssl_state(['status' => 'unknown', 'alerted_status' => 'ok']), null, [14, 7, 1])['action'])->toBe('none');
});

test('certificate alerts read plainly', function () {
    $base = ['kind' => 'ssl_expiring', 'check' => 'ssl', 'days_left' => 7, 'valid_to' => '2027-01-23T12:00:00Z', 'summary' => 'The certificate expires in 7 days (23 Jan 2027)', 'server_key' => null, 'server' => null];
    expect(alert_text(bundle_notices([notice($base)], [])[0]['items'][0]))->toBe([
        'headline' => 'shop.example (Customers): the SSL certificate expires in 7 days',
        'details' => ['The certificate expires in 7 days (23 Jan 2027)', 'Renew it before then. With Let\'s Encrypt, check that the automatic renewal runs.'],
    ]);
    expect(alert_text(bundle_notices([notice(['kind' => 'ssl_invalid', 'summary' => 'The certificate expired on 23 Jan 2027'] + $base)], [])[0]['items'][0])['headline'])
        ->toBe('shop.example (Customers): the SSL certificate is not valid')
        ->and(alert_text(bundle_notices([notice(['kind' => 'ssl_fixed'] + $base)], [])[0]['items'][0])['headline'])->toBe('shop.example (Customers): the SSL certificate is valid again')
        ->and(alert_text(bundle_notices([notice(['kind' => 'ssl_renewed'] + $base)], [])[0]['items'][0])['headline'])->toBe('shop.example (Customers): the SSL certificate was renewed');
    expect(alert_text(bundle_notices([notice(['renewed_by' => 'Cloudflare'] + $base)], [])[0]['items'][0])['details'][1])
        ->toBe(ssl_advice(['problem' => 'expiring', 'renewed_by' => 'Cloudflare']));
});
