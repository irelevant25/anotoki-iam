<?php

use Anotoki\Lib\Admin\Schema as AdminSchema;
use Anotoki\Lib\Feedback\Feedback;
use Anotoki\Lib\Support\GuardedDirectory;
use Psr\Http\Message\ServerRequestInterface as Request;

// What an anonymization clears that 019 made (authenticatorPauseReady()).
require_once __DIR__ . '/authenticator_pause.php';

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
 *   - Each of the three is also a line of an add-only record beside the
 *     database (storage/records/account-deletions.jsonl, the owner's decision
 *     of 2026-10-13; recordAccountDeletionEvent()), written once it committed:
 *     a database lost and put back by hand from an older backup would bring
 *     back every person deleted since. `php account-deletions.php --replay`
 *     does them again after such a restore (replayAccountDeletions()); the
 *     IAM's restore in the admin panel stays off.
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
    return asBool(
        $pdo
            ->query(
                "SELECT (SELECT count(*) FROM pg_attribute WHERE attrelid = to_regclass('users') AND NOT attisdropped
                   AND attname IN ('deletion_requested_at', 'deletion_due_at', 'anonymized_at')) = 3",
            )
            ->fetchColumn(),
    );
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
        ? [
            'status' => 409,
            'code' => 'account_anonymized',
            'error' => 'This account was deleted on ' . substr((string) isoTime($user['anonymized_at']), 0, 10) . ': its id stays reserved, and nothing about it changes any more',
        ]
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
          RETURNING deletion_due_at, " .
                recordTimeSql('deletion_requested_at') .
                ' AS record_at, ' .
                recordTimeSql('deletion_due_at') .
                ' AS record_due',
        );
        $asked->execute([ACCOUNT_DELETION_DAYS, $userId]);
        $row = $asked->fetch();

        if ($row === false) {
            $pdo->rollBack();
            return ['status' => 409, 'code' => 'deletion_already_requested', 'error' => 'The deletion of this account is asked for already'];
        }
        $due = (string) $row['deletion_due_at'];

        revokeSessionsFor($pdo, $userId, SESSION_REVOKED_DELETION, $keepSessionId);
        $pdo->prepare('UPDATE refresh_tokens SET revoked_at = CURRENT_TIMESTAMP, revoked_reason = ? WHERE user_id = ? AND revoked_at IS NULL')->execute([SESSION_REVOKED_DELETION, $userId]);
        audit($request, $userId, 'account.deletion_requested', 'user', $userId, ['due' => isoTime($due), 'proof' => $proof]);

        $pdo->commit();
    } catch (\Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    // Committed: into the record beside the database, with both its times.
    recordAccountDeletionEvent('requested', $userId, (string) $row['record_at'], ['due' => (string) $row['record_due']]);

    return ['due' => $due];
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
          RETURNING ' .
                recordTimeSql('CURRENT_TIMESTAMP') .
                ' AS record_at',
        );
        $cancelled->execute([$userId]);
        $at = $cancelled->fetchColumn();

        if ($at === false) {
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

    recordAccountDeletionEvent('cancelled', $userId, (string) $at);

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

    $due = $pdo->prepare('SELECT id FROM users WHERE deletion_due_at <= CURRENT_TIMESTAMP AND anonymized_at IS NULL ORDER BY deletion_due_at, id LIMIT ?');
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
 * log; a Google identity attached, or a Google token held for its sign-in, a
 * remembered device, recovery codes, a
 * role a new application or an administrator gave; an announcement's row
 * written back with the address; an audit entry with the person's IP or
 * name; a feedback message sent with its reply address and browser. None of
 * it opens anything - the account is switched off, and every write that would
 * put a credential back on its row finds none (liveAccountSql(),
 * users_anonymized_check) - but all of it is the person's. Swept with every
 * run, through the anonymized ids' own index.
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
    // A Google token held for its sign-in names it no more: spent, so that
    // nothing takes it up instead (api/google_identity.php, 026).
    if (googleTokensReady($pdo)) {
        $pdo->exec("UPDATE google_id_tokens_used SET held_for_user = NULL WHERE held_for_user IN ($anonymized)");
    }
    $pdo->exec(
        'UPDATE announcement_recipients r SET email = u.email
           FROM users u WHERE u.id = r.user_id AND u.anonymized_at IS NOT NULL AND r.email <> u.email',
    );
    $ids = array_map('intval', $pdo->query($anonymized)->fetchAll(PDO::FETCH_COLUMN));
    scrubAuditLog($pdo, $ids);
    scrubFeedback($pdo, $ids);
    $pdo->exec(
        'UPDATE users SET last_login_at = NULL, totp_last_step = NULL
          WHERE anonymized_at IS NOT NULL AND (last_login_at IS NOT NULL OR totp_last_step IS NOT NULL)',
    );
    if (authenticatorPauseReady($pdo)) {
        $pdo->exec(
            'UPDATE users SET totp_paused_until = NULL, totp_count_from = NULL
              WHERE anonymized_at IS NOT NULL AND (totp_paused_until IS NOT NULL OR totp_count_from IS NOT NULL)',
        );
    }
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
              WHERE actor_id = ANY (CAST(? AS bigint[])) AND action = 'account.token_created' AND jsonb_typeof(details) = 'object' AND jsonb_exists(details, 'name')",
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
                AND (actor_id = ANY (CAST(? AS int[])) OR (actor_id IS NULL AND target_type = 'user' AND target_id = ANY (CAST(? AS text[]))))",
        )->execute([$idArray, $idArray]);
        $pdo->prepare(
            "UPDATE {$table} SET details = details - $person
              WHERE target_type = 'user' AND target_id = ANY (CAST(? AS text[]))
                AND jsonb_typeof(details) = 'object' AND jsonb_exists_any(details, $person)",
        )->execute([$idArray]);
        $pdo->prepare(
            "UPDATE {$table} SET details = details - 'name'
              WHERE actor_id = ANY (CAST(? AS int[])) AND action = 'account.token_created' AND jsonb_typeof(details) = 'object' AND jsonb_exists(details, 'name')",
        )->execute([$idArray]);
    }
}

/**
 * The feedback messages of deleted accounts (the library's anotoki_feedback,
 * api/admin_tools.php's iamFeedback(); the owner's decision of 2026-10-19),
 * with the person taken out and the words kept - as the Privacy page says,
 * and as the audit log keeps its events: the messages an account sent - its
 * id among `$ids`, the library's own rule (Feedback::forgetPeopleOn()) - and,
 * while the account's address is still known (the anonymization's own call,
 * `$addresses`), every message whose reply address is that address, whoever
 * sent it (signed out, the address typed in), as the sign-in attempts and the
 * mail log of an address go with its account: each loses its reply address
 * and its browser's name. What a message says stays, under the placeholder's
 * id or none - the site's to keep. Only messages that still hold one of the
 * two are written. Nothing before the library's table is there.
 *
 * @param list<int> $ids
 * @param list<string> $addresses
 */
function scrubFeedback(PDO $pdo, array $ids, array $addresses = []): void
{
    if (!AdminSchema::has($pdo, AdminSchema::FEEDBACK_TABLE)) {
        return;
    }

    Feedback::forgetPeopleOn($pdo, $ids);

    $byAddress = $pdo->prepare(
        'UPDATE ' .
            AdminSchema::FEEDBACK_TABLE .
            ' SET contact_email = NULL, user_agent = NULL, updated_at = now()
              WHERE lower(contact_email) = lower(?)',
    );
    foreach ($addresses as $address) {
        if (trim($address) !== '') {
            $byAddress->execute([trim($address)]);
        }
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
 *      it went, not where. The feedback messages the account sent, and any
 *      whose reply address is its address, lose that address and the
 *      browser's name, their words kept (scrubFeedback()).
 *   4. The row: a placeholder name and an undeliverable address, no password,
 *      two-factor, language, theme or news; switched off, unconfirmed, and
 *      anonymized_at - truncated to the second, the time the public list
 *      gives (so a `since` a site sends back is one the table holds).
 *   5. account.anonymized in the audit log, by nobody.
 *
 * Kept, being nobody's now: the row's id, when it was made, when the deletion
 * was asked and due; what others did that names the id (roles it granted,
 * strings it reworded, announcements it sent - an administrator's work); the
 * audit log's events themselves; what its feedback messages say.
 *
 * Once committed, a line of the record beside the database
 * (recordAccountDeletionEvent()). With $replay - the record done again after
 * a restore (replayAccountDeletions()) - the account goes whether or not the
 * restored row says it is due (the record says it went: its request may be
 * older than the window replayed, or its line lost), and no last mail goes:
 * the person was told when it was done the first time - and its line goes into
 * the record the replay read ($recordFile, null for the IAM's own).
 */
function anonymizeAccount(PDO $pdo, int $userId, bool $replay = false, ?string $recordFile = null): bool
{
    $pdo->beginTransaction();
    try {
        foreach ([ANONYMIZATION_LOCK, IAM_ADMINS_LOCK, MEMBER_ROLES_LOCK] as $lock) {
            $pdo->query('SELECT pg_advisory_xact_lock(' . $lock . ')');
        }

        $row = $pdo->prepare('SELECT * FROM users WHERE id = ? AND anonymized_at IS NULL' . ($replay ? '' : ' AND deletion_due_at <= CURRENT_TIMESTAMP') . ' FOR UPDATE');
        $row->execute([$userId]);
        $user = $row->fetch();

        if ($user === false) {
            $pdo->rollBack();
            return false;
        }

        $email = (string) $user['email'];
        $farewell = asBool($user['email_confirmed']) && !$replay ? accountDeletedMessage($user) : null;

        foreach (
            [
                'DELETE FROM auth_codes WHERE user_id = ?',
                'DELETE FROM refresh_tokens WHERE user_id = ?',
                'DELETE FROM sessions WHERE user_id = ?',
                'DELETE FROM user_trusted_devices WHERE user_id = ?',
                'DELETE FROM user_sign_in_devices WHERE user_id = ?',
                'DELETE FROM user_tokens WHERE user_id = ?',
                'DELETE FROM user_recovery_codes WHERE user_id = ?',
                'DELETE FROM user_identities WHERE user_id = ?',
                'DELETE FROM user_roles WHERE user_id = ?',
            ]
            as $sql
        ) {
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
        scrubFeedback($pdo, [$userId], [$email]);

        // A row anonymized holds when it was asked and due (users_deletion_check): one done again
        // by a replay whose restored row has no request keeps the time it is done at.
        $done = $pdo->prepare(
            "UPDATE users SET username = ?, email = ?, email_confirmed = FALSE, password = NULL, password_login_enabled = FALSE,
                    totp_secret = NULL, totp_enabled = FALSE, totp_last_step = NULL, force_password_change = FALSE,
                    language = 'en', theme = 'auto', date_format = NULL, time_format = NULL, news_mail = FALSE,
                    disabled = TRUE, last_login_at = NULL, anonymized_at = date_trunc('second', clock_timestamp()),
                    deletion_requested_at = COALESCE(deletion_requested_at, CURRENT_TIMESTAMP), deletion_due_at = COALESCE(deletion_due_at, CURRENT_TIMESTAMP)
              WHERE id = ?
          RETURNING " .
                recordTimeSql('anonymized_at') .
                ' AS record_at',
        );
        $done->execute([$username, $address, $userId]);
        $at = (string) $done->fetchColumn();
        // The authenticator's pause and its count (019, once there): the person's too.
        if (authenticatorPauseReady($pdo)) {
            $pdo->prepare('UPDATE users SET totp_paused_until = NULL, totp_count_from = NULL WHERE id = ?')->execute([$userId]);
        }

        audit(null, null, 'account.anonymized', 'user', $userId, ['requested_at' => isoTime((string) $user['deletion_requested_at'])] + ($replay ? ['replayed' => true] : []));

        $pdo->commit();
    } catch (\Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    recordAccountDeletionEvent('anonymized', $userId, $at, $replay ? ['replayed' => true] : [], $replay ? $recordFile : null);

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
    $where = $after === null ? 'anonymized_at >= ?::timestamptz' : '(anonymized_at > ?::timestamptz OR (anonymized_at = ?::timestamptz AND id > ?))';
    $parameters = $after === null ? [$from] : [$from, $from, $after];

    $statement = $pdo->prepare("SELECT id, anonymized_at FROM users WHERE anonymized_at IS NOT NULL AND $where ORDER BY anonymized_at, id LIMIT " . (ANONYMIZED_PAGE + 1));
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

// ─────────────────────────────────────────────────────────────────────────────
// The record beside the database, and its replay (the owner's decision of 2026-10-13)
// ─────────────────────────────────────────────────────────────────────────────
//
// A deletion lives in the database: asked for, cancelled, done. Were the
// database lost and an older backup put back by hand, every request and every
// anonymization made after it would be gone with it - and an anonymized
// person's name, address and history would be back. So each of the three is
// also a line of an add-only record kept beside the database, in storage/:
//
//   {"at":"2026-10-13T12:34:56.789012Z","event":"requested","user_id":42,"due":"2026-11-12T12:34:56.789012Z"}
//   {"at":"...","event":"cancelled","user_id":42}
//   {"at":"...","event":"anonymized","user_id":42}
//
// ids and the database's own times, nothing of the person. Written once the
// event's transaction committed - never for one rolled back - and a failure to
// write is the error log's, loudly, and never the person's: the deletion was
// made. After an offline restore `php account-deletions.php --replay` does
// again every event the restored database lacks (replayAccountDeletions()),
// and its own changes are lines of the record too ("replayed": true), so a
// backup made between the restore and the replay is covered by the next
// replay as well. The IAM's restore in the admin panel stays off.

/** How far before the backup's time a replay starts: a transaction that began before its snapshot may have committed after it. */
const ACCOUNT_DELETION_REPLAY_MARGIN = 3600;

/** The events a record holds. */
const ACCOUNT_DELETION_EVENTS = ['requested', 'cancelled', 'anonymized'];

/** Where the record is kept: beside the database, in storage/ (its folder guarded like the others). */
function accountDeletionRecordFile(): string
{
    return iamStorageDir() . '/records/account-deletions.jsonl';
}

/**
 * A time as the record writes one - ISO-8601 in UTC, to the microsecond, as
 * the database holds it - from an SQL expression: a replay writes back exactly
 * the time it read.
 */
function recordTimeSql(string $expression): string
{
    return "to_char(({$expression}) AT TIME ZONE 'UTC', 'YYYY-MM-DD\"T\"HH24:MI:SS.US\"Z\"')";
}

/**
 * One event into the record, after its transaction committed. Never throws:
 * a line that cannot be written - the folder cannot be made or guarded, the
 * file cannot be added to - is written into the error log, loudly, naming the
 * event (its account's id and its time, nothing of the person), and the
 * request goes on as it was: the deletion was made, and the database holds it.
 *
 * The folder storage/records is the IAM's own: made readable by the FTP account
 * (the owner downloads the file with the backups, docs/operations.md), and
 * guarded against the web as the library guards its folders (an .htaccess
 * that denies everything, an empty index.html); the file is added to under an
 * exclusive lock, one line at a time. $file is a copy of the record a replay
 * read (`--record=`), which its own lines go into: the owner uploads that one
 * file back - its folder is the owner's, and left as it is.
 */
function recordAccountDeletionEvent(string $event, int $userId, string $at, array $extra = [], ?string $file = null): void
{
    try {
        if ($file === null) {
            $file = accountDeletionRecordFile();
            $folder = dirname($file);
            if (!is_dir($folder) && !@mkdir($folder, 0755, true) && !is_dir($folder)) {
                throw new RuntimeException('its folder cannot be made');
            }
            if (!GuardedDirectory::ensure($folder)) {
                throw new RuntimeException('its folder\'s guard against the web cannot be written');
            }
        }
        $line = json_encode(['at' => $at, 'event' => $event, 'user_id' => $userId] + $extra, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
        if (@file_put_contents($file, $line, FILE_APPEND | LOCK_EX) !== strlen($line)) {
            throw new RuntimeException('it cannot be added to');
        }
    } catch (\Throwable $e) {
        $failure = new RuntimeException(
            "The account deletion record (storage/records/account-deletions.jsonl) is missing an event: {$event} of account {$userId} at {$at} is in the database alone - " . $e->getMessage() . '.',
            0,
            $e,
        );
        try {
            if (function_exists('iamRecordError')) {
                iamRecordError($failure);
            } else {
                error_log('[account deletion record] ' . $failure->getMessage());
            }
        } catch (\Throwable) {
            error_log('[account deletion record] ' . $failure->getMessage());
        }
    }
}

/**
 * The record, read: its events in the order they were written - not always the
 * order they happened, which a replay puts them in (accountDeletionEventTime())
 * -, and how many lines were no event - not JSON, an event it does not know,
 * no account, a time that is none - which are passed over, never guessed at.
 *
 * @return array{events: list<array{at: string, event: string, user_id: int, due?: string, requested_at?: string, replayed?: bool}>, unreadable: int}
 */
function readAccountDeletionRecord(string $file): array
{
    $events = [];
    $unreadable = 0;
    $handle = @fopen($file, 'rb');
    if ($handle === false) {
        return ['events' => [], 'unreadable' => 0];
    }

    try {
        while (($line = fgets($handle)) !== false) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $event = json_decode($line, true);
            $valid =
                is_array($event) &&
                in_array($event['event'] ?? null, ACCOUNT_DELETION_EVENTS, true) &&
                is_int($event['user_id'] ?? null) &&
                $event['user_id'] > 0 &&
                $event['user_id'] <= 2147483647 &&
                is_string($event['at'] ?? null) &&
                anonymizedSince($event['at']) !== null &&
                ($event['event'] !== 'requested' || (is_string($event['due'] ?? null) && anonymizedSince($event['due']) !== null)) &&
                (!isset($event['requested_at']) || (is_string($event['requested_at']) && anonymizedSince($event['requested_at']) !== null));
            if (!$valid) {
                $unreadable++;
                continue;
            }
            $events[] = array_intersect_key($event, array_flip(['at', 'event', 'user_id', 'due', 'requested_at', 'replayed']));
        }
    } finally {
        fclose($handle);
    }

    return ['events' => $events, 'unreadable' => $unreadable];
}

/** One event as the command line prints it: when, what, which account - ids and times only. */
function accountDeletionEventLine(array $event): string
{
    return sprintf('%s  %-10s  #%d', $event['at'], $event['event'], $event['user_id']) . (isset($event['due']) ? '  due ' . $event['due'] : '') . (!empty($event['replayed']) ? '  (replayed)' : '');
}

/**
 * The record done again on a database restored from a backup made at $since:
 * every event from ACCOUNT_DELETION_REPLAY_MARGIN before it on, in the order
 * the events happened (accountDeletionEventTime()) - a request with its own
 * times (the thirty days keep their date), a cancellation, an anonymization
 * run now (anonymizeAccount(), no last mail). Each event says where its
 * account stands afterwards, so an account's last one decides, and only where
 * the database does not say so already: asked and then cancelled, an account
 * ends not asked - without being asked and cancelled again on the way. Then
 * the ids the record names are kept from being issued again, and every way
 * back in the restore brought back is ended (endEverySignIn()). Each change
 * made is a line of the record it read ($recordFile, a copy given with
 * `--record=`; null for the IAM's own), and an entry of the audit log. Done
 * twice, the second changes nothing. With $dryRun, what it would do, and
 * nothing changed.
 *
 * And it says how many live administrators the restored IAM has afterwards
 * (liveAdministratorsAfterReplay()), with a warning when none: the backup may
 * be older than every administrator who stays - one made since is not in it,
 * and the record knows deletions, not roles - and an IAM nobody administers
 * cannot be mended from its own pages.
 *
 * Refused while migrations wait: the record is replayed onto the IAM's schema
 * as it is now.
 *
 * @return array{refused: string}|array{from: string, lines: list<string>, summary: string, counts: array<string, int>, ended: array<string, int>, kept: ?int, administrators: int, warning: ?string}
 */
function replayAccountDeletions(PDO $pdo, array $events, DateTimeImmutable $since, bool $dryRun, ?string $recordFile = null): array
{
    $pending = iamMigrator($pdo)->status()['pending'];
    if ($pending !== []) {
        return [
            'refused' =>
                count($pending) .
                ' database update(s) wait (' .
                implode(', ', array_map(static fn(array $file): string => iamMigrationLabel($file['set'], $file['name']), $pending)) .
                "). Apply them first - php migration.php: the record is replayed onto the IAM's schema as it is now.",
        ];
    }

    $from = $since->setTimezone(new DateTimeZone('UTC'))->modify('-' . ACCOUNT_DELETION_REPLAY_MARGIN . ' seconds');
    $window = array_values(array_filter($events, static fn(array $event): bool => new DateTimeImmutable($event['at']) >= $from));

    // In the order the events happened, by their own times - not the order their lines were written in:
    // each line is added once its transaction committed, so two events of an account a moment apart can
    // stand the other way round in the file, and the last one decides. A tie keeps the record's order.
    $order = array_keys($window);
    usort($order, static fn(int $a, int $b): int => [accountDeletionEventTime($window[$a]), $a] <=> [accountDeletionEventTime($window[$b]), $b]);
    $window = array_map(static fn(int $index): array => $window[$index], $order);

    $last = [];
    foreach ($window as $index => $event) {
        $last[$event['user_id']] = $index;
    }

    $counts = ['requested' => 0, 'cancelled' => 0, 'anonymized' => 0, 'already' => 0, 'missing' => 0];
    $lines = [];
    // What the replay made (or, dry, would make) of each account it changed, for the administrators' count.
    $becomes = [];
    foreach ($window as $index => $event) {
        if ($last[$event['user_id']] !== $index) {
            $lines[] = accountDeletionEventLine($event) . '  a later event of the account decides';
            continue;
        }
        $before = $counts;
        $lines[] = accountDeletionEventLine($event) . '  ' . replayAccountDeletionEvent($pdo, $event, $dryRun, $counts, $recordFile);
        foreach (['requested', 'cancelled', 'anonymized'] as $made) {
            if ($counts[$made] > $before[$made]) {
                $becomes[$event['user_id']] = $made;
            }
        }
    }

    $kept = keepAccountIdsPast($pdo, max([0, ...array_map(static fn(array $event): int => $event['user_id'], $events)]), $dryRun);
    $ended = endEverySignIn($pdo, $dryRun);
    $administrators = liveAdministratorsAfterReplay($pdo, $becomes);

    $would = $dryRun ? 'would be ' : '';
    $summary =
        sprintf(
            'Asked again: %d, cancelled again: %d, deleted again: %d, already so: %d, not in this database: %d. Ended%s: %d session(s), %d refresh and personal token(s), %d remembered browser(s), %d mailed link(s) and code(s).',
            $counts['requested'],
            $counts['cancelled'],
            $counts['anonymized'],
            $counts['already'],
            $counts['missing'],
            $dryRun ? ' (would be)' : '',
            $ended['sessions'],
            $ended['tokens'],
            $ended['devices'],
            $ended['mailed'],
        ) .
        ($kept !== null ? " Account ids up to #{$kept} {$would}kept from being issued again." : '') .
        " Live administrators of the IAM afterwards: {$administrators}.";

    $warning =
        $administrators > 0
            ? null
            : 'WARNING: ' .
                ($dryRun ? 'the replay would leave' : 'the replay leaves') .
                ' the restored IAM with no live administrator - nobody holding the IAM\'s ADMIN who is' .
                ' switched on, not deleted and not waiting for a deletion (an administrator made after the backup is not in it; the record knows deletions, not roles).' .
                ' An administrator whose deletion was asked again can sign in and cancel it; otherwise give a live account the IAM\'s ADMIN in the database by hand' .
                ' (docs/operations.md, "When the database is lost") before the IAM goes live again.';

    // In the audit log when it changed anything: a second run, which changes nothing, leaves nothing.
    $changed = $counts['requested'] + $counts['cancelled'] + $counts['anonymized'] + array_sum($ended) > 0 || $kept !== null;
    if (!$dryRun && $changed) {
        audit(
            null,
            null,
            'account.deletions_replayed',
            null,
            null,
            ['since' => $since->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z')] + $counts + ['ended' => $ended, 'administrators' => $administrators],
        );
    }

    return [
        'from' => $from->format('Y-m-d\TH:i:s\Z'),
        'lines' => $lines,
        'summary' => $summary,
        'counts' => $counts,
        'ended' => $ended,
        'kept' => $kept,
        'administrators' => $administrators,
        'warning' => $warning,
    ];
}

/**
 * When an event happened, for putting a replay's events in order - in UTC, to
 * the microsecond, comparable as text: its own time, which for a request the
 * replay made again is when it was first asked (`requested_at`), not when the
 * replay wrote it.
 */
function accountDeletionEventTime(array $event): string
{
    return (new DateTimeImmutable($event['requested_at'] ?? $event['at']))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u');
}

/**
 * How many live administrators the IAM has once a replay is done - holding the
 * IAM's ADMIN, switched on, not deleted and not waiting for a deletion
 * (iamAdminCount()'s rule) -, with $becomes, what the replay made (or would
 * make, on a dry run) of each account it changed, laid over what the database
 * says: asked or deleted again, an administrator administers nothing any
 * more; cancelled again, one does again.
 *
 * @param array<int, string> $becomes
 */
function liveAdministratorsAfterReplay(PDO $pdo, array $becomes): int
{
    $rows = $pdo->prepare(
        "SELECT DISTINCT u.id, u.deletion_due_at IS NULL AND u.anonymized_at IS NULL AS staying, u.anonymized_at IS NOT NULL AS gone
           FROM user_roles ur
           JOIN application_roles r ON r.id = ur.role_id
           JOIN applications a ON a.id = r.application_id
           JOIN users u ON u.id = ur.user_id AND u.disabled = FALSE
          WHERE a.client_id = ? AND r.name = 'ADMIN'",
    );
    $rows->execute([IAM_CLIENT_ID]);

    $live = 0;
    foreach ($rows->fetchAll() as $row) {
        $live += match ($becomes[(int) $row['id']] ?? null) {
            'requested', 'anonymized' => 0,
            'cancelled' => asBool($row['gone']) ? 0 : 1,
            default => asBool($row['staying']) ? 1 : 0,
        };
    }

    return $live;
}

/** One event done again, where the database does not say so already: what became of it, in a few words. */
function replayAccountDeletionEvent(PDO $pdo, array $event, bool $dryRun, array &$counts, ?string $recordFile = null): string
{
    $userId = $event['user_id'];
    $row = $pdo->prepare('SELECT deletion_due_at IS NOT NULL AS asked, anonymized_at IS NOT NULL AS gone FROM users WHERE id = ?');
    $row->execute([$userId]);
    $state = $row->fetch();

    if ($state === false) {
        $counts['missing']++;

        return 'not in this database (made after the backup?) - its id is kept from being issued again';
    }
    // A deleted account stays deleted: nothing asks for it or cancels it again.
    if (asBool($state['gone'])) {
        $counts['already']++;

        return 'deleted already';
    }

    if ($event['event'] === 'requested') {
        $requestedAt = $event['requested_at'] ?? $event['at'];
        $same = $pdo->prepare('SELECT deletion_requested_at = CAST(? AS timestamptz) AND deletion_due_at = CAST(? AS timestamptz) FROM users WHERE id = ?');
        $same->execute([$requestedAt, $event['due'], $userId]);
        if (asBool($same->fetchColumn())) {
            $counts['already']++;

            return 'already so';
        }
        if (!$dryRun) {
            $pdo->prepare('UPDATE users SET deletion_requested_at = CAST(? AS timestamptz), deletion_due_at = CAST(? AS timestamptz) WHERE id = ? AND anonymized_at IS NULL')->execute([
                $requestedAt,
                $event['due'],
                $userId,
            ]);
            recordAccountDeletionEvent('requested', $userId, recordNow($pdo), ['due' => $event['due'], 'requested_at' => $requestedAt, 'replayed' => true], $recordFile);
            audit(null, null, 'account.deletion_requested', 'user', $userId, ['due' => isoTime($event['due']), 'replayed' => true]);
        }
        $counts['requested']++;

        return $dryRun ? 'would be asked again, with its own times' : 'asked again, with its own times';
    }

    if ($event['event'] === 'cancelled') {
        if (!asBool($state['asked'])) {
            $counts['already']++;

            return 'already so';
        }
        if (!$dryRun) {
            $pdo->prepare('UPDATE users SET deletion_requested_at = NULL, deletion_due_at = NULL WHERE id = ? AND anonymized_at IS NULL')->execute([$userId]);
            recordAccountDeletionEvent('cancelled', $userId, recordNow($pdo), ['replayed' => true], $recordFile);
            audit(null, null, 'account.deletion_cancelled', 'user', $userId, ['replayed' => true]);
        }
        $counts['cancelled']++;

        return $dryRun ? 'would be cancelled again' : 'cancelled again';
    }

    if (!$dryRun && !anonymizeAccount($pdo, $userId, replay: true, recordFile: $recordFile)) {
        // Deleted between the read above and now: by housekeeping, the same end.
        $counts['already']++;

        return 'deleted already';
    }
    $counts['anonymized']++;

    return $dryRun ? 'would be deleted again now' : 'deleted again now';
}

/** The database's clock now, as the record writes a time. */
function recordNow(PDO $pdo): string
{
    return (string) $pdo->query('SELECT ' . recordTimeSql('clock_timestamp()'))->fetchColumn();
}

/**
 * The accounts' id sequence moved past $highest - the highest id the record
 * names - where it is not already: an account made after the backup is not in
 * the restored database, and its id - every site's `sub` for it - must never
 * be anybody else's. The id it would issue next, or null when it is past it.
 */
function keepAccountIdsPast(PDO $pdo, int $highest, bool $dryRun): ?int
{
    $sequence = $pdo->query("SELECT pg_get_serial_sequence('users', 'id')")->fetchColumn();
    if (!is_string($sequence) || $sequence === '' || $highest <= 0) {
        return null;
    }

    $state = $pdo->query('SELECT last_value, is_called FROM ' . $sequence)->fetch();
    $next = asBool($state['is_called']) ? (int) $state['last_value'] + 1 : (int) $state['last_value'];
    if ($highest < $next) {
        return null;
    }
    if (!$dryRun) {
        $pdo->prepare('SELECT setval(CAST(? AS regclass), ?, true)')->execute([$sequence, $highest]);
    }

    return $highest;
}

/**
 * Every way back in that a restored database brought back, ended: live
 * sessions and refresh tokens (personal ones too), remembered two-factor
 * browsers, live mailed links and codes. Revoked after the backup was made,
 * each would be alive again - so everybody signs in again, asks for a new link,
 * and confirms a remembered browser with a code once more. In one transaction;
 * counted, not changed, for a dry run.
 *
 * @return array{sessions: int, tokens: int, devices: int, mailed: int}
 */
function endEverySignIn(PDO $pdo, bool $dryRun): array
{
    $parts = [
        'sessions' => [
            'sessions',
            'revoked_at IS NULL AND expires_at > CURRENT_TIMESTAMP AND absolute_expires_at > CURRENT_TIMESTAMP',
            'revoked_at = CURRENT_TIMESTAMP, revoked_reason = ' . $pdo->quote(SESSION_REVOKED_ADMIN),
        ],
        'tokens' => ['refresh_tokens', 'revoked_at IS NULL AND expires_at > CURRENT_TIMESTAMP', 'revoked_at = CURRENT_TIMESTAMP, revoked_reason = ' . $pdo->quote(SESSION_REVOKED_ADMIN)],
        'devices' => ['user_trusted_devices', 'revoked_at IS NULL AND expires_at > CURRENT_TIMESTAMP', 'revoked_at = CURRENT_TIMESTAMP'],
        'mailed' => ['user_tokens', 'consumed_at IS NULL AND expires_at > CURRENT_TIMESTAMP', 'consumed_at = CURRENT_TIMESTAMP'],
    ];

    $ended = [];
    if (!$dryRun) {
        $pdo->beginTransaction();
    }
    try {
        foreach ($parts as $name => [$table, $live, $end]) {
            $ended[$name] = $dryRun ? (int) $pdo->query("SELECT count(*) FROM {$table} WHERE {$live}")->fetchColumn() : $pdo->exec("UPDATE {$table} SET {$end} WHERE {$live}");
        }
        if (!$dryRun) {
            $pdo->commit();
        }
    } catch (\Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    return $ended;
}

/**
 * php account-deletions.php - the command, as the file runs it and the tests
 * drive it: its arguments, and where it prints. The exit status: 0 done, 1
 * refused or failed, 2 asked wrongly.
 *
 * @param resource $out
 * @param resource $err
 */
function accountDeletionsCommand(array $arguments, $out, $err): int
{
    $option = static function (string $name) use ($arguments): ?string {
        foreach ($arguments as $argument) {
            if (is_string($argument) && str_starts_with($argument, "--{$name}=")) {
                return substr($argument, strlen($name) + 3);
            }
        }

        return null;
    };
    $flag = static fn(string $name): bool => in_array("--{$name}", $arguments, true);
    $usage =
        "Usage:\n" . "  php account-deletions.php --list [--since=<time>] [--record=<file>]\n" . "  php account-deletions.php --replay --since=<the backup's time> [--record=<file>] [--dry-run]\n";

    if ($flag('help')) {
        fwrite($out, $usage);

        return 0;
    }
    if (!$flag('list') && !$flag('replay')) {
        fwrite($err, $usage);

        return 2;
    }

    $sinceText = $option('since');
    $since = $sinceText === null ? null : anonymizedSince($sinceText);
    if ($sinceText !== null && $since === null) {
        fwrite($err, "--since= takes an ISO-8601 time or date (2026-10-13T08:00:00Z), not \"{$sinceText}\".\n");

        return 2;
    }
    if ($flag('replay') && $since === null) {
        fwrite($err, "--replay needs --since=<the backup's time>: the events after it are what the restored database lacks.\n");

        return 2;
    }

    $given = $option('record');
    $file = $given ?? accountDeletionRecordFile();
    if (!is_file($file)) {
        fwrite($err, "There is no record at {$file}." . ($given === null ? ' Nothing was asked, cancelled or deleted since it began - or give a copy with --record=.' : '') . "\n");

        return 1;
    }
    $read = readAccountDeletionRecord($file);
    if ($read['unreadable'] > 0) {
        fwrite($err, "{$read['unreadable']} line(s) of the record are no event: passed over.\n");
    }

    if ($flag('list')) {
        $shown = 0;
        foreach ($read['events'] as $event) {
            if ($since === null || new DateTimeImmutable($event['at']) >= $since) {
                fwrite($out, accountDeletionEventLine($event) . "\n");
                $shown++;
            }
        }
        fwrite($out, "{$shown} event(s).\n");

        return 0;
    }

    $dryRun = $flag('dry-run');
    // What it changes goes into the record it read: a copy given is the one the owner uploads back.
    $result = replayAccountDeletions(iamDb(), $read['events'], $since, $dryRun, $given);
    if (isset($result['refused'])) {
        fwrite($err, $result['refused'] . "\n");

        return 1;
    }

    fwrite($out, ($dryRun ? "A dry run: nothing is changed.\n" : '') . "The record from {$result['from']} on (an hour before the backup's time), in the order it happened:\n");
    foreach ($result['lines'] as $line) {
        fwrite($out, '  ' . $line . "\n");
    }
    fwrite($out, $result['summary'] . "\n");
    if ($result['warning'] !== null) {
        fwrite($out, "\n" . $result['warning'] . "\n");
    }

    return 0;
}
