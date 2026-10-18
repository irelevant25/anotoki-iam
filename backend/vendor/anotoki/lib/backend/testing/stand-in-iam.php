<?php

/**
 * The anotoki family's stand-in IAM - for a site's tests and browser checks
 * only; never point a real site at it. The router of PHP's built-in server,
 * with no dependencies: just enough of the IAM for the library's auth module
 * on both halves (built from the survey's tests/tools/fake-iam.php, which the
 * build analyzer copied, and Piano and Japanese Academy's stand-ins):
 *
 *   GET  /.well-known/openid-configuration   discovery (with ui_locales_supported: en, sk)
 *   GET  /.well-known/jwks.json              the test key set
 *   GET  /api/oidc/authorize                 signs the configured person in at once - or refuses -, with
 *                                            PKCE S256; prompt=none answers login_required or
 *                                            interaction_required; the sign-in hint cookie as the IAM sets
 *                                            and clears it
 *   POST /api/oidc/token                     authorization code (single use, 60 s, PKCE), refresh with rotation
 *   POST /api/oidc/revoke                    spends a refresh token
 *   POST /api/oidc/preferences               the account's theme, dates, clock and language
 *   GET  /api/oidc/logout                    ends the session, clears the hint, back to post_logout_redirect_uri
 *   GET  /api/public/applications/{id}       whether the site requires signing in (or 404)
 *   GET  /api/public/anonymized-accounts     the deleted accounts, paged with since and after as the IAM pages
 *   GET  /account                            a page standing in for the account page
 *   GET  /_test/sign-in?return=URL           somebody signs in at the IAM: a session and a new hint
 *   GET  /_test/sign-out?return=URL          they sign out there: no session, no hint
 *   GET|POST /_test/state                    the state; a POST merges JSON into it
 *
 *   STAND_IN_DIR=<folder> STAND_IN_ISSUER=http://localhost:8597 php -S localhost:8597 vendor/anotoki/lib/backend/testing/stand-in-iam.php
 *
 * (FAKE_IAM_DIR and FAKE_IAM_ISSUER, the survey's names, work too.) <folder>
 * holds key.json - {kid, private_pem, jwks}, as TestIam::create() writes it -
 * and state.json. Anotoki\Lib\Auth\Testing\StandInIam starts and stops it for a
 * test. STAND_IN_JWKS (a key set's file) instead of STAND_IN_DIR serves
 * discovery and the keys only: an installer's "Test the IAM" asks no more.
 *
 * The state - POST /_test/state merges JSON into it:
 *   user                 {id, username, roles, locale?, preferences?}: whom authorize signs in. Roles are
 *                        this application's (every account holds MEMBER). preferences {theme, date_format,
 *                        time_format}: the account's, the tokens' claim - none: an IAM before its migration
 *                        015, where saving them answers 503
 *   session              true (default): somebody is signed in at the IAM, and authorize answers at once
 *   interaction          true: a session this site may not use - prompt=none answers interaction_required
 *   ttl                  seconds an access token lives (600)
 *   down                 true: everything but the key set answers 503 (the IAM unreachable)
 *   refuse               true: a refresh answers invalid_grant (the IAM session ended)
 *   deny                 true: authorize answers access_denied (deny_description its error_description)
 *   login_required       what /api/public/applications/{id} says (true)
 *   application_missing  true: that address answers 404 (an unknown or switched-off application)
 *   application_name     the name it gives there (Test site)
 *   anonymized           [{sub, anonymized_at}]: the deleted accounts; anonymized_page: their page size (500)
 *   hint_cookie          the hint's name (anotoki_signed_in); null: no hint at all
 *   answers              {"/path": {status, body, type?, location?}}: any answer at all for that path - a
 *                        proxy's page, a redirect, a 500 - before anything else answers it
 * Every request is logged in `log` (the last 200, "METHOD /path"), so a test can count them.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli-server') {
    // Never anything but the built-in server's router: served by a real web server, it is nothing.
    http_response_code(404);

    return;
}

$issuer = rtrim((string) (getenv('STAND_IN_ISSUER') ?: getenv('FAKE_IAM_ISSUER')), '/');
$dir = rtrim(str_replace('\\', '/', (string) (getenv('STAND_IN_DIR') ?: getenv('FAKE_IAM_DIR'))), '/');
$jwksOnly = $dir === '' ? (string) getenv('STAND_IN_JWKS') : '';
$keyFile = $dir !== '' ? "$dir/key.json" : '';
if ($issuer === '' || ($keyFile === '' ? !is_file($jwksOnly) : !is_file($keyFile))) {
    http_response_code(500);
    echo 'stand-in IAM: set STAND_IN_ISSUER, and STAND_IN_DIR (with key.json) or STAND_IN_JWKS';

    return true;
}

const STAND_IN_PREFERENCES = [
    'theme' => ['auto', 'light', 'dark'],
    'date_format' => [null, 'dmy_dot', 'dmy_slash', 'mdy_slash', 'ymd_dash'],
    'time_format' => [null, '24', '12'],
    'language' => ['en', 'sk'],
];

const STAND_IN_HINT_SHAPE = '/^[A-Za-z0-9_-]{16,64}$/D';

function stand_in_state(string $dir): array
{
    $state = $dir !== '' && is_file("$dir/state.json") ? json_decode((string) file_get_contents("$dir/state.json"), true) : null;

    return (is_array($state) ? $state : []) + [
        'user' => ['id' => 1, 'username' => 'owner', 'roles' => ['MEMBER', 'ADMIN']],
        'session' => true,
        'interaction' => false,
        'ttl' => 600,
        'down' => false,
        'refuse' => false,
        'deny' => false,
        'deny_description' => 'No access to this application',
        'login_required' => true,
        'application_missing' => false,
        'application_name' => 'Test site',
        'anonymized' => [],
        'anonymized_page' => 500,
        'hint_cookie' => 'anotoki_signed_in',
        'codes' => [],
        'refresh' => [],
        'log' => [],
    ];
}

function stand_in_save(string $dir, array $state): void
{
    if ($dir === '') {
        return;
    }
    $temporary = "$dir/state.json." . bin2hex(random_bytes(4)) . '.tmp';
    file_put_contents($temporary, json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    rename($temporary, "$dir/state.json");
}

function stand_in_json(int $status, mixed $body, string $origin = '', string $cache = 'no-store'): bool
{
    http_response_code($status);
    header('Content-Type: application/json');
    header('Cache-Control: ' . $cache);
    if ($origin !== '') {
        header("Access-Control-Allow-Origin: $origin");
        header('Vary: Origin');
    }
    echo json_encode($body, JSON_UNESCAPED_SLASHES);

    return true;
}

function stand_in_redirect(string $url): bool
{
    http_response_code(302);
    header("Location: $url");

    return true;
}

function stand_in_b64(string $bytes): string
{
    return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
}

function stand_in_sign(array $claims, array $key, string $typ): string
{
    $head = stand_in_b64(json_encode(['alg' => 'RS256', 'typ' => $typ, 'kid' => $key['kid']], JSON_UNESCAPED_SLASHES));
    $payload = stand_in_b64(json_encode($claims, JSON_UNESCAPED_SLASHES));
    openssl_sign("$head.$payload", $signature, $key['private_pem'], OPENSSL_ALGO_SHA256);

    return "$head.$payload." . stand_in_b64($signature);
}

/** The hint a browser sent, when it is one: a value of the hint's shape (anything else counts as none, as at the IAM). */
function stand_in_hint_sent(array $state): ?string
{
    $name = $state['hint_cookie'];
    $value = is_string($name) ? $_COOKIE[$name] ?? null : null;

    return is_string($value) && preg_match(STAND_IN_HINT_SHAPE, $value) === 1 ? $value : null;
}

/** Sets a new hint, as the IAM does when a session opens: 16 random bytes in base64url, Path=/, SameSite=Lax, readable by script. */
function stand_in_set_hint(array &$state): void
{
    if (!is_string($state['hint_cookie'])) {
        return;
    }
    $value = stand_in_b64(random_bytes(16));
    header('Set-Cookie: ' . $state['hint_cookie'] . '=' . $value . '; Path=/; SameSite=Lax', false);
    $state['log'][] = 'hint set';
}

function stand_in_clear_hint(array &$state): void
{
    if (!is_string($state['hint_cookie'])) {
        return;
    }
    header('Set-Cookie: ' . $state['hint_cookie'] . '=; Path=/; Max-Age=0; Expires=Thu, 01 Jan 1970 00:00:00 GMT; SameSite=Lax', false);
    $state['log'][] = 'hint cleared';
}

/** The `sub` of the bearer token sent - read, not verified: the stand-in only ever deals with its own tokens. */
function stand_in_bearer_sub(): ?string
{
    if (preg_match('/^Bearer [\w-]+\.([\w-]+)\.[\w-]+$/', (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? ''), $m) !== 1) {
        return null;
    }
    $claims = json_decode((string) base64_decode(strtr($m[1], '-_', '+/')), true);

    return is_array($claims) && is_string($claims['sub'] ?? null) ? $claims['sub'] : null;
}

/** $user with the saved $changes: the language its locale, the rest its preferences (a session from before them has none yet). */
function stand_in_with_preferences(array $user, array $changes): array
{
    if (array_key_exists('language', $changes)) {
        $user['locale'] = $changes['language'];
    }
    unset($changes['language']);
    $user['preferences'] = $changes + (is_array($user['preferences'] ?? null) ? $user['preferences'] : ['theme' => 'auto', 'date_format' => null, 'time_format' => null]);

    return $user;
}

/** An access token, an ID token and a new refresh token for $user. */
function stand_in_tokens(array &$state, array $user, array $key, string $issuer, string $clientId, ?string $nonce, string $sid, int $authTime): array
{
    $now = time();
    $common =
        [
            'iss' => $issuer,
            'sub' => (string) $user['id'],
            'aud' => $clientId,
            'iat' => $now,
            'sid' => $sid,
            'auth_time' => $authTime,
            'preferred_username' => $user['username'],
            'email' => $user['username'] . '@example.test',
            'email_verified' => true,
            'locale' => is_string($user['locale'] ?? null) ? $user['locale'] : 'en',
            'roles' => $user['roles'],
        ] + (is_array($user['preferences'] ?? null) ? ['preferences' => $user['preferences']] : []);
    $access = stand_in_sign($common + ['client_id' => $clientId, 'exp' => $now + (int) $state['ttl'], 'jti' => bin2hex(random_bytes(8)), 'scope' => 'openid profile email'], $key, 'at+jwt');
    $id = stand_in_sign($common + ['azp' => $clientId, 'exp' => $now + 3600] + ($nonce !== null && $nonce !== '' ? ['nonce' => $nonce] : []), $key, 'JWT');
    $refresh = 'iam_rt_' . bin2hex(random_bytes(16));
    $state['refresh'][$refresh] = ['user' => $user, 'client' => $clientId, 'sid' => $sid, 'auth_time' => $authTime];

    return ['access_token' => $access, 'token_type' => 'Bearer', 'expires_in' => (int) $state['ttl'], 'refresh_token' => $refresh, 'id_token' => $id, 'scope' => 'openid profile email'];
}

/** A `since` as the IAM reads it - ISO 8601 from 1970 on, or a date alone (midnight UTC) - as a Unix time; null when it is none. */
function stand_in_since(string $since): ?int
{
    if (preg_match('/^(\d{4})-(\d\d)-(\d\d)(?:T(\d\d):(\d\d):(\d\d)(?:\.\d+)?(Z|[+-]\d\d:\d\d))?$/D', $since, $m) !== 1 || !checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
        return null;
    }
    $time = strtotime(isset($m[4]) && $m[4] !== '' ? $since : $since . 'T00:00:00Z');

    return $time === false || $time < 0 ? null : $time;
}

$key = $keyFile !== '' ? json_decode((string) file_get_contents($keyFile), true) : ['jwks' => json_decode((string) file_get_contents($jwksOnly), true)];
$state = stand_in_state($dir);
$method = (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET');
$path = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
$origin = (string) ($_SERVER['HTTP_ORIGIN'] ?? '');
$state['log'][] = "$method $path";
$state['log'] = array_slice($state['log'], -200);

if ($jwksOnly !== '' && $path !== '/.well-known/openid-configuration' && $path !== '/.well-known/jwks.json') {
    // A key set alone signs nobody in.
    return stand_in_json(404, ['error' => 'not_found']);
}

if ($path === '/_test/state') {
    if ($method === 'POST') {
        $changes = json_decode((string) file_get_contents('php://input'), true);
        $state = array_replace($state, is_array($changes) ? $changes : []);
    }
    stand_in_save($dir, $state);

    return stand_in_json(200, $state);
}

if ($path === '/_test/sign-in' || $path === '/_test/sign-out') {
    $state['session'] = $path === '/_test/sign-in';
    if ($state['session']) {
        stand_in_set_hint($state);
    } else {
        $state['refresh'] = [];
        stand_in_clear_hint($state);
    }
    stand_in_save($dir, $state);
    $return = (string) ($_GET['return'] ?? '');

    return $return !== '' ? stand_in_redirect($return) : stand_in_json(200, ['session' => $state['session']]);
}

if ($state['down'] && $path !== '/.well-known/jwks.json') {
    stand_in_save($dir, $state);

    return stand_in_json(503, ['error' => 'temporarily_unavailable'], $origin);
}

// Any answer at all, for a path a test names: {"answers": {"/path": {"status": 200, "body": "<html>a proxy's page</html>"}}}.
$answer = is_array($state['answers'] ?? null) ? $state['answers'][$path] ?? null : null;
if (is_array($answer)) {
    stand_in_save($dir, $state);
    http_response_code((int) ($answer['status'] ?? 200));
    header('Content-Type: ' . (string) ($answer['type'] ?? 'application/json'));
    if (isset($answer['location'])) {
        header('Location: ' . (string) $answer['location']);
    }
    echo (string) ($answer['body'] ?? '');

    return true;
}

if ($method === 'GET' && preg_match('#^/api/public/applications/([a-z0-9][a-z0-9-]{1,63})$#D', $path, $m) === 1) {
    stand_in_save($dir, $state);
    if ($state['application_missing']) {
        return stand_in_json(404, ['error' => 'not_found']);
    }

    return stand_in_json(200, ['client_id' => $m[1], 'name' => (string) $state['application_name'], 'login_required' => (bool) $state['login_required']], '', 'public, max-age=60');
}

switch ($path) {
    case '/.well-known/openid-configuration':
        stand_in_save($dir, $state);

        return stand_in_json(
            200,
            [
                'issuer' => $issuer,
                'authorization_endpoint' => "$issuer/api/oidc/authorize",
                'token_endpoint' => "$issuer/api/oidc/token",
                'userinfo_endpoint' => "$issuer/api/oidc/userinfo",
                'jwks_uri' => "$issuer/.well-known/jwks.json",
                'end_session_endpoint' => "$issuer/api/oidc/logout",
                'revocation_endpoint' => "$issuer/api/oidc/revoke",
                'response_types_supported' => ['code'],
                'code_challenge_methods_supported' => ['S256'],
                'prompt_values_supported' => ['none', 'login', 'create'],
                'ui_locales_supported' => STAND_IN_PREFERENCES['language'],
            ],
            $origin !== '' ? $origin : '*',
        );

    case '/.well-known/jwks.json':
        stand_in_save($dir, $state);

        return stand_in_json(200, $key['jwks'], '*');

    case '/api/public/anonymized-accounts':
        stand_in_save($dir, $state);
        $since = isset($_GET['since']) ? stand_in_since((string) $_GET['since']) : null;
        $after = $_GET['after'] ?? null;
        if ((isset($_GET['since']) && $since === null) || ($after !== null && ($since === null || !is_string($after) || preg_match('/^[1-9][0-9]{0,17}$/D', $after) !== 1))) {
            return stand_in_json(400, ['error' => 'invalid_request', 'error_description' => 'since or after is not what the IAM takes']);
        }
        $accounts = array_values(array_filter(is_array($state['anonymized']) ? $state['anonymized'] : [], 'is_array'));
        usort($accounts, static fn(array $a, array $b): int => [strtotime((string) $a['anonymized_at']), (int) $a['sub']] <=> [strtotime((string) $b['anonymized_at']), (int) $b['sub']]);
        $accounts = array_values(
            array_filter($accounts, static function (array $account) use ($since, $after): bool {
                $at = (int) strtotime((string) $account['anonymized_at']);
                if ($since === null) {
                    return true;
                }

                return $after === null ? $at >= $since : $at > $since || ($at === $since && (int) $account['sub'] > (int) $after);
            }),
        );
        $size = max(1, (int) $state['anonymized_page']);
        $page = array_map(
            static fn(array $account): array => ['sub' => (string) $account['sub'], 'anonymized_at' => gmdate('Y-m-d\TH:i:s\Z', (int) strtotime((string) $account['anonymized_at']))],
            array_slice($accounts, 0, $size),
        );
        $last = $page === [] ? null : $page[count($page) - 1];

        return stand_in_json(
            200,
            ['accounts' => $page, 'next' => count($accounts) > $size && $last !== null ? ['since' => $last['anonymized_at'], 'after' => $last['sub']] : null],
            '',
            'public, max-age=60',
        );

    case '/api/oidc/authorize':
        $redirect = (string) ($_GET['redirect_uri'] ?? '');
        $query = ['state' => (string) ($_GET['state'] ?? ''), 'iss' => $issuer];
        $prompt = (string) ($_GET['prompt'] ?? '');
        $hint = stand_in_hint_sent($state);
        if ($redirect === '' || ($_GET['response_type'] ?? '') !== 'code') {
            stand_in_save($dir, $state);

            return stand_in_json(400, ['error' => 'invalid_request']);
        }
        if (($_GET['code_challenge_method'] ?? '') !== 'S256' || (string) ($_GET['code_challenge'] ?? '') === '') {
            stand_in_save($dir, $state);

            return stand_in_redirect($redirect . '?' . http_build_query(['error' => 'invalid_request', 'error_description' => 'PKCE S256 is required'] + $query));
        }
        if ($prompt === 'none') {
            $error = !$state['session'] ? 'login_required' : ($state['interaction'] ? 'interaction_required' : ($state['deny'] ? 'access_denied' : null));
            if ($error !== null) {
                // The stale-hint clean-up: no live session behind a hint that was sent.
                if ($error === 'login_required' && $hint !== null) {
                    stand_in_clear_hint($state);
                }
                stand_in_save($dir, $state);

                return stand_in_redirect($redirect . '?' . http_build_query(['error' => $error] + $query));
            }
            if ($hint === null) {
                stand_in_set_hint($state);
            }
        } else {
            if ($state['deny']) {
                stand_in_save($dir, $state);

                return stand_in_redirect($redirect . '?' . http_build_query(['error' => 'access_denied', 'error_description' => (string) $state['deny_description']] + $query));
            }
            if (!$state['session'] || $prompt === 'login' || $prompt === 'create') {
                // The person signs in at the IAM: a new session, a new hint.
                $state['session'] = true;
                $state['auth_time'] = time();
                stand_in_set_hint($state);
            } elseif ($hint === null) {
                stand_in_set_hint($state);
            }
        }
        $code = bin2hex(random_bytes(16));
        $state['codes'][$code] = [
            'client' => (string) ($_GET['client_id'] ?? ''),
            'redirect' => $redirect,
            'nonce' => (string) ($_GET['nonce'] ?? ''),
            'challenge' => (string) $_GET['code_challenge'],
            'at' => time(),
            'auth_time' => (int) ($state['auth_time'] ?? time()),
        ];
        stand_in_save($dir, $state);

        return stand_in_redirect($redirect . '?' . http_build_query(['code' => $code] + $query));

    case '/api/oidc/token':
        if ($method === 'OPTIONS') {
            http_response_code(204);
            header("Access-Control-Allow-Origin: $origin");
            header('Access-Control-Allow-Headers: Content-Type');
            stand_in_save($dir, $state);

            return true;
        }
        $grant = (string) ($_POST['grant_type'] ?? '');
        $clientId = (string) ($_POST['client_id'] ?? '');
        if (isset($_POST['client_secret'])) {
            stand_in_save($dir, $state);

            return stand_in_json(401, ['error' => 'invalid_client', 'error_description' => 'public clients have no secret'], $origin);
        }
        if ($grant === 'authorization_code') {
            $presented = (string) ($_POST['code'] ?? '');
            $code = $state['codes'][$presented] ?? null;
            unset($state['codes'][$presented]);
            $verifier = (string) ($_POST['code_verifier'] ?? '');
            if (
                $code === null ||
                $code['client'] !== $clientId ||
                $code['redirect'] !== (string) ($_POST['redirect_uri'] ?? '') ||
                $code['at'] < time() - 60 ||
                !hash_equals($code['challenge'], stand_in_b64(hash('sha256', $verifier, true)))
            ) {
                stand_in_save($dir, $state);

                return stand_in_json(400, ['error' => 'invalid_grant', 'error_description' => 'bad code'], $origin);
            }
            $tokens = stand_in_tokens($state, $state['user'], $key, $issuer, $clientId, $code['nonce'], bin2hex(random_bytes(8)), (int) $code['auth_time']);
            stand_in_save($dir, $state);

            return stand_in_json(200, $tokens, $origin);
        }
        if ($grant === 'refresh_token') {
            $presented = (string) ($_POST['refresh_token'] ?? '');
            $entry = $state['refresh'][$presented] ?? null;
            unset($state['refresh'][$presented]);
            if ($state['refuse'] || $entry === null || $entry['client'] !== $clientId) {
                stand_in_save($dir, $state);

                return stand_in_json(400, ['error' => 'invalid_grant', 'error_description' => 'the session has ended'], $origin);
            }
            $tokens = stand_in_tokens($state, $entry['user'], $key, $issuer, $clientId, null, $entry['sid'], (int) ($entry['auth_time'] ?? time()));
            stand_in_save($dir, $state);

            return stand_in_json(200, $tokens, $origin);
        }
        stand_in_save($dir, $state);

        return stand_in_json(400, ['error' => 'unsupported_grant_type'], $origin);

    case '/api/oidc/revoke':
        unset($state['refresh'][(string) ($_POST['token'] ?? '')]);
        stand_in_save($dir, $state);

        return stand_in_json(200, new stdClass(), $origin);

    // A site saving what its signed-in reader chose: the bearer token's own account - the person authorize
    // signs in, and every session of theirs here, so a refresh carries it.
    case '/api/oidc/preferences':
        stand_in_save($dir, $state);
        if ($method === 'OPTIONS') {
            http_response_code(204);
            header("Access-Control-Allow-Origin: $origin");
            header('Access-Control-Allow-Methods: POST');
            header('Access-Control-Allow-Headers: Authorization, Content-Type');
            header('Vary: Origin');

            return true;
        }
        $sub = stand_in_bearer_sub();
        $holders = array_filter($state['refresh'], static fn(array $entry): bool => (string) $entry['user']['id'] === $sub);
        $account = (string) $state['user']['id'] === $sub ? $state['user'] : (reset($holders) ?: ['user' => null])['user'];
        if ($method !== 'POST' || $account === null) {
            return stand_in_json(401, ['error' => 'invalid_token'], $origin);
        }
        if (!is_array($account['preferences'] ?? null)) {
            return stand_in_json(503, ['error' => 'temporarily_unavailable', 'error_description' => 'The preferences are not available yet'], $origin);
        }
        $changes = json_decode((string) file_get_contents('php://input'), true);
        $valid = is_array($changes) && $changes !== [] && !array_is_list($changes) && array_diff_key($changes, STAND_IN_PREFERENCES) === [];
        foreach ($valid ? $changes : [] as $name => $value) {
            $valid = $valid && in_array($value, STAND_IN_PREFERENCES[$name], true);
        }
        if (!$valid) {
            return stand_in_json(400, ['error' => 'invalid_request', 'error_description' => 'Not a change the account takes'], $origin);
        }
        $account = stand_in_with_preferences($account, $changes);
        if ((string) $state['user']['id'] === $sub) {
            $state['user'] = $account;
        }
        // Every session of the account carries the account's choices from its next refresh.
        foreach (array_keys($holders) as $token) {
            $state['refresh'][$token]['user']['preferences'] = $account['preferences'];
            $state['refresh'][$token]['user']['locale'] = $account['locale'] ?? 'en';
        }
        stand_in_save($dir, $state);

        return stand_in_json(200, ['locale' => $account['locale'] ?? 'en', 'preferences' => $account['preferences']], $origin);

    case '/api/oidc/logout':
        $state['session'] = false;
        $state['refresh'] = [];
        stand_in_clear_hint($state);
        stand_in_save($dir, $state);
        $to = (string) ($_GET['post_logout_redirect_uri'] ?? '');

        return $to !== '' ? stand_in_redirect($to) : stand_in_json(200, ['signed_out' => true]);

    case '/account':
        stand_in_save($dir, $state);
        header('Content-Type: text/html; charset=utf-8');
        echo '<!doctype html><title>Stand-in IAM account</title><h1>Stand-in IAM account page</h1>';

        return true;
}

stand_in_save($dir, $state);

return stand_in_json(404, ['error' => 'not_found']);
