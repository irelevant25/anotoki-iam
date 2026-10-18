<?php

declare(strict_types=1);

namespace Anotoki\Lib\Admin\Http;

use Anotoki\Lib\Admin\AdminContext;
use Anotoki\Lib\Support\JsonBody;
use Anotoki\Lib\Support\Refusal;
use Anotoki\Lib\Support\Utf8;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Handlers\Strategies\RequestResponse;
use Slim\Interfaces\RouteCollectorProxyInterface;
use Slim\Interfaces\RouteGroupInterface;
use Slim\Interfaces\RouteInterface;

/**
 * What the admin tools' route helpers share. Internal to the library.
 *
 * @internal
 */
final class Routes
{
    /** The route's handler gets ($request, $response), whatever the app's default invocation strategy. */
    public static function plain(RouteInterface $route): RouteInterface
    {
        return $route->setInvocationStrategy(new RequestResponse());
    }

    /**
     * An admin() route group with its guard (AdminGuard) as the group's first middleware - so the site's
     * own, added to the group it gets back, run before it (its session first). The guard is made before
     * the group: a context that does not say who the ADMIN is throws while the site builds its app, with
     * no route of the group mounted.
     *
     * @param callable(RouteCollectorProxyInterface): void $routes
     */
    public static function adminGroup(RouteCollectorProxyInterface $app, string $base, AdminContext $context, callable $routes): RouteGroupInterface
    {
        $guard = new AdminGuard($context, $app->getResponseFactory());

        return $app->group($base, $routes)->add($guard);
    }

    /**
     * The path a route of a group is mounted under, from the request it answers: its path without the
     * route's own end ($suffix: '/<id>/ticket'). So a link the server hands out names the same place
     * whatever group, prefix or base path the site mounted the routes under. Null when the path does not
     * end so.
     */
    public static function mountedAt(ServerRequestInterface $request, string $suffix): ?string
    {
        $path = $request->getUri()->getPath();
        if ($suffix === '' || !str_ends_with($path, $suffix)) {
            return null;
        }

        return substr($path, 0, -strlen($suffix));
    }

    /**
     * A request's JSON object as an array - read from the raw stream (Support\JsonBody), at most $limit
     * bytes. With $optional, nothing at all is an empty object (a POST that carries only a password where
     * the site asks for one, say, carries nothing elsewhere). Anything but an object: 422 invalid_body.
     *
     * @return array<string, mixed>|Refusal
     */
    public static function body(ServerRequestInterface $request, int $limit, bool $optional = false): array|Refusal
    {
        if ($optional) {
            $stream = $request->getBody();
            $size = $stream->getSize();
            $declared = trim($request->getHeaderLine('Content-Length'));
            if ($declared === '0' || ($declared === '' && $size === 0)) {
                return [];
            }
            if ($size !== null && $size <= $limit) {
                if ($stream->isSeekable()) {
                    $stream->rewind();
                }
                if (trim($stream->getContents()) === '') {
                    return [];
                }
            }
        }

        $body = JsonBody::read($request, $limit);
        if ($body instanceof Refusal) {
            return $body;
        }
        $map = JsonBody::map($body);
        if ($map === null) {
            return Refusal::invalid('invalid_body', 'Send a JSON object.');
        }
        $fields = [];
        foreach ($map as $key => $value) {
            $fields[(string) $key] = self::plainValue($value);
        }

        return $fields;
    }

    /** A positive whole number from a query parameter, or $default. */
    public static function int(mixed $value, int $default, int $min = 1, int $max = PHP_INT_MAX): int
    {
        if (is_int($value)) {
            return max($min, min($max, $value));
        }
        if (is_string($value) && preg_match('/^\d{1,9}$/D', $value) === 1) {
            return max($min, min($max, (int) $value));
        }

        return $default;
    }

    /** A query parameter as a string, '' for anything else (an array a crafted query makes of it, say). */
    public static function text(mixed $value, int $limit = 200): string
    {
        return is_string($value) ? Utf8::prefix(trim($value), $limit) : '';
    }

    /** JSON objects inside a body as arrays, lists as lists - so the modules read one kind of value. */
    private static function plainValue(mixed $value): mixed
    {
        if ($value instanceof \stdClass) {
            $out = [];
            foreach (get_object_vars($value) as $key => $inner) {
                $out[(string) $key] = self::plainValue($inner);
            }

            return $out;
        }
        if (is_array($value)) {
            return array_map(self::plainValue(...), $value);
        }

        return $value;
    }
}
