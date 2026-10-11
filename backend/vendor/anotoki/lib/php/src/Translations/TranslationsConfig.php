<?php

declare(strict_types=1);

namespace Anotoki\Lib\Translations;

use Closure;
use InvalidArgumentException;

/**
 * What differs between the sites' words: everything here has the family's
 * value by default, and a site gives only what is its own.
 */
final class TranslationsConfig
{
    /** Fields of a save's audit summary: no server namespace may take one of these names. */
    private const SUMMARY_FIELDS = ['languages', 'count', 'keys', 'more_keys'];

    /**
     * @param list<string>   $releasedLanguages  the languages the migrations write strings for: they cannot be
     *                                           deleted (the next migration with strings would fail without
     *                                           them). The library itself writes en and sk.
     * @param list<string>   $serverNamespaces   namespaces only the server reads (the IAM: ['mail']): never in
     *                                           a bundle; a save's audit summary keeps each changed string of
     *                                           theirs whole, old and new, under the namespace's name
     * @param list<KeyRules> $rules              the site's rules for some of its keys, asked in order after
     *                                           the library's own (KeyRules says when)
     * @param ?Closure       $extraCheck         fn(string $field, string $text, array $about): ?Refusal - a
     *                                           last check of every string ($field 'value', $about
     *                                           {key, language, language_name}) and of a language's names
     *                                           ($field 'name' or 'native_name', $about {code}) - the build
     *                                           analyzer's names_another_site
     * @param ?LanguageUsage $usage              what the site's tables make of its languages
     * @param ?Closure       $beforeWrite        fn(PDO $pdo, ?int $userId): void - inside a save's transaction,
     *                                           before anything is read or written (Japanese Academy holds the
     *                                           writer's people row)
     * @param int            $languagesMax       how many languages a site keeps at most
     * @param int            $languageNameMax    a language's name, in characters
     * @param int            $sortOrderMax       the highest place in the languages' order
     * @param int            $valueMax           a string, in characters
     * @param int            $bodyLimit          the largest body a write takes, in bytes
     * @param int            $auditKeys          how many keys a save's audit summary names
     * @param int            $auditTextMax       a server namespace's string in the audit summary, in characters
     *                                           (as long as the site lets one be: the IAM's mail strings, 400)
     */
    public function __construct(
        public readonly array $releasedLanguages = ['en', 'sk'],
        public readonly array $serverNamespaces = [],
        public readonly array $rules = [],
        public readonly ?Closure $extraCheck = null,
        public readonly ?LanguageUsage $usage = null,
        public readonly ?Closure $beforeWrite = null,
        public readonly int $languagesMax = 20,
        public readonly int $languageNameMax = 50,
        public readonly int $sortOrderMax = 1000,
        public readonly int $valueMax = 2000,
        public readonly int $bodyLimit = 1024 * 1024,
        public readonly int $auditKeys = 100,
        public readonly int $auditTextMax = 400,
    ) {
        if (!array_is_list($releasedLanguages) || !in_array(Translations::FALLBACK, $releasedLanguages, true)) {
            throw new InvalidArgumentException('releasedLanguages is a list of codes, English (en) among them.');
        }
        foreach ($releasedLanguages as $code) {
            if (!LanguageCode::ok($code)) {
                throw new InvalidArgumentException('releasedLanguages holds something that is not a language code.');
            }
        }

        if (!array_is_list($serverNamespaces)) {
            throw new InvalidArgumentException('serverNamespaces is a list of namespaces.');
        }
        foreach ($serverNamespaces as $namespace) {
            if (!is_string($namespace) || preg_match('/^[a-z][A-Za-z0-9]*$/D', $namespace) !== 1
                || $namespace === rtrim(LibraryWords::PREFIX, '.') || in_array($namespace, self::SUMMARY_FIELDS, true)) {
                throw new InvalidArgumentException('A server namespace is a namespace of the site\'s own keys, like "mail".');
            }
        }

        if (!array_is_list($rules)) {
            throw new InvalidArgumentException('rules is a list of KeyRules.');
        }
        foreach ($rules as $rule) {
            if (!$rule instanceof KeyRules) {
                throw new InvalidArgumentException('Every rule must be a KeyRules.');
            }
        }

        foreach ([
            'languagesMax' => [$languagesMax, 1, 1000],
            'languageNameMax' => [$languageNameMax, 1, 1000],
            'sortOrderMax' => [$sortOrderMax, 0, 2_147_483_647],
            'valueMax' => [$valueMax, 1, 65_535],
            'bodyLimit' => [$bodyLimit, 1024, 1024 * 1024 * 1024],
            'auditKeys' => [$auditKeys, 1, 100_000],
            'auditTextMax' => [$auditTextMax, 1, 65_535],
        ] as $name => [$value, $min, $max]) {
            if ($value < $min || $value > $max) {
                throw new InvalidArgumentException("$name must be from $min to $max.");
            }
        }
    }

    /**
     * Every rule StringCheck asks, in order: the library's own first.
     *
     * @return list<KeyRules>
     */
    public function allRules(): array
    {
        return [new Rules\LibraryKeyRules(), ...$this->rules];
    }

    /** Is the key in a namespace only the server reads? */
    public function isServerKey(string $key): bool
    {
        return in_array(explode('.', $key, 2)[0], $this->serverNamespaces, true);
    }
}
