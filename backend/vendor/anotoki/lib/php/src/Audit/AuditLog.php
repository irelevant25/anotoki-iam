<?php

declare(strict_types=1);

namespace Anotoki\Lib\Audit;

use Anotoki\Lib\Admin\Schema;
use Anotoki\Lib\Support\AtomicFile;
use Anotoki\Lib\Support\ClientAddress;
use Anotoki\Lib\Support\GuardedDirectory;
use Anotoki\Lib\Support\Log;
use Anotoki\Lib\Support\Utf8;
use Closure;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use InvalidArgumentException;
use LogicException;
use PDO;
use Psr\Http\Message\ServerRequestInterface;
use RuntimeException;
use Throwable;

/**
 * The record of what was done on a site - every admin change, and the
 * security-relevant changes people make to themselves - in the library's table
 * anotoki_audit_log (Admin\Schema, 001): the IAM's audit() made general.
 *
 * record() never throws: a change that was made must not be undone by its
 * entry failing to write. Inside the caller's transaction it writes behind a
 * savepoint, so a failed insert leaves the transaction as it was instead of
 * making PostgreSQL refuse every statement after it (genshin's writer
 * swallowed the error and poisoned the change). While the table is missing - a
 * build uploaded before its migrations are applied - an entry waits in a file
 * (pendingFile, appended whole) and goes into the table with its own time when
 * the table comes: flushPending(), which the site calls after every Apply, and
 * which the next entry recorded outside a transaction calls too.
 *
 * recordInside() is the other way round, for a change that must not be made
 * without its entry (the People page's delete, which a restore does again by
 * it): inside the change's own transaction, thrown when it cannot be written,
 * never left to wait in the file.
 *
 * The request's actor, session and address can be held for the request
 * (AuditContextMiddleware, begin()), so deep code records with recordHere();
 * withScope() files every entry meanwhile under what it belongs to (genshin's
 * withAuditScope(): a character's talents under the character).
 */
final class AuditLog implements AuditRecorder
{
    /** The most details one entry keeps, as JSON: a longer value is cut, with a mark. */
    public const DETAILS_LIMIT = 65536;

    public const ACTION_PATTERN = '/^[a-z][a-z0-9_]*(\.[a-z0-9_]+)+$/D';

    private const OPTIONS = ['recordAddress', 'address', 'pendingFile', 'ready', 'personTargetType', 'actorIds', 'onError', 'trustedProxies', 'clientHeader', 'originSecret'];

    private readonly bool $recordAddress;
    private readonly ?Closure $address;
    /** @var list<string> */
    private readonly array $trustedProxies;
    private readonly string $clientHeader;
    private readonly ?string $originSecret;
    private readonly ?string $pendingFile;
    private readonly ?Closure $ready;
    private readonly string $personTargetType;
    private readonly ?Closure $actorIds;
    private readonly Closure $onError;

    /** @var list<AuditContext> */
    private array $contexts = [];

    /** @var list<array{string, string}> scopes outside any context */
    private array $scopes = [];

    /**
     * @param Closure(): PDO $pdo  the site's connection - the one its transactions run on, so an entry
     *                             commits (or not) with the change it records
     * @param array{
     *   recordAddress?: bool,
     *   address?: Closure(ServerRequestInterface): ?string,
     *   pendingFile?: string,
     *   ready?: Closure(PDO): bool,
     *   personTargetType?: string,
     *   actorIds?: Closure(string): list<int|string>,
     *   onError?: Closure(string): void,
     *   trustedProxies?: list<string>,
     *   clientHeader?: string,
     *   originSecret?: string,
     * } $options
     *   - recordAddress: keep the requester's address (the IAM does; the sites do not), from `address` -
     *     the site's own reading - or by ClientAddress's rule: the connection's REMOTE_ADDR, or the
     *     `clientHeader` (CF-Connecting-IP) of a connection from one of `trustedProxies` - with an
     *     `originSecret` (0.5.2), only on a request that carries it (ClientAddress::ORIGIN_HEADER)
     *   - pendingFile: where entries wait while the table is missing (storage/logs/audit-pending.jsonl);
     *     without one they go to `onError`
     *   - ready: whether entries may go into the table now (default: it exists). The IAM: only once its
     *     own migration has moved the old rows in
     *   - personTargetType: the target type of a person, for scrubPeople() ('user')
     *   - actorIds: a name typed into the page's actor filter, as the ids it may mean
     *   - onError: told that an entry could not be written - a fixed sentence and a code (the SQLSTATE, or
     *     the failure's class), never the entry, its details or address, nor the database's words, which
     *     quote values (default: the library's log line, Support\Log - PHP's error log unless the site sends
     *     the lines elsewhere)
     */
    public function __construct(private readonly Closure $pdo, array $options = [])
    {
        $unknown = array_diff(array_map('strval', array_keys($options)), self::OPTIONS);
        if ($unknown !== []) {
            throw new InvalidArgumentException('Unknown AuditLog option: ' . implode(', ', $unknown) . '.');
        }
        $this->recordAddress = (bool) ($options['recordAddress'] ?? false);
        $this->address = $options['address'] ?? null;
        $this->trustedProxies = array_values(array_filter($options['trustedProxies'] ?? [], 'is_string'));
        $this->clientHeader = (string) ($options['clientHeader'] ?? ClientAddress::CLOUDFLARE_HEADER);
        $secret = $options['originSecret'] ?? null;
        $this->originSecret = is_string($secret) && $secret !== '' ? $secret : null;
        $pending = $options['pendingFile'] ?? null;
        $this->pendingFile = is_string($pending) && $pending !== '' ? $pending : null;
        $this->ready = $options['ready'] ?? null;
        $this->personTargetType = (string) ($options['personTargetType'] ?? 'user');
        $this->actorIds = $options['actorIds'] ?? null;
        $this->onError = $options['onError'] ?? static function (string $message): void {
            Log::say($message);
        };
    }

    // ─── Writing ─────────────────────────────────────────────────────────────

    /**
     * One entry. Never throws.
     *
     * @param array<string, mixed> $details  what changed (at most 64 KB as JSON)
     * @param ?array{0: string, 1: int|string} $scope  [type, id] it belongs to; default the current withScope()
     * @param ?string $session  default the request's held session (AuditContextMiddleware)
     */
    public function record(
        ?ServerRequestInterface $request,
        int|string|null $actorId,
        string $action,
        ?string $targetType = null,
        int|string|null $targetId = null,
        array $details = [],
        ?array $scope = null,
        ?string $session = null,
    ): void {
        try {
            if (preg_match(self::ACTION_PATTERN, $action) !== 1 || strlen($action) > 64) {
                ($this->onError)("[audit] not recorded: \"$action\" is no action name (area.verb, small letters)");

                return;
            }
            $entry = $this->entry($request, $actorId, $action, $targetType, $targetId, $details, $scope, $session);

            $pdo = ($this->pdo)();
            if (!$this->tableReady($pdo)) {
                $this->keepPending($entry);

                return;
            }
            if (!$pdo->inTransaction() && $this->pendingFile !== null && @filesize($this->pendingFile) > 0) {
                $this->flushPending();
            }
            $this->insert($pdo, $entry);
        } catch (Throwable $e) {
            ($this->onError)('[audit] an entry could not be written (' . self::failureCode($e) . ').');
        }
    }

    /**
     * One entry that must be there: written on $pdo inside the transaction it is in - so it commits, or rolls
     * back, with the change it records - and THROWN when it cannot be written, never kept in the pending file: a
     * change that must not be made without its entry fails with it (the People page's delete, which a restore
     * does again by its entry - People::delete()). Its details name its transaction (`_txid`: txid_current()),
     * whatever their cut: its created_at is when that transaction began, not when it committed, and a backup
     * holds the change exactly when its snapshot sees that transaction.
     *
     * @param array<string, mixed> $details
     * @throws LogicException when $pdo is in no transaction
     * @throws InvalidArgumentException for an action that is no action name
     * @throws RuntimeException while the table is not there (or `ready` says not yet); the insert's failure as it is
     */
    public function recordInside(
        PDO $pdo,
        ?ServerRequestInterface $request,
        int|string|null $actorId,
        string $action,
        ?string $targetType = null,
        int|string|null $targetId = null,
        array $details = [],
    ): void {
        if (!$pdo->inTransaction()) {
            throw new LogicException('AuditLog::recordInside() writes inside the transaction of the change it records: the connection is in none.');
        }
        if (preg_match(self::ACTION_PATTERN, $action) !== 1 || strlen($action) > 64) {
            throw new InvalidArgumentException("AuditLog: \"$action\" is no action name (area.verb, small letters).");
        }
        if (!$this->tableReady($pdo)) {
            throw new RuntimeException('The audit log is not there yet (a database update waits): what must be recorded in it is not done without it.');
        }
        $entry = $this->entry($request, $actorId, $action, $targetType, $targetId, $details, null, null);
        $pdo->prepare(
            'INSERT INTO ' . Schema::AUDIT_TABLE . ' (created_at, actor_id, actor_session, action, target_type, target_id, scope_type, scope_id, details, ip)
             VALUES (now(), ?, ?, ?, ?, ?, ?, ?, COALESCE(CAST(? AS jsonb), CAST(\'{}\' AS jsonb)) || jsonb_build_object(\'_txid\', CAST(txid_current() AS text)), ?)'
        )->execute([
            $entry['actor_id'], $entry['actor_session'], $entry['action'], $entry['target_type'], $entry['target_id'],
            $entry['scope_type'], $entry['scope_id'], $entry['details'], $entry['ip'],
        ]);
    }

    /**
     * An entry by whoever acts in the request being answered (AuditContextMiddleware,
     * begin()) - for code that is not handed the request. Never throws.
     *
     * @param array<string, mixed> $details
     * @param ?array{0: string, 1: int|string} $scope
     */
    public function recordHere(string $action, ?string $targetType = null, int|string|null $targetId = null, array $details = [], ?array $scope = null): void
    {
        $context = $this->current();
        $this->record($context?->request, $context?->actorId, $action, $targetType, $targetId, $details, $scope, $context?->session);
    }

    /** Holds who acts from now on, until end() - for a site without the middleware (a command line). */
    public function begin(?ServerRequestInterface $request, int|string|null $actorId, ?string $session = null): AuditContext
    {
        return $this->contexts[] = new AuditContext($request, $actorId, $session);
    }

    /** Ends what begin() held. */
    public function end(AuditContext $context): void
    {
        $index = array_search($context, $this->contexts, true);
        if ($index !== false) {
            array_splice($this->contexts, (int) $index, 1);
        }
    }

    /** Who acts in the request being answered, if anybody holds that. */
    public function current(): ?AuditContext
    {
        return $this->contexts === [] ? null : $this->contexts[count($this->contexts) - 1];
    }

    /**
     * Runs $work with every entry recorded meanwhile filed under [$type, $id]
     * (unless it names its own scope); an inner scope wins over an outer one.
     *
     * @template T
     * @param callable(): T $work
     * @return T
     */
    public function withScope(string $type, int|string $id, callable $work): mixed
    {
        $context = $this->current();
        if ($context !== null) {
            $context->scopes[] = [$type, (string) $id];
        } else {
            $this->scopes[] = [$type, (string) $id];
        }
        try {
            return $work();
        } finally {
            if ($context !== null) {
                array_pop($context->scopes);
            } else {
                array_pop($this->scopes);
            }
        }
    }

    /**
     * The entries that waited in the pending file, put into the table with the
     * times they were recorded, in one transaction. Answers how many went in (0
     * while the table is still missing, or when there were none). Never throws.
     * Call it after every Apply (MigrationsRoutes' afterApply) and from the site's
     * command line.
     *
     * The file is read, its entries put in and the file emptied under its own
     * lock, which keepPending() takes too: an entry written meanwhile waits for
     * the lock and lands in the same file after it was emptied - never in a file
     * set aside and gone (a rename would let a writer that opened the file first
     * append to that one). Each entry goes in only where no entry of its time,
     * action, actor and target is there yet, so a process killed after the
     * commit and before the file was emptied leaves entries the next flush puts
     * in without a second copy of any. The file stays, empty.
     */
    public function flushPending(): int
    {
        if ($this->pendingFile === null) {
            return 0;
        }
        clearstatcache(true, $this->pendingFile);
        if (!is_file($this->pendingFile) || @filesize($this->pendingFile) === 0) {
            return 0;
        }
        try {
            $pdo = ($this->pdo)();
            if ($pdo->inTransaction() || !$this->tableReady($pdo)) {
                return 0;
            }
            $handle = @fopen($this->pendingFile, 'c+');
            if ($handle === false) {
                return 0;
            }
            try {
                if (!flock($handle, LOCK_EX)) {
                    return 0;
                }
                $flushed = $this->insertWaiting($pdo, (string) stream_get_contents($handle));
                ftruncate($handle, 0);
                fflush($handle);

                return $flushed;
            } finally {
                flock($handle, LOCK_UN);
                fclose($handle);
            }
        } catch (Throwable $e) {
            ($this->onError)('[audit] the waiting entries could not be written (' . self::failureCode($e) . '); they wait on.');

            return 0;
        }
    }

    /**
     * The entries of $text (JSON lines), each put in where none of its time, action, actor and target is there
     * yet - in one transaction. Answers how many went in.
     */
    private function insertWaiting(PDO $pdo, string $text): int
    {
        $entries = [];
        foreach (preg_split('/\R/', $text) ?: [] as $line) {
            $entry = json_decode($line, true);
            if (is_array($entry) && is_string($entry['action'] ?? null) && is_string($entry['created_at'] ?? null)) {
                $entries[] = $entry;
            }
        }
        if ($entries === []) {
            return 0;
        }
        $inserted = 0;
        $pdo->beginTransaction();
        try {
            foreach ($entries as $entry) {
                $known = $pdo->prepare(
                    'SELECT 1 FROM ' . Schema::AUDIT_TABLE . ' WHERE created_at = CAST(? AS timestamptz) AND action = ?
                        AND actor_id IS NOT DISTINCT FROM CAST(? AS bigint) AND target_type IS NOT DISTINCT FROM CAST(? AS varchar)
                        AND target_id IS NOT DISTINCT FROM CAST(? AS varchar) LIMIT 1'
                );
                $known->execute([
                    $entry['created_at'], $entry['action'], isset($entry['actor_id']) ? (int) $entry['actor_id'] : null,
                    $entry['target_type'] ?? null, $entry['target_id'] ?? null,
                ]);
                if ($known->fetchColumn() === false) {
                    $this->insert($pdo, $entry, false);
                    $inserted++;
                }
            }
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        return $inserted;
    }

    // ─── Reading ─────────────────────────────────────────────────────────────

    /** Whether entries go into the table now (it is there, and the site says so). Asked afresh every time. */
    public function ready(): bool
    {
        try {
            return $this->tableReady(($this->pdo)());
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * A page of entries, newest first: {total, page, pageSize, items}. An item:
     * {id, created_at (ISO 8601 UTC), actor_id, actor_session, action, target_type,
     * target_id, scope_type, scope_id, details (decoded), ip}.
     *
     * Filters: actor (an id, or a name the site's actorIds closure resolves), action (its
     * beginning: "account." finds account.*), target_type, target_id, scope_type, scope_id,
     * from, to (a date - to is the whole of its day - or an ISO 8601 time).
     *
     * @param array<string, mixed> $filter
     * @return array{total: int, page: int, pageSize: int, items: list<array<string, mixed>>}
     */
    public function page(array $filter = [], int $page = 1, int $size = 50): array
    {
        $page = max(1, $page);
        $size = max(1, min(200, $size));
        [$where, $parameters] = $this->where($filter);
        $pdo = ($this->pdo)();

        $count = $pdo->prepare('SELECT count(*) FROM ' . Schema::AUDIT_TABLE . $where);
        $count->execute($parameters);
        $total = (int) $count->fetchColumn();

        $rows = $pdo->prepare(
            'SELECT id, ' . self::isoColumn('created_at') . ' AS created_at, actor_id, actor_session, action, target_type, target_id, scope_type, scope_id, details::text AS details, ip
               FROM ' . Schema::AUDIT_TABLE . $where . ' ORDER BY created_at DESC, id DESC LIMIT ' . $size . ' OFFSET ' . (($page - 1) * $size)
        );
        $rows->execute($parameters);

        return ['total' => $total, 'page' => $page, 'pageSize' => $size, 'items' => array_map(self::item(...), $rows->fetchAll(PDO::FETCH_ASSOC))];
    }

    /**
     * What the page's filters offer: {actions, target_types, scope_types, actor_ids} - each at most 500.
     *
     * @return array{actions: list<string>, target_types: list<string>, scope_types: list<string>, actor_ids: list<int>}
     */
    public function facets(): array
    {
        $pdo = ($this->pdo)();
        // In the bytes' order, whatever the database's collation (a Slovak one sorts "ch" after "h").
        $list = static fn (string $column, string $order = ' COLLATE "C"'): array => array_map(
            'strval',
            $pdo->query('SELECT DISTINCT ' . $column . $order . ' FROM ' . Schema::AUDIT_TABLE . ' WHERE ' . $column . ' IS NOT NULL ORDER BY 1 LIMIT 500')->fetchAll(PDO::FETCH_COLUMN)
        );

        return [
            'actions' => $list('action'),
            'target_types' => $list('target_type'),
            'scope_types' => $list('scope_type'),
            'actor_ids' => array_map('intval', $list('actor_id', '')),
        ];
    }

    /** How many entries since $since - for a dashboard. */
    public function countSince(DateTimeInterface $since): int
    {
        $statement = ($this->pdo)()->prepare('SELECT count(*) FROM ' . Schema::AUDIT_TABLE . ' WHERE created_at >= CAST(? AS timestamptz)');
        $statement->execute([$since->format(DateTimeInterface::ATOM)]);

        return (int) $statement->fetchColumn();
    }

    /**
     * The newest $count entries - for a dashboard.
     *
     * @return list<array<string, mixed>>
     */
    public function latest(int $count = 12): array
    {
        return $this->page([], 1, max(1, min(200, $count)))['items'];
    }

    // ─── People ──────────────────────────────────────────────────────────────

    /**
     * Scrubs what the log says of people whose accounts were anonymized - the
     * IAM's rules, made general (scrubAuditLog()); the events themselves stay:
     *   1. the address goes from every entry by them, and from every entry about
     *      them (target personTargetType + their id) that nobody else did;
     *   2. the entries about them lose these keys of their details (a username's
     *      change, an account's making).
     * Answers how many entries changed. Throws on a database error (the site's
     * anonymization runs it in its own transaction).
     *
     * @param list<int|string> $ids
     * @param list<string> $personKeys
     */
    public function scrubPeople(array $ids, array $personKeys = ['from', 'to', 'username', 'email', 'name', 'address']): int
    {
        $ids = array_values(array_unique(array_map('strval', array_filter($ids, static fn (mixed $id): bool => (is_int($id) && $id >= 0) || (is_string($id) && ctype_digit($id) && strlen($id) <= 18)))));
        if ($ids === []) {
            return 0;
        }
        $idArray = '{' . implode(',', $ids) . '}';
        $keys = '{' . implode(',', array_map(static fn (string $key): string => '"' . addcslashes($key, '"\\') . '"', $personKeys)) . '}';
        $pdo = ($this->pdo)();

        $addresses = $pdo->prepare(
            'UPDATE ' . Schema::AUDIT_TABLE . ' SET ip = NULL
              WHERE ip IS NOT NULL
                AND (actor_id = ANY (CAST(? AS bigint[]))
                     OR (actor_id IS NULL AND target_type = ? AND target_id = ANY (CAST(? AS text[]))))'
        );
        $addresses->execute([$idArray, $this->personTargetType, $idArray]);
        $changed = $addresses->rowCount();

        if ($personKeys !== []) {
            $details = $pdo->prepare(
                'UPDATE ' . Schema::AUDIT_TABLE . ' SET details = details - CAST(? AS text[])
                  WHERE target_type = ? AND target_id = ANY (CAST(? AS text[]))
                    AND jsonb_typeof(details) = \'object\' AND jsonb_exists_any(details, CAST(? AS text[]))'
            );
            $details->execute([$keys, $this->personTargetType, $idArray, $keys]);
            $changed += $details->rowCount();
        }

        return $changed;
    }

    // ─── Inside ──────────────────────────────────────────────────────────────

    /**
     * An entry's row, its time to come (the table's now(), or the time it waited with): who - the request's held
     * session unless $session says - did $action to what, filed under $scope or the current withScope().
     *
     * @param array<string, mixed> $details
     * @param ?array{0: string, 1: int|string} $scope
     * @return array<string, mixed>
     */
    private function entry(?ServerRequestInterface $request, int|string|null $actorId, string $action, ?string $targetType, int|string|null $targetId, array $details, ?array $scope, ?string $session): array
    {
        $context = $this->contextOf($request);
        $scope ??= $this->currentScope();

        return [
            'created_at' => null,
            'actor_id' => self::actor($actorId),
            'actor_session' => self::short($session ?? $context?->session, 64),
            'action' => $action,
            'target_type' => self::short($targetType, 64),
            'target_id' => self::short($targetId === null ? null : (string) $targetId, 100),
            'scope_type' => self::short(isset($scope[0]) ? (string) $scope[0] : null, 64),
            'scope_id' => self::short(isset($scope[1]) ? (string) $scope[1] : null, 100),
            'details' => $details === [] ? null : self::detailsJson($details),
            'ip' => $this->recordAddress && $request !== null ? $this->addressOf($request) : null,
        ];
    }

    /** @param array<string, mixed> $entry */
    private function insert(PDO $pdo, array $entry, bool $savepoint = true): void
    {
        $nested = $savepoint && $pdo->inTransaction();
        if ($nested) {
            $pdo->exec('SAVEPOINT anotoki_audit_log');
        }
        try {
            $statement = $pdo->prepare(
                'INSERT INTO ' . Schema::AUDIT_TABLE . ' (created_at, actor_id, actor_session, action, target_type, target_id, scope_type, scope_id, details, ip)
                 VALUES (COALESCE(CAST(? AS timestamptz), now()), ?, ?, ?, ?, ?, ?, ?, CAST(? AS jsonb), ?)'
            );
            $statement->execute([
                is_string($entry['created_at'] ?? null) ? $entry['created_at'] : null,
                isset($entry['actor_id']) ? (int) $entry['actor_id'] : null,
                $entry['actor_session'] ?? null,
                $entry['action'],
                $entry['target_type'] ?? null,
                $entry['target_id'] ?? null,
                $entry['scope_type'] ?? null,
                $entry['scope_id'] ?? null,
                $entry['details'] ?? null,
                $entry['ip'] ?? null,
            ]);
        } catch (Throwable $e) {
            if ($nested) {
                $pdo->exec('ROLLBACK TO SAVEPOINT anotoki_audit_log');
                $pdo->exec('RELEASE SAVEPOINT anotoki_audit_log');
            }
            throw $e;
        }
        if ($nested) {
            $pdo->exec('RELEASE SAVEPOINT anotoki_audit_log');
        }
    }

    /** @param array<string, mixed> $entry */
    private function keepPending(array $entry): void
    {
        $entry['created_at'] = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.u\Z');
        $line = json_encode($entry, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        if ($this->pendingFile === null || $line === false) {
            ($this->onError)('[audit] the table is not there yet, and an entry had nowhere to wait: it is lost (' . ($line === false ? 'not_encodable' : 'no_pending_file') . ').');

            return;
        }
        $directory = dirname($this->pendingFile);
        if (!is_dir($directory)) {
            GuardedDirectory::ensure($directory);
        }
        if (@file_put_contents($this->pendingFile, $line . "\n", FILE_APPEND | LOCK_EX) === false) {
            ($this->onError)('[audit] the table is not there yet, and an entry could not wait in its file: it is lost (pending_unwritable).');
        }
    }

    /** What failed, as a code a log line may carry: the SQLSTATE of a database's refusal, else the failure's class. */
    private static function failureCode(Throwable $e): string
    {
        $state = $e instanceof \PDOException ? ($e->errorInfo[0] ?? $e->getCode()) : null;
        if (is_string($state) && preg_match('/^[0-9A-Z]{5}$/D', $state) === 1) {
            return 'SQLSTATE ' . $state;
        }
        $class = $e::class;
        $slash = strrpos($class, '\\');

        return $slash === false ? $class : substr($class, $slash + 1);
    }

    private function tableReady(PDO $pdo): bool
    {
        if (!Schema::has($pdo, Schema::AUDIT_TABLE)) {
            return false;
        }

        return $this->ready === null || (bool) ($this->ready)($pdo);
    }

    private function contextOf(?ServerRequestInterface $request): ?AuditContext
    {
        $context = $request?->getAttribute(AuditContextMiddleware::ATTRIBUTE);

        return $context instanceof AuditContext ? $context : $this->current();
    }

    /** @return ?array{0: string, 1: string} */
    private function currentScope(): ?array
    {
        $context = $this->current();
        $scopes = $context !== null && $context->scopes !== [] ? $context->scopes : $this->scopes;

        return $scopes === [] ? null : $scopes[count($scopes) - 1];
    }

    private function addressOf(ServerRequestInterface $request): ?string
    {
        try {
            $address = $this->address !== null ? ($this->address)($request) : ClientAddress::of($request, $this->trustedProxies, $this->clientHeader, $this->originSecret);
        } catch (Throwable) {
            $address = null;
        }

        return is_string($address) && $address !== '' ? substr($address, 0, 45) : null;
    }

    /**
     * @param array<string, mixed> $filter
     * @return array{string, list<mixed>}
     */
    private function where(array $filter): array
    {
        $where = [];
        $parameters = [];

        $actor = is_scalar($filter['actor'] ?? null) ? trim((string) $filter['actor']) : '';
        if ($actor !== '') {
            if (ctype_digit($actor) && strlen($actor) <= 18) {
                $where[] = 'actor_id = ?';
                $parameters[] = (int) $actor;
            } else {
                $ids = $this->actorIds !== null ? array_values(array_filter(array_map('strval', ($this->actorIds)($actor)), 'ctype_digit')) : [];
                $where[] = $ids === [] ? 'FALSE' : 'actor_id = ANY (CAST(? AS bigint[]))';
                if ($ids !== []) {
                    $parameters[] = '{' . implode(',', $ids) . '}';
                }
            }
        }
        $action = is_string($filter['action'] ?? null) ? trim($filter['action']) : '';
        if ($action !== '') {
            // LIKE's escape character is the backslash already, so no ESCAPE '\' clause: before PHP 8.4, PDO's
            // reading of the SQL takes the \' in such a literal for an escaped quote and misses every ? after it.
            $where[] = 'action LIKE ?';
            $parameters[] = addcslashes($action, '%_\\') . '%';
        }
        foreach (['target_type', 'target_id', 'scope_type', 'scope_id'] as $column) {
            $value = is_scalar($filter[$column] ?? null) ? trim((string) $filter[$column]) : '';
            if ($value !== '') {
                $where[] = $column . ' = ?';
                $parameters[] = $value;
            }
        }
        foreach (['from' => '>=', 'to' => '<'] as $name => $operator) {
            $time = self::time($filter[$name] ?? null, $name === 'to');
            if ($time !== null) {
                $where[] = 'created_at ' . $operator . ' CAST(? AS timestamptz)';
                $parameters[] = $time;
            }
        }

        return [$where === [] ? '' : ' WHERE ' . implode(' AND ', $where), $parameters];
    }

    /** A date (the start of its day, or of the next for `to`) or an ISO 8601 time, in UTC; null for anything else. */
    private static function time(mixed $value, bool $endOfDay): ?string
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }
        $value = trim($value);
        try {
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value) === 1) {
                $day = new DateTimeImmutable($value . 'T00:00:00Z');

                return ($endOfDay ? $day->modify('+1 day') : $day)->format('Y-m-d\TH:i:s\Z');
            }
            if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(:\d{2}(\.\d{1,6})?)?(Z|[+-]\d{2}:?\d{2})$/D', $value) === 1) {
                return (new DateTimeImmutable($value))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z');
            }
        } catch (Throwable) {
            return null;
        }

        return null;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function item(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'created_at' => (string) $row['created_at'],
            'actor_id' => $row['actor_id'] === null ? null : (int) $row['actor_id'],
            'actor_session' => $row['actor_session'],
            'action' => (string) $row['action'],
            'target_type' => $row['target_type'],
            'target_id' => $row['target_id'],
            'scope_type' => $row['scope_type'],
            'scope_id' => $row['scope_id'],
            'details' => $row['details'] === null ? null : json_decode((string) $row['details'], true),
            'ip' => $row['ip'],
        ];
    }

    /** A TIMESTAMPTZ column as ISO 8601 UTC with milliseconds, whatever the session's zone. */
    public static function isoColumn(string $column): string
    {
        return 'to_char(' . $column . " AT TIME ZONE 'UTC', 'YYYY-MM-DD\"T\"HH24:MI:SS.MS\"Z\"')";
    }

    /**
     * The details as JSON, at most DETAILS_LIMIT bytes. What is too much is cut so that what it held still
     * shows: its texts shorter and its lists to their first items, every key kept, each cut said where it was
     * ("…[cut]", "…[N more]"); still too much, its keys alone - the shape of every map, four levels deep -
     * and last its first keys, as many as fit. A cut entry says `_cut: true`.
     *
     * @param array<string, mixed> $details
     */
    private static function detailsJson(array $details): string
    {
        $flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR;
        $json = (string) json_encode($details, $flags);
        if (strlen($json) <= self::DETAILS_LIMIT) {
            return $json;
        }
        foreach ([[4096, 200], [1024, 50], [256, 10], [64, 3]] as [$text, $items]) {
            $json = (string) json_encode(['_cut' => true] + self::shrink($details, $text, $items), $flags);
            if (strlen($json) <= self::DETAILS_LIMIT) {
                return $json;
            }
        }
        $json = (string) json_encode(['_cut' => true, 'keys' => self::keysOf($details, 4)], $flags);
        if (strlen($json) <= self::DETAILS_LIMIT) {
            return $json;
        }
        $keys = [];
        $size = 64;
        foreach (array_keys($details) as $key) {
            $size += strlen((string) json_encode((string) $key, $flags)) + 1;
            if ($size > self::DETAILS_LIMIT) {
                break;
            }
            $keys[] = (string) $key;
        }

        return (string) json_encode(['_cut' => true, 'keys' => $keys], $flags);
    }

    /** A value with its texts cut to $text characters and its lists to $items items - every map's keys kept. */
    private static function shrink(mixed $value, int $text, int $items): mixed
    {
        if (is_string($value)) {
            return Utf8::length($value) > $text ? Utf8::prefix($value, $text) . '…[cut]' : $value;
        }
        if (!is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            $kept = array_map(static fn (mixed $inner): mixed => self::shrink($inner, $text, $items), array_slice($value, 0, $items));
            if (count($value) > $items) {
                $kept[] = '…[' . (count($value) - $items) . ' more]';
            }

            return $kept;
        }

        return array_map(static fn (mixed $inner): mixed => self::shrink($inner, $text, $items), $value);
    }

    /** What a value held, as its keys: a map's keys with theirs, a list's length, no value. */
    private static function keysOf(mixed $value, int $depth): mixed
    {
        if (!is_array($value)) {
            return null;
        }
        if (array_is_list($value)) {
            return '[' . count($value) . ' items]';
        }
        if ($depth <= 1) {
            return array_map(static fn (): mixed => null, $value);
        }

        return array_map(static fn (mixed $inner): mixed => self::keysOf($inner, $depth - 1), $value);
    }

    private static function actor(int|string|null $actorId): ?int
    {
        if (is_int($actorId)) {
            return $actorId;
        }

        return is_string($actorId) && ctype_digit($actorId) && strlen($actorId) <= 18 ? (int) $actorId : null;
    }

    private static function short(?string $value, int $limit): ?string
    {
        return $value === null || $value === '' ? null : Utf8::prefix($value, $limit);
    }
}
