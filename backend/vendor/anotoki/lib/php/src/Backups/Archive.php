<?php

declare(strict_types=1);

namespace Anotoki\Lib\Backups;

use Closure;
use RuntimeException;
use Throwable;

/**
 * The backup's file, *.anotoki-backup:
 *
 *   ANOTOKI-BACKUP 1\n
 *   {the header: id, site, kind, created_at, library_version, schema, encrypted, cipher?}\n
 *   the body
 *
 * The body is a gzip'd ustar archive - manifest.json, schema/<set>/<file>.sql,
 * data/NNN-<table>.copy - which `tail -n +3 x.anotoki-backup | tar -xz` opens
 * anywhere; or, encrypted, that same gzip'd archive in AES-256-GCM chunks
 * bound to the two lines above (ChunkCipher). The header is not secret: the
 * list of backups reads it without the key.
 *
 * Written in one pass, a block at a time - memory stays flat whatever the
 * size - and read back the same way.
 *
 * @internal
 */
final class Archive
{
    public const MAGIC = 'ANOTOKI-BACKUP 1';

    /** The most the header line may weigh. */
    private const HEADER_LIMIT = 1048576;

    private const BLOCK = 512;

    /**
     * Writes the archive to $target (which must not exist), and answers its size and SHA-256.
     *
     * @param array<string, mixed> $header
     * @param list<array{name: string, file?: string, size?: int, data?: string}> $entries  in order; a file's
     *        content from `file` (a path, or a spool's url with its `size`) or `data`
     * @param ?string $siteKey  the site's backup key (32 bytes): encrypted when given
     * @return array{size: int, sha256: string, header: array<string, mixed>}
     */
    public static function write(string $target, array $header, array $entries, ?string $siteKey): array
    {
        if ($siteKey !== null) {
            $salt = random_bytes(32);
            $header['encrypted'] = true;
            $header['cipher'] = [
                'name' => 'aes-256-gcm',
                'chunk' => ChunkCipher::CHUNK,
                'kdf' => 'hkdf-sha256',
                'info' => 'anotoki-lib/backup-chunks/v1',
                'salt' => base64_encode($salt),
            ];
        } else {
            $header['encrypted'] = false;
            unset($header['cipher']);
        }
        $preamble = self::MAGIC . "\n" . json_encode($header, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";

        $handle = @fopen($target, 'xb');
        if ($handle === false) {
            throw new RuntimeException('The backup file could not be made.');
        }
        $hash = hash_init('sha256');
        $size = 0;
        $put = static function (string $bytes) use ($handle, $hash, &$size): void {
            if ($bytes === '') {
                return;
            }
            if (fwrite($handle, $bytes) !== strlen($bytes)) {
                throw new RuntimeException('The backup file could not be written (is the disk full?).');
            }
            hash_update($hash, $bytes);
            $size += strlen($bytes);
        };

        try {
            $put($preamble);
            $cipher = $siteKey !== null ? ChunkCipher::writer(ChunkCipher::backupKey($siteKey, $salt), $preamble, $put) : null;
            $deflate = deflate_init(ZLIB_ENCODING_GZIP, ['level' => 6]);
            if ($deflate === false) {
                throw new RuntimeException('zlib could not start compressing.');
            }
            $body = static function (string $bytes) use ($cipher, $put): void {
                if ($cipher !== null) {
                    $cipher->write($bytes);
                } else {
                    $put($bytes);
                }
            };
            $tar = static function (string $bytes) use ($deflate, $body): void {
                $compressed = deflate_add($deflate, $bytes, ZLIB_NO_FLUSH);
                if ($compressed === false) {
                    throw new RuntimeException('zlib could not compress the backup.');
                }
                $body($compressed);
            };

            foreach ($entries as $entry) {
                self::writeEntry($entry, $tar);
            }
            $tar(str_repeat("\0", self::BLOCK * 2));
            $last = deflate_add($deflate, '', ZLIB_FINISH);
            if ($last === false) {
                throw new RuntimeException('zlib could not finish the backup.');
            }
            $body($last);
            $cipher?->finish();
            if (!fflush($handle)) {
                throw new RuntimeException('The backup file could not be written (is the disk full?).');
            }
        } catch (Throwable $e) {
            fclose($handle);
            @unlink($target);
            throw $e;
        }
        fclose($handle);

        return ['size' => $size, 'sha256' => hash_final($hash), 'header' => $header];
    }

    /**
     * The header of an archive file - without the key, and without reading more
     * than its first two lines. Null when it is no backup of this kind.
     *
     * @return ?array<string, mixed>
     */
    public static function header(string $file): ?array
    {
        $handle = @fopen($file, 'rb');
        if ($handle === false) {
            return null;
        }
        try {
            [$header] = self::preamble($handle);

            return $header;
        } catch (Throwable) {
            return null;
        } finally {
            fclose($handle);
        }
    }

    /**
     * Reads the whole archive - decrypting and checking every chunk (encrypted),
     * decompressing, and checking every tar block - and hands each entry to
     * $place, which answers the path to write it to (or throws to refuse it).
     * Anything wrong anywhere throws: a header that is none, a chunk that does
     * not open, a stream cut short, bytes after the end.
     *
     * @param Closure(string $name, int $size): string $place
     * @return array<string, mixed> the header
     * @throws RuntimeException
     */
    public static function extract(string $file, ?string $siteKey, Closure $place): array
    {
        $handle = @fopen($file, 'rb');
        if ($handle === false) {
            throw new RuntimeException('The backup file cannot be opened.');
        }
        try {
            [$header, $preamble] = self::preamble($handle);
            $encrypted = ($header['encrypted'] ?? false) === true;
            // A site whose backups are encrypted made none in the clear: one that says it is, somebody put there.
            if ($siteKey !== null && !$encrypted) {
                throw new RuntimeException('This site\'s backups are encrypted, and this one is not: it is not read.');
            }

            $inflate = inflate_init(ZLIB_ENCODING_GZIP);
            if ($inflate === false) {
                throw new RuntimeException('zlib could not start reading the backup.');
            }
            $reader = new TarReader($place);
            $ended = false;
            $feed = static function (string $compressed) use ($inflate, $reader, &$ended): void {
                if ($compressed === '') {
                    return;
                }
                if ($ended) {
                    throw new RuntimeException('The backup goes on after its compressed end: it was changed.');
                }
                $before = inflate_get_read_len($inflate);
                $plain = @inflate_add($inflate, $compressed, ZLIB_SYNC_FLUSH);
                if ($plain === false) {
                    throw new RuntimeException('The backup does not decompress: it was changed or cut short.');
                }
                $reader->feed($plain);
                if (inflate_get_status($inflate) === ZLIB_STREAM_END) {
                    $ended = true;
                    if (inflate_get_read_len($inflate) - $before < strlen($compressed)) {
                        throw new RuntimeException('The backup goes on after its compressed end: it was changed.');
                    }
                }
            };

            if ($encrypted) {
                if ($siteKey === null) {
                    throw new RuntimeException('The backup is encrypted, and this site has no key to read it with.');
                }
                $salt = base64_decode((string) ($header['cipher']['salt'] ?? ''), true);
                if (!is_string($salt) || strlen($salt) !== 32 || ($header['cipher']['name'] ?? null) !== 'aes-256-gcm') {
                    throw new RuntimeException('The backup\'s cipher is none this library writes.');
                }
                ChunkCipher::open($handle, ChunkCipher::backupKey($siteKey, $salt), $preamble, $feed);
            } else {
                while (!feof($handle)) {
                    $piece = fread($handle, 1048576);
                    if ($piece === false) {
                        throw new RuntimeException('The backup file could not be read.');
                    }
                    $feed($piece);
                }
            }
            if (!$ended) {
                throw new RuntimeException('The backup ends before its compressed end: it was cut short.');
            }
            $reader->finish();

            return $header;
        } finally {
            fclose($handle);
        }
    }

    /**
     * The two lines before the body: [header, the lines' bytes].
     *
     * @param resource $handle
     * @return array{array<string, mixed>, string}
     */
    private static function preamble($handle): array
    {
        $magic = fgets($handle, 64);
        if ($magic !== self::MAGIC . "\n") {
            throw new RuntimeException('This is no anotoki backup (its first line is not "' . self::MAGIC . '").');
        }
        $line = fgets($handle, self::HEADER_LIMIT);
        if (!is_string($line) || !str_ends_with($line, "\n")) {
            throw new RuntimeException('The backup\'s header is missing or too long.');
        }
        $header = json_decode(substr($line, 0, -1), true);
        if (!is_array($header) || !is_string($header['id'] ?? null)) {
            throw new RuntimeException('The backup\'s header is not readable.');
        }

        return [$header, $magic . $line];
    }

    /**
     * One file into the tar: its header block, its content, the padding to a whole block.
     *
     * @param array{name: string, file?: string, size?: int, data?: string} $entry
     * @param Closure(string): void $tar
     */
    private static function writeEntry(array $entry, Closure $tar): void
    {
        if (isset($entry['file'])) {
            $size = $entry['size'] ?? @filesize($entry['file']);
            if (!is_int($size)) {
                throw new RuntimeException('A part of the backup went missing while it was written.');
            }
        } else {
            $size = strlen((string) ($entry['data'] ?? ''));
        }
        $tar(TarReader::headerBlock($entry['name'], $size, time()));

        if (isset($entry['file'])) {
            $source = @fopen($entry['file'], 'rb');
            if ($source === false) {
                throw new RuntimeException('A part of the backup could not be read back.');
            }
            try {
                $written = 0;
                while (!feof($source)) {
                    $piece = fread($source, 1048576);
                    if ($piece === false) {
                        throw new RuntimeException('A part of the backup could not be read back.');
                    }
                    $written += strlen($piece);
                    $tar($piece);
                }
                if ($written !== $size) {
                    throw new RuntimeException('A part of the backup changed while it was written.');
                }
            } finally {
                fclose($source);
            }
        } else {
            $tar((string) ($entry['data'] ?? ''));
        }
        $padding = (self::BLOCK - $size % self::BLOCK) % self::BLOCK;
        if ($padding > 0) {
            $tar(str_repeat("\0", $padding));
        }
    }
}
