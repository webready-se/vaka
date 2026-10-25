<?php

/** Fixture site that is down: every request answers 500. */

http_response_code(500);
header('Content-Type: text/html; charset=utf-8');
echo '<!doctype html><title>Error</title><h1>Internal Server Error</h1>';
