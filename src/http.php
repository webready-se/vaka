<?php

/** How much of a page Vaka reads: plenty for a keyword, small enough to keep memory flat. */
const MAX_BODY_BYTES = 1048576;

/**
 * GET many URLs at once over curl_multi, at most $concurrency at a time.
 * Follows up to 5 redirects (http and https only); the headers returned are
 * the final response's. At most $maxBytes of each body is read. A failed
 * request comes back with errno and error set and status 0, never as an
 * exception.
 *
 * @param array<array-key, string> $urls
 * @return array<array-key, array{status: int, headers: array<string, list<string>>, body: string, ms: int, ip: string, errno: int, error: string, url: string}>
 */
function fetch_many(array $urls, int $timeout, string $userAgent, int $concurrency = 20, string $caFile = '', int $maxBytes = MAX_BODY_BYTES): array
{
    $multi = curl_multi_init();
    $pending = $urls;
    $running = [];
    $results = [];

    $start = function () use (&$pending, &$running, $multi, $timeout, $userAgent, $caFile, $maxBytes): void {
        $key = array_key_first($pending);
        $transfer = (object) ['key' => $key, 'headers' => [], 'body' => '', 'truncated' => false];
        $handle = curl_init($pending[$key]);
        unset($pending[$key]);

        curl_setopt_array($handle, [
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => $timeout,
            CURLOPT_USERAGENT => $userAgent,
            CURLOPT_ENCODING => '',
            CURLOPT_HTTPHEADER => ['Accept: text/html,application/xhtml+xml,*/*;q=0.8'],
            CURLOPT_HEADERFUNCTION => function ($handle, string $line) use ($transfer): int {
                if (preg_match('#^HTTP/\S+\s+\d{3}#', $line)) {
                    // A new response after a redirect: only the last one's headers count
                    $transfer->headers = [];
                } elseif (str_contains($line, ':')) {
                    [$name, $value] = explode(':', $line, 2);
                    $transfer->headers[strtolower(trim($name))][] = trim($value);
                }
                return strlen($line);
            },
            CURLOPT_WRITEFUNCTION => function ($handle, string $chunk) use ($transfer, $maxBytes): int {
                $room = $maxBytes - strlen($transfer->body);
                if ($room <= 0) {
                    // Enough read: stop the download (curl reports a write error, see below)
                    $transfer->truncated = true;
                    return 0;
                }
                $transfer->body .= substr($chunk, 0, $room);
                return strlen($chunk);
            },
        ]);
        if ($caFile !== '') curl_setopt($handle, CURLOPT_CAINFO, $caFile);
        curl_multi_add_handle($multi, $handle);
        $running[spl_object_id($handle)] = [$handle, $transfer];
    };

    while ($pending && count($running) < $concurrency) $start();

    while ($running) {
        curl_multi_exec($multi, $active);
        while ($info = curl_multi_info_read($multi)) {
            [$handle, $transfer] = $running[spl_object_id($info['handle'])];
            unset($running[spl_object_id($handle)]);

            $errno = $info['result'];
            if ($errno === CURLE_WRITE_ERROR && $transfer->truncated) $errno = 0;
            $results[$transfer->key] = [
                'status' => $errno ? 0 : (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE),
                'headers' => $transfer->headers,
                'body' => $transfer->body,
                'ms' => intdiv((int) curl_getinfo($handle, CURLINFO_TOTAL_TIME_T), 1000),
                'ip' => (string) curl_getinfo($handle, CURLINFO_PRIMARY_IP),
                'errno' => $errno,
                'error' => $errno ? (curl_error($handle) ?: curl_strerror($errno)) : '',
                'url' => (string) curl_getinfo($handle, CURLINFO_EFFECTIVE_URL),
            ];
            curl_multi_remove_handle($multi, $handle);

            if ($pending) $start();
        }
        if ($running && curl_multi_select($multi, 0.5) === -1) usleep(10_000);
    }
    curl_multi_close($multi);

    return $results;
}

/** The User-Agent for every request Vaka makes. */
function user_agent(array $config): string
{
    if ($config['user_agent'] !== '') return $config['user_agent'];
    return 'Vaka/' . VAKA_VERSION . ' (+' . ($config['app_url'] ?: 'https://github.com/webready-se/vaka') . ')';
}
