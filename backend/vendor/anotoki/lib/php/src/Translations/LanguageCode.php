<?php

declare(strict_types=1);

namespace Anotoki\Lib\Translations;

/** A language code as the family writes them: "sk", "pt-br". */
final class LanguageCode
{
    public const PATTERN = '/^[a-z]{2}(-[a-z]{2})?$/D';

    /**
     * Is it a code? Asked before any query that takes a code from an address:
     * anything else is no language, and bytes that are not UTF-8 never reach the
     * database (which would refuse them - a 500 for what is only a wrong address).
     */
    public static function ok(mixed $code): bool
    {
        return is_string($code) && preg_match(self::PATTERN, $code) === 1;
    }

    /**
     * The language a code is answered in: itself when it is offered; a code with
     * a region that is not offered itself, by its language when that one is
     * ("sk-sk", a Slovak browser's first wish, by "sk"); anything else by the
     * fallback.
     *
     * @param list<string> $offered
     */
    public static function resolve(string $code, array $offered, string $fallback): string
    {
        $base = substr($code, 0, 2);

        return match (true) {
            in_array($code, $offered, true) => $code,
            in_array($base, $offered, true) => $base,
            default => $fallback,
        };
    }
}
