<?php

beforeEach(function () {
    $this->env = cli_env();
    // Something to keep: a group with its webhook, and a site in it
    vaka(['group:add', 'Customers', '--slack=https://hooks.example/services/T0/B0/x'], $this->env);
    vaka(['site:add', 'shop.example', '--group=Customers'], $this->env);
    $this->dir = temp_dir() . '/backups';
    mkdir($this->dir, 0700);
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

test('a backup leaves out the login log, which holds who tried to log in and from where', function () {
    audit_log(get_db($this->env['DB_PATH']), 'admin', 'login_failed', '198.51.100.23', time());
    vaka(['backup', $this->dir], $this->env);
    $path = glob("{$this->dir}/vaka-*")[0];

    expect((int) unpack_backup($path)->query('SELECT COUNT(*) FROM audit_log')->fetchColumn())->toBe(0)
        // Not even in the free pages of the file
        ->and(file_get_contents("compress.zlib://{$path}"))->not->toContain('198.51.100.23');
});

test('the first backup says how to make one every night, as whom, and that the copies are on this server', function () {
    $first = vaka(['backup', $this->dir, '--keep=14'], $this->env)['out'];
    expect($first)->toContain("This is the first backup here. For one every night, add this line to cron (crontab -e) as the user that runs `vaka run`:\n")
        // The folder and --keep go along, or the nightly job keeps 7 somewhere else
        ->and($first)->toMatch('#\n30 3 \* \* \* .+/vaka backup ' . preg_quote($this->dir, '#') . ' --keep=14 > /dev/null\n#')
        ->and($first)->toContain('The backups are on this server, so they do not help if it is lost: copy them off it, and be told if the job stops (https://github.com/webready-se/vaka/blob/main/docs/install.md#backups).');
});

test('a later backup says how many there are, and says nothing more of cron', function () {
    vaka(['backup', $this->dir], $this->env);
    sleep(1);
    $r = vaka(['backup', $this->dir], $this->env);

    expect($r['out'])->toContain("2 backups in {$this->dir}, the newest 7 kept.\n")
        ->and($r['out'])->not->toContain('first backup')->not->toContain('cron');
});

test('backup writes to backups/ next to the database by default', function () {
    $r = vaka(['backup'], $this->env);

    expect($r['exit'])->toBe(0, $r['err'])
        ->and(glob(dirname($this->env['DB_PATH']) . '/backups/vaka-*.sqlite.gz'))->toHaveCount(1)
        // Its own folder needs no folder in the cron line
        ->and($r['out'])->toMatch('#/vaka backup > /dev/null\n#');
});

test('a folder given must exist, so a typo or a word such as help makes no backup somewhere unexpected', function () {
    $r = vaka(['backup', 'help'], $this->env);
    $where = dirname(__DIR__, 2) . '/help';

    expect($r['exit'])->toBe(1)
        ->and($r['err'])->toBe("There is no folder {$where}. Make it first, or leave the folder out for backups next to the database.\n")
        ->and(file_exists($where))->toBeFalse()
        ->and(vaka(['backup', $this->dir, 'other'], $this->env)['err'])->toBe("backup takes one folder at most. Run `php vaka help` to see how.\n");
});

test('backup leaves nothing half written behind for a job that copies the folder', function () {
    vaka(['backup', $this->dir], $this->env);

    expect(array_values(array_diff(scandir($this->dir), ['.', '..', '.lock'])))->toHaveCount(1);
});

test('two backups at the same moment never report a backup that is not there', function () {
    for ($try = 0; $try < 4; $try++) {
        array_map('unlink', glob("{$this->dir}/vaka-*"));
        // Start both at the beginning of a second, as two cron jobs at 03:30 would
        $second = time();
        while (time() === $second) usleep(1000);
        $runs = [];
        foreach ([0, 1] as $i) {
            $env = $this->env + ['VAKA_ENV_FILE' => '/dev/null', 'TIMEZONE' => 'Europe/Stockholm', 'PATH' => getenv('PATH')];
            $runs[] = proc_open([PHP_BINARY, dirname(__DIR__, 2) . '/vaka', 'backup', $this->dir], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes[$i], null, $env);
        }
        foreach ($runs as $i => $run) {
            $out = stream_get_contents($pipes[$i][1]);
            $err = stream_get_contents($pipes[$i][2]);
            $exit = proc_close($run);
            expect($err)->not->toContain('Warning');
            if ($exit === 0) {
                preg_match('/^Backed up to (\S+) /', $out, $m);
                expect(filesize($m[1]))->toBeGreaterThan(0);
            }
        }
        expect(glob("{$this->dir}/vaka-*"))->not->toBe([]);
    }
});

test('backup keeps the newest backups, compressed or not, and leaves other files alone', function () {
    touch("{$this->dir}/vaka-2026-10-01-030000.sqlite");
    touch("{$this->dir}/vaka-2026-10-02-030000.sqlite");
    touch("{$this->dir}/vaka-2026-10-03-030000.sqlite.gz");
    touch("{$this->dir}/notes.txt");

    $r = vaka(['backup', $this->dir, '--keep=2'], $this->env);
    $left = array_map('basename', glob("{$this->dir}/*"));

    expect($r['exit'])->toBe(0, $r['err'])
        ->and($r['out'])->toContain("2 backups in {$this->dir}, the newest 2 kept: removed 2 older ones.\n")
        // Older ones were there, so this is no first backup
        ->and($r['out'])->not->toContain('first backup')
        ->and($left)->toContain('vaka-2026-10-03-030000.sqlite.gz', 'notes.txt')
        ->and($left)->not->toContain('vaka-2026-10-01-030000.sqlite', 'vaka-2026-10-02-030000.sqlite')
        ->and(count(glob("{$this->dir}/vaka-*")))->toBe(2);
});

test('backup removes what a backup that was stopped left behind, once it is a day old', function () {
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

test('backup says why it cannot write a backup, and what to do', function () {
    rmdir($this->dir);
    touch($this->dir);
    $r = vaka(['backup', $this->dir], $this->env);
    expect($r['exit'])->toBe(1)
        ->and($r['err'])->toBe("{$this->dir} is a file, not a folder. Give a folder, or leave it out for backups next to the database.\n");

    unlink($this->dir);
    mkdir($this->dir, 0500);
    $r = vaka(['backup', $this->dir], $this->env);
    chmod($this->dir, 0700);
    expect($r['exit'])->toBe(1)
        ->and($r['err'])->toBe("Cannot write in {$this->dir}: run backup as the user that owns it, or give a folder that user can write in.\n");
});

test('backup says there is nothing to back up without a database, and makes none', function () {
    $env = cli_env();
    $r = vaka(['backup'], $env);

    expect($r['exit'])->toBe(1)
        ->and($r['err'])->toBe("No database at {$env['DB_PATH']} yet, so there is nothing to back up. It is made by the first command that adds something, such as `php vaka site:add`. If Vaka already watches sites, check DB_PATH in .env.\n")
        ->and(file_exists($env['DB_PATH']))->toBeFalse();
});

test('backup --keep is a number of backups', function () {
    foreach (['--keep=0', '--keep=week', '--keep'] as $keep) {
        $r = vaka(['backup', $this->dir, $keep], $this->env);
        expect($r['exit'])->toBe(1)
            ->and($r['err'])->toBe("--keep is how many backups to keep, 1 or more, such as --keep=14.\n");
    }
});

test('help says backup runs from cron, not that it already does', function () {
    expect(vaka(['help'])['out'])->toContain('backup [<folder>]    A checked, compressed copy of the database. Make one every');
});
