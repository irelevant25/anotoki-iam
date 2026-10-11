<?php

declare(strict_types=1);

namespace Anotoki\Lib\ErrorLog\Http;

use Anotoki\Lib\Admin\AdminContext;
use Anotoki\Lib\Admin\Http\Routes;
use Anotoki\Lib\Audit\AuditRecorder;
use Anotoki\Lib\ErrorLog\ErrorLog;
use Anotoki\Lib\ErrorLog\ErrorLogMiddleware;
use Anotoki\Lib\ErrorLog\ErrorLogReader;
use Anotoki\Lib\Support\JsonBody;
use Anotoki\Lib\Support\JsonResponse;
use Anotoki\Lib\Support\Refusal;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Interfaces\RouteCollectorProxyInterface;
use Slim\Interfaces\RouteGroupInterface;
use Slim\Interfaces\RouteInterface;

/**
 * The error log's routes, on the site's Slim app. Requests to them are never
 * written into the log itself - known by their route as they run, and by the
 * path they are mounted at (under whatever group or base path the site gives
 * them) for what fails before a route runs.
 *
 * admin() - for the site's ADMIN alone: the group asks the context's isAdmin()
 * before anything else (401, 403), and the site adds its own middleware to it
 * as to any group:
 *   GET    $base?days=&level=&status=&search=   ErrorLogReader::report()
 *   GET    $base/{fingerprint}?days=&...        every occurrence of one ([] -> 404 not_found)
 *   DELETE $base?before=                        {deleted: files} - every file, or (before, a UTC date) the days
 *                                               before it; audited errors.cleared
 * Open while an update waits (they need no table): openPaths($base) in the site's gate.
 *
 * client() - the pages' own errors (the owner's decision of 2026-10-07): public,
 *   POST $path {type?, message, page?, stack?, status?} -> 204, a body of at most
 *   8 KB, at most 20 an hour from one address bucket and 500 a day from everybody
 *   (429 too_many), each redacted like any line.
 */
final class ErrorLogRoutes
{
    /** The most a page's error report may weigh. */
    public const CLIENT_BODY_LIMIT = 8 * 1024;

    public static function admin(
        RouteCollectorProxyInterface $app,
        string $base,
        ErrorLog $log,
        AdminContext $context,
        ?AuditRecorder $audit = null,
    ): RouteGroupInterface {
        $reader = $log->reader();

        $routes = Routes::adminGroup($app, $base, $context, function (RouteCollectorProxyInterface $group) use ($reader, $context, $audit): void {
            Routes::plain($group->get('', function (ServerRequestInterface $request, ResponseInterface $response) use ($reader): ResponseInterface {
                return JsonResponse::write($response, 200, $reader->report(self::filter($request)));
            }));

            Routes::plain($group->get('/{fingerprint:[0-9a-f]{12}}', function (ServerRequestInterface $request, ResponseInterface $response) use ($reader, $context): ResponseInterface {
                $occurrences = $reader->occurrences((string) $request->getAttribute('fingerprint'), self::filter($request));
                if ($occurrences === []) {
                    return $context->refuse($response, new Refusal(404, 'not_found', 'Nothing of that kind in these days.'));
                }

                return JsonResponse::write($response, 200, $occurrences);
            }));

            Routes::plain($group->delete('', function (ServerRequestInterface $request, ResponseInterface $response) use ($reader, $context, $audit): ResponseInterface {
                $before = Routes::text($request->getQueryParams()['before'] ?? null, 10);
                if ($before !== '' && !ErrorLogReader::isDay($before)) {
                    return $context->refuse($response, Refusal::invalid('date_invalid', 'Say the first day to keep as a date: 2026-10-11.'));
                }
                $deleted = $reader->clear($before === '' ? null : $before);
                $audit?->record($request, $context->actorId($request), 'errors.cleared', 'error_log', null, ['files' => $deleted] + ($before === '' ? [] : ['before' => $before]));

                return JsonResponse::write($response, 200, ['deleted' => $deleted]);
            }));
        });
        // Added after the guard, so it runs before it: a refusal of the guard is the log's own too.
        $routes->add(ErrorLogMiddleware::own($log));
        $log->ignorePath($app->getBasePath() . $routes->getPattern());

        return $routes;
    }

    /**
     * POST $path - an error a page met, written down as `source: client`. Public:
     * it reads the site's actor (a signed-in person's id) and address bucket
     * through the context, and nothing else of the request.
     */
    public static function client(RouteCollectorProxyInterface $app, string $path, ErrorLog $log, AdminContext $context): RouteInterface
    {
        $route = Routes::plain($app->post($path, function (ServerRequestInterface $request, ResponseInterface $response) use ($log, $context): ResponseInterface {
            $body = JsonBody::read($request, self::CLIENT_BODY_LIMIT);
            if ($body instanceof Refusal) {
                return $context->refuse($response, $body);
            }
            $report = JsonBody::map($body);
            if ($report === null || !is_string($report['message'] ?? null) || trim($report['message']) === '') {
                return $context->refuse($response, Refusal::invalid('message_required', 'Say what went wrong: a message.'));
            }
            $allowance = $log->clientAllowance($request);
            if ($allowance !== null) {
                return $context->refuse($response, $allowance);
            }
            $log->recordClient($request, [
                'type' => $report['type'] ?? null,
                'message' => $report['message'],
                'page' => $report['page'] ?? null,
                'stack' => $report['stack'] ?? null,
                'status' => $report['status'] ?? null,
            ]);

            return $response->withStatus(204)->withHeader('Cache-Control', 'no-store');
        }));
        $route->add(ErrorLogMiddleware::own($log));
        $log->ignorePath($app->getBasePath() . $route->getPattern());

        return $route;
    }

    /**
     * The admin routes as the SiteGate's open paths: exactly $base and $base/{fingerprint},
     * never a prefix ('~^/api/admin/errors(/[0-9a-f]{12})?$~D').
     *
     * @return list<string>
     */
    public static function openPaths(string $base = '/api/admin/errors'): array
    {
        return ['~^' . preg_quote($base, '~') . '(/[0-9a-f]{12})?$~D'];
    }

    /** @return array{days: int, level: string, status: int, search: string} */
    private static function filter(ServerRequestInterface $request): array
    {
        $query = $request->getQueryParams();

        return [
            'days' => Routes::int($query['days'] ?? null, 7, 1, 30),
            'level' => Routes::text($query['level'] ?? null, 10),
            'status' => Routes::int($query['status'] ?? null, 0, 0, 999),
            'search' => Routes::text($query['search'] ?? null, 200),
        ];
    }
}
