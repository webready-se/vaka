<?php

beforeEach(function () {
    $this->env = cli_env();
    // Something to keep: a group with its webhook, and a site in it
    vaka(['group:add', 'Customers', '--slack=https://hooks.example/services/T0/B0/x'], $this->env);
    vaka(['site:add', 'shop.example', '--group=Customers'], $this->env);
    $this->dir = temp_dir() . '/backups';
});

/** A compressed backup unpacked next to it, to open. */
function unpack_backup(string $path): PDO
{
    $copy = temp_dir() . '/unpacked.sqlite';
    copy("compress.zlib://{$path}", $copy);
    return new PDO('sqlite:' . $copy);
}

test('backup writes a checked, compressed copy of the database that only its owner can read', function () {
    $r = vaka(['backup', $this->dir], $this->env);
    $files = glob("{$this->dir}/vaka-*");
    $copy = unpack_backup($files[0]);

    expect($r['exit'])->toBe(0, $r['err'])
        ->and($files)->toHaveCount(1)
        ->and(basename($files[0]))->toMatch('/^vaka-\d{4}-\d{2}-\d{2}-\d{6}\.sqlite\.gz$/')
        ->and($r['out'])->toStartWith("Backed up to {$files[0]} (")
        ->and($r['out'])->toMatch('/compressed from [\d.]+ (KB|MB), checked\)\./')
        ->and($copy->query('SELECT url FROM sites')->fetchColumn())->toBe('https://shop.example')
        ->and($copy->query('SELECT slack_webhook FROM groups')->fetchColumn())->toBe('https://hooks.example/services/T0/B0/x')
        ->and(fileperms($files[0]) & 0777)->toBe(0600);
});

test('the first backup says how to make one every night and that the copies are on this server; the next ones do not', function () {
    $first = vaka(['backup', $this->dir], $this->env)['out'];
    expect($first)->toContain("This is the first backup here. For one every night, add this line to cron:\n")
        // The folder as a full path, since cron starts in the home folder
        ->and($first)->toMatch('#\n30 3 \* \* \* .+/vaka backup ' . preg_quote(realpath($this->dir), '#') . ' > /dev/null\n#')
        ->and($first)->toContain('The backups are on this server, so they do not help if it is lost: copy them off it (https://github.com/webready-se/vaka/blob/main/docs/install.md#backups).');

    sleep(1);
    expect(vaka(['backup', $this->dir], $this->env)['out'])->not->toContain('first backup')->not->toContain('cron');
});

test('backup writes to backups/ next to the database by default', function () {
    $r = vaka(['backup'], $this->env);

    expect($r['exit'])->toBe(0, $r['err'])
        ->and(glob(dirname($this->env['DB_PATH']) . '/backups/vaka-*.sqlite.gz'))->toHaveCount(1)
        // Its own folder needs no folder in the cron line
        ->and($r['out'])->toMatch('#/vaka backup > /dev/null\n#');
});

test('backup leaves nothing half written behind for a job that copies the folder', function () {
    vaka(['backup', $this->dir], $this->env);

    expect(array_values(array_diff(scandir($this->dir), ['.', '..'])))->toHaveCount(1);
});

test('backup keeps the newest backups, compressed or not, and leaves other files alone', function () {
    mkdir($this->dir, 0700, true);
    touch("{$this->dir}/vaka-2026-10-01-030000.sqlite");
    touch("{$this->dir}/vaka-2026-10-02-030000.sqlite");
    touch("{$this->dir}/vaka-2026-10-03-030000.sqlite.gz");
    touch("{$this->dir}/notes.txt");

    $r = vaka(['backup', $this->dir, '--keep=2'], $this->env);
    $left = array_map('basename', glob("{$this->dir}/*"));

    expect($r['exit'])->toBe(0, $r['err'])
        ->and($r['out'])->toContain('Removed 2 older backups, kept the newest 2.')
        ->and($left)->toContain('vaka-2026-10-03-030000.sqlite.gz', 'notes.txt')
        ->and($left)->not->toContain('vaka-2026-10-01-030000.sqlite', 'vaka-2026-10-02-030000.sqlite')
        ->and(count(glob("{$this->dir}/vaka-*")))->toBe(2);
});

test('backup removes what a backup that was stopped left behind, once it is a day old', function () {
    mkdir($this->dir, 0700, true);
    touch("{$this->dir}/.vaka-2026-10-01-030000.sqlite", time() - 90000);
    touch("{$this->dir}/.vaka-2026-10-01-030000.sqlite.gz", time() - 90000);
    touch("{$this->dir}/.vaka-2026-10-02-030000.sqlite");

    vaka(['backup', $this->dir], $this->env);

    expect(file_exists("{$this->dir}/.vaka-2026-10-01-030000.sqlite"))->toBeFalse()
        ->and(file_exists("{$this->dir}/.vaka-2026-10-01-030000.sqlite.gz"))->toBeFalse()
        ->and(file_exists("{$this->dir}/.vaka-2026-10-02-030000.sqlite"))->toBeTrue();
});

test('backup writes the copy as it is where PHP has no zlib', function () {
    $backup = backup_database(get_db($this->env['DB_PATH']), $this->dir, 7, compress: false);
    $copy = new PDO('sqlite:' . $backup['path']);

    expect(basename($backup['path']))->toMatch('/^vaka-\d{4}-\d{2}-\d{2}-\d{6}\.sqlite$/')
        ->and($backup['unpacked'])->toBeNull()
        ->and($backup['kept'])->toBe(1)
        ->and($copy->query('SELECT url FROM sites')->fetchColumn())->toBe('https://shop.example')
        ->and(fileperms($backup['path']) & 0777)->toBe(0600)
        ->and(glob("{$this->dir}/.vaka-*"))->toBe([]);
});

test('backup says so when it cannot write the backup, or there is no database yet', function () {
    touch($this->dir);
    $r = vaka(['backup', $this->dir], $this->env);
    expect($r['exit'])->toBe(1)
        ->and($r['err'])->toContain("Cannot write backups to {$this->dir}.");

    // No database: nothing to back up, and none is made
    $env = cli_env();
    $r = vaka(['backup'], $env);
    expect($r['exit'])->toBe(1)
        ->and($r['err'])->toBe("No database at {$env['DB_PATH']} yet, so there is nothing to back up. It is made by the first command that adds something, such as `php vaka site:add`.\n")
        ->and(file_exists($env['DB_PATH']))->toBeFalse();
});

test('backup --keep is a number of backups', function () {
    foreach (['--keep=0', '--keep=week', '--keep'] as $keep) {
        $r = vaka(['backup', $this->dir, $keep], $this->env);
        expect($r['exit'])->toBe(1)
            ->and($r['err'])->toBe("--keep is how many backups to keep, 1 or more, such as --keep=14.\n");
    }
});

test('help lists backup', function () {
    expect(vaka(['help'])['out'])->toContain('backup [<folder>]');
});
