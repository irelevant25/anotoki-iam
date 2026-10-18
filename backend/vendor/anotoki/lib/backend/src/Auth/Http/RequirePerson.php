<?php

declare(strict_types=1);

namespace Anotoki\Lib\Auth\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Somebody signed in: a verified token (Identify). Nobody - and a visitor,
 * however the routes are stacked - answers 401 sign_in_required.
 */
final class RequirePerson implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $identify = Identify::of($request);

        return Identify::person($request) !== null ? $handler->handle($request) : $identify->signInRequiredRefusal();
    }
}
