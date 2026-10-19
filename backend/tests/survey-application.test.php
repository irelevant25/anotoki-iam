<?php

/**
 * The survey site as an application: migration 005 reaching IAMs installed
 * before it, and the survey site signing in on one built from all of them.
 *
 * 005 is the first seeding migration that also arrives at installations that
 * exist already, one of them live, so the IAMs it may find are built here the
 * way they were left: a database of their own holding the migrations before
 * it, a development IAM or a live one, with accounts and roles - and then 005
 * on top. Where the IAM itself is served from (its own entry's home, which the
 * installer sets) decides whether the site gets development addresses, and an
 * entry made by hand in the admin panel must come through as it was.
 */

declare(strict_types=1);

require __DIR__ . '/lib.php';

// This run's own database is a fresh IAM, built from every migration, 005 included.
bootTests();

const SURVEY_MIGRATION = '005_survey_application.sql';
const SURVEY_FRONTEND = 'http://localhost:4700';
const SURVEY_CALLBACK = 'http://localhost:4700/auth/callback';

/**
 * An IAM installed before 005: a throwaway database holding the migrations that
 * come before it, the IAM's own entry at $iamHome (as the installer leaves it).
 * The name is this run's database's plus a suffix, so it is dropped with it.
 */
function iamBeforeSurvey(string $suffix, ?string $iamHome): PDO
{
    $name = throwawayDatabase(testEnvironment()['db'] . $suffix);
    ensureDatabase(testServer(), $name);
    $pdo = connectDatabase(testServer(), $name, false);

    check(in_array(SURVEY_MIGRATION, migrationFiles('iam'), true), 'the survey site\'s migration is among the files');
    ensureMigrationsTable($pdo);
    foreach (migrationFiles('iam') as $file) {
        if (strnatcmp($file, SURVEY_MIGRATION) < 0) {
            applyMigration($pdo, 'iam', $file);
        }
    }
    check(!in_array(SURVEY_MIGRATION, appliedMigrations($pdo), true), 'built without 005');
    same(0, (int) $pdo->query("SELECT count(*) FROM applications WHERE client_id = 'survey'")->fetchColumn(), 'and without the survey site');

    $pdo->prepare("UPDATE applications SET home_url = ? WHERE client_id = 'iam'")->execute([$iamHome]);

    return $pdo;
}

/** What a live IAM's install did to the seeded sites: every loopback address out, the production ones in. */
function asInstalledOnARealHost(PDO $pdo): void
{
    $pdo->exec("DELETE FROM application_redirect_uris WHERE uri ~* '^https?://(localhost|127\\.0\\.0\\.1|\\[::1\\])'");
    $pdo->exec("DELETE FROM application_origins WHERE origin ~* '^https?://(localhost|127\\.0\\.0\\.1|\\[::1\\])'");
    $pdo->exec("UPDATE applications SET home_url = NULL WHERE client_id NOT IN ('iam') AND home_url ~* '^https?://(localhost|127\\.0\\.0\\.1|\\[::1\\])'");

    $genshin = (int) $pdo->query("SELECT id FROM applications WHERE client_id = 'genshin'")->fetchColumn();
    $pdo->prepare("INSERT INTO application_redirect_uris (application_id, kind, uri) VALUES (?, 'login', ?), (?, 'logout', ?)")->execute([
        $genshin,
        'https://genshin.example.test/auth/callback',
        $genshin,
        'https://genshin.example.test/',
    ]);
    $pdo->prepare('INSERT INTO application_origins (application_id, origin) VALUES (?, ?)')->execute([$genshin, 'https://genshin.example.test']);
    $pdo->prepare('UPDATE applications SET home_url = ? WHERE id = ?')->execute(['https://genshin.example.test', $genshin]);
}

function accountIn(PDO $pdo, string $username): int
{
    $insert = $pdo->prepare('INSERT INTO users (username, email, password, email_confirmed) VALUES (?, ?, ?, TRUE) RETURNING id');
    $insert->execute([$username, "{$username}@example.test", password_hash('correct horse', PASSWORD_DEFAULT)]);

    return (int) $insert->fetchColumn();
}

function grantIn(PDO $pdo, int $userId, string $clientId, string $role): void
{
    $pdo->prepare(
        'INSERT INTO user_roles (user_id, role_id)
         SELECT ?, r.id FROM application_roles r JOIN applications a ON a.id = r.application_id WHERE a.client_id = ? AND r.name = ?',
    )->execute([$userId, $clientId, $role]);
}

/**
 * Applies 005, the way the CLI, the installer and the admin panel do - and
 * nothing after it, so what is asserted is what 005 leaves (010 gives every
 * application MEMBER and every account MEMBER in it: sign-in-rules.test.php).
 */
function applySurveyMigration(PDO $pdo): void
{
    $result = applyMigrationsThrough($pdo, SURVEY_MIGRATION);
    same(null, $result['failed'], 'nothing fails (' . ($result['error'] ?? '') . ')');
    same([SURVEY_MIGRATION], $result['applied'], '005 is applied, and nothing after it');
    same(1, (int) $pdo->query("SELECT count(*) FROM migrations WHERE filename = '" . SURVEY_MIGRATION . "'")->fetchColumn(), 'and recorded as applied');
}

/** The survey site's entry as a database has it, or null. */
function surveyIn(PDO $pdo): ?array
{
    $row = $pdo->query("SELECT id, name, description, home_url, type, access, show_on_landing, system FROM applications WHERE client_id = 'survey'")->fetch();
    if (!$row) {
        return null;
    }

    $column = static function (string $sql) use ($pdo, $row): array {
        $statement = $pdo->prepare($sql);
        $statement->execute([$row['id']]);

        return $statement->fetchAll(PDO::FETCH_COLUMN);
    };
    $holders = static function (string $role) use ($pdo, $row): array {
        $statement = $pdo->prepare('SELECT ur.user_id FROM user_roles ur JOIN application_roles r ON r.id = ur.role_id WHERE r.application_id = ? AND r.name = ? ORDER BY ur.user_id');
        $statement->execute([$row['id'], $role]);

        return array_map('intval', $statement->fetchAll(PDO::FETCH_COLUMN));
    };

    return [
        'name' => $row['name'],
        'description' => $row['description'],
        'home_url' => $row['home_url'],
        'type' => $row['type'],
        'access' => $row['access'],
        'show_on_landing' => asBool($row['show_on_landing']),
        'system' => asBool($row['system']),
        'login' => $column("SELECT uri FROM application_redirect_uris WHERE application_id = ? AND kind = 'login' ORDER BY uri"),
        'logout' => $column("SELECT uri FROM application_redirect_uris WHERE application_id = ? AND kind = 'logout' ORDER BY uri"),
        'origins' => $column('SELECT origin FROM application_origins WHERE application_id = ? ORDER BY origin'),
        'roles' => $column('SELECT name FROM application_roles WHERE application_id = ? ORDER BY name'),
        'admins' => $holders('ADMIN'),
        'editors' => $holders('EDITOR'),
        'members' => $holders('MEMBER'),
    ];
}

/** A development IAM with two administrators and an ordinary account, then 005: what each check of this computer expects. */
function checkADevelopmentIam(string $suffix, string $iamHome): void
{
    $pdo = iamBeforeSurvey($suffix, $iamHome);
    $owner = accountIn($pdo, 'owner');
    $second = accountIn($pdo, 'second');
    $ordinary = accountIn($pdo, 'ordinary');
    grantIn($pdo, $owner, 'iam', 'ADMIN');
    grantIn($pdo, $second, 'iam', 'ADMIN');
    // ADMIN of another site is not ADMIN of the IAM: it earns nothing here.
    grantIn($pdo, $ordinary, 'genshin', 'ADMIN');
    grantIn($pdo, $ordinary, 'genshin', 'EDITOR');

    applySurveyMigration($pdo);
    $survey = surveyIn($pdo);

    check($survey !== null, 'the survey site is there');
    same('Surveys', $survey['name'], 'called what it is');
    same('Answer surveys, one step at a time.', $survey['description'], 'with its description');
    same('public', $survey['type'], 'a browser app, PKCE and no secret');
    same('everyone', $survey['access'], 'anybody signed in takes surveys');
    same(false, $survey['system'], 'an ordinary application, removable in the admin panel');
    same(SURVEY_FRONTEND, $survey['home_url'], 'home is its dev server');
    same(true, $survey['show_on_landing'], 'with a card on the landing page');
    same([SURVEY_CALLBACK], $survey['login'], 'the sign-in callback of its dev server');
    same([SURVEY_FRONTEND . '/'], $survey['logout'], 'the landing after sign-out');
    same([SURVEY_FRONTEND], $survey['origins'], 'its origin');
    same(['ADMIN', 'EDITOR'], $survey['roles'], 'the roles the site asks for');
    same([$owner, $second], $survey['admins'], 'both IAM administrators hold survey ADMIN, and nobody else - not another site\'s ADMIN either');
    same([], $survey['editors'], 'EDITOR is nobody\'s until it is given');

    $ordinaryRoles = $pdo->prepare(
        "SELECT count(*) FROM user_roles ur JOIN application_roles r ON r.id = ur.role_id JOIN applications a ON a.id = r.application_id WHERE a.client_id = 'survey' AND ur.user_id = ?",
    );
    $ordinaryRoles->execute([$ordinary]);
    same(0, (int) $ordinaryRoles->fetchColumn(), 'the ordinary account holds no survey role');
}

// ─────────────────────────────────────────────────────────────────────────────

test('a fresh IAM has run 005, and seeds the survey site with its development addresses', function () {
    same([], pendingMigrations(iamDb(), 'iam'), 'nothing pending');
    check(in_array(SURVEY_MIGRATION, appliedMigrations(iamDb()), true), '005 recorded');

    $survey = surveyIn(iamDb());
    check($survey !== null, 'the survey site is there');
    same(SURVEY_FRONTEND, $survey['home_url'], 'home is its dev server');
    same([SURVEY_CALLBACK], $survey['login'], 'the callback');
    same([], $survey['admins'], 'a fresh IAM has no accounts yet, so nobody holds survey ADMIN');
});

test('a development IAM from before 005: the site\'s development addresses and card, and its administrators hold ADMIN', function () {
    checkADevelopmentIam('_dev', 'http://localhost:4300');
});

test('an IAM served from 127.0.0.1 is on this computer too', function () {
    checkADevelopmentIam('_loopback', 'http://127.0.0.1:4300');
});

test('and one served from [::1]', function () {
    checkADevelopmentIam('_v6', 'http://[::1]:4300');
});

test('a live IAM from before 005: the site without a loopback address or a card, waiting for its https address', function () {
    $pdo = iamBeforeSurvey('_live', 'https://iam.example.test');
    asInstalledOnARealHost($pdo);
    $owner = accountIn($pdo, 'owner');
    $player = accountIn($pdo, 'player');
    grantIn($pdo, $owner, 'iam', 'ADMIN');
    grantIn($pdo, $player, 'genshin', 'ADMIN');

    applySurveyMigration($pdo);
    $survey = surveyIn($pdo);

    check($survey !== null, 'the survey site is there');
    same('Surveys', $survey['name'], 'called what it is');
    same('everyone', $survey['access'], 'open to every account');
    same(null, $survey['home_url'], 'no home: the admin panel gives it its address');
    same(false, $survey['show_on_landing'], 'and no card leading nowhere');
    same([], $survey['login'], 'no redirect URI - a localhost one would hand codes to the visitor\'s own machine');
    same([], $survey['logout'], 'nor a landing after sign-out');
    same([], $survey['origins'], 'nor an origin');
    same(['ADMIN', 'EDITOR'], $survey['roles'], 'the roles are there');
    same([$owner], $survey['admins'], 'the IAM\'s administrator holds survey ADMIN - and the genshin site\'s ADMIN does not');
    same([], $survey['editors'], 'nobody holds EDITOR');

    same(0, (int) $pdo->query("SELECT count(*) FROM application_redirect_uris WHERE uri ~* '^https?://(localhost|127\\.0\\.0\\.1|\\[::1\\])'")->fetchColumn(), 'no loopback redirect URI anywhere');
    same(0, (int) $pdo->query("SELECT count(*) FROM application_origins WHERE origin ~* '^https?://(localhost|127\\.0\\.0\\.1|\\[::1\\])'")->fetchColumn(), 'nor a loopback origin');
});

test('an IAM with no home of its own is not taken for this computer', function () {
    $pdo = iamBeforeSurvey('_nohome', null);
    asInstalledOnARealHost($pdo);

    applySurveyMigration($pdo);
    $survey = surveyIn($pdo);

    check($survey !== null, 'the survey site is there');
    same(null, $survey['home_url'], 'no home');
    same(false, $survey['show_on_landing'], 'no card');
    same([], array_merge($survey['login'], $survey['logout'], $survey['origins']), 'no address at all');
});

test('an address that only starts or ends like this computer is a live IAM', function () {
    $lookalikes = [
        '_look1' => 'https://localhost.example.test',
        '_look2' => 'https://127.0.0.1.example.test',
        '_look3' => 'https://iam.example.test/localhost',
        '_look4' => 'http://localhost@iam.example.test',
    ];

    foreach ($lookalikes as $suffix => $iamHome) {
        $pdo = iamBeforeSurvey($suffix, $iamHome);
        asInstalledOnARealHost($pdo);

        applySurveyMigration($pdo);
        $survey = surveyIn($pdo);

        check($survey !== null, "{$iamHome}: the survey site is there");
        same(null, $survey['home_url'], "{$iamHome}: no home");
        same(false, $survey['show_on_landing'], "{$iamHome}: no card");
        same([], array_merge($survey['login'], $survey['logout'], $survey['origins']), "{$iamHome}: no loopback address");
    }
});

test('a development IAM that made `survey` by hand gets what is missing beside what it has', function () {
    $pdo = iamBeforeSurvey('_devbyhand', 'http://localhost:4300');
    $owner = accountIn($pdo, 'owner');
    $writer = accountIn($pdo, 'writer');
    grantIn($pdo, $owner, 'iam', 'ADMIN');

    // Made in the admin panel while the site was tried on another port: the
    // port table's callback and origin are there already, its landing after
    // sign-out is not, and the only role is EDITOR, which somebody holds.
    $insert = $pdo->prepare("INSERT INTO applications (client_id, name, home_url, type, access, show_on_landing, sort_order, system)
                             VALUES ('survey', 'Survey, by hand', 'http://localhost:4799', 'public', 'everyone', FALSE, 7, FALSE) RETURNING id");
    $insert->execute();
    $id = (int) $insert->fetchColumn();
    $pdo->prepare("INSERT INTO application_redirect_uris (application_id, kind, uri) VALUES (?, 'login', ?), (?, 'login', ?)")->execute([
        $id,
        SURVEY_CALLBACK,
        $id,
        'http://localhost:4799/auth/callback',
    ]);
    $pdo->prepare('INSERT INTO application_origins (application_id, origin) VALUES (?, ?), (?, ?)')->execute([$id, SURVEY_FRONTEND, $id, 'http://localhost:4799']);
    $pdo->prepare("INSERT INTO application_roles (application_id, name, description) VALUES (?, 'EDITOR', 'Writes')")->execute([$id]);
    grantIn($pdo, $writer, 'survey', 'EDITOR');

    applySurveyMigration($pdo);
    $survey = surveyIn($pdo);

    same('Survey, by hand', $survey['name'], 'its name is kept');
    same('http://localhost:4799', $survey['home_url'], 'and its home');
    same(false, $survey['show_on_landing'], 'and no card it did not have');
    same([SURVEY_CALLBACK, 'http://localhost:4799/auth/callback'], $survey['login'], 'the callback it had is there once, the other port\'s beside it');
    same([SURVEY_FRONTEND . '/'], $survey['logout'], 'the landing after sign-out is added');
    same([SURVEY_FRONTEND, 'http://localhost:4799'], $survey['origins'], 'the origin it had is there once, the other port\'s beside it');
    same(['ADMIN', 'EDITOR'], $survey['roles'], 'ADMIN is added to the EDITOR it had');
    same([$writer], $survey['editors'], 'whoever held EDITOR still does');
    same([$owner], $survey['admins'], 'and the IAM\'s administrator holds the new ADMIN');
});

test('a live IAM that made `survey` by hand keeps it as it is through 005, only what is missing added; then 007 opens it', function () {
    $pdo = iamBeforeSurvey('_byhand', 'https://iam.example.test');
    asInstalledOnARealHost($pdo);
    $owner = accountIn($pdo, 'owner');
    $second = accountIn($pdo, 'second');
    grantIn($pdo, $owner, 'iam', 'ADMIN');
    grantIn($pdo, $second, 'iam', 'ADMIN');

    // As the admin panel would have made it: another name, members only, its
    // https addresses, and one role, which the owner gave themselves.
    $insert = $pdo->prepare("INSERT INTO applications (client_id, name, description, home_url, type, access, show_on_landing, sort_order, system)
                             VALUES ('survey', 'Our surveys', 'Made by hand', 'https://survey.example.test', 'public', 'members', FALSE, 7, FALSE) RETURNING id");
    $insert->execute();
    $id = (int) $insert->fetchColumn();
    $pdo->prepare("INSERT INTO application_redirect_uris (application_id, kind, uri) VALUES (?, 'login', ?), (?, 'logout', ?)")->execute([
        $id,
        'https://survey.example.test/auth/callback',
        $id,
        'https://survey.example.test/',
    ]);
    $pdo->prepare('INSERT INTO application_origins (application_id, origin) VALUES (?, ?)')->execute([$id, 'https://survey.example.test']);
    $pdo->prepare("INSERT INTO application_roles (application_id, name, description) VALUES (?, 'ADMIN', 'The owner')")->execute([$id]);
    grantIn($pdo, $owner, 'survey', 'ADMIN');

    // 005's promise first, on its own: what was made by hand is kept, members
    // only included.
    applyMigration($pdo, 'iam', SURVEY_MIGRATION);
    $survey = surveyIn($pdo);

    same('Our surveys', $survey['name'], 'its name is kept');
    same('Made by hand', $survey['description'], 'and its description');
    same('members', $survey['access'], 'and who may use it');
    same('https://survey.example.test', $survey['home_url'], 'and its home');
    same(false, $survey['show_on_landing'], 'and whether it has a card');
    same(7, (int) $pdo->query("SELECT sort_order FROM applications WHERE client_id = 'survey'")->fetchColumn(), 'and its place');
    same(['https://survey.example.test/auth/callback'], $survey['login'], 'its callback, and no loopback one beside it');
    same(['https://survey.example.test/'], $survey['logout'], 'its landing after sign-out');
    same(['https://survey.example.test'], $survey['origins'], 'its origin');
    same(['ADMIN', 'EDITOR'], $survey['roles'], 'EDITOR is added to the ADMIN it had');
    same(
        'The owner',
        $pdo->query("SELECT r.description FROM application_roles r JOIN applications a ON a.id = r.application_id WHERE a.client_id = 'survey' AND r.name = 'ADMIN'")->fetchColumn(),
        'and the ADMIN it had is left as it was',
    );
    same([$owner, $second], $survey['admins'], 'the owner held it already and the other administrator is given it: each once');
    same([], $survey['editors'], 'nobody holds EDITOR');

    // Then what came after it: 007 opens every first-party site to every
    // account, this one too, and leaves the rest of what was made by hand.
    $result = applyMigrationsThrough($pdo, '007_every_site_for_every_account.sql');
    same(null, $result['failed'], 'nothing from 005 to 007 fails (' . ($result['error'] ?? '') . ')');
    $survey = surveyIn($pdo);
    same('everyone', $survey['access'], '007 opens it to every account');
    same(['Our surveys', 'Made by hand', 'https://survey.example.test'], [$survey['name'], $survey['description'], $survey['home_url']], 'and keeps its name, description and home');
    same(['ADMIN', 'EDITOR'], $survey['roles'], 'and its two roles');
    same(
        'The owner',
        $pdo->query("SELECT r.description FROM application_roles r JOIN applications a ON a.id = r.application_id WHERE a.client_id = 'survey' AND r.name = 'ADMIN'")->fetchColumn(),
        'whose words are kept: 007 rewords no survey role',
    );
    same([$owner, $second], $survey['admins'], 'each administrator still holds survey ADMIN once');

    // And the rest: 010 gives it MEMBER, which both accounts hold, and keeps
    // it requiring signing in - an 'everyone' site lets every account in; 023
    // gives it EARLY_ACCESS, which nobody holds.
    $result = applyPendingMigrations($pdo, 'iam');
    same(null, $result['failed'], 'nothing after 007 fails (' . ($result['error'] ?? '') . ')');
    same([], pendingMigrations($pdo, 'iam'), 'nothing left pending');
    $survey = surveyIn($pdo);
    same(['ADMIN', 'EARLY_ACCESS', 'EDITOR', 'MEMBER'], $survey['roles'], '010 adds MEMBER, 023 EARLY_ACCESS');
    same([$owner, $second], $survey['members'], 'which every account holds');
    same(true, asBool($pdo->query("SELECT login_required FROM applications WHERE client_id = 'survey'")->fetchColumn()), 'and the survey site requires signing in');
    same(
        ['ADMIN', 'EARLY_ACCESS', 'EDITOR', 'MEMBER'],
        $pdo
            ->query(
                "SELECT r.name FROM application_roles r JOIN applications a ON a.id = r.application_id
                                                      WHERE a.client_id = 'survey' AND r.may_sign_in ORDER BY r.name",
            )
            ->fetchAll(PDO::FETCH_COLUMN),
        'with every role allowed to sign in',
    );
    same([$owner, $second], $survey['admins'], 'each administrator still holds survey ADMIN once');
});

// ── The survey site signing in ───────────────────────────────────────────────

$surveyOwnerId = makeUser('surveyowner', 'surveyowner@example.test');
grantRole($surveyOwnerId, 'survey', 'ADMIN');
$respondentId = makeUser('respondent', 'respondent@example.test');

/** A whole sign-in from the survey site's dev server: authorize with PKCE, the sign-in page, the code exchange. */
function signInToSurvey(Browser $browser, string $email): array
{
    [$verifier, $challenge] = pkce();
    $state = bin2hex(random_bytes(8));
    $start = $browser->get(
        '/api/oidc/authorize?' .
            http_build_query([
                'response_type' => 'code',
                'client_id' => 'survey',
                'redirect_uri' => SURVEY_CALLBACK,
                'scope' => 'openid profile email',
                'state' => $state,
                'nonce' => bin2hex(random_bytes(8)),
                'code_challenge' => $challenge,
                'code_challenge_method' => 'S256',
            ]),
    );
    $request = queryOf($start->location())['request'] ?? null;
    check($request !== null, 'authorize should send an anonymous browser to the login page, sent to ' . $start->location());

    same(200, $browser->signIn($email, 'correct horse')->status, 'sign-in');
    $accept = $browser->post("/api/login/request/{$request}/accept");
    same(200, $accept->status, 'accept: ' . $accept->body);
    $redirect = (string) ($accept->json()['redirect'] ?? '');
    check(str_starts_with($redirect, SURVEY_CALLBACK . '?'), 'back to the survey site\'s callback: ' . $redirect);
    $callback = queryOf($redirect);
    same($state, $callback['state'] ?? null, 'state comes back');

    $tokens = $browser->request('POST', '/api/oidc/token', [
        'form' => ['grant_type' => 'authorization_code', 'code' => $callback['code'] ?? '', 'redirect_uri' => SURVEY_CALLBACK, 'client_id' => 'survey', 'code_verifier' => $verifier],
        'headers' => ['Origin' => SURVEY_FRONTEND],
    ]);
    same(200, $tokens->status, 'code exchange: ' . $tokens->body);

    return $tokens->json();
}

test('the survey site signs its administrator in, and the token says ADMIN to the survey site only', function () {
    $browser = new Browser();
    $tokens = signInToSurvey($browser, 'surveyowner@example.test');

    $claims = verifyLikeAnApp($browser, $tokens['access_token'], 'survey');
    check($claims !== null, 'the access token verifies as the survey site verifies it');
    same('survey', $claims['aud'], 'for the survey site');
    same((string) $GLOBALS['surveyOwnerId'], $claims['sub'], 'sub is the account');
    check(in_array('ADMIN', $claims['roles'], true), 'roles carry ADMIN: ' . json_encode($claims['roles']));
    check(verifyLikeAnApp($browser, $tokens['access_token'], 'genshin') === null, 'and it is no good for another site');
});

test('anybody signed in takes surveys: an account given no role gets in, with the MEMBER every account holds', function () {
    $browser = new Browser();
    $tokens = signInToSurvey($browser, 'respondent@example.test');

    $claims = verifyLikeAnApp($browser, $tokens['access_token'], 'survey');
    check($claims !== null, 'the access token verifies');
    same((string) $GLOBALS['respondentId'], $claims['sub'], 'sub is the account');
    same(['MEMBER'], $claims['roles'], 'MEMBER and nothing more');
});

test('the survey site\'s origin is answered at the token endpoint; a redirect URI off by one character is refused', function () {
    $browser = new Browser();
    same(SURVEY_FRONTEND, $browser->request('OPTIONS', '/api/oidc/token', ['headers' => ['Origin' => SURVEY_FRONTEND]])->header('Access-Control-Allow-Origin'), 'the origin is echoed');

    [, $challenge] = pkce();
    foreach (['http://localhost:4700/auth/callbacks', 'http://localhost:4701/auth/callback', 'http://localhost:4700/auth/callbac'] as $wrong) {
        $start = $browser->get(
            '/api/oidc/authorize?' .
                http_build_query([
                    'response_type' => 'code',
                    'client_id' => 'survey',
                    'redirect_uri' => $wrong,
                    'scope' => 'openid',
                    'state' => 's',
                    'nonce' => 'n',
                    'code_challenge' => $challenge,
                    'code_challenge_method' => 'S256',
                ]),
        );
        check(str_contains($start->location(), '/error?code=invalid_redirect_uri'), "{$wrong} is refused, sent to " . $start->location());
        check(!str_starts_with($start->location(), $wrong), 'and never redirected to');
    }
});

finishTests();
