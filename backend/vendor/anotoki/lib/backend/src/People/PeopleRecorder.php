<?php

declare(strict_types=1);

namespace Anotoki\Lib\People;

use Anotoki\Lib\Auth\Person;
use InvalidArgumentException;
use LogicException;
use PDO;

/**
 * Keeps a site's row of a person from their verified token: made on their
 * first request, the name kept to the token's preferred_username, last-seen at
 * most every five minutes - Japanese Academy's users_seen(), for every site.
 *
 * - The name is the token's preferred_username, trimmed and clipped to the
 *   column's width. A token without one keeps the stored name (a NULL never
 *   overwrites it); a new row without one gets the table's fallback.
 * - It writes nothing when nothing changed and the last-seen is less than
 *   five minutes old: most requests cost one statement that changes nothing.
 * - It never writes a row the scrub anonymized, and never makes a row for an
 *   id the IAM listed as deleted - neither can happen anyway (the IAM issues
 *   no token for thirty days before it deletes an account), but the guard
 *   costs nothing. Before the site's migration adds anonymized_at, and before
 *   the library's tables are there, it records as the sites always did.
 * - $extra are the site's own columns (Japanese Academy's time_zone): written
 *   with the row, and a NULL keeps what is stored.
 *
 * A site that needs the row inside its own transaction or lock (Piano
 * Academy's, Japanese Academy's) calls seen() there, on that connection;
 * Http\RecordPerson calls it for the route groups a site names - never on the
 * Migrations routes, which must work while `users` waits for an update.
 */
final class PeopleRecorder
{
    /** How old a last-seen may grow before a request writes it again, in seconds. */
    public const EVERY = 300;

    public function __construct(private readonly PeopleTable $table) {}

    /**
     * @param array<string, scalar|null> $extra  the site's own columns and their values
     * @return bool whether the row was written (made or changed)
     */
    public function seen(PDO $pdo, Person $person, array $extra = []): bool
    {
        $t = $this->table;
        $columns = $t->columns($pdo) ?? throw new LogicException("There is no table {$t->table}: the people module records nobody without it.");
        $mapped = [$t->id, $t->name, $t->firstSeen, $t->lastSeen, $t->updatedAt, $t->anonymizedAt];
        foreach ($extra as $column => $value) {
            PeopleTable::checkName((string) $column);
            if (in_array($column, $mapped, true) || !isset($columns[$column])) {
                throw new InvalidArgumentException("PeopleRecorder: $column is no column of the site's own in {$t->table}.");
            }
            if ($value !== null && !is_scalar($value)) {
                throw new InvalidArgumentException("PeopleRecorder: the value of $column is no plain value.");
            }
        }

        $table = PeopleTable::quote($t->table);
        $id = PeopleTable::quote($t->id);
        $name = PeopleTable::quote($t->name);
        $row = static fn(string $column): string => $table . '.' . PeopleTable::quote($column);
        $claimed = self::clip($person->name, $t->nameMax($pdo));

        // Each value cast to its column's type: a parameter in a SELECT list would otherwise be text.
        $cast = static fn(string $column): string => 'CAST(? AS ' . $columns[$column]['type'] . ')';
        $insertColumns = [$id, $name];
        $insertValues = ['CAST(? AS bigint)', $cast($t->name)];
        $insertParameters = [$person->sub, $claimed ?? $t->fallbackName($pdo, $person->sub)];
        foreach ([$t->firstSeen, $t->lastSeen, $t->updatedAt] as $stamp) {
            if ($stamp !== null && isset($columns[$stamp])) {
                $insertColumns[] = PeopleTable::quote($stamp);
                $insertValues[] = $t->nowFor($pdo, $stamp);
            }
        }
        foreach ($extra as $column => $value) {
            $insertColumns[] = PeopleTable::quote($column);
            $insertValues[] = $cast($column);
            $insertParameters[] = is_bool($value) ? ($value ? 'true' : 'false') : $value;
        }

        // What changes on a later request: the name (a NULL keeps it), the site's columns (the same), last-seen.
        $set = ["$name = COALESCE(?, {$row($t->name)})"];
        $setParameters = [$claimed];
        $differs = ["{$row($t->name)} IS DISTINCT FROM COALESCE(CAST(? AS text), {$row($t->name)}::text)"];
        $differsParameters = [$claimed];
        foreach ($extra as $column => $value) {
            $quoted = PeopleTable::quote($column);
            $set[] = "$quoted = COALESCE(EXCLUDED.$quoted, {$row($column)})";
            $differs[] = "{$row($column)} IS DISTINCT FROM COALESCE(EXCLUDED.$quoted, {$row($column)})";
        }
        $changed = '(' . implode(' OR ', $differs) . ')';
        if ($t->updatedAt !== null && isset($columns[$t->updatedAt])) {
            // Stamped when the row's content changed, not when only its last-seen moved on.
            $set[] = PeopleTable::quote($t->updatedAt) . " = CASE WHEN $changed THEN {$t->nowFor($pdo, $t->updatedAt)} ELSE {$row($t->updatedAt)} END";
            $setParameters = [...$setParameters, ...$differsParameters];
        }
        $when = $differs;
        $whenParameters = $differsParameters;
        if ($t->lastSeen !== null && isset($columns[$t->lastSeen])) {
            $now = $t->nowFor($pdo, $t->lastSeen);
            $set[] = PeopleTable::quote($t->lastSeen) . " = $now";
            $when[] = "{$row($t->lastSeen)} IS NULL OR {$row($t->lastSeen)} < $now - make_interval(secs => " . self::EVERY . ')';
        }

        $listed = PeopleTable::libraryReady($pdo);
        $guarded = isset($columns[$t->anonymizedAt]);
        $sql =
            'INSERT INTO ' .
            $table .
            ' (' .
            implode(', ', $insertColumns) .
            ')' .
            ' SELECT ' .
            implode(', ', $insertValues) .
            ($listed ? ' WHERE NOT EXISTS (SELECT 1 FROM anotoki_people_anonymized WHERE sub = CAST(? AS bigint))' : '') .
            " ON CONFLICT ($id) DO UPDATE SET " .
            implode(', ', $set) .
            ' WHERE ' .
            ($guarded ? $row($t->anonymizedAt) . ' IS NULL AND ' : '') .
            '(' .
            implode(' OR ', $when) .
            ')';

        $statement = $pdo->prepare($sql);
        $statement->execute([...$insertParameters, ...$listed ? [$person->sub] : [], ...$setParameters, ...$whenParameters]);

        return $statement->rowCount() > 0;
    }

    /** A name as the column holds it: trimmed, clipped to its width in characters; null for none. */
    public static function clip(?string $name, ?int $max): ?string
    {
        $name = $name === null ? '' : trim($name);
        if ($name === '') {
            return null;
        }

        return $max !== null && mb_strlen($name, 'UTF-8') > $max ? rtrim(mb_substr($name, 0, $max, 'UTF-8')) : $name;
    }
}
