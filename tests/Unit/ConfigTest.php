<?php

test('parse_env reads KEY=value lines', function () {
    $env = parse_env("APP_URL=https://vaka.example.com\nTIMEZONE=Europe/Stockholm\n");
    expect($env)->toBe(['APP_URL' => 'https://vaka.example.com', 'TIMEZONE' => 'Europe/Stockholm']);
});

test('parse_env skips comments, blank lines and lines without =', function () {
    $env = parse_env("# a comment\n\n   \nNOT_A_SETTING\n  # indented comment\nKEY=1\n");
    expect($env)->toBe(['KEY' => '1']);
});

test('parse_env trims whitespace and strips surrounding double quotes', function () {
    $env = parse_env("  NAME = \"Vaka monitor\"  \nEMPTY=\nURL=https://example.com/?a=b\n");
    expect($env)->toBe(['NAME' => 'Vaka monitor', 'EMPTY' => '', 'URL' => 'https://example.com/?a=b']);
});

test('parse_env keeps a lone quote and quotes inside a value', function () {
    $env = parse_env("A=\"\nB=say \"hi\"\n");
    expect($env)->toBe(['A' => '"', 'B' => 'say "hi"']);
});

test('load_env does not override values already in the environment', function () {
    $file = temp_dir() . '/.env';
    file_put_contents($file, "VAKA_TEST_SET=from-file\nVAKA_TEST_NEW=from-file\n");
    $_ENV['VAKA_TEST_SET'] = 'from-os';
    unset($_ENV['VAKA_TEST_NEW']);

    load_env($file);

    expect($_ENV['VAKA_TEST_SET'])->toBe('from-os')
        ->and($_ENV['VAKA_TEST_NEW'])->toBe('from-file');
    unset($_ENV['VAKA_TEST_SET'], $_ENV['VAKA_TEST_NEW']);
});

test('load_env ignores a missing file', function () {
    load_env('/nonexistent/.env');
    expect(true)->toBeTrue();
});

test('resolve_path keeps absolute paths', function () {
    expect(resolve_path('/var/lib/vaka/vaka.sqlite', '/srv/vaka'))->toBe('/var/lib/vaka/vaka.sqlite');
});

test('resolve_path resolves relative paths against the project root', function () {
    expect(resolve_path('data/vaka.sqlite', '/srv/vaka'))->toBe('/srv/vaka/data/vaka.sqlite');
});

test('the .env file is read from the site root on a Forge zero-downtime deploy, where Forge keeps it', function () {
    expect(env_file('/srv/vaka'))->toBe('/srv/vaka/.env')
        ->and(env_file('/home/forge/vaka.example.com/releases/20261026120000'))->toBe('/home/forge/vaka.example.com/.env')
        ->and(env_file('/home/forge/vaka.example.com'))->toBe('/home/forge/vaka.example.com/.env');
});

test('resolve_path resolves a Forge zero-downtime release to the site root', function () {
    $root = '/home/forge/vaka.example.com/releases/20261025120000';
    expect(resolve_path('data/vaka.sqlite', $root))->toBe('/home/forge/vaka.example.com/data/vaka.sqlite');
});

test('int_list parses a comma-separated list into unique integers, largest first', function () {
    expect(int_list('1, 14,7,,7, x'))->toBe([14, 7, 1])
        ->and(int_list(''))->toBe([]);
});

test('an isolated Forge site, under its own user, resolves its releases to its site root too', function () {
    // Forge isolates a site under /home/<its user>/, not /home/forge/: the database and .env must still outlive a release
    $root = '/home/shopdemo/demo.example.com/releases/20261117010101';
    expect(resolve_path('data/vaka.sqlite', $root))->toBe('/home/shopdemo/demo.example.com/data/vaka.sqlite')
        ->and(env_file($root))->toBe('/home/shopdemo/demo.example.com/.env')
        ->and(run_dir($root))->toBe('/home/shopdemo/demo.example.com/current')
        ->and(cron_line($root))->toBe('* * * * * php /home/shopdemo/demo.example.com/current/vaka run')
        // Only a release under a site in a home: elsewhere a releases/ folder is the project's own
        ->and(resolve_path('data/vaka.sqlite', '/srv/vaka/releases/20261117010101'))->toBe('/srv/vaka/releases/20261117010101/data/vaka.sqlite');
});

test('run_dir is where commands run: current/ on a Forge zero-downtime deploy, which follows every release', function () {
    expect(run_dir('/var/www/vaka'))->toBe('/var/www/vaka')
        ->and(run_dir('/home/forge/vaka.example.com/releases/20261026120000'))->toBe('/home/forge/vaka.example.com/current')
        ->and(run_dir('/home/forge/vaka.example.com'))->toBe('/home/forge/vaka.example.com');
});

/** A site with two releases and current/ linked to the second, as a zero-downtime deploy leaves it. */
function release_tree(): string
{
    $site = realpath(temp_dir());
    mkdir($site . '/releases/1', 0777, true);
    mkdir($site . '/releases/2', 0777, true);
    symlink($site . '/releases/2', $site . '/current');
    return $site;
}

test('an old release is told where the live one is; the live one, a folder that is no release and releases without current/ are not', function () {
    $site = release_tree();
    expect(old_release_warning($site . '/releases/1'))->toBe("Warning: this is an old release, not the live code, but it uses the live database. Run it from {$site}/current.")
        ->and(old_release_warning($site . '/releases/2'))->toBeNull()
        ->and(old_release_warning($site))->toBeNull()
        ->and(old_release_warning('/var/www/vaka'))->toBeNull();
    unlink($site . '/current');
    expect(old_release_warning($site . '/releases/1'))->toBeNull();
});

test('php vaka warns from an old release on STDERR and still runs, so its output stays clean', function () {
    $site = release_tree();
    foreach ([1, 2] as $release) exec('cp -R ' . escapeshellarg(__DIR__ . '/../../vaka') . ' ' . escapeshellarg(__DIR__ . '/../../config.php') . ' ' . escapeshellarg(__DIR__ . '/../../src') . ' ' . escapeshellarg("{$site}/releases/{$release}/"));
    $run = function (string $release) use ($site) {
        $proc = proc_open([PHP_BINARY, "{$site}/releases/{$release}/vaka", 'version'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, ['VAKA_ENV_FILE' => '/dev/null', 'PATH' => getenv('PATH')] + cli_env());
        fclose($pipes[0]);
        $out = stream_get_contents($pipes[1]);
        $err = stream_get_contents($pipes[2]);
        return ['exit' => proc_close($proc), 'out' => $out, 'err' => $err];
    };

    $old = $run('1');
    expect($old['exit'])->toBe(0)
        ->and($old['out'])->toContain('Vaka ' . VAKA_VERSION)
        ->and($old['err'])->toBe("Warning: this is an old release, not the live code, but it uses the live database. Run it from {$site}/current.\n");
    // From current/ the path resolves to the live release, so cron and a shell there hear nothing
    expect($run('2')['err'])->toBe('');
    $live = proc_open([PHP_BINARY, "{$site}/current/vaka", 'version'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, ['VAKA_ENV_FILE' => '/dev/null', 'PATH' => getenv('PATH')] + cli_env());
    expect(stream_get_contents($pipes[2]))->toBe('');
    proc_close($live);
});

test('cron_line runs Vaka from run_dir() with the given PHP', function () {
    expect(cron_line('/var/www/vaka'))->toBe('* * * * * php /var/www/vaka/vaka run')
        ->and(cron_line('/home/forge/vaka.example.com/releases/20261026120000', 'php8.5'))->toBe('* * * * * php8.5 /home/forge/vaka.example.com/current/vaka run');
});

test('php_cli is the CLI of this PHP version when the server has one by that name, else php', function () {
    $dir = temp_dir();
    $versioned = 'php' . PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;
    expect(php_cli([$dir]))->toBe('php');
    touch("{$dir}/{$versioned}");
    chmod("{$dir}/{$versioned}", 0755);
    expect(php_cli([$dir]))->toBe($versioned);
});

test('FOOTER_CREDIT turns the family credit off', function () {
    expect(load_config()['footer_credit'])->toBeTrue()
        ->and(load_config(['FOOTER_CREDIT' => 'false'])['footer_credit'])->toBeFalse()
        ->and(load_config(['FOOTER_CREDIT' => 'nonsense'])['footer_credit'])->toBeTrue();
});

test('pair_list parses "key=value" pairs, keys lowercased', function () {
    expect(pair_list('se=whois.iis.se, NU = whois.iis.nu,,broken, dk='))->toBe(['se' => 'whois.iis.se', 'nu' => 'whois.iis.nu'])
        ->and(pair_list(''))->toBe([]);
    expect(load_config()['whois_servers'])->toBe(['se' => 'whois.iis.se', 'nu' => 'whois.iis.nu'])
        ->and(load_config()['rdap_bootstrap_url'])->toBe('https://data.iana.org/rdap/dns.json');
});

test('config.php reads settings from the environment', function () {
    $dir = temp_dir();
    $config = load_config(['DB_PATH' => $dir . '/custom.sqlite', 'SSL_ALERT_DAYS' => '7,30']);

    expect($config['db_path'])->toBe($dir . '/custom.sqlite')
        ->and($config['ssl_alert_days'])->toBe([30, 7]);
});

test('config.php has defaults for every setting', function () {
    $config = load_config();

    expect($config['db_path'])->toEndWith('/data/vaka.sqlite')
        ->and($config['users_file'])->toEndWith('/users.json')
        ->and($config['uptime_interval'])->toBe(5)
        ->and($config['uptime_timeout'])->toBe(10)
        ->and($config['down_after_failures'])->toBe(2)
        ->and($config['ssl_alert_days'])->toBe([14, 7, 1])
        ->and($config['domain_alert_days'])->toBe([30, 14, 7, 1])
        ->and($config['remind_every_hours'])->toBe(4)
        ->and($config['digest_time'])->toBe('08:00')
        ->and($config['trusted_proxies'])->toBe([])
        ->and($config['time_format'])->toBe('24h');
});

test('TIMEZONE: unset is the server\'s, and a name PHP does not know keeps the server\'s with a hint, so a typo never stops the checks', function () {
    expect(timezone_setting('', 'UTC'))->toBe(['zone' => 'UTC', 'problem' => ''])
        ->and(timezone_setting(' Europe/Stockholm ', 'UTC'))->toBe(['zone' => 'Europe/Stockholm', 'problem' => ''])
        ->and(timezone_setting('Europe/Stokholm', 'UTC'))->toBe(['zone' => 'UTC', 'problem' => 'TIMEZONE "Europe/Stokholm" is not a time zone, so Vaka uses the server\'s, UTC. Did you mean Europe/Stockholm?'])
        ->and(timezone_setting('+02:00', 'America/New_York'))->toBe(['zone' => 'America/New_York', 'problem' => 'TIMEZONE "+02:00" is not a time zone, so Vaka uses the server\'s, America/New_York. Use a name such as Europe/Stockholm.']);
});

test('TIME_FORMAT is 24h unless it says 12h', function () {
    expect(time_format_setting(''))->toBe(['format' => '24h', 'problem' => ''])
        ->and(time_format_setting('12H'))->toBe(['format' => '12h', 'problem' => ''])
        ->and(time_format_setting('24h'))->toBe(['format' => '24h', 'problem' => ''])
        ->and(time_format_setting('12'))->toBe(['format' => '24h', 'problem' => 'TIME_FORMAT "12" is neither 24h nor 12h, so Vaka uses 24h.']);
});

test('TRACK_SHARED_URL is the plain address of a Puls, and anything else is left out with a word', function () {
    expect(track_shared_setting(''))->toBe(['url' => null, 'problem' => ''])
        ->and(track_shared_setting(' https://puls.example.com/ '))->toBe(['url' => 'https://puls.example.com', 'problem' => ''])
        // A Puls in a folder, or on a port, as a test install often is
        ->and(track_shared_setting('http://127.0.0.1:8081/stats/'))->toBe(['url' => 'http://127.0.0.1:8081/stats', 'problem' => ''])
        // It goes into a header and a script tag, so only a plain address passes; the demo's own share link was Puls's first try
        ->and(track_shared_setting('https://demo.example/?share=vaka_1'))->toBe(['url' => null, 'problem' => 'TRACK_SHARED_URL "https://demo.example/?share=vaka_1" is not the plain address of a Puls (such as https://puls.example.com, with no ? or #), so visits to shared pages are not counted.'])
        ->and(track_shared_setting('puls.example.com')['url'])->toBeNull()
        ->and(track_shared_setting('https://puls.example.com" onload="x')['url'])->toBeNull()
        ->and(track_shared_setting("https://puls.example.com\r\nX-Evil: 1")['url'])->toBeNull();
});

test('config.php gathers what is wrong with the settings, and the server\'s zone stands in', function () {
    $config = load_config(['TIMEZONE' => 'Mars/Olympus', 'TIME_FORMAT' => 'am/pm']);
    expect($config['timezone'])->toBe(date_default_timezone_get())
        ->and($config['time_format'])->toBe('24h')
        ->and($config['config_problems'])->toHaveCount(2)
        ->and(load_config()['config_problems'])->toBe([]);

    $tracked = load_config(['TRACK_SHARED_URL' => 'puls.example.com', 'TRACK_SHARED_SITE' => ' demo ']);
    expect($tracked['track_shared_url'])->toBeNull()
        ->and($tracked['track_shared_site'])->toBe('demo')
        ->and($tracked['config_problems'])->toBe([track_shared_setting('puls.example.com')['problem']]);
});

test('clock times follow TIME_FORMAT', function () {
    $at = fn (string $time) => new DateTimeImmutable("2026-10-27 {$time}");
    expect(clock_text($at('14:05'), '24h'))->toBe('14:05')
        ->and(clock_text($at('14:05'), '12h'))->toBe('2:05 PM')
        ->and(clock_text($at('00:30'), '12h'))->toBe('12:30 AM')
        ->and(clock_text($at('12:00'), '12h'))->toBe('12:00 PM')
        ->and(hhmm_text('07:00', '12h'))->toBe('7:00 AM')
        ->and(hhmm_text('07:00', '24h'))->toBe('07:00');
});

test('Vaka\'s requests call it VakaBot, so log filters and analytics count it as a bot', function () {
    $config = ['user_agent' => '', 'app_url' => ''];
    expect(user_agent($config))->toBe('VakaBot/' . VAKA_VERSION . ' (+https://github.com/webready-se/vaka)')
        // The page that says what it does: the operator's own, when APP_URL is set
        ->and(user_agent(['app_url' => 'https://vaka.example'] + $config))->toBe('VakaBot/' . VAKA_VERSION . ' (+https://vaka.example)')
        ->and(user_agent(['user_agent' => 'Monitor/1.0'] + $config))->toBe('Monitor/1.0');
});
