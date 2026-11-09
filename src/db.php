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
        2 => [
            "CREATE TABLE groups (
                id INTEGER PRIMARY KEY,
                name TEXT NOT NULL UNIQUE COLLATE NOCASE,
                policy TEXT NOT NULL DEFAULT 'immediate',
                delay_minutes INTEGER NOT NULL DEFAULT 0,
                slack_webhook TEXT NOT NULL DEFAULT '',
                webhook_url TEXT NOT NULL DEFAULT '',
                email TEXT NOT NULL DEFAULT '',
                created_at TEXT NOT NULL
            )",
            "CREATE TABLE sites (
                id INTEGER PRIMARY KEY,
                group_id INTEGER REFERENCES groups (id) ON DELETE SET NULL,
                url TEXT NOT NULL UNIQUE,
                name TEXT NOT NULL DEFAULT '',
                keyword TEXT NOT NULL DEFAULT '',
                interval_minutes INTEGER,
                paused INTEGER NOT NULL DEFAULT 0,
                disabled_checks TEXT NOT NULL DEFAULT '',
                ip TEXT NOT NULL DEFAULT '',
                cdn TEXT NOT NULL DEFAULT '',
                server TEXT NOT NULL DEFAULT '',
                created_at TEXT NOT NULL
            )",
            'CREATE INDEX idx_sites_group ON sites (group_id)',
        ],
        3 => [
            "CREATE TABLE check_state (
                site_id INTEGER NOT NULL REFERENCES sites (id) ON DELETE CASCADE,
                check_name TEXT NOT NULL,
                status TEXT NOT NULL DEFAULT 'pending',
                summary TEXT NOT NULL DEFAULT '',
                details TEXT NOT NULL DEFAULT '{}',
                fail_count INTEGER NOT NULL DEFAULT 0,
                checked_at TEXT,
                next_at TEXT,
                changed_at TEXT,
                alerted_status TEXT NOT NULL DEFAULT 'pending',
                alerted_at TEXT,
                snoozed_until TEXT,
                thresholds_sent TEXT NOT NULL DEFAULT '[]',
                PRIMARY KEY (site_id, check_name)
            )",
            "CREATE TABLE events (
                id INTEGER PRIMARY KEY,
                site_id INTEGER NOT NULL REFERENCES sites (id) ON DELETE CASCADE,
                check_name TEXT NOT NULL,
                status TEXT NOT NULL,
                previous TEXT NOT NULL,
                message TEXT NOT NULL,
                silenced_by TEXT NOT NULL DEFAULT '',
                created_at TEXT NOT NULL
            )",
            'CREATE INDEX idx_events_site ON events (site_id, created_at)',
            "CREATE TABLE samples (
                site_id INTEGER NOT NULL REFERENCES sites (id) ON DELETE CASCADE,
                checked_at TEXT NOT NULL,
                status_code INTEGER NOT NULL,
                ms INTEGER NOT NULL,
                ok INTEGER NOT NULL,
                ip TEXT NOT NULL DEFAULT ''
            )",
            'CREATE INDEX idx_samples_site ON samples (site_id, checked_at)',
            "CREATE TABLE servers (
                ip TEXT PRIMARY KEY,
                label TEXT NOT NULL DEFAULT ''
            )",
        ],
        4 => [
            'ALTER TABLE check_state ADD COLUMN flapping_since TEXT',
        ],
        5 => [
            "CREATE TABLE maintenance (
                id INTEGER PRIMARY KEY,
                site_id INTEGER REFERENCES sites (id) ON DELETE CASCADE,
                group_id INTEGER REFERENCES groups (id) ON DELETE CASCADE,
                label TEXT NOT NULL DEFAULT '',
                days TEXT NOT NULL DEFAULT '',
                start_time TEXT,
                end_time TEXT,
                starts_at TEXT,
                ends_at TEXT,
                created_at TEXT NOT NULL
            )",
        ],
        6 => [
            'CREATE TABLE audit_log (
                id INTEGER PRIMARY KEY,
                username TEXT NOT NULL,
                action TEXT NOT NULL,
                ip TEXT NOT NULL,
                created_at TEXT NOT NULL
            )',
            'CREATE INDEX idx_audit_ip ON audit_log (ip, action, created_at)',
        ],
        7 => [
            // What a change check (exposed files, later headers and DNS) last told, to compare against
            "ALTER TABLE check_state ADD COLUMN alerted_snapshot TEXT NOT NULL DEFAULT '[]'",
        ],
        8 => [
            // Read tokens for the API: only a hash of the token is kept, and its start to tell them apart
            "CREATE TABLE share_tokens (
                id INTEGER PRIMARY KEY,
                token_hash TEXT NOT NULL UNIQUE,
                prefix TEXT NOT NULL,
                site_id INTEGER REFERENCES sites (id) ON DELETE CASCADE,
                group_id INTEGER REFERENCES groups (id) ON DELETE CASCADE,
                label TEXT NOT NULL DEFAULT '',
                expires_at TEXT,
                last_used_at TEXT,
                created_at TEXT NOT NULL
            )",
        ],
        9 => [
            // A read token for one or more groups: they move to a table of their own. The table
            // is rebuilt without group_id, since DROP COLUMN needs SQLite 3.35 and older hosts have less
            'CREATE TABLE share_tokens_new (
                id INTEGER PRIMARY KEY,
                token_hash TEXT NOT NULL UNIQUE,
                prefix TEXT NOT NULL,
                site_id INTEGER REFERENCES sites (id) ON DELETE CASCADE,
                label TEXT NOT NULL DEFAULT \'\',
                expires_at TEXT,
                last_used_at TEXT,
                created_at TEXT NOT NULL
            )',
            'INSERT INTO share_tokens_new (id, token_hash, prefix, site_id, label, expires_at, last_used_at, created_at)
                SELECT id, token_hash, prefix, site_id, label, expires_at, last_used_at, created_at FROM share_tokens',
            'CREATE TABLE share_groups_old AS SELECT id AS token_id, group_id FROM share_tokens WHERE group_id IS NOT NULL',
            'DROP TABLE share_tokens',
            'ALTER TABLE share_tokens_new RENAME TO share_tokens',
            'CREATE TABLE share_groups (
                token_id INTEGER NOT NULL REFERENCES share_tokens (id) ON DELETE CASCADE,
                group_id INTEGER NOT NULL REFERENCES groups (id) ON DELETE CASCADE,
                PRIMARY KEY (token_id, group_id)
            )',
            'INSERT INTO share_groups (token_id, group_id) SELECT token_id, group_id FROM share_groups_old',
            'DROP TABLE share_groups_old',
        ],
    ];
}

/** A time in UTC (now, or a Unix timestamp), as stored in the database. */
function utc_now(?int $time = null): string
{
    return gmdate('Y-m-d\TH:i:s\Z', $time ?? time());
}

function get_meta(PDO $db, string $key): ?string
{
    $stmt = $db->prepare('SELECT value FROM meta WHERE key = ?');
    $stmt->execute([$key]);
    $value = $stmt->fetchColumn();
    return $value === false ? null : $value;
}

function set_meta(PDO $db, string $key, string $value): void
{
    $db->prepare('INSERT INTO meta (key, value) VALUES (?, ?) ON CONFLICT (key) DO UPDATE SET value = excluded.value')
        ->execute([$key, $value]);
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
