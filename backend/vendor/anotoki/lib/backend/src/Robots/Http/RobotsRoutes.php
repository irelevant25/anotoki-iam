<?php

declare(strict_types=1);

namespace Anotoki\Lib\Robots\Http;

use Anotoki\Lib\Admin\Http\Routes;
use Anotoki\Lib\Robots\Robots;
use Anotoki\Lib\Support\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Interfaces\RouteCollectorProxyInterface;
use Slim\Interfaces\RouteInterface;

/**
 * robots.txt and sitemap.xml, on the site's Slim app - the paths its front controller is given besides /api (the
 * deploy tool: `"routes": ["api", "robots.txt", "sitemap.xml"]` in backend/composer.json's extra["anotoki-deploy"]):
 *
 *   GET|HEAD /robots.txt   -> 200 text/plain, Robots::robotsTxt()
 *   GET|HEAD /sitemap.xml  -> 200 application/xml, Robots::sitemapXml() | 404 {code: "not_configured"} while the site
 *                             has no public address configured
 *
 * Public, and kept by the browser and the edge for an hour (Cache-Control: public, max-age=3600). Behind the
 * site's gate as any route: while the site is not set up, waits for an update or is closed for maintenance, robots
 * are told 503 with Retry-After - "come back later", which keeps what they had.
 */
final class RobotsRoutes
{
    public const ROBOTS_PATH = '/robots.txt';
    public const SITEMAP_PATH = '/sitemap.xml';

    private const CACHE = 'public, max-age=3600';

    public static function robots(RouteCollectorProxyInterface $app, Robots $robots, string $path = self::ROBOTS_PATH): RouteInterface
    {
        return Routes::plain(
            $app->map(['GET', 'HEAD'], $path, function (ServerRequestInterface $request, ResponseInterface $response) use ($robots): ResponseInterface {
                $response->getBody()->write($robots->robotsTxt());

                return $response->withHeader('Content-Type', 'text/plain; charset=utf-8')->withHeader('Cache-Control', self::CACHE);
            }),
        );
    }

    public static function sitemap(RouteCollectorProxyInterface $app, Robots $robots, string $path = self::SITEMAP_PATH): RouteInterface
    {
        return Routes::plain(
            $app->map(['GET', 'HEAD'], $path, function (ServerRequestInterface $request, ResponseInterface $response) use ($robots): ResponseInterface {
                $xml = $robots->sitemapXml();
                if ($xml === null) {
                    return JsonResponse::write($response, 404, ['code' => 'not_configured', 'message' => 'This site has no public address configured, and a sitemap lists absolute addresses.']);
                }
                $response->getBody()->write($xml);

                return $response->withHeader('Content-Type', 'application/xml; charset=utf-8')->withHeader('Cache-Control', self::CACHE);
            }),
        );
    }
}
