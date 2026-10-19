<?php

/**
 * Usernames are letters and digits (the owner's decision of 2026-10-19,
 * closing Low 1 of that day's security review): a-z, A-Z and 0-9, at least
 * one and at most 100 (usernameRefusal()). The admin panel's Feedback page
 * names a signed-in sender by their username, and every site shows it: a name
 * that looked like an email address ("From jan@example.com") or turned the
 * direction of the text (U+202E) was a lure in the IAM's own words.
 *
 * The attack it stops, on every way a name is chosen - registration, the
 * account page's rename, the admin panel's create and edit: each such name
 * refused 422 invalid_username, nothing written. What it must not break: an
 * account keeps a name from before the rule, and that name sent back
 * unchanged never stops the rest of a save. The names the IAM makes fit the
 * rule - a Google sign-up's, the first administrator's default -, and the
 * legacy import, a one-time move done, still moves the names it finds.
 *
 * And migration 025, the pages' words for it: error.invalidUsername saying
 * what is allowed (006's words only, where no administrator saved them),
 * common.usernameHint under the field, register.usernameHint gone - on a
 * fresh IAM, on one built through 024 as the files left it and as its owner
 * left it, and a gate that fails loudly.
 */

declare(strict_types=1);

require __DIR__ . '/lib.php';

// This run's own database is a fresh IAM, built from every migration, 025 included.
bootTests();

$adminId = makeUser('admin', 'admin@example.test');
grantRole($adminId, 'iam', 'ADMIN');

const USERNAMES_MIGRATION = '025_usernames_letters_and_digits.sql';
const BEFORE_USERNAMES = '024_whats_new.sql';
const GOOGLE_TEST_CLIENT = 'iam-test.apps.googleusercontent.com';
const PASSWORD = 'correct horse';

/**
 * Names the rule refuses, each with what it is: the review's five first, then
 * more ways one name could pass for another - and one letter too many.
 */
const UNFIT_NAMES = [
    'jan@example.com' => 'an email address',
    "jan\u{202E}novak" => 'a right-to-left override',
    'jan novak' => 'a space',
    'jan.novak' => 'a dot',
    'janNovák' => 'an accented letter',
    'jan-novak' => 'a dash',
    'jan_novak' => 'an underscore',
    "j\u{0430}n" => 'a Cyrillic a (U+0430) beside Latin letters, looking like the a it is not',
    "jan\u{200B}novak" => 'a zero-width space',
    "jan\tnovak" => 'a tab',
    'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa' => 'a 101st letter',
];

/** Names the rule takes: letters and digits, the longest there can be among them. */
const FIT_NAMES = ['Frantisek', 'jan2', 'ABC123xyz', '007', 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa'];

/** The pages' words of 025: [English, Slovak]. */
const USERNAME_HINT = ['Letters and digits only (a–z, A–Z, 0–9).', 'Len písmená bez diakritiky a číslice (a–z, A–Z, 0–9).'];
const INVALID_BEFORE = ['That username cannot be used.', 'Toto používateľské meno sa nedá použiť.'];
const INVALID_AFTER = ['Use only letters and digits (a–z, A–Z, 0–9), at most 100.', 'Použi len písmená bez diakritiky a číslice (a–z, A–Z, 0–9), najviac 100.'];
const OLD_HINT = ['What you would like to be called.', 'Ako ťa máme volať.'];

function admin(): Browser
{
    $browser = new Browser('10.25.0.1');
    same(200, $browser->signIn('admin@example.test', PASSWORD)->status, 'the admin signs in');

    return $browser;
}

/** A browser signed in with a password, from an address of its own. */
function signedIn(string $email, string $ip): Browser
{
    $browser = new Browser($ip);
    $answer = $browser->signIn($email, PASSWORD);
    same(200, $answer->status, "$email signs in: " . $answer->body);

    return $browser;
}

function usernameOf(int $id, ?PDO $pdo = null): ?string
{
    $statement = ($pdo ?? iamDb())->prepare('SELECT username FROM users WHERE id = ?');
    $statement->execute([$id]);
    $name = $statement->fetchColumn();

    return $name === false ? null : (string) $name;
}

function accountsWith(string $email): int
{
    $statement = iamDb()->prepare('SELECT count(*) FROM users WHERE lower(email) = lower(?)');
    $statement->execute([$email]);

    return (int) $statement->fetchColumn();
}

/** How many entries of an action the audit log holds about an account. */
function auditsAbout(string $action, int $userId): int
{
    $statement = iamDb()->prepare("SELECT count(*) FROM anotoki_audit_log WHERE action = ? AND target_type = 'user' AND target_id = ?");
    $statement->execute([$action, (string) $userId]);

    return (int) $statement->fetchColumn();
}

/**
 * An IAM of its own with nobody in it, built from every migration: the first
 * administrator and the legacy import run only into an empty one. The name is
 * this run's database's plus a suffix, so it is dropped with it.
 */
function emptyIam(string $suffix): PDO
{
    $name = throwawayDatabase(testEnvironment()['db'] . $suffix);
    ensureDatabase(testServer(), $name);
    $pdo = connectDatabase(testServer(), $name, false);
    $result = applyPendingMigrations($pdo);
    same(null, $result['failed'], "an empty IAM ({$suffix}) built (" . ($result['error'] ?? '') . ')');

    return $pdo;
}

/**
 * A Google id token for this IAM, signed by a key it is given as Google's: the
 * key cache in this run's storage, which googleSigningKeys() reads before it
 * would ask Google, and a client id in this run's configuration.
 */
function googleToken(string $subject, string $email, string $name): string
{
    static $key = null;

    if ($key === null) {
        $pair = generateRsaKeyPair();
        $key = openssl_pkey_get_private($pair['private_pem']);
        @mkdir(iamStorageDir() . '/cache', 0700, true);
        file_put_contents(
            iamStorageDir() . '/cache/google-jwks.json',
            json_encode([
                'expires_at' => time() + 3600,
                'keys' => ['usernames-test' => ['kty' => 'RSA', 'kid' => 'usernames-test', 'n' => $pair['n'], 'e' => $pair['e']]],
            ]),
        );
        writeTestConfig('google', ['client_id' => GOOGLE_TEST_CLIENT]);
        forgetLocalConfig();
    }

    $header = base64UrlEncode((string) json_encode(['alg' => 'RS256', 'kid' => 'usernames-test', 'typ' => 'JWT']));
    $payload = base64UrlEncode(
        (string) json_encode([
            'iss' => 'https://accounts.google.com',
            'aud' => GOOGLE_TEST_CLIENT,
            'sub' => $subject,
            'email' => $email,
            'email_verified' => true,
            'name' => $name,
            'iat' => time(),
            'exp' => time() + 600,
            // Each its own, as Google writes them: a token is good once (api/google_identity.php).
            'jti' => bin2hex(random_bytes(16)),
        ]),
    );
    openssl_sign("{$header}.{$payload}", $signature, $key, OPENSSL_ALGO_SHA256);

    return "{$header}.{$payload}." . base64UrlEncode($signature);
}

// ── The rule ─────────────────────────────────────────────────────────────────

test('the rule: letters and digits, at least one and at most 100 - anything else refused, the sentence saying what is allowed', function () {
    foreach (FIT_NAMES as $name) {
        same(null, usernameRefusal($name), "$name fits");
    }
    foreach (UNFIT_NAMES as $name => $what) {
        $refusal = (string) usernameRefusal($name);
        check(
            $refusal === 'The username can have only letters and digits: a–z, A–Z and 0–9' || ($what === 'a 101st letter' && $refusal === 'The username can be at most 100 characters'),
            "$what is refused, saying what is allowed: $refusal",
        );
    }
    same('The username cannot be empty', usernameRefusal(''), 'and no name at all');
    // What a body's field() trims is not the rule's to forgive: a line break at either end is refused too.
    check(usernameRefusal("jan\n") !== null && usernameRefusal("\njan") !== null && usernameRefusal("jan\0") !== null, 'a line break or a NUL anywhere');
});

test('registration refuses each such name - 422 invalid_username, no account made; letters and digits make one, unique regardless of case', function () {
    $i = 0;
    foreach (UNFIT_NAMES as $name => $what) {
        $i++;
        $email = "unfit{$i}@example.test";
        $answer = (new Browser("10.25.1.{$i}"))->post('/api/register', ['username' => $name, 'email' => $email, 'password' => 'a long password', 'language' => 'en']);
        same([422, 'invalid_username'], [$answer->status, $answer->code()], "$what: " . $answer->body);
        same(0, accountsWith($email), "$what: no account made");
        check(lastMailTo($email) === null, "$what: nothing mailed");
    }

    foreach (FIT_NAMES as $j => $name) {
        $email = "fit{$j}@example.test";
        $answer = (new Browser("10.25.1.10{$j}"))->post('/api/register', ['username' => $name, 'email' => $email, 'password' => 'a long password', 'language' => 'en']);
        same(201, $answer->status, "$name: " . $answer->body);
        same(
            $name,
            iamDb()
                ->query('SELECT username FROM users WHERE email = ' . iamDb()->quote($email))
                ->fetchColumn(),
            "$name: the account's name as it was typed",
        );
    }
    $taken = (new Browser('10.25.1.200'))->post('/api/register', ['username' => 'FRANTISEK', 'email' => 'other@example.test', 'password' => 'a long password', 'language' => 'en']);
    same([409, 'username_taken'], [$taken->status, $taken->code()], 'a name another account has in other letters\' case');
});

test('the account page refuses each such name for a rename, and keeps the name it had - and the rest of that save; letters and digits are saved', function () {
    $id = makeUser('renamer', 'renamer@example.test', PASSWORD);
    $browser = signedIn('renamer@example.test', '10.25.2.1');

    foreach (UNFIT_NAMES as $name => $what) {
        $answer = $browser->put('/api/account/profile', ['username' => $name]);
        same([422, 'invalid_username'], [$answer->status, $answer->code()], "$what: " . $answer->body);
        same('renamer', usernameOf($id), "$what: the name as it was");
    }
    $answer = $browser->put('/api/account/profile', ['username' => 'jan@example.com', 'theme' => 'dark']);
    same([422, 'invalid_username'], [$answer->status, $answer->code()], 'a refused name in a save with a theme');
    same('auto', $browser->get('/api/account')->json()['preferences']['theme'] ?? null, 'stops the whole save: the theme is not saved either');
    same(0, auditsAbout('account.username_changed', $id), 'and no change of name audited');

    $saved = $browser->put('/api/account/profile', ['username' => 'Renamed2']);
    same([200, 'Renamed2'], [$saved->status, $saved->json()['username'] ?? null], 'letters and digits are saved: ' . $saved->body);
    same(['Renamed2', 1], [usernameOf($id), auditsAbout('account.username_changed', $id)], 'and audited');
});

test('the admin panel refuses each such name, making an account or renaming one, and writes nothing; letters and digits are taken', function () {
    $admin = admin();
    $i = 0;
    foreach (UNFIT_NAMES as $name => $what) {
        $i++;
        $email = "made{$i}@example.test";
        $made = $admin->post('/api/admin/users', ['username' => $name, 'email' => $email, 'password' => PASSWORD]);
        same([422, 'invalid_username'], [$made->status, $made->code()], "$what: " . $made->body);
        same(0, accountsWith($email), "$what: no account made");
    }
    // The panel shows the server's own sentence: it says what is allowed.
    same(
        'The username can have only letters and digits: a–z, A–Z and 0–9',
        $admin->post('/api/admin/users', ['username' => 'jan@example.com', 'email' => 'made@example.test', 'password' => PASSWORD])->json()['error'] ?? null,
        'the sentence the panel shows',
    );

    $made = $admin->post('/api/admin/users', ['username' => 'Handmade1', 'email' => 'handmade1@example.test', 'password' => PASSWORD]);
    same([201, 'Handmade1'], [$made->status, $made->json()['username'] ?? null], 'letters and digits make an account: ' . $made->body);
    $id = (int) $made->json()['id'];

    foreach (UNFIT_NAMES as $name => $what) {
        $edited = $admin->put("/api/admin/users/$id", ['username' => $name]);
        same([422, 'invalid_username'], [$edited->status, $edited->code()], "$what, as a new name: " . $edited->body);
        same('Handmade1', usernameOf($id), "$what: the name as it was");
    }
    $edited = $admin->put("/api/admin/users/$id", ['username' => 'jan@example.com', 'email' => 'handmade-moved@example.test']);
    same([422, 'invalid_username'], [$edited->status, $edited->code()], 'a refused name beside a new address');
    same(0, accountsWith('handmade-moved@example.test'), 'stops the whole change: the address is not changed either');

    $edited = $admin->put("/api/admin/users/$id", ['username' => 'Handmade2']);
    same([200, 'Handmade2'], [$edited->status, $edited->json()['username'] ?? null], 'letters and digits rename it');
});

// ── A name from before the rule ──────────────────────────────────────────────

test('an account keeps a name from before the rule: sent back unchanged it is no change, never checked, and never stops the rest of a save', function () {
    // Made before 2026-10-19, or brought over from the genshin site: the database holds it as it was.
    $id = makeUser('Ján Novák', 'jan.novak@example.test', PASSWORD);
    $browser = signedIn('jan.novak@example.test', '10.25.4.1');
    same('Ján Novák', $browser->get('/api/account')->json()['username'] ?? null, 'the account page shows it');

    $saved = $browser->put('/api/account/profile', ['language' => 'sk']);
    same([200, 'sk', 'Ján Novák'], [$saved->status, $saved->json()['language'] ?? null, $saved->json()['username'] ?? null], 'its language saved: ' . $saved->body);
    // The account sent back whole - its name with it, as a client sending every field does.
    $saved = $browser->put('/api/account/profile', ['username' => 'Ján Novák', 'theme' => 'dark', 'date_format' => 'dmy_dot']);
    same(
        [200, 'Ján Novák', 'dark', 'dmy_dot'],
        [$saved->status, $saved->json()['username'] ?? null, $saved->json()['preferences']['theme'] ?? null, $saved->json()['preferences']['date_format'] ?? null],
        'its theme and dates saved, and its unchanged name with them: ' . $saved->body,
    );
    // The spaces round it do not make it another name.
    $saved = $browser->put('/api/account/profile', ['username' => ' Ján Novák ', 'time_format' => '24']);
    same([200, 'Ján Novák', '24'], [$saved->status, $saved->json()['username'] ?? null, $saved->json()['preferences']['time_format'] ?? null], 'nor do the spaces round it: ' . $saved->body);
    same(['Ján Novák', 0], [usernameOf($id), auditsAbout('account.username_changed', $id)], 'the name as it was, and no change of name audited: there was none');

    // A new name is held to the rule - another case of the same letters is a new name.
    foreach (['Ján Nováková', 'ján novák', 'jan@example.com'] as $name) {
        $refused = $browser->put('/api/account/profile', ['username' => $name, 'theme' => 'light']);
        same([422, 'invalid_username'], [$refused->status, $refused->code()], "$name is a new name, and does not fit");
    }
    same(['Ján Novák', 'dark'], [usernameOf($id), $browser->get('/api/account')->json()['preferences']['theme'] ?? null], 'nothing of those saves written');

    // An administrator changes its address, the name sent back as the panel had it - and anything without it.
    $admin = admin();
    $edited = $admin->put("/api/admin/users/$id", ['username' => 'Ján Novák', 'email' => 'jan.novak2@example.test']);
    same(
        [200, 'Ján Novák', 'jan.novak2@example.test'],
        [$edited->status, $edited->json()['username'] ?? null, $edited->json()['email'] ?? null],
        'its address changed by an administrator: ' . $edited->body,
    );
    same(200, $admin->put("/api/admin/users/$id", ['force_password_change' => false, 'username' => ' Ján Novák '])->status, 'the spaces round it no change there either');
    same([422, 'invalid_username'], [($r = $admin->put("/api/admin/users/$id", ['username' => 'Ján Novák 2']))->status, $r->code()], 'a new name from the panel is held to the rule');

    // Nor is it written back (the security review of 2026-10-19): the copy the route read may be older than a rename the
    // account made meanwhile, which writing it back would undo. While a trigger refuses every write of the name, the
    // edit still goes through - and the name alone, sent back, is the account as it is: nothing written, nothing audited.
    $pdo = iamDb();
    $pdo->exec(
        <<<'SQL'
        CREATE FUNCTION username_written() RETURNS trigger LANGUAGE plpgsql AS $$
        BEGIN
            RAISE EXCEPTION 'the username was written';
        END
        $$
        SQL
        ,
    );
    $pdo->exec('CREATE TRIGGER username_written BEFORE UPDATE OF username ON users FOR EACH ROW EXECUTE FUNCTION username_written()');
    try {
        $edited = $admin->put("/api/admin/users/$id", ['username' => 'Ján Novák', 'email' => 'jan.novak3@example.test']);
        same([200, 'jan.novak3@example.test'], [$edited->status, $edited->json()['email'] ?? null], 'the address changed, the name sent back never written: ' . $edited->body);
        $audited = auditsAbout('user.updated', $id);
        $alone = $admin->put("/api/admin/users/$id", ['username' => 'Ján Novák']);
        same([200, 'Ján Novák'], [$alone->status, $alone->json()['username'] ?? null], 'the name alone, sent back: the account as it is');
        same($audited, auditsAbout('user.updated', $id), '...and nothing audited, as nothing changed');
    } finally {
        $pdo->exec('DROP TRIGGER username_written ON users');
        $pdo->exec('DROP FUNCTION username_written()');
    }

    // Renamed to letters and digits, the old name is a new name too: it cannot come back.
    same(200, $browser->put('/api/account/profile', ['username' => 'JanNovak'])->status, 'renamed to letters and digits');
    same([422, 'invalid_username'], [($r = $browser->put('/api/account/profile', ['username' => 'Ján Novák']))->status, $r->code()], 'and the old name is refused as a new one');
    same('JanNovak', usernameOf($id), 'kept');
});

// ── The names the IAM makes ──────────────────────────────────────────────────

test('a name made of what somebody wrote: the Latin letters with a mark written plain, everything else left out', function () {
    foreach (
        [
            ['František Pastorek', 'FrantisekPastorek'],
            ['frantisek.pastorek', 'frantisekpastorek'],
            ['Ľubomír Šťastný', 'LubomirStastny'],
            ['ÁÄČĎÉĚÍĹĽŇÓÔÖŔŘŠŤÚŮÜÝŽ áäčďéěíĺľňóôöŕřšťúůüýž', 'AACDEEILLNOOORRSTUUUYZaacdeeillnooorrstuuuyz'],
            ['Jürgen Groß', 'JurgenGross'],
            ['STRAẞE', 'STRASSE'],
            ['François Lefèvre-Œuvray', 'FrancoisLefevreOEuvray'],
            ['Øystein Ærø', 'OysteinAEro'],
            ['Þórður Guðmundsson', 'THordurGudmundsson'],
            ['Łukasz Żółć', 'LukaszZolc'],
            ['Ștefan Țepeș, Şerban Ţuţea', 'StefanTepesSerbanTutea'],
            ['José Núñez', 'JoseNunez'],
            ['Ĳsbrand', 'IJsbrand'],
            ["Frantis\u{030C}ek", 'Frantisek'],
            ["\u{202E}moc.elpmaxe@naj", 'mocelpmaxenaj'],
            ['jan@example.com', 'janexamplecom'],
            ['Agent 47 🎮', 'Agent47'],
            ['李小龙', ''],
            ['Иван Петров', ''],
            ["J\xC3n\xFF", 'Jn'],
            ['', ''],
        ]
        as [$written, $made]
    ) {
        same($made, usernameFrom($written), "$written");
        if ($made !== '') {
            same(null, usernameRefusal($made), "$made fits");
        }
    }
    same(str_repeat('A', 100), usernameFrom(str_repeat('Á', 120)), 'at most 100');
    same(str_repeat('A', 90), usernameFrom(str_repeat('Á', 120), 90), 'or fewer when asked');
});

test('a Google sign-up\'s name is made to fit: Google\'s name for the person, else the address, else player - and a number while it is taken', function () {
    $pdo = iamDb();
    same('FrantisekPastorek', availableUsername($pdo, 'František Pastorek', 'frantisek.pastorek@example.test'), 'the display name, its accents written plain');
    same('frantisekpastorek', availableUsername($pdo, '', 'frantisek.pastorek@example.test'), 'no display name: the address before the @');
    same('xiaolongli', availableUsername($pdo, '李小龙', 'xiaolong.li@example.test'), 'a name of another script\'s letters leaves nothing: the address');
    same('player', availableUsername($pdo, 'Иван Петров', 'иван@example.test'), 'and an address of them too: player');
    same('player', availableUsername($pdo, '', ''), 'nothing at all: player');

    makeUser('FrantisekPastorek', 'taken1@example.test');
    same('FrantisekPastorek2', availableUsername($pdo, 'František Pastorek', 'frantisek.pastorek@example.test'), 'taken: the number');
    makeUser('frantisekpastorek2', 'taken2@example.test');
    same('FrantisekPastorek3', availableUsername($pdo, 'František Pastorek', 'frantisek.pastorek@example.test'), 'taken in another case too: the next number');
    makeUser('player', 'taken3@example.test');
    same('player2', availableUsername($pdo, '李小龙', 'иван@example.test'), 'player taken: player2');

    $long = availableUsername($pdo, str_repeat('Á', 120), 'long@example.test');
    same(str_repeat('A', 90), $long, 'a long name: 90 letters, room for the number');
    makeUser($long, 'taken4@example.test');
    same(str_repeat('A', 90) . '2', availableUsername($pdo, str_repeat('Á', 120), 'long@example.test'), 'which fits within 100 when it comes');
});

test('signing up with Google makes an account named to fit, through the endpoint', function () {
    $pdo = iamDb();
    $made = (new Browser('10.25.5.1'))->post('/api/login/google', ['credential' => googleToken('google-lubo', 'lubo@example.test', 'Ľubomír Šťastný'), 'language' => 'sk']);
    same([201, true], [$made->status, $made->json()['created'] ?? null], 'made: ' . $made->body);
    same('LubomirStastny', $made->json()['user']['username'] ?? null, 'its name, the accents written plain');

    $again = (new Browser('10.25.5.2'))->post('/api/login/google', ['credential' => googleToken('google-lubo-2', 'lubo2@example.test', 'Ľubomír Šťastný')]);
    same([201, 'LubomirStastny2'], [$again->status, $again->json()['user']['username'] ?? null], 'another of the same name: the number');

    $script = (new Browser('10.25.5.3'))->post('/api/login/google', ['credential' => googleToken('google-li', 'xiaolong.li@example.test', '李小龙')]);
    same([201, 'xiaolongli'], [$script->status, $script->json()['user']['username'] ?? null], 'a name of another script: the address before the @');

    $lure = (new Browser('10.25.5.4'))->post('/api/login/google', ['credential' => googleToken('google-lure', 'lure@example.test', "\u{202E}moc.elpmaxe@naj")]);
    same([201, 'mocelpmaxenaj'], [$lure->status, $lure->json()['user']['username'] ?? null], 'a display name made a lure: no address and no turn of the text left');

    foreach (['lubo@example.test', 'lubo2@example.test', 'xiaolong.li@example.test', 'lure@example.test'] as $email) {
        same(null, usernameRefusal((string) userByEmail($pdo, $email)['username']), "$email: its name fits the rule");
    }
});

test('the first administrator\'s name is made of the address the same way, else admin - and a name given is held to the rule', function () {
    $pdo = emptyIam('_first_admin');
    foreach (
        [
            'frantisek.pastorek@example.test' => 'frantisekpastorek',
            'admin@localhost' => 'admin',
            'Ján.Novák+iam@example.test' => 'JanNovakiam',
            'админ@example.test' => 'admin',
            '...@example.test' => 'admin',
            '0@example.test' => '0',
        ]
        as $email => $expected
    ) {
        $created = createFirstAdmin($pdo, $email, 'a long enough one');
        same($expected, $created['username'] ?? null, "$email: the name it says");
        $id = (int) $pdo->query('SELECT id FROM users WHERE email = ' . $pdo->quote($email))->fetchColumn();
        same($expected, usernameOf($id, $pdo), "$email: the account's name");
        same(null, usernameRefusal($expected), "$expected fits");
        // Gone again, so the next one is the first administrator too.
        $pdo->prepare('DELETE FROM user_roles WHERE user_id = ?')->execute([$id]);
        $pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$id]);
    }

    // No caller gives one today (the installer and create-admin.php give none); one given is held to the rule.
    try {
        createFirstAdmin($pdo, 'given@example.test', 'a long enough one', 'Ján Novák');
        check(false, 'a name that does not fit is refused');
    } catch (InvalidArgumentException $refused) {
        same('The username can have only letters and digits: a–z, A–Z and 0–9', $refused->getMessage(), 'refused, saying why');
    }
    same(0, (int) $pdo->query('SELECT count(*) FROM users')->fetchColumn(), 'and nothing made');
    same('Given1', createFirstAdmin($pdo, 'given@example.test', 'a long enough one', 'Given1')['username'] ?? null, 'one that fits is the name');
});

test('the legacy import - the one-time move, done - moves the names it finds as they are', function () {
    $legacy = makeLegacyDatabase('_usernames_legacy');
    $legacy
        ->prepare(
            "INSERT INTO users (id, role, username, email, email_confirmed, password, language) VALUES
            (1, 'ADMIN', 'Ján Novák', 'jan@example.test', TRUE, ?, 'sk'),
            (2, 'USER', 'jan@example.com', 'mail@example.test', TRUE, ?, 'en')",
        )
        ->execute([password_hash(PASSWORD, PASSWORD_DEFAULT), password_hash(PASSWORD, PASSWORD_DEFAULT)]);
    $pdo = emptyIam('_usernames_import');

    $summary = importLegacyAccounts($pdo, $legacy, 'genshin', false, TEST_ISSUER);
    same(2, $summary['accounts'], 'both brought over');
    same(['Ján Novák', 'jan@example.com'], $pdo->query('SELECT username FROM users ORDER BY id')->fetchAll(PDO::FETCH_COLUMN), 'with the names the genshin site had');
});

// ── Migration 025: the pages' words ──────────────────────────────────────────

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

/** The three keys 025 touches, as a database has them - to hold one database to another. */
function usernameStrings(PDO $pdo): array
{
    $strings = [];
    foreach (['common.usernameHint', 'error.invalidUsername', 'register.usernameHint'] as $key) {
        $strings[$key] = keyStrings($pdo, $key);
    }

    return $strings;
}

function buildThrough024(string $suffix): PDO
{
    $pdo = buildIamThrough($suffix, BEFORE_USERNAMES);
    same(USERNAMES_MIGRATION, pendingMigrations($pdo)[0] ?? null, 'built through 024, 025 the first pending');
    same([[INVALID_BEFORE[0], null], [INVALID_BEFORE[1], null]], array_slice(keyStrings($pdo, 'error.invalidUsername'), 1), 'before it: 006\'s words for a name refused, by nobody');
    same([[OLD_HINT[0], null], [OLD_HINT[1], null]], array_slice(keyStrings($pdo, 'register.usernameHint'), 1), 'and 006\'s line under the register page\'s field');
    same([null, null, null], keyStrings($pdo, 'common.usernameHint'), 'and no new line yet');

    return $pdo;
}

test('a fresh IAM has run 025: the line under the field in English and an informal Slovak, the refusal saying what is allowed, the old line gone', function () {
    $pdo = iamDb();
    check(in_array(USERNAMES_MIGRATION, appliedMigrations($pdo), true), '025 recorded');

    [$description, $en, $sk] = keyStrings($pdo, 'common.usernameHint');
    check(str_contains((string) $description, 'register page') && str_contains((string) $description, 'No placeholders.'), 'common.usernameHint described: ' . $description);
    same([[USERNAME_HINT[0], null], [USERNAME_HINT[1], null]], [$en, $sk], 'its English (en.ts\'s) and its Slovak, by nobody');
    same([[INVALID_AFTER[0], null], [INVALID_AFTER[1], null]], array_slice(keyStrings($pdo, 'error.invalidUsername'), 1), 'error.invalidUsername says what is allowed, by nobody');
    same([null, null, null], keyStrings($pdo, 'register.usernameHint'), 'register.usernameHint is gone, with its strings');

    foreach ([...USERNAME_HINT, ...INVALID_AFTER] as $text) {
        check(str_contains($text, 'a–z, A–Z, 0–9'), 'each names what is allowed: ' . $text);
        // Where a link goes is the code's: an administrator rewords strings, never addresses (the 0.6.0 review).
        check(preg_match('~https?:|://|www\.|@~i', $text) !== 1 && !str_contains($text, '{'), 'no address and no placeholder in it: ' . $text);
    }
    foreach ([USERNAME_HINT[1], INVALID_AFTER[1]] as $slovak) {
        check(preg_match('/\b(?:Vás|Váš|Vaša|Vaše|Vám|Vami|Použite|použite)\b/u', $slovak) !== 1, 'the Slovak says ty, never Vy: ' . $slovak);
    }
});

test('an IAM built through 024 as the files left it: 025 adds the line, rewords the refusal and takes the old line away, by nobody - as on a fresh IAM', function () {
    $pdo = buildThrough024('_before_usernames');

    $result = applyMigrationsThrough($pdo, USERNAMES_MIGRATION);
    same([[USERNAMES_MIGRATION], null], [$result['applied'], $result['failed']], '025 applied, its gate passed (' . ($result['error'] ?? '') . ')');
    same(usernameStrings(iamDb()), usernameStrings($pdo), 'every key, description and string as on a fresh IAM');
});

test('an IAM built through 024 as its owner left it: the refusal an administrator saved stays as saved, with its writer - in 006\'s very words too -, and the gate passes', function () {
    $pdo = buildThrough024('_owned_usernames');
    $owner = (int) $pdo->query("INSERT INTO users (username, email, email_confirmed) VALUES ('owner', 'owner@example.test', TRUE) RETURNING id")->fetchColumn();
    // Each saved on the Translations page: the English put back to 006's very words on purpose, the Slovak reworded - and the old line reworded.
    $save = $pdo->prepare("UPDATE translations SET value = ?, updated_by = ?, updated_at = '2026-10-09 08:15:00+00' WHERE key_name = ? AND language_code = ?");
    $save->execute([INVALID_BEFORE[0], $owner, 'error.invalidUsername', 'en']);
    $save->execute(['Také meno nejde.', $owner, 'error.invalidUsername', 'sk']);
    $save->execute(['Your name on every anotoki site.', $owner, 'register.usernameHint', 'en']);
    // ...and a Slovak string elsewhere blanked: the panel deletes the row.
    $pdo->exec("DELETE FROM translations WHERE key_name = 'common.save' AND language_code = 'sk'");

    $result = applyMigrationsThrough($pdo, USERNAMES_MIGRATION);
    same([[USERNAMES_MIGRATION], null], [$result['applied'], $result['failed']], '025 applied, its gate passed - a saved string in 006\'s words is no failure (' . ($result['error'] ?? '') . ')');
    same([[INVALID_BEFORE[0], $owner], ['Také meno nejde.', $owner]], array_slice(keyStrings($pdo, 'error.invalidUsername'), 1), 'the owner\'s choices as saved, by the owner');
    same([null, null, null], keyStrings($pdo, 'register.usernameHint'), 'the old line gone, the owner\'s rewording with it: nothing shows it any more');
    same(keyStrings(iamDb(), 'common.usernameHint'), keyStrings($pdo, 'common.usernameHint'), 'the new line as on a fresh IAM');
    same(0, (int) $pdo->query("SELECT count(*) FROM translations WHERE key_name = 'common.save' AND language_code = 'sk'")->fetchColumn(), 'and the Slovak string the owner blanked stays blank');
});

test('an IAM built through 024 with the refusal\'s Slovak blanked: it stays blank, and the English, untouched, is reworded', function () {
    $pdo = buildThrough024('_blank_usernames');
    $pdo->exec("DELETE FROM translations WHERE key_name = 'error.invalidUsername' AND language_code = 'sk'");

    $result = applyMigrationsThrough($pdo, USERNAMES_MIGRATION);
    same([[USERNAMES_MIGRATION], null], [$result['applied'], $result['failed']], '025 applied, its gate passed (' . ($result['error'] ?? '') . ')');
    same([[INVALID_AFTER[0], null], null], array_slice(keyStrings($pdo, 'error.invalidUsername'), 1), 'the untouched English reworded, by nobody; the blank Slovak still blank');
});

test('a 025 whose gate fails - a Slovak string that did not arrive - leaves nothing behind, and runs once the cause is gone', function () {
    $pdo = buildThrough024('_spoilt_usernames');
    $before = usernameStrings($pdo);

    // Something on the host swallows one Slovak string on its way in: the gate must see it.
    $pdo->exec("CREATE FUNCTION test_025_swallow() RETURNS trigger LANGUAGE plpgsql AS \$\$ BEGIN RETURN NULL; END \$\$");
    $pdo->exec(
        "CREATE TRIGGER test_025_swallow BEFORE INSERT ON translations FOR EACH ROW WHEN (NEW.key_name = 'common.usernameHint' AND NEW.language_code = 'sk') EXECUTE FUNCTION test_025_swallow()",
    );

    $result = applyMigrationsThrough($pdo, USERNAMES_MIGRATION);
    same([[], USERNAMES_MIGRATION], [$result['applied'], $result['failed']], '025 fails');
    check(str_contains((string) $result['error'], 'common.usernameHint'), 'its gate names the key: ' . $result['error']);
    same($before, usernameStrings($pdo), 'nothing of it is left: no new line, the refusal and the old line as 006 wrote them');
    same(USERNAMES_MIGRATION, pendingMigrations($pdo)[0] ?? null, 'and it still waits');

    $pdo->exec('DROP TRIGGER test_025_swallow ON translations');
    $pdo->exec('DROP FUNCTION test_025_swallow()');
    $result = applyMigrationsThrough($pdo, USERNAMES_MIGRATION);
    same([[USERNAMES_MIGRATION], null], [$result['applied'], $result['failed']], 'the cause gone, the next Apply runs it (' . ($result['error'] ?? '') . ')');
    same(usernameStrings(iamDb()), usernameStrings($pdo), 'with every key and string');
});

finishTests();
