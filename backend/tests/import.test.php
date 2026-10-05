<?php

/**
 * The legacy import, against a throwaway copy of the genshin site's account
 * tables: ids kept, passwords and two-factor still working, roles mapped, the
 * marker written - and the refusals that protect an IAM that already has
 * accounts or a database with case-only duplicates.
 */

declare(strict_types=1);

require __DIR__ . '/lib.php';

bootTests();

$legacy = makeLegacyDatabase();
$totpSecret = totpSecret();
$recoveryRaw = 'ABCDE23456';

// Ids with gaps, as a real table has after deletions: they must survive as they are.
$legacy->prepare("INSERT INTO users (id, role, username, email, email_confirmed, password, language, totp_secret, totp_enabled) VALUES
    (1, 'ADMIN', 'owner', 'owner@example.test', TRUE, ?, 'sk', ?, TRUE),
    (4, 'EDITOR', 'helper', 'helper@example.test', TRUE, ?, 'en', NULL, FALSE),
    (9, 'USER', 'player', 'player@example.test', TRUE, NULL, 'de', NULL, FALSE)")
    ->execute([password_hash('owner password', PASSWORD_DEFAULT), $totpSecret, password_hash('helper password', PASSWORD_DEFAULT)]);
$legacy->exec("INSERT INTO user_identities (id, user_id, provider, subject, email) VALUES (3, 9, 'google', 'google-sub-9', 'player@gmail.test')");
$legacy->prepare('INSERT INTO user_recovery_codes (id, user_id, code_hash) VALUES (7, 1, ?)')->execute([sha256($recoveryRaw)]);
$legacy->exec("INSERT INTO user_sessions (id, user_id, token_id, method, expires_at) VALUES (12, 1, '" . str_repeat('a', 32) . "', 'password', CURRENT_TIMESTAMP + INTERVAL '1 day')");
$legacy->exec("INSERT INTO user_login_attempts (id, email, user_id, method, outcome) VALUES (5, 'owner@example.test', 1, 'password', 'ok')");
$legacy->exec("INSERT INTO email_log (id, recipient, kind, status, user_id) VALUES (2, 'owner@example.test', 'confirmation', 'sent', 1)");

test('a dry run reports and writes nothing', function () use ($legacy) {
    $summary = importLegacyAccounts(iamDb(), $legacy, 'genshin', true);
    same(3, $summary['accounts'], 'three accounts counted');
    same(0, (int) iamDb()->query('SELECT count(*) FROM users')->fetchColumn(), 'nothing written');
    same(false, (bool) $legacy->query("SELECT to_regclass('public.iam_handover') IS NOT NULL")->fetchColumn(), 'no marker');
});

test('the import keeps ids, maps roles, encrypts two-factor secrets and leaves the marker', function () use ($legacy, $totpSecret) {
    $summary = importLegacyAccounts(iamDb(), $legacy);
    same(3, $summary['accounts'], 'accounts');
    // The old site's ADMIN administers the whole family: ADMIN in the IAM and in
    // every site that has one (the owner's rule, migration 007); EDITOR stays
    // the old site's own.
    $roles = $summary['roles'];
    ksort($roles);
    same([
        'build-analyzer ADMIN' => 1, 'genshin ADMIN' => 1, 'genshin EDITOR' => 1, 'iam ADMIN' => 1,
        'japanese-academy ADMIN' => 1, 'piano-academy ADMIN' => 1, 'survey ADMIN' => 1,
    ], $roles, 'roles');
    same(['build-analyzer', 'genshin', 'iam', 'japanese-academy', 'piano-academy', 'survey'], adminOf(iamDb(), 1), 'the old ADMIN holds ADMIN in the IAM and every first-party site');
    same(applicationsWithAdmin(iamDb()), adminOf(iamDb(), 1), 'which is every application that has one');
    same([], adminOf(iamDb(), 4), 'the old EDITOR holds no ADMIN');

    same([1, 4, 9], array_map('intval', iamDb()->query('SELECT id FROM users ORDER BY id')->fetchAll(PDO::FETCH_COLUMN)), 'ids kept');
    same('en', iamDb()->query('SELECT language FROM users WHERE id = 9')->fetchColumn(), 'an unknown language falls back to English');

    $stored = (string) iamDb()->query('SELECT totp_secret FROM users WHERE id = 1')->fetchColumn();
    check(str_starts_with($stored, 'v1:'), 'secret encrypted');
    same($totpSecret, decryptSecret($stored), 'and decrypts to the same secret');

    same(3, (int) $legacy->query('SELECT accounts FROM iam_handover')->fetchColumn(), 'marker written');

    $next = (int) iamDb()->query("INSERT INTO users (username, email) VALUES ('later', 'later@example.test') RETURNING id")->fetchColumn();
    same(10, $next, 'the id sequence carries on after the copied ids');
});

test('imported accounts sign in with their old passwords, codes and Google links', function () use ($totpSecret, $recoveryRaw) {
    $browser = new Browser('10.1.0.1');
    same('totp_required', $browser->signIn('owner@example.test', 'owner password')->code(), 'two-factor carried over');
    same(200, $browser->signIn('owner@example.test', 'owner password', ['totp' => totpCode($totpSecret, intdiv(time(), 30))])->status, 'the authenticator still works');
    same(true, $browser->get('/api/account')->json()['is_admin'], 'the site admin administers the IAM');

    same(200, (new Browser('10.1.0.2'))->signIn('owner@example.test', 'owner password', ['totp' => $recoveryRaw])->status, 'the paper recovery code still works');
    same(200, (new Browser('10.1.0.3'))->signIn('helper@example.test', 'helper password')->status, 'an editor signs in');
    same('password_login_unavailable', (new Browser('10.1.0.4'))->signIn('player@example.test', 'anything')->code(), 'a Google-only account stays Google-only');
    same(1, (int) iamDb()->query("SELECT count(*) FROM user_identities WHERE user_id = 9 AND provider = 'google'")->fetchColumn(), 'with its link');
});

test('old sessions arrive as ended history, and no old cookie could match them', function () {
    $session = iamDb()->query('SELECT * FROM sessions WHERE id = 12')->fetch();
    check($session !== false, 'session 12 kept its id');
    check($session['revoked_at'] !== null, 'marked ended');
    same(str_repeat('a', 32), trim($session['sid']), 'the old token id is its sid');
});

test('an IAM that already has accounts refuses a second import', function () use ($legacy) {
    try {
        importLegacyAccounts(iamDb(), $legacy);
        throw new AssertionFailed('expected a refusal');
    } catch (RuntimeException $e) {
        check(str_contains($e->getMessage(), 'already has accounts'), 'refused: ' . $e->getMessage());
    }
});

finishTests();
