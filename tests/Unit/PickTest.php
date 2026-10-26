<?php

// The design kit's CLI rules (docs/cli.md): pick from numbered lists with context, the only option taken
// without asking, (y/N) questions, and output that ends with what to do next

beforeEach(function () {
    $this->env = cli_env();
});

test('the only option is taken without asking, and a yes or no question shows its default', function () {
    vaka(['site:add', 'shop.example'], $this->env);
    $r = vaka(['site:remove'], $this->env, "y\n");
    expect($r['exit'])->toBe(0, $r['err'])
        ->and($r['out'])->toContain('Which site? shop.example (the only one)')
        ->and($r['out'])->toContain('Remove https://shop.example and its history? (y/N)')
        ->and($r['out'])->toContain('Removed https://shop.example.');
});

test('a site, a group and a user are picked from lists that say something about each', function () {
    vaka(['group:add', 'Customers'], $this->env);
    vaka(['group:add', 'Own'], $this->env);
    vaka(['site:add', 'shop.example', '--group=Customers'], $this->env);
    vaka(['site:add', 'blog.example'], $this->env);
    vaka(['user:add', 'anna', '--groups=Customers'], $this->env, "a long password\n");
    vaka(['user:add', 'erik'], $this->env, "a long password\n");

    expect(vaka(['site:remove'], $this->env, "2\nn\n")['out'])->toContain("1) shop.example (Customers)\n  2) blog.example (no group)")
        ->and(vaka(['group:remove'], $this->env, "1\nn\n")['out'])->toContain("1) Customers (1 site)\n  2) Own (no sites)")
        ->and(vaka(['user:remove'], $this->env, "1\nn\n")['out'])->toContain("1) anna (sees the group Customers)\n  2) erik (sees every site)");
});

test('user:add without a name asks for the groups as a list, several at once', function () {
    vaka(['group:add', 'Customers'], $this->env);
    vaka(['group:add', 'Own'], $this->env);
    $r = vaka(['user:add'], $this->env, "anna\n1,2\na long password\n");
    expect($r['exit'])->toBe(0, $r['err'])
        ->and($r['out'])->toContain('Numbers, comma-separated (Enter or 0 for every site)')
        ->and($r['out'])->toContain('Added user anna, who sees the groups Customers and Own.');
});

test('user:add ends with where to sign in', function () {
    expect(vaka(['user:add', 'anna'], $this->env + ['APP_URL' => 'https://vaka.example.com'], "a long password\n")['out'])
        ->toContain('Added user anna, who sees every site. Sign in at https://vaka.example.com')
        ->and(vaka(['user:add', 'erik'], $this->env, "a long password\n")['out'])->toContain('Added user erik, who sees every site. Sign in on the dashboard.');
});

test('a maintenance window is set up and removed by picking from lists', function () {
    vaka(['group:add', 'Nightly'], $this->env);
    vaka(['site:add', 'shop.example', '--group=Nightly'], $this->env);
    $r = vaka(['maintenance:add', '--at=00:00-07:00'], $this->env, "1\n");
    expect($r['exit'])->toBe(0, $r['err'])
        ->and($r['out'])->toContain("1) The group Nightly (1 site)\n  2) shop.example (Nightly)")
        ->and($r['out'])->toContain('group Nightly');

    $r = vaka(['maintenance:remove'], $this->env, '');
    expect($r['exit'])->toBe(0, $r['err'])
        ->and($r['out'])->toContain('Which window? group Nightly, every day 00:00-07:00 (the only one)')
        ->and($r['out'])->toContain('Removed the maintenance window for group Nightly');
});

test('a server is named by picking it from the servers Vaka knows', function () {
    vaka(['site:add', 'shop.example'], $this->env);
    vaka(['site:add', 'blog.example'], $this->env);
    get_db($this->env['DB_PATH'])->exec("UPDATE sites SET ip = '203.0.113.10'");
    $r = vaka(['server:label'], $this->env, "web-1\n");
    expect($r['exit'])->toBe(0, $r['err'])
        ->and($r['out'])->toContain('Which server? 203.0.113.10 (2 sites) (the only one)')
        ->and($r['out'])->toContain('Server 203.0.113.10 is called web-1 now.');
});

test('unsnooze without a site lists what is snoozed, and says so when nothing is', function () {
    expect(vaka(['unsnooze'], $this->env)['out'])->toContain('Nothing is snoozed right now.');
});
