<?php

// ---------------------------------------------------------------------------
// config/iam.php - where this IAM is, and how long things last
// ---------------------------------------------------------------------------
// `public_url` is the address people see - the IAM's pages - and it is also the
// OpenID Connect issuer: every token names it as `iss`, and every application
// is configured with it. It must be exactly the URL the discovery document is
// served under, with no trailing slash. On a development machine that is the
// Angular dev server, http://localhost:4300, which proxies /api and
// /.well-known to PHP on 8100; on a real host, the address it is served at.
//
// It comes from config/iam.local.php, which setup.php writes, and from nowhere
// else: an issuer is chosen, never assumed. The same file may override any of
// the tunables below for this installation - returning only the keys that
// differ - and setup.php keeps whatever else it finds there.
// ---------------------------------------------------------------------------

require_once __DIR__ . '/config.php';

function iamConfig(): array
{
    static $tunables = [
        // The session cookie. Secure is decided from public_url unless set: an
        // https installation never sends it in the clear, and a plain-http
        // development server could not set it at all otherwise.
        'cookie_name' => 'anotoki_iam',
        'cookie_secure' => null,

        // A session that was asked to be remembered: signed out after thirty
        // days without use, and after ninety whatever happens. One that was
        // not: a day without use, a week at most - the cookie itself goes when
        // the browser closes.
        'session_remember_idle' => 30 * 86400,
        'session_remember_absolute' => 90 * 86400,
        'session_idle' => 86400,
        'session_absolute' => 7 * 86400,

        // OIDC. Codes are exchanged within seconds by the page that received
        // them; sixty is generous. A sign-in in progress may take a while - a
        // confirmation mail to find, a phone to unlock - so thirty minutes.
        'code_ttl' => 60,
        'request_ttl' => 1800,
        'id_token_ttl' => 3600,
        // Two tabs refreshing the same token at once present it twice within
        // moments. Within this window the second one gets the same successor
        // instead of ending the session.
        'refresh_reuse_leeway' => 60,

        // Personal tokens for scripts: the longest anybody may ask for.
        'personal_token_max_days' => 365,

        // A proxy in front of the IAM - Cloudflare - connects on every visitor's
        // behalf, so without this every limit counts the proxy's few addresses
        // instead of the visitors'. List the proxy's ranges (CIDR, v4 and v6;
        // Cloudflare publishes its own at https://www.cloudflare.com/ips/) and
        // the header it puts the visitor's address in. The header is believed
        // only from those addresses; from anywhere else it is whatever the
        // sender wrote.
        'trusted_proxies' => [],
        'client_ip_header' => 'CF-Connecting-IP',
    ];

    $local = localConfig('iam');
    $config = array_replace($tunables, is_array($local) ? $local : []);

    // Empty until setup.php has written it. Nothing that reads it runs before
    // then: the API's first middleware answers "not set up" instead.
    $config['public_url'] = rtrim(trim((string) ($config['public_url'] ?? '')), '/');

    return $config;
}

/** The OIDC issuer - the IAM's public URL. */
function iamIssuer(): string
{
    return iamConfig()['public_url'];
}

/** scheme://host[:port] of the issuer, for comparing against an Origin header. */
function iamOrigin(): string
{
    $parts = parse_url(iamIssuer());
    $origin = ($parts['scheme'] ?? 'https') . '://' . ($parts['host'] ?? '');

    return isset($parts['port']) ? $origin . ':' . $parts['port'] : $origin;
}

/** A page of the IAM's own frontend, as an absolute URL. */
function iamPageUrl(string $path, array $query = []): string
{
    $query = array_filter($query, static fn($value) => $value !== null && $value !== '');

    return iamIssuer() . $path . ($query ? '?' . http_build_query($query) : '');
}
