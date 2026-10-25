<?php

/**
 * Split argv (without the script name) into positional arguments and
 * --options. "--key=value" gives a string, "--flag" gives true, and
 * everything after "--" is positional.
 *
 * @return array{0: list<string>, 1: array<string, string|true>}
 */
function parse_args(array $argv): array
{
    $args = [];
    $opts = [];
    $rest = false;
    foreach ($argv as $arg) {
        if ($rest || !str_starts_with($arg, '--')) {
            $args[] = $arg;
        } elseif ($arg === '--') {
            $rest = true;
        } elseif (str_contains($arg, '=')) {
            [$key, $value] = explode('=', substr($arg, 2), 2);
            $opts[$key] = $value;
        } else {
            $opts[substr($arg, 2)] = true;
        }
    }
    return [$args, $opts];
}

/** Ask for a line of input (readline when available, so arrow keys and history work). */
function prompt(string $label = ''): string
{
    if (function_exists('readline')) {
        return trim((string) readline($label));
    }
    if ($label !== '') {
        echo $label;
        flush();
    }
    return trim((string) fgets(STDIN));
}

/** Print to stderr and exit with $code. */
function fail(string $message, int $code = 1): never
{
    fwrite(STDERR, $message . "\n");
    exit($code);
}
