<?php

/**
 * Fixture site that leaks: at the root a .env and a Git repository answer
 * with their content, under /minor/ only a composer.json. The pages /,
 * /minor and /fixed are up; everything else, including all of /fixed/, is
 * a 404. The content is fake.
 */

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

$files = [
    '/.env' => "APP_NAME=Shop\nAPP_KEY=base64:bm90LWEtcmVhbC1rZXk=\nDB_PASSWORD=not-a-real-password\n",
    '/.git/HEAD' => "ref: refs/heads/main\n",
    '/.git/config' => "[core]\n\trepositoryformatversion = 0\n\tfilemode = true\n\tbare = false\n",
    '/minor/composer.json' => "{\n    \"name\": \"shop/site\",\n    \"require\": {\"php\": \"^8.3\"}\n}\n",
];

if (isset($files[$path])) {
    header('Content-Type: text/plain');
    echo $files[$path];
    exit;
}
if (in_array($path, ['/', '/minor', '/fixed'], true)) {
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><title>Shop</title><h1>Welcome to the shop</h1>';
    exit;
}

http_response_code(404);
header('Content-Type: text/html; charset=utf-8');
echo '<!doctype html><title>Not found</title><h1>Not found</h1>';
