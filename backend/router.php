<?php

/**
 * PHP's built-in server, made to behave like the Apache the IAM runs on:
 *
 *     php -S localhost:8100 router.php
 *
 * /setup.php is the installer, as it is in the web root on a server; every
 * other request goes to the API's front controller. Without this, `php -S`
 * treats any path with a dot in its last segment - /.well-known/jwks.json - as a
 * static file, and 404s it without the API ever seeing it.
 *
 * The Angular dev server on :4300 proxies /api, /.well-known and /setup.php
 * here, so the browser sees one origin, exactly as in production.
 */

if (parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) === '/setup.php' && is_file(__DIR__ . '/setup.php')) {
    require __DIR__ . '/setup.php';
    return;
}

require __DIR__ . '/api/index.php';
