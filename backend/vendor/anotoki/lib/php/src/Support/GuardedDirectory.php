<?php

declare(strict_types=1);

namespace Anotoki\Lib\Support;

/**
 * A folder the library writes under a site's storage - logs, backups, caches,
 * the trash - made with a guard against being served.
 *
 * On five of the six sites storage/ sits inside the web root, kept from the
 * browser by an .htaccess alone (genshin's build-dist.php writes one). A folder
 * the library makes gets its own as well, so that what it holds stays private
 * even where the site's own guard is missing, was never uploaded, or covers
 * only the folder above: a deny-all in both of Apache's syntaxes (2.4's
 * Require and 2.2's Order/Deny, each inside its IfModule) and no listing; and
 * an empty index.html for a server that lists folders without reading
 * .htaccess files.
 *
 * Unlike the guard of a folder of uploads (Files\UploadPolicy), this one may
 * use Options and Require: nothing in it is ever meant to be served, so a host
 * that answers 500 for a directive it does not allow still serves nothing.
 *
 * Never overwrites: an .htaccess or index.html already there is the site's.
 */
final class GuardedDirectory
{
    public const HTACCESS = <<<'TXT'
# Written by anotoki-lib (Anotoki\Lib\Support\GuardedDirectory): what this
# folder holds - logs, backups, caches, keys - is never served to anybody.
<IfModule mod_authz_core.c>
    Require all denied
</IfModule>
<IfModule !mod_authz_core.c>
    Order allow,deny
    Deny from all
</IfModule>
Options -Indexes

TXT;

    /**
     * Makes $directory (with its parents, mode 0775) when it is missing, and its
     * .htaccess and index.html when they are missing. Returns whether the folder
     * exists with an .htaccess in it - false where it could not be made, or
     * where the guard could not be written (a folder the web server may serve
     * is no place for what the library keeps). Never throws, never warns.
     */
    public static function ensure(string $directory): bool
    {
        $directory = rtrim($directory, '/\\');
        if ($directory === '') {
            return false;
        }
        if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
            return false;
        }

        $guard = $directory . '/.htaccess';
        if (!is_file($guard)) {
            AtomicFile::write($guard, self::HTACCESS, exclusive: true);
        }
        $index = $directory . '/index.html';
        if (!is_file($index)) {
            AtomicFile::write($index, '', exclusive: true);
        }

        return is_file($guard);
    }
}
