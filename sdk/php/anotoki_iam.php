<?php

/**
 * anotoki_iam.php - checks anotoki IAM access tokens, and reads       v1.1.0
 * whether the IAM says a site requires sign-in.
 *
 * One file, no dependencies, plain functions, for any PHP backend of an anotoki
 * site: copy it in and require it. It parses under PHP 7.4 (no match, no
 * nullsafe operator, no PHP 8-only functions) because one of the projects keeps
 * its editor on 7.4; it runs on anything from 7.4 up, and needs the openssl
 * extension. Nothing in it is deprecated up to PHP 8.5, so it stays quiet
 * in a site that turns every notice into an error.
 *
 * What a valid token is - all of these, or nothing:
 *
 *   - three base64url parts, a JSON header and payload
 *   - header alg RS256 and typ at+jwt. The algorithm is pinned here rather than
 *     read from the token: a token is never allowed to say how it should be
 *     checked, which is how "alg: none" and HMAC-with-the-public-key get in.
 *     The typ is what stops an ID token - also signed by the IAM, also naming
 *     this client as its audience - from being replayed as an access token.
 *   - signed by a key in the IAM's published key set (JWKS), found by kid
 *   - iss is the configured issuer, aud is (or contains) the configured client
 *   - exp in the future, iat/nbf not in it, with a little leeway for clocks
 *   - sub is a positive integer (the IAM user id)
 *
 * Every failure returns null. The reason is available through the by-reference
 * argument for a log line; it is never worth telling the caller which check
 * failed, because to anybody holding a token that did not work the answer is
 * the same.
 *
 * The key set is cached on disk for an hour. A kid that is not in the cache is
 * worth one refetch - the IAM has just rotated its key - but not more often
 * than every five minutes, so a stream of made-up kids cannot turn every
 * request into a download.
 *
 * Whether a site requires sign-in is the IAM's to say, per application
 * (anotoki_iam_login_required(), further down): read from the IAM's public
 * answer, kept on disk for a minute, and true - sign-in required - whenever
 * nothing better is known.
 *
 * Configuration (an array):
 *
 *   issuer               required  the IAM's address, http://localhost:4300 in development
 *   client_id            required  this application's client id
 *   jwks_url             optional  default {issuer}/.well-known/jwks.json
 *   jwks_file            optional  a JWKS on disk instead of downloading one (tests, offline)
 *   cache_dir            optional  where the key set and the application's answer are
 *                                  cached; default the system temp dir. Give a PRIVATE
 *                                  folder: see anotoki_iam_login_required()
 *   cache_ttl            optional  seconds the key set is kept, default 3600
 *   leeway               optional  seconds of clock slack, default 60
 *   http_timeout         optional  seconds, default 5 (the key set)
 *   application_ttl      optional  seconds the sign-in answer is kept, default 60
 *   application_timeout  optional  seconds, default 2 (the sign-in answer: asked on a
 *                                  page's way through, so it waits less than the key set)
 *   application_file     optional  the IAM's answer on disk instead of asking it (tests)
 */

const ANOTOKI_IAM_SDK_VERSION = '1.1.0';

/**
 * The token out of an Authorization header, or null.
 *
 * Takes the header's value (or null). Case-insensitive on the scheme, as RFC
 * 6750 allows, and nothing else is accepted - a token in a query string ends up
 * in logs and browser history.
 */
function anotoki_iam_bearer_token($header)
{
    if (!is_string($header)) {
        return null;
    }

    $header = trim($header);

    if (strncasecmp($header, 'Bearer ', 7) !== 0) {
        return null;
    }

    $token = trim(substr($header, 7));

    return $token === '' ? null : $token;
}

/**
 * Whether an Authorization header claims to be somebody: its scheme is Bearer,
 * with or without a token after it.
 *
 * A site that also lets visitors in decides with this, before anything else,
 * that a request is a person's: a token that does not verify then answers 401,
 * and never falls back to being a visitor - a visitor's key is not even read.
 * Only Bearer counts: `Basic ...` is what a host protected by a password file
 * sends with every request, and says nothing about who is using the site.
 */
function anotoki_iam_sent_bearer($header)
{
    return is_string($header) && preg_match('/^\s*Bearer(?:\s|$)/i', $header) === 1;
}

/**
 * The Authorization header of the current request, however the server passed it.
 *
 * Apache under CGI/FastCGI drops the header unless told otherwise; a rewrite
 * rule that copies it into the environment (see the README) makes it arrive as
 * REDIRECT_HTTP_AUTHORIZATION. All three places are looked at.
 */
function anotoki_iam_authorization_header()
{
    foreach (array('HTTP_AUTHORIZATION', 'REDIRECT_HTTP_AUTHORIZATION') as $key) {
        if (isset($_SERVER[$key]) && is_string($_SERVER[$key]) && $_SERVER[$key] !== '') {
            return $_SERVER[$key];
        }
    }

    if (function_exists('getallheaders')) {
        foreach (getallheaders() as $name => $value) {
            if (strcasecmp($name, 'Authorization') === 0) {
                return $value;
            }
        }
    }

    return null;
}

/**
 * The verified claims of an access token, or null.
 *
 * @param string      $token  the bearer token
 * @param array       $config see the top of this file
 * @param string|null $reason set to why a null was returned, for logging
 */
function anotoki_iam_verify_access_token($token, array $config, &$reason = null)
{
    $reason = null;
    $token = (string) $token;
    $issuer = rtrim((string) (isset($config['issuer']) ? $config['issuer'] : ''), '/');
    $clientId = (string) (isset($config['client_id']) ? $config['client_id'] : '');
    $leeway = (int) (isset($config['leeway']) ? $config['leeway'] : 60);

    if ($issuer === '' || $clientId === '') {
        $reason = 'the IAM configuration has no issuer or client_id';
        return null;
    }

    $parts = explode('.', $token);
    if (count($parts) !== 3) {
        $reason = 'not a JWT';
        return null;
    }

    list($encodedHeader, $encodedPayload, $encodedSignature) = $parts;

    $header = anotoki_iam_decode_json($encodedHeader);
    $claims = anotoki_iam_decode_json($encodedPayload);
    $signature = anotoki_iam_base64url_decode($encodedSignature);

    if ($header === null || $claims === null || $signature === '') {
        $reason = 'unreadable header, payload or signature';
        return null;
    }

    if ((isset($header['alg']) ? $header['alg'] : '') !== 'RS256') {
        $reason = 'algorithm is not RS256';
        return null;
    }

    // RFC 9068 spells it "at+jwt"; the media-type form is accepted too.
    $typ = strtolower((string) (isset($header['typ']) ? $header['typ'] : ''));
    if ($typ !== 'at+jwt' && $typ !== 'application/at+jwt') {
        $reason = 'not an access token (typ ' . ($typ === '' ? 'missing' : $typ) . ')';
        return null;
    }

    $kid = (string) (isset($header['kid']) ? $header['kid'] : '');
    if ($kid === '') {
        $reason = 'no kid';
        return null;
    }

    $key = anotoki_iam_signing_key($config, $kid);
    if ($key === null) {
        $reason = 'no published key with kid ' . $kid;
        return null;
    }

    $pem = anotoki_iam_jwk_to_pem($key);
    if ($pem === null || openssl_verify($encodedHeader . '.' . $encodedPayload, $signature, $pem, OPENSSL_ALGO_SHA256) !== 1) {
        $reason = 'bad signature';
        return null;
    }

    if ((isset($claims['iss']) ? rtrim((string) $claims['iss'], '/') : '') !== $issuer) {
        $reason = 'issued by somebody else';
        return null;
    }

    $audience = isset($claims['aud']) ? $claims['aud'] : null;
    $audiences = is_array($audience) ? $audience : array($audience);
    if (!in_array($clientId, $audiences, true)) {
        $reason = 'meant for another application';
        return null;
    }

    $now = time();

    if (!isset($claims['exp']) || !is_numeric($claims['exp']) || (int) $claims['exp'] + $leeway < $now) {
        $reason = 'expired';
        return null;
    }

    if (isset($claims['nbf']) && is_numeric($claims['nbf']) && (int) $claims['nbf'] - $leeway > $now) {
        $reason = 'not valid yet';
        return null;
    }

    if (isset($claims['iat']) && is_numeric($claims['iat']) && (int) $claims['iat'] - $leeway > $now) {
        $reason = 'issued in the future';
        return null;
    }

    if (anotoki_iam_user_id($claims) === null) {
        $reason = 'no usable subject';
        return null;
    }

    return $claims;
}

/** The IAM user id a token names, or null. */
function anotoki_iam_user_id(array $claims)
{
    $sub = isset($claims['sub']) ? (string) $claims['sub'] : '';

    return ctype_digit($sub) && $sub !== '0' && strlen($sub) < 19 ? (int) $sub : null;
}

/** This application's roles for the token's user - uppercase, deduplicated, never null. */
function anotoki_iam_roles(array $claims)
{
    $roles = isset($claims['roles']) && is_array($claims['roles']) ? $claims['roles'] : array();
    $clean = array();

    foreach ($roles as $role) {
        if (is_string($role) && $role !== '') {
            $clean[strtoupper($role)] = true;
        }
    }

    return array_keys($clean);
}

// ─────────────────────────────────────────────────────────────────────────────
// The key set
// ─────────────────────────────────────────────────────────────────────────────

/** The JWK for a kid, refetching the set once if it is not there. */
function anotoki_iam_signing_key(array $config, $kid)
{
    $keys = anotoki_iam_jwks($config, false);

    if (!isset($keys[$kid]) && empty($config['jwks_file'])) {
        $keys = anotoki_iam_jwks($config, true);
    }

    return isset($keys[$kid]) ? $keys[$kid] : null;
}

/**
 * The IAM's signing keys by kid: RSA keys meant for signatures only.
 *
 * `$refresh` asks for a fresh copy, which is honoured at most every five
 * minutes. A key set that cannot be downloaded is not an error here: the last
 * cached one is used, and a token signed by a key not in it simply fails.
 */
function anotoki_iam_jwks(array $config, $refresh = false)
{
    if (!empty($config['jwks_file'])) {
        $document = json_decode((string) @file_get_contents((string) $config['jwks_file']), true);
        return anotoki_iam_usable_keys(is_array($document) ? $document : array());
    }

    $issuer = rtrim((string) $config['issuer'], '/');
    $url = !empty($config['jwks_url']) ? (string) $config['jwks_url'] : $issuer . '/.well-known/jwks.json';
    $ttl = (int) (isset($config['cache_ttl']) ? $config['cache_ttl'] : 3600);
    $directory = !empty($config['cache_dir']) ? (string) $config['cache_dir'] : sys_get_temp_dir();
    $cacheFile = rtrim($directory, '/\\') . '/anotoki-iam-jwks-' . sha1($url) . '.json';

    $cached = is_file($cacheFile) ? json_decode((string) @file_get_contents($cacheFile), true) : null;
    $cached = is_array($cached) ? $cached : null;
    $fetchedAt = $cached !== null ? (int) (isset($cached['fetched_at']) ? $cached['fetched_at'] : 0) : 0;
    $cachedKeys = $cached !== null && isset($cached['keys']) && is_array($cached['keys']) ? $cached['keys'] : array();

    $fresh = $cached !== null && $fetchedAt + $ttl > time();
    $mayRefetch = $fetchedAt + 300 < time();

    if ($fresh && (!$refresh || !$mayRefetch)) {
        return $cachedKeys;
    }

    if ($refresh && !$mayRefetch) {
        return $cachedKeys;
    }

    $body = anotoki_iam_http_get($url, (int) (isset($config['http_timeout']) ? $config['http_timeout'] : 5));
    $document = $body === null ? null : json_decode($body, true);

    if (!is_array($document) || !isset($document['keys'])) {
        return $cachedKeys;
    }

    $keys = anotoki_iam_usable_keys($document);

    if (is_dir($directory) || @mkdir($directory, 0775, true) || is_dir($directory)) {
        // Written beside and renamed over, so a request reading the cache while
        // another writes it never sees half a file.
        $temporary = $cacheFile . '.' . bin2hex(random_bytes(4)) . '.tmp';
        if (@file_put_contents($temporary, json_encode(array('fetched_at' => time(), 'keys' => $keys))) !== false) {
            @rename($temporary, $cacheFile);
        }
    }

    return $keys;
}

/** The RSA signature keys of a JWKS document, by kid. */
function anotoki_iam_usable_keys(array $document)
{
    $keys = array();

    foreach (isset($document['keys']) && is_array($document['keys']) ? $document['keys'] : array() as $key) {
        if (!is_array($key)) {
            continue;
        }
        if ((isset($key['kty']) ? $key['kty'] : '') !== 'RSA' || empty($key['kid']) || empty($key['n']) || empty($key['e'])) {
            continue;
        }
        if (isset($key['use']) && $key['use'] !== 'sig') {
            continue;
        }
        if (isset($key['alg']) && $key['alg'] !== 'RS256') {
            continue;
        }
        $keys[(string) $key['kid']] = array('kty' => 'RSA', 'kid' => (string) $key['kid'], 'n' => $key['n'], 'e' => $key['e']);
    }

    return $keys;
}

/**
 * A GET with a timeout, by curl when there is curl. The body of a 200, null for
 * anything else.
 *
 * `$status` is set to the answer's HTTP status, or 0 when there was no answer
 * at all (unreachable, timed out, refused) - which is how a caller tells "the
 * IAM said no" (a 404) from "the IAM is not there".
 */
function anotoki_iam_http_get($url, $timeout, &$status = null)
{
    $status = 0;

    if (function_exists('curl_init')) {
        $handle = curl_init($url);
        curl_setopt_array($handle, array(
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => $timeout,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_HTTPHEADER => array('Accept: application/json'),
        ));
        $body = curl_exec($handle);
        $status = $body === false ? 0 : (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        // No curl_close(): the handle goes with the variable, the call has done
        // nothing since PHP 8.0, and PHP 8.5 deprecates it.

        return is_string($body) && $status === 200 ? $body : null;
    }

    // fopen() rather than file_get_contents(), so the status line comes from
    // the stream itself: the magic variable file_get_contents() fills in for
    // it is deprecated in PHP 8.5, which says so as soon as the file is
    // compiled. No redirects, as with curl above.
    $context = stream_context_create(array('http' => array(
        'timeout' => $timeout,
        'ignore_errors' => true,
        'follow_location' => 0,
        'header' => "Accept: application/json\r\n",
    )));
    $stream = @fopen($url, 'rb', false, $context);

    if ($stream === false) {
        return null;
    }

    $meta = stream_get_meta_data($stream);
    $body = stream_get_contents($stream);
    fclose($stream);

    $headers = isset($meta['wrapper_data']) && is_array($meta['wrapper_data']) ? $meta['wrapper_data'] : array();
    $statusLine = isset($headers[0]) ? (string) $headers[0] : '';
    $status = preg_match('#^HTTP/\S+\s+(\d{3})\b#', $statusLine, $match) ? (int) $match[1] : 0;

    return is_string($body) && $status === 200 ? $body : null;
}

// ─────────────────────────────────────────────────────────────────────────────
// Is sign-in required here?
// ─────────────────────────────────────────────────────────────────────────────
//
// The IAM says, per application, whether its site requires sign-in:
// GET {issuer}/api/public/applications/{client_id} answers 200 {client_id, name,
// login_required}, or 404 for an application it does not know or has switched
// off. A site asks on its way through a request, so the answer is kept on disk
// for application_ttl, and the rule fails closed: unless the IAM has said "no
// sign-in needed" and nothing has said otherwise since, sign-in is required.
//
// The kept answer is what opens a site to visitors, so it belongs in a folder
// only the site can write. In a shared temp directory anybody else on the host
// could write login_required = false into it.

/**
 * Whether this site requires sign-in: true unless the IAM said otherwise.
 *
 *   - a 200 naming this client_id with a boolean login_required: that, kept
 *   - a 404 (an unknown or switched-off application): true, kept the same way
 *   - no answer, a 5xx, anything else: the last value kept, or true when none
 *     was ever read - and the IAM is not asked again for application_ttl
 *   - while one request asks, the others use the last value: one fetch when
 *     the minute runs out, not one per request
 *   - the answer holds for the rest of the request. A process that serves
 *     several (a test driving the app in-process, a worker) calls
 *     anotoki_iam_login_required_reset() between them.
 *
 * `$reason` says where the answer came from, for a log line: fresh, read,
 * unknown_application, stale, never_read, backing_off, refreshing,
 * not_configured.
 */
function anotoki_iam_login_required(array $config, &$reason = null): bool
{
    $reason = null;
    $issuer = rtrim((string) (isset($config['issuer']) ? $config['issuer'] : ''), '/');
    $clientId = (string) (isset($config['client_id']) ? $config['client_id'] : '');

    if ($issuer === '' || $clientId === '') {
        $reason = 'not_configured';
        return true;
    }

    // The tests' seam, like jwks_file: the file's content is the IAM's answer
    // (a 200), a missing file no answer at all. Read every time - nothing kept,
    // nothing remembered - so a test can change it between two requests.
    if (!empty($config['application_file'])) {
        $file = (string) $config['application_file'];
        $body = is_file($file) ? @file_get_contents($file) : false;
        $outcome = $body === false
            ? anotoki_iam_login_required_from(0, null, $clientId, null, time())
            : anotoki_iam_login_required_from(200, $body, $clientId, null, time());
        $reason = $outcome['reason'];

        return $outcome['value'];
    }

    $ttl = max(1, (int) (isset($config['application_ttl']) ? $config['application_ttl'] : 60));
    $timeout = max(1, (int) (isset($config['application_timeout']) ? $config['application_timeout'] : 2));
    $directory = rtrim(!empty($config['cache_dir']) ? (string) $config['cache_dir'] : sys_get_temp_dir(), '/\\');
    $cacheFile = $directory . '/anotoki-iam-application-' . sha1($issuer . ' ' . $clientId) . '.json';

    $outcome = anotoki_iam_login_required_memo('get', $cacheFile);

    if ($outcome === null) {
        $url = $issuer . '/api/public/applications/' . rawurlencode($clientId);
        $outcome = anotoki_iam_login_required_ask($url, $clientId, $cacheFile, $ttl, $timeout);
        anotoki_iam_login_required_memo('set', $cacheFile, $outcome);
    }

    $reason = $outcome['reason'];

    return $outcome['value'];
}

/** Forgets the answers remembered for this request (tests, workers). */
function anotoki_iam_login_required_reset()
{
    anotoki_iam_login_required_memo('reset');
}

/** The request's remembered answers, by cache file: 'get', 'set' or 'reset'. */
function anotoki_iam_login_required_memo($action, $key = '', $outcome = null)
{
    static $memo = array();

    if ($action === 'reset') {
        $memo = array();
    } elseif ($action === 'set') {
        $memo[$key] = array('value' => (bool) $outcome['value'], 'reason' => (string) $outcome['reason']);
    } elseif (isset($memo[$key])) {
        return $memo[$key];
    }

    return null;
}

/**
 * The kept answer, or a fresh one from the IAM: ['value' => bool, 'reason' => string].
 *
 * Single flight: the request that finds the answer old claims the refresh - a
 * lock nobody waits for, and `tried_at` written into the kept answer before it
 * asks - so the others go on with the last value instead of all asking at once.
 */
function anotoki_iam_login_required_ask($url, $clientId, $cacheFile, $ttl, $timeout)
{
    $step = anotoki_iam_login_required_from_cache(anotoki_iam_login_required_read($cacheFile), time(), $ttl, $timeout);

    if (!$step['fetch']) {
        return $step;
    }

    $lock = anotoki_iam_login_required_lock($cacheFile . '.lock');

    if ($lock === false) {
        $cached = anotoki_iam_login_required_read($cacheFile);
        $last = $cached !== null ? $cached['login_required'] : null;

        return array('fetch' => false, 'value' => $last === null ? true : $last, 'reason' => 'refreshing');
    }

    // Once more under the lock: another request may have finished asking
    // between the first look and the lock.
    $cached = anotoki_iam_login_required_read($cacheFile);
    $step = anotoki_iam_login_required_from_cache($cached, time(), $ttl, $timeout);

    if (!$step['fetch']) {
        anotoki_iam_login_required_unlock($lock);
        return $step;
    }

    $claim = $cached !== null ? $cached : anotoki_iam_login_required_record(null);
    $claim['tried_at'] = time();
    $claimed = anotoki_iam_login_required_write($cacheFile, $claim);

    $body = anotoki_iam_http_get($url, $timeout, $status);
    $outcome = anotoki_iam_login_required_from($status, $body, $clientId, $cached, time());
    $kept = anotoki_iam_login_required_write($cacheFile, $outcome['cache']);

    anotoki_iam_login_required_unlock($lock);

    if (!$claimed || !$kept) {
        // Still answered - from what was read, failing closed - but nothing is
        // kept, so every request asks the IAM and waits for it.
        error_log('anotoki_iam: cannot write ' . $cacheFile . '; the IAM is asked on every request until the cache folder is writable');
    }

    return $outcome;
}

/**
 * What the kept answer says, before asking: ['fetch' => bool, 'value' => bool, 'reason' => string].
 * A pure function of the kept record (anotoki_iam_login_required_read()'s shape, or null) and the time.
 *
 *   fresh        read less than $ttl ago: its value
 *   backing_off  the last ask failed less than $ttl ago: the last value, or true
 *   refreshing   another request claimed the ask and has not finished: the same
 *   otherwise    fetch
 */
function anotoki_iam_login_required_from_cache($cached, $now, $ttl, $timeout)
{
    $record = anotoki_iam_login_required_record($cached);
    $last = $record['login_required'];
    $value = $last === null ? true : $last;
    $recent = function ($at, $window) use ($now) {
        return $at > 0 && $at <= $now && $now - $at < $window;
    };

    if ($last !== null && $recent($record['fetched_at'], $ttl)) {
        return array('fetch' => false, 'value' => $last, 'reason' => 'fresh');
    }

    if ($recent($record['failed_at'], $ttl)) {
        return array('fetch' => false, 'value' => $value, 'reason' => 'backing_off');
    }

    // A claim outlives its asker only when the asker died without a lock to
    // release; a few seconds past the timeout, somebody else may ask.
    $answeredSince = max($record['fetched_at'], $record['failed_at']);
    if ($record['tried_at'] > $answeredSince && $recent($record['tried_at'], $timeout + 3)) {
        return array('fetch' => false, 'value' => $value, 'reason' => 'refreshing');
    }

    return array('fetch' => true, 'value' => $value, 'reason' => 'fetch');
}

/**
 * What the IAM's answer means: ['value' => bool, 'cache' => the record to keep, 'reason' => string].
 * A pure function of the HTTP status (0: no answer), the body, this client id,
 * the kept record (or null) and the time.
 */
function anotoki_iam_login_required_from($status, $body, $clientId, $cached, $now)
{
    $record = anotoki_iam_login_required_record($cached);

    if ($status === 200 && is_string($body)) {
        $answer = json_decode($body, true);

        // Only an answer about this application counts: a proxy's page, an
        // answer for another client or a login_required that is not a JSON
        // boolean is no answer.
        if (is_array($answer)
            && isset($answer['client_id']) && $answer['client_id'] === (string) $clientId
            && array_key_exists('login_required', $answer) && is_bool($answer['login_required'])
        ) {
            $record['login_required'] = $answer['login_required'];
            $record['fetched_at'] = $now;
            $record['failed_at'] = 0;

            return array('value' => $answer['login_required'], 'cache' => $record, 'reason' => 'read');
        }
    } elseif ($status === 404) {
        $record['login_required'] = true;
        $record['fetched_at'] = $now;
        $record['failed_at'] = 0;

        return array('value' => true, 'cache' => $record, 'reason' => 'unknown_application');
    }

    $record['failed_at'] = $now;
    $last = $record['login_required'];

    return array(
        'value' => $last === null ? true : $last,
        'cache' => $record,
        'reason' => $last === null ? 'never_read' : 'stale',
    );
}

/** A kept record in its one shape: login_required (bool, or null: never read) and three times. */
function anotoki_iam_login_required_record($record)
{
    $record = is_array($record) ? $record : array();
    $time = function ($key) use ($record) {
        return isset($record[$key]) && is_int($record[$key]) && $record[$key] > 0 ? $record[$key] : 0;
    };
    $value = isset($record['login_required']) ? $record['login_required'] : null;

    return array(
        'login_required' => is_bool($value) ? $value : null,
        'fetched_at' => $time('fetched_at'),
        'failed_at' => $time('failed_at'),
        'tried_at' => $time('tried_at'),
    );
}

/** The kept record, or null when there is none to read. */
function anotoki_iam_login_required_read($cacheFile)
{
    if (!is_file($cacheFile)) {
        return null;
    }

    $record = json_decode((string) @file_get_contents($cacheFile), true);

    return is_array($record) ? anotoki_iam_login_required_record($record) : null;
}

/** Keeps the record: written beside and renamed over, so no reader sees half of it. */
function anotoki_iam_login_required_write($cacheFile, array $record)
{
    $directory = dirname($cacheFile);

    if (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) {
        return false;
    }

    $temporary = $cacheFile . '.' . bin2hex(random_bytes(4)) . '.tmp';

    if (@file_put_contents($temporary, json_encode(anotoki_iam_login_required_record($record))) === false) {
        return false;
    }

    if (!@rename($temporary, $cacheFile)) {
        @unlink($temporary);
        return false;
    }

    return true;
}

/**
 * The refresh's lock, without waiting: a handle when it is ours, false when
 * another request holds it, null when there is no lock to be had (a folder that
 * cannot be written, a filesystem without locks) - then `tried_at` alone keeps
 * the others from asking at the same time.
 */
function anotoki_iam_login_required_lock($lockFile)
{
    $directory = dirname($lockFile);

    if (!is_dir($directory)) {
        @mkdir($directory, 0700, true);
    }

    $handle = @fopen($lockFile, 'c');

    if ($handle === false) {
        return null;
    }

    if (flock($handle, LOCK_EX | LOCK_NB, $wouldBlock)) {
        return $handle;
    }

    fclose($handle);

    return $wouldBlock ? false : null;
}

function anotoki_iam_login_required_unlock($lock)
{
    if (is_resource($lock)) {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// Encoding
// ─────────────────────────────────────────────────────────────────────────────

function anotoki_iam_base64url_decode($data)
{
    $data = (string) $data;
    if ($data === '' || !preg_match('/^[A-Za-z0-9_-]+$/', $data)) {
        return '';
    }

    $decoded = base64_decode(strtr($data, '-_', '+/'), true);

    return $decoded === false ? '' : $decoded;
}

function anotoki_iam_base64url_encode($data)
{
    return rtrim(strtr(base64_encode((string) $data), '+/', '-_'), '=');
}

function anotoki_iam_decode_json($segment)
{
    $decoded = json_decode(anotoki_iam_base64url_decode($segment), true);

    return is_array($decoded) ? $decoded : null;
}

// A JWK gives an RSA key as its modulus and exponent. openssl wants a PEM, which
// is base64 around a DER SubjectPublicKeyInfo, so the two numbers are wrapped
// back up in the structure they came out of.

function anotoki_iam_der_length($length)
{
    if ($length < 128) {
        return chr($length);
    }

    $bytes = ltrim(pack('N', $length), "\x00");

    return chr(0x80 | strlen($bytes)) . $bytes;
}

/** A DER INTEGER; a leading zero keeps an unsigned value clear of the sign bit. */
function anotoki_iam_der_integer($bytes)
{
    $bytes = ltrim($bytes, "\x00");

    if ($bytes === '') {
        $bytes = "\x00";
    }

    if (ord($bytes[0]) > 0x7F) {
        $bytes = "\x00" . $bytes;
    }

    return "\x02" . anotoki_iam_der_length(strlen($bytes)) . $bytes;
}

function anotoki_iam_der_sequence($contents)
{
    return "\x30" . anotoki_iam_der_length(strlen($contents)) . $contents;
}

function anotoki_iam_jwk_to_pem(array $jwk)
{
    $modulus = anotoki_iam_base64url_decode(isset($jwk['n']) ? $jwk['n'] : '');
    $exponent = anotoki_iam_base64url_decode(isset($jwk['e']) ? $jwk['e'] : '');

    if ($modulus === '' || $exponent === '') {
        return null;
    }

    // rsaEncryption (1.2.840.113549.1.1.1) with its NULL parameters.
    $algorithm = anotoki_iam_der_sequence("\x06\x09\x2a\x86\x48\x86\xf7\x0d\x01\x01\x01\x05\x00");
    $publicKey = anotoki_iam_der_sequence(anotoki_iam_der_integer($modulus) . anotoki_iam_der_integer($exponent));
    $bitString = "\x03" . anotoki_iam_der_length(strlen($publicKey) + 1) . "\x00" . $publicKey;
    $info = anotoki_iam_der_sequence($algorithm . $bitString);

    return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($info), 64, "\n") . "-----END PUBLIC KEY-----\n";
}

// ─────────────────────────────────────────────────────────────────────────────
// For tests: a key pair and tokens signed with it
// ─────────────────────────────────────────────────────────────────────────────
//
// Never used by a running application. A test makes a key pair, writes its
// public half as a JWKS file, points `jwks_file` at it, and signs whatever
// tokens it needs - valid ones, expired ones, ones for the wrong audience.

/** A fresh RSA key pair: ['kid', 'private_pem', 'jwks' => a JWKS document array]. */
function anotoki_iam_test_keypair($kid = null)
{
    $options = array('private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA);

    // Windows builds of PHP need to be told where openssl.cnf is, and not all
    // of them ship one where OpenSSL looks. A config file that says nothing is
    // enough for generating a key.
    $key = @openssl_pkey_new($options);
    if ($key === false) {
        $cnf = tempnam(sys_get_temp_dir(), 'cnf');
        file_put_contents($cnf, "[req]\ndistinguished_name=dn\n[dn]\n");
        $options['config'] = $cnf;
        $key = openssl_pkey_new($options);
        $exported = openssl_pkey_export($key, $privatePem, null, $options);
        @unlink($cnf);
    } else {
        $exported = openssl_pkey_export($key, $privatePem);
    }

    if (!$exported) {
        throw new RuntimeException('openssl could not make a test key: ' . openssl_error_string());
    }

    $details = openssl_pkey_get_details($key);
    $kid = $kid !== null ? $kid : bin2hex(random_bytes(8));

    return array(
        'kid' => $kid,
        'private_pem' => $privatePem,
        'jwks' => array('keys' => array(array(
            'kty' => 'RSA',
            'use' => 'sig',
            'alg' => 'RS256',
            'kid' => $kid,
            'n' => anotoki_iam_base64url_encode($details['rsa']['n']),
            'e' => anotoki_iam_base64url_encode($details['rsa']['e']),
        ))),
    );
}

/** A signed JWT with these claims. `$typ` defaults to an access token's. */
function anotoki_iam_test_sign(array $claims, $privatePem, $kid, $typ = 'at+jwt', $alg = 'RS256')
{
    $header = anotoki_iam_base64url_encode(json_encode(array('alg' => $alg, 'typ' => $typ, 'kid' => $kid)));
    $payload = anotoki_iam_base64url_encode(json_encode($claims));

    if (!openssl_sign($header . '.' . $payload, $signature, $privatePem, OPENSSL_ALGO_SHA256)) {
        throw new RuntimeException('openssl could not sign the test token');
    }

    return $header . '.' . $payload . '.' . anotoki_iam_base64url_encode($signature);
}

/** Claims for a test access token that verifies against the given config. */
function anotoki_iam_test_claims(array $config, $userId, array $roles = array(), array $extra = array())
{
    $now = time();

    return array_merge(array(
        'iss' => rtrim((string) $config['issuer'], '/'),
        'sub' => (string) $userId,
        'aud' => (string) $config['client_id'],
        'client_id' => (string) $config['client_id'],
        'iat' => $now,
        'exp' => $now + 600,
        'jti' => bin2hex(random_bytes(8)),
        'sid' => bin2hex(random_bytes(16)),
        'scope' => 'openid profile email',
        'roles' => $roles,
        'preferred_username' => 'user' . $userId,
        'email' => 'user' . $userId . '@example.test',
        'email_verified' => true,
        'locale' => 'en',
    ), $extra);
}
