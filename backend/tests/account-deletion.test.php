<?php

/**
 * Deleting an account at its owner's request (migration 016, the owner's
 * decision of 2026-10-07): asked for on the account page with the password -
 * or, without one, a sign-in of the last fifteen minutes - and done thirty
 * days later by housekeeping; cancelled on the account page until then.
 *
 * Asked for, the account is closed at once: every other session and every
 * token ends, and nothing is issued to any site - authorize shows the IAM's
 * own page, a refresh is refused, userinfo and a site's save of preferences
 * know no such holder - while the IAM's own sign-in keeps working by every way
 * in, so the person can cancel. The last administrator cannot ask, and an
 * administrator who asked administers nothing meanwhile. When it is due, the
 * "deleted" mail goes first, then everything of the person goes or is
 * scrubbed and the row stays, its id reserved; the sites read the ids at
 * GET /api/public/anonymized-accounts. 016 reaches installed IAMs too, and a
 * build goes up before its migrations: with 016 pending everything answers as
 * before, and asking waits for Apply.
 */

declare(strict_types=1);

require __DIR__ . '/lib.php';

// This run's own database is a fresh IAM, built from every migration, 016 included.
bootTests();

$adminId = makeUser('admin', 'admin@example.test');
grantRole($adminId, 'iam', 'ADMIN');

const DELETION_MIGRATION = '016_account_deletion_and_new_sign_in_mail.sql';
const SURVEY_FRONTEND = 'http://localhost:4700';
const SURVEY_CALLBACK = 'http://localhost:4700/auth/callback';
const GOOGLE_TEST_CLIENT = 'iam-test.apps.googleusercontent.com';
const PASSWORD = 'correct horse';

function admin(): Browser
{
    $browser = new Browser('10.16.0.1');
    same(200, $browser->signIn('admin@example.test', PASSWORD)->status, 'the admin signs in');

    return $browser;
}

/** A browser signed in with a password, from an address of its own. */
function signedIn(string $email, string $ip, array $extra = []): Browser
{
    $browser = new Browser($ip);
    $answer = $browser->signIn($email, PASSWORD, $extra);
    same(200, $answer->status, "$email signs in: " . $answer->body);

    return $browser;
}

/** The emailed code's way in: asked for, read from the mail's subject, typed. */
function signInByCode(Browser $browser, string $email): TestResponse
{
    same(200, $browser->post('/api/login/code', ['email' => $email])->status, 'a code asked for');
    preg_match('/\b(\d{6})\b/', (string) (lastMailTo($email)['subject'] ?? ''), $found);

    return $browser->post('/api/login/code/verify', ['email' => $email, 'code' => $found[1] ?? '']);
}

/** Starts a sign-in to the survey site: where authorize sent the browser, and the verifier. */
function startSignIn(Browser $browser, array $extra = []): array
{
    [$verifier, $challenge] = pkce();
    $start = $browser->get(
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

    return ['location' => $start->location(), 'verifier' => $verifier];
}

function exchange(string $code, string $verifier): TestResponse
{
    return (new Browser('10.16.9.2'))->request('POST', '/api/oidc/token', [
        'form' => ['grant_type' => 'authorization_code', 'code' => $code, 'redirect_uri' => SURVEY_CALLBACK, 'client_id' => 'survey', 'code_verifier' => $verifier],
        'headers' => ['Origin' => SURVEY_FRONTEND],
    ]);
}

/** A signed-in browser's single sign-on to the survey site, through to its tokens. */
function intoSurvey(Browser $browser): array
{
    $start = startSignIn($browser);
    $code = queryOf($start['location'])['code'] ?? null;
    check($code !== null, 'a code straight away: ' . $start['location']);
    $tokens = exchange($code, $start['verifier']);
    same(200, $tokens->status, 'code exchange: ' . $tokens->body);

    return $tokens->json();
}

function refresh(string $refreshToken): TestResponse
{
    return (new Browser('10.16.9.1'))->request('POST', '/api/oidc/token', [
        'form' => ['grant_type' => 'refresh_token', 'refresh_token' => $refreshToken, 'client_id' => 'survey'],
    ]);
}

function userinfo(string $accessToken): TestResponse
{
    return (new Browser('10.16.9.3'))->get('/api/oidc/userinfo', ['headers' => ['Authorization' => 'Bearer ' . $accessToken]]);
}

function savePreferences(string $accessToken): TestResponse
{
    return (new Browser('10.16.9.4'))->request('POST', '/api/oidc/preferences', [
        'json' => ['theme' => 'dark'],
        'headers' => ['Authorization' => 'Bearer ' . $accessToken, 'Origin' => SURVEY_FRONTEND],
    ]);
}

function askDeletion(Browser $browser, ?string $password = PASSWORD, array $options = []): TestResponse
{
    return $browser->post('/api/account/deletion', $password === null ? [] : ['password' => $password], $options);
}

function userRow(int $id): array
{
    return userById(iamDb(), $id) ?? [];
}

/** The session a browser's cookie names. */
function sessionOf(Browser $browser): array
{
    $statement = iamDb()->prepare('SELECT * FROM sessions WHERE token_hash = ?');
    $statement->execute([sha256((string) ($browser->cookies['anotoki_iam'] ?? ''))]);

    return $statement->fetch() ?: [];
}

/** Makes a browser's sign-in as old as a session held a while: its auth_time `$minutes` ago. */
function agedSession(Browser $browser, int $minutes): void
{
    iamDb()
        ->prepare("UPDATE sessions SET auth_time = CURRENT_TIMESTAMP - (? * INTERVAL '1 minute') WHERE token_hash = ?")
        ->execute([$minutes, sha256((string) ($browser->cookies['anotoki_iam'] ?? ''))]);
}

/** A request from an address, for calling the logic directly as a route would. */
function requestFrom(string $ip): \Psr\Http\Message\ServerRequestInterface
{
    return (new \Slim\Psr7\Factory\ServerRequestFactory())->createServerRequest('POST', TEST_ISSUER . '/api/account', ['REMOTE_ADDR' => $ip]);
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

/** The details of the newest audit entry of an action about an account. */
function lastAuditAbout(string $action, int $userId): ?array
{
    $statement = iamDb()->prepare("SELECT actor_id, details, ip FROM anotoki_audit_log WHERE action = ? AND target_type = 'user' AND target_id = ? ORDER BY id DESC LIMIT 1");
    $statement->execute([$action, (string) $userId]);
    $row = $statement->fetch();

    return $row === false ? null : ['actor_id' => $row['actor_id'], 'details' => json_decode((string) $row['details'], true), 'ip' => $row['ip']];
}

/** Today plus thirty days, as close as a test can say: [earliest, latest] epoch seconds. */
function dueWindow(int $askedAround): array
{
    return [$askedAround + ACCOUNT_DELETION_DAYS * 86400 - 5, time() + ACCOUNT_DELETION_DAYS * 86400 + 5];
}

/** Moves an account's deletion into the past, as thirty days would. */
function makeDue(int $userId): void
{
    iamDb()
        ->prepare("UPDATE users SET deletion_requested_at = CURRENT_TIMESTAMP - INTERVAL '30 days 1 minute', deletion_due_at = CURRENT_TIMESTAMP - INTERVAL '1 minute' WHERE id = ?")
        ->execute([$userId]);
}

/** Housekeeping as the next request after an hour runs it. */
function runHousekeeping(): void
{
    @unlink(iamStorageDir() . '/cache/housekeeping.stamp');
    iamHousekeeping();
}

/** How many rows of a table hold an account's id. */
function rowsOf(string $table, int $userId, string $column = 'user_id'): int
{
    $statement = iamDb()->prepare("SELECT count(*) FROM {$table} WHERE {$column} = ?");
    $statement->execute([$userId]);

    return (int) $statement->fetchColumn();
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
                'keys' => ['deletion-test' => ['kty' => 'RSA', 'kid' => 'deletion-test', 'n' => $pair['n'], 'e' => $pair['e']]],
            ]),
        );
        writeTestConfig('google', ['client_id' => GOOGLE_TEST_CLIENT]);
        forgetLocalConfig();
    }

    $header = base64UrlEncode((string) json_encode(['alg' => 'RS256', 'kid' => 'deletion-test', 'typ' => 'JWT']));
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

// ── 016 itself ───────────────────────────────────────────────────────────────

test('a fresh IAM has 016: the three columns and their check, the table of browsers, nobody asked for or anonymized', function () {
    $pdo = iamDb();
    check(in_array(DELETION_MIGRATION, appliedMigrations($pdo), true), '016 recorded');
    same(true, accountDeletionReady($pdo), 'its columns are there');
    same(true, signInDevicesReady($pdo), 'and the browsers\' table');
    same(0, (int) $pdo->query('SELECT count(*) FROM users WHERE deletion_due_at IS NOT NULL OR anonymized_at IS NOT NULL')->fetchColumn(), 'nobody is being deleted');

    foreach (
        [
            "UPDATE users SET deletion_due_at = CURRENT_TIMESTAMP WHERE username = 'admin'" => 'due, never asked for',
            "UPDATE users SET deletion_requested_at = CURRENT_TIMESTAMP WHERE username = 'admin'" => 'asked for, with no day',
            "UPDATE users SET anonymized_at = CURRENT_TIMESTAMP WHERE username = 'admin'" => 'anonymized, never asked for',
        ]
        as $sql => $what
    ) {
        try {
            $pdo->exec($sql);
            check(false, "$what should be refused by the database");
        } catch (PDOException) {
        }
    }
});

test('an IAM through 015, as its owner left it: 016 applies, and a new application then gives MEMBER to every account but an anonymized one', function () {
    $pdo = buildIamThrough('_owner', '015_account_preferences.sql');
    $insert = $pdo->prepare('INSERT INTO users (username, email, password, email_confirmed, disabled) VALUES (?, ?, ?, ?, ?) RETURNING id');
    $ids = [];
    foreach ([['owner', true, false], ['reader', true, false], ['gone', true, true]] as [$name, $confirmed, $disabled]) {
        $insert->execute([$name, "{$name}@example.test", password_hash(PASSWORD, PASSWORD_DEFAULT), pgBool($confirmed), pgBool($disabled)]);
        $ids[$name] = (int) $insert->fetchColumn();
    }

    $result = applyMigrationsThrough($pdo, DELETION_MIGRATION);
    same([[DELETION_MIGRATION], null], [$result['applied'], $result['failed']], '016 applies, its gate passed (' . ($result['error'] ?? '') . ')');
    same(0, (int) $pdo->query('SELECT count(*) FROM users WHERE deletion_due_at IS NOT NULL OR anonymized_at IS NOT NULL')->fetchColumn(), 'nobody is being deleted');
    same(0, (int) $pdo->query("SELECT count(*) FROM applications WHERE client_id LIKE 'migration-016%'")->fetchColumn(), 'its probe left nothing behind');
    same(
        ['Zmazanie účtu', 'Your anotoki account is deleted'],
        [
            $pdo->query("SELECT value FROM translations WHERE key_name = 'deletion.title' AND language_code = 'sk'")->fetchColumn(),
            $pdo->query("SELECT value FROM translations WHERE key_name = 'mail.deleted.subject' AND language_code = 'en'")->fetchColumn(),
        ],
        'its words, in Slovak and English',
    );

    // One of them anonymized, as housekeeping leaves it; then an application is made.
    $pdo->prepare(
        "UPDATE users SET deletion_requested_at = CURRENT_TIMESTAMP, deletion_due_at = CURRENT_TIMESTAMP, anonymized_at = CURRENT_TIMESTAMP,
                                    password = NULL, password_login_enabled = FALSE, email_confirmed = FALSE WHERE id = ?",
    )->execute([$ids['gone']]);
    $pdo->prepare('DELETE FROM user_roles WHERE user_id = ?')->execute([$ids['gone']]);
    $pdo->exec("INSERT INTO applications (client_id, name) VALUES ('later-site', 'Later')");
    $members = $pdo
        ->query(
            "SELECT ur.user_id FROM user_roles ur JOIN application_roles r ON r.id = ur.role_id JOIN applications a ON a.id = r.application_id
          WHERE a.client_id = 'later-site' AND r.name = 'MEMBER' ORDER BY ur.user_id",
        )
        ->fetchAll(PDO::FETCH_COLUMN);
    same([$ids['owner'], $ids['reader']], array_map('intval', $members), 'MEMBER for the two accounts, nothing for the anonymized one');
    same(
        ['ADMIN', 'EDITOR', 'MEMBER'],
        $pdo->query("SELECT r.name FROM application_roles r JOIN applications a ON a.id = r.application_id WHERE a.client_id = 'later-site' ORDER BY r.name")->fetchAll(PDO::FETCH_COLUMN),
        'and the three roles, as 011 made them',
    );
});

// ── Asking ───────────────────────────────────────────────────────────────────

test('asking needs the password where it is a way in: a wrong one, or none, is refused and counted, and nothing changes', function () {
    $id = makeUser('careful', 'careful@example.test');
    $browser = signedIn('careful@example.test', '10.16.1.1');

    foreach (['not my password', null] as $password) {
        $refused = askDeletion($browser, $password);
        same([422, 'wrong_password'], [$refused->status, $refused->code()], 'refused: ' . var_export($password, true));
    }
    same(null, userRow($id)['deletion_due_at'], 'nothing asked');
    same(
        2,
        (int) iamDb()->query("SELECT count(*) FROM login_attempts WHERE email = 'careful@example.test' AND method = 'account_deletion' AND outcome = 'bad_password'")->fetchColumn(),
        'each a failed attempt, as at the sign-in',
    );
    same([], mailsTo('careful@example.test'), 'and nobody mailed');
    same(200, $browser->get('/api/account')->status, 'the session is fine - a 422, not a 401');
});

test('the limiter: a stolen session cannot guess at the password here', function () {
    makeUser('guessed', 'guessed@example.test');
    $browser = signedIn('guessed@example.test', '10.16.1.2');
    for ($i = 0; $i < ATTEMPT_MAX_PER_EMAIL_IP; $i++) {
        same(422, askDeletion($browser, 'guess ' . $i)->status, "guess $i");
    }
    same([429, 'too_many_attempts'], [($r = askDeletion($browser))->status, $r->code()], 'then not even the right one is tried');
});

test('without a password, a sign-in of the last 15 minutes is the proof', function () {
    $id = makeUser('codeonly', 'codeonly@example.test');
    iamDb()
        ->prepare('UPDATE users SET password_login_enabled = FALSE WHERE id = ?')
        ->execute([$id]);
    $browser = new Browser('10.16.2.1');
    same(200, signInByCode($browser, 'codeonly@example.test')->status, 'signed in with an emailed code');

    iamDb()
        ->prepare("UPDATE sessions SET auth_time = CURRENT_TIMESTAMP - INTERVAL '16 minutes' WHERE id = ?")
        ->execute([(int) sessionOf($browser)['id']]);
    $stale = askDeletion($browser);
    same([403, 'reauthentication_required'], [$stale->status, $stale->code()], 'a sign-in 16 minutes old: ' . $stale->body);
    same(403, askDeletion($browser, PASSWORD)->status, 'and a password it does not sign in with changes nothing');
    same(null, userRow($id)['deletion_due_at'], 'nothing asked');

    iamDb()
        ->prepare("UPDATE sessions SET auth_time = CURRENT_TIMESTAMP - INTERVAL '14 minutes' WHERE id = ?")
        ->execute([(int) sessionOf($browser)['id']]);
    $fresh = askDeletion($browser, null);
    same(200, $fresh->status, 'one 14 minutes old is enough: ' . $fresh->body);
    same('recent_sign_in', lastAuditAbout('account.deletion_requested', $id)['details']['proof'] ?? null, 'the log says how it was proved');
});

// ── The proof a session cannot make for itself ───────────────────────────────

test('a session alone makes no proof: a Google-only account\'s first password needs a sign-in of the last 15 minutes - so no deletion follows', function () {
    $browser = new Browser('10.16.15.1');
    $made = $browser->post('/api/login/google', ['credential' => googleToken('google-solo', 'solo@example.test', 'Solo'), 'language' => 'en']);
    same(201, $made->status, 'a Google-only account, signed in: ' . $made->body);
    $id = (int) userByEmail(iamDb(), 'solo@example.test')['id'];

    // Its session in somebody else's hands, long after it signed in.
    agedSession($browser, 16);
    $set = $browser->post('/api/account/password/set', ['password' => 'the holder chose it']);
    same([403, 'reauthentication_required'], [$set->status, $set->code()], 'a first password, from a sign-in 16 minutes old: ' . $set->body);
    same(null, userRow($id)['password'], 'none set');
    $deletion = askDeletion($browser, 'the holder chose it');
    same([403, 'reauthentication_required'], [$deletion->status, $deletion->code()], 'so the deletion asks for a sign-in the session cannot give');
    same(null, userRow($id)['deletion_due_at'], 'nothing asked');

    // The person, a moment after signing in with Google.
    agedSession($browser, 14);
    same(200, ($r = $browser->post('/api/account/password/set', ['password' => 'my own password']))->status, 'a first password within 15 minutes of signing in: ' . $r->body);
    same('recent_sign_in', lastAuditAbout('account.password_set', $id)['details']['proof'] ?? null, 'audited with its proof');
});

test('a session alone makes no proof: a password account connects no Google and switches no password sign-in off without its password', function () {
    $pdo = iamDb();
    $id = makeUser('keeper', 'keeper@example.test');
    $browser = signedIn('keeper@example.test', '10.16.16.1');

    // The holder's own Google, to sign in afresh with whenever they like - then password sign-in off, then the deletion.
    $connect = $browser->post('/api/account/google', ['credential' => googleToken('google-holder', 'holder@example.test', 'Holder')]);
    same([422, 'wrong_password'], [$connect->status, $connect->code()], 'Google, without the password: refused at the first step: ' . $connect->body);
    same(
        [422, 'wrong_password'],
        [($r = $browser->post('/api/account/google', ['credential' => googleToken('google-holder', 'holder@example.test', 'Holder'), 'password' => 'a guess']))->status, $r->code()],
        'or with a wrong one',
    );
    same(null, identityFor($pdo, $id, IDENTITY_GOOGLE), 'nothing connected');
    same(2, (int) $pdo->query("SELECT count(*) FROM login_attempts WHERE user_id = $id AND method = 'account_password' AND outcome = 'bad_password'")->fetchColumn(), 'both counted by the limiter');

    // Its owner connected their own Google long ago; the holder tries to switch the password off.
    attachIdentity($pdo, $id, IDENTITY_GOOGLE, 'google-keeper', 'keeper@example.test');
    $off = $browser->put('/api/account/password/enabled', ['enabled' => false]);
    same([422, 'wrong_password'], [$off->status, $off->code()], 'password sign-in off, without the password: ' . $off->body);
    same(true, asBool(userRow($id)['password_login_enabled']), 'still on');
    same([422, 'wrong_password'], [($r = askDeletion($browser, 'a guess'))->status, $r->code()], 'and the deletion still asks for the password');

    // The owner, who knows it: Google, password sign-in off - then the proof is a fresh sign-in, which a session held a while has not.
    same(200, ($r = $browser->put('/api/account/password/enabled', ['enabled' => false, 'password' => PASSWORD]))->status, 'switched off with the password: ' . $r->body);
    same('password', lastAuditAbout('account.password_login_off', $id)['details']['proof'] ?? null, 'audited with its proof');
    agedSession($browser, 16);
    same(
        [403, 'reauthentication_required'],
        [($r = $browser->put('/api/account/password/enabled', ['enabled' => true]))->status, $r->code()],
        'switching it back on asks for a sign-in of the last 15 minutes',
    );
    same([403, 'reauthentication_required'], [($r = askDeletion($browser))->status, $r->code()], 'as the deletion does now');
    same(
        200,
        ($r = $browser->post('/api/login/google', ['credential' => googleToken('google-keeper', 'keeper@example.test', 'Keeper')]))->status,
        'signed in again, with the owner\'s Google: ' . $r->body,
    );
    same(200, ($r = $browser->put('/api/account/password/enabled', ['enabled' => true]))->status, 'and switched back on: ' . $r->body);
});

test('a change of password counts every wrong current password like a sign-in: a stolen session cannot guess there either', function () {
    $pdo = iamDb();
    $id = makeUser('guessing', 'guessing@example.test');
    $browser = signedIn('guessing@example.test', '10.16.17.1');

    for ($i = 0; $i < ATTEMPT_MAX_PER_EMAIL_IP; $i++) {
        same([422, 'wrong_password'], [($r = $browser->put('/api/account/password', ['current_password' => "guess $i", 'new_password' => 'something new']))->status, $r->code()], "guess $i");
    }
    same(
        ATTEMPT_MAX_PER_EMAIL_IP,
        (int) $pdo->query("SELECT count(*) FROM login_attempts WHERE user_id = $id AND method = 'account_password' AND outcome = 'bad_password'")->fetchColumn(),
        'each counted',
    );
    same(
        [429, 'too_many_attempts'],
        [($r = $browser->put('/api/account/password', ['current_password' => PASSWORD, 'new_password' => 'something new']))->status, $r->code()],
        'then not even the right one is tried',
    );
    same([429, 'too_many_attempts'], [($r = askDeletion($browser))->status, $r->code()], 'nor the deletion\'s, which is the same password');
    check(password_verify(PASSWORD, (string) userRow($id)['password']), 'the password unchanged');
});

test('a session alone does not switch two-factor on: the proof comes before the code - the password, or a sign-in of the last 15 minutes, asked already at setup', function () {
    $pdo = iamDb();
    // A password account, its session in somebody else's hands - held a while:
    // setting up asks for no proof (the password comes at enable), whatever the sign-in's age.
    $id = makeUser('shielded', 'shielded@example.test');
    $browser = signedIn('shielded@example.test', '10.16.23.1');
    agedSession($browser, 16);
    $setUp = $browser->post('/api/account/2fa/setup');
    same(200, $setUp->status, 'setup from a sign-in 16 minutes old, the password being a way in: ' . $setUp->code());
    $secret = (string) ($setUp->json()['secret'] ?? '');
    check($secret !== '', 'a secret waits - which opens nothing');
    $code = totpCode($secret, intdiv(time(), 30));

    $refused = $browser->post('/api/account/2fa/enable', ['code' => $code]);
    same([422, 'wrong_password'], [$refused->status, $refused->code()], 'the holder\'s own authenticator, without the password: ' . $refused->body);
    same([422, 'wrong_password'], [($r = $browser->post('/api/account/2fa/enable', ['code' => '000000', 'password' => 'a guess']))->status, $r->code()], 'the proof is read before the code');
    same([false, 0], [asBool(userRow($id)['totp_enabled']), rowsOf('user_recovery_codes', $id)], 'two-factor still off, no recovery codes made');
    same(2, (int) $pdo->query("SELECT count(*) FROM login_attempts WHERE user_id = $id AND method = 'account_password' AND outcome = 'bad_password'")->fetchColumn(), 'both counted by the limiter');

    same(
        [400, 'totp_invalid'],
        [($r = $browser->post('/api/account/2fa/enable', ['code' => '000000', 'password' => PASSWORD]))->status, $r->code()],
        'the owner\'s password with a wrong code: then the code is read',
    );
    $enabled = $browser->post('/api/account/2fa/enable', ['code' => $code, 'password' => PASSWORD]);
    same(200, $enabled->status, 'the owner, with the password and the code, from the same aged sign-in: ' . $enabled->code());
    same(10, count($enabled->json()['recovery_codes'] ?? []), 'on, and the recovery codes');
    same('password', lastAuditAbout('account.2fa_on', $id)['details']['proof'] ?? null, 'audited with its proof');

    // An account without a password: a sign-in of the last fifteen minutes -
    // asked at setup already, before a secret is made, so that nobody scans a
    // code that enable would then turn away.
    $google = new Browser('10.16.23.2');
    same(
        201,
        ($r = $google->post('/api/login/google', ['credential' => googleToken('google-shield', 'shield@example.test', 'Shield'), 'language' => 'en']))->status,
        'a Google-only account: ' . $r->body,
    );
    $googleId = (int) userByEmail($pdo, 'shield@example.test')['id'];
    agedSession($google, 16);
    $attempts = fn() => (int) $pdo->query("SELECT count(*) FROM login_attempts WHERE user_id = $googleId")->fetchColumn();
    $before = $attempts();
    same([403, 'reauthentication_required'], [($r = $google->post('/api/account/2fa/setup'))->status, $r->code()], 'setup from a sign-in 16 minutes old: ' . $r->body);
    same(null, userRow($googleId)['totp_secret'], 'no secret made');
    same($before, $attempts(), 'nor anything counted: there was nothing to guess');

    agedSession($google, 1);
    $setUp = $google->post('/api/account/2fa/setup');
    same(200, $setUp->status, 'a minute after signing in, set up: ' . $setUp->code());
    $googleSecret = (string) ($setUp->json()['secret'] ?? '');
    check($googleSecret !== '' && userRow($googleId)['totp_secret'] !== null, 'a secret waits');

    // The sign-in grows old between the two steps: enable asks again.
    agedSession($google, 16);
    same(
        [403, 'reauthentication_required'],
        [($r = $google->post('/api/account/2fa/enable', ['code' => totpCode($googleSecret, intdiv(time(), 30))]))->status, $r->code()],
        'enable from a sign-in 16 minutes old: ' . $r->body,
    );
    same(false, asBool(userRow($googleId)['totp_enabled']), 'still off');
    agedSession($google, 1);
    same(200, ($r = $google->post('/api/account/2fa/enable', ['code' => totpCode($googleSecret, intdiv(time(), 30))]))->status, 'a minute after signing in: ' . $r->code());
    same('recent_sign_in', lastAuditAbout('account.2fa_on', $googleId)['details']['proof'] ?? null, 'audited with its proof');

    // On, setup has nothing to do, at any age: the conflict is said first.
    agedSession($google, 16);
    same([409, 'totp_already_on'], [($r = $google->post('/api/account/2fa/setup'))->status, $r->code()], 'setup with two-factor on');
});

test('asked for, the account is closed at once: every other session, every token, every site - and the person is mailed', function () {
    $id = makeUser('leaver', 'leaver@example.test');
    $here = signedIn('leaver@example.test', '10.16.3.1');
    $elsewhere = signedIn('leaver@example.test', '10.16.3.2');
    $hereTokens = intoSurvey($here);
    $elsewhereTokens = intoSurvey($elsewhere);
    $personal = $here->post('/api/account/tokens', ['name' => 'importer', 'client_id' => 'survey', 'expires_in_days' => 30]);
    same(201, $personal->status, 'a personal token: ' . $personal->body);
    $elsewhereSession = (int) sessionOf($elsewhere)['id'];

    $asked = time();
    $answer = askDeletion($here);
    same(200, $answer->status, 'asked: ' . $answer->body);
    $due = strtotime((string) ($answer->json()['deletion_due_at'] ?? ''));
    [$earliest, $latest] = dueWindow($asked);
    check($due !== false && $due >= $earliest && $due <= $latest, 'due in thirty days: ' . $answer->json()['deletion_due_at']);
    same(isoTime((string) userRow($id)['deletion_due_at']), $answer->json()['deletion_due_at'], 'the account page\'s field is the row\'s');

    same(200, $here->get('/api/account')->status, 'this session stays - it is where the deletion is cancelled');
    same([401, 'no_session'], [($r = $elsewhere->get('/api/account'))->status, $r->code()], 'every other one ends');
    same(
        'account_deletion',
        iamDb()
            ->query("SELECT revoked_reason FROM sessions WHERE id = $elsewhereSession")
            ->fetchColumn() ?:
        null,
        'with a reason of its own',
    );
    foreach (['here' => $hereTokens, 'elsewhere' => $elsewhereTokens] as $where => $tokens) {
        same([400, 'invalid_grant'], [($r = refresh($tokens['refresh_token']))->status, $r->json()['error'] ?? null], "the refresh token from $where is refused");
        same(401, userinfo($tokens['access_token'])->status, "userinfo knows no holder of the access token from $where");
        same(401, savePreferences($tokens['access_token'])->status, "nor does a site's save of preferences ($where)");
    }
    same([400, 'invalid_grant'], [($r = refresh($personal->json()['token']))->status, $r->json()['error'] ?? null], 'the personal token is revoked');
    same(
        0,
        (int) iamDb()
            ->query("SELECT count(*) FROM refresh_tokens WHERE user_id = $id AND revoked_at IS NULL")
            ->fetchColumn(),
        'nothing left unrevoked',
    );

    $mail = lastMailTo('leaver@example.test');
    same('Your anotoki account will be deleted', $mail['subject'] ?? null, 'the mail');
    same(
        "Hello leaver,\n\nYou asked for your anotoki account to be deleted, and it will be on " .
            gmdate('Y-m-d', $due) .
            ". Until then it signs you in to no anotoki site. To keep it, sign in on your account page and cancel the deletion:\n\n" .
            TEST_ISSUER .
            "/account\n\nIf you did not ask for this, somebody else has been signed in to your account: cancel the deletion there, then change your password.\n",
        $mail['body'] ?? null,
        'the day it goes, and the account page on a line of its own',
    );
    same(
        'account_deletion_requested',
        iamDb()
            ->query("SELECT kind FROM email_log WHERE user_id = $id ORDER BY id DESC LIMIT 1")
            ->fetchColumn(),
        'logged as its kind',
    );
    $audit = lastAuditAbout('account.deletion_requested', $id);
    same([$id, ['due' => $answer->json()['deletion_due_at'], 'proof' => 'password'], '10.16.3.1'], [(int) $audit['actor_id'], $audit['details'], $audit['ip']], 'and audited');
});

test('meanwhile the IAM\'s own sign-in works by every way in, and nothing is issued to any site', function () {
    $id = makeUser('pending', 'pending@example.test');
    $first = signedIn('pending@example.test', '10.16.4.1');
    // A code given just before the deletion is asked for, exchanged after.
    $early = startSignIn($first);
    same(200, askDeletion($first)->status, 'asked');

    same([400, 'invalid_grant'], [($r = exchange(queryOf($early['location'])['code'] ?? '', $early['verifier']))->status, $r->json()['error'] ?? null], 'a code given before is not exchanged after');

    // Every way in to the IAM itself.
    $byPassword = signedIn('pending@example.test', '10.16.4.2');
    same(200, signInByCode(new Browser('10.16.4.3'), 'pending@example.test')->status, 'an emailed code');
    $google = new Browser('10.16.4.4');
    same(200, ($r = $google->post('/api/login/google', ['credential' => googleToken('google-pending', 'pending@example.test', 'Pending')]))->status, 'Google: ' . $r->body);
    $account = $byPassword->get('/api/account')->json();
    same([true, false], [$account['deletion_due_at'] !== null, $account['is_admin']], 'the account page says when');

    // Nothing for a site: single sign-on lands on the IAM's own page.
    $sso = startSignIn($byPassword);
    same(TEST_ISSUER . '/error?code=account_deletion_pending', $sso['location'], 'single sign-on: the IAM\'s page, worded for the person - nothing goes to the site');
    same(
        1,
        (int) iamDb()
            ->query("SELECT count(*) FROM auth_codes WHERE user_id = $id")
            ->fetchColumn(),
        'no code but the one given before',
    );
    same('interaction_required', queryOf(startSignIn($byPassword, ['prompt' => 'none'])['location'])['error'] ?? null, 'prompt=none shows no page: the site hears interaction_required');

    // Asked to sign in again: the page signs in, and accept refuses the same way.
    $again = startSignIn($byPassword, ['prompt' => 'login']);
    $request = queryOf($again['location'])['request'] ?? '';
    check($request !== '', 'prompt=login goes to the sign-in page: ' . $again['location']);
    same(200, $byPassword->signIn('pending@example.test', PASSWORD)->status, 'signed in again');
    $accept = $byPassword->post("/api/login/request/{$request}/accept");
    same([403, 'account_deletion_pending'], [$accept->status, $accept->code()], 'accept: ' . $accept->body);
    $back = queryOf($byPassword->post("/api/login/request/{$request}/cancel")->json()['redirect'] ?? '');
    same('access_denied', $back['error'] ?? null, 'going back tells the site access_denied');
    check(str_contains((string) ($back['error_description'] ?? ''), 'being deleted'), 'and why, for the person: ' . ($back['error_description'] ?? ''));

    same(
        [403, 'account_deletion_pending'],
        [($r = $byPassword->post('/api/account/tokens', ['name' => 'x', 'client_id' => 'survey', 'expires_in_days' => 7]))->status, $r->code()],
        'no personal token either',
    );
    same([], array_values(array_filter(array_column($byPassword->get('/api/account/applications')->json()['applications'], 'can_use'))), 'no site can be used');
});

test('an account on its way out is sent no news: left out of an announcement, and skipped by one already made', function () {
    $closingId = makeUser('closing', 'closing@example.test');
    $stayingId = makeUser('staying', 'staying@example.test');
    $admin = admin();
    $draft = [
        'template' => 'blank',
        'client_id' => '',
        'link' => '',
        'sender' => 'noreply',
        'content' => ['en' => ['subject' => 'News', 'heading' => 'News', 'body' => 'Something new.', 'button' => '']],
        'audience' => ['everybody' => false, 'roles' => [], 'user_ids' => [$closingId, $stayingId]],
    ];
    $made = $admin->post('/api/admin/announcements', $draft);
    same([201, 2], [$made->status, $made->json()['counts']['total'] ?? null], 'made for both: ' . substr($made->body, 0, 300));

    $closing = signedIn('closing@example.test', '10.16.14.1');
    same(200, askDeletion($closing)->status, 'one asks for the deletion');
    $count = $admin->post('/api/admin/announcements/audience', ['audience' => $draft['audience']])->json();
    same([1, 1], [$count['recipients'], $count['left_out']['deleting'] ?? null], 'a new one would leave them out, and say why');

    $sent = $admin->post('/api/admin/announcements/' . $made->json()['id'] . '/send');
    same(['sent' => 1, 'skipped' => 1], array_intersect_key($sent->json()['counts'] ?? [], ['sent' => 0, 'skipped' => 0]), 'the one made already skips them as it goes');
    same(
        'skipped',
        iamDb()
            ->query("SELECT status FROM announcement_recipients WHERE user_id = $closingId")
            ->fetchColumn(),
        'skipped',
    );
    same([], array_values(array_filter(mailsTo('closing@example.test'), static fn(array $mail): bool => $mail['subject'] === 'News')), 'nothing sent to them');
    same(1, count(array_filter(mailsTo('staying@example.test'), static fn(array $mail): bool => $mail['subject'] === 'News')), 'the other gets it');
});

test('the last administrator of the IAM cannot ask; with another left one may - and administers nothing meanwhile, nor counts as one', function () {
    $pdo = iamDb();
    $chiefId = makeUser('chief', 'chief@example.test');
    grantRole($chiefId, 'iam', 'ADMIN');
    $chief = signedIn('chief@example.test', '10.16.5.1');
    $admin = admin();
    same(2, $admin->get('/api/admin/stats')->json()['admins'] ?? null, 'two administrators');

    // The admin's ADMIN taken away for a moment: the chief is the last one.
    $adminRole = "(SELECT r.id FROM application_roles r JOIN applications a ON a.id = r.application_id WHERE a.client_id = 'iam' AND r.name = 'ADMIN')";
    $pdo->exec("DELETE FROM user_roles WHERE user_id = {$GLOBALS['adminId']} AND role_id = $adminRole");
    try {
        $refused = askDeletion($chief);
        same([409, 'last_admin'], [$refused->status, $refused->code()], 'the last one cannot go: ' . $refused->body);
        same(null, userRow($chiefId)['deletion_due_at'], 'nothing asked');
    } finally {
        grantRole((int) $GLOBALS['adminId'], 'iam', 'ADMIN');
    }

    same(200, askDeletion($chief)->status, 'with another one left, the chief may');
    same([403, 'account_deletion_pending'], [($r = $chief->get('/api/admin/users'))->status, $r->code()], 'and administers nothing meanwhile');
    same(false, $chief->get('/api/account')->json()['is_admin'] ?? null, 'the pages offer no admin panel');
    same(1, $admin->get('/api/admin/stats')->json()['admins'] ?? null, 'nor is counted as an administrator');
    same([409, 'last_admin'], [($r = askDeletion($admin))->status, $r->code()], 'so the one who stays cannot go too');

    same(200, $chief->delete('/api/account/deletion')->status, 'the chief cancels');
    same(200, $chief->get('/api/admin/users')->status, 'and administers again');
    same(2, $admin->get('/api/admin/stats')->json()['admins'] ?? null, 'counted again');
    $pdo->exec("DELETE FROM user_roles WHERE user_id = $chiefId AND role_id = $adminRole");
});

test('asked twice is refused; cancelled when nothing is asked is refused; both need a session and the CSRF header', function () {
    makeUser('twice', 'twice@example.test');
    $browser = signedIn('twice@example.test', '10.16.6.1');

    same([409, 'deletion_not_requested'], [($r = $browser->delete('/api/account/deletion'))->status, $r->code()], 'nothing to cancel yet');
    same([403, 'csrf'], [($r = askDeletion($browser, PASSWORD, ['csrf' => false]))->status, $r->code()], 'asking without the CSRF header');
    same([403, 'csrf'], [($r = askDeletion($browser, PASSWORD, ['headers' => ['Origin' => 'https://elsewhere.example']]))->status, $r->code()], 'or from a page elsewhere');
    same([401, 'no_session'], [($r = askDeletion(new Browser('10.16.6.2')))->status, $r->code()], 'or with no session');
    same(null, userRow((int) userByEmail(iamDb(), 'twice@example.test')['id'])['deletion_due_at'], 'none of them asked anything');

    same(200, askDeletion($browser)->status, 'asked');
    same([409, 'deletion_already_requested'], [($r = askDeletion($browser))->status, $r->code()], 'asked again');
    same([403, 'csrf'], [($r = $browser->delete('/api/account/deletion', ['csrf' => false]))->status, $r->code()], 'cancelling without the CSRF header');
    same([401, 'no_session'], [($r = (new Browser('10.16.6.3'))->delete('/api/account/deletion'))->status, $r->code()], 'or with no session');
    check(userRow((int) userByEmail(iamDb(), 'twice@example.test')['id'])['deletion_due_at'] !== null, 'still asked');
});

test('cancelled, the account is the person\'s again: the sites sign it in after a new sign-in, and the mail says so', function () {
    $id = makeUser('changed', 'changed@example.test');
    $browser = signedIn('changed@example.test', '10.16.7.1');
    $before = intoSurvey($browser);
    same(200, askDeletion($browser)->status, 'asked');

    $cancelled = $browser->delete('/api/account/deletion');
    same([200, true, null], [$cancelled->status, array_key_exists('deletion_due_at', $cancelled->json()), $cancelled->json()['deletion_due_at']], 'cancelled: ' . $cancelled->body);
    same([null, null], [userRow($id)['deletion_requested_at'], userRow($id)['deletion_due_at']], 'both days gone');
    same($id, (int) lastAuditAbout('account.deletion_cancelled', $id)['actor_id'], 'audited');
    $mail = lastMailTo('changed@example.test');
    same('Your anotoki account will not be deleted', $mail['subject'] ?? null, 'the mail');
    check(
        str_contains((string) ($mail['body'] ?? ''), "\n\n" . TEST_ISSUER . "/account\n\nIf you did not cancel it, somebody else"),
        'the account page, and what to do if it was not them: ' . ($mail['body'] ?? ''),
    );

    same([400, 'invalid_grant'], [($r = refresh($before['refresh_token']))->status, $r->json()['error'] ?? null], 'what the request revoked stays revoked');
    $after = intoSurvey($browser);
    same(200, refresh($after['refresh_token'])->status, 'single sign-on gives the site new tokens, which refresh');
    same(200, userinfo($after['access_token'])->status, 'and userinfo knows the holder');
    same(false, anonymizeAccount(iamDb(), $id), 'there is nothing due to anonymize');
});

// ── When it is due ───────────────────────────────────────────────────────────

test('when it is due, housekeeping deletes it: everything of the person goes or is scrubbed, the row stays, and the last mail goes to the address it had', function () {
    $pdo = iamDb();
    $goneId = makeUser('gone', 'Gone@Example.test');
    $stayId = makeUser('stay', 'stay@example.test');
    $secret = totpSecret();
    foreach ([$goneId, $stayId] as $id) {
        grantRole($id, 'genshin', 'EDITOR');
        $pdo->prepare("UPDATE users SET totp_secret = ?, totp_enabled = TRUE, totp_last_step = 1, language = 'sk', theme = 'dark', date_format = 'dmy_dot', time_format = '12' WHERE id = ?")->execute([
            encryptSecret($secret),
            $id,
        ]);
        generateRecoveryCodes($pdo, $id);
        $pdo->prepare("INSERT INTO user_trusted_devices (user_id, token_hash, user_agent, ip, expires_at) VALUES (?, ?, 'Firefox', '10.16.8.9', CURRENT_TIMESTAMP + INTERVAL '30 days')")->execute([
            $id,
            sha256('remembered-' . $id),
        ]);
        attachIdentity($pdo, $id, IDENTITY_GOOGLE, 'google-' . $id, "google-$id@example.test");
    }
    $code = static fn(): string => totpCode($secret, intdiv(time(), 30));

    // The person, as the IAM came to know them: browsers, tokens, a code not
    // yet exchanged, a reset asked for, a stranger's wrong guesses, a new
    // name, a sign-in a site started with the address as a hint, an
    // announcement, and an administrator's change.
    $browsers = [];
    foreach (['gone' => 'Gone@Example.test', 'stay' => 'stay@example.test'] as $name => $email) {
        $browser = new Browser($name === 'gone' ? '10.16.8.1' : '10.16.8.2');
        $signedIn = $browser->post(
            '/api/login/password',
            ['email' => $email, 'password' => PASSWORD, 'totp' => $code()],
            ['headers' => ['User-Agent' => 'Mozilla/5.0 (Windows NT 10.0) Firefox/130.0']],
        );
        same(200, $signedIn->status, "$name signs in: " . $signedIn->body);
        $browsers[$name] = $browser;
        intoSurvey($browser);
        startSignIn($browser);
        same(201, $browser->post('/api/account/tokens', ['name' => "$name's importer", 'client_id' => 'survey', 'expires_in_days' => 30])->status, "$name's personal token");
        same(200, (new Browser('10.16.8.3'))->post('/api/password/forgot', ['email' => $email])->status, "a reset for $name");
        same(401, (new Browser('10.16.8.4'))->signIn(strtolower($email), 'a wrong guess')->status, "a wrong guess at $name");
        (new Browser('10.16.8.5'))->get(
            '/api/oidc/authorize?' .
                http_build_query([
                    'response_type' => 'code',
                    'client_id' => 'survey',
                    'redirect_uri' => SURVEY_CALLBACK,
                    'scope' => 'openid',
                    'state' => 's',
                    'nonce' => 'n',
                    'code_challenge' => pkce()[1],
                    'code_challenge_method' => 'S256',
                    'login_hint' => $email,
                ]),
        );
    }
    same(200, $browsers['gone']->put('/api/account/profile', ['username' => 'goneRenamed'])->status, 'a new name');
    $announcement = (int) $pdo
        ->query(
            "INSERT INTO announcements (template, sender_address, sender_name, content, audience, created_by)
        VALUES ('blank', 'noreply@localhost', 'anotoki', '{}', '{}', {$GLOBALS['adminId']}) RETURNING id",
        )
        ->fetchColumn();
    $pdo->prepare("INSERT INTO announcement_recipients (announcement_id, user_id, email, language, status, sent_at) VALUES (?, ?, ?, 'sk', 'sent', CURRENT_TIMESTAMP)")->execute([
        $announcement,
        $goneId,
        'Gone@Example.test',
    ]);
    $pdo->prepare("INSERT INTO announcement_recipients (announcement_id, user_id, email, language, status, sent_at) VALUES (?, ?, ?, 'sk', 'sent', CURRENT_TIMESTAMP)")->execute([
        $announcement,
        $stayId,
        'stay@example.test',
    ]);
    $admin = admin();
    same(200, $admin->put("/api/admin/users/{$goneId}", ['force_password_change' => false])->status, 'an administrator\'s change');

    same(200, askDeletion($browsers['gone'])->status, 'gone asks');
    makeDue($goneId);
    $requested = userRow($goneId)['deletion_requested_at'];

    $about = static fn(int $id): int => (int) iamDb()
        ->query("SELECT count(*) FROM anotoki_audit_log WHERE actor_id = $id OR (target_type = 'user' AND target_id = '$id')")
        ->fetchColumn();
    $eventsBefore = $about($goneId);
    $stayBefore = [];
    foreach (
        [
            'sessions',
            'refresh_tokens',
            'auth_codes',
            'user_trusted_devices',
            'user_sign_in_devices',
            'user_tokens',
            'user_recovery_codes',
            'user_identities',
            'user_roles',
            'login_attempts',
            'email_log',
            'announcement_recipients',
        ]
        as $table
    ) {
        $stayBefore[$table] = rowsOf($table, $stayId);
        check($stayBefore[$table] > 0 || in_array($table, ['user_sign_in_devices'], true), "stay has $table rows to keep");
    }
    $goneMailsBefore = count(mailsTo('Gone@Example.test'));

    runHousekeeping();

    // The last mail, sent first: to the address and the name that are gone now, in the person's language, with no link.
    $mails = mailsTo('Gone@Example.test');
    same($goneMailsBefore + 1, count($mails), 'one more mail to the address');
    $last = end($mails);
    same('Tvoj účet anotoki je zmazaný', $last['subject'], 'the "deleted" mail, in Slovak');
    same(
        "Ahoj goneRenamed,\n\nAko si požiadal, tvoj účet anotoki je zmazaný: jeho meno, adresa, heslo aj prihlásenia sú preč. Stránky anotoki si to, čo sa na nich vytvorilo, ponechajú bez mena.\n\n" .
            "Toto je posledný e-mail, ktorý na túto adresu posielame. Ďakujeme, že si bol s nami.\n",
        $last['body'],
        'its name and its address, before they went - and no link',
    );

    // The row: a placeholder.
    $row = userRow($goneId);
    $placeholder = [
        'username' => "deleted-$goneId",
        'email' => "deleted-$goneId@deleted.invalid",
        'email_confirmed' => false,
        'password' => null,
        'password_login_enabled' => false,
        'totp_secret' => null,
        'totp_enabled' => false,
        'totp_last_step' => null,
        'force_password_change' => false,
        'language' => 'en',
        'theme' => 'auto',
        'date_format' => null,
        'time_format' => null,
        'news_mail' => false,
        'disabled' => true,
        'last_login_at' => null,
    ];
    same($placeholder, array_map(static fn(string $column): mixed => $row[$column], array_combine(array_keys($placeholder), array_keys($placeholder))), 'nothing of the person in the row');
    check(
        $row['anonymized_at'] !== null && strtotime((string) $row['anonymized_at']) === strtotime(substr((string) $row['anonymized_at'], 0, 19) . 'Z'),
        'anonymized, to the second: ' . $row['anonymized_at'],
    );
    same($requested, $row['deletion_requested_at'], 'when it was asked for stays');

    // Gone.
    foreach (['sessions', 'refresh_tokens', 'auth_codes', 'user_trusted_devices', 'user_sign_in_devices', 'user_tokens', 'user_recovery_codes', 'user_identities', 'user_roles'] as $table) {
        same(0, rowsOf($table, $goneId), "no $table");
    }
    same(0, (int) $pdo->query("SELECT count(*) FROM login_attempts WHERE user_id = $goneId OR lower(email) = 'gone@example.test'")->fetchColumn(), 'no sign-in attempts of the account or the address');
    same(0, (int) $pdo->query("SELECT count(*) FROM email_log WHERE user_id = $goneId OR lower(recipient) = 'gone@example.test'")->fetchColumn(), 'no email log rows of the account or its address');
    same(
        ["deleted-$goneId@deleted.invalid", 'Tvoj účet anotoki je zmazaný', 'sent', null],
        array_values($pdo->query("SELECT recipient, subject, status, user_id FROM email_log WHERE kind = 'account_deleted' ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_NUM)),
        'the last mail is logged, sent after the commit, under the placeholder and no account',
    );
    same(0, (int) $pdo->query("SELECT count(*) FROM auth_requests WHERE lower(login_hint) = 'gone@example.test'")->fetchColumn(), 'no sign-in request with the address for a hint');

    // Scrubbed, the events kept.
    same($eventsBefore + 1, $about($goneId), 'every audit entry by or about the account kept, and account.anonymized added');
    same(
        0,
        (int) $pdo
            ->query("SELECT count(*) FROM anotoki_audit_log WHERE ip IS NOT NULL AND (actor_id = $goneId OR (actor_id IS NULL AND target_type = 'user' AND target_id = '$goneId'))")
            ->fetchColumn(),
        'no IP of the person\'s',
    );
    same('10.16.0.1', lastAuditAbout('user.updated', $goneId)['ip'], 'an administrator\'s IP on an administrator\'s change stays');
    same([], lastAuditAbout('account.username_changed', $goneId)['details'], 'the names in the change of name are gone');
    same(
        ['days' => 30],
        json_decode((string) $pdo->query("SELECT details FROM anotoki_audit_log WHERE action = 'account.token_created' AND actor_id = $goneId")->fetchColumn(), true),
        'and the personal token\'s name',
    );
    same(
        ['actor_id' => null, 'details' => ['requested_at' => isoTime((string) $requested)], 'ip' => null],
        lastAuditAbout('account.anonymized', $goneId),
        'account.anonymized, by nobody, from nowhere',
    );
    same(
        ["deleted-$goneId@deleted.invalid", 'sent'],
        array_values($pdo->query("SELECT email, status FROM announcement_recipients WHERE user_id = $goneId")->fetch(PDO::FETCH_NUM)),
        'the announcement went - nobody knows where',
    );

    // The other account: as it was.
    foreach ($stayBefore as $table => $count) {
        same($count, rowsOf($table, $stayId), "stay keeps its $table");
    }
    same('stay@example.test', userRow($stayId)['email'], 'and its address');

    // The way it looks, and what can be done with it: nothing.
    same([401, 'no_session'], [($r = $browsers['gone']->get('/api/account'))->status, $r->code()], 'its browser is signed out');
    $shown = $admin->get("/api/admin/users/{$goneId}")->json();
    same(
        ["deleted-$goneId", true, [], [], null],
        [$shown['username'], $shown['disabled'], $shown['roles'], $shown['blocked_in'], $shown['deletion_due_at']],
        'the admin panel: a placeholder, no roles, blocked nowhere',
    );
    same(isoTime((string) $row['anonymized_at']), $shown['anonymized_at'], 'and when it went');
    foreach (
        [
            ['PUT', "/api/admin/users/{$goneId}/enabled", ['enabled' => true]],
            ['PUT', "/api/admin/users/{$goneId}/password", ['password' => 'a new life']],
            ['PUT', "/api/admin/users/{$goneId}", ['email' => 'back@example.test']],
            ['PUT', "/api/admin/users/{$goneId}/roles", ['roles' => [['client_id' => 'genshin', 'role' => 'MEMBER']]]],
            ['DELETE', "/api/admin/users/{$goneId}/2fa", null],
            ['POST', "/api/admin/users/{$goneId}/approve", []],
        ]
        as [$method, $path, $body]
    ) {
        $refused = $admin->request($method, $path, $body === null ? [] : ['json' => $body]);
        same([409, 'account_anonymized'], [$refused->status, $refused->code()], "$method $path");
    }
    same([true, "deleted-$goneId"], [asBool(userRow($goneId)['disabled']), userRow($goneId)['username']], 'still nobody');
    same([401, 'invalid_credentials'], [($r = (new Browser('10.16.8.6'))->signIn('Gone@Example.test', PASSWORD))->status, $r->code()], 'the old address signs in to nothing');
    same(
        201,
        ($r = (new Browser('10.16.8.7'))->post('/api/register', ['username' => 'goneAgain', 'email' => 'Gone@Example.test', 'password' => 'a long password', 'language' => 'en']))->status,
        'and makes a new account: ' . $r->body,
    );
    check((int) userByEmail($pdo, 'Gone@Example.test')['id'] !== $goneId, 'with an id of its own');

    same(0, anonymizeDueAccounts($pdo), 'and housekeeping has nothing more to do');
});

test('not due, or cancelled: housekeeping leaves the account alone', function () {
    $waitingId = makeUser('waiting', 'waiting@example.test');
    $browser = signedIn('waiting@example.test', '10.16.9.5');
    same(200, askDeletion($browser)->status, 'asked, due in thirty days');
    runHousekeeping();
    same([null, 'waiting'], [userRow($waitingId)['anonymized_at'], userRow($waitingId)['username']], 'not due: left alone');

    makeDue($waitingId);
    same(200, $browser->delete('/api/account/deletion')->status, 'due, and not yet done: cancelled still');
    runHousekeeping();
    same([null, null, 'waiting'], [userRow($waitingId)['anonymized_at'], userRow($waitingId)['deletion_due_at'], userRow($waitingId)['username']], 'cancelled: left alone');
});

test('housekeeping rides on ordinary traffic: the IAM\'s pages and the sites\' reading of the list delete what is due', function () {
    $pdo = iamDb();
    $ids = [];
    foreach (['firstpage', 'ownpage', 'sitelist'] as $name) {
        $ids[$name] = makeUser($name, "$name@example.test");
        $browser = signedIn("$name@example.test", '10.16.10.' . (count($ids) + 1));
        same(200, askDeletion($browser)->status, "$name asks");
        $ids[$name . '_browser'] = $browser;
    }

    makeDue($ids['firstpage']);
    @unlink(iamStorageDir() . '/cache/housekeeping.stamp');
    same(200, (new Browser('10.16.10.9'))->get('/api/public/config')->status, 'a page of the IAM asks for its config');
    check(userRow($ids['firstpage'])['anonymized_at'] !== null, 'and the account due is deleted');

    makeDue($ids['ownpage']);
    @unlink(iamStorageDir() . '/cache/housekeeping.stamp');
    same(401, $ids['ownpage_browser']->get('/api/account')->status, 'the person\'s own next page: deleted before it is read, so signed out');
    check(userRow($ids['ownpage'])['anonymized_at'] !== null, 'deleted');

    makeDue($ids['sitelist']);
    @unlink(iamStorageDir() . '/cache/housekeeping.stamp');
    $list = (new Browser('10.16.10.8'))->get('/api/public/anonymized-accounts?' . http_build_query(['since' => gmdate('Y-m-d\TH:i:s\Z', time() - 3600)]));
    same(200, $list->status, 'a site reads the list: ' . $list->body);
    same(
        [(string) $ids['firstpage'], (string) $ids['ownpage'], (string) $ids['sitelist']],
        array_values(array_intersect(array_column($list->json()['accounts'], 'sub'), [(string) $ids['firstpage'], (string) $ids['ownpage'], (string) $ids['sitelist']])),
        'and finds all three, the one its own request deleted among them, in the order they went',
    );
    same(0, (int) $pdo->query('SELECT count(*) FROM users WHERE deletion_due_at <= CURRENT_TIMESTAMP AND anonymized_at IS NULL')->fetchColumn(), 'nothing due is left');
});

test('a sign-in on its way when its account went leaves nothing behind: its browser noted nowhere, the rest swept', function () {
    $pdo = iamDb();
    $id = makeUser('racer', 'racer@example.test');
    same(200, askDeletion(signedIn('racer@example.test', '10.16.13.1'))->status, 'asked');
    makeDue($id);
    same(true, anonymizeAccount($pdo, $id), 'anonymized');

    // What a sign-in does once its password was checked, a moment before: a
    // session, the attempt, then the browser noted.
    $request = (new \Slim\Psr7\Factory\ServerRequestFactory())
        ->createServerRequest('POST', TEST_ISSUER . '/api/login/password', ['REMOTE_ADDR' => '10.16.13.2'])
        ->withHeader('User-Agent', 'Mozilla/5.0 (Windows NT 10.0) Firefox/130.0');
    openSession($pdo, $request, ['id' => $id], SESSION_METHOD_PASSWORD, false, true);
    recordLoginAttempt($pdo, $request, 'racer@example.test', $id, SESSION_METHOD_PASSWORD, 'ok');
    $answer = noteSignInDevice($pdo, $request, new \Slim\Psr7\Response(), userRow($id));
    same([[], 0], [$answer->getHeader('Set-Cookie'), rowsOf('user_sign_in_devices', $id)], 'the browser is noted nowhere, and gets no cookie');
    same([1, 1, true], [rowsOf('sessions', $id), rowsOf('login_attempts', $id), userRow($id)['last_login_at'] !== null], 'what the race left: a session, an attempt, a time');

    runHousekeeping();
    same([0, 0, null], [rowsOf('sessions', $id), rowsOf('login_attempts', $id), userRow($id)['last_login_at']], 'swept by the next housekeeping');
});

test('an anonymized row stays one: the database refuses a way in written back onto it, and a write of a request on its way finds no row', function () {
    $pdo = iamDb();
    $id = makeUser('late', 'late@example.test');
    same(200, askDeletion(signedIn('late@example.test', '10.16.18.1'))->status, 'asked');
    makeDue($id);
    same(true, anonymizeAccount($pdo, $id), 'anonymized');

    foreach (
        [
            "UPDATE users SET password = 'x' WHERE id = $id" => 'a password',
            "UPDATE users SET email_confirmed = TRUE WHERE id = $id" => 'a confirmed address',
            "UPDATE users SET disabled = FALSE WHERE id = $id" => 'switched on',
            "UPDATE users SET password_login_enabled = TRUE WHERE id = $id" => 'password sign-in',
            "UPDATE users SET totp_enabled = TRUE WHERE id = $id" => 'two-factor',
            "UPDATE users SET totp_secret = 'v1:x' WHERE id = $id" => 'a two-factor secret',
        ]
        as $sql => $what
    ) {
        try {
            $pdo->exec($sql);
            check(false, "$what on an anonymized row should be refused by the database");
        } catch (PDOException) {
        }
    }

    // A request that read the account a moment before it went writes now.
    same(null, issueOneTimeToken($pdo, $id, TOKEN_PASSWORD_RESET, 3600), 'no reset link is issued - so none is mailed');
    same(null, issueLoginCode($pdo, $id, 600), 'nor a sign-in code');
    same(false, totpSpendStep($pdo, $id, PHP_INT_MAX), 'no authenticator code is taken');
    $write = $pdo->prepare('UPDATE users SET username = ? WHERE id = ?' . liveAccountSql($pdo));
    $write->execute(['Late Again', $id]);
    same([0, "deleted-$id"], [$write->rowCount(), userRow($id)['username']], 'nor a name');

    // A link issued just before the account went - its row swept only at the next run - opens nothing and writes nothing.
    foreach ([TOKEN_PASSWORD_RESET => '/api/password/reset', TOKEN_EMAIL_CONFIRM => '/api/register/confirm'] as $purpose => $path) {
        $token = randomHex(32);
        $pdo->prepare("INSERT INTO user_tokens (user_id, purpose, token_hash, expires_at) VALUES (?, ?, ?, CURRENT_TIMESTAMP + INTERVAL '1 hour')")->execute([$id, $purpose, sha256($token)]);
        same(null, findOneTimeToken($pdo, $token, $purpose), "a $purpose link of a deleted account is no link");
        $answer = (new Browser('10.16.18.2'))->post($path, ['token' => $token, 'password' => 'back from the dead']);
        same([400, 'invalid_token'], [$answer->status, $answer->code()], "$path: " . $answer->body);
    }
    same(
        [null, false, true, 0],
        [
            userRow($id)['password'],
            userRow($id)['email_confirmed'],
            userRow($id)['disabled'],
            (int) $pdo->query("SELECT count(*) FROM anotoki_audit_log WHERE action = 'account.address_claimed' AND target_id = '$id'")->fetchColumn(),
        ],
        'the placeholder as it was, nobody claimed it',
    );
});

test('a reset link asked for while the account is being anonymized waits for it, reads the row again, and is issued for nobody', function () {
    $pdo = iamDb();
    $id = makeUser('midway', 'midway@example.test');
    $environment = testEnvironment();
    $api = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'api' . DIRECTORY_SEPARATOR;

    // The anonymization, holding the account's row and not committed yet.
    $anonymizing = connectDatabase(testServer(), $environment['db'], false);
    $anonymizing->beginTransaction();
    $anonymizing->prepare('SELECT id FROM users WHERE id = ? FOR UPDATE')->execute([$id]);

    // A forgotten password asked for meanwhile - issueOneTimeToken() as the
    // route calls it - in a process of its own, so it can wait. It says only
    // whether a token was issued, never the token.
    $script = $environment['base'] . DIRECTORY_SEPARATOR . 'issue-during-anonymization.php';
    file_put_contents(
        $script,
        "<?php\n" .
            'foreach (["helpers.php", "crypto.php", "account_deletion.php", "one_time_token.php"] as $file) { require ' .
            var_export($api, true) .
            " . \$file; }\n" .
            '$config = require ' .
            var_export($environment['config'] . DIRECTORY_SEPARATOR . 'database.local.php', true) .
            ";\n" .
            '$pdo = new PDO("pgsql:host={$config[\'host\']};port={$config[\'port\']};dbname={$config[\'databases\'][\'iam\']}", $config[\'username\'], $config[\'password\'] ?? \'\', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);' .
            "\n" .
            "\$pdo->exec(\"SET TIME ZONE 'UTC'; SET application_name = 'iam-token-race'\");\n" .
            "echo issueOneTimeToken(\$pdo, $id, TOKEN_PASSWORD_RESET, 3600) === null ? 'none' : 'issued';\n",
    );
    $child = proc_open([PHP_BINARY, $script], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    check(is_resource($child), 'the other request started');

    // It waits for the row - and only then does the anonymization commit.
    $waiting = $pdo->prepare("SELECT count(*) FROM pg_stat_activity WHERE application_name = 'iam-token-race' AND wait_event_type = 'Lock'");
    for ($tries = 0; $tries < 200; $tries++) {
        $waiting->execute();
        if ((int) $waiting->fetchColumn() === 1) {
            break;
        }
        usleep(50000);
    }
    same(
        1,
        (int) $pdo->query("SELECT count(*) FROM pg_stat_activity WHERE application_name = 'iam-token-race' AND wait_event_type = 'Lock'")->fetchColumn(),
        'the insert waits for the anonymization holding the row',
    );
    $anonymizing
        ->prepare(
            "UPDATE users SET deletion_requested_at = CURRENT_TIMESTAMP, deletion_due_at = CURRENT_TIMESTAMP, anonymized_at = CURRENT_TIMESTAMP,
                          password = NULL, password_login_enabled = FALSE, email_confirmed = FALSE, disabled = TRUE WHERE id = ?",
        )
        ->execute([$id]);
    $anonymizing->commit();

    $answer = trim((string) stream_get_contents($pipes[1]));
    $errors = trim((string) stream_get_contents($pipes[2]));
    proc_close($child);
    same('none', $answer, 'then it reads the row again, anonymized: no link is issued, so none is mailed ' . $errors);
    same(0, rowsOf('user_tokens', $id), 'and no token is kept');
});

test('what requests on their way left of the person after the account went is swept by the next housekeeping', function () {
    $pdo = iamDb();
    $id = makeUser('trail', 'trail@example.test');
    same(200, askDeletion(signedIn('trail@example.test', '10.16.19.1'))->status, 'asked');
    makeDue($id);
    same(true, anonymizeAccount($pdo, $id), 'anonymized');

    // Each kind, as a request that read the account a moment before would leave it.
    $pdo->prepare("INSERT INTO user_tokens (user_id, purpose, token_hash, expires_at) VALUES (?, 'password_reset', ?, CURRENT_TIMESTAMP + INTERVAL '1 hour')")->execute([$id, sha256('leftover link')]);
    $pdo->prepare("INSERT INTO email_log (recipient, kind, subject, status, user_id) VALUES ('trail@example.test', 'password_reset', 'Set a new anotoki password', 'sent', ?)")->execute([$id]);
    attachIdentity($pdo, $id, IDENTITY_GOOGLE, 'google-trail', 'trail@example.test');
    $pdo->prepare("INSERT INTO user_trusted_devices (user_id, token_hash, user_agent, ip, expires_at) VALUES (?, ?, 'Firefox', '10.16.19.2', CURRENT_TIMESTAMP + INTERVAL '30 days')")->execute([
        $id,
        sha256('leftover device'),
    ]);
    $pdo->prepare('INSERT INTO user_recovery_codes (user_id, code_hash) VALUES (?, ?)')->execute([$id, sha256('leftover code')]);
    grantRole($id, 'genshin', 'EDITOR');
    $announcement = (int) $pdo
        ->query(
            "INSERT INTO announcements (template, sender_address, sender_name, content, audience, created_by)
        VALUES ('blank', 'noreply@localhost', 'anotoki', '{}', '{}', {$GLOBALS['adminId']}) RETURNING id",
        )
        ->fetchColumn();
    $pdo->prepare("INSERT INTO announcement_recipients (announcement_id, user_id, email, language, status) VALUES (?, ?, 'trail@example.test', 'en', 'skipped')")->execute([$announcement, $id]);
    audit(requestFrom('10.16.19.3'), $id, 'account.username_changed', 'user', $id, ['from' => 'trail', 'to' => 'Trail Again']);
    $pdo->exec("UPDATE users SET totp_last_step = 123 WHERE id = $id");

    runHousekeeping();

    foreach (['user_tokens', 'email_log', 'user_identities', 'user_trusted_devices', 'user_recovery_codes', 'user_roles'] as $table) {
        same(0, rowsOf($table, $id), "no $table left");
    }
    same("deleted-$id@deleted.invalid", $pdo->query("SELECT email FROM announcement_recipients WHERE user_id = $id")->fetchColumn(), 'the announcement\'s row, its address the placeholder again');
    same(['actor_id' => $id, 'details' => [], 'ip' => null], lastAuditAbout('account.username_changed', $id), 'the audit entry kept, its IP and names gone');
    same(null, userRow($id)['totp_last_step'], 'and the row as anonymized');
});

test('an anonymization waits for an application being made, then takes the MEMBER it gave with every other role', function () {
    $pdo = iamDb();
    $id = makeUser('crossing', 'crossing@example.test');
    same(200, askDeletion(signedIn('crossing@example.test', '10.16.20.1'))->status, 'asked');
    makeDue($id);

    // Another request makes an application and is not committed yet: its trigger holds the MEMBER lock, and has given the account MEMBER.
    $other = connectDatabase(testServer(), testEnvironment()['db'], false);
    $other->beginTransaction();
    $other->exec("INSERT INTO applications (client_id, name) VALUES ('crossing-site', 'Crossing')");
    $pdo->exec("SET lock_timeout = '300ms'");
    try {
        anonymizeAccount($pdo, $id);
        check(false, 'the anonymization should wait for the MEMBER lock');
    } catch (PDOException $e) {
        same('55P03', $e->getCode(), 'it waits for the lock: ' . $e->getMessage());
    } finally {
        $pdo->exec('SET lock_timeout = 0');
    }
    same(null, userRow($id)['anonymized_at'], 'not anonymized meanwhile');

    $other->commit();
    same(true, anonymizeAccount($pdo, $id), 'once the application is made, it goes');
    same(0, rowsOf('user_roles', $id), 'and takes the MEMBER the new application gave, with every other role');
    $pdo->exec("DELETE FROM applications WHERE client_id = 'crossing-site'");
});

test('the last mail goes only once the anonymization is committed: one that fails mails nobody, the next run mails once', function () {
    $pdo = iamDb();
    $id = makeUser('stubborn', 'stubborn@example.test');
    same(200, askDeletion(signedIn('stubborn@example.test', '10.16.21.1'))->status, 'asked');
    makeDue($id);
    $deleted = static fn(): int => count(array_filter(mailsTo('stubborn@example.test'), static fn(array $mail): bool => $mail['subject'] === 'Your anotoki account is deleted'));

    // Something after the mail's words were read fails: a trigger refusing this one anonymization.
    $pdo->exec("CREATE FUNCTION test_refuse_anonymization() RETURNS TRIGGER AS \$\$
        BEGIN
            IF NEW.id = $id AND NEW.anonymized_at IS NOT NULL AND OLD.anonymized_at IS NULL THEN
                RAISE EXCEPTION 'refused by the test';
            END IF;
            RETURN NEW;
        END \$\$ LANGUAGE plpgsql");
    $pdo->exec('CREATE TRIGGER test_refuse_anonymization BEFORE UPDATE ON users FOR EACH ROW EXECUTE FUNCTION test_refuse_anonymization()');
    try {
        runHousekeeping();
    } finally {
        $pdo->exec('DROP TRIGGER test_refuse_anonymization ON users');
        $pdo->exec('DROP FUNCTION test_refuse_anonymization()');
    }
    same([null, 'stubborn', 0], [userRow($id)['anonymized_at'], userRow($id)['username'], $deleted()], 'not anonymized - and nobody told it was');

    runHousekeeping();
    same([true, 1], [userRow($id)['anonymized_at'] !== null, $deleted()], 'the next run: anonymized, and told once');
    runHousekeeping();
    same(1, $deleted(), 'and never again');
});

test('the owner claiming an account never confirmed takes back the deletion its registrant asked for, with the rest', function () {
    $pdo = iamDb();
    $id = makeUser('claimed', 'claimed@example.test');
    same(200, askDeletion(signedIn('claimed@example.test', '10.16.22.1'))->status, 'whoever holds the account asks for its deletion');
    // An address nobody proved (an administrator unticked it): whoever registered it may not own it (rule 13).
    $pdo->prepare('UPDATE users SET email_confirmed = FALSE WHERE id = ?')->execute([$id]);

    $owner = new Browser('10.16.22.2');
    same(200, $owner->post('/api/password/forgot', ['email' => 'claimed@example.test'])->status, 'the address\'s owner asks for a reset link');
    preg_match('#/reset-password\?token=([0-9a-f]{64})#', (string) (lastMailTo('claimed@example.test')['body'] ?? ''), $link);
    $reset = $owner->post('/api/password/reset', ['token' => $link[1] ?? '', 'password' => 'the owner\'s own']);
    same(200, $reset->status, 'and takes the account: ' . $reset->body);
    same([null, null], [userRow($id)['deletion_requested_at'], userRow($id)['deletion_due_at']], 'the registrant\'s deletion went with the rest');
    same(['actor_id' => $id, 'details' => ['by' => 'address_claimed'], 'ip' => '10.16.22.2'], lastAuditAbout('account.deletion_cancelled', $id), 'audited');
    same([true, null], [array_key_exists('deletion_due_at', $reset->json()['user']), $reset->json()['user']['deletion_due_at']], 'the account the owner sees is not being deleted');
});

// ── The sites' list ──────────────────────────────────────────────────────────

test('the public list: ids and times only, in order, from an inclusive since, 500 at a time with where the next starts', function () {
    $pdo = iamDb();
    // 520 accounts anonymized long ago, ten in each second.
    $pdo->exec("INSERT INTO users (username, email, disabled, password_login_enabled, deletion_requested_at, deletion_due_at, anonymized_at)
        SELECT 'bulk-' || g, 'bulk-' || g || '@deleted.invalid', TRUE, FALSE, t - INTERVAL '30 days', t, t
          FROM (SELECT g, TIMESTAMPTZ '2001-01-01 00:00:00+00' + (g / 10) * INTERVAL '1 second' AS t FROM generate_series(1, 520) g) s");
    $bulk = array_map('strval', $pdo->query("SELECT id FROM users WHERE username LIKE 'bulk-%' ORDER BY anonymized_at, id")->fetchAll(PDO::FETCH_COLUMN));
    same(520, count($bulk), 'made');

    $reader = new Browser('10.16.11.1');
    $first = $reader->get('/api/public/anonymized-accounts?since=2001-01-01T00:00:00Z', ['headers' => ['Origin' => SURVEY_FRONTEND]]);
    same(200, $first->status, 'answered: ' . substr($first->body, 0, 200));
    same(['accounts', 'next'], array_keys($first->json()), 'two keys');
    same(500, count($first->json()['accounts']), '500 at most');
    same(array_slice($bulk, 0, 500), array_column($first->json()['accounts'], 'sub'), 'in the order they went: by time, then id');
    foreach ($first->json()['accounts'] as $account) {
        if (array_keys($account) !== ['sub', 'anonymized_at'] || !is_string($account['sub']) || preg_match('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ$/', $account['anonymized_at']) !== 1) {
            check(false, 'an id and a time, nothing else: ' . json_encode($account));
        }
    }
    $last = end($first->json()['accounts']);
    same(['since' => $last['anonymized_at'], 'after' => $last['sub']], $first->json()['next'], 'and where the next answer starts');
    same('2001-01-01T00:00:50Z', $last['anonymized_at'], 'the last of the 500 shares its second with others');
    same('public, max-age=60', $first->header('Cache-Control'), 'cacheable for a minute');
    same(['', ''], [$first->header('Access-Control-Allow-Origin'), $first->header('Set-Cookie')], 'in no CORS list, and no cookie');

    $second = $reader->get('/api/public/anonymized-accounts?' . http_build_query($first->json()['next']));
    same(200, $second->status, 'the next answer');
    $rest = array_values(array_intersect(array_column($second->json()['accounts'], 'sub'), $bulk));
    same(array_slice($bulk, 500), $rest, 'the 20 left, none twice, none missed - the second they share with the first page\'s last included');
    same(null, $second->json()['next'], 'and no more');

    // Second 0 holds 9 of them, seconds 1 to 51 ten each, second 52 the last.
    $from50 = $reader->get('/api/public/anonymized-accounts?since=' . urlencode('2001-01-01T00:00:50Z'))->json()['accounts'];
    same([$bulk[499], '2001-01-01T00:00:50Z'], [$from50[0]['sub'], $from50[0]['anonymized_at']], 'since is inclusive: from that very second');
    $from51 = $reader->get('/api/public/anonymized-accounts?' . http_build_query(['since' => '2001-01-01T01:00:51+01:00']))->json()['accounts'];
    same($bulk[509], $from51[0]['sub'] ?? null, 'a time at an offset (its + sent encoded) is the same instant as in UTC');
    same(0, count($reader->get('/api/public/anonymized-accounts?since=2999-01-01')->json()['accounts']), 'nothing after the future');

    foreach (
        [
            'yesterday',
            '2026-02-30',
            '2026-13-01T00:00:00Z',
            '2026-11-06T25:00:00Z',
            '2026-11-06T10:00:00 01:00',
            '1793876400',
            '0000-01-01',
            '0000-06-01T00:00:00Z',
            '1969-12-31T23:59:59Z',
            '1970-01-01T00:30:00+01:00',
        ]
        as $since
    ) {
        same([400, 'invalid_request'], [($r = $reader->get('/api/public/anonymized-accounts?' . http_build_query(['since' => $since])))->status, $r->code()], "since=$since");
    }
    same(200, $reader->get('/api/public/anonymized-accounts?since=1970-01-01T00:00:00Z')->status, 'from the first second of 1970 on');
    foreach (['after=5', 'since=2001-01-01&after=x', 'since=2001-01-01&after=0', 'since=2001-01-01&after=99999999999'] as $query) {
        same(400, $reader->get('/api/public/anonymized-accounts?' . $query)->status, $query);
    }
    same(200, $reader->get('/api/public/anonymized-accounts')->status, 'with no since: from the beginning');

    // Cleared again, so the rest of the run is not 520 accounts heavier.
    $pdo->exec("DELETE FROM user_roles WHERE user_id IN (SELECT id FROM users WHERE username LIKE 'bulk-%')");
    $pdo->exec("DELETE FROM users WHERE username LIKE 'bulk-%'");
});

// ── With 016 pending: a build uploaded before Apply ──────────────────────────

test('with 016 pending everything answers as before, asking waits for Apply, and Apply brings it in the same process', function () {
    $main = testEnvironment()['db'];
    $window = throwawayDatabase($main . '_window');
    buildIamThrough('_window', '015_account_preferences.sql');
    useIamDatabase($window);

    try {
        $pdo = iamDb();
        same(false, accountDeletionReady($pdo), 'no deletion yet');
        $adminId = makeUser('admin', 'admin@example.test');
        grantRole($adminId, 'iam', 'ADMIN');
        makeUser('early', 'early@example.test');

        $early = signedIn('early@example.test', '10.16.12.1');
        $account = $early->get('/api/account')->json();
        same([true, null, false], [array_key_exists('deletion_due_at', $account), $account['deletion_due_at'], $account['is_admin']], 'the account page: nothing to say');
        same([503, 'account_deletion_unavailable'], [($r = askDeletion($early))->status, $r->code()], 'asking waits');
        same([503, 'account_deletion_unavailable'], [($r = $early->delete('/api/account/deletion'))->status, $r->code()], 'and cancelling');
        same(['accounts' => [], 'next' => null], (new Browser('10.16.12.2'))->get('/api/public/anonymized-accounts')->json(), 'the list: nobody was deleted');
        runHousekeeping();
        check(isset(intoSurvey($early)['refresh_token']), 'a site signs the person in, as ever');

        $admin = admin();
        $users = $admin->get('/api/admin/users');
        same(200, $users->status, 'the panel\'s users: ' . substr($users->body, 0, 200));
        same([null, null], [$users->json()[0]['deletion_due_at'], $users->json()[0]['anonymized_at']], 'with nothing to say of a deletion');
        same(1, $admin->get('/api/admin/stats')->json()['admins'] ?? null, 'the administrators counted as before');

        $applied = $admin->post('/api/admin/migrations/apply');
        same([200, DELETION_MIGRATION, null], [$applied->status, $applied->json()['applied'][0]['name'] ?? null, $applied->json()['failed'] ?? null], 'Apply pending: ' . $applied->body);
        same(200, askDeletion($early)->status, 'from the next request on, in the same process');
    } finally {
        useIamDatabase($main);
    }

    same($main, iamDb()->query('SELECT current_database()')->fetchColumn(), 'back on this run\'s own database');
});

finishTests();
