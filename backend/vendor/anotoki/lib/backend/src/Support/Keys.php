<?php

declare(strict_types=1);

namespace Anotoki\Lib\Support;

use InvalidArgumentException;
use RuntimeException;

/**
 * The keys the library's modules use - each derived for one purpose, so that
 * no two purposes ever share a key, and a key of one never opens another.
 *
 * - derive(): HKDF-SHA256 of a site's secret (the IAM's master key, genshin's
 *   appSecret()), with the info "anotoki-lib/<purpose>/v1". The IAM's backups
 *   take derive(iamMasterKey(), 'iam-backup').
 * - installation(): for a site without a secret of its own - 32 random bytes
 *   made once in storage/anotoki-lib.key and kept there. It keys the HMACs
 *   that must not be reproducible from outside (the error log's client hashes,
 *   the feedback limits' buckets, signed preview links, the backups' seal); it
 *   lives on the same server as what it protects, so it never encrypts
 *   anything. It is as safe as the storage folder, and no safer: whoever can
 *   write that folder can delete the key and make one of their own (no read of
 *   the old one needed) - as they can the IAM's key set kept there, which tokens
 *   are checked with. It keeps out whoever cannot write the folder.
 */
final class Keys
{
    /** The file installation() keeps its key in, under the storage folder it is given. */
    public const INSTALLATION_FILE = 'anotoki-lib.key';

    /** How old an empty key file is when its maker is taken for dead, in seconds: it goes, and the key is made again. */
    private const STALE = 10;

    /**
     * 32 bytes for one purpose: hash_hkdf('sha256', $secret, 32, "anotoki-lib/$purpose/v1", $salt).
     *
     * @param string $purpose small letters, digits and dashes ('iam-backup', 'error-log')
     */
    public static function derive(string $secret, string $purpose, string $salt = ''): string
    {
        if ($secret === '') {
            throw new InvalidArgumentException('A key is derived from a secret: this one is empty.');
        }
        if (preg_match('/^[a-z0-9][a-z0-9-]{0,62}$/D', $purpose) !== 1) {
            throw new InvalidArgumentException("A key's purpose is small letters, digits and dashes: \"$purpose\" is not.");
        }

        return hash_hkdf('sha256', $secret, 32, 'anotoki-lib/' . $purpose . '/v1', $salt);
    }

    /**
     * This installation's own key, 32 bytes: read from $storageDir/anotoki-lib.key,
     * made there the first time (one process makes it; every other reads the same:
     * make()). The folder gets a deny-all guard if it has none (GuardedDirectory).
     *
     * @throws RuntimeException when the key can be neither read nor made
     */
    public static function installation(string $storageDir): string
    {
        $storageDir = rtrim($storageDir, '/\\');
        $file = $storageDir . '/' . self::INSTALLATION_FILE;

        // An empty key file is claimed, its key on its way (claim()): waited for - until it is STALE seconds old and
        // goes (its maker died), and a little longer for the key made after it.
        $until = microtime(true) + self::STALE + 5;
        $made = 0;
        $notAKey = 0;
        while (microtime(true) < $until) {
            $key = self::readKey($file);
            if ($key !== null) {
                return $key;
            }
            clearstatcache(true, $file);
            if (!file_exists($file)) {
                // The folder that keeps the key is never served: its guard, where it has none (never over the site's).
                GuardedDirectory::ensure($storageDir);
                if (++$made > 20 || !self::make($storageDir, $file)) {
                    break;
                }
                continue;
            }
            if (is_file($file) && filesize($file) === 0) {
                // Claimed - or left empty by a process that died (one of 0.4.x made the file, then wrote it): nobody
                // ever read a key from that, and it goes once it is STALE seconds old.
                if (filemtime($file) < time() - self::STALE) {
                    @unlink($file);
                    continue;
                }
            } elseif (++$notAKey > 20) {
                // A file that is no key (torn, or nobody's), a moment after: never taken for one.
                break;
            }
            usleep(10_000);
        }

        throw new RuntimeException('The installation key ' . self::INSTALLATION_FILE . ' in the storage folder can be neither read nor made.');
    }

    /**
     * A new key, written whole into a file of its own - the site's own user's alone from its creation
     * (AtomicFile::aside(): tempnam(), 0600), so no handle opened before a chmod reads it - then put in place
     * where none is: by link(), which makes the key file only where there is none, so of two processes making it
     * at one moment one key becomes it and the other process reads that one; without link(), by claim(). False
     * when it cannot be made here at all.
     */
    private static function make(string $storageDir, string $file): bool
    {
        $temporary = AtomicFile::aside($storageDir);
        if ($temporary === null) {
            return false;
        }
        try {
            $made = bin2hex(random_bytes(32)) . "\n";
            if (@file_put_contents($temporary, $made) !== strlen($made)) {
                return false;
            }
            if (function_exists('link')) {
                if (@link($temporary, $file)) {
                    return true;
                }
                clearstatcache(true, $file);
                if (file_exists($file)) {
                    // Another process's came first: it is read.
                    return true;
                }
            }

            return self::claim($temporary, $file);
        } finally {
            self::remove($temporary);
        }
    }

    /**
     * The key put in place without a hard link (link() switched off - a shared host's disable_functions - or a
     * folder that takes none): the name is claimed with an empty file only one process can make ('x'), and only
     * the claimant renames its key over it - every other waits while the file is empty (installation()), and a
     * claim its maker left goes once it is STALE seconds old. Nothing is ever written into the claimed file itself,
     * so a handle opened on it reads no key. True when the key is there or another's is on its way.
     */
    private static function claim(string $temporary, string $file): bool
    {
        $claimed = @fopen($file, 'x');
        if ($claimed === false) {
            clearstatcache(true, $file);

            // Another's claim, whose key is waited for - or a folder that takes no file of that name.
            return file_exists($file);
        }
        fclose($claimed);
        // Retried for a moment: Windows may refuse to replace a file another process holds open this instant.
        for ($attempt = 0; $attempt < 8; $attempt++) {
            if (@rename($temporary, $file)) {
                return true;
            }
            usleep(25_000 * ($attempt + 1));
        }
        // Never put in place: the claim goes, so that the next try - this process's or another's - makes the key.
        self::remove($file);

        return false;
    }

    /** A file of this process's own gone - retried for a moment, as Windows keeps one another process holds open. */
    private static function remove(string $path): void
    {
        for ($attempt = 0; $attempt < 8; $attempt++) {
            if (@unlink($path)) {
                return;
            }
            clearstatcache(true, $path);
            if (!file_exists($path)) {
                return;
            }
            usleep(25_000 * ($attempt + 1));
        }
    }

    private static function readKey(string $file): ?string
    {
        $handle = @fopen($file, 'r');
        if ($handle === false) {
            return null;
        }
        $locked = flock($handle, LOCK_SH);
        $text = stream_get_contents($handle);
        if ($locked) {
            flock($handle, LOCK_UN);
        }
        fclose($handle);

        $text = is_string($text) ? trim($text) : '';

        return preg_match('/^[0-9a-f]{64}$/D', $text) === 1 ? (string) hex2bin($text) : null;
    }
}
