<?php

/**
 * The installer on a development machine, and the IAM before it has run.
 *
 * Starts from an empty configuration folder - an IAM nobody has set up - and
 * checks that the API says so and nothing more; then drives setup.php the way
 * a browser on this computer would: the nonce instead of a token, the "Test
 * connection" answers, a save that writes every file and builds the database,
 * and a second run that shows what is saved but never a password or the key,
 * keeps a blank password, and leaves the installer where it is.
 */

declare(strict_types=1);

require __DIR__ . '/lib.php';
require __DIR__ . '/setup_lib.php';

bootTests(configured: false);

$server = testServer();
$database = throwawayDatabase(testEnvironment()['db']);

// An old genshin accounts database with an admin and a player, to bring over.
$legacy = makeLegacyDatabase();
$legacyName = testEnvironment()['db'] . '_legacy';
$legacy->prepare("INSERT INTO users (id, role, username, email, email_confirmed, password) VALUES
    (3, 'ADMIN', 'owner', 'owner@example.test', TRUE, ?), (8, 'USER', 'player', 'player@example.test', TRUE, ?)")
    ->execute([password_hash('owner password', PASSWORD_DEFAULT), password_hash('player password', PASSWORD_DEFAULT)]);

/** The form as a developer fills it in, over the prefilled values. */
function localForm(string $nonce, array $overrides = []): array
{
    $server = testServer();

    return $overrides + [
        'nonce' => $nonce,
        'public_url' => 'http://localhost:4300',
        'db_host' => (string) $server['host'],
        'db_port' => (string) $server['port'],
        'db_user' => (string) $server['username'],
        'db_pass' => (string) $server['password'],
        'db_name' => $GLOBALS['database'],
        'legacy' => $GLOBALS['legacyName'],
        'accounts' => 'import',
        'mail_noreply' => 'noreply@localhost',
        'mail_support' => 'support@localhost',
        'mail_from_name' => 'anotoki',
        'google_client_id' => '',
    ];
}

// ─────────────────────────────────────────────────────────────────────────────

test('with no configuration the API answers not_set_up to everything, and points at the installer', function () {
    $browser = new Browser();

    foreach (['/api/public/config', '/.well-known/openid-configuration', '/.well-known/jwks.json', '/api/account', '/api/translations/en', '/api/admin/migrations'] as $path) {
        $answer = $browser->get($path);
        same(503, $answer->status, "GET {$path}");
        same('not_set_up', $answer->json()['code'] ?? null, "GET {$path} code");
        // The family's answer (anotoki/lib's gate): never installed, so the installer is named.
        same([false, '/setup.php'], [$answer->json()['installed'] ?? null, $answer->json()['setup'] ?? null], "GET {$path} points at the installer");
        check(is_string($answer->json()['message'] ?? null), "GET {$path} says so in a sentence");
        same('no-store', $answer->header('Cache-Control'), "GET {$path} is not kept");
    }

    same(503, $browser->post('/api/login/password', ['email' => 'a@b.test', 'password' => 'whatever'])->status, 'a sign-in');
    same(503, $browser->post('/api/admin/migrations/apply')->status, 'an Apply');
    same(503, $browser->request('OPTIONS', '/api/oidc/token', ['headers' => ['Origin' => 'http://localhost:4200']])->status, 'a preflight');
    check(!str_contains($browser->get('/api/public/config')->body, 'local.php'), 'the answer names no file');
    same(4, count(iamSetUpProblems()), 'four files missing, Google optional');

    // The status path is the one way through: how the pages learn what to show.
    $status = $browser->get('/api/site-status', ['headers' => ['Origin' => 'http://localhost:4200']]);
    same([200, ['state' => 'not_set_up', 'installed' => false]], [$status->status, $status->json()], 'the status says so itself');
    same(['no-store', ''], [$status->header('Cache-Control'), $status->header('Access-Control-Allow-Origin')], 'not kept, and in no CORS list');
});

test('the development setup is this computer, straight: anything else is a real host', function () {
    check(setupIsLocal(LOCAL_SERVER), '127.0.0.1 to localhost');
    check(setupIsLocal(['REMOTE_ADDR' => '::1', 'HTTP_HOST' => '[::1]:8100']), '::1 to [::1]');
    check(setupIsLocal(['REMOTE_ADDR' => '127.0.0.1', 'HTTP_HOST' => '127.0.0.1:4300']), 'through the Angular dev server');
    check(!setupIsLocal(['REMOTE_ADDR' => '203.0.113.9', 'HTTP_HOST' => 'localhost']), 'Host: localhost from elsewhere');
    check(!setupIsLocal(['REMOTE_ADDR' => '127.0.0.1', 'HTTP_HOST' => 'iam.example.test']), 'a name rebound to 127.0.0.1');
    check(!setupIsLocal(['REMOTE_ADDR' => '127.0.0.1', 'HTTP_HOST' => 'localhost', 'HTTP_X_FORWARDED_FOR' => '203.0.113.9']), 'a proxy on the server');
    check(!setupIsLocal(['REMOTE_ADDR' => '127.0.0.1', 'HTTP_HOST' => 'localhost', 'HTTP_FORWARDED' => 'for=127.0.0.1']), 'any forwarding header');
    check(!setupIsLocal(['REMOTE_ADDR' => '127.0.0.1']), 'no Host at all');
});

test('on this computer the form is prefilled with development values and asks for no token', function () {
    $page = setupRequest('GET', LOCAL_SERVER);
    same(200, $page['status'], 'the form');
    check(nonceFrom($page) !== '', 'a nonce');
    check(!str_contains($page['body'], 'name="token"'), 'no setup token');
    check(!is_file(IAM_CONFIG_DIR . '/setup.local.php'), 'and no token file');
    foreach (['http://localhost:4300', '127.0.0.1', '5432', 'postgres', 'anotoki_iam', 'noreply@localhost', 'admin@localhost'] as $value) {
        check(str_contains($page['body'], 'value="' . $value . '"'), "prefilled with {$value}");
    }
    check(!str_contains($page['body'], 'value="https://'), 'no production address as a value');
    check(str_contains($page['body'], 'src="setup.php?asset=js"') && !preg_match('/<script(?![^>]*\bsrc=)[^>]*>/i', $page['body']), 'the script is a file, nothing inline');
    same(200, setupRequest('GET', LOCAL_SERVER, query: ['asset' => 'js'])['status'], 'which it serves');
    // Under no-referrer a browser's form post says `Origin: null`, which the check below refuses.
    same('same-origin', $page['headers']['Referrer-Policy'], 'a referrer policy under which a form post names its origin');
});

test('a post without the nonce, with a wrong one, or from another page is refused', function () {
    $nonce = nonceFrom(setupRequest('GET', LOCAL_SERVER));

    same(403, setupRequest('POST', LOCAL_SERVER, localForm(''))['status'], 'no nonce');
    same(403, setupRequest('POST', LOCAL_SERVER, localForm(str_repeat('0', 64)))['status'], 'a wrong nonce');
    same(403, setupRequest('POST', LOCAL_SERVER + ['HTTP_ORIGIN' => 'http://localhost:3000'], localForm($nonce))['status'], 'another port on this computer');
    same(403, setupRequest('POST', LOCAL_SERVER + ['HTTP_ORIGIN' => 'null'], localForm($nonce))['status'], 'a sandboxed page, which has no origin to name');
    same(403, setupRequest('POST', LOCAL_SERVER + ['HTTP_SEC_FETCH_SITE' => 'cross-site'], localForm($nonce))['status'], 'a cross-site request');
    same(403, setupRequest('POST', LOCAL_SERVER, json: ['action' => 'test_database'] + localForm(''))['status'], 'a test without the nonce');
    check(!is_file(IAM_CONFIG_DIR . '/database.local.php'), 'and nothing was written');
});

test('"Test connection" answers in place: a login that works, one that does not, a database not there yet', function () use ($server) {
    $nonce = nonceFrom(setupRequest('GET', LOCAL_SERVER));

    // The family's words (every anotoki setup page answers in them).
    $mayCreate = (bool) connectDatabase(testServer(), 'postgres', false)->query('SELECT rolcreatedb OR rolsuper FROM pg_roles WHERE rolname = current_user')->fetchColumn();
    $missing = setupJsonAnswer(setupRequest('POST', LOCAL_SERVER + ['HTTP_ORIGIN' => 'http://localhost:8100'], json: ['action' => 'test_database'] + localForm($nonce)));
    if ($mayCreate) {
        same('good', $missing['tone'], 'the server answers and Save will make the database: ' . $missing['message']);
        same("Connected to the server. The database \"{$GLOBALS['database']}\" does not exist yet - Save creates it.", $missing['message'], 'in the family\'s words');
    } else {
        same('bad', $missing['tone'], 'the server answers, but this user cannot make the database: ' . $missing['message']);
        same("Connected to the server, but there is no database \"{$GLOBALS['database']}\" and this user may not create one. Make it in the hosting panel first.", $missing['message'], 'in the family\'s words');
    }

    $wrong = setupJsonAnswer(setupRequest('POST', LOCAL_SERVER, json: ['action' => 'test_database'] + localForm($nonce, ['db_pass' => 'certainly not the password', 'db_user' => 'iam_test_nobody'])));
    same('bad', $wrong['tone'], 'a wrong login: ' . $wrong['message']);
    check(str_starts_with($wrong['message'], 'Cannot connect: '), 'said as the family says it: ' . $wrong['message']);

    $empty = throwawayDatabase($GLOBALS['database'] . '_empty');
    ensureDatabase(testServer(), $empty);
    $emptyAnswer = setupJsonAnswer(setupRequest('POST', LOCAL_SERVER, json: ['action' => 'test_database'] + localForm($nonce, ['db_name' => $empty])));
    same(['ok' => true, 'tone' => 'good', 'message' => "Connected to the database \"{$empty}\": it is empty - Save makes the tables."], $emptyAnswer, 'an empty database');

    // Every file the engine applies: the IAM's, then the library's translations set.
    $files = migrationFiles();
    $partial = connectDatabase(testServer(), $empty, false);
    ensureMigrationsTable($partial);
    applyMigration($partial, 'iam', $files[0]);
    $partialAnswer = setupJsonAnswer(setupRequest('POST', LOCAL_SERVER, json: ['action' => 'test_database'] + localForm($nonce, ['db_name' => $empty])));
    same(['ok' => true, 'tone' => 'good', 'message' => "Connected to the database \"{$empty}\": " . (count($files) - 1) . ' update(s) to apply - Save applies them.'], $partialAnswer, 'a database with updates to apply');
    $partial = null;

    $old = setupJsonAnswer(setupRequest('POST', LOCAL_SERVER, json: ['action' => 'test_legacy'] + localForm($nonce)));
    same('good', $old['tone'], 'the old accounts: ' . $old['message']);
    check(str_contains($old['message'], '2 accounts') && str_contains($old['message'], '1 of them admin'), 'counted: ' . $old['message']);

    $unsafe = setupRequest('POST', LOCAL_SERVER, json: ['action' => 'test_database'] + localForm($nonce, ['db_name' => 'anotoki_iam;host=elsewhere']));
    same(422, $unsafe['status'], 'a database name that would reach into the DSN');
    check(!is_file(IAM_CONFIG_DIR . '/database.local.php'), 'testing writes nothing');

    // Last, as it depends on this computer's password: one so short that it
    // turns up inside other words fails here, and nothing after it would run.
    if ((string) $server['password'] !== '') {
        foreach ([$missing, $wrong, $emptyAnswer, $partialAnswer, $old] as $answer) {
            check(!str_contains($answer['message'], (string) $server['password']), 'no answer repeats the password');
        }
    }
});

test('"Test connection" for a login that may not create a database, and for a database the login may not open', function () {
    $admin = connectDatabase(testServer(), 'postgres', false);
    if (!(bool) $admin->query('SELECT rolcreaterole OR rolsuper FROM pg_roles WHERE rolname = current_user')->fetchColumn()) {
        echo "      (skipped: the test login may not make the throwaway login these answers need)\n";
        return;
    }

    // A throwaway login of this run's own, with no right to make databases,
    // and a throwaway database nobody but its owner may open. Both go at the
    // end of the run: the database with the others, the login just after.
    $login = testEnvironment()['db'] . '_nocreate';
    $password = bin2hex(random_bytes(16));
    $admin->exec('CREATE ROLE "' . $login . '" LOGIN NOCREATEDB NOSUPERUSER NOCREATEROLE PASSWORD ' . $admin->quote($password));
    register_shutdown_function(static function () use ($login): void {
        try {
            connectDatabase(testServer(), 'postgres', false)->exec('DROP ROLE IF EXISTS "' . $login . '"');
        } catch (\Throwable $e) {
            fwrite(STDERR, "could not drop the login {$login}: {$e->getMessage()}\n");
        }
    });
    $closed = throwawayDatabase($GLOBALS['database'] . '_closed');
    ensureDatabase(testServer(), $closed);
    $admin->exec('REVOKE CONNECT ON DATABASE "' . $closed . '" FROM PUBLIC');

    $nonce = nonceFrom(setupRequest('GET', LOCAL_SERVER));
    $as = static fn(string $name): array => setupJsonAnswer(setupRequest('POST', LOCAL_SERVER,
        json: ['action' => 'test_database'] + localForm($nonce, ['db_user' => $login, 'db_pass' => $password, 'db_name' => $name])));

    $never = $GLOBALS['database'] . '_never';
    $missing = $as($never);
    same(['ok' => false, 'tone' => 'bad', 'message' => "Connected to the server, but there is no database \"{$never}\" and this user may not create one. Make it in the hosting panel first."],
        $missing, 'a database not there, and a login that may not make it');

    $shut = $as($closed);
    $opening = "The server is there, but \"{$login}\" cannot open the database \"{$closed}\": ";
    same([false, 'bad'], [$shut['ok'], $shut['tone']], 'a database the login may not open: ' . $shut['message']);
    check(str_starts_with($shut['message'], $opening), 'said as the family says it: ' . $shut['message']);
    check(strlen($shut['message']) > strlen($opening), 'with the server\'s reason');

    foreach ([$missing, $shut] as $answer) {
        check(!str_contains($answer['message'], $password), 'no answer repeats the login\'s password');
    }
    check(!is_file(IAM_CONFIG_DIR . '/database.local.php'), 'testing writes nothing');
});

test('saving writes every file, builds the database, brings the accounts over, and the API comes up', function () use ($legacy) {
    $nonce = nonceFrom(setupRequest('GET', LOCAL_SERVER));
    $saved = setupRequest('POST', LOCAL_SERVER, localForm($nonce));
    same(200, $saved['status'], 'saved: ' . strip_tags($saved['body']));

    foreach (['database', 'secret', 'iam', 'mail'] as $name) {
        check(is_file(IAM_CONFIG_DIR . "/{$name}.local.php"), "config/{$name}.local.php written");
    }
    check(!is_file(IAM_CONFIG_DIR . '/google.local.php'), 'no Google without a client id');
    same([], iamSetUpProblems(), 'set up');
    same('file', localConfig('mail')['driver'], 'mail goes to files on this computer');
    same($GLOBALS['legacyName'], localConfig('database')['databases']['legacy'] ?? null, 'the old database is remembered for import-legacy.php');
    check(is_file(setupTestContext()['self']), 'the installer stays');
    check(!is_file(IAM_STORAGE_DIR . '/installed.lock'), 'and is not locked');

    // Built from nothing by the family's engine: every file, recorded in the table the engine always kept.
    same([], pendingMigrations(iamDb()), 'nothing pending');
    same(migrationFiles(), migrationNames(iamMigrator(iamDb())->status()['applied']), 'every migration applied, in order');
    same(count(migrationFiles()), (int) iamDb()->query('SELECT count(*) FROM migrations')->fetchColumn(), 'each recorded once');

    $browser = new Browser();
    same(200, $browser->get('/api/public/config')->status, 'the API answers');
    same(['state' => 'ready', 'installed' => false], $browser->get('/api/site-status')->json(), 'ready - and, on this computer, never installed');
    same(TEST_ISSUER, $browser->get('/.well-known/openid-configuration')->json()['issuer'] ?? null, 'as the issuer entered');
    same(200, $browser->signIn('owner@example.test', 'owner password')->status, 'the imported admin signs in with the old password');
    same(true, $browser->get('/api/account')->json()['is_admin'] ?? null, 'and administers the IAM');
    same(applicationsWithAdmin(iamDb()), adminOf(iamDb(), 3), 'and holds ADMIN in every application that has one - every site\'s too');
    same(count(FIRST_PARTY_SITES) + 1, count(adminOf(iamDb(), 3)), 'the IAM and the five sites');
    same([], adminOf(iamDb(), 8), 'the old site\'s player holds none');
    same(2, (int) $legacy->query('SELECT accounts FROM iam_handover')->fetchColumn(), 'the marker for the genshin site is written');
    same('http://localhost:4300', iamDb()->query("SELECT home_url FROM applications WHERE client_id = 'iam'")->fetchColumn(), 'the IAM\'s own home is its address');
});

test('run again: the saved values show, never a password or the key; a blank password keeps the saved one', function () use ($server) {
    $page = setupRequest('GET', LOCAL_SERVER);
    same(200, $page['status'], 'the form again');
    check(str_contains($page['body'], 'value="' . $GLOBALS['database'] . '"'), 'the saved database');
    check(!str_contains($page['body'], (string) localConfig('secret')), 'the master key is never shown');
    if ((string) $server['password'] !== '') {
        check(str_contains($page['body'], 'placeholder="saved - blank keeps it"'), 'the database password is said to be saved');
    }
    check(str_contains($page['body'], 'account'), 'the accounts already there are named');

    // Something added by hand stays through a save.
    writeTestConfig('iam', ['public_url' => 'http://localhost:4300', 'trusted_proxies' => ['10.0.0.0/8']]);
    forgetLocalConfig();
    $key = localConfig('secret');

    $answer = setupRequest('POST', LOCAL_SERVER, localForm(nonceFrom($page), ['db_pass' => '', 'accounts' => 'later', 'mail_from_name' => 'anotoki dev']));
    same(200, $answer['status'], 'saved again: ' . strip_tags($answer['body']));
    check(str_contains($answer['body'], 'left as they are'), 'the accounts are left alone');
    // Compared, never printed: same() would show both values when it fails.
    check((string) localConfig('database')['password'] === (string) $server['password'], 'the blank password kept the saved one');
    check(localConfig('secret') === $key, 'the master key is kept');
    same('anotoki dev', localConfig('mail')['from_name'], 'the change is saved');
    same(['10.0.0.0/8'], localConfig('iam')['trusted_proxies'] ?? null, 'and what setup.php does not manage is kept');
    check(is_file(setupTestContext()['self']), 'the installer is still there');

    $kept = setupJsonAnswer(setupRequest('POST', LOCAL_SERVER, json: ['action' => 'test_database'] + localForm(nonceFrom($page), ['db_pass' => ''])));
    same('good', $kept['tone'], 'a test with a blank password uses the saved one: ' . $kept['message']);
    same("Connected to the database \"{$GLOBALS['database']}\": it is up to date.", $kept['message'], 'and finds the database it built up to date');

    // Last, as it depends on this computer's password: one so short that it
    // turns up inside other words fails here, and nothing after it would run.
    if ((string) $server['password'] !== '') {
        check(!str_contains($page['body'], (string) $server['password']), 'nor the database password');
    }
});

test('a blank password is the saved one only for the server and user it was saved for', function () {
    $saved = ['host' => 'db.example.test', 'port' => '5432', 'username' => 'iam', 'password' => 'the saved one'];
    $values = ['db_host' => 'db.example.test', 'db_port' => '5432', 'db_user' => 'iam'];

    same('the saved one', setupConnection($values, '', $saved)['password'], 'same server, same user');
    same('typed', setupConnection($values, 'typed', $saved)['password'], 'a typed one wins');
    same('', setupConnection(['db_host' => 'elsewhere.example.test'] + $values, '', $saved)['password'], 'never sent to another host');
    same('', setupConnection(['db_user' => 'someone'] + $values, '', $saved)['password'], 'nor for another user');
});

test('a new administrator instead, into a database of its own; Google comes and goes with its client id', function () {
    $fresh = throwawayDatabase($GLOBALS['database'] . '_fresh');
    $nonce = nonceFrom(setupRequest('GET', LOCAL_SERVER));

    $short = setupRequest('POST', LOCAL_SERVER, localForm($nonce, ['db_name' => $fresh, 'accounts' => 'fresh', 'email' => 'admin@localhost', 'password' => 'short', 'confirm' => 'short']));
    same(422, $short['status'], 'a short password is refused');

    $answer = setupRequest('POST', LOCAL_SERVER, localForm($nonce, [
        'db_name' => $fresh, 'accounts' => 'fresh', 'email' => 'admin@localhost', 'password' => 'a long enough one', 'confirm' => 'a long enough one',
        'google_client_id' => '1234-abc.apps.googleusercontent.com',
    ]));
    same(200, $answer['status'], 'saved: ' . strip_tags($answer['body']));
    same(200, (new Browser())->signIn('admin@localhost', 'a long enough one')->status, 'the administrator signs in');
    $pdo = connectDatabase(testServer(), $fresh, false);
    $adminId = (int) $pdo->query("SELECT id FROM users WHERE email = 'admin@localhost'")->fetchColumn();
    same(['build-analyzer', 'genshin', 'iam', 'japanese-academy', 'piano-academy', 'survey'], adminOf($pdo, $adminId), 'and holds ADMIN in the IAM and every site - the owner\'s rule for the setup\'s administrator');
    same(applicationsWithAdmin($pdo), adminOf($pdo, $adminId), 'which is every application that has one');
    $pdo = null;
    same('1234-abc.apps.googleusercontent.com', localConfig('google')['client_id'] ?? null, 'Google is on');
    same(true, (new Browser())->get('/api/public/config')->json()['google_enabled'] ?? null, 'and the pages know');

    $off = setupRequest('POST', LOCAL_SERVER, localForm($nonce, ['db_name' => $fresh, 'accounts' => 'later', 'google_client_id' => '']));
    same(200, $off['status'], 'saved without it');
    check(!is_file(IAM_CONFIG_DIR . '/google.local.php'), 'Google is off again');
});

test('a fresh database arrives with the sites\' development addresses, as in the port table', function () {
    $table = [
        'genshin' => 'http://localhost:4200', 'japanese-academy' => 'http://localhost:4400', 'piano-academy' => 'http://localhost:4500',
        'build-analyzer' => 'http://localhost:4600', 'survey' => 'http://localhost:4700',
    ];
    $pdo = iamDb();

    foreach ($table as $clientId => $frontend) {
        $application = $pdo->prepare('SELECT id, home_url FROM applications WHERE client_id = ?');
        $application->execute([$clientId]);
        $row = $application->fetch();
        same($frontend, $row['home_url'], "{$clientId} home");

        $uris = $pdo->prepare('SELECT kind, uri FROM application_redirect_uris WHERE application_id = ? ORDER BY kind');
        $uris->execute([$row['id']]);
        same([['kind' => 'login', 'uri' => $frontend . '/auth/callback'], ['kind' => 'logout', 'uri' => $frontend . '/']], $uris->fetchAll(), "{$clientId} redirects");

        $origins = $pdo->prepare('SELECT origin FROM application_origins WHERE application_id = ?');
        $origins->execute([$row['id']]);
        same([$frontend], $origins->fetchAll(PDO::FETCH_COLUMN), "{$clientId} origin");
    }
    same('Build Analyzer', $pdo->query("SELECT name FROM applications WHERE client_id = 'build-analyzer'")->fetchColumn(), 'the analyzer is called what it is');

    // The survey site arrives by a later migration than the others, and on a
    // database built on this computer it gets what they got: a card, and roles.
    $survey = $pdo->query("SELECT id, name, login_required, show_on_landing FROM applications WHERE client_id = 'survey'")->fetch();
    same('Surveys', $survey['name'], 'the survey site is called what it is');
    same(true, asBool($survey['login_required']), 'it asks everybody to sign in');
    same(true, asBool($survey['show_on_landing']), 'and it has a card on the landing page');
    $roles = $pdo->prepare('SELECT name FROM application_roles WHERE application_id = ? AND may_sign_in ORDER BY name');
    $roles->execute([$survey['id']]);
    same(['ADMIN', 'EDITOR', 'MEMBER'], $roles->fetchAll(PDO::FETCH_COLUMN), 'the roles the survey site asks for, and the MEMBER every account holds - each may sign in');
});

test('a file the installer reads that does not start with <?php - no <?php line, a byte-order mark - is never shown, and nothing is saved over it until it is corrected; config/cloudflare.local.php it never touches', function () {
    $nonce = nonceFrom(setupRequest('GET', LOCAL_SERVER));
    $secretFile = IAM_CONFIG_DIR . '/secret.local.php';
    $iamFile = IAM_CONFIG_DIR . '/iam.local.php';
    $cloudflareFile = IAM_CONFIG_DIR . '/cloudflare.local.php';
    $key = (string) file_get_contents($secretFile);
    $hex = trim((string) localConfig('secret'));
    $iam = (string) file_get_contents($iamFile);
    $withoutPhp = "return ['public_url' => 'http://localhost:4300'];\n";

    // The origin secret, uploaded by hand: no save reads it or writes it.
    $cloudflare = "<?php\n\nreturn ['origin_secret' => 'uploaded-by-hand-0123456789abcdefghijklmnop'];\n";
    file_put_contents($cloudflareFile, $cloudflare);

    file_put_contents($secretFile, "\xEF\xBB\xBF" . $key);
    file_put_contents($iamFile, $withoutPhp);
    forgetLocalConfig();
    try {
        ob_start();
        $page = setupRequest('GET', LOCAL_SERVER);
        $printed = (string) ob_get_clean();
        same('', $printed, 'nothing of either is printed');
        same(200, $page['status'], 'the form');
        check(!str_contains($page['body'], $hex), 'the master key is not in the page');
        check(str_contains($page['body'], 'config/secret.local.php does not start with &lt;?php') && str_contains($page['body'], 'config/iam.local.php does not start with &lt;?php'),
            'the page names both files and their fault: ' . strip_tags($page['body']));

        ob_start();
        $answer = setupRequest('POST', LOCAL_SERVER, localForm(nonceFrom($page), ['db_pass' => '', 'accounts' => 'later']));
        $printed = (string) ob_get_clean();
        same('', $printed, 'nothing printed by the save either');
        same(409, $answer['status'], 'the save is refused while they print');
        check(!str_contains($answer['body'], $hex), 'nor is the key in its answer');
        check("\xEF\xBB\xBF" . $key === (string) file_get_contents($secretFile), 'the key\'s file is not written over: no new master key in its place');
        same($withoutPhp, (string) file_get_contents($iamFile), 'nor is the other');
    } finally {
        file_put_contents($secretFile, $key);
        file_put_contents($iamFile, $iam);
        forgetLocalConfig();
    }

    $saved = setupRequest('POST', LOCAL_SERVER, localForm($nonce, ['db_pass' => '', 'accounts' => 'later']));
    same(200, $saved['status'], 'corrected, it saves: ' . strip_tags($saved['body']));
    same($cloudflare, (string) file_get_contents($cloudflareFile), 'config/cloudflare.local.php is as it was uploaded');
    check(trim((string) localConfig('secret')) === $hex, 'and the master key is the one it was');
    unlink($cloudflareFile);
    forgetLocalConfig();
});

test('a copy locked as a real host stays locked on this computer too', function () {
    file_put_contents(IAM_STORAGE_DIR . '/installed.lock', '{}');
    $page = setupRequest('GET', LOCAL_SERVER);
    same(409, $page['status'], 'locked');
    check(str_contains($page['body'], 'installed.lock'), 'and says which file');
    same(409, setupRequest('POST', LOCAL_SERVER, json: ['action' => 'test_database'])['status'], 'the tests too');
    check(is_file(setupTestContext()['self']), 'nothing deleted on this computer');
    unlink(IAM_STORAGE_DIR . '/installed.lock');
});

finishTests();
