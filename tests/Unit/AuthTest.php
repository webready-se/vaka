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

// =====================================================================
// Uptime percentage, weighted by time
// =====================================================================

test('downtime counts the time between down and up, clipped to the period', function () {
    $from = strtotime('2026-10-25T00:00:00Z');
    $to = strtotime('2026-10-26T00:00:00Z');
    $events = [
        ['status' => 'fail', 'created_at' => '2026-10-25T10:00:00Z'],
        ['status' => 'ok', 'created_at' => '2026-10-25T10:30:00Z'],
        ['status' => 'fail', 'created_at' => '2026-10-25T23:00:00Z'],
    ];
    expect(downtime_seconds($events, 'ok', $from, $to))->toBe(30 * 60 + 3600)
        ->and(downtime_seconds([['status' => 'ok', 'created_at' => '2026-10-25T01:00:00Z']], 'fail', $from, $to))->toBe(3600)
        ->and(downtime_seconds([], 'fail', $from, $to))->toBe(86400)
        ->and(downtime_seconds([], 'ok', $from, $to))->toBe(0);
});

test('uptime_percent rounds to what is worth showing and is null without data', function () {
    expect(uptime_percent(0, 86400))->toBe(100.0)
        ->and(uptime_percent(30 * 60 + 3600, 86400))->toBe(93.75)
        ->and(uptime_percent(1, 86400 * 30))->toBe(99.99)
        ->and(uptime_percent(0, 0))->toBeNull();
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
