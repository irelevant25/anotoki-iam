<?php

/**
 * Sign-in per site (migration 010, the owner's decisions of 2026-10-05):
 * MEMBER in every application and every account holding it, each role saying
 * whether it may sign in, each site saying whether it requires signing in -
 * and the rule every way in now keeps: a sign-in to a site needs a role there
 * that may sign in.
 *
 * 010 arrives at installed IAMs too, so besides a fresh database it is applied
 * to one built through 009 the way an owner leaves it - members-only
 * applications of the owner's own among it, one with a MEMBER somebody held -
 * and nobody gets in after it who did not before, nor is anybody kept out who
 * got in. And a build is uploaded before its migrations are applied: with 010
 * pending, signing in works by the rule before it and the admin panel's
 * application pages say what to do, until Apply.
 *
 * And 011, the owner's later request of the same day: public clients only
 * (no secrets, PKCE for everyone), the same fixed roles in every application
 * (ADMIN, EDITOR, MEMBER; the panel says only which may sign in), no landing
 * page settings - with the admin panel's lists of who is blocked where.
 */

declare(strict_types=1);

require __DIR__ . '/lib.php';

// This run's own database is a fresh IAM, built from every migration, 010 included.
bootTests();

$adminId = makeUser('admin', 'admin@example.test');
grantRole($adminId, 'iam', 'ADMIN');

const RULES_MIGRATION = '010_sign_in_per_site.sql';
const STATIC_MIGRATION = '011_public_clients_static_roles.sql';
const SITE_ROLE_WORDS = ['ADMIN' => "Everything in the site's admin panel", 'EDITOR' => "Edits the site's content in its admin panel", 'MEMBER' => 'Uses the site'];
const SURVEY_FRONTEND = 'http://localhost:4700';
const SURVEY_CALLBACK = 'http://localhost:4700/auth/callback';
const PIANO_CALLBACK = 'http://localhost:4500/auth/callback';
const DENIED = 'This account cannot use Surveys';

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
         SELECT ?, r.id FROM application_roles r JOIN applications a ON a.id = r.application_id WHERE a.client_id = ? AND r.name = ?'
    )->execute([$userId, $clientId, $role]);
}

/** An application of the owner's own, made in the admin panel before 010: its roles name => description. */
function ownersApplication(PDO $pdo, string $clientId, string $access, array $roles): void
{
    $id = (int) $pdo->query("INSERT INTO applications (client_id, name, type, access, sort_order, system)
                              VALUES ('{$clientId}', '" . ucfirst($clientId) . "', 'public', '{$access}', 70, FALSE) RETURNING id")->fetchColumn();
    foreach ($roles as $name => $description) {
        $pdo->prepare('INSERT INTO application_roles (application_id, name, description) VALUES (?, ?, ?)')->execute([$id, $name, $description]);
    }
}

/** An application's roles: name => [description, may_sign_in]. */
function rolesWith(PDO $pdo, string $clientId): array
{
    $statement = $pdo->prepare('SELECT r.name, r.description, r.may_sign_in FROM application_roles r JOIN applications a ON a.id = r.application_id WHERE a.client_id = ? ORDER BY r.name');
    $statement->execute([$clientId]);
    $roles = [];
    foreach ($statement->fetchAll() as $row) {
        $roles[$row['name']] = [$row['description'], asBool($row['may_sign_in'])];
    }

    return $roles;
}

/** Who holds a role in an application, by username. */
function holders(PDO $pdo, string $clientId, string $role): array
{
    $statement = $pdo->prepare('SELECT u.username FROM user_roles ur JOIN users u ON u.id = ur.user_id JOIN application_roles r ON r.id = ur.role_id
                                  JOIN applications a ON a.id = r.application_id WHERE a.client_id = ? AND r.name = ? ORDER BY u.username');
    $statement->execute([$clientId, $role]);

    return $statement->fetchAll(PDO::FETCH_COLUMN);
}

/** The accounts that do not hold MEMBER in some application, as "username client_id". */
function withoutMember(PDO $pdo): array
{
    return $pdo->query("SELECT u.username || ' ' || a.client_id FROM users u CROSS JOIN applications a
                         WHERE NOT EXISTS (SELECT 1 FROM user_roles ur JOIN application_roles r ON r.id = ur.role_id
                                            WHERE ur.user_id = u.id AND r.application_id = a.id AND r.name = 'MEMBER')
                         ORDER BY 1")->fetchAll(PDO::FETCH_COLUMN);
}

/**
 * Who gets into which site, as "username client_id": by the rule before 010
 * (access everyone, or members and a role held) or by its own (a role held
 * that may sign in) - a site switched off lets nobody in, either way.
 */
function whoGetsIn(PDO $pdo, bool $byTheNewRule): array
{
    $rule = $byTheNewRule
        ? 'EXISTS (SELECT 1 FROM user_roles ur JOIN application_roles r ON r.id = ur.role_id WHERE ur.user_id = u.id AND r.application_id = a.id AND r.may_sign_in)'
        : "(a.access = 'everyone' OR EXISTS (SELECT 1 FROM user_roles ur JOIN application_roles r ON r.id = ur.role_id WHERE ur.user_id = u.id AND r.application_id = a.id))";

    return $pdo->query("SELECT u.username || ' ' || a.client_id FROM users u CROSS JOIN applications a
                         WHERE a.system = FALSE AND a.enabled AND NOT u.disabled AND {$rule} ORDER BY 1")->fetchAll(PDO::FETCH_COLUMN);
}

function loginRequiredIn(PDO $pdo): array
{
    return array_map('asBool', $pdo->query('SELECT client_id, login_required FROM applications ORDER BY client_id')->fetchAll(PDO::FETCH_KEY_PAIR));
}

/** Points the app at another of this run's databases (as translations.test.php does), forgetting what it remembered of the old one. */
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

/** Starts a sign-in to a site at the authorize endpoint: where the browser was sent, and the verifier. */
function startSignIn(Browser $browser, string $clientId = 'survey', string $callback = SURVEY_CALLBACK): array
{
    [$verifier, $challenge] = pkce();
    $start = $browser->get('/api/oidc/authorize?' . http_build_query([
        'response_type' => 'code', 'client_id' => $clientId, 'redirect_uri' => $callback, 'scope' => 'openid profile email',
        'state' => 's', 'nonce' => 'n', 'code_challenge' => $challenge, 'code_challenge_method' => 'S256',
    ]));

    return ['location' => $start->location(), 'verifier' => $verifier];
}

function exchangeFor(Browser $browser, string $code, string $verifier): TestResponse
{
    return $browser->request('POST', '/api/oidc/token', [
        'form' => ['grant_type' => 'authorization_code', 'code' => $code, 'redirect_uri' => SURVEY_CALLBACK, 'client_id' => 'survey', 'code_verifier' => $verifier],
        'headers' => ['Origin' => SURVEY_FRONTEND],
    ]);
}

function refreshAt(string $refreshToken, string $clientId = 'survey'): TestResponse
{
    return (new Browser())->request('POST', '/api/oidc/token', [
        'form' => ['grant_type' => 'refresh_token', 'refresh_token' => $refreshToken, 'client_id' => $clientId],
        'headers' => ['Origin' => SURVEY_FRONTEND],
    ]);
}

/** A signed-in browser's whole sign-in to the survey site: the token response. */
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

function admin(): Browser
{
    $browser = new Browser('10.5.0.1');
    same(200, $browser->signIn('admin@example.test', 'correct horse')->status, 'the admin signs in');

    return $browser;
}

/** The survey site in the admin panel's shape, and its roles as a body sends them back. */
function surveyInPanel(Browser $admin): array
{
    $id = (int) iamDb()->query("SELECT id FROM applications WHERE client_id = 'survey'")->fetchColumn();
    $application = $admin->get("/api/admin/applications/{$id}")->json();

    return [$id, $application];
}

/** Sets which of the survey site's roles may sign in, as the admin panel does: the roles whole, may_sign_in per role. */
function surveyMaySignIn(Browser $admin, array $maySignIn): TestResponse
{
    [$id, $application] = surveyInPanel($admin);
    $roles = array_map(static fn(array $role): array => [
        'name' => $role['name'], 'description' => $role['description'], 'may_sign_in' => $maySignIn[$role['name']] ?? $role['may_sign_in'],
    ], $application['roles']);

    return $admin->put("/api/admin/applications/{$id}", ['roles' => $roles]);
}

function lastAuditOf(string $action): ?array
{
    $statement = iamDb()->prepare('SELECT details FROM audit_log WHERE action = ? ORDER BY id DESC LIMIT 1');
    $statement->execute([$action]);
    $details = $statement->fetchColumn();

    return $details === false ? null : json_decode((string) $details, true);
}

function listedFor(Browser $browser): array
{
    $listed = [];
    foreach ($browser->get('/api/account/applications')->json()['applications'] ?? [] as $application) {
        $listed[$application['client_id']] = $application['can_use'];
    }
    ksort($listed);

    return $listed;
}

// ── 010 itself ───────────────────────────────────────────────────────────────

test('a fresh IAM: MEMBER everywhere, every role may sign in, genshin and the analyzer welcome visitors, the triggers there', function () {
    $pdo = iamDb();
    same([], pendingMigrations($pdo, 'iam'), 'nothing pending');
    check(in_array(RULES_MIGRATION, appliedMigrations($pdo), true), '010 recorded');

    foreach ($pdo->query('SELECT client_id FROM applications ORDER BY client_id')->fetchAll(PDO::FETCH_COLUMN) as $clientId) {
        same(['Uses the site', true], rolesWith($pdo, $clientId)['MEMBER'] ?? null, "{$clientId} has MEMBER, and it may sign in");
    }
    same(0, (int) $pdo->query('SELECT count(*) FROM application_roles WHERE NOT may_sign_in')->fetchColumn(), 'every role may sign in');
    same(['build-analyzer' => false, 'genshin' => false, 'iam' => true, 'japanese-academy' => true, 'piano-academy' => true, 'survey' => true],
        loginRequiredIn($pdo), 'visitors welcome on genshin and the build analyzer; the other sites require signing in');
    same(true, signInRulesReady($pdo), 'the rules are ready');
    same(1, (int) $pdo->query("SELECT count(*) FROM information_schema.columns WHERE table_schema = current_schema() AND table_name = 'applications' AND column_name = 'access'")->fetchColumn(),
        'the access column stays, for the build before 010');
    same(2, (int) $pdo->query("SELECT count(*) FROM pg_trigger WHERE tgname IN ('trg_users_member_everywhere', 'trg_applications_member_role')")->fetchColumn(), 'both triggers');

    foreach ($pdo->query('SELECT client_id, system FROM applications ORDER BY client_id')->fetchAll() as $application) {
        same(asBool($application['system']) ? ['ADMIN', 'MEMBER'] : ['ADMIN', 'EDITOR', 'MEMBER'], array_keys(rolesWith($pdo, $application['client_id'])),
            "{$application['client_id']}: the fixed roles (011)");
    }
    same(0, (int) $pdo->query("SELECT count(*) FROM applications WHERE type <> 'public' OR secret_hash IS NOT NULL")->fetchColumn(), 'every client public, no secret');

    makeUser('fresh', 'fresh@example.test');
    same([], withoutMember($pdo), 'an account made now holds MEMBER in every application');
});

test('an IAM through 009, as its owner left it: 010 lets in exactly who got in before, MEMBER held by everyone', function () {
    $pdo = buildIamThrough('_owner', '009_admin_panel.sql');

    $admin = accountIn($pdo, 'admin');
    $editor = accountIn($pdo, 'editor');
    accountIn($pdo, 'nobody');
    $reader = accountIn($pdo, 'reader');
    $guest = accountIn($pdo, 'guest');
    $guest2 = accountIn($pdo, 'guest2');
    $invitee = accountIn($pdo, 'invitee');
    $pianist = accountIn($pdo, 'pianist');
    grantIn($pdo, $admin, 'iam', 'ADMIN');
    grantIn($pdo, $editor, 'survey', 'EDITOR');

    // What the admin panel may have done since 007.
    $pdo->exec("UPDATE application_roles SET description = 'Writes the surveys'
                 WHERE name = 'EDITOR' AND application_id = (SELECT id FROM applications WHERE client_id = 'survey')");
    $pdo->exec("DELETE FROM applications WHERE client_id = 'japanese-academy'");
    $pdo->exec("UPDATE applications SET access = 'members' WHERE client_id = 'piano-academy'");
    grantIn($pdo, $pianist, 'piano-academy', 'ADMIN');
    ownersApplication($pdo, 'notes', 'members', ['ADMIN' => 'Runs the notes', 'READER' => 'Reads them']);
    grantIn($pdo, $reader, 'notes', 'READER');
    ownersApplication($pdo, 'open', 'everyone', ['MEMBER' => 'Readers']);
    grantIn($pdo, $reader, 'open', 'MEMBER');
    ownersApplication($pdo, 'closed', 'members', []);
    ownersApplication($pdo, 'invited', 'members', ['ADMIN' => 'Runs it', 'MEMBER' => 'Invited people']);
    grantIn($pdo, $guest, 'invited', 'MEMBER');
    ownersApplication($pdo, 'taken', 'members', ['INVITED' => 'Asked in', 'MEMBER' => 'May use it']);
    grantIn($pdo, $guest2, 'taken', 'MEMBER');
    grantIn($pdo, $invitee, 'taken', 'INVITED');
    ownersApplication($pdo, 'quiet', 'members', ['EDITOR' => 'Edits', 'MEMBER' => 'Nobody asked yet']);
    grantIn($pdo, $editor, 'quiet', 'EDITOR');

    $before = whoGetsIn($pdo, false);
    check(in_array('guest invited', $before, true) && in_array('guest2 taken', $before, true), 'a MEMBER held in a members-only application was a way in');
    check(!in_array('nobody notes', $before, true) && !in_array('pianist closed', $before, true), 'and nobody without a role got in there');

    $result = applyMigrationsThrough($pdo, RULES_MIGRATION);
    same([[RULES_MIGRATION], null], [$result['applied'], $result['failed']], '010 applied, its gate passed (' . ($result['error'] ?? '') . ')');

    same($before, whoGetsIn($pdo, true), 'who gets in where is what it was, to the account');
    same([], withoutMember($pdo), 'every account holds MEMBER in every application there is');

    same(['ADMIN' => ['Runs it', true], 'INVITED' => ['Was MEMBER before 2026-10-05', true], 'MEMBER' => ['Uses the site', false]], rolesWith($pdo, 'invited'),
        'a MEMBER somebody held in a members-only application is INVITED now, letting them in; the new MEMBER does not');
    same(['guest'], holders($pdo, 'invited', 'INVITED'), 'held by whoever held it');
    same(['INVITED' => ['Asked in', true], 'INVITED_2' => ['Was MEMBER before 2026-10-05', true], 'MEMBER' => ['Uses the site', false]], rolesWith($pdo, 'taken'),
        'with INVITED taken, it is INVITED_2');
    same([['invitee'], ['guest2']], [holders($pdo, 'taken', 'INVITED'), holders($pdo, 'taken', 'INVITED_2')], 'and each keeps its own holders');
    same(['EDITOR' => ['Edits', true], 'MEMBER' => ['Nobody asked yet', false]], rolesWith($pdo, 'quiet'), 'a MEMBER nobody held keeps its words, and may not sign in');
    same(['ADMIN' => ['Runs the notes', true], 'MEMBER' => ['Uses the site', false], 'READER' => ['Reads them', true]], rolesWith($pdo, 'notes'), 'a members-only application of the owner\'s');
    same(['MEMBER' => ['Readers', true]], rolesWith($pdo, 'open'), 'an everyone one: its MEMBER keeps its words and lets everybody in');
    same(['ADMIN', 'EDITOR', 'MEMBER'], array_keys(array_filter(rolesWith($pdo, 'survey'), static fn(array $role): bool => $role[1])), 'every survey role may sign in');
    same('Writes the surveys', rolesWith($pdo, 'survey')['EDITOR'][0], 'a role reworded in the admin panel keeps its words');
    same(false, rolesWith($pdo, 'piano-academy')['MEMBER'][1], 'a first-party site set to members by hand stays so');

    same(['MEMBER' => ['Uses the site', false]], rolesWith($pdo, 'closed'), 'a members-only application with no role at all');
    same(false, asBool($pdo->query("SELECT enabled FROM applications WHERE client_id = 'closed'")->fetchColumn()), 'let nobody in, and is switched off now');
    same(0, (int) $pdo->query("SELECT count(*) FROM applications WHERE NOT enabled AND client_id <> 'closed'")->fetchColumn(), 'and nothing else is');
    same(0, (int) $pdo->query("SELECT count(*) FROM applications WHERE client_id = 'japanese-academy'")->fetchColumn(), 'a site deleted by hand stays deleted');
    same(['build-analyzer' => false, 'closed' => true, 'genshin' => false, 'iam' => true, 'invited' => true, 'notes' => true, 'open' => true,
          'piano-academy' => true, 'quiet' => true, 'survey' => true, 'taken' => true], loginRequiredIn($pdo), 'who requires signing in');

    // The invariant from now on, on this IAM too.
    $late = accountIn($pdo, 'late');
    ownersApplication($pdo, 'later', 'everyone', []);
    same([], withoutMember($pdo), 'an account and an application made after 010 meet: MEMBER for everyone everywhere');
    same(['MEMBER' => ['Uses the site', true]], rolesWith($pdo, 'later'), 'the new application\'s MEMBER may sign in');
    check(in_array('late later', whoGetsIn($pdo, true), true), 'so the new account gets into the new application');
});

test('an IAM with 009, 010 and 011 pending: Apply runs them all, 009\'s words as it leaves them', function () {
    $pdo = buildIamThrough('_with009', '008_top_bar.sql');
    accountIn($pdo, 'someone');

    $result = applyPendingMigrations($pdo, 'iam');
    same([['009_admin_panel.sql', RULES_MIGRATION, STATIC_MIGRATION], null], [$result['applied'], $result['failed']], 'all three, in order (' . ($result['error'] ?? '') . ')');
    same('Admin panel', $pdo->query("SELECT value FROM translations WHERE key_name = 'shell.admin' AND language_code = 'en'")->fetchColumn(), '009\'s English');
    same([], withoutMember($pdo), 'and 010\'s MEMBER for everyone');
});

test('an IAM through 010, as its owner left it: 011 makes every client public and gives every site ADMIN, EDITOR and MEMBER - to nobody', function () {
    $pdo = buildIamThrough('_static', RULES_MIGRATION);

    $admin = accountIn($pdo, 'admin');
    $editor = accountIn($pdo, 'editor');
    $reader = accountIn($pdo, 'reader');
    grantIn($pdo, $admin, 'iam', 'ADMIN');
    grantIn($pdo, $editor, 'survey', 'EDITOR');
    $pdo->exec("UPDATE application_roles SET description = 'Writes the surveys'
                 WHERE name = 'EDITOR' AND application_id = (SELECT id FROM applications WHERE client_id = 'survey')");
    $pdo->exec("DELETE FROM applications WHERE client_id = 'japanese-academy'");
    $pdo->exec("UPDATE applications SET type = 'confidential', secret_hash = 'a hash of a secret' WHERE client_id = 'piano-academy'");
    // Applications of the owner's own, made in the panel after 010 (MEMBER from its trigger).
    ownersApplication($pdo, 'notes', 'everyone', ['ADMIN' => 'Runs the notes', 'READER' => 'Reads them']);
    grantIn($pdo, $reader, 'notes', 'READER');
    ownersApplication($pdo, 'bare', 'everyone', []);
    $pdo->exec("UPDATE application_roles SET may_sign_in = FALSE WHERE name = 'MEMBER' AND application_id = (SELECT id FROM applications WHERE client_id = 'notes')");
    $grants = (int) $pdo->query('SELECT count(*) FROM user_roles')->fetchColumn();
    $before = whoGetsIn($pdo, true);

    $result = applyMigrationsThrough($pdo, STATIC_MIGRATION);
    same([[STATIC_MIGRATION], null], [$result['applied'], $result['failed']], '011 applied, its gate passed (' . ($result['error'] ?? '') . ')');

    same(0, (int) $pdo->query("SELECT count(*) FROM applications WHERE type <> 'public' OR secret_hash IS NOT NULL")->fetchColumn(), 'every client public, its secret gone');
    same(['ADMIN' => ['Runs the notes', true], 'EDITOR' => [SITE_ROLE_WORDS['EDITOR'], true], 'MEMBER' => ['Uses the site', false], 'READER' => ['Reads them', true]],
        rolesWith($pdo, 'notes'), 'an owner\'s application: EDITOR added in the family\'s words, its ADMIN\'s words and its own READER kept, MEMBER still unticked');
    same(['ADMIN' => [SITE_ROLE_WORDS['ADMIN'], true], 'EDITOR' => [SITE_ROLE_WORDS['EDITOR'], true], 'MEMBER' => ['Uses the site', true]], rolesWith($pdo, 'bare'), 'one with MEMBER alone gets ADMIN and EDITOR');
    same('Writes the surveys', rolesWith($pdo, 'survey')['EDITOR'][0], 'a role reworded in the panel keeps its words');
    same(['ADMIN', 'MEMBER'], array_keys(rolesWith($pdo, 'iam')), 'the IAM: ADMIN and MEMBER, no EDITOR');
    same(0, (int) $pdo->query("SELECT count(*) FROM applications WHERE client_id = 'japanese-academy'")->fetchColumn(), 'a site deleted by hand stays deleted');
    same($grants, (int) $pdo->query('SELECT count(*) FROM user_roles')->fetchColumn(), 'nobody was given anything');
    same($before, whoGetsIn($pdo, true), 'so who gets in where is what it was');
    same(0, (int) $pdo->query("SELECT count(*) FROM applications WHERE client_id = 'migration-011-probe'")->fetchColumn(), 'the gate\'s probe left nothing behind');

    // From now on a new application gets all three; a system one MEMBER alone.
    accountIn($pdo, 'late');
    ownersApplication($pdo, 'later', 'everyone', []);
    same(['ADMIN' => [SITE_ROLE_WORDS['ADMIN'], true], 'EDITOR' => [SITE_ROLE_WORDS['EDITOR'], true], 'MEMBER' => ['Uses the site', true]], rolesWith($pdo, 'later'), 'a new site: the three, in the family\'s words');
    same([], withoutMember($pdo), 'MEMBER for every account, the new one too');
    same([], holders($pdo, 'later', 'ADMIN'), 'and its ADMIN for nobody');
    $pdo->exec("INSERT INTO applications (client_id, name, system) VALUES ('another-system', 'Another', TRUE)");
    same(['MEMBER'], array_keys(rolesWith($pdo, 'another-system')), 'a system entry: MEMBER alone');
});

test('every way an account is made gives it MEMBER everywhere: the installer\'s administrator, the legacy import, registration, the admin panel', function () {
    $first = buildIamThrough('_first', RULES_MIGRATION);
    check(createFirstAdmin($first, 'first@example.test', 'a long enough one') !== null, 'the first administrator');
    same([], withoutMember($first), 'holds MEMBER everywhere');

    $imported = buildIamThrough('_imported', RULES_MIGRATION);
    $legacy = makeLegacyDatabase('_legacy_rules');
    $legacy->prepare("INSERT INTO users (id, role, username, email, email_confirmed, password) VALUES
        (3, 'ADMIN', 'old-owner', 'old-owner@example.test', TRUE, ?), (8, 'USER', 'old-player', 'old-player@example.test', TRUE, ?)")
        ->execute([password_hash('a long password', PASSWORD_DEFAULT), password_hash('a long password', PASSWORD_DEFAULT)]);
    same(2, importLegacyAccounts($imported, $legacy)['accounts'], 'two accounts imported');
    same([], withoutMember($imported), 'each holds MEMBER everywhere, beside what the import mapped');

    same(201, (new Browser('10.5.1.1'))->post('/api/register', ['username' => 'newcomer', 'email' => 'newcomer@example.test', 'password' => 'a long password'])->status, 'registered');

    $made = admin()->post('/api/admin/users', ['username' => 'made', 'email' => 'made@example.test', 'password' => 'correct horse',
        'roles' => [['client_id' => 'survey', 'role' => 'MEMBER'], ['client_id' => 'survey', 'role' => 'EDITOR']]]);
    same(201, $made->status, 'the admin panel makes an account, the body naming the MEMBER it gets anyway: ' . $made->body);
    same([], withoutMember(iamDb()), 'every account here holds MEMBER everywhere');
    same(['made'], holders(iamDb(), 'survey', 'EDITOR'), 'and the role given beside it');
});

test('a new application in the admin panel: ADMIN, EDITOR and MEMBER by themselves, MEMBER every account\'s, last in the list, a public client', function () {
    $admin = admin();
    $everybody = (int) iamDb()->query('SELECT count(*) FROM users')->fetchColumn();

    foreach ([
        'a role of its own' => [['name' => 'GUEST', 'may_sign_in' => true]],
        'its own words for a role' => [['name' => 'ADMIN', 'description' => 'Runs it']],
    ] as $what => $roles) {
        $refused = $admin->post('/api/admin/applications', ['client_id' => 'refused', 'name' => 'Refused', 'roles' => $roles]);
        check($refused->status === 422 && str_starts_with($refused->json()['error'] ?? '', 'The roles are fixed - '), "{$what} is refused: " . $refused->body);
    }
    $refused = $admin->post('/api/admin/applications', ['client_id' => 'refused', 'name' => 'Refused', 'type' => 'confidential']);
    same([422, 'Every application is a public client: it names itself and proves each sign-in with PKCE - there are no client secrets'],
        [$refused->status, $refused->json()['error'] ?? null], 'a confidential client is refused');
    same(0, (int) iamDb()->query("SELECT count(*) FROM applications WHERE client_id = 'refused'")->fetchColumn(), 'and nothing half-made is left');

    $created = $admin->post('/api/admin/applications', ['client_id' => 'newsite', 'name' => 'New site', 'type' => 'public', 'show_on_landing' => false, 'sort_order' => -5,
        'roles' => [['name' => 'MEMBER', 'may_sign_in' => false], ['name' => 'EDITOR', 'description' => SITE_ROLE_WORDS['EDITOR']]]]);
    same(201, $created->status, 'made, naming only the roles whose tick it sets: ' . $created->body);
    $started = lastAuditOf('application.created');
    ksort($started);
    same(['login_required' => true, 'sign_in_roles' => ['ADMIN', 'EDITOR']], $started, 'the audit says who may sign in from the start');
    same(['application'], array_keys($created->json()), 'and no client secret in the answer');
    $application = $created->json()['application'];
    same(true, $application['login_required'], 'a new site requires signing in');
    same([['name' => 'ADMIN', 'description' => SITE_ROLE_WORDS['ADMIN'], 'may_sign_in' => true, 'members' => 0],
          ['name' => 'EDITOR', 'description' => SITE_ROLE_WORDS['EDITOR'], 'may_sign_in' => true, 'members' => 0],
          ['name' => 'MEMBER', 'description' => SITE_ROLE_WORDS['MEMBER'], 'may_sign_in' => false, 'members' => $everybody]],
        $application['roles'], 'the family\'s three roles in the family\'s words, MEMBER every account\'s, ticked as asked');
    same([], array_intersect(['access', 'type', 'has_secret', 'show_on_landing', 'sort_order'], array_keys($application)), 'no access, type, secret or landing page settings in the panel\'s shape');
    same(['public', null], array_values(iamDb()->query("SELECT type, secret_hash FROM applications WHERE client_id = 'newsite'")->fetch(PDO::FETCH_NUM)), 'a public client without a secret');
    same((int) iamDb()->query("SELECT max(sort_order) FROM applications WHERE client_id <> 'newsite'")->fetchColumn() + 1,
        (int) iamDb()->query("SELECT sort_order FROM applications WHERE client_id = 'newsite'")->fetchColumn(), 'last in the list, whatever the body said');
    same([], withoutMember(iamDb()), 'nobody without MEMBER');

    $listed = array_column((new Browser())->get('/api/applications')->json(), 'client_id');
    same('newsite', end($listed), 'the public list: every site switched on, the new one last');
    check(!in_array('iam', $listed, true), 'never the IAM itself');
    same(200, $admin->put("/api/admin/applications/{$application['id']}", ['enabled' => false])->status, 'switched off');
    check(!in_array('newsite', array_column((new Browser())->get('/api/applications')->json(), 'client_id'), true), 'and off the list');
    same(200, $admin->delete("/api/admin/applications/{$application['id']}")->status, 'and deleted again');
});

test('an account and an application made at the same moment meet: the two triggers share one lock', function () {
    $server = testServer();
    $one = connectDatabase($server, testEnvironment()['db'], false);
    $two = connectDatabase($server, testEnvironment()['db'], false);

    $one->beginTransaction();
    $one->exec("INSERT INTO users (username, email, password, email_confirmed) VALUES ('racer', 'racer@example.test', NULL, TRUE)");
    $two->exec("SET lock_timeout = '300ms'");
    $waited = null;
    try {
        $two->exec("INSERT INTO applications (client_id, name) VALUES ('racetrack', 'Racetrack')");
    } catch (PDOException $e) {
        $waited = $e->getCode();
    }
    same('55P03', $waited, 'the application waits for the account\'s transaction (here: until its lock timeout)');
    $one->commit();

    $two->exec("INSERT INTO applications (client_id, name) VALUES ('racetrack', 'Racetrack')");
    same(['racer'], array_values(array_filter(holders(iamDb(), 'racetrack', 'MEMBER'), static fn(string $name): bool => $name === 'racer')), 'made once the account is in, it gives it MEMBER');
    same([], withoutMember(iamDb()), 'nobody without MEMBER anywhere');
});

// ── The rule, through the real endpoints ─────────────────────────────────────

$memberId = makeUser('member', 'member@example.test');
$editorId = makeUser('surveyeditor', 'surveyeditor@example.test');
grantRole($editorId, 'survey', 'EDITOR');

test('somebody holding only MEMBER signs in to a site', function () {
    $tokens = intoSurvey(new Browser('10.5.2.1'), 'member@example.test');
    same(['MEMBER'], verifyLikeAnApp(new Browser(), $tokens['access_token'], 'survey')['roles'] ?? null, 'the token carries MEMBER');
});

test('MEMBER unticked for a site: its holders are refused on every way in, a role that may sign in still gets in', function () {
    $memberBrowser = new Browser('10.5.3.1');
    $before = intoSurvey($memberBrowser, 'member@example.test');
    $personal = $memberBrowser->post('/api/account/tokens', ['name' => 'script', 'client_id' => 'survey', 'expires_in_days' => 30]);
    same(201, $personal->status, 'a personal token, made while MEMBER may sign in: ' . $personal->body);
    same(200, refreshAt($personal->json()['token'])->status, 'and working');
    same(['genshin' => true, 'survey' => true], array_intersect_key(listedFor($memberBrowser), ['genshin' => 1, 'survey' => 1]), 'the account page lists the survey site, usable');

    $saved = surveyMaySignIn(admin(), ['MEMBER' => false]);
    same(200, $saved->status, 'MEMBER unticked in the admin panel: ' . $saved->body);
    same(false, array_column($saved->json()['roles'], 'may_sign_in', 'name')['MEMBER'], 'and said so');
    same(['sign_in_roles' => ['after' => ['ADMIN', 'EDITOR'], 'before' => ['ADMIN', 'EDITOR', 'MEMBER']]], lastAuditOf('application.sign_in_changed'), 'audited, before and after');

    $start = startSignIn($memberBrowser);
    $request = queryOf($start['location'])['request'] ?? null;
    check($request !== null && !isset(queryOf($start['location'])['code']), 'no silent code any more - the sign-in page: ' . $start['location']);
    $accept = $memberBrowser->post("/api/login/request/{$request}/accept");
    same([403, 'access_denied', DENIED], [$accept->status, $accept->code(), $accept->json()['error'] ?? null], 'accept refuses');
    $back = queryOf($memberBrowser->post("/api/login/request/{$request}/cancel")->json()['redirect'] ?? '');
    same(['access_denied', DENIED], [$back['error'] ?? null, $back['error_description'] ?? null], 'and going back tells the site why');

    $refused = refreshAt($before['refresh_token']);
    same('invalid_grant', $refused->json()['error'] ?? null, 'the refresh token from before stops at its next use');
    $family = iamDb()->prepare('SELECT DISTINCT revoked_reason FROM refresh_tokens WHERE user_id = ? AND kind = ? AND revoked_at IS NOT NULL
                                  AND application_id = (SELECT id FROM applications WHERE client_id = ?)');
    $family->execute([$GLOBALS['memberId'], 'rotating', 'survey']);
    same(['access_denied'], $family->fetchAll(PDO::FETCH_COLUMN), 'and its family is revoked, saying why');
    same('invalid_grant', refreshAt($personal->json()['token'])->json()['error'] ?? null, 'the personal token made before is refused too');
    $made = $memberBrowser->post('/api/account/tokens', ['name' => 'another', 'client_id' => 'survey', 'expires_in_days' => 30]);
    same([403, 'access_denied'], [$made->status, $made->code()], 'and no new one is made');

    check(!array_key_exists('survey', listedFor($memberBrowser)), 'the account page leaves out a site it holds nothing but MEMBER in and cannot use');

    $editorBrowser = new Browser('10.5.3.2');
    $tokens = intoSurvey($editorBrowser, 'surveyeditor@example.test');
    same(['EDITOR', 'MEMBER'], verifyLikeAnApp(new Browser(), $tokens['access_token'], 'survey')['roles'] ?? null, 'an EDITOR still gets in, MEMBER in the token too');
    same(true, listedFor($editorBrowser)['survey'] ?? null, 'and sees the site on the account page, usable');

    // A role given by hand that may not sign in: invited, and turned away -
    // listed, saying so. Made the way one came about before the roles were
    // fixed (011): the panel adds none now, and keeps such a one read-only.
    iamDb()->exec("INSERT INTO application_roles (application_id, name, description, may_sign_in)
                   SELECT id, 'GUEST', 'Looks in', FALSE FROM applications WHERE client_id = 'survey'");
    grantRole($GLOBALS['memberId'], 'survey', 'GUEST');
    $guestBrowser = new Browser('10.5.3.3');
    $guestBrowser->signIn('member@example.test', 'correct horse');
    same(false, listedFor($guestBrowser)['survey'] ?? null, 'the account page lists a site it holds a role in, as not usable');

    same(200, surveyMaySignIn(admin(), ['MEMBER' => true])->status, 'MEMBER ticked again');
    intoSurvey(new Browser('10.5.3.4'), 'member@example.test');
});

test('MEMBER taken from one person in one site: refused there, still everywhere else, and everybody else still in', function () {
    $admin = admin();
    $scripting = new Browser('10.5.4.3');
    same(200, $scripting->signIn('member@example.test', 'correct horse')->status, 'the person signs in to the IAM');
    $personal = $scripting->post('/api/account/tokens', ['name' => 'nightly', 'client_id' => 'survey', 'expires_in_days' => 30]);
    same(201, $personal->status, 'a personal token, made while they hold MEMBER: ' . $personal->body);
    same(200, refreshAt($personal->json()['token'])->status, 'and working');

    $grants = $admin->get('/api/admin/users/' . $GLOBALS['memberId'])->json()['roles'];
    $kept = array_values(array_filter($grants, static fn(array $grant): bool => !($grant['client_id'] === 'survey' && $grant['role'] === 'MEMBER')));
    same(200, $admin->put('/api/admin/users/' . $GLOBALS['memberId'] . '/roles', ['roles' => $kept])->status, 'roles set without survey MEMBER');

    $browser = new Browser('10.5.4.1');
    same(200, $browser->signIn('member@example.test', 'correct horse')->status, 'signs in to the IAM');
    check(isset(queryOf(startSignIn($browser)['location'])['request']), 'the survey site: the sign-in page, no code');
    check(isset(queryOf(startSignIn($browser, 'piano-academy', PIANO_CALLBACK)['location'])['code']), 'another site: in');
    same('invalid_grant', refreshAt($personal->json()['token'])->json()['error'] ?? null, 'the personal token made before is refused at its next use');
    intoSurvey(new Browser('10.5.4.2'), 'surveyeditor@example.test');

    grantRole($GLOBALS['memberId'], 'survey', 'MEMBER');
});

test('a code given before MEMBER is unticked, or taken from the person, is not exchanged for tokens', function () {
    $refusedExchange = static function (TestResponse $answer, string $what): void {
        same([400, 'invalid_grant', 'The sign-in behind this code is no longer valid'],
            [$answer->status, $answer->json()['error'] ?? null, $answer->json()['error_description'] ?? null], $what . ': ' . $answer->body);
        check(!isset($answer->json()['access_token']) && !isset($answer->json()['refresh_token']) && !isset($answer->json()['id_token']), 'and no tokens come back');
    };
    $admin = admin();

    $browser = new Browser('10.5.4.5');
    same(200, $browser->signIn('member@example.test', 'correct horse')->status, 'the person signs in to the IAM');
    $start = startSignIn($browser);
    $code = queryOf($start['location'])['code'] ?? null;
    check($code !== null, 'a code, while MEMBER may sign in: ' . $start['location']);
    same(200, surveyMaySignIn($admin, ['MEMBER' => false])->status, 'MEMBER unticked before the site exchanges it');
    $refusedExchange(exchangeFor($browser, $code, $start['verifier']), 'the code is refused');
    same(200, surveyMaySignIn($admin, ['MEMBER' => true])->status, 'MEMBER ticked again');

    $start = startSignIn($browser);
    $code = queryOf($start['location'])['code'] ?? null;
    check($code !== null, 'another code: ' . $start['location']);
    $grants = $admin->get('/api/admin/users/' . $GLOBALS['memberId'])->json()['roles'];
    $kept = array_values(array_filter($grants, static fn(array $grant): bool => !($grant['client_id'] === 'survey' && $grant['role'] === 'MEMBER')));
    same(200, $admin->put('/api/admin/users/' . $GLOBALS['memberId'] . '/roles', ['roles' => $kept])->status, 'MEMBER taken from the person before the site exchanges it');
    $refusedExchange(exchangeFor($browser, $code, $start['verifier']), 'the code is refused');

    grantRole($GLOBALS['memberId'], 'survey', 'MEMBER');
    intoSurvey(new Browser('10.5.4.6'), 'member@example.test');
});

// ── The public contract: is sign-in required here? ───────────────────────────

test('the public contract: three fields for an enabled site, cacheable, no cookie, no CORS; 404 for anything else; the panel\'s switch shows at once', function () {
    $answer = (new Browser('10.5.7.1'))->request('GET', '/api/public/applications/survey', ['headers' => ['Origin' => SURVEY_FRONTEND]]);
    same(200, $answer->status, 'answered without a session: ' . $answer->body);
    $name = (string) iamDb()->query("SELECT name FROM applications WHERE client_id = 'survey'")->fetchColumn();
    same(['client_id' => 'survey', 'name' => $name, 'login_required' => true], $answer->json(), 'the client id, the name, the switch - and nothing else');
    same('public, max-age=60', $answer->header('Cache-Control'), 'cacheable for a minute');
    same(['', ''], [$answer->header('Set-Cookie'), $answer->header('Access-Control-Allow-Origin')], 'no cookie set, no CORS for a browser');

    foreach (['genshin' => false, 'build-analyzer' => false, 'piano-academy' => true, 'japanese-academy' => true] as $clientId => $required) {
        same([200, $required], [($r = (new Browser())->get("/api/public/applications/{$clientId}"))->status, $r->json()['login_required'] ?? null], "{$clientId}: as 010 left it");
    }

    iamDb()->exec("INSERT INTO applications (client_id, name, enabled) VALUES ('dormant', 'Dormant', FALSE)");
    foreach (['nobody-here', 'iam', 'dormant', 'SURVEY', 'x', str_repeat('a', 65), 'survey.json'] as $clientId) {
        $refused = (new Browser())->get('/api/public/applications/' . rawurlencode($clientId));
        same([404, 'not_found'], [$refused->status, $refused->code()], "404 for {$clientId}");
        same(['no-store', ''], [$refused->header('Cache-Control'), $refused->header('Access-Control-Allow-Origin')], "{$clientId}: not kept by a shared cache, no CORS");
    }
    same(404, (new Browser())->get('/api/public/applications/')->status, 'nor with no client id');

    [$id] = surveyInPanel(admin());
    same(200, admin()->put("/api/admin/applications/{$id}", ['login_required' => false])->status, 'sign-in required switched off in the panel');
    $off = (new Browser())->get('/api/public/applications/survey');
    same(false, $off->json()['login_required'] ?? null, 'the next answer says so');

    // What the IAM answers is what the sites' SDK reads: its tests' seam takes the body as the IAM's 200.
    $file = testEnvironment()['storage'] . DIRECTORY_SEPARATOR . 'application.json';
    $config = ['issuer' => 'http://localhost:4300', 'client_id' => 'survey', 'application_file' => $file];
    file_put_contents($file, $off->body);
    same([false, 'read'], [anotoki_iam_login_required($config, $reason), $reason], 'the SDK reads the answer: visitors welcome');
    same(200, admin()->put("/api/admin/applications/{$id}", ['login_required' => true])->status, 'and on again');
    file_put_contents($file, (new Browser())->get('/api/public/applications/survey')->body);
    same([true, 'read'], [anotoki_iam_login_required($config, $reason), $reason], 'the SDK reads it: sign-in required');
    $config['client_id'] = 'genshin';
    same([true, 'never_read'], [anotoki_iam_login_required($config, $reason), $reason], 'an answer about another site is no answer to it');
    @unlink($file);

    same(200, admin()->put("/api/admin/applications/{$id}", ['enabled' => false])->status, 'the survey site switched off');
    same(404, (new Browser())->get('/api/public/applications/survey')->status, 'a switched-off site: 404, which the SDK keeps as "sign-in required"');
    same(200, admin()->put("/api/admin/applications/{$id}", ['enabled' => true])->status, 'and on again');
    same(200, (new Browser())->get('/api/public/applications/survey')->status, 'answered again');
});

// ── Public clients only ──────────────────────────────────────────────────────

test('public clients only: a client secret is refused at the token and revocation endpoints, PKCE is asked of every client, discovery says so', function () {
    $discovery = (new Browser())->get('/.well-known/openid-configuration')->json();
    same([['none'], ['none']], [$discovery['token_endpoint_auth_methods_supported'] ?? null, $discovery['revocation_endpoint_auth_methods_supported'] ?? null], 'discovery: no client authentication but none');

    $browser = new Browser('10.5.8.1');
    $tokens = intoSurvey($browser, 'surveyeditor@example.test');
    $refusal = ['error' => 'invalid_client', 'error_description' => 'Public clients only - send client_id and the PKCE code_verifier, no secret'];
    foreach ([
        'a client_secret in the body' => ['form' => ['client_secret' => 'anything']],
        'an empty client_secret' => ['form' => ['client_secret' => '']],
        'HTTP Basic' => ['headers' => ['Authorization' => 'Basic ' . base64_encode('survey:anything')]],
    ] as $what => $extra) {
        $refreshed = (new Browser())->request('POST', '/api/oidc/token', [
            'form' => ['grant_type' => 'refresh_token', 'refresh_token' => $tokens['refresh_token'], 'client_id' => 'survey'] + ($extra['form'] ?? []),
            'headers' => ['Origin' => SURVEY_FRONTEND] + ($extra['headers'] ?? []),
        ]);
        same([401, $refusal], [$refreshed->status, $refreshed->json()], "token endpoint, {$what}: refused");
        $revoked = (new Browser())->request('POST', '/api/oidc/revoke', [
            'form' => ['token' => $tokens['refresh_token'], 'client_id' => 'survey'] + ($extra['form'] ?? []),
            'headers' => $extra['headers'] ?? [],
        ]);
        same([401, $refusal], [$revoked->status, $revoked->json()], "revocation endpoint, {$what}: refused");
    }
    same(200, refreshAt($tokens['refresh_token'])->status, 'none of that revoked or spent the token: without a secret it refreshes');

    // A client left confidential (the build before 011, or a row edited by
    // hand) is asked for PKCE all the same.
    iamDb()->exec("UPDATE applications SET type = 'confidential' WHERE client_id = 'survey'");
    try {
        $start = $browser->get('/api/oidc/authorize?' . http_build_query([
            'response_type' => 'code', 'client_id' => 'survey', 'redirect_uri' => SURVEY_CALLBACK, 'scope' => 'openid', 'state' => 's',
        ]));
        same(['invalid_request', 'A public client must send a PKCE code_challenge'], [queryOf($start->location())['error'] ?? null, queryOf($start->location())['error_description'] ?? null],
            'no code without a PKCE challenge, whatever the row says');
    } finally {
        iamDb()->exec("UPDATE applications SET type = 'public' WHERE client_id = 'survey'");
    }
});

// ── The admin panel's rules ──────────────────────────────────────────────────

test('the admin panel: the roles are fixed, MEMBER stays, some role may sign in, the IAM has no sign-in switch, what is left out is kept', function () {
    $admin = admin();
    [$id, $application] = surveyInPanel($admin);
    same(true, $application['login_required'], 'the survey site requires signing in');
    same(['ADMIN', 'EDITOR', 'GUEST', 'MEMBER'], array_column($application['roles'], 'name'), 'its three, and the GUEST made before the roles were fixed');
    $roles = static fn(array $names): array => array_map(static fn(string $name): array => ['name' => $name], $names);
    $fixed = static fn(TestResponse $answer): bool => $answer->status === 422 && str_starts_with($answer->json()['error'] ?? '', 'The roles are fixed - ');

    $refused = $admin->put("/api/admin/applications/{$id}", ['roles' => $roles(['ADMIN', 'EDITOR', 'GUEST'])]);
    same([422, 'Every application keeps its MEMBER role - take it from people instead (Users -> the person -> Roles)'], [$refused->status, $refused->json()['error'] ?? null], 'MEMBER cannot be removed');
    check($fixed($admin->put("/api/admin/applications/{$id}", ['roles' => $roles(['ADMIN', 'EDITOR', 'GUEST', 'MEMBERS'])])), 'nor renamed');
    $refused = $admin->put("/api/admin/applications/{$id}", ['roles' => $roles(['ADMIN', 'GUEST', 'MEMBER'])]);
    same([422, 'The roles are fixed - EDITOR cannot be removed; only whether each may sign in can change'], [$refused->status, $refused->json()['error'] ?? null], 'no role can be removed');
    check($fixed($admin->put("/api/admin/applications/{$id}", ['roles' => $roles(['ADMIN', 'EDITOR', 'GUEST', 'MEMBER', 'READER'])])), 'nor one added');
    $reworded = $roles(['ADMIN', 'EDITOR', 'GUEST', 'MEMBER']);
    $reworded[1]['description'] = 'Something else';
    same([422, 'The roles are fixed - EDITOR keeps its description; only whether each may sign in can change'],
        [($r = $admin->put("/api/admin/applications/{$id}", ['roles' => $reworded]))->status, $r->json()['error'] ?? null], 'nor reworded');
    same(array_column($application['roles'], 'description', 'name'), array_column($admin->get("/api/admin/applications/{$id}")->json()['roles'], 'description', 'name'), 'the roles are as they were');
    same(200, $admin->put("/api/admin/applications/{$id}", ['roles' => array_map(static fn(array $role): array => ['name' => strtolower($role['name']), 'description' => $role['description']], $application['roles'])])->status,
        'a body naming them as they are (any case) is fine');

    $refused = $admin->put("/api/admin/applications/{$id}", ['type' => 'confidential']);
    same([422, 'Every application is a public client: it names itself and proves each sign-in with PKCE - there are no client secrets'], [$refused->status, $refused->json()['error'] ?? null], 'no confidential clients');
    same(200, $admin->put("/api/admin/applications/{$id}", ['type' => 'public'])->status, 'public is what it is anyway');
    $before = iamDb()->query("SELECT show_on_landing, sort_order FROM applications WHERE client_id = 'survey'")->fetch();
    same(200, $admin->put("/api/admin/applications/{$id}", ['show_on_landing' => !asBool($before['show_on_landing']), 'sort_order' => 999])->status, 'landing page settings sent by an old script');
    same($before, iamDb()->query("SELECT show_on_landing, sort_order FROM applications WHERE client_id = 'survey'")->fetch(), 'are ignored');

    $none = array_map(static fn(array $role): array => ['name' => $role['name'], 'description' => $role['description'], 'may_sign_in' => false], $application['roles']);
    $refused = $admin->put("/api/admin/applications/{$id}", ['roles' => $none]);
    same([422, 'At least one role must be allowed to sign in - switch the application off instead'], [$refused->status, $refused->json()['error'] ?? null], 'nobody able to sign in is refused');
    same(200, $admin->put("/api/admin/applications/{$id}", ['roles' => $none, 'enabled' => false])->status, 'unless the site is switched off with it');
    same(['enabled' => false], array_intersect_key($admin->get("/api/admin/applications/{$id}")->json(), ['enabled' => 1]), 'as it is now');
    same(200, $admin->put("/api/admin/applications/{$id}", ['description' => 'Still off'])->status, 'a site switched off may still be edited');
    same(422, $admin->put("/api/admin/applications/{$id}", ['enabled' => true])->status, 'but not switched on again while no role may sign in');
    $back = array_map(static fn(array $role): array => ['name' => $role['name'], 'description' => $role['description'], 'may_sign_in' => $role['name'] !== 'GUEST'], $application['roles']);
    same(200, $admin->put("/api/admin/applications/{$id}", ['roles' => $back, 'enabled' => true, 'description' => $application['description']])->status, 'switched on with its roles ticked');

    $kept = array_map(static fn(array $role): array => ['name' => $role['name'], 'description' => $role['description']], $application['roles']);
    $saved = $admin->put("/api/admin/applications/{$id}", ['roles' => $kept, 'access' => 'members']);
    same(200, $saved->status, 'a body that names no may_sign_in, and the old access: ' . $saved->body);
    same(['ADMIN' => true, 'EDITOR' => true, 'GUEST' => false, 'MEMBER' => true], array_column($saved->json()['roles'], 'may_sign_in', 'name'), 'keeps what each role had');
    intoSurvey(new Browser('10.5.5.1'), 'member@example.test');

    $refused = $admin->put("/api/admin/applications/{$id}", ['roles' => [['name' => 'MEMBER', 'may_sign_in' => 'no']]]);
    same([422, 'may_sign_in of MEMBER is true or false'], [$refused->status, $refused->json()['error'] ?? null], 'may_sign_in is a boolean');

    $off = $admin->put("/api/admin/applications/{$id}", ['login_required' => false]);
    same([200, false], [$off->status, $off->json()['login_required'] ?? null], 'sign-in required switched off');
    same(['login_required' => ['after' => false, 'before' => true]], lastAuditOf('application.sign_in_changed'), 'audited');
    same(422, $admin->put("/api/admin/applications/{$id}", ['login_required' => 'yes'])->status, 'login_required is a boolean');
    same(200, $admin->put("/api/admin/applications/{$id}", ['login_required' => true])->status, 'and on again');

    $iamId = (int) iamDb()->query("SELECT id FROM applications WHERE client_id = 'iam'")->fetchColumn();
    $refused = $admin->put("/api/admin/applications/{$iamId}", ['login_required' => false]);
    same([422, 'The IAM\'s own pages have no sign-in switch - Settings -> Signing in is theirs'], [$refused->status, $refused->json()['error'] ?? null], 'the IAM has no sign-in switch');
    $iamRoles = array_map(static fn(array $role): array => ['name' => $role['name'], 'description' => $role['description'], 'may_sign_in' => false], $admin->get("/api/admin/applications/{$iamId}")->json()['roles']);
    $saved = $admin->put("/api/admin/applications/{$iamId}", ['roles' => $iamRoles]);
    same(200, $saved->status, 'its roles are saved: ' . $saved->body);
    same([true, true], array_column($saved->json()['roles'], 'may_sign_in'), 'but may_sign_in means nothing for its own pages, and stays as it was');
});

test('two saves of one application at the same moment run one after the other, so together they still keep a role that may sign in', function () {
    $admin = admin();
    [$id] = surveyInPanel($admin);

    // Another administrator's save, half done: ADMIN and EDITOR unticked, not yet committed.
    $other = connectDatabase(testServer(), testEnvironment()['db'], false);
    $other->beginTransaction();
    $other->prepare('SELECT enabled FROM applications WHERE id = ? FOR UPDATE')->execute([$id]);
    $other->prepare("UPDATE application_roles SET may_sign_in = FALSE WHERE application_id = ? AND name IN ('ADMIN', 'EDITOR')")->execute([$id]);

    iamDb()->exec("SET lock_timeout = '300ms'");
    try {
        $waited = surveyMaySignIn($admin, ['MEMBER' => false]);
    } finally {
        iamDb()->exec('SET lock_timeout = 0');
    }
    same(500, $waited->status, 'unticking MEMBER waits for the other save (here: until its lock timeout) rather than reading its roles as still ticked');
    same(true, asBool(iamDb()->query("SELECT may_sign_in FROM application_roles WHERE name = 'MEMBER' AND application_id = {$id}")->fetchColumn()), 'and changes nothing');
    $other->commit();

    $refused = surveyMaySignIn($admin, ['MEMBER' => false]);
    same([422, 'At least one role must be allowed to sign in - switch the application off instead'], [$refused->status, $refused->json()['error'] ?? null],
        'once the other is in, unticking MEMBER as well is refused');
    same(200, surveyMaySignIn($admin, ['ADMIN' => true, 'EDITOR' => true])->status, 'ADMIN and EDITOR ticked again');
    intoSurvey(new Browser('10.5.5.2'), 'surveyeditor@example.test');
});

// ── The admin panel's lists: who is blocked where ────────────────────────────

test('the admin panel\'s lists: blocked_in on each account, the Members tab\'s holders beyond MEMBER and who is blocked, the users filter beyond MEMBER', function () {
    $admin = admin();
    $pdo = iamDb();
    $taken = makeUser('taken', 'taken@example.test');
    $plain = makeUser('plain', 'plain@example.test');
    $pianoEditor = makeUser('pianoeditor', 'pianoeditor@example.test');
    $off = makeUser('switchedoff', 'switchedoff@example.test');
    grantRole($pianoEditor, 'piano-academy', 'EDITOR');
    $pdo->prepare("DELETE FROM user_roles WHERE user_id IN (?, ?) AND role_id = (SELECT r.id FROM application_roles r JOIN applications a ON a.id = r.application_id
                    WHERE a.client_id = 'survey' AND r.name = 'MEMBER')")->execute([$taken, $off]);
    $pdo->prepare('UPDATE users SET disabled = TRUE WHERE id = ?')->execute([$off]);
    $pianoId = (int) $pdo->query("SELECT id FROM applications WHERE client_id = 'piano-academy'")->fetchColumn();
    $pianoRoles = static fn(array $maySignIn): array => array_map(static fn(array $role): array => ['name' => $role['name'], 'may_sign_in' => $maySignIn[$role['name']] ?? true],
        $admin->get("/api/admin/applications/{$pianoId}")->json()['roles']);
    same(200, $admin->put("/api/admin/applications/{$pianoId}", ['roles' => $pianoRoles(['MEMBER' => false, 'EDITOR' => false])])->status, 'Piano: only ADMIN may sign in');

    try {
        $users = array_column($admin->get('/api/admin/users')->json(), null, 'username');
        same(['survey'], $users['taken']['blocked_in'], 'MEMBER taken from somebody: blocked from that site');
        same([], $users['plain']['blocked_in'], 'MEMBER alone where MEMBER may not sign in: not invited, not "blocked" - or everybody would be');
        same(['piano-academy'], $users['pianoeditor']['blocked_in'], 'a role there, none that may sign in: blocked');
        same(['survey'], $users['switchedoff']['blocked_in'], 'an account switched off is listed with its blocks too');
        same(['piano-academy'], $admin->get("/api/admin/users/{$pianoEditor}")->json()['blocked_in'], 'and so is one account');

        $filters = array_column($admin->get('/api/admin/users/filters')->json()['applications'], null, 'client_id');
        same([false, ['ADMIN', 'EDITOR', 'MEMBER'], ['ADMIN']], [$filters['piano-academy']['system'], $filters['piano-academy']['roles'], $filters['piano-academy']['sign_in_roles']], 'the filters: Piano\'s roles, and which may sign in');
        same([true, ['ADMIN', 'MEMBER']], [$filters['iam']['system'], $filters['iam']['sign_in_roles']], 'the IAM marked as the system entry');

        $members = $admin->get("/api/admin/applications/{$pianoId}/members")->json();
        same([['pianoeditor', ['EDITOR', 'MEMBER']]], array_map(static fn(array $member): array => [$member['username'], $member['roles']], $members['members']),
            'the Members tab: who holds a role beyond MEMBER, with every role there - not every account');
        same([['user_id' => $pianoEditor, 'username' => 'pianoeditor', 'email' => 'pianoeditor@example.test']], $members['blocked'], 'and who cannot sign in');
        $surveyId = (int) $pdo->query("SELECT id FROM applications WHERE client_id = 'survey'")->fetchColumn();
        same(['taken'], array_column($admin->get("/api/admin/applications/{$surveyId}/members")->json()['blocked'], 'username'), 'the survey: MEMBER taken from one, the account switched off left out');
        $iamId = (int) $pdo->query("SELECT id FROM applications WHERE client_id = 'iam'")->fetchColumn();
        $iamMembers = $admin->get("/api/admin/applications/{$iamId}/members")->json();
        same([[], ['admin']], [$iamMembers['blocked'], array_column($iamMembers['members'], 'username')], 'the IAM blocks nobody (Settings -> Signing in is its gate); its ADMIN listed');
        same([404, 'not_found'], [($r = $admin->get('/api/admin/applications/999999/members'))->status, $r->code()], 'no such application');

        $byApplication = array_column($admin->get('/api/admin/users?application=piano-academy')->json(), 'username');
        check(in_array('pianoeditor', $byApplication, true) && !in_array('plain', $byApplication, true), 'the users filter by an application alone: a role there beyond MEMBER');
        $byMember = array_column($admin->get('/api/admin/users?application=survey&role=MEMBER')->json(), 'username');
        check(in_array('plain', $byMember, true) && !in_array('taken', $byMember, true), 'by MEMBER itself: who holds it');
    } finally {
        same(200, $admin->put("/api/admin/applications/{$pianoId}", ['roles' => $pianoRoles([])])->status, 'Piano: every role ticked again');
        $pdo->prepare('UPDATE users SET disabled = FALSE WHERE id = ?')->execute([$off]);
        grantRole($taken, 'survey', 'MEMBER');
        grantRole($off, 'survey', 'MEMBER');
    }
});

// ── With 010 pending: a build uploaded before Apply ──────────────────────────

test('with 010 pending signing in works by the rule before it, the panel\'s application pages say what to do, and Apply brings the rules in this very process', function () {
    $main = testEnvironment()['db'];
    $window = throwawayDatabase($main . '_window');
    buildIamThrough('_window', '009_admin_panel.sql');
    useIamDatabase($window);

    try {
        $pdo = iamDb();
        same($window, $pdo->query('SELECT current_database()')->fetchColumn(), 'the app runs on the database without 010');
        same(false, signInRulesReady($pdo), 'no rules yet');

        $adminId = makeUser('admin', 'admin@example.test');
        grantRole($adminId, 'iam', 'ADMIN');
        makeUser('person', 'person@example.test');
        $pdo->exec("UPDATE applications SET access = 'members' WHERE client_id = 'piano-academy'");

        $personBrowser = new Browser('10.5.6.1');
        $tokens = intoSurvey($personBrowser, 'person@example.test');
        same([], verifyLikeAnApp(new Browser(), $tokens['access_token'], 'survey')['roles'] ?? null, 'a site open to everyone lets an account with no role in, as before');
        $refreshed = refreshAt($tokens['refresh_token']);
        same(200, $refreshed->status, 'and refreshes: ' . $refreshed->body);
        check(isset(queryOf(startSignIn($personBrowser, 'piano-academy', PIANO_CALLBACK)['location'])['request']), 'a members-only site turns it away, as before');
        same(['survey' => true], array_intersect_key(listedFor($personBrowser), ['survey' => 1, 'piano-academy' => 1]), 'the account page lists what it may use and leaves out the members-only one');

        $admin = admin();
        $surveyId = (int) $pdo->query("SELECT id FROM applications WHERE client_id = 'survey'")->fetchColumn();
        foreach (['GET /api/admin/applications', "GET /api/admin/applications/{$surveyId}", "GET /api/admin/applications/{$surveyId}/members", "PUT /api/admin/applications/{$surveyId}"] as $call) {
            [$method, $path] = explode(' ', $call);
            $answer = $method === 'GET' ? $admin->get($path) : $admin->put($path, ['description' => 'x']);
            same([503, 'sign_in_rules_unavailable'], [$answer->status, $answer->code()], $call);
        }
        same(503, $admin->post('/api/admin/applications', ['client_id' => 'early', 'name' => 'Early'])->status, 'nor is one made');
        same([401, 'no_session'], [($r = (new Browser('10.5.6.9'))->get('/api/admin/applications'))->status, $r->code()], 'to anybody else: sign in first');
        $users = $admin->get('/api/admin/users');
        same(200, $users->status, 'the users pages work meanwhile');
        same([[]], array_values(array_unique(array_column($users->json(), 'blocked_in'), SORT_REGULAR)), 'nobody shown blocked anywhere: there is no such thing yet');
        $filters = $admin->get('/api/admin/users/filters');
        same(200, $filters->status, 'with their filters');
        $survey = array_column($filters->json()['applications'], null, 'client_id')['survey'];
        same([false, $survey['roles']], [$survey['system'], $survey['sign_in_roles']], 'every role lets somebody in, by the rule before 010');
        $early = $admin->post('/api/admin/users', ['username' => 'early', 'email' => 'early@example.test', 'password' => 'correct horse']);
        same(201, $early->status, 'an account can be made');
        $renamed = $admin->put('/api/admin/users/' . $early->json()['id'], ['username' => 'earlier']);
        same([200, 'earlier', []], [$renamed->status, $renamed->json()['username'] ?? null, $renamed->json()['blocked_in'] ?? null], 'and changed, its row as ever');
        $contract = (new Browser())->get('/api/public/applications/survey');
        same([503, 'sign_in_rules_unavailable', 'no-store'], [$contract->status, $contract->code(), $contract->header('Cache-Control')], 'the public contract has no switch to read yet: 503, kept by nobody');
        same(404, (new Browser())->get('/api/public/applications/nobody-here')->status, 'an unknown site is still 404');
        same([RULES_MIGRATION, STATIC_MIGRATION], $admin->get('/api/admin/migrations')->json()['pending'] ?? null, 'the Migrations page shows 010 and 011 pending');

        $applied = $admin->post('/api/admin/migrations/apply');
        same([200, [RULES_MIGRATION, STATIC_MIGRATION], null], [$applied->status, $applied->json()['applied'] ?? null, $applied->json()['failed'] ?? null], 'Apply pending: ' . $applied->body);

        same(true, signInRulesReady($pdo), 'from the next request on, in the same process: the rules are there');
        $list = $admin->get('/api/admin/applications');
        same(200, $list->status, 'the application pages answer');
        same(true, array_column($list->json(), 'login_required', 'client_id')['survey'] ?? null, 'with the switch');
        same([200, true], [($r = (new Browser())->get('/api/public/applications/survey'))->status, $r->json()['login_required'] ?? null], 'and so does the public contract');
        $refreshed = refreshAt($refreshed->json()['refresh_token']);
        same(200, $refreshed->status, 'the refresh token from before keeps working: ' . $refreshed->body);
        same(['MEMBER'], verifyLikeAnApp(new Browser(), $refreshed->json()['access_token'], 'survey')['roles'] ?? null, 'and now carries MEMBER');
        check(isset(queryOf(startSignIn($personBrowser, 'piano-academy', PIANO_CALLBACK)['location'])['request']), 'the members-only site still turns it away');
        same(['survey' => true], array_intersect_key(listedFor($personBrowser), ['survey' => 1, 'piano-academy' => 1]), 'and the account page is as it was');
        same([], withoutMember($pdo), 'every account made in the window holds MEMBER now');
    } finally {
        useIamDatabase($main);
    }

    same($main, iamDb()->query('SELECT current_database()')->fetchColumn(), 'back on this run\'s own database');
});

finishTests();
