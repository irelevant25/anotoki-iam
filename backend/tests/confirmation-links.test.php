<?php

/**
 * Confirmation links, and what a sign-in says it checked (the security review
 * of 2026-10-19, its Medium and its Low 3; migration 026).
 *
 * The Medium: a confirmation link left over - "send it again" mails a second,
 * and the first is never opened - stayed good for its 48 hours after the
 * address was confirmed by the other, and opened a session by the mailbox
 * alone: on an account that had switched two-factor on meanwhile too, without
 * the code - a session of thirty days that said `mfa`, and ID tokens whose
 * `amr` said email, otp, mfa. Now every confirmation link of an account is
 * spent once its address is confirmed - by a link, a reset, Google, an
 * administrator - and when two-factor is switched on; a link of an address
 * confirmed already opens no session, saying the address is confirmed; a link
 * that would sign in an account with two-factor on asks for its code, as a
 * reset does, under the authenticator's pause; and a session says `mfa` only
 * where its own sign-in checked a second factor, on every way in.
 *
 * The Low 3: the confirmation page posted the link as soon as it opened, so
 * somebody else's link - an attacker's own new account's, sent to a victim -
 * signed the victim's browser in to it, ending the session it had, and a
 * sign-in a site had waiting there then got a code for the attacker's account.
 * Now the server never puts a confirmed account's session in place of another
 * account's this browser has: it confirms the address and says so (and the
 * page asks for a press first: frontend confirm-email.component.spec.ts).
 *
 * And the review of that change (d0e34fb): "only while the address waits" is
 * decided in the write that confirms it, so a link whose request read the
 * account a moment before something else confirmed it opens nothing; a link
 * notes the address it went to, so one of an account an administrator moved
 * elsewhere says nothing of it; a session the hole opened already ends with
 * 026, rather than saying `mfa` for its thirty days.
 */

declare(strict_types=1);

require __DIR__ . '/lib.php';

// This run's own database is a fresh IAM, built from every migration, 026 included.
bootTests();

const PASSWORD = 'correct horse';
const SIGN_UP_PASSWORD = 'a long password';
const GOOGLE_TEST_CLIENT = 'iam-test.apps.googleusercontent.com';
const GENSHIN_CALLBACK = 'http://localhost:4200/auth/callback';
const GENSHIN_ORIGIN = 'http://localhost:4200';
const LINKS_MIGRATION = '026_confirmation_links_and_google_tokens.sql';
const BEFORE_LINKS = '025_usernames_letters_and_digits.sql';
/** The confirmation page's new keys and their words: key => [English, Slovak]. */
const CONFIRM_WORDS = [
    'confirm.readyText' => ['Press the button to confirm your email address.', 'Stlač tlačidlo a potvrď svoju e-mailovú adresu.'],
    'confirm.submit' => ['Confirm my address', 'Potvrdiť adresu'],
    'confirm.totpText' => [
        'Two-factor authentication is on for this account, so signing in with this link needs it too: the code your authenticator app is showing, or one of your recovery codes.',
        'Tento účet má zapnuté dvojfaktorové overenie, a to platí aj pri prihlásení týmto odkazom: zadaj kód, ktorý ukazuje tvoja overovacia aplikácia, alebo jeden z obnovovacích kódov.',
    ],
    'confirm.signInText' => ['Sign in to use your account.', 'Na používanie účtu sa prihlás.'],
    'confirm.otherAccountText' => [
        'This browser is signed in to another account, and stays so. To use the account whose address you have just confirmed, sign out and sign in to it.',
        'Tento prehliadač je prihlásený k inému účtu a tak aj ostane. Ak chceš používať účet s práve potvrdenou adresou, odhlás sa a prihlás sa doň.',
    ],
    'confirm.signOutAndIn' => ['Sign out and sign in', 'Odhlásiť sa a prihlásiť'],
    'confirm.signedInAs' => ['Signed in as {name} ({email}).', 'Prihlásený účet: {name} ({email}).'],
];
/** The one of them with placeholders, and which. */
const CONFIRM_PLACEHOLDERS = ['confirm.signedInAs' => ['{name}', '{email}']];

/** Registers with the form, from a browser of its own address. */
function register(string $ip, string $username, ?string $request = null): TestResponse
{
    return (new Browser($ip))->post(
        '/api/register',
        ['username' => $username, 'email' => "{$username}@example.test", 'password' => SIGN_UP_PASSWORD, 'language' => 'en'] + ($request !== null ? ['request' => $request] : []),
    );
}

/** Every confirmation link mailed to an address, oldest first: their tokens. */
function linksTo(string $email): array
{
    $files = glob(testEnvironment()['mail'] . DIRECTORY_SEPARATOR . '*.eml') ?: [];
    sort($files);
    $links = [];
    foreach ($files as $file) {
        $text = (string) file_get_contents($file);
        if (str_contains($text, "To: $email\n") && preg_match('#/confirm-email\?token=([0-9a-f]{64})#', $text, $found) === 1) {
            $links[] = $found[1];
        }
    }

    return $links;
}

/** The newest link of a kind mailed to an address: confirm-email or reset-password. */
function newestLink(string $email, string $page): string
{
    preg_match('#/' . $page . '\?token=([0-9a-f]{64})#', (string) (lastMailTo($email)['body'] ?? ''), $found);
    check(isset($found[1]), "a {$page} link was mailed to {$email}");

    return $found[1];
}

/** A mailed link's state: live, spent, or none (no such link). */
function linkState(string $token): string
{
    $statement = iamDb()->prepare("SELECT CASE WHEN consumed_at IS NULL AND expires_at > CURRENT_TIMESTAMP THEN 'live' ELSE 'spent' END FROM user_tokens WHERE token_hash = ?");
    $statement->execute([sha256($token)]);
    $state = $statement->fetchColumn();

    return $state === false ? 'none' : (string) $state;
}

function userIdOf(string $email): int
{
    return (int) (userByEmail(iamDb(), $email)['id'] ?? 0);
}

function confirmedNow(int $userId): bool
{
    return asBool(userById(iamDb(), $userId)['email_confirmed'] ?? false);
}

/** How many sessions an account has had, and how many of them are live. */
function sessionsOf(int $userId): array
{
    $statement = iamDb()->prepare('SELECT count(*), count(*) FILTER (WHERE revoked_at IS NULL) FROM sessions WHERE user_id = ?');
    $statement->execute([$userId]);

    return array_map('intval', $statement->fetch(PDO::FETCH_NUM));
}

/** The newest session of an account: [its method, whether two factors]. */
function newestSession(int $userId): array
{
    $statement = iamDb()->prepare('SELECT method, mfa FROM sessions WHERE user_id = ? ORDER BY id DESC LIMIT 1');
    $statement->execute([$userId]);
    $row = $statement->fetch();

    return [$row['method'] ?? null, asBool($row['mfa'] ?? false)];
}

/** Two-factor switched on from the account page, as its owner does it: the secret. */
function switchTwoFactorOn(Browser $browser, string $password): string
{
    $secret = (string) ($browser->post('/api/account/2fa/setup')->json()['secret'] ?? '');
    check($secret !== '', 'a secret to scan');
    $on = $browser->post('/api/account/2fa/enable', ['code' => totpCode($secret, intdiv(time(), 30)), 'password' => $password]);
    same(200, $on->status, 'two-factor on: ' . $on->body);

    return $secret;
}

/** Two-factor on, as an account brought over from the genshin site has it: [the secret, its recovery codes]. */
function twoFactorOn(int $userId): array
{
    $secret = totpSecret();
    iamDb()
        ->prepare('UPDATE users SET totp_enabled = TRUE, totp_secret = ? WHERE id = ?')
        ->execute([encryptSecret($secret), $userId]);

    return [$secret, generateRecoveryCodes(iamDb(), $userId)];
}

/** The account's code now, as its app shows it - with what was accepted before forgotten, so it is good once more. */
function rightCode(int $userId, string $secret): string
{
    iamDb()
        ->prepare('UPDATE users SET totp_last_step = NULL WHERE id = ?')
        ->execute([$userId]);

    return totpCode($secret, intdiv(time(), 30));
}

/** Six digits no step around now has for this secret. */
function wrongCode(string $secret, int $seed = 0): string
{
    $counter = intdiv(time(), 30);
    $valid = [totpCode($secret, $counter - 1), totpCode($secret, $counter), totpCode($secret, $counter + 1), totpCode($secret, $counter + 2)];
    for ($n = $seed; ; $n++) {
        $code = str_pad((string) (($n * 7919 + 104729) % 1000000), 6, '0', STR_PAD_LEFT);
        if (!in_array($code, $valid, true)) {
            return $code;
        }
    }
}

/** A site's sign-in begun at the authorize endpoint: [the pending request's id, the PKCE verifier]. */
function authorize(Browser $browser): array
{
    [$verifier, $challenge] = pkce();
    $start = $browser->get(
        '/api/oidc/authorize?' .
            http_build_query([
                'response_type' => 'code',
                'client_id' => 'genshin',
                'redirect_uri' => GENSHIN_CALLBACK,
                'scope' => 'openid profile email',
                'state' => bin2hex(random_bytes(8)),
                'nonce' => bin2hex(random_bytes(8)),
                'code_challenge' => $challenge,
                'code_challenge_method' => 'S256',
            ]),
    );
    $request = queryOf($start->location())['request'] ?? null;
    check(is_string($request), 'authorize sends the browser to the sign-in page: ' . $start->location());

    return [$request, $verifier];
}

/** The pending request accepted and its code exchanged, as the site would: the ID token's claims. */
function idTokenAfter(Browser $browser, string $request, string $verifier): array
{
    $accept = $browser->post("/api/login/request/{$request}/accept");
    same(200, $accept->status, 'accepted: ' . $accept->body);
    $tokens = $browser->request('POST', '/api/oidc/token', [
        'form' => [
            'grant_type' => 'authorization_code',
            'code' => queryOf((string) ($accept->json()['redirect'] ?? ''))['code'] ?? '',
            'redirect_uri' => GENSHIN_CALLBACK,
            'client_id' => 'genshin',
            'code_verifier' => $verifier,
        ],
        'headers' => ['Origin' => GENSHIN_ORIGIN],
    ]);
    same(200, $tokens->status, 'the code exchanged: ' . $tokens->body);
    $claims = json_decode(base64UrlDecode(explode('.', (string) ($tokens->json()['id_token'] ?? ''))[1] ?? ''), true);

    return is_array($claims) ? $claims : [];
}

/**
 * A site's sign-in in a browser of its own: authorize for genshin, $signIn
 * (handed the browser and the request's id, answering 200), the request
 * accepted and its code exchanged - the ID token's `amr`.
 */
function amrAfter(string $ip, callable $signIn): array
{
    $browser = new Browser($ip);
    [$request, $verifier] = authorize($browser);
    $answer = $signIn($browser, $request);
    same(200, $answer->status, 'signed in: ' . $answer->body);

    return idTokenAfter($browser, $request, $verifier)['amr'] ?? [];
}

/** The emailed code's sign-in, asked for and read from its mail. */
function mailedSignInCode(string $email, string $ip): string
{
    same(200, (new Browser($ip))->post('/api/login/code', ['email' => $email])->status, 'a code asked for');
    preg_match('/sign-in code: (\d{6})$/m', (string) (lastMailTo($email)['subject'] ?? ''), $found);
    check(isset($found[1]), "a sign-in code was mailed to {$email}");

    return $found[1];
}

/** A Google id token for this IAM, as Google makes them - a `jti` of its own, so no two are alike - signed by a key it is given as Google's. */
function googleToken(string $subject, string $email): string
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
                'keys' => ['links-test' => ['kty' => 'RSA', 'kid' => 'links-test', 'n' => $pair['n'], 'e' => $pair['e']]],
            ]),
        );
        writeTestConfig('google', ['client_id' => GOOGLE_TEST_CLIENT]);
        forgetLocalConfig();
    }

    $header = base64UrlEncode((string) json_encode(['alg' => 'RS256', 'kid' => 'links-test', 'typ' => 'JWT']));
    $payload = base64UrlEncode(
        (string) json_encode([
            'iss' => 'https://accounts.google.com',
            'aud' => GOOGLE_TEST_CLIENT,
            'sub' => $subject,
            'email' => $email,
            'email_verified' => true,
            'name' => $subject,
            'iat' => time(),
            'exp' => time() + 3600,
            'jti' => bin2hex(random_bytes(16)),
        ]),
    );
    openssl_sign("{$header}.{$payload}", $signature, $key, OPENSSL_ALGO_SHA256);

    return "{$header}.{$payload}." . base64UrlEncode($signature);
}

/** An administrator of the IAM, signed in. */
function admin(): Browser
{
    static $made = false;
    if (!$made) {
        grantRole(makeUser('admin', 'admin@example.test'), 'iam', 'ADMIN');
        $made = true;
    }
    $browser = new Browser('10.26.0.1');
    same(200, $browser->signIn('admin@example.test', PASSWORD)->status, 'the admin signs in');

    return $browser;
}

/** A key as a database has it: [description, [English, its writer], [Slovak, its writer]]; null where there is none. */
function keyStrings(PDO $pdo, string $key): array
{
    $description = $pdo->prepare('SELECT description FROM translation_keys WHERE name = ?');
    $description->execute([$key]);
    $found = [$description->fetchColumn() ?: null];
    foreach (['en', 'sk'] as $code) {
        $row = $pdo->prepare('SELECT value, updated_by FROM translations WHERE key_name = ? AND language_code = ?');
        $row->execute([$key, $code]);
        $string = $row->fetch(PDO::FETCH_NUM);
        $found[] = $string === false ? null : [$string[0], $string[1] === null ? null : (int) $string[1]];
    }

    return $found;
}

/** The six keys as a database has them - to hold one database to another. */
function confirmStrings(PDO $pdo): array
{
    $strings = [];
    foreach (array_keys(CONFIRM_WORDS) as $key) {
        $strings[$key] = keyStrings($pdo, $key);
    }

    return $strings;
}

function buildThrough025(string $suffix): PDO
{
    $pdo = buildIamThrough($suffix, BEFORE_LINKS);
    same(LINKS_MIGRATION, pendingMigrations($pdo)[0] ?? null, 'built through 025, 026 the first pending');

    return $pdo;
}

// ── The Medium ───────────────────────────────────────────────────────────────

test(
    'the attack, as the review made it: a second link mailed by "send it again" confirms the address; two-factor goes on; the first link, opened in another browser by whoever holds the mailbox, opens no session - with the code neither - and no site gets a code',
    function () {
        $email = 'leftover@example.test';
        same(201, register('10.26.1.1', 'leftover')->status, 'registered');
        same(200, (new Browser('10.26.1.2'))->post('/api/register/resend', ['email' => $email])->status, 'send it again');
        $links = linksTo($email);
        same(2, count($links), 'two links mailed');
        [$first, $second] = $links;
        $id = userIdOf($email);

        $owner = new Browser('10.26.1.3');
        $confirmed = $owner->post('/api/register/confirm', ['token' => $second]);
        same(200, $confirmed->status, 'the second link confirms the address: ' . $confirmed->body);
        check(isset($owner->cookies['anotoki_iam']), 'and signs its owner in');

        $secret = switchTwoFactorOn($owner, SIGN_UP_PASSWORD);
        same([403, 'totp_required'], [($r = (new Browser('10.26.1.4'))->signIn($email, SIGN_UP_PASSWORD))->status, $r->code()], 'a password sign-in asks for the code now');
        [$sessions, $live] = sessionsOf($id);

        // Whoever holds the mailbox, from another browser, a site's sign-in waiting there.
        $mailbox = new Browser('10.26.1.5');
        [$request] = authorize($mailbox);
        $answer = $mailbox->post('/api/register/confirm', ['token' => $first]);
        same([$sessions, $live], sessionsOf($id), 'no session opened - the newest is ' . json_encode(newestSession($id)) . ', the answer ' . $answer->body);
        same([], array_values(array_intersect(array_keys($mailbox->cookies), ['anotoki_iam', 'anotoki_signed_in', 'anotoki_iam_device'])), 'no session cookie, no sign-in hint, no device cookie');
        same([401, 'no_session'], [($r = $mailbox->post("/api/login/request/{$request}/accept"))->status, $r->code()], 'so the site\'s sign-in gets no code');
        same(
            [200, false, 'already_confirmed'],
            [$answer->status, $answer->json()['signed_in'] ?? null, $answer->json()['reason'] ?? null],
            'the address is confirmed already: said so, and nothing more',
        );
        $fields = array_keys($answer->json());
        sort($fields);
        same(['reason', 'signed_in'], $fields, 'no account in the answer');

        $withCode = $mailbox->post('/api/register/confirm', ['token' => $first, 'totp' => rightCode($id, $secret)]);
        same([200, false], [$withCode->status, $withCode->json()['signed_in'] ?? null], 'nor with the code: the link signs nobody in to an address confirmed');
        same([$sessions, $live], sessionsOf($id), 'still no session');

        // The first link was spent the moment the second confirmed the address.
        same(['spent', 'spent'], [linkState($second), linkState($first)], 'both links spent');
    },
);

test(
    'a link confirmed and opened again - in the same browser or another - says the address is confirmed and opens no second session; a link never mailed, or of an address not confirmed now, did not work',
    function () {
        $email = 'twice@example.test';
        same(201, register('10.26.2.1', 'twice')->status, 'registered');
        [$link] = linksTo($email);
        $id = userIdOf($email);

        $browser = new Browser('10.26.2.2');
        same(200, $browser->post('/api/register/confirm', ['token' => $link])->status, 'confirmed');
        check(isset($browser->cookies['anotoki_iam']), 'and signed in');
        $cookies = $browser->cookies;
        same([1, 1], sessionsOf($id), 'one session');

        $again = $browser->post('/api/register/confirm', ['token' => $link]);
        same([200, false, 'already_confirmed'], [$again->status, $again->json()['signed_in'] ?? null, $again->json()['reason'] ?? null], 'the same browser: the address is confirmed');
        same($cookies, $browser->cookies, 'its session left as it was');
        $elsewhere = new Browser('10.26.2.3');
        same(
            [200, false, 'already_confirmed'],
            [($r = $elsewhere->post('/api/register/confirm', ['token' => $link]))->status, $r->json()['signed_in'] ?? null, $r->json()['reason'] ?? null],
            'another browser: the same',
        );
        same([], $elsewhere->cookies, 'and no session there');
        same([1, 1], sessionsOf($id), 'one session, as before');

        same([400, 'invalid_token'], [($r = $elsewhere->post('/api/register/confirm', ['token' => str_repeat('ab', 32)]))->status, $r->code()], 'a link never mailed: did not work');

        // An administrator asks for the address to be confirmed again: the old link does not do it.
        $admin = admin();
        same(200, $admin->put("/api/admin/users/{$id}", ['email_confirmed' => false])->status, 'the address to be confirmed again');
        same([400, 'invalid_token'], [($r = $elsewhere->post('/api/register/confirm', ['token' => $link]))->status, $r->code()], 'the spent link of an address not confirmed now: did not work');
        same(false, confirmedNow($id), 'and confirmed nothing');
    },
);

test(
    'every way an address is confirmed spends its other links - a reset, Google, an administrator -, an administrator changing the address spends them, and so does two-factor switched on',
    function () {
        // A reset link reaching the mailbox of an account never confirmed (which it claims).
        $email = 'byreset@example.test';
        same(201, register('10.26.3.1', 'byreset')->status, 'registered');
        (new Browser('10.26.3.2'))->post('/api/register/resend', ['email' => $email]);
        same(200, (new Browser('10.26.3.3'))->post('/api/password/forgot', ['email' => $email])->status, 'a reset asked for');
        same(200, (new Browser('10.26.3.4'))->post('/api/password/reset', ['token' => newestLink($email, 'reset-password'), 'password' => 'the owner\'s own'])->status, 'the reset');
        same(['spent', 'spent'], array_map('linkState', linksTo($email)), 'a reset: both confirmation links spent');
        foreach (linksTo($email) as $link) {
            same([200, false], [($r = (new Browser('10.26.3.5'))->post('/api/register/confirm', ['token' => $link]))->status, $r->json()['signed_in'] ?? null], 'and neither signs in');
        }

        // Google vouching for the address.
        $email = 'bygoogle@example.test';
        same(201, register('10.26.3.6', 'bygoogle')->status, 'registered');
        (new Browser('10.26.3.7'))->post('/api/register/resend', ['email' => $email]);
        same(200, (new Browser('10.26.3.8'))->post('/api/login/google', ['credential' => googleToken('google-bygoogle', $email)])->status, 'Google, claiming the address');
        same(['spent', 'spent'], array_map('linkState', linksTo($email)), 'Google: both spent');

        // An administrator confirming it in the panel.
        $admin = admin();
        $email = 'bypanel@example.test';
        same(201, register('10.26.3.9', 'bypanel')->status, 'registered');
        same(200, $admin->put('/api/admin/users/' . userIdOf($email), ['email_confirmed' => true])->status, 'confirmed by an administrator');
        same(['spent'], array_map('linkState', linksTo($email)), 'the panel: spent');

        // An administrator changing the address: the links went to the old one.
        same(201, register('10.26.3.10', 'moved')->status, 'registered');
        $movedId = userIdOf('moved@example.test');
        same(200, $admin->put("/api/admin/users/{$movedId}", ['email' => 'moved-elsewhere@example.test'])->status, 'the address changed');
        same(['spent'], array_map('linkState', linksTo('moved@example.test')), 'the link to the old address spent');
        same([400, 'invalid_token'], [($r = (new Browser('10.26.3.11'))->post('/api/register/confirm', ['token' => linksTo('moved@example.test')[0]]))->status, $r->code()], 'it confirms nothing');
        same(false, confirmedNow($movedId), 'the new address unconfirmed');

        // An administrator's save that changes neither - another field, the address in other letters, the same answer - leaves the link.
        same(201, register('10.26.3.13', 'untouched')->status, 'registered');
        $untouchedId = userIdOf('untouched@example.test');
        same(200, $admin->put("/api/admin/users/{$untouchedId}", ['language' => 'sk'])->status, 'its language changed');
        same(200, $admin->put("/api/admin/users/{$untouchedId}", ['email' => 'Untouched@Example.test', 'email_confirmed' => false])->status, 'its address and its answer sent as they are');
        same(['live'], array_map('linkState', linksTo('untouched@example.test')), 'the link still waits');
        same(200, (new Browser('10.26.3.14'))->post('/api/register/confirm', ['token' => linksTo('untouched@example.test')[0]])->status, 'and confirms the address');

        // Two-factor switched on: a link an older build left live on a confirmed account is spent.
        $olderId = makeUser('older', 'older@example.test');
        $leftover = issueOneTimeToken(iamDb(), $olderId, TOKEN_EMAIL_CONFIRM, 48 * 3600);
        check(is_string($leftover) && linkState($leftover) === 'live', 'a live link from before');
        $older = new Browser('10.26.3.12');
        same(200, $older->signIn('older@example.test', PASSWORD)->status, 'signed in');
        switchTwoFactorOn($older, PASSWORD);
        same('spent', linkState($leftover), 'two-factor on: the link spent');
    },
);

test('a live link an older build left on an address confirmed already opens no session - with two-factor on too - and is spent by being opened', function () {
    $id = makeUser('livelink', 'livelink@example.test');
    twoFactorOn($id);
    $link = issueOneTimeToken(iamDb(), $id, TOKEN_EMAIL_CONFIRM, 48 * 3600);
    check(is_string($link), 'a live link');

    $browser = new Browser('10.26.4.1');
    $answer = $browser->post('/api/register/confirm', ['token' => $link]);
    same([[0, 0], []], [sessionsOf($id), $browser->cookies], 'no session, no cookie - the answer ' . $answer->body);
    same([200, false, 'already_confirmed'], [$answer->status, $answer->json()['signed_in'] ?? null, $answer->json()['reason'] ?? null], 'the address is confirmed already');
    same('spent', linkState($link), 'and the link is spent');
});

test(
    'a link that would sign in an account with two-factor on asks for its code, as a reset does - nothing spent or confirmed before it, every wrong one counted, five pausing the account\'s codes, a recovery code standing in - and its session says mfa',
    function () {
        // An account the genshin site's import brought over: two-factor on, its address never confirmed.
        $email = 'imported@example.test';
        $id = makeUser('imported', $email, PASSWORD, false);
        [$secret, $recovery] = twoFactorOn($id);
        same(200, (new Browser('10.26.5.1'))->post('/api/register/resend', ['email' => $email])->status, 'a link asked for');
        $link = newestLink($email, 'confirm-email');

        $browser = new Browser('10.26.5.2');
        same([403, 'totp_required'], [($r = $browser->post('/api/register/confirm', ['token' => $link]))->status, $r->code()], 'the code asked for');
        same(['live', false, [0, 0]], [linkState($link), confirmedNow($id), sessionsOf($id)], 'nothing spent, confirmed or opened');

        for ($guess = 1; $guess < AUTHENTICATOR_PAUSE_AFTER; $guess++) {
            $wrong = (new Browser("10.26.5.1{$guess}"))->post('/api/register/confirm', ['token' => $link, 'totp' => wrongCode($secret, $guess)]);
            same([403, 'totp_invalid'], [$wrong->status, $wrong->code()], "wrong code {$guess}");
        }
        $fifth = (new Browser('10.26.5.19'))->post('/api/register/confirm', ['token' => $link, 'totp' => wrongCode($secret, 9)]);
        same([403, 'authenticator_paused'], [$fifth->status, $fifth->code()], 'the fifth pauses the account\'s codes, as on every way in');
        $counted = iamDb()->prepare("SELECT count(*) FROM login_attempts WHERE user_id = ? AND outcome = 'bad_totp' AND method = ?");
        $counted->execute([$id, ATTEMPT_METHOD_EMAIL_CONFIRM]);
        same(AUTHENTICATOR_PAUSE_AFTER, (int) $counted->fetchColumn(), 'five wrong codes counted, under the link\'s own method');
        same(
            [403, 'authenticator_paused'],
            [($r = $browser->post('/api/register/confirm', ['token' => $link, 'totp' => rightCode($id, $secret)]))->status, $r->code()],
            'the right code refused unchecked during the pause',
        );
        same(['live', false], [linkState($link), confirmedNow($id)], 'the link still waiting, the address not confirmed');

        $signedIn = $browser->post('/api/register/confirm', ['token' => $link, 'totp' => $recovery[0]]);
        same([200, true], [$signedIn->status, $signedIn->json()['signed_in'] ?? null], 'a recovery code stands in: signed in - ' . $signedIn->body);
        same(['email_link', true], newestSession($id), 'a session of two factors: the link and the code');
        same(['spent', true], [linkState($link), confirmedNow($id)], 'the link spent, the address confirmed');
    },
);

test('mfa and amr say what the sign-in itself checked, on every way in - never that the account has two-factor on', function () {
    $off = static fn(string $name): int => makeUser($name, "{$name}@example.test");

    // The password.
    $off('pwd');
    same(['pwd'], amrAfter('10.26.6.1', fn(Browser $b) => $b->signIn('pwd@example.test', PASSWORD)), 'the password alone');
    $pwdTwo = $off('pwdtwo');
    [$pwdSecret] = twoFactorOn($pwdTwo);
    same(
        ['pwd', 'otp', 'mfa'],
        amrAfter('10.26.6.2', fn(Browser $b) => $b->signIn('pwdtwo@example.test', PASSWORD, ['totp' => rightCode($pwdTwo, $pwdSecret), 'remember_device' => true])),
        'the password and a code',
    );
    $device = (string) ((new Browser('10.26.6.3'))->signIn('pwdtwo@example.test', PASSWORD, ['totp' => rightCode($pwdTwo, $pwdSecret), 'remember_device' => true])->json()['device_token'] ?? '');
    check($device !== '', 'a remembered browser');
    same(
        ['pwd', 'otp', 'mfa'],
        amrAfter('10.26.6.4', fn(Browser $b) => $b->signIn('pwdtwo@example.test', PASSWORD, ['device_token' => $device])),
        'the password and a browser remembered for its code',
    );

    // The emailed code.
    $off('code');
    same(
        ['email'],
        amrAfter('10.26.6.5', fn(Browser $b) => $b->post('/api/login/code/verify', ['email' => 'code@example.test', 'code' => mailedSignInCode('code@example.test', '10.26.6.6')])),
        'an emailed code alone',
    );
    $codeTwo = $off('codetwo');
    [$codeSecret] = twoFactorOn($codeTwo);
    same(
        ['email', 'otp', 'mfa'],
        amrAfter(
            '10.26.6.7',
            fn(Browser $b) => $b->post('/api/login/code/verify', [
                'email' => 'codetwo@example.test',
                'code' => mailedSignInCode('codetwo@example.test', '10.26.6.8'),
                'totp' => rightCode($codeTwo, $codeSecret),
            ]),
        ),
        'an emailed code and an authenticator code',
    );

    // Google.
    $googleId = $off('googler');
    attachIdentity(iamDb(), $googleId, IDENTITY_GOOGLE, 'google-googler', 'googler@example.test');
    same(['google'], amrAfter('10.26.6.9', fn(Browser $b) => $b->post('/api/login/google', ['credential' => googleToken('google-googler', 'googler@example.test')])), 'Google alone');
    [$googleSecret] = twoFactorOn($googleId);
    same(
        ['google', 'otp', 'mfa'],
        amrAfter('10.26.6.10', fn(Browser $b) => $b->post('/api/login/google', ['credential' => googleToken('google-googler', 'googler@example.test'), 'totp' => rightCode($googleId, $googleSecret)])),
        'Google and a code',
    );

    // A reset link.
    $off('resetter');
    same(
        ['email'],
        amrAfter('10.26.6.11', function (Browser $b) {
            $b->post('/api/password/forgot', ['email' => 'resetter@example.test']);
            return $b->post('/api/password/reset', ['token' => newestLink('resetter@example.test', 'reset-password'), 'password' => 'a new password']);
        }),
        'a reset link alone',
    );
    $resetTwo = $off('resettwo');
    [$resetSecret] = twoFactorOn($resetTwo);
    same(
        ['email', 'otp', 'mfa'],
        amrAfter('10.26.6.12', function (Browser $b) use ($resetTwo, $resetSecret) {
            $b->post('/api/password/forgot', ['email' => 'resettwo@example.test']);
            return $b->post('/api/password/reset', ['token' => newestLink('resettwo@example.test', 'reset-password'), 'password' => 'a new password', 'totp' => rightCode($resetTwo, $resetSecret)]);
        }),
        'a reset link and a code',
    );

    // A confirmation link: a new account's; one with two-factor on, from the import.
    same(
        ['email'],
        amrAfter('10.26.6.13', function (Browser $b, string $request) {
            same(201, register('10.26.6.14', 'newcomer', $request)->status, 'registered from the site');
            return $b->post('/api/register/confirm', ['token' => newestLink('newcomer@example.test', 'confirm-email')]);
        }),
        'a confirmation link alone',
    );
    $confirmTwo = makeUser('confirmtwo', 'confirmtwo@example.test', PASSWORD, false);
    [$confirmSecret] = twoFactorOn($confirmTwo);
    same(
        ['email', 'otp', 'mfa'],
        amrAfter('10.26.6.15', function (Browser $b) use ($confirmTwo, $confirmSecret) {
            $b->post('/api/register/resend', ['email' => 'confirmtwo@example.test']);
            return $b->post('/api/register/confirm', ['token' => newestLink('confirmtwo@example.test', 'confirm-email'), 'totp' => rightCode($confirmTwo, $confirmSecret)]);
        }),
        'a confirmation link and a code',
    );

    // An address's owner claiming an account somebody registered with it and switched two-factor on for: nothing of theirs is checked, or kept.
    $claimedId = makeUser('squatted', 'squatted@example.test', PASSWORD, false);
    twoFactorOn($claimedId);
    same(
        ['google'],
        amrAfter('10.26.6.16', fn(Browser $b) => $b->post('/api/login/google', ['credential' => googleToken('google-owner', 'squatted@example.test')])),
        'Google claiming it: the registrant\'s two-factor is gone, and nothing says it was asked',
    );
    same(['google', false], newestSession($claimedId), 'its session: Google\'s, of one factor');
});

// ── The Low 3 ────────────────────────────────────────────────────────────────

test(
    'the attack: another account\'s link opened in a browser signed in confirms that address and leaves the session in place - so the sign-in a site has waiting there gets a code for the account signed in, never the link\'s',
    function () {
        $victimId = makeUser('victim', 'victim@example.test');
        $victim = new Browser('10.26.7.1');
        [$request, $verifier] = authorize($victim);
        same(200, $victim->signIn('victim@example.test', PASSWORD)->status, 'the victim signs in');
        $cookies = $victim->cookies;
        [, $victimLive] = sessionsOf($victimId);

        // The attacker's own new account, its link sent to the victim.
        same(201, register('10.26.7.2', 'attacker')->status, 'the attacker registers');
        $attackerId = userIdOf('attacker@example.test');
        $answer = $victim->post('/api/register/confirm', ['token' => newestLink('attacker@example.test', 'confirm-email')]);
        same($cookies, $victim->cookies, 'the victim\'s cookies as they were - the answer ' . $answer->body);
        same([$victimLive], [sessionsOf($victimId)[1]], 'the victim\'s session live');
        same($victimId, (int) ($victim->get('/api/account')->json()['id'] ?? 0), 'the browser still the victim\'s');
        same([true, [0, 0]], [confirmedNow($attackerId), sessionsOf($attackerId)], 'the attacker\'s address confirmed, no session of it anywhere');
        same(
            [200, false, 'other_account'],
            [$answer->status, $answer->json()['signed_in'] ?? null, $answer->json()['reason'] ?? null],
            'the link says the address is confirmed, and this browser stays signed in to the other account',
        );

        $claims = idTokenAfter($victim, $request, $verifier);
        same((string) $victimId, $claims['sub'] ?? null, 'the site\'s waiting sign-in: the victim\'s account');

        // With two-factor on, the attacker's account is not signed in either - no code is asked, none is opened.
        $twoId = makeUser('attackertwo', 'attackertwo@example.test', PASSWORD, false);
        twoFactorOn($twoId);
        (new Browser('10.26.7.3'))->post('/api/register/resend', ['email' => 'attackertwo@example.test']);
        same(
            [200, 'other_account'],
            [($r = $victim->post('/api/register/confirm', ['token' => newestLink('attackertwo@example.test', 'confirm-email')]))->status, $r->json()['reason'] ?? null],
            'the same with two-factor on',
        );
        same([true, $cookies], [confirmedNow($twoId), $victim->cookies], 'confirmed, and the victim still signed in');
    },
);

test('a link opened in a browser with no session signs it in, as ever; one signed in to the same account is signed in afresh', function () {
    same(201, register('10.26.8.1', 'alone')->status, 'registered');
    $browser = new Browser('10.26.8.2');
    same(200, $browser->post('/api/register/confirm', ['token' => newestLink('alone@example.test', 'confirm-email')])->status, 'no session here: confirmed');
    check(isset($browser->cookies['anotoki_iam']), 'and signed in');

    // The same account's own browser, its address to be confirmed again (an administrator asked).
    $id = userIdOf('alone@example.test');
    same(200, admin()->put("/api/admin/users/{$id}", ['email_confirmed' => false])->status, 'to be confirmed again');
    same(200, (new Browser('10.26.8.3'))->post('/api/register/resend', ['email' => 'alone@example.test'])->status, 'a new link');
    $again = $browser->post('/api/register/confirm', ['token' => newestLink('alone@example.test', 'confirm-email')]);
    same([200, true], [$again->status, $again->json()['signed_in'] ?? null], 'the same account\'s browser: signed in');
    same([2, 1], sessionsOf($id), 'its old session replaced by the new one');
});

// ── The review of d0e34fb ────────────────────────────────────────────────────

test(
    'the attack, timed: a link whose request read the account a moment before something else confirmed the address opens nothing - the write that confirms asks that the address still waits',
    function () {
        $pdo = iamDb();
        same(201, register('10.26.12.1', 'racer')->status, 'registered');
        $id = userIdOf('racer@example.test');
        $link = newestLink('racer@example.test', 'confirm-email');

        // Between the request's read and its write, the address is confirmed by something else - a reset, Google,
        // another link: here, at the moment the request spends its link.
        $pdo->exec('CREATE FUNCTION test_confirm_between() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN UPDATE users SET email_confirmed = TRUE WHERE id = NEW.user_id; RETURN NEW; END $$');
        $pdo->exec(
            "CREATE TRIGGER test_confirm_between AFTER UPDATE OF consumed_at ON user_tokens FOR EACH ROW
           WHEN (NEW.user_id = {$id} AND OLD.consumed_at IS NULL AND NEW.consumed_at IS NOT NULL) EXECUTE FUNCTION test_confirm_between()",
        );
        try {
            $browser = new Browser('10.26.12.2');
            $answer = $browser->post('/api/register/confirm', ['token' => $link]);
            same(
                [[0, 0], []],
                [sessionsOf($id), array_values(array_intersect(array_keys($browser->cookies), ['anotoki_iam', 'anotoki_signed_in', 'anotoki_iam_device']))],
                'no session, no cookie - the answer ' . $answer->body,
            );
            same([200, false, 'already_confirmed'], [$answer->status, $answer->json()['signed_in'] ?? null, $answer->json()['reason'] ?? null], 'the address confirmed meanwhile: said so');
        } finally {
            $pdo->exec('DROP TRIGGER test_confirm_between ON user_tokens');
            $pdo->exec('DROP FUNCTION test_confirm_between()');
        }
    },
);

test('two-factor switched on while a link was on its way: the address confirmed, and nobody signed in - the link never stands in for a code no request checked', function () {
    $pdo = iamDb();
    same(201, register('10.26.13.1', 'midway')->status, 'registered');
    $id = userIdOf('midway@example.test');

    // Two-factor comes on in the very write that confirms the address: the read before it saw it off.
    $pdo->exec('CREATE FUNCTION test_two_factor_with_confirm() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN NEW.totp_enabled := TRUE; RETURN NEW; END $$');
    $pdo->exec(
        "CREATE TRIGGER test_two_factor_with_confirm BEFORE UPDATE OF email_confirmed ON users FOR EACH ROW
           WHEN (NEW.id = {$id} AND NOT OLD.email_confirmed AND NEW.email_confirmed) EXECUTE FUNCTION test_two_factor_with_confirm()",
    );
    try {
        $browser = new Browser('10.26.13.2');
        $answer = $browser->post('/api/register/confirm', ['token' => newestLink('midway@example.test', 'confirm-email')]);
        same(
            [[0, 0], []],
            [sessionsOf($id), array_values(array_intersect(array_keys($browser->cookies), ['anotoki_iam', 'anotoki_signed_in', 'anotoki_iam_device']))],
            'no session, no cookie - the answer ' . $answer->body,
        );
        same([200, false, 'sign_in'], [$answer->status, $answer->json()['signed_in'] ?? null, $answer->json()['reason'] ?? null], 'confirmed; signing in takes the account\'s own way in');
        same([true, true], [confirmedNow($id), asBool(userById($pdo, $id)['totp_enabled'])], 'the address confirmed, two-factor on');
    } finally {
        $pdo->exec('DROP TRIGGER test_two_factor_with_confirm ON users');
        $pdo->exec('DROP FUNCTION test_two_factor_with_confirm()');
    }
});

test(
    'a link notes the address it went to - a mark, beside the site the registration came from; one of an account moved to another address says nothing of it, spent or live, and confirms nothing',
    function () {
        $pdo = iamDb();
        $context = static function (string $token) use ($pdo): array {
            $row = $pdo->prepare('SELECT context FROM user_tokens WHERE token_hash = ?');
            $row->execute([sha256($token)]);
            $found = json_decode((string) $row->fetchColumn(), true);
            ksort($found);

            return $found;
        };

        // Confirmed, then moved by an administrator: the old address's link says nothing of the account.
        same(201, register('10.26.14.4', 'mover')->status, 'registered');
        $moverId = userIdOf('mover@example.test');
        $old = newestLink('mover@example.test', 'confirm-email');
        same(200, (new Browser('10.26.14.5'))->post('/api/register/confirm', ['token' => $old])->status, 'confirmed by its link');
        same(200, admin()->put("/api/admin/users/{$moverId}", ['email' => 'mover-elsewhere@example.test'])->status, 'moved to another address');
        same(
            [400, 'invalid_token'],
            [($r = (new Browser('10.26.14.6'))->post('/api/register/confirm', ['token' => $old]))->status, $r->code()],
            'the old address\'s link: did not work, nothing said of the account',
        );

        // Its address written in other letters is still its address: the link says it is confirmed.
        same(201, register('10.26.14.7', 'casey')->status, 'registered');
        $caseyId = userIdOf('casey@example.test');
        $kept = newestLink('casey@example.test', 'confirm-email');
        same(200, (new Browser('10.26.14.8'))->post('/api/register/confirm', ['token' => $kept])->status, 'confirmed');
        same(200, admin()->put("/api/admin/users/{$caseyId}", ['email' => 'Casey@Example.test'])->status, 'the same address in other letters');
        same([200, 'already_confirmed'], [($r = (new Browser('10.26.14.9'))->post('/api/register/confirm', ['token' => $kept]))->status, $r->json()['reason'] ?? null], 'still said confirmed');

        // A live link whose account's address changed under it (as a race with the panel would leave it): it confirms nothing, and goes.
        same(201, register('10.26.14.10', 'drifter')->status, 'registered');
        $drifterId = userIdOf('drifter@example.test');
        $live = newestLink('drifter@example.test', 'confirm-email');
        $pdo->prepare('UPDATE users SET email = ? WHERE id = ?')->execute(['drifter-elsewhere@example.test', $drifterId]);
        $answer = (new Browser('10.26.14.11'))->post('/api/register/confirm', ['token' => $live]);
        same([400, 'invalid_token'], [$answer->status, $answer->code()], 'a live link to the old address: did not work');
        same([false, 'spent', [0, 0]], [confirmedNow($drifterId), linkState($live), sessionsOf($drifterId)], 'the new address unconfirmed, the link spent, nobody signed in');

        // Where a link went, noted: a registration from a site, and a link sent again.
        $site = new Browser('10.26.14.1');
        [$request] = authorize($site);
        same(201, register('10.26.14.2', 'Noted', $request)->status, 'registered from the site');
        same(
            ['address' => sha256('noted@example.test'), 'client_id' => 'genshin'],
            $context(newestLink('Noted@example.test', 'confirm-email')),
            'the mark of the address - lower case, hashed - and the site',
        );
        same(200, (new Browser('10.26.14.3'))->post('/api/register/resend', ['email' => 'noted@example.test'])->status, 'sent again');
        same(['address' => sha256('noted@example.test')], $context(newestLink('Noted@example.test', 'confirm-email')), 'the mark alone');
    },
);

// ── Migration 026 ────────────────────────────────────────────────────────────

test(
    'a fresh IAM has run 026: the confirmation page\'s seven keys, each described, in English and Slovak, by nobody - no address, and only the account\'s name and address for placeholders',
    function () {
        $pdo = iamDb();
        check(in_array(LINKS_MIGRATION, appliedMigrations($pdo), true), '026 recorded');
        foreach (CONFIRM_WORDS as $key => [$english, $slovak]) {
            [$description, $en, $sk] = keyStrings($pdo, $key);
            $placeholders = CONFIRM_PLACEHOLDERS[$key] ?? [];
            $said = $placeholders === [] ? 'No placeholders.' : 'Placeholders: ' . implode(', ', $placeholders) . '.';
            check(trim((string) $description) !== '' && str_contains((string) $description, $said), "$key: described, saying {$said}");
            same([[$english, null], [$slovak, null]], [$en, $sk], "$key: its English (en.ts's) and its Slovak, by nobody");
            check(preg_match('~https?:|://|www\.|@~i', $english . $slovak) !== 1, "$key: no address in it");
            foreach ([$english, $slovak] as $words) {
                preg_match_all('~\{[a-z]+\}~', $words, $found);
                same($placeholders, $found[0], "$key: its placeholders, once each, in both languages");
            }
        }
    },
);

test(
    'an IAM built through 025 with links an older build left, and sessions they opened: 026 spends every link of an address confirmed, leaves an unconfirmed one\'s live, ends the link sessions that say mfa on an account with two-factor on, and adds the keys as on a fresh IAM',
    function () {
        $pdo = buildThrough025('_before_links');
        $insert = $pdo->prepare('INSERT INTO users (username, email, password, email_confirmed, totp_enabled) VALUES (?, ?, ?, ?, ?) RETURNING id');
        $insert->execute(['confirmedone', 'confirmedone@example.test', password_hash(PASSWORD, PASSWORD_DEFAULT), 'true', 'true']);
        $confirmedId = (int) $insert->fetchColumn();
        $insert->execute(['waitingone', 'waitingone@example.test', password_hash(PASSWORD, PASSWORD_DEFAULT), 'false', 'false']);
        $waitingId = (int) $insert->fetchColumn();
        $insert->execute(['plainone', 'plainone@example.test', password_hash(PASSWORD, PASSWORD_DEFAULT), 'true', 'false']);
        $plainId = (int) $insert->fetchColumn();
        $link = $pdo->prepare("INSERT INTO user_tokens (user_id, purpose, token_hash, expires_at) VALUES (?, ?, ?, CURRENT_TIMESTAMP + INTERVAL '48 hours')");
        foreach ([[$confirmedId, 'email_confirm', 'a'], [$confirmedId, 'email_confirm', 'b'], [$waitingId, 'email_confirm', 'c'], [$confirmedId, 'password_reset', 'd']] as [$user, $purpose, $mark]) {
            $link->execute([$user, $purpose, str_repeat($mark, 64)]);
        }

        // Sessions as builds from before left them: `mfa` written as the account's switch.
        $session = $pdo->prepare(
            "INSERT INTO sessions (user_id, token_hash, sid, method, mfa, expires_at, absolute_expires_at, revoked_at, revoked_reason)
         VALUES (?, ?, ?, ?, ?, CURRENT_TIMESTAMP + INTERVAL '30 days', CURRENT_TIMESTAMP + INTERVAL '90 days', ?, ?) RETURNING id",
        );
        $sessions = [];
        foreach (
            [
                'the hole\'s' => [$confirmedId, 'email_link', 'true', null, null],
                'a password\'s with its code' => [$confirmedId, 'password', 'true', null, null],
                'a link\'s that says no mfa' => [$confirmedId, 'email_link', 'false', null, null],
                'a link\'s ended before' => [$confirmedId, 'email_link', 'true', '2026-10-01 10:00:00+00', 'signed_out'],
                'a link\'s on an account without two-factor' => [$plainId, 'email_link', 'true', null, null],
            ]
            as $what => [$user, $method, $mfa, $revokedAt, $reason]
        ) {
            $session->execute([$user, randomHex(32), randomHex(16), $method, $mfa, $revokedAt, $reason]);
            $sessions[$what] = (int) $session->fetchColumn();
        }
        $pdo->prepare(
            "INSERT INTO refresh_tokens (token_hash, kind, family_id, application_id, user_id, session_id, scope, expires_at)
             SELECT ?, 'rotating', ?, a.id, ?, ?, 'openid', CURRENT_TIMESTAMP + INTERVAL '30 days' FROM applications a WHERE a.client_id = 'genshin'",
        )->execute([randomHex(32), randomHex(16), $confirmedId, $sessions['the hole\'s']]);

        $result = applyMigrationsThrough($pdo, LINKS_MIGRATION);
        same([[LINKS_MIGRATION], null], [$result['applied'], $result['failed']], '026 applied, its gate passed (' . ($result['error'] ?? '') . ')');
        same(
            ['a' => true, 'b' => true, 'c' => false, 'd' => false],
            array_map(
                'asBool',
                array_column($pdo->query('SELECT substr(token_hash, 1, 1) AS mark, consumed_at IS NOT NULL AS spent FROM user_tokens ORDER BY token_hash')->fetchAll(), 'spent', 'mark'),
            ),
            'the confirmed address\'s two links spent; the unconfirmed one\'s link, and a reset link, left live',
        );
        $reasons = [];
        foreach ($sessions as $what => $id) {
            $reasons[$what] = $pdo->query("SELECT revoked_reason FROM sessions WHERE id = {$id}")->fetchColumn() ?: null;
        }
        same(
            [
                'the hole\'s' => 'security_change',
                'a password\'s with its code' => null,
                'a link\'s that says no mfa' => null,
                'a link\'s ended before' => 'signed_out',
                'a link\'s on an account without two-factor' => null,
            ],
            $reasons,
            'the live link session saying mfa on an account with two-factor on ended for a security change - nothing else',
        );
        same('security_change', $pdo->query("SELECT revoked_reason FROM refresh_tokens WHERE session_id = {$sessions['the hole\'s']}")->fetchColumn(), 'and the refresh token issued under it');
        check(googleTokensReady($pdo), 'the Google tokens\' table made, with its hold');
        same(confirmStrings(iamDb()), confirmStrings($pdo), 'every key, description and string as on a fresh IAM');
    },
);

test('an IAM built through 025 as its owner left it: a Slovak string blanked elsewhere stays blank, and the gate passes', function () {
    $pdo = buildThrough025('_owned_links');
    $pdo->exec("DELETE FROM translations WHERE key_name = 'common.save' AND language_code = 'sk'");

    $result = applyMigrationsThrough($pdo, LINKS_MIGRATION);
    same([[LINKS_MIGRATION], null], [$result['applied'], $result['failed']], '026 applied, its gate passed - a blank elsewhere is no failure (' . ($result['error'] ?? '') . ')');
    same(confirmStrings(iamDb()), confirmStrings($pdo), 'the seven keys as on a fresh IAM');
    same(0, (int) $pdo->query("SELECT count(*) FROM translations WHERE key_name = 'common.save' AND language_code = 'sk'")->fetchColumn(), 'and the Slovak string the owner blanked stays blank');
});

test('a 026 whose gate fails - a Slovak string that did not arrive, or a link left live - leaves nothing behind, and runs once the cause is gone', function () {
    $pdo = buildThrough025('_spoilt_links');

    // Something on the host swallows one Slovak string on its way in: the gate must see it.
    $pdo->exec("CREATE FUNCTION test_026_swallow() RETURNS trigger LANGUAGE plpgsql AS \$\$ BEGIN RETURN NULL; END \$\$");
    $pdo->exec("CREATE TRIGGER test_026_swallow BEFORE INSERT ON translations FOR EACH ROW WHEN (NEW.key_name = 'confirm.submit' AND NEW.language_code = 'sk') EXECUTE FUNCTION test_026_swallow()");

    $result = applyMigrationsThrough($pdo, LINKS_MIGRATION);
    same([[], LINKS_MIGRATION], [$result['applied'], $result['failed']], '026 fails');
    check(str_contains((string) $result['error'], 'confirm.submit'), 'its gate names the key: ' . $result['error']);
    same(
        [0, false],
        [
            (int) $pdo->query("SELECT count(*) FROM translation_keys WHERE name LIKE 'confirm.submit'")->fetchColumn(),
            asBool($pdo->query("SELECT to_regclass('google_id_tokens_used') IS NOT NULL")->fetchColumn()),
        ],
        'none of its keys left, nor its table',
    );
    $pdo->exec('DROP TRIGGER test_026_swallow ON translations');
    $pdo->exec('DROP FUNCTION test_026_swallow()');

    // Something keeps a confirmed address's link live: the gate must see that too.
    $insert = $pdo->prepare('INSERT INTO users (username, email, password, email_confirmed) VALUES (?, ?, ?, TRUE) RETURNING id');
    $insert->execute(['stubborn', 'stubborn@example.test', password_hash(PASSWORD, PASSWORD_DEFAULT)]);
    $pdo->prepare("INSERT INTO user_tokens (user_id, purpose, token_hash, expires_at) VALUES (?, 'email_confirm', ?, CURRENT_TIMESTAMP + INTERVAL '48 hours')")->execute([
        (int) $insert->fetchColumn(),
        str_repeat('e', 64),
    ]);
    $pdo->exec("CREATE FUNCTION test_026_keep() RETURNS trigger LANGUAGE plpgsql AS \$\$ BEGIN NEW.consumed_at := NULL; RETURN NEW; END \$\$");
    $pdo->exec('CREATE TRIGGER test_026_keep BEFORE UPDATE ON user_tokens FOR EACH ROW EXECUTE FUNCTION test_026_keep()');
    $result = applyMigrationsThrough($pdo, LINKS_MIGRATION);
    same([[], LINKS_MIGRATION], [$result['applied'], $result['failed']], '026 fails');
    check(str_contains((string) $result['error'], 'confirmation link'), 'its gate says why: ' . $result['error']);
    $pdo->exec('DROP TRIGGER test_026_keep ON user_tokens');
    $pdo->exec('DROP FUNCTION test_026_keep()');

    // Something keeps a link's session that says mfa live: the gate must see that too.
    $insert = $pdo->prepare('INSERT INTO users (username, email, password, email_confirmed, totp_enabled) VALUES (?, ?, ?, TRUE, TRUE) RETURNING id');
    $insert->execute(['lingering', 'lingering@example.test', password_hash(PASSWORD, PASSWORD_DEFAULT)]);
    $pdo->prepare(
        "INSERT INTO sessions (user_id, token_hash, sid, method, mfa, expires_at, absolute_expires_at)
             VALUES (?, ?, ?, 'email_link', TRUE, CURRENT_TIMESTAMP + INTERVAL '30 days', CURRENT_TIMESTAMP + INTERVAL '90 days')",
    )->execute([(int) $insert->fetchColumn(), randomHex(32), randomHex(16)]);
    $pdo->exec("CREATE FUNCTION test_026_linger() RETURNS trigger LANGUAGE plpgsql AS \$\$ BEGIN NEW.revoked_at := NULL; RETURN NEW; END \$\$");
    $pdo->exec('CREATE TRIGGER test_026_linger BEFORE UPDATE ON sessions FOR EACH ROW EXECUTE FUNCTION test_026_linger()');
    $result = applyMigrationsThrough($pdo, LINKS_MIGRATION);
    same([[], LINKS_MIGRATION], [$result['applied'], $result['failed']], '026 fails');
    check(str_contains((string) $result['error'], 'still says mfa'), 'its gate says why: ' . $result['error']);
    $pdo->exec('DROP TRIGGER test_026_linger ON sessions');
    $pdo->exec('DROP FUNCTION test_026_linger()');

    $result = applyMigrationsThrough($pdo, LINKS_MIGRATION);
    same([[LINKS_MIGRATION], null], [$result['applied'], $result['failed']], 'the causes gone, the next Apply runs it (' . ($result['error'] ?? '') . ')');
    same(confirmStrings(iamDb()), confirmStrings($pdo), 'with every key and string');
    same(0, (int) $pdo->query("SELECT count(*) FROM user_tokens WHERE consumed_at IS NULL AND purpose = 'email_confirm'")->fetchColumn(), 'and the link spent');
});

finishTests();
