<?php

/**
 * The UTC expiry for what a person typed: a YYYY-MM-DD, nothing for
 * SHARE_DEFAULT_DAYS from today, or "never" (null); else a failure that says
 * what to type. Never is a choice of its own, so a forgotten token stops
 * working unless someone decided it should not.
 */
function share_expiry_or_fail(string $date, array $config): ?string
{
    $date = trim($date);
    if (strtolower($date) === 'never') return null;
    try {
        return share_expiry($date !== '' ? $date : share_default_day($config['timezone'], time()), $config['timezone'], time());
    } catch (InvalidArgumentException $e) {
        fail($e->getMessage() . '.');
    }
}

function cmd_share_create(array $config, array $args, array $opts): void
{
    allow_options($opts, ['label', 'expires']);
    $db = get_db($config['db_path']);
    [$siteId, $groupId, $name] = group_or_site($db, $args[1] ?? pick_group_or_site($db));
    // A date given as an option is checked before anything is asked
    $expiresAt = isset($opts['expires']) ? share_expiry_or_fail($opts['expires'] === true ? '' : $opts['expires'], $config) : '';
    $label = isset($opts['label']) ? ($opts['label'] === true ? '' : $opts['label']) : ask_optional('Label, e.g. AI assistant or the customer it is for');
    if (!isset($opts['expires'])) {
        $default = share_default_day($config['timezone'], time());
        $expiresAt = share_expiry_or_fail(prompt('Last day it works, YYYY-MM-DD or never (Enter: ' . $default . ', in ' . SHARE_DEFAULT_DAYS . ' days): '), $config);
    }

    try {
        $created = create_share_token($db, $siteId, $groupId, $label, $expiresAt, time());
    } catch (InvalidArgumentException $e) {
        fail($e->getMessage() . '.');
    }
    $token = $created['token'];
    $base = $config['app_url'] !== '' ? $config['app_url'] : 'https://your-vaka.example';

    // The token is printed once, in the lines to copy, and read back from .env
    // after that, so an AI assistant that runs the command never sees it
    echo "Read token for {$name}" . ($label !== '' ? " ({$label})" : '') . ', ' . ($expiresAt === null ? 'never expires' : 'valid through ' . share_expires_words($expiresAt, $config['timezone'])) . ".\n\n";
    echo "Put these two lines in the .env of the project that reads it, such as the site's own; never in CLAUDE.md or another file that is committed:\n\n";
    echo "  VAKA_URL={$base}\n";
    echo "  VAKA_SHARE_TOKEN={$token}\n\n";
    if ($config['app_url'] === '') echo "(Set APP_URL in Vaka's .env to see Vaka's own address here.)\n\n";
    echo "Check that git ignores the file: `git check-ignore .env` answers .env when it does.\n";
    echo "Keep the names as they are: a PUBLIC_, VITE_ or NEXT_PUBLIC_ prefix puts the token in the code sent to visitors' browsers.\n\n";
    echo "Read it from that project (the token stays in .env, so an AI assistant never sees it):\n\n";
    echo "  (set -a; . ./.env; curl -s -H \"Authorization: Bearer \$VAKA_SHARE_TOKEN\" \"\$VAKA_URL/?api\")\n\n";
    echo "It is shown only once: Vaka keeps a fingerprint of it, not the token. Lost it? Create a new one and revoke this one.\n";
    echo "The fields are described in docs/api.md. Revoke it with `php vaka share:revoke " . substr($token, 0, SHARE_PREFIX_LENGTH) . "`.\n";
}

function cmd_share_list(array $config, array $args, array $opts): void
{
    allow_options($opts, []);
    $tokens = list_share_tokens(get_db($config['db_path']));
    if (!$tokens) {
        echo "No read tokens yet. Create one with `php vaka share:create <group or site>`.\n";
        return;
    }
    $now = time();
    print_table(['#', 'Reads', 'Label', 'Token', 'Works through', 'Last used', 'Created'], array_map(fn (array $token) => [
        $token['id'],
        share_target($token),
        $token['label'],
        $token['prefix'] . '…',
        share_expires_words($token['expires_at'], $config['timezone']) . ($token['expires_at'] !== null && $token['expires_at'] <= utc_now($now) ? ' (expired)' : ''),
        $token['last_used_at'] === null ? 'never' : ago($token['last_used_at'], $now),
        (new DateTimeImmutable($token['created_at']))->setTimezone(new DateTimeZone($config['timezone']))->format('j M Y'),
    ], $tokens));
    echo "\nOnly the start of each token is kept in the clear. Revoke one with `php vaka share:revoke <#>`.\n";
}

function cmd_share_revoke(array $config, array $args, array $opts): void
{
    allow_options($opts, []);
    $db = get_db($config['db_path']);
    $tokens = array_column(list_share_tokens($db), null, 'id');
    if (!$tokens) fail('There are no read tokens to revoke.');

    $ref = (string) ($args[1] ?? choose('Which token?', array_map(fn (array $token) => share_target($token) . ($token['label'] !== '' ? " ({$token['label']})" : '') . ', ' . $token['prefix'] . '…', $tokens)));
    if (ctype_digit($ref) && isset($tokens[(int) $ref])) {
        $token = $tokens[(int) $ref];
    } else {
        // The start of the token, as share:list shows it (vaka_ may be left out), or the whole token
        $ref = strtolower(rtrim($ref, '.…'));
        $bare = fn (string $start) => str_starts_with($start, SHARE_TOKEN_PREFIX) ? substr($start, strlen(SHARE_TOKEN_PREFIX)) : $start;
        $matches = array_values(array_filter($tokens, fn (array $token) => is_share_token($ref)
            ? hash_equals($token['token_hash'], share_token_hash($ref))
            : $bare($ref) !== '' && str_starts_with($bare($token['prefix']), $bare($ref))));
        if (count($matches) > 1) fail("More than one read token starts with \"{$ref}\". Use its number from `php vaka share:list`.");
        $token = $matches[0] ?? fail("No read token starts with \"{$ref}\". See `php vaka share:list`.");
    }

    revoke_share_token($db, (int) $token['id']);
    echo 'Revoked the read token for ' . share_target($token) . ($token['label'] !== '' ? " ({$token['label']})" : '') . ". Whatever used it now gets 404.\n";
}
