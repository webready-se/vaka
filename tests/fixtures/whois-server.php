<?php

/**
 * A minimal WHOIS server for the domain check tests: php whois-server.php <port>
 * It answers example.se like the .se registry does, and "not found" for
 * anything else.
 */

[, $port] = $argv;
$server = stream_socket_server("tcp://127.0.0.1:{$port}", $errno, $error);
if (!$server) {
    fwrite(STDERR, "Cannot listen on {$port}: {$error}\n");
    exit(1);
}

$dir = __DIR__ . '/rdap';
while (true) {
    $client = @stream_socket_accept($server, -1);
    if (!$client) continue;
    stream_set_timeout($client, 2);
    $domain = strtolower(trim((string) fgets($client)));
    $answer = $domain === 'example.se'
        ? file_get_contents("{$dir}/iis-found.txt")
        : str_replace('nothing.example.se', $domain, file_get_contents("{$dir}/iis-not-found.txt"));
    fwrite($client, $answer);
    fclose($client);
}
