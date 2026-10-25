<?php

use Anotoki\Lib\Robots\Robots;
use Anotoki\Lib\SiteSettings\SiteSettings;

/**
 * The site settings, and what search engines read - the anotoki family's
 * (anotoki-lib 0.11.0, the owner's requests of 2026-10-25), on the IAM:
 *
 *   - iamSiteSettings(): the switches the IAM's administrators throw without a
 *     deploy - the ANNOUNCEMENT ALONE (the owner's decision): a bar across
 *     the top of the sign-in pages, the account pages and the legal pages,
 *     information, a warning or something wrong, its words per language. Its
 *     routes: GET /api/site-settings (public, routes/login.php) and
 *     /api/admin/site-settings (the IAM's ADMIN, routes/admin.php).
 *     Never Getting in: a maintenance mode would close the door every site
 *     signs in through - every site's sign-in and refresh, and the admin
 *     panel's own way in. Never Page access: a page of the IAM's switched off,
 *     or kept for some readers, could hide a sign-in page or the account
 *     page where a deletion is asked. The library refuses a switch of a part
 *     a site does not offer (422 part_not_offered), the IAM's SiteState is
 *     given no settings (api/migration_runner.php), so nothing here can ever
 *     say `maintenance`, and no PageAccessGate is mounted.
 *   - iamRobots(): robots.txt and sitemap.xml, answered by the PHP (never a
 *     file of the app's): robots kept out of every sign-in page, the account
 *     pages and the rest below; the sitemap listing the legal pages, About and
 *     Support - nothing else of the IAM's is a page for a search. Their
 *     address is the configured issuer, never a request's Host.
 */

/**
 * The IAM's own API paths that must keep answering whatever a switch says,
 * beside the library's own (the status, the bundles, the settings' two): who
 * the reader is (the account's, /api/account), the sign-in's configuration
 * and the sites' public contracts (/api/public), the sign-in itself
 * (/api/login), and every site's way in (/api/oidc, /.well-known). Only
 * Getting in and Page access read them, neither of which the IAM offers:
 * they are said so that no part offered later could close them.
 */
const IAM_RESERVED_API_PATHS = ['/api/account', '/api/public', '/api/login', '/api/oidc', '/.well-known'];

/**
 * The site settings, the announcement alone. Read afresh by the library for
 * every question, defensively: a switch with no row, or one nothing
 * understands, is off - and while its table waits for its update
 * (anotoki_admin/003, a build uploaded before Apply) there is no announcement
 * and the admin routes answer 503 feature_unavailable.
 */
function iamSiteSettings(): SiteSettings
{
    static $settings = null;

    return $settings ??= new SiteSettings(pdo: static fn(): PDO => iamDb(), parts: [SiteSettings::ANNOUNCEMENT], reservedApiPaths: IAM_RESERVED_API_PATHS);
}

/**
 * What robots stay out of, beside the library's three (/admin, /api, /auth):
 * every sign-in page, the account pages, the not-set-up page and the news'
 * unsubscribe - none is anything a search should find, and some carry a
 * mailed token in their address -, and /.well-known/: the discovery document
 * and the key set are for applications, machine-readable JSON for the sites'
 * SDKs, never a page. robots.txt binds crawlers alone - no OIDC client or SDK
 * reads it - so keeping them out changes nothing for any site.
 */
const IAM_ROBOTS_DISALLOW = ['/login', '/register', '/confirm-email', '/forgot-password', '/reset-password', '/logout', '/error', '/not-set-up', '/account', '/unsubscribe', '/.well-known/'];

/** The pages a search may list: the family's legal pages, About and Support - anybody's, the same on every site. */
const IAM_SITEMAP_PAGES = ['/terms', '/privacy', '/contact', '/about', '/support'];

/**
 * robots.txt and sitemap.xml (the library's Robots; RobotsRoutes in
 * routes/login.php). The address is the issuer - config/iam.local.php's
 * public_url, which only the installer writes - and never one read off a
 * request: a Host header is anybody's to write. Behind the gate like any
 * route (503 until the IAM is set up). The sitemap is kept an hour in
 * storage/cache, and made again at once when the address changes.
 */
function iamRobots(): Robots
{
    static $robots = null;

    return $robots ??= new Robots(
        address: static fn(): ?string => iamIssuer(),
        disallow: IAM_ROBOTS_DISALLOW,
        pages: IAM_SITEMAP_PAGES,
        settings: iamSiteSettings(),
        cacheFile: iamStorageDir() . '/cache/anotoki-sitemap.json',
    );
}
