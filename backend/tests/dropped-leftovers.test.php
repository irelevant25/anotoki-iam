<?php

/**
 * Migration 027 (the owner's yes of 2026-10-25): the two things kept "until a
 * later migration drops them, once both IAMs run" the file that left them over
 * go - applications.access, the rule before 010, read only while 010 was
 * pending (the code's signInRulesReady(), gone with this release), and
 * audit_log_before_018, the copy of the IAM's own audit log 018 kept, unread,
 * once every entry was in the library's anotoki_audit_log.
 *
 * Nothing that decides a sign-in changes: who may sign in has been each role's
 * may_sign_in since 010. So 027 is applied to an IAM shaped like an installed
 * one - built through 009 as its owner left it (a site made members-only by
 * hand, an application of the owner's own, a MEMBER somebody held in a
 * members-only one, an audit history), then 010 to 026, as the Migrations page
 * brought them - and the code itself is asked, for every account and every
 * application, before and after: the same answer, account by account. Every
 * audit entry stays in the library's table. And the window - this build on
 * such a database, 027 waiting: a site's sign-in, its refusal, a deletion
 * (the person out of the library's table and of the copy 018 kept, before the
 * Apply's backup), the admin panel's application pages and the sites' public
 * contract all answer, and the Migrations page's Apply (a backup first) brings
 * 027. Its gate refuses a database without 010's column or 018's move, drops
 * nothing, and runs once the cause is gone.
 */

declare(strict_types=1);

require __DIR__ . '/lib.php';

// This run's own database is a fresh IAM, built from every migration, 027 included.
bootTests();

const DROP_MIGRATION = '027_drop_access_and_old_audit_log.sql';
const BEFORE_DROP = '026_confirmation_links_and_google_tokens.sql';
const PASSWORD = 'correct horse';
const SURVEY_FRONTEND = 'http://localhost:4700';
const SURVEY_CALLBACK = 'http://localhost:4700/auth/callback';
const DENIED = 'This account cannot use Surveys';

// ── A database of its own ────────────────────────────────────────────────────

function accountIn(PDO $pdo, string $username): int
{
    $insert = $pdo->prepare('INSERT INTO users (username, email, password, email_confirmed) VALUES (?, ?, ?, TRUE) RETURNING id');
    $insert->execute([$username, "{$username}@example.test", password_hash(PASSWORD, PASSWORD_DEFAULT)]);

    return (int) $insert->fetchColumn();
}

function grantIn(PDO $pdo, int $userId, string $clientId, string $role): void
{
    $pdo->prepare(
        'INSERT INTO user_roles (user_id, role_id)
         SELECT ?, r.id FROM application_roles r JOIN applications a ON a.id = r.application_id WHERE a.client_id = ? AND r.name = ?
         ON CONFLICT DO NOTHING',
    )->execute([$userId, $clientId, $role]);
}

/** An application of the owner's own, made in the admin panel before 010 - members only -, with its roles. */
function membersOnlyBefore010(PDO $pdo, string $clientId, array $roles): void
{
    $insert = $pdo->prepare("INSERT INTO applications (client_id, name, type, access, sort_order, system) VALUES (?, ?, 'public', 'members', 70, FALSE) RETURNING id");
    $insert->execute([$clientId, ucfirst($clientId)]);
    $id = (int) $insert->fetchColumn();
    foreach ($roles as $name => $description) {
        $pdo->prepare('INSERT INTO application_roles (application_id, name, description) VALUES (?, ?, ?)')->execute([$id, $name, $description]);
    }
}

function tableThere(PDO $pdo, string $table): bool
{
    $statement = $pdo->prepare('SELECT to_regclass(?) IS NOT NULL');
    $statement->execute([$table]);

    return asBool($statement->fetchColumn());
}

function columnThere(PDO $pdo, string $table, string $column): bool
{
    $statement = $pdo->prepare('SELECT EXISTS (SELECT 1 FROM pg_attribute WHERE attrelid = to_regclass(?) AND attname = ? AND NOT attisdropped)');
    $statement->execute([$table, $column]);

    return asBool($statement->fetchColumn());
}

/** Every application as the database has it, every column but `access`. */
function applicationsBesidesAccess(PDO $pdo): array
{
    $rows = $pdo->query('SELECT * FROM applications ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);

    return array_map(static function (array $row): array {
        unset($row['access']);

        return $row;
    }, $rows);
}

/** A table's entries, in the IAM's columns, as text. */
function entriesOf(PDO $pdo, string $table): array
{
    return $pdo->query("SELECT id, created_at::text AS created_at, actor_id, action, target_type, target_id, details::text AS details, ip FROM {$table} ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * What the code itself answers - applicationAccessRefusal(), every way to a
 * site's check - for every account and every application there is, on the
 * database the app runs on: "username client_id" => 'let in', or the refusal's
 * code.
 */
function decisions(): array
{
    $pdo = iamDb();
    $answers = [];
    foreach ($pdo->query('SELECT * FROM users ORDER BY username')->fetchAll() as $user) {
        foreach ($pdo->query('SELECT client_id FROM applications ORDER BY client_id')->fetchAll(PDO::FETCH_COLUMN) as $clientId) {
            $answers["{$user['username']} {$clientId}"] = applicationAccessRefusal(applicationByClientId($clientId), $user)['code'] ?? 'let in';
        }
    }

    return $answers;
}

/** Points the app at another of this run's databases, and lets nothing remembered of the old one stand. */
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
    same(200, $browser->signIn($email, PASSWORD)->status, "{$email} signs in to the IAM");
    $start = startSignIn($browser);
    $code = queryOf($start['location'])['code'] ?? null;
    check($code !== null, "{$email} gets a code straight away: " . $start['location']);
    $tokens = $browser->request('POST', '/api/oidc/token', [
        'form' => ['grant_type' => 'authorization_code', 'code' => $code, 'redirect_uri' => SURVEY_CALLBACK, 'client_id' => 'survey', 'code_verifier' => $start['verifier']],
        'headers' => ['Origin' => SURVEY_FRONTEND],
    ]);
    same(200, $tokens->status, 'code exchange: ' . $tokens->body);

    return $tokens->json();
}

/** A browser signed in at the IAM whose sign-in to the survey site is refused at the accept, saying why. */
function refusedAtSurvey(Browser $browser, string $email, string $why): void
{
    same(200, $browser->signIn($email, PASSWORD)->status, "{$email} signs in to the IAM");
    $start = startSignIn($browser);
    $request = queryOf($start['location'])['request'] ?? null;
    check($request !== null && !isset(queryOf($start['location'])['code']), "{$why}: no code - the sign-in page: " . $start['location']);
    $accept = $browser->post("/api/login/request/{$request}/accept");
    same([403, 'access_denied', DENIED], [$accept->status, $accept->code(), $accept->json()['error'] ?? null], "{$why}: accept refuses");
}

// ── 027 itself ───────────────────────────────────────────────────────────────

test('a fresh IAM has run 027: no access column and no audit_log_before_018 - 010\'s columns and triggers, and the library\'s audit table, there', function () {
    $pdo = iamDb();
    check(in_array(DROP_MIGRATION, appliedMigrations($pdo), true), '027 recorded');
    same([false, false], [columnThere($pdo, 'applications', 'access'), tableThere($pdo, 'audit_log_before_018')], 'the column and the copy gone');
    same([true, true], [columnThere($pdo, 'application_roles', 'may_sign_in'), columnThere($pdo, 'applications', 'login_required')], '010\'s columns there');
    same(2, (int) $pdo->query("SELECT count(*) FROM pg_trigger WHERE tgname IN ('trg_users_member_everywhere', 'trg_applications_member_role')")->fetchColumn(), 'and its triggers');
    same([true, false], [tableThere($pdo, 'anotoki_audit_log'), tableThere($pdo, 'audit_log')], 'the library\'s audit table, and the old name gone');
});

test(
    'an IAM through 026 as its owner left it, from before 010 and 018: 027 drops the two - every account let in, or refused, by the code itself exactly as before; every audit entry where it was',
    function () {
        $main = testEnvironment()['db'];
        $pdo = buildIamThrough('_installed', '009_admin_panel.sql');

        // What an owner had before 010: an administrator, an editor, a site made members-only by hand with its ADMIN
        // given, an application of their own with a role of its own, a MEMBER somebody held in a members-only one -
        // and a history in the IAM's own audit log.
        $admin = accountIn($pdo, 'admin');
        grantIn($pdo, $admin, 'iam', 'ADMIN');
        $editor = accountIn($pdo, 'editor');
        grantIn($pdo, $editor, 'survey', 'EDITOR');
        $pianist = accountIn($pdo, 'pianist');
        $reader = accountIn($pdo, 'reader');
        $guest = accountIn($pdo, 'guest');
        accountIn($pdo, 'nobody');
        $pdo->exec("UPDATE applications SET access = 'members' WHERE client_id = 'piano-academy'");
        grantIn($pdo, $pianist, 'piano-academy', 'ADMIN');
        membersOnlyBefore010($pdo, 'notes', ['ADMIN' => 'Runs the notes', 'READER' => 'Reads them']);
        grantIn($pdo, $reader, 'notes', 'READER');
        membersOnlyBefore010($pdo, 'invited', ['MEMBER' => 'Invited people']);
        grantIn($pdo, $guest, 'invited', 'MEMBER');
        $insert = $pdo->prepare('INSERT INTO audit_log (actor_id, action, target_type, target_id, details, ip) VALUES (?, ?, ?, ?, CAST(? AS jsonb), ?)');
        $insert->execute([$admin, 'user.roles_set', 'user', (string) $pianist, '{"after": [{"client_id": "piano-academy", "role": "ADMIN"}]}', '203.0.113.9']);
        $insert->execute([$reader, 'account.username_changed', 'user', (string) $reader, '{"from": "Čitateľ", "to": "reader"}', '2001:db8::1']);
        $insert->execute([null, 'legacy.imported', null, null, '{"accounts": 6}', null]);
        $history = entriesOf($pdo, 'audit_log');

        // Then 010 to 026, as the Migrations page brought them; and since, somebody's MEMBER taken from the survey site.
        $result = applyMigrationsThrough($pdo, BEFORE_DROP);
        same(null, $result['failed'], 'built through 026 (' . ($result['error'] ?? '') . ')');
        $pdo->prepare(
            "DELETE FROM user_roles WHERE user_id = ? AND role_id = (SELECT r.id FROM application_roles r JOIN applications a ON a.id = r.application_id
                    WHERE a.client_id = 'survey' AND r.name = 'MEMBER')",
        )->execute([$reader]);
        same([true, true], [columnThere($pdo, 'applications', 'access'), tableThere($pdo, 'audit_log_before_018')], 'the two leftovers there, before 027');
        same($history, entriesOf($pdo, 'audit_log_before_018'), '018 kept the old table as it was');
        same($history, entriesOf($pdo, 'anotoki_audit_log'), 'and moved every entry into the library\'s');

        useIamDatabase($main . '_installed');
        try {
            same(DROP_MIGRATION, pendingMigrations(iamDb())[0] ?? null, '027 waits, first');
            $before = decisions();
            foreach (
                [
                    'pianist piano-academy' => 'let in',
                    'editor piano-academy' => 'access_denied',
                    'reader notes' => 'let in',
                    'nobody notes' => 'access_denied',
                    'guest invited' => 'let in',
                    'reader invited' => 'access_denied',
                    'reader survey' => 'access_denied',
                    'nobody survey' => 'let in',
                    'editor survey' => 'let in',
                    'nobody iam' => 'let in',
                ]
                as $pair => $answer
            ) {
                same($answer, $before[$pair] ?? null, "before 027: {$pair}");
            }
            $applications = applicationsBesidesAccess($pdo);

            $result = applyMigrationsThrough($pdo, DROP_MIGRATION);
            same([[DROP_MIGRATION], null], [$result['applied'], $result['failed']], '027 applied, its gates passed (' . ($result['error'] ?? '') . ')');
            same([false, false], [columnThere($pdo, 'applications', 'access'), tableThere($pdo, 'audit_log_before_018')], 'the column and the copy gone');

            same($before, decisions(), 'every account let into every application, or refused, by the code itself exactly as before');
            same($applications, applicationsBesidesAccess($pdo), 'every application otherwise as it was, column by column');
            same($history, entriesOf($pdo, 'anotoki_audit_log'), 'every audit entry where it was, in the library\'s table');
        } finally {
            useIamDatabase($main);
        }
    },
);

test(
    'the window: this build on an IAM through 026, 027 waiting - a site\'s sign-in, its refusal, a deletion (the copy 018 kept scrubbed too), the admin panel\'s application pages and the public contract answer; Apply brings 027 after a backup',
    function () {
        $main = testEnvironment()['db'];
        $pdo = buildIamThrough('_window', BEFORE_DROP);
        useIamDatabase($main . '_window');

        try {
            $pdo = iamDb();
            $adminId = makeUser('admin', 'admin@example.test', PASSWORD);
            grantRole($adminId, 'iam', 'ADMIN');
            makeUser('person', 'person@example.test', PASSWORD);
            $blockedId = makeUser('blocked', 'blocked@example.test', PASSWORD);
            $pdo->prepare(
                "DELETE FROM user_roles WHERE user_id = ? AND role_id = (SELECT r.id FROM application_roles r JOIN applications a ON a.id = r.application_id
                        WHERE a.client_id = 'survey' AND r.name = 'MEMBER')",
            )->execute([$blockedId]);
            $goneId = makeUser('gone', 'gone@example.test', PASSWORD);
            same([true, true], [columnThere($pdo, 'applications', 'access'), tableThere($pdo, 'audit_log_before_018')], 'the two leftovers there');

            $tokens = intoSurvey(new Browser('10.27.0.1'), 'person@example.test');
            same(['MEMBER'], verifyLikeAnApp(new Browser(), $tokens['access_token'], 'survey')['roles'] ?? null, 'a site\'s sign-in, with the MEMBER every account holds');
            refusedAtSurvey(new Browser('10.27.0.2'), 'blocked@example.test', 'MEMBER taken from somebody');

            $admin = new Browser('10.27.0.3');
            same(200, $admin->signIn('admin@example.test', PASSWORD)->status, 'the administrator signs in to press Apply');
            $list = $admin->get('/api/admin/applications');
            same([200, true], [$list->status, array_column($list->json(), 'login_required', 'client_id')['survey'] ?? null], 'the admin panel\'s application pages answer');
            same(['survey'], $admin->get("/api/admin/users/{$blockedId}")->json()['blocked_in'] ?? null, 'and say who is blocked where');
            same([200, true], [($r = (new Browser())->get('/api/public/applications/survey'))->status, $r->json()['login_required'] ?? null], 'the sites\' public contract answers');

            // A deletion falling due meanwhile: done, the library's table scrubbed - and the copy 018 kept, still
            // there until the Apply, by the same rules: the Apply's backup is made before 027 drops it, and must not
            // keep the person (the security review of 2026-10-25). The same history in the copy: by the person, about
            // them by nobody and by an administrator, their token.
            audit(null, $goneId, 'account.username_changed', 'user', $goneId, ['from' => 'gone', 'to' => 'gone2']);
            $copy = $pdo->prepare('INSERT INTO audit_log_before_018 (actor_id, action, target_type, target_id, details, ip) VALUES (?, ?, ?, ?, CAST(? AS jsonb), ?)');
            $copy->execute([$goneId, 'account.username_changed', 'user', (string) $goneId, '{"from": "gone", "to": "gone2"}', '192.0.2.61']);
            $copy->execute([null, 'account.created', 'user', (string) $goneId, '{"username": "gone", "email": "gone@example.test"}', '192.0.2.62']);
            $copy->execute([$adminId, 'user.updated', 'user', (string) $goneId, '{"username": "gone", "kept": "yes"}', '198.51.100.9']);
            $copy->execute([$goneId, 'account.token_created', 'refresh_token', '17', '{"name": "my script", "days": 30}', '192.0.2.63']);
            $pdo->prepare("UPDATE users SET deletion_requested_at = CURRENT_TIMESTAMP - INTERVAL '31 days', deletion_due_at = CURRENT_TIMESTAMP - INTERVAL '1 day' WHERE id = ?")->execute([$goneId]);
            same(1, anonymizeDueAccounts($pdo), 'the account due is deleted');
            same(
                '{}',
                $pdo->query("SELECT details::text FROM anotoki_audit_log WHERE action = 'account.username_changed' AND actor_id = {$goneId} ORDER BY id DESC LIMIT 1")->fetchColumn(),
                'its names out of the audit log',
            );
            $kept = $pdo->query("SELECT action, details, ip FROM audit_log_before_018 WHERE target_id IN ('{$goneId}', '17') ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
            same(
                [['account.username_changed', [], null], ['account.created', [], null], ['user.updated', ['kept' => 'yes'], '198.51.100.9'], ['account.token_created', ['days' => 30], null]],
                array_map(static fn(array $row): array => [$row['action'], (array) json_decode((string) $row['details'], true), $row['ip']], $kept),
                'and out of the copy 018 kept, by the same rules - an administrator\'s address on an administrator\'s action stays',
            );

            $applied = $admin->post('/api/admin/migrations/apply');
            same(
                [200, DROP_MIGRATION, null],
                [$applied->status, migrationNames($applied->json()['applied'] ?? [])[0] ?? null, $applied->json()['failed'] ?? null],
                'Apply, 027 first: ' . $applied->body,
            );
            check(
                preg_match('/^A backup of the database as it was is \S+-before-migration-[0-9a-f]{32}\.$/', (string) ($applied->json()['note'] ?? '')) === 1,
                'a backup made first: ' . ($applied->json()['note'] ?? ''),
            );
            same([false, false], [columnThere($pdo, 'applications', 'access'), tableThere($pdo, 'audit_log_before_018')], 'the two gone');

            same(200, refreshAt($tokens['refresh_token'])->status, 'the refresh token from before keeps working');
            refusedAtSurvey(new Browser('10.27.0.4'), 'blocked@example.test', 'after 027 too');
            same(200, $admin->get('/api/admin/applications')->status, 'the application pages answer as before');
        } finally {
            useIamDatabase($main);
        }
    },
);

test('a 027 whose gate fails - a database without 010\'s may_sign_in, or without 018\'s move - drops nothing, names what is missing, and runs once the cause is gone', function () {
    $pdo = buildIamThrough('_spoilt', BEFORE_DROP);
    $untouched = static function (string $why) use ($pdo): void {
        same([true, true], [columnThere($pdo, 'applications', 'access'), tableThere($pdo, 'audit_log_before_018')], "{$why}: nothing dropped");
        same(DROP_MIGRATION, pendingMigrations($pdo)[0] ?? null, "{$why}: 027 still waits");
    };

    $pdo->exec('ALTER TABLE application_roles RENAME COLUMN may_sign_in TO may_sign_in_elsewhere');
    $result = applyMigrationsThrough($pdo, DROP_MIGRATION);
    same([[], DROP_MIGRATION], [$result['applied'], $result['failed']], 'without 010\'s column: 027 fails');
    check(str_contains((string) $result['error'], 'application_roles.may_sign_in (010)'), 'naming it: ' . $result['error']);
    $untouched('without 010\'s column');
    $pdo->exec('ALTER TABLE application_roles RENAME COLUMN may_sign_in_elsewhere TO may_sign_in');

    $pdo->exec('ALTER TABLE audit_log_before_018 RENAME TO audit_log');
    $result = applyMigrationsThrough($pdo, DROP_MIGRATION);
    same(DROP_MIGRATION, $result['failed'], 'with the old audit table under its old name: 027 fails');
    check(str_contains((string) $result['error'], 'the old name is still there'), 'naming it: ' . $result['error']);
    same([true, false], [tableThere($pdo, 'audit_log'), tableThere($pdo, 'audit_log_before_018')], 'nothing dropped');
    $pdo->exec('ALTER TABLE audit_log RENAME TO audit_log_before_018');
    $untouched('the old name back');

    $result = applyMigrationsThrough($pdo, DROP_MIGRATION);
    same([[DROP_MIGRATION], null], [$result['applied'], $result['failed']], 'the causes gone, it runs (' . ($result['error'] ?? '') . ')');
    same([false, false], [columnThere($pdo, 'applications', 'access'), tableThere($pdo, 'audit_log_before_018')], 'and drops the two');
});

finishTests();
