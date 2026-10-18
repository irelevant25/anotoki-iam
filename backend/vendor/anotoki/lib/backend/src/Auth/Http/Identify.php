<?php

declare(strict_types=1);

namespace Anotoki\Lib\Auth\Http;

use Anotoki\Lib\Auth\AccessTokens;
use Anotoki\Lib\Auth\Bearer;
use Anotoki\Lib\Auth\Person;
use Anotoki\Lib\Auth\SignInRule;
use Anotoki\Lib\Support\JsonResponse;
use Anotoki\Lib\Support\Log;
use Anotoki\Lib\Support\Refusal;
use Anotoki\Lib\Support\Throttle;
use Closure;
use LogicException;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Who a request is - once, for every route behind it (PSR-15 middleware). The
 * contract of the IAM's docs/integrating-an-app.md ("Who a request is"), in
 * this order:
 *
 *   1. An `Authorization: Bearer ...` header makes the request a person's - a
 *      verified token, or 401 invalid_token (WWW-Authenticate: Bearer
 *      error="invalid_token"), an empty one too. It never falls back to the
 *      visitor key, which is not even read. Only the Bearer scheme counts:
 *      `Basic ...` is nobody.
 *   2. Otherwise, while signing in is required here (SignInRule), it is nobody,
 *      and the visitor key is never read: every route that needs somebody
 *      answers 401 sign_in_required.
 *   3. Otherwise an X-Visitor-Key is the site's visitor - its `visitor`
 *      closure's answer - or 401 visitor_unknown for a key it does not know.
 *   4. Otherwise nobody.
 *
 * It sets the request attributes anotoki.person (?Person), anotoki.visitor
 * (whatever the site's closure answered, or null) and anotoki.caller (person,
 * visitor or nobody), and leaves the refusals that need somebody to the routes
 * behind it: RequirePerson, RequireRole, RequireCaller, SignInGate. Telling a
 * person reads no table, so the Migrations routes keep working while `users`
 * waits for an update; the visitor closure is the only read, for a request
 * that sends a key and no Bearer while visitors are welcome.
 *
 * Nothing else is ever read as who somebody is: no cookie (the IAM's sign-in
 * hint `anotoki_signed_in` among them - it says somebody signed in at the IAM
 * in this browser, never who), no body, no other header.
 *
 * Put it inside the site's SiteGate (added before it: Slim runs the last
 * added first), so a site that is not set up never asks the IAM anything.
 *
 * The answers are the family's {code, message}; a site that keeps another
 * shape while it adopts this gives a responder.
 */
final class Identify implements MiddlewareInterface
{
    public const PERSON = 'anotoki.person';
    public const VISITOR = 'anotoki.visitor';
    public const CALLER = 'anotoki.caller';
    /** The Identify that identified the request: what RequirePerson, RequireRole and the others answer through. */
    public const SELF = 'anotoki.identify';

    public const VISITOR_KEY_HEADER = 'X-Visitor-Key';

    /** The longest visitor key looked up at all: the sites' keys are 43 to 64 characters. */
    private const VISITOR_KEY_MAX = 256;

    /** A kind of refused token is said to the library's Log at most once in this many seconds. */
    private const LOG_EVERY = 60;

    /** @var array<string, int> when each kind of refusal was last said, for a site with no cache folder */
    private static array $said = [];

    private readonly string $realm;

    /**
     * @param ?Closure(string $key): mixed $visitor  the site's visitor for a key, or null for a key it does
     *        not know (or that is no key); null: the site has no visitors, and a key is never read
     * @param ?Closure(ResponseInterface, Refusal): ResponseInterface $responder  the site's error body; by
     *        default {code, message}
     * @param ?string $realm  the WWW-Authenticate realm; the client id by default
     * @param ?Closure(string $reason): void $log  why a token was refused, for the site's log; by default the
     *        library's Log, for every reason but an expired token (an everyday thing)
     */
    public function __construct(
        private readonly AccessTokens $tokens,
        private readonly SignInRule $rule,
        private readonly ResponseFactoryInterface $responses,
        private readonly ?Closure $visitor = null,
        private readonly ?Closure $responder = null,
        ?string $realm = null,
        private readonly ?Closure $log = null,
    ) {
        $this->realm = $realm ?? ($tokens->iam()->clientId !== '' ? $tokens->iam()->clientId : 'anotoki');
        if (preg_match('/^[A-Za-z0-9 ._-]{1,64}$/D', $this->realm) !== 1) {
            throw new LogicException('Identify: the realm is a short plain name (letters, digits, space, dot, dash, underscore).');
        }
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        // One answer about signing in for the whole request, asked afresh for each.
        $this->rule->reset();
        $request = $request->withAttribute(self::SELF, $this);

        $header = Bearer::header($request);
        if (Bearer::sent($header)) {
            $token = Bearer::token($header);
            $person = $token === null ? null : $this->tokens->verify($token);
            if ($person === null) {
                if ($token === null) {
                    $this->note('a Bearer header with no token', 'a Bearer header with no token');
                } else {
                    $this->note((string) $this->tokens->lastReason(), (string) $this->tokens->lastReasonKind());
                }

                return $this->refuse(new Refusal(401, 'invalid_token', 'Your sign-in has run out. Please sign in again.'));
            }

            return $handler->handle($request->withAttribute(self::PERSON, $person)->withAttribute(self::VISITOR, null)->withAttribute(self::CALLER, 'person'));
        }

        $nobody = $request->withAttribute(self::PERSON, null)->withAttribute(self::VISITOR, null)->withAttribute(self::CALLER, 'nobody');
        $key = trim($request->getHeaderLine(self::VISITOR_KEY_HEADER));
        if ($key === '' || $this->visitor === null || $this->rule->required()) {
            return $handler->handle($nobody);
        }

        $visitor = strlen($key) <= self::VISITOR_KEY_MAX ? ($this->visitor)($key) : null;
        if ($visitor === null) {
            return $this->refuse(new Refusal(401, 'visitor_unknown', 'This browser\'s visitor key is not known here.'));
        }

        return $handler->handle($nobody->withAttribute(self::VISITOR, $visitor)->withAttribute(self::CALLER, 'visitor'));
    }

    /** The request's person, after Identify; null for a visitor and for nobody. */
    public static function person(ServerRequestInterface $request): ?Person
    {
        $person = $request->getAttribute(self::PERSON);

        return $person instanceof Person ? $person : null;
    }

    /** The request's visitor - what the site's closure answered - or null. */
    public static function visitor(ServerRequestInterface $request): mixed
    {
        return $request->getAttribute(self::VISITOR);
    }

    /** person, visitor or nobody. */
    public static function caller(ServerRequestInterface $request): string
    {
        return (string) self::of($request)->callerOf($request);
    }

    /** The Identify a request went through; a LogicException when it went through none (a route set up wrong). */
    public static function of(ServerRequestInterface $request): self
    {
        $identify = $request->getAttribute(self::SELF);
        if (!($identify instanceof self)) {
            throw new LogicException('Who a request is is not known here: add Identify in front of RequirePerson, RequireRole, RequireCaller and SignInGate.');
        }

        return $identify;
    }

    /** Whether signing in is required here, for this request (one answer per request). */
    public function signInRequired(): bool
    {
        return $this->rule->required();
    }

    /**
     * A refusal in the site's shape, never cached, with WWW-Authenticate on a
     * 401 (RFC 6750: `error` only for a token that was sent).
     */
    public function refuse(Refusal $refusal): ResponseInterface
    {
        $response = $this->responses->createResponse();
        $response = $this->responder !== null ? ($this->responder)($response->withStatus($refusal->status), $refusal) : JsonResponse::write($response, $refusal->status, $refusal->body());

        if ($refusal->status === 401) {
            $challenge = 'Bearer realm="' . $this->realm . '"' . ($refusal->code === 'invalid_token' ? ', error="invalid_token"' : '');
            $response = $response->withHeader('WWW-Authenticate', $challenge);
        }

        return $response->withStatus($refusal->status)->withHeader('Cache-Control', 'no-store');
    }

    /** The 401 of a route that needs somebody, for nobody and for a visitor where only a person will do. */
    public function signInRequiredRefusal(): ResponseInterface
    {
        return $this->refuse(new Refusal(401, 'sign_in_required', 'Please sign in.'));
    }

    private function callerOf(ServerRequestInterface $request): string
    {
        $caller = $request->getAttribute(self::CALLER);

        return in_array($caller, ['person', 'visitor', 'nobody'], true) ? $caller : 'nobody';
    }

    /**
     * Why a token was refused, for whoever keeps the site: to the site's own `log`, every time; to the library's
     * Log, never for an expired token (an everyday thing) and at most once a minute for each kind of refusal -
     * a stamp per kind in the IAM client's cache folder (per process without one) - so tokens made up by the
     * thousand are one line a minute, not a line each. The reason is the library's words (AccessTokens).
     */
    private function note(string $reason, string $kind): void
    {
        if ($this->log !== null) {
            ($this->log)($reason);

            return;
        }
        if ($kind !== 'expired' && self::firstThisMinute($this->tokens->iam()->cacheDirectory(), $kind)) {
            Log::say('[anotoki auth] token refused: ' . $reason);
        }
    }

    private static function firstThisMinute(?string $directory, string $kind): bool
    {
        // A folder the stamp cannot be written to would silence every line: then counted per process instead.
        if ($directory !== null && is_writable($directory)) {
            $slug = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($kind)), '-');

            return Throttle::due($directory . '/anotoki-auth-refused-' . ($slug === '' ? 'other' : $slug) . '.stamp', self::LOG_EVERY);
        }

        $now = time();
        $last = self::$said[$kind] ?? null;
        if ($last !== null && $last <= $now && $now - $last < self::LOG_EVERY) {
            return false;
        }
        self::$said[$kind] = $now;

        return true;
    }
}
