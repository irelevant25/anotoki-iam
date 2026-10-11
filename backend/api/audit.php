<?php

use Anotoki\Lib\Audit\AuditLog;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * The record of what was done: every admin change, and the security-relevant
 * changes an account makes to itself.
 *
 * Kept since anotoki-lib 0.4 in the anotoki family's table, anotoki_audit_log
 * (the library's set anotoki_admin, 001), written by the library's AuditLog:
 * one shape, one writer and one Audit page for every anotoki site, the IAM's
 * columns plus three the IAM leaves empty (actor_session, scope_type,
 * scope_id). audit() stays the IAM's one function every route calls, and
 * records what it always recorded: who, what, to what, the details, the
 * requester's address (requestIp(): the trusted proxy's header from a trusted
 * proxy, else the connection's) and the time.
 *
 * Never throws. A change that was made must not be undone by the log failing
 * to write; the failure goes to the PHP error log instead, in a fixed sentence
 * and a code - never the entry, its details or its address.
 *
 * Written inside the caller's transaction when there is one - an account's
 * deletion is asked for, and anonymized, with its entry in the same commit -
 * and then behind a savepoint, as the email log's row is (sendMail()): a
 * failed insert would otherwise leave PostgreSQL refusing every statement
 * after it, and so undo the change the entry was about.
 *
 * Migration 018 moved the IAM's own audit_log into the library's table, every
 * row with its id. Until it has run - a build is uploaded before Apply, and
 * the IAM keeps answering meanwhile (it never blocks) - an entry waits in
 * storage/logs/audit-pending.jsonl and goes into the table with its own time
 * after the Apply (flushPending(), from the Migrations page's Apply, the
 * command line and housekeeping) or with the next entry written outside a
 * transaction: the old table is never written again, so 018's gate proves a
 * move nothing raced.
 */

// The library's AuditLog comes with Composer's autoloader, and the command-line
// tools load this file without the API's front controller.
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/helpers.php';

function audit(?Request $request, ?int $actorId, string $action, ?string $targetType = null, int|string|null $targetId = null, array $details = []): void
{
    try {
        iamAuditLog()->record($request, $actorId, $action, $targetType, $targetId, $details);
    } catch (\Throwable $e) {
        // record() never throws; building the log could, before the IAM is set up.
        error_log('[audit] an entry could not be written (' . get_class($e) . ').');
    }
}

/**
 * The IAM's audit log: the library's AuditLog on the IAM's connection - the
 * one its transactions run on, so an entry commits (or not) with the change it
 * records - keeping the requester's address, waiting in a file until 018 has
 * moved the old rows in. With a connection of the caller's own (the import,
 * which runs in a transaction of the installer's connection), a log on that
 * one; without, the request's, built once.
 */
function iamAuditLog(?PDO $pdo = null): AuditLog
{
    static $log = null;

    if ($pdo === null && $log !== null) {
        return $log;
    }

    $made = new AuditLog($pdo !== null ? static fn(): PDO => $pdo : static fn(): PDO => iamDb(), [
        'recordAddress' => true,
        'address' => static fn(Request $request): ?string => requestIp($request),
        'pendingFile' => iamStorageDir() . '/logs/audit-pending.jsonl',
        'ready' => static fn(PDO $pdo): bool => auditMovedToLibrary($pdo),
        'actorIds' => static fn(string $name): array => auditActorIds($pdo ?? iamDb(), $name),
    ]);

    return $pdo === null ? $log = $made : $made;
}

/**
 * Whether migration 018 has moved the IAM's audit_log into the library's
 * table: the old table's name is gone (018 renamed it audit_log_before_018).
 * Until then an entry waits in the pending file - the library checks its own
 * table besides. A catalog query that cannot fail, asked afresh every time like
 * the others (signInRulesReady(), ...): the Apply runs in the same process as
 * the requests after it.
 */
function auditMovedToLibrary(PDO $pdo): bool
{
    return asBool($pdo->query("SELECT to_regclass('audit_log') IS NULL")->fetchColumn());
}

/**
 * The Audit page's "who" typed as a name: the ids of the accounts whose
 * username has it in it, regardless of case - what the IAM's own filter
 * matched (an id typed alone is the library's, an id). At most 500.
 *
 * @return list<int>
 */
function auditActorIds(PDO $pdo, string $name): array
{
    $statement = $pdo->prepare('SELECT id FROM users WHERE username ILIKE ? ORDER BY id LIMIT 500');
    $statement->execute(['%' . addcslashes($name, '%_\\') . '%']);

    return array_map('intval', $statement->fetchAll(PDO::FETCH_COLUMN));
}

/**
 * The names the Audit page shows beside the ids: each account's username as it
 * is now - an anonymized account's placeholder, never the name it had.
 *
 * @param list<int> $ids
 * @return array<int, string>
 */
function auditActorNames(array $ids): array
{
    if ($ids === []) {
        return [];
    }

    $statement = iamDb()->prepare('SELECT id, username FROM users WHERE id = ANY (CAST(? AS int[]))');
    $statement->execute(['{' . implode(',', array_map('intval', $ids)) . '}']);

    $names = [];
    foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $names[(int) $row['id']] = (string) $row['username'];
    }

    return $names;
}
