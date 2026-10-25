<?php

/**
 * Open the database (created on first use) in WAL mode and bring its schema
 * up to date.
 */
function get_db(string $path): PDO
{
    $dir = dirname($path);
    if (!is_dir($dir)) mkdir($dir, 0750, true);

    $db = new PDO('sqlite:' . $path);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    // Wait up to 10 s for another process's write lock instead of failing at once
    $db->setAttribute(PDO::ATTR_TIMEOUT, 10);
    enable_wal($db);
    $db->exec('PRAGMA foreign_keys = ON');

    run_migrations($db);

    return $db;
}

/**
 * WAL is stored in the database file, so this changes something only once.
 * Switching needs an exclusive lock and SQLite does not wait for it (the busy
 * timeout does not apply), so when several processes open a new database at
 * the same time, the ones that lose retry for a moment.
 */
function enable_wal(PDO $db): void
{
    for ($try = 1; ; $try++) {
        try {
            if ($db->query('PRAGMA journal_mode')->fetchColumn() !== 'wal') {
                $db->exec('PRAGMA journal_mode = WAL');
            }
            return;
        } catch (PDOException $e) {
            if ($try >= 50 || !str_contains($e->getMessage(), 'locked')) throw $e;
            usleep(100_000);
        }
    }
}

/**
 * Schema changes by version. A version is never edited once released: add a
 * new one instead. A fresh database runs them all, in order.
 *
 * @return array<int, list<string>>
 */
function migrations(): array
{
    return [
        1 => [
            'CREATE TABLE meta (
                key TEXT PRIMARY KEY,
                value TEXT NOT NULL
            )',
        ],
    ];
}

/**
 * Apply the migrations a database is missing, tracked in PRAGMA user_version.
 * One process migrates while the others wait for its write lock and then find
 * the schema current. The lock is taken up front (BEGIN IMMEDIATE): a
 * transaction that reads first and writes later fails at once with "database
 * is locked" when another process holds the lock, without waiting.
 */
function run_migrations(PDO $db, ?array $migrations = null): void
{
    $migrations ??= migrations();
    ksort($migrations);
    $latest = max(array_keys($migrations));

    if ((int) $db->query('PRAGMA user_version')->fetchColumn() >= $latest) return;

    $db->exec('BEGIN IMMEDIATE');
    try {
        $version = (int) $db->query('PRAGMA user_version')->fetchColumn();
        foreach ($migrations as $target => $statements) {
            if ($target <= $version) continue;
            foreach ($statements as $sql) $db->exec($sql);
            $db->exec('PRAGMA user_version = ' . (int) $target);
        }
        $db->exec('COMMIT');
    } catch (Throwable $e) {
        $db->exec('ROLLBACK');
        throw $e;
    }
}
