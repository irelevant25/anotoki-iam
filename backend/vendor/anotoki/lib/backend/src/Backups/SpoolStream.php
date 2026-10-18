<?php

declare(strict_types=1);

namespace Anotoki\Lib\Backups;

/**
 * PHP's stream wrapper for the urls of spools (anotoki-lib-spool://<token>):
 * what pdo_pgsql's COPY and the backup's archive write into and read out of.
 * pdo_pgsql opens COPY's file with php_stream_open_wrapper_ex() (PHP 8.2 to
 * 8.5), so a url of a wrapper registered here is as good as a path - and the
 * rows go to the spool a line at a time instead of to a file in the clear.
 *
 * Its methods never throw (a failure is the spool's, said by its failure()):
 * a write refused is COPY's "Unable to write to file", a read refused its end.
 *
 * @internal
 */
final class SpoolStream
{
    /** @var resource|null set by PHP */
    public $context;

    private ?Spool $spool = null;
    private bool $writing = false;

    public static function register(): void
    {
        if (!in_array(Spool::SCHEME, stream_get_wrappers(), true)) {
            stream_wrapper_register(Spool::SCHEME, self::class);
        }
    }

    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
    {
        $spool = Spool::named($path);
        $plain = str_replace(['b', 't'], '', $mode);
        if ($spool === null) {
            return false;
        }
        if ($plain === 'r') {
            $this->writing = false;
            $opened = $spool->openReading();
        } elseif ($plain === 'w' || $plain === 'x') {
            $this->writing = true;
            $opened = $spool->openWriting();
        } else {
            return false;
        }
        $this->spool = $opened ? $spool : null;

        return $opened;
    }

    public function stream_write(string $data): int
    {
        return $this->spool !== null && $this->writing && $this->spool->write($data) ? strlen($data) : 0;
    }

    public function stream_read(int $count): string|false
    {
        return $this->spool !== null && !$this->writing ? $this->spool->read($count) : false;
    }

    public function stream_eof(): bool
    {
        return $this->spool === null || $this->writing || $this->spool->atEnd();
    }

    public function stream_flush(): bool
    {
        return true;
    }

    public function stream_close(): void
    {
        if ($this->spool !== null) {
            if ($this->writing) {
                $this->spool->closeWriting();
            } else {
                $this->spool->closeReading();
            }
        }
        $this->spool = null;
    }

    /** @return array<string, int> */
    public function stream_stat(): array
    {
        return [];
    }
}
