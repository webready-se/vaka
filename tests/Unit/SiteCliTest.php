<?php

beforeEach(function () {
    $this->env = cli_env();
    $this->fixtures = __DIR__ . '/../fixtures/import';
});

test('help lists the site and group commands', function () {
    $out = vaka(['help'])['out'];
    foreach (['site:add', 'site:edit', 'site:remove', 'site:list', 'site:import', 'group:add', 'group:edit', 'group:remove', 'group:list'] as $command) {
        expect($out)->toContain($command);
    }
});

test('group:add with options, then group:list in plain words with secrets masked', function () {
    $r = vaka(['group:add', 'Customers', '--policy=delayed', '--delay=10', '--slack=https://hooks.example/services/abc/def/secret1234', '--email=ops@example.com'], $this->env);
    expect($r['exit'])->toBe(0, $r['err'])
        ->and($r['out'])->toContain('Added group Customers');

    $list = vaka(['group:list'], $this->env)['out'];
    expect($list)->toContain('Customers')
        ->and($list)->toContain('after 10 min down')
        ->and($list)->toContain('hooks.example/...1234')
        ->and($list)->toContain('ops@example.com')
        ->and($list)->not->toContain('secret1234');
});

test('group:list without groups explains what happens instead', function () {
    $out = vaka(['group:list'], $this->env)['out'];
    expect($out)->toContain('No groups yet')
        ->and($out)->toContain('php vaka group:add');
});

test('group:add with a bad policy fails with a helpful message', function () {
    $r = vaka(['group:add', 'X', '--policy=sometimes'], $this->env);
    expect($r['exit'])->toBe(1)
        ->and($r['err'])->toContain('immediate, delayed, digest or silent');
});

test('site:add, site:list and site:edit', function () {
    vaka(['group:add', 'Customers'], $this->env);
    $r = vaka(['site:add', 'Shop.Example/', '--group=Customers', '--keyword=Add to cart'], $this->env);
    expect($r['exit'])->toBe(0, $r['err'])
        ->and($r['out'])->toContain('https://shop.example')
        ->and($r['out'])->toContain('every 5 min');

    $r = vaka(['site:edit', 'shop.example', '--pause', '--interval=1'], $this->env);
    expect($r['exit'])->toBe(0, $r['err']);

    $list = vaka(['site:list'], $this->env)['out'];
    expect($list)->toContain('https://shop.example')
        ->and($list)->toContain('Customers')
        ->and($list)->toContain('1 min')
        ->and($list)->toContain('paused')
        ->and($list)->toContain('Add to cart');
});

test('site:add --pause says the site is not checked until it is resumed', function () {
    $r = vaka(['site:add', 'https://shop.example', '--pause'], $this->env);
    expect($r['exit'])->toBe(0, $r['err'])
        ->and($r['out'])->toContain('Added https://shop.example, paused')
        ->and($r['out'])->toContain('php vaka site:edit shop.example --resume')
        ->and($r['out'])->not->toContain('every 5 min');
});

test('site:add refuses a URL that is already watched', function () {
    vaka(['site:add', 'https://shop.example'], $this->env);
    $r = vaka(['site:add', 'shop.example'], $this->env);
    expect($r['exit'])->toBe(1)
        ->and($r['err'])->toContain('already watched');
});

test('site:edit and site:remove explain an unknown site', function () {
    $r = vaka(['site:edit', 'nothing.example', '--pause'], $this->env);
    expect($r['exit'])->toBe(1)
        ->and($r['err'])->toContain('No site matches "nothing.example"');
});

test('site:list without sites says how to add one', function () {
    expect(vaka(['site:list'], $this->env)['out'])->toContain('php vaka site:add');
});

test('site:remove asks first and --force skips the question', function () {
    vaka(['site:add', 'https://shop.example'], $this->env);

    $r = vaka(['site:remove', 'shop.example'], $this->env, "n\n");
    expect($r['exit'])->toBe(1)
        ->and($r['out'])->toContain('Remove https://shop.example')
        ->and(vaka(['site:list'], $this->env)['out'])->toContain('https://shop.example');

    $r = vaka(['site:remove', 'shop.example', '--force'], $this->env);
    expect($r['exit'])->toBe(0, $r['err'])
        ->and(vaka(['site:list'], $this->env)['out'])->not->toContain('https://shop.example');
});

test('group:remove leaves the sites in place without a group', function () {
    vaka(['group:add', 'Customers'], $this->env);
    vaka(['site:add', 'https://shop.example', '--group=Customers'], $this->env);

    $r = vaka(['group:remove', 'Customers', '--force'], $this->env);
    expect($r['exit'])->toBe(0, $r['err'])
        ->and($r['out'])->toContain('1 site no longer has a group');

    $list = vaka(['site:list'], $this->env)['out'];
    expect($list)->toContain('https://shop.example')
        ->and($list)->not->toContain('Customers');
});

test('site:import adds the sites and groups from a CSV file', function () {
    $r = vaka(['site:import', $this->fixtures . '/sites.csv'], $this->env);
    expect($r['exit'])->toBe(0, $r['err'])
        ->and($r['out'])->toContain('4 new sites (1 paused)')
        ->and($r['out'])->toContain('2 new groups: Customers, Own sites');

    $list = vaka(['site:list'], $this->env)['out'];
    expect($list)->toContain('https://www.example.se')
        ->and($list)->toContain('https://blog.example.com')
        ->and($list)->toContain('https://example.org');

    $again = vaka(['site:import', $this->fixtures . '/sites.csv'], $this->env);
    expect($again['out'])->toContain('0 new sites')
        ->and($again['out'])->toContain('4 already in Vaka');
});

test('site:import --dry-run shows what would happen and saves nothing', function () {
    $r = vaka(['site:import', $this->fixtures . '/sites.csv', '--dry-run'], $this->env);
    expect($r['exit'])->toBe(0, $r['err'])
        ->and($r['out'])->toContain('4 new sites')
        ->and($r['out'])->toContain('Dry run');
    expect(vaka(['site:list'], $this->env)['out'])->toContain('No sites yet');
});

test('site:import lists every problem and saves nothing', function () {
    $r = vaka(['site:import', $this->fixtures . '/broken.csv'], $this->env);
    expect($r['exit'])->toBe(1)
        ->and($r['err'])->toContain('Nothing imported')
        ->and($r['err'])->toContain('line 3')
        ->and($r['err'])->toContain('line 4');
    expect(vaka(['site:list'], $this->env)['out'])->toContain('No sites yet');
});

test('site:import explains a file it cannot read', function () {
    $r = vaka(['site:import', '/nonexistent/sites.csv'], $this->env);
    expect($r['exit'])->toBe(1)
        ->and($r['err'])->toContain('Cannot read /nonexistent/sites.csv');
});

test('site:add without arguments asks for what it needs', function () {
    // URL, then Enter for name, keyword and interval (no groups exist, so no group question)
    $r = vaka(['site:add'], $this->env, "shop.example\n\n\n\n");
    expect($r['exit'])->toBe(0, $r['err'])
        ->and($r['out'])->toContain('URL')
        ->and($r['out'])->toContain('Added https://shop.example');
});
