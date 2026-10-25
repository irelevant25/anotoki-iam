<?php

declare(strict_types=1);

namespace Anotoki\Lib\SiteSettings;

use Anotoki\Lib\Admin\Schema;
use Anotoki\Lib\Audit\AuditLog;
use Anotoki\Lib\Auth\Person;
use Anotoki\Lib\Support\Refusal;
use Anotoki\Lib\Support\Utf8;
use Anotoki\Lib\Translations\LanguageCode;
use Closure;
use InvalidArgumentException;
use PDO;
use PDOException;
use Throwable;

/**
 * Site settings - the switches an administrator throws without a deploy (the genshin site's, for every anotoki
 * site; the owner's request of 2026-10-25), in the library's tables (Admin\Schema, anotoki_admin 003). A site
 * offers the parts it chooses:
 *
 * - **Getting in** (`getting_in`): maintenance mode and the maintenance page's message per language. While it is
 *   on, the site's SiteGate answers everybody but its ADMIN 503 `maintenance` - but for the paths open while an
 *   update waits and the reserved API paths below - and the status path says `maintenance` (SiteState).
 * - **Announcement** (`announcement`): a bar on every page - information, a warning or something wrong - with a
 *   message per language.
 * - **Page access** (`page_access`): a row per page path - who sees it, whether it is switched off for everybody
 *   but the administrators, and the API paths refused with it (PageAccessGate).
 *
 * The IAM offers the announcement alone: maintenance there would stop every site's sign-in.
 *
 * The reserved paths stay out of everybody's reach, whatever a row or a switch says - and the site's ADMIN is never
 * refused anything (no lockout):
 * - API paths (each itself and everything under it): the site status, the translations' bundles, these settings'
 *   two (public and admin), and the site's own - who the reader is (/api/me), its sign-in configuration
 *   (/api/auth/config). Open while the site is closed for maintenance; never claimed by a page, checked both ways
 *   (PageRules::touches()).
 * - Pages: the sign-in's way back (/auth), the administrators' way in while the site is closed (/staff), the admin
 *   panel (/admin), the legal pages, About and Support - and the site's own. Never governed by a row: a row that
 *   would match one, or a page under one, is refused (PageRules::claimsPage()), and one put in by hand is passed
 *   over.
 *
 * Read afresh for every question (one PHP process may serve many requests), defensively: a switch with no row, or
 * a value nothing understands, is its default - off, information, nothing written - never a site that will not
 * start. While the tables wait for their update (a build uploaded before its migrations), every switch is at its
 * default and no page is governed; the admin routes answer 503 feature_unavailable.
 */
final class SiteSettings
{
    public const GETTING_IN = 'getting_in';
    public const ANNOUNCEMENT = 'announcement';
    public const PAGE_ACCESS = 'page_access';
    /** @var list<string> */
    public const PARTS = [self::GETTING_IN, self::ANNOUNCEMENT, self::PAGE_ACCESS];

    public const PUBLIC_PATH = '/api/site-settings';
    public const ADMIN_PATH = '/api/admin/site-settings';

    /** Each switch's part. */
    public const SWITCHES = [
        'maintenance_mode' => self::GETTING_IN,
        'maintenance_message' => self::GETTING_IN,
        'announcement_enabled' => self::ANNOUNCEMENT,
        'announcement_level' => self::ANNOUNCEMENT,
        'announcement_message' => self::ANNOUNCEMENT,
    ];

    /** Each switch's value while it has no row (or one nothing understands). */
    public const DEFAULTS = [
        'maintenance_mode' => false,
        'maintenance_message' => [],
        'announcement_enabled' => false,
        'announcement_level' => 'info',
        'announcement_message' => [],
    ];

    /** The announcement's levels: "know this", "watch out", "something is wrong". */
    public const LEVELS = ['info', 'warning', 'danger'];

    /** The pages no row may govern, each itself and everything under it - the site adds its own. */
    public const RESERVED_PAGES = ['/auth', '/staff', '/admin', '/terms', '/privacy', '/contact', '/about', '/support'];

    /** The most characters a message holds in one language, and the most languages it holds. */
    public const MESSAGE_MAX = 2000;
    public const MESSAGE_LANGUAGES_MAX = 30;
    /** The most API paths one page refuses with it (the table's check says so too), and the most pages. */
    public const API_PATHS_MAX = 20;
    public const PAGES_MAX = 500;

    /** @var list<string> */
    private readonly array $parts;
    /** @var list<string> */
    private readonly array $reservedApiPaths;
    /** @var list<string> */
    private readonly array $reservedPages;

    /**
     * @param Closure(): PDO $pdo  the site's connection, asked when a question needs it (a site not set up has none);
     *        PDO::ERRMODE_EXCEPTION
     * @param list<string> $parts  what the site offers: getting_in, announcement, page_access (the IAM: announcement)
     * @param list<string> $reservedApiPaths  the site's own API paths that must keep answering: who the reader is
     *        (/api/me), its sign-in configuration (AuthRoutes::openPath()) - each itself and everything under it
     * @param list<string> $reservedPages  the site's own pages no row may govern (a callback elsewhere than /auth)
     * @param string $publicPath  where SiteSettingsRoutes::public() answers
     * @param string $adminPath  where SiteSettingsRoutes::admin() mounts its group
     * @param string $statusPath  the site's status path (MigrationsRoutes::status())
     * @param string $translationsPath  the translations' bundles' base (TranslationsRoutes)
     */
    public function __construct(
        private readonly Closure $pdo,
        array $parts = self::PARTS,
        array $reservedApiPaths = [],
        array $reservedPages = [],
        public readonly string $publicPath = self::PUBLIC_PATH,
        public readonly string $adminPath = self::ADMIN_PATH,
        string $statusPath = '/api/site-status',
        string $translationsPath = '/api/translations',
    ) {
        $offered = [];
        foreach ($parts as $part) {
            if (!is_string($part) || !in_array($part, self::PARTS, true)) {
                throw new InvalidArgumentException('SiteSettings: a part is one of ' . implode(', ', self::PARTS) . '.');
            }
            $offered[$part] = true;
        }
        // In the library's order, whatever the site's: the admin page draws its cards so.
        $this->parts = array_values(array_filter(self::PARTS, static fn(string $part): bool => isset($offered[$part])));

        $api = [];
        foreach ([$statusPath, $translationsPath, $publicPath, $adminPath, ...$reservedApiPaths] as $path) {
            if (!is_string($path) || !str_starts_with($path, '/') || str_starts_with($path, '//') || PageRules::normalise($path) === '/' || preg_match('/[\s?#\\\\]/', $path) === 1) {
                throw new InvalidArgumentException('SiteSettings: a reserved API path is a path below the root, such as /api/me - "' . (is_string($path) ? $path : gettype($path)) . '" is not one.');
            }
            $api[PageRules::normalise($path)] = true;
        }
        $this->reservedApiPaths = array_keys($api);

        $pages = [];
        foreach ([...self::RESERVED_PAGES, ...$reservedPages] as $page) {
            if (!is_string($page) || PageRules::pageProblem(PageRules::normalise($page)) !== null || PageRules::normalise($page) === '/') {
                throw new InvalidArgumentException('SiteSettings: a reserved page is a page path below the root, such as /staff - "' . (is_string($page) ? $page : gettype($page)) . '" is not one.');
            }
            $pages[PageRules::normalise($page)] = true;
        }
        $this->reservedPages = array_keys($pages);
    }

    // ─── What the site offers ───────────────────────────────────────────────

    /** @return list<string> the parts the site offers, in the library's order */
    public function parts(): array
    {
        return $this->parts;
    }

    public function offers(string $part): bool
    {
        return in_array($part, $this->parts, true);
    }

    /** @return list<string> the API paths that keep answering, each itself and everything under it */
    public function reservedApiPaths(): array
    {
        return $this->reservedApiPaths;
    }

    /** @return list<string> the pages no row governs, each itself and everything under it */
    public function reservedPages(): array
    {
        return $this->reservedPages;
    }

    /** Whether a request's path (decoded, as the router reads it) is a reserved API path, or under one. */
    public function isReservedApiPath(string $path): bool
    {
        foreach ($this->reservedApiPaths as $reserved) {
            if (PageRules::within($path, $reserved)) {
                return true;
            }
        }

        return false;
    }

    /** Whether the tables are there (asked afresh: an update may be applied between two requests); false when it cannot be asked. */
    public function ready(): bool
    {
        try {
            $pdo = ($this->pdo)();

            return Schema::has($pdo, Schema::SITE_SETTINGS_TABLE) && Schema::has($pdo, Schema::PAGE_ACCESS_TABLE);
        } catch (Throwable) {
            return false;
        }
    }

    public static function unavailable(): Refusal
    {
        return new Refusal(503, 'feature_unavailable', 'The site settings are not there yet: a database update waits. Apply it, then try again.');
    }

    // ─── Getting in and the announcement ────────────────────────────────────

    /**
     * Every switch of the parts the site offers: its value - its row's, or its default - and when it last changed
     * and by whom (null for a switch nobody has thrown).
     *
     * @return array<string, array{value: mixed, updated_at: ?string, updated_by: int|null}>
     */
    public function switches(): array
    {
        $out = [];
        foreach (self::SWITCHES as $name => $part) {
            if ($this->offers($part)) {
                $out[$name] = ['value' => self::DEFAULTS[$name], 'updated_at' => null, 'updated_by' => null];
            }
        }
        if ($out === []) {
            return [];
        }
        $pdo = ($this->pdo)();
        if (!Schema::has($pdo, Schema::SITE_SETTINGS_TABLE)) {
            return $out;
        }
        $rows = $pdo->query('SELECT name, value::text AS value, ' . AuditLog::isoColumn('updated_at') . ' AS updated_at, updated_by FROM ' . Schema::SITE_SETTINGS_TABLE)->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $row) {
            $name = (string) $row['name'];
            if (!isset($out[$name])) {
                continue;
            }
            $out[$name] = [
                'value' => self::readBack($name, json_decode((string) $row['value'], true)),
                'updated_at' => $row['updated_at'] === null ? null : (string) $row['updated_at'],
                'updated_by' => $row['updated_by'] === null ? null : (int) $row['updated_by'],
            ];
        }

        return $out;
    }

    /**
     * Null while the site is open; while it is closed for maintenance, the message its administrators wrote, per
     * language - empty when they wrote none (the page then says the library's built-in sentence). Null too for a
     * site that does not offer getting in, and while the table waits for its update.
     *
     * @return ?array<string, string>
     */
    public function maintenance(): ?array
    {
        if (!$this->offers(self::GETTING_IN)) {
            return null;
        }
        $switches = $this->switches();

        return ($switches['maintenance_mode']['value'] ?? false) === true ? $switches['maintenance_message']['value'] : null;
    }

    /**
     * The bar on every page, or null when there is nothing to say: switched off, nothing written, or not offered.
     *
     * @return ?array{level: string, message: array<string, string>}
     */
    public function announcement(): ?array
    {
        if (!$this->offers(self::ANNOUNCEMENT)) {
            return null;
        }
        $switches = $this->switches();
        if (($switches['announcement_enabled']['value'] ?? false) !== true || $switches['announcement_message']['value'] === []) {
            return null;
        }

        return ['level' => $switches['announcement_level']['value'], 'message' => $switches['announcement_message']['value']];
    }

    /**
     * Saves the switches that changed - all checked first, then written together: half a saved form is a
     * combination nobody chose ("maintenance on, its message refused"). A switch sent unchanged is not written, so
     * when it last changed stays true. Returns what changed, each {old, new}, for the audit log.
     *
     * @param array<string, mixed> $values  switch => value: true or false, a level's name, {language: text}
     * @return array<string, array{old: mixed, new: mixed}>|Refusal
     */
    public function save(array $values, int|string|null $actor): array|Refusal
    {
        if ($values === []) {
            return [];
        }
        $wanted = [];
        foreach ($values as $name => $value) {
            $name = (string) $name;
            if (!array_key_exists($name, self::SWITCHES)) {
                return Refusal::invalid('unknown_setting', "There is no setting called '$name'.", ['setting' => $name]);
            }
            if (!$this->offers(self::SWITCHES[$name])) {
                return Refusal::invalid('part_not_offered', "This site does not offer '$name'.", ['setting' => $name]);
            }
            $clean = self::checked($name, $value);
            if ($clean instanceof Refusal) {
                return $clean;
            }
            $wanted[$name] = $clean;
        }

        if (!$this->ready()) {
            return self::unavailable();
        }
        $before = $this->switches();
        $changed = [];
        foreach ($wanted as $name => $value) {
            if ($value !== $before[$name]['value']) {
                $changed[$name] = ['old' => $before[$name]['value'], 'new' => $value];
            }
        }
        if ($changed === []) {
            return [];
        }

        $pdo = ($this->pdo)();
        $pdo->beginTransaction();
        try {
            $write = $pdo->prepare(
                'INSERT INTO ' .
                    Schema::SITE_SETTINGS_TABLE .
                    ' (name, value, updated_at, updated_by) VALUES (?, ?::jsonb, CURRENT_TIMESTAMP, ?)
                 ON CONFLICT (name) DO UPDATE SET value = EXCLUDED.value, updated_at = EXCLUDED.updated_at, updated_by = EXCLUDED.updated_by',
            );
            foreach ($changed as $name => $change) {
                $write->execute([$name, self::json($name, $change['new']), self::person($actor)]);
            }
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        return $changed;
    }

    // ─── Page access ────────────────────────────────────────────────────────

    /**
     * Every row, in the order the administrators see them, with the API paths each refuses. Empty while the table
     * waits for its update, and for a site that does not offer page access.
     *
     * @return list<array{id: int, path: string, visibility: string, blocked: bool, api_paths: list<string>, sort_order: int, updated_at: ?string, updated_by: int|null}>
     */
    public function pages(): array
    {
        if (!$this->offers(self::PAGE_ACCESS)) {
            return [];
        }
        $pdo = ($this->pdo)();
        if (!Schema::has($pdo, Schema::PAGE_ACCESS_TABLE)) {
            return [];
        }
        $rows = $pdo
            ->query(
                'SELECT id, path, visibility, blocked, array_to_json(api_paths)::text AS api_paths, sort_order, ' .
                    AuditLog::isoColumn('updated_at') .
                    ' AS updated_at, updated_by FROM ' .
                    Schema::PAGE_ACCESS_TABLE .
                    ' ORDER BY sort_order, path COLLATE "C"',
            )
            ->fetchAll(PDO::FETCH_ASSOC);

        return array_map(static function (array $row): array {
            $paths = json_decode((string) $row['api_paths'], true);

            return [
                'id' => (int) $row['id'],
                'path' => (string) $row['path'],
                'visibility' => (string) $row['visibility'],
                'blocked' => $row['blocked'] === true || $row['blocked'] === 't' || $row['blocked'] === 1 || $row['blocked'] === '1',
                'api_paths' => is_array($paths) ? array_values(array_filter($paths, 'is_string')) : [],
                'sort_order' => (int) $row['sort_order'],
                'updated_at' => $row['updated_at'] === null ? null : (string) $row['updated_at'],
                'updated_by' => $row['updated_by'] === null ? null : (int) $row['updated_by'],
            ];
        }, $rows);
    }

    /**
     * The rows that govern something: every row but one a rule refuses - a reserved page, a path that is none -
     * which an administrator can only have put in by hand, and which is passed over.
     *
     * @return list<array{id: int, path: string, visibility: string, blocked: bool, api_paths: list<string>, sort_order: int, updated_at: ?string, updated_by: int|null}>
     */
    public function governing(): array
    {
        return array_values(array_filter($this->pages(), fn(array $row): bool => $this->pageRefusal($row['path']) === null));
    }

    /** The row that governs a page, or null: a page no row governs is anybody's. */
    public function pageFor(string $path): ?array
    {
        foreach ($this->governing() as $row) {
            if (PageRules::matches($row['path'], $path)) {
                return $row;
            }
        }

        return null;
    }

    /**
     * Whether page access refuses this API path (decoded, as the router reads it) to this reader. A path is refused
     * only when a row claims it and every row that claims it refuses the reader: where two pages claim one path
     * (/daily and /quizzes on /api/quiz), the more open one wins - switching one page to members only must not take
     * an endpoint from another that is still anybody's (genshin's rule). Never for the site's ADMIN, never a
     * reserved path.
     */
    public function refusesApi(string $path, ?Person $reader): bool
    {
        if (!$this->offers(self::PAGE_ACCESS) || ($reader !== null && $reader->isAdmin()) || $this->isReservedApiPath($path)) {
            return false;
        }
        $claimed = false;
        foreach ($this->governing() as $row) {
            foreach ($row['api_paths'] as $prefix) {
                if ($this->apiPathRefusal($prefix) !== null || !str_starts_with($path, $prefix)) {
                    continue;
                }
                $claimed = true;
                if (PageRules::allows($row, $reader)) {
                    return false;
                }
            }
        }

        return $claimed;
    }

    /** Why a page cannot be governed by a row, or null when it can: a path the router could declare, and no reserved page. */
    public function pageRefusal(string $path): ?string
    {
        $problem = PageRules::pageProblem($path);
        if ($problem !== null) {
            return $problem;
        }
        foreach ($this->reservedPages as $reserved) {
            if (PageRules::claimsPage($path, $reserved)) {
                return "'$path' would govern $reserved, which stays everybody's: the way in, the way back from the sign-in, the admin panel and the legal pages are never switched off or hidden.";
            }
        }

        return null;
    }

    /** Why an API path cannot be refused with a page, or null when it can: an API path, touching no reserved one either way. */
    public function apiPathRefusal(string $prefix): ?string
    {
        $problem = PageRules::apiProblem($prefix);
        if ($problem !== null) {
            return $problem;
        }
        foreach ($this->reservedApiPaths as $reserved) {
            if (PageRules::touches($prefix, $reserved)) {
                return "'$prefix' touches $reserved, which has to keep answering: who the reader is, how they sign in, the site's status, its words and these settings.";
            }
        }

        return null;
    }

    /**
     * Governs a page no row governs yet. Its visibility is ADMIN unless said - the safe answer for a page nobody has
     * thought about. Returns the row.
     *
     * @param list<mixed> $apiPaths
     * @return array<string, mixed>|Refusal
     */
    public function addPage(string $path, string $visibility, bool $blocked, array $apiPaths, int|string|null $actor): array|Refusal
    {
        if (!$this->offers(self::PAGE_ACCESS)) {
            return Refusal::invalid('part_not_offered', 'This site does not offer page access.');
        }
        $path = PageRules::normalise($path);
        $refusal = $this->pageRefusal($path);
        if ($refusal !== null) {
            return Refusal::invalid('invalid_page', $refusal, ['path' => $path]);
        }
        if (!in_array($visibility, PageRules::VISIBILITIES, true)) {
            return Refusal::invalid('invalid_visibility', "'$visibility' is not one of " . implode(', ', PageRules::VISIBILITIES) . '.');
        }
        $paths = $this->checkedApiPaths($apiPaths);
        if ($paths instanceof Refusal) {
            return $paths;
        }
        if (!$this->ready()) {
            return self::unavailable();
        }

        $pdo = ($this->pdo)();
        if ((int) $pdo->query('SELECT count(*) FROM ' . Schema::PAGE_ACCESS_TABLE)->fetchColumn() >= self::PAGES_MAX) {
            return Refusal::invalid('too_many_pages', 'The table governs ' . self::PAGES_MAX . ' pages already.');
        }
        try {
            $insert = $pdo->prepare(
                'INSERT INTO ' .
                    Schema::PAGE_ACCESS_TABLE .
                    ' (path, visibility, blocked, api_paths, sort_order, updated_at, updated_by)
                 VALUES (?, ?, ?, ?::text[], (SELECT COALESCE(MAX(sort_order), 0) + 10 FROM ' .
                    Schema::PAGE_ACCESS_TABLE .
                    '), CURRENT_TIMESTAMP, ?) RETURNING id',
            );
            $insert->execute([$path, $visibility, $blocked ? 'true' : 'false', self::textArray($paths), self::person($actor)]);
            $id = (int) $insert->fetchColumn();
        } catch (PDOException $e) {
            if ($e->getCode() === '23505') {
                return new Refusal(409, 'page_exists', "'$path' is governed already.", ['path' => $path]);
            }
            throw $e;
        }

        foreach ($this->pages() as $row) {
            if ($row['id'] === $id) {
                return $row;
            }
        }

        return ['id' => $id, 'path' => $path];
    }

    /**
     * Saves the rows that changed - every change checked before any is written: a page and its API paths are one
     * decision, and a window where the page is locked and its data is not is a combination nobody chose. A row sent
     * unchanged is not written. Returns each changed row's {path, field: {old, new}} for the audit log, by id.
     *
     * @param list<mixed> $changes  each {id, visibility?, blocked?, api_paths?}
     * @return array<int, array<string, mixed>>|Refusal
     */
    public function savePages(array $changes, int|string|null $actor): array|Refusal
    {
        if (!$this->offers(self::PAGE_ACCESS)) {
            return Refusal::invalid('part_not_offered', 'This site does not offer page access.');
        }
        if (!$this->ready()) {
            return self::unavailable();
        }
        $known = [];
        foreach ($this->pages() as $row) {
            $known[$row['id']] = $row;
        }

        $wanted = [];
        foreach ($changes as $change) {
            if (!is_array($change)) {
                return Refusal::invalid('invalid_body', 'Send pages as a list of {id, visibility, blocked, api_paths}.');
            }
            $id = $change['id'] ?? null;
            if (!is_int($id) || !isset($known[$id])) {
                return Refusal::invalid('unknown_page', 'There is no such page in the table: read it again.', ['id' => is_int($id) ? $id : null]);
            }
            $row = $known[$id];
            if ($this->pageRefusal($row['path']) !== null) {
                return Refusal::invalid('invalid_page', $this->pageRefusal($row['path']) . ' Remove the row instead.', ['path' => $row['path']]);
            }
            $visibility = $change['visibility'] ?? $row['visibility'];
            if (!is_string($visibility) || !in_array($visibility, PageRules::VISIBILITIES, true)) {
                return Refusal::invalid('invalid_visibility', 'A page is shown to one of ' . implode(', ', PageRules::VISIBILITIES) . '.', ['path' => $row['path']]);
            }
            $blocked = $change['blocked'] ?? $row['blocked'];
            if (!is_bool($blocked)) {
                return Refusal::invalid('invalid_blocked', 'Whether a page is switched off is true or false.', ['path' => $row['path']]);
            }
            $paths = array_key_exists('api_paths', $change) ? $change['api_paths'] : $row['api_paths'];
            $paths = is_array($paths) ? $this->checkedApiPaths($paths) : Refusal::invalid('invalid_api_paths', 'A page\'s API paths are a list.');
            if ($paths instanceof Refusal) {
                return new Refusal($paths->status, $paths->code, $paths->message, $paths->extra + ['path' => $row['path']]);
            }
            $fields = [];
            if ($visibility !== $row['visibility']) {
                $fields['visibility'] = ['old' => $row['visibility'], 'new' => $visibility];
            }
            if ($blocked !== $row['blocked']) {
                $fields['blocked'] = ['old' => $row['blocked'], 'new' => $blocked];
            }
            if ($paths !== $row['api_paths']) {
                $fields['api_paths'] = ['old' => $row['api_paths'], 'new' => $paths];
            }
            if ($fields !== []) {
                $wanted[$id] = ['visibility' => $visibility, 'blocked' => $blocked, 'api_paths' => $paths, 'fields' => $fields];
            }
        }
        if ($wanted === []) {
            return [];
        }

        $pdo = ($this->pdo)();
        $pdo->beginTransaction();
        try {
            $update = $pdo->prepare('UPDATE ' . Schema::PAGE_ACCESS_TABLE . ' SET visibility = ?, blocked = ?, api_paths = ?::text[], updated_at = CURRENT_TIMESTAMP, updated_by = ? WHERE id = ?');
            foreach ($wanted as $id => $row) {
                $update->execute([$row['visibility'], $row['blocked'] ? 'true' : 'false', self::textArray($row['api_paths']), self::person($actor), $id]);
            }
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        $out = [];
        foreach ($wanted as $id => $row) {
            $out[$id] = ['path' => $known[$id]['path']] + $row['fields'];
        }

        return $out;
    }

    /**
     * Stops governing a page: a page with no row is anybody's again - the state every page was in before the table.
     * Returns the row it was, or null when there was none.
     *
     * @return ?array<string, mixed>
     */
    public function deletePage(int $id): ?array
    {
        if (!$this->offers(self::PAGE_ACCESS) || !$this->ready()) {
            return null;
        }
        $row = null;
        foreach ($this->pages() as $page) {
            if ($page['id'] === $id) {
                $row = $page;
            }
        }
        if ($row === null) {
            return null;
        }
        $delete = ($this->pdo)()->prepare('DELETE FROM ' . Schema::PAGE_ACCESS_TABLE . ' WHERE id = ?');
        $delete->execute([$id]);

        return $delete->rowCount() > 0 ? $row : null;
    }

    // ─── What the pages are told ────────────────────────────────────────────

    /**
     * What every page is told, before anybody signs in: whether the site is closed for maintenance (for the notice an
     * administrator reads above each page - the gate reads the site's status, GET /api/site-status, never this), the
     * announcement (or null), and every page a row governs - its path, who sees it, whether it is switched off; never
     * its API paths, which are the gate's business and a map of the site nobody browsing it needs. Each part only
     * where the site offers it.
     *
     * @return array<string, mixed>
     */
    public function publicBody(): array
    {
        $body = [];
        if ($this->offers(self::GETTING_IN)) {
            $body['maintenance'] = $this->maintenance() !== null;
        }
        if ($this->offers(self::ANNOUNCEMENT)) {
            $announcement = $this->announcement();
            $body['announcement'] = $announcement === null ? null : ['level' => $announcement['level'], 'message' => (object) $announcement['message']];
        }
        if ($this->offers(self::PAGE_ACCESS)) {
            $body['pages'] = array_map(static fn(array $row): array => ['path' => $row['path'], 'visibility' => $row['visibility'], 'blocked' => $row['blocked']], $this->governing());
        }

        return $body;
    }

    // ─── Inside ─────────────────────────────────────────────────────────────

    /** A value read from the table, as the switch takes it - its default when it is anything else. */
    private static function readBack(string $name, mixed $value): mixed
    {
        $checked = self::checked($name, $value, true);

        return $checked instanceof Refusal ? self::DEFAULTS[$name] : $checked;
    }

    /**
     * A value as the switch takes it, or why not. A message keeps the languages with words in them, each trimmed,
     * its line breaks as \n, sorted by language (English first); a message read back is cut to its limits rather
     * than thrown away.
     */
    private static function checked(string $name, mixed $value, bool $readingBack = false): mixed
    {
        switch ($name) {
            case 'maintenance_mode':
            case 'announcement_enabled':
                return is_bool($value) ? $value : Refusal::invalid('invalid_setting', "$name is a switch: true or false.", ['setting' => $name]);
            case 'announcement_level':
                return is_string($value) && in_array($value, self::LEVELS, true)
                    ? $value
                    : Refusal::invalid('invalid_setting', "$name is one of " . implode(', ', self::LEVELS) . '.', ['setting' => $name]);
            default:
                return self::message($name, $value, $readingBack);
        }
    }

    /** @return array<string, string>|Refusal */
    private static function message(string $name, mixed $value, bool $readingBack): array|Refusal
    {
        if (!is_array($value) || ($value !== [] && array_is_list($value))) {
            return Refusal::invalid('invalid_setting', "$name is a message per language: {\"en\": \"...\", \"sk\": \"...\"}.", ['setting' => $name]);
        }
        if (count($value) > self::MESSAGE_LANGUAGES_MAX && !$readingBack) {
            return Refusal::invalid('invalid_setting', "$name holds at most " . self::MESSAGE_LANGUAGES_MAX . ' languages.', ['setting' => $name]);
        }
        $out = [];
        foreach ($value as $language => $text) {
            $language = (string) $language;
            if (!LanguageCode::ok($language) || !is_string($text)) {
                if ($readingBack) {
                    continue;
                }

                return Refusal::invalid('invalid_setting', "$name: each language is a code (en, sk, pt-br) with its text.", ['setting' => $name]);
            }
            $text = trim(str_replace(["\r\n", "\r"], "\n", Utf8::scrub($text)));
            if ($text === '') {
                continue;
            }
            if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $text) === 1) {
                if ($readingBack) {
                    continue;
                }

                return Refusal::invalid('invalid_setting', "$name ($language) holds a control character: words, spaces and line breaks only.", ['setting' => $name, 'language' => $language]);
            }
            if (Utf8::length($text) > self::MESSAGE_MAX) {
                if (!$readingBack) {
                    return Refusal::invalid('invalid_setting', "$name ($language) is longer than " . self::MESSAGE_MAX . ' characters.', [
                        'setting' => $name,
                        'language' => $language,
                        'max' => self::MESSAGE_MAX,
                    ]);
                }
                $text = Utf8::prefix($text, self::MESSAGE_MAX);
            }
            $out[$language] = $text;
        }
        uksort($out, static fn(string $a, string $b): int => [$a !== 'en', $a] <=> [$b !== 'en', $b]);

        return array_slice($out, 0, self::MESSAGE_LANGUAGES_MAX, true);
    }

    /**
     * A page's API paths as they are saved - trimmed, without a trailing slash, each once, sorted - or why not.
     *
     * @param list<mixed> $paths
     * @return list<string>|Refusal
     */
    private function checkedApiPaths(array $paths): array|Refusal
    {
        $clean = [];
        foreach ($paths as $path) {
            if (!is_string($path)) {
                return Refusal::invalid('invalid_api_paths', 'A page\'s API paths are a list of paths.');
            }
            $path = rtrim(trim($path), '/');
            if ($path === '') {
                continue;
            }
            $refusal = $this->apiPathRefusal($path);
            if ($refusal !== null) {
                return Refusal::invalid('invalid_api_path', $refusal, ['api_path' => $path]);
            }
            $clean[$path] = true;
        }
        if (count($clean) > self::API_PATHS_MAX) {
            return Refusal::invalid('invalid_api_paths', 'A page refuses at most ' . self::API_PATHS_MAX . ' API paths with it.');
        }
        $out = array_keys($clean);
        sort($out, SORT_STRING);

        return $out;
    }

    /** A switch's value as the table's JSON: a message is an object, empty or not. */
    private static function json(string $name, mixed $value): string
    {
        return (string) json_encode(is_array($value) ? (object) $value : $value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    /** A list of paths as PostgreSQL's array literal (the paths hold no quote, comma, brace or backslash: PageRules::apiProblem()). */
    private static function textArray(array $paths): string
    {
        return '{' . implode(',', array_map(static fn(string $path): string => '"' . $path . '"', $paths)) . '}';
    }

    /** Who acted, as the tables keep it: the site's person id, or null. */
    private static function person(int|string|null $actor): ?int
    {
        return is_int($actor) ? $actor : (is_string($actor) && preg_match('/^\d{1,18}$/D', $actor) === 1 ? (int) $actor : null);
    }
}
