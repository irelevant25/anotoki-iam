<?php

declare(strict_types=1);

namespace Anotoki\Lib\Backups;

use Anotoki\Lib\Admin\Schema;
use Anotoki\Lib\Audit\AuditRecorder;
use Anotoki\Lib\Migrations\Migrator;
use Anotoki\Lib\Support\AfterResponse;
use Anotoki\Lib\Support\AtomicFile;
use Anotoki\Lib\Support\Connection;
use Anotoki\Lib\Support\GuardedDirectory;
use Anotoki\Lib\Support\Keys;
use Anotoki\Lib\Support\Log;
use Anotoki\Lib\Support\Redactor;
use Anotoki\Lib\Support\Refusal;
use Anotoki\Lib\Support\Utf8;
use Closure;
use InvalidArgumentException;
use PDO;
use Psr\Http\Message\ServerRequestInterface;
use RuntimeException;
use Throwable;

/**
 * Backups of a site's database without pg_dump (no host has it): PostgreSQL's
 * COPY through PDO (Copy), every table in one snapshot, into one file with the
 * migrations the database has applied - the schema is the migrations.
 *
 * A backup is {directory}/<id>.anotoki-backup (Archive), the id
 * YYYYMMDD-HHMMSSZ-<kind>-<32 hex> - 128 random bits, so no name can be guessed
 * even where a host ignores the folder's .htaccess - with a sidecar
 * <id>.anotoki-backup.json that the list reads (a file put back by FTP without
 * one is listed from its header). Kinds: daily (the first request of a UTC
 * day, after its answer: dailyIfDue(); 14 kept), manual (until deleted;
 * flagged after 90 days), before-migration (the Apply's beforeApply:
 * beforeApplyHook(); 5 kept), before-restore (5 kept). Only files of that name
 * are ever deleted: whatever else is in the folder (Japanese Academy's own
 * JSON copies, genshin's pg_dump folders) is never touched.
 *
 * Made in one REPEATABLE READ, READ ONLY transaction - one snapshot of every
 * table, its time the database's own (the manifest's snapshot_at: the clock
 * the audit log's entries are written by) and the transactions it sees (its
 * txid_snapshot), which a restore reads what was done since by - in an order a
 * restore can load (parents first), each table COPYed
 * into a spool of its own (Spool) in a working folder of this run, then
 * tarred, gzipped, encrypted where the site says so (`encryption`: the IAM's,
 * always), hashed, written aside and renamed. On a site that encrypts, the
 * rows are never in the clear on its disk: a spool seals them as COPY hands
 * them over, under a key that lives only in memory - and a restore reads them
 * back the same way. A working folder a timeout or a kill left behind goes
 * once it is an hour old (sweep(), with the next backup or restore, and the
 * daily run). One backup or Apply at a time (a lock file and the migrations'
 * advisory lock).
 *
 * Restored in place (restore(), unless `restore` is false - the IAM's first
 * version), all or nothing, only when the live database's applied migrations
 * and columns equal the backup's (else 409 schema_differs, naming the files):
 * a before-restore backup first, then one transaction - every restored table
 * locked (5 seconds at most), its user triggers off (no updated_at rewritten,
 * no IAM MEMBER rows added), emptied in one TRUNCATE, loaded by COPY in the
 * archive's order, its sequences set, its triggers on, its rows counted, then
 * the site's beforeCommit - what must be done again before anybody sees the
 * rows (People::replay(): the deletes done since the backup) - and only then
 * the commit: whatever fails rolls the restore back whole, and nobody reads
 * the restored rows, nor backs them up, before it ran. A restore that waits
 * more than a minute between two of its statements is ended by PostgreSQL
 * (idle_in_transaction_session_timeout) and rolled back with its connection: a
 * hook that works on another connection - the site's own in place of the $pdo
 * it is given - waits on the restore's own locks while the restore waits on the
 * hook, and every request to those tables would wait behind both for ever (no
 * deadlock is seen: the restore's connection is idle). Never restored, always
 * kept: the bookkeeping table(s), anotoki_audit_log (the history stays, the
 * restore's own entry in it) and the site's `keep`. Then the site's
 * afterRestore - what cannot run inside the transaction: the scrub of the
 * IAM's deleted accounts, which asks the IAM (People::afterRestore()), the
 * IAM's own sweep.
 *
 * Downloads go by single-use tickets, good for `ticketSeconds` (60; Tickets).
 * The two files of fixed names the folder holds - the before-migration note
 * and the daily stamp - name no backup: a host that ignores .htaccess would
 * hand out both. Every file and folder the backups write is the site's own
 * user's alone (0600, 0700).
 *
 * A backup that is not encrypted is sealed: its sidecar carries an HMAC of
 * its id and its SHA-256 under a key of this installation's (Keys) - so a file
 * dropped into the folder, with a sidecar of its own or none, is listed as not
 * sealed and never restored here (restored offline, from extractTo(), when its
 * owner means it). The seal vouches for the id and the SHA-256 alone, so a
 * restore compares the SHA-256 of the very bytes it reads - whatever else the
 * sidecar says. What it stops is a file put into storage/backups by somebody
 * who cannot replace the installation's key: a copy from another installation,
 * a file changed or damaged since, whatever writes into the backups folder but
 * never reaches the key (the file explorer reaches neither: it refuses a root
 * that holds anotoki-lib.key, or is, holds or is inside the storage folder).
 * Not somebody who can write the whole storage folder: deleting
 * storage/anotoki-lib.key and making it again are a folder's operations - no
 * read of the old key needed - and with a key of their own they seal what they
 * like (as they can put a key set of their own in place of the IAM's, kept in
 * the same folder, which tokens are checked with: the storage folder is the
 * site's own, and nothing in it holds against whoever writes it). An encrypted
 * one is sealed by its own cipher, and carries no seal; without the
 * installation's key a plain one is made unsealed, never refused.
 */
final class Backups
{
    /** Why a backup without this installation's seal is not restored here. */
    private const UNSEALED = 'It carries no seal of this site\'s - it was not made here, or it was changed since - so it is not restored here: restore it offline (README).';

    /** Why a restore the database ended left nothing of it (load()); %s: how long it may wait between two statements. */
    private const ENDED = 'the database ended its connection, and its transaction with it - as it ends a restore that waits more than %s between two of its statements: a hook inside it that works on another connection waits on the restore\'s own locks (a delete hook works on the $pdo it is given, never on the site\'s own connection).';

    // What a refusal or an answer says of a failure: a fixed sentence of the library's, never the message the failure
    // carries - PHP's, the database's or a hook's own: a TypeError's names the absolute path of the file it was called
    // in, a database's its host, its user and the values it quotes. The failure itself goes to `onError` - the site's
    // error log, which redacts every line it writes - and the sentence says to look there.

    /** 500 backup_failed. */
    private const BACKUP_FAILED = 'The backup could not be made. The error log says why.';

    /** The Apply's error, from beforeApplyHook(). */
    private const BEFORE_UPDATE_FAILED = 'The backup before the update could not be made, so nothing was applied. The error log says why.';

    /** 503 database_unavailable (preview()), and the status card's problem. */
    private const CANNOT_ASK = 'The database cannot be asked now. The error log says why.';

    /** 500 ticket_failed. */
    private const TICKET_FAILED = 'The download could not be prepared. The error log says why.';

    /** 422 archive_invalid (restore()). */
    private const UNREADABLE = 'This backup cannot be read here: it was changed, damaged or cut short, made under another key, or not made here - the error log says which. One not made here is restored offline (README).';

    /** 500 before_restore_failed. */
    private const BEFORE_RESTORE_FAILED = 'The backup of what is there now could not be made, so nothing was restored. The error log says why.';

    /** 500 restore_failed, rolled back whole. */
    private const RESTORE_FAILED = 'The restore failed, and nothing of it stayed. The error log says why.';

    /** 500 restore_failed, its transaction ended by a hook (RestoreEnded); %s: the before-restore backup's id. */
    private const RESTORE_ENDED = 'The restore did not finish, and what it had loaded may have stayed (%s holds the data as it was). The error log says why.';

    /** The answer's after_restore_error: the site's afterRestore threw. */
    private const AFTER_RESTORE_FAILED = 'What this site does after a restore did not all go through. The error log says what.';

    public const DAILY = 'daily';
    public const MANUAL = 'manual';
    public const BEFORE_MIGRATION = 'before-migration';
    public const BEFORE_RESTORE = 'before-restore';
    public const KINDS = [self::DAILY, self::MANUAL, self::BEFORE_MIGRATION, self::BEFORE_RESTORE];

    /** A backup's id, as a route placeholder's pattern (no capturing group). */
    public const ID_PATTERN = '[0-9]{8}-[0-9]{6}Z-(?:daily|manual|before-migration|before-restore)-[0-9a-f]{32}';

    public const EXTENSION = '.anotoki-backup';

    /** The most a description may be, in characters. */
    public const DESCRIPTION_LIMIT = 200;

    private const OPTIONS = [
        'site',
        'migrator',
        'bookkeeping',
        'keep',
        'encryption',
        'restore',
        'beforeCommit',
        'afterRestore',
        'keepDaily',
        'keepBeforeMigration',
        'keepBeforeRestore',
        'staleManualDays',
        'schema',
        'defer',
        'audit',
        'lock',
        'ready',
        'storage',
        'onError',
        'ticketSeconds',
    ];

    /** The savepoint a restore's beforeCommit runs behind: gone after it, its transaction was ended inside. */
    private const BEFORE_COMMIT_SAVEPOINT = 'anotoki_backups_before_commit';

    private readonly string $directory;
    private readonly string $site;
    private readonly Closure $migrator;
    /** @var list<string> */
    private readonly array $bookkeeping;
    /** @var list<string> */
    private readonly array $keep;
    private readonly ?string $encryption;
    private readonly bool $restoreEnabled;
    private readonly ?Closure $beforeCommit;
    private readonly ?Closure $afterRestore;
    /** @var array<string, int> */
    private readonly array $retention;
    private readonly int $staleManualDays;
    private readonly string $schema;
    private readonly ?Closure $defer;
    private readonly ?AuditRecorder $audit;
    private readonly int $lock;
    private readonly ?Closure $ready;
    private readonly string $storage;
    private readonly Closure $onError;
    private readonly int $ticketSeconds;
    private readonly Tickets $tickets;
    private ?string $sealKey = null;
    private bool $sealKeyMissing = false;

    /**
     * How long a restore's connection may wait between two statements inside its transaction before PostgreSQL ends
     * it, in milliseconds: a minute. The restore itself never waits so long - PHP between two statements takes
     * milliseconds, and COPY reads the spools inside its own statement (it is no wait between two) - so only a hook
     * that waits on something else does: on another connection, which waits on the restore's own locks. No option:
     * the tests shorten it.
     */
    private int $idleMilliseconds = 60_000;

    /**
     * @param Closure(): PDO $connect  a connection (PDO::ERRMODE_EXCEPTION) - opened with Copy::connect() where
     *        PHP has Pdo\Pgsql (8.4+), so COPY raises no deprecation
     * @param string $directory  where the backups are kept: the site's storage/backups
     * @param array{
     *   site?: string,
     *   migrator: Closure(PDO): Migrator,
     *   bookkeeping?: list<string>,
     *   keep?: list<string>,
     *   encryption?: ?string,
     *   restore?: bool,
     *   beforeCommit?: Closure(array<string, mixed>, PDO): void,
     *   afterRestore?: Closure(array<string, mixed>, PDO): void,
     *   keepDaily?: int, keepBeforeMigration?: int, keepBeforeRestore?: int, staleManualDays?: int,
     *   schema?: string,
     *   defer?: Closure(callable): void,
     *   audit?: AuditRecorder,
     *   lock?: int,
     *   ready?: Closure(PDO): bool,
     *   storage?: string,
     *   onError?: Closure(Throwable): void,
     *   ticketSeconds?: int,
     * } $options
     *   - site: the site's name in every header ('iam', 'genshin', ...)
     *   - migrator: the site's Migrator on a connection - its sets (the library's too), table and hooks
     *   - bookkeeping: the migrations tables, never restored (default ['migrations']); keep: other tables never
     *     restored (anotoki_audit_log always is kept)
     *   - encryption: the site's backup key, 32 bytes (the IAM: Keys::derive(iamMasterKey(), 'iam-backup')) -
     *     every backup is then encrypted, and none opens without it
     *   - restore: false refuses every restore (the IAM, until its afterRestore exists)
     *   - beforeCommit: inside a restore's transaction - its rows loaded and counted, its locks held - before the
     *     commit: what must be done again before anybody sees the rows (People::replay(): the deletes done since
     *     the backup), on the restore's connection, the manifest given (its snapshot_at, its txid_snapshot). What
     *     it throws rolls the restore back whole; it ends no transaction (a savepoint is fine), and does all its
     *     work on the PDO it is given: another connection waits on the restore's locks, and the restore is ended
     *     after a minute of waiting for it
     *   - afterRestore: after a restore committed, what cannot run inside it (People::afterRestore(): the scrub,
     *     asking the IAM; the IAM's anonymization sweep again, caches cleared) - what it throws is the answer's
     *     after_restore_error
     *   - keepDaily 14, keepBeforeMigration 5, keepBeforeRestore 5; manual ones flagged after staleManualDays 90
     *   - defer: the site's after-the-answer runner (the daily backup); default: at once
     *   - audit: backup.created (by nobody for the automatic ones), .deleted, .downloaded, .restored, .restore_failed
     *   - lock: the migrations' advisory lock (the site's Migrator `lock`, default Migrator::DEFAULT_LOCK)
     *   - ready: whether the site is ready for the daily backup (default: nothing to apply)
     *   - storage: the storage folder (the daily's stamp, the tickets); default the folder above $directory
     *   - onError: told of the daily backup's failure (the error log); default: the library's log line
     *     (Support\Log - PHP's error log unless the site sends the lines elsewhere), redacted
     */
    public function __construct(private readonly Closure $connect, string $directory, array $options)
    {
        $unknown = array_diff(array_map('strval', array_keys($options)), self::OPTIONS);
        if ($unknown !== []) {
            throw new InvalidArgumentException('Unknown Backups option: ' . implode(', ', $unknown) . '.');
        }
        if (!(($options['migrator'] ?? null) instanceof Closure)) {
            throw new InvalidArgumentException('Backups need the site\'s migrator: fn (PDO $pdo): Migrator.');
        }
        $this->directory = rtrim($directory, '/\\');
        $site = (string) ($options['site'] ?? 'site');
        if (preg_match('/^[a-z0-9][a-z0-9_-]{0,31}$/D', $site) !== 1) {
            throw new InvalidArgumentException('A site\'s name is small letters, digits, _ and - (at most 32).');
        }
        $this->site = $site;
        $this->migrator = $options['migrator'];
        $this->bookkeeping = array_values(array_map('strval', $options['bookkeeping'] ?? ['migrations']));
        $this->keep = array_values(array_unique(array_merge(array_map('strval', $options['keep'] ?? []), [Schema::AUDIT_TABLE])));
        $encryption = $options['encryption'] ?? null;
        if ($encryption !== null && (!is_string($encryption) || strlen($encryption) !== 32)) {
            throw new InvalidArgumentException('The option encryption is a key of 32 bytes (Keys::derive()).');
        }
        $this->encryption = $encryption;
        $this->restoreEnabled = (bool) ($options['restore'] ?? true);
        $this->beforeCommit = $options['beforeCommit'] ?? null;
        $this->afterRestore = $options['afterRestore'] ?? null;
        $this->retention = [
            self::DAILY => max(1, (int) ($options['keepDaily'] ?? 14)),
            self::BEFORE_MIGRATION => max(1, (int) ($options['keepBeforeMigration'] ?? 5)),
            self::BEFORE_RESTORE => max(1, (int) ($options['keepBeforeRestore'] ?? 5)),
        ];
        $this->staleManualDays = max(1, (int) ($options['staleManualDays'] ?? 90));
        $this->schema = (string) ($options['schema'] ?? 'public');
        $this->defer = $options['defer'] ?? null;
        $audit = $options['audit'] ?? null;
        if ($audit !== null && !($audit instanceof AuditRecorder)) {
            throw new InvalidArgumentException('The option audit must be an AuditRecorder (the AuditLog).');
        }
        $this->audit = $audit;
        $this->lock = (int) ($options['lock'] ?? Migrator::DEFAULT_LOCK);
        $this->ready = $options['ready'] ?? null;
        $this->storage = rtrim((string) ($options['storage'] ?? dirname($this->directory)), '/\\');
        $this->onError =
            $options['onError'] ??
            static function (Throwable $e): void {
                Log::say('[backups] ' . get_class($e) . ': ' . Redactor::text($e->getMessage()));
            };
        $this->ticketSeconds = max(5, (int) ($options['ticketSeconds'] ?? 60));
        $this->tickets = new Tickets($this->storage . '/cache/backup-tickets', $this->ticketSeconds);
    }

    public function encrypted(): bool
    {
        return $this->encryption !== null;
    }

    public function restoreEnabled(): bool
    {
        return $this->restoreEnabled;
    }

    // ─── Making ──────────────────────────────────────────────────────────────

    /**
     * A backup made now. Refused 409 busy while another backup or an Apply runs,
     * 507 no_space when the disk has less than twice the last backup free, 500
     * backup_failed (a fixed sentence; why, to `onError`).
     *
     * @return array<string, mixed>|Refusal  the backup's entry in the list
     */
    public function create(string $kind = self::MANUAL, ?string $description = null, int|string|null $actorId = null, ?ServerRequestInterface $request = null): array|Refusal
    {
        if (!in_array($kind, self::KINDS, true)) {
            return Refusal::invalid('kind_unknown', 'A backup is daily, manual, before-migration or before-restore.');
        }
        $description = $description === null ? null : trim($description);
        if ($description !== null && Utf8::length($description) > self::DESCRIPTION_LIMIT) {
            return Refusal::invalid('description_too_long', 'The description is longer than ' . self::DESCRIPTION_LIMIT . ' characters.');
        }
        AfterResponse::allowSeconds(300);
        AfterResponse::keepRunning();

        $held = $this->hold();
        if ($held instanceof Refusal) {
            return $held;
        }
        try {
            $space = $this->spaceRefusal();
            if ($space !== null) {
                return $space;
            }
            $pdo = ($this->connect)();
            if (!$this->tryLock($pdo)) {
                return self::busy();
            }
            try {
                $info = $this->make($pdo, $kind, $description === '' ? null : $description, $actorId);
            } finally {
                $this->unlock($pdo);
            }
        } catch (Throwable $e) {
            ($this->onError)($e);

            return new Refusal(500, 'backup_failed', self::BACKUP_FAILED);
        } finally {
            $this->release($held);
        }

        $this->audit?->record($request, $actorId, 'backup.created', 'backup', $info['id'], self::auditDetails($info));
        $this->prune();

        return $info;
    }

    /**
     * The day's backup, after the answer (through `defer`): queued for the first
     * request of a UTC day that finds none made today (and none failed in the
     * last hour), made by one process, skipped while the site is not ready. A
     * failure goes to `onError`, never to the page. Answers whether it was queued.
     */
    public function dailyIfDue(): bool
    {
        $stamp = $this->dailyStamp();
        if (!$this->dailyDue(json_decode((string) @file_get_contents($stamp), true))) {
            return false;
        }
        $this->later(fn() => $this->runDaily());

        return true;
    }

    /**
     * The Migrator's beforeApply: a before-migration backup of the database as
     * it is, once for each list of pending files (a failed Apply tried again
     * makes no second copy; another upload's files make their own), its name
     * the Apply's note. A backup that cannot be made stops the Apply before
     * anything runs. A new installation (nothing applied yet) has nothing to
     * keep. $next is the site's own hook (genshin's order guard), run first.
     *
     * @param null|Closure(PDO, list<array{set: string, name: string}>): ?string $next
     * @return Closure(PDO, list<array{set: string, name: string}>): ?string
     */
    public function beforeApplyHook(?Closure $next = null): Closure
    {
        return function (PDO $pdo, array $files) use ($next): ?string {
            $notes = [];
            if ($next !== null) {
                $note = $next($pdo, $files);
                if (is_string($note) && $note !== '') {
                    $notes[] = $note;
                }
            }
            AfterResponse::allowSeconds(300);
            AfterResponse::keepRunning();
            try {
                $id = $this->beforeMigration($files);
            } catch (Throwable $e) {
                ($this->onError)($e);
                // The Apply's answer says what is thrown here (the Migrations page): the fixed sentence, never $e's.
                throw new RuntimeException(self::BEFORE_UPDATE_FAILED, 0, $e);
            }
            if ($id !== null) {
                $notes[] = "A backup of the database as it was is $id.";
            }

            return $notes === [] ? null : implode(' ', $notes);
        };
    }

    // ─── Reading ─────────────────────────────────────────────────────────────

    /**
     * Every backup in the folder, newest first - from its sidecar, or (put back
     * by FTP without one) from its header.
     *
     * @return list<array<string, mixed>>
     */
    public function list(): array
    {
        $out = [];
        foreach (@scandir($this->directory) ?: [] as $name) {
            if (preg_match('/^(' . self::ID_PATTERN . ')' . preg_quote(self::EXTENSION, '/') . '$/D', $name, $match) === 1) {
                $info = $this->info($match[1]);
                if ($info !== null) {
                    unset($info['tables']);
                    $out[] = $info;
                }
            }
        }
        // Newest first: by the time each was made (to the millisecond), then by name.
        usort($out, static fn(array $a, array $b): int => [(string) ($b['created_at'] ?? ''), (string) $b['id']] <=> [(string) ($a['created_at'] ?? ''), (string) $a['id']]);

        return $out;
    }

    /**
     * One backup: its entry in the list with its tables ({name, rows, bytes}), or null.
     *
     * @return ?array<string, mixed>
     */
    public function get(string $id): ?array
    {
        return self::validId($id) ? $this->info($id) : null;
    }

    /**
     * The page's status card: {directory_writable, free_bytes, last_daily, copy_available, encrypted,
     * cipher_available, restore_enabled, restorable, problems, count, total_bytes, database_bytes, schema}.
     *
     * @return array<string, mixed>
     */
    public function status(): array
    {
        $backups = $this->list();
        $last = null;
        foreach ($backups as $backup) {
            if ($backup['kind'] === self::DAILY) {
                $last = $backup['created_at'];
                break;
            }
        }
        $status = [
            'directory_writable' => is_dir($this->directory) ? is_writable($this->directory) : is_writable(dirname($this->directory)),
            'free_bytes' => $this->freeBytes(),
            'last_daily' => $last,
            'copy_available' => false,
            'encrypted' => $this->encryption !== null,
            'cipher_available' => ChunkCipher::available(),
            'restore_enabled' => $this->restoreEnabled,
            'restorable' => false,
            'problems' => [],
            'count' => count($backups),
            'total_bytes' => array_sum(array_column($backups, 'size')),
            'database_bytes' => null,
            'schema' => null,
        ];
        try {
            $pdo = ($this->connect)();
            $status['copy_available'] = Copy::available($pdo);
            $status['database_bytes'] = (int) $pdo->query('SELECT pg_database_size(current_database())')->fetchColumn();
            $live = $this->liveSchema($pdo);
            $status['schema'] = ['migrations_sha256' => $live['migrations_sha256'], 'columns_sha256' => $live['columns_sha256']];
            $problems = $this->restoreProblems($pdo, $live['shape']);
            $status['problems'] = $problems;
            $status['restorable'] = $this->restoreEnabled && $status['copy_available'] && $problems === [];
        } catch (Throwable $e) {
            ($this->onError)($e);
            $status['problems'][] = self::CANNOT_ASK;
        }

        return $status;
    }

    /**
     * What a restore of this backup would do, table by table - the rows each
     * would have afterwards against now; `lost` adds up the rows there now that a
     * restore takes away (genshin's preview) - and whether it can be done here.
     *
     * @return array<string, mixed>|Refusal
     */
    public function preview(string $id): array|Refusal
    {
        $info = $this->get($id);
        if ($info === null) {
            return self::notFound();
        }
        try {
            $pdo = ($this->connect)();
            $live = $this->liveSchema($pdo);
        } catch (Throwable $e) {
            ($this->onError)($e);

            return new Refusal(503, 'database_unavailable', self::CANNOT_ASK);
        }
        $schema = self::schemaDifference($info['schema'] ?? [], $live);
        $reasons = [];
        if (!$this->restoreEnabled) {
            $reasons[] = 'Restoring is switched off on this site.';
        }
        if (!$schema['equal']) {
            $reasons[] = 'The database\'s schema has changed since this backup: it is restored offline (README).';
        }
        if (!$this->sealedOrEncrypted($info)) {
            $reasons[] = self::UNSEALED;
        }
        foreach ($this->restoreProblems($pdo, $live['shape']) as $problem) {
            $reasons[] = $problem;
        }

        $kept = $this->keptTables($live['shape']);
        $backupRows = [];
        foreach ($info['tables'] ?? [] as $table) {
            $backupRows[(string) $table['name']] = (int) $table['rows'];
        }
        $differences = [];
        $lost = 0;
        $liveTotal = 0;
        foreach ($live['shape']->tables as $table) {
            $name = $table['name'];
            if (in_array($name, $kept, true)) {
                continue;
            }
            $count = (int) $pdo->query('SELECT count(*) FROM ' . $live['shape']->qualified($name))->fetchColumn();
            $liveTotal += $count;
            $rows = $backupRows[$name] ?? null;
            if ($rows === $count) {
                continue;
            }
            $delta = $count - ($rows ?? 0);
            $lost += max(0, $delta);
            $differences[] = ['name' => $name, 'live' => $count, 'backup' => $rows, 'delta' => $delta];
        }
        usort($differences, static fn(array $a, array $b): int => $b['delta'] <=> $a['delta']);

        return [
            'id' => $id,
            'kind' => $info['kind'],
            'created_at' => $info['created_at'],
            'restorable' => $reasons === [],
            'reasons' => $reasons,
            'schema' => $schema,
            'kept' => $kept,
            'live' => ['rows' => $liveTotal, 'tables' => count($live['shape']->tables) - count($kept)],
            'backup' => ['rows' => array_sum(array_diff_key($backupRows, array_flip($kept))), 'tables' => count(array_diff_key($backupRows, array_flip($kept)))],
            'lost' => $lost,
            'differences' => $differences,
        ];
    }

    // ─── Deleting, downloading ──────────────────────────────────────────────

    /** Deletes a backup and its sidecar (audited backup.deleted). */
    public function delete(string $id, int|string|null $actorId = null, ?ServerRequestInterface $request = null): true|Refusal
    {
        $info = $this->get($id);
        if ($info === null) {
            return self::notFound();
        }
        $this->remove($id);
        if (is_file($this->path($id))) {
            return new Refusal(500, 'delete_failed', 'The backup file could not be deleted.');
        }
        $this->audit?->record($request, $actorId, 'backup.deleted', 'backup', $id, ['kind' => $info['kind'], 'size' => $info['size']]);

        return true;
    }

    /**
     * A ticket for one download of the backup within `ticketSeconds` (audited
     * backup.downloaded: the proof was asked before this).
     *
     * @return array{ticket: string, expires_in: int}|Refusal
     */
    public function ticket(string $id, int|string|null $actorId = null, ?ServerRequestInterface $request = null): array|Refusal
    {
        $info = $this->get($id);
        if ($info === null) {
            return self::notFound();
        }
        try {
            $ticket = $this->tickets->issue($id, $actorId);
        } catch (Throwable $e) {
            ($this->onError)($e);

            return new Refusal(500, 'ticket_failed', self::TICKET_FAILED);
        }
        $this->audit?->record($request, $actorId, 'backup.downloaded', 'backup', $id, ['kind' => $info['kind'], 'size' => $info['size']]);

        return ['ticket' => $ticket, 'expires_in' => $this->ticketSeconds];
    }

    /**
     * The file a ticket opens - used up whatever the answer - or null. With
     * $by ([the person asking]), only for the person it was issued to: a site
     * whose download route knows who asks (its session cookie) passes them.
     * With $spend false (a HEAD, which a link checker or a scanner sends), the
     * ticket is only looked at: the GET it is for still finds it.
     *
     * @param ?array{0: int|string|null} $by
     * @return ?array{path: string, size: int, filename: string}
     */
    public function redeem(string $id, string $ticket, ?array $by = null, bool $spend = true): ?array
    {
        if (!self::validId($id) || !($spend ? $this->tickets->redeem($ticket, $id, $by) : $this->tickets->good($ticket, $id, $by))) {
            return null;
        }
        $path = $this->path($id);
        $size = @filesize($path);
        if ($size === false) {
            return null;
        }

        return ['path' => $path, 'size' => $size, 'filename' => $this->site . '-' . $id . self::EXTENSION];
    }

    // ─── Restoring ───────────────────────────────────────────────────────────

    /**
     * Puts the backup back in place - all or nothing (see the class). Refused:
     * 403 restore_disabled, 404, 409 busy | schema_differs (+ differs) | keep_conflict,
     * 422 archive_invalid (changed, cut short, another key), 500 before_restore_failed |
     * restore_failed (rolled back whole; audited backup.restore_failed).
     *
     * @return array<string, mixed>|Refusal  {restored, before_restore, tables, rows, duration_ms, after_restore_error}
     */
    public function restore(string $id, int|string|null $actorId = null, ?ServerRequestInterface $request = null): array|Refusal
    {
        if (!$this->restoreEnabled) {
            return new Refusal(403, 'restore_disabled', 'Restoring a backup is switched off on this site.');
        }
        $info = $this->get($id);
        if ($info === null) {
            return self::notFound();
        }
        $started = microtime(true);
        AfterResponse::allowSeconds(900);
        AfterResponse::keepRunning();

        $held = $this->hold();
        if ($held instanceof Refusal) {
            return $held;
        }
        $work = $this->directory . '/.tmp-restore-' . bin2hex(random_bytes(8));
        $spools = [];
        try {
            $pdo = ($this->connect)();
            if (!$this->tryLock($pdo)) {
                return self::busy();
            }
            try {
                try {
                    if (!self::workingFolder($work)) {
                        throw new RuntimeException('its working folder cannot be made.');
                    }
                    [$manifest, $spools] = $this->unpack($id, $info, $work, spooled: true);
                } catch (Throwable $e) {
                    ($this->onError)($e);

                    return new Refusal(422, 'archive_invalid', self::UNREADABLE);
                }

                $live = $this->liveSchema($pdo);
                $schema = self::schemaDifference($manifest['schema'], $live);
                if (!$schema['equal']) {
                    return new Refusal(409, 'schema_differs', 'The database\'s schema has changed since this backup, so it is not restored here: restore it offline (README).', ['differs' => $schema]);
                }
                $problems = $this->restoreProblems($pdo, $live['shape']);
                if ($problems !== []) {
                    return new Refusal(409, 'keep_conflict', implode(' ', $problems));
                }

                try {
                    $before = $this->make($pdo, self::BEFORE_RESTORE, 'Before restoring ' . $id, $actorId);
                } catch (Throwable $e) {
                    ($this->onError)($e);

                    return new Refusal(500, 'before_restore_failed', self::BEFORE_RESTORE_FAILED);
                }
                $this->audit?->record($request, $actorId, 'backup.created', 'backup', $before['id'], self::auditDetails($before));

                try {
                    $loaded = $this->load($pdo, $live['shape'], $manifest, $spools);
                } catch (Throwable $e) {
                    ($this->onError)($e);
                    // Told as the answer tells it: the audit log keeps its entries for good, and the failure's own
                    // words are the error log's.
                    $said = $e instanceof RestoreEnded ? sprintf(self::RESTORE_ENDED, $before['id']) : self::RESTORE_FAILED;
                    $this->audit?->record($request, $actorId, 'backup.restore_failed', 'backup', $id, ['before_restore' => $before['id'], 'error' => $said]);

                    return new Refusal(500, 'restore_failed', $said);
                }
            } finally {
                $this->unlock($pdo);
            }
        } finally {
            foreach ($spools as $spool) {
                $spool->discard();
            }
            self::removeTree($work);
            $this->release($held);
        }

        $this->audit?->record($request, $actorId, 'backup.restored', 'backup', $id, [
            'kind' => $info['kind'],
            'created_at' => $info['created_at'],
            'before_restore' => $before['id'],
            'tables' => $loaded['tables'],
            'rows' => $loaded['rows'],
        ]);
        $afterError = null;
        if ($this->afterRestore !== null) {
            try {
                ($this->afterRestore)($manifest, $pdo);
            } catch (Throwable $e) {
                ($this->onError)($e);
                $afterError = self::AFTER_RESTORE_FAILED;
            }
        }
        $this->prune();

        return [
            'restored' => $id,
            'before_restore' => $before['id'],
            'tables' => $loaded['tables'],
            'rows' => $loaded['rows'],
            'duration_ms' => (int) round((microtime(true) - $started) * 1000),
            'after_restore_error' => $afterError,
        ];
    }

    /**
     * Reads a backup whole into $directory (made here) - decrypted, every part checked - for the offline
     * tools: the manifest, schema/<set>/<file>.sql, data/NNN-<table>.copy. The one place the rows are
     * written in the clear on purpose: the owner's own folder, on the owner's command line.
     *
     * @return array<string, mixed> the manifest
     * @throws RuntimeException when it cannot be read (changed, cut short, another key)
     */
    public function extractTo(string $id, string $directory): array
    {
        $info = $this->get($id) ?? throw new RuntimeException('There is no such backup.');

        if (file_exists($directory) || !@mkdir($directory, 0700, true)) {
            throw new RuntimeException('The folder to read it into must be a new one.');
        }

        return $this->unpack($id, $info, $directory, spooled: false)[0];
    }

    /**
     * A backup's manifest - its tables with their rows, its migrations, who made it - read from the start of
     * the archive alone (an encrypted one decrypted no further than its first chunks), into memory: no row
     * of the database is read, and nothing is written anywhere. What the command line's --inspect shows.
     *
     * @return array<string, mixed>
     * @throws RuntimeException when it cannot be read (no such backup, another key, no manifest first)
     */
    public function manifest(string $id): array
    {
        $this->get($id) ?? throw new RuntimeException('There is no such backup.');
        $json = '';
        $read = false;
        $enough = new RuntimeException('The manifest is read: the rest is not.');
        try {
            Archive::extract($this->path($id), $this->encryption, static function (string $name, int $size) use (&$json, &$read, $enough): Closure {
                if ($read || $name !== 'manifest.json' || $size > 64 * 1048576) {
                    throw $enough;
                }
                $read = true;

                return static function (string $bytes) use (&$json): void {
                    $json .= $bytes;
                };
            });
        } catch (RuntimeException $e) {
            if ($e !== $enough) {
                throw $e;
            }
        }
        $manifest = json_decode($json, true);
        if (!$read || !is_array($manifest) || ($manifest['format'] ?? null) !== 'anotoki-backup-manifest' || ($manifest['id'] ?? null) !== $id) {
            throw new RuntimeException('Its manifest is not readable.');
        }

        return $manifest;
    }

    /** A backup's file. */
    public function path(string $id): string
    {
        return $this->directory . '/' . $id . self::EXTENSION;
    }

    public static function validId(string $id): bool
    {
        return preg_match('/^' . self::ID_PATTERN . '$/D', $id) === 1;
    }

    // ─── Inside: making ──────────────────────────────────────────────────────

    /**
     * The backup itself, on a connection that holds the locks (or runs inside an Apply that does).
     *
     * @return array<string, mixed> its entry in the list
     */
    private function make(PDO $pdo, string $kind, ?string $description, int|string|null $actorId): array
    {
        $started = microtime(true);
        if (!GuardedDirectory::ensure($this->directory)) {
            throw new RuntimeException('The backups folder cannot be written.');
        }
        if ($this->encryption !== null && !ChunkCipher::available()) {
            throw new RuntimeException('This PHP has no AES-256-GCM, and this site\'s backups are encrypted.');
        }
        if (!Copy::available($pdo)) {
            throw new RuntimeException('This PHP cannot COPY through PDO (pdo_pgsql).');
        }
        if ($pdo->inTransaction()) {
            throw new RuntimeException('A backup is its own transaction: the connection is in another one.');
        }
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $createdAt = $now->format('Y-m-d\TH:i:s.v\Z');
        $id = $now->format('Ymd-His') . 'Z-' . $kind . '-' . bin2hex(random_bytes(16));
        $work = $this->directory . '/.tmp-' . $id;
        if (!self::workingFolder($work) || (!@mkdir($work . '/data', 0700) && !is_dir($work . '/data'))) {
            throw new RuntimeException('The backup\'s working folder cannot be made.');
        }
        $spools = [];

        try {
            $pdo->beginTransaction();
            try {
                // One snapshot of every table, and nothing written meanwhile by this connection.
                $pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY');
                // What a restore reads the deletes done since by (People::replay()): the snapshot itself - the
                // transactions it sees (txid_current_snapshot(): taken by this, the transaction's first statement,
                // and every COPY reads under it) - and its time by the database's clock, which writes every audit
                // entry (now(): the transaction's start, before its snapshot).
                [$snapshotAt, $txidSnapshot] = $pdo
                    ->query("SELECT to_char(now() AT TIME ZONE 'UTC', 'YYYY-MM-DD\"T\"HH24:MI:SS.US\"Z\"'), CAST(txid_current_snapshot() AS text)")
                    ->fetch(PDO::FETCH_NUM);
                $shape = DatabaseShape::read($pdo, $this->schema);
                $migrator = ($this->migrator)($pdo);
                $records = self::records($migrator);

                $tables = [];
                foreach ($shape->tables as $index => $table) {
                    $file = sprintf('data/%03d-%s.copy', $index + 1, self::fileName($table['name'], 60));
                    // Sealed as COPY hands the rows over, where the site's backups are encrypted (Spool).
                    $spool = $spools[$file] = Spool::create($work . '/' . $file, $this->encryption !== null);
                    try {
                        Copy::toFile($pdo, $shape->qualified($table['name']), implode(', ', array_map(Copy::quote(...), $table['columns'])), $spool->url());
                    } catch (Throwable $e) {
                        throw $spool->failure() === null ? $e : new RuntimeException('A part of the backup could not be written: ' . $spool->failure(), 0, $e);
                    }
                    $tables[] = ['name' => $table['name'], 'file' => $file, 'columns' => $table['columns']] + $spool->facts();
                }
                $sequences = [];
                foreach ($shape->sequences as $sequence) {
                    $row = $pdo->query('SELECT last_value, is_called FROM ' . $shape->qualified($sequence['name']))->fetch(PDO::FETCH_NUM);
                    $sequences[] = $sequence + ['last_value' => (string) $row[0], 'is_called' => $row[1] === true || $row[1] === 't'];
                }
                $pdo->commit();
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $e;
            }

            $schemaFiles = [];
            $entries = [];
            $taken = [];
            foreach ($records as $record) {
                $sql = $migrator->source($record['set'], $record['name']);
                if ($sql === null) {
                    continue;
                }
                $file = 'schema/' . self::fileName($record['set'], 64) . '/' . self::fileName($record['name'], 150);
                // Two names that read the same once made plain ("a b.sql", "a_b.sql") each keep a part of their own.
                for ($n = 2; isset($taken[$file]); $n++) {
                    $file = 'schema/' . self::fileName($record['set'], 64) . '/' . $n . '-' . self::fileName($record['name'], 140);
                }
                $taken[$file] = true;
                $schemaFiles[] = ['set' => $record['set'], 'name' => $record['name'], 'file' => $file, 'sha256' => hash('sha256', $sql)];
                $entries[] = ['name' => $file, 'data' => $sql];
            }

            $schema = [
                'migrations' => $records,
                'migrations_sha256' => self::migrationsSha256($records),
                'columns_sha256' => $shape->columnsSha256,
            ];
            $header = [
                'id' => $id,
                'site' => $this->site,
                'kind' => $kind,
                'created_at' => $createdAt,
                'library_version' => self::libraryVersion(),
                'schema' => $schema,
            ];
            $manifest = [
                'format' => 'anotoki-backup-manifest',
                'v' => 1,
                'id' => $id,
                'site' => $this->site,
                'kind' => $kind,
                'created_at' => $createdAt,
                'snapshot_at' => (string) $snapshotAt,
                'txid_snapshot' => (string) $txidSnapshot,
                'created_by' => $actorId,
                'description' => $description,
                'database_schema' => $this->schema,
                'schema' => $schema,
                'tables' => $tables,
                'sequences' => $sequences,
                'schema_files' => $schemaFiles,
                'duration_ms' => (int) round((microtime(true) - $started) * 1000),
            ];
            array_unshift($entries, ['name' => 'manifest.json', 'data' => json_encode($manifest, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)]);
            foreach ($tables as $table) {
                $entries[] = ['name' => $table['file'], 'file' => $spools[$table['file']]->url(), 'size' => $table['bytes']];
            }

            $part = $work . '/archive.part';
            $written = Archive::write($part, $header, $entries, $this->encryption);
            $target = $this->path($id);
            if (!@rename($part, $target)) {
                throw new RuntimeException('The backup could not be put in its place.');
            }

            $info = $written['header'] + [
                'status' => 'complete',
                'file' => basename($target),
                'size' => $written['size'],
                'sha256' => $written['sha256'],
                'created_by' => $actorId,
                'description' => $description,
                'tables' => array_map(static fn(array $table): array => ['name' => $table['name'], 'rows' => $table['rows'], 'bytes' => $table['bytes']], $tables),
                'table_count' => count($tables),
                'rows' => array_sum(array_column($tables, 'rows')),
                'sequences' => count($sequences),
                'duration_ms' => (int) round((microtime(true) - $started) * 1000),
            ];
            unset($info['cipher']);
            // A plain backup's seal: made here, and as it was made (the class). An encrypted one needs none - its
            // cipher is its seal - and a backup is never kept from being made for want of one: without the
            // installation's key it is made unsealed (restored offline), and the site is told.
            if ($this->encryption === null) {
                $seal = $this->seal($id, $written['sha256']);
                if ($seal !== null) {
                    $info['seal'] = $seal;
                } else {
                    ($this->onError)(
                        new RuntimeException(
                            'The backup ' .
                                $id .
                                ' was made without its seal: the installation\'s key (storage/' .
                                Keys::INSTALLATION_FILE .
                                ') can be neither read nor made, so it is restored offline only.',
                        ),
                    );
                }
            }
            AtomicFile::write($target . '.json', json_encode($info, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR), mode: 0600);

            return $this->info($id) ?? $info;
        } finally {
            foreach ($spools as $spool) {
                $spool->discard();
            }
            self::removeTree($work);
        }
    }

    private function runDaily(): void
    {
        $stampFile = $this->dailyStamp();
        if (!GuardedDirectory::ensure(dirname($stampFile))) {
            return;
        }
        $handle = @fopen($stampFile, 'c+');
        if ($handle === false) {
            return;
        }
        @chmod($stampFile, 0600);
        try {
            // One process makes the day's backup; another finds the lock taken and leaves.
            if (!flock($handle, LOCK_EX | LOCK_NB)) {
                return;
            }
            $stamp = json_decode((string) stream_get_contents($handle), true);
            if (!$this->dailyDue($stamp)) {
                return;
            }
            // Once a day at least, made or not: what a killed run left behind goes (an hour old).
            $this->sweep();
            $pdo = ($this->connect)();
            $ready = $this->ready !== null ? (bool) ($this->ready)($pdo) : ($this->migrator)($pdo)->applicable() === [];
            if (!$ready) {
                return;
            }
            $result = $this->create(self::DAILY);
            if ($result instanceof Refusal && $result->code === 'busy') {
                return;
            }
            $today = gmdate('Y-m-d');
            // No backup's name: a host that ignores .htaccess would serve this file of a known name.
            $note = $result instanceof Refusal ? ['date' => $today, 'status' => 'failed', 'at' => time(), 'code' => $result->code] : ['date' => $today, 'status' => 'done', 'at' => time()];
            ftruncate($handle, 0);
            rewind($handle);
            fwrite($handle, (string) json_encode($note));
            fflush($handle);
            if ($result instanceof Refusal) {
                ($this->onError)(new RuntimeException('The daily backup failed: ' . $result->message));
            }
        } catch (Throwable $e) {
            ($this->onError)($e);
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /** @param mixed $stamp what the daily stamp holds */
    private function dailyDue(mixed $stamp): bool
    {
        if (!is_array($stamp) || ($stamp['date'] ?? null) !== gmdate('Y-m-d')) {
            return true;
        }

        // Done today; or failed within the hour (tried again later, not on every request).
        return ($stamp['status'] ?? null) === 'failed' && (int) ($stamp['at'] ?? 0) < time() - 3600;
    }

    private function dailyStamp(): string
    {
        return $this->storage . '/cache/anotoki-backup-daily.json';
    }

    /**
     * @param list<array{set: string, name: string}> $files
     */
    private function beforeMigration(array $files): ?string
    {
        $pdo = ($this->connect)();
        $migrator = ($this->migrator)($pdo);
        $status = $migrator->status();
        if ($status['applied'] === [] && $status['missing'] === []) {
            return null;
        }

        // The note names neither the files nor the backup (a host that ignores .htaccess would serve this file
        // of a known name): a hash of the pending list, and when its backup was made - found again in the list.
        $pending = hash('sha256', implode("\n", array_map(static fn(array $file): string => $file['set'] . '/' . $file['name'], $files)));
        $noteFile = $this->directory . '/.before-migration.json';
        $last = json_decode((string) @file_get_contents($noteFile), true);
        if (is_array($last) && ($last['pending'] ?? null) === $pending && is_string($last['made'] ?? null)) {
            foreach ($this->list() as $backup) {
                if ($backup['kind'] === self::BEFORE_MIGRATION && ($backup['created_at'] ?? null) === $last['made']) {
                    return $backup['id'];
                }
            }
        }

        $held = $this->hold();
        if ($held instanceof Refusal) {
            throw new RuntimeException($held->message);
        }
        try {
            // The Apply holds the migrations' lock already: no other backup or Apply runs.
            $info = $this->make($pdo, self::BEFORE_MIGRATION, 'Before applying ' . count($files) . ' migration file' . (count($files) === 1 ? '' : 's'), null);
        } finally {
            $this->release($held);
        }
        AtomicFile::write($noteFile, (string) json_encode(['pending' => $pending, 'made' => $info['created_at']]), mode: 0600);
        $this->audit?->record(null, null, 'backup.created', 'backup', $info['id'], self::auditDetails($info));
        $this->prune();

        return $info['id'];
    }

    /** Deletes the backups past keeping, kind by kind - only files of the library's own names. */
    private function prune(): void
    {
        $byKind = [];
        foreach ($this->list() as $backup) {
            $byKind[$backup['kind']][] = $backup['id'];
        }
        foreach ($this->retention as $kind => $keep) {
            $ids = $byKind[$kind] ?? [];
            rsort($ids, SORT_STRING);
            foreach (array_slice($ids, $keep) as $id) {
                $this->remove($id);
            }
        }
    }

    private function remove(string $id): void
    {
        if (!self::validId($id)) {
            return;
        }
        @unlink($this->path($id));
        @unlink($this->path($id) . '.json');
    }

    // ─── Inside: restoring ───────────────────────────────────────────────────

    /**
     * The backup read whole into $work, every part checked against what its manifest says - its tables'
     * rows into spools ($spooled: a restore; sealed where the site's backups are encrypted), or into plain
     * files (the offline tools' extractTo()).
     *
     * @param array<string, mixed> $info
     * @return array{0: array<string, mixed>, 1: array<string, Spool>} the manifest, and the spools by file
     */
    private function unpack(string $id, array $info, string $work, bool $spooled): array
    {
        $file = $this->path($id);
        // A restore here takes only what this installation made: a plain backup with its seal (the class).
        if ($spooled && ($info['sealed'] ?? false) !== true) {
            throw new RuntimeException(self::UNSEALED);
        }
        // The seal vouches for the SHA-256 beside it, and for nothing else of the sidecar (its status least of
        // all): a plain backup is restored only when the very bytes read below - the file is opened once - are
        // the ones sealed. An encrypted one's chunks are authenticated as they are read.
        $sealedSha256 = is_string($info['sha256'] ?? null) ? $info['sha256'] : null;
        if (!is_dir($work)) {
            throw new RuntimeException('Its working folder is not there.');
        }

        $manifest = null;
        $readManifest = static function () use (&$manifest, $work): array {
            if ($manifest === null) {
                $manifest = json_decode((string) @file_get_contents($work . '/manifest.json'), true);
                if (!is_array($manifest) || ($manifest['format'] ?? null) !== 'anotoki-backup-manifest') {
                    throw new RuntimeException('Its manifest is not readable.');
                }
            }

            return $manifest;
        };
        $seen = [];
        $spools = [];
        $read = null;
        $sealed = $this->encryption !== null;
        try {
            $header = Archive::extract(
                $file,
                $this->encryption,
                function (string $name, int $size) use ($work, $readManifest, &$seen, &$spools, $spooled, $sealed): string {
                    if (isset($seen[$name])) {
                        throw new RuntimeException('It holds a part twice.');
                    }
                    $seen[$name] = true;
                    if ($name === 'manifest.json') {
                        if (count($seen) !== 1 || $size > 64 * 1048576) {
                            throw new RuntimeException('Its manifest is out of place.');
                        }

                        return $work . '/manifest.json';
                    }
                    $manifest = $readManifest();
                    foreach ($manifest['tables'] ?? [] as $table) {
                        if (($table['file'] ?? null) === $name) {
                            if ((int) ($table['bytes'] ?? -1) !== $size) {
                                throw new RuntimeException("Its part $name is not the size its manifest says.");
                            }
                            if (!$spooled) {
                                return self::placeUnder($work, $name);
                            }
                            $spools[$name] = Spool::create(self::placeUnder($work, $name), $sealed);

                            return $spools[$name]->url();
                        }
                    }
                    foreach ($manifest['schema_files'] ?? [] as $schemaFile) {
                        if (($schemaFile['file'] ?? null) === $name) {
                            return self::placeUnder($work, $name);
                        }
                    }
                    throw new RuntimeException("It holds a part its manifest does not name ($name).");
                },
                $read,
            );

            // A plain archive: the bytes just read must be the ones its sidecar names (and, for a restore, seals) -
            // whatever else the sidecar says. Checked here, before a row of them is loaded.
            if ($read !== null && ($spooled || $sealedSha256 !== null) && ($sealedSha256 === null || !hash_equals($sealedSha256, $read))) {
                throw new RuntimeException('Its checksum is not the one it was written with: it was changed or damaged.');
            }
            $manifest = $readManifest();
            if (($header['id'] ?? null) !== $id || ($manifest['id'] ?? null) !== $id) {
                throw new RuntimeException('It is another backup than its name says.');
            }
            if (($header['schema'] ?? null) !== ($manifest['schema'] ?? null)) {
                throw new RuntimeException('Its header and its manifest disagree.');
            }
            foreach ($manifest['tables'] ?? [] as $table) {
                $path = $work . '/' . $table['file'];
                if ($spooled ? !isset($spools[$table['file']]) : !is_file($path)) {
                    throw new RuntimeException('Its part ' . $table['file'] . ' is missing.');
                }
                $facts = $spooled ? $spools[$table['file']]->facts() : self::fileFacts($path);
                if ($facts['sha256'] !== ($table['sha256'] ?? null) || $facts['rows'] !== (int) ($table['rows'] ?? -1)) {
                    throw new RuntimeException('Its part ' . $table['file'] . ' is not what its manifest says.');
                }
            }
            foreach ($manifest['schema_files'] ?? [] as $schemaFile) {
                $path = $work . '/' . $schemaFile['file'];
                if (!is_file($path) || hash_file('sha256', $path) !== ($schemaFile['sha256'] ?? null)) {
                    throw new RuntimeException('Its part ' . $schemaFile['file'] . ' is not what its manifest says.');
                }
            }
        } catch (Throwable $e) {
            foreach ($spools as $spool) {
                $spool->discard();
            }
            throw $e;
        }

        return [$manifest, $spools];
    }

    /**
     * The restore's one transaction (see the class).
     *
     * @param array<string, mixed> $manifest
     * @param array<string, Spool> $spools  the tables' rows, by their file in the archive
     * @return array{tables: int, rows: int}
     */
    private function load(PDO $pdo, DatabaseShape $shape, array $manifest, array $spools): array
    {
        $kept = $this->keptTables($shape);
        $tables = array_values(array_filter($manifest['tables'], static fn(array $table): bool => !in_array($table['name'], $kept, true)));
        $qualified = array_map(static fn(array $table): string => $shape->qualified($table['name']), $tables);
        $owners = array_flip($kept);

        if ($pdo->inTransaction()) {
            throw new RuntimeException('A restore is its own transaction: the connection is in another one.');
        }
        $pdo->beginTransaction();
        $committing = false;
        try {
            $pdo->exec("SET LOCAL lock_timeout = '5s'");
            // Ended by PostgreSQL - rolled back, its locks let go - should it wait between two statements longer than
            // a restore ever does by itself ($idleMilliseconds): a hook that waits on another connection, which waits
            // on these locks, would otherwise hold the site's tables for ever. SET LOCAL: until the transaction ends.
            $pdo->exec('SET LOCAL idle_in_transaction_session_timeout = ' . max(1, $this->idleMilliseconds));
            $pdo->exec('SET CONSTRAINTS ALL DEFERRED');
            if ($qualified !== []) {
                $pdo->exec('LOCK TABLE ' . implode(', ', $qualified) . ' IN ACCESS EXCLUSIVE MODE');
                foreach ($qualified as $table) {
                    $pdo->exec('ALTER TABLE ' . $table . ' DISABLE TRIGGER USER');
                }
                $pdo->exec('TRUNCATE ' . implode(', ', $qualified));
            }
            $rows = 0;
            foreach ($tables as $table) {
                $spool = $spools[$table['file']] ?? throw new RuntimeException('Its part ' . $table['file'] . ' is missing.');
                try {
                    Copy::fromFile($pdo, $shape->qualified($table['name']), implode(', ', array_map(Copy::quote(...), $table['columns'])), $spool->url());
                } catch (Throwable $e) {
                    throw $spool->failure() === null ? $e : new RuntimeException('A part of the backup could not be read back: ' . $spool->failure(), 0, $e);
                }
                // COPY takes a read that failed for the end of the rows: the spool says so, and the transaction goes.
                if ($spool->failure() !== null) {
                    throw new RuntimeException('A part of the backup could not be read back: ' . $spool->failure());
                }
                $rows += (int) $table['rows'];
            }
            foreach ($manifest['sequences'] ?? [] as $sequence) {
                if ($sequence['owner'] !== null && isset($owners[$sequence['owner']])) {
                    continue;
                }
                // Never backwards: the next value is the later of the backup's and today's. A value handed out
                // since the backup was made may be named where a restore never reaches - the audit log kept, a
                // site's generations of progress - and must never be handed out again. "Later" in the sequence's
                // own direction, by its own step: higher for one that counts up, lower for one that counts down
                // (a site's visitors: -1, -2, ...), where a step of +1 would leave its range (setval(0) past
                // MAXVALUE -1) and fail every restore of a database that ever had a visitor.
                $sequenceName = $shape->qualified((string) $sequence['name']);
                $step = $pdo->prepare('SELECT seqincrement FROM pg_sequence WHERE seqrelid = CAST(? AS regclass)');
                $step->execute([$sequenceName]);
                $increment = (int) $step->fetchColumn();
                $later = $increment < 0 ? 'LEAST' : 'GREATEST';
                $pdo->prepare(
                    'SELECT setval(CAST(? AS regclass), ' .
                        $later .
                        '(CAST(? AS bigint) + CASE WHEN CAST(? AS boolean) THEN CAST(? AS bigint) ELSE 0 END,
                                   s.last_value + CASE WHEN s.is_called THEN CAST(? AS bigint) ELSE 0 END), false)
                       FROM ' .
                        $sequenceName .
                        ' s',
                )->execute([$sequenceName, (string) $sequence['last_value'], $sequence['is_called'] ? 'true' : 'false', (string) $increment, (string) $increment]);
            }
            // The deferred keys checked now, while their tables are whole: ALTER TABLE refuses a table with checks pending.
            $pdo->exec('SET CONSTRAINTS ALL IMMEDIATE');
            foreach ($qualified as $table) {
                $pdo->exec('ALTER TABLE ' . $table . ' ENABLE TRIGGER USER');
            }
            foreach ($tables as $table) {
                $count = (int) $pdo->query('SELECT count(*) FROM ' . $shape->qualified($table['name']))->fetchColumn();
                if ($count !== (int) $table['rows']) {
                    throw new RuntimeException('The table ' . $table['name'] . ' holds ' . $count . ' rows after the load, where the backup has ' . $table['rows'] . '.');
                }
            }
            // What must be done again before anybody sees these rows - the deletes done since the backup - is
            // done here, the locks still held: a request that dies after the commit leaves nothing undone, and no
            // backup is ever made of the rows without it.
            if ($this->beforeCommit !== null) {
                $this->runBeforeCommit($manifest, $pdo);
            }
            $committing = true;
            $pdo->commit();
        } catch (Throwable $e) {
            // The database ended the connection - its transaction went with it, rolled back: nothing of the restore
            // stayed. Unless it went while the COMMIT was on its way, which may have been done.
            if (Connection::lost($pdo)) {
                throw $committing
                    ? new RestoreEnded('Its connection to the database was lost while it committed: ' . $e->getMessage(), 0, $e)
                    : new RuntimeException(sprintf(self::ENDED, self::seconds($this->idleMilliseconds)), 0, $e);
            }
            if ($pdo->inTransaction()) {
                try {
                    $pdo->rollBack();
                } catch (Throwable) {
                    // What failed first is what is said.
                }
            }
            throw $e;
        }

        return ['tables' => count($tables), 'rows' => $rows];
    }

    /** A wait in milliseconds as the restore's words say it: "a minute", "5 seconds". */
    private static function seconds(int $milliseconds): string
    {
        $seconds = max(1, intdiv($milliseconds + 999, 1000));

        return match (true) {
            $seconds === 60 => 'a minute',
            $seconds === 1 => 'a second',
            default => $seconds . ' seconds',
        };
    }

    /**
     * The site's beforeCommit, behind a savepoint of the restore's: a hook that ended the transaction - a commit
     * or a rollback, a new one begun after it or not - leaves the savepoint gone, and is told apart from a hook
     * that failed inside it (RestoreEnded: what the restore had loaded may have been committed with it), and from
     * a connection the database ended meanwhile (load() says so: nothing of the restore stayed).
     *
     * @param array<string, mixed> $manifest
     */
    private function runBeforeCommit(array $manifest, PDO $pdo): void
    {
        $pdo->exec('SAVEPOINT ' . self::BEFORE_COMMIT_SAVEPOINT);
        try {
            ($this->beforeCommit)($manifest, $pdo);
        } catch (Throwable $e) {
            if (!self::savepointHeld($pdo, 'ROLLBACK TO') && !Connection::lost($pdo)) {
                throw new RestoreEnded('Its beforeCommit ended the restore\'s transaction, then failed: ' . $e->getMessage(), 0, $e);
            }
            throw $e;
        }
        if (!self::savepointHeld($pdo, 'RELEASE')) {
            if (Connection::lost($pdo)) {
                throw new RuntimeException('The database ended the restore\'s connection while its beforeCommit ran.');
            }
            throw new RestoreEnded('Its beforeCommit ended the restore\'s transaction - a hook inside a restore begins, commits or rolls back none of its own (a savepoint is fine).');
        }
    }

    /** Whether the restore's savepoint is still there: $how (RELEASE, ROLLBACK TO) it, or fail. */
    private static function savepointHeld(PDO $pdo, string $how): bool
    {
        if (!$pdo->inTransaction()) {
            return false;
        }
        try {
            $pdo->exec($how . ' SAVEPOINT ' . self::BEFORE_COMMIT_SAVEPOINT);

            return true;
        } catch (\PDOException) {
            return false;
        }
    }

    /**
     * What keeps a restore from working here, whatever the backup: tables or sequences the site's database
     * role does not own, a table kept (never restored) with a foreign key into a restored one (TRUNCATE
     * refuses it), no COPY.
     *
     * @return list<string>
     */
    private function restoreProblems(PDO $pdo, DatabaseShape $shape): array
    {
        $problems = [];
        if (!Copy::available($pdo)) {
            $problems[] = 'This PHP cannot COPY through PDO (pdo_pgsql).';
        }
        if (!DatabaseShape::ownsEverything($pdo, $this->schema)) {
            $problems[] = 'The site\'s database role does not own every table and sequence, so it cannot empty and refill them.';
        }
        $kept = $this->keptTables($shape);
        foreach ($shape->keys as $reference) {
            if (in_array($reference['from'], $kept, true) && !in_array($reference['to'], $kept, true)) {
                $problems[] = "The table {$reference['from']}, which a restore keeps as it is, refers to {$reference['to']}, which a restore replaces: keep both, or neither.";
            }
        }

        return $problems;
    }

    /** @return list<string> the tables a restore never touches that the database has */
    private function keptTables(DatabaseShape $shape): array
    {
        $names = array_column($shape->tables, 'name');

        return array_values(array_intersect($names, array_merge($this->bookkeeping, $this->keep)));
    }

    /**
     * @return array{migrations: list<array{set: string, name: string}>, migrations_sha256: string, columns_sha256: string, shape: DatabaseShape}
     */
    private function liveSchema(PDO $pdo): array
    {
        $shape = DatabaseShape::read($pdo, $this->schema);
        $records = self::records(($this->migrator)($pdo));

        return [
            'migrations' => $records,
            'migrations_sha256' => self::migrationsSha256($records),
            'columns_sha256' => $shape->columnsSha256,
            'shape' => $shape,
        ];
    }

    /**
     * @param array<string, mixed> $backup  a header's or manifest's schema
     * @param array<string, mixed> $live
     * @return array{equal: bool, columns_equal: bool, only_in_backup: list<string>, only_here: list<string>}
     */
    private static function schemaDifference(array $backup, array $live): array
    {
        $names = static fn(array $records): array => array_map(static fn(array $record): string => $record['set'] . '/' . $record['name'], $records);
        $theirs = $names(is_array($backup['migrations'] ?? null) ? $backup['migrations'] : []);
        $ours = $names($live['migrations']);
        $columns = ($backup['columns_sha256'] ?? null) === $live['columns_sha256'];
        $onlyThere = array_values(array_diff($theirs, $ours));
        $onlyHere = array_values(array_diff($ours, $theirs));

        return [
            'equal' => $columns && $onlyThere === [] && $onlyHere === [] && ($backup['migrations_sha256'] ?? null) === $live['migrations_sha256'],
            'columns_equal' => $columns,
            'only_in_backup' => $onlyThere,
            'only_here' => $onlyHere,
        ];
    }

    // ─── Inside: the rest ────────────────────────────────────────────────────

    /** @return ?array<string, mixed> a backup's entry - its sidecar, else its header */
    private function info(string $id): ?array
    {
        $path = $this->path($id);
        if (!is_file($path)) {
            return null;
        }
        $size = (int) @filesize($path);
        $sidecar = json_decode((string) @file_get_contents($path . '.json'), true);
        if (is_array($sidecar) && ($sidecar['id'] ?? null) === $id) {
            $info = $sidecar;
        } else {
            $header = Archive::header($path);
            if ($header === null || ($header['id'] ?? null) !== $id) {
                return null;
            }
            unset($header['cipher']);
            $info = $header + ['status' => 'unlisted', 'file' => basename($path), 'created_by' => null, 'description' => null];
        }
        $info['size'] = $size;
        if (!in_array($info['kind'] ?? null, self::KINDS, true)) {
            preg_match('/Z-(' . implode('|', self::KINDS) . ')-/', $id, $kind);
            $info['kind'] = $kind[1] ?? self::MANUAL;
        }
        $info['stale'] = $info['kind'] === self::MANUAL && is_string($info['created_at'] ?? null) && strtotime($info['created_at']) < time() - $this->staleManualDays * 86400;
        // Made here: encrypted under the site's key (its cipher refuses any other), or a plain one whose sidecar
        // this installation sealed (the class). A site without a key made no encrypted backup; a seal that cannot
        // be checked (no installation key) is no seal.
        $seal = $info['seal'] ?? null;
        unset($info['seal']);
        $encrypted = ($info['encrypted'] ?? false) === true;
        $expected = !$encrypted && is_string($seal) && is_string($info['sha256'] ?? null) ? $this->seal($id, $info['sha256']) : null;
        $info['sealed'] = ($encrypted && $this->encryption !== null) || ($expected !== null && hash_equals($expected, (string) $seal));

        return $info;
    }

    /**
     * This installation's seal of a backup: an HMAC of its id and its SHA-256, under a key of its own (Keys) -
     * null when the installation's key can be neither read nor made (asked once: every backup listed would
     * ask again otherwise).
     */
    private function seal(string $id, string $sha256): ?string
    {
        if ($this->sealKey === null && !$this->sealKeyMissing) {
            try {
                $this->sealKey = Keys::derive(Keys::installation($this->storage), 'backup-seal');
            } catch (Throwable) {
                $this->sealKeyMissing = true;
            }
        }

        return $this->sealKey === null ? null : hash_hmac('sha256', $id . "\n" . $sha256, $this->sealKey);
    }

    /** A backup to restore here: encrypted under the site's key, or sealed by this installation. @param array<string, mixed> $info */
    private function sealedOrEncrypted(array $info): bool
    {
        return ($info['sealed'] ?? false) === true;
    }

    /** A lock file of the backups folder, so one backup or restore at a time; a refusal when another holds it. */
    private function hold(): mixed
    {
        if (!GuardedDirectory::ensure($this->directory)) {
            return new Refusal(500, 'storage_unavailable', 'The backups folder cannot be written.');
        }
        $handle = @fopen($this->directory . '/.lock', 'c');
        if ($handle === false) {
            return new Refusal(500, 'storage_unavailable', 'The backups folder cannot be written.');
        }
        @chmod($this->directory . '/.lock', 0600);
        if (!flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);

            return self::busy();
        }
        // Nothing else of the folder runs while the lock is held: what a timeout or a kill left goes now.
        $this->sweep();

        return $handle;
    }

    /**
     * The working folders a backup or a restore left behind (a timeout, a kill - `finally` never ran) go,
     * once nothing in them changed for an hour. Only folders of the library's own names.
     */
    private function sweep(): void
    {
        foreach (@scandir($this->directory) ?: [] as $name) {
            if (preg_match('/^\.tmp-[A-Za-z0-9-]{8,120}$/D', $name) !== 1) {
                continue;
            }
            $path = $this->directory . '/' . $name;
            if (is_dir($path) && !is_link($path) && self::lastChanged($path) < time() - 3600) {
                self::removeTree($path);
            }
        }
    }

    /** The newest change in a folder, its own or of anything in it. */
    private static function lastChanged(string $path): int
    {
        $newest = (int) @filemtime($path);
        foreach (@scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $inner = $path . '/' . $entry;
            $newest = max($newest, is_dir($inner) && !is_link($inner) ? self::lastChanged($inner) : (int) @filemtime($inner));
        }

        return $newest;
    }

    /** A run's own working folder - made new, guarded, open to the site's own user alone. */
    private static function workingFolder(string $path): bool
    {
        if (file_exists($path) || !@mkdir($path, 0700, true)) {
            return false;
        }
        @chmod($path, 0700);

        return GuardedDirectory::ensure($path);
    }

    /** @param resource $handle */
    private function release($handle): void
    {
        flock($handle, LOCK_UN);
        fclose($handle);
    }

    private function tryLock(PDO $pdo): bool
    {
        $statement = $pdo->prepare('SELECT CASE WHEN pg_try_advisory_lock(CAST(? AS bigint)) THEN 1 ELSE 0 END');
        $statement->execute([$this->lock]);

        return (int) $statement->fetchColumn() === 1;
    }

    private function unlock(PDO $pdo): void
    {
        try {
            $pdo->prepare('SELECT pg_advisory_unlock(CAST(? AS bigint))')->execute([$this->lock]);
        } catch (Throwable) {
            // The connection's end lets it go.
        }
    }

    private function spaceRefusal(): ?Refusal
    {
        $free = $this->freeBytes();
        $last = $this->list()[0]['size'] ?? null;
        if ($free !== null && is_int($last) && $free < 2 * $last) {
            return new Refusal(507, 'no_space', 'The disk has less free space than twice the last backup: delete an old backup first.');
        }

        return null;
    }

    private function freeBytes(): ?int
    {
        if (!function_exists('disk_free_space')) {
            return null;
        }
        $free = @disk_free_space(is_dir($this->directory) ? $this->directory : dirname($this->directory));

        return is_float($free) || is_int($free) ? (int) $free : null;
    }

    private function later(Closure $work): void
    {
        if ($this->defer !== null) {
            ($this->defer)($work);

            return;
        }
        $work();
    }

    /**
     * Everything the database has recorded as applied - with the files no longer on disk - by set and name.
     *
     * @return list<array{set: string, name: string}>
     */
    private static function records(Migrator $migrator): array
    {
        $status = $migrator->status();
        $records = [];
        foreach (array_merge($status['applied'], $status['missing']) as $record) {
            $records[] = ['set' => $record['set'], 'name' => $record['name']];
        }

        return $records;
    }

    /** @param list<array{set: string, name: string}> $records */
    private static function migrationsSha256(array $records): string
    {
        $names = array_map(static fn(array $record): string => $record['set'] . '/' . $record['name'], $records);
        sort($names, SORT_STRING);

        return hash('sha256', implode("\n", $names));
    }

    /** @return array{bytes: int, sha256: string, rows: int} a COPY file's size, hash and rows (its lines) */
    private static function fileFacts(string $path): array
    {
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            throw new RuntimeException('A part of the backup could not be read back.');
        }
        $hash = hash_init('sha256');
        $bytes = 0;
        $rows = 0;
        try {
            while (!feof($handle)) {
                $piece = fread($handle, 1048576);
                if ($piece === false) {
                    throw new RuntimeException('A part of the backup could not be read back.');
                }
                hash_update($hash, $piece);
                $bytes += strlen($piece);
                $rows += substr_count($piece, "\n");
            }
        } finally {
            fclose($handle);
        }

        return ['bytes' => $bytes, 'sha256' => hash_final($hash), 'rows' => $rows];
    }

    /** A name as a part of a file name in the archive: letters, digits, . _ -; never starting with a dot. */
    private static function fileName(string $name, int $limit): string
    {
        $safe = (string) preg_replace('/[^A-Za-z0-9._-]/', '_', $name);
        $safe = ltrim($safe, '.');

        return substr($safe === '' ? '_' : $safe, 0, $limit);
    }

    private static function placeUnder(string $work, string $name): string
    {
        $path = $work . '/' . $name;
        $directory = dirname($path);
        if (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new RuntimeException('Its working folder cannot be made.');
        }

        return $path;
    }

    /**
     * @param array<string, mixed> $info
     * @return array<string, mixed>
     */
    private static function auditDetails(array $info): array
    {
        return array_filter(
            [
                'kind' => $info['kind'] ?? null,
                'size' => $info['size'] ?? null,
                'tables' => $info['table_count'] ?? null,
                'rows' => $info['rows'] ?? null,
                'encrypted' => $info['encrypted'] ?? null,
                'description' => $info['description'] ?? null,
            ],
            static fn(mixed $value): bool => $value !== null,
        );
    }

    private static function libraryVersion(): ?string
    {
        if (!class_exists(\Composer\InstalledVersions::class)) {
            return null;
        }
        try {
            return \Composer\InstalledVersions::getPrettyVersion('anotoki/lib');
        } catch (Throwable) {
            return null;
        }
    }

    private static function busy(): Refusal
    {
        return new Refusal(409, 'busy', 'Another backup, restore or database update is running. Wait a moment, then try again.');
    }

    private static function notFound(): Refusal
    {
        return new Refusal(404, 'not_found', 'There is no such backup.');
    }

    private static function removeTree(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            @unlink($path);

            return;
        }
        if (!is_dir($path)) {
            return;
        }
        foreach (@scandir($path) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                self::removeTree($path . '/' . $entry);
            }
        }
        @rmdir($path);
    }
}
