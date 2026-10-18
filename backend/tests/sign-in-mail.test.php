<?php

/**
 * The mail for a sign-in from a new browser (migration 016, the owner's
 * decision of 2026-10-07: "Not based on login to the project but iam in
 * general so there will be no duplicate emails").
 *
 * At the IAM's own sign-in - every way in, past two-factor - the browser is
 * known by a cookie of its own whose hash the account keeps. An account's
 * first browser is noted in silence (release day mails nobody); every other
 * new one is mailed once, and a known one never. A site's single sign-on and
 * its refreshes are no sign-in. The cookie is no credential, a user agent is
 * never written into the mail, a mail that cannot be sent stops no sign-in,
 * a reset forgets the browsers of whoever held the account, and with 016
 * pending signing in works as before.
 */

declare(strict_types=1);

require __DIR__ . '/lib.php';

// This run's own database is a fresh IAM, built from every migration, 016 included.
bootTests();

const PASSWORD = 'correct horse';
const DEVICE_COOKIE = 'anotoki_iam_device';
const NEW_SIGN_IN = 'New sign-in to your anotoki account';
const FIREFOX_WINDOWS = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:130.0) Gecko/20100101 Firefox/130.0';
const SAFARI_IPHONE = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_6 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.6 Mobile/15E148 Safari/604.1';
const SURVEY_FRONTEND = 'http://localhost:4700';
const SURVEY_CALLBACK = 'http://localhost:4700/auth/callback';
const GOOGLE_TEST_CLIENT = 'iam-test.apps.googleusercontent.com';

$adminId = makeUser('admin', 'admin@example.test');
grantRole($adminId, 'iam', 'ADMIN');

/** A password sign-in from a browser, with its user agent. */
function signInFrom(Browser $browser, string $email, string $userAgent = FIREFOX_WINDOWS, array $extra = []): TestResponse
{
    return $browser->post('/api/login/password', ['email' => $email, 'password' => PASSWORD] + $extra, ['headers' => ['User-Agent' => $userAgent]]);
}

/** Every message to an address, oldest first: ['subject', 'body'] each. */
function mailsTo(string $email): array
{
    $files = glob(testEnvironment()['mail'] . DIRECTORY_SEPARATOR . '*.eml') ?: [];
    sort($files);
    $mails = [];
    foreach ($files as $file) {
        $text = (string) file_get_contents($file);
        if (str_contains($text, "To: $email\n")) {
            [$head, $body] = explode("\n\n", $text, 2);
            preg_match('/^Subject: (.*)$/m', $head, $subject);
            $mails[] = ['subject' => $subject[1] ?? '', 'body' => $body];
        }
    }

    return $mails;
}

/** The new sign-in mails to an address, oldest first. */
function newSignInMails(string $email): array
{
    return array_values(array_filter(mailsTo($email), static fn(array $mail): bool => $mail['subject'] === NEW_SIGN_IN));
}

/** The browsers an account is known to have signed in from: [hash, words] each, in the order they came. */
function devicesOf(int $userId): array
{
    $statement = iamDb()->prepare('SELECT token_hash, user_agent FROM user_sign_in_devices WHERE user_id = ? ORDER BY id');
    $statement->execute([$userId]);

    return $statement->fetchAll(PDO::FETCH_NUM);
}

/** The device cookie a response set: its whole Set-Cookie line, or ''. */
function deviceCookieLine(TestResponse $response): string
{
    foreach ($response->headers as $name => $values) {
        if (strcasecmp($name, 'Set-Cookie') === 0) {
            foreach ($values as $line) {
                if (str_starts_with($line, DEVICE_COOKIE . '=')) {
                    return $line;
                }
            }
        }
    }

    return '';
}

/** The emailed code's way in, from a browser with a user agent. */
function signInByCode(Browser $browser, string $email, string $userAgent = FIREFOX_WINDOWS): TestResponse
{
    same(200, $browser->post('/api/login/code', ['email' => $email])->status, 'a code asked for');
    preg_match('/\b(\d{6})\b/', (string) (lastMailTo($email)['subject'] ?? ''), $found);

    return $browser->post('/api/login/code/verify', ['email' => $email, 'code' => $found[1] ?? ''], ['headers' => ['User-Agent' => $userAgent]]);
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
                'keys' => ['sign-in-mail-test' => ['kty' => 'RSA', 'kid' => 'sign-in-mail-test', 'n' => $pair['n'], 'e' => $pair['e']]],
            ]),
        );
        writeTestConfig('google', ['client_id' => GOOGLE_TEST_CLIENT]);
        forgetLocalConfig();
    }

    $header = base64UrlEncode((string) json_encode(['alg' => 'RS256', 'kid' => 'sign-in-mail-test', 'typ' => 'JWT']));
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
        ]),
    );
    openssl_sign("{$header}.{$payload}", $signature, $key, OPENSSL_ALGO_SHA256);

    return "{$header}.{$payload}." . base64UrlEncode($signature);
}

/** The mail configuration this run started with, written again. */
function mailWorks(): void
{
    writeTestConfig('mail', [
        'driver' => 'file',
        'from' => 'noreply@localhost',
        'from_name' => 'anotoki',
        'senders' => ['noreply' => ['from' => 'noreply@localhost', 'from_name' => 'anotoki'], 'support' => ['from' => 'support@localhost', 'from_name' => 'anotoki']],
        'directory' => testEnvironment()['mail'],
    ]);
    forgetLocalConfig();
}

/** Points the app at another of this run's databases, forgetting what it remembered of the old one. */
function useIamDatabase(string $name): void
{
    $environment = testEnvironment();
    file_put_contents(
        $environment['config'] . DIRECTORY_SEPARATOR . 'database.local.php',
        "<?php\n\n\$config = require " .
            var_export(developmentDatabaseFile(), true) .
            ";\n" .
            "\$config['databases'] = ['iam' => " .
            var_export(throwawayDatabase($name), true) .
            "];\n" .
            "\$config['persistent'] = false;\n\nreturn \$config;\n",
    );
    forgetLocalConfig();
    settingsAll(true);
    languageCodes(null, true);
    activeSigningKey(true);
}

// ─────────────────────────────────────────────────────────────────────────────

test('an account\'s first browser is noted in silence - every account\'s first sign-in once 016 is there', function () {
    $id = makeUser('first', 'first@example.test');
    $browser = new Browser('10.17.1.1');
    same(200, signInFrom($browser, 'first@example.test')->status, 'signed in');

    $secret = $browser->cookies[DEVICE_COOKIE] ?? '';
    same(1, preg_match('/^[A-Za-z0-9_-]{43}$/D', $secret), 'a cookie of its own: 32 random bytes');
    same([[signInDeviceHash($id, $secret), 'Firefox · Windows']], devicesOf($id), 'kept: a hash of the account and the secret, and the browser in a few words');
    check(signInDeviceHash($id, $secret) !== sha256($secret), 'not the secret\'s hash alone');
    same([], mailsTo('first@example.test'), 'nobody mailed');
});

test('a second browser is mailed about, once - when, which, and where to see every sign-in; a known one never', function () {
    $id = makeUser('second', 'second@example.test');
    $laptop = new Browser('10.17.2.1');
    same(200, signInFrom($laptop, 'second@example.test')->status, 'the laptop, the first');
    same([], newSignInMails('second@example.test'), 'in silence');

    $phone = new Browser('10.17.2.2');
    $before = time();
    same(200, signInFrom($phone, 'second@example.test', SAFARI_IPHONE)->status, 'the phone');
    $after = time();
    $mails = newSignInMails('second@example.test');
    same(1, count($mails), 'mailed once');

    preg_match('/^    (\d{4}-\d\d-\d\d \d\d:\d\d) UTC$/m', $mails[0]['body'], $when);
    $at = strtotime(($when[1] ?? '') . ' UTC');
    check($at !== false && $at >= $before - 60 && $at <= $after, 'when, in UTC and saying so: ' . ($when[0] ?? $mails[0]['body']));
    same(
        "Hello second,\n\nYour anotoki account was just signed in to from a browser or device it has not been used on before:\n\n" .
            "    {$when[1]} UTC\n    Safari · iPhone\n\n" .
            "If it was you, there is nothing to do. If it was not, change your password now, and end that sign-in on the page that shows every place your account is signed in:\n\n" .
            TEST_ISSUER .
            "/account/sessions\n",
        $mails[0]['body'],
        'the time and the browser on lines of their own, then the page of every sign-in',
    );
    same(
        ['new_sign_in', NEW_SIGN_IN, 'sent'],
        array_values(
            iamDb()
                ->query("SELECT kind, subject, status FROM email_log WHERE user_id = $id ORDER BY id DESC LIMIT 1")
                ->fetch(PDO::FETCH_NUM),
        ),
        'logged as its kind, with its subject',
    );

    same(200, signInFrom($phone, 'second@example.test', SAFARI_IPHONE)->status, 'the phone again');
    same(200, signInFrom($laptop, 'second@example.test')->status, 'the laptop again');
    same(1, count(newSignInMails('second@example.test')), 'no more mail');
    same(2, count(devicesOf($id)), 'two browsers known');
});

test('every way in counts, and only past two-factor: an emailed code, Google; a password stopped for its code is no sign-in yet', function () {
    makeUser('ways', 'ways@example.test');
    same(200, signInFrom(new Browser('10.17.3.1'), 'ways@example.test')->status, 'a first browser');

    same(200, signInByCode(new Browser('10.17.3.2'), 'ways@example.test')->status, 'an emailed code from another');
    same(1, count(newSignInMails('ways@example.test')), 'mailed');
    same(
        200,
        ($r = (new Browser('10.17.3.3'))->post('/api/login/google', ['credential' => googleToken('google-ways', 'ways@example.test', 'Ways')], ['headers' => ['User-Agent' => SAFARI_IPHONE]]))->status,
        'Google from a third: ' . $r->body,
    );
    same(2, count(newSignInMails('ways@example.test')), 'mailed');

    $guardedId = makeUser('guarded', 'guarded@example.test');
    $secret = totpSecret();
    iamDb()
        ->prepare('UPDATE users SET totp_secret = ?, totp_enabled = TRUE WHERE id = ?')
        ->execute([encryptSecret($secret), $guardedId]);
    same(200, signInFrom(new Browser('10.17.3.4'), 'guarded@example.test', FIREFOX_WINDOWS, ['totp' => totpCode($secret, intdiv(time(), 30))])->status, 'a first browser, with its code');
    $stranger = new Browser('10.17.3.5');
    same([403, 'totp_required'], [($r = signInFrom($stranger, 'guarded@example.test'))->status, $r->code()], 'another browser, the password right, the code not given');
    same([1, 0], [count(devicesOf($guardedId)), count(newSignInMails('guarded@example.test'))], 'no sign-in: nothing noted, nobody mailed');
    same(200, signInFrom($stranger, 'guarded@example.test', FIREFOX_WINDOWS, ['totp' => totpCode($secret, intdiv(time(), 30) + 1)])->status, 'with the code');
    same([2, 1], [count(devicesOf($guardedId)), count(newSignInMails('guarded@example.test'))], 'a sign-in: noted, and mailed');

    // A new account's confirmation link is its first sign-in.
    $newcomer = new Browser('10.17.3.6');
    same(201, $newcomer->post('/api/register', ['username' => 'newcomer', 'email' => 'newcomer@example.test', 'password' => 'a long password', 'language' => 'en'])->status, 'registered');
    preg_match('#/confirm-email\?token=([0-9a-f]{64})#', (string) (lastMailTo('newcomer@example.test')['body'] ?? ''), $link);
    same(200, $newcomer->post('/api/register/confirm', ['token' => $link[1] ?? ''])->status, 'confirmed, and signed in');
    same([1, 0], [count(devicesOf((int) userByEmail(iamDb(), 'newcomer@example.test')['id'])), count(newSignInMails('newcomer@example.test'))], 'its first browser, in silence');
});

test('a site\'s single sign-on and its refreshes are no sign-in: nothing is mailed or noted', function () {
    $id = makeUser('sso', 'sso@example.test');
    $browser = new Browser('10.17.4.1');
    same(200, signInFrom($browser, 'sso@example.test')->status, 'signed in');
    $known = devicesOf($id);

    [$verifier, $challenge] = pkce();
    $start = $browser->get(
        '/api/oidc/authorize?' .
            http_build_query([
                'response_type' => 'code',
                'client_id' => 'survey',
                'redirect_uri' => SURVEY_CALLBACK,
                'scope' => 'openid profile email',
                'state' => 's',
                'nonce' => 'n',
                'code_challenge' => $challenge,
                'code_challenge_method' => 'S256',
            ]),
        ['headers' => ['User-Agent' => SAFARI_IPHONE]],
    );
    $code = queryOf($start->location())['code'] ?? '';
    check($code !== '', 'single sign-on: a code at once');
    $tokens = $browser->request('POST', '/api/oidc/token', [
        'form' => ['grant_type' => 'authorization_code', 'code' => $code, 'redirect_uri' => SURVEY_CALLBACK, 'client_id' => 'survey', 'code_verifier' => $verifier],
        'headers' => ['Origin' => SURVEY_FRONTEND, 'User-Agent' => SAFARI_IPHONE],
    ]);
    same(200, $tokens->status, 'the tokens');
    $refreshed = (new Browser('10.17.4.2'))->request('POST', '/api/oidc/token', [
        'form' => ['grant_type' => 'refresh_token', 'refresh_token' => $tokens->json()['refresh_token'], 'client_id' => 'survey'],
        'headers' => ['User-Agent' => 'a site\'s backend'],
    ]);
    same(200, $refreshed->status, 'a refresh');
    same('', deviceCookieLine($start), 'no cookie from authorize');

    same([[], $known], [mailsTo('sso@example.test'), devicesOf($id)], 'nobody mailed, nothing noted');
});

test('a mail that cannot be sent stops no sign-in', function () {
    $id = makeUser('unmailed', 'unmailed@example.test');
    same(200, signInFrom(new Browser('10.17.5.1'), 'unmailed@example.test')->status, 'a first browser');

    // The file driver pointed under a file: no folder can be made there.
    $blocker = testEnvironment()['base'] . DIRECTORY_SEPARATOR . 'not-a-folder';
    file_put_contents($blocker, 'x');
    writeTestConfig('mail', ['driver' => 'file', 'from' => 'noreply@localhost', 'from_name' => 'anotoki', 'directory' => $blocker . DIRECTORY_SEPARATOR . 'mail']);
    forgetLocalConfig();
    try {
        $browser = new Browser('10.17.5.2');
        $answer = signInFrom($browser, 'unmailed@example.test');
        same(200, $answer->status, 'signed in: ' . $answer->body);
        check(isset($browser->cookies['anotoki_iam'], $browser->cookies[DEVICE_COOKIE]), 'with its session and its device cookie');
        same(200, $browser->get('/api/account')->status, 'and the session works');
    } finally {
        mailWorks();
    }
    same(
        ['new_sign_in', 'failed'],
        array_values(
            iamDb()
                ->query("SELECT kind, status FROM email_log WHERE user_id = $id ORDER BY id DESC LIMIT 1")
                ->fetch(PDO::FETCH_NUM),
        ),
        'the mail that did not go, logged',
    );
    same(2, count(devicesOf($id)), 'the browser noted all the same');
});

test('the cookie is no credential: it opens nothing, and skips no second factor', function () {
    $id = makeUser('cookie', 'cookie@example.test');
    $secret = totpSecret();
    iamDb()
        ->prepare('UPDATE users SET totp_secret = ?, totp_enabled = TRUE WHERE id = ?')
        ->execute([encryptSecret($secret), $id]);
    $browser = new Browser('10.17.6.1');
    $first = signInFrom($browser, 'cookie@example.test', FIREFOX_WINDOWS, ['totp' => totpCode($secret, intdiv(time(), 30))]);
    same(200, $first->status, 'signed in');

    $line = deviceCookieLine($first);
    same(
        1,
        preg_match('/^anotoki_iam_device=[A-Za-z0-9_-]{43}; Path=\/; HttpOnly; SameSite=Lax; Max-Age=34560000$/D', $line),
        'HttpOnly, SameSite=Lax, everywhere, 400 days - and not Secure over plain http: ' . $line,
    );

    $thief = new Browser('10.17.6.2');
    $thief->cookies[DEVICE_COOKIE] = $browser->cookies[DEVICE_COOKIE];
    same([401, 'no_session'], [($r = $thief->get('/api/account'))->status, $r->code()], 'the cookie alone is no session');
    same([403, 'totp_required'], [($r = signInFrom($thief, 'cookie@example.test'))->status, $r->code()], 'nor a remembered two-factor device');
    same([403, 'totp_required'], [($r = signInFrom($thief, 'cookie@example.test', FIREFOX_WINDOWS, ['device_token' => $browser->cookies[DEVICE_COOKIE]]))->status, $r->code()], 'not even sent as one');

    $again = signInFrom($browser, 'cookie@example.test', FIREFOX_WINDOWS, ['totp' => totpCode($secret, intdiv(time(), 30) + 1)]);
    same(200, $again->status, 'the same browser again');
    check(str_starts_with(deviceCookieLine($again), DEVICE_COOKIE . '=' . $browser->cookies[DEVICE_COOKIE] . ';'), 'its cookie set again, the same - its 400 days start over');
});

test('one browser, two accounts: two rows that do not match, and each account its own first', function () {
    $aliceId = makeUser('alice', 'alice@example.test');
    $bobId = makeUser('bob', 'bob@example.test');
    $shared = new Browser('10.17.7.1');
    same(200, signInFrom($shared, 'alice@example.test')->status, 'alice');
    $secret = $shared->cookies[DEVICE_COOKIE];
    same(200, signInFrom($shared, 'bob@example.test')->status, 'bob, in the same browser');
    same($secret, $shared->cookies[DEVICE_COOKIE], 'one cookie for the browser');
    same([[signInDeviceHash($aliceId, $secret)], [signInDeviceHash($bobId, $secret)]], [array_column(devicesOf($aliceId), 0), array_column(devicesOf($bobId), 0)], 'a row each');
    check(signInDeviceHash($aliceId, $secret) !== signInDeviceHash($bobId, $secret), 'that the table cannot match to each other');
    same([[], []], [newSignInMails('alice@example.test'), newSignInMails('bob@example.test')], 'each account\'s first browser: nobody mailed');

    same(200, signInFrom($shared, 'alice@example.test')->status, 'alice again');
    same(200, signInFrom(new Browser('10.17.7.2'), 'bob@example.test')->status, 'bob somewhere new');
    same([0, 1], [count(newSignInMails('alice@example.test')), count(newSignInMails('bob@example.test'))], 'only bob is mailed');
});

test('what a browser says of itself is never written into the mail: only names from a fixed list', function () {
    makeUser('agents', 'agents@example.test');
    same(200, signInFrom(new Browser('10.17.8.1'), 'agents@example.test')->status, 'a first browser');

    same(
        200,
        signInFrom(new Browser('10.17.8.2'), 'agents@example.test', 'Mozilla/5.0 (Windows NT 10.0) Firefox/130.0 - urgent: verify at https://evil.example/now or www.evil.example')->status,
        'a user agent with a link in it',
    );
    $mail = newSignInMails('agents@example.test')[0]['body'] ?? '';
    check(str_contains($mail, "\n    Firefox · Windows\n"), 'the browser by name: ' . $mail);
    check(!str_contains($mail, 'evil') && !str_contains($mail, 'urgent'), 'and nothing else of what it said');

    same(200, signInFrom(new Browser('10.17.8.3'), 'agents@example.test', 'EvilBot/1.0 (+https://evil.example)')->status, 'a user agent nothing knows');
    $unknown = newSignInMails('agents@example.test')[1]['body'] ?? '';
    same(1, preg_match('/:\n\n    \d{4}-\d\d-\d\d \d\d:\d\d UTC\n\nIf it was you/', $unknown), 'the time alone: ' . $unknown);
    check(!str_contains($unknown, 'evil') && !str_contains($unknown, 'EvilBot'), 'none of it');
});

test('no more than five new-browser mails an hour: a client that keeps no cookie cannot bury the mailbox', function () {
    $id = makeUser('flooded', 'flooded@example.test');
    for ($i = 1; $i <= 8; $i++) {
        same(200, signInFrom(new Browser('10.17.11.' . $i), 'flooded@example.test')->status, "sign-in $i, with no cookie kept");
    }
    same(8, count(devicesOf($id)), 'every browser noted');
    same(SIGN_IN_DEVICE_MAILS_PER_HOUR, count(newSignInMails('flooded@example.test')), 'the first in silence, then five mails - and no more that hour');
});

test('the five are counted by the browsers mailed about this hour - taken under the account\'s lock, not read from the email log', function () {
    $id = makeUser('counted', 'counted@example.test');
    same(200, signInFrom(new Browser('10.17.12.1'), 'counted@example.test')->status, 'a first browser');
    // Four browsers mailed about in the last hour, one two hours ago - none of their mails in the email log.
    $mailed = iamDb()->prepare("INSERT INTO user_sign_in_devices (user_id, token_hash, user_agent, mailed_at) VALUES (?, ?, 'Firefox', CURRENT_TIMESTAMP - (? * INTERVAL '1 minute'))");
    $mailed->execute([$id, sha256('two hours ago'), 120]);
    for ($i = 1; $i <= 4; $i++) {
        $mailed->execute([$id, sha256("this hour $i"), 10 * $i]);
    }

    same(200, signInFrom(new Browser('10.17.12.2'), 'counted@example.test')->status, 'a new browser: the fifth this hour');
    same(1, count(newSignInMails('counted@example.test')), 'mailed, and taken');
    same(200, signInFrom(new Browser('10.17.12.3'), 'counted@example.test', SAFARI_IPHONE)->status, 'another: the sixth');
    same(1, count(newSignInMails('counted@example.test')), 'not mailed - one mail in the email log, five taken this hour');
    same(
        [5, 2],
        [
            (int) iamDb()
                ->query("SELECT count(*) FROM user_sign_in_devices WHERE user_id = $id AND mailed_at > CURRENT_TIMESTAMP - INTERVAL '1 hour'")
                ->fetchColumn(),
            (int) iamDb()
                ->query("SELECT count(*) FROM user_sign_in_devices WHERE user_id = $id AND mailed_at IS NULL")
                ->fetchColumn(),
        ],
        'five taken this hour; the first browser and the sixth noted with none',
    );
});

test('a reset forgets the browsers: one of whoever held the account is mailed about again', function () {
    $id = makeUser('reset', 'reset@example.test');
    $holders = new Browser('10.17.9.1');
    same(200, signInFrom($holders, 'reset@example.test')->status, 'a browser, the first');
    same(200, signInFrom(new Browser('10.17.9.2'), 'reset@example.test', SAFARI_IPHONE)->status, 'another');
    same(1, count(newSignInMails('reset@example.test')), 'mailed about the second');

    $owner = new Browser('10.17.9.3');
    same(200, $owner->post('/api/password/forgot', ['email' => 'reset@example.test'])->status, 'a reset asked for');
    preg_match('#/reset-password\?token=([0-9a-f]{64})#', (string) (lastMailTo('reset@example.test')['body'] ?? ''), $link);
    same(200, $owner->post('/api/password/reset', ['token' => $link[1] ?? '', 'password' => 'a new password'], ['headers' => ['User-Agent' => FIREFOX_WINDOWS]])->status, 'reset, and signed in');
    same([[signInDeviceHash($id, $owner->cookies[DEVICE_COOKIE]), 'Firefox · Windows']], devicesOf($id), 'only the browser that took the account back is known');
    same(1, count(newSignInMails('reset@example.test')), 'noted as the first, in silence');

    same(
        200,
        $holders->post('/api/login/password', ['email' => 'reset@example.test', 'password' => 'a new password'], ['headers' => ['User-Agent' => FIREFOX_WINDOWS]])->status,
        'the old browser, with the new password',
    );
    same(2, count(newSignInMails('reset@example.test')), 'is mailed about again');
});

test('with 016 pending signing in works as before and sets no cookie; Apply brings it, the first browser in silence', function () {
    $main = testEnvironment()['db'];
    $window = throwawayDatabase($main . '_window');
    buildIamThrough('_window', '015_account_preferences.sql');
    useIamDatabase($window);

    try {
        same(false, signInDevicesReady(iamDb()), 'no browsers\' table yet');
        $adminId = makeUser('admin', 'admin@example.test');
        grantRole($adminId, 'iam', 'ADMIN');
        $id = makeUser('early', 'early@example.test');

        $browser = new Browser('10.17.10.1');
        $answer = signInFrom($browser, 'early@example.test');
        same(200, $answer->status, 'signed in: ' . $answer->body);
        same(['', false], [deviceCookieLine($answer), isset($browser->cookies[DEVICE_COOKIE])], 'no device cookie');
        same(200, signInFrom(new Browser('10.17.10.2'), 'early@example.test')->status, 'and from another browser');
        same([], newSignInMails('early@example.test'), 'nobody mailed');

        $admin = new Browser('10.17.10.3');
        same(200, signInFrom($admin, 'admin@example.test')->status, 'the admin signs in');
        $applied = $admin->post('/api/admin/migrations/apply');
        same(
            [200, '016_account_deletion_and_new_sign_in_mail.sql', null],
            [$applied->status, $applied->json()['applied'][0]['name'] ?? null, $applied->json()['failed'] ?? null],
            'Apply pending: ' . $applied->body,
        );

        same(200, signInFrom($browser, 'early@example.test')->status, 'signed in again, in the same process');
        same([1, 0], [count(devicesOf($id)), count(newSignInMails('early@example.test'))], 'the first browser after 016: noted in silence');
        same(200, signInFrom(new Browser('10.17.10.4'), 'early@example.test', SAFARI_IPHONE)->status, 'a second');
        same(1, count(newSignInMails('early@example.test')), 'mailed');
    } finally {
        useIamDatabase($main);
    }

    same($main, iamDb()->query('SELECT current_database()')->fetchColumn(), 'back on this run\'s own database');
});

finishTests();
