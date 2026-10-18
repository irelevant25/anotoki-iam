<?php

declare(strict_types=1);

namespace Anotoki\Lib\Auth;

use Psr\Http\Message\ServerRequestInterface;

/**
 * The Authorization header of a request, read the way every anotoki site reads
 * it. Only the Bearer scheme claims to be somebody: `Basic ...` is what a host
 * protected by a password file sends with every request, and says nothing
 * about who is using the site. A token anywhere else - a query string, a
 * cookie, a body - is never read: it ends up in logs and in the history.
 */
final class Bearer
{
    /**
     * The request's Authorization header, however the server passed it: the
     * header itself, or what a rewrite rule copied into the environment
     * (Apache under CGI/FastCGI drops the header unless told otherwise, and
     * `SetEnvIf Authorization "(.*)" HTTP_AUTHORIZATION=$1` makes it arrive as
     * REDIRECT_HTTP_AUTHORIZATION). '' for none.
     */
    public static function header(ServerRequestInterface $request): string
    {
        $header = $request->getHeaderLine('Authorization');
        if ($header !== '') {
            return $header;
        }

        $server = $request->getServerParams();
        foreach (['HTTP_AUTHORIZATION', 'REDIRECT_HTTP_AUTHORIZATION'] as $key) {
            if (isset($server[$key]) && is_string($server[$key]) && $server[$key] !== '') {
                return $server[$key];
            }
        }

        return '';
    }

    /**
     * Whether the header claims to be somebody: the Bearer scheme, with or
     * without a token after it. A request that claims so is a person's or is
     * refused - it never falls back to being a visitor.
     */
    public static function sent(string $header): bool
    {
        return preg_match('/^\s*Bearer(?:\s|$)/i', $header) === 1;
    }

    /** The token of a Bearer header (the scheme in any case, as RFC 6750 allows), or null. */
    public static function token(string $header): ?string
    {
        $header = trim($header);
        if (strncasecmp($header, 'Bearer ', 7) !== 0) {
            return null;
        }
        $token = trim(substr($header, 7));

        return $token === '' ? null : $token;
    }
}
