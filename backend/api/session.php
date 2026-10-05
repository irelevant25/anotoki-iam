<?php

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Sessions at the IAM, and the attempts that did not become one.
 *
 * A session is a signed-in browser here. The cookie carries a random secret;
 * the row carries its SHA-256, so the table is worth nothing to anybody who
 * reads it. Every refresh token an application holds names the session it was
 * issued under, which is what makes signing out mean something everywhere at
 * once: end the session, and every site's next refresh fails.
 *
 * Expiry slides. A session is good for a stretch of inactivity - thirty days
 * when "keep me signed in" was ticked, one day when it was not - and never past
 * an absolute limit, however busy it is.
 */

const SESSION_METHOD_PASSWORD = 'password';
const SESSION_METHOD_LOGIN_CODE = 'login_code';
const SESSION_METHOD_GOOGLE = 'google';
/** Followed a link we mailed: confirming an address, or resetting a password. */
const SESSION_METHOD_EMAIL_LINK = 'email_link';

const SESSION_REVOKED_SIGNOUT = 'signed_out';
const SESSION_REVOKED_ELSEWHERE = 'signed_out_elsewhere';
const SESSION_REVOKED_PASSWORD = 'password_changed';
const SESSION_REVOKED_SECURITY = 'security_change';
const SESSION_REVOKED_REPLACED = 'replaced';
const SESSION_REVOKED_ADMIN = 'ended_by_admin';
const SESSION_REVOKED_DISABLED = 'account_disabled';
const SESSION_REVOKED_CLAIMED = 'address_claimed';

/** last_seen_at is rewritten at most this often; nothing reads it more finely. */
const SESSION_TOUCH_INTERVAL = 60;

// ─────────────────────────────────────────────────────────────────────────────
// The cookie
// ─────────────────────────────────────────────────────────────────────────────

function sessionCookieName(): string
{
    return (string) iamConfig()['cookie_name'];
}

function sessionCookieSecure(): bool
{
    $configured = iamConfig()['cookie_secure'];

    return $configured === null ? str_starts_with(iamIssuer(), 'https://') : (bool) $configured;
}

/**
 * Sets the session cookie.
 *
 * HttpOnly so no script - including one injected into a page - can read it;
 * SameSite=Lax so another site cannot make the browser send it with a
 * cross-site POST; Secure wherever the IAM is on HTTPS. A remembered session's
 * cookie outlives the browser; the server's own expiry is still what decides.
 */
function withSessionCookie(Response $response, string $secret, bool $remember): Response
{
    $parts = [sessionCookieName() . '=' . $secret, 'Path=/', 'HttpOnly', 'SameSite=Lax'];

    if ($remember) {
        $parts[] = 'Max-Age=' . (int) iamConfig()['session_remember_absolute'];
    }

    if (sessionCookieSecure()) {
        $parts[] = 'Secure';
    }

    return $response->withAddedHeader('Set-Cookie', implode('; ', $parts));
}

function withoutSessionCookie(Response $response): Response
{
    $parts = [sessionCookieName() . '=', 'Path=/', 'HttpOnly', 'SameSite=Lax', 'Max-Age=0', 'Expires=Thu, 01 Jan 1970 00:00:00 GMT'];

    if (sessionCookieSecure()) {
        $parts[] = 'Secure';
    }

    return $response->withAddedHeader('Set-Cookie', implode('; ', $parts));
}

function sessionSecretFrom(Request $request): ?string
{
    $value = $request->getCookieParams()[sessionCookieName()] ?? null;

    return is_string($value) && preg_match('/^[A-Za-z0-9_-]{40,64}$/', $value) ? $value : null;
}

// ─────────────────────────────────────────────────────────────────────────────
// Sessions
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Opens a session and hands back the cookie's secret with the row.
 *
 * @return array{secret: string, session: array}
 */
function openSession(PDO $pdo, Request $request, array $user, string $method, bool $mfa, bool $remember): array
{
    $config = iamConfig();
    $secret = randomSecret(32);
    $idle = (int) ($remember ? $config['session_remember_idle'] : $config['session_idle']);
    $absolute = (int) ($remember ? $config['session_remember_absolute'] : $config['session_absolute']);

    $insert = $pdo->prepare(
        "INSERT INTO sessions (user_id, token_hash, sid, method, mfa, remember, ip, user_agent, last_seen_at, expires_at, absolute_expires_at)
              VALUES (?, ?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP,
                      CURRENT_TIMESTAMP + (? * INTERVAL '1 second'), CURRENT_TIMESTAMP + (? * INTERVAL '1 second'))
           RETURNING *"
    );
    $insert->execute([
        (int) $user['id'], sha256($secret), randomHex(16), $method, pgBool($mfa), pgBool($remember),
        requestIp($request), requestUserAgent($request), $idle, $absolute,
    ]);

    $pdo->prepare('UPDATE users SET last_login_at = CURRENT_TIMESTAMP WHERE id = ?')->execute([(int) $user['id']]);

    return ['secret' => $secret, 'session' => $insert->fetch()];
}

/** The live session a request's cookie names, or null. */
function sessionFromRequest(PDO $pdo, Request $request): ?array
{
    $secret = sessionSecretFrom($request);

    if ($secret === null) {
        return null;
    }

    $statement = $pdo->prepare(
        'SELECT * FROM sessions
          WHERE token_hash = ? AND revoked_at IS NULL
            AND expires_at > CURRENT_TIMESTAMP AND absolute_expires_at > CURRENT_TIMESTAMP'
    );
    $statement->execute([sha256($secret)]);

    return $statement->fetch() ?: null;
}

function sessionById(PDO $pdo, int $id): ?array
{
    $statement = $pdo->prepare('SELECT * FROM sessions WHERE id = ?');
    $statement->execute([$id]);

    return $statement->fetch() ?: null;
}

/** Whether a session row is still good. */
function sessionLive(?array $session): bool
{
    return $session !== null
        && $session['revoked_at'] === null
        && strtotime((string) $session['expires_at']) > time()
        && strtotime((string) $session['absolute_expires_at']) > time();
}

/**
 * Notes that a session is in use and slides its expiry, at most once a minute.
 *
 * Called on every request the session makes here and at every refresh an
 * application makes under it - an app in daily use keeps its session alive
 * without the person ever coming back to the IAM's own pages.
 */
function touchSession(PDO $pdo, array $session): void
{
    $lastSeen = $session['last_seen_at'] ?? null;

    if ($lastSeen !== null && time() - strtotime((string) $lastSeen) < SESSION_TOUCH_INTERVAL) {
        return;
    }

    $config = iamConfig();
    $idle = (int) (asBool($session['remember']) ? $config['session_remember_idle'] : $config['session_idle']);

    $pdo->prepare(
        "UPDATE sessions
            SET last_seen_at = CURRENT_TIMESTAMP,
                expires_at = LEAST(CURRENT_TIMESTAMP + (? * INTERVAL '1 second'), absolute_expires_at)
          WHERE id = ?"
    )->execute([$idle, (int) $session['id']]);
}

/**
 * Ends one session, and every refresh token issued under it.
 *
 * Personal tokens are not issued under a session and are left alone: signing
 * out of a browser should not stop a script somebody deliberately made a token
 * for. They have their own revoke button.
 */
function revokeSession(PDO $pdo, int $sessionId, string $reason): bool
{
    $statement = $pdo->prepare(
        'UPDATE sessions SET revoked_at = CURRENT_TIMESTAMP, revoked_reason = ? WHERE id = ? AND revoked_at IS NULL'
    );
    $statement->execute([$reason, $sessionId]);

    $pdo->prepare(
        "UPDATE refresh_tokens SET revoked_at = CURRENT_TIMESTAMP, revoked_reason = ?
          WHERE session_id = ? AND kind = 'rotating' AND revoked_at IS NULL"
    )->execute([$reason, $sessionId]);

    return $statement->rowCount() === 1;
}

/**
 * Ends every live session of an account but, optionally, one.
 *
 * What a new password, turning two-factor on or off, or an admin's reset does:
 * the moments somebody is most likely shutting somebody else out.
 */
function revokeSessionsFor(PDO $pdo, int $userId, string $reason, ?int $exceptSessionId = null): int
{
    $except = $exceptSessionId === null ? '' : ' AND id <> ' . (int) $exceptSessionId;

    $statement = $pdo->prepare(
        "UPDATE sessions SET revoked_at = CURRENT_TIMESTAMP, revoked_reason = ?
          WHERE user_id = ? AND revoked_at IS NULL AND expires_at > CURRENT_TIMESTAMP{$except}"
    );
    $statement->execute([$reason, $userId]);

    $tokenExcept = $exceptSessionId === null ? '' : ' AND (session_id IS NULL OR session_id <> ' . (int) $exceptSessionId . ')';
    $pdo->prepare(
        "UPDATE refresh_tokens SET revoked_at = CURRENT_TIMESTAMP, revoked_reason = ?
          WHERE user_id = ? AND kind = 'rotating' AND revoked_at IS NULL{$tokenExcept}"
    )->execute([$reason, $userId]);

    return $statement->rowCount();
}

/**
 * Ends an account's personal tokens - the ones for scripts, which belong to no
 * session and outlive every one of them (up to a year, and no second factor).
 * Ending sessions does not touch them; a reset, which takes an account back,
 * has to.
 */
function revokePersonalTokens(PDO $pdo, int $userId, string $reason): int
{
    $statement = $pdo->prepare(
        "UPDATE refresh_tokens SET revoked_at = CURRENT_TIMESTAMP, revoked_reason = ?
          WHERE user_id = ? AND kind = 'personal' AND revoked_at IS NULL"
    );
    $statement->execute([$reason, $userId]);

    return $statement->rowCount();
}

/** An account's sessions, newest first, each with the applications used from it. */
function sessionsFor(PDO $pdo, int $userId, int $limit = 50): array
{
    $statement = $pdo->prepare(
        "SELECT s.*,
                COALESCE((SELECT json_agg(DISTINCT a.name) FROM refresh_tokens rt
                            JOIN applications a ON a.id = rt.application_id
                           WHERE rt.session_id = s.id), '[]') AS applications
           FROM sessions s
          WHERE s.user_id = ?
          ORDER BY s.created_at DESC
          LIMIT ?"
    );
    $statement->execute([$userId, $limit]);

    return $statement->fetchAll();
}

/** A session as the account page and the admin panel show it. The secret never. */
function sessionEntry(array $row, ?int $currentSessionId = null): array
{
    return [
        'id' => (int) $row['id'],
        'method' => $row['method'],
        'mfa' => asBool($row['mfa']),
        'ip' => $row['ip'],
        'user_agent' => $row['user_agent'],
        'created_at' => isoTime($row['created_at']),
        'last_seen_at' => isoTime($row['last_seen_at']),
        'expires_at' => isoTime(min((string) $row['expires_at'], (string) $row['absolute_expires_at'])),
        'revoked_at' => isoTime($row['revoked_at']),
        'revoked_reason' => $row['revoked_reason'],
        'active' => sessionLive($row),
        'current' => $currentSessionId !== null && (int) $row['id'] === $currentSessionId,
        'applications' => is_string($row['applications'] ?? null) ? (json_decode($row['applications'], true) ?: []) : [],
    ];
}

// ─────────────────────────────────────────────────────────────────────────────
// Attempts
// ─────────────────────────────────────────────────────────────────────────────

/** How far back the limiter counts. */
const ATTEMPT_WINDOW = 900;

/**
 * Failures from one source against one address before it is refused.
 *
 * Keyed on the pair, not the address alone: a limit on the address by itself
 * lets anybody lock somebody else out of their own account by failing at it ten
 * times.
 */
const ATTEMPT_MAX_PER_EMAIL_IP = 10;

/** And a looser ceiling per source, so one machine cannot work through a list. */
const ATTEMPT_MAX_PER_IP = 50;

/** The `method` of an attempt made from the account page: an authenticator code asked for by a signed-in session. */
const ATTEMPT_METHOD_ACCOUNT_TOTP = 'account_totp';

/** The `method` of an authenticator code asked for by a password reset. */
const ATTEMPT_METHOD_PASSWORD_RESET = 'password_reset';

function recordLoginAttempt(PDO $pdo, Request $request, string $email, ?int $userId, string $method, string $outcome): void
{
    $pdo->prepare('INSERT INTO login_attempts (email, user_id, ip, method, outcome) VALUES (?, ?, ?, ?, ?)')
        ->execute([substr(strtolower($email), 0, 255), $userId, requestIp($request), $method, $outcome]);
}

/**
 * Whether this caller has failed too often to try again yet.
 *
 * Counted rather than locked: nothing is stored against the account, so the
 * refusal lifts on its own as the window rolls past.
 */
function loginAttemptsExceeded(PDO $pdo, Request $request, string $email): bool
{
    $ip = requestIp($request);

    if ($ip === null) {
        return false;
    }

    $window = "CURRENT_TIMESTAMP - (" . ATTEMPT_WINDOW . " * INTERVAL '1 second')";

    // Requests that send mail are recorded as 'sent' and count here too, which
    // is what stops the resend and reset buttons being a way to spray mail at
    // somebody's address.
    $perPair = $pdo->prepare(
        "SELECT count(*) FROM login_attempts WHERE email = ? AND ip = ? AND outcome <> 'ok' AND created_at > $window"
    );
    $perPair->execute([strtolower($email), $ip]);

    if ((int) $perPair->fetchColumn() >= ATTEMPT_MAX_PER_EMAIL_IP) {
        return true;
    }

    $perIp = $pdo->prepare(
        "SELECT count(*) FROM login_attempts WHERE ip = ? AND outcome <> 'ok' AND created_at > $window"
    );
    $perIp->execute([$ip]);

    return (int) $perIp->fetchColumn() >= ATTEMPT_MAX_PER_IP;
}

/** Failures against an account since it last signed in successfully. */
function failedAttemptsSinceLastLogin(PDO $pdo, int $userId): int
{
    $statement = $pdo->prepare(
        "SELECT count(*) FROM login_attempts
          WHERE user_id = ? AND outcome NOT IN ('ok', 'sent')
            AND created_at > COALESCE(
                (SELECT max(created_at) FROM login_attempts WHERE user_id = ? AND outcome = 'ok'),
                TIMESTAMPTZ 'epoch')"
    );
    $statement->execute([$userId, $userId]);

    return (int) $statement->fetchColumn();
}
