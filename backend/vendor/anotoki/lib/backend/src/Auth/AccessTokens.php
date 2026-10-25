<?php

declare(strict_types=1);

namespace Anotoki\Lib\Auth;

use Psr\Http\Message\ServerRequestInterface;
use RuntimeException;

/**
 * Checks the IAM's access tokens - the rules of the IAM's PHP SDK
 * (anotoki_iam_verify_access_token()), as typed code. A valid token is all of
 * these, or nothing:
 *
 *   - three base64url parts: a JSON header and payload, and a signature
 *   - the header's alg RS256 and typ at+jwt (or application/at+jwt). The
 *     algorithm is pinned here, never read from the token: a token is never
 *     allowed to say how it is checked, which is how "alg: none" and HMAC
 *     keyed with the public key get in. The typ is what stops an ID token -
 *     also signed by the IAM, also naming this client - being replayed as an
 *     access token. A header that names extensions the reader must understand
 *     (crit) is refused: none is understood here
 *   - a kid, naming a key of the IAM's key set (Jwks), whose signature it has
 *   - iss the configured issuer; aud this client (or a list holding it), and a
 *     client_id claim, when there is one, this client too
 *   - exp in the future, nbf and iat not in it, with the leeway for clocks
 *   - sub an IAM user id (Person::userId())
 *
 * Every failure is the same null: to anybody holding a token that did not
 * work the answer is the same. Why it failed is lastReason(), for a log line:
 * the library's words, with at most a short piece of the token's own (its
 * typ, its kid) in the characters such a name is made of - a token never
 * writes a line of its own into a site's log. lastReasonKind() is the same
 * without the token's piece: what a log line is counted by.
 */
final class AccessTokens
{
    /** The longest token read at all: the IAM's are about a kilobyte. */
    public const MAX_LENGTH = 16384;

    /** The most of a token's own words a reason shows. */
    private const SHOWN_MAX = 64;

    private ?string $lastReason = null;
    private ?string $lastKind = null;
    private readonly Jwks $jwks;

    public function __construct(private readonly IamClient $iam, ?Jwks $jwks = null)
    {
        $this->jwks = $jwks ?? new Jwks($iam);
    }

    /** The person a token names, or null; why not is lastReason(). */
    public function verify(string $token): ?Person
    {
        $claims = $this->claims($token);
        if ($claims === null) {
            return null;
        }
        $person = Person::fromClaims($claims);
        if ($person === null) {
            $this->refuse('no usable subject');
        }

        return $person;
    }

    /**
     * The person a request's `Authorization: Bearer` token names, verified as Identify verifies it - or null: no
     * Bearer token, or one that does not verify. For a check that runs before the site identifies its requests -
     * the SiteGate's `exempt` (0.11.0), which lets the site's ADMIN through while the site is closed for
     * maintenance: `fn ($request) => $tokens->personOf($request)?->isAdmin() ?? false`. Never a cookie, never a
     * body: the token alone.
     */
    public function personOf(ServerRequestInterface $request): ?Person
    {
        $token = Bearer::token(Bearer::header($request));

        return $token === null ? null : $this->verify($token);
    }

    /**
     * A token's claims once every rule but the subject's holds, or null.
     *
     * @return ?array<string, mixed>
     */
    public function claims(string $token): ?array
    {
        $this->lastReason = null;
        $this->lastKind = null;

        if (!$this->iam->configured()) {
            return $this->refuse('the IAM settings have no issuer or client_id');
        }
        if (!function_exists('openssl_verify')) {
            // Not a token's fault: the site cannot check any. Said loudly, never as a refusal.
            throw new RuntimeException("Signing in needs PHP's openssl extension, and this PHP has none.");
        }
        if (strlen($token) > self::MAX_LENGTH) {
            return $this->refuse('too long');
        }

        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            return $this->refuse('not a JWT');
        }
        [$encodedHeader, $encodedPayload, $encodedSignature] = $parts;

        $header = Base64Url::json($encodedHeader);
        $claims = Base64Url::json($encodedPayload);
        $signature = Base64Url::decode($encodedSignature);
        if ($header === null || $claims === null || $signature === '') {
            return $this->refuse('unreadable header, payload or signature');
        }

        if (($header['alg'] ?? null) !== 'RS256') {
            return $this->refuse('algorithm is not RS256');
        }
        // RFC 9068 spells it at+jwt; the media-type form is accepted too.
        $typ = is_string($header['typ'] ?? null) ? strtolower($header['typ']) : '';
        if ($typ !== 'at+jwt' && $typ !== 'application/at+jwt') {
            return $this->refuse('not an access token', ' (typ ' . ($typ === '' ? 'missing' : self::shown($typ)) . ')');
        }
        if (array_key_exists('crit', $header)) {
            return $this->refuse('a header with critical extensions');
        }
        $kid = $header['kid'] ?? null;
        if (!is_string($kid) || $kid === '') {
            return $this->refuse('no kid');
        }

        $key = $this->jwks->key($kid);
        if ($key === null) {
            return $this->refuse('no published key with kid', ' ' . self::shown($kid));
        }
        $pem = Jwks::pem($key);
        if ($pem === null || openssl_verify($encodedHeader . '.' . $encodedPayload, $signature, $pem, OPENSSL_ALGO_SHA256) !== 1) {
            return $this->refuse('bad signature');
        }

        $issuer = is_string($claims['iss'] ?? null) ? rtrim($claims['iss'], '/') : '';
        if ($issuer !== $this->iam->issuer) {
            return $this->refuse('issued by somebody else');
        }
        $audience = $claims['aud'] ?? null;
        $audiences = is_array($audience) ? $audience : [$audience];
        if (!in_array($this->iam->clientId, $audiences, true)) {
            return $this->refuse('meant for another application');
        }
        if (array_key_exists('client_id', $claims) && $claims['client_id'] !== $this->iam->clientId) {
            return $this->refuse('issued to another application');
        }

        $now = time();
        $leeway = $this->iam->leeway;
        $exp = $claims['exp'] ?? null;
        if (!is_int($exp) && !is_float($exp)) {
            return $this->refuse('no expiry');
        }
        if ($exp + $leeway < $now) {
            return $this->refuse('expired');
        }
        foreach (['nbf' => 'not valid yet', 'iat' => 'issued in the future'] as $name => $reason) {
            if (!array_key_exists($name, $claims)) {
                continue;
            }
            $time = $claims[$name];
            if ((!is_int($time) && !is_float($time)) || $time - $leeway > $now) {
                return $this->refuse($reason);
            }
        }

        return $claims;
    }

    /** The IAM and the application the tokens are checked for. */
    public function iam(): IamClient
    {
        return $this->iam;
    }

    /** Why the last token was refused - for a log line, never for the client - or null after one that passed. */
    public function lastReason(): ?string
    {
        return $this->lastReason;
    }

    /**
     * The same, in the library's words alone (no typ, no kid): one of a few, whatever the token held - what a
     * log counts its lines by, so a stream of made-up kids is not a stream of lines. Null after a token that passed.
     */
    public function lastReasonKind(): ?string
    {
        return $this->lastKind;
    }

    /**
     * A piece of the token itself in a reason - its typ, its kid: only the characters such a name is made of
     * (anything else a ?), at most 64 of them. Never a line break, never a page of text.
     */
    private static function shown(string $words): string
    {
        $shown = (string) preg_replace('/[^A-Za-z0-9._+\-]/', '?', $words);

        return strlen($shown) > self::SHOWN_MAX ? substr($shown, 0, self::SHOWN_MAX) . '...' : $shown;
    }

    /**
     * @param string $detail  the token's own piece, shown() already
     * @return null
     */
    private function refuse(string $reason, string $detail = ''): ?array
    {
        $this->lastKind = $reason;
        $this->lastReason = $reason . $detail;

        return null;
    }
}
