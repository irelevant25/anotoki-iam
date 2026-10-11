<?php

declare(strict_types=1);

namespace Anotoki\Lib\Backups;

use Anotoki\Lib\Support\Keys;
use RuntimeException;

/**
 * AES-256-GCM over a stream, a chunk at a time - the IAM's backups (the owner's
 * decision of 2026-10-07: only the IAM's are encrypted).
 *
 * A chunk on disk: [4 bytes: its ciphertext's length, the top bit set on the
 * last chunk][12 bytes: nonce][ciphertext][16 bytes: tag]. Every chunk's
 * plaintext is 1 MB, the last shorter (perhaps empty). Each chunk is
 * authenticated with the archive's header lines, its own index and whether it
 * is the last as associated data - so a header changed, chunks swapped,
 * dropped, repeated, or the tail cut off all fail to open, as does another key.
 *
 * The key of one backup: HKDF-SHA256 of the site's backup key (32 bytes - the
 * IAM's Keys::derive(iamMasterKey(), 'iam-backup')) with a random 32-byte salt
 * of its own (kept in the header), info "anotoki-lib/backup-chunks/v1".
 * OpenSSL where PHP has it, else libsodium's AES-256-GCM where the processor
 * has it; the format is the same.
 *
 * @internal
 */
final class ChunkCipher
{
    public const CHUNK = 1048576;
    public const NONCE = 12;
    public const TAG = 16;
    private const LAST = 0x80000000;

    private int $index = 0;
    private string $buffer = '';
    private bool $finished = false;

    /** @param \Closure(string): void $sink */
    private function __construct(private readonly string $key, private readonly string $aad, private readonly \Closure $sink)
    {
    }

    /** Whether AES-256-GCM is there to use. */
    public static function available(): bool
    {
        return (function_exists('openssl_encrypt') && in_array('aes-256-gcm', openssl_get_cipher_methods(), true))
            || (function_exists('sodium_crypto_aead_aes256gcm_is_available') && sodium_crypto_aead_aes256gcm_is_available());
    }

    /** One backup's key, from the site's backup key and the backup's salt. */
    public static function backupKey(string $siteKey, string $salt): string
    {
        return Keys::derive($siteKey, 'backup-chunks', $salt);
    }

    /**
     * A writer: write() as often as wanted, then finish() once.
     *
     * @param string $aad  what every chunk is bound to (the archive's header lines)
     * @param \Closure(string): void $sink  where the sealed bytes go
     */
    public static function writer(string $key, string $aad, \Closure $sink): self
    {
        if (!self::available()) {
            throw new RuntimeException('This PHP has no AES-256-GCM (OpenSSL, or libsodium on a processor with AES), so the backup cannot be encrypted.');
        }

        return new self($key, $aad, $sink);
    }

    public function write(string $plain): void
    {
        $this->buffer .= $plain;
        while (strlen($this->buffer) > self::CHUNK) {
            $this->emit(substr($this->buffer, 0, self::CHUNK), false);
            $this->buffer = substr($this->buffer, self::CHUNK);
        }
    }

    public function finish(): void
    {
        if ($this->finished) {
            return;
        }
        $this->finished = true;
        $this->emit($this->buffer, true);
        $this->buffer = '';
    }

    /**
     * Opens every chunk of $stream (from where it stands to its end), handing
     * each plaintext to $out in order.
     *
     * @param resource $stream
     * @param \Closure(string): void $out
     * @throws RuntimeException when a chunk does not open, is out of place, the last is missing, or more follows it
     */
    public static function open($stream, string $key, string $aad, \Closure $out): void
    {
        $next = self::reader($stream, $key, $aad);
        while (($plain = $next()) !== null) {
            $out($plain);
        }
    }

    /**
     * The same reading, a chunk at a time as it is asked for: each call answers
     * the next chunk's plaintext, null once the last one was given. Every chunk
     * is checked as it is read (see open()).
     *
     * @param resource $stream
     * @return \Closure(): ?string
     * @throws RuntimeException from the closure, as open() throws
     */
    public static function reader($stream, string $key, string $aad): \Closure
    {
        $index = 0;
        $done = false;

        return static function () use ($stream, $key, $aad, &$index, &$done): ?string {
            if ($done) {
                return null;
            }
            $head = self::readExactly($stream, 4);
            if ($head === '') {
                throw new RuntimeException('The encrypted backup ends before its last chunk: it was cut short.');
            }
            if (strlen($head) !== 4) {
                throw new RuntimeException('The encrypted backup ends inside a chunk: it was cut short.');
            }
            $word = unpack('N', $head)[1];
            $last = ($word & self::LAST) !== 0;
            $length = $word & ~self::LAST;
            if ($length > self::CHUNK) {
                throw new RuntimeException('The encrypted backup holds a chunk larger than any it writes: it was changed.');
            }
            $sealed = self::readExactly($stream, self::NONCE + $length + self::TAG);
            if (strlen($sealed) !== self::NONCE + $length + self::TAG) {
                throw new RuntimeException('The encrypted backup ends inside a chunk: it was cut short.');
            }
            $plain = self::decrypt($key, $sealed, self::chunkAad($aad, $index, $last));
            if ($plain === null) {
                throw new RuntimeException('A chunk of the encrypted backup does not open: it was changed, or it was made with another key.');
            }
            $index++;
            if ($last) {
                if (self::readExactly($stream, 1) !== '') {
                    throw new RuntimeException('The encrypted backup goes on after its last chunk: it was changed.');
                }
                $done = true;
            }

            return $plain;
        };
    }

    private function emit(string $plain, bool $last): void
    {
        $nonce = random_bytes(self::NONCE);
        $aad = self::chunkAad($this->aad, $this->index, $last);
        if (function_exists('openssl_encrypt') && in_array('aes-256-gcm', openssl_get_cipher_methods(), true)) {
            $tag = '';
            $cipher = openssl_encrypt($plain, 'aes-256-gcm', $this->key, OPENSSL_RAW_DATA, $nonce, $tag, $aad, self::TAG);
            if ($cipher === false) {
                throw new RuntimeException('OpenSSL could not encrypt a chunk of the backup.');
            }
            $sealed = $cipher . $tag;
        } else {
            $sealed = sodium_crypto_aead_aes256gcm_encrypt($plain, $aad, $nonce, $this->key);
        }
        $length = strlen($sealed) - self::TAG;
        ($this->sink)(pack('N', $length | ($last ? self::LAST : 0)) . $nonce . $sealed);
        $this->index++;
    }

    private static function decrypt(string $key, string $sealed, string $aad): ?string
    {
        $nonce = substr($sealed, 0, self::NONCE);
        $cipher = substr($sealed, self::NONCE, -self::TAG);
        $tag = substr($sealed, -self::TAG);
        if (function_exists('openssl_decrypt') && in_array('aes-256-gcm', openssl_get_cipher_methods(), true)) {
            $plain = openssl_decrypt($cipher, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $nonce, $tag, $aad);
        } elseif (function_exists('sodium_crypto_aead_aes256gcm_is_available') && sodium_crypto_aead_aes256gcm_is_available()) {
            $plain = sodium_crypto_aead_aes256gcm_decrypt($cipher . $tag, $aad, $nonce, $key);
        } else {
            throw new RuntimeException('This PHP has no AES-256-GCM, so the encrypted backup cannot be read here.');
        }

        return is_string($plain) ? $plain : null;
    }

    private static function chunkAad(string $aad, int $index, bool $last): string
    {
        return $aad . pack('J', $index) . ($last ? "\x01" : "\x00");
    }

    /** @param resource $stream */
    private static function readExactly($stream, int $length): string
    {
        $data = '';
        while (strlen($data) < $length && !feof($stream)) {
            $piece = fread($stream, $length - strlen($data));
            if ($piece === false || $piece === '') {
                break;
            }
            $data .= $piece;
        }

        return $data;
    }
}
