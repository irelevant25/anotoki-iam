<?php

declare(strict_types=1);

namespace Anotoki\Lib\Translations;

use Anotoki\Lib\Support\Refusal;

/**
 * A site's own rules for some of its keys, beyond the family's: which
 * placeholders a key has, and what its strings must or must not be (the IAM's
 * mails: one short line, no address, exactly the placeholders the code fills).
 * TranslationsConfig::$rules lists them; StringCheck asks them in that order,
 * after the library's own (LibraryKeyRules), and the first refusal is the answer.
 *
 * For every string about to be kept - clean, trimmed and not blank - the
 * checks run in this order, and the first thing wrong is refused:
 *
 *   1. checkFirst() of every rule - a rule's own limits of the text itself,
 *      said before the family's (a mail string's line breaks, its shorter
 *      length, its bytes);
 *   2. the family's: the length (TranslationsConfig::$valueMax), then a { or }
 *      that is no part of a placeholder (invalid_placeholder);
 *   3. check() of every rule - what the rule refuses beyond them (a placeholder
 *      a key must keep, an address in a mail);
 *   4. the family's placeholders: an English string must keep exactly the key's
 *      (placeholder_changed), another language's may leave one out but use none
 *      the key does not have (unknown_placeholder);
 *   5. the site's extraCheck, last.
 *
 * The key's placeholders - what steps 2 to 4 hold a string to - are those of
 * the first rule whose placeholders() gives a list; else the stored English
 * string's; else, for a key with none yet, the English sent with it.
 */
interface KeyRules
{
    /**
     * The placeholders the key has in every language, when this rule fixes them
     * (the code is the authority, not the stored English) - or null when it is
     * not this rule's to say.
     *
     * @return ?list<string>
     */
    public function placeholders(string $key): ?array;

    /**
     * Before the family's checks of the length and the braces.
     *
     * @param string       $code          the language's code
     * @param string       $text          the string as it would be kept: clean, trimmed, not blank
     * @param string       $languageName  the language's name in English, for the sentence ("The Slovak string of ...")
     * @param list<string> $placeholders  the key's placeholders
     */
    public function checkFirst(string $key, string $code, string $text, string $languageName, array $placeholders): ?Refusal;

    /**
     * After the family's checks of the length and the braces, before its check of the placeholders.
     *
     * @param list<string> $placeholders  the key's placeholders
     */
    public function check(string $key, string $code, string $text, string $languageName, array $placeholders): ?Refusal;
}
