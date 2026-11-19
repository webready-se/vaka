<?php

test('get_db creates the database and its directory', function () {
    $path = temp_dir() . '/nested/dir/vaka.sqlite';
    get_db($path);
    expect(file_exists($path))->toBeTrue();
});

test('get_db uses WAL mode', function () {
    $db = get_db(temp_dir() . '/vaka.sqlite');
    expect($db->query('PRAGMA journal_mode')->fetchColumn())->toBe('wal');
});

test('get_db brings a new database to the latest schema version', function () {
    $db = get_db(temp_dir() . '/vaka.sqlite');
    $latest = max(array_keys(migrations()));
    expect((int) $db->query('PRAGMA user_version')->fetchColumn())->toBe($latest);
});

test('opening the same database again leaves the schema alone', function () {
    $path = temp_dir() . '/vaka.sqlite';
    get_db($path)->exec("INSERT INTO meta (key, value) VALUES ('probe', 'kept')");
    $db = get_db($path);
    expect($db->query("SELECT value FROM meta WHERE key = 'probe'")->fetchColumn())->toBe('kept');
});

test('run_migrations applies only the migrations a database is missing', function () {
    $db = new PDO('sqlite:' . temp_dir() . '/vaka.sqlite');
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    run_migrations($db, [1 => ['CREATE TABLE a (x)']]);
    $db->exec('INSERT INTO a (x) VALUES (1)');
    run_migrations($db, [1 => ['CREATE TABLE a (x)'], 2 => ['CREATE TABLE b (x)']]);

    expect((int) $db->query('PRAGMA user_version')->fetchColumn())->toBe(2)
        ->and((int) $db->query('SELECT COUNT(*) FROM a')->fetchColumn())->toBe(1)
        ->and($db->query("SELECT name FROM sqlite_master WHERE name = 'b'")->fetchColumn())->toBe('b');
});

test('a failing migration rolls back and keeps the previous version', function () {
    $db = new PDO('sqlite:' . temp_dir() . '/vaka.sqlite');
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    run_migrations($db, [1 => ['CREATE TABLE a (x)']]);

    expect(fn () => run_migrations($db, [
        1 => ['CREATE TABLE a (x)'],
        2 => ['CREATE TABLE c (x)', 'THIS IS NOT SQL'],
    ]))->toThrow(PDOException::class);

    expect((int) $db->query('PRAGMA user_version')->fetchColumn())->toBe(1)
        ->and($db->query("SELECT name FROM sqlite_master WHERE name = 'c'")->fetchColumn())->toBeFalse();
});

test('processes opening a new database at the same time all end up on the latest schema', function () {
    $path = temp_dir() . '/vaka.sqlite';
    $code = 'require $argv[1]; $db = get_db($argv[2]); echo $db->query("PRAGMA user_version")->fetchColumn();';
    $procs = [];
    for ($i = 0; $i < 6; $i++) {
        $procs[] = proc_open([PHP_BINARY, '-r', $code, __DIR__ . '/../../src/bootstrap.php', $path], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $outputs[] = $pipes;
    }
    $latest = (string) max(array_keys(migrations()));
    foreach ($procs as $i => $proc) {
        $out = stream_get_contents($outputs[$i][1]);
        $err = stream_get_contents($outputs[$i][2]);
        expect(proc_close($proc))->toBe(0, $err)
            ->and($out)->toBe($latest);
    }
});

test('a site paused before pauses were kept counts as paused from its last check', function () {
    // Before: a database at version 11, with one site paused and one watched, both checked
    $db = new PDO('sqlite:' . temp_dir() . '/vaka.sqlite');
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $db->exec('PRAGMA foreign_keys = ON');
    run_migrations($db, array_filter(migrations(), fn (int $version) => $version <= 11, ARRAY_FILTER_USE_KEY));
    $paused = add_site($db, ['url' => 'https://shop.example', 'paused' => true]);
    $watched = add_site($db, ['url' => 'https://blog.example']);
    $never = add_site($db, ['url' => 'https://new.example', 'paused' => true]);
    $state = ['status' => 'fail', 'summary' => '', 'details' => [], 'fail_count' => 2, 'changed_at' => '2026-10-26T09:57:00Z'];
    save_check_state($db, $paused, 'uptime', $state, '2026-10-26T10:30:00Z', '2026-10-26T10:31:00Z');
    save_check_state($db, $watched, 'uptime', $state, '2026-11-19T08:00:00Z', '2026-11-19T08:01:00Z');

    run_migrations($db);
    expect($db->query('SELECT site_id, started_at, ended_at FROM pauses')->fetchAll())
        ->toBe([['site_id' => $paused, 'started_at' => '2026-10-26T10:30:00Z', 'ended_at' => null]]);
});

test('a revoked token\'s number is never handed out again, and an older database keeps its tokens and their groups', function () {
    // Before: a database at version 10, with a token for two groups and one for a site
    $db = new PDO('sqlite:' . temp_dir() . '/vaka.sqlite');
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->exec('PRAGMA foreign_keys = ON');
    run_migrations($db, array_filter(migrations(), fn (int $version) => $version <= 10, ARRAY_FILTER_USE_KEY));
    $groups = [add_group($db, ['name' => 'Customers']), add_group($db, ['name' => 'Own sites'])];
    $site = add_site($db, ['url' => 'https://shop.example']);
    $both = create_share_token($db, null, $groups, 'Both', null, time());
    $one = create_share_token($db, $site, [], 'Shop', null, time());

    run_migrations($db);
    $kept = array_column(list_share_tokens($db), null, 'id');
    expect(array_keys($kept))->toEqualCanonicalizing([$both['id'], $one['id']])
        ->and(array_column($kept[$both['id']]['groups'], 'name'))->toEqualCanonicalizing(['Customers', 'Own sites'])
        ->and(find_share_token($db, $one['token'], time())['label'])->toBe('Shop');

    // The newest revoked: the next token gets a number of its own, so a script's `share:revoke 2 --force` never takes it
    revoke_share_token($db, $one['id']);
    expect(create_share_token($db, null, [$groups[0]], '', null, time())['id'])->toBeGreaterThan($one['id']);
});
