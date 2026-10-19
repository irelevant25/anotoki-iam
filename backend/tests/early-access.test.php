<?php

/**
 * Early access (migration 023, the owner's decision of 2026-10-19): a fourth
 * role in every site, EARLY_ACCESS ("Uses the site while it lets in only
 * chosen people (a beta, a test); given by an administrator"), which may sign
 * in and which nobody holds until an administrator of the IAM gives it, as
 * ADMIN and EDITOR are given. For a beta the owner unticks the site's MEMBER
 * and gives the testers EARLY_ACCESS: they sign in, the role in their tokens
 * like any role, and an account holding only the MEMBER everybody holds is
 * refused on every way in. The IAM's own entry keeps ADMIN and MEMBER.
 *
 * 023 arrives at installed IAMs, so besides a fresh database it is applied to
 * one built through 022 as its owner left it - three sites letting only ADMIN
 * in (the owner's IAM since 2026-10-06), a role reworded, a MEMBER taken from
 * somebody, applications of the owner's own (one with a role of its own, one
 * switched off, one with an EARLY_ACCESS of its own already, held and
 * unticked) and another system entry -: every site gets an EARLY_ACCESS that
 * nobody holds, no role or grant changes, and who gets in where is what it
 * was. Its gate is shown failing loudly and leaving nothing behind. And a
 * build is uploaded before Apply: an application made in the admin panel
 * meanwhile gets the four roles all the same, and Apply leaves it as it is.
 */

declare(strict_types=1);

require __DIR__ . '/lib.php';

// This run's own database is a fresh IAM, built from every migration, 023 included.
bootTests();

$adminId = makeUser('admin', 'admin@example.test');
grantRole($adminId, 'iam', 'ADMIN');

const EARLY_MIGRATION = '023_early_access_role.sql';
const BEFORE_EARLY = '022_google_button_on_press.sql';
/** EARLY_ACCESS's words, as 023 and its trigger write them (routes/admin.php's SITE_ROLES, the panel's NEW_APPLICATION_ROLES). */
const EARLY_WORDS = 'Uses the site while it lets in only chosen people (a beta, a test); given by an administrator';
const FOUR_ROLES = ['ADMIN', 'EARLY_ACCESS', 'EDITOR', 'MEMBER'];
const SURVEY_FRONTEND = 'http://localhost:4700';
const SURVEY_CALLBACK = 'http://localhost:4700/auth/callback';
const DENIED = 'This account cannot use Surveys';

// ── A database of its own ────────────────────────────────────────────────────

/** An application's roles: name => [description, may_sign_in, how many hold it]. */
function rolesHere(PDO $pdo, string $clientId): array
{
    $statement = $pdo->prepare(
        'SELECT r.name, r.description, r.may_sign_in, (SELECT count(*) FROM user_roles ur WHERE ur.role_id = r.id) AS holders
           FROM application_roles r JOIN applications a ON a.id = r.application_id WHERE a.client_id = ? ORDER BY r.name',
    );
    $statement->execute([$clientId]);
    $roles = [];
    foreach ($statement->fetchAll() as $row) {
        $roles[$row['name']] = [$row['description'], asBool($row['may_sign_in']), (int) $row['holders']];
    }

    return $roles;
}

/** Every role there is, "client_id NAME" => [its id, description, may_sign_in]. */
function everyRole(PDO $pdo): array
{
    $roles = [];
    foreach ($pdo->query('SELECT a.client_id, r.id, r.name, r.description, r.may_sign_in FROM application_roles r JOIN applications a ON a.id = r.application_id')->fetchAll() as $row) {
        $roles["{$row['client_id']} {$row['name']}"] = [(int) $row['id'], $row['description'], asBool($row['may_sign_in'])];
    }
    ksort($roles);

    return $roles;
}

/** Every grant there is, with who gave it and when. */
function everyGrant(PDO $pdo): array
{
    $grants = $pdo
        ->query(
            "SELECT u.username || ' ' || a.client_id || ' ' || r.name || ' by ' || COALESCE(ur.granted_by::text, 'nobody') || ' at ' || ur.granted_at::text
               FROM user_roles ur JOIN users u ON u.id = ur.user_id JOIN application_roles r ON r.id = ur.role_id JOIN applications a ON a.id = r.application_id",
        )
        ->fetchAll(PDO::FETCH_COLUMN);
    sort($grants);

    return $grants;
}

/** Who gets into which site that is switched on, as "username client_id": a role held there that may sign in. */
function whoIsLetIn(PDO $pdo): array
{
    $in = $pdo
        ->query(
            "SELECT u.username || ' ' || a.client_id FROM users u CROSS JOIN applications a
              WHERE a.system = FALSE AND a.enabled AND NOT u.disabled
                AND EXISTS (SELECT 1 FROM user_roles ur JOIN application_roles r ON r.id = ur.role_id
                             WHERE ur.user_id = u.id AND r.application_id = a.id AND r.may_sign_in)",
        )
        ->fetchAll(PDO::FETCH_COLUMN);
    sort($in);

    return $in;
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

/** An application of the owner's own (the roles the database's trigger gives it), with roles of its own beside them: its id. */
function ownersApplication(PDO $pdo, string $clientId, array $roles = [], bool $system = false): int
{
    $insert = $pdo->prepare('INSERT INTO applications (client_id, name, sort_order, system) VALUES (?, ?, 70, ?) RETURNING id');
    $insert->execute([$clientId, ucfirst($clientId), pgBool($system)]);
    $id = (int) $insert->fetchColumn();
    foreach ($roles as $name => $description) {
        $pdo->prepare('INSERT INTO application_roles (application_id, name, description) VALUES (?, ?, ?)')->execute([$id, $name, $description]);
    }

    return $id;
}

function tableThere(PDO $pdo, string $table): bool
{
    $statement = $pdo->prepare('SELECT to_regclass(?) IS NOT NULL');
    $statement->execute([$table]);

    return asBool($statement->fetchColumn());
}

/** Points the app at another of this run's databases (as sign-in-rules.test.php does), forgetting what it remembered of the old one. */
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

// ── Through the app ──────────────────────────────────────────────────────────

function admin(): Browser
{
    $browser = new Browser('10.23.0.1');
    same(200, $browser->signIn('admin@example.test', 'correct horse')->status, 'the admin signs in');

    return $browser;
}

/** Starts a sign-in to the survey site at the authorize endpoint: where the browser was sent, and the verifier. */
function startSignIn(Browser $browser): array
{
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
    );

    return ['location' => $start->location(), 'verifier' => $verifier];
}

function exchangeFor(Browser $browser, string $code, string $verifier): TestResponse
{
    return $browser->request('POST', '/api/oidc/token', [
        'form' => ['grant_type' => 'authorization_code', 'code' => $code, 'redirect_uri' => SURVEY_CALLBACK, 'client_id' => 'survey', 'code_verifier' => $verifier],
        'headers' => ['Origin' => SURVEY_FRONTEND],
    ]);
}

function refreshAt(string $refreshToken): TestResponse
{
    return (new Browser())->request('POST', '/api/oidc/token', [
        'form' => ['grant_type' => 'refresh_token', 'refresh_token' => $refreshToken, 'client_id' => 'survey'],
        'headers' => ['Origin' => SURVEY_FRONTEND],
    ]);
}

/** A whole sign-in to the survey site from a browser that signs in at the IAM first: the token response. */
function intoSurvey(Browser $browser, string $email): array
{
    same(200, $browser->signIn($email, 'correct horse')->status, "{$email} signs in to the IAM");
    $start = startSignIn($browser);
    $code = queryOf($start['location'])['code'] ?? null;
    check($code !== null, "{$email} gets a code straight away: " . $start['location']);
    $tokens = exchangeFor($browser, $code, $start['verifier']);
    same(200, $tokens->status, 'code exchange: ' . $tokens->body);

    return $tokens->json();
}

function surveyId(): int
{
    return (int) iamDb()->query("SELECT id FROM applications WHERE client_id = 'survey'")->fetchColumn();
}

/** Sets which of the survey site's roles may sign in, as the admin panel does: the roles whole, may_sign_in per role. */
function surveyMaySignIn(Browser $admin, array $maySignIn): TestResponse
{
    $roles = array_map(
        static fn(array $role): array => ['name' => $role['name'], 'description' => $role['description'], 'may_sign_in' => $maySignIn[$role['name']] ?? $role['may_sign_in']],
        $admin->get('/api/admin/applications/' . surveyId())->json()['roles'],
    );

    return $admin->put('/api/admin/applications/' . surveyId(), ['roles' => $roles]);
}

/** Sets an account's roles as the admin panel does - the complete set -, made by $change from the set it has. */
function setRolesOf(Browser $admin, int $userId, callable $change): TestResponse
{
    $grants = array_map(static fn(array $grant): array => ['client_id' => $grant['client_id'], 'role' => $grant['role']], $admin->get("/api/admin/users/{$userId}")->json()['roles']);

    return $admin->put("/api/admin/users/{$userId}/roles", ['roles' => array_values($change($grants))]);
}

function lastAuditOf(string $action): ?array
{
    $statement = iamDb()->prepare('SELECT details FROM anotoki_audit_log WHERE action = ? ORDER BY id DESC LIMIT 1');
    $statement->execute([$action]);
    $details = $statement->fetchColumn();

    return $details === false ? null : json_decode((string) $details, true);
}

/** The account page's applications for a signed-in browser: client_id => can_use. */
function listedFor(Browser $browser): array
{
    $listed = [];
    foreach ($browser->get('/api/account/applications')->json()['applications'] ?? [] as $application) {
        $listed[$application['client_id']] = $application['can_use'];
    }

    return $listed;
}

// ── 023 itself ───────────────────────────────────────────────────────────────

test('a fresh IAM has run 023: every site has EARLY_ACCESS - in the family\'s words, allowed to sign in, held by nobody -, the IAM\'s own entry ADMIN and MEMBER alone', function () {
    $pdo = iamDb();
    check(in_array(EARLY_MIGRATION, appliedMigrations($pdo), true), '023 recorded');
    foreach (FIRST_PARTY_SITES as $clientId) {
        $roles = rolesHere($pdo, $clientId);
        same(FOUR_ROLES, array_keys($roles), "{$clientId}: the four roles");
        same([EARLY_WORDS, true, 0], $roles['EARLY_ACCESS'], "{$clientId}: EARLY_ACCESS in the family's words, allowed to sign in, held by nobody");
    }
    same(['ADMIN', 'MEMBER'], array_keys(rolesHere($pdo, 'iam')), 'the IAM\'s own entry: ADMIN and MEMBER, no EARLY_ACCESS');
    same(false, tableThere($pdo, 'iam_023_early_access'), 'the file\'s own list is gone');
    same(0, (int) $pdo->query("SELECT count(*) FROM applications WHERE client_id LIKE 'migration-023-%'")->fetchColumn(), 'and so are its probes');
});

test('an IAM through 022 as its owner left it: 023 gives every site an EARLY_ACCESS nobody holds, changes no role and no grant, and who gets in where is what it was', function () {
    $pdo = buildIamThrough('_installed', BEFORE_EARLY);

    // The installer's administrator, holding ADMIN everywhere, and a few people.
    $owner = accountIn($pdo, 'owner');
    foreach (['iam', ...FIRST_PARTY_SITES] as $clientId) {
        grantIn($pdo, $owner, $clientId, 'ADMIN');
    }
    $editor = accountIn($pdo, 'editor');
    grantIn($pdo, $editor, 'survey', 'EDITOR');
    $player = accountIn($pdo, 'player');
    $blocked = accountIn($pdo, 'blocked');
    $reader = accountIn($pdo, 'reader');
    // What the owner did in the admin panel: three sites let only ADMIN in
    // (2026-10-06), a role reworded before the roles were fixed, a MEMBER taken.
    $pdo->exec("UPDATE application_roles SET may_sign_in = FALSE
                 WHERE name IN ('MEMBER', 'EDITOR') AND application_id IN (SELECT id FROM applications WHERE client_id IN ('japanese-academy', 'piano-academy', 'build-analyzer'))");
    $pdo->exec("UPDATE application_roles SET description = 'Writes the surveys'
                 WHERE name = 'EDITOR' AND application_id = (SELECT id FROM applications WHERE client_id = 'survey')");
    $pdo->prepare(
        "DELETE FROM user_roles WHERE user_id = ? AND role_id = (SELECT r.id FROM application_roles r JOIN applications a ON a.id = r.application_id
                    WHERE a.client_id = 'genshin' AND r.name = 'MEMBER')",
    )->execute([$blocked]);
    // Applications of the owner's own: one with a role of its own, members-only;
    // one switched off; one with an EARLY_ACCESS of its own already, unticked
    // and held; and a system entry besides the IAM's.
    $notes = ownersApplication($pdo, 'notes', ['READER' => 'Reads them']);
    grantIn($pdo, $reader, 'notes', 'READER');
    $pdo->exec("UPDATE application_roles SET may_sign_in = FALSE WHERE name = 'MEMBER' AND application_id = {$notes}");
    $dormant = ownersApplication($pdo, 'dormant');
    $pdo->exec("UPDATE applications SET enabled = FALSE WHERE id = {$dormant}");
    $pilot = ownersApplication($pdo, 'pilot', ['EARLY_ACCESS' => 'Pilots the new thing']);
    $pdo->exec("UPDATE application_roles SET may_sign_in = FALSE WHERE name = 'EARLY_ACCESS' AND application_id = {$pilot}");
    grantIn($pdo, $player, 'pilot', 'EARLY_ACCESS');
    ownersApplication($pdo, 'another-system', [], true);

    $roles = everyRole($pdo);
    $grants = everyGrant($pdo);
    $before = whoIsLetIn($pdo);
    check(in_array('owner piano-academy', $before, true) && !in_array('player piano-academy', $before, true), 'Piano lets only its ADMIN in');

    $result = applyMigrationsThrough($pdo, EARLY_MIGRATION);
    same([[EARLY_MIGRATION], null], [$result['applied'], $result['failed']], '023 applied, its gate passed (' . ($result['error'] ?? '') . ')');

    $after = everyRole($pdo);
    $added = array_diff_key($after, $roles);
    same(
        ['build-analyzer', 'dormant', 'genshin', 'japanese-academy', 'notes', 'piano-academy', 'survey'],
        array_map(static fn(string $role): string => explode(' ', $role)[0], array_keys($added)),
        'an EARLY_ACCESS for every site that had none - the first-party ones, the owner\'s own, the one switched off -, nowhere else',
    );
    foreach ($added as $role => [, $description, $maySignIn]) {
        check(str_ends_with($role, ' EARLY_ACCESS') && [$description, $maySignIn] === [EARLY_WORDS, true], "{$role}: in the family's words, allowed to sign in");
    }
    same($roles, array_intersect_key($after, $roles), 'every role there before as it was - its row, its words, its tick: the unticked MEMBERs and EDITORs, the reworded EDITOR, the owner\'s READER');
    same(['Pilots the new thing', false, 1], rolesHere($pdo, 'pilot')['EARLY_ACCESS'], 'the owner\'s own EARLY_ACCESS as it was: its words, unticked, its holder');
    same($grants, everyGrant($pdo), 'every grant as it was, with who gave it and when: nobody given anything, nobody losing anything');
    same($before, whoIsLetIn($pdo), 'so who gets in where is what it was, account by account');
    same(['ADMIN', 'MEMBER'], array_keys(rolesHere($pdo, 'iam')), 'the IAM\'s own entry: no EARLY_ACCESS');
    same(['MEMBER'], array_keys(rolesHere($pdo, 'another-system')), 'nor another system entry');
    same(false, tableThere($pdo, 'iam_023_early_access'), 'the file\'s own list is gone');
    same(0, (int) $pdo->query("SELECT count(*) FROM applications WHERE client_id LIKE 'migration-023-%'")->fetchColumn(), 'and so are its probes');

    // From now on: a new site gets the four roles, a new system entry MEMBER alone.
    accountIn($pdo, 'late');
    ownersApplication($pdo, 'later');
    $later = rolesHere($pdo, 'later');
    same(FOUR_ROLES, array_keys($later), 'a new site: the four roles');
    same([EARLY_WORDS, true, 0], $later['EARLY_ACCESS'], 'its EARLY_ACCESS in the family\'s words, allowed to sign in, held by nobody');
    same(
        [0, 0, (int) $pdo->query('SELECT count(*) FROM users')->fetchColumn()],
        [$later['ADMIN'][2], $later['EDITOR'][2], $later['MEMBER'][2]],
        'its ADMIN and EDITOR nobody\'s, its MEMBER every account\'s',
    );
    ownersApplication($pdo, 'third-system', [], true);
    same(['MEMBER'], array_keys(rolesHere($pdo, 'third-system')), 'a new system entry: MEMBER alone');
});

test('a 023 whose gate fails - a site that did not get its EARLY_ACCESS - leaves nothing behind, and runs once the cause is gone', function () {
    $pdo = buildIamThrough('_spoilt', BEFORE_EARLY);
    $roles = everyRole($pdo);
    $piano = (int) $pdo->query("SELECT id FROM applications WHERE client_id = 'piano-academy'")->fetchColumn();

    // Something on the host swallows one site's new role on its way in: the gate must see it.
    $pdo->exec('CREATE FUNCTION test_023_swallow() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN RETURN NULL; END $$');
    $pdo->exec("CREATE TRIGGER test_023_swallow BEFORE INSERT ON application_roles FOR EACH ROW
                WHEN (NEW.name = 'EARLY_ACCESS' AND NEW.application_id = {$piano}) EXECUTE FUNCTION test_023_swallow()");

    $result = applyMigrationsThrough($pdo, EARLY_MIGRATION);
    same([[], EARLY_MIGRATION], [$result['applied'], $result['failed']], '023 fails');
    check(str_contains((string) $result['error'], 'every site should have an EARLY_ACCESS role; these have none: piano-academy'), 'its gate names the site: ' . $result['error']);
    same($roles, everyRole($pdo), 'no role is left of it: no site has an EARLY_ACCESS');
    same(false, tableThere($pdo, 'iam_023_early_access'), 'nor its list');
    same(EARLY_MIGRATION, pendingMigrations($pdo)[0] ?? null, 'and it still waits');

    $pdo->exec('DROP TRIGGER test_023_swallow ON application_roles');
    $pdo->exec('DROP FUNCTION test_023_swallow()');
    ownersApplication($pdo, 'meanwhile');
    same(['ADMIN', 'EDITOR', 'MEMBER'], array_keys(rolesHere($pdo, 'meanwhile')), 'a site made meanwhile gets the three roles 016 gave: the trigger as it was');

    $result = applyMigrationsThrough($pdo, EARLY_MIGRATION);
    same([[EARLY_MIGRATION], null], [$result['applied'], $result['failed']], 'the cause gone, the next Apply runs it (' . ($result['error'] ?? '') . ')');
    same([FOUR_ROLES, FOUR_ROLES], [array_keys(rolesHere($pdo, 'piano-academy')), array_keys(rolesHere($pdo, 'meanwhile'))], 'Piano has its four, and so has the site made meanwhile');
});

// ── A beta, through the real endpoints ───────────────────────────────────────

$testerId = makeUser('tester', 'tester@example.test');
$soloId = makeUser('solo', 'solo@example.test');
$memberId = makeUser('member', 'member@example.test');

test('a beta: MEMBER unticked for a site, the people given its EARLY_ACCESS sign in - with MEMBER or without it -, the role in every token; one without it is refused on every way in', function () {
    $admin = admin();

    // What somebody without it holds from before the beta: a session's tokens
    // and a personal token, made while MEMBER let everybody in.
    $memberBrowser = new Browser('10.23.1.1');
    $memberTokens = intoSurvey($memberBrowser, 'member@example.test');
    $personal = $memberBrowser->post('/api/account/tokens', ['name' => 'script', 'client_id' => 'survey', 'expires_in_days' => 30]);
    same(201, $personal->status, 'a personal token, made while MEMBER may sign in: ' . $personal->body);

    $saved = surveyMaySignIn($admin, ['MEMBER' => false]);
    same(200, $saved->status, 'MEMBER unticked in the admin panel: ' . $saved->body);
    same(
        ['sign_in_roles' => ['after' => ['ADMIN', 'EARLY_ACCESS', 'EDITOR'], 'before' => FOUR_ROLES]],
        lastAuditOf('application.sign_in_changed'),
        'audited: EARLY_ACCESS among the roles that still may',
    );

    // Given as ADMIN and EDITOR are given: the person's complete set, the site's EARLY_ACCESS in it.
    $given = setRolesOf($admin, $GLOBALS['testerId'], static fn(array $grants): array => [...$grants, ['client_id' => 'survey', 'role' => 'EARLY_ACCESS']]);
    same(200, $given->status, 'EARLY_ACCESS given to the tester: ' . $given->body);
    $audit = lastAuditOf('user.roles_set');
    $early = ['client_id' => 'survey', 'role' => 'EARLY_ACCESS'];
    // Loosely: the details come back from JSONB, whose keys are in its own order.
    check(in_array($early, $audit['after'] ?? []) && !in_array($early, $audit['before'] ?? []), 'audited, before and after: ' . json_encode($audit));
    // And one whose MEMBER there is taken in the same set: EARLY_ACCESS alone.
    $given = setRolesOf(
        $admin,
        $GLOBALS['soloId'],
        static fn(array $grants): array => [...array_filter($grants, static fn(array $grant): bool => $grant !== ['client_id' => 'survey', 'role' => 'MEMBER']), $early],
    );
    same(200, $given->status, 'EARLY_ACCESS given, MEMBER taken, in one set: ' . $given->body);

    $tester = new Browser('10.23.1.2');
    $tokens = intoSurvey($tester, 'tester@example.test');
    same(['EARLY_ACCESS', 'MEMBER'], verifyLikeAnApp(new Browser(), $tokens['access_token'], 'survey')['roles'] ?? null, 'the tester signs in, the access token carrying EARLY_ACCESS beside MEMBER');
    same(['EARLY_ACCESS', 'MEMBER'], jwtVerifyOwn($tokens['id_token'], 'JWT')['roles'] ?? null, 'and the ID token');
    $info = (new Browser())->get('/api/oidc/userinfo', ['headers' => ['Authorization' => 'Bearer ' . $tokens['access_token']]]);
    same([200, ['EARLY_ACCESS', 'MEMBER']], [$info->status, $info->json()['roles'] ?? null], 'and userinfo');
    $refreshed = refreshAt($tokens['refresh_token']);
    same(200, $refreshed->status, 'its refresh works: ' . $refreshed->body);
    same(['EARLY_ACCESS', 'MEMBER'], verifyLikeAnApp(new Browser(), $refreshed->json()['access_token'], 'survey')['roles'] ?? null, 'the refreshed token carries it too');
    $scripted = $tester->post('/api/account/tokens', ['name' => 'tests', 'client_id' => 'survey', 'expires_in_days' => 30]);
    same(201, $scripted->status, 'a personal token for the site: ' . $scripted->body);
    same(200, refreshAt($scripted->json()['token'])->status, 'which works');
    same(true, listedFor($tester)['survey'] ?? null, 'the account page lists the site, usable');

    $solo = intoSurvey(new Browser('10.23.1.3'), 'solo@example.test');
    same(['EARLY_ACCESS'], verifyLikeAnApp(new Browser(), $solo['access_token'], 'survey')['roles'] ?? null, 'holding only EARLY_ACCESS there, it signs in - the token carrying EARLY_ACCESS alone');

    // Somebody without it, on every way in.
    $start = startSignIn($memberBrowser);
    $request = queryOf($start['location'])['request'] ?? null;
    check($request !== null && !isset(queryOf($start['location'])['code']), 'no silent code for the member - the sign-in page: ' . $start['location']);
    $accept = $memberBrowser->post("/api/login/request/{$request}/accept");
    same([403, 'access_denied', DENIED], [$accept->status, $accept->code(), $accept->json()['error'] ?? null], 'accept refuses');
    $back = queryOf($memberBrowser->post("/api/login/request/{$request}/cancel")->json()['redirect'] ?? '');
    same(['access_denied', DENIED], [$back['error'] ?? null, $back['error_description'] ?? null], 'and going back tells the site why');
    same('invalid_grant', refreshAt($memberTokens['refresh_token'])->json()['error'] ?? null, 'the refresh token from before the beta stops at its next use');
    same('invalid_grant', refreshAt($personal->json()['token'])->json()['error'] ?? null, 'so does the personal token');
    $made = $memberBrowser->post('/api/account/tokens', ['name' => 'another', 'client_id' => 'survey', 'expires_in_days' => 30]);
    same([403, 'access_denied'], [$made->status, $made->code()], 'and no new one is made');
    check(!array_key_exists('survey', listedFor($memberBrowser)), 'the account page leaves the site out: never invited');
});

test(
    'the admin panel counts it: the testers hold a role there and are blocked nowhere, the member was never invited; the role editor, the Members tab, the users filter and an announcement know it',
    function () {
        $admin = admin();
        $users = array_column($admin->get('/api/admin/users')->json(), null, 'username');
        same([[], [], []], [$users['tester']['blocked_in'], $users['solo']['blocked_in'], $users['member']['blocked_in']], 'blocked nowhere: the testers get in, the member was never invited');
        check(in_array(['client_id' => 'survey', 'role' => 'EARLY_ACCESS'], $users['tester']['roles'], true), 'the tester\'s grants say so: ' . json_encode($users['tester']['roles']));

        $filters = array_column($admin->get('/api/admin/users/filters')->json()['applications'], null, 'client_id');
        same(
            [FOUR_ROLES, ['ADMIN', 'EARLY_ACCESS', 'EDITOR']],
            [$filters['survey']['roles'], $filters['survey']['sign_in_roles']],
            'the role editor\'s survey site: the four, MEMBER the one that may not sign in',
        );
        same([['ADMIN', 'MEMBER'], ['ADMIN', 'MEMBER']], [$filters['iam']['roles'], $filters['iam']['sign_in_roles']], 'the IAM\'s own entry: ADMIN and MEMBER');

        $members = $admin->get('/api/admin/applications/' . surveyId() . '/members')->json();
        same(
            [['solo', ['EARLY_ACCESS']], ['tester', ['EARLY_ACCESS', 'MEMBER']]],
            array_map(static fn(array $member): array => [$member['username'], $member['roles']], $members['members']),
            'the Members tab: the testers, with every role they hold there',
        );
        same([], $members['blocked'], 'and nobody blocked');

        $holders = array_column($admin->get('/api/admin/users?application=survey&role=early_access')->json(), 'username');
        sort($holders);
        same(['solo', 'tester'], $holders, 'the users filter by the role (any case): its holders');

        $audience = $admin->post('/api/admin/announcements/audience', ['audience' => ['roles' => [['client_id' => 'survey', 'role' => 'early_access']]]]);
        same([200, 2], [$audience->status, $audience->json()['recipients'] ?? null], 'an announcement to the beta\'s testers reaches the two: ' . $audience->body);
    },
);

test('EARLY_ACCESS taken back, or unticked: the tester is out at the next refresh - and listed as blocked where it holds the role unticked', function () {
    $admin = admin();
    $early = ['client_id' => 'survey', 'role' => 'EARLY_ACCESS'];
    $tester = new Browser('10.23.3.1');
    $tokens = intoSurvey($tester, 'tester@example.test');

    $taken = setRolesOf($admin, $GLOBALS['testerId'], static fn(array $grants): array => array_filter($grants, static fn(array $grant): bool => $grant !== $early));
    same(200, $taken->status, 'EARLY_ACCESS taken from the tester: ' . $taken->body);
    same('invalid_grant', refreshAt($tokens['refresh_token'])->json()['error'] ?? null, 'the next refresh is refused');
    check(isset(queryOf(startSignIn($tester)['location'])['request']), 'and the next sign-in to the site is the sign-in page, no code');
    same([], $admin->get('/api/admin/users/' . $GLOBALS['testerId'])->json()['blocked_in'], 'holding only MEMBER there now, it is not "blocked" - not invited');

    same(200, setRolesOf($admin, $GLOBALS['testerId'], static fn(array $grants): array => [...$grants, $early])->status, 'EARLY_ACCESS given back');
    $tokens = intoSurvey(new Browser('10.23.3.2'), 'tester@example.test');
    $closed = surveyMaySignIn($admin, ['EARLY_ACCESS' => false]);
    same(200, $closed->status, 'EARLY_ACCESS unticked too - ADMIN and EDITOR still may sign in: ' . $closed->body);
    same('invalid_grant', refreshAt($tokens['refresh_token'])->json()['error'] ?? null, 'the tester\'s next refresh is refused');
    $users = array_column($admin->get('/api/admin/users')->json(), null, 'username');
    same(
        [['survey'], ['survey'], []],
        [$users['tester']['blocked_in'], $users['solo']['blocked_in'], $users['member']['blocked_in']],
        'the testers listed as blocked - invited, and turned away -, the member still not',
    );
    same(['solo', 'tester'], array_column($admin->get('/api/admin/applications/' . surveyId() . '/members')->json()['blocked'], 'username'), 'and on the Members tab');

    same(200, surveyMaySignIn($admin, ['MEMBER' => true, 'EARLY_ACCESS' => true])->status, 'the beta over: MEMBER and EARLY_ACCESS ticked again');
    intoSurvey(new Browser('10.23.3.3'), 'member@example.test');
});

test('EARLY_ACCESS is given and taken like ADMIN and EDITOR - to an account made with it too -, is never removed from a site, and the IAM\'s own entry has none', function () {
    $admin = admin();
    $made = $admin->post('/api/admin/users', [
        'username' => 'invited',
        'email' => 'invited@example.test',
        'password' => 'correct horse',
        'roles' => [['client_id' => 'genshin', 'role' => 'early_access']],
    ]);
    same(201, $made->status, 'an account made with a site\'s EARLY_ACCESS (any case): ' . $made->body);
    $id = (int) $made->json()['id'];
    $early = ['client_id' => 'genshin', 'role' => 'EARLY_ACCESS'];
    check(
        in_array($early, $made->json()['roles'], true) && in_array(['client_id' => 'genshin', 'role' => 'MEMBER'], $made->json()['roles'], true),
        'holding it, beside the MEMBER everybody holds: ' . json_encode($made->json()['roles']),
    );
    $taken = setRolesOf($admin, $id, static fn(array $grants): array => array_filter($grants, static fn(array $grant): bool => $grant !== $early));
    same(200, $taken->status, 'and taken, as any role is: ' . $taken->body);
    check(!in_array($early, $taken->json()['account']['roles'], true), 'gone from its roles');

    $refused = setRolesOf($admin, $id, static fn(array $grants): array => [...$grants, ['client_id' => 'iam', 'role' => 'EARLY_ACCESS']]);
    same([422, 'invalid_role', 'There is no role EARLY_ACCESS in iam'], [$refused->status, $refused->code(), $refused->json()['error'] ?? null], 'the IAM\'s own entry has no EARLY_ACCESS to give');
    $refused = $admin->post('/api/admin/users', [
        'username' => 'nobody',
        'email' => 'nobody@example.test',
        'password' => 'correct horse',
        'roles' => [['client_id' => 'iam', 'role' => 'EARLY_ACCESS']],
    ]);
    same([422, 'invalid_role'], [$refused->status, $refused->code()], 'not at an account\'s making either');
    $refused = $admin->post('/api/admin/announcements/audience', ['audience' => ['roles' => [['client_id' => 'iam', 'role' => 'EARLY_ACCESS']]]]);
    same([422, 'invalid_role'], [$refused->status, $refused->code()], 'nor an audience');

    // The roles are fixed: EARLY_ACCESS never removed from a site nor reworded, never added to the IAM's entry.
    $roles = array_map(static fn(array $role): array => ['name' => $role['name'], 'description' => $role['description']], $admin->get('/api/admin/applications/' . surveyId())->json()['roles']);
    $refused = $admin->put('/api/admin/applications/' . surveyId(), ['roles' => array_values(array_filter($roles, static fn(array $role): bool => $role['name'] !== 'EARLY_ACCESS'))]);
    same(
        [422, 'The roles are fixed - EARLY_ACCESS cannot be removed; only whether each may sign in can change'],
        [$refused->status, $refused->json()['error'] ?? null],
        'EARLY_ACCESS cannot be removed from a site',
    );
    $reworded = array_map(static fn(array $role): array => $role['name'] === 'EARLY_ACCESS' ? ['name' => 'EARLY_ACCESS', 'description' => 'Testers'] : $role, $roles);
    $refused = $admin->put('/api/admin/applications/' . surveyId(), ['roles' => $reworded]);
    same([422, 'The roles are fixed - EARLY_ACCESS keeps its description; only whether each may sign in can change'], [$refused->status, $refused->json()['error'] ?? null], 'nor reworded');
    same(EARLY_WORDS, array_column($admin->get('/api/admin/applications/' . surveyId())->json()['roles'], 'description', 'name')['EARLY_ACCESS'] ?? null, 'it is as it was');

    $iamId = (int) iamDb()->query("SELECT id FROM applications WHERE client_id = 'iam'")->fetchColumn();
    $refused = $admin->put("/api/admin/applications/{$iamId}", ['roles' => [['name' => 'ADMIN'], ['name' => 'EARLY_ACCESS', 'may_sign_in' => true], ['name' => 'MEMBER']]]);
    same(
        [422, "The roles are fixed - 'EARLY_ACCESS' is not one of this application's (ADMIN, MEMBER); only whether each may sign in can change"],
        [$refused->status, $refused->json()['error'] ?? null],
        'the IAM\'s own entry is given no EARLY_ACCESS',
    );
    same(['ADMIN', 'MEMBER'], array_column($admin->get("/api/admin/applications/{$iamId}")->json()['roles'], 'name'), 'it has ADMIN and MEMBER');
});

test('a new application in the admin panel gets the four roles by itself: EARLY_ACCESS in the family\'s words, ticked, held by nobody; MEMBER every account\'s', function () {
    $admin = admin();
    $everybody = (int) iamDb()->query('SELECT count(*) FROM users')->fetchColumn();
    $created = $admin->post('/api/admin/applications', ['client_id' => 'new-project', 'name' => 'New project', 'roles' => [['name' => 'MEMBER', 'may_sign_in' => false]]]);
    same(201, $created->status, 'made as a beta, MEMBER unticked: ' . $created->body);
    same(
        [
            ['name' => 'ADMIN', 'description' => "Everything in the site's admin panel", 'may_sign_in' => true, 'members' => 0],
            ['name' => 'EARLY_ACCESS', 'description' => EARLY_WORDS, 'may_sign_in' => true, 'members' => 0],
            ['name' => 'EDITOR', 'description' => "Edits the site's content in its admin panel", 'may_sign_in' => true, 'members' => 0],
            ['name' => 'MEMBER', 'description' => 'Uses the site', 'may_sign_in' => false, 'members' => $everybody],
        ],
        $created->json()['application']['roles'],
        'the four, in the family\'s words, MEMBER every account\'s and unticked as asked',
    );
    $started = lastAuditOf('application.created');
    ksort($started);
    same(['login_required' => true, 'sign_in_roles' => ['ADMIN', 'EARLY_ACCESS', 'EDITOR']], $started, 'the audit says who may sign in from the start');
    same(200, $admin->delete('/api/admin/applications/' . $created->json()['application']['id'])->status, 'and deleted again');
});

// ── With 023 pending: a build uploaded before Apply ──────────────────────────

test('with 023 pending, an application made in the admin panel gets the four roles all the same; Apply gives every other site its EARLY_ACCESS and leaves that one as it is', function () {
    $main = testEnvironment()['db'];
    $window = throwawayDatabase($main . '_window');
    buildIamThrough('_window', BEFORE_EARLY);
    useIamDatabase($window);

    try {
        $pdo = iamDb();
        same($window, $pdo->query('SELECT current_database()')->fetchColumn(), 'the app runs on the database without 023');
        same(EARLY_MIGRATION, pendingMigrations($pdo)[0] ?? null, '023 waits');
        $adminId = makeUser('admin', 'admin@example.test');
        grantRole($adminId, 'iam', 'ADMIN');
        $admin = admin();

        $created = $admin->post('/api/admin/applications', ['client_id' => 'early-bird', 'name' => 'Early bird']);
        same(201, $created->status, 'made before Apply: ' . $created->body);
        same(FOUR_ROLES, array_column($created->json()['application']['roles'], 'name'), 'with the four roles: EARLY_ACCESS from the build, beside the three the database\'s trigger still makes');
        same([EARLY_WORDS, true, 0], rolesHere($pdo, 'early-bird')['EARLY_ACCESS'] ?? null, 'in the family\'s words, ticked, held by nobody');
        $earlyBird = everyRole($pdo)['early-bird EARLY_ACCESS'];
        same(['ADMIN', 'EDITOR', 'MEMBER'], array_keys(rolesHere($pdo, 'survey')), 'the other sites wait for Apply');

        $applied = $admin->post('/api/admin/migrations/apply');
        same([200, EARLY_MIGRATION, null], [$applied->status, migrationNames($applied->json()['applied'] ?? [])[0] ?? null, $applied->json()['failed'] ?? null], 'Apply pending: ' . $applied->body);
        foreach (FIRST_PARTY_SITES as $clientId) {
            same([EARLY_WORDS, true, 0], rolesHere($pdo, $clientId)['EARLY_ACCESS'] ?? null, "{$clientId}: its EARLY_ACCESS now");
        }
        same($earlyBird, everyRole($pdo)['early-bird EARLY_ACCESS'] ?? null, 'the one made before Apply as it was, the same row');
        same(['ADMIN', 'MEMBER'], array_keys(rolesHere($pdo, 'iam')), 'the IAM\'s own entry: none');
    } finally {
        useIamDatabase($main);
    }

    same($main, iamDb()->query('SELECT current_database()')->fetchColumn(), 'back on this run\'s own database');
});

finishTests();
