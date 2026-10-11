<?php

declare(strict_types=1);

namespace Anotoki\Lib\Admin\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use RuntimeException;
use Slim\Factory\Psr17\Psr17Factory;
use Slim\Factory\Psr17\Psr17FactoryProvider;

/**
 * A file sent as an answer's body straight from the disk - a backup of many
 * megabytes, a picture - never copied into memory or a temporary file first:
 * through the site's PSR-17 stream factory (the one its Slim app was made
 * with, found as Slim finds it, or the one given), with byte ranges for an
 * <audio> that seeks.
 *
 * @internal
 */
final class FileStreams
{
    public static function factory(?StreamFactoryInterface $given = null): StreamFactoryInterface
    {
        if ($given !== null) {
            return $given;
        }
        /** @var class-string<Psr17Factory> $factory */
        foreach (Psr17FactoryProvider::getFactories() as $factory) {
            if ($factory::isStreamFactoryAvailable()) {
                return $factory::getStreamFactory();
            }
        }
        throw new RuntimeException('No PSR-17 stream factory is installed (slim/psr7, nyholm/psr7, guzzlehttp/psr7, laminas-diactoros).');
    }

    /**
     * The whole file - or, with a Range header of one range, that range (206), or 416 when it cannot be
     * satisfied. Content-Length always; Accept-Ranges when $ranges.
     */
    public static function send(
        ServerRequestInterface $request,
        ResponseInterface $response,
        string $path,
        StreamFactoryInterface $streams,
        bool $ranges = false,
    ): ResponseInterface {
        $size = @filesize($path);
        if ($size === false) {
            throw new RuntimeException('The file cannot be read.');
        }
        if ($ranges) {
            $response = $response->withHeader('Accept-Ranges', 'bytes');
            $range = $request->getHeaderLine('Range');
            if ($range !== '' && preg_match('/^bytes=(\d*)-(\d*)$/D', trim($range), $match) === 1 && ($match[1] !== '' || $match[2] !== '')) {
                if ($match[1] === '') {
                    $length = min((int) $match[2], $size);
                    $start = $size - $length;
                    $end = $size - 1;
                } else {
                    $start = (int) $match[1];
                    $end = $match[2] === '' ? $size - 1 : min((int) $match[2], $size - 1);
                }
                if ($size === 0 || $start > $end || $start >= $size) {
                    return $response->withStatus(416)->withHeader('Content-Range', 'bytes */' . $size)->withHeader('Content-Length', '0');
                }
                $length = $end - $start + 1;
                $source = @fopen($path, 'rb');
                if ($source === false) {
                    throw new RuntimeException('The file cannot be read.');
                }
                $slice = fopen('php://temp', 'w+b');
                fseek($source, $start);
                stream_copy_to_stream($source, $slice, $length);
                fclose($source);
                rewind($slice);

                return $response
                    ->withStatus(206)
                    ->withHeader('Content-Range', 'bytes ' . $start . '-' . $end . '/' . $size)
                    ->withHeader('Content-Length', (string) $length)
                    ->withBody($streams->createStreamFromResource($slice));
            }
        }

        return $response
            ->withHeader('Content-Length', (string) $size)
            ->withBody($streams->createStreamFromFile($path, 'rb'));
    }
}
