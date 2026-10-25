<?php

test('parse_import_csv reads group, url and enabled', function () {
    $result = parse_import_csv("group,url,enabled\nCustomers,https://shop.example,1\nOwn,blog.example,0\n");

    expect($result['errors'])->toBe([])
        ->and($result['rows'])->toBe([
            ['line' => 2, 'url' => 'https://shop.example', 'group' => 'Customers', 'paused' => false, 'name' => '', 'keyword' => '', 'interval' => ''],
            ['line' => 3, 'url' => 'https://blog.example', 'group' => 'Own', 'paused' => true, 'name' => '', 'keyword' => '', 'interval' => ''],
        ]);
});

test('parse_import_csv takes columns in any order and ignores unknown ones', function () {
    $result = parse_import_csv("URL,Notes,Group,Name,Keyword,Interval\nshop.example,ignored,Customers,Shop,Add to cart,2\n");

    expect($result['errors'])->toBe([])
        ->and($result['rows'][0])->toMatchArray(['url' => 'https://shop.example', 'group' => 'Customers', 'paused' => false, 'name' => 'Shop', 'keyword' => 'Add to cart', 'interval' => '2']);
});

test('parse_import_csv handles a byte order mark, semicolons, quotes and blank lines', function () {
    $result = parse_import_csv("\u{FEFF}group;url;enabled\r\n\"Own sites; old\";https://shop.example;yes\r\n\r\n;https://blog.example;\r\n");

    expect($result['errors'])->toBe([])
        ->and(array_column($result['rows'], 'group'))->toBe(['Own sites; old', ''])
        ->and(array_column($result['rows'], 'paused'))->toBe([false, false]);
});

test('parse_import_csv understands the usual ways to write enabled', function () {
    $csv = "url,enabled\n" . implode("\n", array_map(fn ($v, $i) => "https://s{$i}.example,{$v}", ['1', 'true', 'Yes', 'on', '0', 'false', 'NO', 'off'], range(1, 8)));
    expect(array_column(parse_import_csv($csv)['rows'], 'paused'))->toBe([false, false, false, false, true, true, true, true]);
});

test('parse_import_csv reports every problem with its line number', function () {
    $result = parse_import_csv("group,url,enabled\nA,shop,1\nA,https://ok.example,maybe\nA,https://ok.example/,1\n,,1\n");

    expect($result['errors'])->toBe([
        'line 2: "shop" has no domain (example: shop.example)',
        'line 3: enabled must be yes or no (got "maybe")',
        'line 4: https://ok.example is already on line 3',
        'line 5: the url is empty',
    ]);
});

test('parse_import_csv needs a header with a url column', function () {
    expect(parse_import_csv("https://shop.example,1\n")['errors'])->toBe(['line 1: the first line must name the columns, with at least "url" (e.g. group,url,enabled)'])
        ->and(parse_import_csv('')['errors'])->toBe(['the file is empty']);
});

test('import_sites adds new sites, creates missing groups and leaves existing sites alone', function () {
    $db = get_db(temp_dir() . '/vaka.sqlite');
    add_group($db, ['name' => 'customers']);
    add_site($db, ['url' => 'https://shop.example', 'keyword' => 'kept']);

    $rows = parse_import_csv("group,url,enabled\nCustomers,https://shop.example,1\nCustomers,https://new.example,0\nOwn,https://blog.example,1\n,https://loose.example,1\n")['rows'];
    $summary = import_sites($db, $rows);

    expect($summary)->toBe(['added' => 3, 'paused' => 1, 'existing' => 1, 'new_groups' => ['Own']])
        ->and(find_site($db, 'shop.example')['keyword'])->toBe('kept')
        ->and(find_site($db, 'shop.example')['group_id'])->toBeNull()
        ->and(find_site($db, 'new.example')['paused'])->toBe(1)
        ->and(find_site($db, 'new.example')['group_id'])->toBe(find_group($db, 'Customers')['id'])
        ->and(find_site($db, 'loose.example')['group_id'])->toBeNull();
});

test('import_sites in a dry run saves nothing', function () {
    $db = get_db(temp_dir() . '/vaka.sqlite');
    $rows = parse_import_csv("group,url\nOwn,https://blog.example\n")['rows'];

    $summary = import_sites($db, $rows, dryRun: true);

    expect($summary['added'])->toBe(1)
        ->and($summary['new_groups'])->toBe(['Own'])
        ->and(list_sites($db))->toBe([])
        ->and(list_groups($db))->toBe([]);
});

test('import_sites saves nothing when a row fails', function () {
    $db = get_db(temp_dir() . '/vaka.sqlite');
    $rows = parse_import_csv("group,url,interval\nOwn,https://blog.example,5\nOwn,https://shop.example,9999\n")['rows'];

    expect(fn () => import_sites($db, $rows))->toThrow(InvalidArgumentException::class, 'line 3');
    expect(list_sites($db))->toBe([]);
});
