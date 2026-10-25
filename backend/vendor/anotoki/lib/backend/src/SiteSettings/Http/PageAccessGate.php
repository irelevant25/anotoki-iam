<?php

declare(strict_types=1);

namespace Anotoki\Lib\SiteSettings\Http;

use Anotoki\Lib\Auth\Http\Identify;
use Anotoki\Lib\Auth\Person;
use Anotoki\Lib\SiteSettings\SiteSettings;
use Anotoki\Lib\Support\JsonResponse;
use Anotoki\Lib\Support\Refusal;
use Closure;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Page access's half on the server (PSR-15 middleware, in front of every route, inside Identify): an API path a
 * page's row refuses with it answers 423 `page_locked` to everybody the page is not shown to - a page switched
 * off, or one for people signed in, for editors or for administrators alone. The frontend's guard takes the page
 * out of the router; this takes its data away, where the row names the API paths that belong to it (none: the page
 * alone - "that page is not finished" - and its data still answers).
 *
 * - The path is read as the router reads it - decoded, a slash in front - so '/%61pi/quiz' is '/api/quiz' here too.
 * - The site's ADMIN is never refused (no lockout: whoever switched a page off must be able to look at it), and
 *   no reserved API path ever is - who the reader is, the sign-in's configuration, the site's status, its words,
 *   the settings - whatever a row put in by hand says.
 * - Where two pages claim one path, the more open one wins (SiteSettings::refusesApi()).
 *
 * 423 rather than 404: nobody is told a lie about what exists, and the state is one somebody put the site into on
 * purpose and will take it out of again. The answer is the site's error body where the request went through
 * Identify (its responder), else the library's {code, message}; never cached.
 */
final class PageAccessGate implements MiddlewareInterface
{
    /**
     * @param null|Closure(ServerRequestInterface): ?Person $reader  who reads; by default the person Identify found
     *        (put this gate inside Identify - added before it: Slim runs the last added first - or it refuses to run)
     */
    public function __construct(private readonly SiteSettings $settings, private readonly ResponseFactoryInterface $responses, private readonly ?Closure $reader = null) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (!$this->settings->offers(SiteSettings::PAGE_ACCESS)) {
            return $handler->handle($request);
        }
        $path = rawurldecode($request->getUri()->getPath());
        $path = $path === '' || $path[0] !== '/' ? '/' . $path : $path;
        // A row claims API paths alone (PageRules::apiProblem()), and never a reserved one.
        if (!str_starts_with($path, '/api/') || $this->settings->isReservedApiPath($path)) {
            return $handler->handle($request);
        }

        if ($this->reader !== null) {
            $reader = ($this->reader)($request);
        } else {
            // Identify's person - and Identify must have run: of() says so loudly where it did not, rather than
            // taking an administrator for nobody.
            Identify::of($request);
            $reader = Identify::person($request);
        }
        if (!$this->settings->refusesApi($path, $reader instanceof Person ? $reader : null)) {
            return $handler->handle($request);
        }

        $refusal = new Refusal(423, 'page_locked', 'That part of the site is not available.');
        $identify = $request->getAttribute(Identify::SELF);
        if ($identify instanceof Identify) {
            return $identify->refuse($refusal);
        }

        return JsonResponse::write($this->responses->createResponse(), $refusal->status, $refusal->body());
    }
}
