<?php

/**
 * Read tokens for the API (docs/api.md): one per site or group, read-only,
 * optionally expiring. The token is shown once when it is made; Vaka keeps
 * only its SHA-256 and its first characters, to tell tokens apart.
 */

/** Characters of a token kept as is, to recognise it in share:list. */
const SHARE_PREFIX_LENGTH = 8;

function share_token_hash(string $token): string
{
    return hash('sha256', $token);
}

/**
 * A new read token for a site or a group. $expiresAt is UTC (see
 * share_expiry()) or null for a token that never expires.
 *
 * @return array{id: int, token: string}
 */
function create_share_token(PDO $db, ?int $siteId, ?int $groupId, string $label, ?string $expiresAt, int $now): array
{
    $token = bin2hex(random_bytes(32));
    $db->prepare('INSERT INTO share_tokens (token_hash, prefix, site_id, group_id, label, expires_at, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)')
        ->execute([share_token_hash($token), substr($token, 0, SHARE_PREFIX_LENGTH), $siteId, $groupId, clean_text($label, 100, 'label'), $expiresAt, utc_now($now)]);
    return ['id' => (int) $db->lastInsertId(), 'token' => $token];
}

/**
 * The token's row with its site or group, when the token is valid now, else
 * null. Records when it was last used (at most once a minute, so reading
 * stays cheap).
 */
function find_share_token(PDO $db, string $token, int $now): ?array
{
    if (!preg_match('/^[a-f0-9]{64}$/', $token)) return null;
    $stmt = $db->prepare('SELECT t.*, s.url AS site_url, g.name AS group_name FROM share_tokens t
        LEFT JOIN sites s ON s.id = t.site_id
        LEFT JOIN groups g ON g.id = t.group_id
        WHERE t.token_hash = ?');
    $stmt->execute([share_token_hash($token)]);
    $row = $stmt->fetch();
    if (!$row || ($row['expires_at'] !== null && $row['expires_at'] <= utc_now($now))) return null;

    if ($row['last_used_at'] === null || $row['last_used_at'] <= utc_now($now - 60)) {
        $db->prepare('UPDATE share_tokens SET last_used_at = ? WHERE id = ?')->execute([utc_now($now), $row['id']]);
    }
    return $row;
}

/** Every token, newest first, with its site or group. */
function list_share_tokens(PDO $db): array
{
    return $db->query('SELECT t.*, s.url AS site_url, g.name AS group_name FROM share_tokens t
        LEFT JOIN sites s ON s.id = t.site_id
        LEFT JOIN groups g ON g.id = t.group_id
        ORDER BY t.created_at DESC, t.id DESC')->fetchAll();
}

function revoke_share_token(PDO $db, int $id): void
{
    $db->prepare('DELETE FROM share_tokens WHERE id = ?')->execute([$id]);
}

/** What a token reads, in words: "group Customers" or "shop.example". */
function share_target(array $token): string
{
    return $token['group_id'] !== null ? "group {$token['group_name']}" : display_url((string) $token['site_url']);
}

/**
 * What a token may read: its type and name for the response, and the SQL
 * condition on sites (s.) for overview_rows().
 *
 * @return array{type: string, name: string, where: string}
 */
function share_scope(array $token): array
{
    return $token['group_id'] !== null
        ? ['type' => 'group', 'name' => $token['group_name'], 'where' => 's.group_id = ' . (int) $token['group_id']]
        : ['type' => 'site', 'name' => display_url((string) $token['site_url']), 'where' => 's.id = ' . (int) $token['site_id']];
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
