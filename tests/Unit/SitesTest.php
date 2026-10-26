<?php

beforeEach(function () {
    $this->db = get_db(temp_dir() . '/vaka.sqlite');
});

test('add_site stores a normalized URL with defaults', function () {
    $id = add_site($this->db, ['url' => 'Shop.Example/']);
    $site = find_site($this->db, (string) $id);

    expect($site['url'])->toBe('https://shop.example')
        ->and($site['group_id'])->toBeNull()
        ->and($site['interval_minutes'])->toBeNull()
        ->and($site['paused'])->toBe(0)
        ->and($site['disabled_checks'])->toBe('');
});

test('a URL can only be added once', function () {
    add_site($this->db, ['url' => 'https://shop.example']);
    expect(fn () => add_site($this->db, ['url' => 'shop.example/']))
        ->toThrow(InvalidArgumentException::class, 'already watched');
});

test('add_site puts a site in a group by name', function () {
    $groupId = add_group($this->db, ['name' => 'Customers']);
    add_site($this->db, ['url' => 'https://shop.example', 'group' => 'customers']);
    expect(find_site($this->db, 'shop.example')['group_id'])->toBe($groupId);
});

test('add_site refuses a group that does not exist', function () {
    expect(fn () => add_site($this->db, ['url' => 'https://shop.example', 'group' => 'Nope']))
        ->toThrow(InvalidArgumentException::class, 'No group called "Nope"');
});

test('site fields are validated', function (array $fields, string $reason) {
    expect(fn () => add_site($this->db, ['url' => 'https://shop.example'] + $fields))->toThrow(InvalidArgumentException::class, $reason);
})->with([
    'interval too short' => [['interval' => '0'], '1 and 1440'],
    'interval not a number' => [['interval' => 'often'], '1 and 1440'],
    'unknown check' => [['disabled_checks' => 'ssl,ping'], 'ping'],
    'keyword too long' => [['keyword' => str_repeat('k', 201)], '200'],
    'name too long' => [['name' => str_repeat('n', 101)], '100'],
]);

test('disabled checks are stored sorted and unique', function () {
    add_site($this->db, ['url' => 'https://shop.example', 'disabled_checks' => 'ssl, dns,ssl']);
    expect(find_site($this->db, 'shop.example')['disabled_checks'])->toBe('dns,ssl');
});

test('find_site matches an id, a URL or a unique host', function () {
    $id = add_site($this->db, ['url' => 'https://www.shop.example']);
    add_site($this->db, ['url' => 'https://blog.example/news']);
    add_site($this->db, ['url' => 'https://blog.example/shop']);

    expect(find_site($this->db, (string) $id)['id'])->toBe($id)
        ->and(find_site($this->db, 'https://www.shop.example/')['id'])->toBe($id)
        ->and(find_site($this->db, 'shop.example')['id'])->toBe($id)
        ->and(find_site($this->db, 'blog.example'))->toBeNull()
        ->and(find_site($this->db, 'nothing.example'))->toBeNull();
});

test('find_site finds an http site when no scheme is typed, port included', function () {
    $id = add_site($this->db, ['url' => 'http://127.0.0.1:8791/second']);
    add_site($this->db, ['url' => 'http://127.0.0.1:8792']);
    expect(find_site($this->db, '127.0.0.1:8791/second')['id'])->toBe($id)
        ->and(find_site($this->db, 'https://127.0.0.1:8791/second'))->toBeNull();
});

test('a bare domain means the site without a path when there are several', function () {
    $root = add_site($this->db, ['url' => 'http://127.0.0.1:8791']);
    add_site($this->db, ['url' => 'http://127.0.0.1:8791/second']);
    add_site($this->db, ['url' => 'https://www.shop.example']);
    $shop = add_site($this->db, ['url' => 'https://www.shop.example/en']);

    expect(find_site($this->db, '127.0.0.1:8791')['id'])->toBe($root)
        ->and(find_site($this->db, 'shop.example/en'))->toBeNull()
        ->and(find_site($this->db, 'www.shop.example/en')['id'])->toBe($shop);
});

test('update_site changes only the given fields', function () {
    add_group($this->db, ['name' => 'Customers']);
    $id = add_site($this->db, ['url' => 'https://shop.example', 'keyword' => 'Add to cart', 'group' => 'Customers']);

    update_site($this->db, $id, ['paused' => true, 'interval' => '1', 'server' => 'web-1']);

    $site = find_site($this->db, (string) $id);
    expect($site['paused'])->toBe(1)
        ->and($site['interval_minutes'])->toBe(1)
        ->and($site['server'])->toBe('web-1')
        ->and($site['keyword'])->toBe('Add to cart')
        ->and($site['group_id'])->not->toBeNull();
});

test('update_site moves a site out of its group with group none', function () {
    add_group($this->db, ['name' => 'Customers']);
    $id = add_site($this->db, ['url' => 'https://shop.example', 'group' => 'Customers']);
    update_site($this->db, $id, ['group' => 'none']);
    expect(find_site($this->db, (string) $id)['group_id'])->toBeNull();
});

test('update_site can change the URL but not onto another site', function () {
    $id = add_site($this->db, ['url' => 'https://shop.example']);
    add_site($this->db, ['url' => 'https://blog.example']);

    update_site($this->db, $id, ['url' => 'https://shop.example/en']);
    expect(find_site($this->db, (string) $id)['url'])->toBe('https://shop.example/en');

    expect(fn () => update_site($this->db, $id, ['url' => 'blog.example']))->toThrow(InvalidArgumentException::class, 'already watched');
});

test('a new URL is checked on the next run by every check, a new keyword by uptime only', function () {
    $id = add_site($this->db, ['url' => 'https://shop.example']);
    $later = utc_now(time() + 86400);
    foreach (['uptime', 'ssl', 'exposed'] as $check) {
        save_check_state($this->db, $id, $check, ['status' => 'ok', 'summary' => '', 'details' => [], 'fail_count' => 0, 'changed_at' => null], utc_now(), $later);
    }
    $nextAt = fn () => array_column($this->db->query('SELECT check_name, next_at FROM check_state ORDER BY check_name')->fetchAll(), 'next_at', 'check_name');

    update_site($this->db, $id, ['keyword' => 'Add to cart']);
    expect($nextAt())->toBe(['exposed' => $later, 'ssl' => $later, 'uptime' => null]);

    update_site($this->db, $id, ['url' => 'https://shop.example/en']);
    expect($nextAt())->toBe(['exposed' => null, 'ssl' => null, 'uptime' => null]);
});

test('update_site with an empty interval goes back to the default', function () {
    $id = add_site($this->db, ['url' => 'https://shop.example', 'interval' => '2']);
    update_site($this->db, $id, ['interval' => '']);
    expect(find_site($this->db, (string) $id)['interval_minutes'])->toBeNull();
});

test('remove_site deletes the site', function () {
    $id = add_site($this->db, ['url' => 'https://shop.example']);
    remove_site($this->db, $id);
    expect(find_site($this->db, (string) $id))->toBeNull();
});

test('sites sort by their address as it is shown, so an international domain sorts by its letters', function () {
    add_group($this->db, ['name' => 'A']);
    foreach (['https://zebra.example', 'https://xn--rksmrgs-5wao1o.example', 'https://shop.example'] as $url) add_site($this->db, ['url' => $url, 'group' => 'A']);
    $db = $this->db;
    $db->exec("UPDATE sites SET ip = '203.0.113.10'");

    expect(array_map(fn ($site) => display_url($site['url']), list_sites($db)))->toBe(['räksmörgås.example', 'shop.example', 'zebra.example'])
        ->and(array_map(fn ($site) => display_url($site['url']), sites_on_server($db, '203.0.113.10')))->toBe(['räksmörgås.example', 'shop.example', 'zebra.example']);
})->skip(!function_exists('idn_to_utf8'), 'needs the intl extension');

test('list_sites sorts by group then URL, ungrouped last, and can filter by group', function () {
    add_group($this->db, ['name' => 'B']);
    add_group($this->db, ['name' => 'A']);
    add_site($this->db, ['url' => 'https://z.example', 'group' => 'A']);
    add_site($this->db, ['url' => 'https://a.example']);
    add_site($this->db, ['url' => 'https://m.example', 'group' => 'B']);
    add_site($this->db, ['url' => 'https://b.example', 'group' => 'A']);

    expect(array_column(list_sites($this->db), 'url'))->toBe([
        'https://b.example', 'https://z.example', 'https://m.example', 'https://a.example',
    ]);
    expect(array_column(list_sites($this->db, find_group($this->db, 'B')['id']), 'url'))->toBe(['https://m.example']);
    expect(list_sites($this->db)[0]['group_name'])->toBe('A');
});
