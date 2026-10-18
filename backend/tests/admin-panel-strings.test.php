<?php

/**
 * Migration 009, "Admin panel": the two strings that name the admin area -
 * the person's menu entry and the account overview's card - reworded from
 * 006's "Admin console" / "Administrácia" to "Admin panel" /
 * "Administrátorský panel".
 *
 * 009 arrives at installed IAMs, where 006 is long applied, so besides a fresh
 * database it is applied to one holding 001-008 the way an owner may leave it:
 * one of the strings reworded in the admin panel (the owner's words, which
 * stay), a Slovak one blanked (no row: it stays blank) - neither a reason for
 * the gate to fail.
 */

declare(strict_types=1);

require __DIR__ . '/lib.php';

// This run's own database is a fresh IAM, built from every migration, 009 included.
bootTests();

const ADMIN_PANEL_MIGRATION = '009_admin_panel.sql';
const ADMIN_PANEL_STRINGS = [
    'overview.adminTitle' => ['Admin panel', 'Administrátorský panel'],
    'shell.admin' => ['Admin panel', 'Administrátorský panel'],
];

/** The two keys as a database has them: key => [English, Slovak]; null where a language has no string. */
function adminPanelStrings(PDO $pdo): array
{
    $strings = [];
    foreach (array_keys(ADMIN_PANEL_STRINGS) as $key) {
        $row = $pdo->prepare("SELECT (SELECT value FROM translations WHERE key_name = ? AND language_code = 'en'), (SELECT value FROM translations WHERE key_name = ? AND language_code = 'sk')");
        $row->execute([$key, $key]);
        $strings[$key] = $row->fetch(PDO::FETCH_NUM);
    }
    return $strings;
}

test('a fresh IAM has run 009: the admin area is the admin panel, in English and Slovak', function () {
    check(in_array(ADMIN_PANEL_MIGRATION, appliedMigrations(iamDb()), true), '009 recorded');
    same(ADMIN_PANEL_STRINGS, adminPanelStrings(iamDb()), 'the strings, as en.ts and the Slovak have them');
});

test('an IAM from before 009, as its owner left it: 006\'s words reworded, the owner\'s own words and a blanked string kept', function () {
    $name = throwawayDatabase(testEnvironment()['db'] . '_before_panel');
    ensureDatabase(testServer(), $name);
    $pdo = connectDatabase(testServer(), $name, false);
    ensureMigrationsTable($pdo);
    foreach (migrationFiles('iam') as $file) {
        if (strnatcmp($file, ADMIN_PANEL_MIGRATION) < 0) {
            applyMigration($pdo, 'iam', $file);
        }
    }
    same(ADMIN_PANEL_MIGRATION, pendingMigrations($pdo, 'iam')[0] ?? null, 'built up to 008, 009 the first pending');
    same(['Admin console', 'Administrácia'], adminPanelStrings($pdo)['shell.admin'], 'before it: 006\'s words');

    // What the owner may have done on the Translations page: a card reworded in English, the menu entry's Slovak blanked.
    $pdo->exec("UPDATE translations SET value = 'Administration' WHERE key_name = 'overview.adminTitle' AND language_code = 'en'");
    $pdo->exec("DELETE FROM translations WHERE key_name = 'shell.admin' AND language_code = 'sk'");

    $result = applyPendingMigrations($pdo, 'iam');
    same([ADMIN_PANEL_MIGRATION, null], [$result['applied'][0] ?? null, $result['failed']], '009 applied, its gate passed (' . ($result['error'] ?? '') . ')');
    same(
        [
            'overview.adminTitle' => ['Administration', 'Administrátorský panel'],
            'shell.admin' => ['Admin panel', null],
        ],
        adminPanelStrings($pdo),
        'only 006\'s words reworded; the owner\'s English and the blank Slovak stay',
    );
    same(0, (int) $pdo->query("SELECT count(*) FROM translations WHERE value IN ('Admin console', 'Administrácia')")->fetchColumn(), 'and 006\'s words are nowhere');
});

finishTests();
