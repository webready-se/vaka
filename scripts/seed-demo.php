<?php

/**
 * A demo database with fake sites, for trying the dashboard, screenshots and
 * the smoke test:
 *
 *   php scripts/seed-demo.php <new.sqlite> [--hostile]
 *
 * Reserved example names and documentation IPs only. --hostile adds names
 * and messages full of markup, which the dashboard must show as plain text.
 */

require __DIR__ . '/../src/bootstrap.php';

[$args, $opts] = parse_args(array_slice($argv, 1));
$path = $args[0] ?? fail('Usage: php scripts/seed-demo.php <new.sqlite> [--hostile]');
if (file_exists($path)) fail("{$path} already exists. Give the path of a new file.");

$db = get_db($path);
$now = time();
mt_srand(42);
$month = $now - 30 * 86400;

add_group($db, ['name' => 'Customers', 'slack_webhook' => 'https://hooks.example/services/demo/demo/demo1234']);
add_group($db, ['name' => 'Own sites', 'policy' => 'delayed', 'delay_minutes' => '5']);
add_group($db, ['name' => 'Nightly', 'policy' => 'digest']);
add_maintenance($db, null, find_group($db, 'Nightly')['id'], maintenance_fields(['at' => '00:00-07:00', 'label' => 'Heavy imports'], 'Europe/Stockholm'));
$db->exec("INSERT INTO servers (ip, label) VALUES ('203.0.113.10', 'web-1'), ('198.51.100.7', 'web-2')");

// [url, group, ip, cdn, typical ms, what is going on]
$sites = [
    ['https://shop.example', 'Customers', '203.0.113.10', '', 310, 'down'],
    ['https://www.example.com', 'Customers', '203.0.113.10', '', 180, 'up'],
    ['https://booking.example', 'Customers', '203.0.113.10', '', 420, 'up'],
    ['https://cafe.example', 'Customers', '203.0.113.10', '', 150, 'up'],
    ['https://builder.example', 'Customers', '203.0.113.10', '', 260, 'unstable'],
    ['https://store.example', 'Customers', '', 'Cloudflare', 90, 'up'],
    ['https://example.org', 'Own sites', '198.51.100.7', '', 120, 'up'],
    ['https://docs.example', 'Own sites', '198.51.100.7', '', 140, 'up'],
    ['https://blog.example', 'Own sites', '198.51.100.7', '', 230, 'failing'],
    ['https://status.example', 'Own sites', '198.51.100.7', '', 100, 'paused'],
    ['https://deals1.example', 'Nightly', '203.0.113.10', '', 520, 'up'],
    ['https://deals2.example', 'Nightly', '203.0.113.10', '', 610, 'snoozed'],
    ['https://deals3.example', 'Nightly', '203.0.113.10', '', 480, 'up'],
    ['https://example.net', null, '198.51.100.7', '', 200, 'up'],
];
if (isset($opts['hostile'])) {
    add_group($db, ['name' => '"><img src=x data-xss="group">']);
    $sites[] = ['https://xss.example/?q=<script>window.__xss=1</script>', '"><img src=x data-xss="group">', '192.0.2.66', '', 300, 'hostile'];
}

$insertSample = $db->prepare('INSERT INTO samples (site_id, checked_at, status_code, ms, ok, ip) VALUES (?, ?, ?, ?, ?, ?)');
$db->beginTransaction();
foreach ($sites as [$url, $group, $ip, $cdn, $ms, $story]) {
    $id = add_site($db, ['url' => $url, 'group' => $group ?? 'none', 'paused' => $story === 'paused', 'keyword' => $story === 'hostile' ? '<script>window.__xss=3</script>' : '']);
    $db->prepare('UPDATE sites SET ip = ?, cdn = ?, created_at = ? WHERE id = ?')->execute([$ip, $cdn, utc_now($month), $id]);
    if ($story === 'hostile') $db->prepare('UPDATE sites SET name = ? WHERE id = ?')->execute(['<img src=x onerror="window.__xss=2">', $id]);
    add_event($db, $id, 'uptime', ['status' => 'ok', 'previous' => 'pending', 'message' => 'First check: answered 200 OK in ' . $ms . ' ms'], utc_now($month));

    // When it was down in the last 24 hours: [from, to] seconds ago
    $outages = match ($story) {
        'down' => [[14 * 60, 0]],
        'snoozed' => [[40 * 60, 0]],
        'unstable' => [[50 * 60, 46 * 60], [38 * 60, 35 * 60], [24 * 60, 21 * 60]],
        'up' => $url === 'https://booking.example' ? [[20 * 3600, 19 * 3600 + 48 * 60]] : [],
        default => [],
    };
    foreach (array_reverse($outages) as [$from, $to]) {
        add_event($db, $id, 'uptime', ['status' => 'fail', 'previous' => 'ok', 'message' => 'Down: no answer within 10 s'], utc_now($now - $from));
        if ($to > 0) add_event($db, $id, 'uptime', ['status' => 'ok', 'previous' => 'fail', 'message' => 'Up again after ' . human_duration($from - $to) . ': answered 200 OK in ' . $ms . ' ms'], utc_now($now - $to));
    }

    if ($story !== 'paused') {
        for ($t = $now - 86400 + 300; $t <= $now; $t += 300) {
            $down = false;
            foreach ($outages as [$from, $to]) $down = $down || ($t >= $now - $from && $t < $now - $to);
            $jitter = (int) round($ms * (0.8 + mt_rand(0, 60) / 100) + (date('G', $t) >= 9 && date('G', $t) <= 17 ? $ms * 0.3 : 0));
            $insertSample->execute([$id, utc_now($t), $down ? 503 : 200, $down ? 0 : $jitter, $down ? 0 : 1, $ip]);
        }
    }

    $state = match ($story) {
        'down' => ['status' => 'fail', 'summary' => 'The site answered 503 Service Unavailable', 'fail_count' => 14, 'changed_at' => utc_now($now - 14 * 60), 'details' => ['status_code' => 503, 'ms' => 0, 'ip' => $ip, 'cdn' => '', 'problem' => 'http', 'failing_since' => utc_now($now - 14 * 60)]],
        'snoozed' => ['status' => 'fail', 'summary' => 'No answer within 10 s', 'fail_count' => 40, 'changed_at' => utc_now($now - 40 * 60), 'details' => ['status_code' => 0, 'ms' => 0, 'ip' => $ip, 'cdn' => '', 'problem' => 'timeout', 'failing_since' => utc_now($now - 40 * 60)]],
        'failing' => ['status' => 'ok', 'summary' => 'Failed once, checking again: no answer within 10 s', 'fail_count' => 1, 'changed_at' => utc_now($month), 'details' => ['status_code' => 0, 'ms' => 0, 'ip' => $ip, 'cdn' => '', 'problem' => 'timeout', 'failing_since' => utc_now($now - 40)]],
        'hostile' => ['status' => 'fail', 'summary' => 'Answered 200 OK, but the page does not contain "<script>window.__xss=3</script>"', 'fail_count' => 2, 'changed_at' => utc_now($now - 120), 'details' => ['status_code' => 200, 'ms' => 300, 'ip' => $ip, 'cdn' => '', 'problem' => 'keyword']],
        default => ['status' => 'ok', 'summary' => "Answered 200 OK in {$ms} ms", 'fail_count' => 0, 'changed_at' => utc_now($story === 'unstable' ? $now - 21 * 60 : $month), 'details' => ['status_code' => 200, 'ms' => $ms, 'ip' => $ip, 'cdn' => $cdn, 'problem' => '']],
    };
    if ($story !== 'paused') save_check_state($db, $id, 'uptime', $state, utc_now($now - 20), utc_now($now + 280));

    // Certificates: most renewed a while ago, one about to expire
    $days = $url === 'https://booking.example' ? 6 : 40 + mt_rand(0, 45);
    $validTo = $now + $days * 86400 + 3600;
    $ssl = $days <= 14
        ? ['status' => 'warn', 'summary' => "The certificate expires in {$days} days (" . date('j M Y', $validTo) . ')', 'problem' => 'expiring']
        : ['status' => 'ok', 'summary' => 'Valid until ' . date('j M Y', $validTo) . " ({$days} days), issued by Let's Encrypt", 'problem' => ''];
    if (!in_array($story, ['paused', 'hostile'], true)) {
        save_check_state($db, $id, 'ssl', ['status' => $ssl['status'], 'summary' => $ssl['summary'], 'fail_count' => 0, 'changed_at' => utc_now($month), 'details' => ['days_left' => $days, 'valid_to' => utc_now($validTo), 'issuer' => "Let's Encrypt", 'problem' => $ssl['problem']]], utc_now($now - 3600), utc_now($now + 5 * 3600));
        $db->prepare("UPDATE check_state SET alerted_status = ?, thresholds_sent = ? WHERE site_id = ? AND check_name = 'ssl'")
            ->execute([$ssl['status'], json_encode(['valid_to' => utc_now($validTo), 'sent' => $days <= 7 ? [14, 7] : []]), $id]);
    }
    // Exposed files: one site has left its .env and Git repository public
    if (!in_array($story, ['paused', 'hostile'], true)) {
        $leaks = $url === 'https://cafe.example' ? ['.env', '.git/'] : [];
        $found = [];
        foreach (EXPOSED_FILES as $file) {
            if (in_array($file['label'], $leaks, true)) $found[$file['label']] ??= ['label' => $file['label'], 'what' => $file['what'], 'severity' => $file['severity'], 'url' => $url . '/' . $file['path']];
        }
        $found = array_values($found);
        $checked = count(EXPOSED_FILES);
        $exposed = $found
            ? ['status' => 'fail', 'summary' => count($found) . ' files that should be private are public: ' . list_words(array_column($found, 'label')), 'changed_at' => utc_now($now - 3 * 3600), 'details' => ['found' => $found, 'checked' => $checked, 'problem' => 'exposed']]
            : ['status' => 'ok', 'summary' => "Nothing that should be private is public ({$checked} files checked)", 'changed_at' => utc_now($month), 'details' => ['found' => [], 'checked' => $checked, 'problem' => '']];
        save_check_state($db, $id, 'exposed', ['fail_count' => 0] + $exposed, utc_now($now - 1800), utc_now($now + 1800));
        $db->prepare("UPDATE check_state SET alerted_status = ?, alerted_snapshot = ? WHERE site_id = ? AND check_name = 'exposed'")
            ->execute([$exposed['status'], json_encode(array_column($found, 'label')), $id]);
        if ($found) add_event($db, $id, 'exposed', ['status' => 'fail', 'previous' => 'ok', 'message' => $exposed['summary']], $exposed['changed_at']);
    }

    // Security headers: a spread of grades, from a hardened site to a bare one
    if (!in_array($story, ['paused', 'hostile'], true)) {
        $strong = ['strict-transport-security' => ['max-age=31536000'], 'content-security-policy' => ["default-src 'self'; frame-ancestors 'self'"], 'x-content-type-options' => ['nosniff'], 'referrer-policy' => ['strict-origin-when-cross-origin'], 'permissions-policy' => ['camera=(), microphone=()']];
        $forge = ['x-frame-options' => ['SAMEORIGIN'], 'x-content-type-options' => ['nosniff']];
        $sent = match ($url) {
            'https://www.example.com', 'https://example.org' => $strong,
            'https://store.example', 'https://docs.example' => ['content-security-policy' => ["script-src 'self' 'unsafe-inline'; frame-ancestors 'self'"]] + $strong,
            'https://shop.example', 'https://blog.example' => ['referrer-policy' => ['strict-origin-when-cross-origin'], 'permissions-policy' => ['camera=()']] + $forge,
            'https://builder.example' => [],
            'https://example.net' => ['referrer-policy' => ['no-referrer']] + $forge,
            default => $forge,
        };
        $graded = evaluate_headers(['status' => 200, 'errno' => 0, 'error' => '', 'url' => $url . '/', 'headers' => $sent], 10);
        $confirmed = confirm_headers(null, $graded)['details'];
        save_check_state($db, $id, 'headers', ['status' => $graded['status'], 'summary' => $graded['summary'], 'details' => $confirmed, 'fail_count' => 0, 'changed_at' => utc_now($month)], utc_now($now - 2 * 3600), utc_now($now + 4 * 3600));
        $db->prepare("UPDATE check_state SET alerted_status = ?, alerted_snapshot = ? WHERE site_id = ? AND check_name = 'headers'")
            ->execute([$graded['status'], json_encode($confirmed['confirmed']), $id]);
    }

    $alerted = in_array($story, ['down', 'snoozed', 'hostile'], true) ? 'fail' : 'ok';
    $db->prepare("UPDATE check_state SET alerted_status = ?, flapping_since = ?, snoozed_until = ? WHERE site_id = ? AND check_name = 'uptime'")->execute([
        $story === 'snoozed' ? 'snoozed' : $alerted,
        $story === 'unstable' ? utc_now($now - 35 * 60) : null,
        $story === 'snoozed' ? utc_now($now + 3 * 3600 + 20 * 60) : null,
        $id,
    ]);
}
$db->commit();
set_meta($db, 'last_run_at', utc_now($now - 20));

echo 'Seeded ' . count($sites) . " demo sites into {$path}\n";
