<?php

declare(strict_types=1);

namespace Anotoki\Lib\Support;

/**
 * Text as characters, without the mbstring extension (a host may lack it):
 * PCRE's /u does the work.
 */
final class Utf8
{
    /** $text as valid UTF-8: every byte that is no part of a character becomes "?". */
    public static function scrub(string $text): string
    {
        if (preg_match('//u', $text) === 1) {
            return $text;
        }

        // Each valid sequence kept, each stray byte replaced.
        return (string) preg_replace_callback(
            '/[\x00-\x7F]|[\xC2-\xDF][\x80-\xBF]|\xE0[\xA0-\xBF][\x80-\xBF]|[\xE1-\xEC\xEE\xEF][\x80-\xBF]{2}|\xED[\x80-\x9F][\x80-\xBF]|\xF0[\x90-\xBF][\x80-\xBF]{2}|[\xF1-\xF3][\x80-\xBF]{3}|\xF4[\x80-\x8F][\x80-\xBF]{2}|(.)/s',
            static fn (array $match): string => isset($match[1]) ? '?' : $match[0],
            $text
        );
    }

    /** How many characters (code points) $text has; its bytes when it is no UTF-8. */
    public static function length(string $text): int
    {
        $count = preg_match_all('/./su', $text);

        return $count === false ? strlen($text) : $count;
    }

    /** The first $limit characters of $text (scrubbed first). */
    public static function prefix(string $text, int $limit): string
    {
        $text = self::scrub($text);
        if ($limit <= 0) {
            return '';
        }
        if (strlen($text) <= $limit) {
            return $text;
        }
        preg_match('/^.{0,' . $limit . '}/su', $text, $match);

        return $match[0] ?? '';
    }

    /** $text cut to at most $limit characters, "…" at the cut. */
    public static function cut(string $text, int $limit): string
    {
        $text = self::scrub($text);
        if (self::length($text) <= $limit) {
            return $text;
        }

        return self::prefix($text, max(0, $limit - 1)) . '…';
    }
}
