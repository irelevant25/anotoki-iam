<?php

/**
 * Signing tokens, and reading back the ones this IAM signed.
 *
 * RS256 only. Applications verify with the public key from the JWKS, so they
 * never hold anything that could mint a token - which an HMAC secret shared
 * with every site would have been, and why the genshin site's HS256 tokens
 * could not simply be extended to the others.
 *
 * Reading back is needed in one place: an ID token handed to the logout
 * endpoint as `id_token_hint`, to learn which session and application it names.
 * That check is against our own keys, with the algorithm pinned.
 */

/** A signed JWT: header and claims in, compact serialization out. */
function jwtSign(array $claims, string $typ = 'JWT'): string
{
    $key = activeSigningKey();

    $header = base64UrlEncode((string) json_encode(['alg' => 'RS256', 'typ' => $typ, 'kid' => $key['kid']]));
    $payload = base64UrlEncode((string) json_encode($claims, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

    $signature = '';
    if (!openssl_sign($header . '.' . $payload, $signature, $key['private_pem'], OPENSSL_ALGO_SHA256)) {
        throw new RuntimeException('openssl could not sign a token: ' . openssl_error_string());
    }

    return $header . '.' . $payload . '.' . base64UrlEncode($signature);
}

/**
 * The claims of a token this IAM signed, or null.
 *
 * `$ignoreExpiry` is for the logout hint only: OIDC lets an application pass an
 * ID token that has already expired, because what it is used for there - "who
 * is signing out" - does not stop being true when it expires.
 */
function jwtVerifyOwn(string $token, ?string $expectedTyp = null, bool $ignoreExpiry = false): ?array
{
    $parts = explode('.', $token);
    if (count($parts) !== 3) {
        return null;
    }

    [$encodedHeader, $encodedPayload, $encodedSignature] = $parts;
    $header = json_decode(base64UrlDecode($encodedHeader), true);
    $claims = json_decode(base64UrlDecode($encodedPayload), true);
    $signature = base64UrlDecode($encodedSignature);

    if (!is_array($header) || !is_array($claims) || $signature === '' || ($header['alg'] ?? '') !== 'RS256') {
        return null;
    }

    if ($expectedTyp !== null && strtolower((string) ($header['typ'] ?? '')) !== strtolower($expectedTyp)) {
        return null;
    }

    $key = signingKeyByKid((string) ($header['kid'] ?? ''));
    if ($key === null || openssl_verify($encodedHeader . '.' . $encodedPayload, $signature, $key['public_pem'], OPENSSL_ALGO_SHA256) !== 1) {
        return null;
    }

    if (($claims['iss'] ?? '') !== iamIssuer()) {
        return null;
    }

    if (!$ignoreExpiry && (int) ($claims['exp'] ?? 0) < time()) {
        return null;
    }

    return $claims;
}

/**
 * The at_hash of an access token: the left half of its SHA-256, base64url.
 * Lets a frontend check that the access token it got belongs with the ID token.
 */
function atHash(string $accessToken): string
{
    return base64UrlEncode(substr(hash('sha256', $accessToken, true), 0, 16));
}
