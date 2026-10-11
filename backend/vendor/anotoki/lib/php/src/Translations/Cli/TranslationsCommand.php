<?php

declare(strict_types=1);

namespace Anotoki\Lib\Translations\Cli;

use Anotoki\Lib\Support\Refusal;
use Anotoki\Lib\Translations\TranslationFile;
use Anotoki\Lib\Translations\Translations;
use Closure;
use RuntimeException;
use Throwable;

/**
 * A site's words from the command line - for a computer with a shell and the
 * whole repository; a site never uploads it (the live site's strings are edited
 * in its admin pages). Each site keeps a short translations.php that builds
 * this and runs it:
 *
 *   php translations.php [--status]           the keys the frontend uses against the database's, and how
 *                                             much of each language there is; exit 1 when a key is missing,
 *                                             has no English string, or the scan finds a problem
 *   php translations.php --export CODE [FILE] a language's own strings as flat JSON, to FILE or the screen
 *                                             (--export=CODE too)
 *   php translations.php --import CODE FILE   and back in: checked as a save in the admin pages is, all of
 *                                             it or none of it; an empty string is passed over (--import=CODE)
 *
 * It refuses while the tables are not there or a migration is waiting: the
 * strings of a database that needs an update are not the release's yet. An
 * import is written by nobody (updated_by NULL), as a migration's strings are,
 * and audited with `via: command line`.
 */
final class TranslationsCommand
{
    private const USAGE = 'php translations.php --status | --export CODE [FILE] | --import CODE FILE';

    /** @var resource */
    private $out;

    /** @var resource */
    private $err;

    private readonly bool $colours;

    /**
     * @param Translations|Closure(): Translations $translations
     * @param ?Closure $audit    fn(string $action, ?string $type, ?string $id, array $details): void - an import's entry
     * @param ?Closure $pending  fn(): list<string> - the migration files waiting (the site's Migrator::applicable())
     * @param string   $title    the heading of --status: "survey: the site's own strings"
     * @param resource|null $out where it writes (STDOUT)
     * @param resource|null $err where it says why not (STDERR)
     * @param ?Closure $read     fn(string $path, string $code): array - the strings an import takes from a file, for
     *                           a site that also imports its own kind of file (Japanese Academy's whole-site backup);
     *                           TranslationFile::read() by default
     */
    public function __construct(
        private readonly Translations|Closure $translations,
        private readonly KeyScanner $scanner,
        private readonly ?Closure $audit = null,
        private readonly ?Closure $pending = null,
        private readonly string $title = 'the site\'s own strings',
        $out = null,
        $err = null,
        ?bool $colours = null,
        private readonly ?Closure $read = null,
    ) {
        $this->out = $out ?? STDOUT;
        $this->err = $err ?? STDERR;
        $this->colours = $colours ?? (function_exists('stream_isatty') && @stream_isatty($this->out));
    }

    /**
     * Runs one command and answers the exit code.
     *
     * @param list<string> $argv  as PHP gives them: the script first
     */
    public function run(array $argv): int
    {
        $arguments = array_slice(array_values($argv), 1);
        $command = $arguments[0] ?? '--status';

        if (in_array($command, ['--help', '-h'], true)) {
            $this->write(self::USAGE . "\n\n"
                . "  --status            the keys the frontend uses against the database's; exit 1 when one is missing, has no\n"
                . "                      English string, or is asked for in a way the scan does not let through\n"
                . "  --export CODE       a language's own strings as flat JSON, to FILE or to the screen\n"
                . "  --import CODE FILE  and back in: checked as a save in the admin pages is, all of it or none of it\n");

            return 0;
        }

        // --export=sk and --export sk are both a code; what follows is the file.
        $code = null;
        if (preg_match('/^--(export|import)=(.*)$/sD', $command, $match) === 1) {
            $command = '--' . $match[1];
            $code = $match[2];
            $rest = array_slice($arguments, 1);
        } else {
            $code = $arguments[1] ?? null;
            $rest = array_slice($arguments, 2);
        }
        if (!in_array($command, ['--status', '--export', '--import'], true)) {
            return $this->refuse("Unknown command $command. " . self::USAGE);
        }
        if ($command === '--status' && count($arguments) > 1) {
            return $this->refuse('usage: ' . self::USAGE);
        }

        try {
            $translations = $this->translations instanceof Closure ? ($this->translations)() : $this->translations;
            $ready = $translations->ready();
            $pending = $this->pending === null ? [] : ($this->pending)();
        } catch (Throwable $e) {
            return $this->refuse('Cannot reach the database: ' . $e->getMessage());
        }
        if (!$ready) {
            return $this->refuse('This database has no strings yet: apply the migrations first (php migration.php).');
        }
        if ($pending !== []) {
            return $this->refuse('The database needs an update first (' . implode(', ', $pending) . '): php migration.php');
        }

        try {
            return match ($command) {
                '--status' => $this->status($translations),
                '--export' => $code === null || $code === '' ? $this->refuse('usage: php translations.php --export CODE [FILE]') : $this->export($translations, $code, $rest[0] ?? null),
                '--import' => $code === null || $code === '' || !isset($rest[0]) ? $this->refuse('usage: php translations.php --import CODE FILE') : $this->import($translations, $code, $rest[0]),
            };
        } catch (RuntimeException $e) {
            return $this->refuse('  ' . $e->getMessage());
        }
    }

    /** The frontend's keys against the database's, and how much of each language there is. */
    private function status(Translations $translations): int
    {
        $this->say('cyan', "\n== {$this->title}\n");

        $keys = $translations->keyNames();
        $scan = $this->scanner->scan($keys);
        $noEnglish = $translations->keysWithout(Translations::FALLBACK);
        $refusals = $translations->storedRefusals();

        $this->write(sprintf("  the database has     %d key%s\n", count($keys), count($keys) === 1 ? '' : 's'));
        $this->write(sprintf("  the frontend uses    %d of them\n", count($scan['used'])));

        if ($scan['missing'] !== []) {
            $this->say('red', "\n  Used by the frontend, not in the database - the page shows the key itself:");
            foreach ($scan['missing'] as $key => $where) {
                $this->write("    $key\n");
                foreach (array_unique($where) as $place) {
                    $this->write("        $place\n");
                }
            }
        }
        if ($noEnglish !== []) {
            $this->say('red', "\n  No English string, so nothing to fall back to:");
            foreach ($noEnglish as $key) {
                $this->write("    $key\n");
            }
        }
        if ($scan['problems'] !== []) {
            $this->say('red', "\n  What the scan does not let through:");
            foreach ($scan['problems'] as $problem) {
                $this->write("    $problem\n");
            }
        }
        if ($scan['unused'] !== []) {
            $this->say('yellow', "\n  In the database, used nowhere - a migration deletes a key the code has stopped using:");
            foreach ($scan['unused'] as $key) {
                $this->write("    $key\n");
            }
        }
        if ($refusals !== []) {
            $this->say('yellow', "\n  Stored as a save would not take them - reword them in the admin pages:");
            foreach ($refusals as $refusal) {
                $this->write("    {$refusal['key']} ({$refusal['language']}): {$refusal['code']} - {$refusal['message']}\n");
            }
        }

        $this->write("\n");
        foreach ($translations->languages() as $language) {
            $this->write(sprintf("  %-6s %-20s %4d / %d%s\n", $language['code'], $language['name'], $language['strings'], count($keys), $language['enabled'] ? '' : '   (not offered)'));
        }
        $this->write("\n");

        if ($scan['missing'] !== [] || $noEnglish !== [] || $scan['problems'] !== []) {
            $this->say('red', '  Not clean.');

            return 1;
        }
        $this->say('green', '  Clean.');

        return 0;
    }

    /** A language's own strings - not merged with English - to a file, or to the screen. */
    private function export(Translations $translations, string $code, ?string $file): int
    {
        $result = $translations->export($code);
        if ($result instanceof Refusal) {
            return $this->refused($result);
        }

        $values = (array) $result['values'];
        if ($file === null) {
            $this->write(TranslationFile::text($values));

            return 0;
        }
        TranslationFile::write($file, $values);
        $this->say('green', '  ' . count($values) . ' string' . (count($values) === 1 ? '' : 's') . " of \"$code\" written to $file");

        return 0;
    }

    /** A file of strings into a language: checked like a save in the admin pages, written by nobody. */
    private function import(Translations $translations, string $code, string $file): int
    {
        $values = $this->read !== null ? ($this->read)($file, $code) : TranslationFile::read($file);
        $result = $translations->import($code, $values, null);
        if ($result instanceof Refusal) {
            return $this->refused($result);
        }

        if ($this->audit !== null && $result['count'] > 0) {
            ($this->audit)('translations.imported', 'language', $code, $result + ['via' => 'command line']);
        }
        $this->say('green', '  ' . count($values) . ' string' . (count($values) === 1 ? '' : 's')
            . " in $file: {$result['count']} changed in \"$code\", the rest as they were (or empty, and passed over).");

        return 0;
    }

    /** What the API would have answered: the sentence, and the keys it is about. */
    private function refused(Refusal $refusal): int
    {
        $this->error('  Refused (' . $refusal->code . '): ' . $refusal->message);
        foreach ($refusal->extra['keys'] ?? [] as $key) {
            fwrite($this->err, "    $key\n");
        }

        return 1;
    }

    /** Says why not, on the error output; the exit code is 1. */
    private function refuse(string $text): int
    {
        $this->error($text);

        return 1;
    }

    private function error(string $text): void
    {
        fwrite($this->err, $this->colour('red', $text) . "\n");
    }

    private function say(string $colour, string $text): void
    {
        $this->write($this->colour($colour, $text) . "\n");
    }

    private function write(string $text): void
    {
        fwrite($this->out, $text);
    }

    private function colour(string $colour, string $text): string
    {
        if (!$this->colours) {
            return $text;
        }
        $codes = ['red' => 31, 'green' => 32, 'yellow' => 33, 'cyan' => 36];

        return "\033[" . ($codes[$colour] ?? 0) . 'm' . $text . "\033[0m";
    }
}
