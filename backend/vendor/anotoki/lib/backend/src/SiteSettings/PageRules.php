<?php

declare(strict_types=1);

namespace Anotoki\Lib\SiteSettings;

use Anotoki\Lib\Auth\Person;

/**
 * Page access's rules, on their own: how a row's path matches a page, how an API path is matched, who a
 * visibility lets in, and why a path may not be saved - the reserved paths checked both ways. Pure functions;
 * SiteSettings applies them to its rows and its reserved lists.
 *
 * - A page's path is as the site's router declares it: '/', '/quizzes', '/database/characters/:id'. A ':name'
 *   segment stands for any one segment, and the counts must match: '/database/characters' is not
 *   '/database/characters/5' - two rows, two decisions (genshin's routeMatchesPath()).
 * - An API path is matched from its start, letter by letter: '/api/quiz' covers /api/quizzes and /api/quiz/stats
 *   alike - genshin's rule, and its rows mean it.
 * - A reserved path (of a page, or of the API) is itself and everything under it: '/auth' is /auth/callback too.
 *   No row may claim one, either way round: a row that would cover it ('/api/' covers /api/me, '/:section' covers
 *   /staff) and a row inside it ('/api/site-settings/x') are refused alike - a page access that shut the way in,
 *   the words, the status or the settings that would undo it is a lockout of everybody but the administrators.
 *
 * @internal
 */
final class PageRules
{
    /** Who a page is shown to, from the most open: anybody, somebody signed in, editors and administrators, administrators. */
    public const VISIBILITIES = ['PUBLIC', 'USER', 'EDITOR', 'ADMIN'];

    /** A page path as a router declares it: segments of letters, digits, '.', '_' and '-', a placeholder's with a ':' first. */
    private const PAGE = '#^(/:?[A-Za-z0-9][A-Za-z0-9._-]*)+$#D';

    /** An API path: under /api/, of the characters a path holds, no empty segment, no dot segment. */
    private const API = '#^/api(/[A-Za-z0-9._~-]+)+$#D';

    public const PATH_MAX = 255;

    /** A path's segments: '/' -> [], '/a/:id' -> ['a', ':id']. */
    public static function segments(string $path): array
    {
        $trimmed = trim($path, '/');

        return $trimmed === '' ? [] : explode('/', $trimmed);
    }

    /** Whether a row's path is this page's: the same number of segments, each the same or a placeholder. */
    public static function matches(string $pattern, string $path): bool
    {
        $left = self::segments($pattern);
        $right = self::segments($path);
        if (count($left) !== count($right)) {
            return false;
        }
        foreach ($left as $index => $segment) {
            if (!str_starts_with($segment, ':') && $segment !== $right[$index]) {
                return false;
            }
        }

        return true;
    }

    /**
     * Whether a row's path could be a reserved page or a page under it: as many segments as the reserved path or
     * more, its first ones each the reserved path's or a placeholder. ('/:section' is /staff; '/auth/:step' is
     * under /auth; '/account' is not under '/account/settings' - it has fewer segments, and is no page there.)
     */
    public static function claimsPage(string $pattern, string $reserved): bool
    {
        $row = self::segments($pattern);
        $kept = self::segments($reserved);
        if ($kept === [] || count($row) < count($kept)) {
            return $kept === [] && $row === [];
        }
        foreach ($kept as $index => $segment) {
            if (!str_starts_with($row[$index], ':') && $row[$index] !== $segment) {
                return false;
            }
        }

        return true;
    }

    /** Whether a request's path is a reserved path or under it ('/api/me', '/api/me/x'; never '/api/media'). */
    public static function within(string $path, string $reserved): bool
    {
        return $path === $reserved || str_starts_with($path, rtrim($reserved, '/') . '/');
    }

    /**
     * Whether an API path claimed by a row touches a reserved one, either way: it covers it - every request the
     * reserved path answers begins with it, letter by letter ('/api/' and '/api/m' cover /api/me) - or it is the
     * reserved path or inside it ('/api/site-settings/x'). '/api/media' touches /api/me neither way.
     */
    public static function touches(string $prefix, string $reserved): bool
    {
        return str_starts_with($reserved, $prefix) || self::within($prefix, $reserved);
    }

    /** Why a page's path cannot be a row, or null when it can (the reserved pages apart: SiteSettings asks those). */
    public static function pageProblem(string $path): ?string
    {
        if (!str_starts_with($path, '/')) {
            return "'$path' is not a page: a page's path begins with /.";
        }
        if (strlen($path) > self::PATH_MAX) {
            return 'That path is longer than ' . self::PATH_MAX . ' characters.';
        }
        if ($path !== '/' && preg_match(self::PAGE, $path) !== 1) {
            return "'$path' is not a path the site's router could declare: segments of letters, digits, dots, dashes and underscores (a placeholder starts with :), no empty one.";
        }

        return null;
    }

    /** Why an API path cannot be claimed, or null when it can (the reserved ones apart: SiteSettings asks those). */
    public static function apiProblem(string $prefix): ?string
    {
        if (!str_starts_with($prefix, '/api/')) {
            return "'$prefix' is not an API path: they begin with /api/.";
        }
        if (strlen($prefix) > self::PATH_MAX) {
            return 'That path is longer than ' . self::PATH_MAX . ' characters.';
        }
        if (preg_match(self::API, $prefix) !== 1 || in_array('.', self::segments($prefix), true) || in_array('..', self::segments($prefix), true)) {
            return "'$prefix' is not an API path: segments of letters, digits, dots, dashes, underscores and tildes, no empty one and no dot segment.";
        }

        return null;
    }

    /** A path as it is saved: trimmed, without a trailing slash (the root stays '/'). */
    public static function normalise(string $path): string
    {
        $path = rtrim(trim($path), '/');

        return $path === '' ? '/' : $path;
    }

    /**
     * Whether a reader is one of those a visibility lets in. A visibility nothing understands is read as the
     * strictest, never the most open: a value the table should not hold is no reason to hand a page out.
     */
    public static function visibleTo(string $visibility, ?Person $reader): bool
    {
        return match ($visibility) {
            'PUBLIC' => true,
            'USER' => $reader !== null,
            'EDITOR' => $reader !== null && $reader->has('EDITOR', 'ADMIN'),
            default => $reader !== null && $reader->isAdmin(),
        };
    }

    /**
     * Whether a reader may have a page: the site's ADMIN always - whoever switched a page off must be able to look
     * at it and switch it on again (no lockout) -, anybody else when it is not switched off and its visibility
     * lets them in.
     *
     * @param array{visibility: string, blocked: bool} $row
     */
    public static function allows(array $row, ?Person $reader): bool
    {
        if ($reader !== null && $reader->isAdmin()) {
            return true;
        }

        return !$row['blocked'] && self::visibleTo($row['visibility'], $reader);
    }
}
