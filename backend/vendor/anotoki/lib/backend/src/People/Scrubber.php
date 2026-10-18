<?php

declare(strict_types=1);

namespace Anotoki\Lib\People;

use Anotoki\Lib\Auth\IamClient;
use Anotoki\Lib\Support\Log;
use Anotoki\Lib\Support\Redactor;
use Closure;
use InvalidArgumentException;
use LogicException;
use PDO;
use PDOException;
use Throwable;

/**
 * The scrub of the IAM's deleted accounts on one site: for every id the IAM
 * lists (AnonymizedAccounts), this site's copies of who the person was are
 * blanked, and the rest of its data stays, nobody's now. The owner's rule: the
 * data stays; nothing is deleted - every step is an UPDATE.
 *
 * A run:
 *
 *   1. reads `since` (anotoki_people_sync) - none: from the beginning;
 *   2. asks the IAM from it, following `next` within the run until it is null
 *      or the run's time is up (`next` is never kept for the next run);
 *   3. handles each account in a transaction of its own: its id goes into
 *      anotoki_people_anonymized (whether the site knew the person or not), its
 *      row is held FOR UPDATE, its name becomes the IAM's own placeholder
 *      `deleted-{sub}` and anonymized_at the IAM's time - only where it was not
 *      scrubbed yet. Where the name column is UNIQUE and somebody already goes
 *      by that placeholder, the name is the IAM's free form instead,
 *      `deleted-{sub}-{eight hex digits}` (its account_deletion.php): a name
 *      somebody chose never stops the scrub of another. Then the site's hooks
 *      run for the copies elsewhere
 *      (fn(PDO $pdo, int $sub, ?array $former): void, $former the row's
 *      ['name' => …, 'anonymized_at' => …] before, null when the site never
 *      knew the person). A hook that throws rolls that account back; the run
 *      stops there, `since` stays before it, and the People page is told so in
 *      a fixed sentence - what the hook threw goes to the server's log alone,
 *      redacted (a TypeError's message names an absolute path) - and the next
 *      run tries again;
 *   4. keeps `since` - the newest anonymized_at handled; the IAM's `since` is
 *      inclusive, so that second comes again, which is harmless: every step is
 *      idempotent, and the hooks must be too - with checked_at, ok_at (a run
 *      that went through every id it was given), the error, and how many rows
 *      of people it scrubbed.
 *
 * An IAM that cannot be reached, a 5xx, an answer that is not the IAM's: the
 * run keeps checked_at and the error, keeps `since`, and the next run tries
 * again. One run at a time per database (an advisory lock nobody waits for).
 * It stays idle - writing nothing - until the site's anonymized_at and the
 * library's tables are there (looked for in the catalog on every run).
 *
 * A restored backup brings its own cursor back with it, and the next run
 * scrubs again whatever the backup brought back: a restore takes the runs'
 * lock before it commits, refused while a run holds it (People::replay()),
 * and a run a restore overtook all the same keeps the restored cursor, not
 * its own. Files kept outside the database (backup copies) need a retention
 * no longer than the IAM's.
 */
final class Scrubber
{
    /** The advisory lock every run takes; no site's lock has this number. */
    public const LOCK = 4_271_029_101;

    /** How long a run may go on, in seconds: it runs after the answer, and nobody waits for it - but a process does. */
    public const BUDGET = 8.0;

    /** PostgreSQL's unique_violation. */
    private const UNIQUE_VIOLATION = '23505';

    /** The placeholder, then two free names: a third taken one is no accident, and stops the run as any failure does. */
    private const NAME_TRIES = 3;

    /** @var list<Closure(PDO, int, ?array<string, mixed>): void> */
    private readonly array $hooks;

    /**
     * @param list<Closure(PDO, int, ?array<string, mixed>): void> $hooks
     */
    public function __construct(
        private readonly PDO $pdo,
        private readonly PeopleTable $table,
        private readonly AnonymizedAccounts $list,
        array $hooks = [],
        private readonly float $budget = self::BUDGET,
        private readonly int $lock = self::LOCK,
    ) {
        foreach ($hooks as $hook) {
            if (!($hook instanceof Closure)) {
                throw new InvalidArgumentException('Scrubber: every hook is a Closure(PDO $pdo, int $sub, ?array $former): void.');
            }
        }
        $this->hooks = array_values($hooks);
    }

    /** A Scrubber of $iam's list on $pdo. */
    public static function of(PDO $pdo, PeopleTable $table, IamClient $iam, array $hooks = [], ?Closure $fetch = null): self
    {
        return new self($pdo, $table, new AnonymizedAccounts($iam, $fetch), $hooks);
    }

    /**
     * One run.
     *
     * @return array{state: 'done'|'more'|'stopped'|'unreachable'|'busy'|'not_ready', handled: int, scrubbed: int, error: ?string, problems: list<string>}
     *   done: every id the IAM gave; more: the run's time was up with pages left; stopped: a hook failed;
     *   unreachable: the IAM gave no usable answer; busy: another run holds the lock; not_ready: the tables
     *   or the column are missing (problems says which)
     */
    public function run(): array
    {
        $result = ['state' => 'done', 'handled' => 0, 'scrubbed' => 0, 'error' => null, 'problems' => []];
        if ($this->pdo->inTransaction()) {
            throw new LogicException('Scrubber: give it a connection outside a transaction - each account is a transaction of its own.');
        }

        $this->table->forget();
        $problems = $this->table->problems($this->pdo);
        if ($problems !== []) {
            return ['state' => 'not_ready', 'problems' => $problems] + $result;
        }

        $locked = $this->pdo->prepare('SELECT CASE WHEN pg_try_advisory_lock(CAST(? AS bigint)) THEN 1 ELSE 0 END');
        $locked->execute([$this->lock]);
        if ((int) $locked->fetchColumn() !== 1) {
            return ['state' => 'busy'] + $result;
        }

        try {
            return $this->runLocked($result);
        } finally {
            $this->pdo->prepare('SELECT pg_advisory_unlock(CAST(? AS bigint))')->execute([$this->lock]);
        }
    }

    /**
     * Where the scrub stands, for the People page: null until the library's tables are there.
     *
     * @return ?array{since: ?string, checked_at: ?string, ok_at: ?string, error: ?string, scrubbed: int}
     */
    public static function status(PDO $pdo): ?array
    {
        if (!PeopleTable::libraryReady($pdo)) {
            return null;
        }
        $row = $pdo
            ->query(
                'SELECT ' .
                    PeopleTable::epochOf('since') .
                    ', ' .
                    PeopleTable::epochOf('checked_at') .
                    ', ' .
                    PeopleTable::epochOf('ok_at') .
                    ', error, scrubbed
               FROM anotoki_people_sync WHERE id = 1',
            )
            ->fetch(PDO::FETCH_NUM);
        if ($row === false) {
            return ['since' => null, 'checked_at' => null, 'ok_at' => null, 'error' => null, 'scrubbed' => 0];
        }
        [$since, $checked, $ok, $error, $scrubbed] = $row;
        $iso = static fn(mixed $epoch): ?string => $epoch === null ? null : gmdate('Y-m-d\TH:i:s\Z', (int) $epoch);

        return ['since' => $iso($since), 'checked_at' => $iso($checked), 'ok_at' => $iso($ok), 'error' => $error === null ? null : (string) $error, 'scrubbed' => (int) $scrubbed];
    }

    /**
     * @param array{state: string, handled: int, scrubbed: int, error: ?string, problems: list<string>} $result
     * @return array{state: 'done'|'more'|'stopped'|'unreachable'|'busy'|'not_ready', handled: int, scrubbed: int, error: ?string, problems: list<string>}
     */
    private function runLocked(array $result): array
    {
        $started = microtime(true);
        // Read once: the place asked from and the one keep() holds the run to are the same - a restore that put
        // the backup's place back between two reads would pass for no change, and the run would go past it.
        [$since, $from] = $this->since();
        $newest = null;
        $cursor = [$since, null];

        while (true) {
            $page = $this->list->page($cursor[0], $cursor[1]);
            if ($page['error'] !== null) {
                $result['state'] = 'unreachable';
                $result['error'] = $page['error'];
                Log::say('[anotoki people] the IAM\'s deleted accounts could not be read: ' . $page['error']);
                break;
            }

            foreach ($page['accounts'] as $account) {
                try {
                    $result['scrubbed'] += $this->scrub($account['sub'], $account['anonymized_at']) ? 1 : 0;
                } catch (Throwable $e) {
                    $result['state'] = 'stopped';
                    // Kept and answered for the People page: the library's sentence, never what the scrub threw.
                    $result['error'] = "The scrub of account {$account['sub']} failed: the server's error log says why.";
                    Log::say("[anotoki people] The scrub of account {$account['sub']} failed: " . Redactor::text($e->getMessage()));
                    break 2;
                }
                $result['handled']++;
                $newest = $account['anonymized_at'];
            }

            if ($page['next'] === null) {
                break;
            }
            if (microtime(true) - $started >= $this->budget) {
                $result['state'] = 'more';
                break;
            }
            $cursor = [$page['next']['since'], $page['next']['after']];
        }

        $this->keep($result, $newest, $from);

        return $result;
    }

    /**
     * `since`, read once: as the IAM's list is asked from (to the second, as status() says it) and exactly as kept
     * (to the microsecond) - nulls for none.
     *
     * @return array{?string, ?string}
     */
    private function since(): array
    {
        $row = $this->pdo
            ->query('SELECT ' . PeopleTable::epochOf('since') . ", to_char(since AT TIME ZONE 'UTC', 'YYYY-MM-DD\"T\"HH24:MI:SS.US\"Z\"') FROM anotoki_people_sync WHERE id = 1")
            ->fetch(PDO::FETCH_NUM);
        if ($row === false) {
            return [null, null];
        }

        return [$row[0] === null ? null : gmdate('Y-m-d\TH:i:s\Z', (int) $row[0]), is_string($row[1]) ? $row[1] : null];
    }

    /** One account, in a transaction of its own; whether a row of this site's people was scrubbed. */
    private function scrub(int $sub, string $anonymizedAt): bool
    {
        $t = $this->table;
        $table = PeopleTable::quote($t->table);
        $id = PeopleTable::quote($t->id);
        $name = PeopleTable::quote($t->name);
        $anonymized = PeopleTable::quote($t->anonymizedAt);

        $this->pdo->beginTransaction();
        try {
            $read = $this->pdo->prepare("SELECT $name, " . PeopleTable::epochOf($anonymized) . " FROM $table WHERE $id = CAST(? AS bigint) AND $id > 0 FOR UPDATE");
            $read->execute([$sub]);
            $row = $read->fetch(PDO::FETCH_NUM);
            $former =
                $row === false
                    ? null
                    : [
                        'name' => $row[0] === null ? null : (string) $row[0],
                        'anonymized_at' => $row[1] === null ? null : gmdate('Y-m-d\TH:i:s\Z', (int) $row[1]),
                    ];

            $this->pdo
                ->prepare('INSERT INTO anotoki_people_anonymized (sub, anonymized_at, had_row) VALUES (CAST(? AS bigint), CAST(? AS timestamptz), ?) ON CONFLICT (sub) DO NOTHING')
                ->execute([$sub, $anonymizedAt, $former === null ? 'false' : 'true']);

            $set = ["$name = ?", "$anonymized = CAST(? AS timestamptz)"];
            if ($t->updatedAt !== null && isset($t->columns($this->pdo)[$t->updatedAt])) {
                $set[] = PeopleTable::quote($t->updatedAt) . ' = ' . $t->nowFor($this->pdo, $t->updatedAt);
            }
            $scrubbed = $this->rename("UPDATE $table SET " . implode(', ', $set) . " WHERE $id = CAST(? AS bigint) AND $id > 0 AND $anonymized IS NULL", $sub, $anonymizedAt);

            foreach ($this->hooks as $hook) {
                $hook($this->pdo, $sub, $former);
            }

            $this->pdo->commit();

            return $scrubbed;
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * The row's UPDATE ($sql: the name, anonymized_at, the id): under the IAM's placeholder, or - when a UNIQUE
     * name column has it taken already (somebody called themselves deleted-{sub}) - under a free one, as the IAM
     * names such an account. Each try behind a savepoint, so a refused one leaves the account's transaction
     * whole. Whether a row was scrubbed.
     */
    private function rename(string $sql, int $sub, string $anonymizedAt): bool
    {
        $name = 'deleted-' . $sub;
        for ($try = 1; ; $try++) {
            $this->pdo->exec('SAVEPOINT anotoki_people_name');
            try {
                $update = $this->pdo->prepare($sql);
                $update->execute([$name, $anonymizedAt, $sub]);
                $this->pdo->exec('RELEASE SAVEPOINT anotoki_people_name');

                return $update->rowCount() > 0;
            } catch (PDOException $e) {
                if ($e->getCode() !== self::UNIQUE_VIOLATION || $try >= self::NAME_TRIES) {
                    throw $e;
                }
                $this->pdo->exec('ROLLBACK TO SAVEPOINT anotoki_people_name');
                $name = 'deleted-' . $sub . '-' . bin2hex(random_bytes(4));
            }
        }
    }

    /**
     * The run's outcome; `since` moved on (and ok_at) only where it still is where the run read it ($from): a
     * restore that put the backup's place back while the run went on keeps it - the run's place would pass over
     * whoever the restore brought back. (A restore holds the run's lock from its People::replay() on; this is
     * for a site that does not run it.)
     *
     * @param array{state: string, handled: int, scrubbed: int, error: ?string} $result
     */
    private function keep(array $result, ?string $newest, ?string $from): void
    {
        $sets = ['checked_at = now()', 'error = ?', 'scrubbed = scrubbed + CAST(? AS integer)'];
        $parameters = [$result['state'] === 'unreachable' || $result['state'] === 'stopped' ? $result['error'] : null, $result['scrubbed']];
        $unmoved = 'since IS NOT DISTINCT FROM CAST(? AS timestamptz)';
        if ($newest !== null) {
            // Never back: a run that started from a cursor kept by another one cannot move it behind.
            $sets[] = "since = CASE WHEN $unmoved THEN GREATEST(since, CAST(? AS timestamptz)) ELSE since END";
            $parameters[] = $from;
            $parameters[] = $newest;
        }
        if ($result['state'] === 'done') {
            $sets[] = "ok_at = CASE WHEN $unmoved THEN now() ELSE ok_at END";
            $parameters[] = $from;
        }
        $this->pdo->prepare('INSERT INTO anotoki_people_sync (id) VALUES (1) ON CONFLICT (id) DO NOTHING')->execute();
        $this->pdo->prepare('UPDATE anotoki_people_sync SET ' . implode(', ', $sets) . ' WHERE id = 1')->execute($parameters);
    }
}
