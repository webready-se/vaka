<?php

/**
 * Fixture site for the security header check. / sends five of the six
 * protections (over plain http there is no HSTS), /weaker drops the CSP,
 * /error answers 500. /with-files and /cached-files load a stylesheet and a
 * script from the site: the /assets/ files carry nosniff like the pages, the
 * /cached/ ones answer from a cache rule with a header of its own and lose
 * it, as an nginx location with its own add_header does. /sent-twice sends
 * X-Frame-Options from both the application and the web server, and nothing
 * else: its /served/ file has only the web server's, as a static file does.
 */

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

if ($path === '/error') {
    http_response_code(500);
    echo 'Internal Server Error';
    exit;
}

if (str_starts_with($path, '/assets/') || str_starts_with($path, '/cached/')) {
    if (str_starts_with($path, '/assets/')) header('X-Content-Type-Options: nosniff');
    header('Cache-Control: public, max-age=31536000, immutable');
    header('Content-Type: ' . (str_ends_with($path, '.css') ? 'text/css' : 'text/javascript'));
    echo '/* a file */';
    exit;
}

if (str_starts_with($path, '/served/')) {
    header('X-Frame-Options: SAMEORIGIN');
    header('Content-Type: text/css');
    echo '/* a file */';
    exit;
}

if ($path === '/sent-twice') {
    header('X-Frame-Options: DENY');
    header('X-Frame-Options: SAMEORIGIN', false);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><title>Shop</title><link rel="stylesheet" href="/served/site.css"><h1>Welcome to the shop</h1>';
    exit;
}

if ($path === '/weaker') {
    header('X-Frame-Options: SAMEORIGIN');
} else {
    header("Content-Security-Policy: default-src 'self'; frame-ancestors 'self'");
}
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('Permissions-Policy: camera=(), microphone=()');
header('Content-Type: text/html; charset=utf-8');
$files = match ($path) {
    '/with-files' => '<link rel="stylesheet" href="/assets/site.css"><script src="assets/app.js" defer></script>',
    '/cached-files' => '<link rel="stylesheet" href="/cached/site.css"><script src="cached/app.js" defer></script>',
    default => '',
};
echo "<!doctype html><title>Shop</title>{$files}<h1>Welcome to the shop</h1>";
