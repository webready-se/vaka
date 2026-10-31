<?php

// =====================================================================
// Client IP behind trusted proxies (same rules as Puls)
// =====================================================================

test('ip_in_range matches single addresses and CIDR ranges in both families', function () {
    expect(ip_in_range('192.0.2.4', '192.0.2.4'))->toBeTrue()
        ->and(ip_in_range('192.0.2.5', '192.0.2.4'))->toBeFalse()
        ->and(ip_in_range('192.0.2.200', '192.0.2.0/24'))->toBeTrue()
        ->and(ip_in_range('198.51.100.15', '198.51.100.0/28'))->toBeTrue()
        ->and(ip_in_range('198.51.100.16', '198.51.100.0/28'))->toBeFalse()
        ->and(ip_in_range('2001:db8:1:2::abcd', '2001:db8:1::/48'))->toBeTrue()
        ->and(ip_in_range('::ffff:192.0.2.4', '192.0.2.0/24'))->toBeTrue()
        ->and(ip_in_range('192.0.2.4', '2001:db8::/32'))->toBeFalse()
        ->and(ip_in_range('192.0.2.4', 'not-an-ip'))->toBeFalse();
});

test('client_ip trusts forwarded headers only from a trusted proxy', function () {
    $server = ['REMOTE_ADDR' => '203.0.113.9', 'HTTP_X_FORWARDED_FOR' => '192.0.2.50'];
    expect(client_ip($server, []))->toBe('203.0.113.9')
        ->and(client_ip($server, ['203.0.113.0/24']))->toBe('192.0.2.50')
        ->and(client_ip(['REMOTE_ADDR' => '203.0.113.9', 'HTTP_X_REAL_IP' => '192.0.2.51'], ['203.0.113.9']))->toBe('192.0.2.51');
});

test('client_ip takes the last hop that is not a trusted proxy, so a spoofed first hop is ignored', function () {
    $server = ['REMOTE_ADDR' => '203.0.113.9', 'HTTP_X_FORWARDED_FOR' => '10.0.0.1, 192.0.2.50, 203.0.113.8'];
    expect(client_ip($server, ['203.0.113.0/24']))->toBe('192.0.2.50');
});

// =====================================================================
// Users
// =====================================================================

test('usernames are short, lowercase and safe', function () {
    expect(clean_username(' Admin '))->toBe('admin')
        ->and(clean_username('anna.dev-2'))->toBe('anna.dev-2');
    expect(fn () => clean_username('a b'))->toThrow(InvalidArgumentException::class, 'letters, digits')
        ->and(fn () => clean_username(''))->toThrow(InvalidArgumentException::class)
        ->and(fn () => clean_username(str_repeat('a', 41)))->toThrow(InvalidArgumentException::class);
});

test('users are saved readable by the owner only', function () {
    $file = temp_dir() . '/users.json';
    save_users($file, ['admin' => ['password' => hash_password('long enough password'), 'groups' => []]]);

    expect(substr(sprintf('%o', fileperms($file)), -3))->toBe('600')
        ->and(password_verify('long enough password', load_users($file)['admin']['password']))->toBeTrue();
});

test('a user with no groups sees everything, otherwise only the listed groups', function () {
    $db = get_db(temp_dir() . '/vaka.sqlite');
    $customers = add_group($db, ['name' => 'Customers']);
    add_group($db, ['name' => 'Own']);

    expect(user_group_ids($db, ['groups' => []]))->toBeNull()
        ->and(user_group_ids($db, ['groups' => ['customers']]))->toBe([$customers])
        ->and(user_group_ids($db, ['groups' => ['Gone']]))->toBe([]);
});

test('a renamed group stays in the users that had it', function () {
    $file = temp_dir() . '/users.json';
    save_users($file, ['anna' => ['password' => 'x', 'groups' => ['Customers', 'Own']], 'bo' => ['password' => 'x', 'groups' => []]]);
    rename_group_in_users($file, 'customers', 'Clients');
    expect(load_users($file)['anna']['groups'])->toBe(['Clients', 'Own'])
        ->and(load_users($file)['bo']['groups'])->toBe([]);
});

// =====================================================================
// Login lockout, counted per IP in the database
// =====================================================================

test('an IP is locked out after too many failed logins, until a success or the time passes', function () {
    $db = get_db(temp_dir() . '/vaka.sqlite');
    $now = strtotime('2026-10-25T12:00:00Z');
    for ($i = 0; $i < 4; $i++) audit_log($db, 'admin', 'login_failed', '192.0.2.7', $now);
    expect(is_locked_out($db, '192.0.2.7', 5, 15, $now))->toBeFalse();

    audit_log($db, 'admin', 'login_failed', '192.0.2.7', $now);
    expect(is_locked_out($db, '192.0.2.7', 5, 15, $now))->toBeTrue()
        ->and(is_locked_out($db, '192.0.2.8', 5, 15, $now))->toBeFalse()
        ->and(is_locked_out($db, '192.0.2.7', 5, 15, $now + 15 * 60 + 1))->toBeFalse();

    audit_log($db, 'admin', 'login_success', '192.0.2.7', $now + 1);
    expect(is_locked_out($db, '192.0.2.7', 5, 15, $now + 2))->toBeFalse();
});

test('cleanup trims the login log after 30 days', function () {
    $db = get_db(temp_dir() . '/vaka.sqlite');
    $now = strtotime('2026-10-25T12:00:00Z');
    audit_log($db, 'admin', 'login_failed', '192.0.2.7', $now - 31 * 86400);
    audit_log($db, 'admin', 'login_failed', '192.0.2.7', $now - 29 * 86400);
    cleanup($db, test_config(), $now);
    expect((int) $db->query('SELECT COUNT(*) FROM audit_log')->fetchColumn())->toBe(1);
});

test('cleanup removes session files older than a session lasts, which many servers never do', function () {
    $dir = temp_dir();
    $db = get_db("{$dir}/vaka.sqlite");
    $now = strtotime('2026-10-25T12:00:00Z');
    mkdir("{$dir}/sessions");
    touch("{$dir}/sessions/sess_old", $now - 31 * 86400);
    touch("{$dir}/sessions/sess_fresh", $now - 3600);
    cleanup($db, test_config(['db_path' => "{$dir}/vaka.sqlite", 'session_lifetime' => 30 * 86400]), $now);
    expect(file_exists("{$dir}/sessions/sess_old"))->toBeFalse()
        ->and(file_exists("{$dir}/sessions/sess_fresh"))->toBeTrue();
});

// =====================================================================
// Uptime percentage, weighted by time
// =====================================================================

test('down periods run from down to up, clipped to the period', function () {
    $from = strtotime('2026-10-25T00:00:00Z');
    $to = strtotime('2026-10-26T00:00:00Z');
    $events = [
        ['status' => 'fail', 'created_at' => '2026-10-25T10:00:00Z'],
        ['status' => 'ok', 'created_at' => '2026-10-25T10:30:00Z'],
        ['status' => 'fail', 'created_at' => '2026-10-25T23:00:00Z'],
    ];
    expect(down_periods($events, 'ok', $from, $to))->toBe([
        [strtotime('2026-10-25T10:00:00Z'), strtotime('2026-10-25T10:30:00Z')],
        [strtotime('2026-10-25T23:00:00Z'), $to],
    ])
        ->and(period_seconds(down_periods($events, 'ok', $from, $to)))->toBe(30 * 60 + 3600)
        ->and(down_periods([['status' => 'ok', 'created_at' => '2026-10-25T01:00:00Z']], 'fail', $from, $to))->toBe([[$from, $from + 3600]])
        ->and(down_periods([], 'fail', $from, $to))->toBe([[$from, $to]])
        ->and(down_periods([], 'ok', $from, $to))->toBe([]);
});

test('overlap_seconds counts the time inside the cover once, even where the cover overlaps itself', function () {
    $down = [[100, 200], [300, 400]];
    expect(overlap_seconds($down, [[150, 350]]))->toBe(50 + 50)
        ->and(overlap_seconds($down, [[150, 250], [120, 180]]))->toBe(80)
        ->and(overlap_seconds($down, []))->toBe(0)
        ->and(overlap_seconds([], [[0, 1000]]))->toBe(0);
});

test('uptime_percent rounds to what is worth showing and is null without data', function () {
    expect(uptime_percent(0, 86400))->toBe(100.0)
        ->and(uptime_percent(30 * 60 + 3600, 86400))->toBe(93.75)
        ->and(uptime_percent(1, 86400 * 30))->toBe(99.99)
        ->and(uptime_percent(0, 0))->toBeNull();
});

test('daily_uptime gives each of the last days its uptime, in local days, from the first check', function () {
    $db = get_db(temp_dir() . '/vaka.sqlite');
    $id = add_site($db, ['url' => 'https://shop.example']);
    // 12:00 in Stockholm on 25 October 2026 (winter time from 03:00 that night)
    $now = strtotime('2026-10-25T11:00:00Z');
    $db->exec("UPDATE sites SET created_at = '2026-10-22T09:50:00Z' WHERE id = {$id}");
    add_event($db, $id, 'uptime', ['status' => 'ok', 'previous' => 'pending', 'message' => 'First check'], '2026-10-22T10:00:00Z');
    add_event($db, $id, 'uptime', ['status' => 'fail', 'previous' => 'ok', 'message' => 'Down'], '2026-10-24T08:00:00Z');
    add_event($db, $id, 'uptime', ['status' => 'ok', 'previous' => 'fail', 'message' => 'Up'], '2026-10-24T09:12:00Z');
    $site = $db->query("SELECT * FROM sites WHERE id = {$id}")->fetch();

    $days = daily_uptime($db, $site, $now, 5, 'Europe/Stockholm');
    expect(array_column($days, 'date'))->toBe(['2026-10-21', '2026-10-22', '2026-10-23', '2026-10-24', '2026-10-25'])
        ->and(array_column($days, 'up'))->toBe([null, 100.0, 100.0, 95.0, 100.0])
        ->and($days[3])->toMatchArray(['down_seconds' => 72 * 60, 'outages' => 1, 'planned_seconds' => 0])
        ->and($days[0])->toMatchArray(['down_seconds' => null, 'outages' => null, 'planned_seconds' => null]);
});

test('downtime inside a maintenance window is planned: shown on its own and left out of uptime', function () {
    $db = get_db(temp_dir() . '/vaka.sqlite');
    $group = add_group($db, ['name' => 'Customers']);
    $id = add_site($db, ['url' => 'https://shop.example', 'group' => 'Customers']);
    // Every night 00:00-07:00 in Stockholm, for the whole group; 2 November 2026 is a Monday
    add_maintenance($db, null, $group, ['label' => '', 'days' => '1,2,3,4,5,6,7', 'start_time' => '00:00', 'end_time' => '07:00', 'starts_at' => null, 'ends_at' => null]);
    $at = fn (string $local) => utc_now((new DateTimeImmutable($local, new DateTimeZone('Europe/Stockholm')))->getTimestamp());
    $event = fn (string $status, string $local) => add_event($db, $id, 'uptime', ['status' => $status, 'previous' => '', 'message' => ''], $at($local));
    $event('ok', '2026-11-01 12:00');
    // A restart in the window: planned
    $event('fail', '2026-11-02 02:00');
    $event('ok', '2026-11-02 02:20');
    // Down from 06:50 until 07:30: ten minutes planned, the half hour after the window is not
    $event('fail', '2026-11-02 06:50');
    $event('ok', '2026-11-02 07:30');
    // And five minutes in the day
    $event('fail', '2026-11-02 12:00');
    $event('ok', '2026-11-02 12:05');
    $site = $db->query("SELECT * FROM sites WHERE id = {$id}")->fetch();
    $now = (new DateTimeImmutable('2026-11-03 12:00', new DateTimeZone('Europe/Stockholm')))->getTimestamp();

    $days = daily_uptime($db, $site, $now, 3, 'Europe/Stockholm');
    expect($days[1])->toBe(['date' => '2026-11-02', 'up' => 97.56, 'down_seconds' => 35 * 60, 'outages' => 2, 'planned_seconds' => 30 * 60])
        ->and($days[2])->toMatchArray(['up' => 100.0, 'down_seconds' => 0, 'outages' => 0, 'planned_seconds' => 0])
        // Watched for two days, 35 minutes of them down outside the window
        ->and(site_uptime($db, $site, 7 * 86400, $now, 'Europe/Stockholm'))->toBe(uptime_percent(35 * 60, 2 * 86400));
});

test('response time buckets: the average per hour, and how many checks were up', function () {
    $now = strtotime('2026-10-25T12:00:00Z');
    $samples = [
        ['checked_at' => '2026-10-25T11:10:00Z', 'ms' => 100, 'ok' => 1],
        ['checked_at' => '2026-10-25T11:50:00Z', 'ms' => 300, 'ok' => 1],
        ['checked_at' => '2026-10-25T09:30:00Z', 'ms' => 0, 'ok' => 0],
    ];
    $buckets = hourly_buckets($samples, $now, 4);
    expect($buckets)->toBe([
        ['ms' => null, 'up' => null],
        ['ms' => null, 'up' => 0.0],
        ['ms' => null, 'up' => null],
        ['ms' => 200, 'up' => 1.0],
    ]);
});

test('response time buckets for every site in one query come out the same as counted one by one', function () {
    $db = get_db(temp_dir() . '/vaka.sqlite');
    $shop = add_site($db, ['url' => 'https://shop.example']);
    $blog = add_site($db, ['url' => 'https://blog.example']);
    $quiet = add_site($db, ['url' => 'https://quiet.example']);
    $now = strtotime('2026-10-25T12:00:00Z');
    $samples = [
        $shop => [['2026-10-25T11:10:00Z', 100, 1], ['2026-10-25T11:50:00Z', 301, 1], ['2026-10-25T09:30:00Z', 0, 0], ['2026-10-25T09:35:00Z', 180, 1]],
        // One just before the 4 hours, one on their first second
        $blog => [['2026-10-25T07:59:59Z', 999, 1], ['2026-10-25T08:00:00Z', 250, 1], ['2026-10-25T10:05:00Z', 0, 0]],
    ];
    $insert = $db->prepare('INSERT INTO samples (site_id, checked_at, status_code, ms, ok, ip) VALUES (?, ?, ?, ?, ?, ?)');
    foreach ($samples as $site => $rows) {
        foreach ($rows as [$at, $ms, $ok]) $insert->execute([$site, $at, $ok ? 200 : 503, $ms, $ok, '192.0.2.10']);
    }
    $one = fn (int $site) => hourly_buckets(array_map(fn ($row) => ['checked_at' => $row[0], 'ms' => $row[1], 'ok' => $row[2]], $samples[$site]), $now, 4);

    $buckets = hourly_buckets_by_site($db, [$shop, $blog, $quiet], $now, 4);
    expect($buckets[$shop])->toBe($one($shop))
        ->and($buckets[$shop][3])->toBe(['ms' => 201, 'up' => 1.0])
        ->and($buckets[$blog])->toBe($one($blog))
        // A site without samples is left out: the caller has its empty hours
        ->and($buckets)->not->toHaveKey($quiet)
        ->and(hourly_buckets_by_site($db, [], $now, 4))->toBe([]);
});
