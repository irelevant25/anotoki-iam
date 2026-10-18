<?php

use Anotoki\Lib\Support\ClientAddress;
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
/**
 * The password, then - during the authenticator's pause - a code mailed to the
 * address in the authenticator's place (api/authenticator_pause.php): the
 * mailbox stood in for the second factor, which the `amr` claim says.
 */
const SESSION_METHOD_PASSWORD_EMAIL = 'password_email';

const SESSION_REVOKED_SIGNOUT = 'signed_out';
const SESSION_REVOKED_ELSEWHERE = 'signed_out_elsewhere';
const SESSION_REVOKED_PASSWORD = 'password_changed';
const SESSION_REVOKED_SECURITY = 'security_change';
const SESSION_REVOKED_REPLACED = 'replaced';
const SESSION_REVOKED_ADMIN = 'ended_by_admin';
const SESSION_REVOKED_DISABLED = 'account_disabled';
const SESSION_REVOKED_CLAIMED = 'address_claimed';
/** The person asked for the account to be deleted (api/account_deletion.php): every other session, and every token. */
const SESSION_REVOKED_DELETION = 'account_deletion';

/** last_seen_at is rewritten at most this often; nothing reads it more finely. */
const SESSION_TOUCH_INTERVAL = 60;

// ─────────────────────────────────────────────────────────────────────────────
// The cookie
// ─────────────────────────────────────────────────────────────────────────────
//
// Read, set and expired here and nowhere else: sessionSecretFrom(),
// withSessionCookie(), withoutSessionCookie(), withoutOldSessionCookie().

/** The session cookie's name as configured - `anotoki_iam` - before any prefix. The device cookie is named after it. */
function sessionCookieBaseName(): string
{
    return (string) iamConfig()['cookie_name'];
}

/**
 * The name the session cookie goes by. Wherever it is Secure, `__Host-` before
 * it (a hardening of 2026-10-07, docs/decisions.md): a browser keeps a cookie
 * of that exact name only when this very host sets it - Secure, Path=/, no
 * Domain. The IAM reads it by that exact name, as sent (sessionSecretFrom()):
 * a look-alike a sibling host can set - a name that only decodes to it, like
 * `__%48ost-anotoki_iam` - is not it, and the name sent twice is no session.
 * Wherever it is Secure, a cookie under the bare name is never read at all,
 * beside a __Host- one or without it (the owner's decision of 2026-10-10). On
 * a plain-http development machine the bare name, as before: a __Host- cookie
 * is Secure.
 */
function sessionCookieName(): string
{
    return sessionCookieSecure() ? '__Host-' . sessionCookieBaseName() : sessionCookieBaseName();
}

/**
 * The bare name a Secure session cookie went by before the prefix, or null
 * where the name never changed. Never read (the owner's decision of
 * 2026-10-10): a cookie under it opens nothing, not even in a browser that
 * sends no __Host- one - where it once stood for a session from before the
 * prefix, and where one a sibling host planted, beside a planted sign-in
 * hint, signed the browser into the planter's account at the first site that
 * hopped. Only expired: by every sign-out and every refusal that drops the
 * cookie (withoutSessionCookie()), and by every answer that uses the session
 * while a browser still sends one (withoutOldSessionCookie()), so the ones the
 * IAM set before the prefix leave the browsers.
 */
function sessionCookieOldName(): ?string
{
    return sessionCookieSecure() ? sessionCookieBaseName() : null;
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
 * cross-site POST; Secure wherever the IAM is on HTTPS, and then under the
 * __Host- name, with Path=/ and never a Domain. A remembered session's cookie
 * outlives the browser, until the session's absolute end; the server's own
 * expiry is still what decides.
 */
function withSessionCookie(Response $response, string $secret, array $session): Response
{
    $parts = [sessionCookieName() . '=' . $secret, 'Path=/', 'HttpOnly', 'SameSite=Lax'];

    if (asBool($session['remember'] ?? false)) {
        $parts[] = 'Max-Age=' . max(1, (int) strtotime((string) ($session['absolute_expires_at'] ?? '')) - time());
    }

    if (sessionCookieSecure()) {
        $parts[] = 'Secure';
    }

    return $response->withAddedHeader('Set-Cookie', implode('; ', $parts));
}

/**
 * Expires the session cookie - under its name and, where it had another,
 * under the old one too, with the attributes it was set with (this host only,
 * Path=/), so that the browser really drops it.
 */
function withoutSessionCookie(Response $response): Response
{
    foreach (array_filter([sessionCookieName(), sessionCookieOldName()]) as $name) {
        $response = withSessionCookieExpired($response, $name);
    }

    return $response;
}

function withSessionCookieExpired(Response $response, string $name): Response
{
    $parts = [$name . '=', 'Path=/', 'HttpOnly', 'SameSite=Lax', 'Max-Age=0', 'Expires=Thu, 01 Jan 1970 00:00:00 GMT'];

    if (sessionCookieSecure()) {
        $parts[] = 'Secure';
    }

    return $response->withAddedHeader('Set-Cookie', implode('; ', $parts));
}

/**
 * The values the request's Cookie header carries under exactly this name, in
 * the order they came - the name compared as it was sent, never decoded.
 *
 * The cookie params are no place to read the session cookie from: Slim's
 * parser, which builds them in production (Cookies::parseHeader()), URL-decodes
 * every name and keeps the first of each. So `__%48ost-anotoki_iam` - a name no
 * browser holds to the __Host- prefix's rules, which a sibling host may set for
 * the whole domain with a longer path, sent first - would come out as
 * `__Host-anotoki_iam` in place of the real one (found by the security review).
 */
function cookieValuesNamed(Request $request, string $name): array
{
    $values = [];

    foreach ($request->getHeader('Cookie') as $header) {
        foreach (explode(';', $header) as $pair) {
            $parts = explode('=', $pair, 2);
            if (count($parts) === 2 && trim($parts[0], " \t") === $name) {
                $values[] = trim($parts[1], " \t\r\n");
            }
        }
    }

    return $values;
}

/**
 * The session's secret from the request's cookie, or null - the one place the
 * cookie is read, by its exact name in the Cookie header (cookieValuesNamed()),
 * and by that one name alone: on https `__Host-anotoki_iam`, which only the
 * IAM's own host can set. A cookie under the bare name is never looked at
 * there (the owner's decision of 2026-10-10), beside a __Host- one or without
 * it: one left from before the prefix opens nothing any more - its browser
 * signs in once more - and one a sibling host planted for the whole domain
 * never stands in for a session. On plain-http development the bare name is
 * the only one. The name sent more than once is no session: only the IAM's
 * own host sets a __Host- cookie, so two of them mean something is wrong - and
 * of two bare ones, one was planted.
 */
function sessionSecretFrom(Request $request): ?string
{
    $values = cookieValuesNamed($request, sessionCookieName());

    return count($values) === 1 && preg_match('/^[A-Za-z0-9_-]{40,64}$/D', $values[0]) === 1 ? $values[0] : null;
}

/**
 * The answer to a request that still carried a cookie under the old bare name
 * over https: that cookie expired, with the attributes the IAM set it with
 * before the prefix (this host, Path=/), so that it leaves the browser. It was
 * not read, and nothing is set in its place: its session is no longer this
 * browser's at the IAM, whose owner signs in once more. Not where the answer
 * expires it already (a sign-out, a refusal that drops the cookie). One a
 * sibling host planted, with a Domain or a longer Path, is not reached by
 * this; it opens nothing either. Whether one came is asked as the session
 * cookie is read, by the exact name in the Cookie header (cookieValuesNamed()).
 * Called where the session is used - requireSession(), authorize,
 * completeSignIn() - never by the four endpoints the sites call cross-origin,
 * which read no cookie and set none.
 */
function withoutOldSessionCookie(Request $request, Response $response): Response
{
    $old = sessionCookieOldName();

    if ($old === null || cookieValuesNamed($request, $old) === [] || responseSetsCookie($response, $old)) {
        return $response;
    }

    return withSessionCookieExpired($response, $old);
}

/** Whether a response sets or expires a cookie of this name already. */
function responseSetsCookie(Response $response, string $name): bool
{
    foreach ($response->getHeader('Set-Cookie') as $line) {
        if (str_starts_with($line, $name . '=')) {
            return true;
        }
    }

    return false;
}

// ─────────────────────────────────────────────────────────────────────────────
// The sign-in hint
// ─────────────────────────────────────────────────────────────────────────────
//
// The owner's decision of 2026-10-07 (docs/decisions.md): signing in here also
// sets `anotoki_signed_in`, a cookie every anotoki site's pages can read, which
// says only that somebody signed in at the IAM in this browser. A site that
// lets visitors in and sees it with no session of its own signs the person in
// with one silent hop (authorize, prompt=none) and back; without it, nobody - a
// visitor with no account, a search engine - is ever sent anywhere.
//
// A hint, never proof. Its value is random per session, kept nowhere and
// derived from nothing: not the session's secret, its hash or its sid, not the
// account, not the time. Nothing here reads it as more than "was it sent" - to
// clear it, or to set it again - and a site only decides by it whether a hop
// is worth making: who the person is comes from the code that hop brings back,
// by every rule any sign-in keeps. It is the one IAM cookie a script can read
// and the one with a Domain; the session cookie stays host-only and HttpOnly.
//
// Set at every session opened (completeSignIn()), and again where a live
// session finds the browser without one; cleared at sign-out, at a deletion
// asked for, and wherever it arrives with no live session behind it - or with
// an account no site lets in. A session ended elsewhere leaves it stale until
// this browser next reaches the IAM: the first authorize that sees it with no
// session clears it, for every site.

/** The hint's shape on its way in: what the sites accept as one, a plain token. */
const SIGN_IN_HINT_SHAPE = '/^[A-Za-z0-9_-]{16,64}$/D';

/** How often the error log is told again that the hint was passed over or kept to the IAM's host: once a day. */
const SIGN_IN_HINT_LOG_EVERY = 86400;

/**
 * The hint's name - `anotoki_signed_in` unless config/iam.local.php names
 * another - or null for no hint at all. Null there is the way to switch it
 * off; a name that is not a plain token, one with a prefix browsers give a
 * meaning to (__Host-, __Secure-), or another IAM cookie's switches it off too,
 * and the error log says so. Plain: PHP hands a cookie whose name has a dot or
 * a space in it to the app under another name, and one sharing the session
 * cookie's name would overwrite it.
 */
function signInHintCookieName(): ?string
{
    $name = iamConfig()['hint_cookie_name'] ?? null;

    if ($name === null) {
        return null;
    }

    if (
        is_string($name) &&
        preg_match('/^[A-Za-z0-9_-]{1,64}$/D', $name) === 1 &&
        preg_match('/^__(host|secure)-/i', $name) !== 1 &&
        !in_array($name, [sessionCookieName(), sessionCookieBaseName(), signInDeviceCookieName()], true)
    ) {
        return $name;
    }

    signInHintMisconfigured('config/iam.local.php: hint_cookie_name is not a plain cookie name of its own; no sign-in hint is set');

    return null;
}

/**
 * The domain the hint is set for, or null for a cookie of the IAM's host
 * alone: signInHintDomainNamed(), when a site is under it.
 *
 * The hint goes with every request to every host under its domain, so that
 * domain must be the family's own, not merely the IAM's parent - an IAM at
 * iam.some-hosting.example would otherwise hand it to every customer of the
 * hosting. So it is used only when some application switched on - not the
 * IAM's own entry - lands its sign-in on a host under it (its login redirect
 * URIs, the admin panel's Applications); otherwise the hint is host-only, and
 * the error log says so. Asked afresh at every call, like everything about
 * the issuer and the applications.
 */
function signInHintDomain(): ?string
{
    $domain = signInHintDomainNamed();

    if ($domain === null || signInHintSitesUnder($domain)) {
        return $domain;
    }

    signInHintMisconfigured("no application switched on signs in under {$domain}, so the sign-in hint is host-only (docs/operations.md)");

    return null;
}

/**
 * The domain the issuer names for the hint, before the sites are asked about
 * it - also the one a hint is cleared under, so one set while a site was there
 * goes when none is left.
 *
 * Derived, unless config/iam.local.php names one: the issuer's host without
 * its first label, when it has three labels or more (iam.anotoki.eu ->
 * anotoki.eu), so that every anotoki site under it reads the hint. A host of
 * two labels keeps it to itself - one label less is a top-level domain no
 * browser sets a cookie for - as does an address. A loopback issuer never
 * gives it a domain, whatever is configured: localhost's cookies ignore ports,
 * so every site's dev server on this computer reads the host-only one, and no
 * cookie may name this computer as a real host's domain.
 *
 * A configured domain the issuer's host is not under, a single label, an
 * address or a loopback name is passed over: the hint is host-only, and the
 * error log says so. The hint is a convenience; a wrong setting must cost
 * nothing but itself.
 */
function signInHintDomainNamed(): ?string
{
    $host = rtrim(strtolower((string) parse_url(iamIssuer(), PHP_URL_HOST)), '.');

    if ($host === '' || signInHintLoopback($host) || filter_var(trim($host, '[]'), FILTER_VALIDATE_IP) !== false) {
        return null;
    }

    $configured = iamConfig()['hint_cookie_domain'] ?? null;

    if ($configured === null) {
        $labels = explode('.', $host);

        return count($labels) >= 3 ? implode('.', array_slice($labels, 1)) : null;
    }

    // A leading dot is how a Domain used to be written; browsers drop it.
    $domain = is_string($configured) ? ltrim(strtolower(trim($configured)), '.') : '';
    $fits =
        preg_match('/^[a-z0-9-]+(\.[a-z0-9-]+)+$/D', $domain) === 1 &&
        filter_var($domain, FILTER_VALIDATE_IP) === false &&
        !signInHintLoopback($domain) &&
        ($host === $domain || str_ends_with($host, '.' . $domain));

    if (!$fits) {
        signInHintMisconfigured('config/iam.local.php: hint_cookie_domain is not a domain the issuer\'s host is under, so the sign-in hint is host-only');

        return null;
    }

    return $domain;
}

/**
 * Whether a site lands its sign-in under the domain: a login redirect URI of
 * an application switched on, not the IAM's own entry, at a host that is the
 * domain or under it. A question about the database that cannot fail on any
 * schema the IAM has had; should the database not answer, the hint is
 * host-only - it must never take a request down.
 */
function signInHintSitesUnder(string $domain): bool
{
    try {
        $uris = iamDb()
            ->query(
                "SELECT u.uri FROM application_redirect_uris u JOIN applications a ON a.id = u.application_id
              WHERE u.kind = 'login' AND a.enabled AND NOT a.system",
            )
            ->fetchAll(PDO::FETCH_COLUMN);
    } catch (\Throwable $e) {
        error_log('[sign-in hint] could not read the applications\' addresses: ' . $e->getMessage());

        return false;
    }

    foreach ($uris as $uri) {
        $host = rtrim(strtolower((string) parse_url((string) $uri, PHP_URL_HOST)), '.');
        if ($host === $domain || str_ends_with($host, '.' . $domain)) {
            return true;
        }
    }

    return false;
}

/** A name that means this computer: loopbackHost()'s, and every name under localhost (RFC 6761). */
function signInHintLoopback(string $host): bool
{
    return loopbackHost($host) || str_ends_with(strtolower($host), '.localhost');
}

/**
 * Tells the error log that the hint was passed over or kept to the IAM's host
 * - at most once a day for each thing it says. A stamp file under
 * storage/cache remembers when it last did: under PHP-FPM or CGI every request
 * starts afresh, so a flag in memory would say it at every request. Where no
 * stamp can be kept, once per process.
 */
function signInHintMisconfigured(string $why): void
{
    static $saidWithoutStamp = [];

    $stamp = iamStorageDir() . '/cache/sign-in-hint-' . substr(sha256($why), 0, 16) . '.logged';
    clearstatcache(true, $stamp);
    $last = @filemtime($stamp);

    if ($last !== false && time() - $last < SIGN_IN_HINT_LOG_EVERY) {
        return;
    }

    $directory = dirname($stamp);
    if (!((is_dir($directory) || @mkdir($directory, 0775, true)) && @touch($stamp))) {
        if (isset($saidWithoutStamp[$why])) {
            return;
        }
        $saidWithoutStamp[$why] = true;
    }

    error_log('[sign-in hint] ' . $why);
}

/**
 * Whether an account may have a hint at all: switched on, approved, neither
 * being deleted nor deleted. An account no site lets in gives no site a
 * reason to try - and a hint that reached its browser anyway, through a
 * request that raced the change, is cleared where it is seen.
 */
function signInHintAllowed(?array $user): bool
{
    return $user !== null && !asBool($user['disabled'] ?? false) && !accountAwaitsApproval($user) && !accountDeletionPending($user) && !accountAnonymized($user);
}

/**
 * Sets the hint, with a new value: at every session opened (completeSignIn()),
 * where a live session finds the browser without one - a site's single
 * sign-on, the account pages - and where the sites are to try again: a
 * deletion cancelled, a forced change of password done.
 *
 * Path=/ and SameSite=Lax: a page of a sibling site reads it, and no
 * cross-site request ever needs it. Not HttpOnly - the sites' pages read it -
 * and Secure exactly when the session cookie is. It lasts as the session
 * does: until the browser closes for a session not remembered, else until the
 * session's absolute end (a later sliding expiry leaves it stale, which costs
 * one hop).
 */
function withSignInHint(Response $response, array $session): Response
{
    $name = signInHintCookieName();

    if ($name === null) {
        return $response;
    }

    // Sixteen bytes from the CSPRNG and nothing else, kept nowhere. The sites
    // try each value once, so a new sign-in is a new value.
    $parts = [$name . '=' . randomSecret(16), 'Path=/', 'SameSite=Lax'];

    if (asBool($session['remember'] ?? false)) {
        $left = (int) strtotime((string) ($session['absolute_expires_at'] ?? '')) - time();
        if ($left <= 0) {
            return $response;
        }
        $parts[] = 'Max-Age=' . $left;
    }

    $domain = signInHintDomain();
    if ($domain !== null) {
        $parts[] = 'Domain=' . $domain;
    }

    if (sessionCookieSecure()) {
        $parts[] = 'Secure';
    }

    return $response->withAddedHeader('Set-Cookie', implode('; ', $parts));
}

/**
 * Expires the hint: the copy under the domain the issuer names now (whether or
 * not a site is under it any more) and, when it names one, a host-only copy
 * too - so a hint set before a domain was named, or while a site was under it,
 * goes. A copy left under an earlier domain - one config/iam.local.php named
 * before it was changed - is not reached, and stays stale until it runs out:
 * one wasted hop at most, as for any stale hint.
 */
function withoutSignInHint(Response $response): Response
{
    $name = signInHintCookieName();

    if ($name === null) {
        return $response;
    }

    $expired = [$name . '=', 'Path=/', 'SameSite=Lax', 'Max-Age=0', 'Expires=Thu, 01 Jan 1970 00:00:00 GMT'];
    $secure = sessionCookieSecure() ? ['Secure'] : [];
    $domain = signInHintDomainNamed();

    if ($domain !== null) {
        $response = $response->withAddedHeader('Set-Cookie', implode('; ', [...$expired, 'Domain=' . $domain, ...$secure]));
    }

    return $response->withAddedHeader('Set-Cookie', implode('; ', [...$expired, ...$secure]));
}

/**
 * Whether the request carries a hint the IAM could have set. Only ever asked
 * to clear it or to leave it be - never as a sign of who anybody is. One of
 * another shape (tossed by a sibling host, or garbled) counts as none, and a
 * live session puts a real one in its place.
 */
function signInHintSent(Request $request): bool
{
    $name = signInHintCookieName();
    $value = $name === null ? null : $request->getCookieParams()[$name] ?? null;

    return is_string($value) && preg_match(SIGN_IN_HINT_SHAPE, $value) === 1;
}

/** Whether a response sets or expires the hint already - a handler that decided about it is not overruled. */
function signInHintInResponse(Response $response): bool
{
    $name = signInHintCookieName();

    return $name !== null && responseSetsCookie($response, $name);
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
           RETURNING *",
    );
    $insert->execute([(int) $user['id'], sha256($secret), randomHex(16), $method, pgBool($mfa), pgBool($remember), requestIp($request), requestUserAgent($request), $idle, $absolute]);

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
            AND expires_at > CURRENT_TIMESTAMP AND absolute_expires_at > CURRENT_TIMESTAMP',
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
    return $session !== null && $session['revoked_at'] === null && strtotime((string) $session['expires_at']) > time() && strtotime((string) $session['absolute_expires_at']) > time();
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
          WHERE id = ?",
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
    $statement = $pdo->prepare('UPDATE sessions SET revoked_at = CURRENT_TIMESTAMP, revoked_reason = ? WHERE id = ? AND revoked_at IS NULL');
    $statement->execute([$reason, $sessionId]);

    $pdo->prepare(
        "UPDATE refresh_tokens SET revoked_at = CURRENT_TIMESTAMP, revoked_reason = ?
          WHERE session_id = ? AND kind = 'rotating' AND revoked_at IS NULL",
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
          WHERE user_id = ? AND revoked_at IS NULL AND expires_at > CURRENT_TIMESTAMP{$except}",
    );
    $statement->execute([$reason, $userId]);

    $tokenExcept = $exceptSessionId === null ? '' : ' AND (session_id IS NULL OR session_id <> ' . (int) $exceptSessionId . ')';
    $pdo->prepare(
        "UPDATE refresh_tokens SET revoked_at = CURRENT_TIMESTAMP, revoked_reason = ?
          WHERE user_id = ? AND kind = 'rotating' AND revoked_at IS NULL{$tokenExcept}",
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
          WHERE user_id = ? AND kind = 'personal' AND revoked_at IS NULL",
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
          LIMIT ?",
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

/** The `method` of the password a deletion of the account is asked with, and of its mail (16 characters: the column's width). */
const ATTEMPT_METHOD_ACCOUNT_DELETION = 'account_deletion';

/**
 * The `method` of the current password the account page asks for anything
 * else - a change of password, Google connected, password sign-in switched on
 * or off (accountProof()).
 */
const ATTEMPT_METHOD_ACCOUNT_PASSWORD = 'account_password';

/**
 * The `method` of the password an administrator gives to make, download or
 * delete a backup of the database (api/admin_tools.php: the same proof,
 * accountProof(), counted by the same limiter).
 */
const ATTEMPT_METHOD_BACKUP = 'admin_backup';

/**
 * The source the limiter counts, and keeps as an attempt's `ip`: the
 * requester's address (requestIp(): the visitor's, through Cloudflare's
 * ranges) in the anotoki family's bucket - an IPv4 address whole, an IPv6
 * address its /64 ("2001:db8:aa:bb::/64"). An IPv6 subscriber holds a whole
 * /64, 2^64 addresses: counted address by address, every one of them would be
 * a fresh source with a fresh allowance, and the limits no ceiling at all - on
 * guessing a password, or on mailing somebody's address. Null when the
 * connection has no address.
 */
function limiterSource(Request $request): ?string
{
    $ip = requestIp($request);

    return $ip === null ? null : substr(ClientAddress::bucket($ip), 0, 45);
}

function recordLoginAttempt(PDO $pdo, Request $request, string $email, ?int $userId, string $method, string $outcome): void
{
    $pdo->prepare('INSERT INTO login_attempts (email, user_id, ip, method, outcome) VALUES (?, ?, ?, ?, ?)')->execute([
        substr(strtolower($email), 0, 255),
        $userId,
        limiterSource($request),
        $method,
        $outcome,
    ]);
}

/**
 * Whether this caller has failed too often to try again yet - "this caller"
 * being its source as limiterSource() counts it (an IPv6 address by its /64).
 *
 * Counted rather than locked: nothing is stored against the account, so the
 * refusal lifts on its own as the window rolls past.
 */
function loginAttemptsExceeded(PDO $pdo, Request $request, string $email): bool
{
    $ip = limiterSource($request);

    if ($ip === null) {
        return false;
    }

    $window = 'CURRENT_TIMESTAMP - (' . ATTEMPT_WINDOW . " * INTERVAL '1 second')";

    // Requests that send mail are recorded as 'sent' and count here too, which
    // is what stops the resend and reset buttons being a way to spray mail at
    // somebody's address.
    $perPair = $pdo->prepare("SELECT count(*) FROM login_attempts WHERE email = ? AND ip = ? AND outcome <> 'ok' AND created_at > $window");
    $perPair->execute([strtolower($email), $ip]);

    if ((int) $perPair->fetchColumn() >= ATTEMPT_MAX_PER_EMAIL_IP) {
        return true;
    }

    $perIp = $pdo->prepare("SELECT count(*) FROM login_attempts WHERE ip = ? AND outcome <> 'ok' AND created_at > $window");
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
                TIMESTAMPTZ 'epoch')",
    );
    $statement->execute([$userId, $userId]);

    return (int) $statement->fetchColumn();
}
