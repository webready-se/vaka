<?php

/**
 * Fixture alert receiver (Slack webhook, JSON webhook). Every POST is
 * written as one JSON line to the file named by ?log=, and the answer has
 * the status from ?status= (200 by default), so tests can make a
 * destination fail.
 */

$log = (string) ($_GET['log'] ?? '');
if ($log !== '' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $line = json_encode([
        'path' => parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH),
        'type' => $_SERVER['CONTENT_TYPE'] ?? '',
        'body' => json_decode(file_get_contents('php://input'), true),
    ]);
    file_put_contents($log, $line . "\n", FILE_APPEND | LOCK_EX);
}

http_response_code((int) ($_GET['status'] ?? 200));
echo 'ok';
