<?php

function rdap_fixture(string $name): array
{
    return json_decode(file_get_contents(__DIR__ . "/../fixtures/rdap/{$name}"), true);
}

/** A lookup result as lookup_domain() returns it. */
function lookup(array $overrides = []): array
{
    return $overrides + ['status' => 'found', 'domain' => 'example.se', 'expires' => strtotime('2031-09-13T00:00:00Z'), 'registrar' => 'Example Registrar AB', 'source' => 'whois', 'error' => ''];
}

test('a domain is looked up from the shortest name that could be registered', function () {
    expect(registrable_candidates('shop.example.se'))->toBe(['example.se', 'shop.example.se'])
        ->and(registrable_candidates('www.example.co.uk'))->toBe(['co.uk', 'example.co.uk', 'www.example.co.uk'])
        ->and(registrable_candidates('Example.COM.'))->toBe(['example.com'])
        ->and(registrable_candidates('192.0.2.10'))->toBe([])
        ->and(registrable_candidates('[2001:db8::1]'))->toBe([])
        ->and(registrable_candidates('localhost'))->toBe([]);
});

test('the bootstrap file says which registry to ask, the longest match winning', function () {
    $bootstrap = rdap_fixture('dns.json');
    $bootstrap['services'][] = [['com'], ['http://rdap.verisign.example/com/v1', 'https://rdap.verisign.example/com/v1']];
    expect(rdap_base_url($bootstrap, 'shop.example'))->toBe('http://127.0.0.1:8796/')
        ->and(rdap_base_url($bootstrap, 'shop.co.example'))->toBe('http://127.0.0.1:8796/co/')
        ->and(rdap_base_url($bootstrap, 'example.com'))->toBe('https://rdap.verisign.example/com/v1/')
        ->and(rdap_base_url($bootstrap, 'example.se'))->toBeNull();
});

test('RDAP gives the expiry date and the registrar', function () {
    expect(parse_rdap_domain(rdap_fixture('shop.example.json')))->toBe(['expires' => strtotime('2027-03-04T10:00:00Z'), 'registrar' => 'Example Registrar, Inc.'])
        ->and(parse_rdap_domain(rdap_fixture('no-expiry.example.json')))->toBe(['expires' => null, 'registrar' => '']);
});

test('the .se WHOIS answer gives the expiry date, and says when a domain is not registered', function () {
    expect(parse_whois(file_get_contents(__DIR__ . '/../fixtures/rdap/iis-found.txt')))->toBe(['found' => true, 'expires' => strtotime('2031-09-13T00:00:00Z'), 'registrar' => 'Example Registrar AB'])
        ->and(parse_whois(file_get_contents(__DIR__ . '/../fixtures/rdap/iis-not-found.txt')))->toBe(['found' => false, 'expires' => null, 'registrar' => '']);
});

test('a registration far from expiry is ok, and says until when', function () {
    $now = strtotime('2026-10-25T12:00:00Z');
    $result = evaluate_domain(lookup(), $now, 30);
    expect($result['status'])->toBe('ok')
        ->and($result['summary'])->toBe('example.se is registered until 13 Sep 2031 (1783 days), with Example Registrar AB')
        ->and($result['details'])->toMatchArray(['domain' => 'example.se', 'expires' => '2031-09-13T00:00:00Z', 'days_left' => 1783, 'problem' => '']);
});

test('a registration close to expiry warns, and an expired one fails', function () {
    $expires = strtotime('2026-11-08T00:00:00Z');
    expect(evaluate_domain(lookup(['expires' => $expires]), $expires - 14 * 86400 - 60, 30))
        ->toMatchArray(['status' => 'warn', 'summary' => 'example.se expires in 14 days (8 Nov 2026)'])
        ->and(evaluate_domain(lookup(['expires' => $expires]), $expires - 3600, 30)['summary'])->toBe('example.se expires today (8 Nov 2026)')
        ->and(evaluate_domain(lookup(['expires' => $expires]), $expires - 86400 - 60, 30)['details']['problem'])->toBe('domain_expiring')
        ->and(evaluate_domain(lookup(['expires' => $expires]), $expires + 86400, 30))->toMatchArray(['status' => 'fail', 'summary' => 'example.se expired on 8 Nov 2026'])
        ->and(evaluate_domain(lookup(['expires' => $expires]), $expires + 86400, 30)['details']['problem'])->toBe('domain_expired');
});

test('what cannot be known is unknown, in plain words, never an alert', function () {
    $now = strtotime('2026-10-25T12:00:00Z');
    expect(evaluate_domain(lookup(['status' => 'no_registry', 'domain' => 'example.xyz', 'expires' => null]), $now, 30))
        ->toMatchArray(['status' => 'unknown', 'summary' => 'The .xyz registry publishes no expiry dates that Vaka can read'])
        ->and(evaluate_domain(lookup(['status' => 'not_found', 'domain' => 'shop.example', 'expires' => null]), $now, 30)['summary'])->toBe('The registry does not know shop.example')
        ->and(evaluate_domain(lookup(['domain' => 'no-expiry.example', 'expires' => null]), $now, 30)['summary'])->toBe('The registry does not say when no-expiry.example expires')
        ->and(evaluate_domain(lookup(['status' => 'error', 'error' => 'no answer within 10 s', 'expires' => null]), $now, 30)['summary'])->toBe('Could not look up example.se: no answer within 10 s');
});

function domain_state(array $overrides = []): array
{
    return $overrides + ['status' => 'ok', 'alerted_status' => 'ok', 'details' => ['domain' => 'example.se', 'days_left' => 400, 'expires' => '2027-11-08T00:00:00Z'], 'thresholds_sent' => '[]'];
}

test('domain_notice sends each threshold once per registration period', function () {
    $warn = domain_state(['status' => 'warn', 'details' => ['domain' => 'example.se', 'days_left' => 29, 'expires' => '2026-11-23T00:00:00Z']]);
    $first = domain_notice($warn, null, [30, 14, 7, 1]);
    expect($first)->toMatchArray(['action' => 'send', 'kind' => 'domain_expiring'])
        ->and(json_decode($first['thresholds'], true))->toBe(['expires' => '2026-11-23T00:00:00Z', 'sent' => [30]]);

    expect(domain_notice(['thresholds_sent' => $first['thresholds'], 'alerted_status' => 'warn'] + $warn, null, [30, 14, 7, 1])['action'])->toBe('none');

    $fourteen = domain_notice(['thresholds_sent' => $first['thresholds'], 'alerted_status' => 'warn', 'details' => ['domain' => 'example.se', 'days_left' => 13, 'expires' => '2026-11-23T00:00:00Z']] + $warn, null, [30, 14, 7, 1]);
    expect($fourteen['kind'])->toBe('domain_expiring')
        ->and(json_decode($fourteen['thresholds'], true)['sent'])->toBe([30, 14]);
});

test('domain_notice: expired, renewed, a first result, quiet policies and unknown', function () {
    expect(domain_notice(domain_state(['status' => 'fail']), null, [30, 14, 7, 1])['kind'])->toBe('domain_expired')
        ->and(domain_notice(domain_state(['alerted_status' => 'fail']), null, [30, 14, 7, 1])['kind'])->toBe('domain_renewed')
        ->and(domain_notice(domain_state(['alerted_status' => 'warn', 'thresholds_sent' => '{"expires":"2026-11-23T00:00:00Z","sent":[30,14]}']), null, [30, 14, 7, 1])['kind'])->toBe('domain_renewed')
        ->and(domain_notice(domain_state(['alerted_status' => 'pending']), null, [30, 14, 7, 1])['action'])->toBe('mark')
        ->and(domain_notice(domain_state(['status' => 'fail']), ['policy' => 'digest'], [30, 14, 7, 1])['action'])->toBe('mark')
        ->and(domain_notice(domain_state(['status' => 'unknown', 'alerted_status' => 'ok']), null, [30, 14, 7, 1])['action'])->toBe('none');
});

test('domain alerts read plainly, named by the domain', function () {
    $base = ['check' => 'domain', 'domain' => 'example.se', 'server_key' => null, 'server' => null, 'url' => 'https://www.example.se'];
    $text = fn (array $notice) => alert_text(bundle_notices([notice($notice + $base)], [])[0]['items'][0]);

    expect($text(['kind' => 'domain_expiring', 'days_left' => 14, 'summary' => 'example.se expires in 14 days (8 Nov 2026)']))->toBe([
        'headline' => 'example.se (Customers): the domain expires in 14 days',
        'details' => ['example.se expires in 14 days (8 Nov 2026)', advice_for('domain_expiring', 0)],
    ])
        ->and($text(['kind' => 'domain_expired', 'summary' => 'example.se expired on 8 Nov 2026'])['headline'])->toBe('example.se (Customers): the domain has expired')
        ->and($text(['kind' => 'domain_renewed', 'summary' => 'example.se is registered until 8 Nov 2027 (378 days)'])['headline'])->toBe('example.se (Customers): the domain was renewed')
        ->and(alert_emoji('domain_expired'))->toBe(':red_circle:')
        ->and(alert_emoji('domain_expiring'))->toBe(':warning:')
        ->and(alert_emoji('domain_renewed'))->toBe(':large_green_circle:');
});
