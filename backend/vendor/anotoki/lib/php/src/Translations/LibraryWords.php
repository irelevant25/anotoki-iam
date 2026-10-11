<?php

declare(strict_types=1);

namespace Anotoki\Lib\Translations;

use JsonException;
use RuntimeException;

/**
 * The library's own words: the keys under `anotoki.` that its modules show on
 * every site (the UI kit - its top bar, language and theme switches, dialogs,
 * fields - and the status page), each with its description and its English
 * and Slovak (informal, "ty").
 *
 * One file holds them, php/resources/library-words.json -
 * {"keys": {name: {description, en, sk}}} - and everything else follows it:
 * this class (the placeholders LibraryKeyRules holds the keys to), the SQL of
 * the library's migrations (a test applies them to a new database and holds
 * the result to the file), and the Angular half's built-in words
 * (angular/projects/lib/ui/config/src/library-words.ts), which a script there
 * generates from the same file (`bun run words`). A new library string is an entry
 * here plus a new library migration; a rewording of a released one is a
 * migration that changes it only where it still has the earlier words.
 *
 * The file ships in the Composer package (php/resources). Without it a site
 * fails loudly at its first save of strings, never quietly.
 */
final class LibraryWords
{
    /** The namespace of every library key: no site's keys use it. */
    public const PREFIX = 'anotoki.';

    /** @var ?array<string, array<string, string>> */
    private static ?array $words = null;

    public static function file(): string
    {
        return dirname(__DIR__, 2) . '/resources/library-words.json';
    }

    /** Is it the library's key - one under `anotoki.`? */
    public static function isLibraryKey(string $key): bool
    {
        return str_starts_with($key, self::PREFIX);
    }

    /**
     * Every library key with its description and its words by language:
     * [name => ['description' => ..., 'en' => ..., 'sk' => ...]], in the file's order.
     *
     * @return array<string, array<string, string>>
     */
    public static function all(): array
    {
        return self::$words ??= self::read(self::file());
    }

    /** @return list<string> */
    public static function keys(): array
    {
        return array_keys(self::all());
    }

    /** A library key's English, or null for a key that is not one. */
    public static function english(string $key): ?string
    {
        return self::all()[$key]['en'] ?? null;
    }

    /**
     * A library key's placeholders - its English's - or null for a key that is not one.
     *
     * @return ?list<string>
     */
    public static function placeholders(string $key): ?array
    {
        $english = self::english($key);

        return $english === null ? null : Placeholders::names($english);
    }

    /**
     * The words of a file in the library's format, checked whole: a key of the
     * shape `anotoki.segment[.segment]` with a description and an English string,
     * and words in other languages under their codes.
     *
     * @return array<string, array<string, string>>
     */
    public static function read(string $file): array
    {
        $raw = is_file($file) ? @file_get_contents($file) : false;
        if (!is_string($raw)) {
            throw new RuntimeException("The anotoki library's words are missing: there is no $file. The Composer package ships php/resources.");
        }
        try {
            $document = json_decode($raw, true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new RuntimeException("$file is not JSON: " . $e->getMessage() . '.');
        }
        if (!is_array($document) || !is_array($document['keys'] ?? null) || $document['keys'] === [] || array_is_list($document['keys'])) {
            throw new RuntimeException("$file does not hold {\"keys\": {name: {description, en, sk}}}.");
        }

        $words = [];
        foreach ($document['keys'] as $name => $entry) {
            $name = (string) $name;
            if (preg_match('/^anotoki(?:\.[A-Za-z0-9][A-Za-z0-9_-]*)+$/D', $name) !== 1) {
                throw new RuntimeException("$file: \"$name\" is not a library key (anotoki.segment[.segment]).");
            }
            if (!is_array($entry) || !is_string($entry['description'] ?? null) || !is_string($entry['en'] ?? null) || trim($entry['en']) === '') {
                throw new RuntimeException("$file: \"$name\" needs a description and an English string.");
            }
            foreach ($entry as $field => $text) {
                if ($field !== 'description' && (!LanguageCode::ok($field) || !is_string($text) || trim($text) === '')) {
                    throw new RuntimeException("$file: \"$name\" has \"$field\", which is neither its description nor a language's words.");
                }
            }
            $words[$name] = array_map('strval', $entry);
        }

        return $words;
    }
}
