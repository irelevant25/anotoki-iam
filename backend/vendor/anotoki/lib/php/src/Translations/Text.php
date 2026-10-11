<?php

declare(strict_types=1);

namespace Anotoki\Lib\Translations;

/**
 * Text as people see it, and as the browser measures it - so the server and
 * the admin pages agree on what is empty, what is too long and where a string
 * begins and ends.
 */
final class Text
{
    /**
     * What the browser counts as a space - the characters String.prototype.trim()
     * takes away and \s matches in JavaScript, character for character: tab, the
     * line breaks, the space, the no-break space, the Unicode spaces, the line and
     * paragraph separators and the byte-order mark. Written out rather than as \s
     * and \p{Z}: those also take U+0085 and U+180E, which the browser does not,
     * and then the two sides would disagree on where a text ends.
     */
    private const SPACE = '[\t\n\x0B\f\r \x{A0}\x{1680}\x{2000}-\x{200A}\x{2028}\x{2029}\x{202F}\x{205F}\x{3000}\x{FEFF}]';

    /**
     * A text field's value as it is kept: valid UTF-8 without control characters
     * but tabs and line breaks - or null for what is not text at all. The controls
     * are dropped rather than refused: one pasted from a word processor would
     * otherwise make a string that can never be saved, however often it is tried.
     */
    public static function clean(mixed $value): ?string
    {
        if (!is_string($value) || preg_match('//u', $value) !== 1) {
            return null;
        }

        return self::withoutControls($value);
    }

    /** The text without control characters, tabs and line breaks aside. */
    public static function withoutControls(string $text): string
    {
        return (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $text);
    }

    /**
     * The text without the spaces around it - exactly the ones the browser's
     * trim() takes away, not only the ASCII ones PHP's trim() knows.
     *
     * A text the expression cannot get through - a run of more than a million
     * spaces inside it, which a body of 1 MiB has room for - comes back as it is,
     * never as an empty one: whoever measures it next refuses it as too long.
     * Taken for nothing, it would have deleted the string it was sent for.
     */
    public static function trim(string $text): string
    {
        return preg_replace('/^' . self::SPACE . '+|' . self::SPACE . '+$/u', '', $text) ?? $text;
    }

    /**
     * Is there nothing in the text to see? True when it has no character but
     * white space, separators and format characters: a zero-width space, a word
     * joiner or a soft hyphen alone shows as nothing, though trim() leaves each
     * of them - it looks like a cleared field, and is one.
     */
    public static function blank(string $text): bool
    {
        return preg_match('/[^\s\p{Z}\p{Cf}]/u', $text) !== 1;
    }

    /** How many characters: code points, which is at most what the browser counts (UTF-16 units). */
    public static function length(string $text): int
    {
        return (int) preg_match_all('/./us', $text);
    }

    /** The first $max characters of a UTF-8 text (a $max of at most 65535). */
    public static function clip(string $text, int $max): string
    {
        if (strlen($text) <= $max) {
            return $text;
        }

        return preg_match('/^.{0,' . $max . '}/us', $text, $match) === 1 ? $match[0] : '';
    }
}
