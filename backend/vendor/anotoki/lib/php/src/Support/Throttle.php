<?php

declare(strict_types=1);

namespace Anotoki\Lib\Support;

/**
 * "At most once per interval, one process at a time" - for the housekeeping
 * that rides on ordinary requests (no host has cron): a prune, a sweep.
 *
 * A stamp file holds when the work last ran. due() answers true to exactly one
 * caller per interval: it takes the file's lock without waiting (a process
 * that finds it taken answers false at once - another is claiming it this
 * moment), reads the stamp, and writes the new one before it answers true. So
 * the work is claimed before it runs, and a failure waits for the next
 * interval instead of being retried by every request.
 */
final class Throttle
{
    /**
     * True for the one caller that may run the work now (the stamp is written then); false for every other. A
     * stamp later than now is none: a clock set back would otherwise hold the work off until it caught up.
     */
    public static function due(string $stampFile, int $seconds): bool
    {
        return self::claim($stampFile, static fn (string $stamp): bool => !ctype_digit($stamp) || (int) $stamp <= time() - $seconds || (int) $stamp > time(), (string) time());
    }

    /**
     * True once per $period - a key such as the UTC date: the first caller that
     * finds another key (or none) in the stamp gets true, and writes $period.
     */
    public static function once(string $stampFile, string $period): bool
    {
        return self::claim($stampFile, static fn (string $stamp): bool => $stamp !== $period, $period);
    }

    /** @param \Closure(string): bool $isDue */
    private static function claim(string $stampFile, \Closure $isDue, string $next): bool
    {
        $directory = dirname($stampFile);
        if (!is_dir($directory) && !GuardedDirectory::ensure($directory)) {
            return false;
        }
        $handle = @fopen($stampFile, 'c+');
        if ($handle === false) {
            return false;
        }

        try {
            if (!flock($handle, LOCK_EX | LOCK_NB)) {
                return false;
            }
            $stamp = trim((string) stream_get_contents($handle));
            if (!$isDue($stamp)) {
                return false;
            }
            ftruncate($handle, 0);
            rewind($handle);
            fwrite($handle, $next . "\n");
            fflush($handle);

            return true;
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }
}
