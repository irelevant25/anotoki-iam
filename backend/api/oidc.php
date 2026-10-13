<?php

/**
 * OpenID Connect: authorization requests, codes, refresh tokens, and the
 * tokens themselves.
 *
 * The flow is the authorization code flow with PKCE, which is what OAuth's
 * current security advice asks of every client and the only one a browser app
 * can do safely: the code travels through the browser, but it is worth nothing
 * without the verifier that only the page which started the sign-in holds, and
 * it is exchanged directly with the token endpoint.
 *
 * Every secret here - codes, refresh tokens, personal tokens - is 256 random
 * bits stored only as its SHA-256.
 */

const OIDC_SCOPES = ['openid', 'profile', 'email'];

/** Where each sign-in method puts itself in the `amr` claim (RFC 8176 names where one fits). */
const AMR_BY_METHOD = [
    SESSION_METHOD_PASSWORD => 'pwd',
    SESSION_METHOD_LOGIN_CODE => 'email',
    SESSION_METHOD_EMAIL_LINK => 'email',
    SESSION_METHOD_GOOGLE => 'google',
    SESSION_METHOD_PASSWORD_EMAIL => 'pwd',
];

/**
 * The second factor's own name in `amr`, where it was not the authenticator's
 * `otp`: the password's sign-in finished, during the authenticator's pause,
 * with a code mailed to the address - the mailbox stood in, and a site reading
 * `amr` is told so rather than told an authenticator answered.
 */
const AMR_SECOND_FACTOR_BY_METHOD = [
    SESSION_METHOD_PASSWORD_EMAIL => 'email',
];

/**
 * The scopes asked for, reduced to the ones there are.
 *
 * OIDC says an unknown scope is ignored rather than refused, so an application
 * asking for something this IAM does not offer still gets a sign-in - with the
 * scopes that do exist. `openid` must be among them; the caller checks.
 */
function grantedScope(string $requested): string
{
    $asked = array_values(array_unique(array_filter(explode(' ', $requested))));

    return implode(' ', array_values(array_intersect(OIDC_SCOPES, $asked)));
}

// ─────────────────────────────────────────────────────────────────────────────
// Authorization requests
// ─────────────────────────────────────────────────────────────────────────────

/** Stores what an application asked for and answers the id the browser will carry. */
function createAuthRequest(PDO $pdo, array $application, array $fields, string $kind = 'login'): string
{
    $id = randomHex(16);

    $pdo->prepare(
        "INSERT INTO auth_requests (id, kind, application_id, redirect_uri, scope, state, nonce, code_challenge,
                                    code_challenge_method, prompt, login_hint, max_age, ui_locales, expires_at)
              VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP + (? * INTERVAL '1 second'))"
    )->execute([
        $id,
        $kind,
        (int) $application['id'],
        $fields['redirect_uri'] ?? null,
        $fields['scope'] ?? null,
        $fields['state'] ?? null,
        $fields['nonce'] ?? null,
        $fields['code_challenge'] ?? null,
        $fields['code_challenge_method'] ?? null,
        $fields['prompt'] ?? null,
        $fields['login_hint'] ?? null,
        $fields['max_age'] ?? null,
        $fields['ui_locales'] ?? null,
        (int) iamConfig()['request_ttl'],
    ]);

    return $id;
}

/** A live request of a kind - unexpired, unused - with its application, or null. */
function liveAuthRequest(PDO $pdo, string $id, string $kind = 'login'): ?array
{
    if (!preg_match('/^[0-9a-f]{32}$/', $id)) {
        return null;
    }

    $statement = $pdo->prepare(
        'SELECT * FROM auth_requests WHERE id = ? AND kind = ? AND completed_at IS NULL AND expires_at > CURRENT_TIMESTAMP'
    );
    $statement->execute([$id, $kind]);
    $request = $statement->fetch();

    if (!$request) {
        return null;
    }

    $application = applicationById((int) $request['application_id']);

    return $application ? $request + ['application' => $application] : null;
}

/** Marks a request used; true only for the one call that did it. */
function completeAuthRequest(PDO $pdo, string $id): bool
{
    $statement = $pdo->prepare('UPDATE auth_requests SET completed_at = CURRENT_TIMESTAMP WHERE id = ? AND completed_at IS NULL');
    $statement->execute([$id]);

    return $statement->rowCount() === 1;
}

/** A redirect URI with OAuth parameters added, keeping any it already had. */
function redirectWith(string $uri, array $parameters): string
{
    $parameters = array_filter($parameters, static fn($value) => $value !== null && $value !== '');

    if ($parameters === []) {
        return $uri;
    }

    return $uri . (str_contains($uri, '?') ? '&' : '?') . http_build_query($parameters, '', '&', PHP_QUERY_RFC3986);
}

/** Where a request goes back to with an error - RFC 9207's `iss` included. */
function errorRedirect(array $request, string $error, ?string $description = null): string
{
    return redirectWith((string) $request['redirect_uri'], [
        'error' => $error,
        'error_description' => $description,
        'state' => $request['state'],
        'iss' => iamIssuer(),
    ]);
}

/**
 * Whether a session is good enough for this request: `prompt=login` asks for
 * credentials again whatever the session, and `max_age` for credentials
 * presented recently enough.
 */
function sessionSatisfiesRequest(array $session, array $request): bool
{
    if (in_array('login', explode(' ', (string) ($request['prompt'] ?? '')), true)) {
        return false;
    }

    if ($request['max_age'] !== null && strtotime((string) $session['auth_time']) + (int) $request['max_age'] < time()) {
        return false;
    }

    return true;
}

// ─────────────────────────────────────────────────────────────────────────────
// Codes
// ─────────────────────────────────────────────────────────────────────────────

/** Issues the code for a request, and the redirect URI carrying it. */
function issueAuthorizationCode(PDO $pdo, array $request, array $user, array $session): string
{
    $code = randomSecret(32);

    $pdo->prepare(
        "INSERT INTO auth_codes (code_hash, application_id, user_id, session_id, redirect_uri, scope, nonce,
                                 code_challenge, code_challenge_method, expires_at)
              VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP + (? * INTERVAL '1 second'))"
    )->execute([
        sha256($code),
        (int) $request['application_id'],
        (int) $user['id'],
        (int) $session['id'],
        $request['redirect_uri'],
        $request['scope'],
        $request['nonce'],
        $request['code_challenge'],
        $request['code_challenge_method'],
        (int) iamConfig()['code_ttl'],
    ]);

    return redirectWith((string) $request['redirect_uri'], ['code' => $code, 'state' => $request['state'], 'iss' => iamIssuer()]);
}

/**
 * The code's row, once everything about the exchange checks out - or the OAuth
 * error to answer with.
 *
 * A code already used is not simply refused: somebody else has it, so whatever
 * the first exchange produced is revoked as well (RFC 6749 §4.1.2).
 *
 * @return array|string the row, or an error description for invalid_grant
 */
function redeemAuthorizationCode(PDO $pdo, string $code, array $application, string $redirectUri, string $verifier): array|string
{
    $statement = $pdo->prepare('SELECT * FROM auth_codes WHERE code_hash = ?');
    $statement->execute([sha256($code)]);
    $row = $statement->fetch();

    if (!$row || (int) $row['application_id'] !== (int) $application['id']) {
        return 'The code is not valid';
    }

    if ($row['consumed_at'] !== null) {
        if ($row['family_id'] !== null) {
            revokeTokenFamily($pdo, (string) $row['family_id'], 'code_reused');
        }
        return 'The code has already been used';
    }

    if (strtotime((string) $row['expires_at']) < time()) {
        return 'The code has expired';
    }

    if (!hash_equals((string) $row['redirect_uri'], $redirectUri)) {
        return 'redirect_uri does not match the one the code was issued for';
    }

    if ($row['code_challenge'] !== null) {
        // RFC 7636: base64url(SHA-256(verifier)), and the verifier itself is
        // 43 to 128 characters of the unreserved set.
        if (!preg_match('/^[A-Za-z0-9\-._~]{43,128}$/', $verifier)
            || !hash_equals((string) $row['code_challenge'], base64UrlEncode(hash('sha256', $verifier, true)))) {
            return 'code_verifier does not match the challenge';
        }
    } else {
        // Every client is public (011): a code without a challenge proves
        // nothing about who redeems it.
        return 'A public client must use PKCE';
    }

    $consume = $pdo->prepare('UPDATE auth_codes SET consumed_at = CURRENT_TIMESTAMP WHERE id = ? AND consumed_at IS NULL');
    $consume->execute([(int) $row['id']]);

    return $consume->rowCount() === 1 ? $row : 'The code has already been used';
}

// ─────────────────────────────────────────────────────────────────────────────
// Refresh tokens
// ─────────────────────────────────────────────────────────────────────────────

/**
 * A new refresh token. A rotating one expires with its session; a personal one
 * when its owner said.
 *
 * @return array{token: string, id: int}
 */
function issueRefreshToken(PDO $pdo, string $kind, string $familyId, int $applicationId, int $userId, ?int $sessionId, string $scope, int $expiresInSeconds, ?string $name = null): array
{
    $token = randomSecret(32, $kind === 'personal' ? 'iam_pat_' : 'iam_rt_');

    $insert = $pdo->prepare(
        "INSERT INTO refresh_tokens (token_hash, kind, family_id, application_id, user_id, session_id, scope, name, expires_at)
              VALUES (?, ?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP + (? * INTERVAL '1 second'))
           RETURNING id"
    );
    $insert->execute([sha256($token), $kind, $familyId, $applicationId, $userId, $sessionId, $scope, $name, max(1, $expiresInSeconds)]);

    return ['token' => $token, 'id' => (int) $insert->fetchColumn()];
}

/** Seconds until a session's absolute end, which is when its refresh tokens end too. */
function secondsUntilSessionEnds(array $session): int
{
    return max(1, strtotime((string) $session['absolute_expires_at']) - time());
}

function revokeTokenFamily(PDO $pdo, string $familyId, string $reason): void
{
    $pdo->prepare(
        'UPDATE refresh_tokens SET revoked_at = CURRENT_TIMESTAMP, revoked_reason = ? WHERE family_id = ? AND revoked_at IS NULL'
    )->execute([$reason, $familyId]);
}

/**
 * Spends a refresh token and issues its successor - or refuses.
 *
 * In order: the token must exist, be this application's, be neither revoked
 * nor expired; a personal token is simply checked and never rotated; a rotating
 * token already spent is reuse - forgiven within the leeway (two tabs racing),
 * and otherwise the end of the whole family; the session behind it must still
 * be live; and the account must still be allowed to use the application.
 *
 * @return array{error: string}|array{token: ?string, row: array, user: array, session: ?array}
 */
function exchangeRefreshToken(PDO $pdo, string $token, array $application): array
{
    $statement = $pdo->prepare('SELECT * FROM refresh_tokens WHERE token_hash = ?');
    $statement->execute([sha256($token)]);
    $row = $statement->fetch();

    if (!$row || (int) $row['application_id'] !== (int) $application['id']) {
        return ['error' => 'The refresh token is not valid'];
    }

    if ($row['revoked_at'] !== null || strtotime((string) $row['expires_at']) < time()) {
        return ['error' => 'The refresh token is no longer valid'];
    }

    $user = userById($pdo, (int) $row['user_id']);
    if ($user === null) {
        return ['error' => 'The refresh token is no longer valid'];
    }

    if ($refusal = applicationAccessRefusal($application, $user)) {
        revokeTokenFamily($pdo, (string) $row['family_id'], $refusal['code']);
        return ['error' => $refusal['error']];
    }

    if ($row['kind'] === 'personal') {
        if ($row['last_used_at'] === null || time() - strtotime((string) $row['last_used_at']) >= 60) {
            $pdo->prepare('UPDATE refresh_tokens SET last_used_at = CURRENT_TIMESTAMP WHERE id = ?')->execute([(int) $row['id']]);
        }
        return ['token' => null, 'row' => $row, 'user' => $user, 'session' => null];
    }

    $session = sessionById($pdo, (int) $row['session_id']);
    if (!sessionLive($session)) {
        revokeTokenFamily($pdo, (string) $row['family_id'], 'session_ended');
        return ['error' => 'The session this token belongs to has ended'];
    }

    // Spent by this call, or by one a moment ago?
    $spend = $pdo->prepare('UPDATE refresh_tokens SET used_at = CURRENT_TIMESTAMP, last_used_at = CURRENT_TIMESTAMP WHERE id = ? AND used_at IS NULL');
    $spend->execute([(int) $row['id']]);

    if ($spend->rowCount() !== 1) {
        $usedAt = $pdo->prepare('SELECT used_at FROM refresh_tokens WHERE id = ?');
        $usedAt->execute([(int) $row['id']]);
        $spentAt = strtotime((string) $usedAt->fetchColumn());
        $withinLeeway = $spentAt !== false && $spentAt + (int) iamConfig()['refresh_reuse_leeway'] >= time();

        // Inside the leeway: another tab of the same browser lost the race,
        // and gets a successor of its own rather than a sign-out - one, taken
        // by a conditional UPDATE (migration 004). A further use in the same
        // minute is not a second tab; it is a copy starting a chain of its own.
        $sibling = null;
        if ($withinLeeway) {
            $sibling = $pdo->prepare('UPDATE refresh_tokens SET sibling_issued_at = CURRENT_TIMESTAMP WHERE id = ? AND sibling_issued_at IS NULL');
            $sibling->execute([(int) $row['id']]);
        }

        if ($sibling === null || $sibling->rowCount() !== 1) {
            revokeTokenFamily($pdo, (string) $row['family_id'], 'reuse_detected');
            audit(null, (int) $row['user_id'], 'refresh_token.reuse_detected', 'application', $application['client_id'], ['family' => $row['family_id']]);
            return ['error' => 'The refresh token has already been used'];
        }
    }

    $next = issueRefreshToken(
        $pdo, 'rotating', (string) $row['family_id'], (int) $application['id'], (int) $user['id'],
        (int) $session['id'], (string) $row['scope'], secondsUntilSessionEnds($session)
    );

    $pdo->prepare('UPDATE refresh_tokens SET replaced_by = ? WHERE id = ? AND replaced_by IS NULL')->execute([$next['id'], (int) $row['id']]);
    touchSession($pdo, $session);

    return ['token' => $next['token'], 'row' => $row, 'user' => $user, 'session' => $session];
}

/** Revokes a refresh or personal token, and a rotating token's whole family. Silent about what it found. */
function revokeRefreshToken(PDO $pdo, string $token, array $application): void
{
    $statement = $pdo->prepare('SELECT * FROM refresh_tokens WHERE token_hash = ? AND application_id = ?');
    $statement->execute([sha256($token), (int) $application['id']]);
    $row = $statement->fetch();

    if (!$row) {
        return;
    }

    if ($row['kind'] === 'rotating') {
        revokeTokenFamily($pdo, (string) $row['family_id'], 'revoked');
    } else {
        $pdo->prepare("UPDATE refresh_tokens SET revoked_at = CURRENT_TIMESTAMP, revoked_reason = 'revoked' WHERE id = ? AND revoked_at IS NULL")
            ->execute([(int) $row['id']]);
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// The tokens
// ─────────────────────────────────────────────────────────────────────────────

/**
 * An access token for one application (RFC 9068's JWT profile).
 *
 * `roles` are the roles in this application and nothing wider, read at the
 * moment of issue - a role granted or taken away shows in the next token.
 *
 * @return array{token: string, expires_in: int}
 */
function mintAccessToken(array $application, array $user, ?array $session, string $scope, array $roles): array
{
    $now = time();
    $ttl = (int) $application['access_token_ttl'];

    $claims = [
        'iss' => iamIssuer(),
        'sub' => (string) $user['id'],
        'aud' => $application['client_id'],
        'client_id' => $application['client_id'],
        'iat' => $now,
        'exp' => $now + $ttl,
        'jti' => randomHex(16),
        'scope' => $scope,
        'roles' => array_values($roles),
    ];

    if ($session !== null) {
        $claims['sid'] = $session['sid'];
        $claims['auth_time'] = strtotime((string) $session['auth_time']);
    }

    return ['token' => jwtSign($claims + profileClaims($user, $scope), 'at+jwt'), 'expires_in' => $ttl];
}

function amrFor(array $session): array
{
    $amr = [AMR_BY_METHOD[$session['method']] ?? 'pwd'];

    if (asBool($session['mfa'])) {
        $amr[] = AMR_SECOND_FACTOR_BY_METHOD[$session['method']] ?? 'otp';
        $amr[] = 'mfa';
    }

    return $amr;
}

/** An ID token: who signed in, when and how, for the application's frontend. */
function mintIdToken(array $application, array $user, array $session, ?string $nonce, string $accessToken, string $scope, array $roles): string
{
    $now = time();

    $claims = [
        'iss' => iamIssuer(),
        'sub' => (string) $user['id'],
        'aud' => $application['client_id'],
        'azp' => $application['client_id'],
        'iat' => $now,
        'exp' => $now + (int) iamConfig()['id_token_ttl'],
        'auth_time' => strtotime((string) $session['auth_time']),
        'sid' => $session['sid'],
        'amr' => amrFor($session),
        'at_hash' => atHash($accessToken),
        'roles' => array_values($roles),
    ];

    if ($nonce !== null && $nonce !== '') {
        $claims['nonce'] = $nonce;
    }

    return jwtSign($claims + profileClaims($user, $scope), 'JWT');
}

/**
 * The token endpoint's answer for a user, a session and a refresh token.
 * `$refreshToken` null is a personal token's exchange: an access token alone.
 */
function tokenResponse(array $application, array $user, ?array $session, string $scope, ?string $refreshToken, ?string $nonce = null): array
{
    $roles = userRolesIn((int) $user['id'], (int) $application['id']);
    $access = mintAccessToken($application, $user, $session, $scope, $roles);

    $answer = [
        'access_token' => $access['token'],
        'token_type' => 'Bearer',
        'expires_in' => $access['expires_in'],
        'scope' => $scope,
    ];

    if ($refreshToken !== null) {
        $answer['refresh_token'] = $refreshToken;
    }

    if ($session !== null && in_array('openid', explode(' ', $scope), true)) {
        $answer['id_token'] = mintIdToken($application, $user, $session, $nonce, $access['token'], $scope, $roles);
    }

    return $answer;
}

/**
 * The claims of an access token presented to the userinfo endpoint, or null.
 *
 * Verified against our own keys like any application would, and then held to
 * one more rule an application cannot check: its session, when it has one, must
 * still be live - userinfo is the one place a revoked sign-in shows at once.
 */
function verifyAccessTokenHere(PDO $pdo, string $token): ?array
{
    $claims = jwtVerifyOwn($token, 'at+jwt');

    if ($claims === null || !ctype_digit((string) ($claims['sub'] ?? ''))) {
        return null;
    }

    if (isset($claims['sid'])) {
        $statement = $pdo->prepare('SELECT * FROM sessions WHERE sid = ?');
        $statement->execute([(string) $claims['sid']]);
        if (!sessionLive($statement->fetch() ?: null)) {
            return null;
        }
    }

    return $claims;
}
