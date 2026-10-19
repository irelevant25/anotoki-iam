<?php

/**
 * The sign-in hint (the owner's decision of 2026-10-07), and the session
 * cookie's __Host- name (a hardening of the same day).
 *
 * Signing in at the IAM also sets `anotoki_signed_in`, a cookie every anotoki
 * site's pages can read, which says only that somebody signed in at the IAM in
 * this browser - so a site that lets visitors in can sign the person in with
 * one silent hop (prompt=none), and sends nobody without it anywhere.
 *
 * Set by every way in, a new value each time; again where a live session finds
 * the browser without one - a site's single sign-on, the account and admin
 * pages - and where the sites are to try again: a deletion cancelled, a forced
 * change of password done. Never for an account no site lets in. Cleared by
 * both sign-outs, ending this browser's own session, a deletion asked for,
 * every answer with no session behind it (the 401s, the sign-in page's accept,
 * authorize - prompt=none's login_required among them), and wherever an
 * account no site lets in shows one; kept by interaction_required. Host-only on
 * this computer; on a real host the issuer's parent domain, or one configured
 * that the issuer is under - and only while a site signs in under it. SameSite=
 * Lax, Secure when the session cookie is, never HttpOnly. A setting passed over
 * is said in the error log at most once a day. And the attacks: a forged or
 * stale hint opens nothing anywhere, it carries nothing of the person, and it
 * brings no CSRF or CORS exemption.
 *
 * On https the session cookie is `__Host-anotoki_iam`, which no sibling host
 * can plant, and the bare `anotoki_iam` is never read there (the owner's
 * decision of 2026-10-10): a cookie under it - even one carrying a live
 * session's secret, as a browser from before the prefix does - opens nothing
 * anywhere and is expired in the answer, while the sites keep their tokens; a
 * planted one beside a planted hint signs nobody in; a __Host- session beside
 * one is read by its exact name; and plain-http development is unchanged.
 */

declare(strict_types=1);

require __DIR__ . '/lib.php';

// This run's own database is a fresh IAM, built from every migration.
bootTests();

const PASSWORD = 'correct horse';
const HINT = 'anotoki_signed_in';
const SESSION_COOKIE = 'anotoki_iam';
const HOST_SESSION_COOKIE = '__Host-anotoki_iam';
const DEVICE_COOKIE = 'anotoki_iam_device';
const HINT_SHAPE = '/^[A-Za-z0-9_-]{22}$/D';
const SURVEY_FRONTEND = 'http://localhost:4700';
const SURVEY_CALLBACK = 'http://localhost:4700/auth/callback';
const GOOGLE_TEST_CLIENT = 'iam-test.apps.googleusercontent.com';
const REAL_HOST = 'https://iam.anotoki.test';
/** Where the survey site signs in on the real host of these tests: a site under the issuer's parent domain. */
const REAL_SITE_CALLBACK = 'https://survey.anotoki.test/auth/callback';
/** A remembered session's absolute end, ninety days (config/iam.php). */
const REMEMBERED = 90 * 86400;

$adminId = makeUser('admin', 'admin@example.test');
grantRole($adminId, 'iam', 'ADMIN');

/** The Set-Cookie lines a response has for one cookie, in the order they came. */
function cookieLines(TestResponse $response, string $name): array
{
    $lines = [];
    foreach ($response->headers as $header => $values) {
        if (strcasecmp((string) $header, 'Set-Cookie') === 0) {
            foreach ($values as $line) {
                if (str_starts_with($line, $name . '=')) {
                    $lines[] = $line;
                }
            }
        }
    }

    return $lines;
}

/** The names of every cookie a response sets or expires, in order. */
function cookieNames(TestResponse $response): array
{
    $names = [];
    foreach ($response->headers as $header => $values) {
        if (strcasecmp((string) $header, 'Set-Cookie') === 0) {
            foreach ($values as $line) {
                $names[] = explode('=', $line, 2)[0];
            }
        }
    }

    return $names;
}

/** A Set-Cookie line taken apart: ['value' => ..., 'path' => '/', 'secure' => true, ...] - names lower-cased, a bare one true. */
function cookieParts(string $line): array
{
    $parts = explode(';', $line);
    $pair = explode('=', (string) array_shift($parts), 2);
    $attributes = ['value' => $pair[1] ?? ''];
    foreach ($parts as $part) {
        [$key, $setting] = array_pad(explode('=', trim($part), 2), 2, true);
        $attributes[strtolower((string) $key)] = $setting;
    }

    return $attributes;
}

/** The hint a response set, taken apart - or null when it set none (expiring it is not setting it). */
function hintSet(TestResponse $response): ?array
{
    foreach (cookieLines($response, HINT) as $line) {
        $parts = cookieParts($line);
        if (($parts['max-age'] ?? null) !== '0') {
            return $parts;
        }
    }

    return null;
}

/** Whether a response expires the hint, and sets none. */
function hintCleared(TestResponse $response): bool
{
    return cookieLines($response, HINT) !== [] && hintSet($response) === null;
}

/** Whether a response leaves the hint alone: no line for it at all. */
function hintUntouched(TestResponse $response): bool
{
    return cookieLines($response, HINT) === [];
}

/** Whether every line a response has for a cookie expires it - and there is one. */
function cookieExpired(TestResponse $response, string $name): bool
{
    $lines = cookieLines($response, $name);

    return $lines !== [] && array_filter($lines, static fn(string $line): bool => (cookieParts($line)['max-age'] ?? null) !== '0') === [];
}

/** A browser that signed in with a password, from an address of its own: the browser and the answer. */
function signInWithPassword(string $email, string $ip, array $extra = []): array
{
    $browser = new Browser($ip);
    $answer = $browser->signIn($email, PASSWORD, $extra);
    same(200, $answer->status, "$email signs in: " . $answer->body);

    return [$browser, $answer];
}

/** A browser carrying nothing but a sign-in hint - a stale one, a copied one, a forged one. */
function holdingOnly(string $hint, string $ip): Browser
{
    $browser = new Browser($ip);
    $browser->cookies[HINT] = $hint;

    return $browser;
}

/** A browser carrying a session's secret under the bare name - one from before the prefix, or one planted - and, if given, a hint. */
function holdingBare(string $secret, string $ip, ?string $hint = null): Browser
{
    $browser = new Browser($ip);
    $browser->cookies[SESSION_COOKIE] = $secret;
    if ($hint !== null) {
        $browser->cookies[HINT] = $hint;
    }

    return $browser;
}

/** Starts a sign-in to the survey site: the answer, and the PKCE verifier. */
function authorizeSurvey(Browser $browser, array $extra = []): array
{
    [$verifier, $challenge] = pkce();
    $answer = $browser->get(
        '/api/oidc/authorize?' .
            http_build_query(
                $extra + [
                    'response_type' => 'code',
                    'client_id' => 'survey',
                    'redirect_uri' => SURVEY_CALLBACK,
                    'scope' => 'openid profile email',
                    'state' => 's',
                    'nonce' => 'n',
                    'code_challenge' => $challenge,
                    'code_challenge_method' => 'S256',
                ],
            ),
    );

    return [$answer, $verifier];
}

/** The tokens the survey site gets for a code. */
function surveyTokens(string $code, string $verifier): array
{
    $tokens = (new Browser('10.18.99.1'))->request('POST', '/api/oidc/token', [
        'form' => ['grant_type' => 'authorization_code', 'code' => $code, 'redirect_uri' => SURVEY_CALLBACK, 'client_id' => 'survey', 'code_verifier' => $verifier],
        'headers' => ['Origin' => SURVEY_FRONTEND],
    ]);
    same(200, $tokens->status, 'the code exchanged: ' . $tokens->body);

    return $tokens->json();
}

/** The emailed code's way in: asked for, read from the mail's subject, typed. */
function signInByCode(Browser $browser, string $email): TestResponse
{
    same(200, $browser->post('/api/login/code', ['email' => $email])->status, 'a code asked for');
    preg_match('/\b(\d{6})\b/', (string) (lastMailTo($email)['subject'] ?? ''), $found);

    return $browser->post('/api/login/code/verify', ['email' => $email, 'code' => $found[1] ?? '']);
}

/**
 * A Google id token for this IAM, signed by a key it is given as Google's: the
 * key cache in this run's storage, which googleSigningKeys() reads before it
 * would ask Google, and a client id in this run's configuration.
 */
function googleToken(string $subject, string $email, string $name): string
{
    static $key = null;

    if ($key === null) {
        $pair = generateRsaKeyPair();
        $key = openssl_pkey_get_private($pair['private_pem']);
        @mkdir(iamStorageDir() . '/cache', 0700, true);
        file_put_contents(
            iamStorageDir() . '/cache/google-jwks.json',
            json_encode([
                'expires_at' => time() + 3600,
                'keys' => ['sign-in-hint-test' => ['kty' => 'RSA', 'kid' => 'sign-in-hint-test', 'n' => $pair['n'], 'e' => $pair['e']]],
            ]),
        );
        writeTestConfig('google', ['client_id' => GOOGLE_TEST_CLIENT]);
        forgetLocalConfig();
    }

    $header = base64UrlEncode((string) json_encode(['alg' => 'RS256', 'kid' => 'sign-in-hint-test', 'typ' => 'JWT']));
    $payload = base64UrlEncode(
        (string) json_encode([
            'iss' => 'https://accounts.google.com',
            'aud' => GOOGLE_TEST_CLIENT,
            'sub' => $subject,
            'email' => $email,
            'email_verified' => true,
            'name' => $name,
            'iat' => time(),
            'exp' => time() + 600,
            // Each its own, as Google writes them: a token is good once (api/google_identity.php).
            'jti' => bin2hex(random_bytes(16)),
        ]),
    );
    openssl_sign("{$header}.{$payload}", $signature, $key, OPENSSL_ALGO_SHA256);

    return "{$header}.{$payload}." . base64UrlEncode($signature);
}

/** Runs $body with config/iam.local.php saying $iam, and puts this run's back afterwards. */
function withIamConfig(array $iam, callable $body): void
{
    writeTestConfig('iam', $iam);
    forgetLocalConfig();
    try {
        $body();
    } finally {
        writeTestConfig('iam', ['public_url' => TEST_ISSUER]);
        forgetLocalConfig();
    }
}

/** Runs $body with one more address an application lands its sign-in (or sign-out) at, taken away afterwards. */
function withSiteAt(string $uri, callable $body, string $clientId = 'survey', string $kind = 'login'): void
{
    iamDb()
        ->prepare('INSERT INTO application_redirect_uris (application_id, kind, uri) SELECT id, ?, ? FROM applications WHERE client_id = ?')
        ->execute([$kind, $uri, $clientId]);
    try {
        $body();
    } finally {
        iamDb()
            ->prepare('DELETE FROM application_redirect_uris WHERE kind = ? AND uri = ?')
            ->execute([$kind, $uri]);
    }
}

/** Runs $body on a real host: the https issuer REAL_HOST (with $iam's tunables), and the survey site signing in under its parent domain. */
function onRealHost(callable $body, array $iam = []): void
{
    withSiteAt(REAL_SITE_CALLBACK, static fn() => withIamConfig(['public_url' => REAL_HOST] + $iam, $body));
}

/** Runs $body with the error log going to a file of this run's, and answers what was written there. */
function capturingErrorLog(callable $body): string
{
    $file = testEnvironment()['base'] . DIRECTORY_SEPARATOR . 'error-' . bin2hex(random_bytes(4)) . '.log';
    $previous = ini_get('error_log');
    ini_set('error_log', $file);
    try {
        $body();
    } finally {
        ini_set('error_log', is_string($previous) ? $previous : '');
    }

    return is_file($file) ? (string) file_get_contents($file) : '';
}

/** The stamps by which the hint's log lines are said at most once a day. */
function hintLogStamps(): array
{
    return glob(iamStorageDir() . DIRECTORY_SEPARATOR . 'cache' . DIRECTORY_SEPARATOR . 'sign-in-hint-*.logged') ?: [];
}

/** The session row behind a browser's cookie, whichever name it is under. */
function sessionOf(Browser $browser): array
{
    $statement = iamDb()->prepare('SELECT * FROM sessions WHERE token_hash = ?');
    $statement->execute([sha256($browser->cookies[HOST_SESSION_COOKIE] ?? ($browser->cookies[SESSION_COOKIE] ?? ''))]);

    return $statement->fetch() ?: [];
}

/** Takes the survey site's MEMBER from an account: it may sign in everywhere but there. */
function takeSurveyMember(int $userId): void
{
    iamDb()
        ->prepare(
            "DELETE FROM user_roles WHERE user_id = ? AND role_id IN (
             SELECT r.id FROM application_roles r JOIN applications a ON a.id = r.application_id WHERE a.client_id = 'survey' AND r.name = 'MEMBER')",
        )
        ->execute([$userId]);
}

/** Moves a browser's session cookie from the __Host- name to the bare one: a browser from before the prefix, whose bare cookie https no longer reads. */
function underOldName(Browser $browser): string
{
    $secret = $browser->cookies[HOST_SESSION_COOKIE] ?? '';
    unset($browser->cookies[HOST_SESSION_COOKIE]);
    $browser->cookies[SESSION_COOKIE] = $secret;

    return $secret;
}

// ─────────────────────────────────────────────────────────────────────────────
// The hint: set
// ─────────────────────────────────────────────────────────────────────────────

test('every way in sets the hint, a new value each time: a password, an emailed code, Google, a confirmation link, a reset link', function () {
    makeUser('ways', 'ways@example.test');
    $values = [];

    [$browser, $first] = signInWithPassword('ways@example.test', '10.18.1.1');
    $hint = hintSet($first);
    check($hint !== null, 'a password sets it');
    same($hint['value'], $browser->cookies[HINT] ?? null, 'and the browser keeps it');
    $values[] = $hint['value'];

    $again = $browser->signIn('ways@example.test', PASSWORD);
    same(200, $again->status, 'the same browser signs in again');
    $values[] = hintSet($again)['value'] ?? '';
    same(end($values), $browser->cookies[HINT] ?? null, 'a new session, a new value in the old one\'s place');

    $byCode = signInByCode(new Browser('10.18.1.2'), 'ways@example.test');
    same(200, $byCode->status, 'an emailed code: ' . $byCode->body);
    $values[] = hintSet($byCode)['value'] ?? '';

    $google = (new Browser('10.18.1.3'))->post('/api/login/google', ['credential' => googleToken('google-ways', 'ways@example.test', 'Ways')]);
    same(200, $google->status, 'Google, attached by the address: ' . $google->body);
    $values[] = hintSet($google)['value'] ?? '';

    $made = (new Browser('10.18.1.4'))->post('/api/login/google', ['credential' => googleToken('google-made', 'made@example.test', 'Made')]);
    same(201, $made->status, 'Google, making an account: ' . $made->body);
    $values[] = hintSet($made)['value'] ?? '';

    $newcomer = new Browser('10.18.1.5');
    $registered = $newcomer->post('/api/register', ['username' => 'newcomer', 'email' => 'newcomer@example.test', 'password' => 'a long password', 'language' => 'en']);
    same(201, $registered->status, 'registered');
    check(hintUntouched($registered), 'no session yet, no hint');
    preg_match('#/confirm-email\?token=([0-9a-f]{64})#', (string) (lastMailTo('newcomer@example.test')['body'] ?? ''), $link);
    $confirmed = $newcomer->post('/api/register/confirm', ['token' => $link[1] ?? '']);
    same(200, $confirmed->status, 'a confirmation link signs in: ' . $confirmed->body);
    $values[] = hintSet($confirmed)['value'] ?? '';

    $owner = new Browser('10.18.1.6');
    same(200, $owner->post('/api/password/forgot', ['email' => 'ways@example.test'])->status, 'a reset asked for');
    preg_match('#/reset-password\?token=([0-9a-f]{64})#', (string) (lastMailTo('ways@example.test')['body'] ?? ''), $resetLink);
    $reset = $owner->post('/api/password/reset', ['token' => $resetLink[1] ?? '', 'password' => PASSWORD]);
    same(200, $reset->status, 'a reset link signs in: ' . $reset->body);
    $values[] = hintSet($reset)['value'] ?? '';

    foreach ($values as $value) {
        same(1, preg_match(HINT_SHAPE, $value), 'sixteen random bytes in base64url: ' . json_encode($value));
    }
    same(count($values), count(array_unique($values)), 'every session its own value');
});

test('two-factor: the hint comes with the session, past the second factor - a password stopped for its code sets none', function () {
    $id = makeUser('guarded', 'guarded@example.test');
    $secret = totpSecret();
    iamDb()
        ->prepare('UPDATE users SET totp_secret = ?, totp_enabled = TRUE WHERE id = ?')
        ->execute([encryptSecret($secret), $id]);

    $browser = new Browser('10.18.2.1');
    $stopped = $browser->signIn('guarded@example.test', PASSWORD);
    same([403, 'totp_required'], [$stopped->status, $stopped->code()], 'the password right, the code not given');
    check(hintUntouched($stopped) && !isset($browser->cookies[HINT]), 'no session, no hint');

    $through = $browser->signIn('guarded@example.test', PASSWORD, ['totp' => totpCode($secret, intdiv(time(), 30))]);
    same(200, $through->status, 'with the code: ' . $through->body);
    check(hintSet($through) !== null && isset($browser->cookies[HINT]), 'the hint, with the session');
});

test('an account no site lets in has no hint: one waiting for approval gets none at the sign-in or its confirmation link, and the rule reads the account as it is', function () {
    $id = makeUser('waiting', 'waiting@example.test');
    iamDb()
        ->prepare('UPDATE users SET approved = FALSE WHERE id = ?')
        ->execute([$id]);
    $refused = (new Browser('10.18.3.1'))->signIn('waiting@example.test', PASSWORD);
    same([403, 'approval_pending'], [$refused->status, $refused->code()], 'waiting for an administrator');
    check(hintUntouched($refused), 'no session, no hint');

    $newcomer = new Browser('10.18.3.2');
    same(201, $newcomer->post('/api/register', ['username' => 'newcomerWaits', 'email' => 'newcomer-waits@example.test', 'password' => 'a long password', 'language' => 'en'])->status, 'registered');
    iamDb()
        ->prepare('UPDATE users SET approved = FALSE WHERE email = ?')
        ->execute(['newcomer-waits@example.test']);
    preg_match('#/confirm-email\?token=([0-9a-f]{64})#', (string) (lastMailTo('newcomer-waits@example.test')['body'] ?? ''), $link);
    $confirmed = $newcomer->post('/api/register/confirm', ['token' => $link[1] ?? '']);
    same([403, 'approval_pending'], [$confirmed->status, $confirmed->code()], 'its address confirmed, the account still waiting');
    check(hintUntouched($confirmed), 'and no hint');

    $row = userById(iamDb(), makeUser('allowed', 'allowed@example.test'));
    same(true, signInHintAllowed($row), 'an account switched on, approved, kept');
    same(false, signInHintAllowed(null), 'no account');
    same(false, signInHintAllowed(['disabled' => true] + $row), 'switched off');
    same(false, signInHintAllowed(['approved' => false] + $row), 'waiting for approval');
    same(false, signInHintAllowed(['deletion_due_at' => '2026-11-07 00:00:00+00'] + $row), 'its deletion pending');
    same(false, signInHintAllowed(['anonymized_at' => '2026-11-07 00:00:00+00', 'deletion_due_at' => '2026-11-07 00:00:00+00'] + $row), 'deleted');
});

test('on this computer: host-only, Path=/, SameSite=Lax, readable by script, Secure exactly when the session cookie is; it lasts as the session does', function () {
    makeUser('local', 'local@example.test');

    [, $remembered] = signInWithPassword('local@example.test', '10.18.4.1');
    $hint = hintSet($remembered);
    same(['value', 'path', 'samesite', 'max-age'], array_keys($hint ?? []), 'the value, Path, SameSite and Max-Age - no Domain, no Secure over plain http, not HttpOnly');
    same(['/', 'Lax'], [$hint['path'], $hint['samesite']], 'Path=/ and SameSite=Lax');
    $maxAge = (int) $hint['max-age'];
    check(abs($maxAge - REMEMBERED) <= 60, 'a remembered session\'s: until its absolute end, ninety days - ' . $maxAge);

    $session = cookieParts(cookieLines($remembered, SESSION_COOKIE)[0] ?? '');
    check(isset($session['httponly']) && !isset($session['domain']), 'the session cookie stays HttpOnly and host-only');

    $once = (new Browser('10.18.4.2'))->signIn('local@example.test', PASSWORD, ['remember' => false]);
    same(200, $once->status, 'a sign-in not remembered');
    same(['value', 'path', 'samesite'], array_keys(hintSet($once) ?? []), 'no Max-Age: it goes when the browser closes, as the session cookie does');

    withIamConfig(['public_url' => TEST_ISSUER, 'cookie_secure' => true], function () {
        [, $secured] = signInWithPassword('local@example.test', '10.18.4.3');
        $hint = hintSet($secured) ?? [];
        check(isset($hint['secure'], cookieParts(cookieLines($secured, HOST_SESSION_COOKIE)[0] ?? '')['secure']), 'with cookie_secure on, Secure as the session cookie is - which is then __Host-');
        check(!isset($hint['domain'], $hint['httponly']), 'and still host-only, and readable');
    });
});

test(
    'a live session gets its hint back: a site\'s single sign-on and the account and admin pages set one where the browser has none - a new value each time - and leave one it has alone',
    function () {
        makeUser('returning', 'returning@example.test');
        [$browser] = signInWithPassword('returning@example.test', '10.18.5.1');

        unset($browser->cookies[HINT]);
        [$sso] = authorizeSurvey($browser);
        check(isset(queryOf($sso->location())['code']), 'single sign-on: a code at once - ' . $sso->location());
        check(hintSet($sso) !== null && isset($browser->cookies[HINT]), 'and a hint, for a browser that had none');
        [$again] = authorizeSurvey($browser);
        check(isset(queryOf($again->location())['code']) && hintUntouched($again), 'one it has is left alone');

        unset($browser->cookies[HINT]);
        $page = $browser->get('/api/account');
        same(200, $page->status, 'the account page');
        $firstValue = hintSet($page)['value'] ?? '';
        check($firstValue !== '', 'sets it for a browser that had none');
        check(hintUntouched($browser->get('/api/account')), 'and leaves it alone after');
        unset($browser->cookies[HINT]);
        $secondValue = hintSet($browser->get('/api/account'))['value'] ?? '';
        check(preg_match(HINT_SHAPE, $secondValue) === 1 && $secondValue !== $firstValue, 'given back twice for one session: two values, as random as the first');

        iamDb()
            ->prepare("UPDATE sessions SET absolute_expires_at = CURRENT_TIMESTAMP + INTERVAL '1 hour' WHERE id = ?")
            ->execute([(int) sessionOf($browser)['id']]);
        unset($browser->cookies[HINT]);
        $left = (int) (hintSet($browser->get('/api/account'))['max-age'] ?? 0);
        check(abs($left - 3600) <= 60, 'for as long as the session has left: ' . $left);

        $admin = new Browser('10.18.5.2');
        same(200, $admin->signIn('admin@example.test', PASSWORD)->status, 'the admin signs in');
        unset($admin->cookies[HINT]);
        $panel = $admin->get('/api/admin/stats');
        same(200, $panel->status, 'the admin panel');
        check(hintSet($panel) !== null, 'sets it too');
    },
);

// ─────────────────────────────────────────────────────────────────────────────
// The hint: where it goes
// ─────────────────────────────────────────────────────────────────────────────

test('on a real host: the issuer\'s parent domain, Secure; the session cookie __Host- and the device cookie host-only; signing out expires every copy', function () {
    makeUser('hosted', 'hosted@example.test');

    onRealHost(function () {
        [$browser, $answer] = signInWithPassword('hosted@example.test', '10.18.6.1');
        $hint = hintSet($answer) ?? [];
        same('anotoki.test', $hint['domain'] ?? null, 'Domain: the issuer\'s parent, which the survey site is under');
        check(isset($hint['secure']) && !isset($hint['httponly']), 'Secure, as the session cookie is over https - and readable by script');
        same(['/', 'Lax'], [$hint['path'] ?? null, $hint['samesite'] ?? null], 'Path=/ and SameSite=Lax');

        same([], cookieLines($answer, SESSION_COOKIE), 'no session cookie under the bare name');
        foreach ([HOST_SESSION_COOKIE, DEVICE_COOKIE] as $name) {
            $line = cookieLines($answer, $name)[0] ?? '';
            $cookie = cookieParts($line);
            check(isset($cookie['httponly'], $cookie['secure']) && ($cookie['path'] ?? null) === '/' && !isset($cookie['domain']), "$name: host-only, HttpOnly, Secure, Path=/ - $line");
        }

        $out = $browser->post('/api/logout');
        same(200, $out->status, 'signed out');
        same(
            [['anotoki.test', '0', true], [null, '0', true]],
            array_map(static fn(string $line): array => [cookieParts($line)['domain'] ?? null, cookieParts($line)['max-age'] ?? null, cookieParts($line)['secure'] ?? null], cookieLines($out, HINT)),
            'both copies of the hint expired - under the domain, and host-only - Secure as they were set',
        );
        check(cookieExpired($out, HOST_SESSION_COOKIE) && cookieExpired($out, SESSION_COOKIE), 'and the session cookie under both its names');
    });

    onRealHost(
        function () {
            [, $answer] = signInWithPassword('hosted@example.test', '10.18.6.2');
            same('anotoki.test', hintSet($answer)['domain'] ?? null, 'a domain named in config/iam.local.php');
            foreach ([HOST_SESSION_COOKIE, DEVICE_COOKIE] as $name) {
                check(!isset(cookieParts(cookieLines($answer, $name)[0] ?? '')['domain']), "$name stays host-only all the same");
            }
        },
        ['hint_cookie_domain' => 'anotoki.test'],
    );

    withIamConfig(['public_url' => REAL_HOST], function () {
        $browser = null;
        withSiteAt(REAL_SITE_CALLBACK, function () use (&$browser) {
            [$browser, $answer] = signInWithPassword('hosted@example.test', '10.18.6.3');
            same('anotoki.test', hintSet($answer)['domain'] ?? null, 'set under the domain while a site is under it');
        });
        $out = $browser->post('/api/logout');
        same(
            ['anotoki.test', null],
            array_map(static fn(string $line): ?string => cookieParts($line)['domain'] ?? null, cookieLines($out, HINT)),
            'with no site left under it, a sign-out still expires the copy under the domain',
        );
    });
});

test('the domain: the issuer\'s parent, or one configured that the issuer is under - and only while a site switched on signs in under it; else host-only', function () {
    makeUser('domains', 'domains@example.test');
    $domainFor = static function (string $issuer, mixed $configured = null): ?string {
        writeTestConfig('iam', ['public_url' => $issuer] + ($configured === null ? [] : ['hint_cookie_domain' => $configured]));
        forgetLocalConfig();

        return signInHintDomain();
    };

    capturingErrorLog(function () use ($domainFor) {
        try {
            same(null, $domainFor('https://iam.anotoki.test'), 'iam.anotoki.test, with no site under anotoki.test: host-only');
            same(null, $domainFor('https://iam.anotoki.test', 'anotoki.test'), 'nor configured, with none');

            withSiteAt('https://survey.anotoki.test/auth/callback', function () use ($domainFor) {
                same('anotoki.test', $domainFor('https://iam.anotoki.test'), 'a site under it: anotoki.test');
                same('anotoki.test', $domainFor('https://IAM.Anotoki.Test'), 'in small letters');
                same('anotoki.test', $domainFor('https://iam.anotoki.test', 'anotoki.test'), 'configured: a domain the issuer is under');
                same('anotoki.test', $domainFor('https://iam.anotoki.test', ' .Anotoki.test'), 'a leading dot, spaces and capitals are nothing');
                same(null, $domainFor('https://iam.anotoki.test', 'iam.anotoki.test'), 'the issuer\'s own host: no site under it');
                same(null, $domainFor('https://anotoki.test'), 'an issuer of two labels: one less is a top-level domain');
                same('anotoki.test', $domainFor('https://anotoki.test', 'anotoki.test'), 'which shares it by naming itself');
                same(null, $domainFor('http://localhost:4300', 'anotoki.test'), 'a loopback issuer never gets one, whatever is configured');
                foreach (
                    ['evil.test', 'otoki.test', 'test', 'login.iam.anotoki.test', 'anotoki.test.', '203.0.113.7', 'localhost', 'iam.localhost', '', 'anotoki.test/x', ['anotoki.test'], 42, true]
                    as $wrong
                ) {
                    same(null, $domainFor('https://iam.anotoki.test', $wrong), 'passed over, host-only: ' . json_encode($wrong));
                }

                iamDb()->exec("UPDATE applications SET enabled = FALSE WHERE client_id = 'survey'");
                try {
                    same(null, $domainFor('https://iam.anotoki.test'), 'the site switched off: it does not count');
                } finally {
                    iamDb()->exec("UPDATE applications SET enabled = TRUE WHERE client_id = 'survey'");
                }
            });

            withSiteAt('https://login.iam.anotoki.test/auth/callback', function () use ($domainFor) {
                same('iam.anotoki.test', $domainFor('https://login.iam.anotoki.test'), 'only the first label goes');
            });
            withSiteAt('https://survey.anotoki.test/', fn() => same(null, $domainFor('https://iam.anotoki.test'), 'a sign-out address is no site signing in'), 'survey', 'logout');
            withSiteAt('https://admin.anotoki.test/auth/callback', fn() => same(null, $domainFor('https://iam.anotoki.test'), 'nor is the IAM\'s own entry'), IAM_CLIENT_ID);
            withSiteAt('https://notanotoki.test/auth/callback', fn() => same(null, $domainFor('https://iam.anotoki.test'), 'a host that merely ends in the letters is not under it'));

            foreach (['https://203.0.113.7', 'https://[2001:db8::7]', 'http://localhost:4300', 'http://127.0.0.1:4300', 'http://[::1]:4300', 'https://iam.dev.localhost'] as $issuer) {
                same(null, $domainFor($issuer), "an address, or this computer: $issuer");
            }
        } finally {
            writeTestConfig('iam', ['public_url' => TEST_ISSUER]);
            forgetLocalConfig();
        }
    });

    // A wrong setting costs nothing but itself: the sign-in goes on, its hint host-only.
    onRealHost(
        function () {
            [, $answer] = signInWithPassword('domains@example.test', '10.18.7.1');
            $hint = hintSet($answer);
            check($hint !== null && !isset($hint['domain']) && isset($hint['secure']), 'signed in, the hint host-only');
        },
        ['hint_cookie_domain' => 'evil.test'],
    );
});

test('a setting passed over is said in the error log at most once a day - kept by a stamp, not in memory - and null, the way to switch the hint off, is no mistake', function () {
    makeUser('logged', 'logged@example.test');
    foreach (hintLogStamps() as $stamp) {
        unlink($stamp);
    }

    $log = capturingErrorLog(function () {
        withIamConfig(['public_url' => REAL_HOST, 'hint_cookie_domain' => 'evil.test'], function () {
            for ($i = 1; $i <= 3; $i++) {
                same(null, signInHintDomain(), "asked again ($i)");
            }
            same(1, count(hintLogStamps()), 'a stamp of when it was said');
            touch(hintLogStamps()[0], time() - 2 * 86400);
            same(null, signInHintDomain(), 'a day later');
        });
        withIamConfig(['public_url' => REAL_HOST], function () {
            same(null, signInHintDomain(), 'no site under the derived domain');
            same(null, signInHintDomain(), 'and again');
        });
        withIamConfig(['public_url' => TEST_ISSUER, 'hint_cookie_name' => null], function () {
            [$browser, $answer] = signInWithPassword('logged@example.test', '10.18.8.1');
            same([SESSION_COOKIE, DEVICE_COOKIE], cookieNames($answer), 'null switches the hint off: the session and device cookies, nothing else');
            same(200, $browser->get('/api/account')->status, 'the session works');
        });
    });

    same(2, substr_count($log, 'hint_cookie_domain'), 'the wrong domain said once, and once more a day later - not at every call: ' . $log);
    check(!str_contains($log, 'evil.test'), 'the setting named, not its value');
    same(1, substr_count($log, 'no application switched on signs in under anotoki.test'), 'a domain no site is under, said once');
    check(!str_contains($log, 'hint_cookie_name'), 'null is not a mistake: nothing said');
});

test('a hint name that would be another cookie\'s, browser-prefixed, or not a plain name is passed over: no hint at all - and the session cookie stands', function () {
    makeUser('named', 'named@example.test');

    $log = capturingErrorLog(function () {
        foreach ([SESSION_COOKIE, DEVICE_COOKIE, '__Host-anotoki_signed_in', '__secure-signed', 'anotoki.signed.in', ''] as $i => $name) {
            withIamConfig(['public_url' => TEST_ISSUER, 'hint_cookie_name' => $name], function () use ($name, $i) {
                [$browser, $answer] = signInWithPassword('named@example.test', '10.18.9.' . ($i + 1));
                same([SESSION_COOKIE, DEVICE_COOKIE], cookieNames($answer), 'the session and device cookies, once each, and no hint: ' . json_encode($name));
                same(1, preg_match('/^[A-Za-z0-9_-]{43}$/D', $browser->cookies[SESSION_COOKIE] ?? ''), 'the session cookie is the session\'s');
                same(200, $browser->get('/api/account')->status, 'and works');
            });
        }
        withIamConfig(['public_url' => REAL_HOST, 'hint_cookie_name' => HOST_SESSION_COOKIE], function () {
            same(null, signInHintCookieName(), 'nor the session cookie\'s __Host- name on https');
        });
    });

    same(1, substr_count($log, 'hint_cookie_name'), 'said once in the log: ' . $log);
});

// ─────────────────────────────────────────────────────────────────────────────
// The hint: cleared, kept
// ─────────────────────────────────────────────────────────────────────────────

test('signing out clears it: at the IAM, from a site with its ID token, from a site with nobody signed in here; a sign-out waiting to be confirmed keeps it until it is', function () {
    makeUser('leaver', 'leaver@example.test');

    [$browser] = signInWithPassword('leaver@example.test', '10.18.10.1');
    $out = $browser->post('/api/logout');
    check(hintCleared($out) && !isset($browser->cookies[HINT]), 'POST /api/logout clears it');
    check(hintCleared(holdingOnly(randomSecret(16), '10.18.10.2')->post('/api/logout')), 'with no session behind it too: signed out is a state');

    [$browser, $answer] = signInWithPassword('leaver@example.test', '10.18.10.3');
    $hint = hintSet($answer)['value'] ?? '';
    [$sso, $verifier] = authorizeSurvey($browser);
    $tokens = surveyTokens(queryOf($sso->location())['code'] ?? '', $verifier);
    $proven = $browser->get(
        '/api/oidc/logout?' .
            http_build_query([
                'client_id' => 'survey',
                'id_token_hint' => $tokens['id_token'],
                'post_logout_redirect_uri' => SURVEY_FRONTEND . '/',
                'state' => 'bye',
            ]),
    );
    same(SURVEY_FRONTEND . '/?state=bye', $proven->location(), 'a site\'s sign-out with its ID token: back on the site');
    check(hintCleared($proven) && !isset($browser->cookies[HINT]), 'and the hint goes with the session');

    $away = holdingOnly($hint, '10.18.10.4')->get('/api/oidc/logout?client_id=survey&post_logout_redirect_uri=' . urlencode(SURVEY_FRONTEND . '/'));
    same(SURVEY_FRONTEND . '/', $away->location(), 'with nobody signed in here: straight back');
    check(hintCleared($away), 'and a hint left in the browser goes');

    [$browser] = signInWithPassword('leaver@example.test', '10.18.10.5');
    $asked = $browser->get('/api/oidc/logout?client_id=survey&post_logout_redirect_uri=' . urlencode(SURVEY_FRONTEND . '/'));
    check(str_contains($asked->location(), '/logout?request='), 'without the ID token: asked first');
    check(hintUntouched($asked) && isset($browser->cookies[HINT]), 'the hint stays until the person says yes');
    $confirmed = $browser->post('/api/logout', ['request' => queryOf($asked->location())['request'] ?? '']);
    same(SURVEY_FRONTEND . '/', $confirmed->json()['redirect'] ?? null, 'confirmed');
    check(hintCleared($confirmed) && !isset($browser->cookies[HINT]), 'and then it goes');
});

test('ending this browser\'s own session - on the account page\'s API or the admin panel\'s - answers as a sign-out; ending another leaves this one its hint', function () {
    makeUser('ender', 'ender@example.test');
    [$browser] = signInWithPassword('ender@example.test', '10.18.11.1');
    [$other] = signInWithPassword('ender@example.test', '10.18.11.2');

    unset($browser->cookies[HINT]);
    $another = $browser->delete('/api/account/sessions/' . (int) sessionOf($other)['id']);
    same(200, $another->status, 'another session ended');
    check(hintSet($another) !== null && cookieLines($another, SESSION_COOKIE) === [], 'this one lives on: its hint given back, its cookie left alone');

    unset($browser->cookies[HINT]);
    $ownId = (int) sessionOf($browser)['id'];
    $own = $browser->delete('/api/account/sessions/' . $ownId);
    same(200, $own->status, 'its own session ended');
    check(hintCleared($own) && cookieExpired($own, SESSION_COOKIE), 'no new hint for a session that is over: the hint and the cookie expired, as at a sign-out');
    same(false, sessionLive(sessionById(iamDb(), $ownId)), 'the session is over');
    same(401, $browser->get('/api/account')->status, 'signed out');

    $adminId = makeUser('ender-admin', 'ender-admin@example.test');
    grantRole($adminId, 'iam', 'ADMIN');
    [$admin] = signInWithPassword('ender-admin@example.test', '10.18.11.3');
    unset($admin->cookies[HINT]);
    $ownAdminId = (int) sessionOf($admin)['id'];
    $ended = $admin->delete('/api/admin/sessions/' . $ownAdminId);
    same(200, $ended->status, 'the administrator ends their own session in the panel');
    check(hintCleared($ended) && cookieExpired($ended, SESSION_COOKIE), 'answered as a sign-out');
    same(false, sessionLive(sessionById(iamDb(), $ownAdminId)), 'the session is over');

    onRealHost(function () {
        [$hosted] = signInWithPassword('ender@example.test', '10.18.11.4');
        $ended = $hosted->delete('/api/account/sessions/' . (int) sessionOf($hosted)['id']);
        check(cookieExpired($ended, HOST_SESSION_COOKIE) && cookieExpired($ended, SESSION_COOKIE), 'on https: the session cookie under both its names');
    });
});

test(
    'asking for the deletion clears it; a sign-in while it is pending sets none and drops one left behind; nothing gives it back - a raced one goes at the next answer - until cancelling sets a new one',
    function () {
        makeUser('deleting', 'deleting@example.test');
        [$browser, $first] = signInWithPassword('deleting@example.test', '10.18.12.1');
        $before = hintSet($first)['value'] ?? '';

        $asked = $browser->post('/api/account/deletion', ['password' => PASSWORD]);
        same(200, $asked->status, 'the deletion asked for: ' . $asked->body);
        check(hintCleared($asked) && !isset($browser->cookies[HINT]), 'the hint goes: no site lets the account in now');
        check(hintUntouched($browser->get('/api/account')), 'the account page gives none back while the deletion is pending');

        // A hint that reached the browser anyway - another tab's answer, a moment
        // after the deletion was asked for - goes at the next answer that sees it.
        $browser->cookies[HINT] = $before;
        $raced = $browser->get('/api/account');
        same(200, $raced->status, 'the account page, with a hint that raced the deletion');
        check(hintCleared($raced) && !isset($browser->cookies[HINT]), 'cleared there');
        $browser->cookies[HINT] = $before;
        [$silent] = authorizeSurvey($browser, ['prompt' => 'none']);
        same('interaction_required', queryOf($silent->location())['error'] ?? null, 'a site\'s silent sign-in: somebody is signed in here, whom no site lets in');
        check(hintCleared($silent), 'and there too, interaction_required or not');

        $elsewhere = holdingOnly($before, '10.18.12.2');
        $signIn = $elsewhere->signIn('deleting@example.test', PASSWORD);
        same(200, $signIn->status, 'the IAM\'s own sign-in still works, to cancel');
        check(hintCleared($signIn) && !isset($elsewhere->cookies[HINT]), 'sets no hint, and the one left in that browser goes');
        check(hintUntouched((new Browser('10.18.12.3'))->signIn('deleting@example.test', PASSWORD)), 'from a browser without one: none at all');

        $cancelled = $browser->delete('/api/account/deletion');
        same(200, $cancelled->status, 'cancelled');
        same(1, count(cookieLines($cancelled, HINT)), 'one hint');
        $after = hintSet($cancelled)['value'] ?? '';
        check(preg_match(HINT_SHAPE, $after) === 1 && $after !== $before, 'a new one: the sites are the person\'s again, and try afresh');

        unset($browser->cookies[HINT]);
        $again = $browser->post('/api/account/deletion', ['password' => PASSWORD]);
        same(200, $again->status, 'asked again, from a browser without the hint');
        check(hintCleared($again), 'cleared - the account pages\' "give one back" does not overrule it');
        same(200, $browser->delete('/api/account/deletion')->status, 'and cancelled');
    },
);

test('a forced change of password, once done, gives a new hint - the sites that met interaction_required try again', function () {
    $id = makeUser('forced', 'forced@example.test');
    iamDb()
        ->prepare('UPDATE users SET force_password_change = TRUE WHERE id = ?')
        ->execute([$id]);
    [$browser, $answer] = signInWithPassword('forced@example.test', '10.18.13.1');
    $first = hintSet($answer)['value'] ?? '';

    [$silent] = authorizeSurvey($browser, ['prompt' => 'none']);
    same('interaction_required', queryOf($silent->location())['error'] ?? null, 'a new password to choose first');
    check(hintUntouched($silent), 'the hint kept');

    $changed = $browser->put('/api/account/password', ['current_password' => PASSWORD, 'new_password' => 'a brand new password']);
    same(200, $changed->status, 'the password changed: ' . $changed->body);
    $renewed = hintSet($changed)['value'] ?? '';
    check(preg_match(HINT_SHAPE, $renewed) === 1 && $renewed !== $first, 'a new hint');
    [$now] = authorizeSurvey($browser, ['prompt' => 'none']);
    check(isset(queryOf($now->location())['code']), 'and a silent sign-in brings a code now');
    check(hintUntouched($browser->put('/api/account/password', ['current_password' => 'a brand new password', 'new_password' => PASSWORD])), 'a change nobody forced keeps the hint it has');

    $firstId = makeUser('forced-first', 'forced-first@example.test');
    iamDb()
        ->prepare('UPDATE users SET password = NULL, force_password_change = TRUE WHERE id = ?')
        ->execute([$firstId]);
    $coded = new Browser('10.18.13.2');
    $in = signInByCode($coded, 'forced-first@example.test');
    same(200, $in->status, 'an account without a password signs in by an emailed code: ' . $in->body);
    $before = hintSet($in)['value'] ?? '';
    $set = $coded->post('/api/account/password/set', ['password' => 'a first long password']);
    same(200, $set->status, 'a first password: ' . $set->body);
    $after = hintSet($set)['value'] ?? '';
    check(preg_match(HINT_SHAPE, $after) === 1 && $after !== $before, 'a new hint too');
});

test('authorize clears a hint with no session behind it - prompt=none answered login_required, a sign-in sent to the page; interaction_required keeps it', function () {
    $id = makeUser('silent', 'silent@example.test');
    [$browser, $answer] = signInWithPassword('silent@example.test', '10.18.14.1');
    $hint = hintSet($answer)['value'] ?? '';

    revokeSession(iamDb(), (int) sessionOf($browser)['id'], SESSION_REVOKED_ELSEWHERE);
    [$none] = authorizeSurvey($browser, ['prompt' => 'none']);
    check(str_starts_with($none->location(), SURVEY_CALLBACK . '?'), 'prompt=none goes back to the site');
    same('login_required', queryOf($none->location())['error'] ?? null, 'nobody signed in here: the session ended elsewhere');
    check(hintCleared($none) && !isset($browser->cookies[HINT]), 'and the stale hint goes, for every site at once');

    [$page] = authorizeSurvey(holdingOnly($hint, '10.18.14.2'));
    check(str_contains($page->location(), '/login?request=') && hintCleared($page), 'a sign-in sent to the page clears it too');
    [$create] = authorizeSurvey(holdingOnly($hint, '10.18.14.3'), ['prompt' => 'create']);
    check(str_contains($create->location(), '/register?request=') && hintCleared($create), 'and one sent to the registration form');

    [$off] = signInWithPassword('silent@example.test', '10.18.14.4');
    iamDb()
        ->prepare('UPDATE users SET disabled = TRUE WHERE id = ?')
        ->execute([$id]);
    [$switchedOff] = authorizeSurvey($off, ['prompt' => 'none']);
    same('login_required', queryOf($switchedOff->location())['error'] ?? null, 'an account switched off is nobody signed in');
    check(hintCleared($switchedOff), 'its hint goes');
    iamDb()
        ->prepare('UPDATE users SET disabled = FALSE WHERE id = ?')
        ->execute([$id]);

    [$kept] = signInWithPassword('silent@example.test', '10.18.14.5');
    $keptHint = $kept->cookies[HINT] ?? '';

    takeSurveyMember($id);
    [$blocked] = authorizeSurvey($kept, ['prompt' => 'none']);
    same('interaction_required', queryOf($blocked->location())['error'] ?? null, 'MEMBER taken at the survey site');
    check(hintUntouched($blocked), 'kept: the session is good for every other site');
    grantRole($id, 'survey', 'MEMBER');

    iamDb()
        ->prepare('UPDATE users SET force_password_change = TRUE WHERE id = ?')
        ->execute([$id]);
    [$forced] = authorizeSurvey($kept, ['prompt' => 'none']);
    same('interaction_required', queryOf($forced->location())['error'] ?? null, 'a new password to choose first');
    check(hintUntouched($forced), 'kept');
    iamDb()
        ->prepare('UPDATE users SET force_password_change = FALSE WHERE id = ?')
        ->execute([$id]);

    iamDb()
        ->prepare("UPDATE sessions SET auth_time = CURRENT_TIMESTAMP - INTERVAL '1 hour' WHERE id = ?")
        ->execute([(int) sessionOf($kept)['id']]);
    [$old] = authorizeSurvey($kept, ['prompt' => 'none', 'max_age' => '60']);
    same('interaction_required', queryOf($old->location())['error'] ?? null, 'a sign-in older than max_age');
    check(hintUntouched($old), 'kept');

    [$login] = authorizeSurvey($kept, ['prompt' => 'login']);
    check(str_contains($login->location(), '/login?request=') && hintUntouched($login), 'prompt=login with a live session: the page, and the hint kept');
    same($keptHint, $kept->cookies[HINT] ?? null, 'the same hint all along');
});

test('what a request does to its own session counts: a handler that ends it leaves no hint behind, and a sign-in raced by a switch-off gets none', function () {
    $id = makeUser('racer', 'racer@example.test');
    [$browser] = signInWithPassword('racer@example.test', '10.18.22.1');
    unset($browser->cookies[HINT]);
    $request = $browser->serverRequest('GET', '/api/account');
    $handler = static fn(bool $ends): \Psr\Http\Server\RequestHandlerInterface => new class ($ends) implements \Psr\Http\Server\RequestHandlerInterface {
        public function __construct(private bool $ends) {}

        public function handle(\Psr\Http\Message\ServerRequestInterface $request): \Psr\Http\Message\ResponseInterface
        {
            if ($this->ends) {
                revokeSession(iamDb(), (int) $request->getAttribute('session')['id'], SESSION_REVOKED_ELSEWHERE);
            }

            return respondJson(new \Slim\Psr7\Response(), ['done' => true]);
        }
    };

    $kept = requireSession()($request, $handler(false));
    check(responseSetsCookie($kept, HINT), 'a handler that leaves the session be: the browser that had no hint gets one');
    $ended = requireSession()($request, $handler(true));
    same([200, false], [$ended->getStatusCode(), responseSetsCookie($ended, HINT)], 'one that ends it: no hint for a session that is over');

    // An administrator switches the account off between the password checked and the session opened.
    $user = userById(iamDb(), $id);
    iamDb()
        ->prepare('UPDATE users SET disabled = TRUE WHERE id = ?')
        ->execute([$id]);
    try {
        $raced = completeSignIn(
            (new \Slim\Psr7\Factory\ServerRequestFactory())->createServerRequest('POST', TEST_ISSUER . '/api/login/password', ['REMOTE_ADDR' => '10.18.22.2']),
            new \Slim\Psr7\Response(),
            $user,
            SESSION_METHOD_PASSWORD,
            false,
            [],
        );
        check(!responseSetsCookie($raced, HINT), 'the account read afresh: switched off, no hint');
    } finally {
        iamDb()
            ->prepare('UPDATE users SET disabled = FALSE WHERE id = ?')
            ->execute([$id]);
    }
});

test('every answer with no session behind the hint clears it: a stale session\'s 401, the sign-in page\'s accept, an account gone mid-request', function () {
    makeUser('stale', 'stale@example.test');
    [$browser] = signInWithPassword('stale@example.test', '10.18.15.1');

    revokeSession(iamDb(), (int) sessionOf($browser)['id'], SESSION_REVOKED_ELSEWHERE);
    $refused = $browser->get('/api/account');
    same([401, 'no_session'], [$refused->status, $refused->code()], 'a session ended elsewhere');
    check(hintCleared($refused) && !isset($browser->cookies[HINT], $browser->cookies[SESSION_COOKIE]), 'its cookie and its hint both go');

    $hintOnly = holdingOnly(randomSecret(16), '10.18.15.2')->get('/api/account');
    check($hintOnly->status === 401 && hintCleared($hintOnly), 'a hint and no session cookie at all: it goes too');
    check(hintUntouched((new Browser('10.18.15.3'))->get('/api/account')), 'and a browser with neither is set nothing');

    [$start] = authorizeSurvey(new Browser('10.18.15.4'));
    $accept = holdingOnly(randomSecret(16), '10.18.15.5')->post('/api/login/request/' . (queryOf($start->location())['request'] ?? '') . '/accept');
    same([401, 'no_session'], [$accept->status, $accept->code()], 'the sign-in page\'s accept with no session');
    check(hintCleared($accept), 'clears it');

    buildIamApp();
    $gone = accountGone(new \Slim\Psr7\Response());
    $answer = new TestResponse($gone->getStatusCode(), $gone->getHeaders(), (string) $gone->getBody());
    check($answer->status === 401 && hintCleared($answer) && cookieExpired($answer, SESSION_COOKIE), 'an account deleted while its request was on the way: the session cookie and the hint go');
});

// ─────────────────────────────────────────────────────────────────────────────
// The hint: the attacks
// ─────────────────────────────────────────────────────────────────────────────

test('a forged or stale hint opens nothing: no endpoint takes it for a session, authorize issues no code by it, and prompt=none with it alone is login_required', function () {
    $victimId = makeUser('victim', 'victim@example.test');
    grantRole($victimId, 'iam', 'ADMIN');
    [$victim, $answer] = signInWithPassword('victim@example.test', '10.18.16.1');
    $real = hintSet($answer)['value'] ?? '';

    foreach (['the victim\'s own hint, copied' => $real, 'a forged one' => randomSecret(16)] as $what => $hint) {
        foreach (['/api/account', '/api/account/sessions', '/api/admin/stats', '/api/admin/users'] as $path) {
            $read = holdingOnly($hint, '10.18.16.2')->get($path);
            same([401, 'no_session'], [$read->status, $read->code()], "$what, at $path");
        }
        $write = holdingOnly($hint, '10.18.16.3')->post('/api/account/deletion', ['password' => PASSWORD]);
        same([401, 'no_session'], [$write->status, $write->code()], "$what: no write either");

        [$start] = authorizeSurvey(new Browser('10.18.16.4'));
        $requestId = queryOf($start->location())['request'] ?? '';
        $accept = holdingOnly($hint, '10.18.16.5')->post("/api/login/request/{$requestId}/accept");
        same([401, 'no_session'], [$accept->status, $accept->code()], "$what: no code through the sign-in page");

        [$page] = authorizeSurvey(holdingOnly($hint, '10.18.16.6'));
        check(str_contains($page->location(), '/login?request=') && !isset(queryOf($page->location())['code']), "$what: authorize asks for a sign-in, and gives no code");
        [$silent] = authorizeSurvey(holdingOnly($hint, '10.18.16.7'), ['prompt' => 'none']);
        same(['login_required', null], [queryOf($silent->location())['error'] ?? null, queryOf($silent->location())['code'] ?? null], "$what: prompt=none with the hint alone");

        same(401, holdingOnly($hint, '10.18.16.8')->get('/api/oidc/userinfo')->status, "$what: userinfo knows no holder");
        same(
            401,
            holdingOnly($hint, '10.18.16.8')->request('POST', '/api/oidc/preferences', ['json' => ['theme' => 'dark'], 'headers' => ['Origin' => SURVEY_FRONTEND]])->status,
            "$what: nor does a site's save of the preferences",
        );

        $out = holdingOnly($hint, '10.18.16.9')->get('/api/oidc/logout?client_id=survey&post_logout_redirect_uri=' . urlencode(SURVEY_FRONTEND . '/'));
        same(SURVEY_FRONTEND . '/', $out->location(), "$what: a sign-out by it ends nobody's session");

        $asSession = new Browser('10.18.16.10');
        $asSession->cookies[SESSION_COOKIE] = $hint;
        same(401, $asSession->get('/api/account')->status, "$what, sent as the session cookie");
    }

    same(200, $victim->get('/api/account')->status, 'the victim is still signed in');
    check(isset(queryOf(authorizeSurvey($victim)[0]->location())['code']), 'and single sign-on still works for them');
});

test('the hint says nothing of the person: random, new for every session, never the session\'s secret, its hash or sid, the account\'s id, name or address - and kept nowhere', function () {
    $id = makeUser('private', 'private@example.test');
    [$one, $first] = signInWithPassword('private@example.test', '10.18.17.1');
    [$two, $second] = signInWithPassword('private@example.test', '10.18.17.2');
    $third = $one->signIn('private@example.test', PASSWORD);
    same(200, $third->status, 'the first browser again');

    $values = array_map(static fn(TestResponse $answer): string => hintSet($answer)['value'] ?? '', [$first, $second, $third]);
    same(3, count(array_unique($values)), 'a new value for every session - one account, the same browser, the same second');
    foreach ([$first, $second, $third] as $answer) {
        same(['value', 'path', 'samesite', 'max-age'], array_keys(hintSet($answer) ?? []), 'nothing but the value and its attributes');
    }

    foreach ([[$one, $values[2]], [$two, $values[1]]] as [$browser, $hint]) {
        $session = sessionOf($browser);
        $secret = $browser->cookies[SESSION_COOKIE] ?? '';
        check($secret !== '' && $session !== [], 'a session to compare with');
        foreach ([$secret, sha256($secret), (string) $session['sid'], 'private@example.test', 'private'] as $personal) {
            check(!str_contains($hint, $personal) && !str_contains($personal, $hint), 'nothing of the session or the person in it');
        }
        check($hint !== (string) $id && $hint !== base64UrlEncode((string) $id), 'nor the account\'s id');
    }

    $kept = iamDb()->prepare('SELECT count(*) FROM sessions WHERE token_hash IN (?, ?) OR sid = ?');
    foreach ($values as $hint) {
        $kept->execute([$hint, sha256($hint), $hint]);
        same(0, (int) $kept->fetchColumn(), 'kept nowhere a session is');
    }
});

test(
    'the hint is no CSRF or CORS handle: the exempt paths stay the four, a write with the session and the hint but not the header is refused, and no first-party endpoint answers a sibling\'s preflight',
    function () {
        $four = ['/api/oidc/token', '/api/oidc/userinfo', '/api/oidc/revoke', '/api/oidc/preferences'];
        same($four, CSRF_EXEMPT_PATHS, 'no new CSRF exemption');
        same($four, CORS_CLIENT_PATHS, 'no new cross-origin path');

        makeUser('guarded-writes', 'guarded-writes@example.test');
        [$browser] = signInWithPassword('guarded-writes@example.test', '10.18.18.1');
        check(isset($browser->cookies[HINT], $browser->cookies[SESSION_COOKIE]), 'signed in, with the hint');

        $forged = $browser->post('/api/logout', [], ['csrf' => false, 'headers' => ['Origin' => SURVEY_FRONTEND]]);
        same([403, 'csrf'], [$forged->status, $forged->code()], 'a sibling site\'s page cannot sign the person out');
        check(hintUntouched($forged), 'nor touch the hint');
        same(200, $browser->get('/api/account')->status, 'still signed in');

        $preflight = $browser->request('OPTIONS', '/api/account', ['headers' => ['Origin' => SURVEY_FRONTEND]]);
        same('', $preflight->header('Access-Control-Allow-Origin'), 'no first-party endpoint answers a sibling\'s preflight');
    },
);

// ─────────────────────────────────────────────────────────────────────────────
// The session cookie's __Host- name
// ─────────────────────────────────────────────────────────────────────────────

test(
    'on https the bare name opens nothing, even carrying a live session\'s secret - the account and admin pages, a write, authorize (prompt=none is login_required and clears a sent hint), the sign-in page\'s accept, both sign-outs - and is expired in the answer; the sites keep their tokens',
    function () {
        $id = makeUser('before', 'before@example.test');
        grantRole($id, 'iam', 'ADMIN');

        onRealHost(function () use ($id) {
            // A browser from before the __Host- name, signed in at the IAM and, through it, at a site.
            [$browser] = signInWithPassword('before@example.test', '10.18.19.1');
            [$sso, $verifier] = authorizeSurvey($browser);
            $tokens = surveyTokens(queryOf($sso->location())['code'] ?? '', $verifier);
            $secret = underOldName($browser);
            $sessionId = (int) (sessionOf($browser)['id'] ?? 0);
            check($sessionId > 0 && sessionLive(sessionById(iamDb(), $sessionId)), 'its session live, its secret under the bare name');
            $bare = static fn(string $ip): Browser => holdingBare($secret, $ip, randomSecret(16));

            foreach (['/api/account', '/api/account/sessions', '/api/admin/stats', '/api/admin/users'] as $i => $path) {
                $answer = $bare('10.18.19.' . (10 + $i))->get($path);
                same([401, 'no_session'], [$answer->status, $answer->code()], "$path: nobody is signed in");
                check(cookieExpired($answer, SESSION_COOKIE) && cookieLines($answer, HOST_SESSION_COOKIE) === [], "$path: the bare cookie expired, nothing set under the __Host- name");
                check(hintCleared($answer), "$path: the hint sent with it cleared");
            }
            $expired = cookieParts(cookieLines($bare('10.18.19.20')->get('/api/account'), SESSION_COOKIE)[0] ?? '');
            same(
                ['', '0', '/', true, true, 'Lax'],
                [$expired['value'] ?? null, $expired['max-age'] ?? null, $expired['path'] ?? null, $expired['secure'] ?? null, $expired['httponly'] ?? null, $expired['samesite'] ?? null],
                'expired with the attributes the IAM set it with before the prefix',
            );
            check(!isset($expired['domain']), 'this host only, as it was set');

            $write = $bare('10.18.19.21')->post('/api/account/deletion', ['password' => PASSWORD]);
            same([401, 'no_session'], [$write->status, $write->code()], 'no write either');
            same(null, userById(iamDb(), $id)['deletion_due_at'] ?? null, 'nothing asked for');

            [$silent] = authorizeSurvey($bare('10.18.19.22'), ['prompt' => 'none']);
            same(['login_required', false], [queryOf($silent->location())['error'] ?? null, isset(queryOf($silent->location())['code'])], 'a site\'s silent hop: nobody signed in here, no code');
            check(hintCleared($silent) && cookieExpired($silent, SESSION_COOKIE), 'the hint cleared, the bare cookie expired');

            $asked = $bare('10.18.19.23');
            [$page] = authorizeSurvey($asked);
            check(str_contains($page->location(), '/login?request=') && !isset(queryOf($page->location())['code']), 'an ordinary sign-in: the page, no code');
            check(cookieExpired($page, SESSION_COOKIE), 'the bare cookie expired there too');
            $asked->cookies[SESSION_COOKIE] = $secret;
            $accept = $asked->post('/api/login/request/' . (queryOf($page->location())['request'] ?? '') . '/accept');
            same([401, 'no_session'], [$accept->status, $accept->code()], 'the sign-in page\'s accept: no code by it');

            $out = $bare('10.18.19.24')->post('/api/logout');
            same(200, $out->status, 'a sign-out answers as ever');
            check(cookieExpired($out, SESSION_COOKIE) && cookieExpired($out, HOST_SESSION_COOKIE), 'both names expired');
            $siteOut = $bare('10.18.19.25')->get(
                '/api/oidc/logout?' .
                    http_build_query([
                        'client_id' => 'survey',
                        'id_token_hint' => $tokens['id_token'] ?? '',
                        'post_logout_redirect_uri' => SURVEY_FRONTEND . '/',
                        'state' => 'bye',
                    ]),
            );
            same(SURVEY_FRONTEND . '/?state=bye', $siteOut->location(), 'a site\'s sign-out with its ID token: nobody signed in here, straight back');
            check(cookieExpired($siteOut, SESSION_COOKIE) && hintCleared($siteOut), 'the bare cookie expired there too, and the hint cleared');
            check(sessionLive(sessionById(iamDb(), $sessionId)), 'neither sign-out ended the session: a cookie never read names none');

            // The sites hold their own tokens, and refresh without the IAM's cookie.
            $refreshed = (new Browser('10.18.99.1'))->request('POST', '/api/oidc/token', [
                'form' => ['grant_type' => 'refresh_token', 'refresh_token' => $tokens['refresh_token'] ?? '', 'client_id' => 'survey'],
                'headers' => ['Origin' => SURVEY_FRONTEND],
            ]);
            same(200, $refreshed->status, 'the site\'s refresh still works: ' . ($refreshed->json()['error'] ?? ''));

            $again = $bare('10.18.19.26');
            $signIn = $again->signIn('before@example.test', PASSWORD);
            same(200, $signIn->status, 'signing in once more, from that browser');
            $fresh = $again->cookies[HOST_SESSION_COOKIE] ?? '';
            check($fresh !== '' && $fresh !== $secret && cookieExpired($signIn, SESSION_COOKIE), 'a new session under the __Host- name, the bare cookie expired');
            same(200, $again->get('/api/account')->status, 'signed in by it');
            check(sessionLive(sessionById(iamDb(), $sessionId)), 'the session the bare cookie named is not replaced: the sites keep their tokens until it ends');
        });
    },
);

test(
    'the attack the bare name left open: a session planted under it beside a planted hint, in a browser with no session at the IAM, signs nobody in - a site\'s silent hop is login_required, an ordinary one gets the sign-in page, and no code is issued under the planter\'s session',
    function () {
        makeUser('hopper', 'hopper@example.test');

        onRealHost(function () {
            [$planter] = signInWithPassword('hopper@example.test', '10.18.26.1');
            $planted = $planter->cookies[HOST_SESSION_COOKIE] ?? '';
            $planterSession = (int) (sessionOf($planter)['id'] ?? 0);

            // What a sibling host can set for the whole domain in somebody else's
            // browser: its own session under the bare name, and a hint - which
            // makes a site that lets visitors in hop to the IAM without a click.
            $victim = holdingBare($planted, '10.18.26.2', randomSecret(16));
            [$silent] = authorizeSurvey($victim, ['prompt' => 'none']);
            same(['login_required', false], [queryOf($silent->location())['error'] ?? null, isset(queryOf($silent->location())['code'])], 'the silent hop: nobody is signed in here, and no code');
            check(hintCleared($silent), 'the planted hint cleared, so the site does not hop on it again');

            // Planted with a Domain, both are beyond a host-only expiry: the browser sends them again.
            $victim->cookies[SESSION_COOKIE] = $planted;
            $victim->cookies[HINT] = randomSecret(16);
            [$page] = authorizeSurvey($victim);
            check(str_contains($page->location(), '/login?request=') && !isset(queryOf($page->location())['code']), 'an ordinary hop: the sign-in page, no code');
            $victim->cookies[SESSION_COOKIE] = $planted;
            $accept = $victim->post('/api/login/request/' . (queryOf($page->location())['request'] ?? '') . '/accept');
            same([401, 'no_session'], [$accept->status, $accept->code()], 'the sign-in page cannot accept as the planter');
            $victim->cookies[SESSION_COOKIE] = $planted;
            same(401, $victim->get('/api/account')->status, 'nor is the account page the planter\'s');

            $codes = iamDb()->prepare('SELECT count(*) FROM auth_codes WHERE session_id = ?');
            $codes->execute([$planterSession]);
            same(0, (int) $codes->fetchColumn(), 'no code issued under the planter\'s session');
            same(200, $planter->get('/api/account')->status, 'the planter\'s session untouched, in the planter\'s own browser');
        });
    },
);

test(
    'a __Host- session beside a cookie under the bare name is still read by its exact name: the account, a site\'s code, a sign-in and a sign-out are the owner\'s, and the bare one is expired in the answer',
    function () {
        $ownerId = makeUser('owner', 'owner@example.test');
        makeUser('planter', 'planter@example.test');

        onRealHost(function () use ($ownerId) {
            [$planter] = signInWithPassword('planter@example.test', '10.18.20.1');
            $planted = $planter->cookies[HOST_SESSION_COOKIE] ?? '';
            [$owner] = signInWithPassword('owner@example.test', '10.18.20.2');

            // What a sibling host can do: a cookie under the bare name, for the
            // whole domain, which the browser sends first.
            $owner->cookies[SESSION_COOKIE] = $planted;
            $page = $owner->get('/api/account');
            same([200, 'owner'], [$page->status, $page->json()['username'] ?? null], 'the account is the owner\'s');
            same([], cookieLines($page, HOST_SESSION_COOKIE), 'the owner\'s own cookie is left as it is');
            check(cookieExpired($page, SESSION_COOKIE), 'the bare one expired in the answer');

            $owner->cookies[SESSION_COOKIE] = $planted;
            [$sso, $verifier] = authorizeSurvey($owner);
            check(cookieExpired($sso, SESSION_COOKIE), 'there too');
            $tokens = surveyTokens(queryOf($sso->location())['code'] ?? '', $verifier);
            same((string) $ownerId, jwtVerifyOwn($tokens['id_token'], 'JWT')['sub'] ?? null, 'a site gets the owner\'s code');

            $owner->cookies[SESSION_COOKIE] = $planted;
            $signIn = $owner->signIn('owner@example.test', PASSWORD);
            same(200, $signIn->status, 'the owner signs in again');
            check(cookieExpired($signIn, SESSION_COOKIE), 'the bare one expired by the sign-in too');

            $owner->cookies[SESSION_COOKIE] = $planted;
            same(200, $owner->post('/api/logout')->status, 'the owner signs out');
            same(401, $owner->get('/api/account')->status, 'the owner\'s session is over');
            same(200, $planter->get('/api/account')->status, 'the planter\'s session was not touched');
        });
    },
);

test(
    'plain-http development is unchanged: the session cookie is anotoki_iam - its one name, read as ever - and not Secure; nothing more set or expired, and a __Host- name is no session there',
    function () {
        makeUser('developer', 'developer@example.test');
        [$browser, $answer] = signInWithPassword('developer@example.test', '10.18.21.1');

        $cookie = cookieParts(cookieLines($answer, SESSION_COOKIE)[0] ?? '');
        check(isset($cookie['httponly']) && !isset($cookie['secure'], $cookie['domain']) && ($cookie['path'] ?? null) === '/', 'anotoki_iam: HttpOnly, Path=/, not Secure over plain http');
        same([], cookieLines($answer, HOST_SESSION_COOKIE), 'no __Host- name: it would have to be Secure');
        $page = $browser->get('/api/account');
        same([200, []], [$page->status, cookieNames($page)], 'the bare name is the session here: nothing set or expired');
        $secret = $browser->cookies[SESSION_COOKIE] ?? '';
        same(401, (new Browser('10.18.21.2'))->get('/api/account', ['headers' => ['Cookie' => "__Host-anotoki_iam={$secret}"]])->status, 'the __Host- name is not read over plain http');
        same([SESSION_COOKIE, HINT], cookieNames($browser->post('/api/logout')), 'a sign-out expires the one name');
    },
);

test('the session cookie is read by its exact name, as sent: a look-alike that only decodes to it, sent first, never stands in - the account and the sign-out stay the owner\'s', function () {
    makeUser('exact', 'exact@example.test');
    makeUser('lookalike', 'lookalike@example.test');

    onRealHost(function () {
        [$planter] = signInWithPassword('lookalike@example.test', '10.18.23.1');
        $planted = $planter->cookies[HOST_SESSION_COOKIE] ?? '';
        [$owner] = signInWithPassword('exact@example.test', '10.18.23.2');
        $real = $owner->cookies[HOST_SESSION_COOKIE] ?? '';

        same(
            $planted,
            \Slim\Psr7\Cookies::parseHeader(["__%48ost-anotoki_iam={$planted}; __Host-anotoki_iam={$real}"])[HOST_SESSION_COOKIE] ?? null,
            'the cookie params - Slim\'s parser, as in production - hold the look-alike under the real name: why they are not read',
        );

        // A sibling host's cookie whose name decodes to the session cookie's,
        // for the whole domain with a longer path: the browser sends it first.
        foreach (['__%48ost-anotoki_iam', '%5F%5FHost-anotoki_iam', 'anotoki%5Fiam'] as $lookalike) {
            $owner->cookies = [$lookalike => $planted] + $owner->cookies;
            $page = $owner->get('/api/account');
            same([200, 'exact'], [$page->status, $page->json()['username'] ?? null], "$lookalike sent first: the account is the owner's");
            same([], cookieLines($page, HOST_SESSION_COOKIE), 'nothing set in its place');
            unset($owner->cookies[$lookalike]);
        }

        // A browser from before the prefix, a look-alike planted beside it: neither is the __Host- name as sent.
        [$before] = signInWithPassword('exact@example.test', '10.18.23.3');
        underOldName($before);
        $before->cookies = ['__%48ost-anotoki_iam' => $planted] + $before->cookies;
        $page = $before->get('/api/account');
        same([401, 'no_session'], [$page->status, $page->code()], 'the bare name and a look-alike: no session - neither is read');
        check(cookieLines($page, HOST_SESSION_COOKIE) === [] && cookieExpired($page, SESSION_COOKIE), 'nothing set under the __Host- name, the bare cookie expired');

        $owner->cookies = ['__%48ost-anotoki_iam' => $planted] + $owner->cookies;
        same(200, $owner->post('/api/logout')->status, 'the owner signs out, the look-alike still sent first');
        $state = iamDb()->prepare('SELECT revoked_reason FROM sessions WHERE token_hash = ?');
        $state->execute([sha256($real)]);
        same(SESSION_REVOKED_SIGNOUT, $state->fetchColumn(), 'the owner\'s own session ended');
        $state->execute([sha256($planted)]);
        same(null, $state->fetchColumn(), 'the planter\'s untouched');
        same(200, $planter->get('/api/account')->status, 'and still live');
    });
});

test('the session cookie\'s name sent twice is no session - the __Host- one on https, the bare one on plain http - and on https the bare one is none even once', function () {
    makeUser('twice', 'twice@example.test');

    onRealHost(function () {
        [$browser] = signInWithPassword('twice@example.test', '10.18.24.1');
        $real = $browser->cookies[HOST_SESSION_COOKIE] ?? '';
        [$other] = signInWithPassword('twice@example.test', '10.18.24.2');
        $second = $other->cookies[HOST_SESSION_COOKIE] ?? '';

        same(200, (new Browser('10.18.24.3'))->get('/api/account', ['headers' => ['Cookie' => "__Host-anotoki_iam={$real}"]])->status, 'once: the session');
        $twice = [
            'the __Host- name twice, the same' => "__Host-anotoki_iam={$real}; __Host-anotoki_iam={$real}",
            'the __Host- name twice, two sessions' => "__Host-anotoki_iam={$second}; __Host-anotoki_iam={$real}",
            'the bare name once' => "anotoki_iam={$real}",
            'the bare name twice, the same' => "anotoki_iam={$real}; anotoki_iam={$real}",
            'the bare name twice, two sessions' => "anotoki_iam={$second}; anotoki_iam={$real}",
        ];
        foreach ($twice as $what => $header) {
            $answer = (new Browser('10.18.24.3'))->get('/api/account', ['headers' => ['Cookie' => $header]]);
            same([401, 'no_session'], [$answer->status, $answer->code()], "$what: no session");
            same([], cookieLines($answer, HOST_SESSION_COOKIE), "$what: nothing set under the __Host- name");
        }
    });

    [$developer] = signInWithPassword('twice@example.test', '10.18.24.4');
    $secret = $developer->cookies[SESSION_COOKIE] ?? '';
    same(200, (new Browser('10.18.24.5'))->get('/api/account', ['headers' => ['Cookie' => "anotoki_iam={$secret}"]])->status, 'plain http, once: the session');
    $answer = (new Browser('10.18.24.5'))->get('/api/account', ['headers' => ['Cookie' => "anotoki_iam={$secret}; anotoki_iam={$secret}"]]);
    same([401, 'no_session'], [$answer->status, $answer->code()], 'plain http, twice: no session');
});

test(
    'the four endpoints the sites call cross-origin set no cookie: sent a bare-name session cookie - its session live, then revoked - and the hint, token, userinfo, revoke and preferences expire and clear nothing',
    function () {
        makeUser('crossing', 'crossing@example.test');

        onRealHost(function () {
            [$browser] = signInWithPassword('crossing@example.test', '10.18.25.1');
            underOldName($browser);
            check(isset($browser->cookies[SESSION_COOKIE], $browser->cookies[HINT]), 'a browser holding the bare name from before the prefix, and its hint');

            foreach (['live', 'revoked'] as $state) {
                if ($state === 'revoked') {
                    revokeSession(iamDb(), (int) (sessionOf($browser)['id'] ?? 0), SESSION_REVOKED_ELSEWHERE);
                }
                $answers = [
                    'token' => $browser->request('POST', '/api/oidc/token', [
                        'form' => ['grant_type' => 'refresh_token', 'refresh_token' => 'iam_rt_nothing', 'client_id' => 'survey'],
                        'headers' => ['Origin' => SURVEY_FRONTEND],
                    ]),
                    'userinfo' => $browser->get('/api/oidc/userinfo', ['headers' => ['Origin' => SURVEY_FRONTEND]]),
                    'revoke' => $browser->request('POST', '/api/oidc/revoke', ['form' => ['token' => 'iam_rt_nothing', 'client_id' => 'survey'], 'headers' => ['Origin' => SURVEY_FRONTEND]]),
                    'preferences' => $browser->request('POST', '/api/oidc/preferences', ['json' => ['theme' => 'dark'], 'headers' => ['Origin' => SURVEY_FRONTEND]]),
                ];
                foreach ($answers as $endpoint => $answer) {
                    same([], cookieNames($answer), "$endpoint ({$answer->status}), its session $state: no Set-Cookie");
                }
                same(
                    [400, 401, 200, 401],
                    array_values(array_map(static fn(TestResponse $answer): int => $answer->status, $answers)),
                    "each answering as it would without the cookies, its session $state",
                );
                check(isset($browser->cookies[SESSION_COOKIE], $browser->cookies[HINT]) && !isset($browser->cookies[HOST_SESSION_COOKIE]), "the cookies still as they were, its session $state");
            }
        });
    },
);

finishTests();
