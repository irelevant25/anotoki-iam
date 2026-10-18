<?php

/**
 * Every first-party site open to every account, with ADMIN and EDITOR in each
 * and MEMBER gone (migration 007, the owner's decisions of 2026-10-01) - and
 * an administrator of the family holding ADMIN in every application that has
 * one: those 007 finds, and the one setup.php or create-admin.php makes
 * (createFirstAdmin()). The legacy import's ADMIN is import.test.php's.
 *
 * 007 arrives at installed IAMs too, so besides a fresh database it is applied
 * to one holding 001-006 the way an owner leaves it: an administrator, an
 * account invited as MEMBER, a role reworded and a site deleted in the admin
 * panel, and an application of the owner's own.
 *
 * 010 brought MEMBER back, for every account (sign-in-rules.test.php), so
 * what 007 leaves is asserted on databases built through 007 and no further.
 */

declare(strict_types=1);

require __DIR__ . '/lib.php';

// This run's own database is a fresh IAM, built from every migration, 007 included.
bootTests();

const EVERY_SITE_MIGRATION = '007_every_site_for_every_account.sql';

/** The roles every first-party site has after 007, in the words of 002, 005 and 007. */
const EVERY_SITE_ROLE_WORDS = [
    'build-analyzer' => [
        'ADMIN' => 'Everything an editor does, and adds and deletes languages, applies database updates, switches sources on and off and deletes a person\'s data',
        'EDITOR' => 'Rewords the site\'s strings and runs the build and catalog syncs',
    ],
    'genshin' => [
        'ADMIN' => 'Everything in the admin panel, including the system pages',
        'EDITOR' => 'Edits game content; may read the system pages',
    ],
    'japanese-academy' => [
        'ADMIN' => 'Everything an editor does, and adds and deletes languages, applies database updates and deletes a person\'s data',
        'EDITOR' => 'Rewords the app\'s strings',
    ],
    'piano-academy' => [
        'ADMIN' => 'Everything an editor does, and adds and deletes languages, applies database updates and deletes a person\'s data',
        'EDITOR' => 'Rewords the app\'s strings',
    ],
    'survey' => [
        'ADMIN' => 'Everything an editor does, and deletes surveys and answers',
        'EDITOR' => 'Writes surveys, opens and closes them, and reads the answers',
    ],
];

/** An application's roles as a database has them: name => description. */
function rolesIn(PDO $pdo, string $clientId): array
{
    $statement = $pdo->prepare('SELECT r.name, r.description FROM application_roles r JOIN applications a ON a.id = r.application_id WHERE a.client_id = ? ORDER BY r.name');
    $statement->execute([$clientId]);

    return $statement->fetchAll(PDO::FETCH_KEY_PAIR);
}

/** Who may use each application: client_id => access. */
function accessIn(PDO $pdo): array
{
    return $pdo->query('SELECT client_id, access FROM applications ORDER BY client_id')->fetchAll(PDO::FETCH_KEY_PAIR);
}

/** Every role an account holds, as "client_id ROLE". */
function grantsOf(PDO $pdo, int $userId): array
{
    $statement = $pdo->prepare(
        "SELECT a.client_id || ' ' || r.name FROM user_roles ur JOIN application_roles r ON r.id = ur.role_id JOIN applications a ON a.id = r.application_id
          WHERE ur.user_id = ? ORDER BY 1",
    );
    $statement->execute([$userId]);

    return $statement->fetchAll(PDO::FETCH_COLUMN);
}

/**
 * An IAM installed before 007: a throwaway database holding the migrations
 * that come before it. The name is this run's database's plus a suffix, so it
 * is dropped with it.
 */
function iamBeforeEverySite(string $suffix): PDO
{
    $name = throwawayDatabase(testEnvironment()['db'] . $suffix);
    ensureDatabase(testServer(), $name);
    $pdo = connectDatabase(testServer(), $name, false);

    check(in_array(EVERY_SITE_MIGRATION, migrationFiles('iam'), true), '007 is among the files');
    ensureMigrationsTable($pdo);
    foreach (migrationFiles('iam') as $file) {
        if (strnatcmp($file, EVERY_SITE_MIGRATION) < 0) {
            applyMigration($pdo, 'iam', $file);
        }
    }
    same(EVERY_SITE_MIGRATION, pendingMigrations($pdo, 'iam')[0] ?? null, 'built up to 006, 007 the first pending (what came after it pending too)');

    return $pdo;
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

// ─────────────────────────────────────────────────────────────────────────────

test('a fresh IAM through 007: every first-party site open to every account, with ADMIN and EDITOR and no MEMBER', function () {
    check(in_array(EVERY_SITE_MIGRATION, appliedMigrations(iamDb()), true), '007 recorded on this run\'s IAM, built from every migration');

    $pdo = buildIamThrough('_fresh007', EVERY_SITE_MIGRATION);
    $applied = appliedMigrations($pdo);
    check(in_array(EVERY_SITE_MIGRATION, $applied, true) && !in_array('010_sign_in_per_site.sql', $applied, true), 'another one built through 007, without 010');

    $access = accessIn($pdo);
    foreach (FIRST_PARTY_SITES as $clientId) {
        same('everyone', $access[$clientId] ?? null, "{$clientId} is open to every account");
        same(EVERY_SITE_ROLE_WORDS[$clientId], rolesIn($pdo, $clientId), "{$clientId} has ADMIN and EDITOR, in the owner's split's words");
    }
    same('members', $access['iam'] ?? null, 'the IAM\'s own entry is left as it was');
    same(0, (int) $pdo->query("SELECT count(*) FROM application_roles WHERE name = 'MEMBER'")->fetchColumn(), 'no MEMBER role anywhere');
    same(0, (int) $pdo->query('SELECT count(*) FROM user_roles')->fetchColumn(), 'a fresh IAM has no accounts, so nobody holds a role yet');
});

test('the first administrator - setup.php\'s, create-admin.php\'s - holds ADMIN in every application that has one', function () {
    $created = createFirstAdmin(iamDb(), 'first@example.test', 'a long enough one');
    check($created !== null, 'made, on an IAM with no accounts');
    $id = (int) iamDb()->query("SELECT id FROM users WHERE email = 'first@example.test'")->fetchColumn();

    same(['build-analyzer', 'genshin', 'iam', 'japanese-academy', 'piano-academy', 'survey'], adminOf(iamDb(), $id), 'ADMIN in the IAM and every first-party site');
    same(applicationsWithAdmin(iamDb()), adminOf(iamDb(), $id), 'which is every application that has one');
    $everyApplication = iamDb()->query('SELECT client_id FROM applications ORDER BY client_id')->fetchAll(PDO::FETCH_COLUMN);
    same(
        array_map(static fn(string $clientId): string => "{$clientId} MEMBER", $everyApplication),
        array_values(array_filter(grantsOf(iamDb(), $id), static fn(string $grant): bool => !str_ends_with($grant, ' ADMIN'))),
        'and beyond ADMIN only the MEMBER every account holds in every application (010)',
    );

    $browser = new Browser();
    same(200, $browser->signIn('first@example.test', 'a long enough one')->status, 'it signs in');
    same(true, $browser->get('/api/account')->json()['is_admin'] ?? null, 'and administers the IAM');
    same(null, createFirstAdmin(iamDb(), 'second@example.test', 'a long enough one'), 'a second one is no way in');
});

test('an IAM from before 007, as its owner left it: MEMBER goes with its grants, the administrator holds ADMIN everywhere, the owner\'s edits stay', function () {
    $pdo = iamBeforeEverySite('_owner');

    $owner = accountIn($pdo, 'owner');
    $pianist = accountIn($pdo, 'pianist');
    $player = accountIn($pdo, 'player');
    grantIn($pdo, $owner, 'iam', 'ADMIN');
    grantIn($pdo, $pianist, 'piano-academy', 'MEMBER');
    grantIn($pdo, $player, 'genshin', 'EDITOR');
    // ADMIN of one site is not ADMIN of the IAM: it earns nothing more.
    grantIn($pdo, $player, 'japanese-academy', 'ADMIN');

    // What the admin panel may have done: a role reworded, a role nobody
    // held deleted, a site deleted, an application of the owner's own with an
    // ADMIN of its own.
    $pdo->exec("UPDATE application_roles SET description = 'Runs the whole academy'
                 WHERE name = 'ADMIN' AND application_id = (SELECT id FROM applications WHERE client_id = 'japanese-academy')");
    $pdo->exec("DELETE FROM application_roles
                 WHERE name = 'EDITOR' AND application_id = (SELECT id FROM applications WHERE client_id = 'survey')");
    $pdo->exec("DELETE FROM applications WHERE client_id = 'build-analyzer'");
    $notes = (int) $pdo
        ->query(
            "INSERT INTO applications (client_id, name, home_url, type, access, show_on_landing, sort_order, system)
                                VALUES ('notes', 'Notes', 'https://notes.example.test', 'public', 'members', FALSE, 60, FALSE) RETURNING id",
        )
        ->fetchColumn();
    $pdo->prepare("INSERT INTO application_roles (application_id, name, description) VALUES (?, 'ADMIN', 'Runs the notes'), (?, 'READER', 'Reads them')")->execute([$notes, $notes]);

    same(
        ['genshin' => 'everyone', 'iam' => 'members', 'japanese-academy' => 'members', 'notes' => 'members', 'piano-academy' => 'members', 'survey' => 'everyone'],
        accessIn($pdo),
        'before: the academies are for members',
    );
    same(['piano-academy MEMBER'], grantsOf($pdo, $pianist), 'and the pianist was invited to one');

    $result = applyMigrationsThrough($pdo, EVERY_SITE_MIGRATION);
    same([[EVERY_SITE_MIGRATION], null], [$result['applied'], $result['failed']], '007 applied, and its gate passed (' . ($result['error'] ?? '') . ')');

    same(
        ['genshin' => 'everyone', 'iam' => 'members', 'japanese-academy' => 'everyone', 'notes' => 'members', 'piano-academy' => 'everyone', 'survey' => 'everyone'],
        accessIn($pdo),
        'every first-party site is open to every account; the IAM and the owner\'s own application are as they were',
    );
    same(0, (int) $pdo->query("SELECT count(*) FROM applications WHERE client_id = 'build-analyzer'")->fetchColumn(), 'the site deleted by hand stays deleted');

    same([], grantsOf($pdo, $pianist), 'MEMBER went, and the pianist\'s grant of it with it');
    same(0, (int) $pdo->query("SELECT count(*) FROM application_roles WHERE name = 'MEMBER'")->fetchColumn(), 'no MEMBER role anywhere');

    same(
        ['ADMIN' => 'Runs the whole academy', 'EDITOR' => EVERY_SITE_ROLE_WORDS['japanese-academy']['EDITOR']],
        rolesIn($pdo, 'japanese-academy'),
        'the ADMIN reworded in the admin panel keeps its words; EDITOR is added',
    );
    same(EVERY_SITE_ROLE_WORDS['piano-academy'], rolesIn($pdo, 'piano-academy'), 'an ADMIN still in 002\'s words takes the split\'s');
    same(EVERY_SITE_ROLE_WORDS['genshin'], rolesIn($pdo, 'genshin'), 'genshin keeps its roles and words');
    same(EVERY_SITE_ROLE_WORDS['survey'], rolesIn($pdo, 'survey'), 'and so does the survey - its EDITOR, deleted by hand, back in its words');
    same(['ADMIN' => 'Runs the notes', 'READER' => 'Reads them'], rolesIn($pdo, 'notes'), 'the owner\'s own application gets no EDITOR');

    same(['genshin', 'iam', 'japanese-academy', 'notes', 'piano-academy', 'survey'], adminOf($pdo, $owner), 'the IAM\'s administrator holds ADMIN in every application that has one');
    same(applicationsWithAdmin($pdo), adminOf($pdo, $owner), 'all of them');
    same(['genshin EDITOR', 'japanese-academy ADMIN'], grantsOf($pdo, $player), 'another site\'s ADMIN is given nothing more');
});

finishTests();
