<?php

/**
 * Loads every function Vaka has. Defines functions only and has no side
 * effects, so the CLI, the web front controller and the tests can all require it.
 */

const VAKA_VERSION = '0.4.0';

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/sites.php';
require_once __DIR__ . '/import.php';
require_once __DIR__ . '/http.php';
require_once __DIR__ . '/checks/uptime.php';
require_once __DIR__ . '/checks/ssl.php';
require_once __DIR__ . '/checks/domain.php';
require_once __DIR__ . '/checks/exposed.php';
require_once __DIR__ . '/checks/headers.php';
require_once __DIR__ . '/checks/dns.php';
require_once __DIR__ . '/runner.php';
require_once __DIR__ . '/silence.php';
require_once __DIR__ . '/share.php';
require_once __DIR__ . '/manage.php';
require_once __DIR__ . '/alerts.php';
require_once __DIR__ . '/notify.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/stats.php';
require_once __DIR__ . '/report.php';
require_once __DIR__ . '/web.php';
require_once __DIR__ . '/cli.php';
