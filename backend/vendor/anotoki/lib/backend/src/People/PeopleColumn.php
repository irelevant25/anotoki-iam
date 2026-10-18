<?php

declare(strict_types=1);

namespace Anotoki\Lib\People;

use Closure;
use InvalidArgumentException;

/**
 * A column of the site's own on the People page: what each person has there -
 * Piano Academy's notes, steps and rounds; Japanese Academy's time zone,
 * learned items and exams; the build analyzer's game accounts, imports and
 * last import. Its values come for one page of people at a time.
 *
 *   new PeopleColumn('notes', 'Notes', 'count', fn (PDO $pdo, array $subs): array =>
 *       $pdo->query(...)->fetchAll(PDO::FETCH_KEY_PAIR))      // [sub => value]; a sub left out has none
 *
 * kind: count (a number), text, or time (Unix seconds or ISO 8601; answered as ISO 8601 UTC).
 */
final class PeopleColumn
{
    public const KINDS = ['count', 'text', 'time'];

    /** The fields every row has: no site column takes their names. */
    public const RESERVED = ['sub', 'name', 'first_seen_at', 'last_seen_at', 'anonymized_at', 'iam_url'];

    /**
     * @param Closure(\PDO $pdo, list<int> $subs): array<int|string, mixed> $values
     */
    public function __construct(public readonly string $key, public readonly string $label, public readonly string $kind, public readonly Closure $values)
    {
        if (preg_match('/^[a-z][a-z0-9_]{0,40}$/D', $key) !== 1 || in_array($key, self::RESERVED, true)) {
            throw new InvalidArgumentException("PeopleColumn: \"$key\" is no key of the site's own (snake_case, and not " . implode(', ', self::RESERVED) . ').');
        }
        if (!in_array($kind, self::KINDS, true)) {
            throw new InvalidArgumentException("PeopleColumn $key: the kind is count, text or time.");
        }
        if (trim($label) === '') {
            throw new InvalidArgumentException("PeopleColumn $key: give it a label.");
        }
    }

    /** @return array{key: string, label: string, kind: string} */
    public function describe(): array
    {
        return ['key' => $this->key, 'label' => $this->label, 'kind' => $this->kind];
    }

    /** A value as the answer carries it: a count as an integer, a time as ISO 8601 UTC, text as text; null for none. */
    public function answer(mixed $value): int|float|string|null
    {
        if ($value === null) {
            return null;
        }

        return match ($this->kind) {
            'count' => is_numeric($value) ? $value + 0 : null,
            'time' => match (true) {
                is_int($value) || (is_string($value) && ctype_digit($value)) => gmdate('Y-m-d\TH:i:s\Z', (int) $value),
                is_string($value) && strtotime($value) !== false => gmdate('Y-m-d\TH:i:s\Z', (int) strtotime($value)),
                default => null,
            },
            default => is_scalar($value) ? (string) $value : null,
        };
    }
}
