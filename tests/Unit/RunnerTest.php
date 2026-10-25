<?php

beforeEach(function () {
    $this->db = get_db(temp_dir() . '/vaka.sqlite');
    $this->now = strtotime('2026-10-25T12:00:00Z');
});

test('a new site is due for its uptime check', function () {
    add_site($this->db, ['url' => 'https://shop.example']);
    expect(array_column(due_uptime_sites($this->db, utc_now($this->now)), 'url'))->toBe(['https://shop.example']);
});

test('a site is not due before its next check', function () {
    $id = add_site($this->db, ['url' => 'https://shop.example']);
    save_check_state($this->db, $id, 'uptime', ['status' => 'ok', 'summary' => '', 'details' => [], 'fail_count' => 0, 'changed_at' => null], utc_now($this->now), utc_now($this->now + 300));

    expect(due_uptime_sites($this->db, utc_now($this->now + 299)))->toBe([])
        ->and(due_uptime_sites($this->db, utc_now($this->now + 300)))->toHaveCount(1);
});

test('paused sites and sites with uptime turned off are never due', function () {
    add_site($this->db, ['url' => 'https://paused.example', 'paused' => true]);
    add_site($this->db, ['url' => 'https://off.example', 'disabled_checks' => 'uptime,ssl']);
    add_site($this->db, ['url' => 'https://ssl-off.example', 'disabled_checks' => 'ssl']);

    expect(array_column(due_uptime_sites($this->db, utc_now($this->now)), 'url'))->toBe(['https://ssl-off.example']);
});

test('run_locked runs once at a time', function () {
    $lock = temp_dir() . '/vaka.lock';
    $inner = null;
    $outer = run_locked($lock, function () use ($lock, &$inner) {
        $inner = run_locked($lock, fn () => 'ran twice');
        return 'ran';
    });

    expect($outer)->toBe('ran')
        ->and($inner)->toBeNull()
        ->and(run_locked($lock, fn () => 'free again'))->toBe('free again');
});

test('a run that is already going in another process is skipped', function () {
    $lock = temp_dir() . '/vaka.lock';
    $holder = proc_open([PHP_BINARY, '-r', '$f = fopen($argv[1], "c"); flock($f, LOCK_EX); echo "locked\n"; sleep(5);', $lock], [1 => ['pipe', 'w']], $pipes);
    expect(trim(fgets($pipes[1])))->toBe('locked');

    expect(run_locked($lock, fn () => 'ran'))->toBeNull();

    proc_terminate($holder);
    proc_close($holder);
    expect(run_locked($lock, fn () => 'ran'))->toBe('ran');
});

test('cleanup trims old samples once a day', function () {
    $id = add_site($this->db, ['url' => 'https://shop.example']);
    $sample = $this->db->prepare('INSERT INTO samples (site_id, checked_at, status_code, ms, ok, ip) VALUES (?, ?, 200, 100, 1, ?)');
    $sample->execute([$id, utc_now($this->now - 31 * 86400), '192.0.2.10']);
    $sample->execute([$id, utc_now($this->now - 29 * 86400), '192.0.2.10']);

    cleanup($this->db, test_config(['samples_retention_days' => 30]), $this->now);
    expect((int) $this->db->query('SELECT COUNT(*) FROM samples')->fetchColumn())->toBe(1);

    // Already cleaned today: an old sample added now stays until tomorrow
    $sample->execute([$id, utc_now($this->now - 40 * 86400), '192.0.2.10']);
    cleanup($this->db, test_config(['samples_retention_days' => 30]), $this->now + 3600);
    expect((int) $this->db->query('SELECT COUNT(*) FROM samples')->fetchColumn())->toBe(2);

    cleanup($this->db, test_config(['samples_retention_days' => 30]), $this->now + 86400);
    expect((int) $this->db->query('SELECT COUNT(*) FROM samples')->fetchColumn())->toBe(1);
});

test('removing a site removes its state, events and samples', function () {
    $id = add_site($this->db, ['url' => 'https://shop.example']);
    save_check_state($this->db, $id, 'uptime', ['status' => 'ok', 'summary' => '', 'details' => [], 'fail_count' => 0, 'changed_at' => null], utc_now($this->now), utc_now($this->now));
    add_event($this->db, $id, 'uptime', ['status' => 'ok', 'previous' => 'pending', 'message' => 'First check'], utc_now($this->now));
    $this->db->exec("INSERT INTO samples (site_id, checked_at, status_code, ms, ok) VALUES ({$id}, '2026-10-25T12:00:00Z', 200, 1, 1)");

    remove_site($this->db, $id);

    foreach (['check_state', 'events', 'samples'] as $table) {
        expect((int) $this->db->query("SELECT COUNT(*) FROM {$table}")->fetchColumn())->toBe(0, $table);
    }
});
