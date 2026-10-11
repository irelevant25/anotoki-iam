<?php

/**
 * translations.php - the IAM's own strings, from the command line
 *
 *   php translations.php --status            the keys the frontend uses against the database's, and how
 *                                            much of each language there is; then the mails' keys against
 *                                            MAIL_KEYS. Exit 1 when a key is missing, has no English
 *                                            string, or the scan finds a problem
 *   php translations.php --export sk [file]  a language's own strings as flat JSON (to the screen without a file)
 *   php translations.php --import sk file    and back in: checked as a save in the admin panel is, all of
 *                                            it or none of it; an empty string is passed over
 *
 * The anotoki family's command (anotoki/lib's TranslationsCommand), on the
 * IAM's configuration (api/translations.php): the library's checks of a
 * string with the IAM's mail rules, and the family's scan of a frontend set
 * for the IAM's (iamKeyScanner(): the `t` pipe, the mails' keys left out,
 * innerHTML anywhere refused). It refuses while the strings' tables are not
 * there or a migration waits: a database that needs an update does not have
 * the release's strings yet - `php migration.php` first.
 *
 * For a computer with a shell and the whole repository: it is never uploaded
 * (build-dist.php leaves the command-line tools out), and the live IAM's
 * strings are edited in the admin panel. Export them from there before a
 * release that changes strings.
 *
 * The strings live in the database so they can be reworded without a build,
 * which means the database and the frontend can drift: a key no page asks for
 * any more, a page asking for one no migration wrote. --status is the check
 * for that; tests/translations.test.php holds the database to the same scan.
 *
 * An import is written by nobody (updated_by NULL), as a migration's strings
 * are, and recorded in the audit log with no actor, `via: command line`.
 *
 * It works on the database in config/database.local.php, which setup.php writes.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("translations.php is a command-line tool.\n");
}

require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/iam.php';
require_once __DIR__ . '/api/helpers.php';
require_once __DIR__ . '/api/audit.php';
require_once __DIR__ . '/api/mail.php';
require_once __DIR__ . '/api/translations.php';
require_once __DIR__ . '/api/migration_runner.php';

use Anotoki\Lib\Translations\Cli\TranslationsCommand;

/** Whether a migration waits: its files, by the IAM's names for them. */
function pendingMigrationLabels(): array
{
    return array_map(static fn(array $file): string => iamMigrationLabel($file['set'], $file['name']), iamMigrator(iamDb())->applicable());
}

/**
 * The mails' keys against MAIL_KEYS - the code's list, which no frontend
 * scan sees. A mail key the database lacks goes out in the English kept in
 * the code (MAIL_FALLBACK): not clean. One the code does not know is used by
 * no mail: a migration deletes it.
 */
function mailKeysStatus(PDO $pdo, bool $colours): int
{
    // Written where the library's command writes, so the two come out in order.
    $write = static fn(string $text) => fwrite(STDOUT, $text);
    $say = static function (string $colour, string $text) use ($colours, $write): void {
        $codes = ['red' => 31, 'green' => 32, 'yellow' => 33, 'cyan' => 36];
        $write(($colours ? "\033[" . $codes[$colour] . 'm' . $text . "\033[0m" : $text) . "\n");
    };

    $statement = $pdo->prepare('SELECT name FROM translation_keys WHERE name LIKE ? ORDER BY name COLLATE "C"');
    $statement->execute([TRANSLATION_SERVER_NAMESPACE . '.%']);
    $mailKeys = $statement->fetchAll(PDO::FETCH_COLUMN);
    $missing = array_values(array_diff(array_keys(MAIL_KEYS), $mailKeys));
    $unknown = array_values(array_diff($mailKeys, array_keys(MAIL_KEYS)));

    $say('cyan', "\n== iam: the mails' strings\n");
    $write(sprintf("  the code has         %d mail keys (MAIL_KEYS)\n", count(MAIL_KEYS)));
    $write(sprintf("  the database has     %d of them\n", count(MAIL_KEYS) - count($missing)));
    if ($missing !== []) {
        $say('red', "\n  A mail's string the database does not have - it goes out in the English kept in the code:");
        foreach ($missing as $key) {
            $write("    $key\n");
        }
    }
    if ($unknown !== []) {
        $say('yellow', "\n  In the database, used by no mail - a migration deletes a key the code has stopped using:");
        foreach ($unknown as $key) {
            $write("    $key\n");
        }
    }
    $write("\n");
    $say($missing === [] ? 'green' : 'red', $missing === [] ? '  Clean.' : '  Not clean.');

    return $missing === [] ? 0 : 1;
}

$command = $argv[1] ?? '--status';
$colours = function_exists('stream_isatty') && @stream_isatty(STDOUT);

// --help answers with nothing set up; everything else needs the configuration.
if (!in_array($command, ['--help', '-h'], true)) {
    requireSetUpOnCli();
}

$translations = new TranslationsCommand(
    static fn() => iamTranslations(),
    iamKeyScanner(),
    static function (string $action, ?string $type, ?string $id, array $details): void {
        auditTranslations(null, null, $action, $type, $id, $details);
    },
    pendingMigrationLabels(...),
    'iam: the IAM\'s own strings',
    colours: $colours,
);
$status = $translations->run($argv);

// After the pages' keys, the mails' - on a database the command read: ready,
// and with nothing pending (it said why not otherwise, and stopped).
if ($command === '--status' && count($argv) <= 2) {
    try {
        $pdo = iamDb();
        if (translationsReady($pdo) && pendingMigrationLabels() === []) {
            $status = max($status, mailKeysStatus($pdo, $colours));
        }
    } catch (Throwable) {
        // The command has said it could not reach the database.
    }
}

exit($status);
