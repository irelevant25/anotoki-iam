<?php

declare(strict_types=1);

namespace Anotoki\Lib\Auth\Http;

use InvalidArgumentException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Optional, for a site that keeps one gate in front of everything rather than
 * a check on each route (genshin's signInGate()): while signing in is required
 * here, every path but the open ones needs a person - 401 sign_in_required.
 * While it is not, it lets everybody through, and the routes decide.
 *
 * The open paths are what a page needs before anybody signs in - the boot
 * configuration, the words, the site's status: exact paths, or a regular
 * expression (delimiters included, ending with D) when it starts with '~'.
 * Behind Identify (added before it).
 */
final class SignInGate implements MiddlewareInterface
{
    /** @param list<string> $open */
    public function __construct(private readonly array $open = [])
    {
        foreach ($open as $path) {
            if (!is_string($path) || $path === '') {
                throw new InvalidArgumentException('Every open path must be a string.');
            }
            if (str_starts_with($path, '~') && @preg_match($path, '') === false) {
                throw new InvalidArgumentException("The open path $path is not a valid regular expression.");
            }
        }
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $identify = Identify::of($request);
        if (Identify::person($request) !== null || $this->isOpen(rawurldecode($request->getUri()->getPath()))) {
            return $handler->handle($request);
        }

        return $identify->signInRequired() ? $identify->signInRequiredRefusal() : $handler->handle($request);
    }

    private function isOpen(string $path): bool
    {
        foreach ($this->open as $open) {
            if (str_starts_with($open, '~') ? preg_match($open, $path) === 1 : $open === $path) {
                return true;
            }
        }

        return false;
    }
}
