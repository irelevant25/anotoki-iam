<?php

/**
 * Migration 021: the sign-in page's two buttons that have a code emailed, in
 * the IAM's informal Slovak ("ty") - 006's login.useCode and 019's
 * login.paused.sendCode, which asked in the formal plural ("pošlite").
 *
 * 021 arrives at installed IAMs, where 006 and 019 are applied, so besides a
 * fresh database it is applied to one built through 020 as the files left it,
 * where both are reworded, and to ones as their owner may have left them,
 * none a reason for the gate to fail: both saved by an administrator on the
 * Translations page - one put back to its file's formal words on purpose,
 * the other reworded -, which stay as saved, with their writer; and one's
 * Slovak blanked (no row: it stays blank) beside the other untouched, which
 * is reworded.
 */

declare(strict_types=1);

require __DIR__ . '/lib.php';

// This run's own database is a fresh IAM, built from every migration, 021 included.
bootTests();

const INFORMAL_MIGRATION = '021_informal_code_buttons.sql';
const BEFORE_INFORMAL = '020_legal_pages.sql';
const FORMAL_BUTTONS = ['login.useCode' => 'Radšej mi pošlite kód e-mailom', 'login.paused.sendCode' => 'Pošlite mi kód e-mailom'];
const INFORMAL_BUTTONS = ['login.useCode' => 'Radšej mi pošli kód e-mailom', 'login.paused.sendCode' => 'Pošli mi kód e-mailom'];
const ENGLISH_BUTTONS = ['login.useCode' => 'Email me a sign-in code instead', 'login.paused.sendCode' => 'Email me a code'];

/** The two buttons' strings in a language: key => [text, who wrote it]; null where there is none. */
function buttonStrings(PDO $pdo, string $code): array
{
    $strings = [];
    foreach (array_keys(FORMAL_BUTTONS) as $key) {
        $row = $pdo->prepare('SELECT value, updated_by FROM translations WHERE key_name = ? AND language_code = ?');
        $row->execute([$key, $code]);
        $found = $row->fetch(PDO::FETCH_NUM);
        $strings[$key] = $found === false ? null : [$found[0], $found[1] === null ? null : (int) $found[1]];
    }

    return $strings;
}

function buildThrough020(string $suffix): PDO
{
    $pdo = buildIamThrough($suffix, BEFORE_INFORMAL);
    same(INFORMAL_MIGRATION, pendingMigrations($pdo)[0] ?? null, 'built through 020, 021 the first pending');

    return $pdo;
}

test('a fresh IAM has run 021: both buttons ask in the informal Slovak, their English as it was, and no Slovak string of the IAM\'s says "pošlite"', function () {
    check(in_array(INFORMAL_MIGRATION, appliedMigrations(iamDb()), true), '021 recorded');
    same(array_map(static fn(string $text): array => [$text, null], INFORMAL_BUTTONS), buttonStrings(iamDb(), 'sk'), 'the Slovak, "ty"');
    same(array_map(static fn(string $text): array => [$text, null], ENGLISH_BUTTONS), buttonStrings(iamDb(), 'en'), 'the English untouched (en.ts has the same)');
    same(
        [],
        iamDb()->query("SELECT key_name FROM translations WHERE language_code = 'sk' AND key_name NOT LIKE 'anotoki.%' AND value ~* 'pošlite'")->fetchAll(PDO::FETCH_COLUMN),
        'the formal plural is in none of the IAM\'s own Slovak strings',
    );
});

test('an IAM built through 020 as the files left it: both buttons reworded, by nobody', function () {
    $pdo = buildThrough020('_before_informal');
    same(array_map(static fn(string $text): array => [$text, null], FORMAL_BUTTONS), buttonStrings($pdo, 'sk'), 'before it: the formal words 006 and 019 wrote');

    $result = applyMigrationsThrough($pdo, INFORMAL_MIGRATION);
    same([[INFORMAL_MIGRATION], null], [$result['applied'], $result['failed']], '021 applied, its gate passed (' . ($result['error'] ?? '') . ')');
    same(array_map(static fn(string $text): array => [$text, null], INFORMAL_BUTTONS), buttonStrings($pdo, 'sk'), 'after it: "ty"');
    same(array_map(static fn(string $text): array => [$text, null], ENGLISH_BUTTONS), buttonStrings($pdo, 'en'), 'and the English as it was');
});

test('an IAM built through 020 as its owner left it: a button an administrator saved stays as saved, with its writer - in its file\'s formal words too - and the gate passes', function () {
    $pdo = buildThrough020('_owned_informal');
    $owner = (int) $pdo->query("INSERT INTO users (username, email, email_confirmed) VALUES ('owner', 'owner@example.test', TRUE) RETURNING id")->fetchColumn();
    // Each saved on the Translations page: one put back to 006's very words on purpose, the other reworded.
    $save = $pdo->prepare("UPDATE translations SET value = ?, updated_by = ?, updated_at = '2026-10-09 08:15:00+00' WHERE key_name = ? AND language_code = 'sk'");
    $save->execute([FORMAL_BUTTONS['login.useCode'], $owner, 'login.useCode']);
    $save->execute(['Pošli mi radšej kód', $owner, 'login.paused.sendCode']);

    $result = applyMigrationsThrough($pdo, INFORMAL_MIGRATION);
    same([[INFORMAL_MIGRATION], null], [$result['applied'], $result['failed']], '021 applied, its gate passed - a saved string in the formal words is no failure (' . ($result['error'] ?? '') . ')');
    same(
        ['login.useCode' => [FORMAL_BUTTONS['login.useCode'], $owner], 'login.paused.sendCode' => ['Pošli mi radšej kód', $owner]],
        buttonStrings($pdo, 'sk'),
        'the owner\'s choices as saved, by the owner',
    );
});

test('an IAM built through 020 with one button\'s Slovak blanked: it stays blank, and the other, untouched, is reworded', function () {
    $pdo = buildThrough020('_blank_informal');
    $pdo->exec("DELETE FROM translations WHERE key_name = 'login.paused.sendCode' AND language_code = 'sk'");

    $result = applyMigrationsThrough($pdo, INFORMAL_MIGRATION);
    same([[INFORMAL_MIGRATION], null], [$result['applied'], $result['failed']], '021 applied, its gate passed (' . ($result['error'] ?? '') . ')');
    same(
        ['login.useCode' => [INFORMAL_BUTTONS['login.useCode'], null], 'login.paused.sendCode' => null],
        buttonStrings($pdo, 'sk'),
        'the untouched one in "ty", by nobody; the blank Slovak still blank',
    );
});

finishTests();
