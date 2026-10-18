<?php

declare(strict_types=1);

namespace Anotoki\Lib\Files\Http;

use Anotoki\Lib\Admin\AdminContext;
use Anotoki\Lib\Admin\Http\FileStreams;
use Anotoki\Lib\Admin\Http\Routes;
use Anotoki\Lib\Audit\AuditRecorder;
use Anotoki\Lib\Files\FileExplorer;
use Anotoki\Lib\Support\JsonResponse;
use Anotoki\Lib\Support\Refusal;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\UploadedFileInterface;
use Slim\Interfaces\RouteCollectorProxyInterface;
use Slim\Interfaces\RouteGroupInterface;
use Slim\Interfaces\RouteInterface;

/**
 * The file explorer's routes, on the site's Slim app.
 *
 * admin() - for the site's ADMIN alone: the group asks the context's isAdmin()
 * before anything else (401, 403), and the site adds its own middleware to it
 * as to any group:
 *   GET    $base/roots                               {roots}
 *   GET    $base/{root}?path=&search=&page=&mode=new  FileExplorer::list()
 *   POST   $base/{root}/rescan                       the snapshot's status
 *   POST   $base/{root}/upload   multipart: path (the folder), replace (1), the files  -> {uploaded, refused, files}
 *   POST   $base/{root}/folder   {path}              -> {path}            audited folder.created
 *   POST   $base/{root}/move     {from, to}          -> {from, to, op}    audited file.renamed | file.moved
 *   DELETE $base/{root}?path=                        -> {path, trashed}   audited file.deleted
 *   GET    $base/{root}/trash                        {items}
 *   POST   $base/{root}/trash/restore {trashed}      -> {path}            audited file.restored
 *   DELETE $base/{root}/trash?trashed=               -> {deleted, bytes, failed}  audited file.trash_emptied
 * Every upload kept is audited file.uploaded.
 *
 * raw() - a preview, DELIBERATELY outside the admin check (an <img> sends no
 * Bearer header): the link's signature is the check.
 *   GET $path?root=&path=&exp=&sig=  the file, its link signed for five minutes - inline only pictures,
 *       sound, video and short text; every answer with Content-Security-Policy: sandbox; default-src 'none',
 *       nosniff, Cross-Origin-Resource-Policy: same-origin and Cache-Control: private, no-store.
 * The listing's links name the raw route where it is mounted - under whatever
 * group, prefix or base path the site gives it.
 */
final class FilesRoutes
{
    /** A root's name - never raw or roots, so the routes of those names are never shadowed (FastRoute refuses it). */
    private const ROOT = '/{root:(?!(?:raw|roots)$)[a-z][a-z0-9_-]{0,31}}';

    public static function admin(RouteCollectorProxyInterface $app, string $base, FileExplorer $explorer, AdminContext $context, ?AuditRecorder $audit = null): RouteGroupInterface
    {
        return Routes::adminGroup($app, $base, $context, function (RouteCollectorProxyInterface $group) use ($explorer, $context, $audit): void {
            $answer = static function (ResponseInterface $response, mixed $result, int $status = 200) use ($context): ResponseInterface {
                return $result instanceof Refusal ? $context->refuse($response, $result) : JsonResponse::write($response, $status, $result);
            };
            $record = static function (ServerRequestInterface $request, string $action, string $root, string $path, array $details = []) use ($audit, $context): void {
                $audit?->record($request, $context->actorId($request), $action, 'file', $root . ':' . $path, ['root' => $root, 'path' => $path] + $details);
            };

            Routes::plain(
                $group->get('/roots', function (ServerRequestInterface $request, ResponseInterface $response) use ($explorer): ResponseInterface {
                    return JsonResponse::write($response, 200, ['roots' => $explorer->roots()]);
                }),
            );

            Routes::plain(
                $group->get(self::ROOT, function (ServerRequestInterface $request, ResponseInterface $response) use ($explorer, $answer): ResponseInterface {
                    $query = $request->getQueryParams();

                    return $answer(
                        $response,
                        $explorer->list(
                            (string) $request->getAttribute('root'),
                            Routes::text($query['path'] ?? null, 1024),
                            Routes::text($query['search'] ?? null, 200),
                            Routes::int($query['page'] ?? null, 1, 1, 1_000_000),
                            ($query['mode'] ?? null) === 'new',
                        ),
                    );
                }),
            );

            Routes::plain(
                $group->post(self::ROOT . '/rescan', function (ServerRequestInterface $request, ResponseInterface $response) use ($explorer, $answer): ResponseInterface {
                    return $answer($response, $explorer->rescan((string) $request->getAttribute('root')));
                }),
            );

            Routes::plain(
                $group->post(self::ROOT . '/upload', function (ServerRequestInterface $request, ResponseInterface $response) use ($explorer, $answer, $record): ResponseInterface {
                    $root = (string) $request->getAttribute('root');
                    $fields = $request->getParsedBody();
                    $fields = is_array($fields) ? $fields : [];
                    $folder = is_string($fields['path'] ?? null) ? $fields['path'] : '';
                    $replace = in_array($fields['replace'] ?? null, ['1', 'true', 'yes', 'on'], true);
                    $result = $explorer->upload($root, $folder, self::uploadedFiles($request->getUploadedFiles()), $replace);
                    if (is_array($result)) {
                        foreach ($result['files'] as $file) {
                            if ($file['status'] !== 'refused') {
                                $record($request, 'file.uploaded', $root, (string) $file['path'], ['size' => $file['size'] ?? null, 'replaced' => $file['status'] === 'replaced']);
                            }
                        }
                    }

                    return $answer($response, $result);
                }),
            );

            Routes::plain(
                $group->post(self::ROOT . '/folder', function (ServerRequestInterface $request, ResponseInterface $response) use ($explorer, $answer, $record): ResponseInterface {
                    $body = Routes::body($request, 4096);
                    if ($body instanceof Refusal) {
                        return $answer($response, $body);
                    }
                    $root = (string) $request->getAttribute('root');
                    $result = $explorer->makeFolder($root, is_string($body['path'] ?? null) ? $body['path'] : '');
                    if (is_array($result)) {
                        $record($request, 'folder.created', $root, $result['path']);
                    }

                    return $answer($response, $result, 201);
                }),
            );

            Routes::plain(
                $group->post(self::ROOT . '/move', function (ServerRequestInterface $request, ResponseInterface $response) use ($explorer, $answer, $record): ResponseInterface {
                    $body = Routes::body($request, 8192);
                    if ($body instanceof Refusal) {
                        return $answer($response, $body);
                    }
                    $root = (string) $request->getAttribute('root');
                    $result = $explorer->move($root, is_string($body['from'] ?? null) ? $body['from'] : '', is_string($body['to'] ?? null) ? $body['to'] : '');
                    if (is_array($result)) {
                        $record($request, $result['op'] === 'rename' ? 'file.renamed' : 'file.moved', $root, $result['to'], ['from' => $result['from']]);
                    }

                    return $answer($response, $result);
                }),
            );

            Routes::plain(
                $group->delete(self::ROOT, function (ServerRequestInterface $request, ResponseInterface $response) use ($explorer, $answer, $record): ResponseInterface {
                    $root = (string) $request->getAttribute('root');
                    $result = $explorer->delete($root, Routes::text($request->getQueryParams()['path'] ?? null, 1024));
                    if (is_array($result)) {
                        $record($request, 'file.deleted', $root, $result['path'], ['trashed' => $result['trashed']]);
                    }

                    return $answer($response, $result);
                }),
            );

            Routes::plain(
                $group->get(self::ROOT . '/trash', function (ServerRequestInterface $request, ResponseInterface $response) use ($explorer, $answer): ResponseInterface {
                    $items = $explorer->trash((string) $request->getAttribute('root'));

                    return $answer($response, is_array($items) ? ['items' => $items] : $items);
                }),
            );

            Routes::plain(
                $group->post(self::ROOT . '/trash/restore', function (ServerRequestInterface $request, ResponseInterface $response) use ($explorer, $answer, $record): ResponseInterface {
                    $body = Routes::body($request, 4096);
                    if ($body instanceof Refusal) {
                        return $answer($response, $body);
                    }
                    $root = (string) $request->getAttribute('root');
                    $trashed = is_string($body['trashed'] ?? null) ? $body['trashed'] : '';
                    $result = $explorer->restore($root, $trashed);
                    if (is_array($result)) {
                        $record($request, 'file.restored', $root, $result['path'], ['trashed' => $trashed]);
                    }

                    return $answer($response, $result);
                }),
            );

            Routes::plain(
                $group->delete(self::ROOT . '/trash', function (ServerRequestInterface $request, ResponseInterface $response) use ($explorer, $answer, $record): ResponseInterface {
                    $root = (string) $request->getAttribute('root');
                    $one = Routes::text($request->getQueryParams()['trashed'] ?? null, 1024);
                    $result = $explorer->emptyTrash($root, $one === '' ? null : $one);
                    if (is_array($result) && $result['deleted'] > 0) {
                        $record($request, 'file.trash_emptied', $root, $one, ['deleted' => $result['deleted'], 'bytes' => $result['bytes']]);
                    }

                    return $answer($response, $result);
                }),
            );
        });
    }

    /** GET $path?root=&path=&exp=&sig= - a preview by its signed link (see the class). */
    public static function raw(RouteCollectorProxyInterface $app, string $path, FileExplorer $explorer, AdminContext $context, ?StreamFactoryInterface $streams = null): RouteInterface
    {
        $route = Routes::plain(
            $app->get($path, function (ServerRequestInterface $request, ResponseInterface $response) use ($explorer, $context, $streams): ResponseInterface {
                $query = $request->getQueryParams();
                $file = $explorer->raw(
                    is_string($query['root'] ?? null) ? $query['root'] : '',
                    is_string($query['path'] ?? null) ? $query['path'] : '',
                    is_string($query['exp'] ?? null) ? $query['exp'] : '',
                    is_string($query['sig'] ?? null) ? $query['sig'] : '',
                );
                $response = $response
                    ->withHeader('Content-Security-Policy', "sandbox; default-src 'none'")
                    ->withHeader('X-Content-Type-Options', 'nosniff')
                    ->withHeader('Cross-Origin-Resource-Policy', 'same-origin')
                    ->withHeader('Cache-Control', 'private, no-store')
                    ->withHeader('Referrer-Policy', 'no-referrer');
                if ($file === null) {
                    return $context->refuse($response, new Refusal(404, 'not_found', 'This preview link is no longer good. Open the folder again.'));
                }
                $name = str_replace(['"', '\\', "\r", "\n"], '_', $file['name']);

                return FileStreams::send($request, $response, $file['path'], FileStreams::factory($streams), ranges: true)
                    ->withHeader('Content-Type', $file['type'])
                    ->withHeader('Content-Disposition', ($file['inline'] ? 'inline' : 'attachment') . '; filename="' . $name . '"; filename*=UTF-8\'\'' . rawurlencode($file['name']));
            }),
        );
        // The route's whole pattern holds the groups it is mounted in; the base path comes before them all.
        $explorer->servedAt($app->getBasePath() . $route->getPattern());

        return $route;
    }

    /**
     * Every uploaded file of a request, however its fields nest them (file, files[], files[a][b]).
     *
     * @param array<mixed> $files
     * @return list<UploadedFileInterface>
     */
    private static function uploadedFiles(array $files): array
    {
        $out = [];
        array_walk_recursive($files, static function (mixed $file) use (&$out): void {
            if ($file instanceof UploadedFileInterface) {
                $out[] = $file;
            }
        });

        return $out;
    }
}
