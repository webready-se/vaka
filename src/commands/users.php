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
    if ($confirm && $read('Again, to be sure: ') !== $password) fail('The passwords do not match.');
    return $password;
}

function new_password(string $label): string
{
    $password = read_password($label, true);
    if (text_length($password) < MIN_PASSWORD_LENGTH) fail('Passwords are at least ' . MIN_PASSWORD_LENGTH . ' characters.');
    return hash_password($password);
}

/** Group names from "a,b" as they are stored; every one must exist. Empty means every site. */
function group_names(PDO $db, string $list): array
{
    $names = [];
    foreach (array_filter(array_map('trim', explode(',', $list))) as $name) {
        $group = find_group($db, $name);
        if (!$group) fail("No group called \"{$name}\". See `php vaka group:list`.");
        $names[] = $group['name'];
    }
    return array_values(array_unique($names));
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
    $name = choose('Which user?', array_combine(array_keys($users), array_keys($users)));
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
        $groups = group_names($db, (string) $opts['groups']);
    } elseif (!isset($args[1]) && list_groups($db)) {
        $groups = group_names($db, ask('Groups this user may see, comma-separated (Enter for every site)'));
    } else {
        $groups = [];
    }

    $users[$name] = ['password' => new_password("Password for {$name}: "), 'groups' => $groups];
    save_users($config['users_file'], $users);
    echo "Added user {$name}, who sees " . describe_access($groups) . ".\n";
}

function cmd_user_edit(array $config, array $args, array $opts): void
{
    allow_options($opts, ['groups', 'password']);
    $db = get_db($config['db_path']);
    $users = load_users($config['users_file']);
    $name = pick_user($users, $args[1] ?? null);

    if (!$opts) {
        $groups = ask_optional('Groups this user may see', implode(', ', $users[$name]['groups'] ?? []), skip: 'Enter for every site');
        $users[$name]['groups'] = group_names($db, $groups);
        if (confirm('Set a new password?')) $users[$name]['password'] = new_password("New password for {$name}: ");
    }
    if (isset($opts['groups'])) $users[$name]['groups'] = group_names($db, $opts['groups'] === true ? '' : (string) $opts['groups']);
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
