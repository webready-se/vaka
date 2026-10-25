<?php

/**
 * Seconds a site was down within [$from, $to): from each down event to the
 * next up event, clipped to the period. $before is the status at $from
 * ('fail' when it was already down). Events must be in time order; only
 * 'fail' and 'ok' events count.
 */
function downtime_seconds(array $events, string $before, int $from, int $to): int
{
    $down = $before === 'fail' ? $from : null;
    $total = 0;
    foreach ($events as $event) {
        $at = max($from, min($to, strtotime($event['created_at'])));
        if ($event['status'] === 'fail' && $down === null) {
            $down = $at;
        } elseif ($event['status'] === 'ok' && $down !== null) {
            $total += $at - $down;
            $down = null;
        }
    }
    if ($down !== null) $total += $to - $down;
    return $total;
}

/** Uptime as a percentage, floored to two decimals so any downtime shows; null without a period. */
function uptime_percent(int $downSeconds, int $periodSeconds): ?float
{
    if ($periodSeconds <= 0) return null;
    return floor((1 - $downSeconds / $periodSeconds) * 10000) / 100;
}

/**
 * A site's uptime over the last $seconds, from its events. The period starts
 * no earlier than when the site was added.
 */
function site_uptime(PDO $db, array $site, int $seconds, int $now): ?float
{
    $from = max($now - $seconds, strtotime($site['created_at']));
    if ($from >= $now) return null;

    $before = $db->prepare("SELECT status FROM events WHERE site_id = ? AND check_name = 'uptime' AND status IN ('ok', 'fail') AND created_at < ? ORDER BY created_at DESC, id DESC LIMIT 1");
    $before->execute([$site['id'], utc_now($from)]);
    $events = $db->prepare("SELECT status, created_at FROM events WHERE site_id = ? AND check_name = 'uptime' AND status IN ('ok', 'fail') AND created_at >= ? AND created_at < ? ORDER BY created_at, id");
    $events->execute([$site['id'], utc_now($from), utc_now($now)]);

    return uptime_percent(downtime_seconds($events->fetchAll(), $before->fetchColumn() ?: 'ok', $from, $now), $now - $from);
}

/**
 * Uptime for each of the last $days local days (oldest first), from the
 * site's events: {date: 'Y-m-d', up: percent or null}. A day before the site
 * was added is null; today counts up to now.
 *
 * @return list<array{date: string, up: ?float}>
 */
function daily_uptime(PDO $db, array $site, int $now, int $days, string $timezone): array
{
    $tz = new DateTimeZone($timezone);
    $today = (new DateTimeImmutable('@' . $now))->setTimezone($tz)->setTime(0, 0);
    $first = $today->modify('-' . ($days - 1) . ' days');
    $added = strtotime($site['created_at']);

    $before = $db->prepare("SELECT status FROM events WHERE site_id = ? AND check_name = 'uptime' AND status IN ('ok', 'fail') AND created_at < ? ORDER BY created_at DESC, id DESC LIMIT 1");
    $before->execute([$site['id'], utc_now($first->getTimestamp())]);
    $status = $before->fetchColumn() ?: 'ok';
    $events = $db->prepare("SELECT status, created_at FROM events WHERE site_id = ? AND check_name = 'uptime' AND status IN ('ok', 'fail') AND created_at >= ? AND created_at < ? ORDER BY created_at, id");
    $events->execute([$site['id'], utc_now($first->getTimestamp()), utc_now($now)]);
    $events = $events->fetchAll();

    $result = [];
    for ($day = $first; $day <= $today; $day = $day->modify('+1 day')) {
        $from = max($day->getTimestamp(), $added);
        $to = min($day->modify('+1 day')->getTimestamp(), $now);
        if ($from >= $to) {
            $result[] = ['date' => $day->format('Y-m-d'), 'up' => null];
            continue;
        }
        $inDay = [];
        $statusAtStart = $status;
        foreach ($events as $event) {
            $at = strtotime($event['created_at']);
            if ($at < $from) $statusAtStart = $event['status'];
            elseif ($at < $to) $inDay[] = $event;
        }
        $result[] = ['date' => $day->format('Y-m-d'), 'up' => uptime_percent(downtime_seconds($inDay, $statusAtStart, $from, $to), $to - $from)];
    }
    return $result;
}

/**
 * Samples in hourly buckets for the last $hours, oldest first: the average
 * response time of the checks that were up, and the share of checks that
 * were up (null for an hour without checks).
 *
 * @return list<array{ms: ?int, up: ?float}>
 */
function hourly_buckets(array $samples, int $now, int $hours): array
{
    $start = $now - $hours * 3600;
    $buckets = array_fill(0, $hours, ['ms' => [], 'checks' => 0, 'ups' => 0]);
    foreach ($samples as $sample) {
        $i = intdiv(strtotime($sample['checked_at']) - $start, 3600);
        if ($i < 0 || $i >= $hours) continue;
        $buckets[$i]['checks']++;
        if ($sample['ok']) {
            $buckets[$i]['ups']++;
            $buckets[$i]['ms'][] = $sample['ms'];
        }
    }
    return array_map(fn ($b) => [
        'ms' => $b['ms'] ? (int) round(array_sum($b['ms']) / count($b['ms'])) : null,
        'up' => $b['checks'] ? round($b['ups'] / $b['checks'], 4) : null,
    ], $buckets);
}
