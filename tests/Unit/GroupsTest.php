<?php

beforeEach(function () {
    $this->db = get_db(temp_dir() . '/vaka.sqlite');
});

test('add_group stores a group with immediate alerts by default', function () {
    $id = add_group($this->db, ['name' => 'Customers']);
    $group = find_group($this->db, 'customers');

    expect($group['id'])->toBe($id)
        ->and($group['name'])->toBe('Customers')
        ->and($group['policy'])->toBe('immediate')
        ->and($group['delay_minutes'])->toBe(0);
});

test('group names are unique regardless of case', function () {
    add_group($this->db, ['name' => 'Customers']);
    expect(fn () => add_group($this->db, ['name' => 'CUSTOMERS']))
        ->toThrow(InvalidArgumentException::class, 'already exists');
});

test('a group needs a usable name', function (string $name, string $reason) {
    expect(fn () => add_group($this->db, ['name' => $name]))->toThrow(InvalidArgumentException::class, $reason);
})->with([
    'empty' => ['  ', 'name'],
    'reserved' => ['none', 'reserved'],
    'too long' => [str_repeat('x', 61), '60'],
    'control characters' => ["bad\x07name", 'name'],
]);

test('the delayed policy needs a delay', function () {
    expect(fn () => add_group($this->db, ['name' => 'Slow', 'policy' => 'delayed']))
        ->toThrow(InvalidArgumentException::class, 'minutes');

    add_group($this->db, ['name' => 'Slow', 'policy' => 'delayed', 'delay_minutes' => '15']);
    expect(find_group($this->db, 'Slow')['delay_minutes'])->toBe(15);
});

test('only the delayed policy keeps a delay', function () {
    add_group($this->db, ['name' => 'Fast', 'policy' => 'immediate', 'delay_minutes' => '15']);
    expect(find_group($this->db, 'Fast')['delay_minutes'])->toBe(0);
});

test('unknown policies are rejected', function () {
    expect(fn () => add_group($this->db, ['name' => 'X', 'policy' => 'sometimes']))
        ->toThrow(InvalidArgumentException::class, 'immediate, delayed, digest or silent');
});

test('destinations are validated', function (array $fields, string $reason) {
    expect(fn () => add_group($this->db, ['name' => 'X'] + $fields))->toThrow(InvalidArgumentException::class, $reason);
})->with([
    'slack not https' => [['slack_webhook' => 'http://hooks.example/abc'], 'https'],
    'slack not a url' => [['slack_webhook' => 'hooks'], 'https'],
    'webhook not a url' => [['webhook_url' => 'ftp://example.com/hook'], 'http'],
    'bad email' => [['email' => 'ops@example.com, not-an-email'], 'not-an-email'],
]);

test('email lists are tidied', function () {
    add_group($this->db, ['name' => 'X', 'email' => ' ops@example.com ;dev@example.com,, ']);
    expect(find_group($this->db, 'X')['email'])->toBe('ops@example.com, dev@example.com');
});

test('update_group changes only the given fields', function () {
    $id = add_group($this->db, ['name' => 'Customers', 'slack_webhook' => 'https://hooks.example/one']);
    update_group($this->db, $id, ['policy' => 'digest']);

    $group = find_group($this->db, 'Customers');
    expect($group['policy'])->toBe('digest')
        ->and($group['slack_webhook'])->toBe('https://hooks.example/one');
});

test('update_group can rename but not onto another group', function () {
    $id = add_group($this->db, ['name' => 'Customers']);
    add_group($this->db, ['name' => 'Own']);

    update_group($this->db, $id, ['name' => 'Clients']);
    expect(find_group($this->db, 'Clients')['id'])->toBe($id);

    expect(fn () => update_group($this->db, $id, ['name' => 'own']))->toThrow(InvalidArgumentException::class, 'already exists');
});

test('removing a group leaves its sites without a group', function () {
    $id = add_group($this->db, ['name' => 'Customers']);
    add_site($this->db, ['url' => 'https://shop.example', 'group' => 'Customers']);

    remove_group($this->db, $id);

    expect(find_group($this->db, 'Customers'))->toBeNull()
        ->and(find_site($this->db, 'shop.example')['group_id'])->toBeNull();
});

test('list_groups counts sites per group', function () {
    add_group($this->db, ['name' => 'B']);
    add_group($this->db, ['name' => 'A']);
    add_site($this->db, ['url' => 'https://one.example', 'group' => 'B']);
    add_site($this->db, ['url' => 'https://two.example', 'group' => 'B']);

    $groups = list_groups($this->db);
    expect(array_column($groups, 'name'))->toBe(['A', 'B'])
        ->and(array_column($groups, 'site_count'))->toBe([0, 2]);
});

test('describe_policy says in plain words when alerts go out', function () {
    expect(describe_policy(['policy' => 'immediate', 'delay_minutes' => 0], '08:00'))->toBe('right away')
        ->and(describe_policy(['policy' => 'delayed', 'delay_minutes' => 10], '08:00'))->toBe('after 10 min down')
        ->and(describe_policy(['policy' => 'digest', 'delay_minutes' => 0], '08:00'))->toBe('as a daily summary at 08:00')
        ->and(describe_policy(['policy' => 'silent', 'delay_minutes' => 0], '08:00'))->toBe('never (dashboard only)');
});

test('describe_alerting says where alerts go, falling back to .env, and when nowhere', function () {
    $group = ['policy' => 'delayed', 'delay_minutes' => 10, 'slack_webhook' => '', 'webhook_url' => '', 'email' => ''];
    $none = ['digest_time' => '08:00', 'slack_webhook_url' => '', 'webhook_url' => '', 'alert_email' => []];
    $defaults = ['slack_webhook_url' => 'https://hooks.example/services/x/y/zzzz9999', 'alert_email' => ['ops@example.com']] + $none;

    expect(describe_alerting(['slack_webhook' => 'https://hooks.example/services/a/b/cccc1234', 'email' => 'dev@example.com'] + $group, $none))
        ->toBe('Alerts go out after 10 min down, to Slack hooks.example/...1234 and dev@example.com.')
        ->and(describe_alerting($group, $defaults))
        ->toBe('Alerts go out after 10 min down, to the defaults in .env: Slack hooks.example/...9999 and ops@example.com.')
        ->and(describe_alerting($group, $none))
        ->toBe('Alerts go out after 10 min down, but nowhere yet: set SLACK_WEBHOOK_URL in .env, or give the group --slack=<webhook url>.')
        ->and(describe_alerting(['policy' => 'silent'] + $group, $defaults))
        ->toBe('No alerts are sent: these sites show on the dashboard only.');
});

test('mask_secret shows the host and the last characters only', function () {
    expect(mask_secret('https://hooks.example/services/T000/B000/abcdefgh1234'))->toBe('hooks.example/...1234')
        ->and(mask_secret(''))->toBe('');
});
