<?php

/**
 * Migration 018, "the audit log, into the anotoki library's table": the
 * admin panel's tools are the family's library's (anotoki-lib 0.4), and the
 * audit log is kept in its table, anotoki_audit_log - made by the library's
 * own set, anotoki_admin, which runs before the IAM's files. 018 moves every
 * entry of the IAM's audit_log there with its id, proves it in its gate, and
 * keeps the old table, unread, as audit_log_before_018.
 *
 * 018 arrives at installed IAMs - built from the IAM's files through 017 and
 * the library's translations set's 001 and 002, with no admin set - so it is
 * applied to one shaped like that, with a history of every kind of entry. And
 * the window: a build goes up before anybody presses Apply, and the IAM keeps
 * answering meanwhile (blockWhilePending false): every way in works, audit()
 * fails no request - its entries wait in a file - and Apply brings the library's
 * set, 018 and the waiting entries in, with a backup of the database as it was
 * made first.
 */

declare(strict_types=1);

require __DIR__ . '/lib.php';

use Anotoki\Lib\Migrations\MigrationSet;
use Anotoki\Lib\Migrations\Migrator;
use Anotoki\Lib\Translations\Schema as TranslationsSchema;

// This run's own database is a fresh IAM, built from every migration: the library's admin set, the IAM's 001-018, the library's translations set.
bootTests();

const AUDIT_MIGRATION = '018_audit_log_into_the_library.sql';
const ADMIN_SET_FILES = ['anotoki_admin/001_audit_log.sql', 'anotoki_admin/002_feedback.sql'];
const PASSWORD = 'correct horse';
const SITE_CALLBACK = 'http://localhost:4200/auth/callback';
const SITE_ORIGIN = 'http://localhost:4200';

/** Points the app at another of this run's databases, and lets nothing remembered of the old one stand. */
function useDatabase(string $name): void
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

/**
 * An IAM as every installed one is before this release: the IAM's own files
 * through 017, then the library's translations set's 001 and 002 - all it had
 * - and no admin set. Built with the sets as the IAM listed them then.
 */
function buildIamAsInstalled(string $suffix): PDO
{
    $name = throwawayDatabase(testEnvironment()['db'] . $suffix);
    ensureDatabase(testServer(), $name);
    $pdo = connectDatabase(testServer(), $name, false);

    $own = (new Migrator($pdo, [iamMigrationSet()], ['ready' => static fn(MigrationSet $set, string $file): bool => strcmp($file, '018') < 0]))->apply();
    same(null, $own['failed'], 'the IAM\'s files through 017: ' . ($own['error'] ?? ''));
    $words = (new Migrator($pdo, [TranslationsSchema::migrationSet()], ['ready' => static fn(MigrationSet $set, string $file): bool
        => in_array($file, ['001_languages_and_strings.sql', '002_library_words.sql'], true)]))->apply();
    same(null, $words['failed'], 'the library\'s translations set\'s 001 and 002: ' . ($words['error'] ?? ''));
    same(['017_strings_through_the_library.sql', 'anotoki_translations/001_languages_and_strings.sql', 'anotoki_translations/002_library_words.sql'],
        array_slice($pdo->query('SELECT filename FROM migrations ORDER BY id')->fetchAll(PDO::FETCH_COLUMN), -3), 'as an installed IAM records them');

    return $pdo;
}

/**
 * A history an installed IAM may have: entries by people and by nobody, with
 * and without an address (v4 and v6), details of every shape - an object with
 * Slovak and an emoji, a list, a nested one, none, a null inside -, the longest
 * target the old table took, times to the microsecond, ids with gaps; and its
 * sequence as those ids left it.
 *
 * @return list<array> the rows, as audit_log has them
 */
function writeHistory(PDO $pdo): array
{
    $owner = (int) $pdo->query("INSERT INTO users (username, email, email_confirmed) VALUES ('owner', 'owner@example.test', TRUE) RETURNING id")->fetchColumn();
    $person = (int) $pdo->query("INSERT INTO users (username, email, email_confirmed) VALUES ('person', 'person@example.test', TRUE) RETURNING id")->fetchColumn();
    $insert = $pdo->prepare('INSERT INTO audit_log (id, created_at, actor_id, action, target_type, target_id, details, ip) VALUES (?, ?, ?, ?, ?, ?, CAST(? AS jsonb), ?)');
    foreach ([
        [1, '2026-09-27 10:15:00+00', null, 'legacy.imported', null, null, '{"accounts": 41, "identities": 3}', null],
        [2, '2026-09-28 08:00:00.123456+00', $owner, 'user.roles_set', 'user', (string) $person, '{"before": [{"client_id": "genshin", "role": "MEMBER"}], "after": []}', '203.0.113.9'],
        [5, '2026-10-01 12:34:56.5+00', $person, 'account.username_changed', 'user', (string) $person, '{"from": "Jožko", "to": "Jožko 🎹"}', '2001:db8::1'],
        [6, '2026-10-02 00:00:00+00', $owner, 'user.updated', 'user', (string) $person, '["username", "email"]', '203.0.113.9'],
        [7, '2026-10-03 07:07:07+00', $owner, 'settings.changed', 'settings', null, '{"registration_approval": "true", "nested": {"a": [1, 2, {"b": null}]}}', '203.0.113.9'],
        [9, '2026-10-04 09:09:09+00', null, 'user.password_reset_cli', 'user', (string) $person, '{"clear_2fa": false}', null],
        [10, '2026-10-05 10:10:10+00', $owner, 'keys.rotated', 'key', str_repeat('k', 64), null, '198.51.100.7'],
        [1000, '2026-10-10 23:59:59.999999+00', $person, 'account.token_created', 'refresh_token', '17', '{"name": "Môj skript", "days": 30}', '2001:db8::1'],
    ] as $row) {
        $insert->execute($row);
    }
    $pdo->exec("SELECT setval(pg_get_serial_sequence('audit_log', 'id'), 1000)");

    return entriesOf($pdo, 'audit_log');
}

/** A table's entries, in the IAM's columns, as text: what 018 must move exactly. */
function entriesOf(PDO $pdo, string $table): array
{
    return $pdo->query("SELECT id, created_at::text AS created_at, actor_id, action, target_type, target_id, details::text AS details, ip FROM {$table} ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
}

function tableThere(PDO $pdo, string $table): bool
{
    return asBool($pdo->query("SELECT to_regclass('{$table}') IS NOT NULL")->fetchColumn());
}

/** The audit entries waiting in the file for 018: their actions, in the order they came. */
function waitingActions(): array
{
    $file = iamStorageDir() . '/logs/audit-pending.jsonl';
    $actions = [];
    foreach (is_file($file) ? (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: []) : [] as $line) {
        $actions[] = json_decode($line, true)['action'] ?? null;
    }

    return $actions;
}

/** A browser's sign-in to the genshin site, through to its tokens: the token answer. */
function signInToTheSite(Browser $browser, string $email): TestResponse
{
    [$verifier, $challenge] = pkce();
    $start = $browser->get('/api/oidc/authorize?' . http_build_query([
        'response_type' => 'code', 'client_id' => 'genshin', 'redirect_uri' => SITE_CALLBACK, 'scope' => 'openid profile email',
        'state' => 's', 'nonce' => 'n', 'code_challenge' => $challenge, 'code_challenge_method' => 'S256',
    ]));
    $request = queryOf($start->location())['request'] ?? null;
    check($request !== null, 'authorize sends an anonymous browser to the sign-in page: ' . $start->location());
    same(200, $browser->signIn($email, PASSWORD)->status, 'the sign-in');
    $accept = $browser->post("/api/login/request/{$request}/accept");
    same(200, $accept->status, 'accept: ' . $accept->body);

    return $browser->request('POST', '/api/oidc/token', [
        'form' => [
            'grant_type' => 'authorization_code', 'code' => queryOf($accept->json()['redirect'])['code'] ?? '',
            'redirect_uri' => SITE_CALLBACK, 'client_id' => 'genshin', 'code_verifier' => $verifier,
        ],
        'headers' => ['Origin' => SITE_ORIGIN],
    ]);
}

// ─────────────────────────────────────────────────────────────────────────────

test('a fresh IAM: the library\'s admin set first, 018 after 017, every entry in the library\'s table - and the old one kept, empty, under its new name', function () {
    $pdo = iamDb();
    same(['anotoki_admin', 'iam', 'anotoki_translations'], array_map(static fn(MigrationSet $set): string => $set->name, iamMigrationSets()), 'the family\'s order: the admin set, the IAM\'s, the translations set');
    $applied = appliedMigrations($pdo);
    same(ADMIN_SET_FILES, array_slice($applied, 0, 2), 'the library\'s admin set the first applied');
    same(array_search('017_strings_through_the_library.sql', $applied, true) + 1, array_search(AUDIT_MIGRATION, $applied, true), '018 right after 017');
    same(ADMIN_SET_FILES, $pdo->query("SELECT filename FROM migrations WHERE filename LIKE 'anotoki_admin/%' ORDER BY id")->fetchAll(PDO::FETCH_COLUMN), 'recorded as set/name in the IAM\'s own table');

    same([false, true, true], [tableThere($pdo, 'audit_log'), tableThere($pdo, 'audit_log_before_018'), tableThere($pdo, 'anotoki_audit_log')], 'the old name gone, kept under the new one; the library\'s table there');
    same(0, (int) $pdo->query('SELECT count(*) FROM audit_log_before_018')->fetchColumn(), 'nothing in the kept table of a fresh IAM');
    same(true, auditMovedToLibrary($pdo), 'so audit() writes into the library\'s table');

    $id = makeUser('fresh', 'fresh@example.test');
    $request = (new \Slim\Psr7\Factory\ServerRequestFactory())->createServerRequest('POST', TEST_ISSUER . '/api/account', ['REMOTE_ADDR' => '192.0.2.44']);
    audit($request, $id, 'account.username_changed', 'user', $id, ['from' => 'a', 'to' => 'b']);
    $row = $pdo->query("SELECT actor_id, action, target_type, target_id, details, ip, actor_session, scope_type, scope_id, created_at > CURRENT_TIMESTAMP - INTERVAL '1 minute' AS recent FROM anotoki_audit_log ORDER BY id DESC LIMIT 1")->fetch();
    $details = (array) json_decode((string) $row['details'], true);
    ksort($details);
    same([$id, 'account.username_changed', 'user', (string) $id, ['from' => 'a', 'to' => 'b'], '192.0.2.44', null, null, null, true],
        [(int) $row['actor_id'], $row['action'], $row['target_type'], $row['target_id'], $details, $row['ip'], $row['actor_session'], $row['scope_type'], $row['scope_id'], asBool($row['recent'])],
        'what it always kept: who, what, to what, the details, the address, the time - and nothing the IAM never wrote');
    same([], waitingActions(), 'nothing waits in the file');
});

test('an installed IAM with a history: 018 moves every entry exactly, its id kept, the next one numbered after them, the old table kept as it was', function () {
    $pdo = buildIamAsInstalled('_installed');
    $history = writeHistory($pdo);
    same(8, count($history), 'the history written');
    same([...ADMIN_SET_FILES, AUDIT_MIGRATION, ...array_slice(array_values(array_filter(migrationFiles(), static fn(string $file): bool => str_starts_with($file, 'anotoki_translations/'))), 2)],
        pendingMigrations($pdo), 'what an upload brings an installed IAM: the library\'s admin set, 018, the translations set\'s new files');

    $result = applyPendingMigrations($pdo);
    same([[], null], [pendingMigrations($pdo), $result['failed']], 'every file applied, every gate passed (' . ($result['error'] ?? '') . ')');
    same(AUDIT_MIGRATION, $result['applied'][2] ?? null, '018 after the admin set');

    same($history, entriesOf($pdo, 'anotoki_audit_log'), 'every entry in the library\'s table exactly as it was: id, time, who, what, to what, details, address');
    same(0, (int) $pdo->query('SELECT count(*) FROM anotoki_audit_log WHERE actor_session IS NOT NULL OR scope_type IS NOT NULL OR scope_id IS NOT NULL')->fetchColumn(), 'and nothing the IAM never wrote');
    same([false, $history], [tableThere($pdo, 'audit_log'), entriesOf($pdo, 'audit_log_before_018')], 'the old table kept, as it was, under its new name');

    // The next entry: numbered after the highest, written where the app now writes.
    $main = testEnvironment()['db'];
    useDatabase($main . '_installed');
    try {
        audit(null, null, 'keys.removed', 'key', 'k1');
        same([1001, 'keys.removed'], array_values(iamDb()->query('SELECT id, action FROM anotoki_audit_log ORDER BY id DESC LIMIT 1')->fetch(PDO::FETCH_NUM)), 'the next entry is 1001');
        same(8, (int) iamDb()->query('SELECT count(*) FROM audit_log_before_018')->fetchColumn(), 'and the kept table is never written');
    } finally {
        useDatabase($main);
    }
});

test('a 018 whose gate fails moves nothing and keeps nothing - every entry where it was, 018 waiting - and runs once the cause is gone', function () {
    $pdo = buildIamAsInstalled('_spoilt');
    $history = writeHistory($pdo);
    $result = applyMigrationsThrough($pdo, ADMIN_SET_FILES[1]);
    same([ADMIN_SET_FILES, null], [$result['applied'], $result['failed']], 'the library\'s admin set applied first');

    $untouched = static function (string $why) use ($pdo, $history): void {
        same([true, false], [tableThere($pdo, 'audit_log'), tableThere($pdo, 'audit_log_before_018')], "$why: the old table where it was");
        same($history, entriesOf($pdo, 'audit_log'), "$why: every entry in it as it was");
        same(AUDIT_MIGRATION, pendingMigrations($pdo)[0] ?? null, "$why: 018 still waits");
    };

    // Something on the host spoils the entries as they arrive: the gate must see it.
    $pdo->exec("CREATE FUNCTION test_018_spoil() RETURNS trigger LANGUAGE plpgsql AS \$\$ BEGIN NEW.details := '{\"spoilt\": true}'; RETURN NEW; END \$\$");
    $pdo->exec('CREATE TRIGGER test_018_spoil BEFORE INSERT ON anotoki_audit_log FOR EACH ROW EXECUTE FUNCTION test_018_spoil()');
    $result = applyPendingMigrations($pdo);
    same([[], AUDIT_MIGRATION], [$result['applied'], $result['failed']], '018 fails, and nothing after it runs');
    check(str_contains((string) $result['error'], 'audit entries that did not arrive as they were'), 'its gate says why: ' . $result['error']);
    same(0, (int) $pdo->query('SELECT count(*) FROM anotoki_audit_log')->fetchColumn(), 'nothing of it stayed in the library\'s table');
    $untouched('a spoilt move');
    $pdo->exec('DROP TRIGGER test_018_spoil ON anotoki_audit_log');
    $pdo->exec('DROP FUNCTION test_018_spoil()');

    // An entry in the library's table already: the ids could not be kept - said, before anything moves.
    $pdo->exec("INSERT INTO anotoki_audit_log (action) VALUES ('stray.entry')");
    $result = applyPendingMigrations($pdo);
    same(AUDIT_MIGRATION, $result['failed'], 'refused with the library\'s table not empty');
    check(str_contains((string) $result['error'], 'holds entries already'), 'and says so: ' . $result['error']);
    $untouched('the library\'s table not empty');
    $pdo->exec('DELETE FROM anotoki_audit_log');

    // An action written by hand the library does not take: named.
    $pdo->exec("INSERT INTO audit_log (id, action) VALUES (2000, 'Hand Written')");
    $result = applyPendingMigrations($pdo);
    same(AUDIT_MIGRATION, $result['failed'], 'refused with an action the library\'s table does not take');
    check(str_contains((string) $result['error'], 'Hand Written'), 'and names it: ' . $result['error']);
    $pdo->exec('DELETE FROM audit_log WHERE id = 2000');
    $untouched('an action the library does not take');

    $result = applyPendingMigrations($pdo);
    same([null, AUDIT_MIGRATION], [$result['failed'], $result['applied'][0] ?? null], 'the causes gone, the next Apply runs it (' . ($result['error'] ?? '') . ')');
    same($history, entriesOf($pdo, 'anotoki_audit_log'), 'and every entry arrives as it was');
});

test('the window: an installed IAM, the new code, nothing applied - every way in works, audit() fails no request, its entries wait, and Apply brings them in after a backup', function () {
    $main = testEnvironment()['db'];
    $pdo = buildIamAsInstalled('_window');
    $history = writeHistory($pdo);
    useDatabase($main . '_window');

    try {
        $pdo = iamDb();
        $adminId = makeUser('admin', 'admin@example.test', PASSWORD);
        grantRole($adminId, 'iam', 'ADMIN');
        $personId = makeUser('somebody', 'somebody@example.test', PASSWORD);
        @unlink(iamStorageDir() . '/logs/audit-pending.jsonl');

        $visitor = new Browser('10.41.0.1');
        same(['state' => 'update_pending', 'installed' => false], $visitor->get('/api/site-status')->json(), 'an update waits - and closes nothing');

        // Every way in, each of them audited or not, none of them failing.
        $person = new Browser('10.41.0.2');
        same(200, $person->signIn('somebody@example.test', PASSWORD)->status, 'a password');
        same([200, 'somebody'], [($r = $person->get('/api/account'))->status, $r->json()['username'] ?? null], 'the account page');
        same(200, $person->put('/api/account/profile', ['username' => 'somebody else'])->status, 'a change of name - audited');
        $tokens = signInToTheSite(new Browser('10.41.0.3'), 'somebody@example.test');
        same(200, $tokens->status, 'a site\'s sign-in, through to its tokens: ' . $tokens->body);
        same(['MEMBER'], verifyLikeAnApp(new Browser(), (string) ($tokens->json()['access_token'] ?? ''), 'genshin')['roles'] ?? null, 'with a token the site accepts');
        same(200, (new Browser('10.41.0.4'))->post('/api/login/code', ['email' => 'somebody@example.test'])->status, 'an emailed code');
        $registered = (new Browser('10.41.0.5'))->post('/api/register', ['username' => 'Nováčik', 'email' => 'novacik@example.test', 'password' => 'a long password', 'language' => 'sk']);
        same(201, $registered->status, 'a registration - audited: ' . $registered->body);
        same(200, (new Browser('10.41.0.6'))->post('/api/password/forgot', ['email' => 'novacik@example.test'])->status, 'a reset asked for');

        $admin = new Browser('10.41.0.7');
        same(200, $admin->signIn('admin@example.test', PASSWORD)->status, 'the administrator signs in to press Apply');
        same(200, $admin->put("/api/admin/users/{$personId}", ['force_password_change' => false])->status, 'an administrator\'s change - audited');

        // Where those entries went: not into the old table, nowhere in the database - into the file, each.
        same($history, entriesOf($pdo, 'audit_log'), 'the old table is not written while 018 waits');
        same(false, tableThere($pdo, 'anotoki_audit_log'), 'the library\'s table is not there yet');
        $waiting = waitingActions();
        foreach (['account.username_changed', 'account.created', 'user.updated'] as $action) {
            check(in_array($action, $waiting, true), "$action waits in the file: " . json_encode($waiting));
        }

        // The admin panel's tools meanwhile: the Audit page waits for the update; the error log and the backups need no table.
        same([503, 'feature_unavailable', '60'], [($r = $admin->get('/api/admin/audit'))->status, $r->code(), $r->header('Retry-After')], 'the Audit page: the update first');
        same(200, $admin->get('/api/admin/errors')->status, 'the Error log');
        $made = $admin->post('/api/admin/backups', ['password' => PASSWORD, 'description' => 'before the update, by hand']);
        same([201, true], [$made->status, $made->json()['backup']['encrypted'] ?? null], 'a backup, made by hand while the update waits: ' . substr($made->body, 0, 300));

        // An account deleted in the window: the old table is what the anonymization scrubs then.
        $pdo->prepare("INSERT INTO audit_log (id, actor_id, action, target_type, target_id, details, ip) VALUES (3000, ?, 'account.username_changed', 'user', ?, '{\"from\": \"x\", \"to\": \"y\"}', '192.0.2.99')")
            ->execute([$personId, (string) $personId]);
        $pdo->prepare("UPDATE users SET deletion_requested_at = CURRENT_TIMESTAMP - INTERVAL '31 days', deletion_due_at = CURRENT_TIMESTAMP - INTERVAL '1 day' WHERE id = ?")->execute([$personId]);
        same(1, anonymizeDueAccounts($pdo), 'the account due is deleted while 018 waits');
        same([null, []], [($r = $pdo->query('SELECT ip, details FROM audit_log WHERE id = 3000')->fetch())['ip'], json_decode((string) $r['details'], true)], 'its entries in the old table scrubbed by the IAM\'s rules');
        $history = entriesOf($pdo, 'audit_log');
        $waitingBefore = waitingActions();
        check(in_array('account.anonymized', $waitingBefore, true), 'and its account.anonymized waits with the rest');

        // Apply: a backup of the database as it was, the library's set, 018, the entries that waited.
        $pending = migrationNames($admin->get('/api/admin/migrations')->json()['pending'] ?? []);
        same([...ADMIN_SET_FILES, AUDIT_MIGRATION], array_slice($pending, 0, 3), 'what Apply will bring: the admin set, 018, ...');
        $applied = $admin->post('/api/admin/migrations/apply');
        same([200, $pending, null], [$applied->status, migrationNames($applied->json()['applied'] ?? []), $applied->json()['failed'] ?? null], 'Apply: ' . $applied->body);
        check(preg_match('/^A backup of the database as it was is (\S+-before-migration-[0-9a-f]{32})\.$/', (string) ($applied->json()['note'] ?? ''), $backup) === 1, 'made first: ' . ($applied->json()['note'] ?? ''));
        same('before-migration', iamBackups()->get($backup[1])['kind'] ?? null, 'and there on the Backups page');

        $moved = entriesOf($pdo, 'anotoki_audit_log');
        same($history, array_slice($moved, 0, count($history)), 'the history moved with its ids, the scrub included');
        $after = array_slice($moved, count($history));
        same([...$waitingBefore, 'backup.created', 'migrations.applied'], array_column($after, 'action'),
            'then the entries that waited, in the order they came - the backup before the update the last of them, made before any file ran - and the Apply\'s own');
        same([null, $backup[1]], [$after[count($waitingBefore)]['actor_id'], $after[count($waitingBefore)]['target_id']], 'that backup made by nobody: the Apply made it');
        $applyTime = $pdo->query("SELECT created_at FROM anotoki_audit_log WHERE action = 'migrations.applied' ORDER BY id DESC LIMIT 1")->fetchColumn();
        same(count($waitingBefore) + 1, (int) $pdo->query("SELECT count(*) FROM anotoki_audit_log WHERE id > 3000 AND action <> 'migrations.applied' AND created_at < " . $pdo->quote((string) $applyTime))->fetchColumn(), 'each with the time it was recorded, before the Apply');
        same('10.41.0.7', $pdo->query("SELECT ip FROM anotoki_audit_log WHERE action = 'user.updated' ORDER BY id DESC LIMIT 1")->fetchColumn(), 'with its address');
        // The person deleted while 018 waited: their own entry that waited with them is scrubbed as it goes in, not an hour later.
        $theirs = $pdo->query("SELECT ip, details FROM anotoki_audit_log WHERE action = 'account.username_changed' AND actor_id = {$personId} AND id > 3000")->fetch();
        check($theirs !== false, 'the deleted person\'s entry that waited is in the log');
        same([null, []], [$theirs['ip'], (array) json_decode((string) $theirs['details'], true)], 'and has no address and no names in it');
        same([], waitingActions(), 'and the file is empty');
        same(false, tableThere($pdo, 'audit_log'), 'the old name gone');

        $page = $admin->get('/api/admin/audit');
        same([200, count($moved)], [$page->status, $page->json()['total'] ?? null], 'the Audit page has them all');
        same(['state' => 'ready', 'installed' => false], $visitor->get('/api/site-status')->json(), 'up to date');
    } finally {
        useDatabase($main);
    }
});

finishTests();
