<?php

declare(strict_types=1);

namespace Anotoki\Lib\Audit\Http;

use Anotoki\Lib\Admin\AdminContext;
use Anotoki\Lib\Admin\Http\Routes;
use Anotoki\Lib\Audit\AuditLog;
use Anotoki\Lib\Support\JsonResponse;
use Anotoki\Lib\Support\Refusal;
use Closure;
use PDOException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Interfaces\RouteCollectorProxyInterface;
use Slim\Interfaces\RouteGroupInterface;
use Throwable;

/**
 * The audit log's routes, for the site's ADMIN alone - the group asks the
 * context's isAdmin() before anything else (401, 403), and the site adds its
 * own middleware to it as to any group - read only:
 *
 *   GET $base?actor=&action=&target_type=&target_id=&scope_type=&scope_id=&from=&to=&page=
 *       {total, page, pageSize: 50, items} - the IAM's answer (AuditEntry), each item with
 *       actor_username from the site's $actorNames, and actor_session, scope_type, scope_id
 *   GET $base/facets  {actions, target_types, scope_types, actors: [{id, username}]}
 *
 * 503 feature_unavailable (Retry-After: 60) while the table is not there yet.
 */
final class AuditRoutes
{
    public const PAGE_SIZE = 50;

    /**
     * @param null|Closure(list<int>): array<int|string, string> $actorNames  the people's names by id (the IAM:
     *        users.username; a site: its own copy of the IAM's names) - an id it does not know shows no name;
     *        without one, the context's names
     */
    public static function admin(RouteCollectorProxyInterface $app, string $base, AuditLog $audit, AdminContext $context, ?Closure $actorNames = null): RouteGroupInterface
    {
        $actorNames ??= static fn(array $ids): array => $context->names($ids);

        return Routes::adminGroup($app, $base, $context, function (RouteCollectorProxyInterface $group) use ($audit, $context, $actorNames): void {
            Routes::plain(
                $group->get('', function (ServerRequestInterface $request, ResponseInterface $response) use ($audit, $context, $actorNames): ResponseInterface {
                    if (!$audit->ready()) {
                        return $context->refuse($response, self::unavailable());
                    }
                    $query = $request->getQueryParams();
                    $filter = [];
                    foreach (['actor', 'action', 'target_type', 'target_id', 'scope_type', 'scope_id', 'from', 'to'] as $name) {
                        $filter[$name] = Routes::text($query[$name] ?? null, 100);
                    }
                    try {
                        $page = $audit->page($filter, Routes::int($query['page'] ?? null, 1, 1, 1_000_000), self::PAGE_SIZE);
                    } catch (PDOException) {
                        return $context->refuse($response, self::unavailable());
                    }
                    $names = self::names($actorNames, array_column($page['items'], 'actor_id'));
                    foreach ($page['items'] as $index => $item) {
                        $page['items'][$index] = ['actor_username' => $item['actor_id'] === null ? null : $names[$item['actor_id']] ?? null] + $item;
                    }

                    return JsonResponse::write($response, 200, $page);
                }),
            );

            Routes::plain(
                $group->get('/facets', function (ServerRequestInterface $request, ResponseInterface $response) use ($audit, $context, $actorNames): ResponseInterface {
                    if (!$audit->ready()) {
                        return $context->refuse($response, self::unavailable());
                    }
                    $facets = $audit->facets();
                    $names = self::names($actorNames, $facets['actor_ids']);
                    $actors = array_map(static fn(int $id): array => ['id' => $id, 'username' => $names[$id] ?? null], $facets['actor_ids']);
                    unset($facets['actor_ids']);

                    return JsonResponse::write($response, 200, $facets + ['actors' => $actors]);
                }),
            );
        });
    }

    public static function unavailable(): Refusal
    {
        return new Refusal(503, 'feature_unavailable', 'The audit log is not there yet: a database update waits. It comes once the update is applied.');
    }

    /**
     * @param list<mixed> $ids
     * @return array<int, string>
     */
    private static function names(?Closure $actorNames, array $ids): array
    {
        $ids = array_values(array_unique(array_filter($ids, 'is_int')));
        if ($actorNames === null || $ids === []) {
            return [];
        }
        try {
            $names = $actorNames($ids);
        } catch (Throwable) {
            return [];
        }
        $out = [];
        foreach (is_array($names) ? $names : [] as $id => $name) {
            if (is_string($name)) {
                $out[(int) $id] = $name;
            }
        }

        return $out;
    }
}
