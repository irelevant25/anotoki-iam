<?php

declare(strict_types=1);

namespace Anotoki\Lib\Admin\Http;

use Anotoki\Lib\Admin\AdminContext;
use Anotoki\Lib\ErrorLog\ErrorLog;
use Anotoki\Lib\ErrorLog\RequestRecord;
use LogicException;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * The first word of every admin() route group of the library: the site's ADMIN
 * alone (AdminContext::adminRefusal() - 401 for nobody, 403 for anybody else),
 * whatever the site adds to the group, and even when it adds nothing. A group
 * mounted inside the site's own session check (the IAM's) gets that check
 * first; this one runs after it, on the request it hands on.
 *
 * @internal
 */
final class AdminGuard implements MiddlewareInterface
{
    public function __construct(private readonly AdminContext $context, private readonly ResponseFactoryInterface $responses)
    {
        if (!$context->knowsAdmins()) {
            throw new LogicException('The library\'s admin routes need the site\'s ADMIN check: new AdminContext(..., isAdmin: fn ($request): bool => ...).');
        }
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        // The error log's record of this request learns the request as the route sees it - with the person the
        // site's middleware set on it - so a failure here names who asked.
        $record = $request->getAttribute(ErrorLog::ATTRIBUTE);
        if ($record instanceof RequestRecord) {
            $record->latest = $request;
        }
        $refusal = $this->context->adminRefusal($request);

        return $refusal === null ? $handler->handle($request) : $this->context->refuse($this->responses->createResponse(), $refusal);
    }
}
