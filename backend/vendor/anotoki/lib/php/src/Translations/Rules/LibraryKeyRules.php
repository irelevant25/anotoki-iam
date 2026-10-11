<?php

declare(strict_types=1);

namespace Anotoki\Lib\Translations\Rules;

use Anotoki\Lib\Support\Refusal;
use Anotoki\Lib\Translations\KeyRules;
use Anotoki\Lib\Translations\LibraryWords;

/**
 * The library's keys (`anotoki.*`) have the placeholders of the library's own
 * English (LibraryWords), whatever a site's stored English says: the library's
 * code fills them, on every site alike. Always the first rule StringCheck asks.
 */
final class LibraryKeyRules implements KeyRules
{
    public function placeholders(string $key): ?array
    {
        return LibraryWords::isLibraryKey($key) ? (LibraryWords::placeholders($key) ?? []) : null;
    }

    public function checkFirst(string $key, string $code, string $text, string $languageName, array $placeholders): ?Refusal
    {
        return null;
    }

    public function check(string $key, string $code, string $text, string $languageName, array $placeholders): ?Refusal
    {
        return null;
    }
}
