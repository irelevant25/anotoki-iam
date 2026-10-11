<?php

declare(strict_types=1);

namespace Anotoki\Lib\Translations\Cli;

use JsonException;
use RuntimeException;

/**
 * A site's compiled English - the words its pages show while no bundle is in
 * memory - read so that a test can hold it to the English the migrations
 * seeded. Three ways the sites keep it:
 *
 *   - a TypeScript object: `export const en = {...} as const;` (the IAM's en.ts),
 *     `export const KEPT_ENGLISH: Readonly<Record<string, string>> = {...};`
 *     (Piano Academy), or several `const ENGLISH = {...}` maps across files
 *     (Japanese Academy) - read as text, `'key': 'value',` entries, single or
 *     double quoted, with escapes, the value on the key's line or the next;
 *   - flat JSON parts, one {"key": "English"} object each, a key in one part
 *     only (the build analyzer's en/*.json).
 *
 * It refuses whatever it cannot read whole - a key it could not read would
 * otherwise pass as one that is not there.
 */
final class EnglishDictionary
{
    /**
     * The entries of a file: [key => English]. A .json file is one flat object;
     * a .ts file the object named $const - or, without a name, the one object the
     * file declares.
     *
     * @return array<string, string>
     */
    public static function read(string $path, ?string $const = null): array
    {
        $raw = is_file($path) ? @file_get_contents($path) : false;
        if (!is_string($raw)) {
            throw new RuntimeException("There is no dictionary at $path.");
        }

        return str_ends_with(strtolower($path), '.json') ? self::json($raw, $path) : self::typeScript($raw, $path, $const);
    }

    /**
     * Several parts as one dictionary: a key in more than one part is refused.
     *
     * @param array<string, string> ...$parts
     * @return array<string, string>
     */
    public static function merge(array ...$parts): array
    {
        $entries = [];
        foreach ($parts as $part) {
            foreach ($part as $key => $text) {
                if (array_key_exists($key, $entries)) {
                    throw new RuntimeException("The dictionary has the key $key in two parts.");
                }
                $entries[$key] = $text;
            }
        }

        return $entries;
    }

    /** @return array<string, string> */
    private static function json(string $raw, string $path): array
    {
        if (str_starts_with($raw, "\xEF\xBB\xBF")) {
            $raw = substr($raw, 3);
        }
        try {
            $values = json_decode($raw, true, 4, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new RuntimeException("$path is not JSON: " . $e->getMessage() . '.');
        }
        if (!is_array($values) || $values === [] || array_is_list($values)) {
            throw new RuntimeException("$path does not hold one flat object of strings.");
        }
        $entries = [];
        foreach ($values as $key => $text) {
            if (!is_string($text)) {
                throw new RuntimeException("$path: the English of $key is not a string.");
            }
            $entries[(string) $key] = $text;
        }

        return $entries;
    }

    /** @return array<string, string> */
    private static function typeScript(string $raw, string $path, ?string $const): array
    {
        $text = KeyScanner::sourceText($raw, false);
        $declaration = '/^(?:export\s+)?const\s+(\w+)(?:\s*:[^=\n]+)?\s*=\s*\{\n(.*?)\n\}(?:\s*as\s+const)?;/ms';
        if (preg_match_all($declaration, $text, $objects, PREG_SET_ORDER) === false) {
            throw new RuntimeException("$path could not be read: " . preg_last_error_msg() . '.');
        }
        if ($const !== null) {
            $objects = array_values(array_filter($objects, static fn (array $object): bool => $object[1] === $const));
        }
        if (count($objects) !== 1) {
            throw new RuntimeException($objects === []
                ? "$path declares no object of strings" . ($const === null ? '' : " named $const") . '.'
                : "$path declares more than one object: name the one that holds the English.");
        }
        $body = $objects[0][2];

        $string = '\'(?:[^\'\\\\\n]|\\\\.)*\'|"(?:[^"\\\\\n]|\\\\.)*"';
        $entry = '~^[ \t]*(' . $string . ')[ \t]*:\s*(' . $string . ')[ \t]*,?[ \t]*$~mu';
        if (preg_match_all($entry, $body, $matches, PREG_SET_ORDER) === false) {
            throw new RuntimeException("$path could not be read: " . preg_last_error_msg() . '.');
        }

        $entries = [];
        foreach ($matches as $match) {
            $key = self::unquote($match[1]);
            if (array_key_exists($key, $entries)) {
                throw new RuntimeException("$path has the key $key twice.");
            }
            $entries[$key] = self::unquote($match[2]);
        }

        // Nothing of the object may be left unread. Counting the lines that look
        // like an entry would miss exactly what does not look like one - a key
        // without quotes (`save: 'Save',` is TypeScript too), a spread, a computed
        // key, a value put together - and such a key would be in the compiled
        // English, in no test, and a bare key on the page.
        $rest = preg_replace($entry, '', $body);
        if ($rest === null) {
            throw new RuntimeException("$path could not be read: " . preg_last_error_msg() . '.');
        }
        $left = array_values(array_filter(array_map('trim', explode("\n", $rest)), static fn (string $line): bool => $line !== ''));
        if ($left !== []) {
            throw new RuntimeException("$path: could not read \"{$left[0]}\" - it is written in a way this reader does not know ("
                . count($left) . (count($left) === 1 ? ' such line).' : ' such lines).'));
        }
        if ($entries === []) {
            throw new RuntimeException("$path has no entries.");
        }

        return $entries;
    }

    /** A quoted TypeScript string literal's value, escapes undone. */
    private static function unquote(string $literal): string
    {
        return (string) preg_replace_callback(
            '/\\\\(u\{[0-9A-Fa-f]{1,6}\}|u[0-9A-Fa-f]{4}|x[0-9A-Fa-f]{2}|.)/su',
            static function (array $m): string {
                $escape = $m[1];

                return match (true) {
                    $escape === 'n' => "\n",
                    $escape === 't' => "\t",
                    $escape === 'r' => "\r",
                    $escape[0] === 'u' && ($escape[1] ?? '') === '{' => self::character((int) hexdec(substr($escape, 2, -1))),
                    $escape[0] === 'u' && strlen($escape) === 5 => self::character((int) hexdec(substr($escape, 1))),
                    $escape[0] === 'x' && strlen($escape) === 3 => self::character((int) hexdec(substr($escape, 1))),
                    default => $escape, // \' \" \\ and anything else stand for themselves
                };
            },
            substr($literal, 1, -1)
        );
    }

    /** A code point as UTF-8, without the mbstring extension. */
    private static function character(int $codePoint): string
    {
        $json = json_decode('"' . ($codePoint > 0xFFFF
            ? sprintf('\\u%04x\\u%04x', 0xD800 + (($codePoint - 0x10000) >> 10), 0xDC00 + (($codePoint - 0x10000) & 0x3FF))
            : sprintf('\\u%04x', $codePoint)) . '"');

        return is_string($json) ? $json : '';
    }
}
