<?php

declare(strict_types=1);

namespace Anotoki\Lib\Translations;

/**
 * What stands in a string for a value the code fills in: {name}. The same
 * expression as the pages' (the Angular half's) and every site's server.
 */
final class Placeholders
{
    public const PATTERN = '/\{([A-Za-z][A-Za-z0-9_]*)\}/';

    /**
     * The placeholders a string uses, each once, in the order they first appear.
     *
     * @return list<string>
     */
    public static function names(string $text): array
    {
        preg_match_all(self::PATTERN, $text, $matches);

        return array_values(array_unique($matches[1]));
    }

    /**
     * With every placeholder taken out, is a brace left? What is left would be
     * shown to the reader as it is written - "{ count }", "{počet}", "{count",
     * "{{count}}". (A replace that fails leaves the text, which is then judged whole.)
     */
    public static function strayBrace(string $text): bool
    {
        return preg_match('/[{}]/', preg_replace(self::PATTERN, '', $text) ?? $text) === 1;
    }
}
