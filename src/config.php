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
