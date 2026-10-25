<?php

/**
 * The site settings and what search engines read - the anotoki family's
 * (anotoki-lib 0.11.0, the owner's requests of 2026-10-25), on the IAM
 * (api/site_settings.php).
 *
 * The IAM offers the announcement alone - a bar across the top of the sign-in,
 * account and legal pages, its words per language: GET /api/site-settings
 * says it to anybody, no cookie read, in no CORS list; the IAM's ADMIN sets it
 * on /api/admin/site-settings, under the session, requireAdmin() and the CSRF
 * header, every change audited. Never Getting in - a maintenance mode would
 * close the door every site signs in through - and never Page access: a
 * switch of either is refused, and a row of either written straight into the
 * library's tables closes nothing (no SiteState settings, no PageAccessGate).
 * While the library's table waits for its update, there is no announcement.
 *
 * robots.txt and sitemap.xml: robots kept out of every sign-in page, the
 * account pages and /.well-known/; the sitemap the legal pages, About and
 * Support - by the configured issuer's address, never a request's Host.
 */

declare(strict_types=1);

require __DIR__ . '/lib.php';

use Slim\Psr7\Factory\ServerRequestFactory;

// This run's own database is a fresh IAM, built from every migration - the library's admin set's 003 among them.
bootTests();

const PASSWORD = 'correct horse';
const ADMIN_SETTINGS = '/api/admin/site-settings';
const PUBLIC_SETTINGS = '/api/site-settings';
const SITE_ORIGIN = 'http://localhost:4200';

$adminId = makeUser('admin', 'admin@example.test', PASSWORD);
grantRole($adminId, 'iam', 'ADMIN');
$personId = makeUser('person', 'person@example.test', PASSWORD);

function signedIn(string $email, string $ip): Browser
{
    $browser = new Browser($ip);
    same(200, $browser->signIn($email, PASSWORD)->status, "{$email} signs in");

    return $browser;
}

/** An object's keys in order, all the way down (JSONB keeps its own order of them). */
function sortedKeys(mixed $value): mixed
{
    if (!is_array($value)) {
        return $value;
    }
    if (!array_is_list($value)) {
        ksort($value);
    }

    return array_map('sortedKeys', $value);
}

function lastAuditOf(string $action): ?array
{
    $statement = iamDb()->prepare('SELECT actor_id, target_type, details, ip FROM anotoki_audit_log WHERE action = ? ORDER BY id DESC LIMIT 1');
    $statement->execute([$action]);
    $row = $statement->fetch(PDO::FETCH_ASSOC);

    return $row === false
        ? null
        : [
            'actor_id' => $row['actor_id'] === null ? null : (int) $row['actor_id'],
            'target_type' => $row['target_type'],
            'details' => sortedKeys(json_decode((string) $row['details'], true)),
            'ip' => $row['ip'],
        ];
}

function auditCount(string $action): int
{
    $statement = iamDb()->prepare('SELECT count(*) FROM anotoki_audit_log WHERE action = ?');
    $statement->execute([$action]);

    return (int) $statement->fetchColumn();
}

/** A request the app answers as it would on the host, from a URI of the test's choosing - another host than the issuer's. */
function answerTo(string $method, string $uri, array $headers = []): TestResponse
{
    $request = (new ServerRequestFactory())->createServerRequest($method, $uri, ['REMOTE_ADDR' => '203.0.113.9']);
    foreach ($headers as $name => $value) {
        $request = $request->withHeader($name, $value);
    }
    $response = buildIamApp()->handle($request);

    return new TestResponse($response->getStatusCode(), $response->getHeaders(), (string) $response->getBody());
}

/** Points the app at another of this run's databases, and lets nothing remembered of the old one stand. */
function useIamDatabase(string $name): void
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
            "\$config['persistent'] = false;\n\nreturn \$config;\n",
    );
    forgetLocalConfig();
    settingsAll(true);
    languageCodes(null, true);
    activeSigningKey(true);
}

const SAID = [
    'announcement_enabled' => true,
    'announcement_level' => 'warning',
    'announcement_message' => ['en' => "The sign-in is slow tonight.\nWe are on it.", 'sk' => 'Prihlasovanie je dnes večer pomalé.'],
];

// ── The announcement ─────────────────────────────────────────────────────────

test('what every page is told: the announcement alone - null until one is written -, the same for anybody, no cookie, in no CORS list, kept by nobody', function () {
    $answer = (new Browser('10.71.0.1'))->get(PUBLIC_SETTINGS);
    same([200, ['announcement' => null]], [$answer->status, $answer->json()], 'no announcement, and no other part: no maintenance, no pages');
    same(['no-store', ''], [$answer->header('Cache-Control'), $answer->header('Set-Cookie')], 'kept by nobody, no cookie set');

    $cross = (new Browser('10.71.0.2'))->get(PUBLIC_SETTINGS, ['headers' => ['Origin' => SITE_ORIGIN]]);
    same([200, ''], [$cross->status, $cross->header('Access-Control-Allow-Origin')], 'a site\'s page elsewhere is let read nothing');
    $preflight = (new Browser('10.71.0.2'))->request('OPTIONS', PUBLIC_SETTINGS, ['headers' => ['Origin' => SITE_ORIGIN, 'Access-Control-Request-Method' => 'GET']]);
    same('', $preflight->header('Access-Control-Allow-Origin'), 'nor a preflight');
});

test('the Site settings page\'s routes: nobody signed in, an account that is no administrator, an administrator whose deletion is pending - refused, nothing read or written', function () {
    global $adminId;
    $nobody = new Browser('10.71.1.1');
    foreach (['GET', 'PUT'] as $method) {
        same([401, 'no_session'], [($r = $nobody->request($method, ADMIN_SETTINGS, ['json' => ['settings' => SAID]]))->status, $r->code()], "nobody signed in: {$method}");
    }

    $person = signedIn('person@example.test', '10.71.1.2');
    foreach (['GET', 'PUT'] as $method) {
        same([403, 'forbidden'], [($r = $person->request($method, ADMIN_SETTINGS, ['json' => ['settings' => SAID]]))->status, $r->code()], "an account that is no administrator: {$method}");
    }
    same([403, 'forbidden'], [($r = $person->post(ADMIN_SETTINGS . '/pages', ['path' => '/quizzes']))->status, $r->code()], 'nor its pages routes');

    $leaving = makeUser('leaving', 'leaving@example.test', PASSWORD);
    grantRole($leaving, 'iam', 'ADMIN');
    $browser = signedIn('leaving@example.test', '10.71.1.3');
    iamDb()
        ->prepare("UPDATE users SET deletion_requested_at = CURRENT_TIMESTAMP, deletion_due_at = CURRENT_TIMESTAMP + INTERVAL '30 days' WHERE id = ?")
        ->execute([$leaving]);
    try {
        foreach (['GET', 'PUT'] as $method) {
            same(
                [403, 'account_deletion_pending'],
                [($r = $browser->request($method, ADMIN_SETTINGS, ['json' => ['settings' => SAID]]))->status, $r->code()],
                "an administrator whose deletion is pending: {$method}",
            );
        }
    } finally {
        iamDb()
            ->prepare('UPDATE users SET deletion_requested_at = NULL, deletion_due_at = NULL WHERE id = ?')
            ->execute([$leaving]);
    }

    same(['announcement' => null], (new Browser('10.71.1.4'))->get(PUBLIC_SETTINGS)->json(), 'nothing was written');
    same(0, auditCount('site_settings.changed'), 'nothing audited');
});

test('a save needs the IAM\'s own pages: without the CSRF header, or from another origin, it is refused and nothing changes', function () {
    $admin = signedIn('admin@example.test', '10.71.2.1');
    same([403, 'csrf'], [($r = $admin->put(ADMIN_SETTINGS, ['settings' => SAID], ['csrf' => false]))->status, $r->code()], 'no X-IAM-Request');
    same([403, 'csrf'], [($r = $admin->put(ADMIN_SETTINGS, ['settings' => SAID], ['headers' => ['Origin' => SITE_ORIGIN]]))->status, $r->code()], 'a site\'s origin');
    same(['announcement' => null], (new Browser('10.71.2.2'))->get(PUBLIC_SETTINGS)->json(), 'nothing was written');
});

test('the IAM\'s administrator: the page\'s whole - the announcement\'s three switches alone -, a save that every page is told at once, audited with each switch before and after', function () {
    global $adminId;
    $admin = signedIn('admin@example.test', '10.71.3.1');

    $page = $admin->get(ADMIN_SETTINGS);
    same([200, 'no-store'], [$page->status, $page->header('Cache-Control')], 'the page');
    same(['announcement'], $page->json()['parts'] ?? null, 'the one part the IAM offers');
    same(['announcement_enabled', 'announcement_level', 'announcement_message'], array_keys($page->json()['settings'] ?? []), 'its three switches, and no maintenance');
    same(
        [false, 'info', []],
        [$page->json()['settings']['announcement_enabled']['value'], $page->json()['settings']['announcement_level']['value'], $page->json()['settings']['announcement_message']['value']],
        'off, information, nothing written',
    );
    same([], $page->json()['pages'] ?? null, 'no pages');

    $saved = $admin->put(ADMIN_SETTINGS, ['settings' => SAID]);
    same(200, $saved->status, 'saved: ' . $saved->body);
    same(
        ['true', 'warning', 'admin'],
        [
            json_encode($saved->json()['settings']['announcement_enabled']['value']),
            $saved->json()['settings']['announcement_level']['value'],
            $saved->json()['settings']['announcement_level']['updated_by_name'],
        ],
        'as saved, and by whom',
    );

    same(
        ['announcement' => ['level' => 'warning', 'message' => SAID['announcement_message']]],
        (new Browser('10.71.3.2'))->get(PUBLIC_SETTINGS)->json(),
        'every page is told at once - its lines kept',
    );

    $entry = lastAuditOf('site_settings.changed');
    same([$adminId, 'site_settings', '10.71.3.1'], [$entry['actor_id'] ?? null, $entry['target_type'] ?? null, $entry['ip'] ?? null], 'audited: the administrator, from their address');
    same(
        sortedKeys([
            'announcement_enabled' => ['old' => false, 'new' => true],
            'announcement_level' => ['old' => 'info', 'new' => 'warning'],
            'announcement_message' => ['old' => [], 'new' => SAID['announcement_message']],
        ]),
        $entry['details'] ?? null,
        'each switch before and after',
    );

    $count = auditCount('site_settings.changed');
    same(200, $admin->put(ADMIN_SETTINGS, ['settings' => SAID])->status, 'the same again');
    same($count, auditCount('site_settings.changed'), 'changes nothing, and audits nothing');

    same(200, $admin->put(ADMIN_SETTINGS, ['settings' => ['announcement_enabled' => false]])->status, 'switched off');
    same(['announcement' => null], (new Browser('10.71.3.3'))->get(PUBLIC_SETTINGS)->json(), 'no bar - its words kept for next time');
    same(['new' => false, 'old' => true], lastAuditOf('site_settings.changed')['details']['announcement_enabled'] ?? null, 'audited');
});

test('what is no announcement is refused, saying why, and nothing of the save is written: a kind it does not know, a control character, a message that is no message', function () {
    $admin = signedIn('admin@example.test', '10.71.4.1');
    $count = auditCount('site_settings.changed');
    foreach (
        [
            'a kind it does not know' => ['announcement_enabled' => true, 'announcement_level' => 'shouting'],
            'a control character' => ['announcement_enabled' => true, 'announcement_message' => ['en' => "Bell\u{0007}"]],
            'a list for a message' => ['announcement_enabled' => true, 'announcement_message' => ['Hello']],
            'a switch that is no switch' => ['announcement_enabled' => 'yes'],
        ]
        as $why => $settings
    ) {
        $refused = $admin->put(ADMIN_SETTINGS, ['settings' => $settings]);
        same([422, 'invalid_setting'], [$refused->status, $refused->code()], $why);
        check(($refused->json()['error'] ?? '') !== '', "{$why}: said in a sentence");
    }
    same([422, 'unknown_setting'], [($r = $admin->put(ADMIN_SETTINGS, ['settings' => ['banner' => true]]))->status, $r->code()], 'a setting there is not');
    same([422, 'invalid_body'], [($r = $admin->put(ADMIN_SETTINGS, ['enabled' => true]))->status, $r->code()], 'a body that names no settings');
    same(false, $admin->get(ADMIN_SETTINGS)->json()['settings']['announcement_enabled']['value'] ?? null, 'nothing was switched on');
    same($count, auditCount('site_settings.changed'), 'nothing audited');
});

// ── What the IAM never offers ────────────────────────────────────────────────

test('Getting in and Page access are no parts of the IAM\'s: their switches and routes refused - and a row of either written straight into the tables closes nothing, hides nothing', function () {
    $admin = signedIn('admin@example.test', '10.71.5.1');
    foreach (['maintenance_mode' => true, 'maintenance_message' => ['en' => 'Closed for maintenance']] as $name => $value) {
        same([422, 'part_not_offered'], [($r = $admin->put(ADMIN_SETTINGS, ['settings' => [$name => $value]]))->status, $r->code()], "{$name}: not offered");
    }
    same([422, 'part_not_offered'], [($r = $admin->post(ADMIN_SETTINGS . '/pages', ['path' => '/login', 'visibility' => 'ADMIN', 'blocked' => true]))->status, $r->code()], 'no page is governed');
    same([422, 'part_not_offered'], [($r = $admin->put(ADMIN_SETTINGS . '/pages', ['pages' => []]))->status, $r->code()], 'nor any changed');
    same([404, 'not_found'], [($r = $admin->delete(ADMIN_SETTINGS . '/pages/1'))->status, $r->code()], 'nor any removed: there is none');
    same(0, (int) iamDb()->query("SELECT count(*) FROM anotoki_site_settings WHERE name LIKE 'maintenance%'")->fetchColumn(), 'nothing of maintenance written');

    // Rows the routes would never write, put in by hand: the IAM reads neither part, so neither closes or hides anything.
    $pdo = iamDb();
    $pdo->exec("INSERT INTO anotoki_site_settings (name, value) VALUES ('maintenance_mode', 'true'), ('maintenance_message', '{\"en\": \"Closed\"}')");
    $pdo->exec("INSERT INTO anotoki_page_access (path, visibility, blocked, api_paths) VALUES ('/login', 'ADMIN', TRUE, '{/api/login,/api/oidc}'), ('/privacy', 'ADMIN', TRUE, '{}')");
    try {
        $visitor = new Browser('10.71.5.2');
        same(['state' => 'ready', 'installed' => false], $visitor->get('/api/site-status')->json(), 'the status: ready, never maintenance');
        same(['announcement' => null], $visitor->get(PUBLIC_SETTINGS)->json(), 'every page is told neither');
        same(200, $visitor->get('/api/public/config')->status, 'the sign-in pages\' configuration answers');
        same(200, (new Browser('10.71.5.3'))->signIn('person@example.test', PASSWORD)->status, 'a password sign-in goes through');
        same(200, $visitor->get('/.well-known/openid-configuration')->status, 'and the sites\' discovery');
        check(str_contains($visitor->get('/sitemap.xml')->body, 'http://localhost:4300/privacy'), 'the sitemap leaves nothing out for it');
    } finally {
        $pdo->exec("DELETE FROM anotoki_site_settings WHERE name LIKE 'maintenance%'");
        $pdo->exec('DELETE FROM anotoki_page_access');
    }
});

test('the window: the library\'s table not there yet (a build uploaded before Apply) - no announcement, the page says the update comes first, nothing is written', function () {
    $main = testEnvironment()['db'];
    $window = $main . '_without003';
    $pdo = buildIamThrough('_without003', '027_drop_access_and_old_audit_log.sql');
    // As an IAM had it before anotoki_admin/003: neither table, and the file not recorded.
    $pdo->exec('DROP TABLE anotoki_page_access');
    $pdo->exec('DROP TABLE anotoki_site_settings');
    $pdo->exec("DELETE FROM migrations WHERE filename = 'anotoki_admin/003_site_settings.sql'");
    useIamDatabase($window);

    try {
        $adminId = makeUser('admin', 'admin@example.test', PASSWORD);
        grantRole($adminId, 'iam', 'ADMIN');
        $admin = signedIn('admin@example.test', '10.71.6.1');
        same([200, ['announcement' => null]], [($r = (new Browser('10.71.6.2'))->get(PUBLIC_SETTINGS))->status, $r->json()], 'every page is told there is none');
        same([503, 'feature_unavailable'], [($r = $admin->get(ADMIN_SETTINGS))->status, $r->code()], 'the page: the update first');
        same([503, 'feature_unavailable'], [($r = $admin->put(ADMIN_SETTINGS, ['settings' => SAID]))->status, $r->code()], 'and no save');
        same(200, (new Browser('10.71.6.3'))->signIn('admin@example.test', PASSWORD)->status, 'and every way in works meanwhile');
    } finally {
        useIamDatabase($main);
    }
});

// ── robots.txt and sitemap.xml ───────────────────────────────────────────────

test('robots.txt: every robot kept out of the admin panel, the API, every sign-in page, the account pages and /.well-known/ - and the sitemap named by the issuer', function () {
    $robots = (new Browser('10.71.7.1'))->get('/robots.txt');
    same([200, 'text/plain; charset=utf-8', 'public, max-age=3600'], [$robots->status, $robots->header('Content-Type'), $robots->header('Cache-Control')], 'public, for an hour');
    same(
        implode("\n", [
            '# The robots of every search engine: what to leave alone, and where the site\'s pages are listed.',
            'User-agent: *',
            'Disallow: /admin',
            'Disallow: /api',
            'Disallow: /auth',
            'Disallow: /login',
            'Disallow: /register',
            'Disallow: /confirm-email',
            'Disallow: /forgot-password',
            'Disallow: /reset-password',
            'Disallow: /logout',
            'Disallow: /error',
            'Disallow: /not-set-up',
            'Disallow: /account',
            'Disallow: /unsubscribe',
            'Disallow: /.well-known/',
            '',
            'Sitemap: ' . TEST_ISSUER . '/sitemap.xml',
            '',
        ]),
        $robots->body,
        'every rule, and the sitemap\'s address',
    );
    same('', $robots->header('Set-Cookie'), 'no cookie');
    same([200, ''], [($r = (new Browser('10.71.7.2'))->request('HEAD', '/robots.txt'))->status, $r->body], 'HEAD too');
});

test('sitemap.xml: the legal pages, About and Support - nothing else of the IAM\'s - at the issuer\'s address', function () {
    $sitemap = (new Browser('10.71.8.1'))->get('/sitemap.xml');
    same([200, 'application/xml; charset=utf-8', 'public, max-age=3600'], [$sitemap->status, $sitemap->header('Content-Type'), $sitemap->header('Cache-Control')], 'public, for an hour');
    same(
        implode("\n", [
            '<?xml version="1.0" encoding="UTF-8"?>',
            '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">',
            '  <url><loc>' . TEST_ISSUER . '/terms</loc></url>',
            '  <url><loc>' . TEST_ISSUER . '/privacy</loc></url>',
            '  <url><loc>' . TEST_ISSUER . '/contact</loc></url>',
            '  <url><loc>' . TEST_ISSUER . '/about</loc></url>',
            '  <url><loc>' . TEST_ISSUER . '/support</loc></url>',
            '</urlset>',
            '',
        ]),
        $sitemap->body,
        'the five pages, absolute',
    );
    check(simplexml_load_string($sitemap->body) !== false, 'valid XML');
});

test('a request\'s Host names nothing: robots.txt and the sitemap carry the configured issuer whatever a request says - and follow the issuer when the installation changes it', function () {
    $headers = ['Host' => 'evil.example', 'X-Forwarded-Host' => 'evil.example', 'X-Forwarded-Proto' => 'https', 'Forwarded' => 'host=evil.example;proto=https'];
    foreach (['/robots.txt', '/sitemap.xml'] as $path) {
        $answer = answerTo('GET', 'https://evil.example' . $path, $headers);
        same(200, $answer->status, "{$path} from another host");
        check(!str_contains($answer->body, 'evil.example'), "{$path}: nothing of the request's host");
        check(str_contains($answer->body, TEST_ISSUER . '/'), "{$path}: the issuer's address");
    }

    // The installation's own address, changed: the next answer is made again at once, never one kept under the old address.
    writeTestConfig('iam', ['public_url' => 'https://account.example.test']);
    forgetLocalConfig();
    try {
        check(str_contains((new Browser('10.71.9.1'))->get('/robots.txt')->body, "\nSitemap: https://account.example.test/sitemap.xml\n"), 'robots.txt follows the issuer');
        $sitemap = (new Browser('10.71.9.1'))->get('/sitemap.xml')->body;
        check(str_contains($sitemap, '<loc>https://account.example.test/privacy</loc>') && !str_contains($sitemap, TEST_ISSUER), 'and so does the sitemap, made again at once');
    } finally {
        writeTestConfig('iam', ['public_url' => TEST_ISSUER]);
        forgetLocalConfig();
    }
    check(str_contains((new Browser('10.71.9.2'))->get('/sitemap.xml')->body, TEST_ISSUER . '/privacy'), 'and back');
});

finishTests();
