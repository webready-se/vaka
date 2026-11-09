<?php

beforeEach(function () {
    $this->db = get_db(temp_dir() . '/vaka.sqlite');
    $this->now = strtotime('2026-10-26T12:00:00Z');
    $this->group = add_group($this->db, ['name' => 'Customers']);
    $this->other = add_group($this->db, ['name' => 'Own sites']);
    $this->site = add_site($this->db, ['url' => 'https://shop.example', 'group' => 'Customers']);
    add_site($this->db, ['url' => 'https://cafe.example', 'group' => 'Customers']);
    add_site($this->db, ['url' => 'https://example.org', 'group' => 'Own sites']);
});

// =====================================================================
// Tokens
// =====================================================================

test('a share token is shown once and stored only as its hash', function () {
    $created = create_share_token($this->db, null, [$this->group], 'AI assistant', utc_now($this->now + 86400), $this->now);
    $row = $this->db->query('SELECT * FROM share_tokens')->fetch();

    expect($created['token'])->toMatch('/^vaka_[a-f0-9]{64}$/')
        ->and($row['token_hash'])->toBe(hash('sha256', $created['token']))
        ->and($row['prefix'])->toBe(substr($created['token'], 0, 13))
        ->and($row['label'])->toBe('AI assistant')
        ->and(implode(' ', array_map('strval', $row)))->not->toContain($created['token']);
});

test('find_share_token: a valid token and what it may read, never an unknown, revoked or expired one', function () {
    $group = create_share_token($this->db, null, [$this->group], '', utc_now($this->now + 86400), $this->now)['token'];
    $site = create_share_token($this->db, $this->site, [], '', utc_now($this->now + 86400), $this->now)['token'];
    $expiring = create_share_token($this->db, $this->site, [], '', utc_now($this->now + 3600), $this->now)['token'];

    expect(find_share_token($this->db, $group, $this->now))->toMatchArray(['site_id' => null, 'groups' => [['id' => $this->group, 'name' => 'Customers']]])
        ->and(find_share_token($this->db, $site, $this->now))->toMatchArray(['site_id' => $this->site, 'site_url' => 'https://shop.example'])
        ->and(find_share_token($this->db, $expiring, $this->now + 3599))->not->toBeNull()
        ->and(find_share_token($this->db, $expiring, $this->now + 3600))->toBeNull()
        ->and(find_share_token($this->db, str_repeat('a', 64), $this->now))->toBeNull()
        ->and(find_share_token($this->db, create_share_token($this->db, $this->site, [], '', null, $this->now)['token'], $this->now + 10 * 365 * 86400))->not->toBeNull()
        ->and(find_share_token($this->db, 'vaka_' . str_repeat('a', 64), $this->now))->toBeNull()
        ->and(find_share_token($this->db, 'not a token', $this->now))->toBeNull();

    revoke_share_token($this->db, (int) find_share_token($this->db, $group, $this->now)['id']);
    expect(find_share_token($this->db, $group, $this->now))->toBeNull();
});

test('a token made before the vaka_ prefix still works', function () {
    $old = str_repeat('ab', 32);
    $this->db->prepare('INSERT INTO share_tokens (token_hash, prefix, site_id, label, expires_at, created_at) VALUES (?, ?, ?, ?, ?, ?)')
        ->execute([hash('sha256', $old), substr($old, 0, 8), $this->site, '', utc_now($this->now + 86400), utc_now($this->now)]);
    expect(find_share_token($this->db, $old, $this->now))->toMatchArray(['site_id' => $this->site]);
});

test('share_token_expired_on: when a known token stopped working; nothing for a valid, unknown or revoked one', function () {
    $expired = create_share_token($this->db, $this->site, [], '', utc_now($this->now - 1), $this->now - 86400)['token'];
    $valid = create_share_token($this->db, $this->site, [], '', utc_now($this->now + 3600), $this->now)['token'];
    $revoked = create_share_token($this->db, $this->site, [], '', utc_now($this->now - 1), $this->now - 86400);
    revoke_share_token($this->db, $revoked['id']);

    expect(share_token_expired_on($this->db, $expired, $this->now))->toBe(utc_now($this->now - 1))
        ->and(share_token_expired_on($this->db, $valid, $this->now))->toBeNull()
        ->and(share_token_expired_on($this->db, create_share_token($this->db, $this->site, [], '', null, $this->now)['token'], $this->now))->toBeNull()
        ->and(share_token_expired_on($this->db, $revoked['token'], $this->now))->toBeNull()
        ->and(share_token_expired_on($this->db, 'vaka_' . str_repeat('0', 64), $this->now))->toBeNull()
        ->and(share_token_expired_on($this->db, 'nonsense', $this->now))->toBeNull();
});

test('a token remembers when it was last used, at most once a minute', function () {
    $token = create_share_token($this->db, $this->site, [], '', utc_now($this->now + 86400), $this->now)['token'];
    $used = fn () => $this->db->query('SELECT last_used_at FROM share_tokens')->fetchColumn();
    expect($used())->toBeNull();

    find_share_token($this->db, $token, $this->now);
    expect($used())->toBe('2026-10-26T12:00:00Z');
    find_share_token($this->db, $token, $this->now + 30);
    expect($used())->toBe('2026-10-26T12:00:00Z');
    find_share_token($this->db, $token, $this->now + 90);
    expect($used())->toBe('2026-10-26T12:01:30Z');
});

test('a token goes with its site or group', function () {
    create_share_token($this->db, $this->site, [], '', utc_now($this->now + 86400), $this->now);
    create_share_token($this->db, null, [$this->other], '', utc_now($this->now + 86400), $this->now);
    remove_site($this->db, $this->site);
    remove_group($this->db, $this->other);
    expect((int) $this->db->query('SELECT COUNT(*) FROM share_tokens')->fetchColumn())->toBe(0);
});

test('a token for several groups keeps the others when one is removed, and goes with the last', function () {
    $third = add_group($this->db, ['name' => 'Agency']);
    $token = create_share_token($this->db, null, [$this->group, $this->other], '', utc_now($this->now + 86400), $this->now)['token'];
    create_share_token($this->db, null, [$third], '', utc_now($this->now + 86400), $this->now);

    remove_group($this->db, $this->other);
    expect(find_share_token($this->db, $token, $this->now)['groups'])->toBe([['id' => $this->group, 'name' => 'Customers']]);

    remove_group($this->db, $this->group);
    expect(find_share_token($this->db, $token, $this->now))->toBeNull()
        ->and((int) $this->db->query('SELECT COUNT(*) FROM share_tokens')->fetchColumn())->toBe(1);
});

test('share_scope: a site token reads its site, a group token the sites in its groups', function () {
    $sites = fn (array $token) => array_column(overview_rows($this->db, share_scope($token)['where']), 'url');
    $group = find_share_token($this->db, create_share_token($this->db, null, [$this->group], '', utc_now($this->now + 86400), $this->now)['token'], $this->now);
    $both = find_share_token($this->db, create_share_token($this->db, null, [$this->other, $this->group], '', utc_now($this->now + 86400), $this->now)['token'], $this->now);
    $site = find_share_token($this->db, create_share_token($this->db, $this->site, [], '', utc_now($this->now + 86400), $this->now)['token'], $this->now);

    expect($sites($group))->toBe(['https://cafe.example', 'https://shop.example'])
        ->and(share_scope($group))->toMatchArray(['type' => 'group', 'name' => 'Customers', 'groups' => ['Customers']])
        ->and($sites($both))->toBe(['https://cafe.example', 'https://shop.example', 'https://example.org'])
        ->and(share_scope($both))->toMatchArray(['type' => 'group', 'name' => 'Customers, Own sites', 'groups' => ['Customers', 'Own sites']])
        ->and(share_target($both))->toBe('groups Customers and Own sites')
        ->and($sites($site))->toBe(['https://shop.example'])
        ->and(share_scope($site))->toMatchArray(['type' => 'site', 'name' => 'shop.example'])
        ->and(share_scope($site))->not->toHaveKey('groups');
});

test('a token for a group without sites reads nothing, never every site', function () {
    $empty = add_group($this->db, ['name' => 'Empty']);
    $token = find_share_token($this->db, create_share_token($this->db, null, [$empty], '', utc_now($this->now + 86400), $this->now)['token'], $this->now);
    expect(overview_rows($this->db, share_scope($token)['where']))->toBe([]);
});

test('tokens made before tokens for several groups keep what they read', function () {
    $file = temp_dir() . '/old.sqlite';
    $db = new PDO('sqlite:' . $file);
    $db->exec('PRAGMA foreign_keys = ON');
    run_migrations($db, array_filter(migrations(), fn ($version) => $version <= 8, ARRAY_FILTER_USE_KEY));
    $db->exec("INSERT INTO groups (id, name, created_at) VALUES (4, 'Customers', '2026-10-01T00:00:00Z')");
    $db->exec("INSERT INTO sites (id, group_id, url, created_at) VALUES (7, 4, 'https://shop.example', '2026-10-01T00:00:00Z')");
    $db->exec("INSERT INTO share_tokens (id, token_hash, prefix, group_id, label, expires_at, last_used_at, created_at) VALUES (1, 'aa', 'vaka_aa', 4, 'Bot', NULL, '2026-10-20T00:00:00Z', '2026-10-01T00:00:00Z')");
    $db->exec("INSERT INTO share_tokens (id, token_hash, prefix, site_id, label, expires_at, created_at) VALUES (2, 'bb', 'vaka_bb', 7, '', '2027-01-01T00:00:00Z', '2026-10-01T00:00:00Z')");
    $db = null;

    $db = get_db($file);
    $tokens = array_column(list_share_tokens($db), null, 'id');
    expect($tokens[1])->toMatchArray(['site_id' => null, 'label' => 'Bot', 'last_used_at' => '2026-10-20T00:00:00Z', 'groups' => [['id' => 4, 'name' => 'Customers']]])
        ->and($tokens[2])->toMatchArray(['site_id' => 7, 'expires_at' => '2027-01-01T00:00:00Z', 'groups' => []])
        ->and(array_column($db->query('PRAGMA table_info(share_tokens)')->fetchAll(), 'name'))->not->toContain('group_id');

    // Still with their group and site: removing them takes the tokens along
    remove_group($db, 4);
    remove_site($db, 7);
    expect((int) $db->query('SELECT COUNT(*) FROM share_tokens')->fetchColumn())->toBe(0);
});

test('share_expiry: the end of the chosen day in local time; a passed day or another format is refused', function () {
    expect(share_expiry('2026-12-31', 'Europe/Stockholm', $this->now))->toBe('2026-12-31T23:00:00Z')
        ->and(share_expiry('2026-10-26', 'Europe/Stockholm', $this->now))->toBe('2026-10-26T23:00:00Z')
        ->and(fn () => share_expiry('31/12/2026', 'Europe/Stockholm', $this->now))->toThrow(InvalidArgumentException::class, 'YYYY-MM-DD')
        ->and(fn () => share_expiry('2026-02-30', 'Europe/Stockholm', $this->now))->toThrow(InvalidArgumentException::class, 'YYYY-MM-DD')
        ->and(fn () => share_expiry('2026-10-25', 'Europe/Stockholm', $this->now))->toThrow(InvalidArgumentException::class, 'has passed');
});

test('share_default_day: a new token works for 90 days unless something else is chosen', function () {
    expect(share_default_day('Europe/Stockholm', $this->now))->toBe('2027-01-24')
        ->and(share_default_day('Pacific/Auckland', strtotime('2026-10-26T12:30:00Z')))->toBe('2027-01-25');
});

// =====================================================================
// The command line
// =====================================================================

test('share:create shows the token once, in the lines for .env; share:list shows only its start; share:revoke ends it', function () {
    $env = cli_env() + ['APP_URL' => 'https://vaka.example.com'];
    vaka(['group:add', 'Customers'], $env);
    vaka(['site:add', 'https://shop.example', '--group=Customers'], $env);

    $r = vaka(['share:create', 'Customers', '--label=AI assistant', '--expires=2099-12-31'], $env);
    expect($r['exit'])->toBe(0, $r['err'])
        ->and(preg_match('/\b(vaka_[a-f0-9]{64})\b/', $r['out'], $m))->toBe(1);
    $token = $m[1];
    $prefix = substr($token, 0, 13);
    expect($r['out'])->toContain('Read token for group Customers (AI assistant), valid through 31 Dec 2099.')
        ->and(substr_count($r['out'], $token))->toBe(1)
        ->and($r['out'])->toContain("  VAKA_URL=https://vaka.example.com\n  VAKA_SHARE_TOKEN={$token}\n")
        ->and($r['out'])->toContain('never in CLAUDE.md')
        ->and($r['out'])->toContain('git check-ignore .env')
        ->and($r['out'])->toContain('PUBLIC_, VITE_ or NEXT_PUBLIC_')
        ->and($r['out'])->toContain('(set -a; . ./.env; curl -s -H "Authorization: Bearer $VAKA_SHARE_TOKEN" "$VAKA_URL/?api&format=md")')
        ->and($r['out'])->toContain('without &format=md the same data comes as JSON')
        ->and($r['out'])->toContain('shown only once')
        ->and($r['out'])->toContain("php vaka share:revoke {$prefix}")
        ->and($r['out'])->not->toContain('Set APP_URL');

    $list = vaka(['share:list'], $env)['out'];
    expect($list)->toContain('group Customers')
        ->and($list)->toContain('AI assistant')
        ->and($list)->toContain($prefix . '…')
        ->and($list)->toContain('31 Dec 2099')
        ->and($list)->toContain('never')
        ->and($list)->not->toContain($token);

    $r = vaka(['share:revoke', $prefix], $env);
    expect($r['exit'])->toBe(0, $r['err'])
        ->and($r['out'])->toContain('Revoked the read token for group Customers (AI assistant)');
    expect(vaka(['share:list'], $env)['out'])->toContain('No read tokens');
});

test('share:create --groups makes one token for several groups, which reads sites added to them later', function () {
    $env = cli_env();
    vaka(['group:add', 'Customers'], $env);
    vaka(['group:add', 'Own'], $env);
    vaka(['site:add', 'https://shop.example', '--group=Customers'], $env);

    $r = vaka(['share:create', '--groups=customers, Own', '--label=Report bot', '--expires=2099-12-31'], $env);
    expect($r['exit'])->toBe(0, $r['err'])
        ->and($r['out'])->toContain('Read token for groups Customers and Own (Report bot), valid through 31 Dec 2099.')
        ->and($r['out'])->toContain('It reads every site in these groups, including sites added later.')
        ->and(vaka(['share:list'], $env)['out'])->toContain('groups Customers and Own');

    expect(vaka(['share:create', 'Customers', '--expires=2099-12-31', '--label='], $env)['out'])->toContain('It reads every site in the group, including sites added later.')
        ->and(vaka(['share:create', 'shop.example', '--expires=2099-12-31', '--label='], $env)['out'])->not->toContain('sites added later');
});

test('share:create --groups refuses an empty list, an unknown group, and a site or group next to it', function () {
    $env = cli_env();
    vaka(['group:add', 'Customers'], $env);
    foreach (['--groups=', '--groups', '--groups= , '] as $flag) {
        $r = vaka(['share:create', $flag, '--expires=2099-12-31', '--label='], $env);
        expect($r['exit'])->toBe(1)
            ->and($r['err'])->toContain('--groups is empty. Give group names separated by commas.');
    }
    expect(vaka(['share:create', '--groups=Customers,Nobody', '--expires=2099-12-31', '--label='], $env)['err'])->toContain('No group called "Nobody"')
        ->and(vaka(['share:create', 'Customers', '--groups=Customers', '--expires=2099-12-31', '--label='], $env)['err'])->toContain('Give a group or site, or --groups, not both.')
        ->and(vaka(['share:list'], $env)['out'])->toContain('No read tokens yet');
});

test('share:create at a terminal picks one site, or one or more groups, from one list', function () {
    $env = cli_env();
    vaka(['group:add', 'Customers'], $env);
    vaka(['group:add', 'Own'], $env);
    vaka(['site:add', 'https://shop.example', '--group=Customers'], $env);

    $r = vaka(['share:create', '--label=', '--expires=2099-12-31'], $env, "1-2\n");
    expect($r['exit'])->toBe(0, $r['err'])
        ->and($r['out'])->toContain("What is the token for? One site, or one or more groups:\n  1) The group Customers (1 site)\n  2) The group Own (no sites)\n  3) shop.example (Customers)")
        ->and($r['out'])->toContain('Numbers, comma-separated or ranges like 3-7 (several groups like 1,2)')
        ->and($r['out'])->toContain('Read token for groups Customers and Own, valid through 31 Dec 2099.');

    expect(vaka(['share:create', '--label=', '--expires=2099-12-31'], $env, "3\n")['out'])->toContain('Read token for shop.example, valid through')
        ->and(vaka(['share:create', '--label=', '--expires=2099-12-31'], $env, "1,3\n")['err'])->toContain('A token reads one site, or one or more groups.');
});

test('share:revoke takes the start of a token with or without vaka_, or the whole token', function () {
    $env = cli_env();
    vaka(['site:add', 'https://shop.example'], $env);
    $token = fn () => preg_match('/(vaka_[a-f0-9]{64})/', vaka(['share:create', 'shop.example', '--label=', '--expires='], $env)['out'], $m) ? $m[1] : '';

    foreach ([fn ($t) => substr($t, 5, 8), fn ($t) => substr($t, 0, 13), fn ($t) => $t] as $ref) {
        $r = vaka(['share:revoke', $ref($token())], $env);
        expect($r['exit'])->toBe(0, $r['err'])->and($r['out'])->toContain('Revoked the read token for shop.example');
    }
    expect(vaka(['share:list'], $env)['out'])->toContain('No read tokens');
});

test('share:create works for 90 days unless told otherwise; never is a choice of its own', function () {
    $env = cli_env();
    vaka(['site:add', 'https://shop.example'], $env);

    $r = vaka(['share:create', 'shop.example', '--label='], $env);
    expect($r['exit'])->toBe(0, $r['err'])
        ->and($r['out'])->toContain('Last day it works, YYYY-MM-DD or never (Enter: ')
        ->and($r['out'])->toContain('in 90 days)')
        ->and($r['out'])->toMatch('/Read token for shop\.example, valid through \d{1,2} \w{3} \d{4}\./');
    expect(vaka(['share:create', 'shop.example', '--label=', '--expires='], $env)['out'])->toMatch('/valid through \d{1,2} \w{3} \d{4}\./');

    $r = vaka(['share:create', 'shop.example', '--label=Never', '--expires=never'], $env);
    expect($r['exit'])->toBe(0, $r['err'])
        ->and($r['out'])->toContain('Read token for shop.example (Never), never expires.')
        ->and($r['out'])->not->toContain('Last day it works');
    expect(vaka(['share:list'], $env)['out'])->toMatch('/Never\s+vaka_[a-f0-9]{8}…\s+never\s/');
});

test('share:create for a site, without APP_URL, says where to set it', function () {
    $env = cli_env();
    vaka(['site:add', 'https://shop.example'], $env);
    $r = vaka(['share:create', 'shop.example', '--label=', '--expires=2099-12-31'], $env);
    expect($r['exit'])->toBe(0, $r['err'])
        ->and($r['out'])->toContain('Read token for shop.example, valid through 31 Dec 2099.')
        ->and($r['out'])->toContain('VAKA_URL=https://your-vaka.example')
        ->and($r['out'])->toContain('"$VAKA_URL/?api&format=md")')
        ->and($r['out'])->toContain("Set APP_URL in Vaka's .env");
});

test('share commands explain what they cannot do', function () {
    $env = cli_env();
    vaka(['site:add', 'https://shop.example'], $env);
    expect(vaka(['share:create', 'nothing.example'], $env)['err'])->toContain('No group or site matches "nothing.example"')
        ->and(vaka(['share:create', 'shop.example', '--expires=2001-01-01'], $env)['err'])->toContain('has passed')
        ->and(vaka(['share:list'], $env)['out'])->toContain('No read tokens yet. Create one with `php vaka share:create <group or site>`')
        ->and(vaka(['share:revoke'], $env)['err'])->toContain('There are no read tokens to revoke')
        ->and(vaka(['share:create', 'shop.example', '--expires=someday'], $env)['err'])->toContain('YYYY-MM-DD')
        ->and(vaka(['share:revoke', 'abcdef12'], vaka_with_token($env))['err'])->toContain('No read token starts with "abcdef12"')
        ->and(vaka(['share:revoke', 'vaka_'], vaka_with_token($env))['err'])->toContain('No read token starts with "vaka_"');
});

test('help lists the share commands', function () {
    $out = vaka(['help'])['out'];
    foreach (['share:create', 'share:list', 'share:revoke'] as $command) expect($out)->toContain($command);
});

/** $env after creating one read token for shop.example. */
function vaka_with_token(array $env): array
{
    vaka(['share:create', 'shop.example', '--label=', '--expires='], $env);
    return $env;
}
