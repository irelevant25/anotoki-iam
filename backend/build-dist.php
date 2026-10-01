<?php

/**
 * Assembles backend/dist/ - the runtime-only slice of the IAM backend, ready to
 * upload into the web root beside the Angular build.
 *
 *     php build-dist.php
 *
 * A copy with an allowlist, like the genshin site's: what lands in dist/ is
 * exactly what the running IAM needs. The tests, the command-line tools and
 * every per-machine secret are left behind.
 *
 * Shipped:
 *   api/          the application
 *   config/       minus every *.local.php
 *   vendor/       Slim and its dependencies (the host cannot run composer)
 *   migrations/   the schema - the admin console applies it
 *   storage/      empty, writable, and denied to the web
 *
 * Not shipped: setup.php (uploaded by hand for the first install only), the CLI
 * tools (migration.php, import-legacy.php, create-admin.php, reset-password.php,
 * clients.php, translations.php), tests/, and config/*.local.php - which
 * setup.php writes on the server itself. Nor composer.json and composer.lock: vendor/ loads itself, and
 * in the web root they would hand anybody the list of every dependency and its
 * version.
 */

$root = __DIR__;
$dist = $root . '/dist';

function removeTree(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    foreach (scandir($dir) as $entry) {
        if ($entry !== '.' && $entry !== '..') {
            $path = $dir . '/' . $entry;
            is_dir($path) ? removeTree($path) : @unlink($path);
        }
    }
    @rmdir($dir);
}

$stats = ['files' => 0, 'bytes' => 0];

function copyTree(string $src, string $dst, ?callable $skip = null): void
{
    global $stats;
    $src = rtrim(str_replace('\\', '/', $src), '/');
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($src, FilesystemIterator::SKIP_DOTS | FilesystemIterator::UNIX_PATHS),
        RecursiveIteratorIterator::SELF_FIRST
    );

    foreach ($iterator as $path => $info) {
        $relative = substr(str_replace('\\', '/', $path), strlen($src) + 1);
        if ($skip !== null && $skip($relative, $info->isDir())) {
            continue;
        }
        if ($info->isDir()) {
            @mkdir($dst . '/' . $relative, 0775, true);
            continue;
        }
        @mkdir(dirname($dst . '/' . $relative), 0775, true);
        copy($path, $dst . '/' . $relative);
        $stats['files']++;
        $stats['bytes'] += (int) filesize($path);
    }
}

/** Read by PHP from disk, never served. */
function denyWeb(string $dir): void
{
    @mkdir($dir, 0775, true);
    file_put_contents($dir . '/.htaccess', "<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n    Order allow,deny\n    Deny from all\n</IfModule>\n");
}

echo "Assembling backend/dist/ ...\n";
removeTree($dist);
mkdir($dist, 0775, true);

copyTree($root . '/api', $dist . '/api');

$leftOut = [];
copyTree($root . '/config', $dist . '/config', static function (string $relative, bool $isDir) use (&$leftOut): bool {
    if (!$isDir && str_ends_with($relative, '.local.php')) {
        $leftOut[] = $relative;
        return true;
    }
    return false;
});

copyTree($root . '/vendor', $dist . '/vendor');
copyTree($root . '/migrations', $dist . '/migrations');

foreach (['storage/cache', 'storage/mail'] as $folder) {
    @mkdir($dist . '/' . $folder, 0775, true);
}
foreach (['config', 'migrations', 'storage', 'vendor'] as $guarded) {
    denyWeb($dist . '/' . $guarded);
}

printf("\n  %d files, %.1f MB -> backend/dist/\n", $stats['files'], $stats['bytes'] / 1048576);
echo "\nUpload the CONTENTS of backend/dist/ into the web root, beside the Angular build\n";
echo "(frontend/dist/iam/browser/*, which brings the .htaccess that routes /api and /.well-known here).\n";
echo "\nFirst install only: upload backend/setup.php to the web root too and open it in a browser.\n";

if ($leftOut) {
    sort($leftOut);
    echo "\nLeft out on purpose (setup.php writes the server's own):\n";
    foreach ($leftOut as $file) {
        echo "  config/{$file}\n";
    }
}
