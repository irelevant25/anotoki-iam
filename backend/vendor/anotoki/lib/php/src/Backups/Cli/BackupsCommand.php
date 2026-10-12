<?php

declare(strict_types=1);

namespace Anotoki\Lib\Backups\Cli;

use Anotoki\Lib\Backups\Backups;
use Anotoki\Lib\Backups\Copy;
use Anotoki\Lib\Support\Refusal;
use Throwable;

/**
 * The backups on the command line - a site's backup.php in a few lines, run on
 * the owner's computer or wherever there is a shell (never uploaded):
 *
 *   php backup.php --list
 *   php backup.php --create ["description"]
 *   php backup.php --inspect=<id>               the tables and their rows, from the manifest alone, in memory (an
 *                                               IAM backup's first chunks decrypted with the site's key, no more)
 *   php backup.php --extract=<id> <folder>      everything into a new folder, decrypted and checked:
 *                                               manifest.json, schema/<set>/<file>.sql, data/NNN-<table>.copy -
 *                                               and how to load it into another database (psql \copy)
 *   php backup.php --restore=<id> --confirm=<the id's last 6 characters>
 *                                               in place, as the admin page restores (where the site allows it)
 *
 * What a command line does needs no proof - a shell is the owner's already - and
 * is written by nobody in the audit log. So it runs on the command line alone:
 * a backup.php uploaded by mistake and opened in a browser does nothing.
 */
final class BackupsCommand
{
    /** @var ?resource */
    private $out;

    /** @var ?resource */
    private $err;

    /**
     * @param ?resource $out
     * @param ?resource $err
     * @param string $sapi  PHP's interface (PHP_SAPI); a test says another
     */
    public function __construct(private readonly Backups $backups, $out = null, $err = null, private readonly string $sapi = PHP_SAPI)
    {
        $this->out = $out ?? (defined('STDOUT') ? STDOUT : null);
        $this->err = $err ?? (defined('STDERR') ? STDERR : null);
    }

    /**
     * @param list<string> $arguments  the command line's, after the script's name
     * @return int the exit code
     */
    public function run(array $arguments): int
    {
        // Before anything else: no proof is asked here, so nothing but a shell may ask.
        if ($this->sapi !== 'cli' && $this->sapi !== 'phpdbg') {
            if ($this->err !== null) {
                fwrite($this->err, "The backups' command line runs on the command line alone.\n");
            }

            return 1;
        }
        $first = $arguments[0] ?? '';
        try {
            return match (true) {
                $first === '--list' => $this->list(),
                $first === '--create' => $this->create($arguments[1] ?? null),
                str_starts_with($first, '--inspect=') => $this->inspect(substr($first, 10)),
                str_starts_with($first, '--extract=') => $this->extract(substr($first, 10), $arguments[1] ?? ''),
                str_starts_with($first, '--restore=') => $this->restore(substr($first, 10), self::option($arguments, '--confirm=')),
                default => $this->usage(),
            };
        } catch (Throwable $e) {
            fwrite($this->err, 'Failed: ' . $e->getMessage() . "\n");

            return 1;
        }
    }

    private function list(): int
    {
        $backups = $this->backups->list();
        if ($backups === []) {
            fwrite($this->out, "No backups.\n");

            return 0;
        }
        foreach ($backups as $backup) {
            fwrite($this->out, sprintf(
                "%s  %-16s %10s  %s%s%s\n",
                $backup['id'],
                $backup['kind'],
                self::size((int) $backup['size']),
                ($backup['encrypted'] ?? false) ? 'encrypted' : 'plain',
                $backup['description'] !== null ? '  ' . $backup['description'] : '',
                ($backup['stale'] ?? false) ? '  (old: delete it?)' : '',
            ));
        }

        return 0;
    }

    private function create(?string $description): int
    {
        $backup = $this->backups->create(Backups::MANUAL, $description);
        if ($backup instanceof Refusal) {
            fwrite($this->err, $backup->message . "\n");

            return 1;
        }
        fwrite($this->out, 'Made ' . $backup['id'] . ' (' . self::size((int) $backup['size']) . ', ' . $backup['table_count'] . ' tables, ' . $backup['rows'] . " rows).\n");

        return 0;
    }

    private function inspect(string $id): int
    {
        // The manifest alone, in memory: not a row of the database is decrypted, nothing is written anywhere.
        $manifest = $this->backups->manifest($id);
        fwrite($this->out, sprintf("%s  %s, made %s by %s%s\n", $manifest['id'], $manifest['kind'], $manifest['created_at'], $manifest['created_by'] ?? 'nobody', $manifest['description'] !== null ? ' - ' . $manifest['description'] : ''));
        fwrite($this->out, 'Migrations applied: ' . count($manifest['schema']['migrations']) . "\n");
        foreach ($manifest['tables'] as $table) {
            fwrite($this->out, sprintf("  %-40s %10d rows %10s\n", $table['name'], $table['rows'], self::size((int) $table['bytes'])));
        }

        return 0;
    }

    private function extract(string $id, string $folder): int
    {
        if ($folder === '' || file_exists($folder)) {
            fwrite($this->err, "Give a folder that is not there yet: it is made, and holds the backup decrypted.\n");

            return 1;
        }
        $manifest = $this->backups->extractTo($id, $folder);
        fwrite($this->out, "Read whole and checked into $folder.\n\n");
        fwrite($this->out, "To load it into an empty database: apply schema/ in this order (the migrations it had applied),\n");
        foreach ($manifest['schema']['migrations'] as $migration) {
            fwrite($this->out, '  ' . $migration['set'] . '/' . $migration['name'] . "\n");
        }
        fwrite($this->out, "then, in psql from $folder, the data - parents first:\n");
        foreach ($manifest['tables'] as $table) {
            $columns = implode(', ', array_map(Copy::quote(...), $table['columns']));
            fwrite($this->out, '  \\copy ' . Copy::quote((string) ($manifest['database_schema'] ?? 'public')) . '.' . Copy::quote($table['name']) . " ($columns) FROM '" . $table['file'] . "'\n");
        }
        fwrite($this->out, "and set every sequence as the manifest says (SELECT setval(...)).\n");

        return 0;
    }

    private function restore(string $id, ?string $confirm): int
    {
        if ($confirm === null || !hash_equals(substr($id, -6), $confirm)) {
            fwrite($this->err, "Say --confirm= and the backup's last six characters to restore it: everything written since it goes.\n");

            return 1;
        }
        $result = $this->backups->restore($id);
        if ($result instanceof Refusal) {
            fwrite($this->err, $result->message . "\n");

            return 1;
        }
        fwrite($this->out, 'Restored ' . $result['tables'] . ' tables, ' . $result['rows'] . ' rows. What was there before is ' . $result['before_restore'] . ".\n");
        if ($result['after_restore_error'] !== null) {
            fwrite($this->err, "The site's work after the restore failed: " . $result['after_restore_error'] . "\n");

            return 1;
        }

        return 0;
    }

    private function usage(): int
    {
        fwrite($this->err, "Use: --list | --create [description] | --inspect=<id> | --extract=<id> <folder> | --restore=<id> --confirm=<last 6>\n");

        return 2;
    }

    /** @param list<string> $arguments */
    private static function option(array $arguments, string $prefix): ?string
    {
        foreach ($arguments as $argument) {
            if (str_starts_with($argument, $prefix)) {
                return substr($argument, strlen($prefix));
            }
        }

        return null;
    }

    private static function size(int $bytes): string
    {
        return match (true) {
            $bytes >= 1048576 => round($bytes / 1048576, 1) . ' MB',
            $bytes >= 1024 => round($bytes / 1024, 1) . ' KB',
            default => $bytes . ' B',
        };
    }
}
