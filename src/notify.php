<?php

/** Send one bundle to one destination. True when the destination took it. */
function deliver(array $destination, array $bundle, array $config, int $now): bool
{
    return match ($destination['type']) {
        'slack' => post_json($destination['target'], slack_payload($bundle, $config['app_url']), user_agent($config)),
        'webhook' => post_json($destination['target'], webhook_payload($bundle, utc_now($now)), user_agent($config)),
        'email' => send_mail($destination['target'], $config['alert_from'], email_message($bundle, $config['app_url'])),
    };
}

function alert_emoji(string $kind): string
{
    return match ($kind) {
        'down', 'still_down', 'ssl_invalid', 'exposed' => ':red_circle:',
        'up', 'stable', 'ssl_fixed', 'ssl_renewed', 'exposed_fixed' => ':large_green_circle:',
        'unstable', 'ssl_expiring', 'exposed_minor', 'headers_worse' => ':warning:',
        'digest' => ':clipboard:',
        default => ':bell:',
    };
}

/** Text from the sites (names, reasons, keywords) must not be read as Slack markup or mentions. */
function slack_escape(string $text): string
{
    return str_replace(['&', '<', '>'], ['&amp;', '&lt;', '&gt;'], $text);
}

/** A Slack incoming webhook message (mrkdwn text). */
function slack_payload(array $bundle, string $appUrl): array
{
    $blocks = [];
    foreach ($bundle['items'] as $item) {
        $text = alert_text($item);
        $lines = [alert_emoji($item['kind']) . ' *' . slack_escape($text['headline']) . '*'];
        foreach ($text['details'] as $detail) $lines[] = '> ' . slack_escape($detail);
        $blocks[] = implode("\n", $lines);
    }
    if ($appUrl !== '') $blocks[] = "<{$appUrl}|Open Vaka>";
    return ['text' => implode("\n\n", $blocks)];
}

/** A plain-text email: the first headline as subject, every item in the body. */
function email_message(array $bundle, string $appUrl): array
{
    $texts = array_map('alert_text', $bundle['items']);
    $more = count($texts) - 1;
    $subject = $texts[0]['headline'] . ($more ? ' (and ' . plural($more, 'more alert', 'more alerts') . ')' : '');

    $parts = [];
    foreach ($texts as $text) {
        $parts[] = implode("\n", [$text['headline'], ...array_map(fn ($detail) => "  {$detail}", $text['details'])]);
    }
    $body = implode("\n\n", $parts) . "\n\n-- \nVaka" . ($appUrl !== '' ? " {$appUrl}" : '') . "\n";

    // Headers must never carry line breaks (header injection)
    return ['subject' => preg_replace('/[\x00-\x1F\x7F]+/', ' ', $subject), 'body' => $body];
}

/** The generic webhook's JSON: stable field names, documented in docs/alerts.md. */
function webhook_payload(array $bundle, string $sentAt): array
{
    return [
        'source' => 'vaka',
        'version' => VAKA_VERSION,
        'sent_at' => $sentAt,
        'alerts' => array_map(function ($item) {
            $text = alert_text($item);
            return [
                'kind' => $item['kind'],
                'headline' => $text['headline'],
                'details' => $text['details'],
                'server' => $item['server'] ?? null,
                'sites' => array_map(fn ($site) => [
                    'url' => $site['url'],
                    'group' => $site['group'],
                    'summary' => $site['summary'],
                    'since' => $site['since'],
                ], $item['sites']),
            ];
        }, $bundle['items']),
    ];
}

/** POST JSON; true on a 2xx answer within 10 s. */
function post_json(string $url, array $payload, string $userAgent): bool
{
    $handle = curl_init($url);
    curl_setopt_array($handle, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_USERAGENT => $userAgent,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
    ]);
    $ok = curl_exec($handle) !== false;
    $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
    return $ok && $status >= 200 && $status < 300;
}

/**
 * Send a plain-text email with PHP's mail(). This needs a working mail
 * transfer agent on the server (sendmail_path); without one, mail() fails
 * and the alert is tried again on the next run.
 */
function send_mail(array $to, string $from, array $message): bool
{
    $headers = ['MIME-Version: 1.0', 'Content-Type: text/plain; charset=UTF-8', 'Content-Transfer-Encoding: 8bit'];
    if ($from !== '') $headers[] = 'From: ' . preg_replace('/[\r\n]+/', '', $from);
    return mail(
        implode(', ', $to),
        '=?UTF-8?B?' . base64_encode($message['subject']) . '?=',
        $message['body'],
        implode("\r\n", $headers),
    );
}
