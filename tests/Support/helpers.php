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
 * Serve tests/fixtures/sites/<name> with `php -S` on 127.0.0.1:<port>, using
 * its index.php as the router. Several workers, so a slow request does not
 * hold up the others. Returns once the port accepts connections.
 */
function start_site(string $name, int $port): mixed
{
    $dir = __DIR__ . '/../fixtures/sites/' . $name;
    $proc = proc_open(
        [PHP_BINARY, '-S', "127.0.0.1:{$port}", '-t', $dir, $dir . '/index.php'],
        [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
        $pipes,
        null,
        ['PHP_CLI_SERVER_WORKERS' => '8', 'PATH' => getenv('PATH')],
    );
    for ($i = 0; $i < 100; $i++) {
        $sock = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.1);
        if ($sock) {
            fclose($sock);
            return $proc;
        }
        usleep(50_000);
    }
    throw new RuntimeException("Fixture site {$name} did not start on port {$port}");
}

/** Stop a fixture site. Its workers are child processes that outlive the parent's SIGTERM, so they go first. */
function stop_site(mixed $proc): void
{
    if (!is_resource($proc)) return;
    exec('pkill -TERM -P ' . (int) proc_get_status($proc)['pid']);
    proc_terminate($proc);
    proc_close($proc);
}

/** The config.php array for tests: defaults, plus $overrides. */
function test_config(array $overrides = []): array
{
    static $base;
    $base ??= load_config();
    return $overrides + $base;
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
