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
 * Stop with a clear message when another process already listens on $port:
 * otherwise the tests would quietly talk to that process instead.
 */
function assert_port_free(int $port): void
{
    set_error_handler(fn () => true);
    $sock = fsockopen('127.0.0.1', $port, $errno, $errstr, 0.2);
    restore_error_handler();
    if (!$sock) return;
    fclose($sock);
    throw new RuntimeException("Port {$port} is already taken by another process, so the tests cannot start their server there. See what holds it with `lsof -nP -iTCP:{$port} -sTCP:LISTEN`.");
}

/**
 * Serve tests/fixtures/sites/<name> with `php -S` on 127.0.0.1:<port>, using
 * its index.php as the router, with $env for the router to read. Several
 * workers, so a slow request does not hold up the others. Returns once the
 * port accepts connections.
 */
function start_site(string $name, int $port, array $env = []): mixed
{
    assert_port_free($port);
    $dir = __DIR__ . '/../fixtures/sites/' . $name;
    $proc = proc_open(
        [PHP_BINARY, '-S', "127.0.0.1:{$port}", '-t', $dir, $dir . '/index.php'],
        [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
        $pipes,
        null,
        $env + ['PHP_CLI_SERVER_WORKERS' => '8', 'PATH' => getenv('PATH')],
    );
    // Refused connections are expected until the server is up; keep them from
    // reaching the test runner's error handler (which sees past @)
    set_error_handler(fn () => true);
    try {
        for ($i = 0; $i < 100; $i++) {
            $sock = fsockopen('127.0.0.1', $port, $errno, $errstr, 0.1);
            if ($sock) {
                fclose($sock);
                return $proc;
            }
            usleep(50_000);
        }
    } finally {
        restore_error_handler();
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
function vaka(array $args, array $env = [], ?string $stdin = null, array $ini = [], ?string $cwd = null): array
{
    static $dir;
    $dir ??= temp_dir();
    $env += [
        'VAKA_ENV_FILE' => '/dev/null',
        'DB_PATH' => $dir . '/vaka.sqlite',
        'USERS_FILE' => $dir . '/users.json',
        'PATH' => getenv('PATH'),
    ];
    $flags = [];
    foreach ($ini as $name => $value) array_push($flags, '-d', "{$name}={$value}");
    $cmd = array_merge([PHP_BINARY], $flags, [__DIR__ . '/../../vaka'], $args);
    $proc = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $cwd, $env);
    if ($stdin !== null) fwrite($pipes[0], $stdin);
    fclose($pipes[0]);
    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return ['exit' => proc_close($proc), 'out' => $out, 'err' => $err];
}

/** An alert notice for the alert tests, with sensible defaults. */
function notice(array $overrides = []): array
{
    return $overrides + [
        'site_id' => 1,
        'url' => 'https://shop.example',
        'group' => 'Customers',
        'kind' => 'down',
        'summary' => 'No answer within 10 s',
        'since' => '2026-10-25T12:00:00Z',
        'down_seconds' => 0,
        'server_key' => 'ip:203.0.113.10',
        'server' => '203.0.113.10',
        'destinations' => [['type' => 'slack', 'target' => 'https://hooks.example/a', 'key' => 'slack:https://hooks.example/a']],
    ];
}

function down_sites(int $count, array $overrides = []): array
{
    return array_map(fn ($i) => notice(['site_id' => $i, 'url' => "https://site{$i}.example"] + $overrides), range(1, $count));
}

/**
 * Serve Vaka's public/ with `php -S` on 127.0.0.1:<port>, with its own
 * database and users file from $env. Returns once the port answers.
 */
function start_web(int $port, array $env): mixed
{
    assert_port_free($port);
    $public = __DIR__ . '/../../public';
    $proc = proc_open(
        [PHP_BINARY, '-S', "127.0.0.1:{$port}", '-t', $public],
        [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
        $pipes,
        null,
        $env + ['VAKA_ENV_FILE' => '/dev/null', 'PHP_CLI_SERVER_WORKERS' => '4', 'PATH' => getenv('PATH')],
    );
    set_error_handler(fn () => true);
    try {
        for ($i = 0; $i < 100; $i++) {
            $sock = fsockopen('127.0.0.1', $port, $errno, $errstr, 0.1);
            if ($sock) {
                fclose($sock);
                return $proc;
            }
            usleep(50_000);
        }
    } finally {
        restore_error_handler();
    }
    throw new RuntimeException("Vaka did not start on port {$port}");
}

/**
 * One HTTP request to the test web server, with a cookie jar file so a
 * login carries over between calls that share it.
 *
 * @return array{status: int, headers: array<string, string>, body: string, json: mixed}
 */
function http_request(string $method, string $url, array $options = []): array
{
    $handle = curl_init($url);
    $headers = [];
    curl_setopt_array($handle, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_HTTPHEADER => $options['headers'] ?? [],
        CURLOPT_HEADERFUNCTION => function ($handle, string $line) use (&$headers) {
            if (str_contains($line, ':')) {
                [$name, $value] = explode(':', $line, 2);
                $headers[strtolower(trim($name))] = trim($value);
            }
            return strlen($line);
        },
    ]);
    if (isset($options['jar'])) {
        curl_setopt($handle, CURLOPT_COOKIEFILE, $options['jar']);
        curl_setopt($handle, CURLOPT_COOKIEJAR, $options['jar']);
    }
    if (isset($options['form'])) curl_setopt($handle, CURLOPT_POSTFIELDS, http_build_query($options['form']));
    if (isset($options['json'])) {
        curl_setopt($handle, CURLOPT_POSTFIELDS, json_encode($options['json']));
        curl_setopt($handle, CURLOPT_HTTPHEADER, [...($options['headers'] ?? []), 'Content-Type: application/json']);
    }
    $body = (string) curl_exec($handle);
    return ['status' => (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE), 'headers' => $headers, 'body' => $body, 'json' => json_decode($body, true)];
}
