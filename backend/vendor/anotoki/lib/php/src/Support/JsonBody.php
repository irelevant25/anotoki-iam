<?php

declare(strict_types=1);

namespace Anotoki\Lib\Support;

use JsonException;
use Psr\Http\Message\ServerRequestInterface;
use stdClass;

/**
 * A request's JSON body, read by the library itself from the raw stream - not
 * from what the site's body parser made of it, which differs from site to site
 * (and would turn {} and [] into the same empty array).
 */
final class JsonBody
{
    /** How deep a body may nest: every body the library takes is a few levels deep. */
    private const DEPTH = 32;

    /**
     * The body, objects as stdClass and lists as arrays - or the refusal, the
     * first that applies:
     *
     *   413 too_large               more than $limit bytes, by what Content-Length says or by what arrived
     *   415 unsupported_media_type  a Content-Type other than application/json (a form, which any page
     *                               open in the browser can send without asking first, among them)
     *   422 empty_body              nothing arrived - which is also what PHP makes of a body larger than its
     *                               post_max_size, so it is said rather than read as an empty object
     *   400 invalid_json            not JSON
     *
     * The stream is rewound first (the site's parser may have read it) and read
     * no further than one byte past the limit.
     */
    public static function read(ServerRequestInterface $request, int $limit): mixed
    {
        $tooLarge = new Refusal(413, 'too_large', 'That is more than this route takes (' . self::size($limit) . ').');

        $declared = trim($request->getHeaderLine('Content-Length'));
        if ($declared !== '' && (!ctype_digit($declared) || strlen($declared) > 15 || (int) $declared > $limit)) {
            return $tooLarge;
        }

        $type = strtolower(trim(explode(';', $request->getHeaderLine('Content-Type'), 2)[0]));
        if ($type !== 'application/json') {
            return new Refusal(415, 'unsupported_media_type', 'Send it as JSON, with Content-Type: application/json.');
        }

        $stream = $request->getBody();
        if ($stream->isSeekable()) {
            $stream->rewind();
        }
        $raw = '';
        while (!$stream->eof() && strlen($raw) <= $limit) {
            $chunk = $stream->read($limit + 1 - strlen($raw));
            if ($chunk === '') {
                break;
            }
            $raw .= $chunk;
        }
        if (strlen($raw) > $limit) {
            return $tooLarge;
        }
        if (trim($raw) === '') {
            return Refusal::invalid('empty_body', 'Nothing arrived. A body larger than the server accepts arrives empty.');
        }

        try {
            return json_decode($raw, false, self::DEPTH, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            return new Refusal(400, 'invalid_json', 'The body is not JSON (' . $e->getMessage() . ').');
        }
    }

    /**
     * An object of a body as an array of its members - or null for anything
     * else: a list (the empty one too), a text, a number, true, nothing. PHP
     * makes integers of keys like "12": whoever walks it reads each key as
     * (string) $key.
     *
     * @return ?array<array-key, mixed>
     */
    public static function map(mixed $value): ?array
    {
        return $value instanceof stdClass ? get_object_vars($value) : null;
    }

    private static function size(int $bytes): string
    {
        return match (true) {
            $bytes >= 1024 * 1024 && $bytes % (1024 * 1024) === 0 => ($bytes / (1024 * 1024)) . ' MB',
            $bytes >= 1024 && $bytes % 1024 === 0 => ($bytes / 1024) . ' KB',
            default => $bytes . ' bytes',
        };
    }
}
