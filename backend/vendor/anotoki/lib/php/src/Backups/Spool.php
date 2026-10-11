<?php

declare(strict_types=1);

namespace Anotoki\Lib\Backups;

use Closure;
use HashContext;
use RuntimeException;
use Throwable;

/**
 * One table's rows on their way into a backup or out of one, held in a file of
 * the backup's working folder (0600, in a folder of its own for each run) -
 * and on a site whose backups are encrypted (the IAM's), never in the clear on
 * its disk, not even a table at a time: sealed in AES-256-GCM chunks
 * (ChunkCipher) under a key of its own that lives only in this process's
 * memory, so what a timeout or a kill leaves behind opens for nobody.
 *
 * PostgreSQL's COPY writes into a spool and reads out of one by its url
 * (SpoolStream): pdo_pgsql opens COPY's file through PHP's streams, a row at
 * a time - so memory stays at a chunk, whatever the table's size. The rows'
 * size, SHA-256 and count are taken as they pass (facts()).
 *
 * @internal
 */
final class Spool
{
    public const SCHEME = 'anotoki-lib-spool';

    private const NEW = 'new';
    private const WRITING = 'writing';
    private const WRITTEN = 'written';
    private const READING = 'reading';
    private const FAILED = 'failed';

    /** @var array<string, self> the spools a url may name, by their token */
    private static array $open = [];

    private readonly string $token;
    private readonly ?string $key;
    private readonly string $aad;
    private string $state = self::NEW;
    private ?string $failure = null;

    /** @var ?resource */
    private $handle = null;
    private ?ChunkCipher $sealer = null;
    private ?HashContext $hash = null;
    private int $bytes = 0;
    private int $rows = 0;

    /** @var ?array{bytes: int, sha256: string, rows: int} */
    private ?array $facts = null;

    /** @var ?Closure(): ?string */
    private ?Closure $nextChunk = null;
    private string $buffer = '';
    private bool $drained = false;

    private function __construct(private readonly string $path, bool $sealed)
    {
        $this->token = bin2hex(random_bytes(16));
        $this->key = $sealed ? random_bytes(32) : null;
        $this->aad = 'anotoki-lib/spool/v1/' . $this->token;
    }

    /** A new spool at $path, which must not be there yet: sealed (a site that encrypts its backups), or not. */
    public static function create(string $path, bool $sealed): self
    {
        SpoolStream::register();
        $spool = new self($path, $sealed);
        self::$open[$spool->token] = $spool;

        return $spool;
    }

    /** What COPY and the archive open it by. */
    public function url(): string
    {
        return self::SCHEME . '://' . $this->token;
    }

    /** @internal SpoolStream: the spool a url names, while it is open. */
    public static function named(string $url): ?self
    {
        $prefix = self::SCHEME . '://';

        return str_starts_with($url, $prefix) ? (self::$open[substr($url, strlen($prefix))] ?? null) : null;
    }

    /**
     * The rows' size, SHA-256 and count, once they were written whole.
     *
     * @return array{bytes: int, sha256: string, rows: int}
     * @throws RuntimeException when they were not
     */
    public function facts(): array
    {
        return $this->facts ?? throw new RuntimeException($this->failure ?? 'A part of the backup was not written whole.');
    }

    /** Why writing or reading it failed, if it did. */
    public function failure(): ?string
    {
        return $this->failure;
    }

    /** Its file deleted; its url opens nothing any more. */
    public function discard(): void
    {
        $this->close();
        @unlink($this->path);
        unset(self::$open[$this->token]);
        $this->state = self::FAILED;
        $this->failure ??= 'It was discarded.';
    }

    // ─── Writing (SpoolStream) ───────────────────────────────────────────────

    /** @internal */
    public function openWriting(): bool
    {
        if ($this->state !== self::NEW) {
            return false;
        }
        $handle = @fopen($this->path, 'xb');
        if ($handle === false) {
            return $this->fail('Its working file cannot be made.');
        }
        @chmod($this->path, 0600);
        $this->handle = $handle;
        $this->hash = hash_init('sha256');
        try {
            if ($this->key !== null) {
                $this->sealer = ChunkCipher::writer($this->key, $this->aad, function (string $sealed): void {
                    if ($this->handle === null || fwrite($this->handle, $sealed) !== strlen($sealed)) {
                        throw new RuntimeException('Its working file could not be written (is the disk full?).');
                    }
                });
            }
        } catch (Throwable $e) {
            return $this->fail($e->getMessage());
        }
        $this->state = self::WRITING;

        return true;
    }

    /** @internal */
    public function write(string $bytes): bool
    {
        if ($this->state !== self::WRITING || $this->handle === null || $this->hash === null) {
            return false;
        }
        try {
            hash_update($this->hash, $bytes);
            $this->bytes += strlen($bytes);
            $this->rows += substr_count($bytes, "\n");
            if ($this->sealer !== null) {
                $this->sealer->write($bytes);
            } elseif (fwrite($this->handle, $bytes) !== strlen($bytes)) {
                throw new RuntimeException('Its working file could not be written (is the disk full?).');
            }

            return true;
        } catch (Throwable $e) {
            return $this->fail($e->getMessage());
        }
    }

    /** @internal the last chunk sealed, the file closed, the facts kept */
    public function closeWriting(): void
    {
        if ($this->state !== self::WRITING || $this->handle === null || $this->hash === null) {
            return;
        }
        try {
            $this->sealer?->finish();
            if (!fflush($this->handle)) {
                throw new RuntimeException('Its working file could not be written (is the disk full?).');
            }
            $this->close();
            $this->facts = ['bytes' => $this->bytes, 'sha256' => hash_final($this->hash), 'rows' => $this->rows];
            $this->sealer = null;
            $this->state = self::WRITTEN;
        } catch (Throwable $e) {
            $this->fail($e->getMessage());
        }
    }

    // ─── Reading (SpoolStream) ───────────────────────────────────────────────

    /** @internal from its start, as often as asked, once written whole */
    public function openReading(): bool
    {
        if ($this->state !== self::WRITTEN) {
            return false;
        }
        $handle = @fopen($this->path, 'rb');
        if ($handle === false) {
            return $this->fail('Its working file cannot be read.');
        }
        $this->handle = $handle;
        $this->buffer = '';
        $this->drained = false;
        $this->nextChunk = $this->key !== null ? ChunkCipher::reader($handle, $this->key, $this->aad) : null;
        $this->state = self::READING;

        return true;
    }

    /** @internal at most $count bytes; '' at the end, false when it cannot be read */
    public function read(int $count): string|false
    {
        if ($this->state !== self::READING || $this->handle === null) {
            return false;
        }
        try {
            while (strlen($this->buffer) < $count && !$this->drained) {
                if ($this->nextChunk !== null) {
                    $plain = ($this->nextChunk)();
                    if ($plain === null) {
                        $this->drained = true;
                    } else {
                        $this->buffer .= $plain;
                    }
                    continue;
                }
                $piece = fread($this->handle, max($count, 65536));
                if ($piece === false) {
                    throw new RuntimeException('Its working file cannot be read.');
                }
                if ($piece === '') {
                    $this->drained = feof($this->handle) || $this->drained;
                    if (!$this->drained) {
                        throw new RuntimeException('Its working file cannot be read.');
                    }
                }
                $this->buffer .= $piece;
            }
            $out = substr($this->buffer, 0, $count);
            $this->buffer = substr($this->buffer, strlen($out));

            return $out;
        } catch (Throwable $e) {
            $this->fail($e->getMessage());

            return false;
        }
    }

    /** @internal */
    public function atEnd(): bool
    {
        return $this->state !== self::READING || ($this->drained && $this->buffer === '');
    }

    /** @internal */
    public function closeReading(): void
    {
        if ($this->state !== self::READING) {
            return;
        }
        $this->close();
        $this->nextChunk = null;
        $this->buffer = '';
        $this->state = self::WRITTEN;
    }

    private function close(): void
    {
        if ($this->handle !== null) {
            @fclose($this->handle);
            $this->handle = null;
        }
    }

    private function fail(string $why): bool
    {
        $this->failure ??= $why;
        $this->close();
        $this->facts = null;
        $this->state = self::FAILED;

        return false;
    }
}
