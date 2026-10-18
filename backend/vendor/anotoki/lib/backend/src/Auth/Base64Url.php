<?php

declare(strict_types=1);

namespace Anotoki\Lib\Auth;

/** base64url without padding (RFC 7515), strictly: anything but its alphabet decodes to ''. */
final class Base64Url
{
    public static function encode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    public static function decode(string $data): string
    {
        if ($data === '' || preg_match('/^[A-Za-z0-9_-]+$/D', $data) !== 1) {
            return '';
        }
        $decoded = base64_decode(strtr($data, '-_', '+/'), true);

        return $decoded === false ? '' : $decoded;
    }

    /**
     * A JSON object in base64url (a token's header or payload), as an array -
     * or null for anything else: a list, a scalar, an empty object (no header
     * or payload is empty), JSON nested deeper than a token ever is.
     *
     * @return ?array<string, mixed>
     */
    public static function json(string $segment): ?array
    {
        $decoded = json_decode(self::decode($segment), true, 32);

        return is_array($decoded) && $decoded !== [] && !array_is_list($decoded) ? $decoded : null;
    }
}
