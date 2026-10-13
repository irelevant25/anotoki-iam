<?php

declare(strict_types=1);

namespace Anotoki\Lib\Support;

use Psr\Http\Message\ServerRequestInterface;

/**
 * Where a request comes from, by one rule wherever the library reads it (the
 * owner's of 2026-10-11): the connection's address (REMOTE_ADDR), unless the
 * connection is from one of the site's trusted proxies - then the address the
 * proxy's header names (Cloudflare's CF-Connecting-IP). A header from anybody
 * else is never believed: anybody can send one. The sites are behind
 * Cloudflare, whose published ranges are CLOUDFLARE.
 *
 * What the library keeps of an address is a keyed hash of its bucket - an
 * IPv4 address whole, an IPv6 address its /64 (one subscriber's whole
 * network) - never the address.
 *
 * Cloudflare's ranges are every Cloudflare customer's, not the owner's zone
 * alone: a Worker of anybody's can open a raw socket to the host from them and
 * write a CF-Connecting-IP of its choosing. A site that is given an origin
 * secret (0.5.2, the owner's decision of 2026-10-13) believes the header only
 * on a request that also carries ORIGIN_HEADER with that secret - which a
 * Transform Rule of the owner's zone adds to every request through it, and
 * nobody else knows. Without it such a request is counted by its connection
 * (the edge's address): coarser, never looser.
 */
final class ClientAddress
{
    /** The header Cloudflare names the visitor's address in. */
    public const CLOUDFLARE_HEADER = 'CF-Connecting-IP';

    /** The header the owner's Cloudflare zone adds with the origin secret (a Transform Rule). */
    public const ORIGIN_HEADER = 'X-Anotoki-Origin';

    /**
     * Cloudflare's published ranges (www.cloudflare.com/ips-v4 and /ips-v6), as of 2026-10-11. They change
     * rarely; a site that follows them closer passes its own list.
     */
    public const CLOUDFLARE = [
        '173.245.48.0/20', '103.21.244.0/22', '103.22.200.0/22', '103.31.4.0/22', '141.101.64.0/18', '108.162.192.0/18',
        '190.93.240.0/20', '188.114.96.0/20', '197.234.240.0/22', '198.41.128.0/17', '162.158.0.0/15', '104.16.0.0/13',
        '104.24.0.0/14', '172.64.0.0/13', '131.0.72.0/22',
        '2400:cb00::/32', '2606:4700::/32', '2803:f800::/32', '2405:b500::/32', '2405:8100::/32', '2a06:98c0::/29', '2c0f:f248::/32',
    ];

    /**
     * The request's address: the header's (a valid address) when REMOTE_ADDR is in one of $trustedProxies -
     * and, when the site has an $originSecret, the request carries it in ORIGIN_HEADER -, else REMOTE_ADDR;
     * null when neither is an address. An IPv4 address written as IPv6 is read as the IPv4 address it is
     * (plain()), before it is judged.
     *
     * @param list<string> $trustedProxies  CIDR ranges (or single addresses) of the site's proxies
     * @param string|null $originSecret  the secret the owner's Cloudflare zone adds in ORIGIN_HEADER; null or
     *        '' when the site has none (the header is then believed from the trusted proxies alone, as before)
     */
    public static function of(
        ServerRequestInterface $request,
        array $trustedProxies = [],
        string $header = self::CLOUDFLARE_HEADER,
        #[\SensitiveParameter] ?string $originSecret = null,
    ): ?string {
        $connection = $request->getServerParams()['REMOTE_ADDR'] ?? null;
        $connection = is_string($connection) && filter_var($connection, FILTER_VALIDATE_IP) !== false ? self::plain($connection) : null;
        if ($connection !== null && $header !== '' && self::trusted($connection, $trustedProxies) && self::throughTheOrigin($request, $originSecret)) {
            $named = trim($request->getHeaderLine($header));
            if (filter_var($named, FILTER_VALIDATE_IP) !== false) {
                return self::plain($named);
            }
        }

        return $connection;
    }

    /**
     * Whether the request came through the owner's zone: true when the site has no origin secret; else when
     * ORIGIN_HEADER holds exactly that secret (compared in constant time).
     */
    private static function throughTheOrigin(ServerRequestInterface $request, #[\SensitiveParameter] ?string $originSecret): bool
    {
        if ($originSecret === null || $originSecret === '') {
            return true;
        }
        $sent = $request->getHeaderLine(self::ORIGIN_HEADER);

        return $sent !== '' && hash_equals($originSecret, $sent);
    }

    /**
     * An IPv4 address written as IPv6 - ::ffff:203.0.113.9, as a server listening on [::] for both kinds
     * hands it over (nginx on [::] without ipv6only, PHP's built-in server) - as the IPv4 address it is;
     * any other address as it is. Without it every IPv4 visitor would share the bucket ::/64, and
     * Cloudflare's IPv4 ranges would never match.
     */
    public static function plain(string $address): string
    {
        $packed = @inet_pton($address);
        if ($packed !== false && strlen($packed) === 16 && str_starts_with($packed, str_repeat("\0", 10) . "\xff\xff")) {
            return (string) inet_ntop(substr($packed, 12));
        }

        return $address;
    }

    /** The bucket the limits count an address in: an IPv4 address whole (one written as IPv6 too), an IPv6 address its /64. */
    public static function bucket(string $address): string
    {
        $address = self::plain($address);
        if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false) {
            return $address;
        }
        $packed = @inet_pton($address);
        if ($packed === false || strlen($packed) !== 16) {
            return $address;
        }

        return (string) inet_ntop(substr($packed, 0, 8) . str_repeat("\0", 8)) . '/64';
    }

    /** Whether $address is one of $ranges (CIDR ranges, or single addresses). */
    public static function trusted(string $address, array $ranges): bool
    {
        foreach ($ranges as $range) {
            if (is_string($range) && self::within($address, $range)) {
                return true;
            }
        }

        return false;
    }

    /** Whether $address is inside $range ("173.245.48.0/20", "2400:cb00::/32", or one address). */
    public static function within(string $address, string $range): bool
    {
        [$network, $bits] = array_pad(explode('/', trim($range), 2), 2, null);
        $packedAddress = @inet_pton(self::plain($address));
        $packedNetwork = @inet_pton((string) $network);
        if ($packedAddress === false || $packedNetwork === false || strlen($packedAddress) !== strlen($packedNetwork)) {
            return false;
        }
        $length = strlen($packedAddress) * 8;
        if ($bits === null || $bits === '') {
            $bits = $length;
        } elseif (!ctype_digit($bits) || (int) $bits > $length) {
            return false;
        }
        $bits = (int) $bits;
        $whole = intdiv($bits, 8);
        if (substr($packedAddress, 0, $whole) !== substr($packedNetwork, 0, $whole)) {
            return false;
        }
        $rest = $bits % 8;
        if ($rest === 0) {
            return true;
        }
        $mask = (0xFF << (8 - $rest)) & 0xFF;

        return (ord($packedAddress[$whole]) & $mask) === (ord($packedNetwork[$whole]) & $mask);
    }
}
