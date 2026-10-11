<?php

declare(strict_types=1);

namespace Anotoki\Lib\Support;

use Closure;

/**
 * Where the library writes a line for whoever keeps the site: PHP's error log,
 * unless the site (or a test) sends the lines elsewhere. Only what an operator
 * acts on goes here - a token refused for a reason worth knowing, an IAM that
 * did not answer, a folder that cannot be written - never a token, a key or
 * anything a person typed.
 */
final class Log
{
    private static ?Closure $sink = null;

    public static function say(string $line): void
    {
        if (self::$sink !== null) {
            (self::$sink)($line);

            return;
        }
        error_log($line);
    }

    /** Where the lines go from now on: fn(string $line): void, or null for PHP's error log. */
    public static function to(?Closure $sink): void
    {
        self::$sink = $sink;
    }
}
