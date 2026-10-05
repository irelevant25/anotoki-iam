<?php

/**
 * The SDK's own checks: php sdk/php/test.php
 *
 * The verifier: makes a key pair, publishes it as a JWKS file, and throws
 * tokens at anotoki_iam_verify_access_token() - the good one, and each way a
 * token can be wrong.
 *
 * The sign-in reader, anotoki_iam_login_required(): its two pure decisions
 * with every kind of answer, the application_file seam, the kept answer on
 * disk, the request's memo, and - against a stand-in IAM this script starts on
 * a free local port with PHP's built-in server - the fetches themselves: how
 * many there are, the back-off, and the single-flight claim.
 *
 * Runs on PHP 7.4 and up. Exit status 0 when every case comes out as it should.
 */

require __DIR__ . '/anotoki_iam.php';

$dir = sys_get_temp_dir() . '/anotoki-iam-sdk-test-' . bin2hex(random_bytes(4));
mkdir($dir);

$failures = 0;
$check = function ($name, $ok, $detail = '') use (&$failures) {
    $failures += $ok ? 0 : 1;
    printf("%s %s%s\n", $ok ? 'ok  ' : 'FAIL', $name, $ok || $detail === '' ? '' : ' - ' . $detail);
};

$pair = anotoki_iam_test_keypair('test-key');
$other = anotoki_iam_test_keypair('test-key');   // same kid, different key: a forgery
file_put_contents($dir . '/jwks.json', json_encode($pair['jwks']));

$config = [
    'issuer' => 'https://iam.example.test',
    'client_id' => 'app',
    'jwks_file' => $dir . '/jwks.json',
];

$good = anotoki_iam_test_claims($config, 7, ['EDITOR', 'editor', 'ADMIN']);
$sign = static fn(array $claims, string $typ = 'at+jwt', ?array $key = null, string $alg = 'RS256') =>
    anotoki_iam_test_sign($claims, ($key ?? $pair)['private_pem'], ($key ?? $pair)['kid'], $typ, $alg);

$cases = [
    'a good token' => [$sign($good), true],
    'an ID token (typ JWT)' => [$sign($good, 'JWT'), false],
    'alg none in the header' => [$sign($good, 'at+jwt', null, 'none'), false],
    'signed by another key with the same kid' => [$sign($good, 'at+jwt', $other), false],
    'another issuer' => [$sign(['iss' => 'https://evil.test'] + $good), false],
    'another audience' => [$sign(['aud' => 'other-app'] + $good), false],
    'audience list containing this app' => [$sign(['aud' => ['other-app', 'app']] + $good), true],
    'expired' => [$sign(['exp' => time() - 120] + $good), false],
    'expired within the leeway' => [$sign(['exp' => time() - 30] + $good), true],
    'not valid yet' => [$sign(['nbf' => time() + 600] + $good), false],
    'no subject' => [$sign(['sub' => ''] + $good), false],
    'subject not a number' => [$sign(['sub' => 'admin'] + $good), false],
    'unknown kid' => [anotoki_iam_test_sign($good, $pair['private_pem'], 'nope'), false],
    'tampered payload' => [(function () use ($sign, $good) {
        [$h, , $s] = explode('.', $sign($good));
        return $h . '.' . anotoki_iam_base64url_encode(json_encode(['sub' => '1'] + $good)) . '.' . $s;
    })(), false],
    'garbage' => ['not.a.token', false],
    'two parts' => ['abc.def', false],
];

foreach ($cases as $name => [$token, $shouldPass]) {
    $claims = anotoki_iam_verify_access_token($token, $config, $reason);
    $passed = $claims !== null;
    $ok = $passed === $shouldPass;
    $failures += $ok ? 0 : 1;
    printf("%s %-42s %s\n", $ok ? 'ok  ' : 'FAIL', $name, $passed ? 'accepted' : 'refused: ' . $reason);
}

// The helpers.
$claims = anotoki_iam_verify_access_token($sign($good), $config);
$check('user id is an int', anotoki_iam_user_id($claims) === 7);
$check('roles are cleaned', anotoki_iam_roles($claims) === ['EDITOR', 'ADMIN']);
$check('bearer is parsed', anotoki_iam_bearer_token('bearer abc.def.ghi') === 'abc.def.ghi');
$check('other schemes are not', anotoki_iam_bearer_token('Basic abc') === null);
$check('pem round trip', anotoki_iam_jwk_to_pem($pair['jwks']['keys'][0]) !== null);

// Whether a request claims to be somebody: the Bearer scheme, token or not.
foreach ([
    ['Bearer abc.def.ghi', true],
    ['bearer abc', true],
    ['  BEARER abc', true],
    ['Bearer', true],
    ['Bearer ', true],
    ['Basic dXNlcjpwYXNz', false],
    ['Bearerabc', false],
    ['', false],
    [null, false],
] as [$header, $expected]) {
    $check('sent_bearer(' . var_export($header, true) . ') is ' . var_export($expected, true), anotoki_iam_sent_bearer($header) === $expected);
}

// The download path, against a JWKS served from a file:// URL is not possible
// through curl's HTTP-only defaults, so the cache is exercised directly: a
// cached set is used without any download.
$cacheConfig = ['issuer' => 'https://iam.example.test', 'client_id' => 'app', 'cache_dir' => $dir];
$cacheFile = $dir . '/anotoki-iam-jwks-' . sha1('https://iam.example.test/.well-known/jwks.json') . '.json';
file_put_contents($cacheFile, json_encode(['fetched_at' => time(), 'keys' => anotoki_iam_usable_keys($pair['jwks'])]));
$cached = anotoki_iam_verify_access_token($sign($good), $cacheConfig, $reason) !== null;
$check('verifies from the on-disk cache', $cached, (string) $reason);

$check('the version is 1.1.0', ANOTOKI_IAM_SDK_VERSION === '1.1.0');

// ─────────────────────────────────────────────────────────────────────────────
// Is sign-in required here? What an answer means (pure).
// ─────────────────────────────────────────────────────────────────────────────

$now = 1700000000;
$kept = static fn(?bool $value, int $fetchedAt = 0, int $failedAt = 0, int $triedAt = 0): array =>
    ['login_required' => $value, 'fetched_at' => $fetchedAt, 'failed_at' => $failedAt, 'tried_at' => $triedAt];
$answer = static fn($value, string $clientId = 'app'): string =>
    json_encode(['client_id' => $clientId, 'name' => 'App', 'login_required' => $value]);
$from = static fn(int $status, ?string $body, ?array $cached): array =>
    anotoki_iam_login_required_from($status, $body, 'app', $cached, $now);

$meaning = [
    // name => [status, body, kept record, value, reason, kept login_required]
    '200 true' => [200, $answer(true), null, true, 'read', true],
    '200 false' => [200, $answer(false), null, false, 'read', false],
    '200 false over a kept true' => [200, $answer(false), $kept(true, $now - 100), false, 'read', false],
    '200 for another client_id, nothing kept' => [200, $answer(false, 'other'), null, true, 'never_read', null],
    '200 for another client_id, false kept' => [200, $answer(true, 'other'), $kept(false, $now - 100), false, 'stale', false],
    '404 - unknown or switched off' => [404, null, null, true, 'unknown_application', true],
    '404 over a kept false' => [404, null, $kept(false, $now - 100), true, 'unknown_application', true],
    '503 with false kept' => [503, null, $kept(false, $now - 100), false, 'stale', false],
    '503 with nothing kept' => [503, null, null, true, 'never_read', null],
    '500 with false kept' => [500, null, $kept(false, $now - 100), false, 'stale', false],
    '500 with nothing kept' => [500, null, null, true, 'never_read', null],
    'unreachable with false kept' => [0, null, $kept(false, $now - 100), false, 'stale', false],
    'unreachable with nothing kept' => [0, null, null, true, 'never_read', null],
    'a redirect' => [302, null, null, true, 'never_read', null],
    'garbage JSON' => [200, '<html>proxy error</html>', null, true, 'never_read', null],
    'garbage JSON, false kept' => [200, '{"client_id": "app", ', $kept(false, $now - 100), false, 'stale', false],
    'login_required "false" (a string)' => [200, $answer('false'), null, true, 'never_read', null],
    'login_required 0' => [200, $answer(0), null, true, 'never_read', null],
    'login_required null' => [200, $answer(null), null, true, 'never_read', null],
    'login_required missing' => [200, json_encode(['client_id' => 'app', 'name' => 'App']), null, true, 'never_read', null],
    'a JSON list' => [200, json_encode([false]), null, true, 'never_read', null],
    'a JSON scalar' => [200, 'false', null, true, 'never_read', null],
];

foreach ($meaning as $name => [$status, $body, $cachedRecord, $value, $reason, $keptValue]) {
    $outcome = $from($status, $body, $cachedRecord);
    $ok = $outcome['value'] === $value && $outcome['reason'] === $reason && $outcome['cache']['login_required'] === $keptValue;
    $check('answer: ' . $name, $ok, json_encode($outcome));
}

$read = $from(200, $answer(false), $kept(true, $now - 100, $now - 70));
$check('answer: a read keeps its time and clears a failure', $read['cache']['fetched_at'] === $now && $read['cache']['failed_at'] === 0);
$failed = $from(503, null, $kept(false, $now - 100));
$check('answer: a failure keeps its time and the read time', $failed['cache']['failed_at'] === $now && $failed['cache']['fetched_at'] === $now - 100);

// ─────────────────────────────────────────────────────────────────────────────
// Whether to ask (pure).
// ─────────────────────────────────────────────────────────────────────────────

$before = [
    // name => [kept record, fetch, value, reason]
    'nothing kept' => [null, true, true, 'fetch'],
    'a fresh false' => [$kept(false, $now - 10), false, false, 'fresh'],
    'a fresh true' => [$kept(true, $now - 59), false, true, 'fresh'],
    'false read 60 s ago' => [$kept(false, $now - 60), true, false, 'fetch'],
    'a read time in the future' => [$kept(false, $now + 30), true, false, 'fetch'],
    'the back-off after a failure, false kept' => [$kept(false, $now - 100, $now - 10), false, false, 'backing_off'],
    'the back-off after a failure, nothing kept' => [$kept(null, 0, $now - 10), false, true, 'backing_off'],
    'the back-off over' => [$kept(false, $now - 200, $now - 60), true, false, 'fetch'],
    'another request claimed it' => [$kept(false, $now - 100, 0, $now - 1), false, false, 'refreshing'],
    'another request claimed it, nothing kept' => [$kept(null, 0, 0, $now), false, true, 'refreshing'],
    'a claim left by a request that died' => [$kept(false, $now - 100, 0, $now - 6), true, false, 'fetch'],
    'a claim already answered' => [$kept(false, $now - 100, $now - 100, $now - 100), true, false, 'fetch'],
];

foreach ($before as $name => [$cachedRecord, $fetch, $value, $reason]) {
    $step = anotoki_iam_login_required_from_cache($cachedRecord, $now, 60, 2);
    $ok = $step['fetch'] === $fetch && $step['value'] === $value && $step['reason'] === $reason;
    $check('before asking: ' . $name, $ok, json_encode($step));
}

// ─────────────────────────────────────────────────────────────────────────────
// The function itself, without a network.
// ─────────────────────────────────────────────────────────────────────────────

$check('no issuer or client_id: sign-in required', anotoki_iam_login_required(['issuer' => ''], $reason) === true && $reason === 'not_configured');

// The tests' seam: the file is the IAM's answer, read every time, nothing kept.
$seamCache = $dir . '/seam-cache';
mkdir($seamCache);
$seam = ['issuer' => 'http://127.0.0.1:9', 'client_id' => 'app', 'cache_dir' => $seamCache, 'application_file' => $dir . '/application.json'];
file_put_contents($seam['application_file'], $answer(false));
$check('application_file: false', anotoki_iam_login_required($seam, $reason) === false && $reason === 'read', (string) $reason);
file_put_contents($seam['application_file'], $answer(true));
$check('application_file: true, read again at once', anotoki_iam_login_required($seam, $reason) === true && $reason === 'read', (string) $reason);
file_put_contents($seam['application_file'], $answer(false, 'other'));
$check('application_file: an answer for another client', anotoki_iam_login_required($seam, $reason) === true && $reason === 'never_read', (string) $reason);
unlink($seam['application_file']);
$check('application_file missing: unreachable, sign-in required', anotoki_iam_login_required($seam, $reason) === true && $reason === 'never_read', (string) $reason);
$check('application_file: nothing kept in cache_dir', glob($seamCache . '/*') === []);

// The kept answer and the request's memo. The issuer is a port nothing
// listens on: a fresh answer must not need it.
$offline = ['issuer' => 'http://127.0.0.1:9', 'client_id' => 'app', 'cache_dir' => $dir . '/offline-cache'];
$keptFile = static fn(string $clientId): string =>
    $offline['cache_dir'] . '/anotoki-iam-application-' . sha1('http://127.0.0.1:9 ' . $clientId) . '.json';
$offlineFile = $keptFile('app');
mkdir($offline['cache_dir']);
file_put_contents($offlineFile, json_encode($kept(false, time() - 5)));
anotoki_iam_login_required_reset();
$check('a fresh kept false, without asking', anotoki_iam_login_required($offline, $reason) === false && $reason === 'fresh', (string) $reason);
file_put_contents($offlineFile, json_encode($kept(true, time() - 5)));
$check('the memo: the same answer for the rest of the request', anotoki_iam_login_required($offline, $reason) === false && $reason === 'fresh');
file_put_contents($keptFile('another'), json_encode($kept(true, time() - 5)));
$check('the memo is per client', anotoki_iam_login_required(['client_id' => 'another'] + $offline, $reason) === true && $reason === 'fresh', (string) $reason);
anotoki_iam_login_required_reset();
$check('the memo reset: the kept answer read again', anotoki_iam_login_required($offline, $reason) === true && $reason === 'fresh', (string) $reason);
file_put_contents($offlineFile, '{"login_required": "false", "fetched_at": ' . time() . '}');
$step = anotoki_iam_login_required_from_cache(anotoki_iam_login_required_read($offlineFile), time(), 60, 2);
$check('a kept non-bool counts as never read', $step['fetch'] === true && $step['value'] === true);

// The single-flight claim, without a network: a stale answer, and another
// request holding the lock or a fresh claim. Asking would fail against port 9
// and write failed_at, so an unchanged file proves nobody asked.
file_put_contents($offlineFile, json_encode($kept(false, time() - 600)));
$lock = fopen($offlineFile . '.lock', 'c');
flock($lock, LOCK_EX);
anotoki_iam_login_required_reset();
$value = anotoki_iam_login_required($offline, $reason);
$untouched = anotoki_iam_login_required_read($offlineFile)['failed_at'] === 0;
$check('single flight: the lock held elsewhere, the last value', $value === false && $reason === 'refreshing' && $untouched, (string) $reason);
flock($lock, LOCK_UN);
fclose($lock);
file_put_contents($offlineFile, json_encode($kept(false, time() - 600, 0, time())));
anotoki_iam_login_required_reset();
$value = anotoki_iam_login_required($offline, $reason);
$untouched = anotoki_iam_login_required_read($offlineFile)['failed_at'] === 0;
$check('single flight: a claim written by another request, the last value', $value === false && $reason === 'refreshing' && $untouched, (string) $reason);

// ─────────────────────────────────────────────────────────────────────────────
// Against a stand-in IAM on a free local port.
// ─────────────────────────────────────────────────────────────────────────────

$server = startStandIn($dir . '/stand-in');

if ($server === null) {
    echo "skip the stand-in IAM's cases: PHP's built-in server did not start here\n";
} else {
    [$process, $port] = $server;
    $issuer = 'http://127.0.0.1:' . $port;
    $answers = $dir . '/stand-in/answer.json';
    $hitsFile = $dir . '/stand-in/hits.log';
    $say = static function (int $status, string $body) use ($answers) {
        file_put_contents($answers, json_encode(['status' => $status, 'body' => $body]));
    };
    $hits = static fn(): array => is_file($hitsFile) ? file($hitsFile, FILE_IGNORE_NEW_LINES) : [];
    $live = ['issuer' => $issuer . '/', 'client_id' => 'app', 'cache_dir' => $dir . '/live-cache'];
    $liveFile = $live['cache_dir'] . '/anotoki-iam-application-' . sha1($issuer . ' app') . '.json';
    $age = static function (array $changes) use ($liveFile) {
        $record = anotoki_iam_login_required_read($liveFile);
        file_put_contents($liveFile, json_encode(array_merge($record, $changes)));
    };
    $ask = static function (array $config) use (&$reason) {
        anotoki_iam_login_required_reset();
        return anotoki_iam_login_required($config, $reason);
    };

    // http_get's status.
    $say(200, $answer(false));
    $body = anotoki_iam_http_get($issuer . '/api/public/applications/app', 2, $status);
    $check('http_get: a 200 gives its body and status', $status === 200 && $body === $answer(false), $status . ' ' . var_export($body, true));
    $say(404, '{"error":"not_found"}');
    $body = anotoki_iam_http_get($issuer . '/api/public/applications/app', 2, $status);
    $check('http_get: a 404 gives its status and no body', $status === 404 && $body === null, (string) $status);
    $say(503, '{"error":"unavailable"}');
    $body = anotoki_iam_http_get($issuer . '/api/public/applications/app', 2, $status);
    $check('http_get: a 503 gives its status and no body', $status === 503 && $body === null, (string) $status);
    $check('http_get: still callable with two arguments', anotoki_iam_http_get($issuer . '/x', 2) === null);
    @unlink($hitsFile);

    // Read, kept, fresh.
    $say(200, $answer(false));
    $value = $ask($live);
    $check('live: false read from the IAM', $value === false && $reason === 'read' && count($hits()) === 1, (string) $reason);
    $check('live: asked at the contract\'s address', $hits() === ['/api/public/applications/app'], json_encode($hits()));
    $check('live: kept in cache_dir', anotoki_iam_login_required_read($liveFile)['login_required'] === false);
    $value = $ask($live);
    $check('live: a fresh answer is not asked again', $value === false && $reason === 'fresh' && count($hits()) === 1, (string) $reason);

    // A 5xx: the last value, then the back-off.
    $age(['fetched_at' => time() - 61]);
    $say(503, '{"error":"unavailable"}');
    $value = $ask($live);
    $check('live: a 503 keeps the last value', $value === false && $reason === 'stale' && count($hits()) === 2, (string) $reason);
    $value = $ask($live);
    $check('live: not asked again for a minute after a failure', $value === false && $reason === 'backing_off' && count($hits()) === 2, (string) $reason);

    // A garbage 200 once the back-off is over: the last value.
    $age(['failed_at' => time() - 61]);
    $say(200, '<html>a proxy\'s page</html>');
    $value = $ask($live);
    $check('live: a garbage 200 keeps the last value', $value === false && $reason === 'stale' && count($hits()) === 3, (string) $reason);

    // A 404: sign-in required, kept.
    $age(['failed_at' => time() - 61]);
    $say(404, '{"error":"not_found"}');
    $value = $ask($live);
    $check('live: a 404 is sign-in required', $value === true && $reason === 'unknown_application' && count($hits()) === 4, (string) $reason);
    $value = $ask($live);
    $check('live: and kept like an answer', $value === true && $reason === 'fresh' && count($hits()) === 4, (string) $reason);

    // Single flight against a live IAM: nobody asks while the lock is held.
    $age(['login_required' => false, 'fetched_at' => time() - 61]);
    $say(200, $answer(true));
    $lock = fopen($liveFile . '.lock', 'c');
    flock($lock, LOCK_EX);
    $value = $ask($live);
    $check('live: the lock held elsewhere - not asked, the last value', $value === false && $reason === 'refreshing' && count($hits()) === 4, (string) $reason);
    flock($lock, LOCK_UN);
    fclose($lock);
    $value = $ask($live);
    $check('live: the lock released - asked', $value === true && $reason === 'read' && count($hits()) === 5, (string) $reason);

    // A folder that cannot be written: still answered, logged, asked every time.
    file_put_contents($dir . '/a-file', '');
    $unwritable = ['cache_dir' => $dir . '/a-file/cache'] + $live;
    $previousLog = ini_set('error_log', $dir . '/error.log');
    $value = $ask($unwritable);
    $value2 = $ask($unwritable);
    ini_set('error_log', (string) $previousLog);
    $logged = is_file($dir . '/error.log') && strpos((string) file_get_contents($dir . '/error.log'), 'cannot write') !== false;
    $check('live: an unwritable cache folder - answered, logged, asked each request', $value === true && $value2 === true && count($hits()) === 7 && $logged, count($hits()) . ' hits');

    // The IAM gone.
    proc_terminate($process);
    proc_close($process);
    $age(['login_required' => false, 'fetched_at' => time() - 61]);
    $value = $ask($live);
    $check('live: unreachable keeps the last value', $value === false && $reason === 'stale', (string) $reason);
    $value = $ask(['client_id' => 'never-read'] + $live);
    $check('live: unreachable with nothing ever read - sign-in required', $value === true && $reason === 'never_read', (string) $reason);
    anotoki_iam_http_get($issuer . '/api/public/applications/app', 1, $status);
    $check('http_get: unreachable is status 0', $status === 0, (string) $status);
}

removeTree($dir);

echo $failures === 0 ? "\nall good\n" : "\n$failures failed\n";
exit($failures === 0 ? 0 : 1);

/**
 * PHP's built-in server on a free local port, answering every request with
 * answer.json's {status, body} and logging each path to hits.log:
 * [process, port], or null when it cannot be started.
 */
function startStandIn(string $folder): ?array
{
    if (!function_exists('proc_open')) {
        return null;
    }

    mkdir($folder);
    file_put_contents($folder . '/router.php', <<<'PHP'
<?php
file_put_contents(__DIR__ . '/hits.log', $_SERVER['REQUEST_URI'] . "\n", FILE_APPEND);
$answer = json_decode((string) file_get_contents(__DIR__ . '/answer.json'), true);
http_response_code($answer['status']);
header('Content-Type: application/json');
echo $answer['body'];
PHP
    );
    file_put_contents($folder . '/answer.json', json_encode(['status' => 503, 'body' => '{}']));

    $probe = @stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
    if ($probe === false) {
        return null;
    }
    $name = stream_socket_get_name($probe, false);
    fclose($probe);
    $port = (int) substr($name, strrpos($name, ':') + 1);

    $process = proc_open(
        [PHP_BINARY, '-S', '127.0.0.1:' . $port, $folder . '/router.php'],
        [0 => ['pipe', 'r'], 1 => ['file', $folder . '/server.out', 'w'], 2 => ['file', $folder . '/server.err', 'w']],
        $pipes,
        $folder
    );
    if (!is_resource($process)) {
        return null;
    }

    for ($i = 0; $i < 50; $i++) {
        $socket = @fsockopen('127.0.0.1', $port, $errno, $error, 0.2);
        if ($socket !== false) {
            fclose($socket);
            return [$process, $port];
        }
        usleep(100000);
    }

    proc_terminate($process);
    proc_close($process);

    return null;
}

function removeTree(string $path): void
{
    if (is_dir($path) && !is_link($path)) {
        foreach (scandir($path) as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                removeTree($path . '/' . $entry);
            }
        }
        @rmdir($path);
    } else {
        @unlink($path);
    }
}
