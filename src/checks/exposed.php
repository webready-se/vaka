<?php

/** How often a site is checked for exposed files, and how often while something is public or the check could not run. */
const EXPOSED_INTERVAL_SECONDS = 24 * 3600;
const EXPOSED_RECHECK_SECONDS = 3600;

/** How much of each file Vaka reads: enough for every signature, little enough for a backup archive. */
const EXPOSED_MAX_BYTES = 65536;

/** Sites fetched together in one batch; the run budget is checked between batches. */
const EXPOSED_SITES_PER_BATCH = 5;

/**
 * Files that must never be public, and how each is recognised from its
 * content (see exposed_matches()). `fail` files hold secrets, source code or
 * data; `warn` files tell an attacker which software, versions and paths a
 * site has. Files with the same label are one finding.
 */
const EXPOSED_FILES = [
    ['path' => '.env', 'label' => '.env', 'severity' => 'fail', 'match' => 'env', 'what' => 'the environment file, often with database passwords and API keys'],
    ['path' => '.git/HEAD', 'label' => '.git/', 'severity' => 'fail', 'match' => 'git_head', 'what' => 'the Git repository: the site\'s source code and its history'],
    ['path' => '.git/config', 'label' => '.git/', 'severity' => 'fail', 'match' => 'git_config', 'what' => 'the Git repository: the site\'s source code and its history'],
    ['path' => 'wp-config.php.bak', 'label' => 'wp-config.php.bak', 'severity' => 'fail', 'match' => 'wp_config', 'what' => 'a copy of the WordPress settings, with the database password'],
    ['path' => 'backup.zip', 'label' => 'backup.zip', 'severity' => 'fail', 'match' => 'zip', 'what' => 'a backup of the site'],
    ['path' => 'site.zip', 'label' => 'site.zip', 'severity' => 'fail', 'match' => 'zip', 'what' => 'a backup of the site'],
    ['path' => 'db.sql', 'label' => 'db.sql', 'severity' => 'fail', 'match' => 'sql', 'what' => 'a copy of the database'],
    ['path' => 'dump.sql', 'label' => 'dump.sql', 'severity' => 'fail', 'match' => 'sql', 'what' => 'a copy of the database'],
    ['path' => 'backup.sql', 'label' => 'backup.sql', 'severity' => 'fail', 'match' => 'sql', 'what' => 'a copy of the database'],
    ['path' => 'db.sql.gz', 'label' => 'db.sql.gz', 'severity' => 'fail', 'match' => 'gzip', 'what' => 'a copy of the database'],
    ['path' => 'phpinfo.php', 'label' => 'phpinfo.php', 'severity' => 'warn', 'match' => 'phpinfo', 'what' => 'a PHP information page: versions, settings and paths on the server'],
    ['path' => 'info.php', 'label' => 'info.php', 'severity' => 'warn', 'match' => 'phpinfo', 'what' => 'a PHP information page: versions, settings and paths on the server'],
    ['path' => 'server-status', 'label' => 'server-status', 'severity' => 'warn', 'match' => 'server_status', 'what' => 'the Apache status page: who is visiting and which addresses they ask for'],
    ['path' => 'composer.json', 'label' => 'composer.json', 'severity' => 'warn', 'match' => 'composer_json', 'what' => 'the list of PHP packages the site uses'],
    ['path' => 'composer.lock', 'label' => 'composer.lock', 'severity' => 'warn', 'match' => 'composer_lock', 'what' => 'the exact versions of the PHP packages the site uses'],
    ['path' => 'package.json', 'label' => 'package.json', 'severity' => 'warn', 'match' => 'package_json', 'what' => 'the list of JavaScript packages the site uses'],
    ['path' => '.DS_Store', 'label' => '.DS_Store', 'severity' => 'warn', 'match' => 'ds_store', 'what' => 'a macOS folder index that lists the files next to it'],
];

/**
 * Whether $body looks like a file of type $type. Sites often answer any
 * address with a page of their own (a soft 404), so a file counts only when
 * its content says what it is, and a page (anything that starts with a tag)
 * never counts as a text file.
 */
function exposed_matches(string $type, string $body): bool
{
    $html = (bool) preg_match('/\A(?:\xEF\xBB\xBF)?\s*</', $body);
    $json = fn (string $keys) => !$html && preg_match('/\A\s*\{/', $body) && preg_match('/"(?:' . $keys . ')"\s*:/', $body);

    return match ($type) {
        'env' => !$html && preg_match_all('/^\s*(?:export\s+)?[A-Z][A-Z0-9_]*\s*=/m', $body) >= 2,
        'git_head' => (bool) preg_match('/\A(?:ref: refs\/\S+|[0-9a-f]{40})\s*\z/', $body),
        'git_config' => !$html && preg_match('/^\s*\[core\]/m', $body) && str_contains($body, 'repositoryformatversion'),
        'wp_config' => (bool) preg_match('/define\s*\(\s*[\'"]DB_(?:NAME|USER|PASSWORD)[\'"]/', $body),
        'zip' => str_starts_with($body, "PK\x03\x04"),
        'sql' => !$html && preg_match('/^(?:-- (?:MySQL dump|MariaDB dump|phpMyAdmin SQL Dump|PostgreSQL database dump)|\s*(?:CREATE TABLE|INSERT INTO|DROP TABLE)\b)/mi', $body),
        // A web server may send a .gz with Content-Encoding: gzip, and then curl unpacks it
        'gzip' => str_starts_with($body, "\x1f\x8b") || exposed_matches('sql', $body),
        'phpinfo' => (bool) preg_match('/<title>(?:PHP [\d.]+ - )?phpinfo\(\)<\/title>|class="p">PHP Version [\d.]+/i', $body),
        'server_status' => str_contains($body, 'Apache Server Status for'),
        'composer_json' => $json('require|require-dev|autoload'),
        'composer_lock' => $json('content-hash') && str_contains($body, '"packages"'),
        'package_json' => $json('dependencies|devDependencies|scripts'),
        'ds_store' => str_starts_with($body, "\x00\x00\x00\x01Bud1"),
    };
}

/** The folder a site's files are looked for in: its address without a file name or query, ending in a slash. */
function exposed_base(string $url): string
{
    $parts = parse_url($url);
    $path = $parts['path'] ?? '';
    if (str_contains(basename($path), '.')) $path = dirname($path);
    $origin = $parts['scheme'] . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');
    return $origin . rtrim($path, '/') . '/';
}

/** Whether a response is a public file of type $type. */
function exposed_found(string $type, array $response): bool
{
    return $response['errno'] === 0 && $response['status'] >= 200 && $response['status'] < 300 && exposed_matches($type, $response['body']);
}

/**
 * What the answers for the known files say: ok (none public), fail (a
 * file with secrets, code or data is public), warn (only files that tell
 * versions and paths), or unknown (the site did not answer). $baseline is
 * the answer for a made-up address: content that it gets too is the site's
 * answer to anything, not a file.
 *
 * @param array<string, array> $responses path => fetch_many() result
 * @return array{status: string, summary: string, details: array}
 */
function evaluate_exposed(array $responses, array $baseline, int $timeout): array
{
    if ($baseline['errno']) {
        return ['status' => 'unknown', 'summary' => 'Could not check: ' . lcfirst(connection_problem($baseline['errno'], $baseline['error'], $timeout)), 'details' => ['found' => [], 'checked' => 0, 'problem' => '']];
    }
    // The made-up page answered with a bot check: so does every file, and none of them was really looked at
    $by = bot_check($baseline);
    if ($by !== '') {
        return ['status' => 'unknown', 'summary' => 'Could not check: ' . bot_check_words($by), 'details' => ['found' => [], 'checked' => 0, 'problem' => '']];
    }

    $found = [];
    $checked = 0;
    foreach (EXPOSED_FILES as $file) {
        $response = $responses[$file['path']] ?? null;
        if ($response === null || $response['errno']) continue;
        $checked++;
        if (!exposed_found($file['match'], $response) || exposed_found($file['match'], $baseline)) continue;
        $found[$file['label']] ??= ['label' => $file['label'], 'what' => $file['what'], 'severity' => $file['severity'], 'url' => $response['url']];
    }
    $found = array_values($found);
    if ($checked === 0) {
        return ['status' => 'unknown', 'summary' => 'Could not check: none of the files answered', 'details' => ['found' => [], 'checked' => 0, 'problem' => '']];
    }

    $status = match (true) {
        in_array('fail', array_column($found, 'severity'), true) => 'fail',
        (bool) $found => 'warn',
        default => 'ok',
    };
    $summary = match (count($found)) {
        0 => "Nothing that should be private is public ({$checked} files checked)",
        1 => "{$found[0]['label']} is public: {$found[0]['what']}",
        default => count($found) . ' files that should be private are public: ' . list_words(array_column($found, 'label')),
    };
    $problem = ['fail' => 'exposed', 'warn' => 'exposed_minor', 'ok' => ''][$status];
    return ['status' => $status, 'summary' => $summary, 'details' => ['found' => $found, 'checked' => $checked, 'problem' => $problem]];
}

/**
 * Check several sites for exposed files in one parallel batch: every known
 * file next to each site, plus a made-up address as the baseline.
 *
 * @param array<int|string, string> $urls key => site URL
 * @return array<int|string, array> key => evaluate_exposed() result
 */
function check_exposed(array $urls, array $config): array
{
    $requests = [];
    foreach ($urls as $key => $url) {
        $base = exposed_base($url);
        foreach (EXPOSED_FILES as $file) $requests["{$key}:{$file['path']}"] = $base . $file['path'];
        $requests["{$key}:baseline"] = $base . 'vaka-' . bin2hex(random_bytes(8));
    }
    $responses = fetch_many($requests, $config['uptime_timeout'], user_agent($config), 20, $config['ssl_ca_file'], EXPOSED_MAX_BYTES);

    $results = [];
    foreach ($urls as $key => $url) {
        $answers = [];
        foreach (EXPOSED_FILES as $file) $answers[$file['path']] = $responses["{$key}:{$file['path']}"];
        $results[$key] = evaluate_exposed($answers, $responses["{$key}:baseline"], $config['uptime_timeout']);
    }
    return $results;
}

/**
 * The alert due for a site's exposed files, if any: files found that were
 * not told yet (exposed, or exposed_minor when none of them holds secrets),
 * or everything private again (exposed_fixed). A file that is taken down
 * while others stay public is noted without a message. `snapshot` is the
 * list of files to store as told.
 *
 * @return array{action: 'send'|'mark'|'none', kind: ?string, snapshot: string, new: list<array>, still: list<string>}
 */
function exposed_notice(array $state, ?array $group): array
{
    $status = $state['status'];
    $found = $state['details']['found'] ?? [];
    $labels = array_column($found, 'label');
    $told = json_decode($state['alerted_snapshot'] ?: '[]', true) ?: [];
    $new = array_values(array_filter($found, fn (array $file) => !in_array($file['label'], $told, true)));
    $result = fn (string $action, ?string $kind = null) => [
        'action' => $action,
        'kind' => $kind,
        'snapshot' => json_encode($labels),
        'new' => $new,
        'still' => array_values(array_diff($labels, array_column($new, 'label'))),
    ];

    if ($status === 'pending' || $status === 'unknown') return $result('none');
    $same = !array_diff($labels, $told) && !array_diff($told, $labels);
    if ($status === $state['alerted_status'] && $same) return $result('none');
    if (in_array($group['policy'] ?? '', ['silent', 'digest'], true)) return $result('mark');
    if ($new) return $result('send', in_array('fail', array_column($new, 'severity'), true) ? 'exposed' : 'exposed_minor');
    if ($status === 'ok' && in_array($state['alerted_status'], ['fail', 'warn'], true)) return $result('send', 'exposed_fixed');
    return $result('mark');
}
