<?php

declare(strict_types=1);

namespace Anotoki\Lib\Support;

/**
 * A file written whole or not at all: written aside under a name of its own,
 * then renamed into place - so a reader never sees half of it, and two
 * requests writing the same file never write into one copy. Renaming is
 * retried for a moment, as Windows can hold a fresh file briefly (Japanese
 * Academy's file_put_atomic()).
 */
final class AtomicFile
{
    /**
     * Writes $content to $path. With $exclusive, a file already at $path is
     * left as it is (and false answered). The file written aside is made by
     * tempnam() - new, in $path's own folder, and the site's own user's alone
     * (0600) from the moment it is there: nobody else opens it before a byte is
     * in it, nor while it is written (a handle opened under the umask's 0644
     * before a chmod would read what is written after). Then it gets $mode
     * (0600 for what is the site's alone; without one, what a new file gets
     * under the umask, as a guard .htaccess the web server reads needs), then
     * its place. Never throws, never warns.
     */
    public static function write(string $path, string $content, bool $exclusive = false, ?int $mode = null): bool
    {
        if ($exclusive && file_exists($path)) {
            return false;
        }

        $temporary = self::aside(dirname($path));
        if ($temporary === null) {
            return false;
        }
        if (@file_put_contents($temporary, $content) !== strlen($content)) {
            @unlink($temporary);

            return false;
        }
        @chmod($temporary, $mode ?? (0666 & ~umask()));

        for ($attempt = 0; $attempt < 8; $attempt++) {
            if ($exclusive && file_exists($path)) {
                @unlink($temporary);

                return false;
            }
            if (@rename($temporary, $path)) {
                return true;
            }
            usleep(25_000 * ($attempt + 1));
        }
        @unlink($temporary);

        return false;
    }

    /**
     * A new, empty file of a name of its own in $directory, 0600 from its creation (tempnam()) - or null. Never one
     * in the system's temporary folder, where tempnam() makes it when $directory cannot be written: a rename from
     * there may be a copy, and what is written there is not the site's to keep.
     *
     * @internal AtomicFile, Keys
     */
    public static function aside(string $directory): ?string
    {
        $temporary = @tempnam($directory, '.anotoki-');
        if (!is_string($temporary) || $temporary === '') {
            return null;
        }
        $made = realpath(dirname($temporary));
        $wanted = realpath($directory);
        $same = is_string($made) && is_string($wanted) && (PHP_OS_FAMILY === 'Windows'
            ? strcasecmp(rtrim($made, '\\/'), rtrim($wanted, '\\/')) === 0
            : rtrim($made, '/') === rtrim($wanted, '/'));
        if (!$same) {
            @unlink($temporary);

            return null;
        }

        return $temporary;
    }
}
