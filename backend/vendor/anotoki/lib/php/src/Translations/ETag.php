<?php

declare(strict_types=1);

namespace Anotoki\Lib\Translations;

/** The tag of a bundle: what a browser sends back to ask "is it still this?". */
final class ETag
{
    /** The tag of an answer's body: its MD5 in hex, in quotes. */
    public static function of(string $body): string
    {
        return '"' . md5($body) . '"';
    }

    /**
     * Does the browser hold this very answer already? If-None-Match compared the
     * weak way, which is what a conditional GET asks for: any of the tags it
     * lists, or *. A tag comes back as whatever stood on the way made of it, so
     * both marks of a compressed answer are taken off first: W/ in front (nginx
     * and CDNs weaken a tag they compress), and -gzip, -br or -zstd inside the
     * quotes (an Apache that compresses appends the name of the packing -
     * mod_deflate and mod_brotli do, and cannot be told not to from an
     * .htaccess). Without the second, behind such an Apache no browser would ever
     * be told "the same as you have". The tag itself is 32 hex digits, which
     * never end that way on their own.
     */
    public static function notModified(string $ifNoneMatch, string $etag): bool
    {
        foreach (explode(',', $ifNoneMatch) as $candidate) {
            $candidate = trim($candidate);
            if (str_starts_with($candidate, 'W/')) {
                $candidate = substr($candidate, 2);
            }
            if ($candidate === '*' || $candidate === $etag || preg_replace('/-(?:gzip|br|zstd)"$/D', '"', $candidate) === $etag) {
                return true;
            }
        }

        return false;
    }
}
