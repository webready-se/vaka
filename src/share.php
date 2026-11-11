<?php

/**
 * Read tokens for the API (docs/api.md): one per site or groups, read-only,
 * working for 90 days unless another last day, or never, is chosen. The
 * token is shown once when it is made; Vaka keeps only its SHA-256 and its
 * first characters, to tell tokens apart.
 */

/** The start of every new token, so a person or a secret scanner can tell a token is Vaka's. */
const SHARE_TOKEN_PREFIX = 'vaka_';

/** Characters of a token kept as is, to recognise it in share:list: vaka_ and eight more. */
const SHARE_PREFIX_LENGTH = 13;

/** Days a new token works when nothing else is chosen. */
const SHARE_DEFAULT_DAYS = 90;

function share_token_hash(string $token): string
{
    return hash('sha256', $token);
}

/** The start of a token as share:list shows it: vaka_ and eight characters, or eight for a token made before the prefix. */
function share_token_start(string $token): string
{
    return substr($token, 0, str_starts_with($token, SHARE_TOKEN_PREFIX) ? SHARE_PREFIX_LENGTH : 8);
}

/** Whether $token looks like a whole read token. Tokens made before the vaka_ prefix have none, and still work. */
function is_share_token(string $token): bool
{
    return (bool) preg_match('/^(' . preg_quote(SHARE_TOKEN_PREFIX, '/') . ')?[a-f0-9]{64}$/', $token);
}

/**
 * A new read token for a site, or for one or more groups: vaka_ and 64 hex
 * characters. One token per reader: a reader of two groups would keep two
 * tokens in the same .env, so a second one limits nothing. $expiresAt is UTC
 * (see share_expiry()), or null for one that never expires.
 *
 * @return array{id: int, token: string}
 */
function create_share_token(PDO $db, ?int $siteId, array $groupIds, string $label, ?string $expiresAt, int $now): array
{
    if (($siteId === null) === !$groupIds) throw new InvalidArgumentException('A token reads one site, or one or more groups');
    $token = SHARE_TOKEN_PREFIX . bin2hex(random_bytes(32));
    $db->beginTransaction();
    $db->prepare('INSERT INTO share_tokens (token_hash, prefix, site_id, label, expires_at, created_at) VALUES (?, ?, ?, ?, ?, ?)')
        ->execute([share_token_hash($token), share_token_start($token), $siteId, clean_text($label, 100, 'label'), $expiresAt, utc_now($now)]);
    $id = (int) $db->lastInsertId();
    $add = $db->prepare('INSERT OR IGNORE INTO share_groups (token_id, group_id) VALUES (?, ?)');
    foreach ($groupIds as $groupId) $add->execute([$id, (int) $groupId]);
    $db->commit();
    return ['id' => $id, 'token' => $token];
}

/**
 * Change a token's label, last day (expires_at, null for never) or groups
 * (ids, never none). The token itself stays the same, so whoever reads with
 * it changes nothing: one about to expire is extended where it is kept.
 */
function update_share_token(PDO $db, int $id, array $changes): void
{
    if (array_key_exists('label', $changes)) $changes['label'] = clean_text($changes['label'], 100, 'label');
    if (isset($changes['groups']) && !$changes['groups']) throw new InvalidArgumentException('A token for groups reads at least one');
    $db->beginTransaction();
    foreach (array_intersect_key($changes, ['label' => 1, 'expires_at' => 1]) as $column => $value) {
        $db->prepare("UPDATE share_tokens SET {$column} = ? WHERE id = ?")->execute([$value, $id]);
    }
    if (isset($changes['groups'])) {
        $db->prepare('DELETE FROM share_groups WHERE token_id = ?')->execute([$id]);
        $add = $db->prepare('INSERT OR IGNORE INTO share_groups (token_id, group_id) VALUES (?, ?)');
        foreach ($changes['groups'] as $groupId) $add->execute([$id, (int) $groupId]);
    }
    $db->commit();
}

/** Tokens' rows with their groups as [{id, name}], by name ([] for a site token). */
function with_share_groups(PDO $db, array $tokens): array
{
    if (!$tokens) return [];
    $ids = implode(', ', array_map(fn (array $token) => (int) $token['id'], $tokens));
    $groups = [];
    foreach ($db->query("SELECT sg.token_id, g.id, g.name FROM share_groups sg JOIN groups g ON g.id = sg.group_id WHERE sg.token_id IN ({$ids}) ORDER BY g.name COLLATE NOCASE, g.id") as $row) {
        $groups[$row['token_id']][] = ['id' => (int) $row['id'], 'name' => $row['name']];
    }
    return array_map(fn (array $token) => $token + ['groups' => $groups[$token['id']] ?? []], $tokens);
}

/**
 * The token's row with its site or groups, when the token is valid now, else
 * null. Records when it was last used (at most once a minute, so reading
 * stays cheap).
 */
function find_share_token(PDO $db, string $token, int $now): ?array
{
    if (!is_share_token($token)) return null;
    $stmt = $db->prepare('SELECT t.*, s.url AS site_url FROM share_tokens t
        LEFT JOIN sites s ON s.id = t.site_id
        WHERE t.token_hash = ?');
    $stmt->execute([share_token_hash($token)]);
    $row = $stmt->fetch();
    if (!$row || ($row['expires_at'] !== null && $row['expires_at'] <= utc_now($now))) return null;

    if ($row['last_used_at'] === null || $row['last_used_at'] <= utc_now($now - 60)) {
        $db->prepare('UPDATE share_tokens SET last_used_at = ? WHERE id = ?')->execute([utc_now($now), $row['id']]);
    }
    return with_share_groups($db, [$row])[0];
}

/** When a token Vaka knows stopped working (UTC), or null for one that works, or that it does not know. */
function share_token_expired_on(PDO $db, string $token, int $now): ?string
{
    if (!is_share_token($token)) return null;
    $stmt = $db->prepare('SELECT expires_at FROM share_tokens WHERE token_hash = ? AND expires_at <= ?');
    $stmt->execute([share_token_hash($token), utc_now($now)]);
    return $stmt->fetchColumn() ?: null;
}

/** "31 Dec 2026", the last day a token works, or "never". */
function share_expires_words(?string $expiresAt, string $timezone): string
{
    if ($expiresAt === null) return 'never';
    return (new DateTimeImmutable('@' . (strtotime($expiresAt) - 1)))->setTimezone(new DateTimeZone($timezone))->format('j M Y');
}

/** Every token, newest first, with its site or groups. */
function list_share_tokens(PDO $db): array
{
    return with_share_groups($db, $db->query('SELECT t.*, s.url AS site_url FROM share_tokens t
        LEFT JOIN sites s ON s.id = t.site_id
        ORDER BY t.created_at DESC, t.id DESC')->fetchAll());
}

function revoke_share_token(PDO $db, int $id): void
{
    $db->prepare('DELETE FROM share_tokens WHERE id = ?')->execute([$id]);
}

/**
 * Who reads each group, in words, by group id: the users limited to it
 * ("user anna") and the read tokens for it that work now ("read token #3
 * (Claude Code)", numbered as share:list numbers them). A login or a token
 * for a group follows its sites, so these are who a move shows a site to.
 * Users who see every site and tokens for one site are left out: a site that
 * changes group changes nothing for them.
 *
 * @return array<int, list<string>>
 */
function group_readers(PDO $db, string $usersFile, int $now): array
{
    $readers = [];
    $users = load_users($usersFile);
    ksort($users, SORT_NATURAL | SORT_FLAG_CASE);
    foreach ($users as $name => $user) {
        foreach ($user['groups'] ?? [] as $groupName) {
            if ($group = find_group($db, (string) $groupName)) $readers[(int) $group['id']][] = "user {$name}";
        }
    }
    $tokens = $db->prepare('SELECT sg.group_id, t.id, t.label FROM share_groups sg JOIN share_tokens t ON t.id = sg.token_id
        WHERE t.expires_at IS NULL OR t.expires_at > ? ORDER BY t.id');
    $tokens->execute([utc_now($now)]);
    foreach ($tokens as $row) $readers[(int) $row['group_id']][] = "read token #{$row['id']}" . ($row['label'] !== '' ? " ({$row['label']})" : '');
    // A user may name a group twice, in another case
    return array_map(fn (array $names) => array_values(array_unique($names)), $readers);
}

/**
 * Who sees sites, or no longer does, when they move from their groups
 * ($from: each one's group id, null for none) to $to (null for none), from
 * group_readers(). Never who reads both, and each name once.
 *
 * @return array{gain: list<string>, lose: list<string>}
 */
function readers_change(array $readers, array $from, ?int $to): array
{
    $now = $to === null ? [] : $readers[$to] ?? [];
    $gain = $lose = [];
    foreach (array_unique($from) as $group) {
        $before = $group === null ? [] : $readers[$group] ?? [];
        array_push($gain, ...array_diff($now, $before));
        array_push($lose, ...array_diff($before, $now));
    }
    return ['gain' => array_values(array_unique($gain)), 'lose' => array_values(array_unique($lose))];
}

/** What a token reads, in words: "group Customers", "groups Customers and Own" or "shop.example". */
function share_target(array $token): string
{
    if ($token['site_id'] !== null) return display_url((string) $token['site_url']);
    $names = array_column($token['groups'], 'name');
    return (count($names) === 1 ? 'group ' : 'groups ') . list_words($names);
}

/**
 * What a token may read: its type and name for the response (and for a
 * group token the names of its groups), the SQL condition on sites (s.) for
 * overview_rows(), and for a site token its site. A group token reads the
 * sites in its groups as they are now, and nothing when they have none.
 *
 * @return array{type: string, name: string, groups?: list<string>, where: string, site_id?: int}
 */
function share_scope(array $token): array
{
    if ($token['site_id'] !== null) {
        return ['type' => 'site', 'name' => display_url((string) $token['site_url']), 'where' => 's.id = ' . (int) $token['site_id'], 'site_id' => (int) $token['site_id']];
    }
    $names = array_column($token['groups'], 'name');
    return ['type' => 'group', 'name' => implode(', ', $names), 'groups' => $names, 'where' => group_filter(array_column($token['groups'], 'id'))];
}

/** The last day (YYYY-MM-DD, local time) a token made now works, unless something else is chosen. */
function share_default_day(string $timezone, int $now): string
{
    return (new DateTimeImmutable('@' . $now))->setTimezone(new DateTimeZone($timezone))->modify('+' . SHARE_DEFAULT_DAYS . ' days')->format('Y-m-d');
}

/**
 * The end of a day (YYYY-MM-DD) in local time, in UTC: a token that expires
 * on a day works through that day. Today is allowed; a day that has passed is not.
 */
function share_expiry(string $date, string $timezone, int $now): string
{
    $tz = new DateTimeZone($timezone);
    $day = DateTimeImmutable::createFromFormat('!Y-m-d', trim($date), $tz);
    if (!$day || $day->format('Y-m-d') !== trim($date)) {
        throw new InvalidArgumentException("Give the last day the token works as YYYY-MM-DD (got \"{$date}\")");
    }
    $end = $day->modify('+1 day');
    if ($end->getTimestamp() <= $now) throw new InvalidArgumentException("{$date} has passed: give today or a later day");
    return utc_now($end->getTimestamp());
}
