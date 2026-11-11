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

test('group:remove refuses a group that users are limited to, and says what to do first', function () {
    vaka(['group:add', 'Customers'], $this->env);
    vaka(['group:add', 'Own'], $this->env);
    save_users($this->env['USERS_FILE'], ['anna' => ['password' => 'x', 'groups' => ['Customers']], 'bo' => ['password' => 'x', 'groups' => ['customers', 'Own']], 'admin' => ['password' => 'x', 'groups' => []]]);

    $r = vaka(['group:remove', 'Customers', '--force'], $this->env);
    expect($r['exit'])->toBe(1)
        ->and($r['err'])->toContain('Customers cannot be removed while users are limited to it: anna and bo.')
        ->and($r['err'])->toContain('php vaka user:edit <name> --groups=<groups>')
        ->and(vaka(['group:list'], $this->env)['out'])->toContain('Customers');
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

test('site:import says where it looked, and why ".." misleads from a linked directory like current/', function () {
    // A Forge zero-downtime layout: current/ links to releases/1, the list sits in the site root
    $site = realpath(temp_dir());
    mkdir("{$site}/releases/1", 0755, true);
    symlink("{$site}/releases/1", "{$site}/current");
    file_put_contents("{$site}/sites.csv", "url\nhttps://shop.example\n");

    $r = vaka(['site:import', '../sites.csv', '--dry-run'], $this->env + ['PWD' => "{$site}/current"], null, [], "{$site}/current");
    expect($r['exit'])->toBe(1)
        ->and($r['err'])->toContain("Cannot read ../sites.csv: there is no {$site}/releases/sites.csv.")
        ->and($r['err'])->toContain("{$site}/current is a link to {$site}/releases/1")
        ->and($r['err'])->toMatch('#php[\d.]* vaka site:import ' . preg_quote("{$site}/sites.csv", '#') . '#');
});

test('site:edit moves every site on a server into a group, by IP or by its name', function () {
    vaka(['group:add', 'Customers'], $this->env);
    vaka(['group:add', 'web-1'], $this->env);
    foreach (['shop.example', 'blog.example', 'other.example', 'cdn.example'] as $domain) vaka(['site:add', $domain, '--group=Customers'], $this->env);
    $db = get_db($this->env['DB_PATH']);
    $db->exec("UPDATE sites SET ip = '203.0.113.10' WHERE url IN ('https://shop.example', 'https://blog.example')");
    $db->exec("UPDATE sites SET ip = '198.51.100.7' WHERE url = 'https://other.example'");
    // Behind a CDN the IP is the CDN's, so the site is not on that server
    $db->exec("UPDATE sites SET ip = '203.0.113.10', cdn = 'Cloudflare' WHERE url = 'https://cdn.example'");
    $groups = fn () => array_column($db->query('SELECT s.url, g.name FROM sites s LEFT JOIN groups g ON g.id = s.group_id ORDER BY s.url')->fetchAll(), 'name', 'url');

    $r = vaka(['site:edit', '203.0.113.10', '--group=web-1'], $this->env);
    expect($r['exit'])->toBe(0, $r['err'])
        ->and($r['out'])->toBe("Moved 2 sites on server 203.0.113.10 to web-1: blog.example and shop.example.\n")
        ->and($groups())->toBe(['https://blog.example' => 'web-1', 'https://cdn.example' => 'Customers', 'https://other.example' => 'Customers', 'https://shop.example' => 'web-1']);

    vaka(['server:label', '198.51.100.7', 'web-2'], $this->env);
    expect(vaka(['site:edit', 'web-2', '--group=none'], $this->env)['out'])->toContain('Moved 1 site on server web-2 (198.51.100.7) out of its group: other.example.');
});

test('a move says who it shows the sites to and hides them from, since logins and tokens for a group follow its sites', function () {
    vaka(['group:add', 'Customers'], $this->env);
    vaka(['group:add', 'Agency'], $this->env);
    foreach (['shop.example', 'blog.example'] as $domain) vaka(['site:add', $domain, '--group=Customers'], $this->env);
    save_users($this->env['USERS_FILE'], ['anna' => ['password' => 'x', 'groups' => ['Customers']], 'bo' => ['password' => 'x', 'groups' => ['Agency', 'Customers']], 'admin' => ['password' => 'x', 'groups' => []]]);
    $db = get_db($this->env['DB_PATH']);
    $token = create_share_token($db, null, [find_group($db, 'Agency')['id']], 'Claude Code', null, time())['id'];

    $r = vaka(['site:edit', 'shop.example', '--group=Agency'], $this->env);
    expect($r['exit'])->toBe(0, $r['err'])
        ->and($r['out'])->toEndWith("\nNow also visible to: read token #{$token} (Claude Code).\nNo longer visible to: user anna.\n");

    // Nothing changes for anyone: no lines
    expect(vaka(['site:edit', 'shop.example', '--name=Shop'], $this->env)['out'])->not->toContain('visible to');

    // A whole server, out of its group
    $db->exec("UPDATE sites SET ip = '203.0.113.10'");
    expect(vaka(['site:edit', '203.0.113.10', '--group=none'], $this->env)['out'])
        ->toBe("Moved 2 sites on server 203.0.113.10 out of their group: blog.example and shop.example.\nNo longer visible to: user anna, user bo, read token #{$token} (Claude Code).\n");
});

test('site:edit on a server changes only the group', function () {
    vaka(['site:add', 'shop.example'], $this->env);
    get_db($this->env['DB_PATH'])->exec("UPDATE sites SET ip = '203.0.113.10'");
    $r = vaka(['site:edit', '203.0.113.10', '--keyword=Buy'], $this->env);
    expect($r['exit'])->toBe(1)
        ->and($r['err'])->toContain('For a whole server, only --group can change');
});

test('site messages show an international domain as it is written, not as xn--', function () {
    expect(vaka(['site:add', 'https://xn--rksmrgs-5wao1o.example'], $this->env)['out'])->toContain('Added https://räksmörgås.example.')
        ->and(vaka(['site:list'], $this->env)['out'])->toContain('https://räksmörgås.example');
    $r = vaka(['site:remove', 'räksmörgås.example'], $this->env, "y\n");
    expect($r['out'])->toContain('Remove https://räksmörgås.example and its history? (y/N)')
        ->and($r['out'])->toContain('Removed https://räksmörgås.example.');
})->skip(!function_exists('idn_to_utf8'), 'needs the intl extension');

test('site:add without arguments asks for what it needs', function () {
    // URL, then Enter for name, keyword and interval (no groups exist, so no group question)
    $r = vaka(['site:add'], $this->env, "shop.example\n\n\n\n");
    expect($r['exit'])->toBe(0, $r['err'])
        ->and($r['out'])->toContain('URL')
        ->and($r['out'])->toContain('Added https://shop.example');
});

test('site:add from a script adds an address that does not resolve, and says so', function () {
    $env = cli_env();
    $r = vaka(['site:add', 'https://shop.invalid', '--group=none'], $env);
    expect($r['exit'])->toBe(0, $r['out'] . $r['err'])
        ->and($r['err'])->toContain('Warning: shop.invalid does not resolve: no DNS record says where it is. Added anyway: check the spelling.')
        ->and(vaka(['site:list'], $env)['out'])->toContain('shop.invalid');
});
