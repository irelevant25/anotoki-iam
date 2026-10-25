<?php

declare(strict_types=1);

namespace Anotoki\Lib\Migrations\Http;

use Anotoki\Lib\Support\JsonResponse;
use Closure;
use InvalidArgumentException;
use LogicException;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Throwable;

/**
 * The one behaviour of every anotoki site while it is not ready (PSR-15
 * middleware, in front of every route):
 *
 * - the status path always passes: it is how the pages learn the state;
 * - not_set_up: every other path answers 503 {code: "not_set_up", installed,
 *   message}, with `setup` (the setup page's path) only while the site was
 *   never installed - once its installer finished nobody is sent there again;
 * - update_pending, on a site that blocks while an update waits: the open
 *   paths pass (the boot/sign-in configuration, the translations, the health
 *   check, the admin migrations routes - so people can sign in, the pages have
 *   their words and an administrator reaches Apply), everything else answers
 *   503 {code: "update_pending", message} with Retry-After: 60;
 * - closed for maintenance (0.11.0: a ready site whose administrators switched
 *   the site settings' maintenance mode on - SiteState::maintenance()): the
 *   open paths pass, and so do the site settings' reserved API paths (who the
 *   reader is, the sign-in configuration, the status, the words, the settings
 *   themselves), and so does whoever `exempt` says - the site's ADMIN, judged
 *   by the token alone; everybody else gets 503 {code: "maintenance", message,
 *   maintenance_message} with Retry-After: 300. A site that can be closed
 *   cannot build a gate without its ADMIN check: its administrators would be
 *   shut out with everybody else;
 * - unavailable and ready: everything passes (the site's own error handling
 *   answers a dead database).
 *
 * The state is computed once per request.
 */
final class SiteGate implements MiddlewareInterface
{
    private const RETRY_AFTER_SECONDS = 60;
    private const MAINTENANCE_RETRY_AFTER_SECONDS = 300;

    /**
     * @param list<string> $open  paths answered while an update waits (and while the site is closed for
     *                            maintenance): exact paths, or a regular expression (delimiters included) when it
     *                            starts with '~'
     * @param null|Closure(ServerRequestInterface): bool $exempt  who passes while the site is closed for maintenance:
     *        the site's ADMIN, from the token - the gate runs before the site identifies the request, so it reads
     *        the token itself (AccessTokens::personOf()): `fn ($request) => $tokens->personOf($request)?->isAdmin() ?? false`.
     *        Required where the state's site settings offer "Getting in"; a check that throws exempts nobody
     */
    public function __construct(
        private readonly SiteState $state,
        private readonly ResponseFactoryInterface $responses,
        private readonly array $open = [],
        private readonly string $statusPath = '/api/site-status',
        private readonly string $setupPath = '/setup.php',
        private readonly ?Closure $exempt = null,
    ) {
        foreach ($open as $path) {
            if (!is_string($path)) {
                throw new InvalidArgumentException('Every open path must be a string.');
            }
            if (str_starts_with($path, '~') && @preg_match($path, '') === false) {
                throw new InvalidArgumentException("The open path $path is not a valid regular expression.");
            }
        }
        if ($exempt === null && $state->offersMaintenance()) {
            throw new LogicException(
                'The site can be closed for maintenance: give its SiteGate the ADMIN check (exempt: fn ($request) => $tokens->personOf($request)?->isAdmin() ?? false), or its administrators would be shut out with everybody else.',
            );
        }
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $path = rawurldecode($request->getUri()->getPath());
        if ($path === $this->statusPath) {
            return $handler->handle($request);
        }

        $state = $this->state->state();

        if ($state === SiteState::NOT_SET_UP) {
            $installed = $this->state->installed();
            $body = [
                'code' => 'not_set_up',
                'installed' => $installed,
                'message' => $installed ? 'The site is not available right now. Try again in a few minutes.' : 'This site is not set up yet. Its setup page connects it to its database.',
            ];
            if (!$installed) {
                $body['setup'] = $this->setupPath;
            }

            return $this->json($body);
        }

        if ($state === SiteState::UPDATE_PENDING && $this->state->blockWhilePending && !$this->isOpen($path)) {
            return $this->json([
                'code' => 'update_pending',
                'message' => 'The site is being updated. Try again in a few minutes.',
            ])->withHeader('Retry-After', (string) self::RETRY_AFTER_SECONDS);
        }

        if ($state === SiteState::READY && $this->state->offersMaintenance() && !$this->isOpenWhileClosed($path)) {
            $message = $this->state->maintenance();
            if ($message !== null && !$this->isExempt($request)) {
                return $this->json([
                    'code' => 'maintenance',
                    'message' => 'The site is closed for maintenance. Try again later.',
                    'maintenance_message' => (object) $message,
                ])->withHeader('Retry-After', (string) self::MAINTENANCE_RETRY_AFTER_SECONDS);
            }
        }

        return $handler->handle($request);
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

    /** The open paths, and the site settings' reserved API paths - each itself and everything under it. */
    private function isOpenWhileClosed(string $path): bool
    {
        // The router reads the path with a slash in front when it has none: so does this.
        $routed = $path === '' || $path[0] !== '/' ? '/' . $path : $path;

        return $this->isOpen($path) || ($this->state->settings()?->isReservedApiPath($routed) ?? false);
    }

    private function isExempt(ServerRequestInterface $request): bool
    {
        try {
            return $this->exempt !== null && ($this->exempt)($request) === true;
        } catch (Throwable) {
            return false;
        }
    }

    /** @param array<string, mixed> $body */
    private function json(array $body): ResponseInterface
    {
        return JsonResponse::write($this->responses->createResponse(), 503, $body);
    }
}
