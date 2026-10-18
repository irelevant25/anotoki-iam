<?php

declare(strict_types=1);

namespace Anotoki\Lib\People\Http;

use Anotoki\Lib\Auth\Http\Identify;
use Anotoki\Lib\People\People;
use Closure;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Keeps the row of the request's person (PeopleRecorder::seen()) before the
 * route runs - on the route groups a site names, behind Identify. Never on the
 * Migrations routes: they must work while `users` waits for an update. A
 * visitor and nobody are not recorded here (the site's visitors are its own).
 *
 *   $app->group('/api', $routes)->add(new RecordPerson($people, extra: fn (ServerRequestInterface $r): array
 *       => ['time_zone' => knownTimeZone($r->getHeaderLine('X-Time-Zone'))]));
 */
final class RecordPerson implements MiddlewareInterface
{
    /**
     * @param ?Closure(ServerRequestInterface): array<string, scalar|null> $extra  the site's own columns
     */
    public function __construct(private readonly People $people, private readonly ?Closure $extra = null) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $person = Identify::person($request);
        if ($person !== null) {
            $this->people->recorder()->seen($this->people->pdo(), $person, $this->extra !== null ? ($this->extra)($request) : []);
        }

        return $handler->handle($request);
    }
}
