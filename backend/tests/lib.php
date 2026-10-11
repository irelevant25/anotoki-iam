<?php

/**
 * The test harness: a throwaway database, a browser with a cookie jar, and a
 * way to read the mail the IAM sends.
 *
 * Tests run in-process: the Slim app is built once and handed PSR-7 requests,
 * so there is no server to start and nothing listens on a port. Each run gets
 * its own database, iam_test_<pid>_<random>, made from the migrations and
 * dropped at the end - the development database is never touched.
 *
 * Each run gets its own configuration as well: a temp folder of *.local.php
 * files (IAM_CONFIG_DIR) and one of storage (IAM_STORAGE_DIR), holding a random
 * master key, the development issuer and mail written to files. Only the
 * database server is borrowed from this machine's config/database.local.php,
 * which setup.php writes - and its password is not copied: the run's own
 * database.local.php requires that file and changes the database name.
 */

declare(strict_types=1);

use Anotoki\Lib\Migrations\MigrationSet;

const TEST_ISSUER = 'http://localhost:4300';

/** The name of this run's database, and the folders it writes to. */
function testEnvironment(): array
{
    static $environment = null;

    if ($environment === null) {
        $suffix = getmypid() . '_' . bin2hex(random_bytes(3));
        $base = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'iam-test-' . $suffix;
        $environment = [
            'db' => 'iam_test_' . $suffix,
            'base' => $base,
            'config' => $base . DIRECTORY_SEPARATOR . 'config',
            'storage' => $base . DIRECTORY_SEPARATOR . 'storage',
            'mail' => $base . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'mail',
            'cache' => $base . DIRECTORY_SEPARATOR . 'cache',
        ];
    }

    return $environment;
}

/** This machine's config/database.local.php, whose server the tests make their databases on. */
function developmentDatabaseFile(): string
{
    return dirname(__DIR__) . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'database.local.php';
}

/** The PostgreSQL server the tests use: host, port and login, from that file. */
function testServer(): array
{
    static $server = null;

    if ($server === null) {
        $file = developmentDatabaseFile();
        $config = is_file($file) ? (static fn(string $path): mixed => require $path)($file) : null;

        if (!is_array($config) || !isset($config['host'], $config['username'])) {
            fwrite(STDERR, "The tests make throwaway databases on this machine's PostgreSQL and find it in config/database.local.php.\n"
                . "Run setup.php first (DEV.md).\n");
            exit(1);
        }

        $server = ['host' => $config['host'], 'port' => $config['port'] ?? '5432', 'username' => $config['username'], 'password' => $config['password'] ?? ''];
    }

    return $server;
}

/** A database this run made, to be dropped when it ends. Only iam_test_ names, so nothing else is ever dropped. */
function throwawayDatabase(string $name): string
{
    if (!str_starts_with($name, 'iam_test_')) {
        throw new LogicException("A test database must be called iam_test_..., not {$name}");
    }

    $GLOBALS['testDatabases'][$name] = true;

    return $name;
}

/** Writes one *.local.php into this run's configuration folder. */
function writeTestConfig(string $name, mixed $value): void
{
    file_put_contents(testEnvironment()['config'] . DIRECTORY_SEPARATOR . $name . '.local.php', "<?php\n\nreturn " . var_export($value, true) . ";\n");
}

/**
 * The configuration a set-up IAM has, for this run: the machine's database
 * server with this run's database on it, a random master key, the development
 * issuer, and mail written into this run's folder.
 */
function writeTestConfiguration(): void
{
    $environment = testEnvironment();

    file_put_contents($environment['config'] . DIRECTORY_SEPARATOR . 'database.local.php',
        "<?php\n\n\$config = require " . var_export(developmentDatabaseFile(), true) . ";\n"
        . "\$config['databases'] = ['iam' => " . var_export(throwawayDatabase($environment['db']), true) . "];\n"
        . "\$config['persistent'] = false;\n\nreturn \$config;\n");
    writeTestConfig('secret', bin2hex(random_bytes(32)));
    writeTestConfig('iam', ['public_url' => TEST_ISSUER]);
    writeTestConfig('mail', [
        'driver' => 'file',
        'from' => 'noreply@localhost',
        'from_name' => 'anotoki',
        'senders' => ['noreply' => ['from' => 'noreply@localhost', 'from_name' => 'anotoki'], 'support' => ['from' => 'support@localhost', 'from_name' => 'anotoki']],
        'directory' => $environment['mail'],
    ]);
}

/**
 * Loads the app against this run's own configuration and storage.
 *
 * With $configured false the configuration folder stays empty - an IAM nobody
 * has set up yet - and no database is made; the installer's tests start there.
 */
function bootTests(bool $configured = true): void
{
    $environment = testEnvironment();
    testServer();

    foreach (['config', 'storage', 'mail', 'cache'] as $folder) {
        @mkdir($environment[$folder], 0700, true);
    }

    define('IAM_CONFIG_DIR', $environment['config']);
    define('IAM_STORAGE_DIR', $environment['storage']);
    define('IAM_NO_RUN', true);

    $GLOBALS['testDatabases'] = [];
    register_shutdown_function('cleanUpTests');

    if ($configured) {
        writeTestConfiguration();
    }

    require __DIR__ . '/../api/index.php';
    require_once __DIR__ . '/../api/legacy_import.php';
    require_once __DIR__ . '/../../sdk/php/anotoki_iam.php';

    if ($configured) {
        ensureDatabase(testServer(), $environment['db']);

        $result = applyPendingMigrations(iamDb());
        if ($result['failed'] !== null) {
            fwrite(STDERR, "migration {$result['failed']} failed: {$result['error']}\n");
            exit(1);
        }
    }
}

/** Drops this run's databases and removes its folders. */
function cleanUpTests(): void
{
    $server = testServer();

    foreach (array_keys($GLOBALS['testDatabases'] ?? []) as $name) {
        try {
            $pdo = new PDO("pgsql:host={$server['host']};port={$server['port']};dbname=postgres", $server['username'], $server['password']);
            $pdo->exec('DROP DATABASE IF EXISTS "' . $name . '" WITH (FORCE)');
        } catch (\Throwable $e) {
            fwrite(STDERR, "could not drop {$name}: {$e->getMessage()}\n");
        }
    }

    removeTree(testEnvironment()['base']);
}

function removeTree(string $path): void
{
    if (is_dir($path) && !is_link($path)) {
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                removeTree($path . DIRECTORY_SEPARATOR . $entry);
            }
        }
        @rmdir($path);
    } elseif (file_exists($path) || is_link($path)) {
        @unlink($path);
    }
}

// ── Running and checking ─────────────────────────────────────────────────────

$GLOBALS['testFailures'] = 0;
$GLOBALS['testCount'] = 0;

function test(string $name, callable $body): void
{
    $GLOBALS['testCount']++;

    try {
        $body();
        echo "ok    {$name}\n";
    } catch (\Throwable $e) {
        $GLOBALS['testFailures']++;
        echo "FAIL  {$name}\n      {$e->getMessage()}\n";
        if (!$e instanceof AssertionFailed) {
            echo '      at ' . $e->getFile() . ':' . $e->getLine() . "\n";
        }
    }
}

final class AssertionFailed extends RuntimeException
{
}

function check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new AssertionFailed($message);
    }
}

function same(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new AssertionFailed($message . ' - expected ' . json_encode($expected) . ', got ' . json_encode($actual));
    }
}

function finishTests(): never
{
    $failures = $GLOBALS['testFailures'];
    echo "\n{$GLOBALS['testCount']} tests, " . ($failures === 0 ? 'all passed' : "$failures failed") . "\n";
    exit($failures === 0 ? 0 : 1);
}

// ── A browser ────────────────────────────────────────────────────────────────

final class TestResponse
{
    public function __construct(public int $status, public array $headers, public string $body)
    {
    }

    public function json(): mixed
    {
        return json_decode($this->body, true);
    }

    public function header(string $name): string
    {
        foreach ($this->headers as $key => $values) {
            if (strcasecmp($key, $name) === 0) {
                return implode(', ', $values);
            }
        }
        return '';
    }

    public function location(): string
    {
        return $this->header('Location');
    }

    public function code(): ?string
    {
        return $this->json()['code'] ?? $this->json()['error'] ?? null;
    }
}

/**
 * One browser: its own cookie jar, its own address. First-party writes carry
 * the CSRF header unless a test says otherwise, as the IAM's pages do.
 */
final class Browser
{
    public array $cookies = [];

    public function __construct(public string $ip = '127.0.0.1')
    {
    }

    /**
     * The request this browser sends, built as production builds it: the jar
     * as one Cookie header, and the cookie params parsed from that header by
     * Slim's own parser - as createFromGlobals() does - so what a header
     * carries (a name that only decodes to another, a name twice) reaches the
     * app as it would on the host. A test may send a Cookie header of its own
     * (the 'headers' option), which then is all the browser sends.
     */
    public function serverRequest(string $method, string $path, array $options = []): \Psr\Http\Message\ServerRequestInterface
    {
        $uri = (new \Slim\Psr7\Factory\UriFactory())->createUri(TEST_ISSUER . $path);
        $request = (new \Slim\Psr7\Factory\ServerRequestFactory())->createServerRequest($method, $uri, ['REMOTE_ADDR' => $this->ip]);
        $streams = new \Slim\Psr7\Factory\StreamFactory();

        if (isset($options['json'])) {
            $request = $request->withHeader('Content-Type', 'application/json')->withBody($streams->createStream((string) json_encode($options['json'])));
        } elseif (isset($options['form'])) {
            $request = $request->withHeader('Content-Type', 'application/x-www-form-urlencoded')->withBody($streams->createStream(http_build_query($options['form'])));
        } elseif (isset($options['body'])) {
            // Bytes as they are - a body that is no JSON, or none - with whatever Content-Type the test names.
            $request = $request->withBody($streams->createStream((string) $options['body']));
        }

        $firstParty = str_starts_with($path, '/api/') && !str_starts_with($path, '/api/oidc/');
        if ($firstParty && !in_array($method, ['GET', 'HEAD'], true) && ($options['csrf'] ?? true)) {
            $request = $request->withHeader('X-IAM-Request', '1');
        }

        if ($this->cookies !== []) {
            $pairs = array_map(static fn(string|int $name, string $value): string => $name . '=' . $value, array_keys($this->cookies), $this->cookies);
            $request = $request->withHeader('Cookie', implode('; ', $pairs));
        }

        foreach ($options['headers'] ?? [] as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        return $request->withCookieParams(\Slim\Psr7\Cookies::parseHeader($request->getHeader('Cookie')));
    }

    public function request(string $method, string $path, array $options = []): TestResponse
    {
        $response = buildIamApp()->handle($this->serverRequest($method, $path, $options));

        foreach ($response->getHeader('Set-Cookie') as $cookie) {
            [$pair] = explode(';', $cookie, 2);
            [$name, $value] = explode('=', $pair, 2);
            if (str_contains(strtolower($cookie), 'max-age=0') || $value === '') {
                unset($this->cookies[$name]);
            } else {
                $this->cookies[$name] = $value;
            }
        }

        return new TestResponse($response->getStatusCode(), $response->getHeaders(), (string) $response->getBody());
    }

    public function get(string $path, array $options = []): TestResponse
    {
        return $this->request('GET', $path, $options);
    }

    public function post(string $path, array $json = [], array $options = []): TestResponse
    {
        return $this->request('POST', $path, ['json' => $json] + $options);
    }

    public function put(string $path, array $json = [], array $options = []): TestResponse
    {
        return $this->request('PUT', $path, ['json' => $json] + $options);
    }

    public function delete(string $path, array $options = []): TestResponse
    {
        return $this->request('DELETE', $path, $options);
    }

    public function signIn(string $email, string $password, array $extra = []): TestResponse
    {
        return $this->post('/api/login/password', ['email' => $email, 'password' => $password] + $extra);
    }
}

// ── Fixtures ─────────────────────────────────────────────────────────────────

function makeUser(string $username, string $email, string $password = 'correct horse', bool $confirmed = true): int
{
    $insert = iamDb()->prepare('INSERT INTO users (username, email, password, email_confirmed) VALUES (?, ?, ?, ?) RETURNING id');
    $insert->execute([$username, $email, password_hash($password, PASSWORD_DEFAULT), $confirmed ? 'true' : 'false']);

    return (int) $insert->fetchColumn();
}

function grantRole(int $userId, string $clientId, string $role): void
{
    iamDb()->prepare(
        'INSERT INTO user_roles (user_id, role_id)
         SELECT ?, r.id FROM application_roles r JOIN applications a ON a.id = r.application_id WHERE a.client_id = ? AND r.name = ?
         ON CONFLICT DO NOTHING'
    )->execute([$userId, $clientId, $role]);
}

/** The client ids of every application that has an ADMIN role, in order. */
function applicationsWithAdmin(PDO $pdo): array
{
    return $pdo->query(
        "SELECT a.client_id FROM application_roles r JOIN applications a ON a.id = r.application_id WHERE r.name = 'ADMIN' ORDER BY a.client_id"
    )->fetchAll(PDO::FETCH_COLUMN);
}

/** The client ids of the applications whose ADMIN this account holds, in order. */
function adminOf(PDO $pdo, int $userId): array
{
    $statement = $pdo->prepare(
        "SELECT a.client_id FROM user_roles ur JOIN application_roles r ON r.id = ur.role_id JOIN applications a ON a.id = r.application_id
          WHERE ur.user_id = ? AND r.name = 'ADMIN' ORDER BY a.client_id"
    );
    $statement->execute([$userId]);

    return $statement->fetchAll(PDO::FETCH_COLUMN);
}

/** Every first-party site, as migrations 002 and 005 register them. */
const FIRST_PARTY_SITES = ['build-analyzer', 'genshin', 'japanese-academy', 'piano-academy', 'survey'];

// ── The migrations, through the IAM's engine ─────────────────────────────────
//
// The engine is the anotoki family's (anotoki/lib's Migrator, iamMigrator()),
// which replaced the IAM's own. The tests were written against the names the
// IAM's engine had, and keep them: each is a few lines over iamMigrator(), so
// a test builds its databases exactly as the command line, the installer and
// the admin panel do, and reads as it did. Answers carry the files by the
// IAM's names for them (iamMigrationLabel()): one of migrations/iam by its
// name ('016_x.sql'), one of the library's translations set, which runs after
// them, as `set/name` ('anotoki_translations/001_languages_and_strings.sql')
// - the engine's own are {set, name} pairs.

/** The engine's answer to an apply, as the tests read it: the files' names, and the failed one's. */
function migrationResult(array $result): array
{
    if ($result['busy']) {
        throw new LogicException('Another apply holds the migrations lock');
    }

    return ['applied' => migrationNames($result['applied']), 'failed' => $result['failed'] === null ? null : migrationNames([$result['failed']])[0], 'error' => $result['error']];
}

/** The names of files as the engine and the admin routes list them ({set, name, ...} each). */
function migrationNames(array $files): array
{
    return array_map(static fn(array $file): string => iamMigrationLabel($file['set'], $file['name']), $files);
}

/**
 * The files, in the order they run: every set's (the IAM's, then the
 * library's translations set), or with $set = 'iam' the IAM's own alone.
 */
function migrationFiles(?string $set = null): array
{
    // The engine lists them; it needs a connection to exist, not to list.
    static $server = null;
    $server ??= connectDatabase(testServer(), 'postgres', false);

    return $set === null ? iamMigrationFiles(iamMigrator($server)) : iamMigrator($server)->files(iamMigrationSet());
}

/** Every file the database records as applied - one whose file is gone too, as the table holds it. */
function appliedMigrations(PDO $pdo): array
{
    $status = iamMigrator($pdo)->status();

    return migrationNames(array_merge($status['applied'], $status['missing']));
}

/** The files the database lacks, in the order they would run. */
function pendingMigrations(PDO $pdo, string $set = 'iam'): array
{
    return migrationNames(iamMigrator($pdo)->status()['pending']);
}

/** The bookkeeping table, made where it is missing - as an apply makes it. */
function ensureMigrationsTable(PDO $pdo): void
{
    iamMigrator($pdo)->ensureTable();
}

/**
 * Applies every pending file, stopping at the first that fails.
 *
 * @return array{applied: string[], failed: ?string, error: ?string}
 */
function applyPendingMigrations(PDO $pdo, string $set = 'iam'): array
{
    return migrationResult(iamMigrator($pdo)->apply());
}

/**
 * Applies what is pending up to and including $lastFile, and nothing after
 * it, the way the engine applies each file: the files after it are drafts to
 * the engine (its `ready` hook), which it never applies. A migration's own
 * test asserts the state its file leaves - "apply what is pending" would apply
 * every later file too, and a later one (010's MEMBER for everyone) rewrite it.
 * "After" is in the order the files run: through one of migrations/iam, none
 * of the library's translations set, which comes after them all.
 *
 * @return array{applied: string[], failed: ?string, error: ?string}
 */
function applyMigrationsThrough(PDO $pdo, string $lastFile): array
{
    $order = migrationFiles();
    $last = array_search($lastFile, $order, true);
    if ($last === false) {
        throw new LogicException("There is no migration {$lastFile}");
    }

    $migrator = iamMigrator($pdo, ['ready' => static function (MigrationSet $set, string $name) use ($order, $last): bool {
        $at = array_search(iamMigrationLabel($set->name, $name), $order, true);

        return $at !== false && $at <= $last;
    }]);

    return migrationResult($migrator->apply());
}

/**
 * Applies one file, the next one pending, whole or not at all; throws when it
 * fails - or when it is not the next one, which the engine would not apply on
 * its own either.
 */
function applyMigration(PDO $pdo, string $set, string $file): void
{
    $result = applyMigrationsThrough($pdo, $file);

    if ($result['failed'] !== null) {
        throw new RuntimeException("{$result['failed']}: {$result['error']}");
    }
    if ($result['applied'] !== [$file]) {
        throw new LogicException("{$file} is not the next migration to apply - applied " . json_encode($result['applied']));
    }
}

/**
 * A throwaway IAM built from the migrations up to and including $lastFile -
 * an installed IAM as it was before what came after. The name is this run's
 * database's plus a suffix, so it is dropped with it.
 */
function buildIamThrough(string $suffix, string $lastFile): PDO
{
    $name = throwawayDatabase(testEnvironment()['db'] . $suffix);
    ensureDatabase(testServer(), $name);
    $pdo = connectDatabase(testServer(), $name, false);

    $result = applyMigrationsThrough($pdo, $lastFile);
    same(null, $result['failed'], "built through {$lastFile} (" . ($result['error'] ?? '') . ')');

    return $pdo;
}

/**
 * A throwaway copy of the genshin site's old accounts database, in the shape
 * its migrations leave it, empty. The name is this run's database's plus a
 * suffix, so it is dropped with the rest.
 */
function makeLegacyDatabase(string $suffix = '_legacy'): PDO
{
    $name = throwawayDatabase(testEnvironment()['db'] . $suffix);
    ensureDatabase(testServer(), $name);

    $pdo = legacyConnect(testServer(), $name);
    $pdo->exec(<<<SQL
        CREATE TABLE users (
            id SERIAL PRIMARY KEY, role VARCHAR(50) NOT NULL DEFAULT 'USER', username VARCHAR(100) NOT NULL UNIQUE,
            email VARCHAR(255) NOT NULL UNIQUE, email_confirmed BOOLEAN NOT NULL DEFAULT FALSE, password VARCHAR(255),
            background VARCHAR(100), language VARCHAR(10) NOT NULL DEFAULT 'en', deleted BOOLEAN NOT NULL DEFAULT FALSE,
            version VARCHAR(10), created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP,
            theme_main VARCHAR(50) NOT NULL DEFAULT 'auto', theme_admin VARCHAR(50) NOT NULL DEFAULT 'auto',
            password_login_enabled BOOLEAN NOT NULL DEFAULT TRUE, totp_secret VARCHAR(64), totp_enabled BOOLEAN NOT NULL DEFAULT FALSE,
            date_format VARCHAR(16), time_format VARCHAR(8), force_password_change BOOLEAN NOT NULL DEFAULT FALSE
        );
        CREATE TABLE user_identities (id SERIAL PRIMARY KEY, user_id INT NOT NULL, provider VARCHAR(32) NOT NULL, subject VARCHAR(255) NOT NULL, email VARCHAR(255), created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP);
        CREATE TABLE user_recovery_codes (id SERIAL PRIMARY KEY, user_id INT NOT NULL, code_hash CHAR(64) NOT NULL, used_at TIMESTAMP, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP);
        CREATE TABLE user_sessions (id SERIAL PRIMARY KEY, user_id INT NOT NULL, token_id CHAR(32) NOT NULL, method VARCHAR(16) NOT NULL, ip VARCHAR(45), user_agent VARCHAR(255), created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, last_seen_at TIMESTAMP, expires_at TIMESTAMP NOT NULL, revoked_at TIMESTAMP, revoked_reason VARCHAR(32));
        CREATE TABLE user_login_attempts (id SERIAL PRIMARY KEY, email VARCHAR(255) NOT NULL, user_id INT, ip VARCHAR(45), method VARCHAR(16) NOT NULL, outcome VARCHAR(24) NOT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP);
        CREATE TABLE email_log (id SERIAL PRIMARY KEY, recipient VARCHAR(320) NOT NULL, kind VARCHAR(64) NOT NULL, subject VARCHAR(255), status VARCHAR(16) NOT NULL, error TEXT, user_id INT, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP);
        SQL);

    return $pdo;
}

/** A PKCE pair: [verifier, challenge]. */
function pkce(): array
{
    $verifier = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');

    return [$verifier, rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=')];
}

/** The query parameters of a URL. */
function queryOf(string $url): array
{
    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

    return $query;
}

/** The newest message to an address: ['subject', 'body']. */
function lastMailTo(string $email): ?array
{
    $files = glob(testEnvironment()['mail'] . DIRECTORY_SEPARATOR . '*.eml') ?: [];
    sort($files);

    foreach (array_reverse($files) as $file) {
        $text = (string) file_get_contents($file);
        if (str_contains($text, "To: $email\n")) {
            [$head, $body] = explode("\n\n", $text, 2);
            preg_match('/^Subject: (.*)$/m', $head, $subject);
            return ['subject' => $subject[1] ?? '', 'body' => $body];
        }
    }

    return null;
}

/** Verifies an access token the way an application would, with the SDK and the published keys. */
function verifyLikeAnApp(Browser $browser, string $token, string $clientId): ?array
{
    $jwks = testEnvironment()['cache'] . DIRECTORY_SEPARATOR . 'jwks.json';
    file_put_contents($jwks, $browser->get('/.well-known/jwks.json')->body);

    return anotoki_iam_verify_access_token($token, ['issuer' => TEST_ISSUER, 'client_id' => $clientId, 'jwks_file' => $jwks]);
}
