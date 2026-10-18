<?php

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;

/**
 * The global middleware: who may call what from where, and the headers every
 * answer carries.
 *
 * Three audiences, three rules:
 *
 *   the IAM's own pages   same origin, cookie session. Every write must carry
 *                         X-IAM-Request (a custom header forces a CORS preflight,
 *                         which these endpoints never answer), and an Origin, when
 *                         the browser sends one, must be the IAM's own.
 *   applications          the token, userinfo and revocation endpoints and a
 *                         site's save of its reader's preferences, called
 *                         cross-origin from the sites' pages. CORS is echoed for
 *                         an origin some application has registered - no
 *                         credentials, no cookie; the token endpoint and the
 *                         preferences then also check the origin belongs to
 *                         the client named, or the token's.
 *   anybody               discovery, the key set and the landing-page list are
 *                         public and cacheable: CORS "*".
 */

/** Endpoints called by the sites' own pages, cross-origin, with no cookie. */
const CORS_CLIENT_PATHS = ['/api/oidc/token', '/api/oidc/userinfo', '/api/oidc/revoke', '/api/oidc/preferences'];

/** Public documents anybody may fetch from anywhere. */
const CORS_PUBLIC_PATHS = ['/.well-known/openid-configuration', '/.well-known/jwks.json', '/api/applications'];

/**
 * The only writes that need no CSRF header: the endpoints the sites call
 * cross-origin, which read no cookie - the token, userinfo and revocation
 * endpoints, and a site's save of its reader's preferences, whose proof is the
 * person's access token (logout is a GET). None of them may ever read the
 * session cookie: the header is what stands between a cookie and a page on a
 * sibling subdomain.
 */
const CSRF_EXEMPT_PATHS = ['/api/oidc/token', '/api/oidc/userinfo', '/api/oidc/revoke', '/api/oidc/preferences'];

/**
 * The path as the router sees it.
 *
 * Slim matches routes against the percent-decoded path, so every decision
 * about a path is made on that one too: `/%61pi/...` reaches the same route as
 * `/api/...`, and a check reading the raw path would wave it through.
 */
function routedPath(Request $request): string
{
    return rawurldecode($request->getUri()->getPath());
}

function corsMiddleware(): callable
{
    return function (Request $request, RequestHandler $handler): Response {
        $path = routedPath($request);
        $origin = $request->getHeaderLine('Origin');
        $preflight = $request->getMethod() === 'OPTIONS';

        $headers = [];

        if (in_array($path, CORS_PUBLIC_PATHS, true)) {
            $headers = [
                'Access-Control-Allow-Origin' => '*',
                'Access-Control-Allow-Methods' => 'GET, OPTIONS',
                'Access-Control-Allow-Headers' => 'Content-Type',
            ];
        } elseif (in_array($path, CORS_CLIENT_PATHS, true) && $origin !== '' && originRegistered($origin)) {
            $headers = [
                'Access-Control-Allow-Origin' => $origin,
                'Access-Control-Allow-Methods' => 'GET, POST, OPTIONS',
                'Access-Control-Allow-Headers' => 'Authorization, Content-Type',
                'Access-Control-Max-Age' => '600',
            ];
        }

        $response = $preflight ? new \Slim\Psr7\Response(204) : $handler->handle($request);

        foreach ($headers as $name => $value) {
            $response = $response->withHeader($name, $value);
        }

        // The answer depends on the Origin wherever it is echoed, and a cache
        // in between must not hand one site's answer to another.
        if (isset($headers['Access-Control-Allow-Origin']) && $headers['Access-Control-Allow-Origin'] !== '*') {
            $response = $response->withHeader('Vary', 'Origin');
        }

        return $response;
    };
}

/**
 * The CSRF guard for the IAM's own endpoints.
 *
 * SameSite=Lax on the cookie already keeps other sites' forms from sending
 * it. This covers what Lax does not: a page on a sibling subdomain is
 * "same-site" and would get the cookie sent. The custom header is the part
 * that cannot be forged cross-origin without a preflight nobody answers.
 *
 * Every write needs it except the few protocol endpoints named exactly - not
 * "everything outside /api/", which an encoded path used to count as while
 * the router still found the route behind it.
 */
function csrfMiddleware(): callable
{
    return function (Request $request, RequestHandler $handler): Response {
        $method = $request->getMethod();

        $exempt = in_array($method, ['GET', 'HEAD', 'OPTIONS'], true) || in_array(routedPath($request), CSRF_EXEMPT_PATHS, true);

        if ($exempt) {
            return $handler->handle($request);
        }

        $origin = $request->getHeaderLine('Origin');

        if ($request->getHeaderLine('X-IAM-Request') !== '1' || ($origin !== '' && $origin !== iamOrigin())) {
            return respondError(new \Slim\Psr7\Response(), 403, 'csrf', 'This request did not come from the IAM\'s own pages');
        }

        return $handler->handle($request);
    };
}

/**
 * Baseline headers on every answer. A route that set one knows its own needs
 * better, so each is only filled in where it is missing.
 */
function securityHeadersMiddleware(): callable
{
    return function (Request $request, RequestHandler $handler): Response {
        $response = $handler->handle($request);

        $ensure = static fn(Response $response, string $name, string $value): Response => $response->hasHeader($name) ? $response : $response->withHeader($name, $value);

        $response = $ensure($response, 'X-Content-Type-Options', 'nosniff');
        $response = $ensure($response, 'X-Frame-Options', 'DENY');
        $response = $ensure($response, 'Referrer-Policy', 'no-referrer');
        $response = $ensure($response, 'Content-Security-Policy', "default-src 'none'; frame-ancestors 'none'");

        // Nothing an identity provider answers should sit in a shared cache
        // unless a route says otherwise (discovery and the key set do).
        $response = $ensure($response, 'Cache-Control', 'no-store');

        if ($request->getUri()->getScheme() === 'https' || str_starts_with(iamIssuer(), 'https://')) {
            $response = $ensure($response, 'Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        return $response;
    };
}
