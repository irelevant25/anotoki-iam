<?php

/**
 * php tests/run.php - the IAM's tests, against a throwaway database.
 *
 * The flows an application depends on, end to end through the real routes:
 * signing in, single sign-on, the code exchange with PKCE, refresh rotation and
 * reuse detection, logout, members-only applications, two-factor, personal
 * tokens, the admin panel's guard rails - and the attacks each rule exists
 * to stop.
 */

declare(strict_types=1);

require __DIR__ . '/lib.php';

// The applications sign in through the development addresses migration 002
// registers - the same ones every site's dev server uses.
bootTests();

const GENSHIN_CALLBACK = 'http://localhost:4200/auth/callback';
const GENSHIN_ORIGIN = 'http://localhost:4200';
const JAPANESE_CALLBACK = 'http://localhost:4400/auth/callback';

/** Starts a sign-in at the authorize endpoint and answers where it sent the browser. */
function authorize(Browser $browser, array $overrides = []): array
{
    [$verifier, $challenge] = pkce();
    $parameters = $overrides + [
        'response_type' => 'code',
        'client_id' => 'genshin',
        'redirect_uri' => GENSHIN_CALLBACK,
        'scope' => 'openid profile email',
        'state' => bin2hex(random_bytes(8)),
        'nonce' => bin2hex(random_bytes(8)),
        'code_challenge' => $challenge,
        'code_challenge_method' => 'S256',
    ];
    $parameters = array_filter($parameters, static fn($value) => $value !== null);

    $response = $browser->get('/api/oidc/authorize?' . http_build_query($parameters));

    return ['response' => $response, 'location' => $response->location(), 'verifier' => $verifier, 'parameters' => $parameters];
}

function exchangeCode(Browser $browser, string $code, string $verifier, array $overrides = []): TestResponse
{
    return $browser->request('POST', '/api/oidc/token', [
        'form' => $overrides + [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => GENSHIN_CALLBACK,
            'client_id' => 'genshin',
            'code_verifier' => $verifier,
        ],
        'headers' => ['Origin' => GENSHIN_ORIGIN],
    ]);
}

function refreshWith(Browser $browser, string $refreshToken, string $clientId = 'genshin'): TestResponse
{
    return $browser->request('POST', '/api/oidc/token', [
        'form' => ['grant_type' => 'refresh_token', 'refresh_token' => $refreshToken, 'client_id' => $clientId],
    ]);
}

/** Signs in and completes a genshin authorization: the token response. */
function signInToGenshin(Browser $browser, string $email, string $password = 'correct horse'): array
{
    $start = authorize($browser);
    $request = queryOf($start['location'])['request'] ?? null;
    check($request !== null, 'authorize should send an anonymous browser to the login page, sent to ' . $start['location']);

    same(200, $browser->signIn($email, $password)->status, 'sign-in');
    $accept = $browser->post("/api/login/request/{$request}/accept");
    same(200, $accept->status, 'accept: ' . $accept->body);

    $callback = queryOf($accept->json()['redirect']);
    same($start['parameters']['state'], $callback['state'] ?? null, 'state comes back');
    same(TEST_ISSUER, $callback['iss'] ?? null, 'iss comes back (RFC 9207)');

    $tokens = exchangeCode($browser, $callback['code'], $start['verifier']);
    same(200, $tokens->status, 'code exchange: ' . $tokens->body);

    return $tokens->json() + ['_nonce' => $start['parameters']['nonce']];
}

$adminId = makeUser('admin', 'admin@example.test');
grantRole($adminId, 'iam', 'ADMIN');
$playerId = makeUser('player', 'player@example.test');
grantRole($playerId, 'genshin', 'EDITOR');

// ─────────────────────────────────────────────────────────────────────────────

test('discovery names this issuer and its endpoints', function () {
    $document = (new Browser())->get('/.well-known/openid-configuration')->json();
    same(TEST_ISSUER, $document['issuer'], 'issuer');
    same(TEST_ISSUER . '/api/oidc/token', $document['token_endpoint'], 'token endpoint');
    same(['S256'], $document['code_challenge_methods_supported'], 'PKCE methods');
});

test('the key set publishes one RSA signing key, made on first use', function () {
    $keys = (new Browser())->get('/.well-known/jwks.json')->json()['keys'];
    same(1, count($keys), 'one key');
    same('RSA', $keys[0]['kty'], 'kty');
    check(!isset($keys[0]['d']), 'no private part in the JWKS');
});

test('the signing key is stored encrypted', function () {
    $stored = (string) iamDb()->query("SELECT private_key FROM signing_keys WHERE status = 'active'")->fetchColumn();
    check(str_starts_with($stored, 'v1:'), 'private key is encrypted');
    check(!str_contains($stored, 'PRIVATE KEY'), 'no PEM in the table');
});

test('an unknown client or an unregistered redirect URI never redirects there', function () {
    $browser = new Browser();
    check(str_contains(authorize($browser, ['client_id' => 'nobody'])['location'], '/error?code=invalid_client'), 'unknown client');
    check(str_contains(authorize($browser, ['redirect_uri' => 'https://evil.test/cb'])['location'], '/error?code=invalid_redirect_uri'), 'foreign redirect');
    check(str_contains(authorize($browser, ['redirect_uri' => GENSHIN_CALLBACK . 'x'])['location'], '/error?code=invalid_redirect_uri'), 'prefix match is not a match');
});

test('a public client without PKCE is sent back with invalid_request', function () {
    $start = authorize(new Browser(), ['code_challenge' => null, 'code_challenge_method' => null]);
    check(str_starts_with($start['location'], GENSHIN_CALLBACK), 'goes back to the registered URI');
    same('invalid_request', queryOf($start['location'])['error'] ?? null, 'error');
});

test('scope without openid is refused', function () {
    same('invalid_scope', queryOf(authorize(new Browser(), ['scope' => 'profile'])['location'])['error'] ?? null, 'error');
});

test('the full sign-in: authorize, login, accept, exchange - and the token verifies with the SDK', function () {
    $browser = new Browser();
    $tokens = signInToGenshin($browser, 'player@example.test');

    check(str_starts_with($tokens['refresh_token'], 'iam_rt_'), 'a refresh token');
    same('Bearer', $tokens['token_type'], 'token type');

    $claims = verifyLikeAnApp($browser, $tokens['access_token'], 'genshin');
    check($claims !== null, 'the access token verifies against the published keys');
    same((string) $GLOBALS['playerId'], $claims['sub'], 'sub is the user id');
    same(['EDITOR', 'MEMBER'], $claims['roles'], 'roles are genshin\'s roles - the one given, and the MEMBER every account holds');
    same('player', $claims['preferred_username'], 'username claim');

    $idToken = jwtVerifyOwn($tokens['id_token'], 'JWT');
    same($tokens['_nonce'], $idToken['nonce'], 'the nonce is in the ID token');
    same(atHash($tokens['access_token']), $idToken['at_hash'], 'at_hash matches');

    check(verifyLikeAnApp($browser, $tokens['access_token'], 'japanese-academy') === null, 'the token is not good for another application');
    check(verifyLikeAnApp($browser, $tokens['id_token'], 'genshin') === null, 'an ID token is not an access token');
});

test('single sign-on: a signed-in browser gets a code without a page', function () {
    $browser = new Browser();
    signInToGenshin($browser, 'player@example.test');

    $again = authorize($browser);
    check(str_starts_with($again['location'], GENSHIN_CALLBACK . '?code='), 'straight back with a code: ' . $again['location']);
    same(200, exchangeCode($browser, queryOf($again['location'])['code'], $again['verifier'])->status, 'and it exchanges');
});

test('prompt=none without a session answers login_required, prompt=login asks again', function () {
    same('login_required', queryOf(authorize(new Browser(), ['prompt' => 'none'])['location'])['error'] ?? null, 'prompt=none');

    $browser = new Browser();
    signInToGenshin($browser, 'player@example.test');
    check(str_contains(authorize($browser, ['prompt' => 'login'])['location'], '/login?request='), 'prompt=login shows the form');
});

test('a code works once; the second exchange revokes what the first produced', function () {
    $browser = new Browser();
    signInToGenshin($browser, 'player@example.test');
    $start = authorize($browser);
    $code = queryOf($start['location'])['code'];

    $first = exchangeCode($browser, $code, $start['verifier']);
    same(200, $first->status, 'first exchange');
    same('invalid_grant', exchangeCode($browser, $code, $start['verifier'])->json()['error'] ?? null, 'second exchange');
    same('invalid_grant', refreshWith($browser, $first->json()['refresh_token'])->json()['error'] ?? null, 'the first exchange\'s refresh token is dead');
});

test('the wrong PKCE verifier, redirect URI or origin is refused', function () {
    $browser = new Browser();
    signInToGenshin($browser, 'player@example.test');

    $start = authorize($browser);
    same('invalid_grant', exchangeCode($browser, queryOf($start['location'])['code'], pkce()[0])->json()['error'] ?? null, 'wrong verifier');

    $start = authorize($browser);
    same('invalid_grant', exchangeCode($browser, queryOf($start['location'])['code'], $start['verifier'], ['redirect_uri' => 'http://localhost:4200/other'])->json()['error'] ?? null, 'wrong redirect');

    $start = authorize($browser);
    $wrongOrigin = $browser->request('POST', '/api/oidc/token', [
        'form' => ['grant_type' => 'authorization_code', 'code' => queryOf($start['location'])['code'], 'redirect_uri' => GENSHIN_CALLBACK, 'client_id' => 'genshin', 'code_verifier' => $start['verifier']],
        'headers' => ['Origin' => 'https://evil.test'],
    ]);
    same(400, $wrongOrigin->status, 'foreign origin');
});

test('refresh rotates; a spent token gets one sibling within the leeway, and a copy ends the family', function () {
    $browser = new Browser();
    $tokens = signInToGenshin($browser, 'player@example.test');
    $first = $tokens['refresh_token'];

    $second = refreshWith($browser, $first);
    same(200, $second->status, 'refresh');
    check($second->json()['refresh_token'] !== $first, 'a new refresh token');

    same(200, refreshWith($browser, $first)->status, 'reuse within the leeway is forgiven once (two tabs)');
    same('invalid_grant', refreshWith($browser, $first)->json()['error'] ?? null, 'a third use, even within the leeway, is a copy');
    same('invalid_grant', refreshWith($browser, $second->json()['refresh_token'])->json()['error'] ?? null, 'and the whole family is dead');

    $again = signInToGenshin(new Browser(), 'player@example.test')['refresh_token'];
    same(200, refreshWith($browser, $again)->status, 'a new sign-in refreshes');
    iamDb()->prepare("UPDATE refresh_tokens SET used_at = CURRENT_TIMESTAMP - INTERVAL '5 minutes' WHERE token_hash = ?")->execute([sha256($again)]);
    same('invalid_grant', refreshWith($browser, $again)->json()['error'] ?? null, 'reuse after the leeway is refused outright');
});

test('the CORS answer names a registered origin and never a stranger', function () {
    $browser = new Browser();
    $allowed = $browser->request('OPTIONS', '/api/oidc/token', ['headers' => ['Origin' => GENSHIN_ORIGIN]]);
    same(GENSHIN_ORIGIN, $allowed->header('Access-Control-Allow-Origin'), 'registered origin echoed');
    same('', $browser->request('OPTIONS', '/api/oidc/token', ['headers' => ['Origin' => 'https://evil.test']])->header('Access-Control-Allow-Origin'), 'stranger gets nothing');
    same('', $browser->request('OPTIONS', '/api/account', ['headers' => ['Origin' => GENSHIN_ORIGIN]])->header('Access-Control-Allow-Origin'), 'first-party endpoints answer no preflight');
});

test('userinfo answers fresh claims, and stops once the session ends', function () {
    $browser = new Browser();
    $tokens = signInToGenshin($browser, 'player@example.test');
    $bearer = ['headers' => ['Authorization' => 'Bearer ' . $tokens['access_token']]];

    $info = $browser->get('/api/oidc/userinfo', $bearer);
    same(200, $info->status, 'userinfo');
    same('player', $info->json()['preferred_username'], 'username');

    same(200, $browser->post('/api/logout')->status, 'logout');
    same(401, $browser->get('/api/oidc/userinfo', $bearer)->status, 'userinfo after logout');
    same('invalid_grant', refreshWith($browser, $tokens['refresh_token'])->json()['error'] ?? null, 'refresh after logout');
});

test('RP-initiated logout with a valid id_token_hint signs out without asking', function () {
    $browser = new Browser();
    $tokens = signInToGenshin($browser, 'player@example.test');

    $logout = $browser->get('/api/oidc/logout?' . http_build_query([
        'client_id' => 'genshin',
        'id_token_hint' => $tokens['id_token'],
        'post_logout_redirect_uri' => 'http://localhost:4200/',
        'state' => 'xyz',
    ]));
    same('http://localhost:4200/?state=xyz', $logout->location(), 'lands on the registered page');
    same(401, $browser->get('/api/account')->status, 'the IAM session is over');
});

test('logout without a hint asks first, and an unregistered landing is refused', function () {
    $browser = new Browser();
    signInToGenshin($browser, 'player@example.test');

    $asked = $browser->get('/api/oidc/logout?client_id=genshin&post_logout_redirect_uri=' . urlencode('http://localhost:4200/'));
    check(str_contains($asked->location(), '/logout?request='), 'sent to the confirm page');
    same(200, $browser->get('/api/account')->status, 'still signed in until confirmed');

    $request = queryOf($asked->location())['request'];
    $confirmed = $browser->post('/api/logout', ['request' => $request]);
    same('http://localhost:4200/', $confirmed->json()['redirect'], 'confirming lands on the registered page');

    check(str_contains($browser->get('/api/oidc/logout?client_id=genshin&post_logout_redirect_uri=' . urlencode('https://evil.test/'))->location(), 'invalid_redirect_uri'), 'foreign landing refused');
});

test('first-party writes need the CSRF header and the IAM\'s own origin', function () {
    $browser = new Browser();
    same('csrf', $browser->post('/api/login/password', ['email' => 'player@example.test', 'password' => 'correct horse'], ['csrf' => false])->code(), 'no header');
    same('csrf', $browser->post('/api/login/password', ['email' => 'player@example.test', 'password' => 'correct horse'], ['headers' => ['Origin' => 'https://genshin.anotoki.eu']])->code(), 'a sibling site\'s origin');
    same(200, $browser->post('/api/login/password', ['email' => 'player@example.test', 'password' => 'correct horse'], ['headers' => ['Origin' => TEST_ISSUER]])->status, 'the IAM\'s own origin');

    // The router decodes the path before matching; the guard must see the same
    // path, or `/%61pi/...` reaches a first-party route with no header at all.
    same('csrf', $browser->post('/%61pi/login/password', ['email' => 'player@example.test', 'password' => 'correct horse'])->code(), 'an encoded /api/');
    same('csrf', $browser->post('/api%2Flogin%2Fpassword', ['email' => 'player@example.test', 'password' => 'correct horse'])->code(), 'encoded slashes');
    same('csrf', $browser->post('/api/oidc/..%2Flogin/password', ['email' => 'player@example.test', 'password' => 'correct horse'])->code(), 'through the exempt prefix');
    check($browser->request('POST', '/api/oidc/token', ['form' => ['grant_type' => 'refresh_token', 'refresh_token' => 'x', 'client_id' => 'genshin']])->code() !== 'csrf', 'the token endpoint needs no header');
});

test('the same answer for a wrong password and an unknown address, and the limiter holds', function () {
    $browser = new Browser('10.0.0.9');
    same('invalid_credentials', $browser->signIn('player@example.test', 'nope nope')->code(), 'wrong password');
    same('invalid_credentials', $browser->signIn('ghost@example.test', 'nope nope')->code(), 'unknown address');

    for ($i = 0; $i < 10; $i++) {
        $browser->signIn('player@example.test', 'nope nope');
    }
    same(429, $browser->signIn('player@example.test', 'correct horse')->status, 'limited, even with the right password');
    same(200, (new Browser('10.0.0.10'))->signIn('player@example.test', 'correct horse')->status, 'another source is unaffected');
});

test('registration: no session until the link is opened, then signed in and pointed back', function () {
    $browser = new Browser('10.0.1.1');
    $start = authorize($browser);
    $request = queryOf($start['location'])['request'];

    $registered = $browser->post('/api/register', ['username' => 'newbie', 'email' => 'newbie@example.test', 'password' => 'a long password', 'language' => 'sk', 'request' => $request]);
    same(201, $registered->status, 'registered: ' . $registered->body);
    same('email_not_confirmed', $browser->signIn('newbie@example.test', 'a long password')->code(), 'not usable yet');

    $mail = lastMailTo('newbie@example.test');
    check($mail !== null && str_contains($mail['subject'], 'anotoki'), 'a confirmation was mailed (in Slovak: ' . ($mail['subject'] ?? '-') . ')');
    preg_match('/token=([0-9a-f]{64})/', $mail['body'], $found);

    $confirmed = $browser->post('/api/register/confirm', ['token' => $found[1]]);
    same(200, $confirmed->status, 'confirmed');
    same('Genshin Impact', $confirmed->json()['continue_to']['name'] ?? null, 'the way back to the application');
    same(400, $browser->post('/api/register/confirm', ['token' => $found[1]])->status, 'the link works once');
    same(409, $browser->post('/api/register', ['username' => 'NEWBIE', 'email' => 'other@example.test', 'password' => 'a long password'])->status, 'usernames are unique regardless of case');
});

test('going back from the sign-in page is a cancel, once', function () {
    $browser = new Browser();
    $start = authorize($browser);
    $request = queryOf($start['location'])['request'] ?? null;
    check($request !== null, 'signed out, the sign-in page is asked for: ' . $start['location']);

    $back = queryOf($browser->post("/api/login/request/{$request}/cancel")->json()['redirect'] ?? '');
    same('access_denied', $back['error'] ?? null, 'the error the application is sent');
    same('The sign-in was cancelled', $back['error_description'] ?? null, 'nobody was signed in, so nobody was turned away');
    same($start['parameters']['state'], $back['state'] ?? null, 'the state goes back');
    same(404, $browser->post("/api/login/request/{$request}/cancel")->status, 'a request is left only once');
});

test('a site whose MEMBER may not sign in refuses an account holding only MEMBER, until a role that may is granted', function () {
    // Every site lets in the MEMBER every account holds - but whether a role
    // may sign in is the admin panel's to change; one of the sites stands in
    // for a members-only application here.
    iamDb()->exec("UPDATE application_roles SET may_sign_in = FALSE
                    WHERE name = 'MEMBER' AND application_id = (SELECT id FROM applications WHERE client_id = 'japanese-academy')");

    $browser = new Browser();
    $browser->signIn('player@example.test', 'correct horse');
    [$verifier, $challenge] = pkce();
    $start = $browser->get('/api/oidc/authorize?' . http_build_query([
        'response_type' => 'code', 'client_id' => 'japanese-academy', 'redirect_uri' => JAPANESE_CALLBACK,
        'scope' => 'openid profile', 'state' => 's', 'nonce' => 'n', 'code_challenge' => $challenge, 'code_challenge_method' => 'S256',
    ]));
    $request = queryOf($start->location())['request'] ?? null;
    check($request !== null, 'no silent code for a non-member: ' . $start->location());
    same('access_denied', $browser->post("/api/login/request/{$request}/accept")->code(), 'accept refused');

    // Going back from the refusal tells the application why; that request is
    // then spent, so the rest of the test starts another.
    $back = queryOf($browser->post("/api/login/request/{$request}/cancel")->json()['redirect'] ?? '');
    same('access_denied', $back['error'] ?? null, 'the way back is an access_denied');
    same('This account cannot use Japanese Academy', $back['error_description'] ?? null, 'and says why, not "cancelled"');
    $request = queryOf($browser->get('/api/oidc/authorize?' . http_build_query([
        'response_type' => 'code', 'client_id' => 'japanese-academy', 'redirect_uri' => JAPANESE_CALLBACK,
        'scope' => 'openid profile', 'state' => 's', 'nonce' => 'n', 'code_challenge' => $challenge, 'code_challenge_method' => 'S256',
    ]))->location())['request'] ?? null;

    grantRole($GLOBALS['playerId'], 'japanese-academy', 'EDITOR');
    $accept = $browser->post("/api/login/request/{$request}/accept");
    same(200, $accept->status, 'accepted once it holds a role there');

    $tokens = $browser->request('POST', '/api/oidc/token', ['form' => [
        'grant_type' => 'authorization_code', 'code' => queryOf($accept->json()['redirect'])['code'],
        'redirect_uri' => JAPANESE_CALLBACK, 'client_id' => 'japanese-academy', 'code_verifier' => $verifier,
    ]])->json();
    check(isset($tokens['refresh_token']), 'tokens');

    iamDb()->prepare("DELETE FROM user_roles WHERE user_id = ? AND role_id IN (SELECT r.id FROM application_roles r JOIN applications a ON a.id = r.application_id WHERE a.client_id = 'japanese-academy' AND r.name = 'EDITOR')")
        ->execute([$GLOBALS['playerId']]);
    same('invalid_grant', refreshWith($browser, $tokens['refresh_token'], 'japanese-academy')->json()['error'] ?? null, 'the role taken away stops the next refresh');

    iamDb()->exec("UPDATE application_roles SET may_sign_in = TRUE
                    WHERE name = 'MEMBER' AND application_id = (SELECT id FROM applications WHERE client_id = 'japanese-academy')");
});

test('every first-party site is open to every account: one given no role there signs in, with the MEMBER it holds', function () {
    $browser = new Browser('10.2.0.1');
    same(200, $browser->signIn('player@example.test', 'correct horse')->status, 'signed in');
    [$verifier, $challenge] = pkce();
    $start = $browser->get('/api/oidc/authorize?' . http_build_query([
        'response_type' => 'code', 'client_id' => 'japanese-academy', 'redirect_uri' => JAPANESE_CALLBACK,
        'scope' => 'openid profile', 'state' => 's', 'nonce' => 'n', 'code_challenge' => $challenge, 'code_challenge_method' => 'S256',
    ]));
    $code = queryOf($start->location())['code'] ?? null;
    check($code !== null, 'a signed-in browser gets its code straight away: ' . $start->location());

    $tokens = $browser->request('POST', '/api/oidc/token', ['form' => [
        'grant_type' => 'authorization_code', 'code' => $code, 'redirect_uri' => JAPANESE_CALLBACK,
        'client_id' => 'japanese-academy', 'code_verifier' => $verifier,
    ]]);
    same(200, $tokens->status, 'code exchange: ' . $tokens->body);
    $claims = verifyLikeAnApp($browser, $tokens->json()['access_token'], 'japanese-academy');
    check($claims !== null, 'the access token verifies as the site verifies it');
    same(['MEMBER'], $claims['roles'], 'and carries MEMBER alone: the site lets anybody signed in in, and keeps its admin area for ADMIN and EDITOR');
});

test('two-factor: setup, enable, then every sign-in asks; a recovery code works once', function () {
    $userId = makeUser('careful', 'careful@example.test');
    $browser = new Browser('10.0.2.1');
    $browser->signIn('careful@example.test', 'correct horse');

    $secret = $browser->post('/api/account/2fa/setup')->json()['secret'];
    check(!str_contains((string) iamDb()->query("SELECT totp_secret FROM users WHERE id = $userId")->fetchColumn(), $secret), 'the secret is stored encrypted');

    $step = intdiv(time(), 30);
    $enabled = $browser->post('/api/account/2fa/enable', ['code' => totpCode($secret, $step)]);
    same(200, $enabled->status, 'enabled');
    $recovery = $enabled->json()['recovery_codes'];
    same(10, count($recovery), 'ten recovery codes');

    $other = new Browser('10.0.2.2');
    same('totp_required', $other->signIn('careful@example.test', 'correct horse')->code(), 'asks for the code');
    same('totp_invalid', $other->signIn('careful@example.test', 'correct horse', ['totp' => '000000'])->code(), 'wrong code');
    same('totp_invalid', $other->signIn('careful@example.test', 'correct horse', ['totp' => totpCode($secret, $step)])->code(), 'the code that turned it on is spent');
    // The next step's code is inside the window, and later than any used.
    $withCode = $other->signIn('careful@example.test', 'correct horse', ['totp' => totpCode($secret, $step + 1), 'remember_device' => true]);
    same(200, $withCode->status, 'right code');
    check(is_string($withCode->json()['device_token']), 'a remembered device');
    same('totp_invalid', (new Browser('10.0.2.6'))->signIn('careful@example.test', 'correct horse', ['totp' => totpCode($secret, $step + 1)])->code(), 'a code works once');

    // The mailed code comes first: without it, the answer says nothing about
    // two-factor, and nothing lets the authenticator be guessed at.
    $mailer = new Browser('10.0.2.7');
    same('invalid_code', $mailer->post('/api/login/code/verify', ['email' => 'careful@example.test', 'code' => '123456'])->code(), 'a wrong mailed code says only that');
    $mailer->post('/api/login/code', ['email' => 'careful@example.test']);
    preg_match('/\b(\d{6})\b/', (string) (lastMailTo('careful@example.test')['subject'] ?? ''), $mailed);
    check(isset($mailed[1]), 'a sign-in code was mailed');
    same('totp_required', $mailer->post('/api/login/code/verify', ['email' => 'careful@example.test', 'code' => $mailed[1]])->code(), 'then the authenticator is asked for');
    same('totp_invalid', $mailer->post('/api/login/code/verify', ['email' => 'careful@example.test', 'code' => $mailed[1], 'totp' => '000000'])->code(), 'a wrong one is refused');
    same(1, (int) iamDb()->query("SELECT count(*) FROM login_attempts WHERE email = 'careful@example.test' AND method = 'login_code' AND outcome = 'bad_totp'")->fetchColumn(), 'and counted');
    same(200, $mailer->post('/api/login/code/verify', ['email' => 'careful@example.test', 'code' => $mailed[1], 'totp' => $recovery[1]])->status, 'the same mailed code finishes it');

    $third = new Browser('10.0.2.3');
    same(200, $third->signIn('careful@example.test', 'correct horse', ['device_token' => $withCode->json()['device_token']])->status, 'the remembered device skips the code');

    $fourth = new Browser('10.0.2.4');
    same(200, $fourth->signIn('careful@example.test', 'correct horse', ['totp' => strtolower($recovery[0])])->status, 'a recovery code, in any case');
    same('totp_invalid', (new Browser('10.0.2.5'))->signIn('careful@example.test', 'correct horse', ['totp' => $recovery[0]])->code(), 'but only once');
});

test('personal tokens: made on the account page, exchanged by a script, revoked there', function () {
    $browser = new Browser();
    $browser->signIn('player@example.test', 'correct horse');

    $made = $browser->post('/api/account/tokens', ['name' => 'importer', 'client_id' => 'genshin', 'expires_in_days' => 30]);
    same(201, $made->status, 'made: ' . $made->body);
    $pat = $made->json()['token'];
    check(str_starts_with($pat, 'iam_pat_'), 'recognisable prefix');

    $exchanged = refreshWith(new Browser(), $pat);
    same(200, $exchanged->status, 'exchanged');
    check(!isset($exchanged->json()['refresh_token']), 'never rotated');
    same(['EDITOR', 'MEMBER'], verifyLikeAnApp($browser, $exchanged->json()['access_token'], 'genshin')['roles'], 'carries the roles');
    same(200, refreshWith(new Browser(), $pat)->status, 'usable again');

    same(200, $browser->post('/api/logout')->status, 'signing out of the browser');
    same(200, refreshWith(new Browser(), $pat)->status, 'does not stop the script');

    $browser->signIn('player@example.test', 'correct horse');
    same(200, $browser->delete('/api/account/tokens/' . $made->json()['entry']['id'])->status, 'revoked');
    same('invalid_grant', refreshWith(new Browser(), $pat)->json()['error'] ?? null, 'dead after revoking');
});

/** Asks for a reset link for an address and answers the token in it. */
function resetTokenFor(string $email, string $ip): string
{
    (new Browser($ip))->post('/api/password/forgot', ['email' => $email]);
    preg_match('/token=([0-9a-f]{64})/', (string) (lastMailTo($email)['body'] ?? ''), $link);
    check(isset($link[1]), 'a reset link was mailed to ' . $email);

    return $link[1];
}

test('a reset takes the account back: sessions, personal tokens and outside identities all go', function () {
    $userId = makeUser('retaken', 'retaken@example.test');
    $intruder = new Browser('10.0.3.1');
    $intruder->signIn('retaken@example.test', 'correct horse');
    $pat = $intruder->post('/api/account/tokens', ['name' => 'left behind', 'client_id' => 'genshin', 'expires_in_days' => 30])->json()['token'] ?? '';
    iamDb()->prepare("INSERT INTO user_identities (user_id, provider, subject, email) VALUES (?, 'google', 'the-intruders-google', 'intruder@example.test')")->execute([$userId]);
    same(200, refreshWith(new Browser(), $pat)->status, 'the personal token works before');

    $reset = (new Browser('10.0.3.2'))->post('/api/password/reset', ['token' => resetTokenFor('retaken@example.test', '10.0.3.2'), 'password' => 'the owners own now']);
    same(200, $reset->status, 'reset: ' . $reset->body);

    same('invalid_grant', refreshWith(new Browser(), $pat)->json()['error'] ?? null, 'the personal token is dead');
    same(0, (int) iamDb()->query("SELECT count(*) FROM user_identities WHERE user_id = $userId")->fetchColumn(), 'the attached Google is gone');
    same(401, $intruder->get('/api/account')->status, 'the session is over');
});

test('a reset with two-factor on asks for a code first, and spends nothing until it gets one', function () {
    $userId = makeUser('guarded', 'guarded@example.test');
    $secret = totpSecret();
    iamDb()->prepare('UPDATE users SET totp_enabled = TRUE, totp_secret = ? WHERE id = ?')->execute([encryptSecret($secret), $userId]);
    $token = resetTokenFor('guarded@example.test', '10.0.5.1');
    $browser = new Browser('10.0.5.2');

    same('totp_required', $browser->post('/api/password/reset', ['token' => $token, 'password' => 'a mailbox is not enough'])->code(), 'the link alone is not enough');
    same('totp_invalid', $browser->post('/api/password/reset', ['token' => $token, 'password' => 'a mailbox is not enough', 'totp' => '000000'])->code(), 'nor a wrong code');
    same(200, (new Browser('10.0.5.3'))->signIn('guarded@example.test', 'correct horse', ['totp' => totpCode($secret, intdiv(time(), 30))])->status, 'the old password still stands meanwhile');

    same(200, $browser->post('/api/password/reset', ['token' => $token, 'password' => 'a mailbox is not enough', 'totp' => totpCode($secret, intdiv(time(), 30) + 1)])->status, 'the same link, with a code');
    same(400, $browser->post('/api/password/reset', ['token' => $token, 'password' => 'again and again', 'totp' => totpCode($secret, intdiv(time(), 30) + 1)])->status, 'and then the link is spent');
});

test('an address proved on an account never confirmed: nothing its registrant set survives', function () {
    $pdo = iamDb();
    $userId = makeUser('squatter', 'squatted@example.test', 'the squatters password', false);
    $pdo->prepare('UPDATE users SET totp_enabled = TRUE, totp_secret = ? WHERE id = ?')->execute([encryptSecret(totpSecret()), $userId]);
    $pdo->prepare('INSERT INTO user_recovery_codes (user_id, code_hash) VALUES (?, ?)')->execute([$userId, sha256('SQUATTERCODE')]);
    $pdo->prepare("INSERT INTO user_identities (user_id, provider, subject, email) VALUES (?, 'google', 'the-squatters-google', 'squatter@example.test')")->execute([$userId]);

    // The registrant's two-factor is not asked for: it is theirs, and it goes.
    $reset = (new Browser('10.0.4.1'))->post('/api/password/reset', ['token' => resetTokenFor('squatted@example.test', '10.0.4.1'), 'password' => 'the real owners password']);
    same(200, $reset->status, 'the owner of the mailbox resets: ' . $reset->body);

    $user = userById($pdo, $userId);
    check(asBool($user['email_confirmed']), 'the address is confirmed');
    check(!asBool($user['totp_enabled']) && $user['totp_secret'] === null, 'the registrant\'s two-factor is gone');
    same(0, (int) $pdo->query("SELECT count(*) FROM user_recovery_codes WHERE user_id = $userId")->fetchColumn(), 'and its recovery codes');
    same(0, (int) $pdo->query("SELECT count(*) FROM user_identities WHERE user_id = $userId")->fetchColumn(), 'and the Google it attached');
    same('invalid_credentials', (new Browser('10.0.4.2'))->signIn('squatted@example.test', 'the squatters password')->code(), 'the registrant\'s password does not work');
    same(200, (new Browser('10.0.4.3'))->signIn('squatted@example.test', 'the real owners password')->status, 'the owner\'s does');
});

test('trusted proxy ranges: v4 and v6, prefixes and single addresses', function () {
    $cloudflare = ['173.245.48.0/20', '2400:cb00::/32'];
    check(ipInRanges('173.245.48.1', $cloudflare) && ipInRanges('173.245.63.254', $cloudflare), 'inside the v4 range');
    check(!ipInRanges('173.245.64.1', $cloudflare), 'just past it');
    check(ipInRanges('2400:cb00:2049::1', $cloudflare) && !ipInRanges('2400:cb01::1', $cloudflare), 'v6 the same way');
    check(ipInRanges('10.0.0.7', ['10.0.0.7']) && !ipInRanges('10.0.0.8', ['10.0.0.7']), 'a bare address is a range of one');
    check(ipInRanges('192.168.1.130', ['192.168.1.128/25']) && !ipInRanges('192.168.1.127', ['192.168.1.128/25']), 'a prefix that ends inside a byte');
    check(!ipInRanges('not an address', $cloudflare) && !ipInRanges('127.0.0.1', ['::1/128']), 'nonsense, and v4 against v6, match nothing');
    check(!ipInRanges('127.0.0.1', []), 'no proxies, nobody trusted');
});

test('plain http is only for this computer, in redirect URIs and origins alike', function () {
    check(validRedirectUri('https://piano.anotoki.eu/auth/callback.html'), 'https anywhere');
    check(validRedirectUri('http://localhost:4200/auth/callback') && validRedirectUri('http://127.0.0.1:4400/auth/callback') && validRedirectUri('http://[::1]:8000/cb'), 'http on this computer');
    check(!validRedirectUri('http://genshin.anotoki.eu/auth/callback'), 'but nowhere else');
    check(!validRedirectUri('https://genshin.anotoki.eu/cb#fragment'), 'no fragment');
    check(validOrigin('https://genshin.anotoki.eu') && validOrigin('http://localhost:4200'), 'origins the same way');
    check(!validOrigin('http://genshin.anotoki.eu'), 'no plain-http origin elsewhere');
});

test('the admin panel is for IAM admins only', function () {
    $player = new Browser();
    $player->signIn('player@example.test', 'correct horse');
    same(403, $player->get('/api/admin/users')->status, 'a player is refused');
    same(401, (new Browser())->get('/api/admin/users')->status, 'anonymous is refused');

    $admin = new Browser();
    $admin->signIn('admin@example.test', 'correct horse');
    same(200, $admin->get('/api/admin/users')->status, 'an admin gets in');
});

test('admins cannot lock themselves out, and the last admin stays', function () {
    $admin = new Browser();
    $admin->signIn('admin@example.test', 'correct horse');
    $adminId = $GLOBALS['adminId'];

    same('self_lockout', $admin->put("/api/admin/users/{$adminId}/enabled", ['enabled' => false])->code(), 'cannot disable self');
    same('self_lockout', $admin->put("/api/admin/users/{$adminId}/roles", ['roles' => []])->code(), 'cannot drop own ADMIN');
});

test('disabling an account ends its sessions and refresh tokens everywhere', function () {
    $victimId = makeUser('leaving', 'leaving@example.test');
    $browser = new Browser();
    $tokens = signInToGenshin($browser, 'leaving@example.test');

    $admin = new Browser();
    $admin->signIn('admin@example.test', 'correct horse');
    same(200, $admin->put("/api/admin/users/{$victimId}/enabled", ['enabled' => false])->status, 'disabled');

    same(401, $browser->get('/api/account')->status, 'the session is gone');
    same('invalid_grant', refreshWith($browser, $tokens['refresh_token'])->json()['error'] ?? null, 'refresh refused');
    same('account_disabled', (new Browser())->signIn('leaving@example.test', 'correct horse')->code(), 'cannot sign in');
});

test('rotating the key keeps tokens signed by the old one verifiable', function () {
    $browser = new Browser();
    $before = signInToGenshin($browser, 'player@example.test');

    $admin = new Browser();
    $admin->signIn('admin@example.test', 'correct horse');
    same(200, $admin->post('/api/admin/keys/rotate')->status, 'rotated');

    same(2, count($browser->get('/.well-known/jwks.json')->json()['keys']), 'both keys published');
    check(verifyLikeAnApp($browser, $before['access_token'], 'genshin') !== null, 'the old token still verifies');
    check(verifyLikeAnApp($browser, refreshWith($browser, $before['refresh_token'])->json()['access_token'], 'genshin') !== null, 'and a new one does');
});

test('the login switch shuts out everybody but IAM admins', function () {
    iamDb()->exec("UPDATE settings SET value = 'false' WHERE name = 'login_enabled'");
    settingsAll(true);

    same('login_disabled', (new Browser('10.0.3.1'))->signIn('player@example.test', 'correct horse')->code(), 'a player');
    same(200, (new Browser('10.0.3.2'))->signIn('admin@example.test', 'correct horse')->status, 'an admin');

    iamDb()->exec("UPDATE settings SET value = 'true' WHERE name = 'login_enabled'");
    settingsAll(true);
});

test('an admin-set password forces a change before any application gets a code', function () {
    $userId = makeUser('handed', 'handed@example.test');
    $admin = new Browser();
    $admin->signIn('admin@example.test', 'correct horse');
    same(200, $admin->put("/api/admin/users/{$userId}/password", ['password' => 'temporary pass'])->status, 'set');

    $browser = new Browser();
    $start = authorize($browser);
    $request = queryOf($start['location'])['request'];
    same(200, $browser->signIn('handed@example.test', 'temporary pass')->status, 'can sign in');
    same('password_change_required', $browser->post("/api/login/request/{$request}/accept")->code(), 'but not continue');

    same(200, $browser->put('/api/account/password', ['current_password' => 'temporary pass', 'new_password' => 'my own password'])->status, 'changed');
    same(200, $browser->post("/api/login/request/{$request}/accept")->status, 'now it continues');
});

test('housekeeping sweeps dead requests, codes and old spent tokens, and nothing live', function () {
    $pdo = iamDb();
    $browser = new Browser();
    $tokens = signInToGenshin($browser, 'player@example.test');

    $application = (int) $pdo->query("SELECT id FROM applications WHERE client_id = 'genshin'")->fetchColumn();
    $pdo->prepare("INSERT INTO auth_requests (id, application_id, expires_at) VALUES (?, ?, CURRENT_TIMESTAMP - INTERVAL '2 days')")
        ->execute([str_repeat('d', 32), $application]);
    $pdo->exec("UPDATE refresh_tokens SET used_at = CURRENT_TIMESTAMP - INTERVAL '40 days' WHERE id = (SELECT min(id) FROM refresh_tokens)");
    $oldest = (int) $pdo->query('SELECT min(id) FROM refresh_tokens')->fetchColumn();

    @unlink(iamStorageDir() . '/cache/housekeeping.stamp');
    iamHousekeeping();

    same(false, (bool) $pdo->query("SELECT 1 FROM auth_requests WHERE id = '" . str_repeat('d', 32) . "'")->fetchColumn(), 'the dead request is gone');
    same(false, (bool) $pdo->query("SELECT 1 FROM refresh_tokens WHERE id = $oldest")->fetchColumn(), 'the month-old spent token is gone');
    same(200, refreshWith($browser, $tokens['refresh_token'])->status, 'a live token still refreshes');
});

test('personal account data never leaks: no password hash or TOTP secret in any answer', function () {
    $admin = new Browser();
    $admin->signIn('admin@example.test', 'correct horse');
    $everything = $admin->get('/api/admin/users')->body . $admin->get('/api/admin/users/' . $GLOBALS['playerId'] . '/detail')->body . $admin->get('/api/account')->body;
    check(!str_contains($everything, '$2y$'), 'no bcrypt hash');
    check(!str_contains($everything, 'totp_secret'), 'no TOTP secret field');
});

finishTests();
