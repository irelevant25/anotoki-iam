<?php

declare(strict_types=1);

namespace Anotoki\Lib\Auth;

use Anotoki\Lib\Support\Log;
use InvalidArgumentException;

/**
 * Which IAM a site signs in through, and as which application: its issuer and
 * client id, where the site keeps what it reads from the IAM, and the seams
 * the tests put in place of asking it.
 *
 * fromArray() takes the keys of the IAM's PHP SDK (anotoki_iam.php), so every
 * site's config/iam.local.php or auth.local.php stays as it is:
 *
 *   issuer               required  the IAM's address: https - http only on this computer (localhost,
 *                                  127.0.0.1, [::1]), http://localhost:4300 in development. Anything else
 *                                  is refused (InvalidArgumentException): the keys tokens are checked with
 *                                  come from there, and never over plain http across a network
 *   client_id            required  this application's client id
 *   cache_dir            -         where the key set and the IAM's answers are kept - a PRIVATE folder of
 *                                  the site's own (storage/cache), outside the web root, which only the
 *                                  site's PHP user writes: the kept answer about signing in is what opens
 *                                  a site to visitors, and the kept keys are what tokens are checked with.
 *                                  Without one nothing is kept on disk, and the IAM is asked on every
 *                                  request (said once per process in the error log)
 *   jwks_url             -         default {issuer}/.well-known/jwks.json; https too (http on this computer)
 *   jwks_file            -         tests: a key set on disk instead of the IAM's
 *   cache_ttl            3600      seconds the key set is kept
 *   leeway               60        seconds of clock slack when a token's times are checked
 *   http_timeout         5         seconds to wait for the key set
 *   application_ttl      60        seconds the IAM's answer about signing in is kept, and a failure waited out
 *   application_timeout  2         seconds to wait for that answer (asked on a page's way through)
 *   application_file     -         tests: the IAM's answer about signing in, read from this file
 *   anonymized_timeout   3         seconds to wait for each page of the IAM's deleted accounts
 *   anonymized_file      -         tests: the IAM's deleted accounts, read from this file
 *
 * Keys it does not know are passed over: a site's settings file holds its own.
 */
final class IamClient
{
    public readonly string $issuer;
    public readonly string $clientId;

    public function __construct(
        string $issuer,
        string $clientId,
        public readonly ?string $cacheDir = null,
        public readonly ?string $jwksUrl = null,
        public readonly ?string $jwksFile = null,
        public readonly int $cacheTtl = 3600,
        public readonly int $leeway = 60,
        public readonly int $httpTimeout = 5,
        public readonly int $applicationTtl = 60,
        public readonly int $applicationTimeout = 2,
        public readonly ?string $applicationFile = null,
        public readonly int $anonymizedTimeout = 3,
        public readonly ?string $anonymizedFile = null,
    ) {
        // A trailing slash is how an address is often written; the tokens' iss never has one.
        $this->issuer = rtrim(trim($issuer), '/');
        $this->clientId = trim($clientId);

        // No issuer is a site not set up yet (every token refused, signing in required); a wrong one is refused here.
        foreach (['issuer' => $this->issuer === '' ? null : $this->issuer, 'jwks_url' => $jwksUrl] as $name => $address) {
            if ($address !== null && !self::secureAddress($address)) {
                throw new InvalidArgumentException("IamClient: the $name is an https address - http only on this computer (localhost, 127.0.0.1, [::1]) - and \"" . self::shown($address) . '" is not.');
            }
        }

        foreach (['cacheTtl' => $cacheTtl, 'httpTimeout' => $httpTimeout, 'applicationTtl' => $applicationTtl,
            'applicationTimeout' => $applicationTimeout, 'anonymizedTimeout' => $anonymizedTimeout] as $name => $value) {
            if ($value < 1) {
                throw new InvalidArgumentException("IamClient: $name must be at least 1 second.");
            }
        }
        if ($leeway < 0 || $leeway > 600) {
            throw new InvalidArgumentException('IamClient: leeway is between 0 and 600 seconds.');
        }
    }

    /**
     * From the SDK's configuration array (the keys above). Empty strings count
     * as missing; issuer and client_id may be missing too - then every token is
     * refused and signing in is required (not_configured), failing closed.
     *
     * @param array<string, mixed> $config
     */
    public static function fromArray(array $config): self
    {
        $text = static fn (string $key): ?string => isset($config[$key]) && is_string($config[$key]) && trim($config[$key]) !== '' ? trim($config[$key]) : null;
        $int = static fn (string $key, int $default): int => isset($config[$key]) && is_numeric($config[$key]) ? (int) $config[$key] : $default;

        return new self(
            issuer: $text('issuer') ?? '',
            clientId: $text('client_id') ?? '',
            cacheDir: $text('cache_dir'),
            jwksUrl: $text('jwks_url'),
            jwksFile: $text('jwks_file'),
            cacheTtl: max(1, $int('cache_ttl', 3600)),
            leeway: min(600, max(0, $int('leeway', 60))),
            httpTimeout: max(1, $int('http_timeout', 5)),
            applicationTtl: max(1, $int('application_ttl', 60)),
            applicationTimeout: max(1, $int('application_timeout', 2)),
            applicationFile: $text('application_file'),
            anonymizedTimeout: max(1, $int('anonymized_timeout', 3)),
            anonymizedFile: $text('anonymized_file'),
        );
    }

    /**
     * An address the library may ask the IAM at: https with a host, or http to this computer alone - localhost,
     * 127.0.0.0/8, [::1] - as in development. Never another scheme, never plain http across a network: a key
     * set read there could be anybody's.
     */
    public static function secureAddress(string $url): bool
    {
        $parts = parse_url($url);
        if (!is_array($parts) || !is_string($parts['scheme'] ?? null) || !is_string($parts['host'] ?? null) || $parts['host'] === '') {
            return false;
        }
        $scheme = strtolower($parts['scheme']);
        if ($scheme === 'https') {
            return true;
        }
        $host = strtolower(trim($parts['host'], '[]'));

        return $scheme === 'http' && ($host === 'localhost' || $host === '::1' || preg_match('/^127(?:\.\d{1,3}){3}$/D', $host) === 1);
    }

    /** Whether the site names an IAM and itself: without both, nobody is let in and signing in is required. */
    public function configured(): bool
    {
        return $this->issuer !== '' && $this->clientId !== '';
    }

    /** Where the IAM publishes its signing keys. */
    public function keySetUrl(): string
    {
        return $this->jwksUrl ?? $this->issuer . '/.well-known/jwks.json';
    }

    /** The IAM's public answer about this application: whether its site requires signing in. */
    public function applicationUrl(): string
    {
        return $this->issuer . '/api/public/applications/' . rawurlencode($this->clientId);
    }

    /** The IAM's list of the accounts it deleted (anonymized), from $since (inclusive) and after the id $after. */
    public function anonymizedUrl(?string $since = null, ?string $after = null): string
    {
        $query = [];
        if ($since !== null) {
            $query['since'] = $since;
            if ($after !== null) {
                $query['after'] = $after;
            }
        }

        return $this->issuer . '/api/public/anonymized-accounts' . ($query === [] ? '' : '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986));
    }

    /** The IAM's page of one account, in its admin panel: for the IAM's administrators only. */
    public function userAdminUrl(int|string $sub): string
    {
        return $this->issuer . '/admin/users/' . rawurlencode((string) $sub);
    }

    /** The IAM's page where a person keeps their own account. */
    public function accountUrl(): string
    {
        return $this->issuer . '/account';
    }

    /** An address in a refusal: printable, and short. */
    private static function shown(string $address): string
    {
        $shown = (string) preg_replace('/[^\x21-\x7E]/', '?', $address);

        return strlen($shown) > 100 ? substr($shown, 0, 100) . '...' : $shown;
    }

    /**
     * The folder a file of the library's is kept in, made when it is missing -
     * or null: no cache_dir, or one that cannot be made. Said once per process
     * in the error log, as the IAM is then asked on every request.
     */
    public function cacheDirectory(): ?string
    {
        static $said = [];

        $directory = $this->cacheDir === null ? null : rtrim($this->cacheDir, '/\\');
        if ($directory !== null && $directory !== '' && (is_dir($directory) || @mkdir($directory, 0700, true) || is_dir($directory))) {
            return $directory;
        }

        $why = $directory === null || $directory === ''
            ? 'no cache_dir is configured'
            : "the cache_dir $directory cannot be made";
        if (!isset($said[$why])) {
            $said[$why] = true;
            Log::say("[anotoki auth] $why: the IAM's keys and its answer about signing in are asked on every request.");
        }

        return null;
    }
}
