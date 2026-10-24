<?php

/**
 * Migration 024: What's new (the owner's decision of 2026-10-19). The IAM's
 * footer has the family's row now - the version, What's new and Feedback -
 * and What's new is the IAM's own dialog (frontend shared/changelog-dialog),
 * which lists the versions of public/changelog.json: six page keys are its
 * own words around them, each described, in English (en.ts's, which
 * translations.test.php holds to it) and in Slovak.
 *
 * 024 arrives at installed IAMs, so besides a fresh database it is applied to
 * one built through 023 as the files left it, and to one as its owner may
 * have left it - a Slovak string elsewhere blanked (no row) - neither a reason
 * for the gate to fail. And its gate is shown failing loudly, leaving
 * nothing behind, when a string does not arrive.
 */

declare(strict_types=1);

require __DIR__ . '/lib.php';

// This run's own database is a fresh IAM, built from every migration, 024 included.
bootTests();

const WHATS_NEW_MIGRATION = '024_whats_new.sql';
const BEFORE_WHATS_NEW = '023_early_access_role.sql';
/** The six keys and their words: key => [English, Slovak]. */
const WHATS_NEW_WORDS = [
    'whatsNew.title' => ['What\'s new', 'Čo je nové'],
    'whatsNew.version' => ['Version {version}', 'Verzia {version}'],
    'whatsNew.latest' => ['Latest', 'Najnovšia'],
    'whatsNew.added' => ['Added', 'Pridané'],
    'whatsNew.fixed' => ['Fixed', 'Opravené'],
    'whatsNew.updated' => ['Changed', 'Zmenené'],
];

/** A key as a database has it: [description, [English, its writer], [Slovak, its writer]]; null where there is none. */
function keyStrings(PDO $pdo, string $key): array
{
    $description = $pdo->prepare('SELECT description FROM translation_keys WHERE name = ?');
    $description->execute([$key]);
    $found = [$description->fetchColumn() ?: null];
    foreach (['en', 'sk'] as $code) {
        $row = $pdo->prepare('SELECT value, updated_by FROM translations WHERE key_name = ? AND language_code = ?');
        $row->execute([$key, $code]);
        $string = $row->fetch(PDO::FETCH_NUM);
        $found[] = $string === false ? null : [$string[0], $string[1] === null ? null : (int) $string[1]];
    }

    return $found;
}

/** The six keys as a database has them - to hold one database to another. */
function whatsNewStrings(PDO $pdo): array
{
    $strings = [];
    foreach (array_keys(WHATS_NEW_WORDS) as $key) {
        $strings[$key] = keyStrings($pdo, $key);
    }

    return $strings;
}

function buildThrough023(string $suffix): PDO
{
    $pdo = buildIamThrough($suffix, BEFORE_WHATS_NEW);
    same(WHATS_NEW_MIGRATION, pendingMigrations($pdo)[0] ?? null, 'built through 023, 024 the first pending');
    same(0, (int) $pdo->query("SELECT count(*) FROM translation_keys WHERE name LIKE 'whatsNew.%'")->fetchColumn(), 'no key of What\'s new before it');

    return $pdo;
}

test('a fresh IAM has run 024: What\'s new\'s six keys, each described, in English and Slovak, by nobody - {version} the one placeholder, kept in both', function () {
    $pdo = iamDb();
    check(in_array(WHATS_NEW_MIGRATION, appliedMigrations($pdo), true), '024 recorded');
    foreach (WHATS_NEW_WORDS as $key => [$english, $slovak]) {
        [$description, $en, $sk] = keyStrings($pdo, $key);
        check(trim((string) $description) !== '', "$key: described");
        same([[$english, null], [$slovak, null]], [$en, $sk], "$key: its English (en.ts's) and its Slovak, by nobody");
        $placeholders = $key === 'whatsNew.version' ? 'Placeholders: {version}.' : 'No placeholders.';
        check(str_contains((string) $description, $placeholders), "$key: its description says {$placeholders}");
        // Where a link goes is the code's: an administrator rewords strings, never addresses (the 0.6.0 review).
        check(preg_match('~https?:|://|www\.|@~i', $english . $slovak) !== 1, "$key: no address in it");
    }
    same(1, substr_count(WHATS_NEW_WORDS['whatsNew.version'][1], '{version}'), 'the Slovak keeps the version where the English has it');
});

test('an IAM built through 023 as the files left it: 024 adds the six keys - as on a fresh IAM', function () {
    $pdo = buildThrough023('_before_whats_new');

    $result = applyMigrationsThrough($pdo, WHATS_NEW_MIGRATION);
    same([[WHATS_NEW_MIGRATION], null], [$result['applied'], $result['failed']], '024 applied, its gate passed (' . ($result['error'] ?? '') . ')');
    same(whatsNewStrings(iamDb()), whatsNewStrings($pdo), 'every key, description and string as on a fresh IAM');
});

test('an IAM built through 023 as its owner left it: a Slovak string blanked elsewhere stays blank, and the gate passes', function () {
    $pdo = buildThrough023('_owned_whats_new');
    // The Translations page deletes a string that is blanked: no row.
    $pdo->exec("DELETE FROM translations WHERE key_name = 'common.save' AND language_code = 'sk'");

    $result = applyMigrationsThrough($pdo, WHATS_NEW_MIGRATION);
    same([[WHATS_NEW_MIGRATION], null], [$result['applied'], $result['failed']], '024 applied, its gate passed - a blank elsewhere is no failure (' . ($result['error'] ?? '') . ')');
    same(whatsNewStrings(iamDb()), whatsNewStrings($pdo), 'the six keys as on a fresh IAM');
    same(0, (int) $pdo->query("SELECT count(*) FROM translations WHERE key_name = 'common.save' AND language_code = 'sk'")->fetchColumn(), 'and the Slovak string the owner blanked stays blank');
});

test('a 024 whose gate fails - a Slovak string that did not arrive - leaves nothing behind, and runs once the cause is gone', function () {
    $pdo = buildThrough023('_spoilt_whats_new');

    // Something on the host swallows one Slovak string on its way in: the gate must see it.
    $pdo->exec("CREATE FUNCTION test_024_swallow() RETURNS trigger LANGUAGE plpgsql AS \$\$ BEGIN RETURN NULL; END \$\$");
    $pdo->exec("CREATE TRIGGER test_024_swallow BEFORE INSERT ON translations FOR EACH ROW WHEN (NEW.key_name = 'whatsNew.latest' AND NEW.language_code = 'sk') EXECUTE FUNCTION test_024_swallow()");

    $result = applyMigrationsThrough($pdo, WHATS_NEW_MIGRATION);
    same([[], WHATS_NEW_MIGRATION], [$result['applied'], $result['failed']], '024 fails');
    check(str_contains((string) $result['error'], 'whatsNew.latest'), 'its gate names the key: ' . $result['error']);
    same(0, (int) $pdo->query("SELECT count(*) FROM translation_keys WHERE name LIKE 'whatsNew.%'")->fetchColumn(), 'none of its keys is left');
    same(WHATS_NEW_MIGRATION, pendingMigrations($pdo)[0] ?? null, 'and it still waits');

    $pdo->exec('DROP TRIGGER test_024_swallow ON translations');
    $pdo->exec('DROP FUNCTION test_024_swallow()');
    $result = applyMigrationsThrough($pdo, WHATS_NEW_MIGRATION);
    same([[WHATS_NEW_MIGRATION], null], [$result['applied'], $result['failed']], 'the cause gone, the next Apply runs it (' . ($result['error'] ?? '') . ')');
    same(whatsNewStrings(iamDb()), whatsNewStrings($pdo), 'with every key and string');
});

finishTests();
