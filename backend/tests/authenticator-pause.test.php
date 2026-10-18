<?php

/**
 * The authenticator's pause (migration 019, the owner's decision of
 * 2026-10-13): reaching the authenticator step means the first one was
 * passed - the password, as a rule - and six digits are all that is left.
 * After five wrong codes for an account - counted for the account, whatever
 * address they come from, within a rolling fifteen minutes, from nought again
 * after a right code or a completed sign-in - its codes are refused unchecked
 * for fifteen minutes from the fifth, on every way in that takes one. A
 * recovery code and a remembered browser still work; the password's sign-in
 * may finish with a code mailed to the address instead - the password first,
 * then the mailbox -, counted as the authenticator's codes are: five wrong
 * ones in a pause and none stands in until it ends. Such a sign-in is a
 * session of its own method, its `amr` pwd, email, mfa. The owner is mailed
 * once a pause. With 019 pending the step is checked as before.
 *
 * The attacks: a guesser holding the password who spreads guesses over many
 * addresses past the per-address limiter; one who keeps guessing once paused;
 * one holding the password alone who guesses at the mailed code instead - has
 * new ones mailed, or sends a burst of guesses at once.
 */

declare(strict_types=1);

require __DIR__ . '/lib.php';

// This run's own database is a fresh IAM, built from every migration, 019 included.
bootTests();

const PASSWORD = 'correct horse';
const PAUSED_SUBJECT = 'Wrong codes for your anotoki account';
const PAUSE_MIGRATION = '019_authenticator_pause.sql';
const GOOGLE_TEST_CLIENT = 'iam-test.apps.googleusercontent.com';
const GENSHIN_CALLBACK = 'http://localhost:4200/auth/callback';
const GENSHIN_ORIGIN = 'http://localhost:4200';

/** An account with two-factor on: [id, its secret, its ten recovery codes]. */
function twoFactorAccount(string $name): array
{
    $id = makeUser($name, "{$name}@example.test", PASSWORD);
    $secret = totpSecret();
    iamDb()
        ->prepare('UPDATE users SET totp_enabled = TRUE, totp_secret = ? WHERE id = ?')
        ->execute([encryptSecret($secret), $id]);

    return [$id, $secret, generateRecoveryCodes(iamDb(), $id)];
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

/** The account's code now, as its app shows it - with what was accepted before forgotten, so it is good once more. */
function rightCode(int $userId, string $secret): string
{
    iamDb()
        ->prepare('UPDATE users SET totp_last_step = NULL WHERE id = ?')
        ->execute([$userId]);

    return totpCode($secret, intdiv(time(), 30));
}

/** A password sign-in, from a browser of its own address. */
function passwordSignIn(string $ip, string $email, array $extra = [], string $password = PASSWORD): TestResponse
{
    return (new Browser($ip))->post('/api/login/password', ['email' => $email, 'password' => $password] + $extra);
}

/** Five wrong codes after the right password, each from an address of its own: the fifth answer. */
function pauseByGuessing(string $email, string $secret, string $network): TestResponse
{
    for ($guess = 1; $guess < AUTHENTICATOR_PAUSE_AFTER; $guess++) {
        same([403, 'totp_invalid'], [($r = passwordSignIn("{$network}.{$guess}", $email, ['totp' => wrongCode($secret, $guess)]))->status, $r->code()], "wrong code {$guess} - not paused yet");
    }

    return passwordSignIn("{$network}.99", $email, ['totp' => wrongCode($secret, 99)]);
}

/** When the account's codes are paused until - the column as it is - or null. */
function pausedUntilOf(int $userId): ?string
{
    $statement = iamDb()->prepare('SELECT totp_paused_until FROM users WHERE id = ?');
    $statement->execute([$userId]);
    $until = $statement->fetchColumn();

    return $until === false || $until === null ? null : (string) $until;
}

/** The outcomes of an account's sign-in attempts, oldest first. */
function outcomesOf(int $userId): array
{
    $statement = iamDb()->prepare('SELECT outcome FROM login_attempts WHERE user_id = ? ORDER BY id');
    $statement->execute([$userId]);

    return $statement->fetchAll(PDO::FETCH_COLUMN);
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

/** The pause mails to an address, oldest first. */
function pausedMails(string $email, string $subject = PAUSED_SUBJECT): array
{
    return array_values(array_filter(mailsTo($email), static fn(array $mail): bool => $mail['subject'] === $subject));
}

/** A sign-in code mailed to an address - asked for from a browser, read from its subject. */
function mailedCode(string $email, string $ip): string
{
    same(200, (new Browser($ip))->post('/api/login/code', ['email' => $email])->status, 'a code asked for');
    $codes = [];
    foreach (mailsTo($email) as $mail) {
        if (preg_match('/sign-in code: (\d{6})$/', $mail['subject'], $found) === 1) {
            $codes[] = $found[1];
        }
    }
    check($codes !== [], "a sign-in code was mailed to {$email}");

    return end($codes);
}

/** Fifteen minutes and a second pass for an account: its pause, its count and its attempts all move back by as much. */
function fifteenMinutesPass(int $userId): void
{
    $pdo = iamDb();
    $pdo->prepare("UPDATE users SET totp_paused_until = totp_paused_until - INTERVAL '901 seconds', totp_count_from = totp_count_from - INTERVAL '901 seconds' WHERE id = ?")->execute([$userId]);
    $pdo->prepare("UPDATE login_attempts SET created_at = created_at - INTERVAL '901 seconds' WHERE user_id = ?")->execute([$userId]);
}

/** A reset link mailed to an address: its token. */
function resetTokenFor(string $email, string $ip): string
{
    (new Browser($ip))->post('/api/password/forgot', ['email' => $email]);
    $token = null;
    foreach (mailsTo($email) as $mail) {
        if (preg_match('/token=([0-9a-f]{64})/', $mail['body'], $link) === 1) {
            $token = $link[1];
        }
    }
    check($token !== null, "a reset link was mailed to {$email}");

    return $token;
}

/**
 * A Google id token for this IAM, signed by a key it is given as Google's (the
 * key cache in this run's storage, read before Google would be asked), and a
 * client id in this run's configuration.
 */
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
                'keys' => ['pause-test' => ['kty' => 'RSA', 'kid' => 'pause-test', 'n' => $pair['n'], 'e' => $pair['e']]],
            ]),
        );
        writeTestConfig('google', ['client_id' => GOOGLE_TEST_CLIENT]);
        forgetLocalConfig();
    }

    $header = base64UrlEncode((string) json_encode(['alg' => 'RS256', 'kid' => 'pause-test', 'typ' => 'JWT']));
    $payload = base64UrlEncode(
        (string) json_encode([
            'iss' => 'https://accounts.google.com',
            'aud' => GOOGLE_TEST_CLIENT,
            'sub' => $subject,
            'email' => $email,
            'email_verified' => true,
            'name' => $subject,
            'iat' => time(),
            'exp' => time() + 600,
        ]),
    );
    openssl_sign("{$header}.{$payload}", $signature, $key, OPENSSL_ALGO_SHA256);

    return "{$header}.{$payload}." . base64UrlEncode($signature);
}

/** Six digits that are not $code: its neighbour $step on. */
function besides(string $code, int $step): string
{
    return str_pad((string) (((int) $code + $step) % 1000000), 6, '0', STR_PAD_LEFT);
}

/** The newest session of an account: [its method, whether two factors]. */
function newestSession(int $userId): array
{
    $row = iamDb()
        ->query("SELECT method, mfa FROM sessions WHERE user_id = {$userId} ORDER BY id DESC LIMIT 1")
        ->fetch();

    return [$row['method'] ?? null, asBool($row['mfa'] ?? false)];
}

/** How many of an account's sign-in attempts ended so. */
function attemptsEnded(int $userId, string $outcome): int
{
    return count(array_filter(outcomesOf($userId), static fn(string $kept): bool => $kept === $outcome));
}

/**
 * A sign-in as an application sees it - authorize for genshin, the password's
 * sign-in with $signIn beside the password, accept, the code exchanged - and
 * the `amr` of the ID token it gets.
 */
function amrOfSignIn(string $ip, string $email, array $signIn): array
{
    $browser = new Browser($ip);
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

    $signedIn = $browser->post('/api/login/password', ['email' => $email, 'password' => PASSWORD] + $signIn);
    same(200, $signedIn->status, 'signed in: ' . $signedIn->body);
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

    return is_array($claims) ? $claims['amr'] ?? [] : [];
}

/**
 * A script that sends one guess as a browser would - the password's sign-in
 * with a code by email, through the app - in a process of its own, so it can
 * wait at the account's row while a test holds it. Its connection is named for
 * pg_stat_activity; its address and code are its arguments; it says the
 * answer's status and code alone.
 */
function emailCodeGuessScript(string $email): string
{
    $environment = testEnvironment();
    $script = $environment['base'] . DIRECTORY_SEPARATOR . 'email-code-guess-' . md5($email) . '.php';
    file_put_contents(
        $script,
        "<?php\n" .
            "define('IAM_CONFIG_DIR', " .
            var_export($environment['config'], true) .
            ");\n" .
            "define('IAM_STORAGE_DIR', " .
            var_export($environment['storage'], true) .
            ");\n" .
            "define('IAM_NO_RUN', true);\n" .
            'require ' .
            var_export(dirname(__DIR__) . DIRECTORY_SEPARATOR . 'api' . DIRECTORY_SEPARATOR . 'index.php', true) .
            ";\n" .
            "iamDb()->exec(\"SET application_name = 'iam-email-code-guess'\");\n" .
            '$body = (new Slim\Psr7\Factory\StreamFactory())->createStream((string) json_encode(["email" => ' .
            var_export($email, true) .
            ', "password" => ' .
            var_export(PASSWORD, true) .
            ', "email_code" => $argv[2]]));' .
            "\n" .
            '$request = (new Slim\Psr7\Factory\ServerRequestFactory())->createServerRequest("POST", ' .
            var_export(TEST_ISSUER . '/api/login/password', true) .
            ', ["REMOTE_ADDR" => $argv[1]])' .
            "\n" .
            '    ->withHeader("Content-Type", "application/json")->withHeader("X-IAM-Request", "1")->withBody($body);' .
            "\n" .
            '$response = buildIamApp()->handle($request);' .
            "\n" .
            'echo $response->getStatusCode(), " ", json_decode((string) $response->getBody(), true)["code"] ?? "";' .
            "\n",
    );

    return $script;
}

/**
 * A connection that runs a hook once, right after its next BEGIN: another
 * transaction's moment, between this one's start and its first read.
 */
final class HookAfterBeginPdo extends PDO
{
    public ?Closure $afterBegin = null;

    public function beginTransaction(): bool
    {
        $begun = parent::beginTransaction();
        if ($this->afterBegin !== null) {
            [$hook, $this->afterBegin] = [$this->afterBegin, null];
            $hook();
        }

        return $begun;
    }
}

/** One guess started in a process of its own: [the process, its pipes]. */
function startGuess(string $script, string $ip, string $code): array
{
    $child = proc_open([PHP_BINARY, $script, $ip, $code], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    check(is_resource($child), "a guess from {$ip} started");

    return [$child, $pipes];
}

/** How many guesses wait at a lock, once $count do - or after a minute, however many then. */
function guessesWaiting(int $count): int
{
    $waiting = iamDb()->prepare("SELECT count(*) FROM pg_stat_activity WHERE application_name = 'iam-email-code-guess' AND wait_event_type = 'Lock'");
    for ($tries = 0; $tries < 1200; $tries++) {
        $waiting->execute();
        if ((int) $waiting->fetchColumn() === $count) {
            return $count;
        }
        usleep(50000);
    }
    $waiting->execute();

    return (int) $waiting->fetchColumn();
}

/** A guess's answer once its process ends: [its status and code, what it said wrong]. */
function guessAnswer(array $guess): array
{
    [$child, $pipes] = $guess;
    $answer = trim((string) stream_get_contents($pipes[1]));
    $errors = trim((string) stream_get_contents($pipes[2]));
    proc_close($child);

    return [$answer, $errors];
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

test('a fresh IAM has 019: the two columns, nobody paused or counted from, and the words in English and Slovak', function () {
    check(authenticatorPauseReady(iamDb()), 'the columns are there');
    same(0, (int) iamDb()->query('SELECT count(*) FROM users WHERE totp_paused_until IS NOT NULL OR totp_count_from IS NOT NULL')->fetchColumn(), 'nobody paused');
    check(in_array(PAUSE_MIGRATION, appliedMigrations(iamDb()), true), 'recorded as applied');

    $words = iamDb()
        ->query(
            "SELECT key_name, language_code FROM translations WHERE key_name LIKE 'login.paused.%' OR key_name LIKE 'mail.authenticatorPaused.%'
                                OR key_name IN ('error.authenticatorPaused', 'sessions.method.password_email')",
        )
        ->fetchAll(PDO::FETCH_NUM);
    same(26, count($words), 'thirteen keys, each in English and Slovak');
    same(
        'Pošli mi kód e-mailom',
        iamDb()->query("SELECT value FROM translations WHERE key_name = 'login.paused.sendCode' AND language_code = 'sk'")->fetchColumn(),
        'the Slovak button in the form of the sign-in page\'s other one ("Radšej mi pošli kód e-mailom") - informal ("ty") since 021, which reworded both (informal-code-buttons.test.php)',
    );
    same(MAIL_FALLBACK['mail.authenticatorPaused.subject'], mailWords('en')['mail.authenticatorPaused.subject'], 'the mail\'s English is the code\'s');
    same('Nesprávne kódy k tvojmu účtu anotoki', mailWords('sk')['mail.authenticatorPaused.subject'], 'and its Slovak');
});

test('the attack: five wrong codes after the right password, from five addresses, pause the account\'s codes for fifteen minutes - the sixth, even the right one, is refused unchecked', function () {
    [$id, $secret] = twoFactorAccount('guessed');

    $fifth = pauseByGuessing('guessed@example.test', $secret, '10.19.1');
    same([403, 'authenticator_paused'], [$fifth->status, $fifth->code()], 'the fifth wrong code: paused');
    $until = strtotime((string) ($fifth->json()['paused_until'] ?? ''));
    check($until !== false && abs($until - (time() + AUTHENTICATOR_PAUSE_SECONDS)) <= 5, 'for fifteen minutes from the fifth: ' . $fifth->body);
    same(true, $fifth->json()['email_code'] ?? null, 'and the password\'s sign-in is told a code by email may stand in');

    $sixth = passwordSignIn('10.19.1.100', 'guessed@example.test', ['totp' => totpCode($secret, intdiv(time(), 30))]);
    same([403, 'authenticator_paused'], [$sixth->status, $sixth->code()], 'the sixth - the right code, from yet another address: refused');
    same(
        null,
        iamDb()
            ->query("SELECT totp_last_step FROM users WHERE id = $id")
            ->fetchColumn(),
        'unchecked: the right code was not spent',
    );
    same([403, 'authenticator_paused'], [($r = passwordSignIn('10.19.1.101', 'guessed@example.test'))->status, $r->code()], 'the step itself answers that it is paused');

    same(['bad_totp', 'bad_totp', 'bad_totp', 'bad_totp', 'bad_totp', 'totp_paused', 'totp_paused'], outcomesOf($id), 'five wrong codes counted, the rest refused unchecked');
    same(
        7,
        (int) iamDb()
            ->query("SELECT count(DISTINCT ip) FROM login_attempts WHERE user_id = $id")
            ->fetchColumn(),
        'from seven addresses: the per-address limiter never saw more than one each',
    );

    $entry = iamDb()->query("SELECT actor_id, target_id, details FROM anotoki_audit_log WHERE action = 'account.authenticator_paused' ORDER BY id DESC LIMIT 1")->fetch();
    check($entry !== false, 'the audit log keeps the pause');
    same([null, (string) $id], [$entry['actor_id'], (string) $entry['target_id']], 'about the account, by nobody');
    same('password', json_decode((string) $entry['details'], true)['method'] ?? null, 'with the way in it came by');
});

test('every way in that takes an authenticator code adds to the one count, and is refused during the pause: the emailed code\'s sign-in, Google, a password reset, the account page', function () {
    [$id, $secret, $recovery] = twoFactorAccount('everyway');
    $email = 'everyway@example.test';
    $pdo = iamDb();

    // A session first (a completed sign-in), for the account page.
    $page = new Browser('10.19.2.1');
    same(200, $page->post('/api/login/password', ['email' => $email, 'password' => PASSWORD, 'totp' => rightCode($id, $secret)])->status, 'signed in, for the account page');

    // One wrong code by each way.
    $code = mailedCode($email, '10.19.2.2');
    same('totp_invalid', (new Browser('10.19.2.3'))->post('/api/login/code/verify', ['email' => $email, 'code' => $code, 'totp' => wrongCode($secret, 1)])->code(), '1: the emailed code\'s sign-in');
    same('totp_invalid', (new Browser('10.19.2.4'))->post('/api/login/google', ['credential' => googleToken('everyway-google', $email), 'totp' => wrongCode($secret, 2)])->code(), '2: Google');
    $token = resetTokenFor($email, '10.19.2.5');
    same('totp_invalid', (new Browser('10.19.2.6'))->post('/api/password/reset', ['token' => $token, 'password' => 'a new password', 'totp' => wrongCode($secret, 3)])->code(), '3: a password reset');
    same([400, 'totp_invalid'], [($r = $page->post('/api/account/2fa/disable', ['code' => wrongCode($secret, 4)]))->status, $r->code()], '4: the account page, turning two-factor off');
    same(null, pausedUntilOf($id), 'four: not yet');
    same(
        [403, 'authenticator_paused'],
        [($r = $page->post('/api/account/2fa/recovery', ['code' => wrongCode($secret, 5)]))->status, $r->code()],
        '5: the account page\'s new recovery codes - the fifth, by the fifth way: paused',
    );
    check(pausedUntilOf($id) !== null, 'the account is paused');
    same(5, count(array_filter(outcomesOf($id), static fn(string $outcome): bool => $outcome === 'bad_totp')), 'five wrong codes, one count');

    // During the pause, every way in refuses an authenticator code unchecked.
    $right = rightCode($id, $secret);
    same(
        ['authenticator_paused', false],
        [($r = (new Browser('10.19.2.7'))->post('/api/login/code/verify', ['email' => $email, 'code' => $code, 'totp' => $right]))->code(), $r->json()['email_code'] ?? null],
        'the emailed code\'s sign-in - which asks for the mailbox alone, so no code by email stands in there',
    );
    same('authenticator_paused', (new Browser('10.19.2.8'))->post('/api/login/google', ['credential' => googleToken('everyway-google', $email), 'totp' => $right])->code(), 'Google');
    same('authenticator_paused', (new Browser('10.19.2.9'))->post('/api/password/reset', ['token' => $token, 'password' => 'a new password', 'totp' => $right])->code(), 'a password reset');
    same([403, 'authenticator_paused'], [($r = $page->post('/api/account/2fa/disable', ['code' => $right]))->status, $r->code()], 'the account page: turning two-factor off');
    same([403, 'authenticator_paused'], [($r = $page->post('/api/account/2fa/recovery', ['code' => $right]))->status, $r->code()], 'and new recovery codes');
    same(null, $pdo->query("SELECT totp_last_step FROM users WHERE id = $id")->fetchColumn(), 'none of them spent the right code');
    check(asBool($pdo->query("SELECT totp_enabled FROM users WHERE id = $id")->fetchColumn()), 'two-factor still on');

    // A recovery code is no six digits to guess: the reset takes one, as ever.
    same(
        200,
        (new Browser('10.19.2.10'))->post('/api/password/reset', ['token' => $token, 'password' => 'a new password', 'totp' => $recovery[0]])->status,
        'a recovery code still works, on the reset too',
    );
});

test('during the pause a code by email signs in - after the right password, never without it: the password alone, or the mailbox alone, gets no further', function () {
    [$id, $secret] = twoFactorAccount('mailed');
    $email = 'mailed@example.test';
    same('authenticator_paused', pauseByGuessing($email, $secret, '10.19.3')->code(), 'paused');

    $code = mailedCode($email, '10.19.3.20');
    // The mailbox alone: the emailed code's own sign-in asks for the authenticator, and is paused with it.
    same(
        ['authenticator_paused', false],
        [($r = (new Browser('10.19.3.21'))->post('/api/login/code/verify', ['email' => $email, 'code' => $code]))->code(), $r->json()['email_code'] ?? null],
        'the mailbox alone: paused, and no code by email offered there',
    );
    // The mailed code with a wrong password: the password comes first.
    same(
        [401, 'invalid_credentials'],
        [($r = passwordSignIn('10.19.3.22', $email, ['email_code' => $code], 'not the password'))->status, $r->code()],
        'the mailed code with a wrong password: refused',
    );
    same(0, attemptsEnded($id, 'bad_email_code'), 'and no code by email tried');

    // The owner: the password, and the code from the mailbox.
    $signedIn = (new Browser('10.19.3.42'))->post('/api/login/password', ['email' => $email, 'password' => PASSWORD, 'email_code' => $code, 'remember_device' => true]);
    same(200, $signedIn->status, 'the password and the mailed code: signed in - ' . $signedIn->body);
    check(array_key_exists('device_token', $signedIn->json()) && $signedIn->json()['device_token'] === null, 'no browser remembered: the mailbox stood in for the authenticator');
    same(
        0,
        (int) iamDb()
            ->query("SELECT count(*) FROM user_trusted_devices WHERE user_id = $id")
            ->fetchColumn(),
        'none kept',
    );
    same(['password_email', true], newestSession($id), 'a session of its own method, of two factors: the password and the mailbox');
    same([400, 'invalid_code'], [($r = passwordSignIn('10.19.3.43', $email, ['email_code' => $code]))->status, $r->code()], 'the code is spent');
    check(pausedUntilOf($id) !== null && strtotime((string) pausedUntilOf($id)) > time(), 'the pause runs on: the sign-in started the count again, the codes stay paused');
    same('authenticator_paused', passwordSignIn('10.19.3.44', $email, ['totp' => rightCode($id, $secret)])->code(), 'the authenticator still refused unchecked meanwhile');
});

test(
    'the attack: codes by email guessed with the password are counted for the account as the authenticator\'s are - five wrong in a pause, and every one is refused unchecked until it ends, one mailed since and the right one too',
    function () {
        [$id, $secret, $recovery] = twoFactorAccount('mailguessed');
        $email = 'mailguessed@example.test';
        same('authenticator_paused', pauseByGuessing($email, $secret, '10.19.11')->code(), 'paused');
        $code = mailedCode($email, '10.19.11.20');

        // The password alone, guessing at the mailed code, from an address of its own each time.
        for ($guess = 1; $guess < AUTHENTICATOR_PAUSE_AFTER; $guess++) {
            same([400, 'invalid_code'], [($r = passwordSignIn("10.19.11.3{$guess}", $email, ['email_code' => besides($code, $guess)]))->status, $r->code()], "a wrong code by email ({$guess})");
        }
        $fifth = passwordSignIn('10.19.11.39', $email, ['email_code' => besides($code, 9)]);
        same([403, 'authenticator_paused', false], [$fifth->status, $fifth->code(), $fifth->json()['email_code'] ?? null], 'the fifth: paused, and no code by email stands in any more');
        check(is_string($fifth->json()['paused_until'] ?? null), 'saying until when');

        // New codes asked for: the right one is refused all the same, and not even tried.
        $fresh = mailedCode($email, '10.19.11.40');
        $answer = passwordSignIn('10.19.11.41', $email, ['email_code' => $fresh]);
        same([403, 'authenticator_paused', false], [$answer->status, $answer->code(), $answer->json()['email_code'] ?? null], 'a code mailed since - the right one - refused');
        $token = iamDb()->prepare("SELECT attempts, consumed_at FROM user_tokens WHERE user_id = ? AND purpose = 'login_code' AND token_hash = ?");
        $token->execute([$id, loginCodeHash($id, $fresh)]);
        same(['attempts' => 0, 'consumed_at' => null], $token->fetch(), 'unchecked: not tried, not spent');
        same([403, 'authenticator_paused', false], [($r = passwordSignIn('10.19.11.42', $email))->status, $r->code(), $r->json()['email_code'] ?? null], 'the step itself offers no code by email now');
        same([AUTHENTICATOR_PAUSE_AFTER, 1], [attemptsEnded($id, 'bad_email_code'), attemptsEnded($id, 'email_code_paused')], 'five wrong ones counted, the one after them refused unchecked');

        // A recovery code is no six digits to guess: it still signs in.
        same(200, passwordSignIn('10.19.11.43', $email, ['totp' => $recovery[0]])->status, 'a recovery code still works');
        same('authenticator_paused', passwordSignIn('10.19.11.44', $email, ['email_code' => $fresh])->code(), 'and a sign-in completed meanwhile gives the guesser no new tries');

        // The pause's end: the authenticator again; a new pause, five new codes by email.
        fifteenMinutesPass($id);
        same(200, passwordSignIn('10.19.11.50', $email, ['totp' => rightCode($id, $secret)])->status, 'the pause over: the authenticator signs in');
        same('authenticator_paused', pauseByGuessing($email, $secret, '10.19.21')->code(), 'paused again');
        same(true, passwordSignIn('10.19.11.51', $email)->json()['email_code'] ?? null, 'a code by email offered again');
        same(200, passwordSignIn('10.19.11.52', $email, ['email_code' => mailedCode($email, '10.19.11.53')])->status, 'and it stands in');
    },
);

test('the attack: a burst of codes by email sent at once is checked one at a time under the hold on the account\'s row - five wrong ones at most, the rest refused unchecked', function () {
    [$id, $secret] = twoFactorAccount('burst');
    $email = 'burst@example.test';
    same('authenticator_paused', pauseByGuessing($email, $secret, '10.19.12')->code(), 'paused');
    $code = mailedCode($email, '10.19.12.20');
    $burst = 8;

    // The account's row held, as a check in progress holds it, so the burst waits at one moment.
    $holder = connectDatabase(testServer(), testEnvironment()['db'], false);
    $holder->beginTransaction();
    $holder->prepare('SELECT id FROM users WHERE id = ? FOR UPDATE')->execute([$id]);

    // Each guess the password's sign-in with a wrong code by email, from an address of its own.
    $script = emailCodeGuessScript($email);
    $guesses = [];
    try {
        for ($n = 1; $n <= $burst; $n++) {
            $guesses[] = startGuess($script, "10.19.12.{$n}0", besides($code, $n));
        }
        same($burst, guessesWaiting($burst), 'all eight wait at the account\'s row at once');
    } finally {
        $holder->commit();
        $holder = null;
    }

    $answers = [];
    $errors = '';
    foreach ($guesses as $guess) {
        [$answer, $said] = guessAnswer($guess);
        $answers[] = $answer;
        $errors .= $said;
    }
    sort($answers);
    same(
        array_merge(array_fill(0, AUTHENTICATOR_PAUSE_AFTER - 1, '400 invalid_code'), array_fill(0, $burst - AUTHENTICATOR_PAUSE_AFTER + 1, '403 authenticator_paused')),
        $answers,
        'four said wrong, the fifth and the rest paused ' . $errors,
    );
    same(
        [AUTHENTICATOR_PAUSE_AFTER, $burst - AUTHENTICATOR_PAUSE_AFTER],
        [attemptsEnded($id, 'bad_email_code'), attemptsEnded($id, 'email_code_paused')],
        'five checked, however many came at once - the rest refused unchecked',
    );
});

test(
    'the attack, timed: a guess by email sent while the fifth wrong code is being checked waits at the account\'s row, and is refused with the pause - no code by email tried, none counted',
    function () {
        [$id] = twoFactorAccount('timed');
        $email = 'timed@example.test';
        $code = mailedCode($email, '10.19.18.1');

        // The fifth wrong authenticator code's check, holding the account's row, its pause not begun yet.
        $pausing = connectDatabase(testServer(), testEnvironment()['db'], false);
        $pausing->beginTransaction();
        $pausing->prepare('SELECT id FROM users WHERE id = ? FOR UPDATE')->execute([$id]);

        // Meanwhile a guess with the password and a code by email: no pause to read yet, so the authenticator's step, waiting for the row.
        $guess = startGuess(emailCodeGuessScript($email), '10.19.18.2', besides($code, 1));
        try {
            same(1, guessesWaiting(1), 'the guess waits at the account\'s row');
            $pausing
                ->prepare("UPDATE users SET totp_paused_until = CURRENT_TIMESTAMP + (? * INTERVAL '1 second'), totp_count_from = CURRENT_TIMESTAMP WHERE id = ?")
                ->execute([AUTHENTICATOR_PAUSE_SECONDS, $id]);
        } finally {
            $pausing->commit();
            $pausing = null;
        }

        [$answer, $errors] = guessAnswer($guess);
        same('403 authenticator_paused', $answer, 'refused with the pause ' . $errors);
        $tried = iamDb()->prepare("SELECT attempts FROM user_tokens WHERE user_id = ? AND purpose = 'login_code' AND token_hash = ?");
        $tried->execute([$id, loginCodeHash($id, $code)]);
        same([0, 0], [attemptsEnded($id, 'bad_email_code'), (int) $tried->fetchColumn()], 'no code by email tried, so nothing to count');
    },
);

test(
    'the attack, timed to the moment: a check by email whose transaction began before the pause did, and read the row once it had begun, starts afresh - so its wrong code is counted in the pause, five still the most',
    function () {
        [$id] = twoFactorAccount('raced');
        $email = 'raced@example.test';
        $code = mailedCode($email, '10.19.19.1');
        $server = testServer();
        $pdo = new HookAfterBeginPdo("pgsql:host={$server['host']};port={$server['port']};dbname=" . testEnvironment()['db'], (string) $server['username'], (string) $server['password']);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->exec("SET TIME ZONE 'UTC'");

        // Right after the check's BEGIN, the fifth wrong authenticator code begins the pause, in a transaction of its own.
        $pdo->afterBegin = static function () use ($id): void {
            usleep(2000);
            iamDb()
                ->prepare("UPDATE users SET totp_paused_until = CURRENT_TIMESTAMP + (? * INTERVAL '1 second'), totp_count_from = CURRENT_TIMESTAMP WHERE id = ?")
                ->execute([AUTHENTICATOR_PAUSE_SECONDS, $id]);
        };
        $request = (new Slim\Psr7\Factory\ServerRequestFactory())->createServerRequest('POST', TEST_ISSUER . '/api/login/password', ['REMOTE_ADDR' => '10.19.19.2']);

        $result = pausedEmailCodeCheck($pdo, $request, userById(iamDb(), $id), besides($code, 1));
        same([false, 'invalid_code'], [$result['passed'] ?? null, $result['code'] ?? null], 'checked in the pause: a wrong code');
        same([1, 1], [attemptsEnded($id, 'bad_email_code'), wrongEmailCodesThisPause(iamDb(), $id)], 'and counted in it - kept at the pause\'s time or later, never before it');
        $pdo = null;
    },
);

test('a sign-in finished with a code by email is a session of its own method, and its ID token says the mailbox stood in: amr pwd, email, mfa - never otp, which is the authenticator\'s', function () {
    [$id, $secret] = twoFactorAccount('amr');
    $email = 'amr@example.test';

    same(['pwd', 'otp', 'mfa'], amrOfSignIn('10.19.14.1', $email, ['totp' => rightCode($id, $secret)]), 'the password and the authenticator: pwd, otp, mfa');
    same(['password', true], newestSession($id), 'its session: the password\'s, of two factors');

    same('authenticator_paused', pauseByGuessing($email, $secret, '10.19.14')->code(), 'paused');
    same(['pwd', 'email', 'mfa'], amrOfSignIn('10.19.14.20', $email, ['email_code' => mailedCode($email, '10.19.14.21')]), 'the password and a code by email during the pause: pwd, email, mfa');
    same(['password_email', true], newestSession($id), 'its session: a method of its own, of two factors');
});

test('outside a pause a code by email stands in for nothing: the authenticator is asked for, and the code is not spent', function () {
    [$id, $secret] = twoFactorAccount('unpaused');
    $email = 'unpaused@example.test';
    $code = mailedCode($email, '10.19.4.1');

    same([403, 'totp_required'], [($r = passwordSignIn('10.19.4.2', $email, ['email_code' => $code]))->status, $r->code()], 'the password and a mailed code: the authenticator is asked for');
    same(
        1,
        (int) iamDb()
            ->query("SELECT count(*) FROM user_tokens WHERE user_id = $id AND purpose = 'login_code' AND consumed_at IS NULL")
            ->fetchColumn(),
        'the mailed code not spent',
    );
    same(200, passwordSignIn('10.19.4.3', $email, ['totp' => rightCode($id, $secret)])->status, 'the authenticator signs in');
});

test('the pause lifts fifteen minutes after the fifth wrong code, and the count starts again from nought', function () {
    [$id, $secret] = twoFactorAccount('waited');
    $email = 'waited@example.test';
    same('authenticator_paused', pauseByGuessing($email, $secret, '10.19.5')->code(), 'paused');
    same('authenticator_paused', passwordSignIn('10.19.5.50', $email, ['totp' => rightCode($id, $secret)])->code(), 'still paused, a moment later');

    fifteenMinutesPass($id);
    same(200, passwordSignIn('10.19.5.51', $email, ['totp' => rightCode($id, $secret)])->status, 'fifteen minutes on: the right code signs in');
    for ($guess = 1; $guess < AUTHENTICATOR_PAUSE_AFTER; $guess++) {
        same('totp_invalid', passwordSignIn("10.19.5.6{$guess}", $email, ['totp' => wrongCode($secret, $guess)])->code(), "wrong code {$guess} after it: counted from nought, not paused");
    }

    // Without a right code between: the wrong codes that paused it count no more once it ends.
    [$otherId, $otherSecret] = twoFactorAccount('waited2');
    same('authenticator_paused', pauseByGuessing('waited2@example.test', $otherSecret, '10.19.15')->code(), 'another, paused');
    fifteenMinutesPass($otherId);
    same('totp_invalid', passwordSignIn('10.19.15.50', 'waited2@example.test', ['totp' => wrongCode($otherSecret, 50)])->code(), 'one wrong code after its end: just wrong, no new pause');
});

test('the account\'s owner is mailed once a pause - when, from which browser, where to change the password - in their language; a new pause, a new mail', function () {
    [$id, $secret] = twoFactorAccount('told');
    $email = 'told@example.test';
    for ($guess = 1; $guess < AUTHENTICATOR_PAUSE_AFTER; $guess++) {
        passwordSignIn("10.19.6.{$guess}", $email, ['totp' => wrongCode($secret, $guess)]);
    }
    same([], pausedMails($email), 'four wrong codes: no mail');

    $before = time();
    $fifth = (new Browser('10.19.6.99'))->post(
        '/api/login/password',
        ['email' => $email, 'password' => PASSWORD, 'totp' => wrongCode($secret, 99)],
        ['headers' => ['User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:130.0) Gecko/20100101 Firefox/130.0']],
    );
    $after = time();
    same('authenticator_paused', $fifth->code(), 'the fifth');
    // More during the pause: nothing more is mailed.
    passwordSignIn('10.19.6.100', $email, ['totp' => wrongCode($secret, 100)]);
    passwordSignIn('10.19.6.101', $email, ['totp' => rightCode($id, $secret)]);
    passwordSignIn('10.19.6.102', $email);

    $mails = pausedMails($email);
    same(1, count($mails), 'mailed once');
    preg_match('/^    (\d{4}-\d\d-\d\d \d\d:\d\d) UTC$/m', $mails[0]['body'], $when);
    $at = strtotime(($when[1] ?? '') . ' UTC');
    check($at !== false && $at >= $before - 60 && $at <= $after, 'when the fifth came, in UTC and saying so: ' . $mails[0]['body']);
    same(
        "Hello told,\n\n" .
            MAIL_FALLBACK['mail.authenticatorPaused.intro'] .
            "\n\n    {$when[1]} UTC\n    Firefox · Windows\n\n" .
            MAIL_FALLBACK['mail.authenticatorPaused.ignore'] .
            "\n\n" .
            TEST_ISSUER .
            "/account/security\n",
        $mails[0]['body'],
        'what happened, the time and the browser on lines of their own, what to do, and the account page\'s security',
    );
    same(
        ['authenticator_paused', PAUSED_SUBJECT, 'sent'],
        array_values(
            iamDb()
                ->query("SELECT kind, subject, status FROM email_log WHERE user_id = $id ORDER BY id DESC LIMIT 1")
                ->fetch(PDO::FETCH_NUM),
        ),
        'logged as its kind, with its subject',
    );

    // Its end, and five wrong codes again: a new pause, a new mail.
    fifteenMinutesPass($id);
    same('authenticator_paused', pauseByGuessing($email, $secret, '10.19.16')->code(), 'paused again');
    same(2, count(pausedMails($email)), 'a second pause, a second mail');

    // In the account's own language.
    [$slovakId, $slovakSecret] = twoFactorAccount('slovak');
    iamDb()->exec("UPDATE users SET language = 'sk' WHERE id = {$slovakId}");
    same('authenticator_paused', pauseByGuessing('slovak@example.test', $slovakSecret, '10.19.26')->code(), 'a Slovak account, paused');
    same(1, count(pausedMails('slovak@example.test', 'Nesprávne kódy k tvojmu účtu anotoki')), 'mailed in Slovak');

    // Never to an address its owner did not prove.
    [$unconfirmedId, $unconfirmedSecret] = twoFactorAccount('unproved');
    iamDb()->exec("UPDATE users SET email_confirmed = FALSE WHERE id = {$unconfirmedId}");
    same('authenticator_paused', pauseByGuessing('unproved@example.test', $unconfirmedSecret, '10.19.36')->code(), 'an unconfirmed account, paused all the same');
    same([], pausedMails('unproved@example.test'), 'but not mailed');
});

test('a right code before the fifth starts the count again - a recovery code too - and so does a sign-in completed another way', function () {
    [$id, $secret, $recovery] = twoFactorAccount('recounted');
    $email = 'recounted@example.test';
    $device = passwordSignIn('10.19.7.1', $email, ['totp' => rightCode($id, $secret), 'remember_device' => true])->json()['device_token'] ?? null;
    check(is_string($device), 'a remembered browser, for later');

    $fourWrong = static function (string $network) use ($email, $secret): void {
        for ($guess = 1; $guess < AUTHENTICATOR_PAUSE_AFTER; $guess++) {
            same('totp_invalid', passwordSignIn("{$network}.{$guess}", $email, ['totp' => wrongCode($secret, $guess)])->code(), "{$network}: wrong code {$guess}");
        }
    };

    $fourWrong('10.19.17');
    same(200, passwordSignIn('10.19.7.10', $email, ['totp' => rightCode($id, $secret)])->status, 'the right code, before the fifth');
    $fourWrong('10.19.27');
    same(null, pausedUntilOf($id), 'four more after it: not paused - counted from the right code');

    same(200, passwordSignIn('10.19.7.20', $email, ['totp' => $recovery[0]])->status, 'a recovery code, before the fifth');
    $fourWrong('10.19.37');
    same(null, pausedUntilOf($id), 'four more after it: not paused');

    same(200, passwordSignIn('10.19.7.30', $email, ['device_token' => $device])->status, 'a sign-in completed by a remembered browser, no code typed');
    $fourWrong('10.19.47');
    same(null, pausedUntilOf($id), 'four more after it: not paused');
    same('authenticator_paused', passwordSignIn('10.19.7.40', $email, ['totp' => wrongCode($secret, 40)])->code(), 'the fifth in a row: paused');
});

test(
    'during the pause a recovery code still works - fifty bits, no guess - and so does a remembered browser; a wrong recovery code is said wrong; six digits, however written, are never checked',
    function () {
        [$id, $secret, $recovery] = twoFactorAccount('recovering');
        $email = 'recovering@example.test';
        $device = passwordSignIn('10.19.8.200', $email, ['totp' => rightCode($id, $secret), 'remember_device' => true])->json()['device_token'] ?? null;
        check(is_string($device), 'a remembered browser');
        same('authenticator_paused', pauseByGuessing($email, $secret, '10.19.8')->code(), 'paused');

        same([403, 'totp_invalid'], [($r = passwordSignIn('10.19.8.20', $email, ['totp' => 'ABCDE-FGHIJ']))->status, $r->code()], 'a wrong recovery code: said wrong');
        same(200, passwordSignIn('10.19.8.21', $email, ['totp' => $recovery[3]])->status, 'a recovery code: signed in');
        same(200, passwordSignIn('10.19.8.22', $email, ['device_token' => $device])->status, 'a remembered browser: signed in');
        same([403, 'authenticator_paused'], [($r = passwordSignIn('10.19.8.23', $email, ['totp' => '123 456']))->status, $r->code()], 'six digits with a space: unchecked');
        same([403, 'authenticator_paused'], [($r = passwordSignIn('10.19.8.24', $email, ['totp' => rightCode($id, $secret)]))->status, $r->code()], 'the right code: unchecked');
        same(
            ['bad_recovery', 'totp_paused', 'totp_paused'],
            array_values(array_filter(outcomesOf($id), static fn(string $outcome): bool => in_array($outcome, ['bad_recovery', 'totp_paused'], true))),
            'kept as what they were - and none of them towards the next pause',
        );
        same(5, count(array_filter(outcomesOf($id), static fn(string $outcome): bool => $outcome === 'bad_totp')), 'the five that paused it, no more');
    },
);

test('the account page: a code refused unchecked during the pause; a recovery code turns two-factor off, and a new secret switched on clears the pause', function () {
    [$id, $secret, $recovery] = twoFactorAccount('reenabled');
    $email = 'reenabled@example.test';
    $page = new Browser('10.19.10.200');
    same(200, $page->post('/api/login/password', ['email' => $email, 'password' => PASSWORD, 'totp' => rightCode($id, $secret)])->status, 'signed in');
    same('authenticator_paused', pauseByGuessing($email, $secret, '10.19.10')->code(), 'paused by guesses from elsewhere');

    same(
        [403, 'authenticator_paused'],
        [($r = $page->post('/api/account/2fa/disable', ['code' => rightCode($id, $secret)]))->status, $r->code()],
        'turning it off with the app\'s code: refused unchecked',
    );
    check(isset($r->json()['paused_until']), 'saying until when');
    same(200, $page->post('/api/account/2fa/disable', ['code' => $recovery[0]])->status, 'with a recovery code: off');

    $new = (string) ($page->post('/api/account/2fa/setup')->json()['secret'] ?? '');
    same(200, $page->post('/api/account/2fa/enable', ['code' => totpCode($new, intdiv(time(), 30)), 'password' => PASSWORD])->status, 'on again, with a secret of its own');
    same(null, pausedUntilOf($id), 'the pause went with the old secret');
    same(200, passwordSignIn('10.19.10.50', $email, ['totp' => totpCode($new, intdiv(time(), 30) + 1)])->status, 'and the new secret\'s codes sign in at once');
});

test('with 019 pending the authenticator is checked as before - no pause, however many wrong codes; Apply brings the pause in the same process', function () {
    $main = testEnvironment()['db'];
    $window = $main . '_window';
    buildIamThrough('_window', '018_audit_log_into_the_library.sql');
    useIamDatabase($window);

    try {
        same(false, authenticatorPauseReady(iamDb()), 'no pause\'s columns yet');
        $adminId = makeUser('admin', 'admin@example.test');
        grantRole($adminId, 'iam', 'ADMIN');
        [$id, $secret] = twoFactorAccount('early');

        for ($guess = 1; $guess <= AUTHENTICATOR_PAUSE_AFTER + 1; $guess++) {
            same('totp_invalid', passwordSignIn("10.19.9.{$guess}", 'early@example.test', ['totp' => wrongCode($secret, $guess)])->code(), "wrong code {$guess}: refused, never paused");
        }
        same(200, passwordSignIn('10.19.9.10', 'early@example.test', ['totp' => rightCode($id, $secret)])->status, 'the right code still signs in');

        $admin = new Browser('10.19.9.20');
        same(200, $admin->post('/api/login/password', ['email' => 'admin@example.test', 'password' => PASSWORD])->status, 'the admin signs in');
        $applied = $admin->post('/api/admin/migrations/apply');
        same([200, PAUSE_MIGRATION, null], [$applied->status, $applied->json()['applied'][0]['name'] ?? null, $applied->json()['failed'] ?? null], 'Apply pending: ' . $applied->body);
        check(authenticatorPauseReady(iamDb()), 'the columns, in the same process');
        // The wrong codes of the last fifteen minutes count from Apply on - a right code starts the count afresh.
        same(200, passwordSignIn('10.19.9.30', 'early@example.test', ['totp' => rightCode($id, $secret)])->status, 'a right code');
        same('authenticator_paused', pauseByGuessing('early@example.test', $secret, '10.19.19')->code(), 'and five wrong codes pause it from then on');
    } finally {
        useIamDatabase($main);
    }

    same($main, iamDb()->query('SELECT current_database()')->fetchColumn(), 'back on this run\'s own database');
});

finishTests();
