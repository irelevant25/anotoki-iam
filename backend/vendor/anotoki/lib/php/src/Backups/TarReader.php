<?php

declare(strict_types=1);

namespace Anotoki\Lib\Backups;

use Closure;
use RuntimeException;

/**
 * The ustar archive inside a backup: the header block the writer makes, and a
 * reader fed the archive's bytes as they are decompressed, which writes each
 * file where the caller says and refuses anything but plain files with plain
 * names - an archive is read, never trusted.
 *
 * @internal
 */
final class TarReader
{
    private const BLOCK = 512;

    private string $buffer = '';

    /** @var ?resource the file being written */
    private $out = null;
    private int $left = 0;
    private int $padding = 0;
    private int $zeroBlocks = 0;
    private bool $ended = false;

    /** @param Closure(string $name, int $size): string $place */
    public function __construct(private readonly Closure $place)
    {
    }

    /** A ustar header block for a plain file. */
    public static function headerBlock(string $name, int $size, int $mtime): string
    {
        if (!self::nameOk($name)) {
            throw new RuntimeException("\"$name\" cannot be a name in the backup.");
        }
        $prefix = '';
        if (strlen($name) > 100) {
            $cut = strrpos(substr($name, 0, 156), '/');
            if ($cut === false || strlen($name) - $cut - 1 > 100) {
                throw new RuntimeException("\"$name\" is too long a name for the backup.");
            }
            $prefix = substr($name, 0, $cut);
            $name = substr($name, $cut + 1);
        }

        $block = pack(
            'a100a8a8a8a12a12a8a1a100a6a2a32a32a8a8a155a12',
            $name,
            sprintf('%07o', 0644),
            sprintf('%07o', 0),
            sprintf('%07o', 0),
            sprintf('%011o', $size),
            sprintf('%011o', $mtime),
            str_repeat(' ', 8),
            '0',
            '',
            "ustar\0",
            '00',
            'anotoki',
            'anotoki',
            '',
            '',
            $prefix,
            ''
        );
        $sum = 0;
        for ($i = 0; $i < self::BLOCK; $i++) {
            $sum += ord($block[$i]);
        }

        return substr_replace($block, sprintf('%06o', $sum) . "\0 ", 148, 8);
    }

    /** Bytes of the archive, in order. */
    public function feed(string $bytes): void
    {
        $this->buffer .= $bytes;
        $offset = 0;
        $length = strlen($this->buffer);

        while ($offset < $length) {
            if ($this->left > 0) {
                $take = min($this->left, $length - $offset);
                if (fwrite($this->out, substr($this->buffer, $offset, $take)) !== $take) {
                    throw new RuntimeException('A part of the backup could not be written out (is the disk full?).');
                }
                $offset += $take;
                $this->left -= $take;
                if ($this->left === 0) {
                    fclose($this->out);
                    $this->out = null;
                }
                continue;
            }
            if ($this->padding > 0) {
                $take = min($this->padding, $length - $offset);
                if (trim(substr($this->buffer, $offset, $take), "\0") !== '') {
                    throw new RuntimeException('The backup\'s archive is not well formed.');
                }
                $offset += $take;
                $this->padding -= $take;
                continue;
            }
            if ($length - $offset < self::BLOCK) {
                break;
            }
            $block = substr($this->buffer, $offset, self::BLOCK);
            $offset += self::BLOCK;
            $this->header($block);
        }

        $this->buffer = (string) substr($this->buffer, $offset);
    }

    /** The archive's end: whole, with its two empty blocks, and nothing half-read. */
    public function finish(): void
    {
        if ($this->out !== null) {
            fclose($this->out);
            $this->out = null;
        }
        if (!$this->ended || $this->left > 0 || $this->padding > 0 || $this->buffer !== '') {
            throw new RuntimeException('The backup\'s archive is cut short.');
        }
    }

    private function header(string $block): void
    {
        if ($block === str_repeat("\0", self::BLOCK)) {
            $this->zeroBlocks++;
            if ($this->zeroBlocks >= 2) {
                $this->ended = true;
            }

            return;
        }
        if ($this->ended || $this->zeroBlocks > 0) {
            throw new RuntimeException('The backup\'s archive goes on after its end.');
        }

        $fields = unpack('a100name/a8mode/a8uid/a8gid/a12size/a12mtime/a8sum/a1type/a100link/a6magic/a2version/a32user/a32group/a8major/a8minor/a155prefix', $block);
        if (!is_array($fields)) {
            throw new RuntimeException('The backup\'s archive is not well formed.');
        }
        $sum = 0;
        for ($i = 0; $i < self::BLOCK; $i++) {
            $sum += ($i >= 148 && $i < 156) ? 32 : ord($block[$i]);
        }
        $stored = trim((string) $fields['sum'], " \0");
        if (preg_match('/^[0-7]{1,7}$/D', $stored) !== 1 || (int) octdec($stored) !== $sum) {
            throw new RuntimeException('A block of the backup\'s archive is damaged (its checksum is wrong).');
        }
        if (!str_starts_with((string) $fields['magic'], 'ustar')) {
            throw new RuntimeException('The backup\'s archive is not ustar.');
        }
        $type = (string) $fields['type'];
        if ($type !== '0' && $type !== "\0" && $type !== '') {
            throw new RuntimeException('The backup\'s archive holds something other than plain files.');
        }
        $name = rtrim((string) $fields['name'], "\0");
        $prefix = rtrim((string) $fields['prefix'], "\0");
        $name = $prefix === '' ? $name : $prefix . '/' . $name;
        $sizeText = trim((string) $fields['size'], " \0");
        if (!self::nameOk($name) || preg_match('/^[0-7]{1,11}$/D', $sizeText) !== 1) {
            throw new RuntimeException('The backup\'s archive holds a name or a size it may not.');
        }
        $size = (int) octdec($sizeText);

        $path = ($this->place)($name, $size);
        $out = @fopen($path, 'xb');
        if ($out === false) {
            throw new RuntimeException('A part of the backup could not be written out.');
        }
        $this->out = $out;
        $this->left = $size;
        $this->padding = (self::BLOCK - $size % self::BLOCK) % self::BLOCK;
        if ($size === 0) {
            fclose($this->out);
            $this->out = null;
        }
    }

    /** A relative name of plain parts: letters, digits, . _ - and /, no part empty or starting with a dot. */
    private static function nameOk(string $name): bool
    {
        if ($name === '' || strlen($name) > 255 || preg_match('~^[A-Za-z0-9._/-]+$~D', $name) !== 1) {
            return false;
        }
        foreach (explode('/', $name) as $part) {
            if ($part === '' || $part[0] === '.') {
                return false;
            }
        }

        return true;
    }
}
