<?php

require_once __DIR__ . '/src/config.php';

// VAKA_ENV_FILE points somewhere else (tests use /dev/null to skip the local .env)
load_env(getenv('VAKA_ENV_FILE') ?: __DIR__ . '/.env');

$env = fn (string $key, string $default = '') => (string) ($_ENV[$key] ?? $default);

return [
    'app_url' => rtrim($env('APP_URL'), '/'),

    'timezone' => $env('TIMEZONE', 'Europe/Stockholm'),

    'db_path' => resolve_path($env('DB_PATH', 'data/vaka.sqlite'), __DIR__),

    'users_file' => resolve_path($env('USERS_FILE', 'users.json'), __DIR__),

    'session_lifetime' => (int) $env('SESSION_LIFETIME', '2592000'),

    'max_login_attempts' => (int) $env('MAX_LOGIN_ATTEMPTS', '5'),

    'lockout_minutes' => (int) $env('LOCKOUT_MINUTES', '15'),

    // Reverse proxies (IPs or CIDR ranges) whose X-Forwarded-For / X-Real-IP is trusted
    'trusted_proxies' => array_values(array_filter(array_map('trim', explode(',', $env('TRUSTED_PROXIES'))))),

    // Checks
    'uptime_interval' => max(1, (int) $env('UPTIME_INTERVAL', '5')),

    'uptime_timeout' => max(1, (int) $env('UPTIME_TIMEOUT', '10')),

    'down_after_failures' => max(1, (int) $env('DOWN_AFTER_FAILURES', '2')),

    'ssl_alert_days' => int_list($env('SSL_ALERT_DAYS', '14,7,1')),

    'domain_alert_days' => int_list($env('DOMAIN_ALERT_DAYS', '30,14,7,1')),

    'samples_retention_days' => max(1, (int) $env('SAMPLES_RETENTION_DAYS', '30')),

    'user_agent' => $env('USER_AGENT'),

    // Dead man's switch pinged at the end of every run
    'heartbeat_url' => $env('HEARTBEAT_URL'),

    // Alerts: defaults for groups without destinations of their own
    'digest_time' => $env('DIGEST_TIME', '08:00'),

    'slack_webhook_url' => $env('SLACK_WEBHOOK_URL'),

    'webhook_url' => $env('WEBHOOK_URL'),

    'alert_email' => array_values(array_filter(array_map('trim', explode(',', $env('ALERT_EMAIL'))))),

    'alert_from' => $env('ALERT_FROM'),
];
