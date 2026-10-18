<?php

declare(strict_types=1);

namespace Anotoki\Lib\ErrorLog;

use DateTimeImmutable;
use DateTimeZone;
use Throwable;

/**
 * The error log read back, for the admin page: the newest lines first (each
 * day's files read from their end), at most 2,000 of them, grouped by
 * fingerprint - loudest first.
 *
 * Lines written before the library (genshin's, without `v`) are read as well:
 * their `at` has no zone and was written in the server's own, their address
 * is shown as it is.
 */
final class ErrorLogReader
{
    /** The most lines one answer reads. */
    public const LIMIT = 2000;

    public function __construct(private readonly string $directory, private readonly int $days = 30) {}

    /**
     * The page's answer for the last $filter['days'] days (1-30):
     *
     *   {total, errors, warnings, notices, distinct, truncated, days: [dates with a file],
     *    daily: [{key: date, errors, warnings, notices}], statuses: [{key, errors, warnings, notices}],
     *    groups: [{fingerprint, level, status, type, code, message, file, line, route, source, count,
     *              first_at, last_at, paths: [<=8], latest: entry}], dropped: {date: lines}}
     *
     * Filters: level (error, warning, notice), status, search (in the type, code, message, path, route, file).
     *
     * @param array{days?: int, level?: string, status?: int, search?: string} $filter
     * @return array<string, mixed>
     */
    public function report(array $filter = []): array
    {
        $filter = self::filter($filter, $this->days);
        [$entries, $truncated, $available] = $this->read($filter, null);

        $daily = [];
        for ($i = $filter['days'] - 1; $i >= 0; $i--) {
            $daily[gmdate('Y-m-d', time() - $i * 86400)] = ['errors' => 0, 'warnings' => 0, 'notices' => 0];
        }
        $statuses = [];
        $counts = ['errors' => 0, 'warnings' => 0, 'notices' => 0];
        foreach ($entries as $entry) {
            $bucket = self::bucket((string) $entry['level']);
            $counts[$bucket]++;
            $day = substr((string) $entry['at'], 0, 10);
            if (isset($daily[$day])) {
                $daily[$day][$bucket]++;
            }
            $status = (string) (int) $entry['status'];
            $statuses[$status] ??= ['errors' => 0, 'warnings' => 0, 'notices' => 0];
            $statuses[$status][$bucket]++;
        }
        ksort($statuses, SORT_NUMERIC);
        $tally = static function (array $rows): array {
            $out = [];
            foreach ($rows as $key => $row) {
                $out[] = ['key' => (string) $key] + $row;
            }

            return $out;
        };
        $groups = self::group($entries);

        return [
            'total' => count($entries),
            'errors' => $counts['errors'],
            'warnings' => $counts['warnings'],
            'notices' => $counts['notices'],
            'distinct' => count($groups),
            'truncated' => $truncated,
            'days' => $available,
            'daily' => $tally($daily),
            'statuses' => $tally($statuses),
            'groups' => $groups,
            'dropped' => $this->dropped(),
        ];
    }

    /**
     * Every line of one fingerprint in the range, newest first (at most 2,000).
     *
     * @param array{days?: int, level?: string, status?: int, search?: string} $filter
     * @return list<array<string, mixed>>
     */
    public function occurrences(string $fingerprint, array $filter = []): array
    {
        if (preg_match('/^[0-9a-f]{12}$/D', $fingerprint) !== 1) {
            return [];
        }

        return $this->read(self::filter($filter, $this->days), $fingerprint)[0];
    }

    /**
     * Deletes the log's files - every one (and the count of dropped lines), or with $before (a UTC
     * date, 'Y-m-d') only the days before it, so the newest stay: "older than today". Answers how
     * many files went; a $before that is no date deletes nothing (-1).
     */
    public function clear(?string $before = null): int
    {
        if ($before !== null && !self::isDay($before)) {
            return -1;
        }
        $gone = 0;
        foreach (glob($this->directory . '/errors-*.jsonl') ?: [] as $file) {
            if ($before !== null && (preg_match('/^errors-(\d{4}-\d{2}-\d{2})[.]/', basename($file), $match) !== 1 || $match[1] >= $before)) {
                continue;
            }
            if (is_file($file) && @unlink($file)) {
                $gone++;
            }
        }
        // A day's count of lines of each kind (ErrorLog's perFingerprintDay) goes with the day.
        foreach (glob($this->directory . '/errors-*.counts.json') ?: [] as $file) {
            if ($before === null || (preg_match('/^errors-(\d{4}-\d{2}-\d{2})\./', basename($file), $match) === 1 && $match[1] < $before)) {
                @unlink($file);
            }
        }

        $dropped = $this->directory . '/errors-dropped.json';
        if ($before === null) {
            @unlink($dropped);
        } elseif (is_file($dropped)) {
            $kept = array_filter($this->dropped(), static fn(string $day): bool => $day >= $before, ARRAY_FILTER_USE_KEY);
            if ($kept === []) {
                @unlink($dropped);
            } else {
                @file_put_contents($dropped, (string) json_encode($kept), LOCK_EX);
            }
        }

        return $gone;
    }

    /** A UTC date as the log's files are named: 'Y-m-d', a real one. */
    public static function isDay(string $day): bool
    {
        return preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $day, $parts) === 1 && checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1]);
    }

    /** @return array<string, int> lines not written - the log was full, or their kind had its lines for the day - by UTC date */
    public function dropped(): array
    {
        $counts = json_decode((string) @file_get_contents($this->directory . '/errors-dropped.json'), true);
        if (!is_array($counts)) {
            return [];
        }
        $out = [];
        foreach ($counts as $day => $count) {
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/D', (string) $day) === 1 && is_int($count) && $count > 0) {
                $out[(string) $day] = $count;
            }
        }
        krsort($out);

        return $out;
    }

    /**
     * @param array{days: int, level: string, status: int, search: string} $filter
     * @return array{list<array<string, mixed>>, bool, list<string>} the entries newest first, whether more were left
     *         unread, and the dates that have a file
     */
    private function read(array $filter, ?string $fingerprint): array
    {
        $files = [];
        foreach (glob($this->directory . '/errors-*.jsonl') ?: [] as $file) {
            if (preg_match('/^errors-(\d{4}-\d{2}-\d{2})(?:\.(\d+|overflow))?\.jsonl$/D', basename($file), $match) === 1) {
                $part = ($match[2] ?? '') === '' ? 1 : ($match[2] === 'overflow' ? 1000 : (int) $match[2]);
                $files[] = ['day' => $match[1], 'part' => $part, 'file' => $file];
            }
        }
        // Newest day first; within a day, the last part first.
        usort($files, static fn(array $a, array $b): int => [$b['day'], $b['part']] <=> [$a['day'], $a['part']]);

        $available = array_values(array_unique(array_column($files, 'day')));
        // A legacy day is the server's local date: a day more, so its last hours are not left out.
        $cutoff = gmdate('Y-m-d', time() - $filter['days'] * 86400);
        $from = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->modify('-' . ($filter['days'] - 1) . ' days')->format('Y-m-d') . 'T00:00:00';

        $entries = [];
        foreach ($files as $file) {
            if ($file['day'] < $cutoff) {
                continue;
            }
            $lines = @file($file['file'], FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
            for ($i = count($lines) - 1; $i >= 0; $i--) {
                $entry = self::entry($lines[$i]);
                if ($entry === null || $entry['at'] < $from) {
                    continue;
                }
                if ($fingerprint !== null && $entry['fingerprint'] !== $fingerprint) {
                    continue;
                }
                if (!self::matches($entry, $filter)) {
                    continue;
                }
                if (count($entries) === self::LIMIT) {
                    return [self::newestFirst($entries), true, $available];
                }
                $entries[] = $entry;
            }
        }

        return [self::newestFirst($entries), false, $available];
    }

    /**
     * One line as an entry of the current shape - a legacy line (no `v`) brought to it.
     *
     * @return ?array<string, mixed>
     */
    private static function entry(string $line): ?array
    {
        $data = json_decode($line, true);
        if (!is_array($data)) {
            return null;
        }
        $legacy = !isset($data['v']);

        $entry = [
            'v' => $legacy ? 0 : (int) $data['v'],
            'at' => $legacy ? self::legacyTime($data['at'] ?? null) : (is_string($data['at'] ?? null) ? $data['at'] : ''),
            'source' => is_string($data['source'] ?? null) ? $data['source'] : 'server',
            'level' => in_array($data['level'] ?? null, ['error', 'warning', 'notice'], true) ? $data['level'] : 'error',
            'status' => (int) ($data['status'] ?? 0),
            'type' => (string) ($data['type'] ?? ''),
            'code' => isset($data['code']) && is_scalar($data['code']) ? (string) $data['code'] : null,
            'message' => (string) ($data['message'] ?? ''),
            'file' => isset($data['file']) ? (string) $data['file'] : null,
            'line' => isset($data['line']) ? (int) $data['line'] : null,
            'method' => isset($data['method']) ? (string) $data['method'] : null,
            'route' => isset($data['route']) ? (string) $data['route'] : null,
            'path' => isset($data['path']) ? (string) $data['path'] : null,
            'user_id' => isset($data['user_id']) && (is_int($data['user_id']) || is_string($data['user_id'])) ? $data['user_id'] : null,
            'client' => isset($data['client']) ? (string) $data['client'] : null,
            'trace' => self::traceOf($data['trace'] ?? null),
            'fingerprint' => (string) ($data['fingerprint'] ?? ''),
        ];
        if ($legacy) {
            // What genshin's lines carried that the library's do not: shown as it was written.
            $entry['ip'] = isset($data['ip']) && is_string($data['ip']) ? $data['ip'] : null;
        }
        if ($entry['at'] === '' || preg_match('/^[0-9a-f]{12}$/D', $entry['fingerprint']) !== 1) {
            return null;
        }

        return $entry;
    }

    /** @return ?list<string> */
    private static function traceOf(mixed $trace): ?array
    {
        if (is_string($trace)) {
            return array_values(array_filter(explode("\n", $trace), static fn(string $line): bool => $line !== ''));
        }
        if (is_array($trace)) {
            return array_values(array_map('strval', array_filter($trace, 'is_scalar')));
        }

        return null;
    }

    /** genshin's 'Y-m-d H:i:s', written in the server's own zone, as ISO 8601 UTC. */
    private static function legacyTime(mixed $at): string
    {
        if (!is_string($at) || preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', $at) !== 1) {
            return '';
        }
        try {
            return (new DateTimeImmutable($at, new DateTimeZone(date_default_timezone_get())))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.v\Z');
        } catch (Throwable) {
            return '';
        }
    }

    /** @param array{days: int, level: string, status: int, search: string} $filter */
    private static function matches(array $entry, array $filter): bool
    {
        if ($filter['level'] !== '' && $entry['level'] !== $filter['level']) {
            return false;
        }
        if ($filter['status'] !== 0 && $entry['status'] !== $filter['status']) {
            return false;
        }
        if ($filter['search'] !== '') {
            $haystack = strtolower(implode("\n", array_map('strval', [$entry['type'], $entry['code'], $entry['message'], $entry['path'], $entry['route'], $entry['file']])));
            if (!str_contains($haystack, strtolower($filter['search']))) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<string, mixed> $filter
     * @return array{days: int, level: string, status: int, search: string}
     */
    private static function filter(array $filter, int $keep): array
    {
        $days = is_int($filter['days'] ?? null) ? $filter['days'] : (int) ($filter['days'] ?? 7);
        $level = $filter['level'] ?? '';
        $status = $filter['status'] ?? 0;

        return [
            'days' => max(1, min($keep, $days)),
            'level' => in_array($level, ['error', 'warning', 'notice'], true) ? $level : '',
            'status' => is_int($status) || (is_string($status) && ctype_digit($status)) ? (int) $status : 0,
            'search' => is_string($filter['search'] ?? null) ? trim($filter['search']) : '',
        ];
    }

    /**
     * Entries sharing a fingerprint as one row, loudest first, then the latest.
     *
     * @param list<array<string, mixed>> $entries newest first
     * @return list<array<string, mixed>>
     */
    private static function group(array $entries): array
    {
        $groups = [];
        foreach ($entries as $entry) {
            $key = $entry['fingerprint'];
            if (!isset($groups[$key])) {
                $groups[$key] = [
                    'fingerprint' => $key,
                    'level' => $entry['level'],
                    'status' => $entry['status'],
                    'type' => $entry['type'],
                    'code' => $entry['code'],
                    'message' => $entry['message'],
                    'file' => $entry['file'],
                    'line' => $entry['line'],
                    'route' => $entry['route'],
                    'source' => $entry['source'],
                    'count' => 0,
                    'first_at' => $entry['at'],
                    'last_at' => $entry['at'],
                    'paths' => [],
                    // Newest first: the first seen is the latest.
                    'latest' => $entry,
                ];
            }
            $group = &$groups[$key];
            $group['count']++;
            if ($entry['at'] < $group['first_at']) {
                $group['first_at'] = $entry['at'];
            }
            if ($entry['at'] > $group['last_at']) {
                $group['last_at'] = $entry['at'];
            }
            $path = (string) ($entry['path'] ?? '');
            if ($path !== '') {
                $group['paths'][$path] = ($group['paths'][$path] ?? 0) + 1;
            }
            unset($group);
        }

        foreach ($groups as &$group) {
            arsort($group['paths']);
            $group['paths'] = array_map('strval', array_slice(array_keys($group['paths']), 0, 8));
        }
        unset($group);

        $groups = array_values($groups);
        usort($groups, static fn(array $a, array $b): int => [$b['count'], $b['last_at']] <=> [$a['count'], $a['last_at']]);

        return $groups;
    }

    /**
     * @param list<array<string, mixed>> $entries
     * @return list<array<string, mixed>>
     */
    private static function newestFirst(array $entries): array
    {
        usort($entries, static fn(array $a, array $b): int => strcmp((string) $b['at'], (string) $a['at']));

        return $entries;
    }

    private static function bucket(string $level): string
    {
        return match ($level) {
            'warning' => 'warnings',
            'notice' => 'notices',
            default => 'errors',
        };
    }
}
