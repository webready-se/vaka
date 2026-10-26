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

test('site:edit --group without a site moves the sites picked from a list, ranges and all', function () {
    vaka(['group:add', 'Customers'], $this->env);
    vaka(['group:add', 'Own'], $this->env);
    foreach (['a.example', 'b.example', 'c.example', 'd.example'] as $domain) vaka(['site:add', $domain, '--group=Customers'], $this->env);
    $groups = fn () => array_column(get_db($this->env['DB_PATH'])->query('SELECT s.url, g.name FROM sites s LEFT JOIN groups g ON g.id = s.group_id ORDER BY s.url')->fetchAll(), 'name', 'url');

    $r = vaka(['site:edit', '--group=Own'], $this->env, "1,3-4\n");
    expect($r['exit'])->toBe(0, $r['err'])
        ->and($r['out'])->toContain("Which sites?\n  1) a.example (Customers)")
        ->and($r['out'])->toContain('Numbers, comma-separated or ranges like 3-7 (0 for all, Enter for none)')
        ->and($r['out'])->toContain('Moved 3 sites to Own: a.example, c.example and d.example.')
        ->and($groups())->toBe(['https://a.example' => 'Own', 'https://b.example' => 'Customers', 'https://c.example' => 'Own', 'https://d.example' => 'Own']);

    expect(vaka(['site:edit', '--group=Own'], $this->env, "\n")['out'])->toContain('Nothing moved.')
        ->and(vaka(['site:edit', '--group=none'], $this->env, "0\n")['out'])->toContain('Moved 4 sites out of their group');
});

test('site:edit --group offers to add a group that does not exist yet', function () {
    vaka(['site:add', 'shop.example'], $this->env);
    $r = vaka(['site:edit', '--group=Agency'], $this->env, "\n1\n");
    expect($r['exit'])->toBe(0, $r['err'])
        ->and($r['out'])->toContain('There is no group called Agency. Add it? (Y/n)')
        ->and($r['out'])->toContain('Moved 1 site to Agency: shop.example.');

    expect(vaka(['site:edit', '--group=Typo'], $this->env, "n\n"))->toMatchArray(['exit' => 1])
        ->and(vaka(['site:edit', '--group=Typo'], $this->env, "n\n")['err'])->toContain('No group called "Typo"');
});

test('user:add without a name asks for the groups as a list, several at once', function () {
    vaka(['group:add', 'Customers'], $this->env);
    vaka(['group:add', 'Own'], $this->env);
    $r = vaka(['user:add'], $this->env, "anna\n1,2\na long password\n");
    expect($r['exit'])->toBe(0, $r['err'])
        ->and($r['out'])->toContain('Numbers, comma-separated or ranges like 3-7 (0 or Enter for every site)')
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
