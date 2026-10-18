<?php

declare(strict_types=1);

namespace Anotoki\Lib\ErrorLog;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Throwable;

/**
 * The error log's two places in a site's middleware (PSR-15), which share what
 * they know of a request through the request attribute ErrorLog::ATTRIBUTE:
 *
 * - outer(): outside everything (added last - Slim runs the last added first),
 *   around the site's error middleware. It holds the request being answered
 *   (a PHP warning comes without one), and writes down an answer whose status
 *   the log keeps - a route that answered 404 or 503 itself - unless an
 *   exception's line said it already. An exception that nothing answered
 *   (a site without error middleware) is written down and thrown on.
 * - inner(): inside the site's error middleware (added before it), for a site
 *   whose error handling is no Slim default handler: an exception is written
 *   down with its status (a Slim HTTP exception's own, else 500) and thrown on
 *   to the site's handler. A site with a Slim handler wraps it instead
 *   (ErrorLog::wrapHandler()), which knows the status the site answered.
 * - own(): on the log's own routes (ErrorLogRoutes puts it there): the request
 *   is the log's, never written into it - wherever the routes are mounted.
 */
final class ErrorLogMiddleware implements MiddlewareInterface
{
    private const OUTER = 'outer';
    private const INNER = 'inner';
    private const OWN = 'own';

    private function __construct(private readonly ErrorLog $log, private readonly string $place) {}

    public static function outer(ErrorLog $log): self
    {
        return new self($log, self::OUTER);
    }

    public static function inner(ErrorLog $log): self
    {
        return new self($log, self::INNER);
    }

    /** @internal ErrorLogRoutes */
    public static function own(ErrorLog $log): self
    {
        return new self($log, self::OWN);
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        return match ($this->place) {
            self::OUTER => $this->around($request, $handler),
            self::INNER => $this->inside($request, $handler),
            default => $handler->handle($this->log->markOwn($request)),
        };
    }

    private function around(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $record = new RequestRecord($request);
        $request = $request->withAttribute(ErrorLog::ATTRIBUTE, $record);
        $record->request = $request;
        $before = $this->log->enter($record);

        try {
            try {
                $response = $handler->handle($request);
            } catch (Throwable $exception) {
                if (!$record->handled) {
                    $this->log->record($exception, $request, ErrorLog::statusOf($exception));
                }
                throw $exception;
            }
            $this->log->recordResponse($request, $response);

            return $response;
        } finally {
            $this->log->leave($before);
        }
    }

    private function inside(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        try {
            return $handler->handle($request);
        } catch (Throwable $exception) {
            if (!$this->log->ignoresException($exception)) {
                $this->log->record($exception, $request, ErrorLog::statusOf($exception));
            }
            throw $exception;
        }
    }
}
