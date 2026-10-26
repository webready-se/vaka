<?php

/**
 * Fixture site for the security header check. / sends five of the six
 * protections (over plain http there is no HSTS), /weaker drops the CSP,
 * /error answers 500.
 */

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

if ($path === '/error') {
    http_response_code(500);
    echo 'Internal Server Error';
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
echo '<!doctype html><title>Shop</title><h1>Welcome to the shop</h1>';
