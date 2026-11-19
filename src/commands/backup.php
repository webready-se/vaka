<?php

/** Where docs/install.md says how to copy the backups off the server; the release zip has no docs. */
const BACKUP_DOCS_URL = 'https://github.com/webready-se/vaka/blob/main/docs/install.md#backups';

/**
 * php vaka backup [<folder>] [--keep=N]: a checked, compressed copy of the
 * database (backup_database()), in backups/ next to it by default. For a
 * nightly cron job; the first one says how to add that job, and that the
 * copies are on the same server until something copies them off it.
 */
function cmd_backup(array $config, array $args, array $opts): void
{
    allow_options($opts, ['keep']);
    $keep = $opts['keep'] ?? (string) BACKUP_KEEP;
    if (!is_string($keep) || !ctype_digit($keep) || (int) $keep < 1) {
        fail('--keep is how many backups to keep, 1 or more, such as --keep=14.');
    }
    // get_db() would make an empty database to copy
    if (!file_exists($config['db_path'])) {
        fail("No database at {$config['db_path']} yet, so there is nothing to back up. It is made by the first command that adds something, such as `php vaka site:add`.");
    }
    $dir = $args[1] ?? dirname($config['db_path']) . '/backups';

    try {
        $backup = backup_database(get_db($config['db_path']), $dir, (int) $keep);
    } catch (Throwable $e) {
        fail($e->getMessage());
    }
    $size = bytes_text($backup['bytes']) . ($backup['unpacked'] !== null ? ' compressed from ' . bytes_text($backup['unpacked']) : '');
    echo "Backed up to {$backup['path']} ({$size}, checked).\n";
    if ($backup['removed'] > 0) echo 'Removed ' . plural($backup['removed'], 'older backup', 'older backups') . ", kept the newest {$keep}.\n";

    if ($backup['kept'] === 1) {
        // A folder given here goes in the line as a full path: cron starts in the home folder
        $line = backup_cron_line(dirname(__DIR__, 2), php_path(), isset($args[1]) ? realpath(dirname($backup['path'])) : null);
        echo "This is the first backup here. For one every night, add this line to cron:\n{$line}\n";
        echo 'The backups are on this server, so they do not help if it is lost: copy them off it (' . BACKUP_DOCS_URL . ").\n";
    }
}
