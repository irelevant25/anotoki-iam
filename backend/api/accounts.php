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
 * devices, every session and token, and any outside identity - and a yes to
 * the news from the sites, which was the registrant's to give, not theirs;
 * the browsers the registrant signed in from are forgotten too, so a later
 * sign-in from one of them is mailed to the owner; and a deletion of the
 * account the registrant asked for is cancelled - it was never the owner's
 * request. The account is then the owner's alone, and they add back whatever
 * ways in they want. An account confirmed all along is left as it is: its
 * credentials were set by somebody who had proved the address.
 *
 * Call it with the account as it was before anything confirmed it.
 */
function claimUnconfirmedAccount(PDO $pdo, \Psr\Http\Message\ServerRequestInterface $request, array $user): void
{
    if (asBool($user['email_confirmed'])) {
        return;
    }

    $userId = (int) $user['id'];

    $pdo->prepare('UPDATE users SET password = NULL, totp_enabled = FALSE, totp_secret = NULL, totp_last_step = NULL, force_password_change = FALSE WHERE id = ?')->execute([$userId]);
    if (announcementsReady($pdo)) {
        $pdo->prepare('UPDATE users SET news_mail = FALSE WHERE id = ?')->execute([$userId]);
    }
    $pdo->prepare('DELETE FROM user_recovery_codes WHERE user_id = ?')->execute([$userId]);
    $pdo->prepare('DELETE FROM user_identities WHERE user_id = ?')->execute([$userId]);
    revokeTrustedDevices($pdo, $userId);
    forgetSignInDevices($pdo, $userId);
    revokeSessionsFor($pdo, $userId, SESSION_REVOKED_CLAIMED);
    revokePersonalTokens($pdo, $userId, SESSION_REVOKED_CLAIMED);
    audit($request, $userId, 'account.address_claimed', 'user', $userId);

    // The registrant's deletion request goes with the rest (016): the owner
    // did not ask for it. Never once the account is deleted - then there is
    // nothing left to keep.
    if (accountDeletionReady($pdo)) {
        $cancelled = $pdo->prepare(
            'UPDATE users SET deletion_requested_at = NULL, deletion_due_at = NULL
              WHERE id = ? AND deletion_due_at IS NOT NULL AND anonymized_at IS NULL RETURNING ' . recordTimeSql('CURRENT_TIMESTAMP'),
        );
        $cancelled->execute([$userId]);
        $at = $cancelled->fetchColumn();
        if ($at !== false) {
            audit($request, $userId, 'account.deletion_cancelled', 'user', $userId, ['by' => 'address_claimed']);
            // Committed with its statement: a line of the record beside the database (api/account_deletion.php).
            recordAccountDeletionEvent('cancelled', $userId, (string) $at);
        }
    }
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

/** Without a password to ask for, a sign-in this recent (the session's auth_time) is the proof. */
const ACCOUNT_PROOF_RECENT_SIGN_IN = 900;

/** Whether the session's sign-in - credentials last presented, its auth_time - is recent enough to stand for the person. */
function recentSignIn(array $session): bool
{
    return strtotime((string) $session['auth_time']) + ACCOUNT_PROOF_RECENT_SIGN_IN >= time();
}

/** The refusal of a sign-in too old to stand for the person, where there is no password to ask for instead. */
function reauthenticationRequired(): array
{
    return ['status' => 403, 'code' => 'reauthentication_required', 'error' => 'Sign in again first: without a password, this needs a sign-in of the last 15 minutes'];
}

/**
 * The proof the account page asks before what a session alone must not do:
 * deleting the account, and every change of how the account signs in that
 * would let a session make that proof for itself - a first password (then the
 * deletion asks for the password the session just chose), Google connected
 * (the session's holder's own Google, for a fresh sign-in of their own), and
 * password sign-in switched on or off (which proof the account asks for) -
 * or lock the owner out: two-factor switched on with the holder's own
 * authenticator, the recovery codes theirs too. One rule for all of them, so
 * none is the way around another:
 *
 *   - the account's password, wherever it is a way in (passwordLoginAvailable()):
 *     whoever holds a session left open somewhere does not know it;
 *   - otherwise a sign-in of the last fifteen minutes - the session's own
 *     auth_time, which only credentials presented afresh set.
 *
 * Limited like a sign-in, wrong passwords counted (with `$method`), so a
 * stolen session is no way to guess at the password either.
 *
 * @return array{proof: 'password'|'recent_sign_in'}|array{status: int, code: string, error: string}
 */
function accountProof(PDO $pdo, \Psr\Http\Message\ServerRequestInterface $request, array $user, array $session, mixed $password, string $method): array
{
    if (loginAttemptsExceeded($pdo, $request, (string) $user['email'])) {
        return ['status' => 429, 'code' => 'too_many_attempts', 'error' => 'Too many attempts. Wait a few minutes and try again.'];
    }

    if (passwordLoginAvailable($user)) {
        $given = is_string($password) ? $password : '';
        // 422, not 401, as for a change of password: the session is fine.
        if ($given === '' || !password_verify($given, (string) $user['password'])) {
            recordLoginAttempt($pdo, $request, (string) $user['email'], (int) $user['id'], $method, 'bad_password');
            return ['status' => 422, 'code' => 'wrong_password', 'error' => 'The current password is not right'];
        }

        return ['proof' => 'password'];
    }

    if (!recentSignIn($session)) {
        return reauthenticationRequired();
    }

    return ['proof' => 'recent_sign_in'];
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
    return asBool($pdo->query("SELECT EXISTS (SELECT 1 FROM pg_attribute WHERE attrelid = to_regclass('users') AND attname = 'approved' AND NOT attisdropped)")->fetchColumn());
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
 * `news_mail` is what the person chose: the registration form offers the news
 * from the sites unticked (migration 014), so an account is made with it off
 * unless they ticked it. Before 013 there is no such column, and no choice.
 *
 * @param array{username: string, email: string, password: ?string, email_confirmed: bool, language: string, news_mail?: bool} $account
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

        $columns = [
            'username' => $account['username'],
            'email' => $account['email'],
            'password' => $account['password'],
            'email_confirmed' => pgBool($account['email_confirmed']),
            'language' => $account['language'],
        ];
        if ($waits) {
            $columns['approved'] = pgBool(false);
        }
        if (announcementsReady($pdo)) {
            $columns['news_mail'] = pgBool(($account['news_mail'] ?? false) === true);
        }

        $insert = $pdo->prepare(
            'INSERT INTO users (' .
                implode(', ', array_keys($columns)) .
                ')
             VALUES (' .
                implode(', ', array_fill(0, count($columns), '?')) .
                ') RETURNING id',
        );
        $insert->execute(array_values($columns));
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

// ── Display preferences ──────────────────────────────────────────────────────
//
// How the person reads every anotoki site (migration 015, the owner's
// decision of 2026-10-06): the theme, how dates are written, the clock - and
// the language, the account's already. Kept by the IAM, given to every site in
// the `preferences` claim (and `locale`), and changed on the account page or by
// a site for its signed-in person (POST /api/oidc/preferences).

const PREFERENCE_THEMES = ['auto', 'light', 'dark'];
const PREFERENCE_DATE_FORMATS = ['dmy_dot', 'dmy_slash', 'mdy_slash', 'ymd_dash'];
const PREFERENCE_TIME_FORMATS = ['24', '12'];

/**
 * Whether migration 015's columns are there. A catalog query that cannot
 * fail, asked afresh like the others: a build goes up before its migrations.
 */
function preferencesReady(PDO $pdo): bool
{
    return asBool($pdo->query("SELECT EXISTS (SELECT 1 FROM pg_attribute WHERE attrelid = to_regclass('users') AND attname = 'theme' AND NOT attisdropped)")->fetchColumn());
}

/**
 * The account's theme, date format and clock as the sites are told them - or
 * null for a row from before 015, which has no such columns. A date format or
 * clock not chosen is null: each site writes them its own way.
 *
 * @return array{theme: string, date_format: ?string, time_format: ?string}|null
 */
function accountPreferences(array $user): ?array
{
    if (!array_key_exists('theme', $user)) {
        return null;
    }

    return [
        'theme' => in_array($user['theme'], PREFERENCE_THEMES, true) ? $user['theme'] : 'auto',
        'date_format' => in_array($user['date_format'], PREFERENCE_DATE_FORMATS, true) ? $user['date_format'] : null,
        'time_format' => in_array($user['time_format'], PREFERENCE_TIME_FORMATS, true) ? $user['time_format'] : null,
    ];
}

/**
 * What a body asks to change of the preferences, checked - the account page's
 * and a site's alike: ['changes' => [column => value]], or ['error' => a
 * sentence]. A field left out stays as it is. A date format or clock of null,
 * '' or 'auto' is "automatic" again. The language must be one the IAM offers:
 * a site that speaks one the IAM does not keeps it for its own page.
 *
 * @return array{changes: array<string, ?string>}|array{error: string}
 */
function preferenceChanges(PDO $pdo, array $body): array
{
    $changes = [];

    if (array_key_exists('theme', $body)) {
        if (!in_array($body['theme'], PREFERENCE_THEMES, true)) {
            return ['error' => 'theme is one of ' . implode(', ', PREFERENCE_THEMES)];
        }
        $changes['theme'] = $body['theme'];
    }

    foreach (['date_format' => PREFERENCE_DATE_FORMATS, 'time_format' => PREFERENCE_TIME_FORMATS] as $field => $allowed) {
        if (!array_key_exists($field, $body)) {
            continue;
        }
        $value = $body[$field];
        if ($value === null || $value === '' || $value === 'auto') {
            $changes[$field] = null;
        } elseif (is_string($value) && in_array($value, $allowed, true)) {
            $changes[$field] = $value;
        } else {
            return ['error' => "$field is one of " . implode(', ', $allowed) . ', or null for automatic'];
        }
    }

    if (array_key_exists('language', $body)) {
        $language = is_string($body['language']) ? strtolower(trim($body['language'])) : '';
        if (!in_array($language, languageCodes($pdo), true)) {
            return ['error' => 'language is one of ' . implode(', ', languageCodes($pdo))];
        }
        $changes['language'] = $language;
    }

    return ['changes' => $changes];
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
        // Not while the account's deletion is pending: it administers nothing
        // then (requireAdmin()), and the pages offer no admin panel.
        'is_admin' => isIamAdmin($id) && !accountDeletionPending($user),
        // Whether the account gets the announcements; null while migration
        // 013 is pending, and the account page shows no switch.
        'news_mail' => array_key_exists('news_mail', $user) ? asBool($user['news_mail']) : null,
        // The theme, dates and clock every site follows; null while 015 is
        // pending, and the account page shows the theme per device as before.
        'preferences' => accountPreferences($user),
        // When the account is deleted, while its deletion is pending (016);
        // null otherwise, and while 016 is pending.
        'deletion_due_at' => accountDeletionPending($user) ? isoTime((string) $user['deletion_due_at']) : null,
    ];
}

/**
 * A username nobody else has, for a Google sign-up, made to fit the rule every
 * name keeps (letters and digits, usernameRefusal()) by usernameFrom(): from
 * the display name Google offered ("František Pastorek" FrantisekPastorek),
 * else the address before its @ (frantisek.pastorek@... frantisekpastorek) -
 * a name of only another script's letters leaves nothing, and the next is
 * tried -, else "player"; then a number while it is taken, regardless of case.
 * At most 90 letters, so the number fits within the 100. Whoever becomes
 * "peter2" can change it afterwards, which is a smaller annoyance than being
 * asked to invent one before being let in.
 */
function availableUsername(PDO $pdo, string $preferred, string $email): string
{
    $room = USERNAME_MAX - 10;
    $base = usernameFrom($preferred, $room);

    if ($base === '') {
        $base = usernameFrom((string) strstr($email, '@', true), $room);
    }

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
        // How every site is to be read (migration 015): the theme, dates and
        // clock. Left out while 015 is pending - a site then keeps its own.
        if (($preferences = accountPreferences($user)) !== null) {
            $claims['preferences'] = $preferences;
        }
    }

    if (in_array('email', $scopes, true)) {
        $claims['email'] = $user['email'];
        $claims['email_verified'] = asBool($user['email_confirmed']);
    }

    return $claims;
}
