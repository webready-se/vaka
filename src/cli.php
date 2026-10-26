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

/** Fail on options a command does not know, so a typo is not silently ignored. */
function allow_options(array $opts, array $known): void
{
    foreach (array_keys($opts) as $name) {
        if (!in_array($name, $known, true)) {
            fail("Unknown option --{$name}. Run `php vaka help` to see the options.");
        }
    }
}

/**
 * Ask for a line of input. Readline (arrow keys, history) on a terminal;
 * plain reads from a pipe, so answers can be scripted.
 */
function prompt(string $label = ''): string
{
    if (function_exists('readline') && stream_isatty(STDIN)) {
        return trim((string) readline($label));
    }
    if ($label !== '') {
        echo $label;
        flush();
    }
    $line = fgets(STDIN);
    if (!stream_isatty(STDIN)) echo "\n";
    return trim((string) $line);
}

/**
 * Why a file given on the command line cannot be read, and where it was
 * looked for. From a directory that is a link (Forge's current/), ".." leads
 * out of the real directory, not the one the shell shows: then the message
 * says so and gives the full path to use, for $command.
 */
function unreadable_file(string $path, string $command): string
{
    if ($path === '' || $path[0] === '/') return "Cannot read {$path}.";
    $real = getcwd();
    $dir = realpath(dirname("{$real}/{$path}"));
    $message = "Cannot read {$path}: there is no " . ($dir === false ? "{$real}/{$path}" : $dir . '/' . basename($path)) . '.';

    $shown = (string) getenv('PWD');
    if ($shown === '' || $shown === $real || realpath($shown) !== $real) return $message;
    // Resolve the path the way the shell shows it, ".." and all
    $parts = [];
    foreach (explode('/', "{$shown}/{$path}") as $part) {
        if ($part === '..') array_pop($parts);
        elseif ($part !== '' && $part !== '.') $parts[] = $part;
    }
    $meant = '/' . implode('/', $parts);
    $message .= "\n{$shown} is a link to {$real}, so \"..\" leads out of the real directory, not the one your shell shows.";
    return $message . (is_file($meant) ? " Give the full path:\n  " . basename(PHP_BINARY) . " vaka {$command} {$meant}" : ' Give the full path instead.');
}

/** Ask with a default that Enter accepts. */
function ask(string $label, string $default = ''): string
{
    $answer = prompt($default === '' ? "{$label}: " : "{$label} [{$default}]: ");
    return $answer === '' ? $default : $answer;
}

/** Yes or no, where only an explicit yes counts. */
function confirm(string $question, bool $default = false): bool
{
    return parse_bool(prompt($question . ($default ? ' (Y/n) ' : ' (y/N) '))) ?? $default;
}

/**
 * Pick one of $options (value => label) by number. Enter picks $default,
 * or returns null when there is none.
 */
function choose(string $question, array $options, int|string|null $default = null): int|string|null
{
    $values = array_keys($options);
    // Nothing to choose between: take it, and say so
    if (count($values) === 1) {
        echo "{$question} {$options[$values[0]]} (the only one)\n";
        return $values[0];
    }
    echo $question . "\n";
    foreach ($values as $i => $value) {
        echo sprintf("  %d) %s\n", $i + 1, $options[$value]);
    }
    $defaultNumber = $default === null ? '' : (string) (array_search($default, $values, true) + 1);
    $answer = ask('Choose', $defaultNumber);
    if ($answer === '') return null;
    if (!ctype_digit($answer) || !isset($values[(int) $answer - 1])) {
        fail("\"{$answer}\" is not one of the numbers above.");
    }
    return $values[(int) $answer - 1];
}

/**
 * Pick several of $options by number: comma-separated, or ranges like 3-7.
 * Returns the picked keys, true for 0 (all of them), or null for Enter.
 * $hint says what 0 and Enter mean for the question.
 */
function choose_many(string $question, array $options, string $hint): array|bool|null
{
    echo $question . "\n";
    $values = array_keys($options);
    foreach ($values as $i => $value) echo sprintf("  %d) %s\n", $i + 1, $options[$value]);
    $answer = trim(ask("Numbers, comma-separated or ranges like 3-7 ({$hint})"));
    if ($answer === '') return null;
    if ($answer === '0') return true;
    $picked = [];
    foreach (preg_split('/[\s,]+/', $answer) as $part) {
        [$from, $to] = preg_match('/^(\d+)-(\d+)$/', $part, $m) ? [(int) $m[1], (int) $m[2]] : [(int) $part, (int) $part];
        if (!preg_match('/^\d+(-\d+)?$/', $part) || $from < 1 || $to > count($values) || $from > $to) {
            fail("\"{$part}\" is not one of the numbers above.");
        }
        for ($n = $from; $n <= $to; $n++) $picked[] = $values[$n - 1];
    }
    return array_values(array_unique($picked));
}

/** Rows as aligned columns, with a header line. */
function print_table(array $headers, array $rows): void
{
    $widths = array_map('text_length', $headers);
    foreach ($rows as $row) {
        foreach (array_values($row) as $i => $cell) {
            $widths[$i] = max($widths[$i], text_length((string) $cell));
        }
    }
    $line = function (array $cells) use ($widths) {
        $out = '';
        foreach (array_values($cells) as $i => $cell) {
            $cell = (string) $cell;
            $out .= $cell . str_repeat(' ', $widths[$i] - text_length($cell) + 2);
        }
        echo rtrim($out) . "\n";
    };
    $line($headers);
    foreach ($rows as $row) $line($row);
}

/** "1 site", "3 sites". */
function plural(int $count, string $one, string $many): string
{
    return $count . ' ' . ($count === 1 ? $one : $many);
}

/** Print to stderr and exit with $code. */
function fail(string $message, int $code = 1): never
{
    fwrite(STDERR, $message . "\n");
    exit($code);
}
