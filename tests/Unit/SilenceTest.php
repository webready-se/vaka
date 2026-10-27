<?php

/** A local time in Stockholm as a Unix timestamp. */
function stockholm(string $local): int
{
    return (new DateTimeImmutable($local, new DateTimeZone('Europe/Stockholm')))->getTimestamp();
}

function recurring(string $days, string $start, string $end): array
{
    return ['days' => $days, 'start_time' => $start, 'end_time' => $end, 'starts_at' => null, 'ends_at' => null];
}

test('parse_days understands the usual ways to say which days', function (string $input, array $days) {
    expect(parse_days($input))->toBe($days);
})->with([
    'daily' => ['daily', [1, 2, 3, 4, 5, 6, 7]],
    'weekdays' => ['weekdays', [1, 2, 3, 4, 5]],
    'weekends' => ['Weekends', [6, 7]],
    'short names' => ['mon, wed,fri', [1, 3, 5]],
    'full names, any order' => ['Sunday,monday', [1, 7]],
]);

test('parse_days rejects a day it does not know', function () {
    expect(fn () => parse_days('mon,funday'))->toThrow(InvalidArgumentException::class, '"funday"');
});

test('parse_time_range reads HH:MM-HH:MM', function () {
    expect(parse_time_range('00:00-07:00'))->toBe(['00:00', '07:00'])
        ->and(parse_time_range(' 7:00 - 9:30 '))->toBe(['07:00', '09:30']);
});

test('parse_time_range rejects what is not a time range', function (string $input) {
    expect(fn () => parse_time_range($input))->toThrow(InvalidArgumentException::class);
})->with(['25:00-07:00', '07:00-07:00', '0700', '07:00', '07:60-08:00']);

test('a recurring window is active between its times on its days', function () {
    $night = recurring('1,2,3,4,5,6,7', '00:00', '07:00');
    expect(window_active($night, stockholm('2026-11-03 00:00'), 'Europe/Stockholm'))->toBeTrue()
        ->and(window_active($night, stockholm('2026-11-03 03:00'), 'Europe/Stockholm'))->toBeTrue()
        ->and(window_active($night, stockholm('2026-11-03 07:00'), 'Europe/Stockholm'))->toBeFalse()
        ->and(window_active($night, stockholm('2026-11-03 23:59'), 'Europe/Stockholm'))->toBeFalse();

    $weekdays = recurring('1,2,3,4,5', '00:00', '07:00');
    // 7 November 2026 is a Saturday
    expect(window_active($weekdays, stockholm('2026-11-07 03:00'), 'Europe/Stockholm'))->toBeFalse();
});

test('a window that crosses midnight belongs to the day it starts', function () {
    // Mondays 22:00 to 02:00; 2 November 2026 is a Monday
    $window = recurring('1', '22:00', '02:00');
    expect(window_active($window, stockholm('2026-11-02 23:00'), 'Europe/Stockholm'))->toBeTrue()
        ->and(window_active($window, stockholm('2026-11-03 01:30'), 'Europe/Stockholm'))->toBeTrue()
        ->and(window_active($window, stockholm('2026-11-03 23:00'), 'Europe/Stockholm'))->toBeFalse()
        ->and(window_active($window, stockholm('2026-11-02 01:00'), 'Europe/Stockholm'))->toBeFalse();
});

test('a recurring window follows local time across the change to winter time', function () {
    // Summer time ends 25 October 2026 at 03:00, which becomes 02:00 again
    $night = recurring('1,2,3,4,5,6,7', '00:00', '07:00');
    expect(window_active($night, strtotime('2026-10-25T05:30:00Z'), 'Europe/Stockholm'))->toBeTrue()   // 06:30 CET
        ->and(window_active($night, strtotime('2026-10-25T06:30:00Z'), 'Europe/Stockholm'))->toBeFalse(); // 07:30 CET
});

test('a one-off window is active from its start to its end', function () {
    $once = ['days' => '', 'start_time' => null, 'end_time' => null, 'starts_at' => '2026-11-01T21:00:00Z', 'ends_at' => '2026-11-02T01:00:00Z'];
    expect(window_active($once, strtotime('2026-11-01T20:59:59Z'), 'UTC'))->toBeFalse()
        ->and(window_active($once, strtotime('2026-11-01T21:00:00Z'), 'UTC'))->toBeTrue()
        ->and(window_active($once, strtotime('2026-11-02T00:59:59Z'), 'UTC'))->toBeTrue()
        ->and(window_active($once, strtotime('2026-11-02T01:00:00Z'), 'UTC'))->toBeFalse();
});

test('describe_window says when, in plain words and local time', function () {
    expect(describe_window(recurring('1,2,3,4,5,6,7', '00:00', '07:00'), 'Europe/Stockholm'))->toBe('every day 00:00-07:00')
        ->and(describe_window(recurring('1,2,3,4,5', '22:00', '02:00'), 'Europe/Stockholm'))->toBe('weekdays 22:00-02:00')
        ->and(describe_window(recurring('6,7', '10:00', '12:00'), 'Europe/Stockholm'))->toBe('weekends 10:00-12:00')
        ->and(describe_window(recurring('1,3', '08:00', '09:00'), 'Europe/Stockholm'))->toBe('Mon, Wed 08:00-09:00')
        ->and(describe_window(['days' => '', 'start_time' => null, 'end_time' => null, 'starts_at' => '2026-11-01T21:00:00Z', 'ends_at' => '2026-11-02T01:00:00Z'], 'Europe/Stockholm'))
        ->toBe('once, 1 Nov 22:00 to 2 Nov 02:00');
});

test('describe_window tells the time in 12h when TIME_FORMAT says so', function () {
    expect(describe_window(recurring('1,2,3,4,5,6,7', '00:00', '07:00'), 'Europe/Stockholm', '12h'))->toBe('every day 12:00 AM to 7:00 AM')
        ->and(describe_window(recurring('1,2,3,4,5', '22:00', '02:00'), 'Europe/Stockholm', '12h'))->toBe('weekdays 10:00 PM to 2:00 AM')
        ->and(describe_window(['days' => '', 'start_time' => null, 'end_time' => null, 'starts_at' => '2026-11-01T21:00:00Z', 'ends_at' => '2026-11-02T01:00:00Z'], 'Europe/Stockholm', '12h'))
        ->toBe('once, 1 Nov 10:00 PM to 2 Nov 2:00 AM');
});

test('maintenance_fields builds a recurring window, every day unless told otherwise', function () {
    expect(maintenance_fields(['at' => '00:00-07:00', 'label' => 'Nightly imports'], 'Europe/Stockholm'))
        ->toBe(['label' => 'Nightly imports', 'days' => '1,2,3,4,5,6,7', 'start_time' => '00:00', 'end_time' => '07:00', 'starts_at' => null, 'ends_at' => null])
        ->and(maintenance_fields(['at' => '22:00-02:00', 'days' => 'weekdays'], 'Europe/Stockholm')['days'])->toBe('1,2,3,4,5');
});

test('maintenance_fields builds a one-off window from local times', function () {
    expect(maintenance_fields(['from' => '2026-11-01 22:00', 'to' => '2026-11-02 02:00'], 'Europe/Stockholm'))
        ->toBe(['label' => '', 'days' => '', 'start_time' => null, 'end_time' => null, 'starts_at' => '2026-11-01T21:00:00Z', 'ends_at' => '2026-11-02T01:00:00Z']);
});

test('maintenance_fields explains what is missing or mixed up', function (array $opts, string $reason) {
    expect(fn () => maintenance_fields($opts, 'Europe/Stockholm'))->toThrow(InvalidArgumentException::class, $reason);
})->with([
    'nothing' => [[], '--at=00:00-07:00'],
    'both kinds' => [['at' => '00:00-07:00', 'from' => '2026-11-01 22:00', 'to' => '2026-11-02 02:00'], 'either'],
    'from without to' => [['from' => '2026-11-01 22:00'], '--to'],
    'ends first' => [['from' => '2026-11-02 02:00', 'to' => '2026-11-01 22:00'], 'after'],
    'not a date' => [['from' => 'tomorrow-ish', 'to' => '2026-11-02 02:00'], 'YYYY-MM-DD HH:MM'],
    'days without times' => [['days' => 'weekdays'], '--at'],
]);

test('active_silence: a snooze, or a maintenance window for the site or its group', function () {
    $now = stockholm('2026-11-03 03:00');
    $site = ['id' => 7, 'group_id' => 3, 'snoozed_until' => null];
    $night = recurring('1,2,3,4,5,6,7', '00:00', '07:00');

    expect(active_silence($site, [], $now, 'Europe/Stockholm'))->toBe('')
        ->and(active_silence(['snoozed_until' => utc_now($now + 60)] + $site, [], $now, 'Europe/Stockholm'))->toBe('snooze')
        ->and(active_silence(['snoozed_until' => utc_now($now - 60)] + $site, [], $now, 'Europe/Stockholm'))->toBe('')
        ->and(active_silence($site, [['site_id' => 7, 'group_id' => null] + $night], $now, 'Europe/Stockholm'))->toBe('maintenance')
        ->and(active_silence($site, [['site_id' => null, 'group_id' => 3] + $night], $now, 'Europe/Stockholm'))->toBe('maintenance')
        ->and(active_silence($site, [['site_id' => 8, 'group_id' => null] + $night, ['site_id' => null, 'group_id' => 4] + $night], $now, 'Europe/Stockholm'))->toBe('');
});

test('parse_snooze takes 1h, 4h or 24h', function () {
    expect(parse_snooze('1h'))->toBe(3600)
        ->and(parse_snooze('4H'))->toBe(14400)
        ->and(parse_snooze('24h'))->toBe(86400)
        ->and(fn () => parse_snooze('forever'))->toThrow(InvalidArgumentException::class, '1h, 4h or 24h');
});

test('still down after a snooze reads plainly, also for a server', function () {
    expect(alert_text(bundle_notices([notice(['kind' => 'still_down'])], [])[0]['items'][0]))->toBe([
        'headline' => 'shop.example (Customers) is still down after the snooze',
        'details' => ['No answer within 10 s'],
    ]);
    $server = array_map(fn ($n) => ['kind' => 'still_down'] + $n, down_sites(3));
    expect(alert_text(bundle_notices($server, ['ip:203.0.113.10' => 3])[0]['items'][0])['headline'])
        ->toBe('Server 203.0.113.10: all 3 sites are still down after the snooze');
});

test('notice_for: still down after a snooze is its own kind', function () {
    $now = strtotime('2026-10-25T12:00:00Z');
    $state = ['status' => 'fail', 'alerted_status' => 'snoozed', 'flapping_since' => null, 'changed_at' => utc_now($now - 7200)];
    expect(notice_for($state, alert_decision($state, null, $now), 0, $now))->toBe(['action' => 'send', 'kind' => 'still_down']);
});
