<?php

/**
 * The IAM's front controller.
 *
 * Under Apache, api/.htaccess sends every /api/ path here, and the web root's
 * .htaccess (written by the Angular build) sends /.well-known/ here too, so
 * discovery and the key set live at the issuer's root as OIDC requires. Slim
 * routes on the original path. In development, router.php does the same for
 * PHP's built-in server.
 *
 * Until setup.php has written config/*.local.php, every request is answered
 * 503 `not_set_up` (setUpRequiredMiddleware()) and nothing else runs.
 */

// The hosting prints PHP's warnings into the page (display_errors is on there),
// which puts the server's paths in front of an answer's JSON and breaks it for
// the apps. Served by a web server, they go to the error log only; the command
// line and the development server keep showing them.
if (!in_array(PHP_SAPI, ['cli', 'cli-server'], true)) {
    ini_set('display_errors', '0');
}

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/iam.php';
require_once __DIR__ . '/../config/secret.php';
require_once __DIR__ . '/../config/mail.php';
require_once __DIR__ . '/../config/google.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/crypto.php';
require_once __DIR__ . '/keys.php';
require_once __DIR__ . '/jwt.php';
require_once __DIR__ . '/settings.php';
require_once __DIR__ . '/audit.php';
require_once __DIR__ . '/applications.php';
require_once __DIR__ . '/session.php';
require_once __DIR__ . '/one_time_token.php';
require_once __DIR__ . '/totp.php';
require_once __DIR__ . '/trusted_device.php';
require_once __DIR__ . '/google_identity.php';
require_once __DIR__ . '/mail.php';
require_once __DIR__ . '/translations.php';
require_once __DIR__ . '/announcements.php';
require_once __DIR__ . '/accounts.php';
require_once __DIR__ . '/account_deletion.php';
require_once __DIR__ . '/sign_in_devices.php';
require_once __DIR__ . '/oidc.php';
require_once __DIR__ . '/housekeeping.php';
require_once __DIR__ . '/migration_runner.php';
require_once __DIR__ . '/meddleware/security.php';
require_once __DIR__ . '/meddleware/auth.php';

use Slim\Factory\AppFactory;

/**
 * The application: every route and middleware, not yet running. Tests call
 * this and hand it requests. Built once - the route files declare their helper
 * functions, and a second require would declare them twice.
 */
function buildIamApp(): \Slim\App
{
    static $built = null;

    if ($built !== null) {
        return $built;
    }

    $app = AppFactory::create();
    $app->addBodyParsingMiddleware();

    require __DIR__ . '/routes/oidc.php';
    require __DIR__ . '/routes/login.php';
    require __DIR__ . '/routes/account.php';
    require __DIR__ . '/routes/admin.php';

    // Added in this order, so they run the other way round: security headers
    // first, then the not-set-up answer (before anything could read a
    // configuration that is not there), CORS, the error handler, CSRF, and
    // the body parser innermost around the routes.
    $app->add(csrfMiddleware());

    $errorMiddleware = $app->addErrorMiddleware(false, true, true);
    $errorMiddleware->setDefaultErrorHandler(function (\Psr\Http\Message\ServerRequestInterface $request, \Throwable $exception) use ($app) {
        [$status, $code, $message] = match (true) {
            $exception instanceof \Slim\Exception\HttpNotFoundException => [404, 'not_found', 'Not found'],
            $exception instanceof \Slim\Exception\HttpMethodNotAllowedException => [405, 'method_not_allowed', 'Method not allowed'],
            default => [500, 'server_error', 'Something went wrong on our side'],
        };

        if ($status === 500) {
            error_log('[iam] ' . get_class($exception) . ': ' . $exception->getMessage() . ' in ' . $exception->getFile() . ':' . $exception->getLine());
        }

        $response = $app->getResponseFactory()->createResponse($status);
        $response->getBody()->write((string) json_encode(['error' => $message, 'code' => $code]));

        return $response->withHeader('Content-Type', 'application/json');
    });

    $app->add(corsMiddleware());
    $app->add(setUpRequiredMiddleware());
    $app->add(securityHeadersMiddleware());

    return $built = $app;
}

// The tests build the app themselves and hand it requests; nothing is served
// while they load this file.
if (!defined('IAM_NO_RUN')) {
    buildIamApp()->run();
}
