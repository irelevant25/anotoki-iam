<?php

declare(strict_types=1);

namespace Anotoki\Lib\Backups;

use PDO;
use RuntimeException;

/**
 * What a backup needs to know of a database's schema, from the catalog: its
 * tables in an order a restore can load them in (every table after the tables
 * it refers to), their columns, its sequences, and a fingerprint of every
 * column - the restore's proof that the rows will fit.
 *
 * @internal
 */
final class DatabaseShape
{
    /**
     * @param list<array{name: string, columns: list<string>}> $tables  parent-first
     * @param list<array{name: string, owner: ?string}> $sequences  owner: the table that owns it (SERIAL), if any
     * @param list<array{from: string, to: string}> $references  the foreign keys a load must follow (between two tables, not deferrable)
     * @param list<array{from: string, to: string}> $keys  every foreign key between two tables
     */
    private function __construct(
        public readonly string $schema,
        public readonly array $tables,
        public readonly array $sequences,
        public readonly array $references,
        public readonly array $keys,
        public readonly string $columnsSha256,
    ) {}

    /**
     * Reads the shape of $schema. Refuses a cycle of foreign keys that cannot
     * be deferred - no order loads it - naming its tables.
     *
     * @throws RuntimeException
     */
    public static function read(PDO $pdo, string $schema): self
    {
        $namespace = $pdo->prepare('SELECT oid FROM pg_namespace WHERE nspname = ?');
        $namespace->execute([$schema]);
        $schemaOid = $namespace->fetchColumn();
        if ($schemaOid === false) {
            throw new RuntimeException("The database has no schema \"$schema\".");
        }

        $generated = (int) $pdo->query("SELECT current_setting('server_version_num')")->fetchColumn() >= 120000 ? 'a.attgenerated <> \'\'' : 'FALSE';
        $statement = $pdo->prepare(
            "SELECT c.relname, a.attname, format_type(a.atttypid, a.atttypmod), a.attnotnull, $generated
               FROM pg_class c
               JOIN pg_attribute a ON a.attrelid = c.oid AND a.attnum > 0 AND NOT a.attisdropped
              WHERE c.relnamespace = CAST(? AS oid) AND c.relkind IN ('r', 'p') AND NOT c.relispartition
              ORDER BY c.relname, a.attnum",
        );
        $statement->execute([$schemaOid]);
        $columns = [];
        $fingerprint = [];
        foreach ($statement->fetchAll(PDO::FETCH_NUM) as [$table, $column, $type, $notNull, $isGenerated]) {
            $columns[(string) $table] ??= [];
            $isGenerated = $isGenerated === true || $isGenerated === 't';
            if (!$isGenerated) {
                // A generated column is computed again as a row arrives: never copied.
                $columns[(string) $table][] = (string) $column;
            }
            $fingerprint[] = $table . "\t" . $column . "\t" . $type . "\t" . ($notNull === true || $notNull === 't' ? 'not null' : 'null') . ($isGenerated ? "\tgenerated" : '');
        }
        // A table without a column is a table still.
        $empty = $pdo->prepare("SELECT relname FROM pg_class WHERE relnamespace = CAST(? AS oid) AND relkind IN ('r', 'p') AND NOT relispartition");
        $empty->execute([$schemaOid]);
        foreach ($empty->fetchAll(PDO::FETCH_COLUMN) as $table) {
            $columns[(string) $table] ??= [];
        }

        $keys = $pdo->prepare(
            "SELECT c.relname, p.relname, k.condeferrable
               FROM pg_constraint k
               JOIN pg_class c ON c.oid = k.conrelid
               JOIN pg_class p ON p.oid = k.confrelid
              WHERE k.contype = 'f' AND c.relnamespace = CAST(? AS oid) AND p.relnamespace = CAST(? AS oid)",
        );
        $keys->execute([$schemaOid, $schemaOid]);
        $references = [];
        $allKeys = [];
        foreach ($keys->fetchAll(PDO::FETCH_NUM) as [$from, $to, $deferrable]) {
            if ($from !== $to) {
                $allKeys[] = ['from' => (string) $from, 'to' => (string) $to];
            }
            // A table's key into itself loads whole: a COPY checks its rows' keys when it ends. A deferrable key is
            // checked at the commit (the restore defers them all).
            if ($from === $to || $deferrable === true || $deferrable === 't') {
                continue;
            }
            $references[] = ['from' => (string) $from, 'to' => (string) $to];
        }

        $sequences = $pdo->prepare(
            "SELECT s.relname, t.relname
               FROM pg_class s
               LEFT JOIN pg_depend d ON d.objid = s.oid AND d.classid = 'pg_class'::regclass AND d.refclassid = 'pg_class'::regclass AND d.deptype IN ('a', 'i')
               LEFT JOIN pg_class t ON t.oid = d.refobjid
              WHERE s.relkind = 'S' AND s.relnamespace = CAST(? AS oid)
              ORDER BY s.relname",
        );
        $sequences->execute([$schemaOid]);
        $sequenceList = [];
        foreach ($sequences->fetchAll(PDO::FETCH_NUM) as [$name, $owner]) {
            $sequenceList[] = ['name' => (string) $name, 'owner' => $owner === null ? null : (string) $owner];
        }

        $order = self::parentsFirst(array_keys($columns), $references);
        $tables = array_map(static fn(string $table): array => ['name' => $table, 'columns' => $columns[$table]], $order);

        return new self($schema, $tables, $sequenceList, $references, $allKeys, hash('sha256', implode("\n", $fingerprint)));
    }

    /** The table's name, schema-qualified and quoted. */
    public function qualified(string $table): string
    {
        return Copy::quote($this->schema) . '.' . Copy::quote($table);
    }

    /**
     * Whether every table and sequence belongs to the connection's role (or one it is a member of): what
     * TRUNCATE, ALTER TABLE ... DISABLE TRIGGER and setval() need.
     */
    public static function ownsEverything(PDO $pdo, string $schema): bool
    {
        $statement = $pdo->prepare(
            "SELECT count(*) FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace
              WHERE n.nspname = ? AND c.relkind IN ('r', 'p', 'S') AND NOT pg_has_role(c.relowner, 'USAGE')",
        );
        $statement->execute([$schema]);

        return (int) $statement->fetchColumn() === 0;
    }

    /**
     * Topological order, parents first; among equals by name, so the same database gives the same order.
     *
     * @param list<string> $tables
     * @param list<array{from: string, to: string}> $references
     * @return list<string>
     */
    private static function parentsFirst(array $tables, array $references): array
    {
        sort($tables, SORT_STRING);
        $waitsFor = array_fill_keys($tables, []);
        foreach ($references as $reference) {
            if (isset($waitsFor[$reference['from']], $waitsFor[$reference['to']])) {
                $waitsFor[$reference['from']][$reference['to']] = true;
            }
        }

        $order = [];
        $placed = [];
        while (count($order) < count($tables)) {
            $progress = false;
            foreach ($tables as $table) {
                if (isset($placed[$table])) {
                    continue;
                }
                if (array_diff_key($waitsFor[$table], $placed) === []) {
                    $order[] = $table;
                    $placed[$table] = true;
                    $progress = true;
                }
            }
            if (!$progress) {
                $left = array_values(array_filter($tables, static fn(string $table): bool => !isset($placed[$table])));
                throw new RuntimeException(
                    'These tables refer to each other in a circle with keys that cannot be deferred, so no order loads them: ' . implode(', ', $left) . '. Make one of the keys DEFERRABLE.',
                );
            }
        }

        return $order;
    }
}
