<?php

declare(strict_types=1);

namespace Anotoki\Lib\Admin;

use Anotoki\Lib\Support\ClientAddress;
use Anotoki\Lib\Support\JsonResponse;
use Anotoki\Lib\Support\Refusal;
use Closure;
use InvalidArgumentException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;

/**
 * What the library's admin tools need to know of the site they run in, given
 * once as closures - the site's own answers, never the library's guesses:
 *
 * - actorId(request): who is acting - the person's id from the session (the
 *   IAM) or the token (the sites: the IAM's sub), never from a body; null for
 *   nobody;
 * - isAdmin(request): whether that person is the site's ADMIN - the IAM's
 *   ADMIN of its own entry, a site's ADMIN from the token. Every admin() route
 *   group of the library asks it before anything else (adminRefusal(): 401
 *   for nobody, 403 for anybody else), so a group mounted without the site's
 *   own check still opens to nobody; a context without it cannot mount one;
 * - proof(request, body): the proof a dangerous action asks for - a backup
 *   downloaded, made, deleted or restored. Null when it is given; otherwise the
 *   refusal ({status, code, error}, a Refusal, or false). The IAM:
 *   accountProof() (the password, or a sign-in of the last 15 minutes); the
 *   sites: recentSignIn() below (the token's auth_time, ten minutes);
 * - proofKind(request): 'password' or 'recent_sign_in' - which one the page
 *   asks for (a password field, or "Sign in again");
 * - clientKey(request): the address bucket a request comes from as the site
 *   counts it for its limits. Default: ClientAddress's rule - the
 *   connection's REMOTE_ADDR, or the `clientHeader` (CF-Connecting-IP) when the
 *   connection is from one of `trustedProxies` (Cloudflare's ranges:
 *   ClientAddress::CLOUDFLARE), never a header from anybody else - an IPv6
 *   address as its /64. Without trusted proxies, behind Cloudflare that is the
 *   edge's address (a bucket shared by strangers: stricter, never looser);
 * - names(ids): the people's names by id, for the pages that show who did what
 *   (a backup's maker, an audit entry's actor) - the IAM's usernames, a site's
 *   own copy of the IAM's names; an id it does not know shows no name;
 * - error(response, refusal): the site's error body. Default: the library's
 *   {code, message, ...extra}.
 *
 * The site's own middleware still runs on each group the library returns - its
 * session, the IAM's CSRF header on every write - before the library's check.
 */
final class AdminContext
{
    public const PROOF_PASSWORD = 'password';
    public const PROOF_RECENT_SIGN_IN = 'recent_sign_in';

    /** @var list<string> */
    private readonly array $trustedProxies;

    /**
     * @param Closure(ServerRequestInterface): (int|string|null) $actorId
     * @param null|Closure(ServerRequestInterface, array<string, mixed>): mixed $proof  null when proven (or an array
     *        with `proof`, the IAM's accountProof() success), else {status, code, error|message} or a Refusal
     * @param string|Closure(ServerRequestInterface): string $proofKind  'password' or 'recent_sign_in'
     * @param null|Closure(ServerRequestInterface): string $clientKey
     * @param null|Closure(ResponseInterface, Refusal): ResponseInterface $error
     * @param null|Closure(ServerRequestInterface): bool $isAdmin  the site's ADMIN check; every admin() group needs it
     * @param null|Closure(list<int|string>): array<int|string, string> $names  people's names by id
     * @param list<string> $trustedProxies  the site's proxies' ranges, whose clientHeader is believed
     * @param string|null $originSecret  the secret the owner's Cloudflare zone adds (ClientAddress::ORIGIN_HEADER):
     *        with it, the clientHeader is believed only on a request that carries it (0.5.2)
     */
    public function __construct(
        private readonly Closure $actorId,
        private readonly ?Closure $proof = null,
        private readonly string|Closure $proofKind = self::PROOF_RECENT_SIGN_IN,
        private readonly ?Closure $clientKey = null,
        private readonly ?Closure $error = null,
        private readonly ?Closure $isAdmin = null,
        private readonly ?Closure $names = null,
        array $trustedProxies = [],
        private readonly string $clientHeader = ClientAddress::CLOUDFLARE_HEADER,
        #[\SensitiveParameter] private readonly ?string $originSecret = null,
    ) {
        if (is_string($proofKind) && !in_array($proofKind, [self::PROOF_PASSWORD, self::PROOF_RECENT_SIGN_IN], true)) {
            throw new InvalidArgumentException("The proof is 'password' or 'recent_sign_in', not \"$proofKind\".");
        }
        $this->trustedProxies = array_values(array_filter($trustedProxies, 'is_string'));
    }

    /** The site's proxies, whose header names a request's address (ClientAddress). @return list<string> */
    public function trustedProxies(): array
    {
        return $this->trustedProxies;
    }

    public function clientHeader(): string
    {
        return $this->clientHeader;
    }

    /**
     * The people's names by id, as the site's `names` gives them - an id it does not know (or a site that
     * gave none) has no name; never throws.
     *
     * @param list<mixed> $ids
     * @return array<int|string, string>
     */
    public function names(array $ids): array
    {
        $ids = array_values(array_unique(array_filter($ids, static fn(mixed $id): bool => is_int($id) || (is_string($id) && $id !== ''))));
        if ($this->names === null || $ids === []) {
            return [];
        }
        try {
            $names = ($this->names)($ids);
        } catch (Throwable) {
            return [];
        }
        $out = [];
        foreach (is_array($names) ? $names : [] as $id => $name) {
            if (is_string($name) && $name !== '') {
                $out[$id] = $name;
            }
        }

        return $out;
    }

    /** Whether this context can guard an admin() group: it says who the site's ADMIN is. */
    public function knowsAdmins(): bool
    {
        return $this->isAdmin !== null;
    }

    /**
     * Null when the request is the site's ADMIN's; otherwise 401 sign_in_required
     * (nobody is acting) or 403 forbidden (somebody else, or a check that failed
     * or answered anything but true) - every admin() group's first word.
     */
    public function adminRefusal(ServerRequestInterface $request): ?Refusal
    {
        if ($this->actorId($request) === null) {
            return new Refusal(401, 'sign_in_required', 'Please sign in.');
        }
        $admin = false;
        if ($this->isAdmin !== null) {
            try {
                $admin = ($this->isAdmin)($request) === true;
            } catch (Throwable) {
                $admin = false;
            }
        }

        return $admin ? null : new Refusal(403, 'forbidden', 'Only an administrator of this site may do this.');
    }

    /**
     * A proof for a site that judges people by the IAM's token: a sign-in of the
     * last $seconds (ten minutes), from the token's auth_time; otherwise
     * 403 reauth_required - the page offers "Sign in again" (the SDK's
     * signIn({prompt: 'login'})).
     *
     * @param Closure(ServerRequestInterface): ?int $authTime  the token's auth_time (Unix seconds), null when it has none
     * @return Closure(ServerRequestInterface, array<string, mixed>): ?Refusal
     */
    public static function recentSignIn(Closure $authTime, int $seconds = 600): Closure
    {
        return static function (ServerRequestInterface $request) use ($authTime, $seconds): ?Refusal {
            $at = $authTime($request);
            if (is_int($at) && $at >= time() - $seconds && $at <= time() + 60) {
                return null;
            }

            return new Refusal(403, 'reauth_required', 'This needs a sign-in of the last ' . intdiv($seconds, 60) . ' minutes. Sign in again, then try once more.');
        };
    }

    /** Who is acting: the site's answer, or null when it has none (or fails). */
    public function actorId(ServerRequestInterface $request): int|string|null
    {
        try {
            $actor = ($this->actorId)($request);
        } catch (Throwable) {
            return null;
        }

        return is_int($actor) || (is_string($actor) && $actor !== '') ? $actor : null;
    }

    /** 'password' or 'recent_sign_in': which proof the page asks for. */
    public function proofKind(ServerRequestInterface $request): string
    {
        $kind = is_string($this->proofKind) ? $this->proofKind : ($this->proofKind)($request);

        return $kind === self::PROOF_PASSWORD ? self::PROOF_PASSWORD : self::PROOF_RECENT_SIGN_IN;
    }

    /**
     * Null when the request carries the proof; otherwise why not. A site that
     * gave no proof has none to give: refused, never waved through.
     *
     * @param array<string, mixed> $body
     */
    public function proof(ServerRequestInterface $request, array $body): ?Refusal
    {
        if ($this->proof === null) {
            return new Refusal(403, 'proof_unavailable', 'This site has not said how its administrators prove who they are, so this cannot be done here.');
        }

        $answer = ($this->proof)($request, $body);

        return match (true) {
            $answer === null, $answer === true => null,
            $answer instanceof Refusal => $answer,
            is_array($answer) && isset($answer['proof']) && !isset($answer['status']) => null,
            is_array($answer) && is_int($answer['status'] ?? null) => new Refusal(
                $answer['status'],
                is_string($answer['code'] ?? null) ? $answer['code'] : 'proof_failed',
                is_string($answer['error'] ?? null) ? $answer['error'] : (is_string($answer['message'] ?? null) ? $answer['message'] : 'That did not prove who you are.'),
            ),
            default => new Refusal(403, 'proof_failed', 'That did not prove who you are.'),
        };
    }

    /**
     * The address bucket the site's limits count for this request: the site's clientKey, else ClientAddress's
     * rule over the context's trusted proxies - never a header from anybody else - an IPv6 address as its /64.
     */
    public function clientKey(ServerRequestInterface $request): string
    {
        if ($this->clientKey !== null) {
            try {
                $key = ($this->clientKey)($request);
                if (is_string($key) && $key !== '') {
                    return $key;
                }
            } catch (Throwable) {
                // The rule's own answer below.
            }
        }
        $address = ClientAddress::of($request, $this->trustedProxies, $this->clientHeader, $this->originSecret);

        return $address === null ? '?' : ClientAddress::bucket($address);
    }

    /** The refusal as the site answers one. */
    public function refuse(ResponseInterface $response, Refusal $refusal): ResponseInterface
    {
        $response = $this->error !== null ? ($this->error)($response, $refusal) : JsonResponse::write($response, $refusal->status, $refusal->body());
        if ($refusal->status === 503 && !$response->hasHeader('Retry-After')) {
            $response = $response->withHeader('Retry-After', '60');
        }
        if ($refusal->status === 429 && is_int($refusal->extra['retry_after'] ?? null) && !$response->hasHeader('Retry-After')) {
            $response = $response->withHeader('Retry-After', (string) $refusal->extra['retry_after']);
        }

        return $response->hasHeader('Cache-Control') ? $response : $response->withHeader('Cache-Control', 'no-store');
    }
}
