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

test('run_dir is where commands run: current/ on a Forge zero-downtime deploy, which follows every release', function () {
    expect(run_dir('/var/www/vaka'))->toBe('/var/www/vaka')
        ->and(run_dir('/home/forge/vaka.example.com/releases/20261026120000'))->toBe('/home/forge/vaka.example.com/current')
        ->and(run_dir('/home/forge/vaka.example.com'))->toBe('/home/forge/vaka.example.com');
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
        ->and($config['trusted_proxies'])->toBe([]);
});
