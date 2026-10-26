<?php

/**
 * Fixture RDAP registry: /dns.json is the bootstrap file, /domain/<name>
 * (and /co/domain/<name>) answers from tests/fixtures/rdap/<name>.json, or
 * 404 for a domain it does not know. Each request is logged to the file
 * named by RDAP_LOG, so a test can count lookups.
 */

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$dir = __DIR__ . '/../../rdap';
if (getenv('RDAP_LOG')) file_put_contents(getenv('RDAP_LOG'), $path . "\n", FILE_APPEND | LOCK_EX);

header('Content-Type: application/rdap+json');
if ($path === '/dns.json') {
    readfile($dir . '/dns.json');
    exit;
}
if (preg_match('#^(?:/co)?/domain/([a-z0-9.-]+)$#', strtolower($path), $m) && is_file("{$dir}/{$m[1]}.json")) {
    readfile("{$dir}/{$m[1]}.json");
    exit;
}
http_response_code(404);
echo json_encode(['errorCode' => 404, 'title' => 'Not Found']);
