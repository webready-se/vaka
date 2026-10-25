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
