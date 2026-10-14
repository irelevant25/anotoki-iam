<?php

/**
 * Migration 020, the legal pages' facts: the IAM's own words around the
 * family's Terms, Privacy and Contact pages (anotoki-lib 0.6.0) - seventy
 * page keys, each with a description, its English (en.ts's, which
 * translations.test.php holds to it) and its Slovak, informal - and the
 * sign-in pages' sky made true beside the Privacy page: 006's "Nothing is
 * shared with anybody outside anotoki" reworded where nobody saved it.
 *
 * 020 arrives at installed IAMs, so besides a fresh database it is applied to
 * one built through 019 the way its owner may have left it - a Slovak string
 * elsewhere blanked, the sky saved by an administrator (its English put back
 * to 006's very words on purpose, its Slovak reworded): the owner's choices
 * stay, with their writer, and none is a reason for its gate to fail - and
 * its gate is shown failing loudly, leaving nothing behind, when a string
 * does not arrive.
 *
 * And what the 0.6.0 review asked of every site: no address in a string an
 * administrator can reword. Where the pages' links go - the account page,
 * the operator, Cloudflare, Google - is the code's (frontend
 * core/legal/legal-facts.ts and the library), never a string's.
 */

declare(strict_types=1);

require __DIR__ . '/lib.php';

// This run's own database is a fresh IAM, built from every migration, 020 included.
bootTests();

const LEGAL_MIGRATION = '020_legal_pages.sql';
const BEFORE_LEGAL = '019_authenticator_pause.sql';
const LEGAL_KEY_COUNT = 70;
/** The sign-in pages' sky, brand.point3: what 006 wrote, and what 020 makes of it - English, Slovak. */
const SKY_BEFORE = ['Nothing is shared with anybody outside anotoki.', 'Nič sa nezdieľa s nikým mimo anotoki.'];
const SKY_AFTER = ['Nothing is sold or used for ads; Privacy says who else handles data.', 'Nič sa nepredáva ani nepoužíva na reklamu; kto ďalší spracúva údaje, nájdeš v Súkromí.'];

/** The sky's line as a database has it: [English, Slovak]; null where a language has none. */
function skyLine(PDO $pdo): array
{
    return array_column(skyRows($pdo), 0);
}

/** The sky's line with who saved it: [[English, its writer], [Slovak, its writer]]; [null, null] where a language has none. */
function skyRows(PDO $pdo): array
{
    $rows = [];
    foreach (['en', 'sk'] as $code) {
        $row = $pdo->prepare("SELECT value, updated_by FROM translations WHERE key_name = 'brand.point3' AND language_code = ?");
        $row->execute([$code]);
        $found = $row->fetch(PDO::FETCH_NUM);
        $rows[] = $found === false ? [null, null] : [$found[0], $found[1] === null ? null : (int) $found[1]];
    }

    return $rows;
}

/** The keys 020's gate names - which must be every key the file writes. */
function legalGateKeys(): array
{
    $sql = (string) file_get_contents(dirname(__DIR__) . '/migrations/iam/' . LEGAL_MIGRATION);
    preg_match('/FROM \(VALUES (.*?)\) AS wanted\(name\)/s', $sql, $gate);
    preg_match_all("/\\('([A-Za-z.]+)'\\)/", $gate[1] ?? '', $keys);

    return $keys[1];
}

/** The legal pages' keys as a database has them: name => [description, English, Slovak]; null where there is none. */
function legalStrings(PDO $pdo): array
{
    $strings = [];
    foreach ($pdo->query(
        "SELECT k.name, k.description,
                (SELECT value FROM translations WHERE key_name = k.name AND language_code = 'en') AS en,
                (SELECT value FROM translations WHERE key_name = k.name AND language_code = 'sk') AS sk
           FROM translation_keys k WHERE k.name LIKE 'legal.%' ORDER BY k.name COLLATE \"C\""
    )->fetchAll() as $row) {
        $strings[$row['name']] = [$row['description'], $row['en'], $row['sk']];
    }

    return $strings;
}

function sortedKeys(array $keys): array
{
    sort($keys, SORT_STRING);

    return $keys;
}

test('a fresh IAM has run 020: the legal pages\' seventy keys, each described, in English and in an informal Slovak - and none holds an address', function () {
    check(in_array(LEGAL_MIGRATION, appliedMigrations(iamDb()), true), '020 recorded');
    $strings = legalStrings(iamDb());
    same(LEGAL_KEY_COUNT, count($strings), 'seventy keys under legal.');
    same(sortedKeys(array_keys($strings)), sortedKeys(legalGateKeys()), 'the gate names every key the file writes, and no other');

    foreach ($strings as $key => [$description, $english, $slovak]) {
        check(trim((string) $description) !== '' && trim((string) $english) !== '' && trim((string) $slovak) !== '', "$key: a description, an English and a Slovak string");
        foreach (['en' => $english, 'sk' => $slovak] as $code => $text) {
            // Where a link goes is the code's: an administrator rewords strings, never addresses (the 0.6.0 review).
            check(preg_match('~https?:|://|www\.|@~i', (string) $text) !== 1, "$key ($code) holds no address: $text");
            // The facts are said as they are: nothing fills them in.
            check(!str_contains((string) $text, '{') && !str_contains((string) $text, '}'), "$key ($code) has no placeholder");
        }
        check(preg_match('/\b(?:Vás|Váš|Vaša|Vaše|Vám|Vami)\b/u', (string) $slovak) !== 1, "$key: the Slovak says ty, never Vy");
    }
    same('Ochrana osobných údajov', $strings['legal.privacyPageTitle'][2], 'the Privacy tab\'s Slovak title, as the library heads the page');
    same(['About the anotoki account', 'O anotoki účte'], array_slice($strings['legal.termsWhatTitle'], 1), 'Terms\' first heading in natural words - not the library\'s "What anotoki account is"');
    check(str_contains((string) $strings['legal.deletion.what'][1], '30 days'), 'the deletion\'s thirty days, as api/account_deletion.php has them');
    check(str_contains((string) $strings['legal.backups.kept'][1], 'The 14 newest daily copies and the 5 newest made before an update'), 'the backups\' keeping, as iamBackups() keeps them');

    // The sign-in pages' sky: no longer "nothing is shared" beside a page that names who else handles data.
    same(SKY_AFTER, skyLine(iamDb()), 'the sky\'s line, true beside the Privacy page, in English and Slovak');
    $before = iamDb()->prepare('SELECT count(*) FROM translations WHERE value IN (?, ?)');
    $before->execute(SKY_BEFORE);
    same(0, (int) $before->fetchColumn(), 'and 006\'s words are nowhere');
});

test('an IAM built through 019, as its owner left it: 020 adds its keys and strings; the sky an administrator saved stays theirs - in 006\'s very words too -, and a blanked string stays blank', function () {
    $pdo = buildIamThrough('_before_legal', BEFORE_LEGAL);
    same(LEGAL_MIGRATION, pendingMigrations($pdo)[0] ?? null, 'built through 019, 020 the first pending');
    same([], legalStrings($pdo), 'no legal key before it');
    same([[SKY_BEFORE[0], null], [SKY_BEFORE[1], null]], skyRows($pdo), 'before it: the sky as 006 wrote it, by nobody');

    // What the owner may have done on the Translations page, each saved by an administrator: the sky's English put back to
    // 006's very words on purpose, its Slovak reworded - and a Slovak string elsewhere blanked (the panel deletes it).
    $owner = (int) $pdo->query("INSERT INTO users (username, email, email_confirmed) VALUES ('owner', 'owner@example.test', TRUE) RETURNING id")->fetchColumn();
    $save = $pdo->prepare("UPDATE translations SET value = ?, updated_by = ?, updated_at = '2026-10-09 08:15:00+00' WHERE key_name = 'brand.point3' AND language_code = ?");
    $save->execute([SKY_BEFORE[0], $owner, 'en']);
    $save->execute(['Nič nepredávame, nikomu.', $owner, 'sk']);
    $pdo->exec("DELETE FROM translations WHERE key_name = 'common.save' AND language_code = 'sk'");

    $result = applyMigrationsThrough($pdo, LEGAL_MIGRATION);
    same([[LEGAL_MIGRATION], null], [$result['applied'], $result['failed']], '020 applied, its gate passed - a saved string in 006\'s words is no failure (' . ($result['error'] ?? '') . ')');
    same(legalStrings(iamDb()), legalStrings($pdo), 'every key, description and string as on a fresh IAM');
    same([[SKY_BEFORE[0], $owner], ['Nič nepredávame, nikomu.', $owner]], skyRows($pdo), 'the owner\'s choices as the owner saved them, by the owner: 006\'s English kept, the Slovak reworded kept');
    same(0, (int) $pdo->query("SELECT count(*) FROM translations WHERE key_name = 'common.save' AND language_code = 'sk'")->fetchColumn(), 'and the Slovak string the owner blanked stays blank');
});

test('a 020 whose gate fails - a Slovak string that did not arrive - leaves nothing behind, and runs once the cause is gone', function () {
    $pdo = buildIamThrough('_spoilt_legal', BEFORE_LEGAL);

    // Something on the host swallows one Slovak string on its way in: the gate must see it.
    $pdo->exec("CREATE FUNCTION test_020_swallow() RETURNS trigger LANGUAGE plpgsql AS \$\$ BEGIN RETURN NULL; END \$\$");
    $pdo->exec("CREATE TRIGGER test_020_swallow BEFORE INSERT ON translations FOR EACH ROW WHEN (NEW.key_name = 'legal.about' AND NEW.language_code = 'sk') EXECUTE FUNCTION test_020_swallow()");

    $result = applyMigrationsThrough($pdo, LEGAL_MIGRATION);
    same([[], LEGAL_MIGRATION], [$result['applied'], $result['failed']], '020 fails');
    check(str_contains((string) $result['error'], 'legal.about'), 'its gate names the key: ' . $result['error']);
    same([], legalStrings($pdo), 'nothing of it is left');
    same(LEGAL_MIGRATION, pendingMigrations($pdo)[0] ?? null, 'and it still waits');

    $pdo->exec('DROP TRIGGER test_020_swallow ON translations');
    $pdo->exec('DROP FUNCTION test_020_swallow()');
    $result = applyMigrationsThrough($pdo, LEGAL_MIGRATION);
    same([[LEGAL_MIGRATION], null], [$result['applied'], $result['failed']], 'the cause gone, the next Apply runs it (' . ($result['error'] ?? '') . ')');
    same(LEGAL_KEY_COUNT, count($pdo->query("SELECT name FROM translation_keys WHERE name LIKE 'legal.%'")->fetchAll()), 'with every key');
});

finishTests();
