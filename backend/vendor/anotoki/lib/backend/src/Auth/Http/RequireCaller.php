<?php

declare(strict_types=1);

namespace Anotoki\Lib\Auth\Http;

use InvalidArgumentException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Whose own data a route reads or saves: the person (Identify), else the
 * browser's visitor - the "owner" check every site wrote for itself (piano's,
 * genshin's and the build analyzer's requireOwner, the survey's requireTaker,
 * Japanese Academy's learner_middleware). A request that is nobody's:
 *
 *   - while signing in is required here: 401 sign_in_required, whatever the mode;
 *   - refuse: 401 sign_in_required;
 *   - empty:  the route answers what nobody has (the attribute anotoki.nobody is true);
 *   - make:   the route is a save that makes a visitor in its own transaction once its
 *             body checks out (the attribute anotoki.makeVisitor is true): the site makes
 *             the visitor, and hands its key back in X-Visitor-Key, as it does today.
 *
 * Every query below it on somebody's data is that one's own: the routes take
 * the id from Identify's attributes and from nowhere else.
 */
final class RequireCaller implements MiddlewareInterface
{
    public const REFUSE = 'refuse';
    public const EMPTY = 'empty';
    public const MAKE = 'make';

    /** Set (true) on a request of nobody's that an `empty` route answers. */
    public const NOBODY = 'anotoki.nobody';
    /** Set (true) on a request of nobody's that a `make` route turns into a visitor's. */
    public const MAKE_VISITOR = 'anotoki.makeVisitor';

    public function __construct(public readonly string $nobody = self::REFUSE)
    {
        if (!in_array($nobody, [self::REFUSE, self::EMPTY, self::MAKE], true)) {
            throw new InvalidArgumentException("RequireCaller('$nobody'): refuse, empty or make.");
        }
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $identify = Identify::of($request);
        $caller = Identify::caller($request);
        if ($caller === 'person' || $caller === 'visitor') {
            return $handler->handle($request);
        }

        if ($this->nobody === self::REFUSE || $identify->signInRequired()) {
            return $identify->signInRequiredRefusal();
        }

        return $handler->handle($request->withAttribute($this->nobody === self::MAKE ? self::MAKE_VISITOR : self::NOBODY, true));
    }
}
