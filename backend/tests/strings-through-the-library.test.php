<?php

/**
 * Migration 017, "the IAM's words, through the anotoki library": the language
 * switcher's two keys moved under the library's (lang.label ->
 * anotoki.language.label, lang.button -> anotoki.language.button) with every
 * string, writer and date; sessions.method.other and sessions.ended.other
 * renamed .unknown, their strings following by the foreign key. And then the
 * library's own set, anotoki_translations, which runs after the IAM's: 001
 * proves the tables' shape, 002 adds the library's words - only where a
 * library key has no string at all.
 *
 * 017 arrives at installed IAMs, so besides a fresh database it is applied to
 * one built through 016 the way its owner may have left it: lang.button
 * reworded in Slovak and sessions.method.other in English, each by an
 * administrator at a time of their own; the Slovak lang.label and
 * sessions.ended.other blanked; a string elsewhere reworded. Every one of them
 * must be there under the new keys exactly - word, writer, time, blank - and
 * nothing else may change: the bundles before and after differ only by the
 * four keys' names and the library's words added.
 *
 * And the window. A build is uploaded before anybody presses Apply: on a live
 * IAM, 017 and the library's set wait while everything runs on the new code.
 * The IAM does not close meanwhile (blockWhilePending false) - the sign-in
 * pages must keep their words, and every way in must work.
 */

declare(strict_types=1);

require __DIR__ . '/lib.php';

use Anotoki\Lib\Translations\LibraryWords;

// This run's own database is a fresh IAM, built from every migration: 017, then the library's set.
bootTests();

const LIBRARY_MIGRATION = '017_strings_through_the_library.sql';
const BEFORE_LIBRARY = '016_account_deletion_and_new_sign_in_mail.sql';

/**
 * The library's translations set, in the order its files run - after every
 * file of the IAM's: 001 proves the tables, 002 the kit's and status page's
 * words, and the files of later releases more of its words.
 */
function libraryFiles(): array
{
    return array_values(array_filter(migrationFiles(), static fn(string $file): bool => str_starts_with($file, 'anotoki_translations/')));
}

/**
 * What a database built through 016 has waiting: 017 and every file of the
 * IAM's after it, then the library's translations set (its admin set runs
 * before the IAM's files, and was applied with them).
 */
function pendingAfter016(): array
{
    $iam = migrationFiles('iam');

    return [...array_slice($iam, (int) array_search(LIBRARY_MIGRATION, $iam, true)), ...libraryFiles()];
}

/** The four keys 017 moves: the name they had => the name they have. */
const MOVED_KEYS = [
    'lang.label' => 'anotoki.language.label',
    'lang.button' => 'anotoki.language.button',
    'sessions.method.other' => 'sessions.method.unknown',
    'sessions.ended.other' => 'sessions.ended.unknown',
];

/**
 * The keys the IAM's own files after 017 write - 019's, the authenticator's
 * pause, 020's, the legal pages' facts, and 022's, the IAM's own Google
 * button: applying all that waits after 016 brings them too, and their own
 * files' tests hold them (authenticator-pause.test.php,
 * legal-pages-strings.test.php, google-button-strings.test.php). A later file
 * that adds keys adds them here - this test holds 017 and the library's set.
 */
const LATER_PAGE_KEYS = [
    'error.authenticatorPaused', 'login.paused.title', 'login.paused.text', 'login.paused.emailLead', 'login.paused.sendCode', 'login.paused.codeSent', 'login.paused.passwordFirst',
    'login.paused.emailSpent', 'sessions.method.password_email',
    'legal.termsPageTitle', 'legal.privacyPageTitle', 'legal.contactPageTitle', 'legal.about', 'legal.termsWhatTitle', 'legal.account.name', 'legal.account.what',
    'legal.account.why', 'legal.account.kept', 'legal.twoFactor.name', 'legal.twoFactor.what', 'legal.twoFactor.why', 'legal.twoFactor.kept',
    'legal.google.name', 'legal.google.what', 'legal.google.why', 'legal.google.kept', 'legal.sessions.name', 'legal.sessions.what',
    'legal.sessions.why', 'legal.sessions.kept', 'legal.attempts.name', 'legal.attempts.what', 'legal.attempts.why', 'legal.attempts.kept',
    'legal.remembered.name', 'legal.remembered.what', 'legal.remembered.why', 'legal.remembered.kept', 'legal.browsers.name', 'legal.browsers.what',
    'legal.browsers.why', 'legal.browsers.kept', 'legal.sites.name', 'legal.sites.what', 'legal.sites.why', 'legal.sites.kept', 'legal.mails.name',
    'legal.mails.what', 'legal.mails.why', 'legal.mails.kept', 'legal.news.name', 'legal.news.what', 'legal.news.why', 'legal.news.kept',
    'legal.approvals.name', 'legal.approvals.what', 'legal.approvals.why', 'legal.approvals.kept', 'legal.deletion.name', 'legal.deletion.what',
    'legal.deletion.why', 'legal.deletion.kept', 'legal.audit.what', 'legal.audit.why', 'legal.audit.kept', 'legal.backups.what', 'legal.backups.why',
    'legal.backups.kept', 'legal.storage.session.purpose', 'legal.storage.session.kept', 'legal.storage.device.purpose', 'legal.storage.device.kept',
    'legal.storage.remembered.purpose', 'legal.storage.remembered.kept', 'legal.storage.tabLanguage.purpose', 'legal.storage.drafts.purpose',
    'legal.storage.untilTabClosed', 'legal.google.role', 'legal.mail.role',
    'google.signIn', 'google.signUp', 'google.continue', 'google.frame', 'google.ready', 'google.failed',
];
const LATER_MAIL_KEYS = ['mail.authenticatorPaused.subject', 'mail.authenticatorPaused.greeting', 'mail.authenticatorPaused.intro', 'mail.authenticatorPaused.ignore'];

/**
 * The strings of keys from before 017 that the IAM's own files after it
 * reword - 020's: the sign-in pages' sky made true beside the Privacy page;
 * 021's: the sign-in page's button that has a code emailed, in the informal
 * Slovak ("ty") - 'key language' => its words after them. Applying all that
 * waits after 016 brings them too, and their own files' tests hold them
 * (legal-pages-strings.test.php, informal-code-buttons.test.php); 017 keeps
 * every other string as it was. A later file that rewords a string adds it
 * here.
 */
const LATER_REWORDED = [
    'brand.point3 en' => 'Nothing is sold or used for ads; Privacy says who else handles data.',
    'brand.point3 sk' => 'Nič sa nepredáva ani nepoužíva na reklamu; kto ďalší spracúva údaje, nájdeš v Súkromí.',
    'login.useCode sk' => 'Radšej mi pošli kód e-mailom',
];

/** Those keys' strings, as stringsOf() names them: 'key language', in English and Slovak. */
function laterCells(): array
{
    return array_merge(...array_map(static fn(string $key): array => ["$key en", "$key sk"], [...LATER_PAGE_KEYS, ...LATER_MAIL_KEYS]));
}

const SITE_CALLBACK = 'http://localhost:4200/auth/callback';
const SITE_ORIGIN = 'http://localhost:4200';
const PASSWORD = 'correct horse';

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
    // Each database has its own signing key, remembered for the process.
    activeSigningKey(true);
}

/** Every string a database has: 'key language' => [text, who wrote it, when]. */
function stringsOf(PDO $pdo): array
{
    $strings = [];
    foreach ($pdo->query('SELECT key_name, language_code, value, updated_by, updated_at::text AS at FROM translations ORDER BY key_name COLLATE "C", language_code COLLATE "C"')->fetchAll() as $row) {
        $strings[$row['key_name'] . ' ' . $row['language_code']] = [$row['value'], $row['updated_by'] === null ? null : (int) $row['updated_by'], $row['at']];
    }

    return $strings;
}

/** Every key a database has: name => [description, when it was made]. */
function keysOf(PDO $pdo): array
{
    $keys = [];
    foreach ($pdo->query('SELECT name, description, created_at::text AS made FROM translation_keys ORDER BY name COLLATE "C"')->fetchAll() as $row) {
        $keys[$row['name']] = [$row['description'], $row['made']];
    }

    return $keys;
}

/**
 * A map whose keys - 'key' or 'key language' - are under the names 017 gave
 * them, in the order a bundle and the queries above keep (byte order).
 */
function underNewNames(array $map): array
{
    $renamed = [];
    foreach ($map as $key => $value) {
        [$name, $rest] = array_pad(explode(' ', (string) $key, 2), 2, null);
        $name = MOVED_KEYS[$name] ?? $name;
        $renamed[$rest === null ? $name : "$name $rest"] = $value;
    }
    ksort($renamed, SORT_STRING);

    return $renamed;
}

/** Whether 017's snapshot of the moved strings is still in a database: it never should be after the file. */
function snapshotLeft(PDO $pdo): bool
{
    return asBool($pdo->query("SELECT to_regclass('iam_017_moved_words') IS NOT NULL")->fetchColumn());
}

/** The library's keys 017 did not move there: the ones its own files write (002 and later). */
function libraryKeysOf002(): array
{
    return array_values(array_diff(LibraryWords::keys(), array_values(MOVED_KEYS)));
}

/** The bundle in a language, as a page asks for it, on the database the app points at. */
function bundle(string $code): array
{
    $answer = (new Browser('10.31.9.9'))->get("/api/translations/$code");
    same(200, $answer->status, "the bundle ($code): " . substr($answer->body, 0, 200));

    return $answer->json();
}

/**
 * Holds a bundle after 017 and the library's set to the one before: the same
 * language and languages; the same strings, but for the four keys under their
 * new names; and the library's other keys added (002's and its later files'), in the library's words.
 */
function sameButRenamedAndLibrary(array $before, array $after, string $code): void
{
    same([$before['language'], $before['languages']], [$after['language'], $after['languages']], "$code: the same language, the same languages on offer");
    foreach (['values', 'english'] as $map) {
        if (!isset($before[$map])) {
            same(false, isset($after[$map]), "$code: no $map before, none after");
            continue;
        }
        $expected = underNewNames($before[$map]);
        // What a later file rewords reads its words: in this language's own strings (`values`), and an English
        // rewording in the English beside another language's (`english`) too.
        foreach (LATER_REWORDED as $cell => $words) {
            [$key, $language] = explode(' ', $cell);
            if (($map === 'english' ? $language === 'en' : $language === $code) && isset($expected[$key])) {
                $expected[$key] = $words;
            }
        }
        $added = array_diff_key($after[$map], $expected, array_flip(LATER_PAGE_KEYS));
        same($expected, array_intersect_key($after[$map], $expected), "$code $map: every string as it was, the four keys under their new names");
        same(LATER_PAGE_KEYS, array_values(array_intersect(LATER_PAGE_KEYS, array_keys($after[$map]))), "$code $map: the later files' own keys, as their files have them");
        same(sorted(libraryKeysOf002()), sorted(array_keys($added)), "$code $map: and nothing else but the library's other keys");
        $words = $map === 'english' || $code === 'en' ? 'en' : $code;
        same(array_map(static fn(string $key): string => LibraryWords::all()[$key][$words], libraryKeysOf002()), array_map(static fn(string $key): string => $added[$key], libraryKeysOf002()), "$code $map: in the library's words");
    }
}

function sorted(array $list): array
{
    sort($list, SORT_STRING);

    return $list;
}

/** A signed-in browser's sign-in to the genshin site, through to its tokens: the token answer. */
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

test('a fresh IAM has run 017 and then the library\'s set: the four keys under their new names, the library\'s words beside the IAM\'s', function () {
    $pdo = iamDb();
    same(['anotoki_admin', 'iam', 'anotoki_translations'], array_map(static fn($set): string => $set->name, iamMigrationSets()), 'the library\'s translations set after the IAM\'s (its admin set before them) - the API, the installer and the command line all apply through iamMigrator()');
    $applied = appliedMigrations($pdo);
    same(libraryFiles(), array_slice($applied, -count(libraryFiles())), 'the library\'s translations set, the last applied');
    check(array_search(LIBRARY_MIGRATION, $applied, true) < array_search(libraryFiles()[0], $applied, true), '017 before it');
    same(libraryFiles(), $pdo->query("SELECT filename FROM migrations WHERE filename LIKE 'anotoki_translations/%' ORDER BY id")->fetchAll(PDO::FETCH_COLUMN), 'recorded in the IAM\'s own table as set/name, which needs no folder column');
    same(false, snapshotLeft($pdo), '017\'s snapshot of the moved strings is gone with the file');

    $strings = stringsOf($pdo);
    foreach ([
        'anotoki.language.label' => ['Language', 'Jazyk'],
        'anotoki.language.button' => ['Language: {name} ({code})', 'Jazyk: {name} ({code})'],
        'sessions.method.unknown' => ['Other', 'Iné'],
        'sessions.ended.unknown' => ['Ended', 'Ukončené'],
    ] as $key => $words) {
        same($words, [$strings["$key en"][0] ?? null, $strings["$key sk"][0] ?? null], "$key in English and Slovak, the words the IAM always had");
    }
    $keys = keysOf($pdo);
    foreach (array_keys(MOVED_KEYS) as $old) {
        check(!isset($keys[$old]) && !isset($strings["$old en"]) && !isset($strings["$old sk"]), "$old is gone, with its strings");
    }

    // The library's words: every key, in English and Slovak, as its file has them, with its description.
    foreach (LibraryWords::all() as $key => $words) {
        same([$words['en'], $words['sk'], $words['description']], [$strings["$key en"][0] ?? null, $strings["$key sk"][0] ?? null, $keys[$key][0] ?? null], "$key, as the library has it");
    }
    // No key of the IAM's ends in a plural form's name: the family's scan would take it for one.
    same([], array_values(array_filter(array_keys($keys), static fn(string $key): bool => preg_match('/\.(zero|one|two|few|many|other)$/D', $key) === 1)), 'no key ending in a plural form');
    // What carried the renamed keys' strings: the foreign key, cascading when a key is renamed.
    same(['c', 'c'], array_values($pdo->query("SELECT confupdtype::text AS updated, confdeltype::text AS deleted FROM pg_constraint WHERE contype = 'f' AND conrelid = 'translations'::regclass AND confrelid = 'translation_keys'::regclass")->fetch()), 'translations.key_name follows translation_keys.name on update, and goes with it on delete');
});

test('an IAM built through 016, as its owner left it: 017 moves every string exactly - word, writer, time, blank - and the bundles change by nothing else', function () {
    $main = testEnvironment()['db'];
    $pdo = buildIamThrough('_installed', BEFORE_LIBRARY);
    same(pendingAfter016(), pendingMigrations($pdo), 'built through 016: 017, the IAM\'s files after it and the library\'s translations set wait');

    // What the owner did on the Translations page, each saved by an administrator at a time of its own.
    $owner = (int) $pdo->query("INSERT INTO users (username, email, email_confirmed) VALUES ('owner', 'owner@example.test', TRUE) RETURNING id")->fetchColumn();
    $reword = $pdo->prepare('UPDATE translations SET value = ?, updated_by = ?, updated_at = ? WHERE key_name = ? AND language_code = ?');
    $reword->execute(['Jazyk stránky: {name} ({code})', $owner, '2026-10-09 08:15:00+00', 'lang.button', 'sk']);
    $reword->execute(['Some other way', $owner, '2026-10-09 08:16:30.25+00', 'sessions.method.other', 'en']);
    $reword->execute(['Ulož', $owner, '2026-10-09 08:17:00+00', 'common.save', 'sk']);
    // ...and blanked: the panel deletes the row, and Slovak reads the English there.
    $pdo->exec("DELETE FROM translations WHERE language_code = 'sk' AND key_name IN ('lang.label', 'sessions.ended.other')");

    $strings = stringsOf($pdo);
    $keys = keysOf($pdo);
    useDatabase($main . '_installed');
    try {
        $bundles = ['en' => bundle('en'), 'sk' => bundle('sk')];
        same(['Jazyk stránky: {name} ({code})', 'Language'], [$bundles['sk']['values']['lang.button'] ?? null, $bundles['sk']['values']['lang.label'] ?? null], 'before: the owner\'s words, and English where Slovak was blanked');

        $result = applyPendingMigrations($pdo);
        same([pendingAfter016(), null], [$result['applied'], $result['failed']], '017 and the library\'s set applied, every gate passed (' . ($result['error'] ?? '') . ')');
        same(false, snapshotLeft($pdo), 'and 017\'s snapshot of the moved strings is not left behind');

        // The strings: every one as it was - the four keys' under their new names - and the library's others added, written by nobody.
        $after = stringsOf($pdo);
        $expected = array_diff_key(underNewNames($strings), LATER_REWORDED);
        same($expected, array_intersect_key($after, $expected), 'every string as it was: word, writer, time');
        foreach (LATER_REWORDED as $cell => $words) {
            same([$words, null], array_slice($after[$cell] ?? [], 0, 2), "$cell: as the later file rewords it, by nobody");
        }
        $added = array_diff_key($after, $expected, array_flip(laterCells()), LATER_REWORDED);
        same(laterCells(), array_values(array_intersect(laterCells(), array_keys($after))), 'the later files\' own keys, in English and Slovak');
        same(sorted(array_merge(...array_map(static fn(string $key): array => ["$key en", "$key sk"], libraryKeysOf002()))), sorted(array_keys($added)), 'and nothing else but the library\'s other keys, in English and Slovak');
        foreach ($added as $cell => [$value, $by]) {
            [$key, $code] = explode(' ', $cell);
            same([LibraryWords::all()[$key][$code], null], [$value, $by], "$cell: the library's words, written by nobody");
        }
        same(['Jazyk stránky: {name} ({code})', $owner], array_slice($after['anotoki.language.button sk'], 0, 2), 'the owner\'s Slovak button name, under the library\'s key, by the owner');
        same($strings['lang.button sk'], $after['anotoki.language.button sk'], 'and from when the owner saved it');
        same($strings['sessions.method.other en'], $after['sessions.method.unknown en'], 'the owner\'s English for an unknown way of signing in, renamed with who and when');
        same(['Some other way', $owner], array_slice($after['sessions.method.unknown en'], 0, 2), 'its words and its writer');
        check(!isset($after['anotoki.language.label sk']), 'the Slovak the owner blanked stays blank: the library\'s 002 writes only for a key with no string at all');
        check(!isset($after['sessions.ended.unknown sk']), 'and the blanked Slovak of the renamed key too');
        same($strings['common.save sk'], $after['common.save sk'], 'a rewording elsewhere is untouched');

        // The keys: the IAM's as they were - the renamed two with their descriptions and their age -, the library's with its descriptions.
        $keysAfter = keysOf($pdo);
        foreach ($keys as $name => $key) {
            if (!isset(MOVED_KEYS[$name])) {
                same($key, $keysAfter[$name] ?? null, "$name as it was");
            }
        }
        same([$keys['sessions.method.other'], $keys['sessions.ended.other']], [$keysAfter['sessions.method.unknown'] ?? null, $keysAfter['sessions.ended.unknown'] ?? null], 'the renamed keys, their descriptions and when they were made');
        foreach (LibraryWords::keys() as $name) {
            same(LibraryWords::all()[$name]['description'], $keysAfter[$name][0] ?? null, "$name: the library's description");
        }
        same(count($keys) - 2 + count(LibraryWords::keys()) + count(LATER_PAGE_KEYS) + count(LATER_MAIL_KEYS), count($keysAfter), 'two keys gone, every key of the library\'s there - and the later files\' own');

        // The bundles the pages read: before and after differ by the four names and the library's words, nothing else.
        foreach (['en', 'sk'] as $code) {
            sameButRenamedAndLibrary($bundles[$code], bundle($code), $code);
        }
        $slovak = bundle('sk');
        same(['Jazyk stránky: {name} ({code})', 'Language', 'Ended', 'Some other way'], [
            $slovak['values']['anotoki.language.button'], $slovak['values']['anotoki.language.label'], $slovak['values']['sessions.ended.unknown'], $slovak['english']['sessions.method.unknown'],
        ], 'what a Slovak page reads: the owner\'s words, English where the owner blanked it');
    } finally {
        useDatabase($main);
    }
});

test('a 017 whose gate fails leaves nothing behind - no snapshot, every key and string as it was, nothing recorded - and runs once the cause is gone', function () {
    $pdo = buildIamThrough('_spoilt', BEFORE_LIBRARY);
    $strings = stringsOf($pdo);
    $keys = keysOf($pdo);

    // Something on the host spoils the strings as they arrive under the library's keys: the gate must see it.
    $pdo->exec("CREATE FUNCTION test_017_spoil() RETURNS trigger LANGUAGE plpgsql AS \$\$ BEGIN NEW.value := NEW.value || ' (spoilt)'; RETURN NEW; END \$\$");
    $pdo->exec("CREATE TRIGGER test_017_spoil BEFORE INSERT ON translations FOR EACH ROW WHEN (NEW.key_name LIKE 'anotoki.%') EXECUTE FUNCTION test_017_spoil()");

    $result = applyPendingMigrations($pdo);
    same([[], LIBRARY_MIGRATION], [$result['applied'], $result['failed']], '017 fails, and nothing after it runs');
    check(str_contains((string) $result['error'], 'strings that did not arrive under their new keys as they were'), 'its gate says why: ' . $result['error']);
    same(false, snapshotLeft($pdo), 'the snapshot went back with the rest of the file');
    same([$strings, $keys], [stringsOf($pdo), keysOf($pdo)], 'every key and string as it was - the four old keys among them');
    same(pendingAfter016(), pendingMigrations($pdo), 'and 017 still waits, with the library\'s set behind it');

    $pdo->exec('DROP TRIGGER test_017_spoil ON translations');
    $pdo->exec('DROP FUNCTION test_017_spoil()');
    $result = applyPendingMigrations($pdo);
    same([pendingAfter016(), null], [$result['applied'], $result['failed']], 'the cause gone, the next Apply runs it (' . ($result['error'] ?? '') . ')');
    same(false, snapshotLeft($pdo), 'and leaves no snapshot');
});

test('the window: built through 016, the new code, nothing applied - the sign-in pages read their words, every way in works, and Apply brings 017 and the library\'s set', function () {
    $main = testEnvironment()['db'];
    buildIamThrough('_window', BEFORE_LIBRARY);
    useDatabase($main . '_window');

    try {
        $pdo = iamDb();
        $adminId = makeUser('admin', 'admin@example.test', PASSWORD);
        grantRole($adminId, 'iam', 'ADMIN');
        makeUser('person', 'person@example.test', PASSWORD);
        $seeded = stringsOf($pdo);

        $visitor = new Browser('10.31.0.1');
        same(['state' => 'update_pending', 'installed' => false], $visitor->get('/api/site-status')->json(), 'an update waits - and closes nothing');

        // The sign-in pages' words, in both languages, as the database has them.
        foreach (['en', 'sk'] as $code) {
            $words = bundle($code)['values'];
            foreach (['login.title', 'login.submit', 'register.title', 'common.save'] as $key) {
                same($seeded["$key $code"][0] ?? null, $words[$key] ?? null, "$key ($code)");
            }
            check(!isset($words['anotoki.language.label']), "$code: the library's words are not in the database yet - the pages say them from the library's built-in words meanwhile");
        }
        same([200, ['en', 'sk']], [($r = $visitor->get('/api/public/config'))->status, $r->json()['languages'] ?? null], 'the pages\' config');
        same(['en', 'sk'], $visitor->get('/.well-known/openid-configuration')->json()['ui_locales_supported'] ?? null, 'discovery');

        // Every way in.
        $person = new Browser('10.31.0.2');
        same(200, $person->signIn('person@example.test', PASSWORD)->status, 'a password');
        same([200, 'person'], [($r = $person->get('/api/account'))->status, $r->json()['username'] ?? null], 'the account page');
        $tokens = signInToTheSite(new Browser('10.31.0.3'), 'person@example.test');
        same(200, $tokens->status, 'a site\'s sign-in, through to its tokens: ' . $tokens->body);
        same(['MEMBER'], verifyLikeAnApp(new Browser(), (string) ($tokens->json()['access_token'] ?? ''), 'genshin')['roles'] ?? null, 'with a token the site accepts');

        same(200, (new Browser('10.31.0.4'))->post('/api/login/code', ['email' => 'person@example.test'])->status, 'an emailed code is asked for');
        check(preg_match('/^Your anotoki sign-in code: (\d{6})$/', lastMailTo('person@example.test')['subject'] ?? '', $code) === 1, 'and mailed');
        same(200, (new Browser('10.31.0.4'))->post('/api/login/code/verify', ['email' => 'person@example.test', 'code' => $code[1]])->status, 'and it signs in');

        $newcomer = new Browser('10.31.0.5');
        $registered = $newcomer->post('/api/register', ['username' => 'Nováčik', 'email' => 'novacik@example.test', 'password' => 'a long password', 'language' => 'sk']);
        same([201, true], [$registered->status, $registered->json()['sent'] ?? null], 'a registration, in Slovak: ' . $registered->body);
        same($seeded['mail.confirm.subject sk'][0], lastMailTo('novacik@example.test')['subject'] ?? null, 'its mail in the Slovak the database has');
        same(200, (new Browser('10.31.0.6'))->post('/api/password/forgot', ['email' => 'novacik@example.test'])->status, 'a reset is asked for');
        same($seeded['mail.reset.subject sk'][0], lastMailTo('novacik@example.test')['subject'] ?? null, 'and mailed in Slovak');

        // The admin panel's Translations and Languages work on the strings as they are, and a save there survives the update.
        $admin = new Browser('10.31.0.7');
        same(200, $admin->signIn('admin@example.test', PASSWORD)->status, 'the administrator signs in to press Apply');
        $languages = $admin->get('/api/admin/languages')->json()['languages'] ?? [];
        same([['en', count(array_filter(array_keys($seeded), static fn(string $cell): bool => str_ends_with($cell, ' en'))), 2], ['sk', count(array_filter(array_keys($seeded), static fn(string $cell): bool => str_ends_with($cell, ' sk'))), 1]],
            array_map(static fn(array $language): array => [$language['code'], $language['strings'], $language['accounts']], $languages), 'the languages, with their strings and accounts (the newcomer\'s Slovak among them)');
        same(200, $admin->put('/api/admin/translations', ['values' => ['common.save' => ['sk' => 'Ulož']]])->status, 'a rewording, saved while the update waits');
        same(['lang.label', 'sessions.method.other'], array_values(array_intersect(['lang.label', 'sessions.method.other'], array_column($admin->get('/api/admin/translations')->json()['keys'] ?? [], 'name'))), 'the grid has the keys as they are');

        $pending = $admin->get('/api/admin/migrations')->json()['pending'] ?? [];
        same(pendingAfter016(), migrationNames($pending), 'what Apply will bring: 017, then the library\'s set');

        $applied = $admin->post('/api/admin/migrations/apply');
        same([200, pendingAfter016(), null], [$applied->status, migrationNames($applied->json()['applied'] ?? []), $applied->json()['failed'] ?? null], 'Apply: ' . $applied->body);
        $entry = json_decode((string) $pdo->query("SELECT details FROM anotoki_audit_log WHERE action = 'migrations.applied' ORDER BY id DESC LIMIT 1")->fetchColumn(), true);
        same(pendingAfter016(), $entry['applied'] ?? null, 'audited, each file by the name the table records');

        // From the next request on: the four keys under their new names, the library's words beside them, the owner's save kept.
        $slovak = bundle('sk');
        same(['Jazyk', 'Jazyk: {name} ({code})', 'Iné', 'Ukončené', 'Ulož'], [
            $slovak['values']['anotoki.language.label'] ?? null, $slovak['values']['anotoki.language.button'] ?? null,
            $slovak['values']['sessions.method.unknown'] ?? null, $slovak['values']['sessions.ended.unknown'] ?? null, $slovak['values']['common.save'] ?? null,
        ], 'the moved and renamed keys, and the rewording saved in the window');
        check(!isset($slovak['values']['lang.label']) && !isset($slovak['values']['sessions.method.other']), 'under their old names no more');
        same(LibraryWords::all()['anotoki.siteStatus.updatingTitle']['sk'], $slovak['values']['anotoki.siteStatus.updatingTitle'] ?? null, 'and the library\'s own words');
        same(['state' => 'ready', 'installed' => false], $visitor->get('/api/site-status')->json(), 'up to date');
    } finally {
        useDatabase($main);
    }
});

finishTests();
