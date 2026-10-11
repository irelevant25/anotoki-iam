<?php

/**
 * New accounts waiting for an administrator's approval (migration 012, the
 * owner's decision of 2026-10-06): while Settings -> "New accounts need
 * approval" is on, an account made by signing up - the form or Google - signs
 * in nowhere until an administrator approves it, on every way in. Then the
 * administrators' list, approving - and the mail it sends - and declining;
 * switching the setting off letting in whoever waited; and the accounts an
 * administrator makes, and the accounts there were, never waiting.
 *
 * 012 reaches installed IAMs too: applied to one built through 011 the way an
 * owner leaves it, nobody waits. And a build goes up before its migrations
 * are applied: with 012 pending, signing up and signing in work as they did,
 * and so do the panel's user pages; the approvals answer 503 until Apply.
 */

declare(strict_types=1);

require __DIR__ . '/lib.php';

// This run's own database is a fresh IAM, built from every migration, 012 included.
bootTests();

$adminId = makeUser('admin', 'admin@example.test');
grantRole($adminId, 'iam', 'ADMIN');
$memberId = makeUser('member', 'member@example.test');

const APPROVAL_MIGRATION = '012_approval_of_new_accounts.sql';
const SURVEY_FRONTEND = 'http://localhost:4700';
const SURVEY_CALLBACK = 'http://localhost:4700/auth/callback';
const GOOGLE_TEST_CLIENT = 'iam-test.apps.googleusercontent.com';
const ACCOUNT_WAITS = 'This account is waiting for an administrator\'s approval';

function admin(): Browser
{
    $browser = new Browser('10.12.0.1');
    same(200, $browser->signIn('admin@example.test', 'correct horse')->status, 'the admin signs in');

    return $browser;
}

/** Settings -> New accounts need approval, as the admin panel saves it. */
function accountApproval(Browser $admin, bool $on): void
{
    $saved = $admin->put('/api/admin/settings', ['settings' => [['name' => 'registration_approval', 'value' => $on ? 'true' : 'false']]]);
    same(200, $saved->status, 'the switch saved: ' . $saved->body);
}

/** Starts a sign-in to the survey site at the authorize endpoint: where the browser was sent, and the verifier. */
function startSignIn(Browser $browser): array
{
    [$verifier, $challenge] = pkce();
    $start = $browser->get('/api/oidc/authorize?' . http_build_query([
        'response_type' => 'code', 'client_id' => 'survey', 'redirect_uri' => SURVEY_CALLBACK, 'scope' => 'openid profile email',
        'state' => 's', 'nonce' => 'n', 'code_challenge' => $challenge, 'code_challenge_method' => 'S256',
    ]));

    return ['location' => $start->location(), 'verifier' => $verifier];
}

/** A signed-in browser's sign-in to the survey site, through to its tokens. */
function intoSurvey(Browser $browser): array
{
    $start = startSignIn($browser);
    $code = queryOf($start['location'])['code'] ?? null;
    check($code !== null, 'a code straight away: ' . $start['location']);
    $tokens = $browser->request('POST', '/api/oidc/token', [
        'form' => ['grant_type' => 'authorization_code', 'code' => $code, 'redirect_uri' => SURVEY_CALLBACK, 'client_id' => 'survey', 'code_verifier' => $start['verifier']],
        'headers' => ['Origin' => SURVEY_FRONTEND],
    ]);
    same(200, $tokens->status, 'code exchange: ' . $tokens->body);

    return $tokens->json();
}

/** Registers with the form, from a site's sign-in request when there is one. */
function register(Browser $browser, string $username, ?string $request = null, string $language = 'en'): TestResponse
{
    return $browser->post('/api/register', ['username' => $username, 'email' => "{$username}@example.test", 'password' => 'a long password', 'language' => $language]
        + ($request !== null ? ['request' => $request] : []));
}

/** The token of the newest link of a kind mailed to an address: confirm-email or reset-password. */
function mailedToken(string $email, string $page): string
{
    preg_match('#/' . $page . '\?token=([0-9a-f]{64})#', (string) (lastMailTo($email)['body'] ?? ''), $found);
    check(isset($found[1]), "a {$page} link was mailed to {$email}");

    return $found[1];
}

function userRow(int $id): array
{
    return userById(iamDb(), $id);
}

function userIdOf(string $username): int
{
    $statement = iamDb()->prepare('SELECT id FROM users WHERE username = ?');
    $statement->execute([$username]);

    return (int) $statement->fetchColumn();
}

/** The usernames the administrators' list holds, oldest first. */
function waitingIn(Browser $admin): array
{
    $answer = $admin->get('/api/admin/approvals');
    same(200, $answer->status, 'the approvals: ' . $answer->body);

    return array_column($answer->json()['accounts'], 'username');
}

/** The details of the newest audit entry of an action; null for none, or for one with no details. */
function lastAuditOf(string $action): ?array
{
    $statement = iamDb()->prepare('SELECT details FROM anotoki_audit_log WHERE action = ? ORDER BY id DESC LIMIT 1');
    $statement->execute([$action]);
    $details = $statement->fetchColumn();

    return $details === false || $details === null ? null : json_decode((string) $details, true);
}

function auditCount(string $action, int $targetId): int
{
    $statement = iamDb()->prepare('SELECT count(*) FROM anotoki_audit_log WHERE action = ? AND target_id = ?');
    $statement->execute([$action, (string) $targetId]);

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
        file_put_contents(iamStorageDir() . '/cache/google-jwks.json', json_encode([
            'expires_at' => time() + 3600,
            'keys' => ['approvals-test' => ['kty' => 'RSA', 'kid' => 'approvals-test', 'n' => $pair['n'], 'e' => $pair['e']]],
        ]));
        writeTestConfig('google', ['client_id' => GOOGLE_TEST_CLIENT]);
        forgetLocalConfig();
    }

    $header = base64UrlEncode((string) json_encode(['alg' => 'RS256', 'kid' => 'approvals-test', 'typ' => 'JWT']));
    $payload = base64UrlEncode((string) json_encode([
        'iss' => 'https://accounts.google.com', 'aud' => GOOGLE_TEST_CLIENT, 'sub' => $subject, 'email' => $email,
        'email_verified' => true, 'name' => $name, 'iat' => time(), 'exp' => time() + 600,
    ]));
    openssl_sign("{$header}.{$payload}", $signature, $key, OPENSSL_ALGO_SHA256);

    return "{$header}.{$payload}." . base64UrlEncode($signature);
}

/** Points the app at another of this run's databases, forgetting what it remembered of the old one. */
function useIamDatabase(string $name): void
{
    $environment = testEnvironment();
    file_put_contents($environment['config'] . DIRECTORY_SEPARATOR . 'database.local.php',
        "<?php\n\n\$config = require " . var_export(developmentDatabaseFile(), true) . ";\n"
        . "\$config['databases'] = ['iam' => " . var_export(throwawayDatabase($name), true) . "];\n"
        . "\$config['persistent'] = false;\n\nreturn \$config;\n");
    forgetLocalConfig();
    settingsAll(true);
    languageCodes(null, true);
    activeSigningKey(true);
}

// ── 012 itself ───────────────────────────────────────────────────────────────

test('a fresh IAM has 012: the switch, off, and every account approved', function () {
    $pdo = iamDb();
    check(in_array(APPROVAL_MIGRATION, appliedMigrations($pdo), true), '012 recorded');
    same(['access', 'boolean', 'false'], array_values($pdo->query("SELECT group_name, type, value FROM settings WHERE name = 'registration_approval'")->fetch(PDO::FETCH_NUM) ?: []),
        'Settings -> New accounts need approval, among the switches of who gets in, off');
    same(false, registrationApprovalRequired(), 'and read as off');
    same(true, accountApprovalReady($pdo), 'the column is there');
    same(0, (int) $pdo->query('SELECT count(*) FROM users WHERE NOT approved')->fetchColumn(), 'every account approved - those the tests make by hand too');
    same(false, (new Browser('10.12.1.1'))->get('/api/public/config')->json()['registration_approval'] ?? null, 'the sign-in pages are told so');
});

test('an IAM through 011, as its owner left it: 012 has nobody wait', function () {
    $pdo = buildIamThrough('_owner', '011_public_clients_static_roles.sql');
    $insert = $pdo->prepare('INSERT INTO users (username, email, password, email_confirmed, disabled) VALUES (?, ?, ?, ?, ?)');
    foreach ([['owner', true, false], ['reader', true, false], ['newcomer', false, false], ['gone', true, true]] as [$name, $confirmed, $disabled]) {
        $insert->execute([$name, "{$name}@example.test", password_hash('correct horse', PASSWORD_DEFAULT), pgBool($confirmed), pgBool($disabled)]);
    }
    // What the owner may have done in the panel: a Slovak string blanked elsewhere.
    $pdo->exec("DELETE FROM translations WHERE key_name = 'common.save' AND language_code = 'sk'");

    $result = applyMigrationsThrough($pdo, APPROVAL_MIGRATION);
    same([[APPROVAL_MIGRATION], null], [$result['applied'], $result['failed']], '012 applied, its gate passed (' . ($result['error'] ?? '') . ')');

    same(0, (int) $pdo->query('SELECT count(*) FROM users WHERE NOT approved')->fetchColumn(), 'every account there was is approved - the unconfirmed one and the switched-off one too');
    same('false', $pdo->query("SELECT value FROM settings WHERE name = 'registration_approval'")->fetchColumn(), 'the switch is off');
    same(['Waiting for approval', 'Čaká sa na schválenie'], [
        $pdo->query("SELECT value FROM translations WHERE key_name = 'login.pending.title' AND language_code = 'en'")->fetchColumn(),
        $pdo->query("SELECT value FROM translations WHERE key_name = 'login.pending.title' AND language_code = 'sk'")->fetchColumn(),
    ], 'its words, in English and Slovak');
    same(0, (int) $pdo->query("SELECT count(*) FROM translations WHERE key_name = 'common.save' AND language_code = 'sk'")->fetchColumn(), 'and the string the owner blanked stays blank');
});

// ── Waiting, on every way in ─────────────────────────────────────────────────

test('a new account waits on every way in until an administrator approves it, and the mail says so', function () {
    $admin = admin();
    accountApproval($admin, true);
    same(true, (new Browser('10.12.2.9'))->get('/api/public/config')->json()['registration_approval'] ?? null, 'the sign-in pages are told');

    $browser = new Browser('10.12.2.1');
    $made = register($browser, 'waiter');
    same([201, true, true], [$made->status, $made->json()['approval_pending'] ?? null, $made->json()['sent'] ?? null], 'made, waiting for an administrator, the confirmation mailed: ' . $made->body);
    $id = userIdOf('waiter');
    same(false, asBool(userRow($id)['approved']), 'the account waits');
    same(['via' => 'register', 'waits' => true], lastAuditOf('account.created'), 'and the log says so');

    same([403, 'email_not_confirmed'], [($r = $browser->signIn('waiter@example.test', 'a long password'))->status, $r->code()], 'unconfirmed, the address comes first');

    $confirmed = $browser->post('/api/register/confirm', ['token' => mailedToken('waiter@example.test', 'confirm-email')]);
    same([403, 'approval_pending', ACCOUNT_WAITS], [$confirmed->status, $confirmed->code(), $confirmed->json()['error'] ?? null], 'the confirmation link: no way in yet');
    same([], $browser->cookies, 'and no session');
    same(true, asBool(userRow($id)['email_confirmed']), 'but the address is confirmed');

    $password = $browser->signIn('waiter@example.test', 'a long password');
    same([403, 'approval_pending'], [$password->status, $password->code()], 'password');
    same(200, $browser->post('/api/login/code', ['email' => 'waiter@example.test'])->status, 'a sign-in code asked for');
    preg_match('/\b(\d{6})\b/', (string) (lastMailTo('waiter@example.test')['subject'] ?? ''), $mailed);
    $code = $browser->post('/api/login/code/verify', ['email' => 'waiter@example.test', 'code' => $mailed[1] ?? '']);
    same([403, 'approval_pending'], [$code->status, $code->code()], 'an emailed code');
    same(200, $browser->post('/api/password/forgot', ['email' => 'waiter@example.test'])->status, 'a reset asked for');
    $reset = $browser->post('/api/password/reset', ['token' => mailedToken('waiter@example.test', 'reset-password'), 'password' => 'another long one']);
    same([403, 'approval_pending'], [$reset->status, $reset->code()], 'a reset link: the password is set, but no way in');
    same([403, 'approval_pending'], [($r = $browser->signIn('waiter@example.test', 'another long one'))->status, $r->code()], 'with the new password, still waiting');
    same([], $browser->cookies, 'never a session');
    check(isset(queryOf(startSignIn($browser)['location'])['request']), 'a site sends it to the sign-in page, no code');

    same(['waiter'], waitingIn($admin), 'the administrators see it');
    same(1, $admin->get('/api/admin/stats')->json()['awaiting_approval'] ?? null, 'and count it');
    same(false, $admin->get("/api/admin/users/{$id}")->json()['approved'] ?? null, 'the panel\'s row says it waits');

    $declined = $admin->post("/api/admin/users/{$id}/decline");
    same([200, true, false, false], [$declined->status, $declined->json()['account']['disabled'] ?? null, $declined->json()['account']['approved'] ?? null, $declined->json()['mailed'] ?? null],
        'declined: switched off, still not approved, nobody mailed: ' . $declined->body);
    same(1, auditCount('user.declined', $id), 'logged');
    same([], waitingIn($admin), 'off the list');
    same(0, $admin->get('/api/admin/stats')->json()['awaiting_approval'] ?? null, 'and the count');
    same([403, 'account_disabled'], [($r = $browser->signIn('waiter@example.test', 'another long one'))->status, $r->code()], 'switched off is what it hears');
    same([409, 'not_waiting', 'waiter is switched off already'], [($r = $admin->post("/api/admin/users/{$id}/decline"))->status, $r->code(), $r->json()['error'] ?? null], 'declined once is enough');
    same([409, 'not_waiting', 'waiter is switched off - switch the account on first, and it waits for approval again'],
        [($r = $admin->post("/api/admin/users/{$id}/approve"))->status, $r->code(), $r->json()['error'] ?? null], 'nor approved while declined: nobody is mailed "approved" while switched off');

    same(200, $admin->put("/api/admin/users/{$id}/enabled", ['enabled' => true])->status, 'switched on again');
    same(['waiter'], waitingIn($admin), 'it waits again');

    $approved = $admin->post("/api/admin/users/{$id}/approve");
    same([200, true, true], [$approved->status, $approved->json()['account']['approved'] ?? null, $approved->json()['mailed'] ?? null], 'approved, and mailed: ' . $approved->body);
    $mail = lastMailTo('waiter@example.test');
    same('Your anotoki account is approved', $mail['subject'] ?? null, 'the mail');
    same("Hello waiter,\n\nAn administrator has approved your anotoki account. You can sign in now, the same way you signed up:\n\n" . TEST_ISSUER . "/login\n", $mail['body'] ?? null,
        'greeting, intro, and the sign-in page on a line of its own - nothing else');
    same(1, auditCount('user.approved', $id), 'logged');
    same([409, 'not_waiting'], [($r = $admin->post("/api/admin/users/{$id}/approve"))->status, $r->code()], 'approved once is enough');

    same(200, $browser->signIn('waiter@example.test', 'another long one')->status, 'it signs in now');
    same(200, $browser->get('/api/account')->status, 'and its account page opens');
    same(['MEMBER'], verifyLikeAnApp(new Browser(), intoSurvey($browser)['access_token'], 'survey')['roles'] ?? null, 'and a site signs it in, a MEMBER like anybody');
    same([], waitingIn($admin), 'nobody waits');
});

test('a sign-up started from a site waits like any other, and once approved the site\'s sign-in goes through', function () {
    $admin = admin();
    $browser = new Browser('10.12.3.1');
    $request = queryOf(startSignIn($browser)['location'])['request'] ?? '';
    check($request !== '', 'the site sent the browser to the sign-in page');

    same([201, true], [($r = register($browser, 'visitor', $request))->status, $r->json()['approval_pending'] ?? null], 'signed up from the survey site: waiting');
    $confirmed = $browser->post('/api/register/confirm', ['token' => mailedToken('visitor@example.test', 'confirm-email')]);
    same([403, 'approval_pending'], [$confirmed->status, $confirmed->code()], 'confirmed, not signed in');

    same(true, $admin->post('/api/admin/users/' . userIdOf('visitor') . '/approve')->json()['mailed'] ?? null, 'approved, and mailed');
    same(200, $browser->signIn('visitor@example.test', 'a long password')->status, 'it signs in');
    $accepted = $browser->post("/api/login/request/{$request}/accept");
    check(isset(queryOf($accepted->json()['redirect'] ?? '')['code']), 'and the sign-in the site started gets its code: ' . $accepted->body);
});

test('an account approved before its address is confirmed is mailed nothing: its confirmation link signs it in', function () {
    $admin = admin();
    $browser = new Browser('10.12.4.1');
    same(201, register($browser, 'early', null, 'sk')->status, 'made, waiting');

    $approved = $admin->post('/api/admin/users/' . userIdOf('early') . '/approve');
    same([200, false], [$approved->status, $approved->json()['mailed'] ?? null], 'approved, nobody mailed');
    same('Potvrď svoj účet anotoki', lastMailTo('early@example.test')['subject'] ?? null, 'the newest mail is still the confirmation (in Slovak)');
    same(200, $browser->post('/api/register/confirm', ['token' => mailedToken('early@example.test', 'confirm-email')])->status, 'the link signs it in');

    // One that waits in Slovak, approved once confirmed: the mail is in Slovak.
    $slovak = new Browser('10.12.4.2');
    same(201, register($slovak, 'neskoro', null, 'sk')->status, 'another, waiting');
    same(403, $slovak->post('/api/register/confirm', ['token' => mailedToken('neskoro@example.test', 'confirm-email')])->status, 'confirmed, waiting');
    same(true, $admin->post('/api/admin/users/' . userIdOf('neskoro') . '/approve')->json()['mailed'] ?? null, 'approved: mailed');
    $mail = lastMailTo('neskoro@example.test');
    same('Tvoj účet anotoki je schválený', $mail['subject'] ?? null, 'in its own language');
    check(str_starts_with($mail['body'] ?? '', "Ahoj neskoro,\n\nSprávca schválil tvoj účet anotoki."), 'greeted in it: ' . ($mail['body'] ?? ''));
});

test('signing up with Google: made, waiting, no session - and Google again says the same until approved', function () {
    $admin = admin();
    $browser = new Browser('10.12.5.1');

    $made = $browser->post('/api/login/google', ['credential' => googleToken('google-sub-1', 'gwen@example.test', 'Gwen'), 'language' => 'en']);
    same([403, 'approval_pending', true], [$made->status, $made->code(), $made->json()['created'] ?? null], 'made and waiting: ' . $made->body);
    same([], $browser->cookies, 'no session');
    $id = userIdOf('Gwen');
    same([false, true], [asBool(userRow($id)['approved']), asBool(userRow($id)['email_confirmed'])], 'waiting, its address confirmed by Google');
    same('google', identityFor(iamDb(), $id, IDENTITY_GOOGLE)['provider'] ?? null, 'the identity attached');
    same(['via' => 'google', 'waits' => true], lastAuditOf('account.created'), 'logged');
    same(['Gwen'], waitingIn($admin), 'listed');

    same([403, 'approval_pending'], [($r = $browser->post('/api/login/google', ['credential' => googleToken('google-sub-1', 'gwen@example.test', 'Gwen')]))->status, $r->code()], 'Google again: still waiting');

    same(true, $admin->post("/api/admin/users/{$id}/approve")->json()['mailed'] ?? null, 'approved; Google confirmed the address, so it is mailed');
    $in = $browser->post('/api/login/google', ['credential' => googleToken('google-sub-1', 'gwen@example.test', 'Gwen')]);
    same([200, false], [$in->status, $in->json()['created'] ?? null], 'and signs in with Google: ' . $in->body);
});

test('Google vouching for the address of an account that waits, never confirmed: the address is confirmed and Google attached, so the approval can be mailed', function () {
    $admin = admin();
    same(201, register(new Browser('10.12.5.2'), 'gina')->status, 'registered with the form, waiting, its address not confirmed');
    $id = userIdOf('gina');

    $browser = new Browser('10.12.5.3');
    $answer = $browser->post('/api/login/google', ['credential' => googleToken('google-sub-2', 'gina@example.test', 'Gina')]);
    same([403, 'approval_pending'], [$answer->status, $answer->code()], 'still waiting: ' . $answer->body);
    same([], $browser->cookies, 'no session');
    same([true, false, null], [asBool(userRow($id)['email_confirmed']), asBool(userRow($id)['approved']), userRow($id)['password']],
        'the address is confirmed by Google; whatever the registrant set is gone (rule 13); it waits');
    same('google', identityFor(iamDb(), $id, IDENTITY_GOOGLE)['provider'] ?? null, 'and Google is attached');

    same(true, $admin->post("/api/admin/users/{$id}/approve")->json()['mailed'] ?? null, 'approved, and mailed to the address Google vouched for');
    same(200, $browser->post('/api/login/google', ['credential' => googleToken('google-sub-2', 'gina@example.test', 'Gina')])->status, 'and Google signs it in');
});

test('an address nobody has confirmed is never declined: its owner takes the account back, and is approved like anybody', function () {
    $admin = admin();
    // Somebody registers an address that is not theirs, with a password of their own.
    same(201, register(new Browser('10.12.10.1'), 'squatter')->status, 'a stranger registers it');
    $id = userIdOf('squatter');

    $refused = $admin->post("/api/admin/users/{$id}/decline");
    same([409, 'not_confirmed'], [$refused->status, $refused->code()], 'declining it is refused: ' . $refused->body);
    same(false, asBool(userRow($id)['disabled']), 'it is not switched off');
    same([403, 'email_not_confirmed'], [($r = (new Browser('10.12.10.1'))->signIn('squatter@example.test', 'a long password'))->status, $r->code()], 'and its registrant cannot sign in with it anyway');

    // The address's owner takes it back with a reset link from their own mailbox.
    $owner = new Browser('10.12.10.2');
    same(200, $owner->post('/api/password/forgot', ['email' => 'squatter@example.test'])->status, 'the owner asks for a reset');
    $reset = $owner->post('/api/password/reset', ['token' => mailedToken('squatter@example.test', 'reset-password'), 'password' => 'the owner\'s own']);
    same([403, 'approval_pending'], [$reset->status, $reset->code()], 'it is theirs now, and waits like any new account');
    same([401, 'invalid_credentials'], [($r = $owner->signIn('squatter@example.test', 'a long password'))->status, $r->code()], 'the registrant\'s password no longer opens it');
    same(true, asBool(userRow($id)['email_confirmed']), 'the address is confirmed');

    same(true, $admin->post("/api/admin/users/{$id}/approve")->json()['mailed'] ?? null, 'approved, and mailed - to its owner');
    same(200, $owner->signIn('squatter@example.test', 'the owner\'s own')->status, 'the owner signs in');
});

// ── Who never waits, and switching it off ────────────────────────────────────

test('accounts an administrator makes never wait; switching the approval off lets in everybody still waiting, never one declined', function () {
    $admin = admin();
    $made = $admin->post('/api/admin/users', ['username' => 'handmade', 'email' => 'handmade@example.test', 'password' => 'correct horse']);
    same([201, true], [$made->status, $made->json()['approved'] ?? null], 'made in the panel: approved');
    same(200, (new Browser('10.12.6.9'))->signIn('handmade@example.test', 'correct horse')->status, 'and it signs in');

    foreach (['one', 'two'] as $name) {
        same(201, register(new Browser('10.12.6.1'), "waiting{$name}")->status, "waiting{$name} made");
    }
    $turnedAway = new Browser('10.12.6.4');
    same(201, register($turnedAway, 'turnedaway')->status, 'and a third');
    same(403, $turnedAway->post('/api/register/confirm', ['token' => mailedToken('turnedaway@example.test', 'confirm-email')])->status, 'which confirms its address');
    same(200, $admin->post('/api/admin/users/' . userIdOf('turnedaway') . '/decline')->status, 'and is declined');
    same(['waitingone', 'waitingtwo'], waitingIn($admin), 'two wait');

    accountApproval($admin, false);
    same(['accounts' => 2], lastAuditOf('approvals.released'), 'switched off: both let in, and logged');
    same(['turnedaway'], iamDb()->query('SELECT username FROM users WHERE NOT approved')->fetchAll(PDO::FETCH_COLUMN), 'nobody waits; the declined one stays declined');
    $browser = new Browser('10.12.6.2');
    same(200, $browser->post('/api/register/confirm', ['token' => mailedToken('waitingone@example.test', 'confirm-email')])->status, 'the confirmation link signs one in');

    same(200, $admin->put('/api/admin/users/' . userIdOf('turnedaway') . '/enabled', ['enabled' => true])->status, 'the declined one switched on again');
    same(['turnedaway'], waitingIn($admin), 'waits for an administrator\'s word, as its decline said - the switch being off changes nothing for it');
    same([403, 'approval_pending'], [($r = $turnedAway->signIn('turnedaway@example.test', 'a long password'))->status, $r->code()], 'and is not let in by it');
    same(200, $admin->post('/api/admin/users/' . userIdOf('turnedaway') . '/approve')->status, 'until approved');

    accountApproval($admin, true);
    same(true, asBool(userRow(userIdOf('waitingtwo'))['approved']), 'switched on again, it is about accounts made from then on');
    accountApproval($admin, false);
    same(false, register(new Browser('10.12.6.3'), 'freely')->json()['approval_pending'] ?? null, 'and off, a new account does not wait');
});

test('with the switch off: the form\'s account works once its address is confirmed; Google\'s at once, Google having vouched for the address', function () {
    accountApproval(admin(), false);

    $browser = new Browser('10.12.6.5');
    same([201, false], [($r = register($browser, 'plainform'))->status, $r->json()['approval_pending'] ?? null], 'registered with the form, not waiting for anybody');
    same([[], 'email_not_confirmed'], [$browser->cookies, $browser->signIn('plainform@example.test', 'a long password')->code()], 'no session, and no way in before the link');
    same(200, $browser->post('/api/register/confirm', ['token' => mailedToken('plainform@example.test', 'confirm-email')])->status, 'the link confirms the address and signs it in');

    $google = new Browser('10.12.6.6');
    $made = $google->post('/api/login/google', ['credential' => googleToken('google-sub-3', 'plaingoogle@example.test', 'Plaingoogle')]);
    same([201, true], [$made->status, $made->json()['created'] ?? null], 'Google makes the account and signs it in at once: ' . $made->body);
    check(isset($google->cookies['anotoki_iam']), 'with a session');
    $row = userRow(userIdOf('Plaingoogle'));
    same([true, true], [asBool($row['email_confirmed']), asBool($row['approved'])], 'its address confirmed by Google, nothing to wait for');
    same(null, lastMailTo('plaingoogle@example.test'), 'and no confirmation mailed');
});

test('a sign-up and the switch turned off at the same moment go one after the other: nobody is left waiting with the switch off', function () {
    accountApproval(admin(), true);

    // Another administrator's save switching it off, half done: the row is
    // changed and locked, everybody waiting not yet let in, nothing committed.
    $other = connectDatabase(testServer(), testEnvironment()['db'], false);
    $other->beginTransaction();
    $other->exec("UPDATE settings SET value = 'false' WHERE name = 'registration_approval'");

    iamDb()->exec("SET lock_timeout = '300ms'");
    try {
        $waited = register(new Browser('10.12.11.1'), 'racer');
    } finally {
        iamDb()->exec('SET lock_timeout = 0');
    }
    same(500, $waited->status, 'the sign-up waits for the save (here: until its lock timeout) rather than reading the switch as it was before it');
    same(0, userIdOf('racer'), 'and makes nothing meanwhile');

    $other->exec('UPDATE users SET approved = TRUE WHERE NOT approved AND NOT disabled');
    $other->commit();
    settingsAll(true);
    $made = register(new Browser('10.12.11.1'), 'racer');
    same([201, false], [$made->status, $made->json()['approval_pending'] ?? null], 'once the save is in, the sign-up reads the switch as it is: off, and the account does not wait');
});

test('an account that waits holds nothing it could use: should one hold a session or tokens, every door refuses them', function () {
    // Not a state the IAM makes - an account waits only from its making - but
    // the same switch is checked on every door, so it is tried on each.
    $browser = new Browser('10.12.12.1');
    same(200, $browser->signIn('member@example.test', 'correct horse')->status, 'an account signs in');
    $tokens = intoSurvey($browser);
    $personal = $browser->post('/api/account/tokens', ['name' => 'script', 'client_id' => 'survey', 'expires_in_days' => 30]);
    same(201, $personal->status, 'and makes a personal token: ' . $personal->body);

    iamDb()->prepare('UPDATE users SET approved = FALSE WHERE id = ?')->execute([$GLOBALS['memberId']]);
    try {
        $start = startSignIn($browser);
        check(isset($browser->cookies['anotoki_iam']) && !isset(queryOf($start['location'])['code']), 'no code from single sign-on, the session cookie sent: ' . $start['location']);
        same([401, 'no_session'], [($r = $browser->post('/api/login/request/' . (queryOf($start['location'])['request'] ?? '') . '/accept'))->status, $r->code()], 'nor from the sign-in page');
        same([401, 'no_session'], [($r = $browser->get('/api/account'))->status, $r->code()], 'the account page: its session lets it in no more');
        $refresh = (new Browser())->request('POST', '/api/oidc/token', [
            'form' => ['grant_type' => 'refresh_token', 'refresh_token' => $tokens['refresh_token'], 'client_id' => 'survey'], 'headers' => ['Origin' => SURVEY_FRONTEND],
        ]);
        same([400, 'invalid_grant'], [$refresh->status, $refresh->json()['error'] ?? null], 'the refresh token is refused');
        $script = (new Browser())->request('POST', '/api/oidc/token', ['form' => ['grant_type' => 'refresh_token', 'refresh_token' => $personal->json()['token'], 'client_id' => 'survey']]);
        same([400, 'invalid_grant'], [$script->status, $script->json()['error'] ?? null], 'and so is the personal token');
        same(401, (new Browser())->get('/api/oidc/userinfo', ['headers' => ['Authorization' => 'Bearer ' . $tokens['access_token']]])->status, 'and userinfo says the access token is no good');
    } finally {
        iamDb()->prepare('UPDATE users SET approved = TRUE WHERE id = ?')->execute([$GLOBALS['memberId']]);
    }
});

test('the decisions: somebody waiting, by an administrator, with the CSRF header', function () {
    $admin = admin();
    $memberId = $GLOBALS['memberId'];
    same([404, 'not_found'], [($r = $admin->post('/api/admin/users/999999/approve'))->status, $r->code()], 'nobody to approve');
    same([404, 'not_found'], [($r = $admin->post('/api/admin/users/999999/decline'))->status, $r->code()], 'nobody to decline');
    same([409, 'not_waiting', 'member is not waiting for approval'], [($r = $admin->post("/api/admin/users/{$memberId}/approve"))->status, $r->code(), $r->json()['error'] ?? null], 'not waiting');
    same([409, 'not_waiting'], [($r = $admin->post("/api/admin/users/{$memberId}/decline"))->status, $r->code()], 'not waiting, so not declined');
    same(false, asBool(userRow($memberId)['disabled']), 'and nothing was switched off by it');

    $member = new Browser('10.12.7.1');
    $member->signIn('member@example.test', 'correct horse');
    same([403, 'forbidden'], [($r = $member->get('/api/admin/approvals'))->status, $r->code()], 'the list is the administrators\'');
    same([403, 'forbidden'], [($r = $member->post("/api/admin/users/{$memberId}/approve"))->status, $r->code()], 'and so are the decisions');
    same([403, 'csrf'], [($r = $admin->post("/api/admin/users/{$memberId}/approve", [], ['csrf' => false]))->status, $r->code()], 'a decision needs the CSRF header');
    same([401, 'no_session'], [($r = (new Browser('10.12.7.2'))->get('/api/admin/approvals'))->status, $r->code()], 'and a session');

    // An account that waits signs in nowhere and administers nothing - yet the
    // last-admin rule would count it as an administrator left.
    accountApproval($admin, true);
    same(201, register(new Browser('10.12.7.3'), 'climber')->status, 'one waits');
    $climber = userIdOf('climber');
    $roles = [...$admin->get("/api/admin/users/{$climber}")->json()['roles'], ['client_id' => 'iam', 'role' => 'ADMIN']];
    same([422, 'not_approved'], [($r = $admin->put("/api/admin/users/{$climber}/roles", ['roles' => $roles]))->status, $r->code()], 'it cannot be made the IAM\'s administrator');
    same(false, isIamAdmin($climber), 'and is not');
    same(200, $admin->post("/api/admin/users/{$climber}/approve")->status, 'approved');
    same(200, $admin->put("/api/admin/users/{$climber}/roles", ['roles' => $roles])->status, 'it can be');
    accountApproval($admin, false);
});

// ── With 012 pending: a build uploaded before Apply ──────────────────────────

test('with 012 pending signing up and signing in work as before, so do the panel\'s pages, and the approvals wait for Apply', function () {
    $main = testEnvironment()['db'];
    $window = throwawayDatabase($main . '_window');
    buildIamThrough('_window', '011_public_clients_static_roles.sql');
    useIamDatabase($window);

    try {
        $pdo = iamDb();
        same(false, accountApprovalReady($pdo), 'no approvals yet');
        $adminId = makeUser('admin', 'admin@example.test');
        grantRole($adminId, 'iam', 'ADMIN');
        makeUser('person', 'person@example.test');

        $person = new Browser('10.12.8.1');
        same(200, $person->signIn('person@example.test', 'correct horse')->status, 'signing in');
        intoSurvey($person);
        same(false, (new Browser('10.12.8.2'))->get('/api/public/config')->json()['registration_approval'] ?? null, 'the switch reads as off');
        $made = register(new Browser('10.12.8.2'), 'windowed');
        same([201, false], [$made->status, $made->json()['approval_pending'] ?? null], 'signing up: ' . $made->body);
        same(200, (new Browser('10.12.8.3'))->post('/api/register/confirm', ['token' => mailedToken('windowed@example.test', 'confirm-email')])->status, 'and its link signs it in');

        $admin = admin();
        foreach (['GET /api/admin/approvals', "POST /api/admin/users/{$adminId}/approve", "POST /api/admin/users/{$adminId}/decline"] as $call) {
            [$method, $path] = explode(' ', $call);
            $answer = $method === 'GET' ? $admin->get($path) : $admin->post($path);
            same([503, 'sign_in_rules_unavailable'], [$answer->status, $answer->code()], $call);
        }
        $users = $admin->get('/api/admin/users');
        same([200, [true]], [$users->status, array_values(array_unique(array_column($users->json(), 'approved')))], 'the users pages: everybody approved');
        same(200, $admin->get('/api/admin/applications')->status, 'the application pages answer as they did');
        same(0, $admin->get('/api/admin/stats')->json()['awaiting_approval'] ?? null, 'the dashboard counts nobody');
        same([422, 'unknown_setting'], [($r = $admin->put('/api/admin/settings', ['settings' => [['name' => 'registration_approval', 'value' => 'true']]]))->status, $r->code()],
            'there is no switch to turn on yet');

        $applied = $admin->post('/api/admin/migrations/apply');
        same([200, [APPROVAL_MIGRATION], null], [$applied->status, array_slice(migrationNames($applied->json()['applied'] ?? []), 0, 1), $applied->json()['failed'] ?? null], 'Apply pending, 012 first: ' . $applied->body);
        same(true, accountApprovalReady($pdo), 'from the next request on, in the same process');
        same([200, []], [($r = $admin->get('/api/admin/approvals'))->status, $r->json()['accounts'] ?? null], 'the approvals answer, nobody waiting');
        same(true, asBool(userRow(userIdOf('windowed'))['approved']), 'the account made in the window is approved');
        accountApproval($admin, true);
        same(true, register(new Browser('10.12.8.4'), 'afterwards')->json()['approval_pending'] ?? null, 'and the switch works');
    } finally {
        useIamDatabase($main);
    }

    same($main, iamDb()->query('SELECT current_database()')->fetchColumn(), 'back on this run\'s own database');
});

finishTests();
