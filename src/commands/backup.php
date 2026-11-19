<?php

/** Where docs/install.md says how to copy the backups off the server; the release zip has no docs. */
const BACKUP_DOCS_URL = 'https://github.com/webready-se/vaka/blob/main/docs/install.md#backups';

/**
 * php vaka backup [<folder>] [--keep=N]: a checked, compressed copy of the
 * database (backup_database()), in backups/ next to it by default. For a
 * nightly cron job; the first one says how to add that job, and that the
 * copies are on the same server until something copies them off it.
 *
 * A folder given is read from Vaka's folder when relative, as DB_PATH is (on
 * a zero-downtime deploy the site's, never a release that goes away), and must
 * exist: a typo, or `backup help`, would otherwise make a folder of its own.
 */
function cmd_backup(array $config, array $args, array $opts): void
{
    allow_options($opts, ['keep']);
    $keep = $opts['keep'] ?? (string) BACKUP_KEEP;
    if (!is_string($keep) || !ctype_digit($keep) || (int) $keep < 1) {
        fail('--keep is how many backups to keep, 1 or more, such as --keep=14.');
    }
    if (count($args) > 2) fail('backup takes one folder at most. Run `php vaka help` to see how.');
    // get_db() would make an empty database to copy
    if (!file_exists($config['db_path'])) {
        fail("No database at {$config['db_path']} yet, so there is nothing to back up. It is made by the first command that adds something, such as `php vaka site:add`. If Vaka already watches sites, check DB_PATH in .env.");
    }
    $given = isset($args[1]) ? rtrim(resolve_path($args[1], dirname(__DIR__, 2)), '/') : null;
    $dir = $given ?? dirname($config['db_path']) . '/backups';
    if ($given !== null && !file_exists($given)) fail("There is no folder {$given}. Make it first, or leave the folder out for backups next to the database.");
    if (file_exists($dir) && !is_dir($dir)) fail("{$dir} is a file, not a folder. Give a folder, or leave it out for backups next to the database.");
    if (is_dir($dir) && !is_writable($dir)) fail("Cannot write in {$dir}: run backup as the user that owns it, or give a folder that user can write in.");

    try {
        $backup = backup_database(get_db($config['db_path']), $dir, (int) $keep);
    } catch (Throwable $e) {
        fail($e->getMessage());
    }
    $size = bytes_text($backup['bytes']) . ($backup['unpacked'] !== null ? ' compressed from ' . bytes_text($backup['unpacked']) : '');
    echo "Backed up to {$backup['path']} ({$size}, checked).\n";

    // The first in this folder: none was there before, also none that --keep has just removed
    if ($backup['kept'] + $backup['removed'] === 1) {
        $line = backup_cron_line(dirname(__DIR__, 2), php_path(), $given, isset($opts['keep']) ? (int) $keep : null);
        echo "This is the first backup here. For one every night, add this line to cron (crontab -e) as the user that runs `vaka run`:\n{$line}\n";
        echo 'The backups are on this server, so they do not help if it is lost: copy them off it, and be told if the job stops (' . BACKUP_DOCS_URL . ").\n";
        return;
    }
    $removed = $backup['removed'] > 0 ? ': removed ' . plural($backup['removed'], 'older one', 'older ones') : '';
    echo plural($backup['kept'], 'backup', 'backups') . " in {$dir}, the newest {$keep} kept{$removed}.\n";
}
