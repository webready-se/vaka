<?php

/**
 * A minimal TLS server for the SSL check tests: php tls-server.php <port> <cert.pem> <key.pem>
 * It completes the handshake, answers a short HTTP response and closes.
 */

[, $port, $cert, $key] = $argv;
$context = stream_context_create(['ssl' => ['local_cert' => $cert, 'local_pk' => $key, 'allow_self_signed' => true, 'verify_peer' => false]]);
$server = stream_socket_server("ssl://127.0.0.1:{$port}", $errno, $error, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN, $context);
if (!$server) {
    fwrite(STDERR, "Cannot listen on {$port}: {$error}\n");
    exit(1);
}

while (true) {
    // A failed handshake (a client that rejects the certificate) is expected; keep serving
    $client = @stream_socket_accept($server, -1);
    if (!$client) continue;
    // Read the request first: closing with it unread makes Linux reset the connection
    stream_set_timeout($client, 2);
    while (($line = fgets($client)) !== false && trim($line) !== '');
    fwrite($client, "HTTP/1.1 200 OK\r\nContent-Length: 2\r\nConnection: close\r\n\r\nok");
    fclose($client);
}
