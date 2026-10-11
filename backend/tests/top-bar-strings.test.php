<?php

/**
 * Migration 008, the top bar's words: three keys with their English and
 * Slovak, and `shell.account` gone with its strings.
 *
 * 008 arrives at installed IAMs too, so besides a fresh database it is applied
 * to one holding 001-007 the way an owner leaves it: the key that goes
 * reworded in the panel, and a Slovak string elsewhere blanked (the panel
 * deletes it) - neither is a reason for the gate to fail.
 *
 * Since 017 the language button's name is the anotoki library's key,
 * anotoki.language.button, with 008's strings moved there
 * (tests/strings-through-the-library.test.php): a fresh IAM has it under that
 * name, and the installed one here is held to what 008 itself leaves.
 */

declare(strict_types=1);

require __DIR__ . '/lib.php';

// This run's own database is a fresh IAM, built from every migration, 008 included.
bootTests();

const TOP_BAR_MIGRATION = '008_top_bar.sql';
const TOP_BAR_STRINGS = [
    'lang.button' => ['Language: {name} ({code})', 'Jazyk: {name} ({code})'],
    'shell.menu' => ['Menu', 'Ponuka'],
    'shell.roleAdmin' => ['Administrator', 'Administrátor'],
];

/** The bar's keys as a database has them: key => [English, Slovak]. */
function topBarStrings(PDO $pdo, array $keys = []): array
{
    $strings = [];
    foreach ($keys === [] ? array_keys(TOP_BAR_STRINGS) : $keys as $key) {
        $row = $pdo->prepare("SELECT (SELECT value FROM translations WHERE key_name = ? AND language_code = 'en'), (SELECT value FROM translations WHERE key_name = ? AND language_code = 'sk')");
        $row->execute([$key, $key]);
        $strings[$key] = $row->fetch(PDO::FETCH_NUM);
    }
    return $strings;
}

function accountKeyLeft(PDO $pdo): int
{
    return (int) $pdo->query("SELECT (SELECT count(*) FROM translation_keys WHERE name = 'shell.account') + (SELECT count(*) FROM translations WHERE key_name = 'shell.account')")->fetchColumn();
}

test('a fresh IAM has run 008: the bar\'s three keys in English and Slovak - the button\'s name the library\'s key since 017 -, shell.account gone', function () {
    check(in_array(TOP_BAR_MIGRATION, appliedMigrations(iamDb()), true), '008 recorded');
    $fresh = ['anotoki.language.button' => TOP_BAR_STRINGS['lang.button']] + TOP_BAR_STRINGS;
    unset($fresh['lang.button']);
    same($fresh, topBarStrings(iamDb(), array_keys($fresh)), 'the strings, as en.ts, the library and the Slovak "ty" have them');
    same([null, null], topBarStrings(iamDb(), ['lang.button'])['lang.button'], 'under 008\'s name no more');
    same(0, accountKeyLeft(iamDb()), 'no shell.account, nor a string of it');
});

test('an IAM from before 008, as its owner left it: the key goes reworded, a blanked Slovak string elsewhere is no failure', function () {
    $name = throwawayDatabase(testEnvironment()['db'] . '_before_bar');
    ensureDatabase(testServer(), $name);
    $pdo = connectDatabase(testServer(), $name, false);
    ensureMigrationsTable($pdo);
    foreach (migrationFiles('iam') as $file) {
        if (strnatcmp($file, TOP_BAR_MIGRATION) < 0) {
            applyMigration($pdo, 'iam', $file);
        }
    }
    same(TOP_BAR_MIGRATION, pendingMigrations($pdo, 'iam')[0] ?? null, 'built up to 007, 008 the first pending');

    $pdo->exec("UPDATE translations SET value = 'Your account' WHERE key_name = 'shell.account' AND language_code = 'en'");
    $pdo->exec("DELETE FROM translations WHERE key_name = 'common.save' AND language_code = 'sk'");

    $result = applyMigrationsThrough($pdo, TOP_BAR_MIGRATION);
    same([[TOP_BAR_MIGRATION], null], [$result['applied'], $result['failed']], '008 applied, its gate passed (' . ($result['error'] ?? '') . ')');
    same(TOP_BAR_STRINGS, topBarStrings($pdo), 'the three keys and their strings');
    same(0, accountKeyLeft($pdo), 'shell.account gone, reworded or not');
    same(0, (int) $pdo->query("SELECT count(*) FROM translations WHERE key_name = 'common.save' AND language_code = 'sk'")->fetchColumn(), 'and the Slovak string the owner blanked stays blank');
});

finishTests();
