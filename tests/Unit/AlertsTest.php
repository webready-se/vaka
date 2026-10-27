<?php

// =====================================================================
// Servers
// =====================================================================

test('server_key: a server set by hand wins, a CDN site stands alone, otherwise the IP', function () {
    $site = ['server' => '', 'cdn' => '', 'ip' => '203.0.113.10'];
    expect(server_key($site))->toBe('ip:203.0.113.10')
        ->and(server_key(['server' => 'Web-1'] + $site))->toBe('label:web-1')
        ->and(server_key(['cdn' => 'Cloudflare'] + $site))->toBeNull()
        ->and(server_key(['server' => 'web-1', 'cdn' => 'Cloudflare'] + $site))->toBe('label:web-1')
        ->and(server_key(['ip' => ''] + $site))->toBeNull();
});

test('server_display uses the label for an IP when there is one', function () {
    $site = ['server' => '', 'cdn' => '', 'ip' => '203.0.113.10'];
    expect(server_display($site, []))->toBe('203.0.113.10')
        ->and(server_display($site, ['203.0.113.10' => 'web-1']))->toBe('web-1 (203.0.113.10)')
        ->and(server_display(['server' => 'web-2'] + $site, ['203.0.113.10' => 'web-1']))->toBe('web-2');
});

// =====================================================================
// When to alert
// =====================================================================

test('alert_decision follows the state and the group policy', function (array $state, ?array $group, int $minutesSinceChange, string $decision) {
    $now = strtotime('2026-10-25T12:00:00Z');
    $state += ['changed_at' => utc_now($now - $minutesSinceChange * 60)];
    expect(alert_decision($state, $group, $now))->toBe($decision);
})->with([
    'already told' => [['status' => 'fail', 'alerted_status' => 'fail'], null, 5, 'none'],
    'not checked yet' => [['status' => 'pending', 'alerted_status' => 'pending'], null, 0, 'none'],
    'first check up' => [['status' => 'ok', 'alerted_status' => 'pending'], null, 0, 'baseline'],
    'first check down' => [['status' => 'fail', 'alerted_status' => 'pending'], null, 0, 'send'],
    'down, no group' => [['status' => 'fail', 'alerted_status' => 'ok'], null, 0, 'send'],
    'up again' => [['status' => 'ok', 'alerted_status' => 'fail'], null, 0, 'send'],
    'immediate' => [['status' => 'fail', 'alerted_status' => 'ok'], ['policy' => 'immediate', 'delay_minutes' => 0], 0, 'send'],
    'delayed, too soon' => [['status' => 'fail', 'alerted_status' => 'ok'], ['policy' => 'delayed', 'delay_minutes' => 10], 9, 'wait'],
    'delayed, long enough' => [['status' => 'fail', 'alerted_status' => 'ok'], ['policy' => 'delayed', 'delay_minutes' => 10], 10, 'send'],
    'delayed, up again' => [['status' => 'ok', 'alerted_status' => 'fail'], ['policy' => 'delayed', 'delay_minutes' => 10], 0, 'send'],
    'digest' => [['status' => 'fail', 'alerted_status' => 'ok'], ['policy' => 'digest', 'delay_minutes' => 0], 0, 'quiet'],
    'silent' => [['status' => 'fail', 'alerted_status' => 'ok'], ['policy' => 'silent', 'delay_minutes' => 0], 0, 'quiet'],
]);

test('alerts_configured: true once alerts can go somewhere, by default or for a group', function () {
    $db = get_db(temp_dir() . '/vaka.sqlite');
    $none = ['slack_webhook_url' => '', 'webhook_url' => '', 'alert_email' => []];
    expect(alerts_configured($db, $none))->toBeFalse()
        ->and(alerts_configured($db, ['slack_webhook_url' => 'https://hooks.example/a'] + $none))->toBeTrue()
        ->and(alerts_configured($db, ['alert_email' => ['ops@example.com']] + $none))->toBeTrue();
    add_group($db, ['name' => 'Customers']);
    expect(alerts_configured($db, $none))->toBeFalse();
    add_group($db, ['name' => 'Own', 'webhook_url' => 'https://hooks.example/b']);
    expect(alerts_configured($db, $none))->toBeTrue();
});

test('alert_destinations: the group\'s own, otherwise the defaults in .env', function () {
    $config = ['slack_webhook_url' => 'https://hooks.example/default', 'webhook_url' => '', 'alert_email' => ['ops@example.com']];
    $own = ['slack_webhook' => 'https://hooks.example/own', 'webhook_url' => 'https://example.com/hook', 'email' => ''];
    $none = ['slack_webhook' => '', 'webhook_url' => '', 'email' => ''];

    expect(array_column(alert_destinations($own, $config), 'key'))->toBe(['slack:https://hooks.example/own', 'webhook:https://example.com/hook'])
        ->and(array_column(alert_destinations($none, $config), 'key'))->toBe(['slack:https://hooks.example/default', 'email:ops@example.com'])
        ->and(array_column(alert_destinations(null, $config), 'key'))->toBe(['slack:https://hooks.example/default', 'email:ops@example.com'])
        ->and(alert_destinations($none, ['slack_webhook_url' => '', 'webhook_url' => '', 'alert_email' => []]))->toBe([]);
});

// =====================================================================
// A site that keeps going down and up (flapping)
// =====================================================================

test('notice_for turns a second outage within the window into one unstable alert', function (array $state, string $decision, int $outages, array $expected) {
    $now = strtotime('2026-10-25T12:00:00Z');
    $state += ['flapping_since' => null, 'changed_at' => utc_now($now)];
    expect(notice_for($state, $decision, $outages, $now))->toBe($expected);
})->with([
    'first outage' => [['status' => 'fail'], 'send', 1, ['action' => 'send', 'kind' => 'down']],
    'recovery' => [['status' => 'ok'], 'send', 1, ['action' => 'send', 'kind' => 'up']],
    'second outage' => [['status' => 'fail'], 'send', 2, ['action' => 'send', 'kind' => 'unstable']],
    'baseline' => [['status' => 'ok'], 'baseline', 0, ['action' => 'mark', 'kind' => null]],
    'quiet policy' => [['status' => 'fail'], 'quiet', 5, ['action' => 'mark', 'kind' => null]],
    'waiting' => [['status' => 'fail'], 'wait', 2, ['action' => 'none', 'kind' => null]],
]);

test('while a site is unstable nothing is sent until it has been stable for 15 minutes', function () {
    $now = strtotime('2026-10-25T12:00:00Z');
    $flapping = ['flapping_since' => utc_now($now - 3600)];

    expect(notice_for(['status' => 'ok', 'changed_at' => utc_now($now - 14 * 60)] + $flapping, 'send', 4, $now))->toBe(['action' => 'none', 'kind' => null])
        ->and(notice_for(['status' => 'fail', 'changed_at' => utc_now($now - 60)] + $flapping, 'none', 4, $now))->toBe(['action' => 'none', 'kind' => null])
        ->and(notice_for(['status' => 'ok', 'changed_at' => utc_now($now - 15 * 60)] + $flapping, 'none', 4, $now))->toBe(['action' => 'send', 'kind' => 'stable'])
        ->and(notice_for(['status' => 'fail', 'changed_at' => utc_now($now - 15 * 60)] + $flapping, 'none', 4, $now))->toBe(['action' => 'send', 'kind' => 'down'])
        ->and(notice_for(['status' => 'fail', 'changed_at' => utc_now($now - 60)] + $flapping, 'quiet', 4, $now))->toBe(['action' => 'mark', 'kind' => null]);
});

test('unstable and stable again read plainly, for one site and for a server', function () {
    expect(alert_text(bundle_notices([notice(['kind' => 'unstable', 'outages' => 2])], [])[0]['items'][0]))->toBe([
        'headline' => 'shop.example (Customers) is unstable: down 2 times in 30 min',
        'details' => ['No answer within 10 s', 'Vaka stays quiet about it until it has been stable for 15 min.'],
    ]);
    expect(alert_text(bundle_notices([notice(['kind' => 'stable'])], [])[0]['items'][0]))->toBe([
        'headline' => 'shop.example (Customers) is stable again: up for 15 min',
        'details' => [],
    ]);

    $server = array_map(fn ($n) => ['kind' => 'unstable', 'outages' => 3] + $n, down_sites(4));
    expect(alert_text(bundle_notices($server, ['ip:203.0.113.10' => 4])[0]['items'][0])['headline'])
        ->toBe('Server 203.0.113.10 is unstable: all 4 sites went down 3 times in 30 min');
    $stable = array_map(fn ($n) => ['kind' => 'stable'] + $n, down_sites(4));
    expect(alert_text(bundle_notices($stable, ['ip:203.0.113.10' => 4])[0]['items'][0])['headline'])
        ->toBe('Server 203.0.113.10 is stable again: 4 sites up for 15 min');
});

test('the unstable alert uses the warning emoji in Slack', function () {
    $payload = slack_payload(bundle_notices([notice(['kind' => 'unstable', 'outages' => 2])], [])[0], '');
    expect($payload['text'])->toStartWith(':warning: *shop.example (Customers) is unstable');
});

// =====================================================================
// A site that stays down
// =====================================================================

test('reminder_due: a site still down is reminded every few hours after the alert', function (array $state, ?array $group, int $hours, bool $due) {
    $now = strtotime('2026-10-25T12:00:00Z');
    $state += ['status' => 'fail', 'alerted_status' => 'fail', 'flapping_since' => null];
    $state['alerted_at'] = utc_now($now - $state['told_minutes_ago'] * 60);
    expect(reminder_due($state, $group, $hours, $now))->toBe($due);
})->with([
    'told 4 h ago' => [['told_minutes_ago' => 240], null, 4, true],
    'told 3 h 59 min ago' => [['told_minutes_ago' => 239], null, 4, false],
    'up again' => [['told_minutes_ago' => 300, 'status' => 'ok'], null, 4, false],
    'not told yet' => [['told_minutes_ago' => 300, 'alerted_status' => 'ok'], null, 4, false],
    'snoozed' => [['told_minutes_ago' => 300, 'alerted_status' => 'snoozed'], null, 4, false],
    'unstable' => [['told_minutes_ago' => 300, 'flapping_since' => '2026-10-25T06:00:00Z'], null, 4, false],
    'immediate' => [['told_minutes_ago' => 300], ['policy' => 'immediate'], 4, true],
    'delayed' => [['told_minutes_ago' => 300], ['policy' => 'delayed'], 4, true],
    'digest' => [['told_minutes_ago' => 300], ['policy' => 'digest'], 4, false],
    'silent' => [['told_minutes_ago' => 300], ['policy' => 'silent'], 4, false],
    'turned off' => [['told_minutes_ago' => 3000], null, 0, false],
]);

test('a reminder says how long the site has been down and how to stop the reminders', function () {
    $reminder = ['kind' => 'reminder', 'down_seconds' => 4 * 3600 + 120, 'remind_hours' => 4];
    expect(alert_text(bundle_notices([notice($reminder)], [])[0]['items'][0]))->toBe([
        'headline' => 'shop.example (Customers) has been down for 4 h 2 min',
        'details' => ['No answer within 10 s', 'Vaka reminds you every 4 h while it is down. Snooze it in Vaka if someone is already on it.'],
    ]);

    $server = array_map(fn ($n) => $reminder + $n, down_sites(4));
    expect(alert_text(bundle_notices($server, ['ip:203.0.113.10' => 4])[0]['items'][0]))->toBe([
        'headline' => 'Server 203.0.113.10 is still not answering: all 4 sites have been down for 4 h 2 min',
        'details' => [
            'site1.example, site2.example, site3.example, site4.example',
            'No answer within 10 s',
            'Vaka reminds you every 4 h while they are down. Snooze them in Vaka if someone is already on it.',
        ],
    ]);
    expect(alert_text(bundle_notices(array_slice($server, 0, 2), ['ip:203.0.113.10' => 4])[0]['items'][0])['headline'])
        ->toBe('2 of 4 sites on server 203.0.113.10 have been down for 4 h 2 min');
});

test('a reminder is red in Slack and comes after new outages', function () {
    $bundle = bundle_notices([
        notice(['site_id' => 1, 'url' => 'https://a.example', 'kind' => 'reminder', 'down_seconds' => 4 * 3600, 'remind_hours' => 4, 'server_key' => null]),
        notice(['site_id' => 2, 'url' => 'https://b.example', 'server_key' => null]),
    ], [])[0];

    expect(array_map(fn ($item) => alert_text($item)['headline'], $bundle['items']))->toBe([
        'b.example (Customers) is down',
        'a.example (Customers) has been down for 4 h',
    ])
        ->and(slack_payload($bundle, '')['text'])->toContain(':red_circle: *a.example (Customers) has been down for 4 h*')
        ->and(webhook_payload($bundle, '2026-10-25T16:00:00Z')['alerts'][1]['kind'])->toBe('reminder');
});

// =====================================================================
// One message, not fifty
// =====================================================================

test('sites on the same server that go down together are one alert', function () {
    $bundles = bundle_notices(down_sites(10), ['ip:203.0.113.10' => 10]);

    expect($bundles)->toHaveCount(1)
        ->and($bundles[0]['items'])->toHaveCount(1)
        ->and(alert_text($bundles[0]['items'][0]))->toBe([
            'headline' => 'Server 203.0.113.10 is not answering: all 10 sites are down',
            'details' => [
                'site1.example, site2.example, site3.example, site4.example, site5.example, site6.example, site7.example, site8.example, site9.example, site10.example',
                'No answer within 10 s',
            ],
        ]);
});

test('some of the sites on a server is a partial outage, with each reason when they differ', function () {
    $notices = down_sites(2);
    $notices[] = notice(['site_id' => 3, 'url' => 'https://site3.example', 'summary' => 'The site answered 502 Bad Gateway']);
    $text = alert_text(bundle_notices($notices, ['ip:203.0.113.10' => 10])[0]['items'][0]);

    expect($text['headline'])->toBe('3 of 10 sites on server 203.0.113.10 are down')
        ->and($text['details'])->toBe([
            'site1.example: no answer within 10 s',
            'site2.example: no answer within 10 s',
            'site3.example: the site answered 502 Bad Gateway',
        ]);
});

test('a long list of sites is cut short', function () {
    $text = alert_text(bundle_notices(down_sites(20), ['ip:203.0.113.10' => 40])[0]['items'][0]);
    expect($text['headline'])->toBe('20 of 40 sites on server 203.0.113.10 are down')
        ->and($text['details'][0])->toEndWith('site15.example and 5 more');
});

test('one site is an alert of its own, with its group', function () {
    $text = alert_text(bundle_notices([notice()], ['ip:203.0.113.10' => 10])[0]['items'][0]);
    expect($text)->toBe(['headline' => 'shop.example (Customers) is down', 'details' => ['No answer within 10 s']]);
});

test('sites on different servers or behind a CDN are not folded together', function () {
    $notices = [
        notice(['site_id' => 1, 'url' => 'https://a.example']),
        notice(['site_id' => 2, 'url' => 'https://b.example', 'server_key' => 'ip:198.51.100.7', 'server' => '198.51.100.7']),
        notice(['site_id' => 3, 'url' => 'https://c.example', 'server_key' => null, 'server' => null]),
        notice(['site_id' => 4, 'url' => 'https://d.example', 'server_key' => null, 'server' => null]),
    ];
    $items = bundle_notices($notices, ['ip:203.0.113.10' => 5, 'ip:198.51.100.7' => 5])[0]['items'];
    expect(array_map(fn ($item) => alert_text($item)['headline'], $items))->toBe([
        'a.example (Customers) is down',
        'b.example (Customers) is down',
        'c.example (Customers) is down',
        'd.example (Customers) is down',
    ]);
});

test('recoveries are folded the same way, with how long it took', function () {
    $up = array_map(fn ($n) => ['kind' => 'up', 'down_seconds' => 600 + $n['site_id'] * 60] + $n, down_sites(10));
    expect(alert_text(bundle_notices($up, ['ip:203.0.113.10' => 10])[0]['items'][0])['headline'])
        ->toBe('Server 203.0.113.10 is back: 10 sites up again after 20 min');

    $some = array_slice($up, 0, 3);
    expect(alert_text(bundle_notices($some, ['ip:203.0.113.10' => 10])[0]['items'][0])['headline'])
        ->toBe('3 sites on server 203.0.113.10 are up again after 13 min');

    expect(alert_text(bundle_notices([notice(['kind' => 'up', 'down_seconds' => 840])], [])[0]['items'][0]))
        ->toBe(['headline' => 'shop.example (Customers) is up again after 14 min', 'details' => []]);
});

test('each destination gets one message with only its own sites, outages first', function () {
    $other = [['type' => 'email', 'target' => ['ops@example.com'], 'key' => 'email:ops@example.com']];
    $notices = [
        notice(['site_id' => 1, 'url' => 'https://a.example', 'kind' => 'up']),
        notice(['site_id' => 2, 'url' => 'https://b.example']),
        notice(['site_id' => 3, 'url' => 'https://c.example', 'destinations' => $other, 'server_key' => null]),
    ];
    $bundles = bundle_notices($notices, ['ip:203.0.113.10' => 10]);

    expect(array_column(array_column($bundles, 'destination'), 'key'))->toBe(['slack:https://hooks.example/a', 'email:ops@example.com'])
        ->and(array_map(fn ($item) => alert_text($item)['headline'], $bundles[0]['items']))->toBe([
            'b.example (Customers) is down',
            'a.example (Customers) is up again',
        ])
        ->and($bundles[1]['items'])->toHaveCount(1);
});

// =====================================================================
// Messages
// =====================================================================

test('the Slack message escapes what came from the sites', function () {
    $bundle = bundle_notices([notice(['url' => 'https://shop.example', 'summary' => 'Answered 200 OK, but the page does not contain "<!channel> & co"'])], [])[0];
    $payload = slack_payload($bundle, 'https://vaka.example.com');

    expect($payload['text'])->toContain(':red_circle: *shop.example (Customers) is down*')
        ->and($payload['text'])->toContain('&lt;!channel&gt; &amp; co')
        ->and($payload['text'])->not->toContain('<!channel>')
        ->and($payload['text'])->toContain('<https://vaka.example.com|Open Vaka>');
});

test('the email has a plain subject and body', function () {
    $bundle = bundle_notices(array_merge(down_sites(3), [notice(['site_id' => 9, 'url' => 'https://other.example', 'server_key' => null])]), ['ip:203.0.113.10' => 3])[0];
    $email = email_message($bundle, '');

    expect($email['subject'])->toBe('Server 203.0.113.10 is not answering: all 3 sites are down (and 1 more alert)')
        ->and($email['body'])->toContain("Server 203.0.113.10 is not answering: all 3 sites are down\n  site1.example, site2.example, site3.example\n  No answer within 10 s")
        ->and($email['body'])->toContain('other.example (Customers) is down');
});

test('an email subject never carries line breaks', function () {
    $bundle = bundle_notices([notice(['group' => "Evil\r\nBcc: someone@example.com"])], [])[0];
    expect(email_message($bundle, '')['subject'])->not->toContain("\n")->not->toContain("\r");
});

test('the webhook payload has stable fields', function () {
    $bundle = bundle_notices(down_sites(2), ['ip:203.0.113.10' => 2])[0];
    $payload = webhook_payload($bundle, '2026-10-25T12:01:00Z');

    expect($payload)->toMatchArray(['source' => 'vaka', 'version' => VAKA_VERSION, 'sent_at' => '2026-10-25T12:01:00Z'])
        ->and($payload['alerts'][0])->toMatchArray(['kind' => 'down', 'headline' => 'Server 203.0.113.10 is not answering: all 2 sites are down', 'server' => '203.0.113.10'])
        ->and($payload['alerts'][0]['sites'][0])->toBe(['url' => 'https://site1.example', 'group' => 'Customers', 'summary' => 'No answer within 10 s', 'since' => '2026-10-25T12:00:00Z']);
});

// =====================================================================
// Daily summary
// =====================================================================

test('the daily summary lists what happened and what is still down', function () {
    $events = [
        ['url' => 'https://shop.example', 'status' => 'fail', 'message' => 'Down: no answer within 10 s', 'created_at' => '2026-10-25T01:12:00Z'],
        ['url' => 'https://shop.example', 'status' => 'ok', 'message' => 'Up again after 28 min: answered 200 OK in 240 ms', 'created_at' => '2026-10-25T01:40:00Z'],
    ];
    $stillDown = [['url' => 'https://blog.example', 'summary' => 'The site answered 503 Service Unavailable', 'changed_at' => '2026-10-25T05:00:00Z']];

    $text = digest_text('Customers', $events, $stillDown, 'Europe/Stockholm');
    expect($text['headline'])->toBe('Daily summary for Customers: 1 outage, 1 site still down')
        ->and($text['details'])->toBe([
            'Still down: blog.example since 06:00: the site answered 503 Service Unavailable',
            '02:12 shop.example: down: no answer within 10 s',
            '02:40 shop.example: up again after 28 min: answered 200 OK in 240 ms',
        ]);
});

test('the daily summary tells the time in 12h when TIME_FORMAT says so', function () {
    $events = [['url' => 'https://shop.example', 'status' => 'fail', 'message' => 'Down: no answer within 10 s', 'created_at' => '2026-10-25T13:12:00Z']];
    $stillDown = [['url' => 'https://blog.example', 'summary' => 'The site answered 503 Service Unavailable', 'changed_at' => '2026-10-25T05:00:00Z']];
    expect(digest_text('Customers', $events, $stillDown, 'Europe/Stockholm', '12h')['details'])->toBe([
        'Still down: blog.example since 6:00 AM: the site answered 503 Service Unavailable',
        '2:12 PM shop.example: down: no answer within 10 s',
    ]);
});
