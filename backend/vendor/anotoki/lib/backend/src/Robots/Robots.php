<?php

declare(strict_types=1);

namespace Anotoki\Lib\Robots;

use Anotoki\Lib\SiteSettings\PageRules;
use Anotoki\Lib\SiteSettings\SiteSettings;
use Anotoki\Lib\Support\AtomicFile;
use Anotoki\Lib\Support\GuardedDirectory;
use Closure;
use DateTimeInterface;
use DateTimeZone;
use InvalidArgumentException;

/**
 * What search engines' robots read on every anotoki site (the owner's request of 2026-10-25): robots.txt and
 * sitemap.xml, answered by the site's PHP (RobotsRoutes) - never a static file of the app, which knows neither the
 * site's address nor what its administrators hid.
 *
 * - robots.txt: every robot kept out of the admin panel, the API and the sign-in's way back (/admin, /api, /auth),
 *   and out of what the site adds (the IAM: its sign-in and account pages); a `Sitemap:` line with the sitemap's
 *   absolute address - the site's public address as its installation is configured, never one read off a request
 *   (a Host header is anybody's to write) - and none while no address is configured.
 * - sitemap.xml: the site's public pages and its own dynamic entries (a callback: genshin's database entries, the
 *   build analyzer's characters), as absolute addresses, each once - without what Page access hides (a page for
 *   people signed in, for editors or for administrators, or one switched off: SiteSettings), without what
 *   robots.txt keeps out, and without anything that is no path of the site's. Valid XML (the sitemaps.org
 *   protocol: <urlset>, each <url> its <loc> and, where the site gives one, its <lastmod>), at most 50,000
 *   addresses; kept in a file of the site's storage for an hour - made again at once when what Page access hides,
 *   or the address, changes.
 */
final class Robots
{
    /** What every anotoki site keeps robots out of: its admin panel, its API, the sign-in's way back. */
    public const DISALLOW = ['/admin', '/api', '/auth'];

    /** The sitemaps protocol's most addresses in one file, and the longest address it takes. */
    public const SITEMAP_MAX = 50000;
    public const URL_MAX = 2048;

    public const CACHE_SECONDS = 3600;

    /** Changes when what a kept sitemap holds is made otherwise: an older one is made again. */
    private const SHAPE = 'anotoki-sitemap/1';

    /** @var list<string> */
    private readonly array $disallow;

    /**
     * @param Closure(): ?string $address  the site's public address as its installation is configured
     *        ('https://genshin.anotoki.eu'); null - or anything that is no http(s) address - while none is
     * @param list<string> $disallow  more paths robots stay out of (the IAM's sign-in and account pages)
     * @param list<string|array{path: string, lastmod?: mixed}> $pages  the site's public pages: '/', '/daily', ...
     * @param null|Closure(): iterable<string|array{path: string, lastmod?: mixed}> $entries  the site's own entries,
     *        made when the sitemap is: each a path ('/database/characters/12'), or {path, lastmod} - lastmod a date
     *        ('2026-10-24', an ISO 8601 time or a DateTimeInterface). A name in a path may be given as it reads
     *        ('/characters/Hu Tao'): every segment is percent-encoded as an address needs it
     * @param ?SiteSettings $settings  Page access: what it hides stays out of the sitemap
     * @param ?string $cacheFile  where the sitemap is kept between requests (storage/cache/anotoki-sitemap.json); none: made every time
     * @param string $sitemapPath  where RobotsRoutes::sitemap() answers, for robots.txt's line
     */
    public function __construct(
        private readonly Closure $address,
        array $disallow = [],
        private readonly array $pages = ['/'],
        private readonly ?Closure $entries = null,
        private readonly ?SiteSettings $settings = null,
        private readonly ?string $cacheFile = null,
        private readonly int $cacheSeconds = self::CACHE_SECONDS,
        private readonly string $sitemapPath = '/sitemap.xml',
    ) {
        $rules = [];
        foreach ([...self::DISALLOW, ...$disallow] as $path) {
            if (!is_string($path) || preg_match('~^/[^\s#\x00-\x1F\x7F]*$~D', $path) !== 1) {
                throw new InvalidArgumentException('Robots: a path to keep robots out of begins with / and holds no space - "' . (is_string($path) ? $path : gettype($path)) . '" is not one.');
            }
            $rules[$path] = true;
        }
        $this->disallow = array_keys($rules);
        if (!str_starts_with($sitemapPath, '/')) {
            throw new InvalidArgumentException('Robots: the sitemap\'s path begins with /.');
        }
    }

    /** The site's public address - its origin, 'https://genshin.anotoki.eu' - or null while none is configured. */
    public function address(): ?string
    {
        $address = ($this->address)();
        if (!is_string($address)) {
            return null;
        }
        $parts = parse_url(trim($address));
        if (
            !is_array($parts) ||
            !in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true) ||
            preg_match('/^[A-Za-z0-9.-]+$/D', $parts['host'] ?? '') !== 1 ||
            isset($parts['user']) ||
            isset($parts['pass']) ||
            isset($parts['query']) ||
            isset($parts['fragment']) ||
            rtrim($parts['path'] ?? '', '/') !== ''
        ) {
            return null;
        }

        return strtolower($parts['scheme']) . '://' . strtolower($parts['host']) . (isset($parts['port']) ? ':' . $parts['port'] : '');
    }

    /** robots.txt, whole. */
    public function robotsTxt(): string
    {
        $lines = ['# The robots of every search engine: what to leave alone, and where the site\'s pages are listed.', 'User-agent: *'];
        foreach ($this->disallow as $path) {
            $lines[] = 'Disallow: ' . $path;
        }
        $address = $this->address();
        if ($address !== null) {
            $lines[] = '';
            $lines[] = 'Sitemap: ' . $address . $this->sitemapPath;
        }

        return implode("\n", $lines) . "\n";
    }

    /** sitemap.xml, whole - or null while no address is configured (an address in a sitemap is absolute). */
    public function sitemapXml(): ?string
    {
        $address = $this->address();
        if ($address === null) {
            return null;
        }
        $hidden = $this->hidden();
        $key = hash('sha256', (string) json_encode([self::SHAPE, $address, $hidden, $this->disallow]));
        $kept = $this->kept($key);
        if ($kept !== null) {
            return $kept;
        }

        $xml = $this->made($address, $hidden);
        $this->keep($key, $xml);

        return $xml;
    }

    /** The paths of the rows Page access hides by: not anybody's, or switched off. */
    private function hidden(): array
    {
        if ($this->settings === null || !$this->settings->offers(SiteSettings::PAGE_ACCESS)) {
            return [];
        }
        $hidden = [];
        foreach ($this->settings->governing() as $row) {
            if ($row['blocked'] || $row['visibility'] !== 'PUBLIC') {
                $hidden[] = $row['path'];
            }
        }
        sort($hidden, SORT_STRING);

        return $hidden;
    }

    /** @param list<string> $hidden */
    private function made(string $address, array $hidden): string
    {
        $listed = [];
        $items = $this->pages;
        if ($this->entries !== null) {
            foreach (($this->entries)() as $entry) {
                $items[] = $entry;
            }
        }
        foreach ($items as $item) {
            if (count($listed) >= self::SITEMAP_MAX) {
                break;
            }
            $entry = $this->entry($address, $item, $hidden);
            if ($entry !== null && !isset($listed[$entry['loc']])) {
                $listed[$entry['loc']] = $entry['lastmod'];
            }
        }

        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
        foreach ($listed as $loc => $lastmod) {
            $xml .= '  <url><loc>' . htmlspecialchars((string) $loc, ENT_XML1 | ENT_QUOTES, 'UTF-8') . '</loc>' . ($lastmod === null ? '' : '<lastmod>' . $lastmod . '</lastmod>') . "</url>\n";
        }

        return $xml . "</urlset>\n";
    }

    /**
     * One page as the sitemap lists it - {loc, lastmod} - or null when it is no path of the site's, robots.txt keeps
     * it out, or Page access hides it.
     *
     * @param list<string> $hidden
     * @return ?array{loc: string, lastmod: ?string}
     */
    private function entry(string $address, mixed $item, array $hidden): ?array
    {
        $path = is_array($item) ? $item['path'] ?? null : $item;
        if (!is_string($path) || !str_starts_with($path, '/') || str_starts_with($path, '//') || preg_match('/[\\\\\x00-\x1F\x7F]/', $path) === 1) {
            return null;
        }
        $segments = PageRules::segments($path);
        foreach ($segments as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                return null;
            }
        }
        $decoded = array_map('rawurldecode', $segments);
        $plain = '/' . implode('/', $decoded);
        foreach ($decoded as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..' || str_contains($segment, '/') || preg_match('/[\x00-\x1F\x7F]/', $segment) === 1) {
                return null;
            }
        }
        foreach ($this->disallow as $rule) {
            if (str_starts_with($plain, $rule)) {
                return null;
            }
        }
        foreach ($hidden as $pattern) {
            if (PageRules::matches($pattern, $plain)) {
                return null;
            }
        }
        $loc = $address . '/' . implode('/', array_map('rawurlencode', $decoded));
        if (strlen($loc) > self::URL_MAX) {
            return null;
        }

        return ['loc' => $loc, 'lastmod' => is_array($item) ? self::lastmod($item['lastmod'] ?? null) : null];
    }

    /** A W3C date or time as the protocol takes it, or null. */
    private static function lastmod(mixed $value): ?string
    {
        if ($value instanceof DateTimeInterface) {
            return $value->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d');
        }
        if (is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}(T\d{2}:\d{2}(:\d{2}(\.\d{1,6})?)?(Z|[+-]\d{2}:\d{2}))?$/D', $value) === 1) {
            return $value;
        }

        return null;
    }

    /** The sitemap kept under this key, while it is fresh; null otherwise. */
    private function kept(string $key): ?string
    {
        if ($this->cacheFile === null || !is_file($this->cacheFile)) {
            return null;
        }
        $kept = json_decode((string) @file_get_contents($this->cacheFile), true);
        if (!is_array($kept) || ($kept['key'] ?? null) !== $key || !is_int($kept['at'] ?? null) || !is_string($kept['xml'] ?? null)) {
            return null;
        }
        $age = time() - $kept['at'];

        return $age >= 0 && $age < $this->cacheSeconds ? $kept['xml'] : null;
    }

    private function keep(string $key, string $xml): void
    {
        if ($this->cacheFile === null || !GuardedDirectory::ensure(dirname($this->cacheFile))) {
            return;
        }
        AtomicFile::write($this->cacheFile, (string) json_encode(['key' => $key, 'at' => time(), 'xml' => $xml], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), mode: 0600);
    }
}
