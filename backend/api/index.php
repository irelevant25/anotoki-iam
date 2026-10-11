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
 * Until setup.php has written config/*.local.php, every request but
 * /api/site-status is answered 503 `not_set_up` by the family's gate
 * (anotoki/lib's SiteGate, iamSiteState()) and nothing else runs.
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
require_once __DIR__ . '/admin_tools.php';
require_once __DIR__ . '/meddleware/security.php';
require_once __DIR__ . '/meddleware/auth.php';

use Anotoki\Lib\ErrorLog\ErrorLogMiddleware;
use Anotoki\Lib\Migrations\Http\SiteGate;
use Anotoki\Lib\Support\AfterResponse;
use Anotoki\Lib\Support\Redactor;
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

    // The error log (the family's library, api/admin_tools.php): what went
    // wrong, written down where the admin panel's Error log page reads it -
    // PHP's warnings and fatal errors too, by handlers chained to whatever was
    // there, the IAM's own names of secrets out of each message first. Made
    // first, so the routes' own (routes/admin.php) know it.
    $errorLog = iamErrorLog($app);
    iamInstallPhpHandlers($errorLog);

    require __DIR__ . '/routes/oidc.php';
    require __DIR__ . '/routes/login.php';
    require __DIR__ . '/routes/account.php';
    require __DIR__ . '/routes/admin.php';

    // Added in this order, so they run the other way round: the error log's
    // outer half first (every answer worth seeing - a 404, a 503, a 500 - as
    // it leaves), the IAM's names of secrets out of an exception nothing
    // answered, then security headers, the not-set-up answer (the family's
    // gate - before anything could read a configuration that is not there),
    // CORS, the error handler, CSRF, and the body parser innermost around the
    // routes.
    $app->add(csrfMiddleware());

    $errorMiddleware = $app->addErrorMiddleware(false, true, true);
    // The IAM's own answer to a failure, written into the error log with the
    // status and code it answered (the library's wrapHandler()).
    $errorMiddleware->setDefaultErrorHandler($errorLog->wrapHandler(function (\Psr\Http\Message\ServerRequestInterface $request, \Throwable $exception) use ($app) {
        [$status, $code, $message] = match (true) {
            $exception instanceof \Slim\Exception\HttpNotFoundException => [404, 'not_found', 'Not found'],
            $exception instanceof \Slim\Exception\HttpMethodNotAllowedException => [405, 'method_not_allowed', 'Method not allowed'],
            default => [500, 'server_error', 'Something went wrong on our side'],
        };

        // The IAM's own names of secrets out of its message, for both logs
        // (iamRedactSecrets()); the library's redactor does the rest - in the
        // error log's line, and in PHP's own below.
        iamRedactException($exception);

        if ($status === 500) {
            error_log('[iam] ' . get_class($exception) . ': ' . Redactor::text($exception->getMessage()) . ' in ' . $exception->getFile() . ':' . $exception->getLine());
        }

        $response = $app->getResponseFactory()->createResponse($status);
        $response->getBody()->write((string) json_encode(['error' => $message, 'code' => $code]));

        return $response->withHeader('Content-Type', 'application/json');
    }));

    $app->add(corsMiddleware());
    // The anotoki family's gate (anotoki/lib): /api/site-status always passes;
    // until the IAM is set up everything else is 503 `not_set_up`, naming the
    // installer only while it was never installed (iamSiteState()). An update
    // waiting closes nothing here - the IAM does not block - so the paths open
    // while one waits change nothing today; they are the admin Migrations
    // routes, exactly, as on every other site.
    //
    // It is asked only while the IAM is not set up: in every other state a
    // site that does not block passes everything, and the gate would learn
    // which state it is by a connection and two queries on every request -
    // discovery, the token endpoint, a 404 - and, with the database host gone,
    // a connect timeout before the route's own. Its answers are the gate's.
    $gate = new SiteGate(iamSiteState(), $app->getResponseFactory(), ['~^/api/admin/migrations(/file|/apply)?$~D']);
    $app->add(static fn(\Psr\Http\Message\ServerRequestInterface $request, \Psr\Http\Server\RequestHandlerInterface $handler): \Psr\Http\Message\ResponseInterface
        => iamSetUpProblems() === [] ? $handler->handle($request) : $gate->process($request, $handler));
    $app->add(securityHeadersMiddleware());
    // An exception none of those answered - thrown outside the error handler -
    // with the IAM's own names of secrets out of its message, before the error
    // log's outer half writes it (iamRedactingMiddleware()).
    $app->add(iamRedactingMiddleware());
    // Outside everything, the error middleware included: the answers worth
    // seeing as they leave - a 404, or a 503 a route answered itself - and the
    // request a PHP warning belongs to. Of an answer it keeps its code and its
    // sentence, never a body, a header or a cookie.
    $app->add(ErrorLogMiddleware::outer($errorLog));

    return $built = $app;
}

// The tests build the app themselves and hand it requests; nothing is served
// while they load this file.
if (!defined('IAM_NO_RUN')) {
    buildIamApp()->run();
    // What belongs to a request but not to its answer - the day's backup - once
    // the answer is out (PHP-FPM and LiteSpeed close the connection first; the
    // host has no cron).
    AfterResponse::shared()->run(static function (\Throwable $e): void {
        iamRecordError($e);
    });
}
