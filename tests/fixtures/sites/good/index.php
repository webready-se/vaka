<?php

/**
 * Fixture site that is up. Router for `php -S`: existing files are served
 * as they are, everything else gets the shop page. A few paths and query
 * parameters make it misbehave on purpose.
 */

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if ($path !== '/' && is_file(__DIR__ . $path)) return false;

if (isset($_GET['sleep'])) sleep((int) $_GET['sleep']);

// Under /botcheck a host's bot protection answers every path, with 200, instead of the site
if (str_starts_with($path, '/botcheck')) {
    header('Content-Type: text/html');
    readfile(__DIR__ . '/../../bot-check.html');
    exit;
}

switch ($path) {
    case '/redirect':
        header('Location: /', true, 301);
        exit;
    case '/loop':
        header('Location: /loop', true, 302);
        exit;
    case '/heartbeat':
        // Records each ping, so a test can see that the run pinged
        file_put_contents((string) $_GET['log'], gmdate('c') . "\n", FILE_APPEND);
        echo 'OK';
        exit;
    case '/cdn':
        header('Server: cloudflare');
        header('CF-RAY: 0123456789abcdef-ARN');
        break;
}

header('Content-Type: text/html; charset=utf-8');
echo '<!doctype html><html lang="en"><title>Shop</title><h1>Welcome to the shop</h1><p>R&auml;ksm&ouml;rg&aring;s</p></html>';
