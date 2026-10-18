<?php

declare(strict_types=1);

namespace Anotoki\Lib\Audit;

use Closure;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Throwable;

/**
 * Holds who acts in a request for as long as it is answered (PSR-15) - the
 * person's id and session, read once from the request by the site's closure -
 * so that code which is not handed the request records with
 * AuditLog::recordHere() (genshin's auditSession()).
 *
 * Add it where the request already says who it is: inside the site's own
 * sign-in check (on a route group, added before that check - Slim runs the
 * last added first), so the attributes that check sets are there to read.
 */
final class AuditContextMiddleware implements MiddlewareInterface
{
    /** The request attribute the held AuditContext is put under. */
    public const ATTRIBUTE = 'anotoki.audit';

    /**
     * @param Closure(ServerRequestInterface): array{actor?: int|string|null, session?: ?string} $actorOf
     */
    private function __construct(private readonly AuditLog $log, private readonly Closure $actorOf) {}

    /**
     * @param Closure(ServerRequestInterface): array{actor?: int|string|null, session?: ?string} $actorOf
     *        who the request is from, as the site's sign-in check left it ([] for nobody)
     */
    public static function fromRequest(AuditLog $log, Closure $actorOf): self
    {
        return new self($log, $actorOf);
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        try {
            $who = ($this->actorOf)($request);
        } catch (Throwable) {
            $who = [];
        }
        $who = is_array($who) ? $who : [];
        $actor = $who['actor'] ?? null;
        $session = $who['session'] ?? null;

        $context = $this->log->begin($request, is_int($actor) || is_string($actor) ? $actor : null, is_string($session) && $session !== '' ? $session : null);
        $request = $request->withAttribute(self::ATTRIBUTE, $context);
        try {
            return $handler->handle($request);
        } finally {
            $this->log->end($context);
        }
    }
}
