<?php

/**
 * Vaka's web front controller: the dashboard, its JSON API, the login and
 * /?health. Point the web server's document root at this directory only.
 */

require __DIR__ . '/../src/bootstrap.php';
$config = require __DIR__ . '/../config.php';
date_default_timezone_set($config['timezone']);

handle_request($config);
