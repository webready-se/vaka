<?php

beforeEach(function () {
    $this->env = cli_env();
});

test('user:add takes the password from stdin and stores a bcrypt hash', function () {
    $r = vaka(['user:add', 'admin'], $this->env, "a long password\n");
    expect($r['exit'])->toBe(0, $r['err'])
        ->and($r['out'])->toContain('Added user admin, who sees every site');

    $users = json_decode(file_get_contents($this->env['USERS_FILE']), true);
    expect($users['admin']['groups'])->toBe([])
        ->and(password_verify('a long password', $users['admin']['password']))->toBeTrue()
        ->and(str_starts_with($users['admin']['password'], '$2y$'))->toBeTrue();
});

test('user:add makes a strong password when none is typed, and shows it once', function () {
    $r = vaka(['user:add', 'admin'], $this->env, "\n");
    expect($r['exit'])->toBe(0, $r['err'])
        ->and($r['out'])->toContain('Enter for a generated one')
        ->and($r['out'])->toMatch('/Generated password: ([a-z2-9]{5}(?:-[a-z2-9]{5}){3})\n/')
        ->and($r['out'])->toContain('Copy it now');
    preg_match('/Generated password: (\S+)/', $r['out'], $m);
    expect(password_verify($m[1], json_decode(file_get_contents($this->env['USERS_FILE']), true)['admin']['password']))->toBeTrue();
});

test('generate_password: four groups of five, without look-alike characters', function () {
    $passwords = array_map(fn () => generate_password(), range(1, 50));
    expect(count(array_unique($passwords)))->toBe(50);
    foreach ($passwords as $password) {
        expect($password)->toMatch('/^[a-hjkmnp-z2-9]{5}(-[a-hjkmnp-z2-9]{5}){3}$/');
    }
});

test('user:add refuses a short password, an existing user and an unknown group', function () {
    expect(vaka(['user:add', 'admin'], $this->env, "short\n")['err'])->toContain('at least 10 characters');

    vaka(['user:add', 'admin'], $this->env, "a long password\n");
    expect(vaka(['user:add', 'admin'], $this->env, "a long password\n")['err'])->toContain('already exists');

    expect(vaka(['user:add', 'anna', '--groups=Customers'], $this->env, "a long password\n")['err'])->toContain('No group called "Customers"');
});

test('a user limited to groups, listed and changed', function () {
    vaka(['group:add', 'Customers'], $this->env);
    vaka(['group:add', 'Own'], $this->env);
    $r = vaka(['user:add', 'anna', '--groups=customers,Own'], $this->env, "a long password\n");
    expect($r['out'])->toContain('Added user anna, who sees the groups Customers and Own');

    expect(vaka(['user:list'], $this->env)['out'])->toMatch('/anna\s+Customers, Own/');

    vaka(['user:edit', 'anna', '--groups=all'], $this->env);
    expect(vaka(['user:list'], $this->env)['out'])->toMatch('/anna\s+every site/');
});

test('an empty --groups is refused: only the word all gives every site', function () {
    vaka(['group:add', 'Customers'], $this->env);
    vaka(['user:add', 'anna', '--groups=Customers'], $this->env, "a long password\n");

    // A script's empty variable, a bare flag or only commas never take a limit away
    foreach (['--groups=', '--groups', '--groups= , '] as $flag) {
        $r = vaka(['user:edit', 'anna', $flag], $this->env);
        expect($r['exit'])->toBe(1)
            ->and($r['err'])->toContain('--groups is empty')
            ->and($r['err'])->toContain('`--groups=all` for every site');
        expect(vaka(['user:list'], $this->env)['out'])->toMatch('/anna\s+Customers/');

        $r = vaka(['user:add', 'erik', $flag], $this->env, "a long password\n");
        expect($r['exit'])->toBe(1)
            ->and($r['err'])->toContain('--groups is empty');
        expect(vaka(['user:list'], $this->env)['out'])->not->toContain('erik');
    }

    expect(vaka(['user:add', 'erik', '--groups=ALL'], $this->env, "a long password\n")['out'])->toContain('Added user erik, who sees every site');
});

test('a group called all wins over the word, since it shows less', function () {
    vaka(['group:add', 'All'], $this->env);
    expect(vaka(['user:add', 'anna', '--groups=all'], $this->env, "a long password\n")['out'])->toContain('Added user anna, who sees the group All');
});

test('renaming a group keeps it for the users that have it', function () {
    vaka(['group:add', 'Customers'], $this->env);
    vaka(['user:add', 'anna', '--groups=Customers'], $this->env, "a long password\n");
    vaka(['group:edit', 'Customers', '--name=Clients'], $this->env);
    expect(vaka(['user:list'], $this->env)['out'])->toMatch('/anna\s+Clients/');
});

test('user:edit --password asks for a new password', function () {
    vaka(['user:add', 'admin'], $this->env, "a long password\n");
    $r = vaka(['user:edit', 'admin', '--password'], $this->env, "another long password\n");
    expect($r['exit'])->toBe(0, $r['err'])
        ->and(password_verify('another long password', json_decode(file_get_contents($this->env['USERS_FILE']), true)['admin']['password']))->toBeTrue();
});

test('user:remove asks first, unless --force', function () {
    vaka(['user:add', 'admin'], $this->env, "a long password\n");
    expect(vaka(['user:remove', 'admin'], $this->env, "n\n")['exit'])->toBe(1);
    expect(vaka(['user:remove', 'admin', '--force'], $this->env)['out'])->toContain('Removed user admin');
    expect(vaka(['user:list'], $this->env)['out'])->toContain('No users yet');
});
