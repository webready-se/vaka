<?php

test('vaka without a command prints help', function () {
    $r = vaka([]);
    expect($r['exit'])->toBe(0)
        ->and($r['out'])->toContain('Usage: php vaka <command>')
        ->and($r['out'])->toContain('help')
        ->and($r['out'])->toContain('version');
});

test('vaka help prints help', function () {
    $r = vaka(['help']);
    expect($r['exit'])->toBe(0)
        ->and($r['out'])->toContain('Usage: php vaka <command>');
});

test('vaka version prints the version', function () {
    $r = vaka(['version']);
    expect($r['exit'])->toBe(0)
        ->and(trim($r['out']))->toBe('Vaka ' . VAKA_VERSION);
});

test('an unknown command fails with a hint on stderr', function () {
    $r = vaka(['nope']);
    expect($r['exit'])->toBe(1)
        ->and($r['err'])->toContain('Unknown command: nope')
        ->and($r['err'])->toContain('php vaka help');
});

test('parse_args splits positional arguments and options', function () {
    [$args, $opts] = parse_args(['site:add', 'https://shop.example', '--group=customers', '--paused', '--name=Shop = best']);
    expect($args)->toBe(['site:add', 'https://shop.example'])
        ->and($opts)->toBe(['group' => 'customers', 'paused' => true, 'name' => 'Shop = best']);
});

test('parse_args treats everything after -- as positional', function () {
    [$args, $opts] = parse_args(['check', '--', '--not-an-option']);
    expect($args)->toBe(['check', '--not-an-option'])
        ->and($opts)->toBe([]);
});

test('the CLI tells times in 12h when TIME_FORMAT says so', function () {
    $env = cli_env() + ['DOWN_AFTER_FAILURES' => '1'];
    vaka(['site:add', 'http://127.0.0.1:9/', '--group=none'], $env);
    vaka(['run'], $env);
    expect(vaka(['status'], $env + ['TIME_FORMAT' => '12h'])['out'])->toMatch('/DOWN\s+since \d{1,2}:\d{2} (AM|PM) \(/')
        ->and(vaka(['status'], $env)['out'])->toMatch('/DOWN\s+since \d{2}:\d{2} \(/');
});

test('a TIMEZONE PHP does not know never stops a run, and every command says what is wrong', function () {
    $env = cli_env() + ['TIMEZONE' => 'Europe/Stokholm'];
    vaka(['site:add', 'http://127.0.0.1:9/', '--group=none'], $env);
    vaka(['maintenance:add', '127.0.0.1:9', '--at=00:00-07:00'], $env);

    $run = vaka(['run'], $env);
    expect($run['exit'])->toBe(0, $run['out'] . $run['err'])
        ->and($run['out'] . $run['err'])->not->toContain('Fatal')
        ->and($run['err'])->toContain('TIMEZONE "Europe/Stokholm" is not a time zone, so Vaka uses the server\'s');

    expect(vaka(['status'], $env)['err'])->toContain('Did you mean Europe/Stockholm?');
});

test('the overview gives the clock format, and what is wrong with the settings to users who see everything', function () {
    $db = get_db(temp_dir() . '/vaka.sqlite');
    $config = test_config(['time_format' => '12h', 'config_problems' => ['TIME_FORMAT "am/pm" is neither 24h nor 12h, so Vaka uses 24h.']]);
    $everything = api_overview($db, $config, null, time());
    expect($everything['time_format'])->toBe('12h')
        ->and($everything['setup']['problems'])->toBe($config['config_problems'])
        ->and(api_overview($db, $config, [], time())['setup'])->toBeNull();
});
