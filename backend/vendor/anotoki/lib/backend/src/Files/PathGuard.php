<?php

declare(strict_types=1);

namespace Anotoki\Lib\Files;

/**
 * The file explorer's paths - each rule the line against an attack:
 *
 * relative(): a path as a request may name one - relative, "/"-separated, in
 * NFC, at most 255 bytes a part and 1024 in all. Refused: NUL and control
 * characters, "\" and ":" (another separator, a drive, a Windows stream), a
 * leading "/", empty parts, "." and "..", a part that begins with a dot
 * (.htaccess, .user.ini, .git) or ends with a dot or a space (Windows drops
 * them: "x.php." is x.php there), < > " | ? * (no name on Windows), and the
 * names Windows keeps for its devices, whatever their extension (CON, PRN,
 * AUX, NUL, COM1-9, LPT1-9 and their kin: "nul.png" is no file there). A
 * request's query is decoded once by the server, so "%2e%2e" arrives as ".."
 * and is refused as such.
 *
 * resolve(): where such a path is on disk - only when every part of the way
 * that exists is no link (is_link()) and resolves, by realpath(), to exactly
 * the path joined so far, inside the root's real path plus a separator. So a
 * symbolic link, a Windows junction, a short 8.3 name or another case of a
 * name all fail, and a sibling folder whose name begins like the root's
 * ("assets-old" beside "assets") is never inside it.
 */
final class PathGuard
{
    /** A path's longest part, in bytes. */
    public const SEGMENT_BYTES = 255;

    /** A path's whole length, in bytes. */
    public const PATH_BYTES = 1024;

    /** The path as the explorer takes it ('' is the root itself), or null. */
    public static function relative(string $path): ?string
    {
        if ($path === '') {
            return '';
        }
        if (preg_match('//u', $path) !== 1) {
            return null;
        }
        if (class_exists(\Normalizer::class)) {
            $normal = \Normalizer::normalize($path, \Normalizer::FORM_C);
            if (!is_string($normal)) {
                return null;
            }
            $path = $normal;
        }
        if (strlen($path) > self::PATH_BYTES || $path[0] === '/') {
            return null;
        }
        foreach (explode('/', $path) as $segment) {
            if (self::segment($segment) === null) {
                return null;
            }
        }

        return $path;
    }

    /** One part of a path - a file's or a folder's name - or null. */
    public static function segment(string $segment): ?string
    {
        if ($segment === '' || $segment === '.' || $segment === '..' || strlen($segment) > self::SEGMENT_BYTES) {
            return null;
        }
        if (preg_match('//u', $segment) !== 1 || preg_match('/[\x00-\x1F\x7F\\\\:<>"|?*\/]/', $segment) === 1) {
            return null;
        }
        if ($segment[0] === '.' || str_ends_with($segment, '.') || trim($segment) !== $segment) {
            return null;
        }
        // A device's name before the first dot, as Windows reads it (with spaces before the dot dropped).
        if (preg_match('/^(?:CON|PRN|AUX|NUL|COM[0-9¹²³]|LPT[0-9¹²³]|CONIN\$|CONOUT\$)\s*(?:\.|$)/iu', $segment) === 1) {
            return null;
        }

        return $segment;
    }

    /** A root's real path (no trailing separator, "/" separators), or null when it is not a folder. */
    public static function root(string $path): ?string
    {
        $real = realpath($path);
        if ($real === false || !is_dir($real)) {
            return null;
        }

        return rtrim(str_replace('\\', '/', $real), '/');
    }

    /**
     * Where $relative (from relative()) is under the root - every existing part
     * checked (see the class). $mustExist: the target is there; otherwise only
     * its folder must be (a new name). Null when anything fails.
     */
    public static function resolve(string $root, string $relative, bool $mustExist = true): ?string
    {
        $current = rtrim(str_replace('\\', '/', $root), '/');
        if ($relative === '') {
            return $current;
        }
        $segments = explode('/', $relative);
        $last = count($segments) - 1;

        foreach ($segments as $index => $segment) {
            $next = $current . '/' . $segment;
            clearstatcache(true, $next);
            if (is_link($next)) {
                return null;
            }
            if (!file_exists($next)) {
                // Only the target itself may be missing, and only for a new name.
                return !$mustExist && $index === $last ? $next : null;
            }
            $real = realpath($next);
            if ($real === false || str_replace('\\', '/', $real) !== $next || !self::inside($next, $root)) {
                return null;
            }
            if ($index !== $last && !is_dir($next)) {
                return null;
            }
            $current = $next;
        }

        return $current;
    }

    /** Whether $path is inside $root - below it; the root itself only with $orRoot. Both real paths. */
    public static function inside(string $path, string $root, bool $orRoot = false): bool
    {
        $path = str_replace('\\', '/', $path);
        $root = rtrim(str_replace('\\', '/', $root), '/');
        if ($root === '') {
            return false;
        }

        return ($orRoot && $path === $root) || str_starts_with($path, $root . '/');
    }

    /** The path's folder part ('' for the root) and its name. @return array{string, string} */
    public static function split(string $relative): array
    {
        $slash = strrpos($relative, '/');

        return $slash === false ? ['', $relative] : [substr($relative, 0, $slash), substr($relative, $slash + 1)];
    }
}
