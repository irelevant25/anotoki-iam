<?php

/**
 * The installer on a real host: anybody can reach it, so nothing happens
 * without the token written to config/setup.local.php; it asks for the sites'
 * production addresses and puts them in place of the development ones; and
 * when it is done it locks itself and deletes itself.
 */

declare(strict_types=1);

require __DIR__ . '/lib.php';
require __DIR__ . '/setup_lib.php';

bootTests(configured: false);

$database = throwawayDatabase(testEnvironment()['db']);

/** The form as the owner fills it in on the server. */
function remoteForm(string $token, array $overrides = []): array
{
    $server = testServer();

    return $overrides + [
        'token' => $token,
        'public_url' => 'https://iam.example.test',
        'db_host' => (string) $server['host'],
        'db_port' => (string) $server['port'],
        'db_user' => (string) $server['username'],
        'db_pass' => (string) $server['password'],
        'db_name' => $GLOBALS['database'],
        'legacy' => '',
        'accounts' => 'fresh',
        'email' => 'owner@example.test',
        'password' => 'the owners password',
        'confirm' => 'the owners password',
        'site' => ['genshin' => 'https://genshin.example.test', 'japanese-academy' => '', 'piano-academy' => '', 'build-analyzer' => '', 'survey' => 'https://survey.example.test'],
        'mail_send' => 'on',
        'mail_noreply' => 'noreply@example.test',
        'mail_support' => 'support@example.test',
        'mail_from_name' => 'anotoki',
        'google_client_id' => '',
    ];
}

/** What only somebody with FTP access can read. */
function tokenOnDisk(): string
{
    $file = IAM_CONFIG_DIR . DIRECTORY_SEPARATOR . 'setup.local.php';
    $config = is_file($file) ? (static fn(string $path): mixed => require $path)($file) : null;

    return is_array($config) ? (string) ($config['token'] ?? '') : '';
}

// ─────────────────────────────────────────────────────────────────────────────

test('on a real host the first view writes a token only the server\'s owner can read, and asks for it', function () {
    $page = setupRequest('GET', REMOTE_SERVER);
    same(200, $page['status'], 'the form');
    $token = tokenOnDisk();
    check(strlen($token) >= 32, 'a token in config/setup.local.php');
    check(!str_contains($page['body'], $token), 'which the page does not show');
    check(str_contains($page['body'], 'name="token"') && !str_contains($page['body'], 'name="nonce"'), 'the form asks for it, and carries no nonce');
    check(str_contains($page['body'], 'value="https://iam.example.test"'), 'the address it is opened at is the suggestion');
    check(str_contains($page['body'], 'value="noreply@example.test"'), 'and mail from its domain');
    check(str_contains($page['body'], 'value="anotoki_iam"'), 'and the database the installer suggests');
    check(str_contains($page['body'], 'placeholder="https://genshin.anotoki.eu"') && !str_contains($page['body'], 'value="https://genshin.anotoki.eu"'), 'the sites\' addresses are placeholders, never values');

    setupRequest('GET', REMOTE_SERVER);
    same($token, tokenOnDisk(), 'a second view keeps the same token');
});

test('without the token nothing happens: not the install, not a test, not from 127.0.0.1 under another name', function () {
    same(403, setupRequest('POST', REMOTE_SERVER, remoteForm(''))['status'], 'no token');
    same(403, setupRequest('POST', REMOTE_SERVER, remoteForm('not the token'))['status'], 'a wrong one');
    same(403, setupRequest('POST', REMOTE_SERVER, json: ['action' => 'test_database'] + remoteForm(''))['status'], 'a test without it');

    // A page that rebinds its own name to this machine is a real host to the installer.
    $rebound = ['REMOTE_ADDR' => '127.0.0.1', 'HTTP_HOST' => 'iam.example.test'];
    $nonce = nonceFrom(setupRequest('GET', LOCAL_SERVER));
    same(403, setupRequest('POST', $rebound, remoteForm('', ['nonce' => $nonce]))['status'], 'a nonce is no token');

    check(!is_file(IAM_CONFIG_DIR . '/database.local.php') && !is_file(IAM_STORAGE_DIR . '/installed.lock'), 'nothing written');
});

test('with the token, "Test connection" answers, in the family\'s words', function () {
    $answer = setupJsonAnswer(setupRequest('POST', REMOTE_SERVER, json: ['action' => 'test_database'] + remoteForm(tokenOnDisk())));

    // The database is not made until the install: what is said of it depends
    // on whether the tests' login may make one.
    $mayCreate = (bool) connectDatabase(testServer(), 'postgres', false)->query('SELECT rolcreatedb OR rolsuper FROM pg_roles WHERE rolname = current_user')->fetchColumn();
    same($mayCreate
        ? ['ok' => true, 'tone' => 'good', 'message' => "Connected to the server. The database \"{$GLOBALS['database']}\" does not exist yet - Save creates it."]
        : ['ok' => false, 'tone' => 'bad', 'message' => "Connected to the server, but there is no database \"{$GLOBALS['database']}\" and this user may not create one. Make it in the hosting panel first."],
        $answer, 'the server answers');
});

test('a real host is https, and each site\'s address is its https origin', function () {
    $token = tokenOnDisk();

    same(422, setupRequest('POST', REMOTE_SERVER, remoteForm($token, ['public_url' => 'http://iam.example.test']))['status'], 'a plain-http issuer');
    same(422, setupRequest('POST', REMOTE_SERVER, remoteForm($token, ['public_url' => 'https://iam.example.test/iam']))['status'], 'an issuer with a path');
    same(422, setupRequest('POST', REMOTE_SERVER, remoteForm($token, ['site' => ['genshin' => 'http://localhost:4200']]))['status'], 'a development address');
    same(422, setupRequest('POST', REMOTE_SERVER, remoteForm($token, ['site' => ['genshin' => 'https://genshin.example.test/app']]))['status'], 'an address with a path');
    same(422, setupRequest('POST', REMOTE_SERVER, remoteForm($token, ['accounts' => 'later']))['status'], 'an install nobody could administer');
    check(!is_file(IAM_STORAGE_DIR . '/installed.lock') && is_file(setupTestContext()['self']), 'none of them installed anything');
});

test('the install: files, database, administrator, the sites\' production addresses in place of the development ones; then the lock, and the installer goes', function () {
    $answer = setupRequest('POST', REMOTE_SERVER, remoteForm(tokenOnDisk()));
    same(200, $answer['status'], 'installed: ' . strip_tags($answer['body']));
    check(str_contains($answer['body'], 'secret.local.php'), 'the master key is to be downloaded and kept');

    same([], iamSetUpProblems(), 'set up');
    same('https://iam.example.test', localConfig('iam')['public_url'], 'the issuer');
    same('mail', localConfig('mail')['driver'], 'mail is sent');
    check(is_file(IAM_STORAGE_DIR . '/installed.lock'), 'the lock is written');
    check(!is_file(IAM_CONFIG_DIR . '/setup.local.php'), 'the token is gone');
    check(!is_file(setupTestContext()['self']), 'and so is the installer');

    $pdo = iamDb();
    same([[], migrationFiles()], [pendingMigrations($pdo), appliedMigrations($pdo)], 'the database, built from nothing: every migration applied');
    $genshin = (int) $pdo->query("SELECT id FROM applications WHERE client_id = 'genshin'")->fetchColumn();
    same(['https://genshin.example.test/auth/callback'], applicationRedirectUris($genshin, 'login'), 'genshin signs in at its own address');
    same(['https://genshin.example.test/'], applicationRedirectUris($genshin, 'logout'), 'and lands there after signing out');
    same(['https://genshin.example.test'], applicationOrigins($genshin), 'its origin');
    same('https://genshin.example.test', $pdo->query("SELECT home_url FROM applications WHERE id = {$genshin}")->fetchColumn(), 'its home');

    // The survey site's migration came after the others' and seeded it on the
    // same terms; the install puts its production address in all the same.
    $survey = (int) $pdo->query("SELECT id FROM applications WHERE client_id = 'survey'")->fetchColumn();
    same(['https://survey.example.test/auth/callback'], applicationRedirectUris($survey, 'login'), 'the survey site signs in at its own address');
    same(['https://survey.example.test/'], applicationRedirectUris($survey, 'logout'), 'and lands there after signing out');
    same(['https://survey.example.test'], applicationOrigins($survey), 'its origin');
    same('https://survey.example.test', $pdo->query("SELECT home_url FROM applications WHERE id = {$survey}")->fetchColumn(), 'its home');

    same(0, (int) $pdo->query("SELECT count(*) FROM application_redirect_uris WHERE uri LIKE 'http://localhost%' OR uri LIKE 'http://127.0.0.1%'")->fetchColumn(), 'no development redirect URI is left');
    same(0, (int) $pdo->query("SELECT count(*) FROM application_origins WHERE origin LIKE 'http://localhost%' OR origin LIKE 'http://127.0.0.1%'")->fetchColumn(), 'nor a development origin');
    $piano = (int) $pdo->query("SELECT id FROM applications WHERE client_id = 'piano-academy'")->fetchColumn();
    same([], applicationRedirectUris($piano, 'login'), 'a site left blank has no address yet');
    same(null, $pdo->query("SELECT home_url FROM applications WHERE id = {$piano}")->fetchColumn(), 'and no home pointing at a development machine');
    same('https://iam.example.test', $pdo->query("SELECT home_url FROM applications WHERE client_id = 'iam'")->fetchColumn(), 'the IAM\'s own home is its address');

    $browser = new Browser('198.51.100.20');
    same('https://iam.example.test', $browser->get('/.well-known/openid-configuration')->json()['issuer'] ?? null, 'discovery names the issuer');
    same(200, $browser->signIn('owner@example.test', 'the owners password')->status, 'the administrator signs in');
    same(true, $browser->get('/api/account')->json()['is_admin'] ?? null, 'as an IAM admin');
    $owner = (int) $pdo->query("SELECT id FROM users WHERE email = 'owner@example.test'")->fetchColumn();
    same(['build-analyzer', 'genshin', 'iam', 'japanese-academy', 'piano-academy', 'survey'], adminOf($pdo, $owner), 'and holds ADMIN in every site as well, a site left blank included');
    same(applicationsWithAdmin($pdo), adminOf($pdo, $owner), 'which is every application that has one');
});

test('once installed it stays installed', function () {
    same(409, setupRequest('GET', REMOTE_SERVER)['status'], 'the page');
    same(409, setupRequest('POST', REMOTE_SERVER, remoteForm('anything'))['status'], 'a post');
    same(409, setupRequest('POST', REMOTE_SERVER, json: ['action' => 'test_database'])['status'], 'a test');
    check(!is_file(IAM_CONFIG_DIR . '/setup.local.php'), 'and no new token is made');
    same(['state' => 'ready', 'installed' => true], (new Browser('198.51.100.21'))->get('/api/site-status')->json(), 'the status: ready, and installed');
});

test('installed, with a configuration file lost: not available - and the installer, gone, is never offered again', function () {
    // As an upload over the server's own files might leave it.
    $file = IAM_CONFIG_DIR . DIRECTORY_SEPARATOR . 'mail.local.php';
    $kept = (string) file_get_contents($file);
    unlink($file);
    forgetLocalConfig();

    try {
        $browser = new Browser('198.51.100.22');
        foreach (['/api/public/config', '/.well-known/openid-configuration', '/api/account', '/api/admin/migrations'] as $path) {
            $answer = $browser->get($path);
            same([503, 'not_set_up', true], [$answer->status, $answer->json()['code'] ?? null, $answer->json()['installed'] ?? null], "GET {$path}");
            check(!array_key_exists('setup', $answer->json()) && !str_contains($answer->body, 'setup.php'), "GET {$path} names no installer: " . $answer->body);
            check(is_string($answer->json()['message'] ?? null), "GET {$path} says so in a sentence");
        }
        same(['state' => 'not_set_up', 'installed' => true], $browser->get('/api/site-status')->json(), 'the status: not set up, and installed');
    } finally {
        file_put_contents($file, $kept);
        forgetLocalConfig();
    }

    same(200, (new Browser('198.51.100.23'))->get('/api/public/config')->status, 'with the file back, the IAM answers');
});

finishTests();
