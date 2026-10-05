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

        if ($changes !== []) {
            $sets = implode(', ', array_map(static fn(string $column): string => $column . ' = ?', array_keys($changes)));
            $pdo->prepare("UPDATE users SET $sets WHERE id = ?")->execute([...array_values($changes), (int) $user['id']]);

            if (isset($changes['username'])) {
                audit($request, (int) $user['id'], 'account.username_changed', 'user', $user['id'], ['from' => $user['username'], 'to' => $changes['username']]);
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

        // 422, not 401: the caller is signed in; what is wrong is a value in
        // the body. A 401 would read as "session expired" and sign them out
        // for mistyping their own password.
        if ($user['password'] === null || !password_verify($current, (string) $user['password'])) {
            return respondError($response, 422, 'wrong_password', 'The current password is not right');
        }

        if ($refusal = passwordRefusal($next)) {
            return respondError($response, 422, 'invalid_password', $refusal);
        }

        $pdo = iamDb();
        $pdo->prepare('UPDATE users SET password = ?, force_password_change = FALSE WHERE id = ?')
            ->execute([password_hash($next, PASSWORD_DEFAULT), (int) $user['id']]);

        // Changing a password is what somebody does when they think another
        // person has it; leaving that person signed in would make it ceremonial.
        revokeSessionsFor($pdo, (int) $user['id'], SESSION_REVOKED_PASSWORD, (int) currentSession($request)['id']);
        revokeTrustedDevices($pdo, (int) $user['id']);
        audit($request, (int) $user['id'], 'account.password_changed', 'user', $user['id']);

        return respondJson($response, ['message' => 'Password changed. Everywhere else has been signed out.']);
    });

    // A first password, on an account with none. Being signed in is the proof -
    // it already took Google or a code from the account's own mailbox. Also the
    // one way an account without a password can satisfy a forced change.
    $group->post('/password/set', function (Request $request, Response $response) {
        $user = me($request);
        $password = (string) (requestBody($request)['password'] ?? '');

        if ($user['password'] !== null) {
            return respondError($response, 409, 'password_already_set', 'This account already has a password');
        }

        if ($refusal = passwordRefusal($password)) {
            return respondError($response, 422, 'invalid_password', $refusal);
        }

        iamDb()->prepare('UPDATE users SET password = ?, password_login_enabled = TRUE, force_password_change = FALSE WHERE id = ?')
            ->execute([password_hash($password, PASSWORD_DEFAULT), (int) $user['id']]);
        audit($request, (int) $user['id'], 'account.password_set', 'user', $user['id']);

        return accountAnswer($response, (int) $user['id']);
    });

    $group->put('/password/enabled', function (Request $request, Response $response) {
        $user = me($request);
        $enabled = !empty(requestBody($request)['enabled']);
        $pdo = iamDb();

        if ($enabled && $user['password'] === null) {
            return respondError($response, 409, 'no_password', 'There is no password on this account to switch on');
        }

        if (!$enabled && identityFor($pdo, (int) $user['id'], IDENTITY_GOOGLE) === null) {
            return respondError($response, 409, 'would_lock_account', 'Connect Google first, or you would not be able to sign in again');
        }

        $pdo->prepare('UPDATE users SET password_login_enabled = ? WHERE id = ?')->execute([pgBool($enabled), (int) $user['id']]);
        audit($request, (int) $user['id'], $enabled ? 'account.password_login_on' : 'account.password_login_off', 'user', $user['id']);

        return accountAnswer($response, (int) $user['id']);
    });

    // ── Google ───────────────────────────────────────────────────────────────

    $group->post('/google', function (Request $request, Response $response) {
        if (!googleLoginEnabled()) {
            return respondError($response, 403, 'google_disabled', 'Google sign-in is switched off');
        }

        $user = me($request);
        $claims = googleVerifyIdToken((string) (requestBody($request)['credential'] ?? ''));

        if ($claims === null) {
            return respondError($response, 400, 'invalid_token', 'That Google sign-in could not be verified');
        }

        $pdo = iamDb();
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
        audit($request, (int) $user['id'], 'account.google_connected', 'user', $user['id']);

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

    $group->post('/2fa/setup', function (Request $request, Response $response) {
        $user = me($request);

        if (asBool($user['totp_enabled'])) {
            return respondError($response, 409, 'totp_already_on', 'Two-factor authentication is already on');
        }

        $secret = totpSecret();
        iamDb()->prepare('UPDATE users SET totp_secret = ? WHERE id = ?')->execute([encryptSecret($secret), (int) $user['id']]);

        return respondJson($response, ['secret' => $secret, 'uri' => totpUri($secret, (string) $user['email'])]);
    });

    // The recovery codes are generated here and shown this once; they are
    // stored hashed, so nobody can show them again.
    $group->post('/2fa/enable', function (Request $request, Response $response) {
        $user = me($request);
        $pdo = iamDb();
        $secret = totpSecretOf($user);

        if (asBool($user['totp_enabled'])) {
            return respondError($response, 409, 'totp_already_on', 'Two-factor authentication is already on');
        }
        if ($secret === null) {
            return respondError($response, 409, 'totp_not_started', 'Start again - there is no secret to confirm');
        }
        $step = totpMatchedStep($secret, (string) (requestBody($request)['code'] ?? ''));
        if ($step === null) {
            return respondError($response, 400, 'totp_invalid', 'That code is not right');
        }

        // The confirming code counts as used, like any other: it cannot then
        // sign somebody in a second later.
        $pdo->prepare('UPDATE users SET totp_enabled = TRUE, totp_last_step = ? WHERE id = ?')->execute([$step, (int) $user['id']]);
        $codes = generateRecoveryCodes($pdo, (int) $user['id']);

        // The other sessions were signed in without the second factor - what
        // was just decided to be not enough.
        revokeSessionsFor($pdo, (int) $user['id'], SESSION_REVOKED_SECURITY, (int) currentSession($request)['id']);
        revokeTrustedDevices($pdo, (int) $user['id']);
        audit($request, (int) $user['id'], 'account.2fa_on', 'user', $user['id']);

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

        $pdo->prepare('UPDATE users SET totp_enabled = FALSE, totp_secret = NULL WHERE id = ?')->execute([(int) $user['id']]);
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
