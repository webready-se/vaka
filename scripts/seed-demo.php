<?php

/**
 * A demo database with fake sites, for trying the dashboard, screenshots and
 * the smoke test:
 *
 *   php scripts/seed-demo.php <new.sqlite> [--hostile] [--token=vaka_...] [--refresh]
 *
 * Reserved example names and documentation IPs only. --hostile adds names
 * and messages full of markup, which the dashboard must show as plain text.
 *
 * For the live demo, whose share link opens it: --token stores that read
 * token for every group (labelled "Live demo", no last day), so the link
 * keeps working; --refresh seeds an existing database again, in place and in
 * one transaction, so a reader sees the old demo or the new one, never half
 * of either (a file replaced under an open WAL database can be broken by the
 * old one's WAL). Everything is dated from now, so a run every minute keeps it
 * as if Vaka had just looked; the site ids stay the same, so an open site does too.
 */

require __DIR__ . '/../src/bootstrap.php';

[$args, $opts] = parse_args(array_slice($argv, 1));
$path = $args[0] ?? fail('Usage: php scripts/seed-demo.php <new.sqlite> [--hostile] [--token=vaka_...] [--refresh]');
$refresh = isset($opts['refresh']);
if (file_exists($path) && !$refresh) fail("{$path} already exists. Give the path of a new file, or --refresh to seed it again in place.");
$token = is_string($opts['token'] ?? null) ? $opts['token'] : null;
if ($token !== null && !is_share_token($token)) fail('--token is a read token: vaka_ and 64 hex characters.');

$db = get_db($path);
$now = time();
mt_srand(42);
$month = $now - 30 * 86400;

// One transaction for all of it: in place, the old demo goes and the new one comes at once
$db->beginTransaction();
if ($refresh) {
    foreach (['share_groups', 'share_tokens', 'samples', 'events', 'check_state', 'maintenance', 'pauses', 'sites', 'servers', 'groups'] as $table) $db->exec("DELETE FROM {$table}");
}

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
    ['https://example.net', null, '198.51.100.7', '', 200, 'blocked'],
];
if (isset($opts['hostile'])) {
    add_group($db, ['name' => '"><img src=x data-xss="group">']);
    $sites[] = ['https://xss.example/?q=<script>window.__xss=1</script>', '"><img src=x data-xss="group">', '192.0.2.66', '', 300, 'hostile'];
}

$insertSample = $db->prepare('INSERT INTO samples (site_id, checked_at, status_code, ms, ok, ip) VALUES (?, ?, ?, ?, ?, ?)');
foreach ($sites as [$url, $group, $ip, $cdn, $ms, $story]) {
    // The hardened site checks uptime on a path that tests its database too
    $id = add_site($db, ['url' => $url, 'group' => $group ?? 'none', 'paused' => $story === 'paused', 'keyword' => $story === 'hostile' ? '<script>window.__xss=3</script>' : '', 'check_path' => $url === 'https://www.example.com' ? '/up' : '']);
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
    $downCode = $story === 'down' ? 503 : 0;
    $downWords = $story === 'down' ? 'Down: the site answered 503 Service Unavailable' : 'Down: no answer within 10 s';
    foreach (array_reverse($outages) as [$from, $to]) {
        add_event($db, $id, 'uptime', ['status' => 'fail', 'previous' => 'ok', 'message' => $downWords], utc_now($now - $from));
        if ($to > 0) add_event($db, $id, 'uptime', ['status' => 'ok', 'previous' => 'fail', 'message' => 'Up again after ' . human_duration($from - $to) . ': answered 200 OK in ' . $ms . ' ms'], utc_now($now - $to));
    }
    // Further back, for the 30 day bars: restarts in the Nightly group's window (planned), and two outages
    $at = fn (int $daysAgo, int $hour) => (new DateTimeImmutable('@' . ($now - $daysAgo * 86400)))->setTimezone(new DateTimeZone('Europe/Stockholm'))->setTime($hour, 0)->getTimestamp();
    $older = match (true) {
        $group === 'Nightly' && $story === 'up' => [[$at(4, 3), 6 * 60, 'maintenance'], [$at(11, 3), 9 * 60, 'maintenance'], [$at(18, 3), 6 * 60, 'maintenance'], [$at(25, 3), 7 * 60, 'maintenance']],
        $url === 'https://www.example.com' => [[$at(9, 14), 25 * 60, '']],
        $url === 'https://cafe.example' => [[$at(16, 10), 100 * 60, '']],
        default => [],
    };
    foreach ($older as [$start, $length, $silencedBy]) {
        add_event($db, $id, 'uptime', ['status' => 'fail', 'previous' => 'ok', 'message' => 'Down: no answer within 10 s'], utc_now($start), $silencedBy);
        add_event($db, $id, 'uptime', ['status' => 'ok', 'previous' => 'fail', 'message' => 'Up again after ' . human_duration($length) . ': answered 200 OK in ' . $ms . ' ms'], utc_now($start + $length), $silencedBy);
    }
    // Paused for a few days last week: grey days, and two that were paused part of the day
    if ($url === 'https://docs.example') {
        $db->prepare('INSERT INTO pauses (site_id, started_at, ended_at) VALUES (?, ?, ?)')->execute([$id, utc_now($at(8, 11)), utc_now($at(5, 15))]);
    }

    // When it last came back: its last outage's end, or a month ago when it has had none
    $lastUp = max([$month, ...array_map(fn ($o) => $now - $o[1], array_filter($outages, fn ($o) => $o[1] > 0)), ...array_map(fn ($o) => $o[0] + $o[1], $older)]);

    if ($story !== 'paused') {
        for ($t = $now - 86400 + 300; $t <= $now; $t += 300) {
            // Behind a bot check for the last three hours: no samples of the site
            if ($story === 'blocked' && $t >= $now - 3 * 3600) break;
            $down = false;
            // An outage still going ($to 0) covers the sample taken just now too
            foreach ($outages as [$from, $to]) $down = $down || ($t >= $now - $from && ($to === 0 || $t < $now - $to));
            $jitter = (int) round($ms * (0.8 + mt_rand(0, 60) / 100) + (date('G', $t) >= 9 && date('G', $t) <= 17 ? $ms * 0.3 : 0));
            $insertSample->execute([$id, utc_now($t), $down ? $downCode : 200, $down ? 0 : $jitter, $down ? 0 : 1, $ip]);
        }
    }

    $state = match ($story) {
        'down' => ['status' => 'fail', 'summary' => 'The site answered 503 Service Unavailable', 'fail_count' => 14, 'changed_at' => utc_now($now - 14 * 60), 'details' => ['status_code' => 503, 'ms' => 0, 'ip' => $ip, 'cdn' => '', 'problem' => 'http', 'failing_since' => utc_now($now - 14 * 60)]],
        'snoozed' => ['status' => 'fail', 'summary' => 'No answer within 10 s', 'fail_count' => 40, 'changed_at' => utc_now($now - 40 * 60), 'details' => ['status_code' => 0, 'ms' => 0, 'ip' => $ip, 'cdn' => '', 'problem' => 'timeout', 'failing_since' => utc_now($now - 40 * 60)]],
        'failing' => ['status' => 'ok', 'summary' => 'Failed once, checking again: no answer within 10 s', 'fail_count' => 1, 'changed_at' => utc_now($month), 'details' => ['status_code' => 0, 'ms' => 0, 'ip' => $ip, 'cdn' => '', 'problem' => 'timeout', 'failing_since' => utc_now($now - 40)]],
        'blocked' => ['status' => 'ok', 'summary' => "Imunify360's bot check answered instead of the site", 'fail_count' => 0, 'changed_at' => utc_now($month), 'details' => ['blocked' => 'Imunify360', 'blocked_since' => utc_now($now - 3 * 3600), 'from_ip' => '2001:db8::5', 'status_code' => 200, 'ms' => $ms, 'ip' => $ip, 'cdn' => '', 'problem' => '']],
        'hostile' => ['status' => 'fail', 'summary' => 'Answered 200 OK, but the page does not contain "<script>window.__xss=3</script>"', 'fail_count' => 2, 'changed_at' => utc_now($now - 120), 'details' => ['status_code' => 200, 'ms' => 300, 'ip' => $ip, 'cdn' => '', 'problem' => 'keyword']],
        default => ['status' => 'ok', 'summary' => "Answered 200 OK in {$ms} ms", 'fail_count' => 0, 'changed_at' => utc_now($lastUp), 'details' => ['status_code' => 200, 'ms' => $ms, 'ip' => $ip, 'cdn' => $cdn, 'problem' => '']],
    };
    if ($story !== 'paused') save_check_state($db, $id, 'uptime', $state, utc_now($now - 20), utc_now($now + 280));

    // Certificates (90 days, as Let's Encrypt's): most renewed a while ago, one about to expire, one that missed its renewal, and Cloudflare's own a month from its renewal
    $days = match ($url) {
        'https://booking.example' => 6,
        'https://deals1.example' => 22,
        'https://store.example' => 31,
        default => 40 + mt_rand(0, 45),
    };
    $validTo = $now + $days * 86400 + 3600;
    $issuer = $cdn === 'Cloudflare' ? 'Google Trust Services' : "Let's Encrypt";
    $ssl = $days <= 14
        ? ['status' => 'warn', 'summary' => "The certificate expires in {$days} days (" . date('j M Y', $validTo) . ')', 'problem' => 'expiring']
        : ['status' => 'ok', 'summary' => ($cdn === 'Cloudflare' ? "Cloudflare's certificate, which Cloudflare renews by itself: valid" : 'Valid') . ' until ' . date('j M Y', $validTo) . " ({$days} days), issued by {$issuer}", 'problem' => ''];
    if (!in_array($story, ['paused', 'hostile'], true)) {
        save_check_state($db, $id, 'ssl', ['status' => $ssl['status'], 'summary' => $ssl['summary'], 'fail_count' => 0, 'changed_at' => utc_now($month), 'details' => ['days_left' => $days, 'valid_from' => utc_now($validTo - 90 * 86400), 'valid_to' => utc_now($validTo), 'issuer' => $issuer, 'problem' => $ssl['problem'], 'renewed_by' => $cdn === 'Cloudflare' ? 'Cloudflare' : null]], utc_now($now - 3600), utc_now($now + 5 * 3600));
        // Told when it passed its threshold, as a run would: a shared page says so
        if ($ssl['status'] !== 'ok') $db->prepare("UPDATE check_state SET alerted_status = ?, alerted_at = ? WHERE site_id = ? AND check_name = 'ssl'")->execute([$ssl['status'], utc_now($now - 6 * 3600), $id]);
        $db->prepare("UPDATE check_state SET alerted_status = ?, thresholds_sent = ? WHERE site_id = ? AND check_name = 'ssl'")
            ->execute([$ssl['status'], json_encode(['valid_to' => utc_now($validTo), 'sent' => $days <= 7 ? [14, 7] : []]), $id]);
    }
    // Domains: registered for a year or more, except one that runs out in 12 days and one due for renewal in 45
    if (!in_array($story, ['paused', 'hostile'], true)) {
        $name = registrable_candidates((string) parse_url($url, PHP_URL_HOST))[0];
        $until = $now + match ($url) {
            'https://docs.example' => 12 * 86400 + 7200,
            'https://example.org' => 45 * 86400 + 7200,
            default => (120 + mt_rand(0, 600)) * 86400,
        };
        $domain = evaluate_domain(['status' => 'found', 'domain' => $name, 'expires' => $until, 'registrar' => 'Example Registrar AB', 'source' => 'rdap', 'error' => ''], $now, 30);
        save_check_state($db, $id, 'domain', ['fail_count' => 0, 'changed_at' => utc_now($month)] + $domain, utc_now($now - 5 * 3600), utc_now($now + 19 * 3600));
        $db->prepare("UPDATE check_state SET alerted_status = ?, thresholds_sent = ? WHERE site_id = ? AND check_name = 'domain'")
            ->execute([$domain['status'], json_encode(['expires' => utc_now($until), 'sent' => $domain['status'] === 'warn' ? [30, 14] : []]), $id]);
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
        if ($found) $db->prepare("UPDATE check_state SET alerted_status = ?, alerted_at = ? WHERE site_id = ? AND check_name = 'exposed'")->execute([$exposed['status'], utc_now(strtotime($exposed['changed_at']) + 60), $id]);
    }

    // Security headers: a spread of grades, from a hardened site to a bare one
    if (!in_array($story, ['paused', 'hostile'], true)) {
        $strong = ['strict-transport-security' => ['max-age=31536000'], 'content-security-policy' => ["default-src 'self'; frame-ancestors 'self'"], 'x-content-type-options' => ['nosniff'], 'referrer-policy' => ['strict-origin-when-cross-origin'], 'permissions-policy' => ['camera=(), microphone=()']];
        $forge = ['x-frame-options' => ['SAMEORIGIN'], 'x-content-type-options' => ['nosniff']];
        $sent = match ($url) {
            // The CSP set in the page with a meta tag, as a framework writes it, so frame protection is X-Frame-Options'
            'https://www.example.com' => ['x-frame-options' => ['SAMEORIGIN']] + array_diff_key($strong, ['content-security-policy' => 0]),
            // A statistics script the policy lets run but not send back to its host
            'https://example.org' => ['content-security-policy' => ["default-src 'self'; script-src 'self' https://stats.example; frame-ancestors 'self'"]] + $strong,
            'https://store.example', 'https://docs.example' => ['content-security-policy' => ["default-src 'self'; script-src 'self' 'unsafe-inline'; frame-ancestors 'self'"]] + $strong,
            'https://shop.example', 'https://blog.example' => ['referrer-policy' => ['strict-origin-when-cross-origin'], 'permissions-policy' => ['camera=()']] + $forge,
            // A CSP tried as Report-Only: the page loads a script it would block once enforced
            'https://builder.example' => ['content-security-policy-report-only' => ["default-src 'self'"]],
            // A Report-Only CSP that still allows hosts the page no longer loads anything from
            'https://deals3.example' => ['content-security-policy-report-only' => ["default-src 'self'; script-src 'self' https://stats.example https://maps.example; style-src 'self' https://fonts.example 'unsafe-inline'; img-src 'self' https://stats.example; connect-src 'self' https://stats.example; object-src 'none'"], 'x-frame-options' => ['SAMEORIGIN']] + array_diff_key($strong, ['content-security-policy' => 0]),
            'https://example.net' => ['referrer-policy' => ['no-referrer']] + $forge,
            // Sent twice: the application's and the web server's, which its file gives away, and one the files cannot tell
            'https://booking.example' => ['x-frame-options' => ['DENY', 'SAMEORIGIN'], 'referrer-policy' => ['strict-origin-when-cross-origin'], 'permissions-policy' => ['camera=()']] + $forge,
            'https://deals1.example' => ['referrer-policy' => ['same-origin', 'no-referrer']] + $forge,
            default => $forge,
        };
        // The scripts and styles: nosniff on the hardened site's, lost by a cache rule on a typical one's (a hostile name with --hostile)
        $files = match ($url) {
            'https://www.example.com' => ['checked' => ['/assets/site.css', '/assets/app.js'], 'missing' => []],
            'https://shop.example' => ['checked' => $paths = ['/build/assets/site-4f2a9c.css', isset($opts['hostile']) ? '/build/<img src=x onerror="window.__xss=4">.js' : '/build/assets/app-81d3e0.js'], 'missing' => $paths],
            'https://booking.example' => ['checked' => [$file = isset($opts['hostile']) ? '/<img src=x onerror="window.__xss=6">.css' : '/assets/site.css'], 'missing' => [], 'sent' => ['x-frame-options' => [$file => ['SAMEORIGIN']]]],
            default => null,
        };
        // Without a CSP, the page's inline scripts and another host's are named (one with markup in it with --hostile)
        $body = match ($url) {
            'https://shop.example' => '<script>window.dataLayer = window.dataLayer || []; function gtag(){dataLayer.push(arguments);} gtag("js", new Date());</script>'
                . '<script>document.documentElement.dataset.theme = localStorage.theme || "light"</script><script src="https://analytics.example/a.js"></script>'
                . (isset($opts['hostile']) ? '<script>document.write("<img src=x onerror=window.__xss=5>")</script>' : ''),
            // A CSP in force that blocks a script the page loads: the policy has no cdn.example
            'https://docs.example' => '<script src="https://cdn.example/widget.js"></script><p style="margin:0">x</p><img src="https://images.example/a.jpg" alt="">',
            'https://example.org', 'https://deals3.example' => '<script src="https://stats.example/?js"></script>',
            'https://www.example.com' => '<html><head><meta http-equiv="content-security-policy" content="default-src \'self\'; frame-ancestors \'self\'"></head><body></body></html>',
            // A tag manager and Google Fonts: the case a drafted policy cannot finish
            'https://builder.example' => '<script>(function(w,d,s,l,i){w[l]=w[l]||[];w[l].push({"gtm.start":new Date().getTime(),event:"gtm.js"})})(window,document,"script","dataLayer","GTM-XXXX")</script><script src="https://www.googletagmanager.com/gtm.js?id=GTM-XXXX"></script><link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter"><p style="margin:0">x</p><script src="https://widgets.example/chat.js"></script>',
            default => '',
        };
        // The builder site sends its start page on to an app under a path, which the CSP notes name
        $page = $url === 'https://builder.example' ? $url . '/app/' : $url . '/';
        $graded = evaluate_headers(['status' => 200, 'errno' => 0, 'error' => '', 'url' => $page, 'headers' => $sent, 'body' => $body], 10, $files);
        $confirmed = confirm_headers(null, $graded)['details'];
        save_check_state($db, $id, 'headers', ['status' => $graded['status'], 'summary' => $graded['summary'], 'details' => $confirmed, 'fail_count' => 0, 'changed_at' => utc_now($month)], utc_now($now - 2 * 3600), utc_now($now + 4 * 3600));
        $db->prepare("UPDATE check_state SET alerted_status = ?, alerted_snapshot = ? WHERE site_id = ? AND check_name = 'headers'")
            ->execute([$graded['status'], json_encode($confirmed['confirmed']), $id]);
    }

    // Mail DNS: most domains have SPF and DMARC, some only monitor, a couple lack DMARC, and one has no email and no records
    if (!in_array($story, ['paused', 'hostile'], true)) {
        $domain = (string) parse_url($url, PHP_URL_HOST);
        $records = match ($url) {
            'https://cafe.example', 'https://builder.example' => ['mx' => ['10 mx1.mail.example'], 'spf' => ['v=spf1 include:_spf.mail.example ~all'], 'dmarc' => []],
            'https://example.net', 'https://deals2.example' => ['mx' => [], 'spf' => ['v=spf1 -all'], 'dmarc' => ["v=DMARC1; p=none; rua=mailto:dmarc@{$domain}"]],
            // A domain with no email at all, and nothing that says so: anyone can send in its name
            'https://deals3.example' => ['mx' => [], 'spf' => [], 'dmarc' => []],
            default => ['mx' => ['10 mx1.mail.example', '20 mx2.mail.example'], 'spf' => ['v=spf1 include:_spf.mail.example -all'], 'dmarc' => ["v=DMARC1; p=reject; rua=mailto:dmarc@{$domain}"]],
        };
        $mail = evaluate_mail_dns($domain, $records);
        $confirmed = confirm_mail_dns(null, $mail)['details'];
        save_check_state($db, $id, 'dns', ['status' => $mail['status'], 'summary' => $mail['summary'], 'details' => $confirmed, 'fail_count' => 0, 'changed_at' => utc_now($month)], utc_now($now - 3 * 3600), utc_now($now + 3 * 3600));
        $db->prepare("UPDATE check_state SET alerted_status = ?, alerted_snapshot = ? WHERE site_id = ? AND check_name = 'dns'")
            ->execute([$mail['status'], json_encode(['domain' => $domain, 'records' => $confirmed['confirmed']]), $id]);
    }

    $alerted = in_array($story, ['down', 'snoozed', 'hostile'], true) ? 'fail' : 'ok';
    // Told a minute after it went down, as a run would: a shared page says when
    $toldAt = ['down' => $now - 13 * 60, 'snoozed' => $now - 39 * 60, 'hostile' => $now - 60][$story] ?? null;
    $db->prepare("UPDATE check_state SET alerted_status = ?, alerted_at = ?, flapping_since = ?, snoozed_until = ? WHERE site_id = ? AND check_name = 'uptime'")->execute([
        $story === 'snoozed' ? 'snoozed' : $alerted,
        $toldAt === null ? null : utc_now($toldAt),
        $story === 'unstable' ? utc_now($now - 35 * 60) : null,
        $story === 'snoozed' ? utc_now($now + 3 * 3600 + 20 * 60) : null,
        $id,
    ]);
}
// The live demo's link: a read token for every group, as share:create makes one, but the same on every run
if ($token !== null) {
    $db->prepare('INSERT INTO share_tokens (token_hash, prefix, site_id, label, expires_at, created_at) VALUES (?, ?, NULL, ?, NULL, ?)')
        ->execute([share_token_hash($token), share_token_start($token), 'Live demo', utc_now($month)]);
    $tokenId = (int) $db->lastInsertId();
    foreach (list_groups($db) as $group) $db->prepare('INSERT INTO share_groups (token_id, group_id) VALUES (?, ?)')->execute([$tokenId, $group['id']]);
}
set_meta($db, 'last_run_at', utc_now($now - 20));
$db->commit();

echo 'Seeded ' . count($sites) . " demo sites into {$path}\n";
