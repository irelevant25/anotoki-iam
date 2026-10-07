<?php

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

// ---------------------------------------------------------------------------
// The account page: an account managing itself
//
// Everything here is scoped to the signed-in account by the query rather than
// by a check afterwards - there is no path through which one account names
// another's session, token or device.
//
// An account must always keep a way in. Disconnecting Google from an account
// with no password is refused; turning password sign-in off without Google is
// refused; and the page reads the same conditions to decide what to offer.
// ---------------------------------------------------------------------------

function me(Request $request): array
{
    return $request->getAttribute('user');
}

function currentSession(Request $request): array
{
    return $request->getAttribute('session');
}

function accountAnswer(Response $response, int $userId): Response
{
    $pdo = iamDb();

    return respondJson($response, accountPayload($pdo, userById($pdo, $userId)));
}

/**
 * The account was deleted while this request was on its way (its write found
 * no row: liveAccountSql()). Nothing of it is written, and its session is
 * gone with it: the answer a request with no session gets.
 */
function accountGone(Response $response): Response
{
    return withoutSessionCookie(respondError($response, 401, 'no_session', 'Sign in first'));
}

/** accountProof()'s refusal, answered; null when the proof was given. */
function proofRefusal(Response $response, array $proof): ?Response
{
    return isset($proof['code']) ? respondError($response, $proof['status'], $proof['code'], $proof['error']) : null;
}

$app->group('/api/account', function (\Slim\Routing\RouteCollectorProxy $group) {

    $group->get('', function (Request $request, Response $response) {
        return respondJson($response, accountPayload(iamDb(), me($request)));
    });

    // ── Profile ──────────────────────────────────────────────────────────────

    $group->put('/profile', function (Request $request, Response $response) {
        $body = requestBody($request);
        $user = me($request);
        $pdo = iamDb();
        $changes = [];

        if (array_key_exists('username', $body)) {
            $username = field($body, 'username');
            if ($refusal = usernameRefusal($username)) {
                return respondError($response, 422, 'invalid_username', $refusal);
            }
            if (usernameTaken($pdo, $username, (int) $user['id'])) {
                return respondError($response, 409, 'username_taken', 'That username is already taken');
            }
            $changes['username'] = $username;
        }

        if (array_key_exists('language', $body)) {
            $language = strtolower(field($body, 'language'));
            if (!in_array($language, languageCodes(), true)) {
                return respondError($response, 422, 'invalid_language', 'Unknown language');
            }
            $changes['language'] = $language;
        }

        // The news from the sites (announcements, migration 013): the switch
        // the link at the end of every announcement turns off.
        if (array_key_exists('news_mail', $body)) {
            if (!is_bool($body['news_mail'])) {
                return respondError($response, 422, 'invalid_input', 'news_mail is true or false');
            }
            if (!announcementsReady($pdo)) {
                $refusal = announcementsUnavailable();
                return respondError($response, $refusal['status'], $refusal['code'], $refusal['error']);
            }
            $changes['news_mail'] = pgBool($body['news_mail']);
        }

        // The theme, dates and clock every site follows (migration 015),
        // checked as a site's own save is (preferenceChanges()).
        $preferences = array_intersect_key($body, array_flip(['theme', 'date_format', 'time_format']));
        if ($preferences !== []) {
            if (!preferencesReady($pdo)) {
                return respondError($response, 503, 'preferences_unavailable', 'The display preferences are not in the database yet. Apply the pending migrations in the admin panel.');
            }
            $checked = preferenceChanges($pdo, $preferences);
            if (isset($checked['error'])) {
                return respondError($response, 422, 'invalid_preference', $checked['error']);
            }
            $changes += $checked['changes'];
        }

        if ($changes !== []) {
            $sets = implode(', ', array_map(static fn(string $column): string => $column . ' = ?', array_keys($changes)));
            $saved = $pdo->prepare("UPDATE users SET $sets WHERE id = ?" . liveAccountSql($pdo));
            $saved->execute([...array_values($changes), (int) $user['id']]);
            if ($saved->rowCount() === 0) {
                return accountGone($response);
            }

            if (isset($changes['username'])) {
                audit($request, (int) $user['id'], 'account.username_changed', 'user', $user['id'], ['from' => $user['username'], 'to' => $changes['username']]);
            }
            if (isset($changes['news_mail']) && $body['news_mail'] !== asBool($user['news_mail'] ?? true)) {
                audit($request, (int) $user['id'], 'account.news_mail', 'user', $user['id'], ['news_mail' => $body['news_mail'], 'by' => 'account']);
            }
        }

        return accountAnswer($response, (int) $user['id']);
    });

    // ── Password ─────────────────────────────────────────────────────────────

    $group->put('/password', function (Request $request, Response $response) {
        $body = requestBody($request);
        $user = me($request);
        $current = (string) ($body['current_password'] ?? '');
        $next = (string) ($body['new_password'] ?? '');
        $pdo = iamDb();

        // Limited like a sign-in, every wrong current password counted: a
        // stolen session must not be a way to guess at the password - which
        // is the proof the account page asks for everything that matters.
        if (loginAttemptsExceeded($pdo, $request, (string) $user['email'])) {
            return tooManyAttempts($response);
        }

        // 422, not 401: the caller is signed in; what is wrong is a value in
        // the body. A 401 would read as "session expired" and sign them out
        // for mistyping their own password.
        if ($user['password'] === null) {
            return respondError($response, 422, 'wrong_password', 'The current password is not right');
        }
        if (!password_verify($current, (string) $user['password'])) {
            recordLoginAttempt($pdo, $request, (string) $user['email'], (int) $user['id'], ATTEMPT_METHOD_ACCOUNT_PASSWORD, 'bad_password');
            return respondError($response, 422, 'wrong_password', 'The current password is not right');
        }

        if ($refusal = passwordRefusal($next)) {
            return respondError($response, 422, 'invalid_password', $refusal);
        }

        $changed = $pdo->prepare('UPDATE users SET password = ?, force_password_change = FALSE WHERE id = ?' . liveAccountSql($pdo));
        $changed->execute([password_hash($next, PASSWORD_DEFAULT), (int) $user['id']]);
        if ($changed->rowCount() === 0) {
            return accountGone($response);
        }

        // Changing a password is what somebody does when they think another
        // person has it; leaving that person signed in would make it ceremonial.
        revokeSessionsFor($pdo, (int) $user['id'], SESSION_REVOKED_PASSWORD, (int) currentSession($request)['id']);
        revokeTrustedDevices($pdo, (int) $user['id']);
        audit($request, (int) $user['id'], 'account.password_changed', 'user', $user['id']);

        return respondJson($response, ['message' => 'Password changed. Everywhere else has been signed out.']);
    });

    // A first password, on an account with none. Also the one way an account
    // without a password can satisfy a forced change. A session alone is not
    // enough (accountProof()): with a password it chose, it would have the
    // very proof the account's deletion asks for. The account has no password
    // to ask for, so a sign-in of the last fifteen minutes is the proof - as
    // right after signing in with Google or an emailed code.
    $group->post('/password/set', function (Request $request, Response $response) {
        $user = me($request);
        $password = (string) (requestBody($request)['password'] ?? '');
        $pdo = iamDb();

        if ($user['password'] !== null) {
            return respondError($response, 409, 'password_already_set', 'This account already has a password');
        }

        if ($refusal = passwordRefusal($password)) {
            return respondError($response, 422, 'invalid_password', $refusal);
        }

        $proof = accountProof($pdo, $request, $user, currentSession($request), null, ATTEMPT_METHOD_ACCOUNT_PASSWORD);
        if ($refused = proofRefusal($response, $proof)) {
            return $refused;
        }

        $set = $pdo->prepare('UPDATE users SET password = ?, password_login_enabled = TRUE, force_password_change = FALSE WHERE id = ? AND password IS NULL' . liveAccountSql($pdo));
        $set->execute([password_hash($password, PASSWORD_DEFAULT), (int) $user['id']]);
        if ($set->rowCount() === 0) {
            // Set by another request a moment ago, or the account went.
            return userById($pdo, (int) $user['id'])['password'] ?? null
                ? respondError($response, 409, 'password_already_set', 'This account already has a password')
                : accountGone($response);
        }
        audit($request, (int) $user['id'], 'account.password_set', 'user', $user['id'], ['proof' => $proof['proof']]);

        return accountAnswer($response, (int) $user['id']);
    });

    // {enabled, password?}: which proof the account asks for is what this
    // switches - so it asks for it itself (accountProof()): the password while
    // the password is a way in (switching it off), else a sign-in of the last
    // fifteen minutes (switching it back on).
    $group->put('/password/enabled', function (Request $request, Response $response) {
        $user = me($request);
        $body = requestBody($request);
        $enabled = !empty($body['enabled']);
        $pdo = iamDb();

        if ($enabled && $user['password'] === null) {
            return respondError($response, 409, 'no_password', 'There is no password on this account to switch on');
        }

        if (!$enabled && identityFor($pdo, (int) $user['id'], IDENTITY_GOOGLE) === null) {
            return respondError($response, 409, 'would_lock_account', 'Connect Google first, or you would not be able to sign in again');
        }

        $proof = accountProof($pdo, $request, $user, currentSession($request), $body['password'] ?? null, ATTEMPT_METHOD_ACCOUNT_PASSWORD);
        if ($refused = proofRefusal($response, $proof)) {
            return $refused;
        }

        $switched = $pdo->prepare('UPDATE users SET password_login_enabled = ? WHERE id = ?' . liveAccountSql($pdo));
        $switched->execute([pgBool($enabled), (int) $user['id']]);
        if ($switched->rowCount() === 0) {
            return accountGone($response);
        }
        audit($request, (int) $user['id'], $enabled ? 'account.password_login_on' : 'account.password_login_off', 'user', $user['id'], ['proof' => $proof['proof']]);

        return accountAnswer($response, (int) $user['id']);
    });

    // ── Google ───────────────────────────────────────────────────────────────

    // {credential, password?}: another way in, so a session alone is not
    // enough (accountProof()) - a Google account of whoever holds the session
    // would sign them in afresh whenever they liked. The password while it is
    // a way in, else a sign-in of the last fifteen minutes.
    $group->post('/google', function (Request $request, Response $response) {
        if (!googleLoginEnabled()) {
            return respondError($response, 403, 'google_disabled', 'Google sign-in is switched off');
        }

        $user = me($request);
        $body = requestBody($request);
        $pdo = iamDb();

        $proof = accountProof($pdo, $request, $user, currentSession($request), $body['password'] ?? null, ATTEMPT_METHOD_ACCOUNT_PASSWORD);
        if ($refused = proofRefusal($response, $proof)) {
            return $refused;
        }

        $claims = googleVerifyIdToken((string) ($body['credential'] ?? ''));

        if ($claims === null) {
            return respondError($response, 400, 'invalid_token', 'That Google sign-in could not be verified');
        }

        $owner = identityOwner($pdo, IDENTITY_GOOGLE, (string) $claims['sub']);

        if ($owner !== null) {
            return (int) $owner['id'] === (int) $user['id']
                ? accountAnswer($response, (int) $user['id'])
                : respondError($response, 409, 'google_taken', 'That Google account is already connected to another account');
        }

        if (identityFor($pdo, (int) $user['id'], IDENTITY_GOOGLE) !== null) {
            return respondError($response, 409, 'google_already_connected', 'This account already has Google connected');
        }

        attachIdentity($pdo, (int) $user['id'], IDENTITY_GOOGLE, (string) $claims['sub'], strtolower(trim((string) ($claims['email'] ?? ''))) ?: null);
        audit($request, (int) $user['id'], 'account.google_connected', 'user', $user['id'], ['proof' => $proof['proof']]);

        return accountAnswer($response, (int) $user['id']);
    });

    $group->delete('/google', function (Request $request, Response $response) {
        $user = me($request);
        $pdo = iamDb();

        if (identityFor($pdo, (int) $user['id'], IDENTITY_GOOGLE) === null) {
            return respondError($response, 409, 'google_not_connected', 'This account does not have Google connected');
        }

        if (!passwordLoginAvailable($user)) {
            return respondError($response, 409, 'would_lock_account', 'Set a password first, or you would not be able to sign in again');
        }

        $pdo->prepare('DELETE FROM user_identities WHERE user_id = ? AND provider = ?')->execute([(int) $user['id'], IDENTITY_GOOGLE]);
        audit($request, (int) $user['id'], 'account.google_disconnected', 'user', $user['id']);

        return accountAnswer($response, (int) $user['id']);
    });

    // ── Two-factor ───────────────────────────────────────────────────────────
    //
    // Three steps, because the middle one matters: a secret is issued and
    // shown, a code computed from it comes back as proof it was really scanned,
    // and only then does the account start requiring one. An account that
    // demanded codes the moment a QR code was drawn would lock out anybody
    // whose camera did not focus.

    // Setting up keeps a secret waiting, which opens nothing, and asks for no
    // proof itself. But an account with no password to ask for will need a
    // sign-in of the last fifteen minutes to enable it (accountProof()): an
    // older one is turned away here, before a secret is made - or the person
    // would scan a code they must throw away, the next setup making another.
    // An account whose password is a way in gives the password at enable.
    $group->post('/2fa/setup', function (Request $request, Response $response) {
        $user = me($request);

        if (asBool($user['totp_enabled'])) {
            return respondError($response, 409, 'totp_already_on', 'Two-factor authentication is already on');
        }
        if (!passwordLoginAvailable($user) && !recentSignIn(currentSession($request))) {
            $refusal = reauthenticationRequired();
            return respondError($response, $refusal['status'], $refusal['code'], $refusal['error']);
        }

        $pdo = iamDb();
        $secret = totpSecret();
        $kept = $pdo->prepare('UPDATE users SET totp_secret = ? WHERE id = ?' . liveAccountSql($pdo));
        $kept->execute([encryptSecret($secret), (int) $user['id']]);
        if ($kept->rowCount() === 0) {
            return accountGone($response);
        }

        return respondJson($response, ['secret' => $secret, 'uri' => totpUri($secret, (string) $user['email'])]);
    });

    // {code, password?}. The recovery codes are generated here and shown this
    // once; they are stored hashed, so nobody can show them again.
    //
    // A session alone must not switch the second factor on: with an
    // authenticator of its holder's own, it would take the recovery codes and
    // keep the owner out until an administrator reset two-factor. So the proof
    // first (accountProof()) - the password while it is a way in, else a
    // sign-in of the last fifteen minutes - and only then the code, as
    // connecting Google asks for it before reading the credential. Setting up
    // (above) only keeps a secret waiting, which opens nothing; it turns away
    // early only the stale sign-in this would turn away.
    $group->post('/2fa/enable', function (Request $request, Response $response) {
        $user = me($request);
        $body = requestBody($request);
        $pdo = iamDb();
        $secret = totpSecretOf($user);

        if (asBool($user['totp_enabled'])) {
            return respondError($response, 409, 'totp_already_on', 'Two-factor authentication is already on');
        }
        if ($secret === null) {
            return respondError($response, 409, 'totp_not_started', 'Start again - there is no secret to confirm');
        }

        $proof = accountProof($pdo, $request, $user, currentSession($request), $body['password'] ?? null, ATTEMPT_METHOD_ACCOUNT_PASSWORD);
        if ($refused = proofRefusal($response, $proof)) {
            return $refused;
        }

        $step = totpMatchedStep($secret, (string) ($body['code'] ?? ''));
        if ($step === null) {
            return respondError($response, 400, 'totp_invalid', 'That code is not right');
        }

        // The confirming code counts as used, like any other: it cannot then
        // sign somebody in a second later.
        $on = $pdo->prepare('UPDATE users SET totp_enabled = TRUE, totp_last_step = ? WHERE id = ?' . liveAccountSql($pdo));
        $on->execute([$step, (int) $user['id']]);
        if ($on->rowCount() === 0) {
            return accountGone($response);
        }
        $codes = generateRecoveryCodes($pdo, (int) $user['id']);

        // The other sessions were signed in without the second factor - what
        // was just decided to be not enough.
        revokeSessionsFor($pdo, (int) $user['id'], SESSION_REVOKED_SECURITY, (int) currentSession($request)['id']);
        revokeTrustedDevices($pdo, (int) $user['id']);
        audit($request, (int) $user['id'], 'account.2fa_on', 'user', $user['id'], ['proof' => $proof['proof']]);

        return respondJson($response, ['recovery_codes' => $codes]);
    });

    // A current code is needed to turn it off - somebody at a signed-in machine
    // should not be able to quietly remove what would have stopped them. A
    // recovery code is accepted too: a lost phone is exactly when this is needed.
    $group->post('/2fa/disable', function (Request $request, Response $response) {
        $user = me($request);
        $pdo = iamDb();

        if (!asBool($user['totp_enabled'])) {
            return respondError($response, 409, 'totp_not_on', 'Two-factor authentication is not on');
        }
        if (loginAttemptsExceeded($pdo, $request, (string) $user['email'])) {
            return tooManyAttempts($response);
        }
        if (!totpChallengePassed($pdo, $user, (string) (requestBody($request)['code'] ?? ''))) {
            recordLoginAttempt($pdo, $request, (string) $user['email'], (int) $user['id'], ATTEMPT_METHOD_ACCOUNT_TOTP, 'bad_totp');
            return respondError($response, 400, 'totp_invalid', 'That code is not right');
        }

        $off = $pdo->prepare('UPDATE users SET totp_enabled = FALSE, totp_secret = NULL WHERE id = ?' . liveAccountSql($pdo));
        $off->execute([(int) $user['id']]);
        if ($off->rowCount() === 0) {
            return accountGone($response);
        }
        $pdo->prepare('DELETE FROM user_recovery_codes WHERE user_id = ?')->execute([(int) $user['id']]);
        revokeSessionsFor($pdo, (int) $user['id'], SESSION_REVOKED_SECURITY, (int) currentSession($request)['id']);
        revokeTrustedDevices($pdo, (int) $user['id']);
        audit($request, (int) $user['id'], 'account.2fa_off', 'user', $user['id']);

        return accountAnswer($response, (int) $user['id']);
    });

    // An authenticator code only: a recovery code must not be able to mint ten
    // more of itself, which would make one leaked code permanent.
    $group->post('/2fa/recovery', function (Request $request, Response $response) {
        $user = me($request);
        $pdo = iamDb();

        if (!asBool($user['totp_enabled'])) {
            return respondError($response, 409, 'totp_not_on', 'Two-factor authentication is not on');
        }
        // Limited like a sign-in: a stolen session guessing its way to a fresh
        // set of recovery codes would own the second factor for good.
        if (loginAttemptsExceeded($pdo, $request, (string) $user['email'])) {
            return tooManyAttempts($response);
        }
        if (!totpCodeAccepted($pdo, $user, (string) (requestBody($request)['code'] ?? ''))) {
            recordLoginAttempt($pdo, $request, (string) $user['email'], (int) $user['id'], ATTEMPT_METHOD_ACCOUNT_TOTP, 'bad_totp');
            return respondError($response, 400, 'totp_invalid', 'That code is not right');
        }

        audit($request, (int) $user['id'], 'account.recovery_codes_renewed', 'user', $user['id']);

        return respondJson($response, ['recovery_codes' => generateRecoveryCodes(iamDb(), (int) $user['id'])]);
    });

    $group->delete('/devices', function (Request $request, Response $response) {
        return respondJson($response, ['ended' => revokeTrustedDevices(iamDb(), (int) me($request)['id'])]);
    });

    // ── Sessions ─────────────────────────────────────────────────────────────

    $group->get('/sessions', function (Request $request, Response $response) {
        $user = me($request);
        $pdo = iamDb();
        $currentId = (int) currentSession($request)['id'];

        return respondJson($response, [
            'sessions' => array_map(static fn(array $row): array => sessionEntry($row, $currentId), sessionsFor($pdo, (int) $user['id'])),
            'failed_since_last_login' => failedAttemptsSinceLastLogin($pdo, (int) $user['id']),
        ]);
    });

    // The user_id in the WHERE is what makes this safe: somebody else's session
    // is not found rather than refused, so ids cannot be probed.
    $group->delete('/sessions/{id:[0-9]+}', function (Request $request, Response $response, array $args) {
        $pdo = iamDb();
        $statement = $pdo->prepare('SELECT id FROM sessions WHERE id = ? AND user_id = ?');
        $statement->execute([(int) $args['id'], (int) me($request)['id']]);

        if (!$statement->fetch()) {
            return respondError($response, 404, 'not_found', 'Not found');
        }

        revokeSession($pdo, (int) $args['id'], SESSION_REVOKED_ELSEWHERE);

        return respondJson($response, ['message' => 'Session ended']);
    });

    $group->delete('/sessions', function (Request $request, Response $response) {
        return respondJson($response, [
            'ended' => revokeSessionsFor(iamDb(), (int) me($request)['id'], SESSION_REVOKED_ELSEWHERE, (int) currentSession($request)['id']),
        ]);
    });

    // ── Applications ─────────────────────────────────────────────────────────

    $group->get('/applications', function (Request $request, Response $response) {
        $user = me($request);
        $pdo = iamDb();

        $statement = $pdo->prepare(
            "SELECT a.*,
                    COALESCE((SELECT json_agg(r.name ORDER BY r.name) FROM user_roles ur
                                JOIN application_roles r ON r.id = ur.role_id
                               WHERE ur.user_id = ? AND r.application_id = a.id), '[]') AS my_roles,
                    (SELECT max(rt.last_used_at) FROM refresh_tokens rt WHERE rt.user_id = ? AND rt.application_id = a.id) AS last_used_at
               FROM applications a
              WHERE a.system = FALSE
              ORDER BY a.sort_order, a.name"
        );
        $statement->execute([(int) $user['id'], (int) $user['id']]);

        $applications = [];
        foreach ($statement->fetchAll() as $row) {
            $roles = json_decode((string) $row['my_roles'], true) ?: [];
            $canUse = applicationAccessRefusal($row, $user) === null;

            // An application nobody invited this account to is not worth
            // listing - it is not something they can do anything with. That is
            // one whose roles keep this account out while it holds none there
            // but the MEMBER every account is given (or none at all: MEMBER
            // taken away, or 010 still pending). One the account is invited to
            // is listed, saying when it cannot be used.
            if (array_diff($roles, ['MEMBER']) === [] && rolesKeepOut($row, (int) $user['id'])) {
                continue;
            }

            $applications[] = applicationSummary($row) + [
                'roles' => $roles,
                'can_use' => $canUse,
                'last_used_at' => isoTime($row['last_used_at']),
            ];
        }

        return respondJson($response, ['applications' => $applications]);
    });

    // ── Tokens for scripts ───────────────────────────────────────────────────

    $group->get('/tokens', function (Request $request, Response $response) {
        return respondJson($response, ['tokens' => personalTokensOf(iamDb(), (int) me($request)['id'])]);
    });

    $group->post('/tokens', function (Request $request, Response $response) {
        $user = me($request);
        $body = requestBody($request);
        $pdo = iamDb();
        $name = field($body, 'name');
        $days = (int) ($body['expires_in_days'] ?? 0);
        $application = applicationByClientId(field($body, 'client_id'));
        $maxDays = (int) iamConfig()['personal_token_max_days'];

        if ($name === '' || mb_strlen($name) > 100) {
            return respondError($response, 422, 'invalid_name', 'Give the token a name of up to 100 characters');
        }
        if ($days < 1 || $days > $maxDays) {
            return respondError($response, 422, 'invalid_expiry', "Tokens last between 1 and $maxDays days");
        }
        if ($application === null || asBool($application['system'])) {
            return respondError($response, 422, 'invalid_application', 'Choose an application');
        }
        if ($refusal = applicationAccessRefusal($application, $user)) {
            return respondError($response, $refusal['status'], $refusal['code'], $refusal['error']);
        }

        $issued = issueRefreshToken(
            $pdo, 'personal', randomHex(16), (int) $application['id'], (int) $user['id'], null,
            'openid profile email', $days * 86400, $name
        );
        audit($request, (int) $user['id'], 'account.token_created', 'application', $application['client_id'], ['name' => $name, 'days' => $days]);

        $entry = array_values(array_filter(personalTokensOf($pdo, (int) $user['id']), static fn(array $t): bool => $t['id'] === $issued['id']))[0] ?? null;

        return respondJson($response, ['token' => $issued['token'], 'entry' => $entry], 201);
    });

    $group->delete('/tokens/{id:[0-9]+}', function (Request $request, Response $response, array $args) {
        $statement = iamDb()->prepare(
            "UPDATE refresh_tokens SET revoked_at = CURRENT_TIMESTAMP, revoked_reason = 'revoked'
              WHERE id = ? AND user_id = ? AND kind = 'personal' AND revoked_at IS NULL"
        );
        $statement->execute([(int) $args['id'], (int) me($request)['id']]);

        if ($statement->rowCount() !== 1) {
            return respondError($response, 404, 'not_found', 'Not found');
        }

        audit($request, (int) me($request)['id'], 'account.token_revoked', 'token', (int) $args['id']);

        return respondJson($response, ['message' => 'Token revoked']);
    });

    // ── Deleting the account ─────────────────────────────────────────────────
    //
    // The owner's decision of 2026-10-07 (api/account_deletion.php): asked
    // for here, done thirty days later, and cancelled here until then. Only
    // the account itself asks: an administrator can neither ask nor cancel on
    // anybody's behalf.

    // {password?}: the proof (accountProof()). The password, wherever it is a
    // way in - a session left open somewhere must not be enough to close an
    // account. Without one (Google, or password sign-in switched off), a
    // sign-in of the last fifteen minutes: the page sends the person to sign
    // in again. Nothing the session can change makes that proof for it: a
    // first password, Google connected and password sign-in switched on or
    // off ask for the same proof themselves. Limited like a sign-in - and each
    // request is a mail.
    $group->post('/deletion', function (Request $request, Response $response) {
        $pdo = iamDb();
        $user = me($request);
        $session = currentSession($request);

        if (!accountDeletionReady($pdo)) {
            $refusal = accountDeletionUnavailable();
            return respondError($response, $refusal['status'], $refusal['code'], $refusal['error']);
        }
        if (accountDeletionPending($user)) {
            return respondError($response, 409, 'deletion_already_requested', 'The deletion of this account is asked for already');
        }

        $proof = accountProof($pdo, $request, $user, $session, requestBody($request)['password'] ?? null, ATTEMPT_METHOD_ACCOUNT_DELETION);
        if ($refused = proofRefusal($response, $proof)) {
            return $refused;
        }

        $result = requestAccountDeletion($pdo, $request, $user, (int) $session['id'], $proof['proof']);
        if (isset($result['code'])) {
            return respondError($response, $result['status'], $result['code'], $result['error']);
        }

        // Counted with the limiter's mail-sending requests; mailed only to an
        // address its owner proved.
        recordLoginAttempt($pdo, $request, (string) $user['email'], (int) $user['id'], ATTEMPT_METHOD_ACCOUNT_DELETION, 'sent');
        if (asBool($user['email_confirmed'])) {
            sendAccountDeletionRequestedMail($user, (int) strtotime($result['due']));
        }

        return accountAnswer($response, (int) $user['id']);
    });

    // The deletion cancelled, until it is done - and mailed: a deletion asked
    // for or cancelled with a stolen session is news its owner must get. Not
    // limited: every cancel follows a request, which is, and a person whose
    // account is about to go must never be told to wait to keep it.
    $group->delete('/deletion', function (Request $request, Response $response) {
        $pdo = iamDb();
        $user = me($request);

        if (!accountDeletionReady($pdo)) {
            $refusal = accountDeletionUnavailable();
            return respondError($response, $refusal['status'], $refusal['code'], $refusal['error']);
        }
        if (!cancelAccountDeletion($pdo, $request, (int) $user['id'])) {
            return respondError($response, 409, 'deletion_not_requested', 'There is no deletion of this account to cancel');
        }

        if (asBool($user['email_confirmed'])) {
            sendAccountDeletionCancelledMail($user);
        }

        return accountAnswer($response, (int) $user['id']);
    });
})->add(requireSession());

/** An account's personal tokens, newest first. The secret is never among them. */
function personalTokensOf(PDO $pdo, int $userId): array
{
    $statement = $pdo->prepare(
        "SELECT rt.id, rt.name, a.client_id, a.name AS application_name, rt.created_at, rt.expires_at, rt.last_used_at, rt.revoked_at
           FROM refresh_tokens rt JOIN applications a ON a.id = rt.application_id
          WHERE rt.user_id = ? AND rt.kind = 'personal'
          ORDER BY rt.created_at DESC"
    );
    $statement->execute([$userId]);

    return array_map(static fn(array $row): array => ['id' => (int) $row['id']] + isoTimes($row, ['created_at', 'expires_at', 'last_used_at', 'revoked_at']), $statement->fetchAll());
}
