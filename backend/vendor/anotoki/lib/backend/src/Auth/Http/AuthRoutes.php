<?php

declare(strict_types=1);

namespace Anotoki\Lib\Auth\Http;

use Anotoki\Lib\Auth\IamClient;
use Anotoki\Lib\Auth\SignInRule;
use Anotoki\Lib\Support\JsonResponse;
use Closure;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Handlers\Strategies\RequestResponse;
use Slim\Interfaces\RouteCollectorProxyInterface;
use Slim\Interfaces\RouteInterface;

/**
 * What a site's pages need to sign in, from its own server - so the browser
 * never asks the IAM about the site itself:
 *
 *   GET /api/auth/config -> 200 {issuer, client_id, login_required, sign_in_available, ...extra}
 *
 * - login_required: SignInRule::required(), failing closed;
 * - sign_in_available: the site names an IAM and itself, and the IAM did not
 *   answer that it does not know the site (SignInRule::known()) - false, and
 *   the pages offer no sign-in and the hint never sends anybody to the IAM;
 * - extra: the site's own fields (the visitors' days, say).
 *
 * Public, no-store, read without a token. Keep it open in the SiteGate
 * (openPath()) so people can sign in while an update waits. A site whose own
 * boot answer carries this already (genshin's /api/settings/public, the build
 * analyzer's /api/meta) puts configBody() in it instead and saves a request.
 */
final class AuthRoutes
{
    public const CONFIG_PATH = '/api/auth/config';

    /**
     * @param ?Closure(): array<string, mixed> $extra
     */
    public static function config(RouteCollectorProxyInterface $app, SignInRule $rule, IamClient $iam, string $path = self::CONFIG_PATH, ?Closure $extra = null): RouteInterface
    {
        $route = $app->get($path, function (ServerRequestInterface $request, ResponseInterface $response) use ($rule, $iam, $extra): ResponseInterface {
            return JsonResponse::write($response, 200, self::configBody($rule, $iam, $extra));
        });
        // The handler gets ($request, $response), whatever the app's default invocation strategy.
        $route->setInvocationStrategy(new RequestResponse());

        return $route;
    }

    /**
     * The boot configuration's fields, for a site's own answer.
     *
     * @param ?Closure(): array<string, mixed> $extra
     * @return array<string, mixed>
     */
    public static function configBody(SignInRule $rule, IamClient $iam, ?Closure $extra = null): array
    {
        $body = [
            'issuer' => $iam->issuer,
            'client_id' => $iam->clientId,
            'login_required' => $rule->required(),
            'sign_in_available' => $iam->configured() && $rule->known(),
        ];

        return $extra === null ? $body : $body + $extra();
    }

    /** The path to keep open in the SiteGate (and in a SignInGate). */
    public static function openPath(string $path = self::CONFIG_PATH): string
    {
        return $path;
    }
}
