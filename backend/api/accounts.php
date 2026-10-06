<?php

/**
 * Accounts: finding them, and describing them to the pages that ask.
 *
 * `password` and `totp_secret` never leave this file in any answer, for
 * anybody - the payloads below are built field by field rather than by
 * removing the dangerous ones from a row, so a column added later is not
 * published by default.
 */

/**
 * The owner of an address has just proved it - Google vouched for it, or a
 * reset link reached the mailbox - on an account whose address was never
 * confirmed.
 *
 * Whoever made that account need not have been them: anybody can register an
 * address they do not hold, with a password of their own choosing, and wait.
 * So nothing the registrant set may keep working once the address's owner
 * arrives: the password, two-factor and its recovery codes, remembered
 * devices, every session and token, and any outside identity. The account is
 * then the owner's alone, and they add back whatever ways in they want. An
 * account confirmed all along is left as it is: its credentials were set by
 * somebody who had proved the address.
 *
 * Call it with the account as it was before anything confirmed it.
 */
function claimUnconfirmedAccount(PDO $pdo, \Psr\Http\Message\ServerRequestInterface $request, array $user): void
{
    if (asBool($user['email_confirmed'])) {
        return;
    }

    $userId = (int) $user['id'];

    $pdo->prepare(
        'UPDATE users SET password = NULL, totp_enabled = FALSE, totp_secret = NULL, totp_last_step = NULL, force_password_change = FALSE WHERE id = ?'
    )->execute([$userId]);
    $pdo->prepare('DELETE FROM user_recovery_codes WHERE user_id = ?')->execute([$userId]);
    $pdo->prepare('DELETE FROM user_identities WHERE user_id = ?')->execute([$userId]);
    revokeTrustedDevices($pdo, $userId);
    revokeSessionsFor($pdo, $userId, SESSION_REVOKED_CLAIMED);
    revokePersonalTokens($pdo, $userId, SESSION_REVOKED_CLAIMED);
    audit($request, $userId, 'account.address_claimed', 'user', $userId);
}

function userById(PDO $pdo, int $id): ?array
{
    $statement = $pdo->prepare('SELECT * FROM users WHERE id = ?');
    $statement->execute([$id]);

    return $statement->fetch() ?: null;
}

/** By address, case-insensitively - one mailbox is one account whatever case it is typed in. */
function userByEmail(PDO $pdo, string $email): ?array
{
    if ($email === '') {
        return null;
    }

    $statement = $pdo->prepare('SELECT * FROM users WHERE lower(email) = lower(?)');
    $statement->execute([$email]);

    return $statement->fetch() ?: null;
}

function usernameTaken(PDO $pdo, string $username, ?int $exceptId = null): bool
{
    $statement = $pdo->prepare('SELECT 1 FROM users WHERE lower(username) = lower(?) AND id <> ?');
    $statement->execute([$username, $exceptId ?? 0]);

    return (bool) $statement->fetchColumn();
}

function emailTaken(PDO $pdo, string $email, ?int $exceptId = null): bool
{
    $statement = $pdo->prepare('SELECT 1 FROM users WHERE lower(email) = lower(?) AND id <> ?');
    $statement->execute([$email, $exceptId ?? 0]);

    return (bool) $statement->fetchColumn();
}

/**
 * Whether email and password is a way into this account: a password on the
 * row, and still accepted. An account made through Google has none; one that
 * switched password sign-in off has one that is not to be accepted.
 */
function passwordLoginAvailable(array $user): bool
{
    return $user['password'] !== null && asBool($user['password_login_enabled']);
}

// ── Approval of new accounts ─────────────────────────────────────────────────
//
// Settings -> New accounts need approval (migration 012, the owner's decision
// of 2026-10-06): while it is on, an account a person makes for themselves -
// the registration form, or a Google sign-up - waits (`approved` FALSE) until
// an administrator of the IAM approves it, and signs in nowhere meanwhile:
// signInRefusal() and applicationAccessRefusal() say so on every way in.
// Accounts an administrator makes never wait - the column's default - and
// switching the setting off lets in everybody still waiting.

/** Whether the account waits for an administrator's approval. A row from before 012 has no such column: it does not. */
function accountAwaitsApproval(array $user): bool
{
    return !asBool($user['approved'] ?? true);
}

/**
 * Whether the database has migration 012's `users.approved`. A catalog query
 * that cannot fail, asked afresh every time like signInRulesReady(): a build
 * is uploaded before its migrations, signing up must work in between, and the
 * admin panel's Apply runs in the same process as the requests after it.
 */
function accountApprovalReady(PDO $pdo): bool
{
    return asBool($pdo->query(
        "SELECT EXISTS (SELECT 1 FROM pg_attribute WHERE attrelid = to_regclass('users') AND attname = 'approved' AND NOT attisdropped)"
    )->fetchColumn());
}

/**
 * Makes an account a person signs up for - the registration form, or Google -
 * waiting for an administrator while new accounts need approval. These two
 * are the only places an account is ever made waiting.
 *
 * The switch is read here, under a share lock on its row and in the insert's
 * own transaction, not from the request's settings: a save switching it off
 * (PUT /api/admin/settings) locks that row and approves everybody waiting in
 * one transaction, so the two go one after the other - an account made
 * waiting is either there for the save to let in, or made after it, by the
 * switch as it is then. Read earlier, one could be made waiting just after
 * the save let everybody in, and wait with the switch off.
 *
 * @param array{username: string, email: string, password: ?string, email_confirmed: bool, language: string} $account
 * @return array{id: int, waits: bool}
 */
function insertSignedUpAccount(PDO $pdo, array $account): array
{
    $pdo->beginTransaction();
    try {
        // Before 012 there is neither the switch nor the column: nobody waits.
        $waits = false;
        if (accountApprovalReady($pdo)) {
            $switch = $pdo->query("SELECT value FROM settings WHERE name = 'registration_approval' FOR SHARE")->fetchColumn();
            $waits = $switch !== false && strtolower(trim((string) $switch)) === 'true';
        }

        $insert = $pdo->prepare(
            'INSERT INTO users (username, email, password, email_confirmed, language' . ($waits ? ', approved' : '') . ')
             VALUES (?, ?, ?, ?, ?' . ($waits ? ', FALSE' : '') . ') RETURNING id'
        );
        $insert->execute([$account['username'], $account['email'], $account['password'], pgBool($account['email_confirmed']), $account['language']]);
        $id = (int) $insert->fetchColumn();

        $pdo->commit();
    } catch (\Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    return ['id' => $id, 'waits' => $waits];
}

/**
 * An administrator approves an account that waits and is switched on: the
 * account as it is now, or null when it was not waiting - or was switched off
 * (declined) meanwhile. Decided in the UPDATE, so what is mailed is never the
 * answer to a row read before somebody else's decision.
 */
function approveAccount(PDO $pdo, int $userId): ?array
{
    $statement = $pdo->prepare('UPDATE users SET approved = TRUE WHERE id = ? AND NOT approved AND NOT disabled RETURNING *');
    $statement->execute([$userId]);

    return $statement->fetch() ?: null;
}

/**
 * New accounts no longer need approval: every account still waiting is
 * approved. Not one that was declined - it is switched off, and stays
 * declined: switched on again, it waits for an administrator's word. How many.
 */
function releaseWaitingAccounts(PDO $pdo): int
{
    return (int) $pdo->exec('UPDATE users SET approved = TRUE WHERE NOT approved AND NOT disabled');
}

/** The account as the IAM's own pages see it. */
function accountPayload(PDO $pdo, array $user): array
{
    $id = (int) $user['id'];
    $identity = identityFor($pdo, $id, IDENTITY_GOOGLE);
    $totp = asBool($user['totp_enabled']);

    return [
        'id' => $id,
        'username' => $user['username'],
        'email' => $user['email'],
        'email_confirmed' => asBool($user['email_confirmed']),
        'language' => $user['language'],
        'created_at' => isoTime($user['created_at']),
        'has_password' => $user['password'] !== null,
        'password_login_enabled' => asBool($user['password_login_enabled']),
        'google_connected' => $identity !== null,
        'google_email' => $identity['email'] ?? null,
        'totp_enabled' => $totp,
        'recovery_codes_remaining' => $totp ? recoveryCodesRemaining($pdo, $id) : 0,
        'trusted_devices' => $totp ? trustedDeviceCount($pdo, $id) : 0,
        'force_password_change' => asBool($user['force_password_change']),
        'is_admin' => isIamAdmin($id),
    ];
}

/**
 * A username nobody else has, from what Google offered: the display name,
 * else the local part of the address, then a number. Whoever becomes "peter2"
 * can change it afterwards, which is a smaller annoyance than being asked to
 * invent one before being let in.
 */
function availableUsername(PDO $pdo, string $preferred, string $email): string
{
    $base = trim((string) preg_replace('/\s+/', ' ', $preferred));

    if ($base === '') {
        $base = (string) strstr($email, '@', true);
    }

    $base = mb_substr(trim((string) preg_replace('/[\x00-\x1F\x7F]/', '', $base)), 0, 90);
    if ($base === '') {
        $base = 'player';
    }

    $candidate = $base;
    for ($suffix = 2; usernameTaken($pdo, $candidate); $suffix++) {
        $candidate = $base . $suffix;
    }

    return $candidate;
}

/** The standard claims about an account, filtered by the scopes granted. */
function profileClaims(array $user, string $scope): array
{
    $scopes = explode(' ', $scope);
    $claims = [];

    if (in_array('profile', $scopes, true)) {
        $claims['preferred_username'] = $user['username'];
        // The account's language while the IAM offers it, else English: a
        // language switched off in the admin panel is one no site is told
        // about any more, though the account keeps it for when it is back.
        $claims['locale'] = languageOrDefault($user['language']);
    }

    if (in_array('email', $scopes, true)) {
        $claims['email'] = $user['email'];
        $claims['email_verified'] = asBool($user['email_confirmed']);
    }

    return $claims;
}
