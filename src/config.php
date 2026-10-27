<?php

/**
 * KEY=value pairs from .env contents. Comments (#), blank lines and lines
 * without = are skipped; one pair of surrounding double quotes is removed.
 */
function parse_env(string $contents): array
{
    $env = [];
    foreach (preg_split('/\R/', $contents) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) continue;
        [$key, $value] = explode('=', $line, 2);
        $value = trim($value);
        if (strlen($value) >= 2 && $value[0] === '"' && $value[-1] === '"') {
            $value = substr($value, 1, -1);
        }
        $env[trim($key)] = $value;
    }
    return $env;
}

/**
 * Load a .env file into $_ENV. OS environment variables win, so a value set
 * by the host (Forge, Docker, CI) is never overridden by the file.
 */
function load_env(string $file): void
{
    // Some PHP setups leave $_ENV empty (variables_order without E)
    foreach (getenv() as $k => $v) $_ENV[$k] ??= $v;

    if (!is_file($file)) return;
    foreach (parse_env(file_get_contents($file)) as $k => $v) $_ENV[$k] ??= $v;
}

/**
 * An absolute path as is, a relative one against the project root. On a Forge
 * zero-downtime deploy the root is a releases/<n> directory that is replaced on
 * every deploy, so relative paths resolve to the site root that outlives it.
 */
function resolve_path(string $path, string $root): string
{
    if ($path !== '' && $path[0] === '/') return $path;
    if (preg_match('#^(/home/forge/[^/]+)/releases/\d+#', $root, $m)) {
        $root = $m[1];
    }
    return rtrim($root, '/') . '/' . $path;
}

/**
 * The .env file for a project root: next to the code, or on a Forge
 * zero-downtime deploy in the site root, where Forge's Environment tab keeps
 * it (whether or not a release links to it).
 */
function env_file(string $root): string
{
    return resolve_path('.env', $root);
}

/** Where Vaka's commands run from: $root, or on a Forge zero-downtime deploy current/, which follows every release. */
function run_dir(string $root): string
{
    if (preg_match('#^(/home/forge/[^/]+)/releases/\d+#', $root, $m)) return $m[1] . '/current';
    return rtrim($root, '/');
}

/**
 * The PHP command for this server: the CLI of the same version as the PHP
 * running now (php8.5 next to php-fpm8.5 on Forge and other Debian or Ubuntu
 * servers, where plain php may be another version), else php.
 */
function php_cli(array $dirs = ['/usr/bin', '/usr/local/bin']): string
{
    $versioned = 'php' . PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;
    foreach ($dirs as $dir) {
        if (is_executable("{$dir}/{$versioned}")) return $versioned;
    }
    return 'php';
}

/** The cron line that runs Vaka every minute from run_dir(). */
function cron_line(string $root, string $php = 'php'): string
{
    return "* * * * * {$php} " . run_dir($root) . '/vaka run';
}

/** "se=whois.iis.se,nu=whois.iis.nu" as key => value, keys lowercased. Pairs without both halves are dropped. */
function pair_list(string $value): array
{
    $pairs = [];
    foreach (explode(',', $value) as $part) {
        [$key, $val] = array_map('trim', explode('=', $part, 2)) + [1 => ''];
        if ($key !== '' && $val !== '') $pairs[strtolower($key)] = $val;
    }
    return $pairs;
}

/** "14,7,1" as unique integers, largest first. Anything that is not a number is dropped. */
function int_list(string $value): array
{
    $ints = [];
    foreach (explode(',', $value) as $part) {
        $part = trim($part);
        if (ctype_digit($part)) $ints[] = (int) $part;
    }
    $ints = array_values(array_unique($ints));
    rsort($ints);
    return $ints;
}

/**
 * The time zone from TIMEZONE. Unset is the server's ($server, php.ini's),
 * and so is a name PHP does not know, with a problem that says so and what
 * was probably meant: a typo must never stop the checks.
 *
 * @return array{zone: string, problem: string}
 */
function timezone_setting(string $value, string $server): array
{
    $value = trim($value);
    $zones = DateTimeZone::listIdentifiers(DateTimeZone::ALL_WITH_BC);
    if ($value === '' || in_array($value, $zones, true)) return ['zone' => $value === '' ? $server : $value, 'problem' => ''];

    $guess = null;
    $best = 4;
    foreach ($zones as $zone) {
        $distance = levenshtein(strtolower($value), strtolower($zone));
        if ($distance < $best) [$guess, $best] = [$zone, $distance];
    }
    return ['zone' => $server, 'problem' => "TIMEZONE \"{$value}\" is not a time zone, so Vaka uses the server's, {$server}. "
        . ($guess !== null ? "Did you mean {$guess}?" : 'Use a name such as Europe/Stockholm.')];
}

/**
 * The clock format from TIME_FORMAT: 24h (14:05, the default) or 12h
 * (2:05 PM), the names clock settings usually have.
 *
 * @return array{format: string, problem: string}
 */
function time_format_setting(string $value): array
{
    $value = strtolower(trim($value));
    if ($value === '' || $value === '24h' || $value === '12h') return ['format' => $value === '12h' ? '12h' : '24h', 'problem' => ''];
    return ['format' => '24h', 'problem' => "TIME_FORMAT \"{$value}\" is neither 24h nor 12h, so Vaka uses 24h."];
}

/** A time of day in TIME_FORMAT: "14:05" or "2:05 PM". */
function clock_text(DateTimeInterface $time, string $format): string
{
    return $time->format($format === '12h' ? 'g:i A' : 'H:i');
}

/** A stored "HH:MM" (a maintenance window's start or end) in TIME_FORMAT. */
function hhmm_text(string $hhmm, string $format): string
{
    return clock_text(new DateTimeImmutable("2000-01-01 {$hhmm}"), $format);
}
