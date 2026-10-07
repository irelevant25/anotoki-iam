<?php

declare(strict_types=1);

namespace Anotoki\Lib\Migrations\Http;

use Anotoki\Lib\Migrations\Migrator;
use Closure;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Handlers\Strategies\RequestResponse;
use Slim\Interfaces\RouteCollectorProxyInterface;
use Slim\Interfaces\RouteGroupInterface;
use Slim\Interfaces\RouteInterface;

/**
 * The routes every site serves for its migrations, on its Slim app: the public
 * status the pages ask, and the administrators' Migrations page's API.
 *
 * The handlers are called as ($request, $response) whatever invocation
 * strategy the site's app uses, and answer JSON with Cache-Control: no-store.
 */
final class MigrationsRoutes
{
    /**
     * GET $path -> 200 {"state": "ready|not_set_up|update_pending|unavailable", "installed": bool}.
     * Public, and it says nothing else; the SiteGate always lets it through.
     */
    public static function status(RouteCollectorProxyInterface $app, SiteState $state, string $path = '/api/site-status'): void
    {
        self::plain($app->get($path, function (ServerRequestInterface $request, ResponseInterface $response) use ($state): ResponseInterface {
            return self::json($response, 200, ['state' => $state->state(), 'installed' => $state->installed()]);
        }));
    }

    /**
     * GET  $base                       -> 200 Migrator::status()
     * GET  $base/file?set=..&name=..   -> 200 {"set","name","sql"} | 404 {"code":"not_found","message":"No such migration file."}
     * POST $base/apply                 -> 200 {"applied","failed","error","note"} (a failed file is still 200: the page shows it)
     *                                   | 409 {"code":"busy","message":"Another database update is running. ..."}
     *
     * Returns the route group: the site adds its own admin check to it
     * (->add(...)), which must not need tables that a pending migration makes.
     *
     * @param Closure(): Migrator $migrator
     * @param null|Closure(ServerRequestInterface, array): void $afterApply  after every apply that was not busy,
     *        with Migrator::apply()'s result (audit, caches); it should not throw: the files it follows are applied
     */
    public static function admin(RouteCollectorProxyInterface $app, string $base, Closure $migrator, ?Closure $afterApply = null): RouteGroupInterface
    {
        return $app->group($base, function (RouteCollectorProxyInterface $group) use ($migrator, $afterApply): void {
            self::plain($group->get('', function (ServerRequestInterface $request, ResponseInterface $response) use ($migrator): ResponseInterface {
                return self::json($response, 200, $migrator()->status());
            }));

            self::plain($group->get('/file', function (ServerRequestInterface $request, ResponseInterface $response) use ($migrator): ResponseInterface {
                $query = $request->getQueryParams();
                $set = $query['set'] ?? null;
                $name = $query['name'] ?? null;
                $sql = is_string($set) && is_string($name) ? $migrator()->source($set, $name) : null;

                if ($sql === null) {
                    return self::json($response, 404, ['code' => 'not_found', 'message' => 'No such migration file.']);
                }

                return self::json($response, 200, ['set' => $set, 'name' => $name, 'sql' => $sql]);
            }));

            self::plain($group->post('/apply', function (ServerRequestInterface $request, ResponseInterface $response) use ($migrator, $afterApply): ResponseInterface {
                $result = $migrator()->apply();

                if ($result['busy']) {
                    return self::json($response, 409, [
                        'code' => 'busy',
                        'message' => 'Another database update is running. Nothing was applied. Wait a moment, then look again.',
                    ]);
                }

                if ($afterApply !== null) {
                    $afterApply($request, $result);
                }

                return self::json($response, 200, [
                    'applied' => $result['applied'],
                    'failed' => $result['failed'],
                    'error' => $result['error'],
                    'note' => $result['note'],
                ]);
            }));
        });
    }

    /** The route's handler gets ($request, $response), whatever the app's default invocation strategy. */
    private static function plain(RouteInterface $route): void
    {
        $route->setInvocationStrategy(new RequestResponse());
    }

    /** @param array<string, mixed> $body */
    private static function json(ResponseInterface $response, int $status, array $body): ResponseInterface
    {
        // A file's text may not be UTF-8: its bad bytes show as U+FFFD rather than failing the answer.
        $response->getBody()->write(
            json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR)
        );

        return $response
            ->withStatus($status)
            ->withHeader('Content-Type', 'application/json; charset=utf-8')
            ->withHeader('Cache-Control', 'no-store');
    }
}
