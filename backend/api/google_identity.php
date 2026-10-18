<?php

/**
 * Checking that a Google id token really is one, and the identities that come
 * of it.
 *
 * The browser gets the token from Google Identity Services and posts it here.
 * It is a JWT signed RS256 by a key Google publishes, so verifying it is three
 * things: the signature is Google's, the token was minted for this IAM's client
 * id, and it has not expired. The audience check carries the weight - anybody
 * can get a valid Google token by signing in to any site that uses Google, and
 * `aud` is what says this one was minted for us.
 *
 * The client id is config/google.local.php's (googleClientId(), in
 * config/google.php). No file means no Google: the button is not drawn and the
 * endpoints refuse.
 */

const GOOGLE_KEYS_URL = 'https://www.googleapis.com/oauth2/v3/certs';
const GOOGLE_ISSUERS = ['accounts.google.com', 'https://accounts.google.com'];
const GOOGLE_KEYS_FALLBACK_TTL = 3600;
const GOOGLE_CLOCK_SKEW = 60;
const IDENTITY_GOOGLE = 'google';

/**
 * A GET: its status, its headers and its body, or null when nothing came back.
 *
 * fopen() rather than file_get_contents(), so the headers come from the stream
 * itself: the magic variable file_get_contents() fills in for them is
 * deprecated in PHP 8.5, which says so as soon as the file is compiled.
 * Redirects are followed; the status and headers are the last answer's.
 *
 * @return array{status: int, headers: list<string>, body: string}|null
 */
function googleFetch(string $url, int $timeout): ?array
{
    $context = stream_context_create(['http' => ['timeout' => $timeout, 'ignore_errors' => true]]);
    $stream = @fopen($url, 'rb', false, $context);
    if ($stream === false) {
        return null;
    }

    $wrapper = stream_get_meta_data($stream)['wrapper_data'] ?? [];
    $body = stream_get_contents($stream);
    fclose($stream);

    // A redirect puts every answer's headers in one list, each starting again
    // at its own status line.
    $status = 0;
    $headers = [];
    foreach (is_array($wrapper) ? $wrapper : [] as $line) {
        if (preg_match('#^HTTP/\S+\s+(\d{3})#', (string) $line, $found)) {
            $status = (int) $found[1];
            $headers = [];
        } else {
            $headers[] = (string) $line;
        }
    }

    return ['status' => $status, 'headers' => $headers, 'body' => is_string($body) ? $body : ''];
}

/** How often a token naming a key not in the cache may send the IAM back to Google for the keys. */
const GOOGLE_KEYS_REFETCH_INTERVAL = 300;

/**
 * Google's current public keys by kid, cached on disk for as long as Google says.
 *
 * $ignoreCache is for a kid the cache does not have - Google may just have
 * rotated. That refetch is allowed once every five minutes, not once per
 * token: the kid is whatever the sender wrote, and a stream of made-up ones
 * would otherwise be a stream of requests to Google.
 */
function googleSigningKeys(bool $ignoreCache = false): array
{
    $cacheFile = iamStorageDir() . '/cache/google-jwks.json';
    $refetchStamp = $cacheFile . '.refetched';

    if ($ignoreCache && is_file($refetchStamp) && time() - (int) @filemtime($refetchStamp) < GOOGLE_KEYS_REFETCH_INTERVAL) {
        $ignoreCache = false;
    }

    if (!$ignoreCache && is_file($cacheFile)) {
        $cached = json_decode((string) @file_get_contents($cacheFile), true);
        if (is_array($cached) && ($cached['expires_at'] ?? 0) > time()) {
            return $cached['keys'] ?? [];
        }
    }

    if ($ignoreCache) {
        @touch($refetchStamp);
    }

    $answer = googleFetch(GOOGLE_KEYS_URL, 10);

    $keys = [];
    if ($answer !== null && $answer['status'] === 200) {
        foreach (json_decode($answer['body'], true)['keys'] ?? [] as $key) {
            if (($key['kty'] ?? '') === 'RSA' && isset($key['kid'], $key['n'], $key['e'])) {
                $keys[$key['kid']] = $key;
            }
        }
    }

    if (!$keys) {
        // Nothing fresh to be had - unreachable, an error page, no keys in it.
        // Stale keys still verify tokens signed before a rotation, and a token
        // signed with a key not among them fails anyway. The cache is left as
        // it was: an empty key set stored for an hour would switch Google
        // sign-in off for that hour over one bad answer.
        $cached = is_file($cacheFile) ? json_decode((string) @file_get_contents($cacheFile), true) : null;
        return is_array($cached) ? $cached['keys'] ?? [] : [];
    }

    $lifetime = GOOGLE_KEYS_FALLBACK_TTL;
    foreach ($answer['headers'] as $header) {
        if (preg_match('/^cache-control:.*max-age=(\d+)/i', $header, $found)) {
            $lifetime = max(300, (int) $found[1]);
        }
    }

    $directory = dirname($cacheFile);
    if (is_dir($directory) || @mkdir($directory, 0775, true) || is_dir($directory)) {
        @file_put_contents($cacheFile, json_encode(['expires_at' => time() + $lifetime, 'keys' => $keys]));
    }

    return $keys;
}

/**
 * The claims of a Google id token, or null for every way it can fail to be one.
 * The caller is told none of them apart.
 */
function googleVerifyIdToken(string $idToken): ?array
{
    $clientId = googleClientId();
    $parts = explode('.', $idToken);

    if ($clientId === null || count($parts) !== 3) {
        return null;
    }

    [$encodedHeader, $encodedPayload, $encodedSignature] = $parts;
    $header = json_decode(base64UrlDecode($encodedHeader), true);
    $claims = json_decode(base64UrlDecode($encodedPayload), true);
    $signature = base64UrlDecode($encodedSignature);

    // The algorithm is pinned, never read from the token.
    if (!is_array($header) || !is_array($claims) || $signature === '' || ($header['alg'] ?? '') !== 'RS256' || empty($header['kid'])) {
        return null;
    }

    $verified = false;
    foreach ([false, true] as $ignoreCache) {
        $keys = googleSigningKeys($ignoreCache);
        if (isset($keys[$header['kid']])) {
            $pem = jwkToPem($keys[$header['kid']]['n'], $keys[$header['kid']]['e']);
            $verified = openssl_verify($encodedHeader . '.' . $encodedPayload, $signature, $pem, OPENSSL_ALGO_SHA256) === 1;
            break;
        }
    }

    if (
        !$verified ||
        !in_array($claims['iss'] ?? '', GOOGLE_ISSUERS, true) ||
        ($claims['aud'] ?? '') !== $clientId ||
        (int) ($claims['exp'] ?? 0) + GOOGLE_CLOCK_SKEW < time() ||
        empty($claims['sub'])
    ) {
        return null;
    }

    return $claims;
}

/** The account an identity belongs to, or null. Disabled accounts included - the caller decides. */
function identityOwner(PDO $pdo, string $provider, string $subject): ?array
{
    $statement = $pdo->prepare('SELECT u.* FROM user_identities i JOIN users u ON u.id = i.user_id WHERE i.provider = ? AND i.subject = ?');
    $statement->execute([$provider, $subject]);

    return $statement->fetch() ?: null;
}

function identityFor(PDO $pdo, int $userId, string $provider): ?array
{
    $statement = $pdo->prepare('SELECT * FROM user_identities WHERE user_id = ? AND provider = ?');
    $statement->execute([$userId, $provider]);

    return $statement->fetch() ?: null;
}

function attachIdentity(PDO $pdo, int $userId, string $provider, string $subject, ?string $email): void
{
    $pdo->prepare('INSERT INTO user_identities (user_id, provider, subject, email) VALUES (?, ?, ?, ?)')->execute([$userId, $provider, $subject, $email]);
}
