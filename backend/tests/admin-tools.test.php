<?php

/**
 * The admin panel's tools from the anotoki family's library (anotoki-lib 0.4,
 * api/admin_tools.php and api/audit.php): the audit log, the error log and
 * backups - for the IAM's ADMIN alone, behind the session, the IAM's ADMIN and
 * the CSRF header on every write, with the library's own ADMIN check inside.
 *
 * What each keeps, and what it never keeps: the audit log what it always kept
 * (who, what, to what, the details, the address, the time), scrubbed by the
 * IAM's rules when an account is deleted; the error log no secret, token,
 * cookie, header, body or query string - the IAM's own names of secrets
 * included; a backup nothing in the clear, under a key derived from the master
 * key, made, downloaded and deleted only with the proof the account page asks
 * for, and never restored here.
 */

declare(strict_types=1);

require __DIR__ . '/lib.php';

use Anotoki\Lib\Backups\Backups;
use Anotoki\Lib\Migrations\Migrator;
use Anotoki\Lib\Support\AfterResponse;
use Anotoki\Lib\Support\Keys;
use Anotoki\Lib\Support\Redactor;

// This run's own database is a fresh IAM, built from every migration.
bootTests();

const PASSWORD = 'correct horse';

/** A value of a secret's alphabet with no digit in it - one random secret in a few hundred is: no token-like run to the library's redactor. */
function lettersOnly(int $length = 43): string
{
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz';
    $out = '';
    for ($i = 0; $i < $length; $i++) {
        $out .= $alphabet[random_int(0, strlen($alphabet) - 1)];
    }

    return $out;
}

// Two routes of the tests' own, before any request is routed: one that fails with secrets in its message,
// one that warns with one - as code that might is answered.
$GLOBALS['planted'] = [
    'cookie' => lettersOnly(), 'refresh' => lettersOnly(), 'password' => lettersOnly(16), 'device' => lettersOnly(),
    'totp' => 'JBSWYDPEHPKPXPJBSWYDPEHPKPXPABCD', 'code' => lettersOnly(), 'master' => bin2hex(random_bytes(32)),
    'jwt' => 'eyJhbGciOiJSUzI1NiJ9.eyJzdWIiOiIxIn0.' . lettersOnly(20), 'warned' => lettersOnly(),
    'recovery' => lettersOnly(), 'personal' => lettersOnly(), 'digits' => '904761',
];
buildIamApp()->get('/api/test/fails', function (): never {
    $p = $GLOBALS['planted'];
    throw new RuntimeException("Cookie: a=b; anotoki_iam={$p['cookie']}; presented iam_rt_{$p['refresh']} again; current_password={$p['password']}; "
        . "device_token: \"{$p['device']}\"; totp_secret={$p['totp']}; code={$p['code']}; key {$p['master']}; Authorization: Bearer {$p['jwt']}; "
        . 'for person@example.test from 203.0.113.9');
});
// And one that fails for another failure, as the library's own wrappers (a backup's, a restore's) throw: each
// cause a secret of its own, which since anotoki-lib 0.5.0 the error log's line writes too.
buildIamApp()->get('/api/test/fails-chained', function (): never {
    $p = $GLOBALS['planted'];
    $root = new PDOException("the root: new_password={$p['password']} recovery_code={$p['recovery']} code={$p['digits']} presented iam_rt_{$p['refresh']} again");
    $between = new RuntimeException("in between: iam_pat_{$p['personal']} and the session __Host-anotoki_iam={$p['cookie']}", 0, $root);
    throw new RuntimeException('A backup could not be made.', 0, $between);
});
buildIamApp()->get('/api/test/warns', function ($request, $response) {
    $display = ini_set('display_errors', '0');
    $log = ini_set('log_errors', '0');
    trigger_error('the device_token=' . $GLOBALS['planted']['warned'] . ' was seen', E_USER_WARNING);
    ini_set('display_errors', (string) $display);
    ini_set('log_errors', (string) $log);

    return respondJson($response, ['ok' => true]);
});

$adminId = makeUser('admin', 'admin@example.test');
grantRole($adminId, 'iam', 'ADMIN');
$secondId = makeUser('second', 'second@example.test');
grantRole($secondId, 'iam', 'ADMIN');
$memberId = makeUser('member', 'member@example.test');
$siteAdminId = makeUser('siteadmin', 'siteadmin@example.test');
grantRole($siteAdminId, 'genshin', 'ADMIN');
grantRole($siteAdminId, 'genshin', 'EDITOR');

/** A browser signed in with a password, from an address of its own. */
function signedIn(string $email, string $ip): Browser
{
    $browser = new Browser($ip);
    $answer = $browser->signIn($email, PASSWORD);
    same(200, $answer->status, "$email signs in: " . $answer->body);

    return $browser;
}

/** The error log's lines, every file of it, decoded. */
function errorLines(): array
{
    $lines = [];
    foreach (glob(iamStorageDir() . '/logs/errors-*.jsonl') ?: [] as $file) {
        foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $lines[] = json_decode($line, true);
        }
    }

    return $lines;
}

/** Everything the error log's folder holds, as one text. */
function errorLogText(): string
{
    $text = '';
    foreach (glob(iamStorageDir() . '/logs/*') ?: [] as $file) {
        $text .= is_file($file) ? (string) file_get_contents($file) : '';
    }

    return $text;
}

/** The newest audit entry of an action: [actor_id, target_type, target_id, details, ip]. */
function lastEntry(string $action): ?array
{
    $statement = iamDb()->prepare('SELECT actor_id, target_type, target_id, details, ip FROM anotoki_audit_log WHERE action = ? ORDER BY id DESC LIMIT 1');
    $statement->execute([$action]);
    $row = $statement->fetch();

    return $row === false ? null : [$row['actor_id'] === null ? null : (int) $row['actor_id'], $row['target_type'], $row['target_id'], json_decode((string) $row['details'], true), $row['ip']];
}

/** The backups' folder. */
function backupsDir(): string
{
    return iamStorageDir() . '/backups';
}

/** A request whose attributes say who it is, as requireSession() leaves one. */
function requestOf(?array $user): \Psr\Http\Message\ServerRequestInterface
{
    $request = (new \Slim\Psr7\Factory\ServerRequestFactory())->createServerRequest('GET', TEST_ISSUER . '/api/admin/backups', ['REMOTE_ADDR' => '10.42.9.9']);

    return $user === null ? $request : $request->withAttribute('user', $user);
}

// A backup to point the routes at, made as the command line makes one.
$fixture = iamBackups()->create(Backups::MANUAL, 'the tests\' fixture');
check(is_array($fixture), 'the fixture backup is made: ' . (is_array($fixture) ? '' : $fixture->message));
$GLOBALS['fixtureId'] = $fixture['id'];

// ─────────────────────────────────────────────────────────────────────────────

test('who may: nobody 401, an account or a site\'s ADMIN and EDITOR 403, an administrator on the way out 403 - the IAM\'s ADMIN alone, every write with the CSRF header', function () {
    global $secondId, $adminId, $memberId;
    $id = $GLOBALS['fixtureId'];
    $routes = [
        ['GET', '/api/admin/audit'], ['GET', '/api/admin/audit/facets'],
        ['GET', '/api/admin/errors'], ['GET', '/api/admin/errors/0123456789ab'], ['DELETE', '/api/admin/errors'],
        ['GET', '/api/admin/backups'], ['GET', '/api/admin/backups/proof'], ['POST', '/api/admin/backups'],
        ['GET', "/api/admin/backups/$id"], ['DELETE', "/api/admin/backups/$id"], ['POST', "/api/admin/backups/$id/ticket"],
        ['GET', "/api/admin/backups/$id/preview"], ['POST', "/api/admin/backups/$id/restore"], ['GET', "/api/admin/backups/$id/file?ticket=x"],
    ];
    $call = static fn(Browser $browser, string $method, string $path, array $options = []): TestResponse
        => $browser->request($method, $path, ['json' => ['password' => PASSWORD, 'confirm' => substr($id, -6)]] + $options);

    $nobody = new Browser('10.42.1.1');
    $member = signedIn('member@example.test', '10.42.1.2');
    $siteAdmin = signedIn('siteadmin@example.test', '10.42.1.3');
    $leaving = signedIn('second@example.test', '10.42.1.4');
    iamDb()->prepare("UPDATE users SET deletion_requested_at = CURRENT_TIMESTAMP, deletion_due_at = CURRENT_TIMESTAMP + INTERVAL '30 days' WHERE id = ?")->execute([$secondId]);
    try {
        foreach ($routes as [$method, $path]) {
            same([401, 'no_session'], [($r = $call($nobody, $method, $path))->status, $r->code()], "nobody: $method $path");
            same([403, 'forbidden'], [($r = $call($member, $method, $path))->status, $r->code()], "an account: $method $path");
            same([403, 'forbidden'], [($r = $call($siteAdmin, $method, $path))->status, $r->code()], "a site's ADMIN and EDITOR: $method $path");
            same([403, 'account_deletion_pending'], [($r = $call($leaving, $method, $path))->status, $r->code()], "an administrator whose deletion is pending: $method $path");
        }
    } finally {
        iamDb()->prepare('UPDATE users SET deletion_requested_at = NULL, deletion_due_at = NULL WHERE id = ?')->execute([$secondId]);
    }

    $admin = signedIn('admin@example.test', '10.42.1.5');
    foreach ($routes as [$method, $path]) {
        if (in_array($method, ['GET', 'HEAD'], true)) {
            continue;
        }
        same([403, 'csrf'], [($r = $call($admin, $method, $path, ['csrf' => false]))->status, $r->code()], "without the CSRF header: $method $path");
        same([403, 'csrf'], [($r = $call($admin, $method, $path, ['headers' => ['Origin' => 'https://elsewhere.example.test']]))->status, $r->code()], "from another origin: $method $path");
    }
    same(true, iamBackups()->get($id) !== null, 'and the backup is still there: nothing refused did anything');
    foreach (['/api/admin/audit', '/api/admin/audit/facets', '/api/admin/errors', '/api/admin/backups', '/api/admin/backups/proof', "/api/admin/backups/$id", "/api/admin/backups/$id/preview"] as $path) {
        same(200, ($r = $admin->get($path))->status, "the IAM's ADMIN: GET $path - " . substr($r->body, 0, 200));
    }

    // The library's own check inside each group, the IAM's rule: should a group ever be mounted outside the IAM's, it still lets nobody in.
    $context = iamAdminContext();
    same([401, 'sign_in_required'], [($r = $context->adminRefusal(requestOf(null)))?->status, $r?->code], 'the library\'s guard: nobody');
    same([403, 'forbidden'], [($r = $context->adminRefusal(requestOf(userById(iamDb(), $memberId))))?->status, $r?->code], 'an account');
    iamDb()->prepare("UPDATE users SET deletion_requested_at = CURRENT_TIMESTAMP, deletion_due_at = CURRENT_TIMESTAMP + INTERVAL '30 days' WHERE id = ?")->execute([$secondId]);
    same(403, $context->adminRefusal(requestOf(userById(iamDb(), $secondId)))?->status, 'an administrator whose deletion is pending');
    iamDb()->prepare('UPDATE users SET deletion_requested_at = NULL, deletion_due_at = NULL WHERE id = ?')->execute([$secondId]);
    same(null, $context->adminRefusal(requestOf(userById(iamDb(), $adminId))), 'the IAM\'s ADMIN');
});

test('the audit log: what it always kept, in the library\'s table - the IAM\'s answer at /api/admin/audit, with its filters and the page\'s choices', function () {
    global $adminId, $memberId;
    $admin = signedIn('admin@example.test', '10.42.2.1');
    same(200, $admin->put("/api/admin/users/{$memberId}", ['force_password_change' => true])->status, 'an administrator\'s change');
    same([$adminId, 'user', (string) $memberId, ['force_password_change'], '10.42.2.1'], lastEntry('user.updated'), 'recorded: who, to what, the details, the address');

    $page = $admin->get('/api/admin/audit');
    same([200, 1, 50], [$page->status, $page->json()['page'] ?? null, $page->json()['pageSize'] ?? null], 'the first page, 50 a page');
    $item = $page->json()['items'][0] ?? [];
    same(['id', 'actor_id', 'actor_username', 'action', 'target_type', 'target_id', 'details', 'ip', 'created_at', 'actor_session', 'scope_type', 'scope_id'],
        array_values(array_intersect(['id', 'actor_id', 'actor_username', 'action', 'target_type', 'target_id', 'details', 'ip', 'created_at', 'actor_session', 'scope_type', 'scope_id'], array_keys($item))),
        'the IAM\'s AuditEntry, and the library\'s three beside it');
    same([$adminId, 'admin', 'user.updated', 'user', (string) $memberId, ['force_password_change'], '10.42.2.1', null, null, null],
        [$item['actor_id'], $item['actor_username'], $item['action'], $item['target_type'], $item['target_id'], $item['details'], $item['ip'], $item['actor_session'], $item['scope_type'], $item['scope_id']], 'the newest first');
    check(preg_match('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d\.\d{3}Z$/', (string) $item['created_at']) === 1, 'its time in UTC, ISO 8601: ' . $item['created_at']);

    $filtered = static fn(array $query): array => $admin->get('/api/admin/audit?' . http_build_query($query))->json();
    check(in_array('user.updated', array_column($filtered(['actor' => 'ADM'])['items'], 'action'), true), 'by a part of the name, any case');
    same([$adminId], array_values(array_unique(array_column($filtered(['actor' => (string) $adminId])['items'], 'actor_id'))), 'by the id');
    same(0, $filtered(['actor' => 'nobody of that name'])['total'], 'by a name nobody has: none');
    same(['user.'], array_values(array_unique(array_map(static fn(string $action): string => substr($action, 0, 5), array_column($filtered(['action' => 'user.'])['items'], 'action')))), 'an action by its beginning');
    same(0, $filtered(['action' => 'user%'])['total'], 'a wildcard is a character like any other');
    same(['user'], array_values(array_unique(array_column($filtered(['target_type' => 'user'])['items'], 'target_type'))), 'what it is about');
    same(0, $filtered(['from' => gmdate('Y-m-d', time() + 86400)])['total'], 'from tomorrow: none');

    $facets = $admin->get('/api/admin/audit/facets')->json();
    check(in_array('user.updated', $facets['actions'] ?? [], true) && in_array(['id' => $adminId, 'username' => 'admin'], $facets['actors'] ?? [], true), 'the page\'s choices: the actions, and who by name');
});

test('audit() never undoes the change it records: an entry the database refuses leaves the caller\'s transaction as it was, and nothing is thrown', function () {
    $pdo = iamDb();
    $pdo->exec("CREATE FUNCTION test_refuse_entry() RETURNS trigger LANGUAGE plpgsql AS \$\$ BEGIN RAISE EXCEPTION 'refused'; END \$\$");
    $pdo->exec("CREATE TRIGGER test_refuse_entry BEFORE INSERT ON anotoki_audit_log FOR EACH ROW WHEN (NEW.action = 'test.refused') EXECUTE FUNCTION test_refuse_entry()");
    try {
        $before = (int) $pdo->query('SELECT count(*) FROM anotoki_audit_log')->fetchColumn();
        $pdo->beginTransaction();
        $pdo->exec("INSERT INTO login_attempts (email, method, outcome) VALUES ('the-change@example.test', 'test', 'test')");
        audit(null, null, 'test.refused');
        audit(null, null, 'Not An Action');
        $pdo->exec("UPDATE login_attempts SET outcome = 'still-usable' WHERE email = 'the-change@example.test'");
        $pdo->commit();
        same('still-usable', $pdo->query("SELECT outcome FROM login_attempts WHERE email = 'the-change@example.test'")->fetchColumn(), 'the change committed, the transaction usable after the refusal');
        same($before, (int) $pdo->query('SELECT count(*) FROM anotoki_audit_log')->fetchColumn(), 'and neither entry was kept');
    } finally {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $pdo->exec("DELETE FROM login_attempts WHERE email = 'the-change@example.test'");
        $pdo->exec('DROP TRIGGER test_refuse_entry ON anotoki_audit_log');
        $pdo->exec('DROP FUNCTION test_refuse_entry()');
    }
});

test('a deleted account\'s person out of the audit log, by the IAM\'s rules - in the library\'s table and in the old one 018 keeps', function () {
    global $adminId;
    $pdo = iamDb();
    $gone = makeUser('gone', 'gone@example.test');
    $other = makeUser('other', 'other@example.test');
    $from = static fn(string $ip): \Psr\Http\Message\ServerRequestInterface => (new \Slim\Psr7\Factory\ServerRequestFactory())->createServerRequest('POST', TEST_ISSUER . '/api/account', ['REMOTE_ADDR' => $ip]);

    // The same history in both tables: by the person, about them by nobody and by an administrator, their token, and somebody else's.
    $history = [
        [$gone, 'account.username_changed', 'user', $gone, ['from' => 'gone', 'to' => 'gone2'], '192.0.2.1'],
        [null, 'account.created', 'user', $gone, ['username' => 'gone', 'email' => 'gone@example.test'], '192.0.2.2'],
        [$adminId, 'user.updated', 'user', $gone, ['username' => 'gone', 'kept' => 'yes'], '198.51.100.1'],
        [$gone, 'account.token_created', 'refresh_token', 17, ['name' => 'my script', 'days' => 30], '192.0.2.3'],
        [$other, 'account.username_changed', 'user', $other, ['from' => 'other', 'to' => 'other2'], '192.0.2.4'],
    ];
    foreach ($history as [$actor, $action, $type, $target, $details, $ip]) {
        audit($from($ip), $actor, $action, $type, $target, $details);
        $pdo->prepare('INSERT INTO audit_log_before_018 (actor_id, action, target_type, target_id, details, ip) VALUES (?, ?, ?, ?, CAST(? AS jsonb), ?)')
            ->execute([$actor, $action, $type, (string) $target, json_encode($details), $ip]);
    }

    scrubAuditLog($pdo, [$gone]);

    foreach (['anotoki_audit_log', 'audit_log_before_018'] as $table) {
        $rows = $pdo->query("SELECT actor_id, action, target_id, details, ip FROM {$table} WHERE target_id IN ('{$gone}', '{$other}', '17') ORDER BY id")->fetchAll();
        $seen = array_map(static function (array $row): array {
            $details = (array) json_decode((string) $row['details'], true);
            ksort($details);

            return [$row['action'], $details, $row['ip']];
        }, array_slice($rows, -5));
        same([
            ['account.username_changed', [], null],
            ['account.created', [], null],
            ['user.updated', ['kept' => 'yes'], '198.51.100.1'],
            ['account.token_created', ['days' => 30], null],
            ['account.username_changed', ['from' => 'other', 'to' => 'other2'], '192.0.2.4'],
        ], $seen, "$table: the person's own address gone, and the one by nobody about them; their names out of what was about them; their token's name - an administrator's address, and the rest of the details, and somebody else's entries as they were");
    }
});

test('the error log: a failure written with its status and code, a trace without arguments - and no secret, cookie, header, query string or address in it, the IAM\'s own names included', function () {
    $planted = $GLOBALS['planted'];
    $session = randomSecret();
    $browser = new Browser('10.42.3.1');
    $failed = $browser->get('/api/test/fails?ticket=' . lettersOnly() . '&token=ThePlantedQueryValue', ['headers' => [
        'Cookie' => "anotoki_iam={$session}; anotoki_iam_device=" . lettersOnly(),
        'Authorization' => 'Bearer ' . $planted['jwt'],
        'X-Planted' => 'ThePlantedHeaderValue',
    ]]);
    same([500, 'server_error'], [$failed->status, $failed->code()], 'the IAM\'s own answer');
    same(200, $browser->get('/api/test/warns')->status, 'a warning answers as ever');
    same(404, $browser->get('/api/no/such/route?code=' . lettersOnly())->status, 'a 404');

    $lines = errorLines();
    $failure = array_values(array_filter($lines, static fn(array $line): bool => ($line['route'] ?? null) === '/api/test/fails'))[0] ?? null;
    check($failure !== null, 'the failure is written');
    same(['server', 'error', 500, 'RuntimeException', 'server_error', 'GET', '/api/test/fails'], [$failure['source'], $failure['level'], $failure['status'], $failure['type'], $failure['code'], $failure['method'], $failure['path']], 'its facts');
    same('tests/admin-tools.test.php', $failure['file'], 'where, relative to the backend');
    check(is_array($failure['trace']) && $failure['trace'] !== [] && array_filter($failure['trace'], static fn(string $frame): bool => preg_match('/\(\)$/', $frame) !== 1) === [], 'a trace whose calls carry no arguments');
    check(preg_match('/^[0-9a-f]{12}$/', (string) $failure['client']) === 1, 'the address as a keyed hash of it');
    $warning = array_values(array_filter($lines, static fn(array $line): bool => ($line['source'] ?? null) === 'php' && str_contains((string) ($line['message'] ?? ''), 'device_token')))[0] ?? null;
    same(['warning', 'E_USER_WARNING', '/api/test/warns'], [$warning['level'] ?? null, $warning['type'] ?? null, $warning['path'] ?? null], 'the warning, with its request');
    check(in_array(404, array_column(array_filter($lines, static fn(array $line): bool => ($line['path'] ?? null) === '/api/no/such/route'), 'status'), true), 'the 404, by its path');

    $text = errorLogText();
    foreach (['session cookie' => $session, 'refresh token' => $planted['refresh'], 'current password' => $planted['password'], 'device token' => $planted['device'],
              'two-factor secret' => $planted['totp'], 'code' => $planted['code'], 'master key' => $planted['master'], 'bearer token' => $planted['jwt'],
              'cookie in the message' => $planted['cookie'], 'the warning\'s device token' => $planted['warned'], 'query string' => 'ThePlantedQueryValue',
              'header' => 'ThePlantedHeaderValue', 'e-mail address' => 'person@example.test', 'the IP in the message' => '203.0.113.9', 'the caller\'s IP' => '10.42.3.1'] as $what => $secret) {
        check(!str_contains($text, $secret), "no $what in the error log");
    }

    // The IAM's names, as the IAM takes them out before the library's redactor - which on its own would miss these, each value with no digit in it.
    foreach (["anotoki_iam={$planted['cookie']}", "__Host-anotoki_iam={$planted['cookie']}", "iam_rt_{$planted['refresh']}", "iam_pat_{$planted['refresh']}",
              "current_password={$planted['password']}", "'new_password' => '{$planted['password']}'", "device_token={$planted['device']}", "totp_secret={$planted['totp']}",
              "code: {$planted['code']}", "recovery_code={$planted['code']}"] as $shape) {
        $value = preg_replace('/^.*?(?:=>\s*\'|[=:]\s*|iam_(?:rt|pat)_)/', '', $shape);
        $value = rtrim((string) $value, "'");
        check(!str_contains(Redactor::text(iamRedactSecrets($shape)), $value), "the IAM's names: {$shape} - redacted");
    }
});

test('the IAM\'s names of secrets out of every way into the error log: an exception nothing answered, a fatal error, a failure written by the IAM itself', function () {
    $environment = testEnvironment();
    $writeDatabase = static function (string $name) use ($environment): void {
        file_put_contents($environment['config'] . DIRECTORY_SEPARATOR . 'database.local.php',
            "<?php\n\n\$config = require " . var_export(developmentDatabaseFile(), true) . ";\n"
            . "\$config['databases'] = ['iam' => " . var_export(throwawayDatabase($name), true) . "];\n"
            . "\$config['persistent'] = false;\n\nreturn \$config;\n");
        forgetLocalConfig();
    };

    // Outside the error handler: the CORS check asks the database whether the origin is a site's, and a database
    // that is not there fails it - with its name in the message. The name carries the IAM's session cookie, and
    // no digit: the library's redactor alone would keep it.
    $cookie = lettersOnly();
    $writeDatabase($environment['db'] . '.anotoki_iam=' . $cookie);
    $thrown = null;
    try {
        (new Browser('10.42.11.1'))->request('POST', '/api/oidc/token', ['form' => ['grant_type' => 'refresh_token'], 'headers' => ['Origin' => 'http://localhost:4200']]);
    } catch (\Throwable $e) {
        $thrown = $e;
    } finally {
        $writeDatabase($environment['db']);
    }
    check($thrown instanceof PDOException, 'nothing inside answered it: the exception left the IAM - ' . ($thrown === null ? 'none' : get_class($thrown)));
    $line = array_values(array_filter(errorLines(), static fn(array $line): bool => ($line['type'] ?? null) === 'PDOException' && ($line['path'] ?? null) === '/api/oidc/token'))[0] ?? null;
    check($line !== null && str_contains((string) $line['message'], 'anotoki_iam={…}'), 'written by the outer half, the cookie\'s name kept and its value out: ' . substr((string) ($line['message'] ?? ''), 0, 120));

    // A fatal error: an exception nobody caught ends the script, and the shutdown handler writes it.
    $fatal = lettersOnly();
    $script = $environment['base'] . DIRECTORY_SEPARATOR . 'fatal.php';
    file_put_contents($script, "<?php\n\n"
        . 'define(\'IAM_CONFIG_DIR\', ' . var_export($environment['config'], true) . ");\n"
        . 'define(\'IAM_STORAGE_DIR\', ' . var_export($environment['storage'], true) . ");\n"
        . "define('IAM_NO_RUN', true);\n"
        . 'require ' . var_export(dirname(__DIR__) . DIRECTORY_SEPARATOR . 'api' . DIRECTORY_SEPARATOR . 'index.php', true) . ";\n"
        . "buildIamApp();\n"
        . 'throw new RuntimeException(\'nobody caught this: device_token=' . $fatal . "');\n");
    exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script) . ' 2>&1', $output, $status);
    check($status !== 0, 'the script ended with the fatal error');
    $line = array_values(array_filter(errorLines(), static fn(array $line): bool => ($line['source'] ?? null) === 'php' && str_contains((string) ($line['message'] ?? ''), 'nobody caught this')))[0] ?? null;
    same(['error', 'E_ERROR'], [$line['level'] ?? null, $line['type'] ?? null], 'the fatal error is written');
    check(str_contains((string) $line['message'], 'device_token={…}'), 'with the IAM\'s name kept and its value out: ' . substr((string) $line['message'], 0, 120));

    // What the IAM writes itself - the day's backup failing, housekeeping, the work after the answer.
    $written = lettersOnly(32);
    iamRecordError(new RuntimeException("the daily backup failed: totp_secret={$written}"));
    $line = array_values(array_filter(errorLines(), static fn(array $line): bool => str_contains((string) ($line['message'] ?? ''), 'the daily backup failed')))[0] ?? null;
    check($line !== null && str_contains((string) $line['message'], 'totp_secret={…}'), 'written, its value out');

    $text = errorLogText();
    foreach (['the cookie in a database\'s name' => $cookie, 'the fatal error\'s token' => $fatal, 'the backup\'s secret' => $written] as $what => $secret) {
        check(!str_contains($text, $secret), "no $what in the error log");
    }
});

test('an exception thrown for another: every cause\'s message written - each without the IAM\'s names of secrets, its prefixes and its cookies, the whole chain walked', function () {
    $planted = $GLOBALS['planted'];
    $failed = (new Browser('10.42.12.1'))->get('/api/test/fails-chained');
    same([500, 'server_error'], [$failed->status, $failed->code()], 'the IAM\'s own answer');

    $line = array_values(array_filter(errorLines(), static fn(array $line): bool => ($line['route'] ?? null) === '/api/test/fails-chained'))[0] ?? null;
    check($line !== null, 'written');
    same(['RuntimeException', 'A backup could not be made.'], [$line['type'], $line['message']], 'the wrapper\'s own sentence');
    $causes = array_values(array_filter($line['trace'] ?? [], static fn(string $frame): bool => str_starts_with($frame, 'Caused by ')));
    same(2, count($causes), 'and its causes in its trace: ' . json_encode($line['trace'] ?? null));
    check(str_starts_with($causes[0], 'Caused by RuntimeException (tests/admin-tools.test.php:') && str_contains($causes[0], 'iam_pat_{…}') && str_contains($causes[0], '__Host-anotoki_iam={…}'),
        'the cause between: its personal token and its cookie out - ' . $causes[0]);
    check(str_starts_with($causes[1], 'Caused by PDOException (tests/admin-tools.test.php:')
        && str_contains($causes[1], 'new_password={…}') && str_contains($causes[1], 'recovery_code={…}') && str_contains($causes[1], 'code={…}') && str_contains($causes[1], 'iam_rt_{…}'),
        'the root: a new password, a recovery code, a code of digits and a refresh token, each out - ' . $causes[1]);
    $text = (string) json_encode($line, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    foreach (['new password' => $planted['password'], 'recovery code' => $planted['recovery'], 'code of digits' => $planted['digits'], 'refresh token' => $planted['refresh'],
              'personal token' => $planted['personal'], 'session cookie' => $planted['cookie']] as $what => $secret) {
        check(!str_contains($text, $secret), "no {$what} in the line");
    }

    // What the IAM writes itself - a daily backup's failure, the library's wrapper around COPY's - the same way.
    $written = lettersOnly();
    iamRecordError(new RuntimeException('The daily backup failed.', 0, new RuntimeException("COPY failed: iam_rt_{$written}; anotoki_iam_device={$written}; device_token={$written}")));
    $line = array_values(array_filter(errorLines(), static fn(array $line): bool => ($line['message'] ?? null) === 'The daily backup failed.'))[0] ?? null;
    $cause = array_values(array_filter($line['trace'] ?? [], static fn(string $frame): bool => str_starts_with($frame, 'Caused by ')))[0] ?? '';
    check(str_contains($cause, 'iam_rt_{…}') && str_contains($cause, 'anotoki_iam_device={…}') && str_contains($cause, 'device_token={…}'), 'its cause written, its secrets out: ' . $cause);
    check(!str_contains(errorLogText(), $written), 'nowhere in the error log');
});

test('the error log\'s own routes: never written into it; the report, a failure\'s every occurrence, and Clear - audited', function () {
    global $adminId;
    $admin = signedIn('admin@example.test', '10.42.4.1');
    $before = count(errorLines());
    same(404, $admin->get('/api/admin/errors/not-a-fingerprint')->status, 'a 404 under the log\'s own path');
    same(200, $admin->get('/api/admin/errors?days=30')->status, 'the report');
    same($before, count(errorLines()), 'neither is written into the log');

    $report = $admin->get('/api/admin/errors?days=30')->json();
    $group = array_values(array_filter($report['groups'] ?? [], static fn(array $group): bool => ($group['type'] ?? null) === 'RuntimeException'))[0] ?? null;
    check($group !== null && preg_match('/^[0-9a-f]{12}$/', (string) ($group['fingerprint'] ?? '')) === 1, 'the failure, grouped: ' . json_encode(array_keys($report)));
    $occurrences = $admin->get('/api/admin/errors/' . $group['fingerprint'] . '?days=30');
    same([200, true], [$occurrences->status, count($occurrences->json() ?? []) >= 1], 'its every occurrence');

    same([422, 'date_invalid'], [($r = $admin->delete('/api/admin/errors?before=yesterday'))->status, $r->code()], 'Clear before a date that is none');
    $cleared = $admin->delete('/api/admin/errors');
    same(200, $cleared->status, 'Clear');
    check(($cleared->json()['deleted'] ?? 0) >= 1, 'the files gone: ' . $cleared->body);
    same([], glob(iamStorageDir() . '/logs/errors-*.jsonl') ?: [], 'none left');
    same([$adminId, 'error_log', null, ['files' => $cleared->json()['deleted']], '10.42.4.1'], lastEntry('errors.cleared'), 'audited, by the administrator');
    same([true, true], [str_contains((string) @file_get_contents(iamStorageDir() . '/logs/.htaccess'), 'Require all denied'), is_file(iamStorageDir() . '/logs/index.html')], 'the log\'s folder never served');
});

test('a backup: made only with the proof - the password, counted by the limiter -, encrypted under the master key, nothing of it in the clear, audited', function () {
    global $adminId;
    $admin = signedIn('admin@example.test', '10.42.5.1');
    $status = $admin->get('/api/admin/backups')->json()['status'] ?? [];
    same([true, true, false, false, true, true], [$status['encrypted'] ?? null, $status['cipher_available'] ?? null, $status['restore_enabled'] ?? null, $status['restorable'] ?? null, $status['copy_available'] ?? null, $status['directory_writable'] ?? null],
        'encrypted, never restored here, and made with COPY');
    same(['kind' => 'password'], $admin->get('/api/admin/backups/proof')->json(), 'the proof asked: the password');

    $attempts = static fn(): int => (int) iamDb()->query("SELECT count(*) FROM login_attempts WHERE method = 'admin_backup' AND outcome = 'bad_password' AND email = 'admin@example.test'")->fetchColumn();
    $counted = $attempts();
    same([422, 'wrong_password'], [($r = $admin->request('POST', '/api/admin/backups', ['json' => new stdClass()]))->status, $r->code()], 'no password: none made');
    same([422, 'wrong_password'], [($r = $admin->post('/api/admin/backups', ['password' => 'not it']))->status, $r->code()], 'a wrong one: none made');
    same($counted + 2, $attempts(), 'each counted by the sign-in limiter');

    $made = $admin->post('/api/admin/backups', ['password' => PASSWORD, 'description' => 'before the holidays']);
    same(201, $made->status, 'made: ' . substr($made->body, 0, 300));
    $backup = $made->json()['backup'];
    same(['manual', true, $adminId, 'before the holidays', 'iam'], [$backup['kind'], $backup['encrypted'], $backup['created_by'], $backup['description'], $backup['site']], 'by hand, encrypted, by the administrator');
    check($backup['table_count'] > 20 && $backup['rows'] > 1000, 'every table: ' . $backup['table_count'] . ' tables, ' . $backup['rows'] . ' rows');
    $entry = lastEntry('backup.created');
    same([$adminId, 'backup', $backup['id'], 'manual', true, '10.42.5.1'], [$entry[0], $entry[1], $entry[2], $entry[3]['kind'] ?? null, $entry[3]['encrypted'] ?? null, $entry[4]], 'audited');
    $listed = [];
    foreach ($admin->get('/api/admin/backups')->json()['backups'] ?? [] as $each) {
        $listed[$each['id']] = [$each['created_by'] ?? null, $each['created_by_name'] ?? null];
    }
    same([$adminId, 'admin'], $listed[$backup['id']] ?? null, 'listed with its maker\'s name - the page\'s "By" - from the IAM\'s accounts');
    same([null, null], $listed[$GLOBALS['fixtureId']] ?? null, 'one the command line made: by nobody, and named nobody');

    // On the disk: the header in the clear, nothing else.
    $file = backupsDir() . '/' . $backup['id'] . '.anotoki-backup';
    $bytes = (string) file_get_contents($file);
    [$magic, $headerLine] = explode("\n", $bytes, 3);
    $header = json_decode($headerLine, true);
    same(['ANOTOKI-BACKUP 1', true, 'aes-256-gcm', 'iam'], [$magic, $header['encrypted'] ?? null, $header['cipher']['name'] ?? null, $header['site'] ?? null], 'the header says it is encrypted');
    $hash = (string) iamDb()->query("SELECT password FROM users WHERE email = 'admin@example.test'")->fetchColumn();
    foreach (['an address' => 'admin@example.test', 'a password hash' => $hash, 'a hash\'s beginning' => substr($hash, 0, 7), 'a table\'s name' => 'user_recovery_codes'] as $what => $plain) {
        check(!str_contains($bytes, $plain), "no $what in the clear in the file");
    }
    same([], glob(backupsDir() . '/.tmp-*') ?: [], 'no working folder left');
    same([true, true], [str_contains((string) @file_get_contents(backupsDir() . '/.htaccess'), 'Require all denied'), is_file(backupsDir() . '/index.html')], 'the backups\' folder never served');

    // With the master key's backup key it opens whole; with another, not at all.
    $reader = static fn(string $key): Backups => new Backups(static fn(): PDO => iamBackupConnection(), backupsDir(), [
        'site' => 'iam', 'migrator' => static fn(PDO $pdo): Migrator => iamMigrator($pdo), 'encryption' => $key, 'restore' => false,
    ]);
    $folder = testEnvironment()['base'] . DIRECTORY_SEPARATOR . 'extracted';
    $manifest = $reader(Keys::derive(iamMasterKey(), 'iam-backup'))->extractTo($backup['id'], $folder);
    $users = array_values(array_filter($manifest['tables'], static fn(array $table): bool => $table['name'] === 'users'))[0] ?? null;
    same((int) iamDb()->query('SELECT count(*) FROM users')->fetchColumn(), $users['rows'] ?? null, 'opened with the master key: every account');
    check(str_contains((string) file_get_contents($folder . '/' . $users['file']), 'admin@example.test'), 'and its rows');
    check(count(array_filter($manifest['schema']['migrations'], static fn(array $file): bool => $file['set'] === 'anotoki_admin')) === 2, 'with the migrations the database had applied');
    removeTree($folder);
    $refused = null;
    try {
        $reader(Keys::derive(random_bytes(32), 'iam-backup'))->extractTo($backup['id'], $folder);
    } catch (\Throwable $e) {
        $refused = $e;
    }
    check($refused !== null, 'with another key it opens to nothing');
    removeTree($folder);
});

test('a download: a single-use ticket, after the proof and audited, for this backup, this administrator and a minute - the file as an attachment nobody keeps', function () {
    global $adminId;
    $admin = signedIn('admin@example.test', '10.42.6.1');
    $second = signedIn('second@example.test', '10.42.6.2');
    $id = $GLOBALS['fixtureId'];
    $other = iamBackups()->create(Backups::MANUAL, 'another')['id'];

    same([422, 'wrong_password'], [($r = $admin->request('POST', "/api/admin/backups/$id/ticket", ['json' => new stdClass()]))->status, $r->code()], 'no ticket without the proof');
    $ticket = $admin->post("/api/admin/backups/$id/ticket", ['password' => PASSWORD]);
    same([200, 60], [$ticket->status, $ticket->json()['expires_in'] ?? null], 'a ticket for a minute: ' . $ticket->body);
    $url = (string) $ticket->json()['url'];
    check(preg_match('#^/api/admin/backups/' . preg_quote($id, '#') . '/file\?ticket=[A-Za-z0-9_-]{43}$#', $url) === 1, 'its link, beside the routes: ' . preg_replace('/ticket=.*/', 'ticket=…', $url));
    same([$adminId, 'backup', $id, '10.42.6.1'], [lastEntry('backup.downloaded')[0], lastEntry('backup.downloaded')[1], lastEntry('backup.downloaded')[2], lastEntry('backup.downloaded')[4]], 'audited when asked for');

    same([401, 'no_session'], [($r = (new Browser('10.42.6.3'))->get($url))->status, $r->code()], 'without a session: the session first');
    $file = $admin->get($url);
    same(200, $file->status, 'the administrator it was issued to: the file');
    same(hash_file('sha256', backupsDir() . "/$id.anotoki-backup"), hash('sha256', $file->body), 'the backup itself, byte for byte');
    same(['application/octet-stream', 'attachment; filename="iam-' . $id . '.anotoki-backup"', 'no-store', 'nosniff', 'no-referrer'],
        [$file->header('Content-Type'), $file->header('Content-Disposition'), $file->header('Cache-Control'), $file->header('X-Content-Type-Options'), $file->header('Referrer-Policy')], 'an attachment, kept nowhere');
    same([403, 'ticket_invalid'], [($r = $admin->get($url))->status, $r->code()], 'once only');

    $forAnother = (string) $admin->post("/api/admin/backups/$id/ticket", ['password' => PASSWORD])->json()['url'];
    same([403, 'ticket_invalid'], [($r = $admin->get(str_replace($id, $other, $forAnother)))->status, $r->code()], 'a ticket of one backup opens no other');
    same([403, 'ticket_invalid'], [($r = $admin->get($forAnother))->status, $r->code()], 'and is spent by the try');
    $forTheFirst = (string) $admin->post("/api/admin/backups/$id/ticket", ['password' => PASSWORD])->json()['url'];
    same([403, 'ticket_invalid'], [($r = $second->get($forTheFirst))->status, $r->code()], 'another administrator\'s session opens no ticket of the first\'s');
    same([403, 'ticket_invalid'], [($r = $admin->get($forTheFirst))->status, $r->code()], 'which is spent by the try');
});

test('deleting a backup asks the proof too, and is audited; a restore is refused here whatever is sent', function () {
    global $adminId;
    $admin = signedIn('admin@example.test', '10.42.7.1');
    $id = iamBackups()->create(Backups::MANUAL, 'to delete')['id'];

    same([422, 'wrong_password'], [($r = $admin->delete("/api/admin/backups/$id"))->status, $r->code()], 'no proof, no delete');
    same(true, is_file(backupsDir() . "/$id.anotoki-backup"), 'still there');
    $deleted = $admin->request('DELETE', "/api/admin/backups/$id", ['json' => ['password' => PASSWORD]]);
    same([200, ['deleted' => true]], [$deleted->status, $deleted->json()], 'deleted');
    same([false, false], [is_file(backupsDir() . "/$id.anotoki-backup"), is_file(backupsDir() . "/$id.anotoki-backup.json")], 'the file and its sidecar gone');
    same([$adminId, 'backup', $id, '10.42.7.1'], [lastEntry('backup.deleted')[0], lastEntry('backup.deleted')[1], lastEntry('backup.deleted')[2], lastEntry('backup.deleted')[4]], 'audited');

    $fixture = $GLOBALS['fixtureId'];
    $users = (int) iamDb()->query('SELECT count(*) FROM users')->fetchColumn();
    $restore = $admin->post("/api/admin/backups/$fixture/restore", ['confirm' => substr($fixture, -6), 'password' => PASSWORD]);
    same([403, 'restore_disabled'], [$restore->status, $restore->code()], 'a restore: switched off here');
    same($users, (int) iamDb()->query('SELECT count(*) FROM users')->fetchColumn(), 'nothing put back');
    same(false, iamBackups()->restoreEnabled(), 'by the IAM\'s configuration');
    same(['Restoring is switched off on this site.'], array_values(array_filter($admin->get("/api/admin/backups/$fixture/preview")->json()['reasons'] ?? [], static fn(string $reason): bool => str_contains($reason, 'switched off'))), 'and the preview says why');
});

test('an administrator without a password proves it with a sign-in of the last 15 minutes; the limiter stops a guesser', function () {
    global $secondId;
    $pdo = iamDb();
    $pdo->prepare('UPDATE users SET password = NULL, password_login_enabled = FALSE WHERE id = ?')->execute([$secondId]);
    $browser = new Browser('10.42.8.1');
    same(200, $browser->post('/api/login/code', ['email' => 'second@example.test'])->status, 'a code asked for');
    preg_match('/\b(\d{6})\b/', (string) (lastMailTo('second@example.test')['subject'] ?? ''), $found);
    same(200, $browser->post('/api/login/code/verify', ['email' => 'second@example.test', 'code' => $found[1] ?? ''])->status, 'and signed in with it');

    same(['kind' => 'recent_sign_in'], $browser->get('/api/admin/backups/proof')->json(), 'the proof asked: a recent sign-in');
    $made = $browser->request('POST', '/api/admin/backups', ['json' => new stdClass()]);
    same(201, $made->status, 'just signed in: made - ' . substr($made->body, 0, 200));
    $pdo->prepare("UPDATE sessions SET auth_time = CURRENT_TIMESTAMP - INTERVAL '20 minutes' WHERE user_id = ? AND revoked_at IS NULL")->execute([$secondId]);
    same([403, 'reauthentication_required'], [($r = $browser->post('/api/admin/backups', ['password' => 'anything']))->status, $r->code()], 'twenty minutes later: sign in again first');
    $pdo->prepare('UPDATE users SET password = ?, password_login_enabled = TRUE WHERE id = ?')->execute([password_hash(PASSWORD, PASSWORD_DEFAULT), $secondId]);

    $guesser = signedIn('admin@example.test', '10.42.8.2');
    for ($i = 0; $i < ATTEMPT_MAX_PER_EMAIL_IP; $i++) {
        $guesser->post('/api/admin/backups', ['password' => "guess $i"]);
    }
    same([429, 'too_many_attempts'], [($r = $guesser->post('/api/admin/backups', ['password' => PASSWORD]))->status, $r->code()], 'too many wrong passwords: refused, the right one too');
});

/** `php backup.php`, run as a person runs it, on this run's configuration and storage: [exit status, output]. */
function runBackupCli(string $arguments): array
{
    $environment = testEnvironment();
    $wrapper = $environment['base'] . DIRECTORY_SEPARATOR . 'backup-cli.php';
    file_put_contents($wrapper, "<?php\n\n"
        . 'define(\'IAM_CONFIG_DIR\', ' . var_export($environment['config'], true) . ");\n"
        . 'define(\'IAM_STORAGE_DIR\', ' . var_export($environment['storage'], true) . ");\n"
        . 'require ' . var_export(dirname(__DIR__) . DIRECTORY_SEPARATOR . 'backup.php', true) . ";\n");
    exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($wrapper) . ' ' . $arguments . ' 2>&1', $lines, $status);

    return [$status, implode("\n", $lines)];
}

test('the command line: this IAM\'s backups listed and read; a downloaded one read with its IAM\'s master key, and with no other; nothing restored', function () {
    $id = $GLOBALS['fixtureId'];
    [$status, $out] = runBackupCli('--list');
    check($status === 0 && str_contains($out, $id) && str_contains($out, 'encrypted'), '--list: ' . $out);
    [$status, $out] = runBackupCli('--inspect=' . $id);
    check($status === 0 && preg_match('/^\s+users\s+\d+ rows/m', $out) === 1, '--inspect reads its manifest, decrypted, in memory: ' . $out);

    // A download, in a folder of its own, with a copy of the master key's file.
    $folder = testEnvironment()['base'] . DIRECTORY_SEPARATOR . 'downloaded';
    @mkdir($folder, 0700, true);
    copy(backupsDir() . "/$id.anotoki-backup", "$folder/$id.anotoki-backup");
    $key = $folder . DIRECTORY_SEPARATOR . 'secret.local.php';
    copy(testEnvironment()['config'] . DIRECTORY_SEPARATOR . 'secret.local.php', $key);
    $hex = trim((string) localConfig('secret'));
    [$status, $out] = runBackupCli('--from=' . escapeshellarg($folder) . ' --key=' . escapeshellarg($key) . ' --inspect=' . $id);
    check($status === 0 && preg_match('/^\s+users\s+\d+ rows/m', $out) === 1, 'with its IAM\'s master key: read - ' . $out);
    check(!str_contains($out, $hex), 'and the key is never printed');
    $other = $folder . DIRECTORY_SEPARATOR . 'other.local.php';
    file_put_contents($other, "<?php\n\nreturn '" . bin2hex(random_bytes(32)) . "';\n");
    [$status, $out] = runBackupCli('--from=' . escapeshellarg($folder) . ' --key=' . escapeshellarg($other) . ' --inspect=' . $id);
    check($status === 1 && str_contains($out, 'Failed'), 'with another: nothing - ' . $out);
    [$status] = runBackupCli('--from=' . escapeshellarg($folder) . ' --key=' . escapeshellarg($key) . ' --create');
    same(2, $status, 'another IAM\'s backups are only read here');

    [$status, $out] = runBackupCli('--restore=' . $id . ' --confirm=' . substr($id, -6));
    check($status === 1 && str_contains($out, 'switched off'), 'a restore: switched off here too - ' . $out);
    removeTree($folder);
});

test('the day\'s backup: queued by the first housekeeping of a UTC day, made after the answer, once', function () {
    $dailies = static fn(): array => array_values(array_filter(iamBackups()->list(), static fn(array $backup): bool => $backup['kind'] === 'daily'));
    same([], $dailies(), 'none yet');
    @unlink(iamStorageDir() . '/cache/housekeeping.stamp');
    iamHousekeeping();
    same([], $dailies(), 'queued, not made while the request is answered');
    AfterResponse::shared()->run(finish: false);
    same(1, count($dailies()), 'made after the answer');
    same([null, 'daily', true], [lastEntry('backup.created')[0], lastEntry('backup.created')[3]['kind'] ?? null, $dailies()[0]['encrypted'] ?? null], 'by nobody, encrypted, audited');
    same(false, iamBackups()->dailyIfDue(), 'and not again today');
    AfterResponse::shared()->run(finish: false);
    same(1, count($dailies()), 'still one');
});

finishTests();
