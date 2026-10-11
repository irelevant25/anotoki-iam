<?php

declare(strict_types=1);

namespace Anotoki\Lib\People;

use Closure;
use InvalidArgumentException;
use PDO;

/**
 * A site's own table of the people who use it - every anotoki site has one,
 * `users`, and every foreign key, every visitor and two sites' per-person locks
 * live there - as the library reads and writes it: through this mapping,
 * never in a table of its own, never changing the table's shape.
 *
 *   new PeopleTable(name: 'preferred_username', firstSeen: 'created_at', lastSeen: 'last_seen_at')  // piano, japanese
 *   new PeopleTable(name: 'preferred_username', firstSeen: 'first_seen_at')                       // survey
 *   new PeopleTable(name: 'username', lastSeen: null, nameFallback: fn (int $sub): string => "user$sub")   // genshin
 *   new PeopleTable(name: 'name', lastSeen: null, updatedAt: 'updated_at',
 *                   nameFallback: fn (int $sub): string => "user $sub")                        // build analyzer
 *
 * The contract the table meets after the site's own adoption migration:
 *
 * - one row per person, its id the IAM's `sub` (id > 0). Rows with other ids
 *   - the visitors' negative ones - are never read or written by the library;
 * - a name column (NOT NULL allowed: a new row without a name in its token
 *   gets nameFallback, or "user {sub}" where the column must be filled);
 * - optionally a first-seen, a last-seen and an updated-at column (TIMESTAMPTZ,
 *   or a TIMESTAMP holding UTC);
 * - `anonymized_at TIMESTAMPTZ NULL`, the one column every site adds. The scrub
 *   looks for it in the catalog on every run and stays idle until it is there.
 *
 * No address: no anotoki site keeps a copy of anybody's email (the owner's
 * decision of 2026-10-07); a page that shows the person their own address
 * reads it from the ID token.
 */
final class PeopleTable
{
    /** @var array<int, ?array<string, array{type: string, notNull: bool, max: ?int}>> the catalog, by connection */
    private array $catalog = [];

    /**
     * @param ?Closure(int $sub): string $nameFallback  the name of a new row whose token carries none
     */
    public function __construct(
        public readonly string $table = 'users',
        public readonly string $name = 'preferred_username',
        public readonly ?string $firstSeen = 'created_at',
        public readonly ?string $lastSeen = 'last_seen_at',
        public readonly ?string $updatedAt = null,
        public readonly ?Closure $nameFallback = null,
        public readonly string $id = 'id',
        public readonly string $anonymizedAt = 'anonymized_at',
    ) {
        $columns = array_filter([$id, $name, $firstSeen, $lastSeen, $updatedAt, $anonymizedAt], static fn (?string $column): bool => $column !== null);
        foreach ([$table, ...$columns] as $identifier) {
            self::checkName($identifier);
        }
        if (count(array_unique($columns)) !== count($columns)) {
            throw new InvalidArgumentException('PeopleTable: each mapped column is a column of its own.');
        }
    }

    /** A table's or a column's name as the library takes one: lower case letters, digits and _, never anything from outside. */
    public static function checkName(string $identifier): void
    {
        if (preg_match('/^[a-z_][a-z0-9_]{0,62}$/D', $identifier) !== 1) {
            throw new InvalidArgumentException("\"$identifier\" is not a table's or a column's name the people module takes (lower case letters, digits and _).");
        }
    }

    /** A name quoted for SQL - only a name checkName() took. */
    public static function quote(string $identifier): string
    {
        self::checkName($identifier);

        return '"' . $identifier . '"';
    }

    /**
     * The table's columns as the catalog has them - type, NOT NULL, the most
     * characters a text column holds - or null when there is no such table.
     * Read once per connection and kept; forget() reads it again.
     *
     * @return ?array<string, array{type: string, notNull: bool, max: ?int}>
     */
    public function columns(PDO $pdo): ?array
    {
        $key = spl_object_id($pdo);
        if (array_key_exists($key, $this->catalog)) {
            return $this->catalog[$key];
        }

        $statement = $pdo->prepare(
            "SELECT a.attname, format_type(a.atttypid, NULL), a.attnotnull,
                    CASE WHEN a.atttypid IN ('varchar'::regtype, 'bpchar'::regtype) AND a.atttypmod > 4 THEN a.atttypmod - 4 END
               FROM pg_catalog.pg_class c
               JOIN pg_catalog.pg_namespace n ON n.oid = c.relnamespace
               JOIN pg_catalog.pg_attribute a ON a.attrelid = c.oid AND a.attnum > 0 AND NOT a.attisdropped
              WHERE n.nspname = current_schema() AND c.relname = ? AND c.relkind IN ('r', 'p')"
        );
        $statement->execute([$this->table]);
        $columns = [];
        foreach ($statement->fetchAll(PDO::FETCH_NUM) as [$column, $type, $notNull, $max]) {
            $columns[(string) $column] = [
                'type' => (string) $type,
                'notNull' => $notNull === true || $notNull === 't' || $notNull === 1 || $notNull === '1',
                'max' => $max === null ? null : (int) $max,
            ];
        }

        return $this->catalog[$key] = $columns === [] ? null : $columns;
    }

    /** Reads the catalog again at the next call: a migration may have changed the table meanwhile. */
    public function forget(): void
    {
        $this->catalog = [];
    }

    /**
     * What the library needs and the database lacks: the table, a mapped
     * column, `anonymized_at`, the library's own tables (anotoki_people) - in
     * words for the People page and the log. Nothing: ready.
     *
     * @return list<string>
     */
    public function problems(PDO $pdo): array
    {
        $columns = $this->columns($pdo);
        if ($columns === null) {
            return ["There is no table {$this->table}."];
        }

        $problems = [];
        foreach ([$this->id, $this->name, $this->firstSeen, $this->lastSeen, $this->updatedAt] as $column) {
            if ($column !== null && !isset($columns[$column])) {
                $problems[] = "{$this->table} has no column $column.";
            }
        }
        if (!isset($columns[$this->anonymizedAt])) {
            $problems[] = "{$this->table} has no column {$this->anonymizedAt} yet: the site's own migration adds it.";
        }
        if (!self::libraryReady($pdo)) {
            $problems[] = 'The library\'s tables anotoki_people_sync and anotoki_people_anonymized are not there yet: the migration set anotoki_people makes them.';
        }

        return $problems;
    }

    public function ready(PDO $pdo): bool
    {
        return $this->problems($pdo) === [];
    }

    /** Are the library's two tables there? A query that cannot fail - safe inside a transaction too. */
    public static function libraryReady(PDO $pdo): bool
    {
        $ready = $pdo->query("SELECT to_regclass('anotoki_people_sync') IS NOT NULL AND to_regclass('anotoki_people_anonymized') IS NOT NULL")->fetchColumn();

        return $ready === true || $ready === 't' || $ready === 1 || $ready === '1';
    }

    /**
     * The SQL for "now" in a time column of the table: a TIMESTAMPTZ takes
     * now(); a TIMESTAMP is written as UTC, whatever the session's time zone.
     */
    public function nowFor(PDO $pdo, string $column): string
    {
        $type = $this->columns($pdo)[$column]['type'] ?? 'timestamp with time zone';

        return $type === 'timestamp without time zone' ? "(now() AT TIME ZONE 'UTC')" : 'now()';
    }

    /**
     * The SQL reading a time column as Unix seconds: EXTRACT reads a TIMESTAMP
     * as UTC and a TIMESTAMPTZ as its instant - UTC either way.
     */
    public static function epochOf(string $expression): string
    {
        return "CASE WHEN $expression IS NOT NULL AND isfinite($expression) THEN floor(extract(epoch FROM $expression))::bigint END";
    }

    /** The name a new row gets when its token carries none: the site's fallback, else "user {sub}" where the column must be filled. */
    public function fallbackName(PDO $pdo, int $sub): ?string
    {
        if ($this->nameFallback !== null) {
            return ($this->nameFallback)($sub);
        }

        return ($this->columns($pdo)[$this->name]['notNull'] ?? false) ? 'user ' . $sub : null;
    }

    /** The most characters the name column holds, or null for no limit. */
    public function nameMax(PDO $pdo): ?int
    {
        return $this->columns($pdo)[$this->name]['max'] ?? null;
    }
}
