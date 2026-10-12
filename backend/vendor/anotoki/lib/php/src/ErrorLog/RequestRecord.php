<?php

declare(strict_types=1);

namespace Anotoki\Lib\ErrorLog;

use Psr\Http\Message\ServerRequestInterface;

/**
 * What the error log knows of the request being answered: the request (for a
 * PHP warning, which comes without one), whether its failure is written down
 * already (an exception's line, with its trace, is not followed by a second
 * line saying only "500"), and the fingerprints it wrote (a warning in a loop
 * writes one line, not a thousand).
 *
 * @internal
 */
final class RequestRecord
{
    public bool $handled = false;

    /** A request to the log's own routes: nothing of it is written. */
    public bool $own = false;

    /**
     * The request as a route of the library's admin tools saw it (AdminGuard) - later in the chain than the
     * one the outer middleware holds, with what the site's middleware set on it (Identify's person): the
     * line's "who" is read from it too.
     */
    public ?ServerRequestInterface $latest = null;

    /** @var array<string, true> */
    public array $seen = [];

    public function __construct(public ServerRequestInterface $request)
    {
    }
}
