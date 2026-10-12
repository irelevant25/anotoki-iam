<?php

use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Deleting an account, at its owner's request (migration 016, the owner's
 * decision of 2026-10-07; docs/decisions.md).
 *
 * The owner's words: the data "will not be deleted immediately but after 30
 * days", and the sites "keep everything but just change the account to
 * anonymous one so no one will know whose data it is". So:
 *
 *   - Asked for (POST /api/account/deletion, with the password or a fresh
 *     sign-in): the account is closed at once. Every other session ends,
 *     every refresh and personal token is revoked, and nothing is issued to
 *     any site again (applicationAccessRefusal(): `account_deletion_pending`)
 *     - every site loses the person within one access token's lifetime. The
 *     IAM's own sign-in keeps working, by every way in, because that is where
 *     the person cancels; its admin panel does not (requireAdmin()).
 *   - Cancelled (DELETE /api/account/deletion) until it is done: the account
 *     is the person's again, and they sign in to the sites again.
 *   - Done thirty days later, by housekeeping (anonymizeDueAccounts()): the
 *     "deleted" mail while the address is still known, then everything of the
 *     person is gone or scrubbed - anonymizeAccount() says table by table -
 *     and the row stays, a placeholder, so its id (every site's `sub`) is
 *     never anybody else's and every foreign key still holds.
 *   - The sites learn which ids went, and when, from
 *     GET /api/public/anonymized-accounts (anonymizedAccountsPage()) - ids and
 *     times, nothing else - and scrub their own copies of the name and address.
 *
 * Everything here reads the columns only once 016 is there
 * (accountDeletionReady()): a build goes up before its migrations.
 */

/** How long a person has to change their mind - the owner's thirty days. */
const ACCOUNT_DELETION_DAYS = 30;

/** At most this many accounts, and this many seconds, per housekeeping run: a request has half a minute on the host. */
const ACCOUNT_DELETION_BATCH = 50;
const ACCOUNT_DELETION_BATCH_SECONDS = 10;

/** The most ids one answer of the public list holds. */
const ANONYMIZED_PAGE = 500;

/**
 * Where an anonymized account's address points: the .invalid top-level
 * domain is reserved (RFC 2606) and never delivers, so the placeholder is
 * never somebody's mailbox.
 */
const ANONYMIZED_EMAIL_DOMAIN = 'deleted.invalid';

/**
 * The advisory lock every anonymization takes first, in its own transaction.
 * One at a time, so accounts done one after another get their anonymized_at
 * in the order they were committed - to the second: two in the same second
 * may stand in either order of their ids. A site that asks again from the
 * newest time it has handled (`since` is inclusive) misses none of them.
 * 4260917 and 4260918 are taken (IAM_ADMINS_LOCK, MEMBER_ROLES_LOCK).
 */
const ANONYMIZATION_LOCK = 4260919;

/**
 * Whether the database has migration 016's columns. A catalog query that
 * cannot fail, asked afresh every time like the others: the admin panel's
 * Apply runs in the same process as the requests after it.
 */
function accountDeletionReady(PDO $pdo): bool
{
    return asBool($pdo->query(
        "SELECT (SELECT count(*) FROM pg_attribute WHERE attrelid = to_regclass('users') AND NOT attisdropped
                   AND attname IN ('deletion_requested_at', 'deletion_due_at', 'anonymized_at')) = 3"
    )->fetchColumn());
}

/** The answer of what needs 016 while it is pending. */
function accountDeletionUnavailable(): array
{
    return [
        'status' => 503,
        'code' => 'account_deletion_unavailable',
        'error' => 'Deleting an account is not in the database yet. Apply the pending migrations in the admin panel.',
    ];
}

/** Whether the account's deletion is asked for and not done yet. A row from before 016 has no such columns: it is not. */
function accountDeletionPending(array $user): bool
{
    return ($user['deletion_due_at'] ?? null) !== null && ($user['anonymized_at'] ?? null) === null;
}

/** Whether the account was deleted - anonymized, its row a placeholder. */
function accountAnonymized(array $user): bool
{
    return ($user['anonymized_at'] ?? null) !== null;
}

/**
 * " AND anonymized_at IS NULL" once 016 is there, "" before: the condition
 * every write to an account's row carries that an anonymization could
 * overtake. A request reads the account, the account is anonymized, and the
 * request writes - a password, a confirmed address, a name, "switched on" -
 * onto the placeholder: with this in its WHERE, the write finds no row (it
 * waits for the anonymization's lock, then reads the row again) and the
 * caller answers as for an account that is gone. users_anonymized_check
 * (016) refuses what matters of it whatever a query says.
 */
function liveAccountSql(PDO $pdo): string
{
    return accountDeletionReady($pdo) ? ' AND anonymized_at IS NULL' : '';
}

/**
 * The refusal of every change the admin panel (or a command-line tool) would
 * make to an anonymized account. Its id is reserved for good: switched on
 * again, given a password or an address, it would be somebody signing in as
 * the `sub` whose data every site has just made nobody's.
 *
 * @return array{status: int, code: string, error: string}|null
 */
function anonymizedRefusal(array $user): ?array
{
    return accountAnonymized($user)
        ? ['status' => 409, 'code' => 'account_anonymized', 'error' => 'This account was deleted on ' . substr((string) isoTime($user['anonymized_at']), 0, 10) . ': its id stays reserved, and nothing about it changes any more']
        : null;
}

/**
 * Asks for the account's deletion, the proof already given: the account is
 * closed in one transaction - the request's two dates, every other session
 * ended, every refresh and personal token revoked (the current session's
 * included: no site keeps a way in through it), and the audit entry.
 *
 * Under lockIamAdmins(), so the last administrator of the IAM cannot leave -
 * the count leaves out every administrator whose own deletion is pending
 * (iamAdminCount()), so two administrators asking at once cannot both go.
 *
 * @return array{due: string}|array{status: int, code: string, error: string}
 */
function requestAccountDeletion(PDO $pdo, Request $request, array $user, int $keepSessionId, string $proof): array
{
    $userId = (int) $user['id'];

    lockIamAdmins($pdo);
    try {
        if (isIamAdmin($userId) && iamAdminCount($userId) === 0) {
            $pdo->rollBack();
            return ['status' => 409, 'code' => 'last_admin', 'error' => 'This is the only IAM administrator left - make somebody else an administrator first'];
        }

        // Decided in the UPDATE: of two requests at once, one asks.
        $asked = $pdo->prepare(
            "UPDATE users SET deletion_requested_at = CURRENT_TIMESTAMP, deletion_due_at = CURRENT_TIMESTAMP + (? * INTERVAL '1 day')
              WHERE id = ? AND deletion_due_at IS NULL AND anonymized_at IS NULL AND NOT disabled
          RETURNING deletion_due_at"
        );
        $asked->execute([ACCOUNT_DELETION_DAYS, $userId]);
        $due = $asked->fetchColumn();

        if ($due === false) {
            $pdo->rollBack();
            return ['status' => 409, 'code' => 'deletion_already_requested', 'error' => 'The deletion of this account is asked for already'];
        }

        revokeSessionsFor($pdo, $userId, SESSION_REVOKED_DELETION, $keepSessionId);
        $pdo->prepare('UPDATE refresh_tokens SET revoked_at = CURRENT_TIMESTAMP, revoked_reason = ? WHERE user_id = ? AND revoked_at IS NULL')
            ->execute([SESSION_REVOKED_DELETION, $userId]);
        audit($request, $userId, 'account.deletion_requested', 'user', $userId, ['due' => isoTime((string) $due), 'proof' => $proof]);

        $pdo->commit();
    } catch (\Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    return ['due' => (string) $due];
}

/**
 * Cancels a pending deletion: the two dates go, in one transaction with its
 * audit entry - decided in the UPDATE, so a cancel and the anonymization at
 * the same moment go one after the other (anonymizeAccount() locks the row).
 * Refused once the account is anonymized - nothing is left to keep then. The
 * sessions and tokens the request ended stay ended: the person signs in to
 * the sites again.
 *
 * @return bool whether there was a deletion to cancel
 */
function cancelAccountDeletion(PDO $pdo, Request $request, int $userId): bool
{
    $pdo->beginTransaction();
    try {
        $cancelled = $pdo->prepare(
            'UPDATE users SET deletion_requested_at = NULL, deletion_due_at = NULL
              WHERE id = ? AND deletion_due_at IS NOT NULL AND anonymized_at IS NULL
          RETURNING id'
        );
        $cancelled->execute([$userId]);

        if ($cancelled->fetchColumn() === false) {
            $pdo->rollBack();
            return false;
        }

        audit($request, $userId, 'account.deletion_cancelled', 'user', $userId);
        $pdo->commit();
    } catch (\Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    return true;
}

/**
 * Anonymizes the accounts whose deletion is due - from housekeeping, which
 * runs on ordinary traffic (the host has no cron). Each in a transaction of
 * its own, at most ACCOUNT_DELETION_BATCH or ACCOUNT_DELETION_BATCH_SECONDS a
 * run: the rest wait for the next. One that fails is logged and costs only
 * itself. How many were done.
 */
function anonymizeDueAccounts(PDO $pdo): int
{
    if (!accountDeletionReady($pdo)) {
        return 0;
    }

    $due = $pdo->prepare(
        'SELECT id FROM users WHERE deletion_due_at <= CURRENT_TIMESTAMP AND anonymized_at IS NULL ORDER BY deletion_due_at, id LIMIT ?'
    );
    $due->execute([ACCOUNT_DELETION_BATCH]);

    $done = 0;
    $started = microtime(true);
    foreach ($due->fetchAll(PDO::FETCH_COLUMN) as $id) {
        if (microtime(true) - $started >= ACCOUNT_DELETION_BATCH_SECONDS) {
            break;
        }
        try {
            if (anonymizeAccount($pdo, (int) $id)) {
                $done++;
            }
        } catch (\Throwable $e) {
            error_log('[account deletion] could not anonymize account ' . (int) $id . ': ' . $e->getMessage());
        }
    }

    anonymizedLeftovers($pdo);

    return $done;
}

/**
 * What a request left that was on its way when its account went - it read the
 * account a moment before the anonymization, and wrote a moment after: a
 * sign-in's session (an IP address, a user agent), its attempt and the time
 * on the row; a reset link or a sign-in code asked for, with its mail in the
 * log; a Google identity attached, a remembered device, recovery codes, a
 * role a new application or an administrator gave; an announcement's row
 * written back with the address; an audit entry with the person's IP or
 * name. None of it opens anything - the account is switched off, and every
 * write that would put a credential back on its row finds none
 * (liveAccountSql(), users_anonymized_check) - but all of it is the person's.
 * Swept with every run, through the anonymized ids' own index.
 */
function anonymizedLeftovers(PDO $pdo): void
{
    $anonymized = 'SELECT id FROM users WHERE anonymized_at IS NOT NULL';

    $pdo->exec("DELETE FROM auth_codes WHERE user_id IN ($anonymized) OR session_id IN (SELECT id FROM sessions WHERE user_id IN ($anonymized))");
    $pdo->exec("DELETE FROM refresh_tokens WHERE user_id IN ($anonymized)");
    $pdo->exec("DELETE FROM sessions WHERE user_id IN ($anonymized)");
    foreach (['user_sign_in_devices', 'user_trusted_devices', 'user_tokens', 'user_recovery_codes', 'user_identities', 'user_roles', 'login_attempts', 'email_log'] as $table) {
        $pdo->exec("DELETE FROM {$table} WHERE user_id IN ($anonymized)");
    }
    $pdo->exec(
        'UPDATE announcement_recipients r SET email = u.email
           FROM users u WHERE u.id = r.user_id AND u.anonymized_at IS NOT NULL AND r.email <> u.email'
    );
    scrubAuditLog($pdo, array_map('intval', $pdo->query($anonymized)->fetchAll(PDO::FETCH_COLUMN)));
    $pdo->exec(
        'UPDATE users SET last_login_at = NULL, totp_last_step = NULL
          WHERE anonymized_at IS NOT NULL AND (last_login_at IS NOT NULL OR totp_last_step IS NOT NULL)'
    );
}

/**
 * The audit log's entries by or about anonymized accounts - `$ids`, their ids
 * - with the events kept and the person taken out of them, by the IAM's three
 * rules: the IP wherever it was the person's (their own actions, and those
 * about them with nobody else acting - a link followed; an administrator's IP
 * on an administrator's action stays, being the administrator's); the names
 * and addresses in the details of what was about them (a username's change, an
 * account's making); a personal token's name, which they chose. The
 * anonymization's own rules, and the sweep's: the same, so an entry written by
 * a request on its way is treated as one written before. Only entries that
 * still hold something are written.
 *
 * The log is the anotoki library's table (migration 018 moved the IAM's rows
 * into it): the first two rules are its scrubPeople() - the IAM's, made the
 * family's - and the third is the IAM's own. And while they are there, the
 * IAM's own table too: audit_log until 018 has run (a build is uploaded
 * before Apply), audit_log_before_018 - the copy 018 keeps, unread - until a
 * later migration drops it. An entry still waiting in the pending file for 018
 * is scrubbed by the sweep after it goes in.
 *
 * @param list<int> $ids
 */
function scrubAuditLog(PDO $pdo, array $ids): void
{
    if ($ids === []) {
        return;
    }

    $idArray = '{' . implode(',', array_map('intval', $ids)) . '}';
    $there = static fn(string $table): bool => asBool($pdo->query("SELECT to_regclass('{$table}') IS NOT NULL")->fetchColumn());

    if ($there('anotoki_audit_log')) {
        iamAuditLog($pdo)->scrubPeople($ids);
        $pdo->prepare(
            "UPDATE anotoki_audit_log SET details = details - 'name'
              WHERE actor_id = ANY (CAST(? AS bigint[])) AND action = 'account.token_created' AND jsonb_typeof(details) = 'object' AND jsonb_exists(details, 'name')"
        )->execute([$idArray]);
    }

    $person = "ARRAY['from', 'to', 'username', 'email', 'name', 'address']";
    foreach (['audit_log', 'audit_log_before_018'] as $table) {
        if (!$there($table)) {
            continue;
        }
        $pdo->prepare(
            "UPDATE {$table} SET ip = NULL
              WHERE ip IS NOT NULL
                AND (actor_id = ANY (CAST(? AS int[])) OR (actor_id IS NULL AND target_type = 'user' AND target_id = ANY (CAST(? AS text[]))))"
        )->execute([$idArray, $idArray]);
        $pdo->prepare(
            "UPDATE {$table} SET details = details - $person
              WHERE target_type = 'user' AND target_id = ANY (CAST(? AS text[]))
                AND jsonb_typeof(details) = 'object' AND jsonb_exists_any(details, $person)"
        )->execute([$idArray]);
        $pdo->prepare(
            "UPDATE {$table} SET details = details - 'name'
              WHERE actor_id = ANY (CAST(? AS int[])) AND action = 'account.token_created' AND jsonb_typeof(details) = 'object' AND jsonb_exists(details, 'name')"
        )->execute([$idArray]);
    }
}

/**
 * The audit entries that waited for migration 018 (storage/logs/audit-pending.jsonl)
 * into the log, with their own times - and then the people of every anonymized
 * account out of them at once (scrubAuditLog()): an entry may be about an
 * account deleted while it waited, whose scrub ran on the old table before the
 * entry was in any. After every Apply (the Migrations page, `php
 * migration.php`) and at every housekeeping run, before its deletions. How many
 * went in.
 *
 * The scrub runs whenever the flush found entries waiting and the log takes
 * them - not only when it put some in: a flush killed after its commit, before
 * it emptied the file, put them in without the scrub after it, and the next
 * one puts none in (the library never inserts an entry twice) - so a count of
 * nought says nothing of what is in the log unscrubbed.
 */
function flushWaitingAuditEntries(PDO $pdo): int
{
    $log = iamAuditLog();
    $waiting = auditEntriesWaiting();
    $flushed = $log->flushPending();
    if (($flushed > 0 || $waiting) && $log->ready() && accountDeletionReady($pdo)) {
        scrubAuditLog($pdo, array_map('intval', $pdo->query('SELECT id FROM users WHERE anonymized_at IS NOT NULL')->fetchAll(PDO::FETCH_COLUMN)));
    }

    return $flushed;
}

/**
 * One account whose deletion is due, made nobody's. False when it is not due
 * (any more) - cancelled a moment ago, or done by another request.
 *
 * In one transaction. The locks first, always in this order: the
 * anonymizations' own (one at a time), the IAM's administrators' (it takes a
 * role that may be the IAM's ADMIN, as lockIamAdmins() is for), the MEMBER
 * triggers' (so an application made at the same moment either gives MEMBER
 * before this takes every role, or sees the account anonymized and gives it
 * none - 016's trigger), and then the account's row (a cancel at the same
 * moment waits, then finds nothing to cancel - or wins, and this finds
 * nothing due). Every other path that takes two of them takes them in this
 * order, so none waits on another in a circle.
 *
 *   1. The "deleted" mail's address and words, read while the address and the
 *      name are still known - only for an address its owner proved. It is sent
 *      once the transaction has committed: never for an anonymization that
 *      did not happen, and never twice. Sending may fail; nothing waits on it.
 *   2. Gone, being the person's and nothing else's: sessions (with their IP
 *      addresses and user agents), codes, refresh and personal tokens, the
 *      remembered two-factor devices and the sign-in browsers, the mailed
 *      one-time tokens, recovery codes, outside identities (Google), every
 *      role, sign-in attempts and email log rows of the account or its
 *      address, sign-in requests that carried the address as a hint.
 *   3. Scrubbed, the event kept: the audit log's rows by the account, or about
 *      it with nobody else acting, lose their IP; the rows about it lose the
 *      names and addresses in their details (a username's change, an
 *      account's making); a personal token's name in its making's row
 *      (scrubAuditLog()). An announcement's row for the person keeps whether
 *      it went, not where.
 *   4. The row: a placeholder name and an undeliverable address, no password,
 *      two-factor, language, theme or news; switched off, unconfirmed, and
 *      anonymized_at - truncated to the second, the time the public list
 *      gives (so a `since` a site sends back is one the table holds).
 *   5. account.anonymized in the audit log, by nobody.
 *
 * Kept, being nobody's now: the row's id, when it was made, when the deletion
 * was asked and due; what others did that names the id (roles it granted,
 * strings it reworded, announcements it sent - an administrator's work); the
 * audit log's events themselves.
 */
function anonymizeAccount(PDO $pdo, int $userId): bool
{
    $pdo->beginTransaction();
    try {
        foreach ([ANONYMIZATION_LOCK, IAM_ADMINS_LOCK, MEMBER_ROLES_LOCK] as $lock) {
            $pdo->query('SELECT pg_advisory_xact_lock(' . $lock . ')');
        }

        $row = $pdo->prepare('SELECT * FROM users WHERE id = ? AND deletion_due_at <= CURRENT_TIMESTAMP AND anonymized_at IS NULL FOR UPDATE');
        $row->execute([$userId]);
        $user = $row->fetch();

        if ($user === false) {
            $pdo->rollBack();
            return false;
        }

        $email = (string) $user['email'];
        $farewell = asBool($user['email_confirmed']) ? accountDeletedMessage($user) : null;

        foreach ([
            'DELETE FROM auth_codes WHERE user_id = ?',
            'DELETE FROM refresh_tokens WHERE user_id = ?',
            'DELETE FROM sessions WHERE user_id = ?',
            'DELETE FROM user_trusted_devices WHERE user_id = ?',
            'DELETE FROM user_sign_in_devices WHERE user_id = ?',
            'DELETE FROM user_tokens WHERE user_id = ?',
            'DELETE FROM user_recovery_codes WHERE user_id = ?',
            'DELETE FROM user_identities WHERE user_id = ?',
            'DELETE FROM user_roles WHERE user_id = ?',
        ] as $sql) {
            $pdo->prepare($sql)->execute([$userId]);
        }
        $pdo->prepare('DELETE FROM login_attempts WHERE user_id = ? OR lower(email) = lower(?)')->execute([$userId, $email]);
        $pdo->prepare('DELETE FROM email_log WHERE user_id = ? OR lower(recipient) = lower(?)')->execute([$userId, $email]);
        $pdo->prepare('DELETE FROM auth_requests WHERE lower(login_hint) = lower(?)')->execute([$email]);

        // The placeholders - free ones: somebody may have chosen such a name.
        $username = 'deleted-' . $userId;
        $address = 'deleted-' . $userId . '@' . ANONYMIZED_EMAIL_DOMAIN;
        if (usernameTaken($pdo, $username, $userId) || emailTaken($pdo, $address, $userId)) {
            $tail = randomHex(4);
            $username .= '-' . $tail;
            $address = 'deleted-' . $userId . '-' . $tail . '@' . ANONYMIZED_EMAIL_DOMAIN;
        }

        $pdo->prepare('UPDATE announcement_recipients SET email = ? WHERE user_id = ?')->execute([$address, $userId]);
        scrubAuditLog($pdo, [$userId]);

        $pdo->prepare(
            "UPDATE users SET username = ?, email = ?, email_confirmed = FALSE, password = NULL, password_login_enabled = FALSE,
                    totp_secret = NULL, totp_enabled = FALSE, totp_last_step = NULL, force_password_change = FALSE,
                    language = 'en', theme = 'auto', date_format = NULL, time_format = NULL, news_mail = FALSE,
                    disabled = TRUE, last_login_at = NULL, anonymized_at = date_trunc('second', clock_timestamp())
              WHERE id = ?"
        )->execute([$username, $address, $userId]);

        audit(null, null, 'account.anonymized', 'user', $userId, ['requested_at' => isoTime((string) $user['deletion_requested_at'])]);

        $pdo->commit();
    } catch (\Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    // Done, for good: now the person may be told so - at the address it had,
    // logged under the one it has now.
    if ($farewell !== null) {
        sendAccountDeletedMail($email, $farewell, $address);
    }

    return true;
}

/**
 * A `since` of the public list: an ISO-8601 time - a date, or a date and a
 * time with seconds and a fraction if wanted, in UTC (Z) or at an offset - as
 * a DateTimeImmutable; null for anything else, a date that does not exist
 * (2026-02-30) included. Without a zone it is UTC. Nothing before 1970-01-01
 * UTC either: nobody was deleted then, and PostgreSQL refuses a year 0 that
 * PHP takes.
 */
function anonymizedSince(string $value): ?DateTimeImmutable
{
    if (preg_match('/^\d{4}-\d{2}-\d{2}(?:T\d{2}:\d{2}(?::\d{2}(?:\.\d{1,6})?)?(?:Z|[+-]\d{2}:?\d{2})?)?$/D', $value) !== 1) {
        return null;
    }

    try {
        $time = new DateTimeImmutable($value, new DateTimeZone('UTC'));
    } catch (\Exception) {
        return null;
    }

    // PHP rolls a day that is not there over into the next month, and says so only here.
    $errors = DateTimeImmutable::getLastErrors();
    if ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0)) {
        return null;
    }

    return $time->getTimestamp() < 0 ? null : $time;
}

/**
 * One answer of the public list: the ids anonymized at or after `$since`, in
 * the order they went (time, then id), at most ANONYMIZED_PAGE - and, when
 * there may be more, where the next answer starts: the last one's time and
 * id (`after`), which the next request sends back. Every answer before 016
 * is an empty one: nobody was anonymized then.
 *
 * @return array{accounts: list<array{sub: string, anonymized_at: string}>, next: array{since: string, after: string}|null}
 */
function anonymizedAccountsPage(PDO $pdo, ?DateTimeImmutable $since, ?int $after): array
{
    if (!accountDeletionReady($pdo)) {
        return ['accounts' => [], 'next' => null];
    }

    $from = ($since ?? new DateTimeImmutable('@0'))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.uP');
    $where = $after === null
        ? 'anonymized_at >= ?::timestamptz'
        : '(anonymized_at > ?::timestamptz OR (anonymized_at = ?::timestamptz AND id > ?))';
    $parameters = $after === null ? [$from] : [$from, $from, $after];

    $statement = $pdo->prepare(
        "SELECT id, anonymized_at FROM users WHERE anonymized_at IS NOT NULL AND $where ORDER BY anonymized_at, id LIMIT " . (ANONYMIZED_PAGE + 1)
    );
    $statement->execute($parameters);
    $rows = $statement->fetchAll();

    $more = count($rows) > ANONYMIZED_PAGE;
    $rows = array_slice($rows, 0, ANONYMIZED_PAGE);
    $accounts = array_map(static fn(array $row): array => ['sub' => (string) $row['id'], 'anonymized_at' => (string) isoTime($row['anonymized_at'])], $rows);
    $last = end($accounts);

    return [
        'accounts' => $accounts,
        'next' => $more && $last !== false ? ['since' => $last['anonymized_at'], 'after' => $last['sub']] : null,
    ];
}
