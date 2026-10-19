<?php

/**
 * A Google id token is good once, and only while it is fresh (the security
 * review of 2026-10-19, its Low 2; migration 026). Google signs one for an
 * hour, and the IAM took it as often as it came within that hour: whoever saw
 * one - in a log, a tab's memory, a proxy - signed in with it again, a site's
 * "Sign in again" (prompt=login) answered too. Now a token whose `iat` is more
 * than five minutes old is refused, as is one from further ahead than the
 * clocks' minute and one without an `iat`, and so is one spent here before -
 * remembered by the SHA-256 of what Google signed, until it runs out. It is
 * spent at the moment it changes something - a session opened, an account
 * made or claimed, an identity attached, Google connected on the account page
 * - never before. And from the moment the Google sign-in finds the account it
 * names, it is held for that account's sign-in (the review of d0e34fb): a
 * sign-in stopped for its second factor, or refused before anything changed,
 * keeps it for the same account to finish with the code, and nothing else
 * takes it; one that names no account is spent at once. The account page
 * holds it for the account that sent it before it asks for the password (the
 * review of 632a464), and connects no Google whose address another account
 * here has confirmed.
 *
 * The attacks: a token used once, sent again from another browser within its
 * hour - its signature written another way too (base64 leaves bits of the
 * last character unread: the same signature, another string); one made long
 * ago; one spent on the account page then sent to the sign-in, and the other
 * way round; two requests with one token at once; and a token left waiting
 * for its code - or refused `unverified_email`, `registration_disabled`, or
 * waiting on the account page for the right password - taken by somebody who
 * saw it, to connect the victim's Google to an account of their own.
 */

declare(strict_types=1);

require __DIR__ . '/lib.php';

// This run's own database is a fresh IAM, built from every migration, 026 included.
bootTests();

const PASSWORD = 'correct horse';
const GOOGLE_TEST_CLIENT = 'iam-test.apps.googleusercontent.com';
const BEFORE_GOOGLE_ONCE = '025_usernames_letters_and_digits.sql';
const GOOGLE_ONCE_MIGRATION = '026_confirmation_links_and_google_tokens.sql';

/**
 * A Google id token for this IAM, signed by a key it is given as Google's (the
 * key cache in this run's storage, read before Google would be asked), with a
 * client id in this run's configuration - and, as Google makes them, a `jti`
 * of its own, so no two are alike. $claims change what Google would have
 * written, or take it away (null).
 */
function googleToken(string $subject, string $email, array $claims = []): string
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
                'keys' => ['once-test' => ['kty' => 'RSA', 'kid' => 'once-test', 'n' => $pair['n'], 'e' => $pair['e']]],
            ]),
        );
        writeTestConfig('google', ['client_id' => GOOGLE_TEST_CLIENT]);
        forgetLocalConfig();
    }

    $claims += [
        'iss' => 'https://accounts.google.com',
        'aud' => GOOGLE_TEST_CLIENT,
        'sub' => $subject,
        'email' => $email,
        'email_verified' => true,
        'name' => $subject,
        'iat' => time(),
        'exp' => time() + 3600,
        'jti' => bin2hex(random_bytes(16)),
    ];
    $header = base64UrlEncode((string) json_encode(['alg' => 'RS256', 'kid' => 'once-test', 'typ' => 'JWT']));
    $payload = base64UrlEncode((string) json_encode(array_filter($claims, static fn(mixed $value): bool => $value !== null)));
    openssl_sign("{$header}.{$payload}", $signature, $key, OPENSSL_ALGO_SHA256);

    return "{$header}.{$payload}." . base64UrlEncode($signature);
}

/**
 * The same token with its signature written another way: the last character
 * of a 256-byte signature carries two bits of it and four that base64 never
 * reads - one of those flipped, the signature decodes to the same bytes.
 */
function rewrittenSignature(string $token): string
{
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789-_';
    [$header, $payload, $signature] = explode('.', $token);
    $rewritten = substr($signature, 0, -1) . $alphabet[strpos($alphabet, $signature[-1]) ^ 1];
    check($rewritten !== $signature && base64UrlDecode($rewritten) === base64UrlDecode($signature), 'the same signature, written another way');

    return "{$header}.{$payload}.{$rewritten}";
}

/** The Google sign-in, from a browser of its own address. */
function googleSignIn(string $ip, string $token, array $extra = []): TestResponse
{
    return (new Browser($ip))->post('/api/login/google', ['credential' => $token] + $extra);
}

/** How many sessions an account has had. */
function sessionsOf(int $userId): int
{
    $statement = iamDb()->prepare('SELECT count(*) FROM sessions WHERE user_id = ?');
    $statement->execute([$userId]);

    return (int) $statement->fetchColumn();
}

/** The newest session of an account: [its method, whether two factors]. */
function newestSession(int $userId): array
{
    $statement = iamDb()->prepare('SELECT method, mfa FROM sessions WHERE user_id = ? ORDER BY id DESC LIMIT 1');
    $statement->execute([$userId]);
    $row = $statement->fetch();

    return [$row['method'] ?? null, asBool($row['mfa'] ?? false)];
}

/** What is kept here of a token, by the hash of what Google signed: 'none', 'spent', or 'held for {account id}'. */
function tokenUse(string $token): string
{
    [$header, $payload] = explode('.', $token);
    $statement = iamDb()->prepare("SELECT COALESCE('held for ' || held_for_user, 'spent') FROM google_id_tokens_used WHERE token_hash = ?");
    $statement->execute([sha256("{$header}.{$payload}")]);
    $use = $statement->fetchColumn();

    return $use === false ? 'none' : (string) $use;
}

/** A Google sign-in's account signed in on the account page, from a browser of its own address. */
function accountPage(string $ip, string $username): Browser
{
    makeUser($username, "{$username}@example.test");
    $page = new Browser($ip);
    same(200, $page->signIn("{$username}@example.test", PASSWORD)->status, "{$username} signed in");

    return $page;
}

/** Connecting Google on the account page, with the password as the proof. */
function connectGoogle(Browser $page, string $token): TestResponse
{
    return $page->post('/api/account/google', ['credential' => $token, 'password' => PASSWORD]);
}

/** A setting, as the admin panel saves it. */
function setting(string $name, string $value): void
{
    iamDb()
        ->prepare('UPDATE settings SET value = ? WHERE name = ?')
        ->execute([$value, $name]);
    settingsAll(true);
}

/** An account with two-factor on: its secret. */
function twoFactorOn(int $userId): string
{
    $secret = totpSecret();
    iamDb()
        ->prepare('UPDATE users SET totp_enabled = TRUE, totp_secret = ? WHERE id = ?')
        ->execute([encryptSecret($secret), $userId]);

    return $secret;
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
function wrongCode(string $secret): string
{
    $counter = intdiv(time(), 30);
    $valid = [totpCode($secret, $counter - 1), totpCode($secret, $counter), totpCode($secret, $counter + 1)];
    for ($n = 1; ; $n++) {
        $code = str_pad((string) (($n * 7919 + 104729) % 1000000), 6, '0', STR_PAD_LEFT);
        if (!in_array($code, $valid, true)) {
            return $code;
        }
    }
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

test('a fresh IAM has 026: the table of the Google tokens used here - a hash, when it runs out, and the account whose sign-in holds it - and nothing in it', function () {
    $pdo = iamDb();
    check(in_array(GOOGLE_ONCE_MIGRATION, appliedMigrations($pdo), true), '026 recorded');
    check(googleTokensReady($pdo), 'the table is there');
    same(
        ['expires_at', 'held_for_user', 'token_hash'],
        $pdo->query("SELECT column_name FROM information_schema.columns WHERE table_name = 'google_id_tokens_used' ORDER BY column_name")->fetchAll(PDO::FETCH_COLUMN),
        'its three columns',
    );
    same(0, (int) $pdo->query('SELECT count(*) FROM google_id_tokens_used')->fetchColumn(), 'and nothing in it');
});

test(
    'the attack (the review of d0e34fb): a token stopped for its second factor is held for that account\'s sign-in - the account page refuses it to anybody who saw it, so the victim\'s Google is connected to nobody else - and the account finishes its sign-in with it',
    function () {
        // The victim: two-factor on, no Google yet - Google names the account by its address.
        $victimId = makeUser('victim', 'victim@example.test');
        $secret = twoFactorOn($victimId);
        $token = googleToken('google-victim', 'victim@example.test');
        same([403, 'totp_required'], [($r = googleSignIn('10.27.9.1', $token))->status, $r->code()], 'the victim\'s sign-in stops for the code');

        // Whoever saw the token, signed in to an account of their own, connects it there.
        $attacker = accountPage('10.27.9.2', 'attacker');
        $connect = connectGoogle($attacker, $token);
        same([400, 'invalid_token'], [$connect->status, $connect->code()], 'the account page refuses a token held for another account\'s sign-in - ' . $connect->body);
        same(null, identityOwner(iamDb(), IDENTITY_GOOGLE, 'google-victim'), 'the victim\'s Google connected to nobody');
        same("held for {$victimId}", tokenUse($token), 'still held for the victim\'s sign-in');

        // The victim, back with the code: signed in, the identity theirs.
        $through = googleSignIn('10.27.9.1', $token, ['totp' => rightCode($victimId, $secret)]);
        same(200, $through->status, 'the same token with the code signs the victim in - ' . $through->body);
        same($victimId, (int) (identityOwner(iamDb(), IDENTITY_GOOGLE, 'google-victim')['id'] ?? 0), 'Google attached to the victim\'s account');
        same('spent', tokenUse($token), 'and spent');
        same([400, 'invalid_token'], [($r = connectGoogle($attacker, $token))->status, $r->code()], 'refused to the account page for good');
    },
);

test('a refusal no second try cures spends the token at once - `unverified_email`, `registration_disabled` - so nothing takes it up after', function () {
    $attacker = accountPage('10.27.10.1', 'taker');

    $unverified = googleToken('google-unverified', 'unverified@example.test', ['email_verified' => false]);
    same([422, 'unverified_email'], [($r = googleSignIn('10.27.10.2', $unverified))->status, $r->code()], 'Google vouches for no address');
    same([400, 'invalid_token'], [($r = connectGoogle($attacker, $unverified))->status, $r->code()], 'the account page refuses it - ' . $r->body);
    same('spent', tokenUse($unverified), 'spent at once');

    setting('registration_enabled', 'false');
    try {
        $closed = googleToken('google-closed', 'closed@example.test');
        same([403, 'registration_disabled'], [($r = googleSignIn('10.27.10.3', $closed))->status, $r->code()], 'no new accounts at the moment');
        same([400, 'invalid_token'], [($r = connectGoogle(accountPage('10.27.10.4', 'othertaker'), $closed))->status, $r->code()], 'the account page refuses it - ' . $r->body);
        same('spent', tokenUse($closed), 'spent at once');
    } finally {
        setting('registration_enabled', 'true');
    }
    same(null, identityFor(iamDb(), (int) (userByEmail(iamDb(), 'taker@example.test')['id'] ?? 0), IDENTITY_GOOGLE), 'nothing connected to the one who saw them');
});

test(
    'a refusal of the account holds the token too - nothing else takes it - and the same account\'s sign-in finishes with it once the refusal lifts; a held token naming another account by then is refused',
    function () {
        // An account switched off, its Google attached: the sign-in refused, the token held.
        $offId = makeUser('switchedoff', 'switchedoff@example.test');
        attachIdentity(iamDb(), $offId, IDENTITY_GOOGLE, 'google-switchedoff', 'switchedoff@example.test');
        iamDb()->exec("UPDATE users SET disabled = TRUE WHERE id = {$offId}");
        $token = googleToken('google-switchedoff', 'switchedoff@example.test');
        same([403, 'account_disabled'], [($r = googleSignIn('10.27.11.1', $token))->status, $r->code()], 'switched off');
        same("held for {$offId}", tokenUse($token), 'held for its sign-in');
        same([400, 'invalid_token'], [($r = connectGoogle(accountPage('10.27.11.2', 'bystander'), $token))->status, $r->code()], 'the account page refuses it');
        iamDb()->exec("UPDATE users SET disabled = FALSE WHERE id = {$offId}");
        same(200, googleSignIn('10.27.11.1', $token)->status, 'switched on again: the same token signs it in');
        same('spent', tokenUse($token), 'and is spent');

        // Held for one account, then naming another - the first account's address moved, another account's took it.
        $firstId = makeUser('firstholder', 'moving@example.test');
        $secret = twoFactorOn($firstId);
        $moving = googleToken('google-moving', 'moving@example.test');
        same([403, 'totp_required'], [($r = googleSignIn('10.27.11.3', $moving))->status, $r->code()], 'held for the first account\'s sign-in');
        iamDb()->exec("UPDATE users SET email = 'moved-away@example.test' WHERE id = {$firstId}");
        $secondId = makeUser('secondholder', 'moving@example.test');
        $refused = googleSignIn('10.27.11.4', $moving, ['totp' => rightCode($firstId, $secret)]);
        same([400, 'invalid_token'], [$refused->status, $refused->code()], 'naming another account now: refused - ' . $refused->body);
        same([null, "held for {$firstId}"], [identityFor(iamDb(), $secondId, IDENTITY_GOOGLE), tokenUse($moving)], 'nothing attached, the hold as it was');
    },
);

test('the hold is claimed once, by the account it is held for; an account deleted - or a row of it deleted outright - leaves it spent', function () {
    $pdo = iamDb();
    $heldId = makeUser('heldone', 'heldone@example.test');
    $otherId = makeUser('otherone', 'otherone@example.test');
    $token = googleToken('google-heldone', 'heldone@example.test');
    $claims = googleIdTokenClaims($token);
    check($claims !== null, 'a token that verifies');

    same(
        [true, true, false],
        [holdGoogleIdToken($pdo, $token, $claims, $heldId), holdGoogleIdToken($pdo, $token, $claims, $heldId), holdGoogleIdToken($pdo, $token, $claims, $otherId)],
        'held for one account - again for it, never for another',
    );
    same(null, googleVerifyIdToken($token), 'held, it verifies for nothing else');
    same(
        [false, false, true, false],
        [
            spendGoogleIdToken($pdo, $token, $claims, $otherId),
            spendGoogleIdToken($pdo, $token, $claims),
            spendGoogleIdToken($pdo, $token, $claims, $heldId),
            spendGoogleIdToken($pdo, $token, $claims, $heldId),
        ],
        'spent neither for another account nor as a new token - by its own account\'s sign-in, once',
    );

    // An account deleted: its holds spent, nothing taking them up.
    $gone = googleToken('google-goneone', 'goneone@example.test');
    $goneId = makeUser('goneone', 'goneone@example.test');
    check(holdGoogleIdToken($pdo, $gone, (array) googleIdTokenClaims($gone), $goneId), 'held for an account');
    $pdo->prepare(
        "UPDATE users SET disabled = TRUE, password = NULL, password_login_enabled = FALSE, email_confirmed = FALSE,
                    deletion_requested_at = CURRENT_TIMESTAMP - INTERVAL '30 days', deletion_due_at = CURRENT_TIMESTAMP, anonymized_at = CURRENT_TIMESTAMP
              WHERE id = ?",
    )->execute([$goneId]);
    anonymizedLeftovers($pdo);
    same('spent', tokenUse($gone), 'deleted: the hold spent');

    $erased = googleToken('google-erased', 'erased@example.test');
    $erasedId = makeUser('erasedone', 'erased@example.test');
    check(holdGoogleIdToken($pdo, $erased, (array) googleIdTokenClaims($erased), $erasedId), 'held for another');
    $pdo->prepare('DELETE FROM user_roles WHERE user_id = ?')->execute([$erasedId]);
    $pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$erasedId]);
    same('spent', tokenUse($erased), 'its row deleted outright: spent, not freed');
});

test('the attack: a Google token that signed somebody in signs nobody in again - from another browser within its hour, nor with its signature written another way', function () {
    $token = googleToken('google-replayed', 'replayed@example.test');
    $first = googleSignIn('10.27.1.1', $token);
    same([201, true], [$first->status, $first->json()['created'] ?? null], 'the token makes the account and signs it in: ' . $first->body);
    $id = (int) ($first->json()['user']['id'] ?? 0);
    same(1, sessionsOf($id), 'one session');

    $thief = new Browser('10.27.1.2');
    $again = $thief->post('/api/login/google', ['credential' => $token]);
    same([400, 'invalid_token'], [$again->status, $again->code()], 'the same token from another browser: refused');
    same([], $thief->cookies, 'no cookie of any kind');
    same([400, 'invalid_token'], [($r = googleSignIn('10.27.1.3', rewrittenSignature($token)))->status, $r->code()], 'its signature written another way: refused too');
    same(1, sessionsOf($id), 'no session opened by either');
    same('spent', tokenUse($token), 'the token kept as spent');

    // Written another way the signature is Google's all the same - one is the other: whichever comes first spends both.
    $fresh = googleToken('google-replayed', 'replayed@example.test');
    check(googleVerifyIdToken(rewrittenSignature($fresh)) !== null, 'a signature written another way verifies');
    same(200, googleSignIn('10.27.1.4', rewrittenSignature($fresh))->status, 'and signs in, once');
    same([400, 'invalid_token'], [($r = googleSignIn('10.27.1.5', $fresh))->status, $r->code()], 'after which the token as Google wrote it is refused');
    same(2, sessionsOf($id), 'two sign-ins, two tokens, two sessions');
});

test('a token older than five minutes is refused - at the sign-in and on the account page -, as is one from further ahead than the clocks\' minute, and one with no iat', function () {
    $email = 'stale@example.test';
    $spent = static fn(): int => (int) iamDb()->query('SELECT count(*) FROM google_id_tokens_used')->fetchColumn();
    $before = $spent();

    // Five minutes and the clocks' minute: six and a few seconds is too old, a minute and a few seconds ahead too far.
    foreach (
        [
            'issued six minutes ago' => ['iat' => time() - 365],
            'issued two minutes ahead of the clock' => ['iat' => time() + 120],
            'with no iat' => ['iat' => null],
            'with an iat that is no number' => ['iat' => (string) time()],
        ]
        as $what => $claims
    ) {
        $answer = googleSignIn('10.27.2.1', googleToken('google-stale', $email, $claims));
        same([400, 'invalid_token'], [$answer->status, $answer->code()], "{$what}: refused");
    }
    same(null, userByEmail(iamDb(), $email), 'no account made by any of them');
    same($before, $spent(), 'none of them spent');

    same(201, googleSignIn('10.27.2.2', googleToken('google-stale', $email, ['iat' => time() - 290]))->status, 'nearly five minutes old: taken');
    same(200, googleSignIn('10.27.2.3', googleToken('google-stale', $email, ['iat' => time() + 30]))->status, 'half a minute ahead: within the clocks\' minute');

    // The account page: connecting Google takes a fresh token too.
    $holderId = makeUser('holder', 'holder@example.test');
    $holder = new Browser('10.27.2.4');
    same(200, $holder->signIn('holder@example.test', PASSWORD)->status, 'signed in');
    $stale = $holder->post('/api/account/google', ['credential' => googleToken('google-holder', 'holder@example.test', ['iat' => time() - 365]), 'password' => PASSWORD]);
    same([400, 'invalid_token'], [$stale->status, $stale->code()], 'connecting Google with a token six minutes old: refused');
    same(null, identityFor(iamDb(), $holderId, IDENTITY_GOOGLE), 'nothing connected');
    same(200, $holder->post('/api/account/google', ['credential' => googleToken('google-holder', 'holder@example.test'), 'password' => PASSWORD])->status, 'a fresh one connects');

    same([300, 60], [GOOGLE_ID_TOKEN_MAX_AGE, GOOGLE_CLOCK_SKEW], 'the code\'s five minutes, and a minute either way');
});

test(
    'a sign-in stopped for its second factor spends nothing - it holds the token for that account: the page sends the same token again with the code - and once that opens a session, it is spent',
    function () {
        $id = makeUser('guarded', 'guarded@example.test');
        attachIdentity(iamDb(), $id, IDENTITY_GOOGLE, 'google-guarded', 'guarded@example.test');
        $secret = twoFactorOn($id);
        $token = googleToken('google-guarded', 'guarded@example.test');

        same([403, 'totp_required'], [($r = googleSignIn('10.27.3.1', $token))->status, $r->code()], 'the code asked for');
        same([403, 'totp_invalid'], [($r = googleSignIn('10.27.3.1', $token, ['totp' => wrongCode($secret)]))->status, $r->code()], 'a wrong one');
        same("held for {$id}", tokenUse($token), 'nothing spent yet: held for this account\'s sign-in');
        same(0, sessionsOf($id), 'nor a session');

        $through = googleSignIn('10.27.3.1', $token, ['totp' => rightCode($id, $secret)]);
        same(200, $through->status, 'the same token with the right code: signed in - ' . $through->body);
        same(['google', true], newestSession($id), 'a session of two factors');
        $again = googleSignIn('10.27.3.2', $token, ['totp' => rightCode($id, $secret)]);
        same([400, 'invalid_token'], [$again->status, $again->code()], 'and never again, whatever code comes with it');
        same(1, sessionsOf($id), 'one session');
        same('spent', tokenUse($token), 'spent by the sign-in');
    },
);

test(
    'every way a token changes something spends it - an account made, an identity attached by its address, Google connected on the account page - and spent one way, it is spent for the others',
    function () {
        // Attached to the account its verified address names.
        $addressedId = makeUser('addressed', 'addressed@example.test');
        $attached = googleToken('google-addressed', 'addressed@example.test');
        same(200, googleSignIn('10.27.4.1', $attached)->status, 'attached by the address, and signed in');
        same('google-addressed', identityFor(iamDb(), $addressedId, IDENTITY_GOOGLE)['subject'] ?? null, 'the identity attached');
        same([400, 'invalid_token'], [($r = googleSignIn('10.27.4.2', $attached))->status, $r->code()], 'spent');

        // Connected on the account page: the token signs nobody in afterwards.
        $connectorId = makeUser('connector', 'connector@example.test');
        $connector = new Browser('10.27.4.3');
        same(200, $connector->signIn('connector@example.test', PASSWORD)->status, 'signed in');
        $connected = googleToken('google-connector', 'elsewhere@example.test');
        same(200, $connector->post('/api/account/google', ['credential' => $connected, 'password' => PASSWORD])->status, 'Google connected');
        same('google-connector', identityFor(iamDb(), $connectorId, IDENTITY_GOOGLE)['subject'] ?? null, 'to this account');
        same([400, 'invalid_token'], [($r = googleSignIn('10.27.4.4', $connected))->status, $r->code()], 'the token that connected it signs nobody in');

        // A token that made an account connects nothing on another's page.
        $made = googleToken('google-maker', 'maker@example.test');
        same(201, googleSignIn('10.27.4.5', $made)->status, 'an account made');
        $otherId = makeUser('other', 'other@example.test');
        $other = new Browser('10.27.4.6');
        same(200, $other->signIn('other@example.test', PASSWORD)->status, 'another account signed in');
        $connect = $other->post('/api/account/google', ['credential' => $made, 'password' => PASSWORD]);
        same([400, 'invalid_token'], [$connect->status, $connect->code()], 'the token, spent, connects nothing');
        same(null, identityFor(iamDb(), $otherId, IDENTITY_GOOGLE), 'nothing connected');

        // A refusal that changes nothing spends nothing - but the account page holds the token for the account
        // that sent it, from the first (the review of 632a464): Google connected to another account already, the
        // token is no longer free for that Google account's own sign-in either. Google's button gives a fresh one.
        $spare = googleToken('google-connector', 'elsewhere@example.test');
        same([409, 'google_taken'], [($r = $other->post('/api/account/google', ['credential' => $spare, 'password' => PASSWORD]))->status, $r->code()], 'connected to another account already');
        same("held for {$otherId}", tokenUse($spare), 'not spent by the refusal: held for the account that sent it');
        same([400, 'invalid_token'], [($r = googleSignIn('10.27.4.7', $spare))->status, $r->code()], 'so not taken by its owner\'s sign-in either');
        same(200, googleSignIn('10.27.4.8', googleToken('google-connector', 'elsewhere@example.test'))->status, 'a fresh token signs its owner in');
    },
);

test(
    'the attack (the review of 632a464): a token waiting on the account page for the right password is held for that account - whoever saw it connects nothing - and the retry connects it; so after a stale sign-in, the limiter and a 409',
    function () {
        $victim = accountPage('10.27.12.1', 'pagevictim');
        $victimId = (int) (userByEmail(iamDb(), 'pagevictim@example.test')['id'] ?? 0);
        $token = googleToken('google-pagevictim', 'pagevictim-google@example.test');
        $wrong = $victim->post('/api/account/google', ['credential' => $token, 'password' => 'not the password']);
        same([422, 'wrong_password'], [$wrong->status, $wrong->code()], 'the victim mistypes the password');

        $attacker = accountPage('10.27.12.2', 'pageattacker');
        $taken = connectGoogle($attacker, $token);
        same([400, 'invalid_token'], [$taken->status, $taken->code()], 'whoever saw the token connects nothing - ' . $taken->body);
        same(null, identityOwner(iamDb(), IDENTITY_GOOGLE, 'google-pagevictim'), 'the victim\'s Google connected to nobody');
        same("held for {$victimId}", tokenUse($token), 'held for the victim\'s account');
        same([400, 'invalid_token'], [($r = googleSignIn('10.27.12.3', $token))->status, $r->code()], 'nor signs anybody in');

        same(200, connectGoogle($victim, $token)->status, 'the victim\'s retry, the right password with the same token: connected');
        same([$victimId, 'spent'], [(int) (identityOwner(iamDb(), IDENTITY_GOOGLE, 'google-pagevictim')['id'] ?? 0), tokenUse($token)], 'to the victim\'s account, the token spent');
        $later = googleSignIn('10.27.12.4', googleToken('google-pagevictim', 'pagevictim-google@example.test'));
        same([200, $victimId], [$later->status, (int) ($later->json()['user']['id'] ?? 0)], 'and the victim\'s Google signs the victim in');

        // A sign-in too old to stand for an account without a password.
        $staleId = makeUser('stalepage', 'stalepage@example.test');
        $stale = new Browser('10.27.12.5');
        same(200, $stale->signIn('stalepage@example.test', PASSWORD)->status, 'signed in');
        iamDb()->exec("UPDATE users SET password = NULL, password_login_enabled = FALSE WHERE id = {$staleId}");
        iamDb()->exec("UPDATE sessions SET auth_time = CURRENT_TIMESTAMP - INTERVAL '1 hour' WHERE user_id = {$staleId}");
        $staleToken = googleToken('google-stalepage', 'stalepage-google@example.test');
        same([403, 'reauthentication_required'], [($r = $stale->post('/api/account/google', ['credential' => $staleToken]))->status, $r->code()], 'a sign-in of more than fifteen minutes ago');
        same([400, 'invalid_token'], [($r = connectGoogle(accountPage('10.27.12.6', 'staletaker'), $staleToken))->status, $r->code()], 'its token held: nobody else connects it');

        // The limiter.
        $limitedId = makeUser('limitedpage', 'limitedpage@example.test');
        $limited = new Browser('10.27.12.7');
        same(200, $limited->signIn('limitedpage@example.test', PASSWORD)->status, 'signed in');
        $fail = iamDb()->prepare("INSERT INTO login_attempts (email, user_id, ip, method, outcome) VALUES ('somebody@example.test', NULL, '10.27.12.7', 'password', 'bad_password')");
        for ($n = 0; $n < ATTEMPT_MAX_PER_IP; $n++) {
            $fail->execute();
        }
        $limitedToken = googleToken('google-limitedpage', 'limitedpage-google@example.test');
        same([429, 'too_many_attempts'], [($r = connectGoogle($limited, $limitedToken))->status, $r->code()], 'too many attempts from its address');
        same([400, 'invalid_token'], [($r = connectGoogle(accountPage('10.27.12.8', 'limitedtaker'), $limitedToken))->status, $r->code()], 'its token held: nobody else connects it');
        same("held for {$limitedId}", tokenUse($limitedToken), 'held for the account that sent it');

        // A 409 after the proof: Google connected to this account already.
        $twiceId = makeUser('connectedtwice', 'connectedtwice@example.test');
        attachIdentity(iamDb(), $twiceId, IDENTITY_GOOGLE, 'google-first', 'first@example.test');
        $twice = new Browser('10.27.12.9');
        same(200, $twice->signIn('connectedtwice@example.test', PASSWORD)->status, 'signed in');
        $second = googleToken('google-second', 'second@example.test');
        same([409, 'google_already_connected'], [($r = connectGoogle($twice, $second))->status, $r->code()], 'Google connected already');
        same([400, 'invalid_token'], [($r = connectGoogle(accountPage('10.27.12.10', 'twicetaker'), $second))->status, $r->code()], 'its token held: nobody else connects it');
    },
);

test(
    'the account page connects no Google account whose address Google vouches for another account here has confirmed - the sign-in would attach it there - and the token stays held for the one who tried; an address registered and never confirmed keeps nobody from it',
    function () {
        $ownerId = makeUser('addressowner', 'addressowner@example.test');
        $tryerId = makeUser('notowner', 'notowner@example.test');
        $tryer = new Browser('10.27.13.1');
        same(200, $tryer->signIn('notowner@example.test', PASSWORD)->status, 'signed in');

        $token = googleToken('google-addressowner', 'AddressOwner@example.test');
        $refused = connectGoogle($tryer, $token);
        same([409, 'email_taken'], [$refused->status, $refused->code()], 'the address is another account\'s, whatever its case - ' . $refused->body);
        same([null, "held for {$tryerId}"], [identityOwner(iamDb(), IDENTITY_GOOGLE, 'google-addressowner'), tokenUse($token)], 'connected to nobody, the token held for the one who tried');

        // The account whose address it is connects it.
        $owner = new Browser('10.27.13.2');
        same(200, $owner->signIn('addressowner@example.test', PASSWORD)->status, 'the address\'s own account signed in');
        same(200, connectGoogle($owner, googleToken('google-addressowner', 'addressowner@example.test'))->status, 'connects it, with a fresh token');
        same($ownerId, (int) (identityOwner(iamDb(), IDENTITY_GOOGLE, 'google-addressowner')['id'] ?? 0), 'to the account whose address it is');

        // Nobody's address: one Google does not vouch for, and one a stranger registered and never confirmed.
        same(
            200,
            connectGoogle(accountPage('10.27.13.3', 'unvouched'), googleToken('google-unvouched', 'notowner@example.test', ['email_verified' => false]))->status,
            'an address Google does not vouch for names nobody',
        );
        $squatterId = makeUser('squatter', 'squatted@example.test', PASSWORD, false);
        $ownGoogle = accountPage('10.27.13.4', 'squattedowner');
        same(200, connectGoogle($ownGoogle, googleToken('google-squatted', 'squatted@example.test'))->status, 'the address\'s owner connects their Google past a stranger\'s unconfirmed registration');
        same(
            [(int) (userByEmail(iamDb(), 'squattedowner@example.test')['id'] ?? 0), null],
            [(int) (identityOwner(iamDb(), IDENTITY_GOOGLE, 'google-squatted')['id'] ?? 0), identityFor(iamDb(), $squatterId, IDENTITY_GOOGLE)],
            'to their own account',
        );
    },
);

test('a confirmed account found by its address (no Google yet) and switched off: refused before anything changed - the token held, nothing attached, and the account page refuses it', function () {
    $id = makeUser('offbyaddress', 'offbyaddress@example.test');
    iamDb()->exec("UPDATE users SET disabled = TRUE WHERE id = {$id}");
    $token = googleToken('google-offbyaddress', 'offbyaddress@example.test');

    same([403, 'account_disabled'], [($r = googleSignIn('10.27.14.1', $token))->status, $r->code()], 'switched off');
    same(["held for {$id}", null], [tokenUse($token), identityFor(iamDb(), $id, IDENTITY_GOOGLE)], 'held, not spent; nothing attached');
    same([400, 'invalid_token'], [($r = connectGoogle(accountPage('10.27.14.2', 'bystanderone'), $token))->status, $r->code()], 'the account page refuses it');
});

test('the limiter\'s 429 on a token naming an account with two-factor on leaves it held for that account, and the account page refuses it', function () {
    $id = makeUser('limited', 'limited@example.test');
    twoFactorOn($id);
    $fail = iamDb()->prepare("INSERT INTO login_attempts (email, user_id, ip, method, outcome) VALUES ('someone@example.test', NULL, '10.27.15.1', 'password', 'bad_password')");
    for ($n = 0; $n < ATTEMPT_MAX_PER_IP; $n++) {
        $fail->execute();
    }
    $token = googleToken('google-limited', 'limited@example.test');

    same([429, 'too_many_attempts'], [($r = googleSignIn('10.27.15.1', $token))->status, $r->code()], 'too many attempts from its address');
    same("held for {$id}", tokenUse($token), 'held, before the limiter was asked');
    same([400, 'invalid_token'], [($r = connectGoogle(accountPage('10.27.15.2', 'takertwo'), $token))->status, $r->code()], 'the account page refuses it');
});

test('two requests with one token: the database lets one of them spend it - and what is kept is the hash of what Google signed, until the token runs out', function () {
    $token = googleToken('google-raced', 'raced@example.test');
    $claims = googleVerifyIdToken($token);
    check($claims !== null, 'a token that verifies');

    same([true, false], [spendGoogleIdToken(iamDb(), $token, $claims), spendGoogleIdToken(iamDb(), $token, $claims)], 'the first spends it, the second is told it did not');
    [$header, $payload] = explode('.', $token);
    $kept = iamDb()->prepare('SELECT token_hash, extract(epoch FROM expires_at)::bigint AS until FROM google_id_tokens_used WHERE token_hash = ?');
    $kept->execute([sha256("{$header}.{$payload}")]);
    $row = $kept->fetch();
    check($row !== false, 'kept by the SHA-256 of header.payload - what Google signed');
    same($claims['exp'] + GOOGLE_CLOCK_SKEW, (int) $row['until'], 'until it runs out, with the clocks\' minute');
    same(null, googleVerifyIdToken($token), 'and it verifies no more');
    same(null, googleVerifyIdToken(rewrittenSignature($token)), 'written any way');
});

test('housekeeping sweeps a token that ran out - refused as expired anyway - and keeps one that has not', function () {
    $pdo = iamDb();
    $pdo->exec('DELETE FROM google_id_tokens_used');
    $pdo->exec(
        "INSERT INTO google_id_tokens_used (token_hash, expires_at) VALUES ('" .
            str_repeat('a', 64) .
            "', CURRENT_TIMESTAMP - INTERVAL '1 minute'), ('" .
            str_repeat('b', 64) .
            "', CURRENT_TIMESTAMP + INTERVAL '1 hour')",
    );

    @unlink(iamStorageDir() . '/cache/housekeeping.stamp');
    iamHousekeeping();

    same([str_repeat('b', 64)], $pdo->query('SELECT token_hash FROM google_id_tokens_used')->fetchAll(PDO::FETCH_COLUMN), 'the one that ran out swept, the other kept');
});

test('with 026 pending a token is held to its five minutes alone - the same one signs in twice, as before 026 - and Apply brings the memory in the same process', function () {
    $main = testEnvironment()['db'];
    buildIamThrough('_google_window', BEFORE_GOOGLE_ONCE);
    useIamDatabase($main . '_google_window');

    try {
        same(false, googleTokensReady(iamDb()), 'no table yet');
        $token = googleToken('google-window', 'window@example.test');
        same(201, googleSignIn('10.27.7.1', $token)->status, 'an account made');
        same(200, googleSignIn('10.27.7.2', $token)->status, 'the same token again: nowhere to remember it, as before 026');
        $stale = googleSignIn('10.27.7.3', googleToken('google-window', 'window@example.test', ['iat' => time() - GOOGLE_ID_TOKEN_MAX_AGE - GOOGLE_CLOCK_SKEW - 5]));
        same([400, 'invalid_token'], [$stale->status, $stale->code()], 'one six minutes old is refused all the same');

        $result = applyPendingMigrations(iamDb());
        same(null, $result['failed'], 'Apply: ' . ($result['error'] ?? ''));
        same(GOOGLE_ONCE_MIGRATION, $result['applied'][0] ?? null, '026 the first applied');
        check(googleTokensReady(iamDb()), 'the table, in the same process');
        $once = googleToken('google-window', 'window@example.test');
        same(200, googleSignIn('10.27.7.4', $once)->status, 'signed in');
        same([400, 'invalid_token'], [($r = googleSignIn('10.27.7.5', $once))->status, $r->code()], 'and spent');
    } finally {
        useIamDatabase($main);
    }

    same($main, iamDb()->query('SELECT current_database()')->fetchColumn(), 'back on this run\'s own database');
});

finishTests();
