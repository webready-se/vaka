<?php

/**
 * A fresh directory under the system temp dir, removed when the test process exits.
 */
function temp_dir(): string
{
    $dir = sys_get_temp_dir() . '/vaka-test-' . getmypid() . '-' . bin2hex(random_bytes(4));
    mkdir($dir, 0700, true);
    register_shutdown_function(fn () => exec('rm -rf ' . escapeshellarg($dir)));
    return $dir;
}

/**
 * The array config.php returns for the given environment, read in a subprocess
 * so the test process's own environment is left alone. The local .env is never read.
 */
function load_config(array $env = []): array
{
    $code = 'echo json_encode(require $argv[1]);';
    $env += ['VAKA_ENV_FILE' => '/dev/null', 'PATH' => getenv('PATH')];
    $proc = proc_open([PHP_BINARY, '-r', $code, __DIR__ . '/../../config.php'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);
    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    proc_close($proc);
    if ($err !== '') throw new RuntimeException($err);
    return json_decode($out, true);
}

/**
 * Environment for a CLI test with its own database and users file, so
 * state carries over between vaka() calls in one test and no further.
 */
function cli_env(): array
{
    $dir = temp_dir();
    return ['DB_PATH' => $dir . '/vaka.sqlite', 'USERS_FILE' => $dir . '/users.json'];
}

/**
 * Run `php vaka ...` as a subprocess. The local .env is never read, and the
 * database and users file live in a temp dir unless $env says otherwise.
 *
 * @return array{exit: int, out: string, err: string}
 */
function vaka(array $args, array $env = [], ?string $stdin = null): array
{
    static $dir;
    $dir ??= temp_dir();
    $env += [
        'VAKA_ENV_FILE' => '/dev/null',
        'DB_PATH' => $dir . '/vaka.sqlite',
        'USERS_FILE' => $dir . '/users.json',
        'PATH' => getenv('PATH'),
    ];
    $cmd = array_merge([PHP_BINARY, __DIR__ . '/../../vaka'], $args);
    $proc = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);
    if ($stdin !== null) fwrite($pipes[0], $stdin);
    fclose($pipes[0]);
    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return ['exit' => proc_close($proc), 'out' => $out, 'err' => $err];
}
