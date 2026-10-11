<?php

declare(strict_types=1);

namespace Anotoki\Lib\Feedback\Http;

use Anotoki\Lib\Admin\AdminContext;
use Anotoki\Lib\Admin\Http\Routes;
use Anotoki\Lib\Audit\AuditRecorder;
use Anotoki\Lib\Feedback\Feedback;
use Anotoki\Lib\Support\JsonResponse;
use Anotoki\Lib\Support\Refusal;
use Closure;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Interfaces\RouteCollectorProxyInterface;
use Slim\Interfaces\RouteGroupInterface;
use Slim\Interfaces\RouteInterface;
use Throwable;

/**
 * Feedback's routes, on the site's Slim app.
 *
 * public() - the form, for everybody (the site's own gates apply as to any route):
 *   POST $path {kind, section?, subject?, message, email?, page?, language?, anonymous?, details?, website}
 *        -> 201 {ok: true} (the honeypot's answer too, word for word) | Feedback::submit()'s refusals
 *
 * admin() - for the site's ADMIN alone: the group asks the context's isAdmin()
 * before anything else (401, 403), and the site adds its own middleware to it
 * as to any group:
 *   GET    $base?kind=&status=&section=&search=&page=   {total, page, pageSize: 25, items}
 *   GET    $base/facets                                {kinds, statuses, sections, fields, byStatus, byKind}
 *   GET    $base/unread                                {unread} - the admin panel's badge
 *   GET    $base/{id}                                  {message} - opening a new one makes it read
 *   PUT    $base/{id}/status {status}                  {message} - audited feedback.status_changed
 *   DELETE $base/{id}                                  {deleted: true} - audited feedback.deleted
 * Every item carries user_name and handled_by_name from the site's $names. 503
 * feature_unavailable while the table is not there yet.
 */
final class FeedbackRoutes
{
    /**
     * @param Closure(ServerRequestInterface): ?array{id?: int|string|null, email?: ?string} $person  the signed-in
     *        sender from the token (the site's optional sign-in check); null for nobody
     */
    public static function public(RouteCollectorProxyInterface $app, string $path, Feedback $feedback, AdminContext $context, Closure $person): RouteInterface
    {
        return Routes::plain($app->post($path, function (ServerRequestInterface $request, ResponseInterface $response) use ($feedback, $context, $person): ResponseInterface {
            $body = Routes::body($request, Feedback::BODY_LIMIT);
            if ($body instanceof Refusal) {
                return $context->refuse($response, $body);
            }
            try {
                $sender = $person($request);
            } catch (Throwable) {
                $sender = null;
            }
            $result = $feedback->submit($request, $body, is_array($sender) ? $sender : null, $context->clientKey($request));
            if ($result instanceof Refusal) {
                return $context->refuse($response, $result);
            }

            return JsonResponse::write($response, $result['status'], $result['body']);
        }));
    }

    /**
     * @param null|Closure(list<int>): array<int|string, string> $names  people's names by id, for the page
     */
    public static function admin(
        RouteCollectorProxyInterface $app,
        string $base,
        Feedback $feedback,
        AdminContext $context,
        ?AuditRecorder $audit = null,
        ?Closure $names = null,
    ): RouteGroupInterface {
        return Routes::adminGroup($app, $base, $context, function (RouteCollectorProxyInterface $group) use ($feedback, $context, $audit, $names): void {
            Routes::plain($group->get('', function (ServerRequestInterface $request, ResponseInterface $response) use ($feedback, $context, $names): ResponseInterface {
                if (!$feedback->ready()) {
                    return $context->refuse($response, Feedback::unavailable());
                }
                $query = $request->getQueryParams();
                $page = $feedback->page([
                    'kind' => Routes::text($query['kind'] ?? null, 32),
                    'status' => Routes::text($query['status'] ?? null, 16),
                    'section' => Routes::text($query['section'] ?? null, 100),
                    'search' => Routes::text($query['search'] ?? null, 200),
                ], Routes::int($query['page'] ?? null, 1, 1, 1_000_000));
                $page['items'] = self::named($page['items'], $names);
                $feedback->pruneIfDue();

                return JsonResponse::write($response, 200, $page);
            }));

            Routes::plain($group->get('/facets', function (ServerRequestInterface $request, ResponseInterface $response) use ($feedback, $context): ResponseInterface {
                if (!$feedback->ready()) {
                    return $context->refuse($response, Feedback::unavailable());
                }

                return JsonResponse::write($response, 200, $feedback->facets());
            }));

            Routes::plain($group->get('/unread', function (ServerRequestInterface $request, ResponseInterface $response) use ($feedback, $context): ResponseInterface {
                if (!$feedback->ready()) {
                    return $context->refuse($response, Feedback::unavailable());
                }

                return JsonResponse::write($response, 200, ['unread' => $feedback->unreadCount()]);
            }));

            Routes::plain($group->get('/{id:[0-9]{1,18}}', function (ServerRequestInterface $request, ResponseInterface $response) use ($feedback, $context, $names): ResponseInterface {
                if (!$feedback->ready()) {
                    return $context->refuse($response, Feedback::unavailable());
                }
                $id = (int) $request->getAttribute('id');
                $feedback->markRead($id);
                $message = $feedback->get($id);
                if ($message === null) {
                    return $context->refuse($response, self::notFound());
                }

                return JsonResponse::write($response, 200, ['message' => self::named([$message], $names)[0]]);
            }));

            Routes::plain($group->put('/{id:[0-9]{1,18}}/status', function (ServerRequestInterface $request, ResponseInterface $response) use ($feedback, $context, $audit, $names): ResponseInterface {
                $body = Routes::body($request, 4096);
                if ($body instanceof Refusal) {
                    return $context->refuse($response, $body);
                }
                if (!$feedback->ready()) {
                    return $context->refuse($response, Feedback::unavailable());
                }
                $id = (int) $request->getAttribute('id');
                $before = $feedback->get($id);
                $status = is_string($body['status'] ?? null) ? $body['status'] : '';
                $actor = $context->actorId($request);
                $result = $before === null ? null : $feedback->setStatus($id, $status, $actor);
                if ($result instanceof Refusal) {
                    return $context->refuse($response, $result);
                }
                if ($result === null || $before === null) {
                    return $context->refuse($response, self::notFound());
                }
                if ($before['status'] !== $result['status']) {
                    $audit?->record($request, $actor, 'feedback.status_changed', 'feedback', $id, ['from' => $before['status'], 'to' => $result['status']]);
                }

                return JsonResponse::write($response, 200, ['message' => self::named([$result], $names)[0]]);
            }));

            Routes::plain($group->delete('/{id:[0-9]{1,18}}', function (ServerRequestInterface $request, ResponseInterface $response) use ($feedback, $context, $audit): ResponseInterface {
                if (!$feedback->ready()) {
                    return $context->refuse($response, Feedback::unavailable());
                }
                $id = (int) $request->getAttribute('id');
                $before = $feedback->get($id);
                if ($before === null || !$feedback->delete($id)) {
                    return $context->refuse($response, self::notFound());
                }
                $audit?->record($request, $context->actorId($request), 'feedback.deleted', 'feedback', $id, ['kind' => $before['kind'], 'status' => $before['status']]);

                return JsonResponse::write($response, 200, ['deleted' => true]);
            }));
        });
    }

    private static function notFound(): Refusal
    {
        return new Refusal(404, 'not_found', 'There is no such message.');
    }

    /**
     * @param list<array<string, mixed>> $items
     * @return list<array<string, mixed>>
     */
    private static function named(array $items, ?Closure $names): array
    {
        $ids = [];
        foreach ($items as $item) {
            foreach (['user_id', 'handled_by'] as $column) {
                if (is_int($item[$column] ?? null)) {
                    $ids[$item[$column]] = true;
                }
            }
        }
        $known = [];
        if ($names !== null && $ids !== []) {
            try {
                foreach ($names(array_keys($ids)) as $id => $name) {
                    if (is_string($name)) {
                        $known[(int) $id] = $name;
                    }
                }
            } catch (Throwable) {
                $known = [];
            }
        }
        foreach ($items as $index => $item) {
            $items[$index]['user_name'] = is_int($item['user_id']) ? ($known[$item['user_id']] ?? null) : null;
            $items[$index]['handled_by_name'] = is_int($item['handled_by']) ? ($known[$item['handled_by']] ?? null) : null;
        }

        return $items;
    }
}
