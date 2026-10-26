<?php

/** The UTC expiry for a YYYY-MM-DD typed by a person, null for none, or a failure that says what to type. */
function share_expiry_or_fail(string $date, array $config): ?string
{
    if (trim($date) === '') return null;
    try {
        return share_expiry($date, $config['timezone'], time());
    } catch (InvalidArgumentException $e) {
        fail($e->getMessage() . '.');
    }
}

/** "31 Dec 2026", the last day a token works, or "never". */
function share_expires_words(?string $expiresAt, string $timezone): string
{
    if ($expiresAt === null) return 'never';
    return (new DateTimeImmutable('@' . (strtotime($expiresAt) - 1)))->setTimezone(new DateTimeZone($timezone))->format('j M Y');
}

function cmd_share_create(array $config, array $args, array $opts): void
{
    allow_options($opts, ['label', 'expires']);
    $db = get_db($config['db_path']);
    [$siteId, $groupId, $name] = group_or_site($db, $args[1] ?? pick_group_or_site($db));
    // A date given as an option is checked before anything is asked
    $expiresAt = isset($opts['expires']) ? share_expiry_or_fail($opts['expires'] === true ? '' : $opts['expires'], $config) : null;
    $label = isset($opts['label']) ? ($opts['label'] === true ? '' : $opts['label']) : ask_optional('Label, e.g. AI assistant or the customer it is for');
    if (!isset($opts['expires'])) $expiresAt = share_expiry_or_fail(ask_optional('Last day it works, YYYY-MM-DD', '', false, 'Enter: it never expires'), $config);

    try {
        $created = create_share_token($db, $siteId, $groupId, $label, $expiresAt, time());
    } catch (InvalidArgumentException $e) {
        fail($e->getMessage() . '.');
    }
    $token = $created['token'];
    $base = $config['app_url'] !== '' ? $config['app_url'] : 'https://your-vaka.example';
    $expiry = $expiresAt === null ? 'never expires' : 'valid through ' . share_expires_words($expiresAt, $config['timezone']);

    echo "Read token for {$name}" . ($label !== '' ? " ({$label})" : '') . ", {$expiry}:\n\n";
    echo "  {$token}\n\n";
    echo "It is shown only once: Vaka keeps a fingerprint of it, not the token. Lost it? Create a new one and revoke this one.\n\n";
    echo "Read it with:\n";
    // A site token reads its one site in detail; a group token the list
    $query = $siteId !== null ? '?api&site=' . rawurlencode($name) : '?api';
    echo "  curl -s -H \"Authorization: Bearer {$token}\" \"{$base}/{$query}\"\n";
    if ($config['app_url'] === '') echo "  (Set APP_URL in .env to see Vaka's own address here.)\n";
    echo "\nFor an AI assistant in the site's own project, put these in its .env (never in CLAUDE.md or other files that are committed):\n";
    echo "  VAKA_URL={$base}\n";
    echo "  VAKA_SHARE_TOKEN={$token}\n";
    echo "\nThe fields are described in docs/api.md. Revoke the token with `php vaka share:revoke " . substr($token, 0, SHARE_PREFIX_LENGTH) . "`.\n";
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
        // The start of the token, as share:list shows it, or the whole token
        $ref = strtolower(rtrim($ref, '.…'));
        $matches = array_values(array_filter($tokens, fn (array $token) => strlen($ref) === 64 ? hash_equals($token['token_hash'], share_token_hash($ref)) : ($ref !== '' && str_starts_with($token['prefix'], $ref))));
        if (count($matches) > 1) fail("More than one read token starts with \"{$ref}\". Use its number from `php vaka share:list`.");
        $token = $matches[0] ?? fail("No read token starts with \"{$ref}\". See `php vaka share:list`.");
    }

    revoke_share_token($db, (int) $token['id']);
    echo 'Revoked the read token for ' . share_target($token) . ($token['label'] !== '' ? " ({$token['label']})" : '') . ". Whatever used it now gets 404.\n";
}
