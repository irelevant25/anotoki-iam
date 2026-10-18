<?php

declare(strict_types=1);

namespace Anotoki\Lib\Files;

use Anotoki\Lib\Support\AtomicFile;
use Anotoki\Lib\Support\Refusal;

/**
 * What may be written into a root - genshin's hardened rules of 2026-10-07
 * (backend/api/upload_guard.php, asset_naming.php), the owner's decision for
 * every site: nothing uploaded can ever run as code.
 *
 * - Names. An Apache that maps PHP with AddHandler or AddType runs a file as
 *   PHP when ANY part of its name says so - mod_mime reads every part after
 *   the first dot as an extension - so `shell.php.png` is a script there,
 *   however well its bytes pass for a picture. So: a name with any such part
 *   (runsOnServer(): scripts, server configuration, and the markup a browser
 *   would run - html, svg, js, xml) is refused; an upload keeps exactly one
 *   extension, its own, from the root's list - every other dot becomes "-"
 *   ("5.0 - Epitome.png" is stored as "5-0 - Epitome.png"); and no name may
 *   begin with a dot (.htaccess, .user.ini).
 * - Content. The bytes must be what the extension says (images: getimagesize()
 *   and finfo; audio and video: their container's magic bytes, an ISO box's
 *   size a box's; text: UTF-8 with no NUL; PDF: %PDF-). No file may begin with
 *   markup (after a byte order mark and white space) - what a browser that
 *   sniffs reads as a page; a text file may carry none in its first kilobyte
 *   (`<` before a letter, ! or ?); and no picture or sound may carry a page's
 *   tags there (an HTML polyglot named .png) - a picture's own metadata (XMP)
 *   stays.
 * - The folder. A root the explorer writes into gets a guard .htaccess before
 *   its first write (never overwritten): RemoveHandler and RemoveType for every
 *   script extension, AddType for every extension it takes - so no file of it
 *   goes out without a type a browser would sniff past - and, where Apache has
 *   mod_headers, nosniff; nothing else - never php_flag, Options or Require,
 *   each of which answers 500 for every file in the folder on a host whose
 *   AllowOverride does not allow it (AddType and Header need FileInfo, as
 *   RemoveHandler does). The name rule stops the attack; the guard is the
 *   second line, not a reason to risk the first.
 */
final class UploadPolicy
{
    /** Parts of a name a server could run, or read as its configuration, or a browser could run as a page. */
    public const DENIED_PARTS = [
        'php',
        'php3',
        'php4',
        'php5',
        'php6',
        'php7',
        'php8',
        'phtml',
        'pht',
        'phps',
        'phar',
        'cgi',
        'pl',
        'py',
        'rb',
        'sh',
        'asp',
        'aspx',
        'jsp',
        'shtml',
        'htaccess',
        'htpasswd',
        'ini',
        'html',
        'htm',
        'xhtml',
        'svg',
        'svgz',
        'js',
        'mjs',
        'xml',
        'exe',
        'dll',
    ];

    /** The handlers the guard takes away - genshin's list, word for word (upload_guard.php). */
    public const GUARDED_HANDLERS = ['php', 'phtml', 'phar', 'pht', 'php3', 'php4', 'php5', 'php6', 'php7', 'php8', 'phps', 'cgi', 'pl', 'py', 'sh', 'asp', 'aspx', 'jsp', 'shtml'];

    /** What each extension a root may take is, and what its bytes must look like. */
    public const FAMILIES = [
        'jpg' => 'image',
        'jpeg' => 'image',
        'png' => 'image',
        'gif' => 'image',
        'webp' => 'image',
        'avif' => 'image',
        'bmp' => 'image',
        'ico' => 'image',
        'ogg' => 'audio',
        'oga' => 'audio',
        'opus' => 'audio',
        'mp3' => 'audio',
        'wav' => 'audio',
        'm4a' => 'audio',
        'flac' => 'audio',
        'weba' => 'audio',
        'mp4' => 'video',
        'webm' => 'video',
        'txt' => 'text',
        'csv' => 'text',
        'md' => 'text',
        'json' => 'text',
        'log' => 'text',
        'pdf' => 'pdf',
    ];

    /** What each extension is served as. */
    public const TYPES = [
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'gif' => 'image/gif',
        'webp' => 'image/webp',
        'avif' => 'image/avif',
        'bmp' => 'image/bmp',
        'ico' => 'image/x-icon',
        'ogg' => 'audio/ogg',
        'oga' => 'audio/ogg',
        'opus' => 'audio/ogg',
        'mp3' => 'audio/mpeg',
        'wav' => 'audio/wav',
        'm4a' => 'audio/mp4',
        'flac' => 'audio/flac',
        'weba' => 'audio/webm',
        'mp4' => 'video/mp4',
        'webm' => 'video/webm',
        'txt' => 'text/plain',
        'csv' => 'text/csv',
        'md' => 'text/markdown',
        'json' => 'application/json',
        'log' => 'text/plain',
        'pdf' => 'application/pdf',
    ];

    /** A page's tags, never in a binary file's first kilobyte (a picture's XMP metadata is none of them). */
    private const PAGE_TAGS = '/<\?php|<\?=|<script|<html|<!doctype|<svg|<iframe|<body|<object|<embed|<!--|<img|<a[\s>\/]|<meta|<link|<style|<math|<form|<base|<input|<template|<frame|<applet/i';

    /** What getimagesize() must say each picture is. */
    private const IMAGE_TYPES = [
        'jpg' => [IMAGETYPE_JPEG],
        'jpeg' => [IMAGETYPE_JPEG],
        'png' => [IMAGETYPE_PNG],
        'gif' => [IMAGETYPE_GIF],
        'webp' => [IMAGETYPE_WEBP],
        'bmp' => [IMAGETYPE_BMP],
        'ico' => [IMAGETYPE_ICO],
    ];

    /**
     * Whether a web server could read any part of a name as a script or its
     * configuration, or a browser as a page. Read as Apache's mod_mime reads a
     * name: the part before the first dot is the file's own name (`php.png` is
     * a picture called php); every part after it counts, wherever it stands. A
     * name that begins with a dot has no such part, so all of it counts.
     */
    public static function runsOnServer(string $name): bool
    {
        $parts = explode('.', strtolower($name));
        if ($name === '' || $name[0] !== '.') {
            array_shift($parts);
        }
        foreach ($parts as $part) {
            if (in_array(trim($part), self::DENIED_PARTS, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The name an upload is stored under: its own extension, small, from the
     * root's list; every other dot of it a dash. Refused: 422 name_invalid,
     * name_runs_on_server, extension_not_allowed.
     */
    public static function storedName(string $clientName, FileRoot $root): string|Refusal
    {
        // A browser sends a name; anything with a folder in it was sent by something else.
        $name = self::plainName($clientName);
        if ($name === null) {
            return Refusal::invalid('name_invalid', 'That is no name a file can have here.', ['name' => self::shown($clientName)]);
        }
        if (self::runsOnServer($name)) {
            return Refusal::invalid('name_runs_on_server', 'A part of that name could run as a script on the web server, so it is not taken.', ['name' => $name]);
        }
        $dot = strrpos($name, '.');
        if ($dot === false || $dot === 0) {
            return Refusal::invalid('extension_not_allowed', 'This folder takes only ' . implode(', ', $root->extensions) . ' files.', ['name' => $name]);
        }
        $extension = strtolower(substr($name, $dot + 1));
        if (!in_array($extension, $root->extensions, true)) {
            return Refusal::invalid('extension_not_allowed', 'This folder takes only ' . implode(', ', $root->extensions) . ' files.', ['name' => $name]);
        }
        $stem = trim(str_replace('.', '-', substr($name, 0, $dot)));

        return PathGuard::segment($stem . '.' . $extension) ?? Refusal::invalid('name_invalid', 'That is no name a file can have here.', ['name' => $name]);
    }

    /**
     * A new name for a file (a rename) or a folder: a plain name, nothing in it
     * a server could run; a file keeps one extension from the root's list, and
     * a folder has none of the denied parts either.
     */
    public static function newName(string $name, FileRoot $root, bool $folder): string|Refusal
    {
        if ($folder) {
            $plain = self::plainName($name);
            if ($plain === null) {
                return Refusal::invalid('name_invalid', 'That is no name a folder can have here.', ['name' => self::shown($name)]);
            }
            if (self::runsOnServer($plain)) {
                return Refusal::invalid('name_runs_on_server', 'A part of that name could run as a script on the web server, so it is not taken.', ['name' => $plain]);
            }

            return $plain;
        }

        return self::storedName($name, $root);
    }

    /**
     * Null when the file's bytes are what its extension says (see the class);
     * otherwise 422 content_mismatch.
     */
    public static function contentRefusal(string $file, string $extension): ?Refusal
    {
        $family = self::FAMILIES[$extension] ?? null;
        $head = (string) @file_get_contents($file, false, null, 0, 4096);
        $mismatch = Refusal::invalid('content_mismatch', 'The file is not what its name says it is.', ['extension' => $extension]);
        if ($family === null || $head === '') {
            return $mismatch;
        }
        // What a browser that sniffs reads as a page: markup where the file begins - in every family, first.
        if (preg_match('/^(?:\xEF\xBB\xBF)?\s*<[!a-z?]/i', $head) === 1) {
            return $mismatch;
        }
        $early = substr($head, 0, 1024);
        if ($family === 'text' ? preg_match('/<[!a-z?]/i', $early) === 1 : preg_match(self::PAGE_TAGS, $early) === 1) {
            return $mismatch;
        }

        $ok = match ($family) {
            'image' => self::isImage($file, $extension, $head),
            'audio', 'video' => self::hasMagic($head, $extension),
            'text' => self::isText($file),
            'pdf' => str_starts_with($head, '%PDF-'),
            default => false,
        };

        return $ok ? null : $mismatch;
    }

    /**
     * The guard's rules: genshin's uploadGuardRules() word for word (RemoveHandler and RemoveType), then a
     * type for every extension the explorer takes (text as plain text), and nosniff where Apache has
     * mod_headers.
     */
    public static function guardRules(): string
    {
        $handlers = '.' . implode(' .', self::GUARDED_HANDLERS);
        $types = [];
        foreach (self::TYPES as $extension => $type) {
            $types[self::FAMILIES[$extension] === 'text' && $extension !== 'json' ? 'text/plain' : $type][] = '.' . $extension;
        }
        $lines = ["RemoveHandler $handlers", "RemoveType $handlers"];
        foreach ($types as $type => $extensions) {
            $lines[] = "AddType $type " . implode(' ', $extensions);
        }
        $lines[] = '<IfModule mod_headers.c>';
        $lines[] = '    Header set X-Content-Type-Options nosniff';
        $lines[] = '</IfModule>';

        return implode("\n", $lines) . "\n";
    }

    public static function guardHtaccess(): string
    {
        return implode("\n", [
            '# What is uploaded here is data - pictures, sound, documents - never code, so',
            '# nothing in this folder may run as a script, whatever its name. Written by',
            '# anotoki-lib\'s file explorer (Anotoki\\Lib\\Files\\UploadPolicy) before its',
            '# first upload here, when the folder had no .htaccess.',
            '#',
            '# Only rules that cannot take the folder down on an unknown Apache: mod_mime\'s',
            '# RemoveHandler, RemoveType and AddType - every file goes out with its own type,',
            '# never one a browser would sniff past - and, where mod_headers is there,',
            '# nosniff (AllowOverride FileInfo, which the web root\'s rewrite rules need',
            '# already). Never php_flag, Options or Require: each can answer 500 for every',
            '# file in the folder on a host whose AllowOverride does not allow it.',
            '',
        ]) . self::guardRules();
    }

    /**
     * The folder's guard, written when it is missing (never over one that is
     * there - a deploy may bring the site's own). Whether the folder has one.
     */
    public static function ensureGuard(string $directory): bool
    {
        $target = rtrim($directory, '/\\') . '/.htaccess';
        if (is_file($target)) {
            return true;
        }
        if (!is_dir($directory)) {
            return false;
        }
        AtomicFile::write($target, self::guardHtaccess(), exclusive: true);

        return is_file($target);
    }

    private static function isImage(string $file, string $extension, string $head): bool
    {
        if ($extension === 'avif') {
            // getimagesize() reads AVIF only on newer builds; the container says it.
            $isAvif = self::isoFile($head) && in_array(substr($head, 8, 4), ['avif', 'avis'], true);
        } else {
            $size = @getimagesize($file);
            $isAvif = false;
            if ($size === false || !in_array($size[2] ?? null, self::IMAGE_TYPES[$extension] ?? [], true)) {
                return false;
            }
        }
        if ($extension === 'avif' && !$isAvif) {
            return false;
        }
        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $type = $finfo === false ? false : finfo_file($finfo, $file);
            // finfo may not know a newer format; it must never call it something else than an image.
            if (is_string($type) && !str_starts_with($type, 'image/') && !in_array($type, ['application/octet-stream', 'application/x-empty'], true)) {
                return false;
            }
        }

        return true;
    }

    private static function hasMagic(string $head, string $extension): bool
    {
        return match ($extension) {
            'ogg', 'oga', 'opus' => str_starts_with($head, 'OggS'),
            'mp3' => str_starts_with($head, 'ID3') || (strlen($head) > 1 && ord($head[0]) === 0xff && (ord($head[1]) & 0xe0) === 0xe0),
            'wav' => str_starts_with($head, 'RIFF') && substr($head, 8, 4) === 'WAVE',
            'm4a', 'mp4' => self::isoFile($head),
            'flac' => str_starts_with($head, 'fLaC'),
            'webm', 'weba' => str_starts_with($head, "\x1A\x45\xDF\xA3"),
            default => false,
        };
    }

    /**
     * An ISO media file (MP4, M4A, AVIF): its first box is ftyp, of a size a ftyp box has - 8 bytes and more,
     * a few dozen in practice - so its first bytes are no markup ("<!--ftypavif" is none).
     */
    private static function isoFile(string $head): bool
    {
        if (strlen($head) < 16 || substr($head, 4, 4) !== 'ftyp') {
            return false;
        }
        $size = unpack('N', substr($head, 0, 4))[1];

        return $size >= 16 && $size <= 1024 && preg_match('/^[\x20-\x7E]{4}$/D', substr($head, 8, 4)) === 1;
    }

    private static function isText(string $file): bool
    {
        $handle = @fopen($file, 'rb');
        if ($handle === false) {
            return false;
        }
        $text = (string) fread($handle, 1048576);
        $more = !feof($handle) && fread($handle, 1) !== '';
        fclose($handle);
        if (str_starts_with($text, "\xEF\xBB\xBF")) {
            $text = substr($text, 3);
        }
        // A cut in the middle of a character at the 1 MB mark is no fault of the file - at the file's end it is.
        for ($trim = 0; $more && $trim < 3 && preg_match('//u', $text) !== 1; $trim++) {
            $text = substr($text, 0, -1);
        }

        return !str_contains($text, "\0") && preg_match('//u', $text) === 1;
    }

    /** One name in NFC, with no folder in it - or null. */
    private static function plainName(string $name): ?string
    {
        $plain = PathGuard::relative(trim($name));

        return $plain === null || $plain === '' || str_contains($plain, '/') ? null : $plain;
    }

    private static function shown(string $name): string
    {
        return substr((string) preg_replace('/[\x00-\x1F\x7F]/', '?', $name), 0, 255);
    }
}
