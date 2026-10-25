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
