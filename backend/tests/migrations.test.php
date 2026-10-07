<?php

/**
 * The anotoki family's migrations module (anotoki/lib) in the IAM: the status
 * the pages ask (/api/site-status), the admin panel's Migrations routes, the
 * command line - and that an update waiting closes nothing here. The IAM is
 * the door every site signs in through, and its administrator signs in
 * through it to press Apply, so it does not block while an update waits
 * (blockWhilePending false), as every other site does.
 *
 * The not-set-up answers - an IAM never installed, and one installed whose
 * configuration went missing - are the installer's tests' (setup.test.php,
 * setup-remote.test.php), which start from an IAM without one.
 */

declare(strict_types=1);

require __DIR__ . '/lib.php';

use Anotoki\Lib\Migrations\Migrator;

// This run's own database is a fresh IAM, built from every migration.
bootTests();

const PASSWORD = 'correct horse';
const SURVEY_FRONTEND = 'http://localhost:4700';
const SURVEY_CALLBACK = 'http://localhost:4700/auth/callback';

$files = migrationFiles();
/** The newest migration: pending on the window's database, which has every file before it. */
define('NEWEST', end($files));
define('BEFORE_NEWEST', $files[count($files) - 2]);

/**
 * Points the app at another of this run's databases, and lets nothing
 * remembered of the old one stand - but the signing key, where the database
 * is one that is never made (there is no key to read).
 */
function useIamDatabase(string $name, bool $reachable = true): void
{
    $environment = testEnvironment();
    file_put_contents($environment['config'] . DIRECTORY_SEPARATOR . 'database.local.php',
        "<?php\n\n\$config = require " . var_export(developmentDatabaseFile(), true) . ";\n"
        . "\$config['databases'] = ['iam' => " . var_export(throwawayDatabase($name), true) . "];\n"
        . "\$config['persistent'] = false;\n\nreturn \$config;\n");
    forgetLocalConfig();
    settingsAll(true);
    languageCodes(null, true);
    if ($reachable) {
        activeSigningKey(true);
    }
}

function signedIn(string $email, string $ip): Browser
{
    $browser = new Browser($ip);
    $answer = $browser->signIn($email, PASSWORD);
    same(200, $answer->status, "{$email} signs in: " . $answer->body);

    return $browser;
}

/** A signed-in browser's single sign-on to the survey site, through to its tokens. */
function intoSurvey(Browser $browser): TestResponse
{
    [$verifier, $challenge] = pkce();
    $start = $browser->get('/api/oidc/authorize?' . http_build_query([
        'response_type' => 'code', 'client_id' => 'survey', 'redirect_uri' => SURVEY_CALLBACK, 'scope' => 'openid profile email',
        'state' => 's', 'nonce' => 'n', 'code_challenge' => $challenge, 'code_challenge_method' => 'S256',
    ]));
    $code = queryOf($start->location())['code'] ?? null;
    check($code !== null, 'a code straight away: ' . $start->location());

    return (new Browser('10.18.9.1'))->request('POST', '/api/oidc/token', [
        'form' => ['grant_type' => 'authorization_code', 'code' => $code, 'redirect_uri' => SURVEY_CALLBACK, 'client_id' => 'survey', 'code_verifier' => $verifier],
        'headers' => ['Origin' => SURVEY_FRONTEND],
    ]);
}

/** How many times the audit log says migrations were applied. */
function appliesAudited(): int
{
    return (int) iamDb()->query("SELECT count(*) FROM audit_log WHERE action = 'migrations.applied'")->fetchColumn();
}

/** The command line, run as a person runs it, against the configuration in $configDir: [exit status, output without colours]. */
function runCli(string $configDir, string $arguments = ''): array
{
    $wrapper = testEnvironment()['base'] . DIRECTORY_SEPARATOR . 'migration-cli.php';
    file_put_contents($wrapper, "<?php\n\n"
        // As the tests do: configuration and storage of the run's own, never this machine's.
        . 'define(\'IAM_CONFIG_DIR\', ' . var_export($configDir, true) . ");\n"
        . 'define(\'IAM_STORAGE_DIR\', ' . var_export(testEnvironment()['storage'], true) . ");\n"
        . 'require ' . var_export(dirname(__DIR__) . DIRECTORY_SEPARATOR . 'migration.php', true) . ";\n");

    exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($wrapper) . ($arguments === '' ? '' : ' ' . $arguments) . ' 2>&1', $lines, $status);

    return [$status, (string) preg_replace('/\e\[[0-9;]*m/', '', implode("\n", $lines))];
}

/** A configuration folder of the run's own whose database is $name: this run's files, another database. */
function cliConfiguration(string $name): string
{
    $environment = testEnvironment();
    $directory = $environment['base'] . DIRECTORY_SEPARATOR . 'config-cli';
    @mkdir($directory, 0700, true);

    foreach (['secret', 'iam', 'mail'] as $file) {
        copy($environment['config'] . DIRECTORY_SEPARATOR . "{$file}.local.php", $directory . DIRECTORY_SEPARATOR . "{$file}.local.php");
    }
    file_put_contents($directory . DIRECTORY_SEPARATOR . 'database.local.php',
        "<?php\n\n\$config = require " . var_export(developmentDatabaseFile(), true) . ";\n"
        . "\$config['databases'] = ['iam' => " . var_export(throwawayDatabase($name), true) . "];\n"
        . "\$config['persistent'] = false;\n\nreturn \$config;\n");

    return $directory;
}

// ─────────────────────────────────────────────────────────────────────────────

test('the status says where the IAM stands - ready on a database built from every migration - and nothing else', function () {
    $answer = (new Browser())->get('/api/site-status', ['headers' => ['Origin' => SURVEY_FRONTEND]]);
    same([200, ['state' => 'ready', 'installed' => false]], [$answer->status, $answer->json()], 'ready; on this computer never installed');
    same(['no-store', ''], [$answer->header('Cache-Control'), $answer->header('Access-Control-Allow-Origin')], 'not kept, and in no CORS list - only the IAM\'s pages read it');
    same(405, (new Browser())->post('/api/site-status')->status, 'it is a GET, nothing else');
    same(false, iamSiteState()->blockWhilePending, 'the IAM does not block while an update waits');
});

test('the database not answering: the status says unavailable, and everything else gets the IAM\'s own answers', function () {
    $main = testEnvironment()['db'];
    // A database of this run's that is never made.
    useIamDatabase($main . '_nowhere', reachable: false);

    try {
        $browser = new Browser('10.18.1.1');
        same(['state' => 'unavailable', 'installed' => false], $browser->get('/api/site-status')->json(), 'unavailable');
        $discovery = $browser->get('/.well-known/openid-configuration');
        same([200, ['en', 'sk']], [$discovery->status, $discovery->json()['ui_locales_supported'] ?? null], 'discovery answers as it always did without its database');
        // What needs the database fails as it always did, through the IAM's own error handler - not a 503 of the gate's.
        same([500, 'server_error'], [($r = $browser->get('/api/translations/en'))->status, $r->code()], 'the words: the IAM\'s own answer');
    } finally {
        useIamDatabase($main);
    }

    same(['state' => 'ready', 'installed' => false], (new Browser('10.18.1.2'))->get('/api/site-status')->json(), 'back on this run\'s own database: ready');
});

test('an update waiting closes nothing: every way in, the account page, a site\'s sign-in and the admin panel answer as ever', function () {
    $main = testEnvironment()['db'];
    $window = $main . '_window';
    buildIamThrough('_window', BEFORE_NEWEST);
    useIamDatabase($window);

    try {
        $adminId = makeUser('admin', 'admin@example.test', PASSWORD);
        grantRole($adminId, 'iam', 'ADMIN');
        makeUser('person', 'person@example.test', PASSWORD);

        $visitor = new Browser('10.18.2.1');
        same(['state' => 'update_pending', 'installed' => false], $visitor->get('/api/site-status')->json(), 'the status says an update waits');
        foreach (['/.well-known/openid-configuration', '/.well-known/jwks.json', '/api/public/config', '/api/translations/en', '/api/translations/sk', '/api/applications'] as $path) {
            $answer = $visitor->get($path);
            same(200, $answer->status, "GET {$path}: " . substr($answer->body, 0, 200));
            same(null, $answer->json()['code'] ?? null, "GET {$path} is no refusal");
        }

        $person = signedIn('person@example.test', '10.18.2.2');
        same([200, 'person'], [($r = $person->get('/api/account'))->status, $r->json()['username'] ?? null], 'the account page');
        $tokens = intoSurvey($person);
        same(200, $tokens->status, 'a site signs the person in, as ever: ' . $tokens->body);
        same(['MEMBER'], verifyLikeAnApp(new Browser(), (string) ($tokens->json()['access_token'] ?? ''), 'survey')['roles'] ?? null, 'with a token the site accepts');

        $admin = signedIn('admin@example.test', '10.18.2.3');
        foreach (['/api/admin/users', '/api/admin/stats', '/api/admin/settings', '/api/admin/migrations'] as $path) {
            same(200, $admin->get($path)->status, "the admin panel: GET {$path}");
        }
        same([NEWEST], pendingMigrations(iamDb()), 'and the update still waits: nothing applied it by the way');
    } finally {
        useIamDatabase($main);
    }
});

test('the Migrations routes: what the database has and lacks, a file\'s SQL, and Apply - for the IAM\'s administrators, with the CSRF header, one at a time', function () {
    $main = testEnvironment()['db'];
    $window = $main . '_window';
    // The window of the test before, the newest file still pending, its two accounts in it.
    useIamDatabase($window);

    try {
        $nobody = new Browser('10.18.3.1');
        foreach (['GET /api/admin/migrations', 'GET /api/admin/migrations/file?set=iam&name=001_initial_schema.sql', 'POST /api/admin/migrations/apply'] as $call) {
            [$method, $path] = explode(' ', $call);
            same([401, 'no_session'], [($r = $nobody->request($method, $path))->status, $r->code()], "nobody signed in: {$call}");
        }

        $person = signedIn('person@example.test', '10.18.3.2');
        foreach (['GET /api/admin/migrations', 'GET /api/admin/migrations/file?set=iam&name=001_initial_schema.sql', 'POST /api/admin/migrations/apply'] as $call) {
            [$method, $path] = explode(' ', $call);
            same([403, 'forbidden'], [($r = $person->request($method, $path))->status, $r->code()], "an account that is no administrator: {$call}");
        }
        same([NEWEST], pendingMigrations(iamDb()), 'none of them applied anything');

        $admin = signedIn('admin@example.test', '10.18.3.3');
        $list = $admin->get('/api/admin/migrations');
        same([200, 'no-store'], [$list->status, $list->header('Cache-Control')], 'the list');
        $status = $list->json();
        same(['iam'], $status['sets'] ?? null, 'one set, the IAM\'s');
        same(array_slice(migrationFiles(), 0, -1), migrationNames($status['applied'] ?? []), 'every file before the newest applied, in the order they ran');
        same(['iam'], array_values(array_unique(array_column($status['applied'], 'set'))), 'each of the set iam');
        foreach ($status['applied'] as $file) {
            check(preg_match('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ$/', (string) $file['applied_at']) === 1, "{$file['name']} applied at a time in UTC: " . $file['applied_at']);
        }
        same([['set' => 'iam', 'name' => NEWEST, 'ready' => true, 'blocked' => false]], $status['pending'] ?? null, 'the newest pending');
        same([], $status['missing'] ?? null, 'nothing recorded that is not on disk');

        $file = $admin->get('/api/admin/migrations/file?' . http_build_query(['set' => 'iam', 'name' => '001_initial_schema.sql']));
        same(200, $file->status, 'a file');
        same(['set' => 'iam', 'name' => '001_initial_schema.sql', 'sql' => file_get_contents(dirname(__DIR__) . '/migrations/iam/001_initial_schema.sql')], $file->json(), 'its SQL, as it is on disk');
        foreach ([['set' => 'iam', 'name' => '../../config/database.local.php'], ['set' => 'iam', 'name' => '..\\..\\config\\secret.local.php'], ['set' => 'iam', 'name' => 'nothing.sql'],
                  ['set' => 'genshin', 'name' => '001_initial_schema.sql'], ['name' => '001_initial_schema.sql'], []] as $query) {
            same([404, 'not_found'], [($r = $admin->get('/api/admin/migrations/file?' . http_build_query($query)))->status, $r->code()], 'no such file: ' . json_encode($query));
        }

        // The CSRF header guards Apply, as every first-party write.
        $audited = appliesAudited();
        same([403, 'csrf'], [($r = $admin->post('/api/admin/migrations/apply', [], ['csrf' => false]))->status, $r->code()], 'Apply without the header');
        same([403, 'csrf'], [($r = $admin->post('/api/admin/migrations/apply', [], ['headers' => ['Origin' => 'https://elsewhere.example.test']]))->status, $r->code()], 'Apply from another origin');
        same([[NEWEST], $audited], [pendingMigrations(iamDb()), appliesAudited()], 'neither applied anything');

        // One apply at a time, whoever starts it: another holds the engine's lock.
        $other = connectDatabase(testServer(), $window, false);
        $other->query('SELECT pg_advisory_lock(' . Migrator::DEFAULT_LOCK . ')');
        $busy = $admin->post('/api/admin/migrations/apply');
        same([409, 'busy'], [$busy->status, $busy->code()], 'another apply is running: ' . $busy->body);
        check(str_contains((string) ($busy->json()['message'] ?? ''), 'Nothing was applied'), 'and it says nothing was applied');
        same([[NEWEST], $audited], [pendingMigrations(iamDb()), appliesAudited()], 'nothing was, and nothing audited');
        $other->query('SELECT pg_advisory_unlock(' . Migrator::DEFAULT_LOCK . ')');
        $other = null;

        $applied = $admin->post('/api/admin/migrations/apply');
        same([200, 'no-store'], [$applied->status, $applied->header('Cache-Control')], 'Apply: ' . $applied->body);
        same(['applied' => [['set' => 'iam', 'name' => NEWEST]], 'failed' => null, 'error' => null, 'note' => null], $applied->json(), 'the newest applied');
        same([], pendingMigrations(iamDb()), 'nothing pending');
        $entry = iamDb()->query("SELECT actor_id, details FROM audit_log WHERE action = 'migrations.applied' ORDER BY id DESC LIMIT 1")->fetch();
        same([$audited + 1, (int) iamDb()->query("SELECT id FROM users WHERE email = 'admin@example.test'")->fetchColumn()], [appliesAudited(), (int) $entry['actor_id']], 'audited, by the administrator');
        $details = (array) json_decode((string) $entry['details'], true);
        ksort($details);
        same(['applied' => [NEWEST], 'error' => null, 'failed' => null], $details, 'with what it applied, as the audit log always had it');
        same(['state' => 'ready', 'installed' => false], (new Browser('10.18.3.4'))->get('/api/site-status')->json(), 'from the next request on, the IAM is ready');
        same([], $admin->get('/api/admin/migrations')->json()['pending'] ?? null, 'and the list has nothing pending');

        $again = $admin->post('/api/admin/migrations/apply');
        same([200, []], [$again->status, $again->json()['applied'] ?? null], 'an Apply with nothing to apply applies nothing');
    } finally {
        useIamDatabase($main);
    }
});

test('the command line builds a database from nothing, says what it has, dry-runs without touching it, and waits its turn', function () {
    $main = testEnvironment()['db'];
    $fresh = $main . '_cli';
    $config = cliConfiguration($fresh);
    $count = count(migrationFiles());

    [$status, $out] = runCli($config, '--status');
    same(1, $status, 'no database yet: ' . $out);
    check(str_contains($out, 'Cannot reach it'), 'says so: ' . $out);

    [$status, $out] = runCli($config);
    same(0, $status, 'from nothing: ' . $out);
    check(str_contains($out, 'Database created.'), 'the database is made: ' . $out);
    foreach (migrationFiles() as $file) {
        check(str_contains($out, "  {$file} ... done"), "{$file} applied: " . $out);
    }
    check(str_contains($out, 'There are no accounts yet'), 'and it says how to start: ' . $out);
    $pdo = connectDatabase(testServer(), $fresh, false);
    same([[], migrationFiles()], [pendingMigrations($pdo), appliedMigrations($pdo)], 'every migration applied, in order');
    same($count, (int) $pdo->query('SELECT count(*) FROM migrations')->fetchColumn(), 'each recorded once, in the table as it always was');
    same(['applied_at', 'filename', 'id'], $pdo->query("SELECT column_name FROM information_schema.columns WHERE table_name = 'migrations' ORDER BY column_name")->fetchAll(PDO::FETCH_COLUMN), 'its columns');
    $pdo = null;

    [$status, $out] = runCli($config, '--status');
    same(0, $status, '--status: ' . $out);
    check(str_contains($out, "{$count} applied, 0 pending"), 'every file applied: ' . $out);

    [$status, $out] = runCli($config);
    check($status === 0 && str_contains($out, "Up to date - {$count} migrations."), 'run again: up to date - ' . $out);

    // A database an update waits for.
    $behind = $main . '_cli_behind';
    buildIamThrough('_cli_behind', BEFORE_NEWEST);
    $config = cliConfiguration($behind);

    [$status, $out] = runCli($config, '--status');
    check($status === 0 && str_contains($out, NEWEST . '   pending') && str_contains($out, ($count - 1) . ' applied, 1 pending'), '--status names what waits: ' . $out);
    [$status, $out] = runCli($config, '--dry-run');
    check($status === 0 && str_contains($out, 'would apply ' . NEWEST), '--dry-run says what it would apply: ' . $out);
    $pdo = connectDatabase(testServer(), $behind, false);
    same([NEWEST], pendingMigrations($pdo), 'and applies nothing');

    $pdo->query('SELECT pg_advisory_lock(' . Migrator::DEFAULT_LOCK . ')');
    [$status, $out] = runCli($config);
    same(1, $status, 'another apply is running: ' . $out);
    check(str_contains($out, 'Another database update is running'), 'says so: ' . $out);
    same([NEWEST], pendingMigrations($pdo), 'and applies nothing');
    $pdo->query('SELECT pg_advisory_unlock(' . Migrator::DEFAULT_LOCK . ')');

    [$status, $out] = runCli($config);
    check($status === 0 && str_contains($out, '  ' . NEWEST . ' ... done'), 'its turn: the newest applied - ' . $out);
    same([], pendingMigrations($pdo), 'nothing pending');
});

test('a pooled connection lets go of the engine\'s lock its last request left, so Apply is not "busy" for ever', function () {
    // A request killed by its time limit mid-apply never reaches the engine's
    // `finally`; on a persistent connection its session - and the lock - live
    // on in the pool, and connectDatabase() is where the next request gets it.
    $name = testEnvironment()['db'];
    $lock = (string) Migrator::DEFAULT_LOCK;
    $pooled = connectDatabase(testServer(), $name, true);
    $pooled->query("SELECT pg_advisory_lock({$lock})");

    $other = connectDatabase(testServer(), $name, false);
    $free = static fn(): bool => asBool($other->query("SELECT pg_try_advisory_lock({$lock})")->fetchColumn());
    same(false, $free(), 'held, as a request that died would leave it');

    $next = connectDatabase(testServer(), $name, true);
    same($pooled->query('SELECT pg_backend_pid()')->fetchColumn(), $next->query('SELECT pg_backend_pid()')->fetchColumn(), 'the next request gets the same pooled session');
    same(true, $free(), 'and with it the lock is let go');
    $other->query("SELECT pg_advisory_unlock({$lock})");

    $applied = (new Browser('10.18.4.1'))->get('/api/site-status');
    same(['state' => 'ready', 'installed' => false], $applied->json(), 'the IAM as it was');
});

finishTests();
