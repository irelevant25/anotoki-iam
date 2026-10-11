<?php

declare(strict_types=1);

namespace Anotoki\Lib\Translations;

use Anotoki\Lib\Support\Refusal;

/**
 * The rules every site's strings keep - one set for a save of the grid, an
 * import and the command line. It reads nothing itself, so it is tested
 * without a database.
 *
 * A key is the code's: migrations write the keys and their first strings, a
 * save only ever changes a string. English is what every other language falls
 * back to, key by key, so it cannot be left without a string. A string is text
 * and is drawn as text - never HTML. What stands in it for a value is a
 * {placeholder}: the code fills in what is written exactly so, and leaves
 * anything else as it stands.
 */
final class StringCheck
{
    /**
     * Checks strings somebody wants kept and says what to write. Everything is
     * checked before anything is written; the first thing wrong is refused (422),
     * with the key and the language it is about (and the placeholder, where it is
     * about one):
     *
     *   invalid_value         not {key: {language: text}}; a value that is not text, or too long
     *                         (or what a site's rule refuses as one)
     *   unknown_key           a key the site does not have (with `keys`: every such key sent)
     *   unknown_language      a language the site does not have
     *   fallback_required     an empty English string - every other language falls back to it
     *   invalid_placeholder   a { or } that is not part of a {placeholder}: "{ count }", "{počet}", "{count", "{{count}}"
     *   placeholder_changed   an English string whose placeholders are not exactly the key's
     *   unknown_placeholder   another language's string with a placeholder the key does not have
     *   (a site's own)        what a KeyRules or the extraCheck refuses - placeholder_missing,
     *                         address_in_mail, names_another_site...
     *
     * An empty string in another language means "no string of its own": the row
     * goes, and the language reads that key in English. Empty is what has nothing
     * in it to see (Text::blank()): spaces, or only characters of no width, which
     * look like a cleared field and are one. A translation may leave a placeholder
     * out, never add one. The order of the checks of one string is KeyRules's.
     *
     * What is to be written comes back by key and then by language, whatever
     * order it was sent in: every save takes its rows in that one order, so two
     * saves of the same strings wait for each other instead of each holding a row
     * the other needs.
     *
     * @param mixed                 $values     {key: {language code: text}}
     * @param list<string>          $keys       every key there is
     * @param array<string, string> $languages  every language there is: code => its name in English
     * @param array<string, string> $english    the stored English string of every key that has one
     *
     * @return list<array{key: string, language: string, value: ?string}>|Refusal  value null: delete that string
     */
    public static function changes(mixed $values, array $keys, array $languages, array $english, TranslationsConfig $config): array|Refusal
    {
        if (!self::isMap($values)) {
            return Refusal::invalid('invalid_value', 'Send the strings by key and language: {key: {language: text}}');
        }

        $known = array_fill_keys($keys, true);
        $rules = $config->allRules();
        $changes = [];

        foreach ($values as $key => $cells) {
            $key = (string) $key; // PHP made a number of a key like "12"
            if (!self::isMap($cells)) {
                return Refusal::invalid('invalid_value', 'The strings of ' . self::quote($key) . ' are not given by language: {language: text}', ['key' => Text::clip($key, 200)]);
            }
            if (!isset($known[$key])) {
                $unknown = array_values(array_filter(array_map('strval', array_keys($values)), static fn (string $name): bool => !isset($known[$name])));

                return Refusal::invalid(
                    'unknown_key',
                    count($unknown) === 1
                        ? 'The site has no key ' . self::quote($key) . '. Keys are made by the code, never here.'
                        : 'The site has no such keys: ' . implode(', ', array_map(self::quote(...), array_slice($unknown, 0, 20)))
                            . (count($unknown) > 20 ? ' and ' . (count($unknown) - 20) . ' more' : '') . '. Keys are made by the code, never here.',
                    ['key' => Text::clip($key, 200), 'keys' => array_map(static fn (string $name): string => Text::clip($name, 200), $unknown)]
                );
            }

            $placeholders = self::placeholdersOf($key, $rules, $english[$key] ?? Text::clean($cells[Translations::FALLBACK] ?? null) ?? '');

            foreach ($cells as $code => $value) {
                $code = (string) $code;
                $about = ['key' => $key, 'language' => Text::clip($code, 20)];
                if (!isset($languages[$code])) {
                    return Refusal::invalid('unknown_language', 'The site has no language ' . self::quote($code), $about);
                }
                $name = $languages[$code];

                $text = Text::clean($value);
                if ($text === null) {
                    return Refusal::invalid('invalid_value', "The $name string of \"$key\" is not text", $about);
                }
                $text = Text::trim($text);
                if (Text::blank($text)) {
                    if ($code === Translations::FALLBACK) {
                        return Refusal::invalid('fallback_required', "\"$key\" needs its English string: every other language falls back to it", $about);
                    }
                    $changes[] = ['key' => $key, 'language' => $code, 'value' => null];
                    continue;
                }

                $refusal = self::checkString($key, $code, $text, $name, $placeholders, $rules, $config);
                if ($refusal !== null) {
                    return $refusal;
                }

                $changes[] = ['key' => $key, 'language' => $code, 'value' => $text];
            }
        }

        // One order for every save (strcmp: <=> would compare "10" and "9" as numbers).
        usort($changes, static fn (array $a, array $b): int => strcmp($a['key'], $b['key']) ?: strcmp($a['language'], $b['language']));

        return $changes;
    }

    /**
     * One of a language's two names: one line of 1 to languageNameMax characters,
     * with something in it to see - or the refusal (422 invalid_language, or the
     * site's extraCheck's).
     *
     * @param string $what   how the sentence names it: "The name in English"
     * @param string $field  the body's field it came in: name, native_name
     */
    public static function languageName(mixed $value, string $what, string $field, TranslationsConfig $config, ?string $code = null): string|Refusal
    {
        $text = Text::clean($value);
        if ($text === null) {
            return Refusal::invalid('invalid_language', "$what is not text");
        }

        $text = Text::trim($text);
        if (Text::blank($text) || Text::length($text) > $config->languageNameMax || preg_match('/[\r\n\t]/', $text) === 1) {
            return Refusal::invalid('invalid_language', "$what must be one line of 1 to {$config->languageNameMax} characters");
        }

        if ($config->extraCheck !== null) {
            $refusal = ($config->extraCheck)($field, $text, ['code' => $code]);
            if ($refusal instanceof Refusal) {
                return $refusal;
            }
        }

        return $text;
    }

    /** Is it a map - {} or {"a": ...} - rather than a list, a text, a number or nothing? */
    public static function isMap(mixed $value): bool
    {
        return is_array($value) && ($value === [] || !array_is_list($value));
    }

    /**
     * The key's placeholders: those of the first rule that fixes them; else its stored English string's;
     * else, for a key with no English yet, those of the English sent with it.
     *
     * @param list<KeyRules> $rules
     * @return list<string>
     */
    private static function placeholdersOf(string $key, array $rules, string $english): array
    {
        foreach ($rules as $rule) {
            $fixed = $rule->placeholders($key);
            if ($fixed !== null) {
                return array_values($fixed);
            }
        }

        return Placeholders::names($english);
    }

    /**
     * Everything about one string that is not blank, in the order KeyRules describes.
     *
     * @param list<string>   $placeholders
     * @param list<KeyRules> $rules
     */
    private static function checkString(string $key, string $code, string $text, string $name, array $placeholders, array $rules, TranslationsConfig $config): ?Refusal
    {
        $about = ['key' => $key, 'language' => $code];

        foreach ($rules as $rule) {
            $refusal = $rule->checkFirst($key, $code, $text, $name, $placeholders);
            if ($refusal !== null) {
                return $refusal;
            }
        }

        if (Text::length($text) > $config->valueMax) {
            return Refusal::invalid('invalid_value', "The $name string of \"$key\" is longer than {$config->valueMax} characters", $about);
        }
        if (Placeholders::strayBrace($text)) {
            // It names one of the key's own placeholders: told "like {count}" on a key whose placeholder is
            // {app}, a translator would add one the key does not have.
            return Refusal::invalid(
                'invalid_placeholder',
                "The $name string of \"$key\" has a { or } that is not part of a placeholder. A placeholder is written exactly as the English string has it, like {"
                    . ($placeholders[0] ?? 'count') . '}: no spaces, and its name is not translated.',
                $about
            );
        }

        foreach ($rules as $rule) {
            $refusal = $rule->check($key, $code, $text, $name, $placeholders);
            if ($refusal !== null) {
                return $refusal;
            }
        }

        $used = Placeholders::names($text);
        if ($code === Translations::FALLBACK) {
            $changed = array_values(array_merge(array_diff($placeholders, $used), array_diff($used, $placeholders)));
            if ($changed !== []) {
                $placeholder = $changed[0];

                return Refusal::invalid(
                    'placeholder_changed',
                    in_array($placeholder, $placeholders, true)
                        ? "The English string of \"$key\" must keep {{$placeholder}}: the page puts a value there"
                        : "The English string of \"$key\" cannot use {{$placeholder}}: the page has no value for it",
                    $about + ['placeholder' => $placeholder]
                );
            }
        } else {
            $unknown = array_values(array_diff($used, $placeholders));
            if ($unknown !== []) {
                return Refusal::invalid(
                    'unknown_placeholder',
                    "The $name string of \"$key\" uses {{$unknown[0]}}, which its English string does not have",
                    $about + ['placeholder' => $unknown[0]]
                );
            }
        }

        if ($config->extraCheck !== null) {
            $refusal = ($config->extraCheck)('value', $text, $about + ['language_name' => $name]);
            if ($refusal instanceof Refusal) {
                return $refusal;
            }
        }

        return null;
    }

    /** What is quoted back in a refusal is never longer than a key can be. */
    private static function quote(string $text): string
    {
        return '"' . Text::clip($text, 200) . '"';
    }
}
