<?php

declare(strict_types=1);

namespace Anotoki\Lib\Translations\Rules;

use Anotoki\Lib\Support\Refusal;
use Anotoki\Lib\Translations\KeyRules;
use Anotoki\Lib\Translations\Placeholders;
use InvalidArgumentException;

/**
 * Keys whose strings must keep a placeholder in every language - a page that
 * cuts a sentence around {app} to set the application's name apart (the IAM's
 * `login.continueTo` and `register.forApp`): a translation without it would
 * lose the name. Other languages may otherwise leave a placeholder out.
 */
final class RequiredPlaceholders implements KeyRules
{
    /** @param array<string, list<string>> $required  key => the placeholders every string of it keeps */
    public function __construct(private readonly array $required)
    {
        foreach ($required as $key => $names) {
            if (!is_array($names)) {
                throw new InvalidArgumentException("The placeholders \"$key\" must keep are a list of names.");
            }
            foreach ($names as $name) {
                if (!is_string($name) || preg_match('/^[A-Za-z][A-Za-z0-9_]*$/D', $name) !== 1) {
                    throw new InvalidArgumentException("\"$key\" must keep a placeholder that is no placeholder's name.");
                }
            }
        }
    }

    public function placeholders(string $key): ?array
    {
        return null;
    }

    public function checkFirst(string $key, string $code, string $text, string $languageName, array $placeholders): ?Refusal
    {
        return null;
    }

    public function check(string $key, string $code, string $text, string $languageName, array $placeholders): ?Refusal
    {
        $missing = array_values(array_diff($this->required[$key] ?? [], Placeholders::names($text)));
        if ($missing === []) {
            return null;
        }

        return Refusal::invalid(
            'placeholder_missing',
            "The $languageName string of \"$key\" must keep {{$missing[0]}}: the page sets what stands there apart from the rest of the sentence",
            ['key' => $key, 'language' => $code, 'placeholder' => $missing[0]]
        );
    }
}
