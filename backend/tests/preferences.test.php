<?php

/**
 * The person's display preferences, kept by the IAM for every site (migration
 * 015, the owner's decision of 2026-10-06): the theme, how dates are written,
 * the clock - beside the language the account had already. Changed on the
 * account page, or by a site for its signed-in reader (POST
 * /api/oidc/preferences, with the person's access token); handed to every site
 * as the `preferences` claim beside `locale`.
 *
 * The site's save reads no cookie and needs no CSRF header - so a session
 * cookie alone must change nothing there, and only a registered site's origin
 * is answered cross-origin. 015 reaches installed IAMs too: through 014 as an
 * owner left it, every account starts on auto with nothing chosen. And a build
 * goes up before its migrations: with 015 pending the account page and the
 * tokens answer as before, and the saves wait for Apply.
 */

declare(strict_types=1);

require __DIR__ . '/lib.php';

// This run's own database is a fresh IAM, built from every migration, 015 included.
bootTests();

$adminId = makeUser('admin', 'admin@example.test');
grantRole($adminId, 'iam', 'ADMIN');
$readerId = makeUser('reader', 'reader@example.test');

const PREFERENCES_MIGRATION = '015_account_preferences.sql';
const SURVEY_FRONTEND = 'http://localhost:4700';
const SURVEY_CALLBACK = 'http://localhost:4700/auth/callback';

/** A browser signed in as the reader. */
function reader(string $ip = '10.15.0.1'): Browser
{
    $browser = new Browser($ip);
    same(200, $browser->signIn('reader@example.test', 'correct horse')->status, 'the reader signs in');

    return $browser;
}

/** A signed-in browser's sign-in to the survey site, through to its tokens. */
function intoSurvey(Browser $browser, string $scope = 'openid profile email'): array
{
    [$verifier, $challenge] = pkce();
    $start = $browser->get('/api/oidc/authorize?' . http_build_query([
        'response_type' => 'code', 'client_id' => 'survey', 'redirect_uri' => SURVEY_CALLBACK, 'scope' => $scope,
        'state' => 's', 'nonce' => 'n', 'code_challenge' => $challenge, 'code_challenge_method' => 'S256',
    ]));
    $code = queryOf($start->location())['code'] ?? null;
    check($code !== null, 'a code straight away: ' . $start->location());
    $tokens = $browser->request('POST', '/api/oidc/token', [
        'form' => ['grant_type' => 'authorization_code', 'code' => $code, 'redirect_uri' => SURVEY_CALLBACK, 'client_id' => 'survey', 'code_verifier' => $verifier],
        'headers' => ['Origin' => SURVEY_FRONTEND],
    ]);
    same(200, $tokens->status, 'code exchange: ' . $tokens->body);

    return $tokens->json();
}

/** The payload of a JWT, unverified - the tests read what a token says. */
function payloadOf(string $jwt): array
{
    return json_decode(base64UrlDecode(explode('.', $jwt)[1] ?? ''), true) ?: [];
}

/** The survey site's save, as its page sends it: the access token, JSON, its own origin - and no CSRF header. `$headers` wins. */
function savePreferences(array $body, ?string $accessToken, array $headers = []): TestResponse
{
    return (new Browser('10.15.9.9'))->request('POST', '/api/oidc/preferences', [
        'json' => $body,
        'headers' => $headers + ($accessToken !== null ? ['Authorization' => 'Bearer ' . $accessToken] : []) + ['Origin' => SURVEY_FRONTEND],
    ]);
}

function preferencesOf(int $userId): array
{
    $statement = iamDb()->prepare('SELECT language, theme, date_format, time_format FROM users WHERE id = ?');
    $statement->execute([$userId]);

    return $statement->fetch(PDO::FETCH_ASSOC) ?: [];
}

/** Points the app at another of this run's databases, forgetting what it remembered of the old one. */
function useIamDatabase(string $name): void
{
    $environment = testEnvironment();
    file_put_contents($environment['config'] . DIRECTORY_SEPARATOR . 'database.local.php',
        "<?php\n\n\$config = require " . var_export(developmentDatabaseFile(), true) . ";\n"
        . "\$config['databases'] = ['iam' => " . var_export(throwawayDatabase($name), true) . "];\n"
        . "\$config['persistent'] = false;\n\nreturn \$config;\n");
    forgetLocalConfig();
    settingsAll(true);
    languageCodes(null, true);
    activeSigningKey(true);
}

const NOTHING_CHOSEN = ['theme' => 'auto', 'date_format' => null, 'time_format' => null];

// ── 015 itself ───────────────────────────────────────────────────────────────

test('a fresh IAM has 015: every account on auto, no date format or clock chosen', function () {
    $pdo = iamDb();
    check(in_array(PREFERENCES_MIGRATION, appliedMigrations($pdo), true), '015 recorded');
    same(true, preferencesReady($pdo), 'its columns are there');
    same(['language' => 'en'] + NOTHING_CHOSEN, preferencesOf((int) $GLOBALS['readerId']), 'the reader: English, auto, nothing chosen');
    foreach (["UPDATE users SET theme = 'blue'", "UPDATE users SET date_format = 'dd.mm'", "UPDATE users SET time_format = '36'"] as $sql) {
        try {
            $pdo->exec($sql);
            check(false, "$sql should be refused by the database");
        } catch (PDOException) {
        }
    }
});

test('an IAM through 014, as its owner left it: 015 starts everybody on auto, and leaves the owner\'s words alone', function () {
    $pdo = buildIamThrough('_owner', '014_news_at_registration.sql');
    $insert = $pdo->prepare('INSERT INTO users (username, email, password, email_confirmed, language) VALUES (?, ?, ?, ?, ?)');
    $insert->execute(['owner', 'owner@example.test', null, 'true', 'sk']);
    $insert->execute(['other', 'other@example.test', null, 'true', 'en']);
    // The owner reworded the appearance line in the admin panel; the language line is 006's.
    $pdo->exec("UPDATE translations SET value = 'Kept here, my way.' WHERE key_name = 'overview.appearanceHint' AND language_code = 'en'");

    $result = applyMigrationsThrough($pdo, PREFERENCES_MIGRATION);
    same([[PREFERENCES_MIGRATION], null], [$result['applied'], $result['failed']], '015 applies (' . ($result['error'] ?? '') . ')');
    same([['sk', 'auto', null, null], ['en', 'auto', null, null]],
        $pdo->query('SELECT language, theme, date_format, time_format FROM users ORDER BY id')->fetchAll(PDO::FETCH_NUM), 'their languages kept, everything else automatic');
    $word = static fn(string $key, string $code): string => (string) $pdo->query("SELECT value FROM translations WHERE key_name = '$key' AND language_code = '$code'")->fetchColumn();
    same('Kept here, my way.', $word('overview.appearanceHint', 'en'), 'the owner\'s rewording stays');
    same('Riadi sa ním každá stránka anotoki, kde používaš svoj účet.', $word('overview.appearanceHint', 'sk'), 'the Slovak, still 006\'s, is reworded');
    same('Used on every anotoki site where you use your account, and in the emails we send you.', $word('overview.languageHint', 'en'), 'the language line too');
});

// ── The account page ─────────────────────────────────────────────────────────

test('the account page shows and saves the theme, the dates and the clock', function () {
    $reader = reader();
    same(NOTHING_CHOSEN, $reader->get('/api/account')->json()['preferences'] ?? null, 'nothing chosen yet');

    $saved = $reader->put('/api/account/profile', ['theme' => 'dark', 'date_format' => 'ymd_dash', 'time_format' => '12']);
    same([200, ['theme' => 'dark', 'date_format' => 'ymd_dash', 'time_format' => '12']], [$saved->status, $saved->json()['preferences'] ?? null], 'saved: ' . $saved->body);
    same(['theme' => 'dark', 'date_format' => null, 'time_format' => '12'], $reader->put('/api/account/profile', ['date_format' => 'auto'])->json()['preferences'] ?? null, '"auto" is automatic again, the rest stays');
    same(['theme' => 'dark', 'date_format' => null, 'time_format' => null], $reader->put('/api/account/profile', ['time_format' => null])->json()['preferences'] ?? null, 'and so is null');

    foreach ([['theme' => 'blue'], ['theme' => null], ['date_format' => 'dd.mm.yy'], ['time_format' => 24], ['time_format' => '36']] as $body) {
        $refused = $reader->put('/api/account/profile', $body);
        same([422, 'invalid_preference'], [$refused->status, $refused->code()], json_encode($body));
    }
    same([403, 'csrf'], [($r = $reader->put('/api/account/profile', ['theme' => 'light'], ['csrf' => false]))->status, $r->code()], 'the CSRF header, as every write of the account page');
    same('dark', preferencesOf((int) $GLOBALS['readerId'])['theme'], 'still dark');
    $reader->put('/api/account/profile', ['theme' => 'auto']);
});

// ── The sites are told ───────────────────────────────────────────────────────

test('every token and userinfo carry the preferences with the profile scope, and only with it', function () {
    $reader = reader('10.15.1.1');
    $reader->put('/api/account/profile', ['theme' => 'light', 'date_format' => 'dmy_dot', 'time_format' => '24']);
    $expected = ['theme' => 'light', 'date_format' => 'dmy_dot', 'time_format' => '24'];

    $tokens = intoSurvey($reader);
    same([$expected, 'en'], [payloadOf($tokens['id_token'])['preferences'] ?? null, payloadOf($tokens['id_token'])['locale'] ?? null], 'the ID token');
    same($expected, payloadOf($tokens['access_token'])['preferences'] ?? null, 'the access token');
    $userinfo = (new Browser('10.15.1.2'))->get('/api/oidc/userinfo', ['headers' => ['Authorization' => 'Bearer ' . $tokens['access_token']]]);
    same([200, $expected], [$userinfo->status, $userinfo->json()['preferences'] ?? null], 'userinfo');
    check(in_array('preferences', (new Browser('10.15.1.2'))->get('/.well-known/openid-configuration')->json()['claims_supported'] ?? [], true), 'discovery names the claim');

    $bare = intoSurvey(reader('10.15.1.3'), 'openid email');
    check(!array_key_exists('preferences', payloadOf($bare['id_token'])) && !array_key_exists('locale', payloadOf($bare['id_token'])), 'without the profile scope, neither the preferences nor the language');
    $reader->put('/api/account/profile', ['theme' => 'auto', 'date_format' => null, 'time_format' => null]);
});

// ── A site saves for its reader ──────────────────────────────────────────────

test('a site saves its reader\'s choice with the person\'s token: the account\'s, and the next token says so', function () {
    $reader = reader('10.15.2.1');
    $tokens = intoSurvey($reader);

    $saved = savePreferences(['theme' => 'dark', 'language' => 'SK', 'date_format' => 'mdy_slash', 'time_format' => '12'], $tokens['access_token']);
    same(200, $saved->status, 'saved, with no CSRF header: ' . $saved->body);
    same(['locale' => 'sk', 'preferences' => ['theme' => 'dark', 'date_format' => 'mdy_slash', 'time_format' => '12']], $saved->json(), 'answered as the account is now');
    same(['language' => 'sk', 'theme' => 'dark', 'date_format' => 'mdy_slash', 'time_format' => '12'], preferencesOf((int) $GLOBALS['readerId']), 'the account\'s');
    same(SURVEY_FRONTEND, $saved->header('Access-Control-Allow-Origin'), 'answered to the site\'s own origin');

    same(['theme' => 'dark', 'date_format' => null, 'time_format' => '12'], savePreferences(['date_format' => 'auto'], $tokens['access_token'])->json()['preferences'] ?? null, 'one at a time; automatic again');

    $refreshed = (new Browser('10.15.2.2'))->request('POST', '/api/oidc/token', [
        'form' => ['grant_type' => 'refresh_token', 'refresh_token' => $tokens['refresh_token'], 'client_id' => 'survey'],
        'headers' => ['Origin' => SURVEY_FRONTEND],
    ]);
    same(200, $refreshed->status, 'a refresh: ' . $refreshed->body);
    same([['theme' => 'dark', 'date_format' => null, 'time_format' => '12'], 'sk'],
        [payloadOf($refreshed->json()['id_token'])['preferences'] ?? null, payloadOf($refreshed->json()['id_token'])['locale'] ?? null], 'the next ID token carries them - every site follows');
    same(['theme' => 'dark', 'date_format' => null, 'time_format' => '12'], $reader->get('/api/account')->json()['preferences'] ?? null, 'and the account page shows them');

    $reader->put('/api/account/profile', ['theme' => 'auto', 'time_format' => null, 'language' => 'en']);
});

test('the site\'s save: only the person\'s token opens it, never the session cookie - and only the four, checked', function () {
    $reader = reader('10.15.3.1');
    $tokens = intoSurvey($reader);
    $before = preferencesOf((int) $GLOBALS['readerId']);

    // The reader's own browser, signed in at the IAM, with no token: the cookie is never read.
    $cookieOnly = $reader->request('POST', '/api/oidc/preferences', ['json' => ['theme' => 'dark'], 'headers' => ['Origin' => SURVEY_FRONTEND]]);
    same([401, 'invalid_token'], [$cookieOnly->status, $cookieOnly->json()['error'] ?? null], 'a session cookie alone changes nothing');
    $withHeader = $reader->request('POST', '/api/oidc/preferences', ['json' => ['theme' => 'dark'], 'headers' => ['X-IAM-Request' => '1']]);
    same(401, $withHeader->status, 'not even with the IAM\'s own CSRF header');

    same([401, 'invalid_token'], [($r = savePreferences(['theme' => 'dark'], null))->status, $r->json()['error'] ?? null], 'no token');
    check(str_contains(savePreferences(['theme' => 'dark'], null)->header('WWW-Authenticate'), 'invalid_token'), 'said in the header too');
    same(401, savePreferences(['theme' => 'dark'], 'not.a.token')->status, 'a token that is not one');
    $forged = explode('.', $tokens['access_token']);
    $forged[1] = base64UrlEncode((string) json_encode(['sub' => (string) $GLOBALS['adminId']] + payloadOf($tokens['access_token'])));
    same(401, savePreferences(['theme' => 'dark'], implode('.', $forged))->status, 'one rewritten for another account');
    same(401, savePreferences(['theme' => 'dark'], $tokens['id_token'])->status, 'an ID token is no access token');

    foreach ([
        [['theme' => 'blue'], 'a theme there is not'],
        [['time_format' => 12], 'a clock as a number'],
        [['language' => 'de'], 'a language the IAM does not offer'],
        [['username' => 'taken'], 'anything but the four'],
        [['theme' => 'dark', 'email_confirmed' => true], 'the four and something else'],
        [[], 'nothing'],
        [['dark'], 'a list'],
    ] as [$body, $what]) {
        $refused = savePreferences($body, $tokens['access_token']);
        same([400, 'invalid_request'], [$refused->status, $refused->json()['error'] ?? null], $what . ': ' . $refused->body);
    }
    same($before, preferencesOf((int) $GLOBALS['readerId']), 'nothing refused changed anything');

    $narrow = intoSurvey(reader('10.15.3.2'), 'openid email');
    $scope = savePreferences(['theme' => 'dark'], $narrow['access_token']);
    same([403, 'insufficient_scope'], [$scope->status, $scope->json()['error'] ?? null], 'a token without the profile scope');

    iamDb()->prepare('UPDATE users SET disabled = TRUE WHERE id = ?')->execute([(int) $GLOBALS['readerId']]);
    try {
        same(401, savePreferences(['theme' => 'dark'], $tokens['access_token'])->status, 'an account switched off since');
    } finally {
        iamDb()->prepare('UPDATE users SET disabled = FALSE WHERE id = ?')->execute([(int) $GLOBALS['readerId']]);
    }
    same($before, preferencesOf((int) $GLOBALS['readerId']), 'still as it was');
});

test('a write asks more of a token than userinfo: a sign-in behind it, its own site\'s page, and the person still let in there', function () {
    $reader = reader('10.15.5.1');
    $tokens = intoSurvey($reader);
    $readerId = (int) $GLOBALS['readerId'];
    $before = preferencesOf($readerId);
    $survey = static fn(string $sql) => iamDb()->prepare($sql)->execute(['survey']);

    // Another site's page - registered, so its preflight is answered - holding the survey's token.
    $foreign = savePreferences(['theme' => 'dark'], $tokens['access_token'], ['Origin' => 'http://localhost:4200']);
    same([400, 'invalid_request'], [$foreign->status, $foreign->json()['error'] ?? null], 'the genshin site\'s page with the survey\'s token: ' . $foreign->body);
    $noPage = (new Browser('10.15.5.2'))->request('POST', '/api/oidc/preferences', [
        'json' => ['theme' => 'light'],
        'headers' => ['Authorization' => 'Bearer ' . $tokens['access_token']],
    ]);
    same(200, $noPage->status, 'no page at all - the site\'s own backend - is the token\'s own business: ' . $noPage->body);

    // A script's personal token, exchanged as a script does: a valid access token with no sign-in behind it.
    $made = $reader->post('/api/account/tokens', ['name' => 'importer', 'client_id' => 'survey', 'expires_in_days' => 30]);
    same(201, $made->status, 'a personal token: ' . $made->body);
    $exchanged = (new Browser('10.15.5.3'))->request('POST', '/api/oidc/token', [
        'form' => ['grant_type' => 'refresh_token', 'refresh_token' => $made->json()['token'], 'client_id' => 'survey'],
    ]);
    same(200, $exchanged->status, 'exchanged: ' . $exchanged->body);
    check(!isset(payloadOf($exchanged->json()['access_token'])['sid']), 'no sign-in behind it');
    same(200, (new Browser('10.15.5.4'))->get('/api/oidc/userinfo', ['headers' => ['Authorization' => 'Bearer ' . $exchanged->json()['access_token']]])->status, 'it reads userinfo, as ever');
    $script = savePreferences(['theme' => 'dark', 'language' => 'sk'], $exchanged->json()['access_token']);
    same([403, 'access_denied'], [$script->status, $script->json()['error'] ?? null], 'but changes nothing: ' . $script->body);

    // The site switched off since the token was issued.
    $survey('UPDATE applications SET enabled = FALSE WHERE client_id = ?');
    try {
        $off = savePreferences(['theme' => 'dark'], $tokens['access_token']);
        same([403, 'access_denied'], [$off->status, $off->json()['error'] ?? null], 'a site switched off writes nothing with the tokens it still holds: ' . $off->body);
    } finally {
        $survey('UPDATE applications SET enabled = TRUE WHERE client_id = ?');
    }

    // The reader kept out of the site: their MEMBER there taken away.
    $taken = iamDb()->prepare(
        "DELETE FROM user_roles WHERE user_id = ? AND role_id = (SELECT r.id FROM application_roles r JOIN applications a ON a.id = r.application_id WHERE a.client_id = 'survey' AND r.name = 'MEMBER')"
    );
    $taken->execute([$readerId]);
    try {
        $out = savePreferences(['theme' => 'dark'], $tokens['access_token']);
        same([403, 'access_denied'], [$out->status, $out->json()['error'] ?? null], 'nor for somebody kept out of it: ' . $out->body);
    } finally {
        grantRole($readerId, 'survey', 'MEMBER');
    }

    same(array_merge($before, ['theme' => 'light']), preferencesOf($readerId), 'only the backend\'s save changed anything');
    same(200, savePreferences(['theme' => $before['theme']], $tokens['access_token'])->status, 'let in again, the site saves again');
});

test('cross-origin: a registered site\'s origin is answered, any other is not', function () {
    $preflight = static fn(string $origin): TestResponse => (new Browser('10.15.4.1'))->request('OPTIONS', '/api/oidc/preferences', [
        'headers' => ['Origin' => $origin, 'Access-Control-Request-Method' => 'POST', 'Access-Control-Request-Headers' => 'authorization, content-type'],
    ]);

    $site = $preflight(SURVEY_FRONTEND);
    same([204, SURVEY_FRONTEND], [$site->status, $site->header('Access-Control-Allow-Origin')], 'the survey site\'s preflight');
    check(str_contains($site->header('Access-Control-Allow-Methods'), 'POST') && str_contains(strtolower($site->header('Access-Control-Allow-Headers')), 'authorization'), 'POST with a token');
    same('', $preflight('https://elsewhere.example')->header('Access-Control-Allow-Origin'), 'a page elsewhere is not answered');
    same('', $preflight('http://localhost:9999')->header('Access-Control-Allow-Origin'), 'nor an origin no site registered');
});

// ── With 015 pending: a build uploaded before Apply ──────────────────────────

test('with 015 pending the account page and the tokens answer as before, and the saves wait for Apply', function () {
    $main = testEnvironment()['db'];
    $window = throwawayDatabase($main . '_window');
    buildIamThrough('_window', '014_news_at_registration.sql');
    useIamDatabase($window);

    try {
        $pdo = iamDb();
        same(false, preferencesReady($pdo), 'no preferences yet');
        $adminId = makeUser('admin', 'admin@example.test');
        grantRole($adminId, 'iam', 'ADMIN');
        makeUser('reader', 'reader@example.test');

        $reader = reader('10.15.8.1');
        $account = $reader->get('/api/account')->json();
        same([true, null], [array_key_exists('preferences', $account), $account['preferences']], 'the account page: no preferences to show');
        same(200, $reader->put('/api/account/profile', ['language' => 'sk'])->status, 'the language saves as ever');
        same([503, 'preferences_unavailable'], [($r = $reader->put('/api/account/profile', ['theme' => 'dark']))->status, $r->code()], 'the theme waits');

        $tokens = intoSurvey($reader);
        check(!array_key_exists('preferences', payloadOf($tokens['id_token'])), 'the tokens say nothing of them');
        same('sk', payloadOf($tokens['id_token'])['locale'] ?? null, 'and the language as ever');
        same([503, 'temporarily_unavailable'], [($r = savePreferences(['theme' => 'dark'], $tokens['access_token']))->status, $r->json()['error'] ?? null], 'a site\'s save waits');

        $admin = new Browser('10.15.8.2');
        same(200, $admin->signIn('admin@example.test', 'correct horse')->status, 'the admin signs in');
        $applied = $admin->post('/api/admin/migrations/apply');
        same([200, [PREFERENCES_MIGRATION], null], [$applied->status, array_slice(migrationNames($applied->json()['applied'] ?? []), 0, 1), $applied->json()['failed'] ?? null], 'Apply pending: ' . $applied->body);
        same(200, savePreferences(['theme' => 'dark'], $tokens['access_token'])->status, 'from the next request on, in the same process');
    } finally {
        useIamDatabase($main);
    }

    same($main, iamDb()->query('SELECT current_database()')->fetchColumn(), 'back on this run\'s own database');
});

finishTests();
