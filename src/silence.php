<?php

/** How long an incident can be snoozed. There is no open-ended snooze, so nothing is forgotten. */
const SNOOZE_CHOICES = ['1h' => 3600, '4h' => 14400, '24h' => 86400];

const WEEKDAYS = ['mon' => 1, 'tue' => 2, 'wed' => 3, 'thu' => 4, 'fri' => 5, 'sat' => 6, 'sun' => 7];

const WEEKDAY_NAMES = ['monday' => 1, 'tuesday' => 2, 'wednesday' => 3, 'thursday' => 4, 'friday' => 5, 'saturday' => 6, 'sunday' => 7];

// =====================================================================
// Maintenance windows
// =====================================================================

/** "daily", "weekdays", "weekends" or day names ("mon,wed", "Sunday") as ISO weekday numbers. */
function parse_days(string $value): array
{
    $value = strtolower(trim($value));
    if ($value === 'daily' || $value === 'every day') return [1, 2, 3, 4, 5, 6, 7];
    if ($value === 'weekdays') return [1, 2, 3, 4, 5];
    if ($value === 'weekends') return [6, 7];

    $days = [];
    foreach (array_filter(preg_split('/[\s,]+/', $value)) as $name) {
        $day = WEEKDAYS[$name] ?? WEEKDAY_NAMES[$name] ?? null;
        if ($day === null) {
            throw new InvalidArgumentException("\"{$name}\" is not a day. Use daily, weekdays, weekends or names like mon,wed,fri");
        }
        $days[] = $day;
    }
    if (!$days) throw new InvalidArgumentException('Which days? Use daily, weekdays, weekends or names like mon,wed,fri');
    $days = array_values(array_unique($days));
    sort($days);
    return $days;
}

/** "00:00-07:00" as ['00:00', '07:00']. An end before the start runs past midnight. */
function parse_time_range(string $value): array
{
    if (!preg_match('/^\s*(\d{1,2}):(\d{2})\s*-\s*(\d{1,2}):(\d{2})\s*$/', $value, $m)
        || (int) $m[1] > 23 || (int) $m[2] > 59 || (int) $m[3] > 23 || (int) $m[4] > 59) {
        throw new InvalidArgumentException("\"{$value}\" is not a time range like 00:00-07:00");
    }
    $start = sprintf('%02d:%02d', $m[1], $m[2]);
    $end = sprintf('%02d:%02d', $m[3], $m[4]);
    if ($start === $end) throw new InvalidArgumentException('A maintenance window must end at another time than it starts');
    return [$start, $end];
}

/** "2026-11-01 22:00" in $timezone, as stored UTC. */
function parse_local_datetime(string $value, string $timezone): string
{
    $time = DateTimeImmutable::createFromFormat('!Y-m-d H:i', trim($value), new DateTimeZone($timezone));
    if (!$time || $time->format('Y-m-d H:i') !== trim($value)) {
        throw new InvalidArgumentException("\"{$value}\" is not a date and time like YYYY-MM-DD HH:MM");
    }
    return utc_now($time->getTimestamp());
}

/**
 * A maintenance window from command line options: --at=HH:MM-HH:MM with
 * optional --days (every day otherwise), or --from and --to (local times)
 * for a single window. --label names it.
 */
function maintenance_fields(array $opts, string $timezone): array
{
    $label = clean_text(is_string($opts['label'] ?? null) ? $opts['label'] : '', 100, 'label');
    $recurring = isset($opts['at']);
    $once = isset($opts['from']) || isset($opts['to']);

    if ($recurring && $once) throw new InvalidArgumentException('A maintenance window is either recurring (--at) or once (--from and --to), not both');
    if ($once) {
        if (!is_string($opts['from'] ?? null) || !is_string($opts['to'] ?? null)) {
            throw new InvalidArgumentException('A one-off window needs both --from and --to, e.g. --from="2026-11-01 22:00" --to="2026-11-02 02:00"');
        }
        $starts = parse_local_datetime($opts['from'], $timezone);
        $ends = parse_local_datetime($opts['to'], $timezone);
        if ($ends <= $starts) throw new InvalidArgumentException('The window must end after it starts');
        return ['label' => $label, 'days' => '', 'start_time' => null, 'end_time' => null, 'starts_at' => $starts, 'ends_at' => $ends];
    }
    if (!$recurring) {
        throw new InvalidArgumentException(isset($opts['days'])
            ? '--days needs the times too, e.g. --at=00:00-07:00'
            : 'When? Every night: --at=00:00-07:00 (add --days=weekdays for some days only). Once: --from="2026-11-01 22:00" --to="2026-11-02 02:00"');
    }
    [$start, $end] = parse_time_range((string) $opts['at']);
    $days = isset($opts['days']) ? parse_days((string) $opts['days']) : [1, 2, 3, 4, 5, 6, 7];
    return ['label' => $label, 'days' => implode(',', $days), 'start_time' => $start, 'end_time' => $end, 'starts_at' => null, 'ends_at' => null];
}

/**
 * Whether a window covers $now. A recurring window uses local time in
 * $timezone, so it keeps its hours across daylight saving changes; one that
 * runs past midnight belongs to the day it starts.
 */
function window_active(array $window, int $now, string $timezone): bool
{
    if ($window['starts_at'] !== null) {
        return $window['starts_at'] <= utc_now($now) && utc_now($now) < $window['ends_at'];
    }
    $local = (new DateTimeImmutable('@' . $now))->setTimezone(new DateTimeZone($timezone));
    $time = $local->format('H:i');
    $days = array_map('intval', explode(',', $window['days']));
    $today = (int) $local->format('N');
    $yesterday = $today === 1 ? 7 : $today - 1;

    if ($window['start_time'] < $window['end_time']) {
        return in_array($today, $days, true) && $window['start_time'] <= $time && $time < $window['end_time'];
    }
    return (in_array($today, $days, true) && $time >= $window['start_time'])
        || (in_array($yesterday, $days, true) && $time < $window['end_time']);
}

/**
 * The times a window covers within [$from, $to), as [start, end) pairs: a
 * one-off window once, a recurring one on each of its days, in local time as
 * window_active() has it.
 *
 * @return list<array{int, int}>
 */
function window_periods(array $window, int $from, int $to, string $timezone): array
{
    if ($window['starts_at'] !== null) {
        $start = max($from, strtotime($window['starts_at']));
        $end = min($to, strtotime($window['ends_at']));
        return $start < $end ? [[$start, $end]] : [];
    }
    $days = array_map('intval', explode(',', $window['days']));
    [$startHour, $startMinute] = array_map('intval', explode(':', $window['start_time']));
    [$endHour, $endMinute] = array_map('intval', explode(':', $window['end_time']));
    $periods = [];
    // From the day before, for a window that runs past midnight into $from
    $day = (new DateTimeImmutable('@' . $from))->setTimezone(new DateTimeZone($timezone))->setTime(0, 0)->modify('-1 day');
    for (; $day->getTimestamp() < $to; $day = $day->modify('+1 day')) {
        if (!in_array((int) $day->format('N'), $days, true)) continue;
        $end = $day->setTime($endHour, $endMinute);
        if ($window['end_time'] <= $window['start_time']) $end = $end->modify('+1 day');
        $start = max($from, $day->setTime($startHour, $startMinute)->getTimestamp());
        $end = min($to, $end->getTimestamp());
        if ($start < $end) $periods[] = [$start, $end];
    }
    return $periods;
}

/** "every day 00:00-07:00", "weekdays 22:00-02:00", "once, 1 Nov 22:00 to 2 Nov 02:00" (12h: "every day 12:00 AM to 7:00 AM") */
function describe_window(array $window, string $timezone, string $timeFormat = '24h'): string
{
    if ($window['starts_at'] !== null) {
        $tz = new DateTimeZone($timezone);
        $format = fn (string $utc) => ($local = (new DateTimeImmutable($utc))->setTimezone($tz))->format('j M ') . clock_text($local, $timeFormat);
        return 'once, ' . $format($window['starts_at']) . ' to ' . $format($window['ends_at']);
    }
    $days = match ($window['days']) {
        '1,2,3,4,5,6,7' => 'every day',
        '1,2,3,4,5' => 'weekdays',
        '6,7' => 'weekends',
        default => implode(', ', array_map(fn ($day) => ucfirst(array_search((int) $day, WEEKDAYS, true)), explode(',', $window['days']))),
    };
    if ($timeFormat === '12h') return "{$days} " . hhmm_text($window['start_time'], $timeFormat) . ' to ' . hhmm_text($window['end_time'], $timeFormat);
    return "{$days} {$window['start_time']}-{$window['end_time']}";
}

function add_maintenance(PDO $db, ?int $siteId, ?int $groupId, array $fields): int
{
    $db->prepare('INSERT INTO maintenance (site_id, group_id, label, days, start_time, end_time, starts_at, ends_at, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)')
        ->execute([$siteId, $groupId, $fields['label'], $fields['days'], $fields['start_time'], $fields['end_time'], $fields['starts_at'], $fields['ends_at'], utc_now()]);
    return (int) $db->lastInsertId();
}

/** Every window, with the name of the site or group it is for. */
function list_maintenance(PDO $db): array
{
    return $db->query('SELECT m.*, s.url AS site_url, g.name AS group_name FROM maintenance m
        LEFT JOIN sites s ON s.id = m.site_id
        LEFT JOIN groups g ON g.id = m.group_id
        ORDER BY m.id')->fetchAll();
}

/** "group Nightly" or "shop.example" */
function window_target(array $window): string
{
    return $window['group_name'] !== null ? "group {$window['group_name']}" : display_url((string) $window['site_url']);
}

function remove_maintenance(PDO $db, int $id): void
{
    $db->prepare('DELETE FROM maintenance WHERE id = ?')->execute([$id]);
}

/** One-off windows that are over, and the rest, for checking a run against. */
function load_windows(PDO $db, int $now): array
{
    $stmt = $db->prepare('SELECT * FROM maintenance WHERE ends_at IS NULL OR ends_at > ?');
    $stmt->execute([utc_now($now)]);
    return $stmt->fetchAll();
}

/** Every window that covers a site, its own and its group's, including one-off windows that are over. */
function site_windows(PDO $db, array $site): array
{
    $stmt = $db->prepare('SELECT * FROM maintenance WHERE site_id = ? OR (group_id IS NOT NULL AND group_id = ?)');
    $stmt->execute([$site['id'], $site['group_id']]);
    return $stmt->fetchAll();
}

// =====================================================================
// Silence: maintenance or snooze
// =====================================================================

/**
 * What silences a site's alerts right now: 'snooze', 'maintenance' or ''.
 * $site needs id, group_id and snoozed_until.
 */
function active_silence(array $site, array $windows, int $now, string $timezone): string
{
    if (($site['snoozed_until'] ?? null) !== null && $site['snoozed_until'] > utc_now($now)) return 'snooze';
    foreach ($windows as $window) {
        $applies = ($window['site_id'] !== null && $window['site_id'] === $site['id'])
            || ($window['group_id'] !== null && $window['group_id'] === $site['group_id']);
        if ($applies && window_active($window, $now, $timezone)) return 'maintenance';
    }
    return '';
}

/** "1h", "4h" or "24h" in seconds. */
function parse_snooze(string $value): int
{
    return SNOOZE_CHOICES[strtolower(trim($value))]
        ?? throw new InvalidArgumentException("Snooze for 1h, 4h or 24h (got \"{$value}\")");
}

/**
 * Snooze a site's ongoing uptime incident. Alerts stay quiet until the snooze
 * ends; if the site is still down then, "still down after the snooze" goes
 * out. A recovery ends the snooze (and is alerted as usual).
 */
function snooze_site(PDO $db, int $siteId, int $seconds, int $now): void
{
    $db->prepare("UPDATE check_state SET snoozed_until = ?, flapping_since = NULL,
            alerted_status = CASE WHEN status = 'fail' THEN 'snoozed' ELSE alerted_status END
        WHERE site_id = ? AND check_name = 'uptime'")
        ->execute([utc_now($now + $seconds), $siteId]);
    add_event($db, $siteId, 'uptime', ['status' => 'snoozed', 'previous' => '', 'message' => 'Snoozed for ' . human_duration($seconds)], utc_now($now));
}

function unsnooze_site(PDO $db, int $siteId, int $now): void
{
    $db->prepare("UPDATE check_state SET snoozed_until = NULL WHERE site_id = ? AND check_name = 'uptime'")->execute([$siteId]);
    add_event($db, $siteId, 'uptime', ['status' => 'snoozed', 'previous' => '', 'message' => 'Snooze lifted'], utc_now($now));
}
