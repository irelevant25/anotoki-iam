<?php

use Anotoki\Lib\Migrations\Http\MigrationsRoutes;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

// ---------------------------------------------------------------------------
// Signing in, signing up, and proving the address is yours
//
// The rules the genshin site's own sign-in grew are all here, unchanged in
// substance: an account works once its address is confirmed; the endpoints
// that mail an address answer the same whether or not it has an account;
// failures are throttled per address-and-source and per source; two-factor,
// once on, applies to every way in; and every way in checks the same switch.
//
// What changed is what a sign-in produces. It used to be a token for one site.
// Now it is a session at the IAM - the cookie - and the site the person came
// from gets its code through /api/login/request/{id}/accept.
// ---------------------------------------------------------------------------

/**
 * The second factor, for the endpoints that start a session. Null when the
 * sign-in may go ahead, a refusal when it may not. `totp_required` and
 * `totp_invalid` are told apart because the page does different things.
 *
 * Every refusal is recorded as a failed attempt, whichever way in asked, so
 * the limiter counts guesses at the digits on every path - not only after a
 * password. A code is accepted once (totpCodeAccepted()).
 */
function totpRefusal(Request $request, Response $response, PDO $pdo, array $user, ?string $code, mixed $deviceToken, string $method): ?Response
{
    if (!asBool($user['totp_enabled'])) {
        return null;
    }

    // A browser that answered a code in the last thirty days skips the digits
    // and nothing else - the password was already right to get here.
    if (trustedDeviceAccepted($pdo, (int) $user['id'], $deviceToken)) {
        return null;
    }

    if (trim((string) $code) === '') {
        recordLoginAttempt($pdo, $request, (string) $user['email'], (int) $user['id'], $method, 'totp_required');
        return respondError($response, 403, 'totp_required', 'A code from your authenticator app is needed');
    }

    if (totpChallengePassed($pdo, $user, $code)) {
        return null;
    }

    recordLoginAttempt($pdo, $request, (string) $user['email'], (int) $user['id'], $method, 'bad_totp');
    return respondError($response, 403, 'totp_invalid', 'That code is not right');
}

function signInRefusalResponse(Response $response, ?array $user): ?Response
{
    $refusal = signInRefusal($user);

    return $refusal === null ? null : respondError($response, $refusal['status'], $refusal['code'], $refusal['error']);
}

/**
 * Opens the session and answers the page.
 *
 * Any session this browser already had is ended first ('replaced'): signing in
 * again - as the same person or somebody else - should not leave the previous
 * sign-in running behind the new one.
 *
 * Every way in ends here - password, emailed code, Google, a confirmation or
 * reset link, each past its second factor - so this is also where the browser
 * is noted, and the account mailed when it has not signed in from it before
 * (noteSignInDevice(), api/sign_in_devices.php). Never on a site's single
 * sign-on or refresh, which open no session.
 *
 * And where the sign-in hint every anotoki site reads is set, a new value for
 * the new session (api/session.php) - but not for an account no site lets in
 * (signInHintAllowed(), read afresh: its deletion pending - it signs in only
 * to cancel it - or switched off or deleted while this sign-in was on its
 * way). A hint this browser still has then belongs to the session just
 * replaced, and goes with it.
 */
function completeSignIn(Request $request, Response $response, array $user, string $method, array $body, int $status = 200, array $extra = []): Response
{
    $pdo = iamDb();

    if ($previous = sessionFromRequest($pdo, $request)) {
        revokeSession($pdo, (int) $previous['id'], SESSION_REVOKED_REPLACED);
    }

    $remember = !array_key_exists('remember', $body) || !empty($body['remember']);
    $opened = openSession($pdo, $request, $user, $method, asBool($user['totp_enabled']), $remember);
    recordLoginAttempt($pdo, $request, (string) $user['email'], (int) $user['id'], $method, 'ok');

    // Only worth issuing where there is a code to skip.
    $deviceToken = !empty($body['remember_device']) && asBool($user['totp_enabled'])
        ? issueTrustedDevice($pdo, (int) $user['id'], $request)
        : null;

    $fresh = userById($pdo, (int) $user['id']);

    $answer = withSessionCookie(
        respondJson($response, ['user' => accountPayload($pdo, $fresh), 'device_token' => $deviceToken] + $extra, $status),
        $opened['secret'],
        $opened['session']
    );

    if (signInHintAllowed($fresh)) {
        $answer = withSignInHint($answer, $opened['session']);
    } elseif (signInHintSent($request)) {
        $answer = withoutSignInHint($answer);
    }

    // A cookie this browser still had under the old bare name goes. It was
    // never read, so the session it named is not replaced.
    return noteSignInDevice($pdo, $request, withoutOldSessionCookie($request, $answer), $fresh);
}

/**
 * A hash of nothing, made the way real passwords are hashed on this PHP.
 *
 * Checked in place of a password for an address with no account. It has to
 * cost what a real one costs: PHP 8.4 raised bcrypt's default from 10 to 12,
 * and rehashing moves the real hashes up with it, so a fixed cost-10 dummy
 * answered unknown addresses measurably faster. Kept in storage/cache and made
 * again whenever the default moves on; made on the spot if it cannot be kept.
 */
function dummyPasswordHash(): string
{
    $file = iamStorageDir() . '/cache/dummy-password.hash';
    $hash = is_file($file) ? trim((string) @file_get_contents($file)) : '';

    if ($hash === '' || password_needs_rehash($hash, PASSWORD_DEFAULT)) {
        $hash = password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT);
        $directory = dirname($file);
        if (is_dir($directory) || @mkdir($directory, 0775, true)) {
            @file_put_contents($file, $hash);
        }
    }

    return $hash;
}

function tooManyAttempts(Response $response): Response
{
    return respondError($response, 429, 'too_many_attempts', 'Too many attempts. Wait a few minutes and try again.');
}

/**
 * Where a confirmation or a reset lands afterwards: back to the application the
 * person started from, when there was one.
 */
function continueTo(array $context): ?array
{
    $application = !empty($context['client_id']) ? applicationByClientId((string) $context['client_id']) : null;

    if ($application === null || !asBool($application['enabled']) || empty($application['home_url'])) {
        return null;
    }

    return ['name' => $application['name'], 'url' => $application['home_url']];
}

/** The application a still-live request names, for remembering in a mailed link. */
function contextFromRequestId(PDO $pdo, string $requestId): ?array
{
    $authRequest = $requestId !== '' ? liveAuthRequest($pdo, $requestId) : null;

    return $authRequest === null ? null : ['client_id' => $authRequest['application']['client_id']];
}

// ── What the pages need first ────────────────────────────────────────────────

$app->get('/api/public/config', function (Request $request, Response $response) {
    // Every page of the IAM asks this first: ordinary traffic for housekeeping
    // to ride on (the host has no cron). It never fails the request.
    iamHousekeeping();

    $google = googleClientId() !== null && googleLoginEnabled();

    return respondJson($response, [
        'login_enabled' => loginEnabled(),
        'registration_enabled' => registrationEnabled() && loginEnabled(),
        'registration_approval' => registrationApprovalRequired(),
        // The registration form offers the news from the sites once there is
        // somewhere to keep the answer (migration 013).
        'registration_news' => registrationNewsOffered(),
        'google_enabled' => $google,
        'google_client_id' => $google ? googleClientId() : null,
        'password_min' => PASSWORD_MIN,
        'languages' => languageCodes(),
    ]);
});

// Where the IAM stands - `{state, installed}`, nothing else - in the anotoki
// family's words (anotoki/lib, iamSiteState()): ready, not_set_up,
// update_pending or unavailable. The gate lets it through in every state, so
// the pages can always ask; the admin panel's line "A database update is
// waiting" reads it. Public and first-party: no cookie is read, and it is in
// neither CORS list.
MigrationsRoutes::status($app, iamSiteState());

// The pages' words in one language, with English under them and the languages
// on offer (translationBundle()). The same for everybody, so nobody is asked
// who they are: no cookie is read and none is set, and a page whose session
// has run out still reads in its language. Only the IAM's own pages read it -
// it is in neither CORS list.
//
// `language` in the answer is the language it is in, which need not be the
// code asked for: "sk-sk" is answered by "sk" when only that is offered - the
// same bytes under the same tag - and what the IAM does not offer by English.
$app->get('/api/translations/{code}', function (Request $request, Response $response, array $args) {
    // Before any query: only a code is looked up. A wrong address is a 404,
    // not a database refusing bytes that are not text.
    $code = (string) $args['code'];
    if (!languageCodeOk($code)) {
        return respondError($response, 404, 'not_found', 'There is no such language');
    }

    $bundle = translationBundle(iamDb(), $code);
    if (translationRefused($bundle)) {
        return translationRefuse($response, $bundle);
    }

    // The strings change when somebody saves one in the admin panel, or with
    // a release: the browser asks every time (no-cache) and is told "the same
    // as you have" in a few bytes. Both headers are set before that answer, so
    // it carries them too - without them it would go out as no-store.
    $body = (string) json_encode($bundle, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $etag = '"' . md5($body) . '"';
    $response = $response->withHeader('ETag', $etag)->withHeader('Cache-Control', 'no-cache');

    if (translationsNotModified($request->getHeaderLine('If-None-Match'), $etag)) {
        return $response->withStatus(304);
    }

    $response->getBody()->write($body);

    return $response->withHeader('Content-Type', 'application/json');
});

// ── The pending authorization request ────────────────────────────────────────

$app->get('/api/login/request/{id}', function (Request $request, Response $response, array $args) {
    $authRequest = liveAuthRequest(iamDb(), (string) $args['id']);

    if ($authRequest === null) {
        return respondError($response, 404, 'request_expired', 'This sign-in took too long, or has already been used');
    }

    return respondJson($response, [
        'id' => $authRequest['id'],
        'application' => applicationSummary($authRequest['application']),
        'prompt' => $authRequest['prompt'],
        'login_hint' => $authRequest['login_hint'],
        'expires_at' => isoTime($authRequest['expires_at']),
    ]);
});

/**
 * POST /api/login/request/{id}/accept - the signed-in person continues to the
 * application: the code is issued and the page is told where to go.
 *
 * Everything that could stop it is checked here and named, so the page can
 * offer the right way forward rather than a bare "no".
 */
$app->post('/api/login/request/{id}/accept', function (Request $request, Response $response, array $args) {
    $pdo = iamDb();
    $signIn = currentSignIn($request);

    // Nobody signed in here: a sign-in hint this browser carries is stale, as
    // at every answer with no session behind it (api/session.php).
    if ($signIn === null) {
        $refusal = respondError($response, 401, 'no_session', 'Sign in first');

        return signInHintSent($request) ? withoutSignInHint($refusal) : $refusal;
    }

    $authRequest = liveAuthRequest($pdo, (string) $args['id']);

    if ($authRequest === null) {
        return respondError($response, 404, 'request_expired', 'This sign-in took too long, or has already been used');
    }

    $user = $signIn['user'];

    if (asBool($user['force_password_change'])) {
        return respondError($response, 403, 'password_change_required', 'Choose a new password first');
    }

    if ($refusal = applicationAccessRefusal($authRequest['application'], $user)) {
        return respondError($response, $refusal['status'], $refusal['code'], $refusal['error']);
    }

    // prompt=login, or a max_age this sign-in is older than: the page asks for
    // the credentials again and then accepts once more.
    if (!sessionSatisfiesRequest($signIn['session'], $authRequest)) {
        return respondError($response, 403, 'login_required', 'Sign in again to continue');
    }

    if (!completeAuthRequest($pdo, $authRequest['id'])) {
        return respondError($response, 404, 'request_expired', 'This sign-in has already been used');
    }

    return respondJson($response, ['redirect' => issueAuthorizationCode($pdo, $authRequest, $user, $signIn['session'])]);
});

$app->post('/api/login/request/{id}/cancel', function (Request $request, Response $response, array $args) {
    $pdo = iamDb();
    $authRequest = liveAuthRequest($pdo, (string) $args['id']);

    if ($authRequest === null || !completeAuthRequest($pdo, $authRequest['id'])) {
        return respondError($response, 404, 'request_expired', 'This sign-in took too long, or has already been used');
    }

    // Going back from the page that said this account may not use the
    // application is not a change of mind, so the application is told why, in
    // the words that page used - otherwise it can only say "cancelled" to
    // somebody who was turned away. Anybody else going back did cancel.
    $signIn = currentSignIn($request);
    $refusal = $signIn === null ? null : applicationAccessRefusal($authRequest['application'], $signIn['user']);

    return respondJson($response, ['redirect' => errorRedirect($authRequest, 'access_denied', $refusal['error'] ?? 'The sign-in was cancelled')]);
});

// ── Password ─────────────────────────────────────────────────────────────────

$app->post('/api/login/password', function (Request $request, Response $response) {
    $body = requestBody($request);
    $email = field($body, 'email');
    $password = (string) ($body['password'] ?? '');
    $pdo = iamDb();

    if ($email === '' || $password === '') {
        return respondError($response, 422, 'invalid_input', 'Email and password are required');
    }

    // Before anything else, including whether the account exists: a limiter
    // that only applied to real addresses would say which ones those are.
    if (loginAttemptsExceeded($pdo, $request, $email)) {
        return tooManyAttempts($response);
    }

    $user = userByEmail($pdo, $email);

    if ($user === null) {
        // A real hash is checked anyway, so an unknown address takes as long
        // to refuse as a wrong password and the timing says nothing.
        password_verify($password, dummyPasswordHash());
        recordLoginAttempt($pdo, $request, $email, null, SESSION_METHOD_PASSWORD, 'unknown_email');
        return respondError($response, 401, 'invalid_credentials', 'That email and password do not match');
    }

    // Real, but not a way in: made through Google, or password sign-in switched
    // off. Saying so admits the address has an account - a deliberate trade,
    // because "wrong password" to somebody who has none leaves them nothing to
    // try. Nothing is sent from here.
    if (!passwordLoginAvailable($user)) {
        recordLoginAttempt($pdo, $request, $email, (int) $user['id'], SESSION_METHOD_PASSWORD, 'password_unavailable');
        return respondError($response, 403, 'password_login_unavailable', 'This account signs in another way');
    }

    if (!password_verify($password, (string) $user['password'])) {
        recordLoginAttempt($pdo, $request, $email, (int) $user['id'], SESSION_METHOD_PASSWORD, 'bad_password');
        return respondError($response, 401, 'invalid_credentials', 'That email and password do not match');
    }

    if ($refusal = totpRefusal($request, $response, $pdo, $user, $body['totp'] ?? null, $body['device_token'] ?? null, SESSION_METHOD_PASSWORD)) {
        return $refusal;
    }

    if (!asBool($user['email_confirmed'])) {
        recordLoginAttempt($pdo, $request, $email, (int) $user['id'], SESSION_METHOD_PASSWORD, 'unconfirmed');
        return respondError($response, 403, 'email_not_confirmed', 'Confirm your email address before signing in');
    }

    if ($stop = signInRefusalResponse($response, $user)) {
        return $stop;
    }

    // A hash made with older settings is quietly brought up to date while the
    // plain password is at hand - the only moment it ever is. Never onto an
    // account deleted meanwhile (liveAccountSql()).
    if (password_needs_rehash((string) $user['password'], PASSWORD_DEFAULT)) {
        $pdo->prepare('UPDATE users SET password = ? WHERE id = ?' . liveAccountSql($pdo))->execute([password_hash($password, PASSWORD_DEFAULT), (int) $user['id']]);
    }

    return completeSignIn($request, $response, $user, SESSION_METHOD_PASSWORD, $body);
});

// ── Emailed sign-in code ─────────────────────────────────────────────────────

$app->post('/api/login/code', function (Request $request, Response $response) {
    $pdo = iamDb();
    $email = field(requestBody($request), 'email');

    if (loginAttemptsExceeded($pdo, $request, $email)) {
        return tooManyAttempts($response);
    }
    recordLoginAttempt($pdo, $request, $email, null, SESSION_METHOD_LOGIN_CODE, 'sent');

    $user = userByEmail($pdo, $email);

    // Not for unconfirmed accounts: the code would confirm the address as a
    // side effect, and the confirmation link is the way that is meant to happen.
    if ($user !== null && asBool($user['email_confirmed']) && !asBool($user['disabled'])) {
        $code = issueLoginCode($pdo, (int) $user['id'], MAIL_LOGIN_CODE_MINUTES * 60);
        if ($code !== null) {
            sendLoginCodeMail($user, $code);
        }
    }

    return respondJson($response, ['requested' => true]);
});

$app->post('/api/login/code/verify', function (Request $request, Response $response) {
    $pdo = iamDb();
    $body = requestBody($request);
    $email = field($body, 'email');

    if (loginAttemptsExceeded($pdo, $request, $email)) {
        return tooManyAttempts($response);
    }

    $user = userByEmail($pdo, $email);
    $code = (string) preg_replace('/\D/', '', (string) ($body['code'] ?? ''));

    if ($user === null || $code === '') {
        recordLoginAttempt($pdo, $request, $email, $user ? (int) $user['id'] : null, SESSION_METHOD_LOGIN_CODE, 'bad_code');
        return respondError($response, 400, 'invalid_code', 'That code is not valid');
    }

    // The mailed code first, and only then the second factor. The other way
    // round, any six digits told a stranger whether the account has two-factor
    // on (totp_required, or invalid_code), and the authenticator could be
    // guessed at without the mailbox. The code is looked up here and spent
    // only once both have passed, so a sign-in that stops to ask for the
    // authenticator can be finished with the same mailed code.
    $token = findLoginCode($pdo, (int) $user['id'], $code);

    if ($token === null) {
        recordLoginAttempt($pdo, $request, $email, (int) $user['id'], SESSION_METHOD_LOGIN_CODE, 'bad_code');
        return respondError($response, 400, 'invalid_code', 'That code is not valid');
    }

    if ($refusal = totpRefusal($request, $response, $pdo, $user, $body['totp'] ?? null, $body['device_token'] ?? null, SESSION_METHOD_LOGIN_CODE)) {
        return $refusal;
    }

    if (!consumeOneTimeToken($pdo, (int) $token['id'])) {
        recordLoginAttempt($pdo, $request, $email, (int) $user['id'], SESSION_METHOD_LOGIN_CODE, 'bad_code');
        return respondError($response, 400, 'invalid_code', 'That code is not valid');
    }

    if ($stop = signInRefusalResponse($response, $user)) {
        return $stop;
    }

    return completeSignIn($request, $response, $user, SESSION_METHOD_LOGIN_CODE, $body);
});

// ── Google ───────────────────────────────────────────────────────────────────

/**
 * POST /api/login/google - sign in, or make an account, with a Google token.
 *
 * In order: the identity is already attached to an account - that is the
 * account, whatever the address says now. Else Google vouches for an address
 * an account here already has - the identity is attached to it; without
 * `email_verified` the address is a string nobody vouched for, and attaching on
 * it would be a way into somebody else's account. Else it is a new account,
 * confirmed on arrival, with no password.
 */
$app->post('/api/login/google', function (Request $request, Response $response) {
    if (!googleLoginEnabled()) {
        return respondError($response, 403, 'google_disabled', 'Google sign-in is switched off');
    }

    $body = requestBody($request);
    $claims = googleVerifyIdToken((string) ($body['credential'] ?? ''));

    if ($claims === null) {
        return respondError($response, 400, 'invalid_token', 'That Google sign-in could not be verified');
    }

    $pdo = iamDb();
    $subject = (string) $claims['sub'];
    $email = strtolower(trim((string) ($claims['email'] ?? '')));
    $verified = !empty($claims['email_verified']);
    $owner = identityOwner($pdo, IDENTITY_GOOGLE, $subject);

    // The same limiter as every other way in, keyed on the account's own
    // address where there is one: a Google token is not a licence to guess at
    // somebody's authenticator codes.
    if (loginAttemptsExceeded($pdo, $request, $owner !== null ? (string) $owner['email'] : $email)) {
        return tooManyAttempts($response);
    }

    if ($user = $owner) {
        if ($refusal = totpRefusal($request, $response, $pdo, $user, $body['totp'] ?? null, $body['device_token'] ?? null, SESSION_METHOD_GOOGLE)) {
            return $refusal;
        }
        if ($stop = signInRefusalResponse($response, $user)) {
            return $stop;
        }
        return completeSignIn($request, $response, $user, SESSION_METHOD_GOOGLE, $body, 200, ['created' => false]);
    }

    if ($verified && $email !== '' && ($user = userByEmail($pdo, $email))) {
        if (asBool($user['email_confirmed'])) {
            // Before attaching anything: an account with two-factor on must
            // not gain a new way in on the strength of a Google token alone.
            if ($refusal = totpRefusal($request, $response, $pdo, $user, $body['totp'] ?? null, $body['device_token'] ?? null, SESSION_METHOD_GOOGLE)) {
                return $refusal;
            }
        } else {
            // Never confirmed: whoever registered it may not own the address
            // Google has just vouched for, so everything they set goes before
            // the owner is let in - their two-factor included, which would
            // otherwise lock the owner out of their own address.
            claimUnconfirmedAccount($pdo, $request, $user);
            $user = userById($pdo, (int) $user['id']);
        }
        // Waiting for an administrator's approval is the one refusal that
        // leaves Google's word standing: the address is this person's, as a
        // confirmation link would say, so the identity is attached and the
        // address confirmed before the answer - and the approval, when it
        // comes, can be mailed to it. Any other refusal stops before either.
        $stop = signInRefusal($user);
        if ($stop !== null && $stop['code'] !== 'approval_pending') {
            return respondError($response, $stop['status'], $stop['code'], $stop['error']);
        }

        attachIdentity($pdo, (int) $user['id'], IDENTITY_GOOGLE, $subject, $email);
        // Google has just vouched for the address, which is what the
        // confirmation link is for. Not for an account deleted meanwhile: its
        // row is a placeholder, and the identity just attached is swept.
        $confirmed = $pdo->prepare('UPDATE users SET email_confirmed = TRUE WHERE id = ?' . liveAccountSql($pdo));
        $confirmed->execute([(int) $user['id']]);
        if ($confirmed->rowCount() === 0) {
            return respondError($response, 403, 'account_disabled', 'This account has been switched off');
        }

        if ($stop !== null) {
            return respondError($response, $stop['status'], $stop['code'], $stop['error']);
        }

        return completeSignIn($request, $response, userById($pdo, (int) $user['id']), SESSION_METHOD_GOOGLE, $body, 200, ['created' => false]);
    }

    if (!$verified || $email === '') {
        return respondError($response, 422, 'unverified_email', 'Google did not confirm an email address for that account');
    }

    if (!registrationEnabled() || !loginEnabled()) {
        return respondError($response, 403, 'registration_disabled', 'New accounts cannot be made at the moment');
    }

    // The news from the sites: a yes only from the registration page's box,
    // which hands it over with the credential. From the sign-in page nobody
    // was asked, and the account gets none.
    $newsMail = ($body['news_mail'] ?? false) === true;
    $made = insertSignedUpAccount($pdo, [
        'username' => availableUsername($pdo, (string) ($claims['name'] ?? ''), $email),
        'email' => $email,
        'password' => null,
        'email_confirmed' => true,
        'language' => languageOrDefault($body['language'] ?? null),
        'news_mail' => $newsMail,
    ]);
    $userId = $made['id'];
    attachIdentity($pdo, $userId, IDENTITY_GOOGLE, $subject, $email);
    audit($request, $userId, 'account.created', 'user', $userId, ['via' => 'google'] + ($made['waits'] ? ['waits' => true] : []) + ($newsMail ? ['news_mail' => true] : []));

    // Made, and waiting for an administrator: no session. The page says so,
    // as it does to an account that waits and signs in again later.
    if ($stop = signInRefusal(userById($pdo, $userId))) {
        return respondError($response, $stop['status'], $stop['code'], $stop['error'], ['created' => true]);
    }

    return completeSignIn($request, $response, userById($pdo, $userId), SESSION_METHOD_GOOGLE, $body, 201, ['created' => true]);
});

// ── Registration ─────────────────────────────────────────────────────────────

$app->post('/api/register', function (Request $request, Response $response) {
    if (!registrationEnabled() || !loginEnabled()) {
        return respondError($response, 403, 'registration_disabled', 'New accounts cannot be made at the moment');
    }

    $body = requestBody($request);
    $username = field($body, 'username');
    $email = field($body, 'email');
    $password = (string) ($body['password'] ?? '');
    $language = languageOrDefault($body['language'] ?? null);
    $pdo = iamDb();

    if ($refusal = usernameRefusal($username)) {
        return respondError($response, 422, 'invalid_username', $refusal);
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return respondError($response, 422, 'invalid_email', 'That does not look like an email address');
    }
    if ($refusal = passwordRefusal($password)) {
        return respondError($response, 422, 'invalid_password', $refusal);
    }
    // The news from the sites: offered unticked, so only a yes given here is
    // one (insertSignedUpAccount()). Left out, it is no.
    $newsMail = $body['news_mail'] ?? false;
    if (!is_bool($newsMail)) {
        return respondError($response, 422, 'invalid_input', 'news_mail is true or false');
    }

    // Registration has to say "that address is taken", which makes it an
    // enumeration oracle, and each account mails a confirmation, which makes it
    // a way to spam one. The login limiter caps both, shared by source.
    if (loginAttemptsExceeded($pdo, $request, $email)) {
        return tooManyAttempts($response);
    }

    if (emailTaken($pdo, $email)) {
        recordLoginAttempt($pdo, $request, $email, null, 'register', 'taken');
        return respondError($response, 409, 'email_taken', 'That email address is already taken');
    }
    if (usernameTaken($pdo, $username)) {
        recordLoginAttempt($pdo, $request, $email, null, 'register', 'taken');
        return respondError($response, 409, 'username_taken', 'That username is already taken');
    }

    $made = insertSignedUpAccount($pdo, [
        'username' => $username,
        'email' => $email,
        'password' => password_hash($password, PASSWORD_DEFAULT),
        'email_confirmed' => false,
        'language' => $language,
        'news_mail' => $newsMail,
    ]);
    $userId = $made['id'];
    recordLoginAttempt($pdo, $request, $email, $userId, 'register', 'sent');
    // A yes to the news is kept with the account's making: when, and from where.
    audit($request, $userId, 'account.created', 'user', $userId, ['via' => 'register'] + ($made['waits'] ? ['waits' => true] : []) + ($newsMail ? ['news_mail' => true] : []));

    $user = userById($pdo, $userId);
    $token = issueOneTimeToken($pdo, $userId, TOKEN_EMAIL_CONFIRM, MAIL_CONFIRM_HOURS * 3600, contextFromRequestId($pdo, field($body, 'request')));
    $sent = $token !== null && sendConfirmationMail($user, $token);

    // No session: the account is unusable until somebody opens the link, which
    // is the whole point of sending one - and, while new accounts need
    // approval, until an administrator approves it too: the page says so.
    return respondJson($response, ['email' => $email, 'sent' => $sent, 'approval_pending' => $made['waits']], 201);
});

$app->post('/api/register/confirm', function (Request $request, Response $response) {
    $pdo = iamDb();
    $token = findOneTimeToken($pdo, field(requestBody($request), 'token'), TOKEN_EMAIL_CONFIRM);

    if ($token === null || !consumeOneTimeToken($pdo, (int) $token['id'])) {
        return respondError($response, 400, 'invalid_token', 'That link is no longer valid');
    }

    // An account deleted since the link was mailed has no address to confirm.
    $confirmed = $pdo->prepare('UPDATE users SET email_confirmed = TRUE WHERE id = ?' . liveAccountSql($pdo));
    $confirmed->execute([(int) $token['user_id']]);
    $user = userById($pdo, (int) $token['user_id']);

    if ($user === null || $confirmed->rowCount() === 0) {
        return respondError($response, 400, 'invalid_token', 'That link is no longer valid');
    }

    if ($stop = signInRefusalResponse($response, $user)) {
        return $stop;
    }

    // Signed in on the spot: holding the link proves the mailbox, a stronger
    // claim than the password they would otherwise be asked for.
    return completeSignIn($request, $response, $user, SESSION_METHOD_EMAIL_LINK, ['remember' => true], 200, [
        'continue_to' => continueTo(tokenContext($token)),
    ]);
});

$app->post('/api/register/resend', function (Request $request, Response $response) {
    $pdo = iamDb();
    $email = field(requestBody($request), 'email');

    if (loginAttemptsExceeded($pdo, $request, $email)) {
        return tooManyAttempts($response);
    }
    recordLoginAttempt($pdo, $request, $email, null, 'resend', 'sent');

    $user = userByEmail($pdo, $email);

    if ($user !== null && !asBool($user['email_confirmed']) && !asBool($user['disabled'])) {
        $token = issueOneTimeToken($pdo, (int) $user['id'], TOKEN_EMAIL_CONFIRM, MAIL_CONFIRM_HOURS * 3600);
        if ($token !== null) {
            sendConfirmationMail($user, $token);
        }
    }

    return respondJson($response, ['requested' => true]);
});

// ── Forgotten passwords ──────────────────────────────────────────────────────

$app->post('/api/password/forgot', function (Request $request, Response $response) {
    $pdo = iamDb();
    $body = requestBody($request);
    $email = field($body, 'email');

    if (loginAttemptsExceeded($pdo, $request, $email)) {
        return tooManyAttempts($response);
    }
    recordLoginAttempt($pdo, $request, $email, null, 'forgot', 'sent');

    $user = userByEmail($pdo, $email);

    // Only for accounts with a password to reset. One that signs in another
    // way has nothing to set here, and saying so would say which.
    if ($user !== null && $user['password'] !== null && !asBool($user['disabled'])) {
        $token = issueOneTimeToken($pdo, (int) $user['id'], TOKEN_PASSWORD_RESET, MAIL_RESET_MINUTES * 60, contextFromRequestId($pdo, field($body, 'request')));
        if ($token !== null) {
            sendPasswordResetMail($user, $token);
        }
    }

    return respondJson($response, ['requested' => true]);
});

$app->post('/api/password/reset', function (Request $request, Response $response) {
    $body = requestBody($request);
    $password = (string) ($body['password'] ?? '');
    $pdo = iamDb();

    if ($refusal = passwordRefusal($password)) {
        return respondError($response, 422, 'invalid_password', $refusal);
    }

    $token = findOneTimeToken($pdo, field($body, 'token'), TOKEN_PASSWORD_RESET);
    $before = $token === null ? null : userById($pdo, (int) $token['user_id']);

    // A link of an account deleted since it was mailed opens nothing: the row
    // is a placeholder, and no password or confirmed address goes back on it.
    if ($before === null || accountAnonymized($before)) {
        return respondError($response, 400, 'invalid_token', 'That link is no longer valid');
    }

    // Two-factor holds here too. The link proves the mailbox and nothing more,
    // and a mailbox is exactly what the second factor stands in case of: with
    // it on, the reset asks for a code like any sign-in, before the link is
    // spent or anything changes - so a wrong code costs nothing but an attempt,
    // and those are limited like any other. Not on an account never confirmed:
    // whatever two-factor it has is its registrant's, and goes with the rest.
    if (asBool($before['email_confirmed']) && asBool($before['totp_enabled'])) {
        if (loginAttemptsExceeded($pdo, $request, (string) $before['email'])) {
            return tooManyAttempts($response);
        }
        if ($refusal = totpRefusal($request, $response, $pdo, $before, $body['totp'] ?? null, null, ATTEMPT_METHOD_PASSWORD_RESET)) {
            return $refusal;
        }
    }

    if (!consumeOneTimeToken($pdo, (int) $token['id'])) {
        return respondError($response, 400, 'invalid_token', 'That link is no longer valid');
    }

    $userId = (int) $token['user_id'];

    // The mailbox's owner on an account never confirmed: what its registrant
    // set goes first (claimUnconfirmedAccount()).
    claimUnconfirmedAccount($pdo, $request, $before);

    // The address is confirmed on the way past: reading the mail proves the
    // mailbox as well as the confirmation link does. Not on an account
    // deleted while this was on its way (liveAccountSql()).
    $reset = $pdo->prepare('UPDATE users SET password = ?, email_confirmed = TRUE, force_password_change = FALSE WHERE id = ?' . liveAccountSql($pdo));
    $reset->execute([password_hash($password, PASSWORD_DEFAULT), $userId]);
    if ($reset->rowCount() === 0) {
        return respondError($response, 400, 'invalid_token', 'That link is no longer valid');
    }

    // A reset is how somebody takes an account back, and it should take it back
    // completely: the other reset links, every session and remembered device,
    // the personal tokens (which outlive sessions), and the outside identities -
    // somebody who held the account for a while could have attached their own
    // Google to it. A Google account under this same address attaches itself
    // again at its next sign-in; one under another address is connected again
    // from the account page. The browsers it signed in from are forgotten too,
    // so a sign-in from one of the holder's is mailed; this one is noted next,
    // as the account's first again.
    revokeOneTimeTokens($pdo, $userId, TOKEN_PASSWORD_RESET);
    revokeSessionsFor($pdo, $userId, SESSION_REVOKED_PASSWORD);
    revokePersonalTokens($pdo, $userId, SESSION_REVOKED_PASSWORD);
    $pdo->prepare('DELETE FROM user_identities WHERE user_id = ?')->execute([$userId]);
    revokeTrustedDevices($pdo, $userId);
    forgetSignInDevices($pdo, $userId);
    audit($request, $userId, 'account.password_reset', 'user', $userId);

    $user = userById($pdo, $userId);

    if ($user === null) {
        return respondError($response, 400, 'invalid_token', 'That link is no longer valid');
    }

    if ($stop = signInRefusalResponse($response, $user)) {
        return $stop;
    }

    return completeSignIn($request, $response, $user, SESSION_METHOD_EMAIL_LINK, ['remember' => true], 200, [
        'continue_to' => continueTo(tokenContext($token)),
    ]);
});

// ── Signing out ──────────────────────────────────────────────────────────────

$app->get('/api/logout/request/{id}', function (Request $request, Response $response, array $args) {
    $logout = liveAuthRequest(iamDb(), (string) $args['id'], 'logout');

    if ($logout === null) {
        return respondError($response, 404, 'request_expired', 'This sign-out request has expired');
    }

    return respondJson($response, ['id' => $logout['id'], 'application' => applicationSummary($logout['application'])]);
});

/**
 * POST /api/logout - ends this browser's session and every refresh token
 * issued under it, on every site. Answers the same whether or not there was
 * one: somebody asking to be signed out is asking for a state, and it holds -
 * no session cookie, and no sign-in hint for any site to act on.
 */
$app->post('/api/logout', function (Request $request, Response $response) {
    $pdo = iamDb();

    if ($session = sessionFromRequest($pdo, $request)) {
        revokeSession($pdo, (int) $session['id'], SESSION_REVOKED_SIGNOUT);
    }

    $redirect = null;
    $requestId = field(requestBody($request), 'request');

    if ($requestId !== '' && ($logout = liveAuthRequest($pdo, $requestId, 'logout')) && completeAuthRequest($pdo, $requestId)) {
        $redirect = $logout['redirect_uri'] !== null ? redirectWith((string) $logout['redirect_uri'], ['state' => $logout['state']]) : null;
    }

    return withoutSignInHint(withoutSessionCookie(respondJson($response, ['message' => 'Signed out', 'redirect' => $redirect])));
});

// ── Stopping the news ────────────────────────────────────────────────────────

/**
 * POST /api/news/unsubscribe {token} - the account the token names gets no
 * more announcements (api/announcements.php). The link at the end of every
 * announcement opens the page (/unsubscribe), which asks first and then
 * sends this: a mail program or a virus scanner that opens every link in a
 * mail must not switch anybody's news off by looking.
 *
 * No session: the token is the proof, and all it can do is this - whoever
 * reads the mail may stop it. It answers the same once the news is off
 * already. It keeps the CSRF header every first-party write has, which is
 * why no mail program's one-click unsubscribe (RFC 8058) is offered: that
 * would be the first write outside the protocol endpoints without it
 * (docs/decisions.md).
 */
$app->post('/api/news/unsubscribe', function (Request $request, Response $response) {
    $pdo = iamDb();

    if (!announcementsReady($pdo)) {
        $refusal = announcementsUnavailable();
        return respondError($response, $refusal['status'], $refusal['code'], $refusal['error']);
    }

    $userId = newsUnsubscribeUser(field(requestBody($request), 'token'));
    if ($userId === null) {
        return respondError($response, 400, 'invalid_token', 'That is not a link from one of our emails');
    }

    $statement = $pdo->prepare('UPDATE users SET news_mail = FALSE WHERE id = ? AND news_mail RETURNING id');
    $statement->execute([$userId]);
    if ($statement->fetchColumn() !== false) {
        audit($request, null, 'account.news_mail', 'user', $userId, ['news_mail' => false, 'by' => 'link']);
    }

    return respondJson($response, ['news_mail' => false]);
});
