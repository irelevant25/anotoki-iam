<?php

/**
 * Migration 022: Google only on a press (the owner's decision of 2026-10-14).
 * The sign-in, sign-up and account pages draw the IAM's own button and fetch
 * nothing from Google until somebody presses it (frontend ui/google-button):
 * six page keys say that button's words, what a screen reader is told and
 * the line shown when Google cannot be reached - each described, in English
 * (en.ts's, which translations.test.php holds to it) and in an informal
 * Slovak. And the Privacy page's sentence about Google, 020's
 * legal.google.role - "your browser loads the button from Google" wherever
 * it is drawn -, now says that only a press loads anything from Google, its
 * description with it.
 *
 * 022 arrives at installed IAMs, so besides a fresh database it is applied to
 * one built through 021 as the files left it, and to ones as their owner may
 * have left them, none a reason for the gate to fail: the sentence saved by
 * an administrator on the Translations page - its English put back to 020's
 * very words on purpose, its Slovak reworded -, which stays as saved, with
 * its writer; and its Slovak blanked (no row: it stays blank) beside the
 * English untouched, which is reworded. And its gate is shown failing loudly,
 * leaving nothing behind, when a string does not arrive.
 */

declare(strict_types=1);

require __DIR__ . '/lib.php';

// This run's own database is a fresh IAM, built from every migration, 022 included.
bootTests();

const GOOGLE_MIGRATION = '022_google_button_on_press.sql';
const BEFORE_GOOGLE = '021_informal_code_buttons.sql';
/** The six keys and their words: key => [English, Slovak]. */
const GOOGLE_WORDS = [
    'google.signIn' => ['Sign in with Google', 'Prihlásiť sa cez Google'],
    'google.signUp' => ['Sign up with Google', 'Zaregistrovať sa cez Google'],
    'google.continue' => ['Continue with Google', 'Pokračovať cez Google'],
    'google.frame' => ['Google’s own button', 'Tlačidlo od Googlu'],
    'google.ready' => ['Google’s own button is ready: press it to choose your Google account.', 'Tlačidlo od Googlu je pripravené: stlač ho a vyber si účet Google.'],
    'google.failed' => ['Google could not be reached: this browser may be blocking it, or you are offline.', 'Nepodarilo sa spojiť s Googlom: tento prehliadač ho možno blokuje alebo nie si pripojený k internetu.'],
];
/** The Privacy page's sentence about Google: what 020 wrote, and what 022 makes of it - English, Slovak. */
const ROLE_BEFORE = [
    'Draws its "Sign in with Google" button on the sign-in and sign-up pages, and on the account page while Google is not connected, whenever signing in with Google is switched on: your browser loads the button from Google, so Google sees your IP address and its own cookies. Only if you choose to sign in with Google does Google tell the anotoki account your Google account’s id, e-mail address and name. What Google itself keeps, its own privacy policy says. A company based in the USA: data may be handled outside the EU.',
    'Na stránkach prihlásenia a registrácie a na stránke účtu, kým Google nie je pripojený, zobrazuje svoje tlačidlo „Prihlásiť sa cez Google“, ak je prihlasovanie cez Google zapnuté: prehliadač ho načíta od Googlu, takže Google vidí tvoju IP adresu a svoje vlastné cookies. Len ak sa rozhodneš prihlásiť cez Google, oznámi Google anotoki účtu identifikátor, e-mailovú adresu a meno tvojho účtu Google. Čo uchováva samotný Google, opisujú jeho vlastné zásady ochrany súkromia. Spoločnosť so sídlom v USA: údaje sa môžu spracúvať aj mimo EÚ.',
];
const ROLE_AFTER = [
    'Only when you press "Sign in with Google", "Sign up with Google" or "Continue with Google" - on the sign-in and sign-up pages, and on the account page while Google is not connected - does your browser load anything from Google: Google’s own button, so Google sees your IP address and its own cookies. Only if you then choose your Google account does Google tell the anotoki account your Google account’s id, e-mail address and name. What Google itself keeps, its own privacy policy says. A company based in the USA: data may be handled outside the EU.',
    'Až keď stlačíš „Prihlásiť sa cez Google“, „Zaregistrovať sa cez Google“ alebo „Pokračovať cez Google“ - na stránkach prihlásenia a registrácie a na stránke účtu, kým Google nie je pripojený -, načíta tvoj prehliadač niečo od Googlu: jeho vlastné tlačidlo, takže Google vidí tvoju IP adresu a svoje vlastné cookies. Len ak si potom vyberieš svoj účet Google, oznámi Google anotoki účtu identifikátor, e-mailovú adresu a meno tvojho účtu Google. Čo uchováva samotný Google, opisujú jeho vlastné zásady ochrany súkromia. Spoločnosť so sídlom v USA: údaje sa môžu spracúvať aj mimo EÚ.',
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

/** The Privacy page's sentence about Google: [[English, its writer], [Slovak, its writer]]; null where a language has none. */
function roleRows(PDO $pdo): array
{
    return array_slice(keyStrings($pdo, 'legal.google.role'), 1);
}

/** The six keys and the sentence as a database has them - to hold one database to another. */
function googleStrings(PDO $pdo): array
{
    $strings = [];
    foreach ([...array_keys(GOOGLE_WORDS), 'legal.google.role'] as $key) {
        $strings[$key] = keyStrings($pdo, $key);
    }

    return $strings;
}

function buildThrough021(string $suffix): PDO
{
    $pdo = buildIamThrough($suffix, BEFORE_GOOGLE);
    same(GOOGLE_MIGRATION, pendingMigrations($pdo)[0] ?? null, 'built through 021, 022 the first pending');
    same([[ROLE_BEFORE[0], null], [ROLE_BEFORE[1], null]], roleRows($pdo), 'before it: the Privacy page\'s sentence as 020 wrote it, by nobody');

    return $pdo;
}

test('a fresh IAM has run 022: the six keys of the IAM\'s own Google button, each described, in English and an informal Slovak - and the Privacy page says Google is contacted only on a press', function () {
    $pdo = iamDb();
    check(in_array(GOOGLE_MIGRATION, appliedMigrations($pdo), true), '022 recorded');
    foreach (GOOGLE_WORDS as $key => [$english, $slovak]) {
        [$description, $en, $sk] = keyStrings($pdo, $key);
        check(trim((string) $description) !== '', "$key: described");
        same([[$english, null], [$slovak, null]], [$en, $sk], "$key: its English (en.ts's) and its Slovak, by nobody");
        check(preg_match('/\b(?:Vás|Váš|Vaša|Vaše|Vám|Vami|stlačte|vyberte)\b/u', $slovak) !== 1, "$key: the Slovak says ty, never Vy");
    }

    [$description, $en, $sk] = keyStrings($pdo, 'legal.google.role');
    same([[ROLE_AFTER[0], null], [ROLE_AFTER[1], null]], [$en, $sk], 'the Privacy page\'s sentence about Google: only a press loads anything from Google, by nobody');
    check(str_contains((string) $description, 'nothing is loaded from Google until somebody presses'), 'and its key described so: ' . $description);
    foreach (ROLE_AFTER as $text) {
        // Where a link goes is the code's: an administrator rewords strings, never addresses (the 0.6.0 review).
        check(preg_match('~https?:|://|www\.|@~i', $text) !== 1 && !str_contains($text, '{'), 'no address and no placeholder in it: ' . $text);
    }
    $before = $pdo->prepare('SELECT count(*) FROM translations WHERE value IN (?, ?)');
    $before->execute(ROLE_BEFORE);
    same(0, (int) $before->fetchColumn(), 'and 020\'s sentence is nowhere');
});

test('an IAM built through 021 as the files left it: 022 adds its keys and rewords the Privacy page\'s sentence and its description, by nobody - as on a fresh IAM', function () {
    $pdo = buildThrough021('_before_google');
    same(array_fill(0, count(GOOGLE_WORDS), [null, null, null]), array_values(array_map(static fn(string $key): array => keyStrings($pdo, $key), array_keys(GOOGLE_WORDS))), 'no key of the button before it');

    $result = applyMigrationsThrough($pdo, GOOGLE_MIGRATION);
    same([[GOOGLE_MIGRATION], null], [$result['applied'], $result['failed']], '022 applied, its gate passed (' . ($result['error'] ?? '') . ')');
    same(googleStrings(iamDb()), googleStrings($pdo), 'every key, description and string as on a fresh IAM');
});

test('an IAM built through 021 as its owner left it: the sentence an administrator saved stays as saved, with its writer - in 020\'s very words too - and the gate passes', function () {
    $pdo = buildThrough021('_owned_google');
    $owner = (int) $pdo->query("INSERT INTO users (username, email, email_confirmed) VALUES ('owner', 'owner@example.test', TRUE) RETURNING id")->fetchColumn();
    // Each saved on the Translations page: the English put back to 020's very words on purpose, the Slovak reworded.
    $save = $pdo->prepare("UPDATE translations SET value = ?, updated_by = ?, updated_at = '2026-10-09 08:15:00+00' WHERE key_name = 'legal.google.role' AND language_code = ?");
    $save->execute([ROLE_BEFORE[0], $owner, 'en']);
    $save->execute(['Google načíta svoje tlačidlo, keď ho chceš.', $owner, 'sk']);

    $result = applyMigrationsThrough($pdo, GOOGLE_MIGRATION);
    same([[GOOGLE_MIGRATION], null], [$result['applied'], $result['failed']], '022 applied, its gate passed - a saved string in 020\'s words is no failure (' . ($result['error'] ?? '') . ')');
    same([[ROLE_BEFORE[0], $owner], ['Google načíta svoje tlačidlo, keď ho chceš.', $owner]], roleRows($pdo), 'the owner\'s choices as saved, by the owner');
    same(GOOGLE_WORDS['google.signIn'], array_column(array_slice(keyStrings($pdo, 'google.signIn'), 1), 0), 'and the button\'s keys there all the same');
});

test('an IAM built through 021 with the sentence\'s Slovak blanked: it stays blank, and the English, untouched, is reworded', function () {
    $pdo = buildThrough021('_blank_google');
    $pdo->exec("DELETE FROM translations WHERE key_name = 'legal.google.role' AND language_code = 'sk'");

    $result = applyMigrationsThrough($pdo, GOOGLE_MIGRATION);
    same([[GOOGLE_MIGRATION], null], [$result['applied'], $result['failed']], '022 applied, its gate passed (' . ($result['error'] ?? '') . ')');
    same([[ROLE_AFTER[0], null], null], roleRows($pdo), 'the untouched English reworded, by nobody; the blank Slovak still blank');
});

test('a 022 whose gate fails - a Slovak string that did not arrive - leaves nothing behind, and runs once the cause is gone', function () {
    $pdo = buildThrough021('_spoilt_google');

    // Something on the host swallows one Slovak string on its way in: the gate must see it.
    $pdo->exec("CREATE FUNCTION test_022_swallow() RETURNS trigger LANGUAGE plpgsql AS \$\$ BEGIN RETURN NULL; END \$\$");
    $pdo->exec("CREATE TRIGGER test_022_swallow BEFORE INSERT ON translations FOR EACH ROW WHEN (NEW.key_name = 'google.ready' AND NEW.language_code = 'sk') EXECUTE FUNCTION test_022_swallow()");

    $result = applyMigrationsThrough($pdo, GOOGLE_MIGRATION);
    same([[], GOOGLE_MIGRATION], [$result['applied'], $result['failed']], '022 fails');
    check(str_contains((string) $result['error'], 'google.ready'), 'its gate names the key: ' . $result['error']);
    same(0, (int) $pdo->query("SELECT count(*) FROM translation_keys WHERE name LIKE 'google.%'")->fetchColumn(), 'none of its keys is left');
    same([[ROLE_BEFORE[0], null], [ROLE_BEFORE[1], null]], roleRows($pdo), 'the Privacy page\'s sentence as 020 wrote it');
    same(GOOGLE_MIGRATION, pendingMigrations($pdo)[0] ?? null, 'and it still waits');

    $pdo->exec('DROP TRIGGER test_022_swallow ON translations');
    $pdo->exec('DROP FUNCTION test_022_swallow()');
    $result = applyMigrationsThrough($pdo, GOOGLE_MIGRATION);
    same([[GOOGLE_MIGRATION], null], [$result['applied'], $result['failed']], 'the cause gone, the next Apply runs it (' . ($result['error'] ?? '') . ')');
    same(googleStrings(iamDb()), googleStrings($pdo), 'with every key and string');
});

finishTests();
