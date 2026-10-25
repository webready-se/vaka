<?php

/**
 * Loads every function Vaka has. Defines functions only and has no side
 * effects, so the CLI, the web front controller and the tests can all require it.
 */

const VAKA_VERSION = '0.1.0-dev';

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/cli.php';
