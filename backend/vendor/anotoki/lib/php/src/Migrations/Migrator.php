<?php

declare(strict_types=1);

namespace Anotoki\Lib\Migrations;

use Closure;
use InvalidArgumentException;
use LogicException;
use PDO;
use PDOException;
use RuntimeException;
use Throwable;

/**
 * The migration engine of every anotoki site: the database is what applying
 * the files of its sets in order gives, and a bookkeeping table records each
 * file once it ran.
 *
 * It reads and writes every shape that table has on the sites' databases,
 * never changing one: the file column is `filename` (or `name`, as on Japanese
 * Academy), the set is recorded in `folder` where the table has that column
 * (the genshin site's two sets), `applied_at` is a TIMESTAMP holding UTC or a
 * TIMESTAMPTZ. A site whose table has an older shape still (a column to
 * rename, a Laravel table to move aside) adopts it in the `prepare` hook,
 * which runs before anything is written.
 *
 * Each file runs in a transaction of its own with its record, so it is applied
 * whole or not at all; the run stops at the first file that fails, because
 * every migration is written expecting the ones before it to have run. One
 * apply at a time per database, whoever starts it (the admin panel, the
 * command line, an installer): each takes the same advisory lock first, and a
 * second one is told it is busy and does nothing. Nothing is ever un-applied.
 *
 * The connection is used as it is given: this never changes its attributes,
 * and it needs PDO::ERRMODE_EXCEPTION (without it a failed statement would
 * pass unnoticed and its file be recorded as applied).
 */
final class Migrator
{
    /** The advisory lock every apply takes, unless the site names its own (`lock`). */
    public const DEFAULT_LOCK = 4_271_029_017;

    private const OPTIONS = ['table', 'lock', 'split', 'ready', 'prepare', 'beforeFile', 'beforeApply'];

    /** @var list<MigrationSet> */
    private readonly array $sets;
    private readonly string $table;
    private readonly int $lock;
    private readonly bool $split;
    private readonly ?Closure $ready;
    private readonly ?Closure $prepare;
    private readonly ?Closure $beforeFile;
    private readonly ?Closure $beforeApply;

    /**
     * @param list<MigrationSet> $sets  in the order they run (at least one; names unique)
     * @param array{
     *   table?: string,
     *   lock?: int,
     *   split?: bool,
     *   ready?: callable(MigrationSet, string, string): bool,
     *   prepare?: callable(PDO): void,
     *   beforeFile?: callable(PDO, MigrationSet, string): void,
     *   beforeApply?: callable(PDO, list<array{set: string, name: string}>): ?string,
     * } $options
     *   - table: the bookkeeping table, default 'migrations'
     *   - lock: the advisory lock's key, default DEFAULT_LOCK
     *   - split: true (default) runs a file statement by statement, so a failure names its statement;
     *     false sends the whole file in one exec
     *   - ready(set, name, sql): false makes the file a draft - it and every file after it wait
     *   - prepare(pdo): write paths only, before the table is created or used (adopting an old shape)
     *   - beforeFile(pdo, set, name): inside the file's transaction, before its SQL
     *   - beforeApply(pdo, files): once, before anything runs; the string it returns is the result's note
     */
    public function __construct(private readonly PDO $pdo, array $sets, array $options = [])
    {
        if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'pgsql') {
            throw new InvalidArgumentException('The Migrator works on PostgreSQL: give it a pgsql: connection.');
        }
        if ($pdo->getAttribute(PDO::ATTR_ERRMODE) !== PDO::ERRMODE_EXCEPTION) {
            throw new InvalidArgumentException(
                'The Migrator needs PDO::ERRMODE_EXCEPTION on its connection: without it a failed statement would pass unnoticed.'
            );
        }

        if ($sets === [] || !array_is_list($sets)) {
            throw new InvalidArgumentException('The Migrator needs a list of at least one MigrationSet.');
        }
        $names = [];
        foreach ($sets as $set) {
            if (!$set instanceof MigrationSet) {
                throw new InvalidArgumentException('Every set must be a MigrationSet.');
            }
            if ($set->name === '') {
                throw new InvalidArgumentException('A migration set needs a name.');
            }
            if (isset($names[$set->name])) {
                throw new InvalidArgumentException("Two migration sets are named \"{$set->name}\": the names must be unique.");
            }
            $names[$set->name] = true;
        }
        $this->sets = $sets;

        $unknown = array_diff(array_map('strval', array_keys($options)), self::OPTIONS);
        if ($unknown !== []) {
            throw new InvalidArgumentException('Unknown Migrator option: ' . implode(', ', $unknown) . '.');
        }

        $table = $options['table'] ?? 'migrations';
        if (!is_string($table) || preg_match('/^[a-z_][a-z0-9_]{0,62}$/D', $table) !== 1) {
            throw new InvalidArgumentException('The option table must be a table name in lower case letters, digits and _.');
        }
        $this->table = $table;

        $lock = $options['lock'] ?? self::DEFAULT_LOCK;
        if (!is_int($lock)) {
            throw new InvalidArgumentException('The option lock must be an integer.');
        }
        $this->lock = $lock;

        $split = $options['split'] ?? true;
        if (!is_bool($split)) {
            throw new InvalidArgumentException('The option split must be true or false.');
        }
        $this->split = $split;

        $this->ready = self::hook($options, 'ready');
        $this->prepare = self::hook($options, 'prepare');
        $this->beforeFile = self::hook($options, 'beforeFile');
        $this->beforeApply = self::hook($options, 'beforeApply');
    }

    /**
     * The set's files: basenames of the *.sql files directly in its folder,
     * in natural order (so 100 comes after 99); none when there is no folder.
     *
     * @return list<string>
     */
    public function files(MigrationSet $set): array
    {
        $directory = $set->directory;
        if (!is_dir($directory)) {
            return [];
        }

        $entries = @scandir($directory);
        if ($entries === false) {
            return [];
        }

        $files = [];
        foreach ($entries as $entry) {
            // Hidden files are left out, as a shell's *.sql leaves them out.
            if ($entry[0] === '.' || !str_ends_with($entry, '.sql') || !is_file($directory . '/' . $entry)) {
                continue;
            }
            $files[] = $entry;
        }
        natsort($files);

        return array_values($files);
    }

    /**
     * What the database has and lacks. Reads only (it never creates anything,
     * nor runs `prepare`): a missing table - or one in a shape this cannot
     * read until `prepare` adopts it - means nothing is applied.
     *
     * @return array{
     *   sets: list<string>,
     *   applied: list<array{set: string, name: string, applied_at: ?string}>,
     *   pending: list<array{set: string, name: string, ready: bool, blocked: bool}>,
     *   missing: list<array{set: string, name: string, applied_at: ?string}>,
     * }
     *   - applied: recorded and on disk, in the order they ran (by id where the table has one, else by
     *     applied_at, then name); applied_at in ISO 8601 UTC ('2026-10-06T10:01:02Z')
     *   - pending: on disk and not recorded, in run order across the sets; ready = not a draft,
     *     blocked = a draft comes before it
     *   - missing: recorded, but the file is not on disk
     */
    public function status(): array
    {
        $records = $this->records($this->shape());
        $files = $this->allFiles();

        $applied = [];
        $missing = [];
        foreach ($records as $record) {
            if (in_array($record['name'], $files[$record['set']] ?? [], true)) {
                $applied[] = $record;
            } else {
                $missing[] = $record;
            }
        }

        $pending = [];
        foreach ($this->pendingFiles($records, $files, false) as $file) {
            $pending[] = ['set' => $file['set']->name, 'name' => $file['name'], 'ready' => $file['ready'], 'blocked' => $file['blocked']];
        }

        return [
            'sets' => array_map(static fn (MigrationSet $set): string => $set->name, $this->sets),
            'applied' => $applied,
            'pending' => $pending,
            'missing' => $missing,
        ];
    }

    /**
     * The pending files that will run now: in order, up to (not including)
     * the first draft. Reads only.
     *
     * @return list<array{set: string, name: string}>
     */
    public function applicable(): array
    {
        return self::names($this->applicableFiles($this->shape()));
    }

    /**
     * Applies every applicable file, each in its own transaction with its
     * record, stopping at the first that fails (rolled back whole; later sets
     * never run). Never throws for a failed migration: the result says which
     * file failed and what the database said. It throws only when the
     * connection itself fails (or `prepare` does).
     *
     * @return array{
     *   applied: list<array{set: string, name: string}>,
     *   failed: ?array{set: string, name: string},
     *   error: ?string,
     *   busy: bool,
     *   note: ?string,
     * }
     *   - busy: another apply holds the lock, and nothing was done
     *   - error: "statement N of M: <what PostgreSQL said>" and the statement's first lines (split),
     *     PostgreSQL's message alone (split false), or what a hook threw
     *   - note: what beforeApply returned
     */
    public function apply(): array
    {
        $result = ['applied' => [], 'failed' => null, 'error' => null, 'busy' => false, 'note' => null];

        if (!$this->tryLock()) {
            $result['busy'] = true;

            return $result;
        }

        try {
            $this->ensureTable();
            $shape = $this->shape() ?? throw new LogicException("The table {$this->table} cannot be read.");

            $files = $this->applicableFiles($shape);
            if ($files === []) {
                return $result;
            }

            if ($this->beforeApply !== null) {
                try {
                    $note = ($this->beforeApply)($this->pdo, self::names($files));
                } catch (Throwable $e) {
                    $result['error'] = $e->getMessage();

                    return $result;
                }
                $result['note'] = is_string($note) && $note !== '' ? $note : null;
            }

            foreach ($files as $file) {
                $set = $file['set'];
                $name = $file['name'];

                $this->pdo->beginTransaction();
                try {
                    // Another way of applying may have run it meanwhile (one that takes no lock, or another key).
                    if ($this->isRecorded($shape, $set->name, $name)) {
                        $this->pdo->commit();
                        continue;
                    }

                    if ($this->beforeFile !== null) {
                        ($this->beforeFile)($this->pdo, $set, $name);
                    }
                    $this->run($file['sql'] ?? $this->readOrFail($set, $name));
                    $this->record($shape, $set->name, $name);
                    $this->pdo->commit();
                } catch (Throwable $e) {
                    if ($this->pdo->inTransaction()) {
                        $this->pdo->rollBack();
                    }
                    $result['failed'] = ['set' => $set->name, 'name' => $name];
                    $result['error'] = $e->getMessage();
                    break;
                }

                $result['applied'][] = ['set' => $set->name, 'name' => $name];
            }

            return $result;
        } finally {
            $this->unlock();
        }
    }

    /**
     * The SQL of a file that files() lists for the set of that name; null for
     * anything else - an unknown set, a name that is no file of it (a path, a
     * file of another folder) or a file that cannot be read.
     */
    public function source(string $set, string $name): ?string
    {
        foreach ($this->sets as $candidate) {
            if ($candidate->name === $set) {
                return in_array($name, $this->files($candidate), true) ? $this->read($candidate, $name) : null;
            }
        }

        return null;
    }

    /**
     * Runs `prepare`, then creates the bookkeeping table when it is missing,
     * in the shape this site needs: (id, filename, applied_at) for one set,
     * with `folder` too for more. A table that is there is left as it is.
     */
    public function ensureTable(): void
    {
        if ($this->prepare !== null) {
            ($this->prepare)($this->pdo);
        }

        $columns = $this->columns();
        if ($columns === null) {
            $this->pdo->exec($this->createTableSql());

            return;
        }

        if ($this->shapeOf($columns) === null) {
            throw new LogicException(
                "The table {$this->table} has neither a filename nor a name column: adopt its shape in the prepare hook first."
            );
        }
    }

    /** @return ?Closure the option as a closure, or null when it is not given */
    private static function hook(array $options, string $name): ?Closure
    {
        $value = $options[$name] ?? null;
        if ($value === null) {
            return null;
        }
        if (!is_callable($value)) {
            throw new InvalidArgumentException("The option $name must be callable.");
        }

        return Closure::fromCallable($value);
    }

    /**
     * @param list<array{set: MigrationSet, name: string}> $files
     * @return list<array{set: string, name: string}>
     */
    private static function names(array $files): array
    {
        return array_map(static fn (array $file): array => ['set' => $file['set']->name, 'name' => $file['name']], $files);
    }

    /** @return array<string, list<string>> each set's files, by its name */
    private function allFiles(): array
    {
        $files = [];
        foreach ($this->sets as $set) {
            $files[$set->name] = $this->files($set);
        }

        return $files;
    }

    /**
     * The applicable files with their SQL where it was read (to judge a draft): what was judged is what runs.
     *
     * @param ?array{file: string, folder: bool, id: bool, appliedAt: ?string} $shape
     * @return list<array{set: MigrationSet, name: string, sql: ?string, ready: bool, blocked: bool}>
     */
    private function applicableFiles(?array $shape): array
    {
        $files = [];
        foreach ($this->pendingFiles($this->records($shape), $this->allFiles(), true) as $file) {
            if (!$file['ready']) {
                break;
            }
            $files[] = $file;
        }

        return $files;
    }

    /**
     * The files not recorded, in run order across the sets.
     *
     * @param list<array{set: string, name: string, applied_at: ?string}> $records
     * @param array<string, list<string>> $files
     * @return list<array{set: MigrationSet, name: string, sql: ?string, ready: bool, blocked: bool}>
     */
    private function pendingFiles(array $records, array $files, bool $stopAtDraft): array
    {
        $recorded = [];
        foreach ($records as $record) {
            $recorded[$record['set']][$record['name']] = true;
        }

        $pending = [];
        $draftBefore = false;
        foreach ($this->sets as $set) {
            foreach ($files[$set->name] as $name) {
                if (isset($recorded[$set->name][$name])) {
                    continue;
                }

                $sql = null;
                $ready = true;
                if ($this->ready !== null) {
                    $sql = $this->read($set, $name);
                    // A file that cannot be read is no file to run.
                    $ready = $sql !== null && (bool) ($this->ready)($set, $name, $sql);
                }

                $pending[] = ['set' => $set, 'name' => $name, 'sql' => $sql, 'ready' => $ready, 'blocked' => $draftBefore];
                if (!$ready) {
                    if ($stopAtDraft) {
                        return $pending;
                    }
                    $draftBefore = true;
                }
            }
        }

        return $pending;
    }

    /**
     * The table's columns and their types, from the catalog; null when the
     * table does not exist in the current schema.
     *
     * @return ?array<string, string>
     */
    private function columns(): ?array
    {
        $statement = $this->pdo->prepare(
            "SELECT a.attname, format_type(a.atttypid, a.atttypmod)
               FROM pg_catalog.pg_class c
               JOIN pg_catalog.pg_namespace n ON n.oid = c.relnamespace
               LEFT JOIN pg_catalog.pg_attribute a ON a.attrelid = c.oid AND a.attnum > 0 AND NOT a.attisdropped
              WHERE n.nspname = current_schema() AND c.relname = ? AND c.relkind IN ('r', 'p')"
        );
        $statement->execute([$this->table]);
        $rows = $statement->fetchAll(PDO::FETCH_NUM);
        if ($rows === []) {
            return null;
        }

        $columns = [];
        foreach ($rows as [$column, $type]) {
            if ($column !== null) {
                $columns[(string) $column] = (string) $type;
            }
        }

        return $columns;
    }

    /**
     * How to read the table: which column holds the file, whether it has
     * `folder` and `id`, and applied_at's type. Null when it is not there or
     * has no file column (a shape `prepare` has yet to adopt).
     *
     * @return ?array{file: string, folder: bool, id: bool, appliedAt: ?string}
     */
    private function shape(): ?array
    {
        $columns = $this->columns();

        return $columns === null ? null : $this->shapeOf($columns);
    }

    /**
     * @param array<string, string> $columns
     * @return ?array{file: string, folder: bool, id: bool, appliedAt: ?string}
     */
    private function shapeOf(array $columns): ?array
    {
        $file = match (true) {
            isset($columns['filename']) => 'filename',
            isset($columns['name']) => 'name',
            default => null,
        };
        if ($file === null) {
            return null;
        }

        $folder = isset($columns['folder']);
        if (!$folder && count($this->sets) > 1) {
            throw new LogicException(
                "The table {$this->table} has no folder column, so it cannot keep " . count($this->sets)
                . ' migration sets apart: configure one set, or adopt the table in the prepare hook.'
            );
        }

        return ['file' => $file, 'folder' => $folder, 'id' => isset($columns['id']), 'appliedAt' => $columns['applied_at'] ?? null];
    }

    /**
     * Every record, in the order the files ran.
     *
     * @param ?array{file: string, folder: bool, id: bool, appliedAt: ?string} $shape
     * @return list<array{set: string, name: string, applied_at: ?string}>
     */
    private function records(?array $shape): array
    {
        if ($shape === null) {
            return [];
        }

        $file = self::quote($shape['file']);
        $folder = $shape['folder'] ? '"folder"' : 'NULL';
        // EXTRACT reads a TIMESTAMP as UTC and a TIMESTAMPTZ as its instant: UTC either way, whatever the session's zone.
        $appliedAt = self::isTimestamp($shape['appliedAt'])
            ? 'CASE WHEN isfinite("applied_at") THEN floor(extract(epoch FROM "applied_at"))::bigint END'
            : 'NULL';
        $order = match (true) {
            $shape['id'] => '"id"',
            $shape['appliedAt'] !== null => '"applied_at", ' . $file,
            default => $file,
        };

        $rows = $this->pdo
            ->query("SELECT $folder, $file, $appliedAt FROM {$this->quotedTable()} ORDER BY $order")
            ->fetchAll(PDO::FETCH_NUM);

        $records = [];
        foreach ($rows as [$set, $name, $epoch]) {
            if ($name === null) {
                continue;
            }
            $records[] = [
                'set' => $shape['folder'] ? (string) $set : $this->sets[0]->name,
                'name' => (string) $name,
                'applied_at' => $epoch === null ? null : gmdate('Y-m-d\TH:i:s\Z', (int) $epoch),
            ];
        }

        return $records;
    }

    /** @param array{file: string, folder: bool, id: bool, appliedAt: ?string} $shape */
    private function isRecorded(array $shape, string $set, string $name): bool
    {
        $sql = "SELECT 1 FROM {$this->quotedTable()} WHERE " . self::quote($shape['file']) . ' = ?';
        $parameters = [$name];
        if ($shape['folder']) {
            $sql .= ' AND "folder" = ?';
            $parameters[] = $set;
        }

        $statement = $this->pdo->prepare($sql . ' LIMIT 1');
        $statement->execute($parameters);

        return $statement->fetchColumn() !== false;
    }

    /**
     * Records a file as applied. applied_at is written as UTC whatever the
     * session's time zone (a TIMESTAMP column's default would write the
     * session's local time).
     *
     * @param array{file: string, folder: bool, id: bool, appliedAt: ?string} $shape
     */
    private function record(array $shape, string $set, string $name): void
    {
        $columns = [self::quote($shape['file'])];
        $values = ['?'];
        $parameters = [$name];

        if ($shape['folder']) {
            $columns[] = '"folder"';
            $values[] = '?';
            $parameters[] = $set;
        }

        $type = $shape['appliedAt'];
        if ($type !== null && str_starts_with($type, 'timestamp')) {
            $columns[] = '"applied_at"';
            $values[] = str_ends_with($type, ' with time zone')
                ? 'now()'
                : "(now() AT TIME ZONE 'UTC')";
        }

        $this->pdo
            ->prepare("INSERT INTO {$this->quotedTable()} (" . implode(', ', $columns) . ') VALUES (' . implode(', ', $values) . ')')
            ->execute($parameters);
    }

    /** Runs a file's SQL: statement by statement (naming the one that fails), or whole. */
    private function run(string $sql): void
    {
        $statements = Splitter::split($sql);

        if (!$this->split) {
            // Nothing but comments: PostgreSQL would call the empty query an error.
            if ($statements !== []) {
                $this->pdo->exec($sql);
            }

            return;
        }

        $count = count($statements);
        foreach ($statements as $index => $statement) {
            try {
                $this->pdo->exec($statement);
            } catch (PDOException $e) {
                $lines = array_slice(preg_split('/\r\n|\r|\n/', $statement) ?: [$statement], 0, 6);
                throw new RuntimeException(
                    'statement ' . ($index + 1) . ' of ' . $count . ': ' . $e->getMessage() . "\n\n  " . implode("\n  ", $lines),
                    0,
                    $e
                );
            }
        }
    }

    private function createTableSql(): string
    {
        if (count($this->sets) === 1) {
            return "CREATE TABLE IF NOT EXISTS {$this->quotedTable()} (
                id         SERIAL       PRIMARY KEY,
                filename   VARCHAR(255) NOT NULL,
                applied_at TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
                UNIQUE (filename)
            )";
        }

        return "CREATE TABLE IF NOT EXISTS {$this->quotedTable()} (
            id         SERIAL       PRIMARY KEY,
            folder     VARCHAR(64)  NOT NULL,
            filename   VARCHAR(255) NOT NULL,
            applied_at TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
            UNIQUE (folder, filename)
        )";
    }

    private function tryLock(): bool
    {
        $statement = $this->pdo->prepare('SELECT CASE WHEN pg_try_advisory_lock(CAST(? AS bigint)) THEN 1 ELSE 0 END');
        $statement->execute([$this->lock]);

        return (int) $statement->fetchColumn() === 1;
    }

    private function unlock(): void
    {
        $this->pdo->prepare('SELECT pg_advisory_unlock(CAST(? AS bigint))')->execute([$this->lock]);
    }

    private function read(MigrationSet $set, string $name): ?string
    {
        $sql = @file_get_contents($set->directory . '/' . $name);

        return is_string($sql) ? $sql : null;
    }

    private function readOrFail(MigrationSet $set, string $name): string
    {
        return $this->read($set, $name) ?? throw new RuntimeException("The file {$set->name}/$name could not be read.");
    }

    private function quotedTable(): string
    {
        return self::quote($this->table);
    }

    /** Quotes a name this class chose or checked (the table's, its columns'): never anything from outside. */
    private static function quote(string $identifier): string
    {
        return '"' . $identifier . '"';
    }

    private static function isTimestamp(?string $type): bool
    {
        return $type !== null && (str_starts_with($type, 'timestamp') || $type === 'date');
    }
}
