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

    vaka(['user:edit', 'anna', '--groups='], $this->env);
    expect(vaka(['user:list'], $this->env)['out'])->toMatch('/anna\s+every site/');
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
