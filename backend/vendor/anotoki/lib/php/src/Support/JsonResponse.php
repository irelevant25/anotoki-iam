<?php

declare(strict_types=1);

namespace Anotoki\Lib\Support;

use Psr\Http\Message\ResponseInterface;

/**
 * The library's JSON answer: UTF-8, never cached (Cache-Control: no-store), and
 * never failing on text that is not UTF-8 - a migration file's bad bytes, say,
 * show as U+FFFD instead.
 */
final class JsonResponse
{
    public const FLAGS = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR;

    public static function write(ResponseInterface $response, int $status, mixed $body): ResponseInterface
    {
        $response->getBody()->write(json_encode($body, self::FLAGS));

        return $response
            ->withStatus($status)
            ->withHeader('Content-Type', 'application/json; charset=utf-8')
            ->withHeader('Cache-Control', 'no-store');
    }
}
