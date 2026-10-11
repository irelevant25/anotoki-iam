<?php

declare(strict_types=1);

namespace Anotoki\Lib\Translations;

use JsonException;
use RuntimeException;
use stdClass;

/**
 * A language's strings as a file - what the admin pages' export downloads and
 * the command line writes and reads: one flat JSON object, a key a line, the
 * letters as they are, so it can be worked on in an editor and compared line
 * by line.
 */
final class TranslationFile
{
    /** The text of the file: pretty JSON (four spaces), unescaped, a line break at the end, {} for none. */
    public static function text(array|object $values): string
    {
        return json_encode((object) (array) $values, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
    }

    public static function write(string $path, array|object $values): void
    {
        if (@file_put_contents($path, self::text($values)) === false) {
            throw new RuntimeException("Cannot write $path.");
        }
    }

    /**
     * The strings in a file: one flat JSON object, {key: text}. A byte-order mark
     * in front (an editor on Windows may put one there) is passed over. What each
     * value is is checked where the strings are kept (StringCheck), not here - and
     * so is each key, as the admin pages' import has them checked: an object
     * whose keys are numbers ({"0": …}) is an object like any other, and a list's
     * items come numbered; either way the numbers are refused there as keys the
     * site does not have (unknown_key). An empty list, or anything else, is no
     * object of strings.
     *
     * @return array<array-key, mixed>
     */
    public static function read(string $path): array
    {
        $raw = is_file($path) ? @file_get_contents($path) : false;
        if ($raw === false) {
            throw new RuntimeException("There is no file $path.");
        }
        if (str_starts_with($raw, "\xEF\xBB\xBF")) {
            $raw = substr($raw, 3);
        }
        try {
            $values = json_decode($raw, false, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new RuntimeException("$path is not JSON: " . $e->getMessage() . '.');
        }
        if ($values instanceof stdClass) {
            return get_object_vars($values);
        }
        if (is_array($values) && $values !== []) {
            return $values;
        }

        throw new RuntimeException("$path does not hold one flat object, {key: text}.");
    }
}
