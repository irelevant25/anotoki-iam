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
 * And two more since the security review of 2026-10-19: the token is fresh -
 * issued in the last five minutes - and new - not used here before. Google
 * signs a token for an hour, and the IAM took it as often as it came within
 * that hour: whoever saw one (a log, a tab's memory, a proxy) signed in with
 * it again, a site's "Sign in again" (prompt=login) answered too. A token is
 * spent (spendGoogleIdToken()) the moment it changes something, and is
 * remembered until it runs out (google_id_tokens_used, migration 026). Before
 * that, from the moment the IAM knows the account it is for - the one the
 * Google sign-in finds it names, the one the account page connects it to - it
 * is held for that account (holdGoogleIdToken()): back with the second factor's
 * code or the password there, refused everywhere else - a token left waiting
 * was once free for anybody who saw it to connect to an account of their own
 * (the reviews of d0e34fb and 632a464).
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
 * How old a Google id token may be when it arrives, by its `iat`: five
 * minutes, with the clocks' GOOGLE_CLOCK_SKEW besides. Long enough to find the
 * phone for the second factor - the page sends the same token again with the
 * code -, short against one that turns up later.
 */
const GOOGLE_ID_TOKEN_MAX_AGE = 300;

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
 * Google's own checks of an id token, and its freshness: its claims, or null
 * for every way it fails them. Nothing about whether it was used here - the
 * caller asks that by holding it for its account (holdGoogleIdToken()): the
 * Google sign-in for the account the token names, the account page's connect
 * for the account connecting it.
 */
function googleIdTokenClaims(string $idToken): ?array
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
        !googleIdTokenFresh($claims) ||
        empty($claims['sub'])
    ) {
        return null;
    }

    return $claims;
}

/**
 * Whether the token was issued within GOOGLE_ID_TOKEN_MAX_AGE, the clocks'
 * GOOGLE_CLOCK_SKEW either way: not older, and not from further ahead. One
 * without an `iat` - Google writes one in every token - is not.
 */
function googleIdTokenFresh(array $claims): bool
{
    $issued = $claims['iat'] ?? null;
    if (!is_int($issued) && !is_float($issued)) {
        return false;
    }
    $now = time();

    return $issued <= $now + GOOGLE_CLOCK_SKEW && $issued + GOOGLE_ID_TOKEN_MAX_AGE + GOOGLE_CLOCK_SKEW >= $now;
}

/**
 * What a spent token is remembered by: the SHA-256 of what Google signed - its
 * header and claims as they came, `header.payload`. Never of the whole token:
 * base64 leaves bits of the signature's last character unread, so one
 * signature can be written several ways, each of which verifies, and a hash
 * of the whole would take each for a token of its own.
 */
function googleIdTokenKey(string $idToken): string
{
    $parts = explode('.', $idToken);

    return sha256($parts[0] . '.' . ($parts[1] ?? ''));
}

/**
 * Whether the database has migration 026's google_id_tokens_used, with its
 * hold (`held_for_user`). A catalog question that cannot fail, asked afresh
 * every time like the others: a build is uploaded before its migrations,
 * Google's sign-in works in between, and the admin panel's Apply runs in the
 * same process as the requests after it. A table without the hold - 026's
 * first version, which only a development database ever ran - counts as none.
 */
function googleTokensReady(PDO $pdo): bool
{
    return asBool($pdo->query("SELECT EXISTS (SELECT 1 FROM pg_attribute WHERE attrelid = to_regclass('google_id_tokens_used') AND attname = 'held_for_user' AND NOT attisdropped)")->fetchColumn());
}

/**
 * Whether the token was used here before - spent, or held for an account.
 * Before 026, never: its five minutes alone hold it then.
 */
function googleIdTokenSeen(PDO $pdo, string $idToken): bool
{
    if (!googleTokensReady($pdo)) {
        return false;
    }

    $statement = $pdo->prepare('SELECT 1 FROM google_id_tokens_used WHERE token_hash = ?');
    $statement->execute([googleIdTokenKey($idToken)]);

    return $statement->fetchColumn() !== false;
}

/** The account a token is held for (holdGoogleIdToken()), or null: never seen, or spent. */
function googleIdTokenHeldFor(PDO $pdo, string $idToken): ?int
{
    if (!googleTokensReady($pdo)) {
        return null;
    }

    $statement = $pdo->prepare('SELECT held_for_user FROM google_id_tokens_used WHERE token_hash = ?');
    $statement->execute([googleIdTokenKey($idToken)]);
    $heldFor = $statement->fetchColumn();

    return $heldFor === false || $heldFor === null ? null : (int) $heldFor;
}

/**
 * Holds a token googleIdTokenClaims() took for one account - the one the
 * Google sign-in finds it names, or the one the account page connects it to -
 * and answers whether that account's sign-in or connect may go on: a token
 * never seen here is held for it now; one held for it already - the same
 * attempt, back with its code or its password - goes on; one spent, or held
 * for another account, does not.
 *
 * From the moment the account is known, every answer leaves the token held or
 * spent, never free: an attempt stopped for the second factor, a password, the
 * limiter, or refused before anything changed, keeps it for the same
 * account to finish, and everything else refuses it. Free, a token left
 * waiting - for its code at the sign-in (the review of d0e34fb), for the
 * password on the account page (the review of 632a464) - was anybody's who
 * saw it: connected to an account of their own, every later sign-in with
 * that Google account then landed in theirs.
 *
 * The hold names the account until housekeeping sweeps the row - the first run
 * after the token ran out, with traffic -, as the spent row keeps its time; an
 * account deleted meanwhile leaves its holds spent (anonymizedLeftovers()).
 * Before 026: true, as before it.
 */
function holdGoogleIdToken(PDO $pdo, string $idToken, array $claims, int $userId): bool
{
    if (!googleTokensReady($pdo)) {
        return true;
    }

    $held = $pdo->prepare(
        "INSERT INTO google_id_tokens_used (token_hash, expires_at, held_for_user) VALUES (?, TIMESTAMPTZ 'epoch' + (? * INTERVAL '1 second'), ?)
         ON CONFLICT (token_hash) DO NOTHING",
    );
    $held->execute([googleIdTokenKey($idToken), googleIdTokenKeptUntil($claims), $userId]);

    return $held->rowCount() === 1 || googleIdTokenHeldFor($pdo, $idToken) === $userId;
}

/**
 * Spends a token, and answers whether this call is the one that did - so two
 * requests racing with one token cannot both be told they won. Called just
 * before the first thing the token changes - a session opened, an account made
 * or claimed, an identity attached or connected - never before.
 *
 * With `$heldFor`, that account's hold is claimed - the Google sign-in's, the
 * account page's connect's: the update asks that it is still that account's
 * hold (one held before 026 was applied, by nothing, is spent by an insert as
 * a new one is). Without, a token never seen here is spent by an insert that
 * steps aside for a row that is there: a Google sign-in that found no account
 * to hold it for - one making an account, or refused in a way no second try
 * cures (`unverified_email`, `registration_disabled`).
 *
 * Remembered until the token runs out (its `exp`, with the clocks' leeway):
 * after that it is refused as expired anyway, and housekeeping sweeps the row.
 * Only the hash and that moment are kept. Before 026 there is nowhere to
 * remember it: true, as before it.
 */
function spendGoogleIdToken(PDO $pdo, string $idToken, array $claims, ?int $heldFor = null): bool
{
    if (!googleTokensReady($pdo)) {
        return true;
    }

    if ($heldFor !== null) {
        $claimed = $pdo->prepare('UPDATE google_id_tokens_used SET held_for_user = NULL WHERE token_hash = ? AND held_for_user = ?');
        $claimed->execute([googleIdTokenKey($idToken), $heldFor]);
        if ($claimed->rowCount() === 1) {
            return true;
        }
    }

    $spent = $pdo->prepare(
        "INSERT INTO google_id_tokens_used (token_hash, expires_at) VALUES (?, TIMESTAMPTZ 'epoch' + (? * INTERVAL '1 second'))
         ON CONFLICT (token_hash) DO NOTHING",
    );
    $spent->execute([googleIdTokenKey($idToken), googleIdTokenKeptUntil($claims)]);

    return $spent->rowCount() === 1;
}

/** Until when a used token is remembered, in seconds since the epoch: its `exp`, with the clocks' leeway - refused as expired after that anyway. */
function googleIdTokenKeptUntil(array $claims): int
{
    return (int) ($claims['exp'] ?? time()) + GOOGLE_CLOCK_SKEW;
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
