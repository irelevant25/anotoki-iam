<?php

declare(strict_types=1);

namespace Anotoki\Lib\SiteSettings\Http;

use Anotoki\Lib\Admin\AdminContext;
use Anotoki\Lib\Admin\Http\Routes;
use Anotoki\Lib\Audit\AuditRecorder;
use Anotoki\Lib\SiteSettings\PageRules;
use Anotoki\Lib\SiteSettings\SiteSettings;
use Anotoki\Lib\Support\JsonResponse;
use Anotoki\Lib\Support\Refusal;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Interfaces\RouteCollectorProxyInterface;
use Slim\Interfaces\RouteGroupInterface;
use Slim\Interfaces\RouteInterface;

/**
 * The site settings' routes, on the site's Slim app, at the paths its SiteSettings names.
 *
 * public() - what every page is told, before anybody signs in (open while the site is closed for maintenance:
 * a reserved path, which no page may claim either):
 *   GET  $publicPath    -> 200 SiteSettings::publicBody(): {maintenance?: bool, announcement?: null|{level, message},
 *                          pages?: [{path, visibility, blocked}]} - each key only where the site offers its part
 *
 * admin() - for the site's ADMIN alone: the group asks the context's isAdmin() before anything else (401, 403),
 * and the site adds its own middleware to it as to any group (the IAM's session and CSRF header):
 *   GET    $adminPath              -> 200 the page's whole: parts, switches, pages, levels, visibilities, reserved, limits
 *   PUT    $adminPath              {settings: {name: value}}  -> 200 the same - audited site_settings.changed
 *   POST   $adminPath/pages        {path, visibility?, blocked?, api_paths?} -> 201 the same - audited page_access.added
 *                                  | 409 page_exists
 *   PUT    $adminPath/pages        {pages: [{id, visibility?, blocked?, api_paths?}]} -> 200 the same - audited
 *                                  page_access.changed (an entry a row)
 *   DELETE $adminPath/pages/{id}   -> 200 the same - audited page_access.removed | 404 not_found
 * A switch or a page refused says why (422, the sentence names the setting or the path); 503 feature_unavailable
 * while the tables wait for their update. Every switch and row carries updated_by_name from the context's names.
 */
final class SiteSettingsRoutes
{
    /** The most a save's body may weigh: five messages of two thousand characters in thirty languages. */
    public const BODY_LIMIT = 512 * 1024;

    public static function public(RouteCollectorProxyInterface $app, SiteSettings $settings): RouteInterface
    {
        return Routes::plain(
            $app->get($settings->publicPath, function (ServerRequestInterface $request, ResponseInterface $response) use ($settings): ResponseInterface {
                return JsonResponse::write($response, 200, $settings->publicBody());
            }),
        );
    }

    public static function admin(RouteCollectorProxyInterface $app, SiteSettings $settings, AdminContext $context, ?AuditRecorder $audit = null): RouteGroupInterface
    {
        return Routes::adminGroup($app, $settings->adminPath, $context, function (RouteCollectorProxyInterface $group) use ($settings, $context, $audit): void {
            Routes::plain(
                $group->get('', function (ServerRequestInterface $request, ResponseInterface $response) use ($settings, $context): ResponseInterface {
                    if (!$settings->ready()) {
                        return $context->refuse($response, SiteSettings::unavailable());
                    }

                    return JsonResponse::write($response, 200, self::whole($settings, $context));
                }),
            );

            Routes::plain(
                $group->put('', function (ServerRequestInterface $request, ResponseInterface $response) use ($settings, $context, $audit): ResponseInterface {
                    $body = Routes::body($request, self::BODY_LIMIT);
                    if ($body instanceof Refusal) {
                        return $context->refuse($response, $body);
                    }
                    $values = $body['settings'] ?? null;
                    if (!is_array($values) || ($values !== [] && array_is_list($values))) {
                        return $context->refuse($response, Refusal::invalid('invalid_body', 'Send {settings: {name: value}}: the switches that changed.'));
                    }
                    $actor = $context->actorId($request);
                    $changed = $settings->save($values, $actor);
                    if ($changed instanceof Refusal) {
                        return $context->refuse($response, $changed);
                    }
                    if ($changed !== []) {
                        $audit?->record($request, $actor, 'site_settings.changed', 'site_settings', null, $changed);
                    }

                    return JsonResponse::write($response, 200, self::whole($settings, $context));
                }),
            );

            Routes::plain(
                $group->post('/pages', function (ServerRequestInterface $request, ResponseInterface $response) use ($settings, $context, $audit): ResponseInterface {
                    $body = Routes::body($request, 16 * 1024);
                    if ($body instanceof Refusal) {
                        return $context->refuse($response, $body);
                    }
                    $path = $body['path'] ?? null;
                    $visibility = $body['visibility'] ?? 'ADMIN';
                    $blocked = $body['blocked'] ?? false;
                    $apiPaths = $body['api_paths'] ?? [];
                    if (!is_string($path) || !is_string($visibility) || !is_bool($blocked) || !is_array($apiPaths)) {
                        return $context->refuse($response, Refusal::invalid('invalid_body', 'Send {path, visibility, blocked, api_paths}.'));
                    }
                    $actor = $context->actorId($request);
                    $row = $settings->addPage($path, $visibility, $blocked, array_values($apiPaths), $actor);
                    if ($row instanceof Refusal) {
                        return $context->refuse($response, $row);
                    }
                    $audit?->record($request, $actor, 'page_access.added', 'page_access', $row['id'], [
                        'path' => $row['path'],
                        'visibility' => $row['visibility'] ?? $visibility,
                        'blocked' => $row['blocked'] ?? $blocked,
                        'api_paths' => $row['api_paths'] ?? [],
                    ]);

                    return JsonResponse::write($response, 201, self::whole($settings, $context));
                }),
            );

            Routes::plain(
                $group->put('/pages', function (ServerRequestInterface $request, ResponseInterface $response) use ($settings, $context, $audit): ResponseInterface {
                    $body = Routes::body($request, 256 * 1024);
                    if ($body instanceof Refusal) {
                        return $context->refuse($response, $body);
                    }
                    $pages = $body['pages'] ?? null;
                    if (!is_array($pages) || !array_is_list($pages)) {
                        return $context->refuse($response, Refusal::invalid('invalid_body', 'Send {pages: [{id, visibility, blocked, api_paths}]}: the pages that changed.'));
                    }
                    $actor = $context->actorId($request);
                    $changed = $settings->savePages($pages, $actor);
                    if ($changed instanceof Refusal) {
                        return $context->refuse($response, $changed);
                    }
                    foreach ($changed as $id => $details) {
                        $audit?->record($request, $actor, 'page_access.changed', 'page_access', $id, $details);
                    }

                    return JsonResponse::write($response, 200, self::whole($settings, $context));
                }),
            );

            Routes::plain(
                $group->delete('/pages/{id:[0-9]{1,18}}', function (ServerRequestInterface $request, ResponseInterface $response) use ($settings, $context, $audit): ResponseInterface {
                    if (!$settings->ready()) {
                        return $context->refuse($response, SiteSettings::unavailable());
                    }
                    $id = (int) $request->getAttribute('id');
                    $row = $settings->deletePage($id);
                    if ($row === null) {
                        return $context->refuse($response, new Refusal(404, 'not_found', 'There is no such page in the table.'));
                    }
                    $audit?->record($request, $context->actorId($request), 'page_access.removed', 'page_access', $id, [
                        'path' => $row['path'],
                        'visibility' => $row['visibility'],
                        'blocked' => $row['blocked'],
                        'api_paths' => $row['api_paths'],
                    ]);

                    return JsonResponse::write($response, 200, self::whole($settings, $context));
                }),
            );
        });
    }

    /**
     * What the admin page draws: the parts the site offers, each switch with its history and the name of whoever
     * changed it last, every page with its API paths, and what the page offers and refuses before it asks.
     *
     * @return array<string, mixed>
     */
    private static function whole(SiteSettings $settings, AdminContext $context): array
    {
        $switches = $settings->switches();
        $pages = $settings->pages();
        $ids = [];
        foreach ([...array_values($switches), ...$pages] as $item) {
            if (is_int($item['updated_by'])) {
                $ids[$item['updated_by']] = true;
            }
        }
        $names = $context->names(array_keys($ids));
        $named = static fn(array $item): array => $item + ['updated_by_name' => is_int($item['updated_by']) ? $names[$item['updated_by']] ?? null : null];

        $out = [];
        foreach ($switches as $name => $switch) {
            // A message is an object on the wire, empty or not.
            $out[$name] = $named(['value' => is_array($switch['value']) ? (object) $switch['value'] : $switch['value']] + $switch);
        }

        return [
            'parts' => $settings->parts(),
            'settings' => (object) $out,
            'pages' => $settings->offers(SiteSettings::PAGE_ACCESS) ? array_map(static fn(array $row): array => $named($row) + ['refused' => $settings->pageRefusal($row['path'])], $pages) : [],
            'levels' => SiteSettings::LEVELS,
            'visibilities' => PageRules::VISIBILITIES,
            'reserved' => ['pages' => $settings->reservedPages(), 'api_paths' => $settings->reservedApiPaths()],
            'limits' => [
                'message' => SiteSettings::MESSAGE_MAX,
                'message_languages' => SiteSettings::MESSAGE_LANGUAGES_MAX,
                'api_paths' => SiteSettings::API_PATHS_MAX,
                'pages' => SiteSettings::PAGES_MAX,
            ],
        ];
    }
}
