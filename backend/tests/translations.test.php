<?php

/**
 * The IAM's own words in the database, through the anotoki family's library
 * (anotoki/lib's Anotoki\Lib\Translations, on the IAM's configuration in
 * api/translations.php): the languages, the strings of the pages and the
 * mails, and the admin panel's routes for them.
 *
 * The library's own tests hold what every site's words do - its checks of a
 * string, the bundle, the grid, saves and their locks, the languages' limits,
 * the body it reads, the key scan, the file format, the command. What is held
 * here is the IAM's:
 *
 *   - The window. A build is uploaded before its migrations are applied, from
 *     the admin panel, by somebody who has to sign in first. So the first
 *     test runs the app on a database that stops before 006: everything a
 *     sign-in needs still works, the mails go out in the English kept in the
 *     code, and the bundle says "not yet" instead of failing.
 *   - The mails. Their words are strings somebody can edit, so the six
 *     messages are compared byte for byte with what the code used to write,
 *     and the ways a string could be turned against the recipient - a header
 *     in a subject, a link in a paragraph, a placeholder that leaks a token -
 *     are tried both through the panel and straight into the table. Their
 *     rules are the IAM's (IamMailRules), asked by the library's checks.
 *   - The catalogue. What the migrations leave is held to the frontend's
 *     compiled English (shared/i18n/en.ts) and to MAIL_KEYS / MAIL_FALLBACK, so
 *     the three cannot drift apart; the library's words beside them are its
 *     file's (backend/resources/library-words.json).
 *   - The IAM's contract on every route (docs/api.md): its error body, who
 *     may, the CSRF header, the audit log, `accounts`, `ui_locales`, the
 *     `locale` claim, discovery and /api/public/config with the database out
 *     of reach.
 *
 * Migration 017, which moved four keys for the library, and the window before
 * it is applied are tests/strings-through-the-library.test.php's.
 */

declare(strict_types=1);

require __DIR__ . '/lib.php';

use Anotoki\Lib\Audit\AuditLog;
use Anotoki\Lib\Support\Refusal;
use Anotoki\Lib\Translations\Cli\EnglishDictionary;
use Anotoki\Lib\Translations\Cli\KeyScanner;
use Anotoki\Lib\Translations\ETag;
use Anotoki\Lib\Translations\LibraryWords;
use Anotoki\Lib\Translations\Placeholders;
use Anotoki\Lib\Translations\StringCheck;
use Anotoki\Lib\Translations\Text;
use Anotoki\Lib\Translations\TranslationFile;

bootTests();

const STRINGS_MIGRATION = '006_languages_and_translations.sql';
const GENSHIN_CALLBACK = 'http://localhost:4200/auth/callback';
const GENSHIN_ORIGIN = 'http://localhost:4200';

const ENGLISH = ['code' => 'en', 'name' => 'English', 'native_name' => 'English'];
const SLOVAK = ['code' => 'sk', 'name' => 'Slovak', 'native_name' => 'Slovenčina'];

/** The IAM's own keys, as the migrations leave them: 581 of the pages and 52 of the mails (019: the authenticator's pause, 9 and 4; 020: the legal pages' facts, 70 of the pages; 022: the IAM's own Google button, 6 of the pages; 024: What's new, 6 of the pages; 025: the line under the username field, one of the pages in place of another; 026: the confirmation page's press and whom it signed in, 7 of the pages). */
const IAM_PAGE_KEYS = 581;
const IAM_MAIL_KEYS = 52;

// ── Helpers ──────────────────────────────────────────────────────────────────

/**
 * Writes the run's database.local.php for one of this run's databases - on the
 * machine's server, or, with $port, on a port of that host where nothing
 * listens - and lets the configuration and the settings remembered of the old
 * one go. The languages remembered are the caller's to deal with.
 */
function pointAtDatabase(string $name, ?string $port = null): void
{
    $environment = testEnvironment();
    file_put_contents(
        $environment['config'] . DIRECTORY_SEPARATOR . 'database.local.php',
        "<?php\n\n\$config = require " .
            var_export(developmentDatabaseFile(), true) .
            ";\n" .
            "\$config['databases'] = ['iam' => " .
            var_export(throwawayDatabase($name), true) .
            "];\n" .
            ($port === null ? '' : "\$config['port'] = " . var_export($port, true) . ";\n") .
            "\$config['persistent'] = false;\n\nreturn \$config;\n",
    );
    forgetLocalConfig();
    settingsAll(true);
}

/** Points the app at another of this run's databases, and lets nothing remembered of the old one stand. */
function useDatabase(string $name): void
{
    pointAtDatabase($name);
    languageCodes(null, true);
    // The key that signs is remembered for the process too, and each database has its own: a token
    // signed with the other one's would be no token to anybody who checks it against this one.
    activeSigningKey(true);
}

/** An IAM as an upload finds it: a database holding the migrations before 006, and nothing of 006. */
function databaseBeforeStrings(string $suffix): string
{
    $name = throwawayDatabase(testEnvironment()['db'] . $suffix);
    ensureDatabase(testServer(), $name);
    $pdo = connectDatabase(testServer(), $name, false);

    check(in_array(STRINGS_MIGRATION, migrationFiles('iam'), true), 'the strings\' migration is among the files');
    ensureMigrationsTable($pdo);
    foreach (migrationFiles('iam') as $file) {
        if (strnatcmp($file, STRINGS_MIGRATION) < 0) {
            applyMigration($pdo, 'iam', $file);
        }
    }
    check(!in_array(STRINGS_MIGRATION, appliedMigrations($pdo), true), 'built without 006');

    return $name;
}

function authorize(Browser $browser, array $overrides = []): array
{
    [$verifier, $challenge] = pkce();
    $parameters = array_filter(
        $overrides + [
            'response_type' => 'code',
            'client_id' => 'genshin',
            'redirect_uri' => GENSHIN_CALLBACK,
            'scope' => 'openid profile email',
            'state' => bin2hex(random_bytes(8)),
            'nonce' => bin2hex(random_bytes(8)),
            'code_challenge' => $challenge,
            'code_challenge_method' => 'S256',
        ],
        static fn($value) => $value !== null,
    );

    $response = $browser->get('/api/oidc/authorize?' . http_build_query($parameters));

    return ['response' => $response, 'location' => $response->location(), 'verifier' => $verifier, 'parameters' => $parameters];
}

/** Signs in and completes a genshin authorization: the token response. */
function signInToGenshin(Browser $browser, string $email, string $password = 'correct horse'): array
{
    $start = authorize($browser);
    $request = queryOf($start['location'])['request'] ?? null;
    check($request !== null, 'authorize sends an anonymous browser to the login page, sent to ' . $start['location']);
    same(200, $browser->signIn($email, $password)->status, 'sign-in');
    $accept = $browser->post("/api/login/request/{$request}/accept");
    same(200, $accept->status, 'accept: ' . $accept->body);

    $tokens = $browser->request('POST', '/api/oidc/token', [
        'form' => [
            'grant_type' => 'authorization_code',
            'code' => queryOf($accept->json()['redirect'])['code'],
            'redirect_uri' => GENSHIN_CALLBACK,
            'client_id' => 'genshin',
            'code_verifier' => $start['verifier'],
        ],
        'headers' => ['Origin' => GENSHIN_ORIGIN],
    ]);
    same(200, $tokens->status, 'code exchange: ' . $tokens->body);

    return $tokens->json();
}

/** The `locale` an application is told for an account: in a fresh access token, and by userinfo. */
function localeOf(string $email, string $ip): array
{
    $browser = new Browser($ip);
    $tokens = signInToGenshin($browser, $email);
    $claims = verifyLikeAnApp($browser, $tokens['access_token'], 'genshin');
    $info = $browser->get('/api/oidc/userinfo', ['headers' => ['Authorization' => 'Bearer ' . $tokens['access_token']]])->json();

    return [$claims['locale'] ?? null, $info['locale'] ?? null];
}

function admin(): Browser
{
    $browser = new Browser('10.9.0.1');
    same(200, $browser->signIn('admin@example.test', 'correct horse')->status, 'the admin signs in');

    return $browser;
}

/** A save of the grid's changed strings, as the admin page sends it: `values` an object, `{}` for none. */
function save(Browser $browser, array $values): TestResponse
{
    return $browser->put('/api/admin/translations', ['values' => (object) $values]);
}

/** One string as the database has it: [value, updated_by, updated_at] - or null. */
function stored(string $key, string $code): ?array
{
    $statement = iamDb()->prepare('SELECT value, updated_by, updated_at FROM translations WHERE key_name = ? AND language_code = ?');
    $statement->execute([$key, $code]);
    $row = $statement->fetch();

    return $row ? array_values($row) : null;
}

/** Every string the database has, with whose it is: to see that a refused save wrote nothing. */
function everyString(): array
{
    return iamDb()
        ->query("SELECT key_name || ' ' || language_code, value || ' by ' || COALESCE(updated_by::text, 'nobody') || ' at ' || updated_at FROM translations ORDER BY 1")
        ->fetchAll(PDO::FETCH_KEY_PAIR);
}

/** How many strings a language has now. */
function stringsIn(string $code): int
{
    $statement = iamDb()->prepare('SELECT count(*) FROM translations WHERE language_code = ?');
    $statement->execute([$code]);

    return (int) $statement->fetchColumn();
}

/** A language's strings as the migrations left them, before any test reworded one. */
function seeded(string $code): array
{
    return $GLOBALS['seeded'][$code] ?? [];
}

/** A map with its keys in order, all the way down: for comparing what JSONB hands back, which keeps no order of its own. */
function ordered(mixed $value): mixed
{
    if (!is_array($value)) {
        return $value;
    }
    $value = array_map('ordered', $value);
    if (!array_is_list($value)) {
        ksort($value, SORT_STRING);
    }

    return $value;
}

/** The newest audit row of an action: [actor_id, target_type, target_id, details (ordered())] - or null. */
function lastAudit(string $action): ?array
{
    $statement = iamDb()->prepare('SELECT actor_id, target_type, target_id, details FROM anotoki_audit_log WHERE action = ? ORDER BY id DESC LIMIT 1');
    $statement->execute([$action]);
    $row = $statement->fetch();

    return $row ? [$row['actor_id'] === null ? null : (int) $row['actor_id'], $row['target_type'], $row['target_id'], ordered(json_decode((string) $row['details'], true))] : null;
}

/**
 * The newest translations.mail_changed entry - a changed mail string, in an entry of its own
 * (auditTranslations()) - as the save's summary used to carry it: ordered([key, language, old, new]).
 */
function lastMailChange(): ?array
{
    $row = iamDb()->query("SELECT target_type, target_id, details FROM anotoki_audit_log WHERE action = 'translations.mail_changed' ORDER BY id DESC LIMIT 1")->fetch();
    if (!$row) {
        return null;
    }
    $details = (array) json_decode((string) $row['details'], true);
    same('translation', $row['target_type'], 'a mail string\'s entry is about a translation');

    return ordered(['key' => $row['target_id'], 'language' => $details['language'] ?? null, 'old' => $details['old'] ?? null, 'new' => $details['new'] ?? null]);
}

function auditCount(string $action): int
{
    $statement = iamDb()->prepare('SELECT count(*) FROM anotoki_audit_log WHERE action = ?');
    $statement->execute([$action]);

    return (int) $statement->fetchColumn();
}

/** The newest message to an address exactly as the file driver wrote it. */
function rawMailTo(string $email): ?string
{
    $files = glob(testEnvironment()['mail'] . DIRECTORY_SEPARATOR . '*.eml') ?: [];
    sort($files);

    foreach (array_reverse($files) as $file) {
        $text = (string) file_get_contents($file);
        if (str_contains($text, "To: $email\n")) {
            return $text;
        }
    }

    return null;
}

/** The newest row of the mail log for an address: [kind, subject, status]. */
function lastLoggedMail(string $email): ?array
{
    $statement = iamDb()->prepare('SELECT kind, subject, status FROM email_log WHERE recipient = ? ORDER BY id DESC LIMIT 1');
    $statement->execute([$email]);
    $row = $statement->fetch();

    return $row ? array_values($row) : null;
}

/**
 * The three mails in both languages as api/mail.php wrote them while the words
 * were still in the code - copied from it as it was, the day they moved into
 * the database. What is sent now must be this, byte for byte.
 *
 * @return array<string, array<string, array{subject: string, body: string}>>
 */
function mailsAsTheyWere(string $username, string $confirmLink, string $resetLink, string $code): array
{
    return [
        'confirm' => [
            'en' => [
                'subject' => 'Confirm your anotoki account',
                'body' =>
                    "Hello {$username},\n\n" .
                    "Open the link below to confirm this address and finish setting up your anotoki account - one account for every anotoki site:\n\n" .
                    "$confirmLink\n\n" .
                    "The link is good for 48 hours.\n\n" .
                    "If you did not sign up, you can ignore this message - the account cannot be used until somebody opens that link.\n",
            ],
            'sk' => [
                'subject' => 'Potvrď svoj účet anotoki',
                'body' =>
                    "Ahoj {$username},\n\n" .
                    "Otvor odkaz nižšie, potvrď túto adresu a dokonči vytvorenie účtu anotoki - jedného účtu pre všetky stránky anotoki:\n\n" .
                    "$confirmLink\n\n" .
                    "Odkaz platí 48 hodín.\n\n" .
                    "Ak si sa neregistroval, túto správu môžeš ignorovať - účet sa nedá použiť, kým niekto ten odkaz neotvorí.\n",
            ],
        ],
        'reset' => [
            'en' => [
                'subject' => 'Set a new anotoki password',
                'body' =>
                    "Hello {$username},\n\n" .
                    "Somebody asked to set a new password for this account. Open the link below to choose one:\n\n" .
                    "$resetLink\n\n" .
                    "The link is good for 60 minutes and can only be used once.\n\n" .
                    "If it was not you, nothing has changed and there is nothing to do. Your current password still works.\n",
            ],
            'sk' => [
                'subject' => 'Nastavenie nového hesla anotoki',
                'body' =>
                    "Ahoj {$username},\n\n" .
                    "Niekto požiadal o nastavenie nového hesla k tomuto účtu. Otvor odkaz nižšie a zvoľ si ho:\n\n" .
                    "$resetLink\n\n" .
                    "Odkaz platí 60 minút a dá sa použiť len raz.\n\n" .
                    "Ak si to nebol ty, nič sa nezmenilo a nemusíš nič robiť. Tvoje súčasné heslo stále platí.\n",
            ],
        ],
        'loginCode' => [
            'en' => [
                'subject' => "Your anotoki sign-in code: $code",
                'body' =>
                    "Hello {$username},\n\n" .
                    "Your sign-in code is:\n\n    $code\n\n" .
                    "It is good for 10 minutes and can only be used once.\n\n" .
                    "If you did not ask to sign in, you can ignore this message. Nobody can get in with this code unless they can also read this mailbox.\n",
            ],
            'sk' => [
                'subject' => "Tvoj prihlasovací kód anotoki: $code",
                'body' =>
                    "Ahoj {$username},\n\n" .
                    "Tvoj prihlasovací kód je:\n\n    $code\n\n" .
                    "Platí 10 minút a dá sa použiť len raz.\n\n" .
                    "Ak si o prihlásenie nežiadal, túto správu môžeš ignorovať. S týmto kódom sa nikto nedostane dnu, pokiaľ nevie čítať aj túto schránku.\n",
            ],
        ],
    ];
}

/**
 * What the library's checks (StringCheck) make of $values on the IAM's
 * configuration - its mail rules, its {app} sentences - on keys of the test's
 * own: the changes, or [code, extra] of the refusal.
 */
function checked(mixed $values, array $english = ['test.hello' => 'Hello, {name}!', 'test.bye' => 'Goodbye']): array
{
    $result = checkedWithIam($values, $english);
    if ($result instanceof Refusal) {
        same(422, $result->status, 'a refused string is a 422');

        return [$result->code, $result->extra];
    }

    return $result;
}

/** The sentence a refusal of those checks says - '' when there is none. */
function refusalSentence(mixed $values, array $english = ['test.hello' => 'Hello, {name}!', 'test.bye' => 'Goodbye']): string
{
    $result = checkedWithIam($values, $english);

    return $result instanceof Refusal ? $result->message : '';
}

function checkedWithIam(mixed $values, array $english): array|Refusal
{
    $keys = array_values(array_unique(['test.hello', 'test.bye', 'test.new', 'login.continueTo', 'register.forApp', ...array_keys(MAIL_KEYS), ...array_keys($english)]));

    return StringCheck::changes(
        $values,
        $keys,
        ['en' => 'English', 'sk' => 'Slovak'],
        $english + ['login.continueTo' => 'Sign in to continue to {app}', 'register.forApp' => 'Create an account to continue to {app}'] + MAIL_FALLBACK,
        iamTranslationsConfig(),
    );
}

/** A small frontend to scan: $files as relative path => contents, written with Windows line endings. */
function sources(array $files): string
{
    static $made = 0;
    $root = testEnvironment()['base'] . DIRECTORY_SEPARATOR . 'sources-' . ++$made;
    foreach ($files as $relative => $contents) {
        if (!is_dir(dirname("$root/$relative"))) {
            mkdir(dirname("$root/$relative"), 0777, true);
        }
        file_put_contents("$root/$relative", str_replace(["\r\n", "\n"], ["\n", "\r\n"], $contents));
    }

    return $root;
}

function sorted(array $list): array
{
    sort($list, SORT_STRING);

    return $list;
}

/**
 * What a call throws, or null - so that "it must throw" is checked outside
 * the try. A failed check is a RuntimeException itself (AssertionFailed): one
 * made inside a try that catches RuntimeException would be caught with the
 * rest, and the test could never fail.
 */
function thrownBy(callable $call): ?RuntimeException
{
    try {
        $call();
    } catch (RuntimeException $e) {
        return $e;
    }

    return null;
}

/** translations.php on this run's database: [exit code, what it printed]. */
function cli(string ...$arguments): array
{
    // The tool reads its configuration where the app does. A constant only code
    // can define says where - so this run's is defined in a file of the run's
    // own, which then hands over to the tool: the tool itself knows nothing of
    // tests, and sees the arguments as if it had been started directly.
    $start = testEnvironment()['base'] . DIRECTORY_SEPARATOR . 'translations-cli.php';
    if (!is_file($start)) {
        file_put_contents(
            $start,
            "<?php\ndefine('IAM_CONFIG_DIR', " .
                var_export(testEnvironment()['config'], true) .
                ");\ndefine('IAM_STORAGE_DIR', " .
                var_export(testEnvironment()['storage'], true) .
                ");\n" .
                'require ' .
                var_export(dirname(__DIR__) . DIRECTORY_SEPARATOR . 'translations.php', true) .
                ";\n",
        );
    }

    exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($start) . ' ' . implode(' ', array_map('escapeshellarg', $arguments)) . ' 2>&1', $lines, $code);

    return [$code, implode("\n", $lines)];
}

// ── The window: new code, the old database ───────────────────────────────────

test(
    'before 006 is applied the IAM still runs: config, discovery, a site\'s sign-in to its tokens, registration, the three mails, the admin\'s accounts - and then Apply brings the strings',
    function () {
        $main = testEnvironment()['db'];
        $window = databaseBeforeStrings('_window');
        useDatabase($window);

        try {
            $pdo = iamDb();
            same($window, $pdo->query('SELECT current_database()')->fetchColumn(), 'the app runs on the database without 006');
            same(false, translationsReady($pdo), 'no tables of 006');
            same(['en', 'sk'], languageCodes(), 'the two languages of before');
            same(['en', 'sk'], languageCodes($pdo), 'and the same through a connection of its own');
            same('sk', languageOrDefault('sk'), 'Slovak is still a language');
            same('en', languageOrDefault('de'), 'German is not');

            $adminId = makeUser('admin', 'admin@example.test');
            grantRole($adminId, 'iam', 'ADMIN');
            makeUser('known', 'known@example.test');

            // What the pages ask for first, and what a site's sign-in needs.
            $config = (new Browser('10.8.0.1'))->get('/api/public/config');
            same([200, ['en', 'sk']], [$config->status, $config->json()['languages'] ?? null], 'public config');
            same(['en', 'sk'], (new Browser('10.8.0.1'))->get('/.well-known/openid-configuration')->json()['ui_locales_supported'] ?? null, 'discovery');
            $start = authorize(new Browser('10.8.0.1'), ['ui_locales' => 'sk']);
            same(302, $start['response']->status, 'authorize redirects');
            same('sk', queryOf($start['location'])['lang'] ?? null, 'authorize with ui_locales=sk opens the sign-in page in Slovak');
            check(str_starts_with($start['location'], TEST_ISSUER . '/login?'), 'at the sign-in page: ' . $start['location']);

            // The pages' strings: not there yet - said so, not a 500 and not "not set up".
            foreach (['en', 'sk'] as $code) {
                $bundle = (new Browser('10.8.0.1'))->get("/api/translations/$code");
                same([503, 'translations_unavailable', '60'], [$bundle->status, $bundle->code(), $bundle->header('Retry-After')], "the bundle ($code), asked again in a minute");
            }

            // Registration, in Slovak: the account is made with its language, and the mail goes out - in the English kept in the code.
            $newcomer = new Browser('10.8.0.2');
            $registered = $newcomer->post('/api/register', ['username' => 'Novacik', 'email' => 'novacik@example.test', 'password' => 'a long password', 'language' => 'sk']);
            same([201, true], [$registered->status, $registered->json()['sent'] ?? null], 'registered, and the mail was sent: ' . $registered->body);
            same('sk', $pdo->query("SELECT language FROM users WHERE email = 'novacik@example.test'")->fetchColumn(), 'with the language the CHECK still allows');
            $mail = lastMailTo('novacik@example.test');
            same('Confirm your anotoki account', $mail['subject'] ?? null, 'the confirmation, in English');
            check(str_starts_with($mail['body'], "Hello Novacik,\n\nOpen the link below"), 'its body is the English one: ' . substr($mail['body'], 0, 60));
            check(preg_match('~^' . preg_quote(TEST_ISSUER, '~') . '/confirm-email\?token=([0-9a-f]{64})$~m', $mail['body'], $found) === 1, 'with the link on a line of its own');
            same(200, $newcomer->post('/api/register/confirm', ['token' => $found[1]])->status, 'and the link confirms the account');
            // The registration page has only its compiled English to show in the window, and sends the
            // language the visitor wished for all the same: the server keeps it, as it did before.
            same('sk', (new Browser('10.8.0.2'))->signIn('novacik@example.test', 'a long password')->json()['user']['language'] ?? null, 'the account is Slovak to whoever signs in to it');
            $loud = (new Browser('10.8.0.9'))->post('/api/register', ['username' => 'Hlasny', 'email' => 'hlasny@example.test', 'password' => 'a long password', 'language' => ' SK ']);
            same([201, 'sk'], [$loud->status, $pdo->query("SELECT language FROM users WHERE email = 'hlasny@example.test'")->fetchColumn()], 'however the code was written');
            $german = (new Browser('10.8.0.10'))->post('/api/register', ['username' => 'Gretchen', 'email' => 'gretchen-early@example.test', 'password' => 'a long password', 'language' => 'de']);
            same(
                [201, 'en'],
                [$german->status, $pdo->query("SELECT language FROM users WHERE email = 'gretchen-early@example.test'")->fetchColumn()],
                'and a language that is neither of the two is an English account - never a row the CHECK refuses',
            );

            // A reset and a sign-in code: the same answer whether or not the address has an account, and a mail for the one that has.
            foreach (['/api/password/forgot' => 'Set a new anotoki password', '/api/login/code' => 'Your anotoki sign-in code: '] as $path => $subject) {
                $known = (new Browser('10.8.0.3'))->post($path, ['email' => 'known@example.test']);
                $unknown = (new Browser('10.8.0.4'))->post($path, ['email' => 'nobody@example.test']);
                same(200, $known->status, "$path answers");
                same(
                    [$known->status, $known->body, $known->headers],
                    [$unknown->status, $unknown->body, $unknown->headers],
                    "$path answers the same, byte for byte, for a known and an unknown address",
                );
                check(lastMailTo('nobody@example.test') === null, "$path mails nobody who has no account");
                $sent = lastMailTo('known@example.test');
                check($sent !== null && str_starts_with($sent['subject'], $subject), "$path mailed the account, in English: " . ($sent['subject'] ?? '-'));
            }
            $reset = (string) rawMailTo('known@example.test'); // the newest is the sign-in code
            check(preg_match('/^Subject: Your anotoki sign-in code: (\d{6})$/m', $reset, $code) === 1, 'the code is in the subject');
            check(str_contains($reset, "Your sign-in code is:\n\n    {$code[1]}\n\nIt is good for 10 minutes"), 'and on a line of its own in the body');
            same([MAIL_KIND_LOGIN_CODE, null, 'sent'], lastLoggedMail('known@example.test'), 'logged, without the subject that carries the code');

            // An account's language, on the account page: the two of before.
            $known = new Browser('10.8.0.5');
            same(200, $known->signIn('known@example.test', 'correct horse')->status, 'signing in works');
            same('sk', $known->put('/api/account/profile', ['language' => 'sk'])->json()['language'] ?? null, 'Slovak can be chosen');
            same('invalid_language', $known->put('/api/account/profile', ['language' => 'de'])->code(), 'German cannot');

            // A site's sign-in, all the way: the code exchanged for tokens, and the site told the language chosen a moment ago.
            same(['sk', 'sk'], localeOf('known@example.test', '10.8.0.8'), 'a site\'s sign-in completes before 006: the code exchange, and the locale in the access token and from userinfo');

            // The admin panel: its string pages say "not yet"; Migrations is where that is mended.
            $admin = new Browser('10.8.0.6');
            same(200, $admin->signIn('admin@example.test', 'correct horse')->status, 'the admin can sign in to press Apply');

            // Its accounts pages work meanwhile, with the two languages of before.
            $made = $admin->post('/api/admin/users', ['username' => 'made', 'email' => 'made@example.test', 'password' => 'correct horse', 'language' => 'sk']);
            same([201, 'sk'], [$made->status, $made->json()['language'] ?? null], 'the admin makes a Slovak account: ' . $made->body);
            same('sk', $pdo->query("SELECT language FROM users WHERE email = 'made@example.test'")->fetchColumn(), 'and Slovak is what the row has');
            $refused = $admin->post('/api/admin/users', ['username' => 'unmade', 'email' => 'unmade@example.test', 'password' => 'correct horse', 'language' => 'de']);
            same([422, 'invalid_language'], [$refused->status, $refused->code()], 'a German one is refused - said so, not left to the CHECK');
            same(0, (int) $pdo->query("SELECT count(*) FROM users WHERE email = 'unmade@example.test'")->fetchColumn(), 'and not made');
            $refused = $admin->put('/api/admin/users/' . $made->json()['id'], ['language' => 'de']);
            same([422, 'invalid_language'], [$refused->status, $refused->code()], 'nor can an account be given German');
            same('en', $admin->put('/api/admin/users/' . $made->json()['id'], ['language' => 'en'])->json()['language'] ?? null, 'English it can');
            foreach (['/api/admin/languages', '/api/admin/translations', '/api/admin/translations/sk/export'] as $path) {
                same([503, 'translations_unavailable'], [$admin->get($path)->status, $admin->get($path)->code()], $path);
            }
            same([503, 'translations_unavailable'], [save($admin, ['common.save' => ['sk' => 'x']])->status, save($admin, ['common.save' => ['sk' => 'x']])->code()], 'nothing to save into');
            same(
                [503, 'translations_unavailable'],
                [($r = $admin->post('/api/admin/languages', ['code' => 'de', 'name' => 'German', 'native_name' => 'Deutsch']))->status, $r->code()],
                'nor a language to add',
            );
            same(
                [401, 'no_session'],
                [(new Browser('10.8.0.6'))->get('/api/admin/languages')->status, (new Browser('10.8.0.6'))->get('/api/admin/languages')->code()],
                'to anybody else they say what they always say: sign in first',
            );
            // 006 is pending, every migration written after it (007 on), and the library's set after them.
            $fromStrings = array_slice(migrationFiles(), array_search(STRINGS_MIGRATION, migrationFiles(), true));
            same(STRINGS_MIGRATION, $fromStrings[0] ?? null, 'the first of what is pending is 006');
            $libraryFiles = array_values(array_filter(migrationFiles(), static fn(string $file): bool => str_starts_with($file, 'anotoki_translations/')));
            same(
                ['anotoki_translations/001_languages_and_strings.sql', 'anotoki_translations/002_library_words.sql'],
                array_slice($libraryFiles, 0, 2),
                'the library\'s translations set: its tables, then its words',
            );
            same($libraryFiles, array_slice($fromStrings, -count($libraryFiles)), 'and its files the last');
            same($fromStrings, migrationNames($admin->get('/api/admin/migrations')->json()['pending'] ?? []), '006 and what came after it are what is pending');

            $applied = $admin->post('/api/admin/migrations/apply');
            same([200, $fromStrings, null], [$applied->status, migrationNames($applied->json()['applied'] ?? []), $applied->json()['failed'] ?? null], 'Apply pending: ' . $applied->body);

            // From the next request on: the strings are there, and the mails are in the account's language.
            $bundle = (new Browser('10.8.0.1'))->get('/api/translations/sk');
            same([200, 'sk', 'Uložiť'], [$bundle->status, $bundle->json()['language'] ?? null, $bundle->json()['values']['common.save'] ?? null], 'the bundle, in Slovak');
            same('Jazyk', $bundle->json()['values']['anotoki.language.label'] ?? null, 'with the library\'s words');
            same(2, count($admin->get('/api/admin/languages')->json()['languages'] ?? []), 'the panel\'s pages work');
            same('sk', $pdo->query("SELECT language FROM users WHERE email = 'novacik@example.test'")->fetchColumn(), 'the account kept its language through the change of constraint');
            same(200, (new Browser('10.8.0.7'))->post('/api/password/forgot', ['email' => 'known@example.test'])->status, 'a reset');
            same('Nastavenie nového hesla anotoki', lastMailTo('known@example.test')['subject'] ?? null, 'now in Slovak, the language the account chose above');
        } finally {
            useDatabase($main);
        }

        same($main, iamDb()->query('SELECT current_database()')->fetchColumn(), 'back on this run\'s own database');
        same(true, translationsReady(iamDb()), 'which has 006');
    },
);

// Everything below runs on this run's own database, built from every migration.
// What the migrations left, before any test rewords a string:
$GLOBALS['seeded'] = [];
foreach (iamDb()->query('SELECT key_name, language_code, value FROM translations ORDER BY key_name COLLATE "C"')->fetchAll() as $row) {
    $GLOBALS['seeded'][$row['language_code']][$row['key_name']] = $row['value'];
}

$adminId = makeUser('admin', 'admin@example.test');
grantRole($adminId, 'iam', 'ADMIN');
$playerId = makeUser('player', 'player@example.test');

// ── The catalogue: what the migrations leave ─────────────────────────────────

test('the two languages the IAM is released in, and every key - the IAM\'s and the library\'s - with an English and a Slovak string', function () {
    $pdo = iamDb();
    same(
        [['en', 'English', 'English', true, 1], ['sk', 'Slovak', 'Slovenčina', true, 2]],
        array_map('array_values', $pdo->query('SELECT code, name, native_name, enabled, sort_order FROM languages ORDER BY sort_order')->fetchAll()),
        'the languages',
    );
    $library = count(LibraryWords::keys());
    same(
        288,
        $library,
        'the library\'s words: 288 keys (anotoki_translations/002 - its kit\'s and status page\'s 54 -, 003 - the sign-in page\'s 9 -, 004 - the feedback form\'s 31 -, 005 - the legal pages\' 122 -, 006 - the top bar\'s 1 -, 007 - the kit\'s pieces\' 4 -, 008 - the tabs\' 2 -, 009 - the input pieces\' 34 -, 012 - the footer\'s 4 - and 013 - the About and Support pages\', the footer\'s two links\' and the feedback form\'s Clear, 27; 010 and 011 reword)',
    );
    same(
        IAM_PAGE_KEYS + IAM_MAIL_KEYS,
        (int) $pdo->query("SELECT count(*) FROM translation_keys WHERE name NOT LIKE 'anotoki.%'")->fetchColumn(),
        'the IAM\'s own: 581 keys of the pages and 52 of the mails',
    );
    same(IAM_PAGE_KEYS + IAM_MAIL_KEYS + $library, (int) $pdo->query('SELECT count(*) FROM translation_keys')->fetchColumn(), 'and the library\'s beside them');
    same(IAM_PAGE_KEYS + IAM_MAIL_KEYS + $library, count(seeded('en')), 'each has an English string');
    same(IAM_PAGE_KEYS + IAM_MAIL_KEYS + $library, count(seeded('sk')), 'and a Slovak one');
    same(0, (int) $pdo->query("SELECT count(*) FROM translation_keys WHERE description IS NULL OR description = ''")->fetchColumn(), 'and a description');
    same(0, (int) $pdo->query('SELECT count(*) FROM translations WHERE updated_by IS NOT NULL')->fetchColumn(), 'written by nobody');
    foreach (array_keys(seeded('en')) as $key) {
        check(KeyScanner::shapeOk($key), "$key has the shape of a key, so the scan can find it");
    }

    // The library's keys are its file's: its words, its descriptions.
    $descriptions = $pdo->query("SELECT name, description FROM translation_keys WHERE name LIKE 'anotoki.%'")->fetchAll(PDO::FETCH_KEY_PAIR);
    foreach (LibraryWords::all() as $key => $words) {
        same(
            [$words['en'], $words['sk'], $words['description']],
            [seeded('en')[$key] ?? null, seeded('sk')[$key] ?? null, $descriptions[$key] ?? null],
            "$key: the library's English, Slovak and description",
        );
    }
    // `anotoki.` is the library's namespace: no migration of the IAM writes a key there but the two 017 moved into it.
    foreach (migrationFiles('iam') as $file) {
        preg_match_all("/'(anotoki\\.[A-Za-z0-9._-]+)'/", (string) file_get_contents(dirname(__DIR__) . "/migrations/iam/$file"), $found);
        same(
            $file === '017_strings_through_the_library.sql' ? ['anotoki.language.button', 'anotoki.language.label'] : [],
            sorted(array_values(array_unique($found[1]))),
            "$file and the library's namespace",
        );
    }

    // The languages a migration writes strings for are the ones that cannot be deleted.
    same(sorted(TRANSLATION_SEEDED_LANGUAGES), sorted(array_keys($GLOBALS['seeded'])), 'TRANSLATION_SEEDED_LANGUAGES names exactly the languages the migrations write');
    check(in_array(TRANSLATION_FALLBACK, TRANSLATION_SEEDED_LANGUAGES, true), 'English among them');

    // The database keeps its own rules.
    foreach (
        [
            "INSERT INTO languages (code, name, native_name) VALUES ('EN', 'x', 'x')" => 'a code in capitals',
            "INSERT INTO translations (key_name, language_code, value) VALUES ('no.such', 'en', 'x')" => 'a string for a key there is not',
            "INSERT INTO translations (key_name, language_code, value) VALUES ('common.save', 'de', 'x')" => 'a string in a language there is not',
            "UPDATE translations SET value = '' WHERE key_name = 'common.save'" => 'an empty string',
            "UPDATE users SET language = 'de'" => 'an account in a language there is not',
        ]
        as $sql => $what
    ) {
        try {
            $pdo->exec($sql);
            check(false, "$what should be refused by the database");
        } catch (PDOException) {
        }
    }
});

test('the IAM\'s configuration of the library: English and Slovak released, the mails the server\'s, and the numbers docs/api.md promises', function () {
    $config = iamTranslationsConfig();
    same([TRANSLATION_SEEDED_LANGUAGES, ['mail']], [$config->releasedLanguages, $config->serverNamespaces], 'released languages, server namespace');
    same(
        ['languages' => 20, 'name' => 50, 'order' => 1000, 'value' => 2000, 'body' => 1024 * 1024, 'audit keys' => 100, 'audit text' => TRANSLATION_MAIL_VALUE_MAX],
        [
            'languages' => $config->languagesMax,
            'name' => $config->languageNameMax,
            'order' => $config->sortOrderMax,
            'value' => $config->valueMax,
            'body' => $config->bodyLimit,
            'audit keys' => $config->auditKeys,
            'audit text' => $config->auditTextMax,
        ],
        'at most 20 languages, names of 50, an order to 1000, strings of 2,000 characters, 1 MB bodies; a changed mail string in the audit log as long as the panel lets one be',
    );
    same(
        ['IamMailRules', 'Anotoki\Lib\Translations\Rules\RequiredPlaceholders'],
        array_map(static fn(object $rule): string => get_class($rule), $config->rules),
        'the mails\' rules, then the {app} sentences - after the library\'s own',
    );
    same(['username'], (new IamMailRules(MAIL_KEYS))->placeholders('mail.reset.greeting'), 'a mail key\'s placeholders are MAIL_KEYS\'s');
    same(
        [null, []],
        [(new IamMailRules(MAIL_KEYS))->placeholders('common.save'), (new IamMailRules(MAIL_KEYS))->placeholders('mail.not.inTheCode')],
        'a page key is not the mails\' rule\'s; a mail key the code does not know has no placeholder at all',
    );
});

test('placeholders: a Slovak string uses none its English lacks; the two the pages cut keep {app}; every stored string passes the checks it is edited under', function () {
    $wrong = [];
    foreach (seeded('sk') as $key => $slovak) {
        $extra = array_diff(Placeholders::names($slovak), Placeholders::names(seeded('en')[$key]));
        if ($extra !== []) {
            $wrong[] = "$key: {" . implode('}, {', $extra) . '}';
        }
    }
    same([], $wrong, 'the pages fill in only the placeholders the English string has');

    foreach (TRANSLATION_REQUIRED_PLACEHOLDERS as $key => $required) {
        foreach (['en', 'sk'] as $code) {
            same([], array_values(array_diff($required, Placeholders::names(seeded($code)[$key] ?? ''))), "$key ($code) keeps what the page cuts the sentence around");
        }
    }

    // Everything the migrations wrote - the IAM's and the library's - would be accepted by the panel as it is.
    same([], iamTranslations()->storedRefusals(), 'every stored string passes the rules it is edited under');
    same(2 * (IAM_PAGE_KEYS + IAM_MAIL_KEYS + count(LibraryWords::keys())), (int) iamDb()->query('SELECT count(*) FROM translations')->fetchColumn(), 'all 1754 of them');
});

test('the mails: MAIL_KEYS and MAIL_FALLBACK are exactly what the database has', function () {
    $mailKeys = array_values(array_filter(array_keys(seeded('en')), static fn(string $key): bool => str_starts_with($key, 'mail.')));
    same(sorted(array_keys(MAIL_KEYS)), sorted($mailKeys), 'MAIL_KEYS names the mail keys of the database');
    same(
        IAM_MAIL_KEYS,
        count(MAIL_KEYS),
        'forty-eight: five strings for each of the three mails with a link or a code, three for the approval, four for each of the announcements\' three templates, the two every announcement ends with, and four for each of the deletion\'s three mails and the new sign-in\'s',
    );
    same(array_keys(MAIL_KEYS), array_keys(MAIL_FALLBACK), 'MAIL_FALLBACK has a string for each, in the same order');

    foreach (MAIL_KEYS as $key => $placeholders) {
        same(MAIL_FALLBACK[$key], seeded('en')[$key], "MAIL_FALLBACK[$key] is the seeded English");
        foreach (['en', 'sk'] as $code) {
            same(sorted($placeholders), sorted(Placeholders::names(seeded($code)[$key])), "$key ($code) uses exactly the placeholders the code fills");
        }
    }

    same(['username'], MAIL_KEYS['mail.reset.greeting'], 'a greeting takes the username');
    same(['hours'], MAIL_KEYS['mail.confirm.validity'], 'the confirmation says hours');
    same(['minutes'], MAIL_KEYS['mail.reset.validity'], 'the reset says minutes');
    same(['minutes'], MAIL_KEYS['mail.loginCode.validity'], 'and so does the code');
    same(['code'], MAIL_KEYS['mail.loginCode.subject'], 'the code is in its subject');
    foreach (MAIL_KEYS as $key => $placeholders) {
        check(!in_array('link', $placeholders, true), "$key: there is no {link} - the link is never part of a string");
    }
    same(MAIL_FALLBACK, mailWords('en'), 'the words of a mail in English, in the order of MAIL_KEYS');
    same(ordered(array_intersect_key(seeded('sk'), MAIL_KEYS)), ordered(mailWords('sk')), 'and in Slovak');
    same(MAIL_FALLBACK, mailWords('de'), 'a language the IAM does not have reads in English');
    same(MAIL_FALLBACK, mailWords("\xFF"), 'and so does what is no language at all');
});

test('the frontend\'s compiled English is the database\'s: the same keys without the mails and the library\'s, the same strings', function () {
    $dictionary = EnglishDictionary::read(iamFrontendSources() . '/' . TRANSLATION_DICTIONARY_FILE, 'en');
    $pages = array_filter(seeded('en'), static fn(string $key): bool => !str_starts_with($key, 'mail.') && !LibraryWords::isLibraryKey($key), ARRAY_FILTER_USE_KEY);

    same(IAM_PAGE_KEYS, count($pages), 'the pages have 581 keys of their own');
    foreach (array_keys($dictionary) as $key) {
        check(KeyScanner::shapeOk((string) $key), "$key in en.ts has the shape of a key, so the scan can find it");
    }
    same(
        [],
        array_values(array_diff(array_keys($dictionary), array_keys($pages))),
        'keys en.ts has and no IAM migration wrote - a page with strings from the server would show the key (a language\'s name comes from the languages table; the language switcher\'s words are the library\'s anotoki.language.*, which it says itself)',
    );
    same([], array_values(array_diff(array_keys($pages), array_keys($dictionary))), 'keys the migrations wrote and en.ts does not have');

    $different = [];
    foreach ($pages as $key => $english) {
        if (array_key_exists($key, $dictionary) && $dictionary[$key] !== $english) {
            $different[] = $key;
        }
    }
    same([], $different, 'keys whose English in en.ts is not the seeded one');
    check(!array_key_exists('mail.reset.subject', $dictionary), 'no mail key is compiled into the pages');
});

test('every page key the database has is used by the frontend, and nothing in the frontend is drawn as HTML', function () {
    $scan = iamKeyScanner()->scan(array_keys(seeded('en')));
    same([], $scan['problems'], 'what the scan does not let through');
    same([], $scan['unused'], 'in the database, used nowhere - a key built at run time cannot be seen and is forbidden; delete a dead key with a migration');
    same([], array_keys($scan['missing']), 'used by the frontend, not in the database - the page would show the key');
    same(IAM_PAGE_KEYS, count($scan['used']), 'all 581');
    same(
        [],
        array_values(array_filter(array_keys($scan['used']), static fn(string $key): bool => str_starts_with($key, 'mail.') || LibraryWords::isLibraryKey($key))),
        'the mail keys are not the frontend\'s, and the library\'s are the library\'s pages\'',
    );

    $files = iamKeyScanner()->sourceFiles();
    check(in_array('app.routes.ts', $files, true), 'the sources are where the scan looks');
    check(!in_array(TRANSLATION_DICTIONARY_FILE, $files, true), 'en.ts lists every key and uses none: it is not scanned');
    foreach ($files as $file) {
        check(preg_match('/\.(ts|html)$/', $file) === 1 && !str_ends_with($file, '.spec.ts'), "$file is a source");
    }
});

// ── The rules a string keeps (no database) ───────────────────────────────────

test('a language somebody sends is one on offer, however it is written - or English', function () {
    same('en', languageOrDefault(null), 'nothing is English');
    same('en', languageOrDefault(5), 'a number is English');
    same('en', languageOrDefault(['sk']), 'a list is English');
    same('en', languageOrDefault(true), 'true is English');
    same('en', languageOrDefault('de'), 'a language not offered is English');
    same('sk', languageOrDefault('sk'), 'an offered one is itself');
    same('sk', languageOrDefault(' SK '), 'however it was typed');
});

test('what reads as empty, and the announcements\' trim - the IAM\'s own helpers; the stored English is the authority on placeholders; the two sentences cut around {app}', function () {
    // Empty is what reads as empty (textBlank(), which the mails ask). Characters of no width - pasted
    // with a word from a web page - look like a cleared field, and kept as a string they would hide the
    // English under them.
    foreach (['', ' ', "\t\n", "\u{00A0}", "\u{3000}", "\u{200B}", "\u{2060}\u{200B}", "\u{00AD}", "\u{FEFF}", "\u{200E}\u{200F}", " \u{200D} \u{200C} ", "\xFF"] as $nothing) {
        check(textBlank($nothing), 'nothing to read in ' . json_encode($nothing, JSON_PARTIAL_OUTPUT_ON_ERROR));
    }
    foreach (['a', ' a ', '0', '.', "\u{200B}a", "a\u{200B}", "\u{3042}", '{name}'] as $something) {
        check(!textBlank($something), 'something to read in ' . json_encode($something));
    }
    foreach (
        ['a zero-width space' => "\u{200B}", 'a word joiner between spaces' => " \u{2060} ", 'a soft hyphen' => "\u{00AD}", 'a joiner, a non-joiner and a direction mark' => "\u{200D}\u{200C}\u{200E}"]
        as $what => $nothing
    ) {
        same(
            [['key' => 'test.hello', 'language' => 'sk', 'value' => null]],
            checked(['test.hello' => ['sk' => $nothing]]),
            "$what alone, in another language: that string goes, and the key reads in English",
        );
        same(['fallback_required', ['key' => 'test.bye', 'language' => 'en']], checked(['test.bye' => ['en' => $nothing]]), "$what alone is no English string");
    }
    // Only the question is wider, not the text: what has a letter in it is kept as it was written.
    same("Ahoj\u{200C}te {name}", checked(['test.hello' => ['sk' => "Ahoj\u{200C}te {name}"]])[0]['value'], 'a non-joiner inside a word stays');
    same("\u{05E9}\u{05DC}\u{05D5}\u{05DD}\u{200F}", checked(['test.bye' => ['sk' => "\u{05E9}\u{05DC}\u{05D5}\u{05DD}\u{200F}"]])[0]['value'], 'and a direction mark at the end of a string');

    // The announcements' words are trimmed by textTrim(): a text the expression cannot get through is
    // too long, never an empty one.
    $endless = 'a' . str_repeat(' ', 1040000) . 'b';
    check(textTrim($endless) === $endless, 'the text comes back as it is, not as nothing');
    check(!textBlank(textTrim($endless)), 'so it is not taken for an empty one');
    same('a b', textTrim(" \u{00A0}a b\u{3000}\t"), 'what can be trimmed still is');
    foreach (['sk', 'en'] as $code) {
        same(
            ['invalid_value', ['key' => 'mail.reset.intro', 'language' => $code]],
            checked(['mail.reset.intro' => [$code => $endless]]),
            "a million spaces inside a mail's words is a string too long, not an empty one ($code)",
        );
    }

    // A translation may leave a placeholder out but never add one - and English sent beside it does not
    // change which ones the key has: the stored English does.
    same('unknown_placeholder', checked(['test.hello' => ['sk' => 'Ahoj {who}', 'en' => 'Hi {who}']])[0], 'English sent with it does not change what the key has');
    same(2, count(checked(['test.new' => ['sk' => 'Nové {a}', 'en' => 'New {a}']])), 'a key with no English yet: the English sent with it counts');
    same('unknown_placeholder', checked(['test.new' => ['sk' => 'Nové {a}']])[0], 'with no English at all there is no placeholder to use');

    // The two sentences the pages cut around {app}.
    foreach (['login.continueTo', 'register.forApp'] as $key) {
        same(['placeholder_missing', ['key' => $key, 'language' => 'sk', 'placeholder' => 'app']], checked([$key => ['sk' => 'Prihlás sa a pokračuj']]), "$key in Slovak without {app}");
        same(['placeholder_missing', ['key' => $key, 'language' => 'en', 'placeholder' => 'app']], checked([$key => ['en' => 'Sign in to continue']]), "$key in English without {app}");
        same('Pokračuj do {app}', checked([$key => ['sk' => 'Pokračuj do {app}']])[0]['value'], "$key with it");
    }
});

test('a brace is only ever part of a placeholder: one written any other way is refused, not shown to everybody - on a page or in a mail', function () {
    $english = ['test.hello' => 'Hello, {name}!', 'test.bye' => 'Goodbye', 'test.count' => '{count} questions', 'test.step' => 'Step {n} of {m}'];
    $refused = static fn(string $key, string $code): array => ['invalid_placeholder', ['key' => $key, 'language' => $code]];

    // What a translator writes when the placeholder looks like a word to translate, or like something to
    // tidy - the ways the library's own tests do not name.
    foreach (
        [
            'a space before the end' => '{count } otázok',
            'a dash in its name' => '{co-unt} otázok',
            'a digit first' => '{1count} otázok',
            'a brace after a good one' => '{count} otázok {',
            'a lone closing one' => '{count} otázok }',
        ]
        as $what => $text
    ) {
        same($refused('test.count', 'sk'), checked(['test.count' => ['sk' => $text]], $english), "$what: $text");
    }
    same($refused('test.step', 'sk'), checked(['test.step' => ['sk' => 'Krok {n / {m}']], $english), 'two placeholders run into one');
    same($refused('test.bye', 'sk'), checked(['test.bye' => ['sk' => 'Dovidenia :-}']], $english), 'a key with no placeholder has no brace at all');
    // English too - and there it is this, not "changed": {{n}} holds the placeholder the key has.
    same($refused('test.step', 'en'), checked(['test.step' => ['en' => 'Step {{n}} of {m}']], $english), 'English with a doubled brace');
    same($refused('test.hello', 'en'), checked(['test.hello' => ['en' => 'Hi, { name }!']], $english), 'English with spaces inside');
    same($refused('test.new', 'en'), checked(['test.new' => ['en' => 'New {a} {', 'sk' => 'Nové {a}']], $english), 'a key with no English yet');
    same(
        ['unknown_placeholder', ['key' => 'test.count', 'language' => 'sk', 'placeholder' => 'Count']],
        checked(['test.count' => ['sk' => '{Count} otázok']], $english),
        'a well-written one in other letters is one the key does not have',
    );
    same('Krok {m}, z {n}: {m}', checked(['test.step' => ['sk' => 'Krok {m}, z {n}: {m}']], $english)[0]['value'], 'in another order, and twice');
    same('invalid_value', checked(['test.count' => ['sk' => str_repeat('á', 2000) . '{']], $english)[0], 'too long comes first');
    same($refused('login.continueTo', 'sk'), checked(['login.continueTo' => ['sk' => 'Pokračuj do { app }']]), 'not "missing {app}": it is there, written wrongly');

    // A mail's words are filled the same way, so they are held to the same.
    foreach (['en', 'sk'] as $code) {
        same(
            $refused('mail.reset.greeting', $code),
            checked(['mail.reset.greeting' => [$code => 'Hello { username },']]),
            "a greeting with spaces in its placeholder ($code) - not \"missing {username}\"",
        );
        same($refused('mail.reset.intro', $code), checked(['mail.reset.intro' => [$code => 'Open the {link below to choose one:']]), "a paragraph with a brace ($code)");
        same($refused('mail.loginCode.subject', $code), checked(['mail.loginCode.subject' => [$code => 'Your code: {{code}}']]), "a subject with a doubled one ($code)");
        same($refused('mail.reset.validity', $code), checked(['mail.reset.validity' => [$code => 'Good for {minutes} minutes}.']]), "a lone closing one after a good placeholder ($code)");
    }
    same('invalid_value', checked(['mail.reset.intro' => ['sk' => str_repeat('ž', 400) . '{']])[0], 'a mail string too long is said to be that');
    same('invalid_value', checked(['mail.reset.intro' => ['sk' => str_repeat('あ', 300) . '{']])[0], 'in bytes too');
    same('invalid_value', checked(['mail.reset.intro' => ['sk' => "Otvor {\nodkaz"]])[0], 'and a line break in one is said first');
    same(
        ['unknown_placeholder', ['key' => 'mail.reset.intro', 'language' => 'sk', 'placeholder' => 'link']],
        checked(['mail.reset.intro' => ['sk' => 'Otvor {link}:']]),
        'a well-written one a mail does not have is still "unknown"',
    );

    // The sentence shows how: with a placeholder of that very key, so nobody is taught one it does not have.
    check(str_contains(refusalSentence(['login.continueTo' => ['sk' => 'Pokračuj do {aplikácie}']]), 'like {app}:'), 'the one the sign-in page cuts around');
    $sentence = refusalSentence(['mail.confirm.greeting' => ['en' => 'Hello {user name},']]);
    check(str_contains($sentence, 'The English string of "mail.confirm.greeting"') && str_contains($sentence, 'like {username}:'), 'a mail\'s: the one the code fills for that key - ' . $sentence);
});

test('a mail\'s words are held to more: exactly the code\'s placeholders, one short line, and no address', function () {
    same('Reset your anotoki password', checked(['mail.reset.subject' => ['en' => ' Reset your anotoki password ']])[0]['value'], 'a subject reworded');
    same('Čau {username},', checked(['mail.reset.greeting' => ['sk' => 'Čau {username},']])[0]['value'], 'a greeting reworded');
    same([['key' => 'mail.reset.intro', 'language' => 'sk', 'value' => null]], checked(['mail.reset.intro' => ['sk' => '']]), 'an empty one in another language: it reads in English');
    same('fallback_required', checked(['mail.reset.intro' => ['en' => '']])[0], 'never an empty English one');
    same(
        ['fallback_required', ['key' => 'mail.reset.subject', 'language' => 'en']],
        checked(['mail.reset.subject' => ['en' => "\u{200B}"]]),
        'nor a subject nobody can see: a zero-width space is an empty one',
    );
    same(
        [['key' => 'mail.reset.subject', 'language' => 'sk', 'value' => null]],
        checked(['mail.reset.subject' => ['sk' => "\u{200B}"]]),
        'in another language it is no string, and the subject reads in English',
    );

    // A header smuggled into a subject - refused, not cleaned up.
    same(['invalid_value', ['key' => 'mail.reset.subject', 'language' => 'en']], checked(['mail.reset.subject' => ['en' => "Reset\r\nBcc: x@evil.test"]]), 'CR LF in a subject');
    foreach (["Reset\nBcc: x", "Reset\rBcc: x", "Reset\tnow", "Reset\u{2028}now", "Reset\u{0085}now"] as $subject) {
        same('invalid_value', checked(['mail.reset.subject' => ['sk' => $subject]])[0], 'a line break or a tab in a subject: ' . json_encode($subject));
    }
    same('invalid_value', checked(['mail.reset.intro' => ['sk' => "Prvý riadok\nDruhý riadok"]])[0], 'or in a paragraph');
    same(150, Text::length(checked(['mail.reset.subject' => ['sk' => str_repeat('ž', 150)]])[0]['value']), 'a subject of 150 characters');
    same('invalid_value', checked(['mail.reset.subject' => ['sk' => str_repeat('ž', 151)]])[0], 'one of 151');
    same(400, Text::length(checked(['mail.reset.intro' => ['sk' => str_repeat('ž', 400)]])[0]['value']), 'a paragraph of 400 characters');
    same('invalid_value', checked(['mail.reset.intro' => ['sk' => str_repeat('ž', 401)]])[0], 'one of 401');

    // And in bytes: a string is one line of the message as it is sent, and a line of mail may have
    // 998. Characters alone do not hold that - 400 of them are 1200 bytes in Japanese.
    check(TRANSLATION_MAIL_LINE_OCTETS <= 998 && TRANSLATION_MAIL_USERNAME_OCTETS >= 4 * 100, 'the bound is under what SMTP allows, with room for the longest username there can be');
    same(800, strlen(checked(['mail.reset.ignore' => ['sk' => str_repeat('ž', 400)]])[0]['value']), 'the scripts the IAM is released in never reach it: 400 characters are 800 bytes');
    same(900, strlen(checked(['mail.reset.ignore' => ['sk' => str_repeat('あ', 300)]])[0]['value']), 'a paragraph of 900 bytes');
    foreach (['en', 'sk'] as $code) {
        same(
            ['invalid_value', ['key' => 'mail.reset.ignore', 'language' => $code]],
            checked(['mail.reset.ignore' => [$code => str_repeat('あ', 301)]]),
            "one of 903 bytes - 301 characters, well within the 400 ($code)",
        );
    }
    same(1, count(checked(['mail.reset.greeting' => ['sk' => str_repeat('あ', 163) . ' {username}']])), 'a greeting of 500 bytes: 400 are kept for the username it is filled with');
    same(['invalid_value', ['key' => 'mail.reset.greeting', 'language' => 'sk']], checked(['mail.reset.greeting' => ['sk' => str_repeat('あ', 164) . ' {username}']]), 'one of 503');
    same(['invalid_value', ['key' => 'mail.confirm.greeting', 'language' => 'sk']], checked(['mail.confirm.greeting' => ['sk' => str_repeat('あ', 170) . ' {username}']]), 'in any of the three mails');
    same(600, strlen(checked(['mail.reset.subject' => ['sk' => str_repeat("\u{1D11E}", 150)]])[0]['value']), 'a subject is never stopped by it: 150 characters are 600 bytes at most');
    // A {date} - the deletion mail's day - keeps its ten bytes the same way.
    same(1, count(checked(['mail.deletionRequested.intro' => ['sk' => str_repeat('あ', 294) . '{date}']])), '888 bytes and a {date}: 890 is the room');
    same('invalid_value', checked(['mail.deletionRequested.intro' => ['sk' => str_repeat('あ', 295) . '{date}']])[0], '891 is not');

    // The longest strings the panel takes, filled with the longest username there can be: no line of the message is over 998 bytes.
    $longest = [
        'mail.reset.greeting' => str_repeat('あ', 163) . ' {username}',
        'mail.reset.intro' => str_repeat('あ', 300),
        'mail.reset.validity' => str_repeat('あ', 297) . '{minutes}',
        'mail.reset.ignore' => str_repeat('あ', 300),
    ];
    same(4, count(checked(array_map(static fn(string $text): array => ['sk' => $text], $longest))), 'each of them is accepted');
    $message = mailMessage('reset', $longest + MAIL_FALLBACK, TEST_ISSUER . '/reset-password?token=' . str_repeat('cd', 32), [
        'username' => str_repeat("\u{1D11E}", 100),
        'minutes' => MAIL_RESET_MINUTES,
    ]);
    $lines = array_map('strlen', explode("\n", $message['body']));
    check(max($lines) <= 998, 'the longest line of that message has ' . max($lines) . ' bytes');
    // No name is chosen like it since 2026-10-19 (letters and digits, a byte each) - but an account keeps a
    // name from before, as the import from the genshin site brought it: the column holds it, so the room stays.
    check(usernameRefusal(str_repeat("\u{1D11E}", 100)) !== null, 'that username is no longer one a person may choose');
    $pdo = iamDb();
    $pdo->beginTransaction();
    try {
        makeUser(str_repeat("\u{1D11E}", 100), 'longest-name@example.test');
        same(str_repeat("\u{1D11E}", 100), $pdo->query("SELECT username FROM users WHERE email = 'longest-name@example.test'")->fetchColumn(), 'but an account from before it can have it');
    } finally {
        $pdo->rollBack();
    }

    // The room is kept for every {username}, not once for the string: each one is filled. A greeting
    // that names its reader three times is 41 bytes as written - and over 998 as sent.
    $name = str_repeat("\u{1D11E}", 100);
    $thrice = 'Hello {username}, {username}, {username},';
    same([41, 1211], [strlen($thrice), strlen(mailFill('mail.reset.greeting', $thrice, ['username' => $name]))], 'what the three-times greeting would be on the wire');
    foreach (['en', 'sk'] as $code) {
        foreach (['mail.reset.greeting', 'mail.confirm.greeting', 'mail.loginCode.greeting'] as $key) {
            same(['invalid_value', ['key' => $key, 'language' => $code]], checked([$key => [$code => $thrice]]), "$key ($code) with the username three times: there is no room for the third");
        }
    }
    same('invalid_value', checked(['mail.reset.greeting' => ['sk' => '{username}{username}{username}']])[0], 'however short the rest of it is');
    $why = static fn(string $text): string => refusalSentence(['mail.reset.greeting' => ['sk' => $text]]);
    check(str_contains($why($thrice), 'it has {username} 3 times') && str_contains($why($thrice), 'which leaves none'), 'and the refusal says why a string of 41 bytes is too long: ' . $why($thrice));
    check(
        str_contains($why(str_repeat('あ', 27) . ' {username} {username}'), 'which leaves 100 bytes, and it has 103'),
        'or how much room two of them leave: ' . $why(str_repeat('あ', 27) . ' {username} {username}'),
    );
    check(str_contains($why(str_repeat('あ', 164) . ' {username}'), 'at most 500 bytes'), 'with one it is the 500 of before: ' . $why(str_repeat('あ', 164) . ' {username}'));
    same(TRANSLATION_MAIL_LINE_OCTETS - 2 * TRANSLATION_MAIL_USERNAME_OCTETS, 100, 'with two, 100 bytes are left for the string');
    $twice = str_repeat('あ', 26) . ' {username} {username}';
    same(100, strlen($twice), 'a greeting of 100 bytes that names the reader twice');
    same($twice, checked(['mail.reset.greeting' => ['sk' => $twice]])[0]['value'], 'is the longest of its kind the panel takes');
    same(
        ['invalid_value', ['key' => 'mail.reset.greeting', 'language' => 'sk']],
        checked(['mail.reset.greeting' => ['sk' => str_repeat('あ', 27) . ' {username} {username}']]),
        'one of 103 bytes is not',
    );
    $message = mailMessage('reset', ['mail.reset.greeting' => $twice] + MAIL_FALLBACK, TEST_ISSUER . '/reset-password?token=' . str_repeat('cd', 32), [
        'username' => $name,
        'minutes' => MAIL_RESET_MINUTES,
    ]);
    $lines = array_map('strlen', explode("\n", $message['body']));
    same([880, 880], [max($lines), $lines[0]], 'filled with the longest username there can be, its line has 880 bytes');
    check(max($lines) <= 998, 'which a line of mail may have');
    same('Ahoj {username}, vitaj {username}!', checked(['mail.reset.greeting' => ['sk' => 'Ahoj {username}, vitaj {username}!']])[0]['value'], 'a short greeting may well say the name twice');
    // Counted only where the code fills it: elsewhere {username} stays seven letters in braces, and is refused as that.
    same(
        ['unknown_placeholder', ['key' => 'mail.reset.intro', 'language' => 'sk', 'placeholder' => 'username']],
        checked(['mail.reset.intro' => ['sk' => str_repeat('あ', 200) . ' {username} {username}']]),
        'a paragraph is not given less room for a placeholder it cannot use',
    );

    // Exactly the placeholders MAIL_KEYS gives the key - in every language, whatever the stored English says.
    foreach (['en', 'sk'] as $code) {
        same(
            ['placeholder_missing', ['key' => 'mail.reset.greeting', 'language' => $code, 'placeholder' => 'username']],
            checked(['mail.reset.greeting' => [$code => 'Hello,']]),
            "a greeting without the username ($code)",
        );
        same(
            ['placeholder_missing', ['key' => 'mail.confirm.validity', 'language' => $code, 'placeholder' => 'hours']],
            checked(['mail.confirm.validity' => [$code => 'The link is good for two days.']]),
            "a validity without its number ($code)",
        );
        same(
            ['unknown_placeholder', ['key' => 'mail.reset.intro', 'language' => $code, 'placeholder' => 'link']],
            checked(['mail.reset.intro' => [$code => 'Open {link} to choose one:']]),
            "there is no {link} ($code)",
        );
        same(
            ['unknown_placeholder', ['key' => 'mail.reset.subject', 'language' => $code, 'placeholder' => 'code']],
            checked(['mail.reset.subject' => [$code => 'Reset {code}']]),
            "a reset has no code ($code)",
        );
        same(
            ['unknown_placeholder', ['key' => 'mail.reset.validity', 'language' => $code, 'placeholder' => 'hours']],
            checked(['mail.reset.validity' => [$code => 'Good for {minutes} minutes or {hours} hours.']]),
            "a reset counts in minutes only ($code)",
        );
    }
    same(
        'unknown_placeholder',
        checked(['mail.reset.intro' => ['en' => 'Open {link}:']], ['mail.reset.intro' => 'Stored with {link} by hand'])[0],
        'the code is the authority, not the stored English',
    );

    // No address in a mail's words.
    foreach (
        [
            'Open https://evil.test/reset to choose one:',
            'Open http://evil.test now',
            'See HTTPS://EVIL.TEST',
            'Go to www.evil.test to choose one',
            'Go to WWW.evil.test',
            'Write to help@evil.test if it was not you',
            'ftp://files.evil.test',
            'javascript://x',
        ]
        as $text
    ) {
        same(['address_in_mail', ['key' => 'mail.reset.intro', 'language' => 'sk']], checked(['mail.reset.intro' => ['sk' => $text]]), $text);
    }
    same('address_in_mail', checked(['mail.reset.subject' => ['en' => 'Reset at www.evil.test']])[0], 'in a subject either');
    same(1, count(checked(['mail.reset.ignore' => ['sk' => 'Ak si to nebol ty: nič sa nezmenilo. Awww.']])), 'a colon, or "www" inside a word, is not an address');

    // An address the check above reads as none: stored backwards, and turned around for the reader by a
    // right-to-left override - "moc.live//:sptth" is what the panel checks, "https://evil.com" what a
    // mail program shows. The letters alone are no address; the character that turns them is refused.
    same(1, count(checked(['mail.reset.intro' => ['sk' => 'Otvor moc.live//:sptth a zvoľ si ho:']])), 'the reversed letters alone read as no address');
    foreach (['en', 'sk'] as $code) {
        same(
            ['invalid_value', ['key' => 'mail.reset.intro', 'language' => $code]],
            checked(['mail.reset.intro' => [$code => "Open \u{202E}moc.live//:sptth\u{202C} to choose one:"]]),
            "turned around by a right-to-left override ($code)",
        );
        same(
            ['invalid_value', ['key' => 'mail.reset.subject', 'language' => $code]],
            checked(['mail.reset.subject' => [$code => "Reset \u{2067}moc.live\u{2069}"]]),
            "or by an isolate, in a subject ($code)",
        );
    }
    // Every character that turns the direction - embeddings and overrides, isolates, the marks - and the C1 controls.
    $turning = [...range(0x202a, 0x202e), ...range(0x2066, 0x2069), 0x200e, 0x200f, 0x061c, ...array_values(array_diff(range(0x0080, 0x009f), [0x0085]))];
    foreach ($turning as $codePoint) {
        $character = mb_chr($codePoint, 'UTF-8');
        $hex = sprintf('U+%04X', $codePoint);
        same(
            ['invalid_value', ['key' => 'mail.reset.ignore', 'language' => 'sk']],
            checked(['mail.reset.ignore' => ['sk' => "Ak si to nebol ty{$character}, nič sa nezmenilo."]]),
            "$hex in a paragraph",
        );
        same('invalid_value', checked(['mail.reset.subject' => ['en' => "Reset {$character}"]])[0], "$hex in a subject");
    }
    check(
        str_contains(refusalSentence(['mail.reset.ignore' => ['sk' => "Ak si to nebol ty\u{202E}"]]), 'turns the direction of the text'),
        'and the refusal says what it is: ' . refusalSentence(['mail.reset.ignore' => ['sk' => "Ak si to nebol ty\u{202E}"]]),
    );
    check(str_contains(refusalSentence(['mail.reset.ignore' => ['sk' => "Ak si to nebol ty\u{0085}"]]), 'must be one line'), 'U+0085, the C1 next-line, is still refused as the line break it is');
    same(
        "\u{05E9}\u{05DC}\u{05D5}\u{05DD}\u{200F}",
        checked(['test.bye' => ['sk' => "\u{05E9}\u{05DC}\u{05D5}\u{05DD}\u{200F}"]])[0]['value'],
        'a page\'s string is drawn by the page itself, in the order the panel shows: a direction mark stays',
    );

    // A page's string is not held to a mail's rules.
    same(1, count(checked(['test.bye' => ['sk' => "Dovidenia\nna https://anotoki.eu"]])), 'a page string may have a line break and an address');
});

test('filling a mail: only the key\'s own placeholders, in one pass; a subject is one line when it goes out', function () {
    same('Hello Anna,', mailFill('mail.reset.greeting', 'Hello {username},', ['username' => 'Anna']), 'the username');
    same('Hello {hours},', mailFill('mail.reset.greeting', 'Hello {username},', ['username' => '{hours}', 'hours' => 48]), 'a value is never read again');
    same(
        'Reset {link} {code} {username}',
        mailFill('mail.reset.subject', 'Reset {link} {code} {username}', ['link' => 'https://x.test/?token=secret', 'code' => '123456', 'username' => 'Anna']),
        'a subject that may use none is filled with none',
    );
    same('Code: 123456 {username}', mailFill('mail.loginCode.subject', 'Code: {code} {username}', ['code' => '123456', 'username' => 'Anna']), 'only what MAIL_KEYS gives the key');
    same('Good for {minutes} minutes', mailFill('mail.reset.validity', 'Good for {minutes} minutes', []), 'a value that is not there leaves the placeholder as written');
    same('x {a}', mailFill('no.such.key', 'x {a}', ['a' => 'b']), 'a key the code does not know is filled with nothing');

    $message = mailMessage('reset', MAIL_FALLBACK, 'https://iam.test/reset-password?token=abc', ['username' => 'Anna', 'minutes' => 60]);
    same(
        "Hello Anna,\n\nSomebody asked to set a new password for this account. Open the link below to choose one:\n\nhttps://iam.test/reset-password?token=abc\n\nThe link is good for 60 minutes and can only be used once.\n\nIf it was not you, nothing has changed and there is nothing to do. Your current password still works.\n",
        $message['body'],
        'greeting, intro, the line, validity, ignore',
    );
    same(MAIL_FALLBACK['mail.reset.intro'], explode("\n\n", mailMessage('reset', [], 'x', [])['body'])[1], 'words that are missing are said with the built-in English, never with a key');

    same('Reset', mailEncodeHeader('Reset'), 'plain ASCII goes out as it is');
    check(str_starts_with(mailEncodeHeader('Potvrď'), '=?UTF-8?B?'), 'anything else is encoded');
    check(str_starts_with(mailEncodeHeader("Reset\n"), '=?UTF-8?B?'), 'and a line break at the very end is not plain ASCII');
    check(str_starts_with(mailEncodeHeader("Reset\r\nBcc: x@evil.test"), '=?UTF-8?B?'), 'nor one in the middle');
});

test('If-None-Match, compared the weak way: the tags the route\'s own test below does not send', function () {
    $etag = '"abc"';
    foreach (['"x" ,W/"abc" , "y"', '"x-gzip" , W/"abc-br" , "y"', '"x-zstd", "abc-zstd"'] as $header) {
        check(ETag::notModified($header, $etag), $header);
    }
    foreach (['w/"abc"', '"abc', 'abc-gzip"', '"abc"-gzip', '"abcgzip"', '"abc-GZIP"', '"abc-gzip-gzip"', '"-gzip"', '"x-br"', '"abczstd"', '"abc-ZSTD"', '"abc-zstd-br"', '"abc-zstd'] as $header) {
        check(!ETag::notModified($header, $etag), "not: $header");
    }
    check(ETag::notModified('"abc-gzip"', '"abc-gzip"'), 'a tag that ends so itself is still its own');
});

// ── The scan and the dictionary reader, on sources of their own ──────────────

test(
    'the IAM\'s scan: a quoted literal with a key\'s shape in a namespace the database has - never an attribute\'s value, a comment, a spec, en.ts, a mail key, a library key; a key put together is refused',
    function () {
        $root = sources([
            'pages/login/login.component.html' => <<<'HTML'
            <!-- {{ 'login.inAComment' | t }} -->
            <h1>{{ 'login.title' | t }}</h1>
            <p [text]="error.message" title="login.anAttribute" [class.on]="login.expression"></p>
            <button [attr.aria-label]="'common.close' | t" (click)="close()">x</button>
            <span>{{ "login.doubleQuoted" | t }}</span>
            <p>Don't stop: {{ 'login.afterAnApostrophe' | t: { app: name } }} - it's fine</p>
            <em>{{ 'login.missing' | t }}</em>
            HTML
            ,
            'pages/login/login.component.ts' => <<<'TS'
            import { Login } from './login.model';
            // 'login.inALineComment' is what a comment explains
            /* 'login.inABlockComment'
               'login.stillInIt' */
            const address = 'http://example.test/'; const afterIt = 'login.afterAnAddress';
            const accept = 'image/*'; const afterAGlob = 'login.afterAGlob'; /* and 'login.afterTheGlob' is a comment */
            this.toasts.show(this.i18n.t('login.saved'), `${this.i18n.t("login.inATemplate")}`);
            const METHODS: Record<string, TranslationKey> = { login_code: 'sessions.method.login_code' };
            const notOurs = ['login.component', 'theme-boot.js', 'login', '.login.title', 'Login.title', 'login..title', 'mail.reset.subject', 'anotoki.language.label'];
            const built = this.i18n.t('login.' + which);
            TS
            ,
            'pages/login/login.component.spec.ts' => "expect(text).toBe('login.onlyInASpec');\n",
            'shared/i18n/en.ts' => "export const en = {\n  'login.onlyInTheDictionary': 'x',\n} as const;\n",
            'pages/login/login.component.scss' => "/* 'login.inAStyle' */\n",
        ]);
        $database = [
            'login.title',
            'login.doubleQuoted',
            'login.afterAnApostrophe',
            'login.afterAnAddress',
            'login.afterAGlob',
            'login.saved',
            'login.inATemplate',
            'login.inAComment',
            'login.inALineComment',
            'login.inABlockComment',
            'login.onlyInASpec',
            'login.onlyInTheDictionary',
            'login.anAttribute',
            'login.expression',
            'common.close',
            'common.unused',
            'error.message',
            'sessions.method.login_code',
            'mail.reset.subject',
            'mail.reset.intro',
            'anotoki.language.label',
        ];
        $scan = iamKeyScanner($root)->scan($database);

        same(
            ['pages/login/login.component.ts:10: a key is put together from \'login.\', which no file of dynamic keys does not declare'],
            $scan['problems'],
            'a key put together at run time cannot be seen in the source: refused',
        );
        same(
            ['login.component' => ['pages/login/login.component.ts:9'], 'login.missing' => ['pages/login/login.component.html:7']],
            $scan['missing'],
            'asked for, and not there - with the file and the line',
        );
        same(
            [
                'common.unused',
                'error.message',
                'login.anAttribute',
                'login.expression',
                'login.inABlockComment',
                'login.inAComment',
                'login.inALineComment',
                'login.onlyInASpec',
                'login.onlyInTheDictionary',
            ],
            $scan['unused'],
            'an attribute\'s value is an expression; comments, specs and en.ts do not count; the mail keys and the library\'s are not looked for at all',
        );
        same(['pages/login/login.component.html:2'], $scan['used']['login.title'], 'with the file and the line');
        same(['pages/login/login.component.ts:6'], $scan['used']['login.afterAGlob'], 'the lines are the file\'s, comments or not');
        same(['pages/login/login.component.ts:8'], $scan['used']['sessions.method.login_code'], 'a key in a map of literals is used');
        foreach (['mail.reset.subject', 'anotoki.language.label'] as $key) {
            check(!isset($scan['used'][$key]) && !isset($scan['missing'][$key]), "$key in the frontend is nobody's business here");
        }
        same([], iamKeyScanner($root)->scan([])['used'], 'a namespace the database does not have is nobody\'s key');
    },
);

test('the IAM\'s scan fails on innerHTML anywhere in the frontend', function () {
    $root = sources([
        'a.component.html' => "<p>{{ 'login.title' | t }}</p>\n<p [innerHTML]=\"text()\"></p>\n<!-- <p [innerHTML]=\"x\"></p> -->\n",
        'a.component.ts' => "// never [innerHTML]\nel.innerHTML = this.i18n.t('login.title');\n@HostBinding('innerHtml') html = '';\n",
    ]);
    $scan = iamKeyScanner($root)->scan(['login.title']);
    same(3, count($scan['problems']), json_encode($scan['problems']));
    check(str_starts_with($scan['problems'][0], 'a.component.html:2: innerHTML'), 'the template, by file and line: ' . $scan['problems'][0]);
    check(str_starts_with($scan['problems'][1], 'a.component.ts:2: innerHTML'), 'the class too, whatever it draws: ' . $scan['problems'][1]);
    check(str_starts_with($scan['problems'][2], 'a.component.ts:3: innerHTML'), 'however it is spelt: ' . $scan['problems'][2]);
});

test('the dictionary reader: every entry of en.ts, however it is quoted or wrapped - or it says it could not', function () {
    $root = sources([
        'en.ts' => <<<'TS'
        /**
         * 'a.comment': 'not an entry',
         */
        export const en = {
          // ── Brand ──
          'brand.account': 'anotoki account',
          "brand.double": "double \"quoted\"",
          'brand.escaped': 'it\'s a \\ and a é and a \u{1F600}',
          'brand.mixed': "it's",
          'brand.wrapped':
            'on the next line, as the formatter breaks a long one',
          'brand.url': 'see // this, not a comment', // a trailing comment
          'brand.braces': 'At least {count} characters.',
        } as const;

        export type TranslationKey = keyof typeof en;
        TS
    ,
    ]);
    same(
        [
            'brand.account' => 'anotoki account',
            'brand.double' => 'double "quoted"',
            'brand.escaped' => "it's a \\ and a é and a \u{1F600}",
            'brand.mixed' => "it's",
            'brand.wrapped' => 'on the next line, as the formatter breaks a long one',
            'brand.url' => 'see // this, not a comment',
            'brand.braces' => 'At least {count} characters.',
        ],
        EnglishDictionary::read("$root/en.ts", 'en'),
        'read as TypeScript writes them',
    );

    foreach (
        [
            'a value it cannot read' => "export const en = {\n  'a.b': 'x',\n  'a.c': `template`,\n} as const;\n",
            'a value put together' => "export const en = {\n  'a.b': 'x' + 'y',\n} as const;\n",
            'a key twice' => "export const en = {\n  'a.b': 'x',\n  'a.b': 'y',\n} as const;\n",
            'no object' => "export const en = makeDictionary();\n",
            'an empty object' => "export const en = {\n} as const;\n",
            'an object of comments only' => "export const en = {\n  // 'a.b': 'x',\n} as const;\n",
        ]
        as $what => $source
    ) {
        $file = sources(['en.ts' => $source]) . '/en.ts';
        check(thrownBy(static fn() => EnglishDictionary::read($file, 'en')) !== null, "$what should not pass as read");
    }

    // Nothing of the object may be left unread: what does not look like an entry is said by name,
    // not passed over - it compiles, the page would read it from en.ts, and no test would know of it.
    foreach (
        [
            'an unquoted key' => ["export const en = {\n  'a.b': 'x',\n  save: 'Save',\n} as const;\n", "save: 'Save',"],
            'an unquoted key, first' => ["export const en = {\n  retry: 'Retry',\n  'a.b': 'x',\n} as const;\n", "retry: 'Retry',"],
            'an unquoted key with its value on the next line' => ["export const en = {\n  'a.b': 'x',\n  save:\n    'Save',\n} as const;\n", 'save:'],
            'a spread' => ["const more = {};\nexport const en = {\n  'a.b': 'x',\n  ...more,\n} as const;\n", '...more,'],
            'a computed key' => ["export const en = {\n  'a.b': 'x',\n  ['x.y']: 'Z',\n} as const;\n", "['x.y']: 'Z',"],
            'two entries on a line' => ["export const en = {\n  'a.b': 'x', c: 'y',\n} as const;\n", "'a.b': 'x', c: 'y',"],
            'a nested object' => ["export const en = {\n  'a.b': 'x',\n  'a.c': { d: 'y' },\n} as const;\n", "'a.c': { d: 'y' },"],
            'a value that is not a string' => ["export const en = {\n  'a.b': 'x',\n  'a.c': 5,\n} as const;\n", "'a.c': 5,"],
        ]
        as $what => [$source, $line]
    ) {
        $file = sources(['en.ts' => $source]) . '/en.ts';
        $refused = thrownBy(static fn() => EnglishDictionary::read($file, 'en'));
        check($refused !== null, "$what should not pass as read");
        check(str_contains($refused->getMessage(), "could not read \"$line\""), "$what is said by its line: " . $refused->getMessage());
    }
});

// ── The bundle the pages read ────────────────────────────────────────────────

test('the bundle: a language\'s strings with English underneath, the library\'s words among them, no mail key, the languages on offer', function () {
    $visitor = new Browser('10.9.1.1');

    $english = $visitor->get('/api/translations/en');
    same(200, $english->status, 'English');
    same(['language', 'languages', 'values'], array_keys($english->json()), 'no second copy of English');
    same('en', $english->json()['language'], 'the language it is in');
    same([ENGLISH, SLOVAK], $english->json()['languages'], 'the languages on offer, with only what a chooser shows');
    same(IAM_PAGE_KEYS + count(LibraryWords::keys()), count($english->json()['values']), 'the 581 strings of the pages, and the library\'s 288');
    same('Save', $english->json()['values']['common.save'], 'a string');
    same(
        ['Language', 'Language: {name} ({code})'],
        [$english->json()['values']['anotoki.language.label'] ?? null, $english->json()['values']['anotoki.language.button'] ?? null],
        'the library\'s words, from the database',
    );
    check(str_contains($english->header('Content-Type'), 'application/json'), 'as JSON');

    $slovak = $visitor->get('/api/translations/sk');
    same(['language', 'languages', 'values', 'english'], array_keys($slovak->json()), 'with English itself, for the admin panel');
    same(['sk', 'Uložiť', 'Save'], [$slovak->json()['language'], $slovak->json()['values']['common.save'], $slovak->json()['english']['common.save']], 'Slovak over English');
    same($english->json()['values'], $slovak->json()['english'], 'the English is the English');

    foreach ([$english, $slovak] as $bundle) {
        foreach (['values', 'english'] as $map) {
            same(
                [],
                array_values(array_filter(array_keys($bundle->json()[$map] ?? []), static fn(string $key): bool => str_starts_with($key, 'mail.'))),
                "no mail key in $map: the mail words are the server's",
            );
        }
        check(!str_contains($bundle->body, 'sign-in code is'), 'nor a mail\'s words under any name');
    }

    // A key with no string of its own reads in English.
    iamDb()->exec("DELETE FROM translations WHERE key_name = 'common.save' AND language_code = 'sk'");
    same('Save', $visitor->get('/api/translations/sk')->json()['values']['common.save'], 'a key with no Slovak string comes in English');
    iamDb()
        ->prepare("INSERT INTO translations (key_name, language_code, value) VALUES ('common.save', 'sk', ?)")
        ->execute([seeded('sk')['common.save']]);

    // A language the IAM does not have: answered in English, and told so.
    $unknown = $visitor->get('/api/translations/de');
    same([200, 'en'], [$unknown->status, $unknown->json()['language']], 'German is answered in English');
    same($english->body, $unknown->body, 'the very same answer');
    same($english->body, $visitor->get('/api/translations/pt-br')->body, 'a code with a region too, when neither it nor its language is offered');
    same($english->body, $visitor->get('/api/translations/de-at')->body, 'whichever region');
});

test('a code with a region that is not offered itself is answered by its language: sk-sk by the Slovak bundle, in the one request', function () {
    $visitor = new Browser('10.9.1.6');
    $slovak = $visitor->get('/api/translations/sk');
    $english = $visitor->get('/api/translations/en');

    // A Slovak browser's first wish is sk-SK; the page asks for it in small letters.
    $regional = $visitor->get('/api/translations/sk-sk');
    same([200, 'sk'], [$regional->status, $regional->json()['language'] ?? null], 'sk-sk is answered in Slovak, and says which language that is');
    same($slovak->body, $regional->body, 'the very answer sk gets, byte for byte');
    same([$slovak->header('ETag'), 'no-cache'], [$regional->header('ETag'), $regional->header('Cache-Control')], 'under the same tag');
    same('Uložiť', $regional->json()['values']['common.save'] ?? null, 'its words are Slovak');
    same($slovak->body, $visitor->get('/api/translations/sk-cz')->body, 'any region of a language on offer');

    $again = $visitor->get('/api/translations/sk-sk', ['headers' => ['If-None-Match' => $slovak->header('ETag')]]);
    same([304, '', $slovak->header('ETag')], [$again->status, $again->body, $again->header('ETag')], 'and a browser that holds the Slovak bundle is told so, whichever of the two it asks for');

    same($english->body, $visitor->get('/api/translations/en-gb')->body, 'en-gb is English');
    same(['language', 'languages', 'values'], array_keys($visitor->get('/api/translations/en-us')->json()), 'with no second copy of it');
    same($english->body, $visitor->get('/api/translations/de-at')->body, 'a region of a language the IAM does not have is English, as that language is');

    // Only a code is ever looked up: what is not one is no language, region or not.
    foreach (['sk-SK', 'SK-sk', 'sk_sk', 'sk-', 'sk-s', 'sk-svk', 'sk-sk-x', 'slk-sk'] as $code) {
        same(404, $visitor->get("/api/translations/$code")->status, "$code is still no code");
    }

    // A language that is switched off speaks through none of its regions.
    iamDb()->exec("UPDATE languages SET enabled = FALSE WHERE code = 'sk'");
    try {
        $hidden = $visitor->get('/api/translations/sk-sk');
        same(['en', ['en']], [$hidden->json()['language'] ?? null, array_column($hidden->json()['languages'] ?? [], 'code')], 'sk-sk while Slovak is hidden: English');
    } finally {
        iamDb()->exec("UPDATE languages SET enabled = TRUE WHERE code = 'sk'");
    }
    same($slovak->body, $visitor->get('/api/translations/sk-sk')->body, 'and Slovak again once it is back');

    // Nothing else reads a code that way: discovery offers whole codes, an account has one of them.
    same(['en', 'sk'], $visitor->get('/.well-known/openid-configuration')->json()['ui_locales_supported'], 'discovery is as it was');
    same('en', languageOrDefault('sk-sk'), 'and sk-sk is no language an account can have');
});

test('a code that is not one is 404, and the database never hears of it', function () {
    $visitor = new Browser('10.9.1.2');
    foreach (['EN', 'Sk', 'e', 'eng', 'en-', 'en-GB', 'en_gb', 'en-gbr', 'sk%0A', '%20sk', 'sk%27', '%FF', '%C3%28', 'en.json'] as $code) {
        $answer = $visitor->get("/api/translations/$code");
        same([404, 'not_found', 'There is no such language.'], [$answer->status, $answer->code(), $answer->json()['error'] ?? null], $code);
    }
    same(404, $visitor->get('/api/translations/en/more')->status, 'nothing under a language');
    same(404, $visitor->get('/api/translations')->status, 'there is no list of its own: the bundle carries it');
    same(405, $visitor->put('/api/translations/sk', ['values' => ['common.save' => 'x']])->status, 'nothing is written here');

    // The admin panel's routes look at the code first, before the body or the database.
    $admin = admin();
    foreach (['%FF', 'SK', 'eng'] as $code) {
        same(404, $admin->put("/api/admin/languages/$code", ['name' => 'X'])->status, "PUT languages/$code");
        same(404, $admin->delete("/api/admin/languages/$code")->status, "DELETE languages/$code");
        same(404, $admin->get("/api/admin/translations/$code/export")->status, "export $code");
        same(404, $admin->put("/api/admin/translations/$code/import", ['common.save' => 'x'])->status, "import $code");
        same(404, $admin->request('PUT', "/api/admin/translations/$code/import", ['body' => 'not json', 'headers' => ['Content-Type' => 'text/plain']])->status, "import $code, whatever its body");
    }
});

test('asked again: 304 for the tag the browser holds - compared weakly - with the headers a cache needs', function () {
    $visitor = new Browser('10.9.1.3');
    $first = $visitor->get('/api/translations/sk');
    $etag = $first->header('ETag');
    same('"' . md5($first->body) . '"', $etag, 'the tag is the answer\'s own');
    same('no-cache', $first->header('Cache-Control'), 'kept, and asked about every time');

    // As the browser got it, as a proxy weakened it, and as an Apache that compresses JSON sent it on: with -gzip, -br or -zstd inside the quotes.
    $gzipped = substr($etag, 0, -1) . '-gzip"';
    $brotli = substr($etag, 0, -1) . '-br"';
    $zstd = substr($etag, 0, -1) . '-zstd"';
    same('"' . md5($first->body) . '-gzip"', $gzipped, 'the tag as mod_deflate rewrites it');
    foreach ([$etag, "W/$etag", "\"other\", W/$etag", "\"a\" , $etag , \"b\"", '*', $gzipped, "W/$gzipped", $brotli, "\"other\", $gzipped", $zstd, "W/$zstd"] as $held) {
        $again = $visitor->get('/api/translations/sk', ['headers' => ['If-None-Match' => $held]]);
        same([304, ''], [$again->status, $again->body], $held);
        same([$etag, 'no-cache'], [$again->header('ETag'), $again->header('Cache-Control')], "the 304's own headers ($held): never no-store");
    }
    foreach (
        [
            '"other"',
            'W/"other"',
            trim($etag, '"'),
            '"other-gzip"',
            substr($etag, 0, -1) . '-zip"',
            substr($etag, 0, -2) . '-gzip"',
            '"other-zstd"',
            substr($etag, 0, -1) . '-zst"',
            substr($etag, 0, -2) . '-zstd"',
        ]
        as $held
    ) {
        $again = $visitor->get('/api/translations/sk', ['headers' => ['If-None-Match' => $held]]);
        same([200, $first->body], [$again->status, $again->body], $held);
    }
    check($etag !== $visitor->get('/api/translations/en')->header('ETag'), 'another language, another tag');

    // A string changes: the tag the browser holds is no longer the answer's.
    iamDb()->exec("UPDATE translations SET value = 'Keep' WHERE key_name = 'common.save' AND language_code = 'en'");
    $changed = $visitor->get('/api/translations/sk', ['headers' => ['If-None-Match' => $etag]]);
    same([200, 'Keep'], [$changed->status, $changed->json()['english']['common.save']], 'the new answer');
    check($changed->header('ETag') !== $etag, 'under a new tag');
    iamDb()->exec("UPDATE translations SET value = 'Save' WHERE key_name = 'common.save' AND language_code = 'en'");
    same($etag, $visitor->get('/api/translations/sk')->header('ETag'), 'the same strings, the same tag');
});

test('the bundle is the same for everybody: no cookie read, none set, and no other site may read it', function () {
    $anonymous = (new Browser('10.9.1.4'))->get('/api/translations/sk');

    $signedIn = admin();
    $sessionBefore = iamDb()->query('SELECT last_seen_at FROM sessions ORDER BY id DESC LIMIT 1')->fetchColumn();
    $answer = $signedIn->get('/api/translations/sk', ['headers' => ['Origin' => GENSHIN_ORIGIN]]);
    same($anonymous->body, $answer->body, 'signed in or not, the same words');
    same('', $answer->header('Set-Cookie'), 'no cookie is set');
    same('', $answer->header('Access-Control-Allow-Origin'), 'no CORS, even for an origin an application registered');
    same('', $answer->header('Access-Control-Allow-Credentials'), 'nor credentials');
    same($sessionBefore, iamDb()->query('SELECT last_seen_at FROM sessions ORDER BY id DESC LIMIT 1')->fetchColumn(), 'and the session was not touched');

    // A cookie naming a session that is over: requireSession() would clear it; this route never looks.
    $stale = new Browser('10.9.1.5');
    check($signedIn->cookies !== [], 'the admin\'s browser holds the session cookie');
    foreach (array_keys($signedIn->cookies) as $name) {
        $stale->cookies[$name] = str_repeat('a', 43); // the shape of a session's secret, and nobody's
    }
    $answer = $stale->get('/api/translations/sk');
    same([200, $anonymous->body, ''], [$answer->status, $answer->body, $answer->header('Set-Cookie')], 'a dead session still reads in its language, and its cookie is left alone');

    $preflight = (new Browser('10.9.1.4'))->request('OPTIONS', '/api/translations/sk', ['headers' => ['Origin' => GENSHIN_ORIGIN]]);
    same('', $preflight->header('Access-Control-Allow-Origin'), 'no preflight is answered');

    // With no English string at all there is nothing to answer with: the pages keep their compiled English.
    $pdo = iamDb();
    $pdo->beginTransaction();
    try {
        $pdo->exec("DELETE FROM translations WHERE language_code = 'en' AND key_name NOT LIKE 'mail.%'");
        $empty = (new Browser('10.9.1.4'))->get('/api/translations/sk');
        same([503, 'translations_unavailable', '60'], [$empty->status, $empty->code(), $empty->header('Retry-After')], 'English with no strings: asked again in a minute');
        same('', $empty->header('ETag'), 'and nothing to cache');
    } finally {
        $pdo->rollBack();
    }
    same(200, (new Browser('10.9.1.4'))->get('/api/translations/sk')->status, 'back as it was');
});

// ── Who may do what ──────────────────────────────────────────────────────────

test('the languages and the strings are for IAM admins, and a write needs the header of the IAM\'s own pages', function () {
    $before = [everyString(), iamDb()->query('SELECT * FROM languages ORDER BY code')->fetchAll()];
    $routes = [
        ['GET', '/api/admin/languages', null],
        ['POST', '/api/admin/languages', ['code' => 'xx', 'name' => 'Nobody\'s', 'native_name' => 'Nobody\'s']],
        ['PUT', '/api/admin/languages/sk', ['enabled' => false, 'name' => 'Changed']],
        ['DELETE', '/api/admin/languages/sk', null],
        ['GET', '/api/admin/translations', null],
        ['PUT', '/api/admin/translations', ['values' => ['common.save' => ['en' => 'Changed']]]],
        ['GET', '/api/admin/translations/sk/export', null],
        ['PUT', '/api/admin/translations/sk/import', ['common.save' => 'Changed']],
    ];

    $player = new Browser('10.9.2.1');
    same(200, $player->signIn('player@example.test', 'correct horse')->status, 'an account with no role in the IAM signs in');
    $admin = admin();

    foreach ($routes as [$method, $path, $body]) {
        $options = $body === null ? [] : ['json' => $body];
        $signedOut = (new Browser('10.9.2.2'))->request($method, $path, $options);
        same([401, 'no_session'], [$signedOut->status, $signedOut->code()], "$method $path, signed out");
        $noRole = $player->request($method, $path, $options);
        same([403, 'forbidden'], [$noRole->status, $noRole->code()], "$method $path, signed in without the role");
        if ($method !== 'GET') {
            $forged = $admin->request($method, $path, $options + ['csrf' => false]);
            same([403, 'csrf'], [$forged->status, $forged->code()], "$method $path, an admin's cookie without X-IAM-Request");
            $sibling = $admin->request($method, $path, $options + ['headers' => ['Origin' => 'https://genshin.anotoki.eu']]);
            same([403, 'csrf'], [$sibling->status, $sibling->code()], "$method $path, from a sibling site's page");
        }
    }

    // A path written with escapes is routed as the path it spells, and its checks come with the route: no way round them.
    foreach (['/api/%61dmin/translations', '/api/admin/%74ranslations', '/api/admin/translations/%73k/export', '/api/%61dmin/%6Canguages'] as $path) {
        same([401, 'no_session'], [($r = (new Browser('10.9.2.2'))->get($path))->status, $r->code()], "GET $path, signed out");
        same([403, 'forbidden'], [($r = $player->get($path))->status, $r->code()], "GET $path, signed in without the role");
        same(200, $admin->get($path)->status, "GET $path is the route it spells: an admin reads it");
    }
    $changed = ['values' => ['common.save' => ['en' => 'Changed']]];
    same([401, 'no_session'], [($r = (new Browser('10.9.2.2'))->put('/api/%61dmin/translations', $changed))->status, $r->code()], 'PUT, escaped, signed out');
    same([403, 'forbidden'], [($r = $player->put('/api/admin/%74ranslations', $changed))->status, $r->code()], 'PUT, escaped, signed in without the role');
    same(
        [403, 'csrf'],
        [($r = $admin->request('PUT', '/api/%61dmin/translations', ['json' => $changed, 'csrf' => false]))->status, $r->code()],
        'PUT, escaped, an admin\'s cookie without X-IAM-Request',
    );
    same([403, 'forbidden'], [($r = $player->request('DELETE', '/api/admin/languages/%73k'))->status, $r->code()], 'DELETE, escaped, signed in without the role');

    same($before, [everyString(), iamDb()->query('SELECT * FROM languages ORDER BY code')->fetchAll()], 'and nothing of it was done');
    same(
        0,
        auditCount('translations.saved') + auditCount('translations.imported') + auditCount('language.created') + auditCount('language.updated') + auditCount('language.deleted'),
        'nor recorded as done',
    );

    foreach (['/api/admin/languages', '/api/admin/translations', '/api/admin/translations/sk/export'] as $path) {
        $read = $admin->get($path);
        same([200, 'no-store'], [$read->status, $read->header('Cache-Control')], "an admin reads $path, and nothing keeps it");
    }
});

// ── The mails, as they go out ────────────────────────────────────────────────

test('the six mails are what they were, byte for byte: three mails, two languages', function () {
    $confirmLink = TEST_ISSUER . '/confirm-email?token=' . str_repeat('ab', 32);
    $resetLink = TEST_ISSUER . '/reset-password?token=' . str_repeat('cd', 32);
    $before = mailsAsTheyWere('Anna Nováková', $confirmLink, $resetLink, '012345');

    foreach (['en', 'sk'] as $language) {
        $email = "anna-$language@example.test";
        $user = ['id' => null, 'username' => 'Anna Nováková', 'email' => $email, 'language' => $language];
        $head = static fn(
            string $from,
            string $subject,
        ): string => "From: anotoki <$from@localhost>\nReply-To: $from@localhost\nMIME-Version: 1.0\nContent-Type: text/plain; charset=UTF-8\nContent-Transfer-Encoding: 8bit\nTo: $email\nSubject: $subject\n\n";

        same(true, sendConfirmationMail($user, str_repeat('ab', 32)), "the confirmation ($language) is sent");
        same($head('support', $before['confirm'][$language]['subject']) . $before['confirm'][$language]['body'], rawMailTo($email), "the confirmation ($language), the whole message");
        same([MAIL_KIND_CONFIRMATION, $before['confirm'][$language]['subject'], 'sent'], lastLoggedMail($email), "logged ($language)");

        same(true, sendPasswordResetMail($user, str_repeat('cd', 32)), "the reset ($language) is sent");
        same($head('noreply', $before['reset'][$language]['subject']) . $before['reset'][$language]['body'], rawMailTo($email), "the reset ($language), the whole message");
        same([MAIL_KIND_PASSWORD_RESET, $before['reset'][$language]['subject'], 'sent'], lastLoggedMail($email), "logged ($language)");

        same(true, sendLoginCodeMail($user, '012345'), "the sign-in code ($language) is sent");
        same($head('noreply', $before['loginCode'][$language]['subject']) . $before['loginCode'][$language]['body'], rawMailTo($email), "the sign-in code ($language), the whole message");
        same([MAIL_KIND_LOGIN_CODE, null, 'sent'], lastLoggedMail($email), "logged without its subject, which carries the code ($language)");
    }

    // The language is the account's while it is one: anything else reads in English.
    foreach (['de', '', null, 'SK-sk', 7] as $language) {
        $email = 'other-' . md5(json_encode($language)) . '@example.test';
        sendPasswordResetMail(['id' => null, 'username' => 'Anna Nováková', 'email' => $email, 'language' => $language], str_repeat('cd', 32));
        same($before['reset']['en'], lastMailTo($email), 'a language the IAM does not offer reads in English: ' . json_encode($language));
    }
    sendPasswordResetMail(['id' => null, 'username' => 'Anna Nováková', 'email' => 'nolanguage@example.test'], str_repeat('cd', 32));
    same($before['reset']['en'], lastMailTo('nolanguage@example.test'), 'and so does an account with none');
});

test('a mail never fails and never carries a key: no tables, a broken query, a missing row - the built-in English, string by string', function () {
    $pdo = iamDb();
    $log = testEnvironment()['base'] . DIRECTORY_SEPARATOR . 'php-errors.log';
    $previous = ini_set('error_log', $log);

    try {
        // A string of its own gone in Slovak: that one in English, the rest in Slovak.
        $pdo->beginTransaction();
        $pdo->exec("DELETE FROM translations WHERE key_name = 'mail.reset.intro' AND language_code = 'sk'");
        $words = mailWords('sk');
        same(seeded('en')['mail.reset.intro'], $words['mail.reset.intro'], 'a string Slovak does not have reads in English');
        same(seeded('sk')['mail.reset.ignore'], $words['mail.reset.ignore'], 'the rest in Slovak');
        $pdo->exec("DELETE FROM translations WHERE key_name = 'mail.reset.intro'");
        same(MAIL_FALLBACK['mail.reset.intro'], mailWords('sk')['mail.reset.intro'], 'a string nobody has: the one kept in the code');
        $pdo->exec("UPDATE translations SET value = '   ' WHERE key_name = 'mail.reset.ignore' AND language_code = 'sk'");
        same(seeded('en')['mail.reset.ignore'], mailWords('sk')['mail.reset.ignore'], 'a string of nothing but spaces is no string');

        // Nor is one of characters nobody can see - written past the panel, which refuses them: never a paragraph or a subject of nothing.
        $write = $pdo->prepare('UPDATE translations SET value = ? WHERE key_name = ? AND language_code = ?');
        foreach (["\u{200B}", "\u{2060}\u{00AD}", " \u{FEFF}\u{200D} "] as $nothing) {
            $write->execute([$nothing, 'mail.reset.ignore', 'sk']);
            same(seeded('en')['mail.reset.ignore'], mailWords('sk')['mail.reset.ignore'], 'a Slovak string of no width reads in English: ' . json_encode($nothing));
        }
        $write->execute(['Reworded, in English.', 'mail.reset.ignore', 'en']);
        same('Reworded, in English.', mailWords('sk')['mail.reset.ignore'], 'in the English the database has');
        $write->execute(["\u{200B}", 'mail.reset.ignore', 'en']);
        same(MAIL_FALLBACK['mail.reset.ignore'], mailWords('sk')['mail.reset.ignore'], 'and with an English one of no width too, in the English kept in the code');
        same(MAIL_FALLBACK['mail.reset.ignore'], mailWords('en')['mail.reset.ignore'], 'for an English reader as well');
        $write->execute(["\u{200B}", 'mail.reset.subject', 'en']);
        $write->execute(["\u{200B}", 'mail.reset.subject', 'sk']);
        same(
            true,
            sendPasswordResetMail(['id' => null, 'username' => 'Anna', 'email' => 'unseen@example.test', 'language' => 'sk'], str_repeat('cd', 32)),
            'a reset with a subject of no width in both languages is sent',
        );
        same(MAIL_FALLBACK['mail.reset.subject'], lastMailTo('unseen@example.test')['subject'] ?? null, 'under the subject kept in the code - not under an invisible one');
        $write->execute(["Heslo\u{200B}", 'mail.reset.subject', 'sk']);
        same("Heslo\u{200B}", mailWords('sk')['mail.reset.subject'], 'a string with a letter in it is a string, whatever else it has');

        $pdo->exec("DELETE FROM translation_keys WHERE name LIKE 'mail.%'");
        same(MAIL_FALLBACK, mailWords('sk'), 'no mail string at all');
        $pdo->rollBack();

        // The tables not there: no query that could fail is made.
        $pdo->beginTransaction();
        $pdo->exec('ALTER TABLE translations RENAME TO translations_elsewhere');
        same(MAIL_FALLBACK, mailWords('sk'), 'no translations table');
        same(1, (int) $pdo->query('SELECT 1')->fetchColumn(), 'and the transaction it was asked in is still alive');
        $pdo->rollBack();

        // A query that fails: caught, said in the log, and the mail goes out in English.
        check(!is_file($log) || !str_contains((string) file_get_contents($log), '[mail]'), 'nothing was logged so far');
        $pdo->beginTransaction();
        $pdo->exec('ALTER TABLE translations RENAME COLUMN value TO words');
        same(MAIL_FALLBACK, mailWords('sk'), 'a query that fails');
        same(true, sendPasswordResetMail(['id' => null, 'username' => 'Anna', 'email' => 'broken@example.test', 'language' => 'sk'], str_repeat('cd', 32)), 'and the mail is still sent');
        $pdo->rollBack();
        same('Set a new anotoki password', lastMailTo('broken@example.test')['subject'] ?? null, 'in English');
        check(str_contains((string) file_get_contents($log), '[mail] could not read the words of a message'), 'and the failure is in the log');
    } finally {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        ini_set('error_log', (string) $previous);
    }

    same(seeded('sk')['mail.reset.intro'], mailWords('sk')['mail.reset.intro'], 'everything is as it was');
});

test('a string written straight into the table cannot turn a mail against its reader: no header, no token, no long subject', function () {
    $pdo = iamDb();
    $subject = $pdo->prepare("UPDATE translations SET value = ? WHERE key_name = 'mail.reset.subject' AND language_code = 'en'");
    $ask = static function (string $ip): void {
        same(200, (new Browser($ip))->post('/api/password/forgot', ['email' => 'player@example.test'])->status, 'a reset is asked for');
    };

    try {
        // A header smuggled into the subject.
        $subject->execute(["Reset\r\nBcc: x@evil.test"]);
        $ask('10.9.3.1');
        $raw = (string) rawMailTo('player@example.test');
        same(0, preg_match('/^Bcc:/mi', $raw), 'the message has no Bcc line');
        same(1, preg_match('/^Subject: Reset  Bcc: x@evil\.test$/m', $raw), 'the subject is one line');
        same(7, substr_count(explode("\n\n", $raw, 2)[0], "\n") + 1, 'and the headers are the seven there always are');
        same([MAIL_KIND_PASSWORD_RESET, 'Reset  Bcc: x@evil.test', 'sent'], lastLoggedMail('player@example.test'), 'sent, and logged as one line');
        foreach (["Reset\nBcc: x@evil.test", "Reset\rBcc: x@evil.test", "Reset\n\nA body of its own"] as $value) {
            $subject->execute([$value]);
            sendPasswordResetMail(['id' => null, 'username' => 'Anna', 'email' => 'lines@example.test', 'language' => 'en'], str_repeat('cd', 32));
            $raw = (string) rawMailTo('lines@example.test');
            same(0, preg_match('/^Bcc:/mi', $raw), 'no Bcc line: ' . json_encode($value));
            check(str_contains($raw, "\nSubject: " . str_replace(["\r", "\n"], ' ', $value) . "\n\nHello Anna,\n"), 'the body starts where it always does: ' . json_encode($value));
        }

        // Placeholders the key does not have: never filled - not with the link, not with its token.
        $subject->execute(['Reset {link} {code} {token} {username} {minutes}']);
        $ask('10.9.3.2');
        $mail = lastMailTo('player@example.test');
        same('Reset {link} {code} {token} {username} {minutes}', $mail['subject'], 'a subject may use no placeholder, so none is filled');
        check(preg_match('/token=([0-9a-f]{64})/', $mail['body'], $token) === 1, 'the link is in the body, where the code puts it');
        $logged = lastLoggedMail('player@example.test');
        check(!str_contains($mail['subject'], $token[1]) && !str_contains((string) $logged[1], $token[1]), 'the token is in neither the subject nor the log');
        same('Reset {link} {code} {token} {username} {minutes}', $logged[1], 'the log has the subject as it went');

        // A link in a paragraph, put there by SQL: it goes out as written (the panel would have refused it) - but the real link is still the code's.
        $pdo->exec("UPDATE translations SET value = 'Reset {link}:' WHERE key_name = 'mail.reset.intro' AND language_code = 'en'");
        $ask('10.9.3.3');
        check(str_contains(lastMailTo('player@example.test')['body'], "\n\nReset {link}:\n\n" . TEST_ISSUER . '/reset-password?token='), 'no {link} is ever filled; the link is on the line after');

        // A subject of any length is cut.
        $subject->execute([str_repeat('ž', 300)]);
        $ask('10.9.3.4');
        same(str_repeat('ž', 200), lastMailTo('player@example.test')['subject'], 'a subject is cut to 200 characters');
        same(str_repeat('ž', 200), lastLoggedMail('player@example.test')[1], 'in the log too, which has room for 255');
    } finally {
        $subject->execute([seeded('en')['mail.reset.subject']]);
        $pdo->prepare("UPDATE translations SET value = ? WHERE key_name = 'mail.reset.intro' AND language_code = 'en'")->execute([seeded('en')['mail.reset.intro']]);
    }

    sendPasswordResetMail(['id' => null, 'username' => 'Anna', 'email' => 'lines@example.test', 'language' => 'en'], str_repeat('cd', 32));
    same('Set a new anotoki password', lastMailTo('lines@example.test')['subject'], 'and back to what it was');
});

// ── Saving strings ───────────────────────────────────────────────────────────

test('the grid: every key with its strings in every language, the mail keys and the library\'s among them', function () {
    $admin = admin();
    $grid = $admin->get('/api/admin/translations');
    same(200, $grid->status, 'the grid');
    same(['languages', 'keys'], array_keys($grid->json()), 'its two parts');
    same($admin->get('/api/admin/languages')->json()['languages'], $grid->json()['languages'], 'every language, as the languages page has them');
    same(IAM_PAGE_KEYS + IAM_MAIL_KEYS + count(LibraryWords::keys()), count($grid->json()['keys']), 'every key');
    same(sorted(array_column($grid->json()['keys'], 'name')), array_column($grid->json()['keys'], 'name'), 'by name');

    $byName = array_column($grid->json()['keys'], null, 'name');
    same(['name' => 'common.save', 'description' => 'Common. No placeholders.', 'values' => ['en' => 'Save', 'sk' => 'Uložiť']], $byName['common.save'], 'a key of the pages');
    same(['en' => 'Set a new anotoki password', 'sk' => 'Nastavenie nového hesla anotoki'], $byName['mail.reset.subject']['values'], 'a key of the mails');
    same('Sign in. Placeholders: {app}.', $byName['login.continueTo']['description'], 'a description says which placeholders a string has');
    check(str_contains($byName['mail.reset.greeting']['description'], '{username}'), 'a mail key\'s too');
    same(
        [LibraryWords::all()['anotoki.language.button']['description'], ['en' => 'Language: {name} ({code})', 'sk' => 'Jazyk: {name} ({code})']],
        [$byName['anotoki.language.button']['description'], $byName['anotoki.language.button']['values']],
        'a key of the library\'s, with the library\'s description',
    );
});

test('saving strings: the changed ones, by whoever saved them; a string sent as it is stays as it was; an empty one goes', function () {
    $admin = admin();
    $adminId = $GLOBALS['adminId'];
    $untouched = stored('common.save', 'en');
    $audits = auditCount('translations.saved');

    $saved = save($admin, ['common.save' => ['sk' => "  Ulož\x07iť teraz "]]);
    same(200, $saved->status, 'saved: ' . substr($saved->body, 0, 200));
    same(['languages', 'keys'], array_keys($saved->json()), 'answered with the grid as it is now');
    same(['en' => 'Save', 'sk' => 'Uložiť teraz'], array_column($saved->json()['keys'], null, 'name')['common.save']['values'], 'the text cleaned');
    same(['Uložiť teraz', $adminId], array_slice(stored('common.save', 'sk'), 0, 2), 'who saved it is the session\'s account');
    same($untouched, stored('common.save', 'en'), 'what was not sent was not touched');
    same('Uložiť teraz', (new Browser('10.9.4.1'))->get('/api/translations/sk')->json()['values']['common.save'], 'and the pages read it at once');

    same([$adminId, null, null, ordered(['languages' => ['sk'], 'count' => 1, 'keys' => ['common.save']])], lastAudit('translations.saved'), 'recorded: who, which languages, how many, which keys');
    same($audits + 1, auditCount('translations.saved'), 'once');

    // A page that still shows the old grid sends strings as they are: nothing is rewritten, nothing recorded.
    $mine = stored('common.save', 'sk');
    same(200, save($admin, ['common.save' => ['sk' => 'Uložiť teraz', 'en' => ' Save ']])->status, 'sent as they are');
    same($mine, stored('common.save', 'sk'), 'still from when it was saved');
    same($untouched, stored('common.save', 'en'), 'and English still a migration\'s: nobody\'s');
    same(200, save($admin, [])->status, 'a save of nothing is the fresh grid');
    same($audits + 1, auditCount('translations.saved'), 'a save that changes nothing is not recorded');

    // An empty string: that language has none of its own again, and reads in English.
    same(200, save($admin, ['common.save' => ['sk' => "  \t"]])->status, 'an empty Slovak string');
    same(null, stored('common.save', 'sk'), 'the row is gone');
    same('Save', (new Browser('10.9.4.1'))->get('/api/translations/sk')->json()['values']['common.save'], 'and Slovak reads it in English');
    same(200, save($admin, ['common.save' => ['sk' => ' ']])->status, 'and once more, when there is none');
    same([200, null], [save($admin, ['common.save' => ['sk' => "\u{200B}"]])->status, stored('common.save', 'sk')], 'a zero-width space is an empty string too: no row is made of it');
    same('Save', (new Browser('10.9.4.1'))->get('/api/translations/sk')->json()['values']['common.save'], 'so Slovak still reads it in English, not as nothing');
    same($audits + 2, auditCount('translations.saved'), 'the deletion was recorded, the ones after it changed nothing');
    $invisible = save($admin, ['common.save' => ['en' => "\u{200B}\u{2060}"]]);
    same(
        [422, 'fallback_required', 'common.save', 'en'],
        [$invisible->status, $invisible->code(), $invisible->json()['key'] ?? null, $invisible->json()['language'] ?? null],
        'and English cannot be saved as one: it would be a button with no word on it',
    );
    same($untouched, stored('common.save', 'en'), 'English is as it was');

    // English reworded keeps its placeholders; a translation may leave one out.
    same(200, save($admin, ['common.passwordHint' => ['en' => '{count} characters or more.', 'sk' => 'Aspoň pár znakov.'], 'common.save' => ['sk' => 'Uložiť']])->status, 'three strings at once');
    same(['{count} characters or more.', $adminId], array_slice(stored('common.passwordHint', 'en'), 0, 2), 'English, by the admin');
    same(ordered(['languages' => ['en', 'sk'], 'count' => 3, 'keys' => ['common.passwordHint', 'common.save']]), lastAudit('translations.saved')[3], 'two languages, three strings, two keys');
    same(200, save($admin, ['common.passwordHint' => ['en' => seeded('en')['common.passwordHint'], 'sk' => seeded('sk')['common.passwordHint']]])->status, 'and back');

    // A library key is reworded like any other - and keeps the placeholders the library's code fills.
    same(200, save($admin, ['anotoki.language.button' => ['sk' => 'Jazyk stránky: {name} ({code})']])->status, 'the language switcher\'s name, reworded in Slovak');
    same('Jazyk stránky: {name} ({code})', (new Browser('10.9.4.1'))->get('/api/translations/sk')->json()['values']['anotoki.language.button'], 'and the pages read it');
    $lost = save($admin, ['anotoki.language.button' => ['en' => 'Language: {name}']]);
    same([422, 'placeholder_changed', 'code'], [$lost->status, $lost->code(), $lost->json()['placeholder'] ?? null], 'its English must keep {code}: the library puts the code shown there');
    same(200, save($admin, ['anotoki.language.button' => ['sk' => seeded('sk')['anotoki.language.button']]])->status, 'and back');
});

test('saving strings: all of it is checked before any of it is written, and each refusal names its key', function () {
    $admin = admin();
    $before = everyString();
    $audits = auditCount('translations.saved');

    $refusals = [
        'unknown_key' => [['common.save' => ['sk' => 'Ulož'], 'no.such' => ['en' => 'x'], 'nor.this' => ['sk' => 'y']], ['key' => 'no.such', 'keys' => ['no.such', 'nor.this']]],
        'unknown_language' => [['common.save' => ['sk' => 'Ulož', 'hu' => 'Mentés']], ['key' => 'common.save', 'language' => 'hu']],
        'fallback_required' => [['common.close' => ['sk' => 'Zatvor'], 'common.save' => ['en' => '  ']], ['key' => 'common.save', 'language' => 'en']],
        'placeholder_changed' => [
            ['common.save' => ['sk' => 'Ulož'], 'common.passwordHint' => ['en' => 'A few characters.']],
            ['key' => 'common.passwordHint', 'language' => 'en', 'placeholder' => 'count'],
        ],
        'unknown_placeholder' => [
            ['common.save' => ['sk' => 'Ulož'], 'common.passwordHint' => ['sk' => 'Aspoň {pocet} znakov.']],
            ['key' => 'common.passwordHint', 'language' => 'sk', 'placeholder' => 'pocet'],
        ],
        'invalid_placeholder' => [['common.save' => ['sk' => 'Ulož'], 'common.passwordHint' => ['sk' => 'Aspoň { count } znakov.']], ['key' => 'common.passwordHint', 'language' => 'sk']],
        'placeholder_missing' => [
            ['common.save' => ['sk' => 'Ulož'], 'login.continueTo' => ['sk' => 'Prihlás sa a pokračuj']],
            ['key' => 'login.continueTo', 'language' => 'sk', 'placeholder' => 'app'],
        ],
        'address_in_mail' => [['common.save' => ['sk' => 'Ulož'], 'mail.reset.intro' => ['sk' => 'Otvor https://evil.test/reset a zvoľ si ho:']], ['key' => 'mail.reset.intro', 'language' => 'sk']],
    ];
    foreach ($refusals as $code => [$values, $about]) {
        $refused = save($admin, $values);
        same([422, $code], [$refused->status, $refused->code()], $refused->body);
        same($about, array_diff_key($refused->json(), ['error' => 0, 'code' => 0]), "$code says what it is about");
        check(is_string($refused->json()['error']) && $refused->json()['error'] !== '', "$code comes with a sentence");
        same($before, everyString(), "$code: nothing of that save was written - not the good strings before the bad one either");
    }
    $added = save($admin, ['common.passwordHint' => ['en' => 'At least {count} of {total} characters.']]);
    same(['placeholder_changed', 'total'], [$added->code(), $added->json()['placeholder'] ?? null], 'a placeholder the page does not fill');
    same(
        ['placeholder_missing', 'app'],
        [
            save($admin, ['register.forApp' => ['en' => 'Create an account to continue']])->code(),
            save($admin, ['register.forApp' => ['en' => 'Create an account to continue']])->json()['placeholder'] ?? null,
        ],
        'the sentence the registration page cuts, in English',
    );

    // A brace that is not a placeholder's would be read by everybody as a brace: refused in any language, English too.
    foreach (
        [
            ['common.passwordHint', 'en', '{{count}} characters or more.', 'like {count}:'],
            ['common.passwordHint', 'sk', 'Aspoň {počet} znakov.', 'like {count}:'],
            ['login.continueTo', 'sk', 'Prihlás sa a pokračuj do { app }', 'like {app}:'],
            ['common.save', 'sk', 'Uložiť }', 'a brace belongs only around a placeholder'],
        ]
        as [$key, $code, $text, $says]
    ) {
        $refused = save($admin, [$key => [$code => $text]]);
        same([422, 'invalid_placeholder', $key, $code], [$refused->status, $refused->code(), $refused->json()['key'] ?? null, $refused->json()['language'] ?? null], "$key ($code): $text");
        check(str_contains((string) ($refused->json()['error'] ?? ''), $says), "and the sentence shows how it is written ($says): " . ($refused->json()['error'] ?? ''));
        check(!array_key_exists('placeholder', $refused->json()), 'it names no placeholder: there is none to name');
    }

    // A text with more than a million spaces inside it - a body of 1 MiB has room for one - is a text
    // too long. Taken for an empty one it would be "200, saved", and the Slovak string gone.
    $endless = 'a' . str_repeat(' ', 1040000) . 'b';
    check(stored('common.save', 'sk') !== null, 'there is a Slovak string to lose');
    $refused = save($admin, ['common.save' => ['sk' => $endless]]);
    same([422, 'invalid_value', 'common.save', 'sk'], [$refused->status, $refused->code(), $refused->json()['key'] ?? null, $refused->json()['language'] ?? null], 'a save of it, in another language');
    $refused = save($admin, ['common.save' => ['en' => $endless]]);
    same(
        [422, 'invalid_value', 'common.save', 'en'],
        [$refused->status, $refused->code(), $refused->json()['key'] ?? null, $refused->json()['language'] ?? null],
        'and in English: too long, not "English is required"',
    );
    $refused = $admin->put('/api/admin/translations/sk/import', ['common.save' => $endless]);
    same([422, 'invalid_value', 'common.save'], [$refused->status, $refused->code(), $refused->json()['key'] ?? null], 'nor does an import pass it over, or delete for it');
    $refused = $admin->post('/api/admin/languages', ['code' => 'fr', 'name' => $endless, 'native_name' => 'Français']);
    same([422, 'invalid_language'], [$refused->status, $refused->code()], 'and it is no language\'s name');

    foreach (
        [
            'no values' => ['strings' => []],
            'values as text' => ['values' => 'common.save'],
            'values as a list' => ['values' => [['common.save' => ['en' => 'x']]]],
            'values as an empty list' => ['values' => []],
            'a key with a text' => ['values' => ['common.save' => 'Save']],
            'a string that is a number' => ['values' => ['common.save' => ['sk' => 5]]],
            'a string that is an object' => ['values' => ['common.save' => ['sk' => ['one' => 'x']]]],
            'a string that is null' => ['values' => ['common.save' => ['sk' => null]]],
            '2001 characters' => ['values' => ['common.save' => ['sk' => str_repeat('ž', 2001)]]],
        ]
        as $what => $body
    ) {
        $refused = $admin->put('/api/admin/translations', $body);
        same([422, 'invalid_value'], [$refused->status, $refused->code()], $what);
    }

    // The body is read by the library, from the request as it came: JSON, and something in it.
    foreach (
        [
            'a form' => [['form' => ['values' => 'x']], 415, 'unsupported_media_type'],
            'no body and no type' => [[], 415, 'unsupported_media_type'],
            'text' => [['body' => '{"values": {}}', 'headers' => ['Content-Type' => 'text/plain']], 415, 'unsupported_media_type'],
            'JSON with nothing in it' => [['body' => '', 'headers' => ['Content-Type' => 'application/json']], 422, 'empty_body'],
            'JSON that is none' => [['body' => '{"values": ', 'headers' => ['Content-Type' => 'application/json; charset=utf-8']], 400, 'invalid_json'],
        ]
        as $what => [$options, $status, $code]
    ) {
        $refused = $admin->request('PUT', '/api/admin/translations', $options);
        same([$status, $code], [$refused->status, $refused->code()], "$what: " . $refused->body);
        check(is_string($refused->json()['error'] ?? null), "$what is answered in the IAM's body, with a sentence");
    }
    same(
        [415, 'unsupported_media_type'],
        [($r = $admin->request('POST', '/api/admin/languages', ['form' => ['code' => 'fr', 'name' => 'French', 'native_name' => 'Français']]))->status, $r->code()],
        'a form is no language either',
    );

    same($before, everyString(), 'and after all of it, the strings are what they were');
    same($audits, auditCount('translations.saved'), 'and nothing was recorded');

    same(200, save($admin, ['common.save' => ['sk' => str_repeat('ž', 2000)]])->status, '2000 characters, counted as people count them');
    same(200, save($admin, ['common.save' => ['sk' => seeded('sk')['common.save']]])->status, 'and back');
});

test('a save or an import is at most 1 MiB: by what it says of itself, and by what arrives', function () {
    $admin = admin();
    $before = everyString();
    $limit = iamTranslationsConfig()->bodyLimit;
    same(1024 * 1024, $limit, 'one mebibyte');

    // A body of exactly the limit, and one of a byte more.
    $empty = strlen((string) json_encode(['values' => ['common.save' => ['sk' => '']]]));
    $fits = ['values' => ['common.save' => ['sk' => str_repeat('x', $limit - $empty)]]];
    $over = ['values' => ['common.save' => ['sk' => str_repeat('x', $limit - $empty + 1)]]];
    same($limit, strlen((string) json_encode($fits)), 'a body of 1 MiB');
    same($limit + 1, strlen((string) json_encode($over)), 'and one of 1 MiB + 1 byte');

    $atLimit = $admin->put('/api/admin/translations', $fits);
    same([422, 'invalid_value'], [$atLimit->status, $atLimit->code()], '1 MiB is read (and its one string found too long)');
    $tooLarge = $admin->put('/api/admin/translations', $over);
    same([413, 'too_large'], [$tooLarge->status, $tooLarge->code()], '1 MiB + 1 byte is not');

    $emptyImport = strlen((string) json_encode(['common.save' => '']));
    $import = $admin->put('/api/admin/translations/sk/import', ['common.save' => str_repeat('x', $limit - $emptyImport + 1)]);
    same([413, 'too_large'], [$import->status, $import->code()], 'an import of 1 MiB + 1 byte');
    same(422, $admin->put('/api/admin/translations/sk/import', ['common.save' => str_repeat('x', $limit - $emptyImport)])->status, 'and one of 1 MiB');

    // What it says of itself is enough, before anything is read - and before the strings or the
    // language are looked at. (A code that is none is a 404 first: no route is that language's.)
    foreach (['/api/admin/translations' => ['values' => ['no.such' => ['xx' => 5]]], '/api/admin/translations/zz/import' => ['no.such' => 5]] as $path => $body) {
        foreach ([(string) ($limit + 1), '99999999999999999999', 'lots'] as $declared) {
            $refused = $admin->request('PUT', $path, ['json' => $body, 'headers' => ['Content-Length' => $declared]]);
            same([413, 'too_large'], [$refused->status, $refused->code()], "$path with Content-Length: $declared");
        }
    }
    same(
        404,
        $admin->request('PUT', '/api/admin/translations/nope/import', ['json' => ['no.such' => 5], 'headers' => ['Content-Length' => (string) ($limit + 1)]])->status,
        'a code that is none is no language, however large',
    );
    same(
        200,
        $admin->request('PUT', '/api/admin/translations', ['json' => ['values' => new stdClass()], 'headers' => ['Content-Length' => (string) $limit]])->status,
        'a Content-Length of the limit itself passes',
    );

    same($before, everyString(), 'and none of it changed a string');
});

test('a mail\'s words through the panel: reworded, refused - and every change recorded with the old and the new text', function () {
    $admin = admin();
    $adminId = $GLOBALS['adminId'];
    $before = everyString();

    // The attack the rules exist for, through both doors.
    $smuggled = "Reset\r\nBcc: x@evil.test";
    $put = save($admin, ['mail.reset.subject' => ['en' => $smuggled]]);
    same([422, 'invalid_value', 'mail.reset.subject', 'en'], [$put->status, $put->code(), $put->json()['key'] ?? null, $put->json()['language'] ?? null], 'a header in a subject, saved in the grid');
    $import = $admin->put('/api/admin/translations/en/import', ['mail.reset.subject' => $smuggled]);
    same([422, 'invalid_value', 'mail.reset.subject'], [$import->status, $import->code(), $import->json()['key'] ?? null], 'and imported');
    same(
        [422, 'invalid_value'],
        [
            $admin->put('/api/admin/translations/sk/import', ['mail.reset.subject' => "Reset\nBcc: x@evil.test"])->status,
            $admin->put('/api/admin/translations/sk/import', ['mail.reset.subject' => "Reset\nBcc: x@evil.test"])->code(),
        ],
        'in any language',
    );

    foreach (
        [
            'address_in_mail' => [
                ['mail.reset.intro' => ['en' => 'Open https://evil.test/reset to choose one:']],
                ['mail.confirm.ignore' => ['sk' => 'Ak nie, napíš na podpora@evil.test.']],
                ['mail.loginCode.intro' => ['sk' => 'Kód zadaj na www.evil.test:']],
            ],
            'unknown_placeholder' => [['mail.reset.intro' => ['sk' => 'Otvor {link}:']], ['mail.reset.subject' => ['en' => 'Reset {code}']]],
            'placeholder_missing' => [
                ['mail.reset.greeting' => ['sk' => 'Ahoj,']],
                ['mail.loginCode.subject' => ['en' => 'Your anotoki sign-in code']],
                ['mail.reset.validity' => ['en' => 'The link is good for an hour.']],
            ],
            'invalid_value' => [
                ['mail.reset.subject' => ['sk' => str_repeat('ž', 151)]],
                ['mail.reset.intro' => ['sk' => str_repeat('ž', 401)]],
                ['mail.reset.intro' => ['sk' => "Prvý\tdruhý"]],
                // Within 400 characters, and more bytes than a line of mail may have.
                ['mail.reset.ignore' => ['sk' => str_repeat('あ', 301)]],
                ['mail.reset.greeting' => ['sk' => str_repeat('あ', 170) . ' {username}']],
                // Short as written, and over a line once each of its three names is filled.
                ['mail.reset.greeting' => ['sk' => 'Ahoj {username}, {username}, {username},']],
                ['mail.loginCode.greeting' => ['en' => 'Hello {username}, {username}, {username},']],
                ['mail.reset.greeting' => ['sk' => str_repeat('あ', 27) . ' {username} {username}']],
                // An address the panel reads backwards and the reader the right way round; a C1 control.
                ['mail.reset.intro' => ['sk' => "Otvor \u{202E}moc.live//:sptth\u{202C} a zvoľ si ho:"]],
                ['mail.reset.subject' => ['en' => "Reset\u{0090}your password"]],
            ],
            'invalid_placeholder' => [
                ['mail.reset.greeting' => ['sk' => 'Ahoj { username },']],
                ['mail.reset.intro' => ['en' => 'Open the {link below to choose one:']],
                ['mail.loginCode.subject' => ['sk' => 'Tvoj kód: {{code}}']],
            ],
            'fallback_required' => [['mail.reset.ignore' => ['en' => '']], ['mail.reset.subject' => ['en' => "\u{200B}"]]],
        ]
        as $code => $attempts
    ) {
        foreach ($attempts as $values) {
            $refused = save($admin, $values);
            same([422, $code], [$refused->status, $refused->code()], json_encode($values, JSON_UNESCAPED_UNICODE));
            same([array_key_first($values), array_key_first(reset($values))], [$refused->json()['key'] ?? null, $refused->json()['language'] ?? null], "$code names the key and the language");
        }
    }
    same($before, everyString(), 'nothing refused was kept');
    same(0, auditCount('translations.imported'), 'nor recorded');

    // A rewording that keeps the rules: saved, used by the next mail, and recorded word for word.
    $intro = 'Niekto chce k tomuto účtu nové heslo. Otvor odkaz nižšie a zvoľ si ho:';
    same(200, save($admin, ['mail.reset.intro' => ['sk' => $intro], 'common.save' => ['sk' => 'Ulož']])->status, 'a mail string and a page string');
    same(
        [
            $adminId,
            null,
            null,
            ordered([
                'languages' => ['sk'],
                'count' => 2,
                'keys' => ['common.save', 'mail.reset.intro'],
                'mail_changed' => 1,
            ]),
        ],
        lastAudit('translations.saved'),
        'the save: the page string by name only, and how many mail strings changed',
    );
    same(
        ordered(['key' => 'mail.reset.intro', 'language' => 'sk', 'old' => seeded('sk')['mail.reset.intro'], 'new' => $intro]),
        lastMailChange(),
        'the mail string with its old and its new text, in an entry of its own',
    );
    same([$adminId, 'translations.saved'], [lastAudit('translations.mail_changed')[0], lastAudit('translations.mail_changed')[3]['part_of'] ?? null], 'by the administrator, part of the save');
    sendPasswordResetMail(['id' => null, 'username' => 'Anna', 'email' => 'reworded@example.test', 'language' => 'sk'], str_repeat('cd', 32));
    check(str_starts_with(lastMailTo('reworded@example.test')['body'], "Ahoj Anna,\n\n$intro\n\n" . TEST_ISSUER . '/reset-password?token='), 'the next reset says it');

    // The log keeps a mail string whole: what it keeps is as long as the panel lets one be.
    $long = str_repeat('ž', 400);
    same(200, save($admin, ['mail.reset.intro' => ['sk' => $long]])->status, 'a paragraph of 400 characters');
    $entry = lastMailChange();
    same([$intro, $long], [$entry['old'], $entry['new']], 'a mail string of the longest the panel takes is in the log whole');
    same($long, stored('mail.reset.intro', 'sk')[0], 'and whole in the table');
    check(iamTranslationsConfig()->auditTextMax >= TRANSLATION_MAIL_VALUE_MAX, 'whatever the longest becomes, the log\'s bound is not under it');

    // The clip is only for a string that came past the panel: of any length, and not the log's to carry.
    $outside = str_repeat('š', 450);
    iamDb()
        ->prepare("UPDATE translations SET value = ? WHERE key_name = 'mail.reset.intro' AND language_code = 'sk'")
        ->execute([$outside]);
    same(200, save($admin, ['mail.reset.intro' => ['sk' => $long]])->status, 'a string written by SQL, 450 characters of it, is replaced in the panel');
    $entry = lastMailChange();
    same([400, str_repeat('š', 400), $long], [Text::length($entry['old']), $entry['old'], $entry['new']], 'the log has its first 400 characters');

    // Taken out: Slovak reads that one paragraph in English, and the log says what it was.
    same(200, save($admin, ['mail.reset.intro' => ['sk' => '']])->status, 'emptied');
    same(ordered(['key' => 'mail.reset.intro', 'language' => 'sk', 'old' => $long, 'new' => null]), lastMailChange(), 'a deleted mail string, with the whole of what it was');
    sendPasswordResetMail(['id' => null, 'username' => 'Anna', 'email' => 'reworded@example.test', 'language' => 'sk'], str_repeat('cd', 32));
    check(str_starts_with(lastMailTo('reworded@example.test')['body'], "Ahoj Anna,\n\n" . seeded('en')['mail.reset.intro'] . "\n\n"), 'that paragraph in English, the greeting still Slovak');

    // What the log is for. A sentence added at the end of the reset mail's paragraph sends the reader
    // elsewhere by a bare domain, which the address rule does not stop - and begins after the 300th
    // character, which is where the log used to stop. Put back afterwards, the table no longer has
    // it: the log must, in the entry of the change and in the entry of its undoing.
    $padding = str_repeat('Choose a new password below. ', 10) . 'Be quick. ';
    $added = 'Or go to anotoki-login.test/reset and type your current password there:';
    same(300, Text::length($padding), '300 characters of ordinary wording first');
    same(200, save($admin, ['mail.reset.intro' => ['en' => $padding . $added]])->status, 'the paragraph with a sentence added at its end is saved: the panel cannot tell it from a rewording');
    $entry = lastMailChange();
    same(['mail.reset.intro', 'en', seeded('en')['mail.reset.intro'], $padding . $added], [$entry['key'], $entry['language'], $entry['old'], $entry['new']], 'the log has the new text word for word');
    check(str_contains($entry['new'], 'anotoki-login.test') && strpos($entry['new'], 'anotoki-login.test') > 300, 'the added sentence among it, though it begins after the 300th character');
    sendPasswordResetMail(['id' => null, 'username' => 'Anna', 'email' => 'added@example.test', 'language' => 'en'], str_repeat('cd', 32));
    check(str_contains(lastMailTo('added@example.test')['body'], "\n\n$padding$added\n\n" . TEST_ISSUER . '/reset-password?token='), 'and it is what the next reset says');
    same(200, save($admin, ['mail.reset.intro' => ['en' => seeded('en')['mail.reset.intro']]])->status, 'put back');
    $entry = lastMailChange();
    same([$padding . $added, seeded('en')['mail.reset.intro']], [$entry['old'], $entry['new']], 'the entry of putting it back has the whole of what it was');
    same(0, (int) iamDb()->query("SELECT count(*) FROM translations WHERE value LIKE '%anotoki-login.test%'")->fetchColumn(), 'which by now is kept nowhere else');

    // What goes out: the longest strings the panel takes in a script of three bytes a letter, to the
    // longest username an account can have - no line of the message is over the 998 bytes a mail allows.
    same(
        200,
        save($admin, ['mail.reset.ignore' => ['sk' => str_repeat('あ', 300)], 'mail.reset.greeting' => ['sk' => str_repeat('あ', 163) . ' {username}']])->status,
        'a Japanese paragraph of 300 characters, and a greeting as long as one may be',
    );
    same(
        true,
        sendPasswordResetMail(['id' => null, 'username' => str_repeat("\u{1D11E}", 100), 'email' => 'longest@example.test', 'language' => 'sk'], str_repeat('cd', 32)),
        'a reset to a username of 100 four-byte characters',
    );
    $lines = array_map('strlen', explode("\n", (string) rawMailTo('longest@example.test')));
    same([900, 890], [max($lines), strlen(str_repeat('あ', 163) . ' ' . str_repeat("\u{1D11E}", 100))], 'the longest line of the message as the file driver wrote it, and the greeting filled');
    check(in_array(890, $lines, true) && max($lines) <= 998, 'both under 998 bytes');

    // And the longest greeting that names its reader twice: room was kept for both names.
    $twice = str_repeat('あ', 26) . ' {username} {username}';
    same(200, save($admin, ['mail.reset.greeting' => ['sk' => $twice]])->status, 'a greeting of 100 bytes with the username in it twice');
    same(
        true,
        sendPasswordResetMail(['id' => null, 'username' => str_repeat("\u{1D11E}", 100), 'email' => 'twice@example.test', 'language' => 'sk'], str_repeat('cd', 32)),
        'a reset to the same username',
    );
    $lines = array_map('strlen', explode("\n", (string) rawMailTo('twice@example.test')));
    same(
        [900, 880],
        [max($lines), strlen(str_repeat('あ', 26) . ' ' . str_repeat("\u{1D11E}", 100) . ' ' . str_repeat("\u{1D11E}", 100))],
        'the longest line is still the paragraph; the greeting, filled twice, has 880 bytes',
    );
    check(in_array(880, $lines, true) && max($lines) <= 998, 'and is in the message as the file driver wrote it, under 998 bytes');

    same(200, save($admin, ['mail.reset.ignore' => ['sk' => seeded('sk')['mail.reset.ignore']], 'mail.reset.greeting' => ['sk' => seeded('sk')['mail.reset.greeting']]])->status, 'and those two back');

    same(200, save($admin, ['mail.reset.intro' => ['sk' => seeded('sk')['mail.reset.intro']], 'common.save' => ['sk' => seeded('sk')['common.save']]])->status, 'and back');
    same(ordered(['old' => null, 'new' => seeded('sk')['mail.reset.intro']]), array_intersect_key(lastMailChange(), ['old' => 0, 'new' => 0]), 'a mail string that had none before');
});

test('a save of every mail string at its longest, in English and Slovak: the summary in one entry, every old and new text whole in an entry of its own - padding hides none', function () {
    $admin = admin();
    $adminId = $GLOBALS['adminId'];
    $pdo = iamDb();

    // The longest each mail string may be, in a letter of two bytes: its placeholders, then as many letters as fit.
    $longest = static function (string $key, string $letter): string {
        $names = MAIL_KEYS[$key];
        $tail = $names === [] ? '' : ' ' . implode(' ', array_map(static fn(string $name): string => '{' . $name . '}', $names));
        $characters = str_ends_with($key, '.subject') ? TRANSLATION_MAIL_SUBJECT_MAX : TRANSLATION_MAIL_VALUE_MAX;
        $octets =
            TRANSLATION_MAIL_LINE_OCTETS - TRANSLATION_MAIL_USERNAME_OCTETS * count(array_keys($names, 'username', true)) - TRANSLATION_MAIL_DATE_OCTETS * count(array_keys($names, 'date', true));

        return str_repeat($letter, min($characters - Text::length($tail), intdiv($octets - strlen($tail), strlen($letter)))) . $tail;
    };
    $values = [];
    $cells = [];
    foreach (array_keys(MAIL_KEYS) as $key) {
        foreach (['en' => 'é', 'sk' => 'ž'] as $code => $letter) {
            $values[$key][$code] = $longest($key, $letter);
            $cells["$key $code"] = ['old' => stored($key, $code)[0] ?? null, 'new' => $values[$key][$code]];
        }
    }
    // Kept as one entry, as the library hands it over, it would be more than an entry may hold - and cut to nothing.
    check(
        strlen((string) json_encode(['mail' => array_values($cells)], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) > AuditLog::DETAILS_LIMIT,
        'the save\'s mail strings alone are more than one entry of the log holds',
    );
    $last = (int) $pdo->query('SELECT COALESCE(max(id), 0) FROM anotoki_audit_log')->fetchColumn();

    same(200, ($saved = save($admin, $values))->status, 'every mail string, at its longest, in two languages: ' . substr($saved->body, 0, 300));
    $keys = array_keys(MAIL_KEYS);
    sort($keys, SORT_STRING);
    same(
        [$adminId, null, null, ordered(['languages' => ['en', 'sk'], 'count' => count($cells), 'keys' => $keys, 'mail_changed' => count($cells)])],
        lastAudit('translations.saved'),
        'the summary: the languages, how many, every key, how many mail strings',
    );

    $entries = $pdo->prepare("SELECT actor_id, target_id, details FROM anotoki_audit_log WHERE id > ? AND action = 'translations.mail_changed' ORDER BY id");
    $entries->execute([$last]);
    $found = [];
    foreach ($entries->fetchAll() as $row) {
        $details = json_decode((string) $row['details'], true);
        same([$adminId, 'translations.saved'], [(int) $row['actor_id'], $details['part_of'] ?? null], "{$row['target_id']} ({$details['language']}): by the administrator, part of the save");
        $found["{$row['target_id']} {$details['language']}"] = ['old' => $details['old'], 'new' => $details['new']];
    }
    ksort($found, SORT_STRING);
    ksort($cells, SORT_STRING);
    same($cells, $found, 'every mail string\'s old and new text, whole, each in an entry of its own');
    same(0, (int) $pdo->query("SELECT count(*) FROM anotoki_audit_log WHERE jsonb_typeof(details) = 'object' AND jsonb_exists(details, '_cut')")->fetchColumn(), 'and no entry was cut');

    // Put back, recorded the same way.
    $back = [];
    foreach ($cells as $cell => ['old' => $old]) {
        [$key, $code] = explode(' ', $cell);
        $back[$key][$code] = $old ?? '';
    }
    same(200, save($admin, $back)->status, 'every mail string back');
    same(seeded('sk')['mail.reset.intro'], stored('mail.reset.intro', 'sk')[0], 'as they were');
    $undone = $pdo
        ->query("SELECT details FROM anotoki_audit_log WHERE action = 'translations.mail_changed' AND target_id = 'mail.reset.intro' AND details->>'language' = 'sk' ORDER BY id DESC LIMIT 1")
        ->fetchColumn();
    $undone = (array) json_decode((string) $undone, true);
    same([$values['mail.reset.intro']['sk'], seeded('sk')['mail.reset.intro']], [$undone['old'] ?? null, $undone['new'] ?? null], 'the putting back has the whole of what it was too');
});

// ── Languages ────────────────────────────────────────────────────────────────

test('languages: added after the others and offered at once - to the pages, to discovery, to ui_locales, to an account', function () {
    $admin = admin();
    $adminId = $GLOBALS['adminId'];

    $list = $admin->get('/api/admin/languages');
    same(200, $list->status, 'the list');
    same(
        [
            ENGLISH + ['enabled' => true, 'sort_order' => 1, 'seeded' => true, 'strings' => stringsIn('en'), 'accounts' => 2],
            SLOVAK + ['enabled' => true, 'sort_order' => 2, 'seeded' => true, 'strings' => stringsIn('sk'), 'accounts' => 0],
        ],
        $list->json()['languages'],
        'the two the IAM is released in, with how many strings each has and how many accounts',
    );

    // What a new language is: last, and - with nothing said about it - offered. Its place, and what
    // only the IAM can know of it, are not the body's to say.
    $made = $admin->post('/api/admin/languages', ['code' => 'de', 'name' => "  German\x07 ", 'native_name' => 'Deutsch', 'sort_order' => 0, 'seeded' => true, 'strings' => 5, 'accounts' => 9]);
    same(201, $made->status, $made->body);
    same(
        ['language' => ['code' => 'de', 'name' => 'German', 'native_name' => 'Deutsch', 'enabled' => true, 'sort_order' => 3, 'seeded' => false, 'strings' => 0, 'accounts' => 0]],
        $made->json(),
        'the language as it was made',
    );
    same([$adminId, 'language', 'de', ordered(['name' => 'German', 'native_name' => 'Deutsch', 'enabled' => true])], lastAudit('language.created'), 'recorded, with whether it was offered');
    $regional = $admin->post('/api/admin/languages', ['code' => 'pt-br', 'name' => 'Portuguese (Brazil)', 'native_name' => str_repeat('ç', 50), 'enabled' => true]);
    same([201, true], [$regional->status, $regional->json()['language']['enabled'] ?? null], 'a code with a region, a name of 50 characters - and offered because the body says so');

    $twice = $admin->post('/api/admin/languages', ['code' => 'de', 'name' => 'Deutsch', 'native_name' => 'Deutsch']);
    same([409, 'language_exists'], [$twice->status, $twice->code()], 'a language that is there');
    foreach (
        [
            'a list' => ['de'],
            'no code' => ['name' => 'French', 'native_name' => 'Français'],
            'a code in capitals' => ['code' => 'FR', 'name' => 'French', 'native_name' => 'Français'],
            'three letters' => ['code' => 'fra', 'name' => 'French', 'native_name' => 'Français'],
            'a code that is a number' => ['code' => 12, 'name' => 'French', 'native_name' => 'Français'],
            'no name' => ['code' => 'fr', 'native_name' => 'Français'],
            'an empty name' => ['code' => 'fr', 'name' => " \x07 ", 'native_name' => 'Français'],
            'a name nobody can see' => ['code' => 'fr', 'name' => "\u{200B}", 'native_name' => 'Français'],
            'a native name nobody can see' => ['code' => 'fr', 'name' => 'French', 'native_name' => " \u{2060}\u{200D} "],
            'a name of 51 characters' => ['code' => 'fr', 'name' => str_repeat('é', 51), 'native_name' => 'Français'],
            'a name that is not text' => ['code' => 'fr', 'name' => ['French'], 'native_name' => 'Français'],
            'a name of two lines' => ['code' => 'fr', 'name' => "French\nFrench", 'native_name' => 'Français'],
            'no native name' => ['code' => 'fr', 'name' => 'French'],
            'the survey site\'s old spelling' => ['code' => 'fr', 'name' => 'French', 'nativeName' => 'Français'],
        ]
        as $what => $body
    ) {
        $refused = $admin->post('/api/admin/languages', $body);
        same([422, 'invalid_language'], [$refused->status, $refused->code()], $what);
    }
    same([415, 'unsupported_media_type'], [($r = $admin->request('POST', '/api/admin/languages'))->status, $r->code()], 'no body');
    same(4, (int) iamDb()->query('SELECT count(*) FROM languages')->fetchColumn(), 'four languages');

    // Offered at once, everywhere the offered languages are read.
    $visitor = new Browser('10.9.5.1');
    same(['en', 'sk', 'de', 'pt-br'], $visitor->get('/api/public/config')->json()['languages'], 'the pages\' config');
    same(['en', 'sk', 'de', 'pt-br'], $visitor->get('/.well-known/openid-configuration')->json()['ui_locales_supported'], 'discovery');
    same(['en', 'sk', 'de', 'pt-br'], languageCodes(), 'and the code');
    $german = $visitor->get('/api/translations/de');
    same(['de', ['en', 'sk', 'de', 'pt-br']], [$german->json()['language'], array_column($german->json()['languages'], 'code')], 'the bundle is that language');
    same($german->json()['english'], $german->json()['values'], 'which has no strings yet, so it reads in English');
    same(['code' => 'de', 'name' => 'German', 'native_name' => 'Deutsch'], $german->json()['languages'][2], 'with only what a chooser shows');

    // A code with a region: itself when it is offered, else its language when that is, else English.
    same($german->body, $visitor->get('/api/translations/de-at')->body, 'de-at is answered by German, now that German is offered');
    same('pt-br', $visitor->get('/api/translations/pt-br')->json()['language'], 'pt-br is offered, and is itself');
    same('en', $visitor->get('/api/translations/pt-pt')->json()['language'], 'pt-pt is not, and there is no "pt" to answer for it: English');
    same('en', $visitor->get('/api/translations/pt')->json()['language'], 'nor is "pt" answered by one of its regions');

    // ui_locales: the first tag that is an offered code as a whole, or by its first subtag.
    foreach (
        [
            'de' => 'de',
            'de-DE' => 'de',
            'DE-at' => 'de',
            'sk-SK' => 'sk',
            'pt-BR' => 'pt-br',
            'pt-br' => 'pt-br',
            'fr de sk' => 'de',
            'xx-YY sk-SK en' => 'sk',
            'en-GB de' => 'en',
            'pt' => null,
            'pt-PT' => null,
            'fr' => null,
            'deu' => null,
            'de_DE' => null,
            '' => null,
        ]
        as $uiLocales => $expected
    ) {
        $start = authorize(new Browser('10.9.5.2'), ['ui_locales' => $uiLocales === '' ? null : $uiLocales]);
        same($expected, queryOf($start['location'])['lang'] ?? null, "ui_locales=$uiLocales");
    }

    // An account may have it: by registering, on the account page, in the admin panel.
    $registered = (new Browser('10.9.5.3'))->post('/api/register', ['username' => 'Gretchen', 'email' => 'gretchen@example.test', 'password' => 'a long password', 'language' => 'de']);
    same(201, $registered->status, 'registered in German');
    same('de', iamDb()->query("SELECT language FROM users WHERE email = 'gretchen@example.test'")->fetchColumn(), 'and German is the account\'s language');
    same('Confirm your anotoki account', lastMailTo('gretchen@example.test')['subject'], 'its mail is in English: German has no strings yet');

    $player = new Browser('10.9.5.4');
    $player->signIn('player@example.test', 'correct horse');
    same('pt-br', $player->put('/api/account/profile', ['language' => 'PT-BR'])->json()['language'] ?? null, 'chosen on the account page');
    same('de', $player->put('/api/account/profile', ['language' => 'de'])->json()['language'] ?? null, 'and another');

    $created = $admin->post('/api/admin/users', ['username' => 'dieter', 'email' => 'dieter@example.test', 'password' => 'correct horse', 'language' => 'de']);
    same([201, 'de'], [$created->status, $created->json()['language'] ?? null], 'an account made by the admin, in German');
    same(3, array_column($admin->get('/api/admin/languages')->json()['languages'], 'accounts', 'code')['de'], 'three accounts have it now');
});

test('a new language starts hidden when the body says so: there for its strings, offered to nobody until it is switched on', function () {
    $admin = admin();
    $adminId = $GLOBALS['adminId'];
    $offered = languageCodes();
    same(['en', 'sk', 'de', 'pt-br'], $offered, 'four languages are offered');

    $made = $admin->post('/api/admin/languages', ['code' => 'fr', 'name' => 'French', 'native_name' => 'Français', 'enabled' => false]);
    same(
        [201, ['language' => ['code' => 'fr', 'name' => 'French', 'native_name' => 'Français', 'enabled' => false, 'sort_order' => 5, 'seeded' => false, 'strings' => 0, 'accounts' => 0]]],
        [$made->status, $made->json()],
        'made hidden, after the others',
    );
    same([$adminId, 'language', 'fr', ordered(['name' => 'French', 'native_name' => 'Français', 'enabled' => false])], lastAudit('language.created'), 'and the log says it was made hidden');

    // In the panel, and nowhere a reader or a site would meet it.
    same(false, array_column($admin->get('/api/admin/languages')->json()['languages'], 'enabled', 'code')['fr'] ?? null, 'the admin panel has it, switched off');
    $visitor = new Browser('10.9.5.8');
    same($offered, $visitor->get('/api/public/config')->json()['languages'], 'the pages are not offered it');
    same($offered, $visitor->get('/.well-known/openid-configuration')->json()['ui_locales_supported'], 'nor is a site told of it');
    same($offered, languageCodes(), 'nor the code');
    $bundle = $visitor->get('/api/translations/fr');
    same(['en', $offered], [$bundle->json()['language'], array_column($bundle->json()['languages'], 'code')], 'asked for by name it reads in English, and no chooser lists it');
    same('en', $visitor->get('/api/translations/fr-ca')->json()['language'], 'through a region of it too');
    same(null, queryOf(authorize(new Browser('10.9.5.8'), ['ui_locales' => 'fr'])['location'])['lang'] ?? null, 'ui_locales=fr opens the page in no language of its own');
    same('invalid_language', $admin->put('/api/admin/users/' . $GLOBALS['playerId'], ['language' => 'fr'])->code(), 'and no account can be given it');

    // What it is there for: its strings can be written before anybody reads in it.
    same(200, save($admin, ['common.save' => ['fr' => 'Enregistrer']])->status, 'a string in the hidden language');
    same('Enregistrer', stored('common.save', 'fr')[0] ?? null, 'is kept');
    same(1, array_column($admin->get('/api/admin/languages')->json()['languages'], 'strings', 'code')['fr'] ?? null, 'and counted');

    // Switched on, it is offered - with the strings that were written meanwhile.
    same(true, $admin->put('/api/admin/languages/fr', ['enabled' => true])->json()['language']['enabled'] ?? null, 'switched on');
    same([...$offered, 'fr'], $visitor->get('/api/public/config')->json()['languages'], 'now the pages offer it');
    $bundle = $visitor->get('/api/translations/fr');
    same(['fr', 'Enregistrer'], [$bundle->json()['language'], $bundle->json()['values']['common.save'] ?? null], 'and read in it');
    same($bundle->body, $visitor->get('/api/translations/fr-ca')->body, 'fr-ca too, by its language');

    // "enabled" is true or false - nothing that only reads like one, which would offer a language against its maker's word.
    foreach (['the word false' => 'false', 'the word true' => 'true', 'a zero' => 0, 'a one' => 1, 'nothing' => null, 'a list' => [false], 'an empty text' => ''] as $what => $value) {
        $refused = $admin->post('/api/admin/languages', ['code' => 'it', 'name' => 'Italian', 'native_name' => 'Italiano', 'enabled' => $value]);
        same([422, 'invalid_language'], [$refused->status, $refused->code()], "enabled as $what");
        check(str_contains((string) ($refused->json()['error'] ?? ''), '"enabled" is true or false'), "and told so ($what)");
    }
    same(0, (int) iamDb()->query("SELECT count(*) FROM languages WHERE code = 'it'")->fetchColumn(), 'no language was made of any of them');

    $gone = $admin->delete('/api/admin/languages/fr');
    same([200, ['strings' => 1, 'accounts' => 0]], [$gone->status, $gone->json()], 'French goes again, with its one string');
    same($offered, languageCodes(), 'and the four are what is offered');
});

test('the languages read through a connection of one\'s own are that database\'s, and never remembered: the import, before 006 and after', function () {
    same(['en', 'sk', 'de', 'pt-br'], languageCodes(), 'this IAM offers four');

    $legacy = makeLegacyDatabase('_legacy_languages');
    $legacy->exec("INSERT INTO users (id, role, username, email, email_confirmed, language) VALUES
        (1, 'ADMIN', 'owner', 'owner@example.test', TRUE, 'sk'),
        (2, 'USER', 'dieter', 'dieter@example.test', TRUE, 'de'),
        (3, 'USER', 'loud', 'loud@example.test', TRUE, ' SK '),
        (4, 'USER', 'odd', 'odd@example.test', TRUE, 'klingon')");
    $languagesOf = static fn(PDO $pdo): array => $pdo->query('SELECT language FROM users ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);

    // An IAM being installed from a build whose 006 has not run there yet: the two languages of before.
    $before = connectDatabase(testServer(), databaseBeforeStrings('_import_before'), false);
    same(['en', 'sk'], languageCodes($before), 'a database without the table');
    same(['en', 'sk'], [languageOrDefault('de', $before), languageOrDefault('sk', $before)], 'German is no language there');
    same(4, importLegacyAccounts($before, $legacy, 'genshin', false, TEST_ISSUER)['accounts'], 'the import runs on it');
    same(['sk', 'en', 'sk', 'en'], $languagesOf($before), 'Slovak kept, however it was written; anything else English');

    // An IAM with 006 and German on offer: the account keeps German.
    $name = throwawayDatabase(testEnvironment()['db'] . '_import_after');
    ensureDatabase(testServer(), $name);
    $after = connectDatabase(testServer(), $name, false);
    same(null, applyPendingMigrations($after)['failed'], 'every migration applies');
    same(['en', 'sk'], languageCodes($after), 'a fresh IAM offers the two it is released in');
    $after->exec("INSERT INTO languages (code, name, native_name, sort_order) VALUES ('de', 'German', 'Deutsch', 3), ('fr', 'French', 'Français', 4)");
    $after->exec("UPDATE languages SET enabled = FALSE WHERE code = 'fr'");
    same(['en', 'sk', 'de'], languageCodes($after), 'read again every time: the language added a moment ago is there, the one switched off is not');
    same(4, importLegacyAccounts($after, $legacy, 'genshin', false, TEST_ISSUER)['accounts'], 'the import runs on it');
    same(['sk', 'de', 'sk', 'en'], $languagesOf($after), 'German kept where German is offered');

    // A table with nothing on offer is answered like no table: the two of before.
    $after->exec('UPDATE languages SET enabled = FALSE');
    same(['en', 'sk'], languageCodes($after), 'nothing offered');

    same(['en', 'sk', 'de', 'pt-br'], languageCodes(), 'and none of it touched what this IAM itself offers');
});

test('with the database out of reach, discovery and the pages\' config still answer, as they did before the languages moved into it - and that answer is not remembered', function () {
    $main = testEnvironment()['db'];
    $offered = languageCodes();
    same(['en', 'sk', 'de', 'pt-br'], $offered, 'this IAM offers four languages');

    // The same server on a port where nothing listens: a connection of its own, and a refused one.
    pointAtDatabase($main, '1');
    try {
        try {
            iamDb();
            check(false, 'the database should be out of reach');
        } catch (PDOException) {
        }
        // A request begins with nothing remembered; this process has the four of a moment ago.
        same(['en', 'sk'], languageCodes(null, true), 'asked anew, the languages are the two of before');

        $discovery = (new Browser('10.9.5.7'))->get('/.well-known/openid-configuration');
        same(
            [200, ['en', 'sk'], 'public, max-age=3600'],
            [$discovery->status, $discovery->json()['ui_locales_supported'] ?? null, $discovery->header('Cache-Control')],
            'discovery answers: ' . substr($discovery->body, 0, 200),
        );
        same(TEST_ISSUER . '/api/oidc/authorize', $discovery->json()['authorization_endpoint'] ?? null, 'with what a site reads it for');

        $config = (new Browser('10.9.5.7'))->get('/api/public/config');
        same(200, $config->status, 'the pages\' config answers: ' . substr($config->body, 0, 200));
        same(
            [
                'login_enabled' => true,
                'registration_enabled' => true,
                'registration_approval' => false,
                'registration_news' => false,
                'google_enabled' => false,
                'google_client_id' => null,
                'password_min' => PASSWORD_MIN,
                'languages' => ['en', 'sk'],
            ],
            $config->json(),
            'with the switches as they are when nothing overrides them, and the two languages',
        );

        // The words: not a 500 - the pages keep the English compiled into them, and ask again.
        $bundle = (new Browser('10.9.5.7'))->get('/api/translations/sk');
        same([503, 'translations_unavailable', '60'], [$bundle->status, $bundle->code(), $bundle->header('Retry-After')], 'the bundle: ' . substr($bundle->body, 0, 200));
    } finally {
        pointAtDatabase($main);
    }

    // No reload was asked for: had the stand-in answer been remembered, it would be the answer still.
    same($offered, languageCodes(), 'back in reach, the languages are the database\'s at once');
    same($offered, (new Browser('10.9.5.7'))->get('/api/public/config')->json()['languages'] ?? null, 'and the pages are told so');

    // A connection that is handed in is not covered: the installer and the import must see their own errors.
    $own = connectDatabase(testServer(), $main, false);
    $own->beginTransaction();
    try {
        $own->exec('SELECT no_such_function()');
    } catch (PDOException) {
    }
    try {
        languageCodes($own);
        check(false, 'a failed query on a connection of one\'s own should be the caller\'s to see');
    } catch (PDOException) {
    }
    $own->rollBack();
    same($offered, languageCodes($own), 'and once it can be asked, it answers');
});

test('a language that is not offered is refused for an account - not quietly turned into English - except where a visitor sends it', function () {
    $admin = admin();
    $playerId = $GLOBALS['playerId'];

    $player = new Browser('10.9.5.5');
    $player->signIn('player@example.test', 'correct horse');
    foreach (['fr', 'xx', '', 'german'] as $language) {
        same(
            [422, 'invalid_language'],
            [$player->put('/api/account/profile', ['language' => $language])->status, $player->put('/api/account/profile', ['language' => $language])->code()],
            'the account page: ' . json_encode($language),
        );
    }

    foreach (['fr', 'xx', '', 5, ['de']] as $language) {
        $refused = $admin->post('/api/admin/users', ['username' => 'nobody', 'email' => 'nobody-yet@example.test', 'password' => 'correct horse', 'language' => $language]);
        same([422, 'invalid_language'], [$refused->status, $refused->code()], 'the admin makes an account: ' . json_encode($language));
        $refused = $admin->put("/api/admin/users/$playerId", ['language' => $language]);
        same([422, 'invalid_language'], [$refused->status, $refused->code()], 'the admin changes one: ' . json_encode($language));
    }
    same(0, (int) iamDb()->query("SELECT count(*) FROM users WHERE email = 'nobody-yet@example.test'")->fetchColumn(), 'no account was made');
    same(
        'de',
        iamDb()
            ->query("SELECT language FROM users WHERE id = $playerId")
            ->fetchColumn(),
        'none was changed',
    );

    $plain = $admin->post('/api/admin/users', ['username' => 'plain', 'email' => 'plain@example.test', 'password' => 'correct horse']);
    same([201, 'en'], [$plain->status, $plain->json()['language'] ?? null], 'an account made with no language named is English');
    same('sk', $admin->put('/api/admin/users/' . $plain->json()['id'], ['language' => ' SK '])->json()['language'] ?? null, 'and can be given one that is offered');

    // Registration takes what the page sends and never fails on it: a language that is none is English.
    $registered = (new Browser('10.9.5.6'))->post('/api/register', ['username' => 'francois', 'email' => 'francois@example.test', 'password' => 'a long password', 'language' => 'fr']);
    same(201, $registered->status, 'registered with a language the IAM does not have');
    same('en', iamDb()->query("SELECT language FROM users WHERE email = 'francois@example.test'")->fetchColumn(), 'in English');
});

test('languages: renamed, reordered, switched off - English never; a switched-off language is no longer spoken, and no site is told of it', function () {
    $admin = admin();
    $adminId = $GLOBALS['adminId'];
    $playerId = $GLOBALS['playerId'];
    $audits = auditCount('language.updated');

    // Changed: only what is sent, and only what is different is recorded.
    $renamed = $admin->put('/api/admin/languages/de', ['name' => ' German (Germany) ', 'sort_order' => 0, 'code' => 'xx', 'seeded' => true]);
    same(
        ['language' => ['code' => 'de', 'name' => 'German (Germany)', 'native_name' => 'Deutsch', 'enabled' => true, 'sort_order' => 0, 'seeded' => false, 'strings' => 0, 'accounts' => 3]],
        $renamed->json(),
        'renamed and moved first',
    );
    same([$adminId, 'language', 'de', ordered(['name' => 'German (Germany)', 'sort_order' => 0])], lastAudit('language.updated'), 'recorded, with what changed');
    same(['de', 'en', 'sk', 'pt-br'], (new Browser('10.9.6.1'))->get('/api/public/config')->json()['languages'], 'in its new place');
    same('German (Germany)', $admin->request('PUT', '/api/admin/languages/de', ['json' => new stdClass()])->json()['language']['name'] ?? null, 'nothing sent ({}), nothing changed');
    same([422, 'invalid_language'], [($r = $admin->put('/api/admin/languages/de', []))->status, $r->code()], 'an empty list is no object of changes');
    same(200, $admin->put('/api/admin/languages/de', ['name' => 'German (Germany)', 'enabled' => true])->status, 'sent as it is');
    same($audits + 1, auditCount('language.updated'), 'and neither of those was recorded');
    foreach (
        [
            'enabled as text' => ['enabled' => 'false'],
            'enabled as a number' => ['enabled' => 0],
            'half an order' => ['sort_order' => 1.5],
            'an order as text' => ['sort_order' => '3'],
            'an order below 0' => ['sort_order' => -1],
            'an order above 1000' => ['sort_order' => 1001],
            'an empty name' => ['name' => ''],
            'a name nobody can see' => ['name' => "\u{200B}"],
            'a long native name' => ['native_name' => str_repeat('x', 51)],
            'a list' => ['name'],
        ]
        as $what => $body
    ) {
        $refused = $admin->put('/api/admin/languages/de', $body);
        same([422, 'invalid_language'], [$refused->status, $refused->code()], $what);
    }
    same(1000, $admin->put('/api/admin/languages/de', ['sort_order' => 1000])->json()['language']['sort_order'] ?? null, 'an order of 1000');
    same(404, $admin->put('/api/admin/languages/fr', ['name' => 'French'])->status, 'a language there is not');

    $english = $admin->put('/api/admin/languages/en', ['enabled' => false, 'name' => 'Changed']);
    same([409, 'fallback_language'], [$english->status, $english->code()], 'English cannot be switched off');
    same('English', iamDb()->query("SELECT name FROM languages WHERE code = 'en'")->fetchColumn(), 'and nothing else of that request was done');
    same(5, $admin->put('/api/admin/languages/en', ['enabled' => true, 'sort_order' => 5])->json()['language']['sort_order'] ?? null, 'its place is anybody\'s to choose');
    $admin->put('/api/admin/languages/en', ['sort_order' => 1]);

    // While German is offered, an application is told the account speaks it.
    same(['de', 'de'], localeOf('dieter@example.test', '10.9.6.2'), 'the locale claim: in the access token and from userinfo');

    // Switched off: no longer offered anywhere - and the accounts keep it for when it is back.
    $hidden = $admin->put('/api/admin/languages/de', ['enabled' => false]);
    same([200, false, 3], [$hidden->status, $hidden->json()['language']['enabled'] ?? null, $hidden->json()['language']['accounts'] ?? null], 'German is switched off');
    same(['enabled' => false], lastAudit('language.updated')[3], 'recorded');
    same(['en', 'sk', 'pt-br'], (new Browser('10.9.6.1'))->get('/api/public/config')->json()['languages'], 'the pages no longer offer it');
    same(['en', 'sk', 'pt-br'], (new Browser('10.9.6.1'))->get('/.well-known/openid-configuration')->json()['ui_locales_supported'], 'nor does discovery');
    same(null, queryOf(authorize(new Browser('10.9.6.1'), ['ui_locales' => 'de'])['location'])['lang'] ?? null, 'ui_locales=de opens the page in no language of its own');
    same('en', (new Browser('10.9.6.1'))->get('/api/translations/de')->json()['language'], 'asked for by name it reads in English');
    same('en', (new Browser('10.9.6.1'))->get('/api/translations/de-at')->json()['language'], 'and so does a region of it: a language that is off answers for nobody');
    same(['en', 'sk', 'pt-br', 'de'], array_column($admin->get('/api/admin/languages')->json()['languages'], 'code'), 'the admin panel still sees it');
    same('de', iamDb()->query("SELECT language FROM users WHERE email = 'dieter@example.test'")->fetchColumn(), 'the account keeps its language');
    same(['en', 'en'], localeOf('dieter@example.test', '10.9.6.3'), 'but the locale claim is English while it is off');

    $player = new Browser('10.9.6.4');
    same('de', $player->signIn('player@example.test', 'correct horse')->json()['user']['language'] ?? null, 'the account page still shows the stored code');
    same('invalid_language', $player->put('/api/account/profile', ['language' => 'de'])->code(), 'which can no longer be chosen');
    same('invalid_language', $admin->put('/api/admin/users/' . $GLOBALS['adminId'], ['language' => 'de'])->code(), 'nor given to an account that does not have it');
    $kept = $admin->put("/api/admin/users/$playerId", ['language' => 'de', 'username' => 'playerRenamed']);
    same(
        [200, 'de', 'playerRenamed'],
        [$kept->status, $kept->json()['language'] ?? null, $kept->json()['username'] ?? null],
        'an account that has it can still be edited: its own language is no change',
    );
    same(
        201,
        (new Browser('10.9.6.5'))->post('/api/register', ['username' => 'spaet', 'email' => 'spaet@example.test', 'password' => 'a long password', 'language' => 'de'])->status,
        'a registration in it',
    );
    same('en', iamDb()->query("SELECT language FROM users WHERE email = 'spaet@example.test'")->fetchColumn(), 'is an English account');

    // A mail in a language that is switched off goes out in English; back on, in the language again.
    same(200, $admin->put('/api/admin/languages/sk', ['enabled' => false])->status, 'Slovak too can be switched off');
    same(ordered(array_intersect_key(seeded('en'), MAIL_KEYS)), ordered(mailWords('sk')), 'the words of a mail in a language that is off are the English ones, whoever asks');
    sendPasswordResetMail(['id' => null, 'username' => 'Anna', 'email' => 'off@example.test', 'language' => 'sk'], str_repeat('cd', 32));
    same('Set a new anotoki password', lastMailTo('off@example.test')['subject'], 'a Slovak account\'s mail while Slovak is off');
    same(200, $admin->put('/api/admin/languages/sk', ['enabled' => true])->status, 'and on again');
    sendPasswordResetMail(['id' => null, 'username' => 'Anna', 'email' => 'off@example.test', 'language' => 'sk'], str_repeat('cd', 32));
    same('Nastavenie nového hesla anotoki', lastMailTo('off@example.test')['subject'], 'in Slovak again');

    same(true, $admin->put('/api/admin/languages/de', ['enabled' => true])->json()['language']['enabled'] ?? null, 'German back on');
    same(['de', 'de'], localeOf('dieter@example.test', '10.9.6.6'), 'and the locale claim is German again');
});

// ── A language as a file ─────────────────────────────────────────────────────

test('a language out as a file and back in: its own strings only; unknown keys refused, empty ones passed over', function () {
    $admin = admin();
    $adminId = $GLOBALS['adminId'];

    $export = $admin->get('/api/admin/translations/sk/export');
    same(200, $export->status, 'the export');
    same(seeded('sk'), $export->json(), 'Slovak as it is: every string, the mail ones and the library\'s among them, flat');
    same(
        [TranslationFile::text(seeded('sk')), 'attachment; filename="translations-sk.json"', 'no-store'],
        [$export->body, $export->header('Content-Disposition'), $export->header('Cache-Control')],
        'a download in the file\'s format, kept nowhere on the way',
    );
    same("{}\n", $admin->get('/api/admin/translations/pt-br/export')->body, 'a language with no strings: an object, not a list');
    same(404, $admin->get('/api/admin/translations/fr/export')->status, 'a language there is not');

    // Into German: the empty ones are keys nobody translated yet.
    $in = $admin->put('/api/admin/translations/de/import', [
        'common.save' => " Speichern\x07",
        'common.close' => '',
        'common.cancel' => "  \t ",
        'login.continueTo' => 'Anmelden und weiter zu {app}',
        'mail.reset.greeting' => 'Hallo {username},',
    ]);
    same(200, $in->status, substr($in->body, 0, 300));
    same(['languages', 'keys'], array_keys($in->json()), 'answered with the grid as it is now');
    same(
        ['common.save' => 'Speichern', 'login.continueTo' => 'Anmelden und weiter zu {app}', 'mail.reset.greeting' => 'Hallo {username},'],
        $admin->get('/api/admin/translations/de/export')->json(),
        'what German has now',
    );
    same(['Speichern', $adminId], array_slice(stored('common.save', 'de'), 0, 2), 'by who imported it');
    same(
        [
            $adminId,
            'language',
            'de',
            ordered([
                'languages' => ['de'],
                'count' => 3,
                'keys' => ['common.save', 'login.continueTo', 'mail.reset.greeting'],
                'mail_changed' => 1,
            ]),
        ],
        lastAudit('translations.imported'),
        'recorded',
    );
    same(ordered(['key' => 'mail.reset.greeting', 'language' => 'de', 'old' => null, 'new' => 'Hallo {username},']), lastMailChange(), 'and the mail string with its text, in an entry of its own');

    // A German account's reset: greeted in German, the rest in English - string by string.
    sendPasswordResetMail(['id' => null, 'username' => 'Dieter', 'email' => 'dieter-mail@example.test', 'language' => 'de'], str_repeat('cd', 32));
    check(str_starts_with(lastMailTo('dieter-mail@example.test')['body'], "Hallo Dieter,\n\nSomebody asked to set a new password"), 'a language with one mail string of its own');
    same('Speichern', (new Browser('10.9.7.1'))->get('/api/translations/de')->json()['values']['common.save'], 'and its pages say it');

    // An empty string deletes nothing - not in English either, where a save would refuse it.
    $before = everyString();
    $audits = auditCount('translations.imported');
    same(200, $admin->put('/api/admin/translations/de/import', ['common.save' => '', 'login.continueTo' => 'Anmelden und weiter zu {app}'])->status, 'an empty string among them');
    same(200, $admin->put('/api/admin/translations/en/import', ['common.save' => ' ', 'common.close' => 'Close'])->status, 'into English');
    // A cell of characters of no width is an empty one: passed over like it, not written as a string and not a deletion.
    same(
        200,
        $admin->put('/api/admin/translations/de/import', ['common.save' => "\u{200B}", 'mail.reset.greeting' => " \u{2060}\u{FEFF} ", 'login.continueTo' => "\u{00AD}"])->status,
        'cells nobody can see, into German',
    );
    same(200, $admin->put('/api/admin/translations/en/import', ['common.save' => "\u{200B}", 'mail.reset.subject' => "\u{200B}"])->status, 'and into English, where a save would refuse them');
    same(200, $admin->request('PUT', '/api/admin/translations/de/import', ['json' => new stdClass()])->status, 'an empty file');
    same(200, $admin->put('/api/admin/translations/sk/import', $export->json())->status, 'what came out goes back in');
    same($before, everyString(), 'what was there stays, as it was and by whom');
    same($audits, auditCount('translations.imported'), 'and an import that changes nothing is not recorded');

    $unknown = $admin->put('/api/admin/translations/de/import', ['common.save' => 'Sichern', 'no.such' => 'x', 'nor.this' => '', 'common.close' => 'Schließen']);
    same([422, 'unknown_key', ['no.such', 'nor.this']], [$unknown->status, $unknown->code(), $unknown->json()['keys'] ?? null], 'every key the IAM does not have, empty or not');

    foreach (
        [
            'unknown_placeholder' => ['de', ['common.save' => 'Sichern', 'common.passwordHint' => 'Mindestens {anzahl} Zeichen.']],
            'invalid_placeholder' => ['de', ['common.save' => 'Sichern', 'common.passwordHint' => 'Mindestens { count } Zeichen.']],
            'placeholder_changed' => ['en', ['common.passwordHint' => 'A few characters.']],
            'placeholder_missing' => ['de', ['login.continueTo' => 'Anmelden und weiter']],
            'address_in_mail' => ['de', ['mail.reset.intro' => 'Bitte auf www.evil.test ein neues wählen:']],
            'invalid_value' => ['de', ['common.save' => ['de' => 'Sichern']]],
        ]
        as $code => [$language, $body]
    ) {
        $refused = $admin->put("/api/admin/translations/$language/import", $body);
        same([422, $code], [$refused->status, $refused->code()], $refused->body);
    }
    same(
        [422, 'unknown_key'],
        [$admin->put('/api/admin/translations/de/import', ['Sichern', 'Schließen'])->status, $admin->put('/api/admin/translations/de/import', ['Sichern', 'Schließen'])->code()],
        'a list is not a language\'s strings: its keys are numbers, which no key is (the library since 0.4.0)',
    );
    same([415, 'unsupported_media_type'], [($r = $admin->request('PUT', '/api/admin/translations/de/import'))->status, $r->code()], 'nor is no body');
    same(404, $admin->put('/api/admin/translations/fr/import', ['common.save' => 'Enregistrer'])->status, 'a language there is not');
    same($before, everyString(), 'nothing refused was kept');
});

test('the command line: --export and --import on the same strings, by nobody and recorded so; --status reads the frontend, then the mails\' keys', function () {
    $dir = sources(['keep' => '']);
    $keys = IAM_PAGE_KEYS + IAM_MAIL_KEYS + count(LibraryWords::keys());

    [$code, $out] = cli('--export', 'de', "$dir/de.json");
    same(0, $code, $out);
    same(
        TranslationFile::text(['common.save' => 'Speichern', 'login.continueTo' => 'Anmelden und weiter zu {app}', 'mail.reset.greeting' => 'Hallo {username},']),
        file_get_contents("$dir/de.json"),
        'German, to a file',
    );
    [$code, $out] = cli('--export', 'sk');
    same([0, seeded('sk')], [$code, json_decode($out, true)], 'Slovak, to the screen');
    [$code, $out] = cli('--export', 'pt-br');
    same([0, '{}'], [$code, trim($out)], 'a language with no strings');
    same(1, cli('--export', 'fr')[0], 'a language there is not');
    same(1, cli('--export', 'EN')[0], 'a code that is not one');
    same([0, '{}'], [cli('--export=pt-br')[0], trim(cli('--export=pt-br')[1])], '--export=CODE too');

    $before = everyString();
    TranslationFile::write("$dir/de.json", [
        'common.save' => 'Sichern',
        'common.close' => 'Schließen',
        'common.cancel' => '',
        'login.continueTo' => 'Anmelden und weiter zu {app}',
        'mail.reset.greeting' => 'Guten Tag {username},',
    ]);
    [$code, $out] = cli('--import', 'de', "$dir/de.json");
    same(0, $code, $out);
    check(preg_match('/5 strings in .*: 3 changed/', $out) === 1, $out);
    same(['Sichern', null], array_slice(stored('common.save', 'de'), 0, 2), 'written by nobody, as a migration\'s are');
    same(['Schließen', null], array_slice(stored('common.close', 'de'), 0, 2), 'a new one too');
    same($before['login.continueTo de'], everyString()['login.continueTo de'], 'a string as it was is still whose it was');
    same(
        [
            null,
            'language',
            'de',
            ordered([
                'languages' => ['de'],
                'count' => 3,
                'keys' => ['common.close', 'common.save', 'mail.reset.greeting'],
                'mail_changed' => 1,
                'via' => 'command line',
            ]),
        ],
        lastAudit('translations.imported'),
        'recorded with no actor',
    );
    same(
        ordered(['key' => 'mail.reset.greeting', 'language' => 'de', 'old' => 'Hallo {username},', 'new' => 'Guten Tag {username},']),
        lastMailChange(),
        'and the mail string with its old and new text, in an entry of its own',
    );
    same(
        [null, 'translations.imported', 'command line'],
        [lastAudit('translations.mail_changed')[0], lastAudit('translations.mail_changed')[3]['part_of'] ?? null, lastAudit('translations.mail_changed')[3]['via'] ?? null],
        'by nobody, from the command line too',
    );

    // Checked as a save is, and all of it or none of it.
    $before = everyString();
    $audits = auditCount('translations.imported');
    foreach (
        [
            'unknown_placeholder' => ['common.save' => 'Speichern', 'common.passwordHint' => 'Mindestens {anzahl} Zeichen.'],
            'invalid_placeholder' => ['common.save' => 'Speichern', 'mail.reset.greeting' => 'Guten Tag { username },'],
            'address_in_mail' => ['common.save' => 'Speichern', 'mail.reset.intro' => 'Bitte auf https://evil.test ein neues wählen:'],
            'invalid_value' => ['common.save' => 'Speichern', 'mail.reset.subject' => "Reset\r\nBcc: x@evil.test"],
        ]
        as $refusal => $values
    ) {
        TranslationFile::write("$dir/bad.json", $values);
        [$code, $out] = cli('--import', 'de', "$dir/bad.json");
        same(1, $code, "$refusal is refused");
        check(str_contains($out, "Refused ($refusal)"), $out);
    }
    TranslationFile::write("$dir/unknown.json", ['common.save' => 'Speichern', 'no.such' => 'x', 'nor.this' => 'y']);
    [$code, $out] = cli('--import', 'de', "$dir/unknown.json");
    same(1, $code, 'unknown keys are refused');
    check(str_contains($out, 'Refused (unknown_key)') && preg_match('/^\s+no\.such$/m', $out) === 1 && preg_match('/^\s+nor\.this$/m', $out) === 1, 'and named, one a line: ' . $out);
    file_put_contents("$dir/list.json", '["a"]');
    same(1, cli('--import', 'de', "$dir/list.json")[0], 'a list');
    same(1, cli('--import', 'de', "$dir/nothing.json")[0], 'a file that is not there');
    same(1, cli('--import', 'fr', "$dir/de.json")[0], 'a language that is not there');
    same(1, cli('--import', 'de')[0], 'no file named');
    same(1, cli('--nonsense')[0], 'a command there is not');
    same($before, everyString(), 'nothing refused was kept');
    same($audits, auditCount('translations.imported'), 'nor recorded');
    same(0, cli('--help')[0], '--help');

    // --status: the frontend's keys against this database's, how much of each language there is, and the mails' keys.
    [$code, $out] = cli('--status');
    check(preg_match("/the database has\s+$keys keys\n/", $out) === 1, $out);
    check(preg_match('/the frontend uses\s+' . IAM_PAGE_KEYS . ' of them\n/', $out) === 1, $out);
    check(preg_match("/\n\s+sk\s+Slovak\s+$keys \/ $keys\n/", $out) === 1, 'how much of each language there is: ' . $out);
    check(preg_match("/\n\s+de\s+German \(Germany\)\s+4 \/ $keys\n/", $out) === 1, 'German: ' . $out);
    check(preg_match('/the code has\s+52 mail keys \(MAIL_KEYS\)\n\s+the database has\s+52 of them\n/', $out) === 1, 'the mails\' keys: ' . $out);
    same([0, 2, 0], [$code, substr_count("$out\n", "  Clean.\n"), substr_count($out, 'Not clean.')], 'clean, both: ' . $out);

    // A key with no English string, and a mail string the database has lost: neither is clean.
    iamDb()->exec("INSERT INTO translation_keys (name) VALUES ('common.noEnglish')");
    iamDb()->exec("DELETE FROM translations WHERE key_name = 'mail.reset.ignore' AND language_code = 'en'");
    [$code, $out] = cli('--status');
    same(1, $code, $out);
    check(preg_match('/No English string.*\n\s+common\.noEnglish\n\s+mail\.reset\.ignore\n/s', $out) === 1, $out);
    iamDb()->exec("DELETE FROM translation_keys WHERE name = 'common.noEnglish'");
    iamDb()
        ->prepare("INSERT INTO translations (key_name, language_code, value) VALUES ('mail.reset.ignore', 'en', ?)")
        ->execute([seeded('en')['mail.reset.ignore']]);

    // A mail key the database does not have: its mail goes out in the English kept in the code - not
    // clean; and one under a name the code does not know is used by no mail. (Renamed and back: its
    // strings follow it both ways, as they were.)
    $before = everyString();
    iamDb()->exec("UPDATE translation_keys SET name = 'mail.old.leftOver' WHERE name = 'mail.reset.ignore'");
    try {
        [$code, $out] = cli('--status');
        same(1, $code, $out);
        check(preg_match('/the code has\s+52 mail keys \(MAIL_KEYS\)\n\s+the database has\s+51 of them\n/', $out) === 1, 'counted: ' . $out);
        check(preg_match('/A mail\'s string the database does not have.*\n\s+mail\.reset\.ignore\n/', $out) === 1, 'named: ' . $out);
        check(preg_match('/used by no mail.*\n\s+mail\.old\.leftOver\n/', $out) === 1, 'and the one the code does not know: ' . $out);
    } finally {
        iamDb()->exec("UPDATE translation_keys SET name = 'mail.reset.ignore' WHERE name = 'mail.old.leftOver'");
    }
    same($before, everyString(), 'and back as it was');
});

// ── Deleting a language ──────────────────────────────────────────────────────

test('deleting a language: never English, never one the IAM is released in; its strings go with it and its accounts go back to English', function () {
    $admin = admin();
    $adminId = $GLOBALS['adminId'];

    $english = $admin->delete('/api/admin/languages/en');
    same([409, 'fallback_language'], [$english->status, $english->code()], 'English');
    $slovak = $admin->delete('/api/admin/languages/sk');
    same([409, 'seeded_language'], [$slovak->status, $slovak->code()], 'Slovak: the next migration with strings would fail without it');
    same(404, $admin->delete('/api/admin/languages/fr')->status, 'a language there is not');
    same(2, (int) iamDb()->query("SELECT count(*) FROM languages WHERE code IN ('en', 'sk')")->fetchColumn(), 'both are still there');

    $accounts = iamDb()->query("SELECT email FROM users WHERE language = 'de' ORDER BY email")->fetchAll(PDO::FETCH_COLUMN);
    same(['dieter@example.test', 'gretchen@example.test', 'player@example.test'], $accounts, 'three accounts are in German');
    $strings = stringsIn('de');
    check($strings > 0, 'and German has strings');

    $gone = $admin->delete('/api/admin/languages/de');
    same([200, ['strings' => $strings, 'accounts' => 3]], [$gone->status, $gone->json()], 'deleted: the answer says how many strings went with it, and how many accounts went back to English');
    same([$adminId, 'language', 'de', ordered(['name' => 'German (Germany)', 'accounts' => 3, 'strings' => $strings])], lastAudit('language.deleted'), 'recorded with those numbers');
    same(
        ['en', 'en', 'en'],
        iamDb()->query("SELECT language FROM users WHERE email IN ('dieter@example.test', 'gretchen@example.test', 'player@example.test')")->fetchAll(PDO::FETCH_COLUMN),
        'the accounts are English now',
    );
    same(0, stringsIn('de'), 'its strings went with it');
    same(IAM_PAGE_KEYS + IAM_MAIL_KEYS + count(LibraryWords::keys()), (int) iamDb()->query('SELECT count(*) FROM translation_keys')->fetchColumn(), 'the keys are the code\'s, and stay');
    same(404, $admin->delete('/api/admin/languages/de')->status, 'it is gone');

    same(['en', 'sk', 'pt-br'], (new Browser('10.9.8.1'))->get('/api/public/config')->json()['languages'], 'no longer offered');
    same('en', (new Browser('10.9.8.1'))->get('/api/translations/de')->json()['language'], 'and asked for by name it reads in English');
    same(['en', 'en'], localeOf('dieter@example.test', '10.9.8.2'), 'the locale claim is English');
    same('invalid_language', $admin->put('/api/admin/users/' . $GLOBALS['playerId'], ['language' => 'de'])->code(), 'and no account can be given it');

    $nobodys = $admin->delete('/api/admin/languages/pt-br');
    same([200, ['strings' => 0, 'accounts' => 0]], [$nobodys->status, $nobodys->json()], 'a language nobody has');
    same(['en', 'sk'], array_column($admin->get('/api/admin/translations')->json()['languages'], 'code'), 'the two it started with');
});

finishTests();
