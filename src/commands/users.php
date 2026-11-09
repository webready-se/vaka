<?php

/** Read a password without echoing it on a terminal; from a pipe (scripts, tests) a single line is read. */
function read_password(string $label, bool $confirm): string
{
    if (!stream_isatty(STDIN)) {
        echo $label . "\n";
        return rtrim((string) fgets(STDIN), "\r\n");
    }
    $read = function (string $label): string {
        echo $label;
        system('stty -echo');
        $password = rtrim((string) fgets(STDIN), "\r\n");
        system('stty echo');
        echo "\n";
        return $password;
    };
    $password = $read($label);
    // Nothing typed means "make one for me": nothing to repeat
    if ($confirm && $password !== '' && $read('Again, to be sure: ') !== $password) fail('The passwords do not match.');
    return $password;
}

/** Ask for a new password, or make one when Enter is pressed on an empty line, and return its hash. */
function new_password(string $label): string
{
    $password = read_password(rtrim($label, ': ') . ' (Enter for a generated one): ', true);
    if ($password === '') {
        $password = generate_password();
        echo "Generated password: {$password}\nCopy it now: Vaka keeps only a hash of it, so it cannot show it again.\n";
    }
    if (text_length($password) < MIN_PASSWORD_LENGTH) fail('Passwords are at least ' . MIN_PASSWORD_LENGTH . ' characters.');
    return hash_password($password);
}

/**
 * The groups in --groups=a,b as stored, every one checked. Empty is refused,
 * so a script's empty variable never means every site; $else says what else
 * may be given.
 *
 * @return list<array> the groups' rows
 */
function groups_option(PDO $db, string|bool $list, string $else = ''): array
{
    $groups = [];
    foreach (array_filter(array_map('trim', explode(',', is_string($list) ? $list : ''))) as $name) {
        $group = find_group($db, $name);
        if (!$group) fail("No group called \"{$name}\". See `php vaka group:list`.");
        $groups[$group['id']] = $group;
    }
    if (!$groups) fail("--groups is empty. Give group names separated by commas{$else}.");
    return array_values($groups);
}

/** The groups a user may see, from --groups=a,b, or [] for every site, which takes the word "all" (a group called all wins, since it shows less). */
function group_names(PDO $db, string|bool $list): array
{
    if (is_string($list) && strcasecmp(trim($list), 'all') === 0 && !find_group($db, $list)) return [];
    return array_column(groups_option($db, $list, ', or `--groups=all` for every site'), 'name');
}

function describe_access(array $groups): string
{
    if (!$groups) return 'every site';
    return (count($groups) === 1 ? 'the group ' : 'the groups ') . list_words($groups);
}

function pick_user(array $users, ?string $name): string
{
    if ($name !== null) {
        $name = strtolower(trim($name));
        if (!isset($users[$name])) fail("No user called \"{$name}\". See `php vaka user:list`.");
        return $name;
    }
    if (!$users) fail('No users yet. Add one with `php vaka user:add <name>`.');
    $options = [];
    foreach ($users as $user => $entry) $options[$user] = "{$user} (sees " . describe_access($entry['groups'] ?? []) . ')';
    $name = choose('Which user?', $options);
    if ($name === null) fail('No user chosen.');
    return $name;
}

function cmd_user_add(array $config, array $args, array $opts): void
{
    allow_options($opts, ['groups']);
    $db = get_db($config['db_path']);
    $users = load_users($config['users_file']);
    try {
        $name = clean_username($args[1] ?? ask('User name'));
    } catch (InvalidArgumentException $e) {
        fail($e->getMessage() . '.');
    }
    if (isset($users[$name])) fail("User {$name} already exists. Change it with `php vaka user:edit {$name}`.");

    if (isset($opts['groups'])) {
        $groups = group_names($db, $opts['groups']);
    } elseif (!isset($args[1]) && ($all = list_groups($db))) {
        $picked = choose_many('Which groups may ' . $name . ' see?', array_column($all, 'name', 'name'), '0 or Enter for every site');
        $groups = is_array($picked) ? $picked : [];
    } else {
        $groups = [];
    }

    $users[$name] = ['password' => new_password("Password for {$name}: "), 'groups' => $groups];
    save_users($config['users_file'], $users);
    echo "Added user {$name}, who sees " . describe_access($groups) . '. ' . ($config['app_url'] !== '' ? "Sign in at {$config['app_url']}" : 'Sign in on the dashboard.') . "\n";
}

function cmd_user_edit(array $config, array $args, array $opts): void
{
    allow_options($opts, ['groups', 'password']);
    $db = get_db($config['db_path']);
    $users = load_users($config['users_file']);
    $name = pick_user($users, $args[1] ?? null);

    if (!$opts) {
        $current = $users[$name]['groups'] ?? [];
        $answer = prompt('Groups this user may see ' . ($current ? '[' . implode(', ', $current) . '] (Enter keeps them, all for every site): ' : '(Enter for every site): '));
        $users[$name]['groups'] = $answer === '' ? $current : group_names($db, $answer);
        if (confirm('Set a new password?')) $users[$name]['password'] = new_password("New password for {$name}: ");
    }
    if (isset($opts['groups'])) $users[$name]['groups'] = group_names($db, $opts['groups']);
    if (isset($opts['password'])) $users[$name]['password'] = new_password("New password for {$name}: ");

    save_users($config['users_file'], $users);
    echo "Updated user {$name}, who sees " . describe_access($users[$name]['groups']) . ".\n";
}

function cmd_user_remove(array $config, array $args, array $opts): void
{
    allow_options($opts, ['force']);
    $users = load_users($config['users_file']);
    $name = pick_user($users, $args[1] ?? null);
    if (empty($opts['force']) && !confirm("Remove user {$name}?")) {
        echo "Nothing removed.\n";
        exit(1);
    }
    unset($users[$name]);
    save_users($config['users_file'], $users);
    echo "Removed user {$name}.\n";
}

function cmd_user_list(array $config, array $args, array $opts): void
{
    allow_options($opts, []);
    $users = load_users($config['users_file']);
    if (!$users) {
        echo "No users yet. Add one with `php vaka user:add <name>`.\n";
        return;
    }
    ksort($users);
    print_table(['User', 'Sees'], array_map(fn ($name, $user) => [$name, $user['groups'] ? implode(', ', $user['groups']) : 'every site'], array_keys($users), $users));
}
