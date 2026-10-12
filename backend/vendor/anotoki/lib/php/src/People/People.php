<?php

declare(strict_types=1);

namespace Anotoki\Lib\People;

use Anotoki\Lib\Admin\Schema as AdminSchema;
use Anotoki\Lib\Auth\IamClient;
use Anotoki\Lib\Feedback\Feedback;
use Anotoki\Lib\People\Http\PeopleUpkeep;
use Anotoki\Lib\Support\AfterResponse;
use Anotoki\Lib\Support\Connection;
use Closure;
use InvalidArgumentException;
use LogicException;
use PDO;
use PDOException;
use Psr\Http\Message\ServerRequestInterface;
use RuntimeException;
use Throwable;

/**
 * A site's people, put together once: its table (PeopleTable), its connection,
 * its IAM, and the hooks the scrub calls for the copies of names kept
 * elsewhere. What the middleware, the routes and a site's command line take.
 *
 *   $people = new People(
 *       new PeopleTable(name: 'username', lastSeen: null, nameFallback: fn (int $sub): string => "user$sub"),
 *       fn (): PDO => db(),
 *       $iam,
 *       hooks: [
 *           // genshin: the feedback's snapshots of who sent it
 *           function (PDO $pdo, int $sub, ?array $former): void {
 *               $pdo->prepare("UPDATE feedback SET username = ?, email = NULL, ip = NULL, user_agent = NULL WHERE user_id = ?")
 *                   ->execute(["deleted-$sub", $sub]);
 *           },
 *       ],
 *   );
 *
 * Hooks are fn(PDO $pdo, int $sub, ?array $former): void, inside the account's
 * transaction, on the scrub's connection. They must be idempotent - an id can
 * come again - and must only UPDATE: the person's data stays, nobody's now.
 * Text the person typed (answers, notes, a feedback's words) is kept.
 */
final class People
{
    /** The savepoint a delete hook runs behind: gone after it, the hook ended the transaction it was given. */
    private const DELETE_SAVEPOINT = 'anotoki_people_delete';

    private const HOOK_ENDED = 'People: the delete hook ended the library\'s transaction - it begins, commits or rolls back none of its own (a savepoint is fine).';

    /**
     * How long before a backup's time an entry may be dated and still be matched to the backup by its transaction
     * (peopleDeletedSince()): far longer than any delete's transaction, far shorter than the life of a cluster.
     */
    private const TXID_WINDOW = '1 hour';

    /** @var list<Closure(PDO, int, ?array<string, mixed>): void> */
    public readonly array $hooks;

    /**
     * @param Closure(): PDO $pdo  the site's connection, made when a request needs it
     * @param list<Closure(PDO, int, ?array<string, mixed>): void> $hooks
     * @param ?Closure(string $url, float $timeout): array{status: int, body: ?string} $fetch  how the IAM is asked
     */
    public function __construct(
        public readonly PeopleTable $table,
        private readonly Closure $pdo,
        public readonly IamClient $iam,
        array $hooks = [],
        private readonly ?Closure $fetch = null,
    ) {
        foreach ($hooks as $hook) {
            if (!$hook instanceof Closure) {
                throw new InvalidArgumentException('People: every hook is a Closure(PDO $pdo, int $sub, ?array $former): void.');
            }
        }
        $this->hooks = array_values($hooks);
    }

    public function pdo(): PDO
    {
        return ($this->pdo)();
    }

    public function recorder(): PeopleRecorder
    {
        return new PeopleRecorder($this->table);
    }

    /** A scrubber on the site's connection - or on $pdo, a connection of its own. */
    public function scrubber(?PDO $pdo = null): Scrubber
    {
        return Scrubber::of($pdo ?? $this->pdo(), $this->table, $this->iam, $this->hooks, $this->fetch);
    }

    /**
     * The People page's Delete of one person: the site's $hook - what it keeps of them, gone, answered by label,
     * or null for nobody with that id - and, where the site has the admin tools' feedback, that person's
     * messages losing the address and browser they were sent with (Feedback::forgetPeopleOn(), as the scrub of
     * a deleted account does; the words stay), and the delete's $entry. All or nothing: in a transaction of the
     * library's on $pdo - or, inside a caller's (a restore doing the delete again, replay()), behind a savepoint
     * of the library's. The hook does ALL its work on the $pdo it is given - never on the site's own connection,
     * which in a restore waits on the restore's own locks while the restore waits on the hook - and begins,
     * commits or rolls back no transaction of its own (a savepoint is fine): one that ends the transaction it was
     * given - a commit and a new BEGIN would pass for it still open - is found by the savepoint being gone, and
     * told so. Its $request is the page's: null when a restore does the delete again, and then the hook does the
     * database's part alone (a copy kept of what a person asked to delete - Japanese Academy's JSON before a
     * delete - is not made for a replay).
     *
     * $entry (PeopleRoutes': the audit log's person.data_deleted, AuditLog::recordInside()) is written inside the
     * same transaction, after the hook, with what the hook answered: a delete is never made without it - a
     * restore does the delete again by it (replay()) - and what it throws undoes the delete. A replay writes none.
     *
     * @param Closure(PDO, int, ?ServerRequestInterface): ?array<string, mixed> $hook
     * @param ?Closure(PDO, array<string, mixed>): void $entry
     * @return ?array<string, mixed> what the hook answered
     */
    public function delete(PDO $pdo, int $sub, Closure $hook, ?ServerRequestInterface $request = null, ?Closure $entry = null): ?array
    {
        $own = !$pdo->inTransaction();
        if ($own) {
            $pdo->beginTransaction();
        }
        try {
            $pdo->exec('SAVEPOINT ' . self::DELETE_SAVEPOINT);
            try {
                $deleted = $hook($pdo, $sub, $request);
                if ($deleted !== null) {
                    if (AdminSchema::has($pdo, AdminSchema::FEEDBACK_TABLE)) {
                        Feedback::forgetPeopleOn($pdo, [$sub]);
                    }
                    if ($entry !== null) {
                        $entry($pdo, $deleted);
                    }
                }
            } catch (Throwable $e) {
                // Its own work undone; a caller's transaction - a restore's - stays as it was before it.
                $held = self::savepoint($pdo, 'ROLLBACK TO') && self::savepoint($pdo, 'RELEASE');
                if ($e instanceof PDOException && str_contains($e->getMessage(), 'already an active transaction')) {
                    throw new LogicException('People: the delete hook runs inside the library\'s transaction (0.5.0) - it begins none of its own (a savepoint is fine).', 0, $e);
                }
                if (!$held) {
                    throw self::gone($pdo, $e);
                }
                throw $e;
            }
            if (!self::savepoint($pdo, 'RELEASE')) {
                throw self::gone($pdo);
            }
            if ($own) {
                $pdo->commit();
            }

            return $deleted;
        } catch (Throwable $e) {
            if ($own && $pdo->inTransaction()) {
                try {
                    $pdo->rollBack();
                } catch (Throwable) {
                    // A connection the database ended took its transaction with it: what failed first is said.
                }
            }
            throw $e;
        }
    }

    /**
     * Both of a restore's hooks, for the site's Backups - `...$people->restoreHooks(delete: $deleteHook)` among
     * its options: replay() as its `beforeCommit`, afterRestore() as its `afterRestore`.
     *
     * @param ?Closure(PDO, int, ?ServerRequestInterface): ?array<string, mixed> $delete  the People page's hook
     * @param ?Closure(callable): void $defer  the site's after-the-answer runner (PeopleUpkeep's)
     * @param ?Closure(array<string, mixed>, PDO): void $then  the site's own work after a restore
     * @return array{beforeCommit: Closure(array<string, mixed>, PDO): void, afterRestore: Closure(array<string, mixed>, PDO): void}
     */
    public function restoreHooks(?Closure $delete = null, ?Closure $defer = null, ?Closure $then = null): array
    {
        return ['beforeCommit' => $this->replay($delete), 'afterRestore' => $this->afterRestore($defer, $then)];
    }

    /**
     * For Backups' `beforeCommit`: what a restore must do again before anybody sees its rows - inside its
     * transaction, on its connection, its locks held, so a request that dies after the commit leaves nothing
     * undone and no backup is made of the rows without it.
     *
     * A restore puts the database back as it was when the backup was made; the audit log is never restored, so
     * it still says what was done since - from the backup's snapshot_at on (the database's clock, which writes
     * the audit log too; a backup of before 0.5.0 has its created_at alone, PHP's):
     *   - every person.data_deleted: the People page's delete again (delete(): the site's $delete, its request
     *     null, and the person's feedback forgotten), in the order they were done. Its entry is written inside
     *     the delete's transaction (0.5.0, `_txid` its transaction's id), so its time is when that transaction
     *     began: one begun just before the snapshot may have committed after it. Such an entry is done again
     *     from the snapshot's time on, and - dated within the hour before it (TXID_WINDOW) - when its
     *     transaction is not in the backup's snapshot (txid_visible_in_snapshot(), the manifest's txid_snapshot).
     *     Never further back: an id is one PostgreSQL cluster's, and a database moved to another by pg_dump counts
     *     anew - against a snapshot of after the move, every entry of before it would seem unseen, and its delete
     *     done again on whatever the person kept here since. An entry of before 0.5.0, or a backup without a
     *     snapshot: the time alone;
     *   - every feedback.deleted: the message deleted again where it is the same one - its id and the time it
     *     was sent both (an entry of before 0.5.0 names no time, and is passed over). Its entry is written inside
     *     the delete's transaction too (Feedback::deleteWithEntry()), and matched to the backup the same way.
     * No check of the IAM's deleted accounts may run across a restore: one begun before it would keep its own
     * place in the IAM's list over the restored one, past whoever the restore brought back. The restore holds
     * the check's lock until it commits - and while a check holds it, the restore is refused (waiting could
     * deadlock: the check waits on the restore's tables).
     *
     * Whatever fails is thrown, and the restore is rolled back whole - never a restore that brings back a
     * deleted person's data. With something to do again (a $delete, the feedback), a backup that says no time,
     * one whose snapshot cannot be read, or a database with no audit log is a failure too, never a delete passed
     * over in silence. The $delete does all its work on the restore's PDO it is given: on another connection it
     * waits on the restore's locks, and the restore is ended (Backups: rolled back after a minute's wait).
     *
     * @param ?Closure(PDO, int, ?ServerRequestInterface): ?array<string, mixed> $delete  the People page's hook -
     *        PeopleRoutes' delete:; without it, no person's delete is done again
     * @return Closure(array<string, mixed>, PDO): void
     */
    public function replay(?Closure $delete = null): Closure
    {
        return function (array $manifest, PDO $pdo) use ($delete): void {
            $locked = $pdo->prepare('SELECT CASE WHEN pg_try_advisory_xact_lock(CAST(? AS bigint)) THEN 1 ELSE 0 END');
            $locked->execute([Scrubber::LOCK]);
            if ((int) $locked->fetchColumn() !== 1) {
                throw new RuntimeException('A check of the accounts the IAM deleted was running, and it would have kept its place in the IAM\'s list over the restored one: restore again in a minute.');
            }

            $feedback = AdminSchema::has($pdo, AdminSchema::FEEDBACK_TABLE);
            if ($delete === null && !$feedback) {
                return;
            }
            $since = self::backupTime($manifest);
            if ($since === null) {
                throw new RuntimeException('This backup says no time it was made at, so what was deleted since cannot be done again.');
            }
            $snapshot = $manifest['txid_snapshot'] ?? null;
            if ($snapshot !== null && (!is_string($snapshot) || preg_match('/^[0-9]{1,19}:[0-9]{1,19}:(?:[0-9]{1,19}(?:,[0-9]{1,19})*)?$/D', $snapshot) !== 1)) {
                throw new RuntimeException('This backup\'s snapshot cannot be read, so what was deleted since cannot be told.');
            }
            if (!AdminSchema::has($pdo, AdminSchema::AUDIT_TABLE)) {
                throw new RuntimeException('There is no audit log here to say what was deleted since this backup was made.');
            }

            if ($delete !== null) {
                foreach (self::peopleDeletedSince($pdo, $since, $snapshot) as $sub) {
                    try {
                        $this->delete($pdo, (int) $sub, $delete);
                    } catch (Throwable $e) {
                        throw new RuntimeException("What was deleted of person $sub since this backup was made could not be deleted again (" . self::said($e) . ').', 0, $e);
                    }
                }
            }
            if ($feedback) {
                try {
                    foreach (self::feedbackDeletedSince($pdo, $since, $snapshot) as [$id, $sentAt]) {
                        Feedback::deleteOn($pdo, $id, $sentAt);
                    }
                } catch (Throwable $e) {
                    throw new RuntimeException('The feedback deleted since this backup was made could not all be deleted again (' . self::said($e) . ').', 0, $e);
                }
            }
        };
    }

    /**
     * For Backups' `afterRestore`: what a restore must do again that cannot run inside its transaction, once it
     * committed (replay() did the deletes inside it):
     *   1. the scrub of the IAM's deleted accounts, at once: the restore brought back the scrub's place of the
     *      backup's day, and with it the names of whoever the IAM deleted since. One that does not finish - the
     *      IAM out of reach, its time up with more to read, a run already going, a failure of any kind - leaves
     *      the upkeep's hourly stamp to go after the answer (this request's own upkeep claims the hour first),
     *      so the next request runs the scrub again;
     *   2. the site's own work after a restore ($then: Piano Academy's new generations), whatever the scrub did.
     * What could not be done is thrown, all of it in one RuntimeException: the restore's after_restore_error,
     * which the Backups page shows and the site's onError hears - never a restore that looks complete with a
     * deleted person's name back. The first failure that threw is its previous: its class, file, line and trace
     * stay with it.
     *
     * @param ?Closure(callable): void $defer  the site's after-the-answer runner (PeopleUpkeep's); default
     *        AfterResponse::shared()
     * @param ?Closure(array<string, mixed>, PDO): void $then  the site's own work after a restore
     * @return Closure(array<string, mixed>, PDO): void
     */
    public function afterRestore(?Closure $defer = null, ?Closure $then = null): Closure
    {
        return function (array $manifest, PDO $pdo) use ($defer, $then): void {
            $failed = [];
            $first = null;
            try {
                $why = $this->scrubAgain($pdo);
            } catch (Throwable $e) {
                $first = $e;
                $why = 'it failed: ' . self::said($e);
            }
            if ($why !== null) {
                try {
                    $this->scrubSoon($defer);
                } catch (Throwable $e) {
                    $first ??= $e;
                    $why .= '; nor could the next request be told to: ' . self::said($e);
                }
                $failed[] = "The accounts the IAM deleted were not checked again ($why). Whoever it deleted since this backup was made may be named here "
                    . 'until they are: the check runs again with the next request, and at once with Check now on the People page.';
            }
            if ($then !== null) {
                try {
                    $then($manifest, $pdo);
                } catch (Throwable $e) {
                    $first ??= $e;
                    $failed[] = self::said($e) . '.';
                }
            }
            if ($failed !== []) {
                throw new RuntimeException(implode(' ', $failed), 0, $first);
            }
        };
    }

    /** The scrub at once, after a restore: null when it went through every id, else why not. */
    private function scrubAgain(PDO $pdo): ?string
    {
        $run = $this->scrubber($pdo)->run();

        return match ($run['state']) {
            'done' => null,
            'unreachable', 'stopped' => lcfirst(self::firstLine((string) $run['error'])),
            'more' => 'its time ran out with more of the IAM\'s list to read',
            'busy' => 'another check was running',
            'not_ready' => 'what it needs is not there: ' . implode('; ', $run['problems']),
            default => 'it ended ' . $run['state'],
        };
    }

    /** The upkeep's hourly stamp gone after the answer - not before: this request's own upkeep would claim it again. */
    private function scrubSoon(?Closure $defer): void
    {
        $cache = $this->iam->cacheDirectory();
        if ($cache === null) {
            return;
        }
        $forget = static function () use ($cache): void {
            @unlink($cache . '/' . PeopleUpkeep::STAMP);
        };
        $defer !== null ? $defer($forget) : AfterResponse::shared()->defer($forget);
    }

    /** What $e says, for a sentence of the library's: its first line, without a closing full stop. */
    private static function said(Throwable $e): string
    {
        return self::firstLine($e->getMessage());
    }

    /**
     * A message's first line, without a closing full stop: what follows in a database's error - its DETAIL and
     * CONTEXT, which quote rows - is never told, and the Redactor would take every word after it with it.
     */
    private static function firstLine(string $message): string
    {
        return rtrim(trim(preg_split('/\R/', $message, 2)[0] ?? ''), '.');
    }

    /**
     * Why the delete's savepoint is gone: the hook ended the transaction it was given - or the database ended the
     * connection itself (a restore's, past its wait while the hook waited on another connection), whose failure is
     * told as it is.
     */
    private static function gone(PDO $pdo, ?Throwable $failure = null): Throwable
    {
        if (Connection::lost($pdo)) {
            return $failure ?? new RuntimeException('People: the database ended the connection while the delete hook ran.');
        }

        return new LogicException(self::HOOK_ENDED, 0, $failure);
    }

    /** $how (RELEASE, ROLLBACK TO) the delete's savepoint: false when it is gone - the hook ended the transaction. */
    private static function savepoint(PDO $pdo, string $how): bool
    {
        if (!$pdo->inTransaction()) {
            return false;
        }
        try {
            $pdo->exec($how . ' SAVEPOINT ' . self::DELETE_SAVEPOINT);

            return true;
        } catch (PDOException) {
            return false;
        }
    }

    /**
     * When the backup's snapshot was taken, by the database's clock (snapshot_at, 0.5.0) - or, for a backup of
     * before, when it was made by PHP's (created_at); null when it says neither.
     *
     * @param array<string, mixed> $manifest
     */
    private static function backupTime(array $manifest): ?string
    {
        foreach (['snapshot_at', 'created_at'] as $key) {
            $value = $manifest[$key] ?? null;
            if (is_string($value) && preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}(\.[0-9]{1,6})?Z$/D', $value) === 1) {
                return $value;
            }
        }

        return null;
    }

    /**
     * The people whose data was deleted since the backup (sinceTheBackup()) - each once, in the order first done.
     * Ids only: a target that is no id is passed over.
     *
     * @return list<string>
     */
    private static function peopleDeletedSince(PDO $pdo, string $since, ?string $snapshot): array
    {
        [$after, $parameters] = self::sinceTheBackup($since, $snapshot);
        $statement = $pdo->prepare(
            'SELECT target_id FROM ' . AdminSchema::AUDIT_TABLE . '
              WHERE action = \'person.data_deleted\' AND target_type = \'user\' AND target_id IS NOT NULL AND ' . $after . '
              GROUP BY target_id ORDER BY min(created_at), min(id)'
        );
        $statement->execute($parameters);

        return array_values(array_filter(
            array_map('strval', $statement->fetchAll(PDO::FETCH_COLUMN)),
            static fn (string $id): bool => preg_match('/^[1-9][0-9]{0,17}$/D', $id) === 1,
        ));
    }

    /**
     * The feedback messages deleted since the backup (sinceTheBackup()): each by its id and the time it was sent,
     * as its delete recorded them (FeedbackRoutes, 0.5.0) - an entry without the time is passed over: by its id
     * alone it might be another message now.
     *
     * @return list<array{int, string}>
     */
    private static function feedbackDeletedSince(PDO $pdo, string $since, ?string $snapshot): array
    {
        [$after, $parameters] = self::sinceTheBackup($since, $snapshot);
        $statement = $pdo->prepare(
            'SELECT target_id, details->>\'created_at\' FROM ' . AdminSchema::AUDIT_TABLE . '
              WHERE action = \'feedback.deleted\' AND target_type = \'feedback\' AND target_id IS NOT NULL AND ' . $after . '
              ORDER BY created_at, id'
        );
        $statement->execute($parameters);
        $out = [];
        foreach ($statement->fetchAll(PDO::FETCH_NUM) as [$id, $sentAt]) {
            if (preg_match('/^[1-9][0-9]{0,17}$/D', (string) $id) === 1 && is_string($sentAt) && $sentAt !== '') {
                $out[] = [(int) $id, $sentAt];
            }
        }

        return $out;
    }

    /**
     * Which audit entries were written since the backup, as an SQL condition and its parameters: every entry from
     * $since on (an entry of that very moment counts), and an entry that names its transaction (`_txid`, written
     * inside its delete's own: its time is when that began) dated within TXID_WINDOW before $since, when the
     * backup's $snapshot does not see that transaction.
     *
     * The transaction's id is asked only of an entry dated just before the backup: the one race it is for - a
     * delete begun before the snapshot, committed after it - lasts as long as a delete's transaction, never an
     * hour. Further back, a cluster's ids may not be this backup's at all (a database moved by pg_dump counts
     * anew): an entry of before the move would seem unseen by every later snapshot, and its delete be done again
     * on whatever was kept here since. The time alone says it is in the backup.
     *
     * @return array{string, list<?string>}
     */
    private static function sinceTheBackup(string $since, ?string $snapshot): array
    {
        return [
            '(created_at >= CAST(? AS timestamptz)
              OR CASE WHEN CAST(? AS text) IS NOT NULL AND created_at >= CAST(? AS timestamptz) - CAST(? AS interval)
                           AND details->>\'_txid\' ~ \'^[0-9]{1,18}$\'
                      THEN NOT txid_visible_in_snapshot(CAST(details->>\'_txid\' AS bigint), CAST(? AS txid_snapshot))
                      ELSE false END)',
            [$since, $snapshot, $since, self::TXID_WINDOW, $snapshot],
        ];
    }
}
