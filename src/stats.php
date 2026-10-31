<?php

/**
 * The times a site was down within [$from, $to), as [start, end) pairs: from
 * each down event to the next up event, clipped to the period. $before is
 * the status at $from ('fail' when it was already down). Events must be in
 * time order; only 'fail' and 'ok' events count.
 *
 * @return list<array{int, int}>
 */
function down_periods(array $events, string $before, int $from, int $to): array
{
    $down = $before === 'fail' ? $from : null;
    $periods = [];
    foreach ($events as $event) {
        $at = max($from, min($to, strtotime($event['created_at'])));
        if ($event['status'] === 'fail' && $down === null) {
            $down = $at;
        } elseif ($event['status'] === 'ok' && $down !== null) {
            if ($at > $down) $periods[] = [$down, $at];
            $down = null;
        }
    }
    if ($down !== null && $to > $down) $periods[] = [$down, $to];
    return $periods;
}

function period_seconds(array $periods): int
{
    return array_sum(array_map(fn ($period) => $period[1] - $period[0], $periods));
}

/** How many seconds of $periods fall inside $cover. Both are [start, end) pairs; the cover may overlap itself. */
function overlap_seconds(array $periods, array $cover): int
{
    usort($cover, fn ($a, $b) => $a[0] <=> $b[0]);
    $merged = [];
    foreach ($cover as [$start, $end]) {
        $last = count($merged) - 1;
        if ($last >= 0 && $start <= $merged[$last][1]) $merged[$last][1] = max($merged[$last][1], $end);
        else $merged[] = [$start, $end];
    }
    $total = 0;
    foreach ($periods as [$start, $end]) {
        foreach ($merged as [$coverStart, $coverEnd]) $total += max(0, min($end, $coverEnd) - max($start, $coverStart));
    }
    return $total;
}

/**
 * The times within [$from, $to) that a site's maintenance windows cover.
 * Downtime then is planned: shown on its own and left out of uptime. The
 * windows are the ones there are now, so a removed window no longer counts.
 */
function planned_periods(array $windows, int $from, int $to, string $timezone): array
{
    return array_merge([], ...array_map(fn ($window) => window_periods($window, $from, $to, $timezone), $windows));
}

/** Uptime as a percentage, floored to two decimals so any downtime shows; null without a period. */
function uptime_percent(int $downSeconds, int $periodSeconds): ?float
{
    if ($periodSeconds <= 0) return null;
    return floor((1 - $downSeconds / $periodSeconds) * 10000) / 100;
}

/** When a site's uptime was first checked (its first uptime event), or null before that. */
function watched_since(PDO $db, array $site): ?int
{
    $stmt = $db->prepare("SELECT MIN(created_at) FROM events WHERE site_id = ? AND check_name = 'uptime'");
    $stmt->execute([$site['id']]);
    $first = $stmt->fetchColumn();
    return $first ? strtotime($first) : null;
}

/**
 * A site's uptime over the last $seconds, from its events, leaving out
 * downtime in its maintenance windows. The period starts no earlier than the
 * first check: before it nothing is known, so a site not checked yet has no
 * uptime (null), not 100%.
 */
function site_uptime(PDO $db, array $site, int $seconds, int $now, string $timezone): ?float
{
    $since = watched_since($db, $site);
    if ($since === null) return null;
    $from = max($now - $seconds, $since);
    if ($from >= $now) return null;

    $before = $db->prepare("SELECT status FROM events WHERE site_id = ? AND check_name = 'uptime' AND status IN ('ok', 'fail') AND created_at < ? ORDER BY created_at DESC, id DESC LIMIT 1");
    $before->execute([$site['id'], utc_now($from)]);
    $events = $db->prepare("SELECT status, created_at FROM events WHERE site_id = ? AND check_name = 'uptime' AND status IN ('ok', 'fail') AND created_at >= ? AND created_at < ? ORDER BY created_at, id");
    $events->execute([$site['id'], utc_now($from), utc_now($now)]);

    $down = down_periods($events->fetchAll(), $before->fetchColumn() ?: 'ok', $from, $now);
    $planned = overlap_seconds($down, planned_periods(site_windows($db, $site), $from, $now, $timezone));
    return uptime_percent(period_seconds($down) - $planned, $now - $from);
}

/**
 * Each of the last $days local days (oldest first), from the site's events:
 * its uptime, the seconds it was down and in how many outages, and the
 * seconds it was down in a maintenance window (planned, not in the rest).
 * An outage wholly inside a window is not counted. A day before the first
 * check has nulls; today counts up to now.
 *
 * @return list<array{date: string, up: ?float, down_seconds: ?int, outages: ?int, planned_seconds: ?int}>
 */
function daily_uptime(PDO $db, array $site, int $now, int $days, string $timezone): array
{
    $tz = new DateTimeZone($timezone);
    $today = (new DateTimeImmutable('@' . $now))->setTimezone($tz)->setTime(0, 0);
    $first = $today->modify('-' . ($days - 1) . ' days');
    // Days before the first check are unknown, not up
    $added = watched_since($db, $site) ?? $now;

    $before = $db->prepare("SELECT status FROM events WHERE site_id = ? AND check_name = 'uptime' AND status IN ('ok', 'fail') AND created_at < ? ORDER BY created_at DESC, id DESC LIMIT 1");
    $before->execute([$site['id'], utc_now($first->getTimestamp())]);
    $status = $before->fetchColumn() ?: 'ok';
    $events = $db->prepare("SELECT status, created_at FROM events WHERE site_id = ? AND check_name = 'uptime' AND status IN ('ok', 'fail') AND created_at >= ? AND created_at < ? ORDER BY created_at, id");
    $events->execute([$site['id'], utc_now($first->getTimestamp()), utc_now($now)]);
    // Each event's time read once, not once for every day
    $events = array_map(fn ($event) => $event + ['at' => strtotime($event['created_at'])], $events->fetchAll());
    $windows = site_windows($db, $site);

    $result = [];
    for ($day = $first; $day <= $today; $day = $day->modify('+1 day')) {
        $from = max($day->getTimestamp(), $added);
        $to = min($day->modify('+1 day')->getTimestamp(), $now);
        if ($from >= $to) {
            $result[] = ['date' => $day->format('Y-m-d'), 'up' => null, 'down_seconds' => null, 'outages' => null, 'planned_seconds' => null];
            continue;
        }
        $inDay = [];
        $statusAtStart = $status;
        foreach ($events as $event) {
            if ($event['at'] < $from) $statusAtStart = $event['status'];
            elseif ($event['at'] < $to) $inDay[] = $event;
        }
        $down = down_periods($inDay, $statusAtStart, $from, $to);
        $cover = planned_periods($windows, $from, $to, $timezone);
        $planned = overlap_seconds($down, $cover);
        $downSeconds = period_seconds($down) - $planned;
        $result[] = [
            'date' => $day->format('Y-m-d'),
            'up' => uptime_percent($downSeconds, $to - $from),
            'down_seconds' => $downSeconds,
            'outages' => count(array_filter($down, fn ($period) => overlap_seconds([$period], $cover) < $period[1] - $period[0])),
            'planned_seconds' => $planned,
        ];
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
        // floor, not intdiv: a sample a second before the start is not in the first hour
        $i = (int) floor((strtotime($sample['checked_at']) - $start) / 3600);
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

/**
 * hourly_buckets() for many sites at once, counted by SQLite. The overview
 * needs the last day of every site, some 14 000 samples for 50 sites, and
 * reading each one's time in PHP was most of its time. A site without
 * samples is left out.
 *
 * @param list<int> $siteIds
 * @return array<int, list<array{ms: ?int, up: ?float}>>
 */
function hourly_buckets_by_site(PDO $db, array $siteIds, int $now, int $hours): array
{
    if (!$siteIds) return [];
    $start = $now - $hours * 3600;
    $stmt = $db->prepare("SELECT site_id, (CAST(strftime('%s', checked_at) AS INTEGER) - :start) / 3600 AS bucket,
            COUNT(*) AS checks, SUM(ok) AS ups, AVG(CASE WHEN ok = 1 THEN ms END) AS ms
        FROM samples WHERE checked_at >= :from AND site_id IN (" . implode(', ', array_map('intval', $siteIds)) . ')
        GROUP BY site_id, bucket');
    $stmt->bindValue(':start', $start, PDO::PARAM_INT);
    $stmt->bindValue(':from', utc_now($start));
    $stmt->execute();

    $empty = array_fill(0, $hours, ['ms' => null, 'up' => null]);
    $buckets = [];
    foreach ($stmt->fetchAll() as $row) {
        $i = (int) $row['bucket'];
        if ($i < 0 || $i >= $hours) continue;
        $buckets[$row['site_id']] ??= $empty;
        $buckets[$row['site_id']][$i] = [
            'ms' => $row['ms'] !== null ? (int) round((float) $row['ms']) : null,
            'up' => round($row['ups'] / $row['checks'], 4),
        ];
    }
    return $buckets;
}
