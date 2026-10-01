<?php

/**
 * translations.php - the IAM's own strings, from the command line
 *
 *   php translations.php --status            the keys the frontend uses against the database's, and how
 *                                            much of each language there is; exit 1 when a key is missing
 *                                            or has no English string
 *   php translations.php --export sk [file]  a language's own strings as flat JSON (to the screen without a file)
 *   php translations.php --import sk file    and back in: checked as a save in the admin console is, all of
 *                                            it or none of it; an empty string is passed over
 *
 * For a computer with a shell and the whole repository: it is never uploaded
 * (build-dist.php leaves the command-line tools out), and the live IAM's
 * strings are edited in the admin console. Export them from there before a
 * release that changes strings.
 *
 * The strings live in the database so they can be reworded without a build,
 * which means the database and the frontend can drift: a key no page asks for
 * any more, a page asking for one no migration wrote. --status is the check
 * for that; the same scan runs in the tests (tests/translation_keys.php says
 * how a key is recognised, tests/translations.test.php holds the database to it).
 *
 * An import is written by nobody (updated_by NULL), as a migration's strings
 * are, and recorded in the audit log with no actor.
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
require_once __DIR__ . '/tests/translation_keys.php';

function colour(string $colour, string $text): string
{
    $codes = ['red' => 31, 'green' => 32, 'yellow' => 33, 'cyan' => 36];

    return "\033[" . ($codes[$colour] ?? 0) . 'm' . $text . "\033[0m";
}

function say(string $colour, string $text): void
{
    echo colour($colour, $text) . "\n";
}

/** Says why not, on the error output, and ends with 1. */
function refuse(string $text): never
{
    fwrite(STDERR, colour('red', $text) . "\n");
    exit(1);
}

/** What the API would have answered: the sentence, and the keys it is about. Ends with 1. */
function refused(array $refusal): never
{
    fwrite(STDERR, colour('red', '  Refused (' . $refusal['code'] . '): ' . $refusal['error']) . "\n");
    foreach ($refusal['extra']['keys'] ?? [] as $key) {
        fwrite(STDERR, "    $key\n");
    }
    exit(1);
}

/** The frontend's keys against the database's, and how much of each language there is. */
function commandStatus(PDO $pdo): int
{
    $root = translationKeysFrontendRoot();
    if (!is_dir($root)) {
        refuse('There is no frontend here to read the keys from (frontend/src/app).');
    }

    $keys = $pdo->query('SELECT name FROM translation_keys ORDER BY name COLLATE "C"')->fetchAll(PDO::FETCH_COLUMN);
    $mailKeys = array_values(array_filter($keys, static fn(string $key): bool => translationKeyNamespace($key) === TRANSLATION_SERVER_NAMESPACE));
    $scan = translationKeysScan($root, $keys);

    $noEnglish = $pdo->prepare(
        'SELECT k.name FROM translation_keys k
          WHERE NOT EXISTS (SELECT 1 FROM translations t WHERE t.key_name = k.name AND t.language_code = ?)
          ORDER BY k.name COLLATE "C"'
    );
    $noEnglish->execute([TRANSLATION_FALLBACK]);
    $noEnglish = $noEnglish->fetchAll(PDO::FETCH_COLUMN);

    // The mails' keys are the code's list (MAIL_KEYS), not the frontend's.
    $mailMissing = array_values(array_diff(array_keys(MAIL_KEYS), $mailKeys));
    $mailUnknown = array_values(array_diff($mailKeys, array_keys(MAIL_KEYS)));

    printf("  the database has     %d key%s (%d of the pages, %d of the mails)\n", count($keys), count($keys) === 1 ? '' : 's', count($keys) - count($mailKeys), count($mailKeys));
    printf("  the frontend uses    %d of the pages' keys\n", count($scan['used']));

    if ($scan['missing']) {
        say('red', "\n  Used by the frontend, not in the database - the page shows the key itself:");
        foreach ($scan['missing'] as $key => $where) {
            echo "    $key\n";
            foreach (array_unique($where) as $place) {
                echo "        $place\n";
            }
        }
    }
    if ($mailMissing) {
        say('red', "\n  A mail's string the database does not have - it goes out in the English kept in the code:");
        foreach ($mailMissing as $key) {
            echo "    $key\n";
        }
    }
    if ($noEnglish) {
        say('red', "\n  No English string, so nothing to fall back to:");
        foreach ($noEnglish as $key) {
            echo "    $key\n";
        }
    }
    if ($scan['problems']) {
        say('red', "\n  What the scan does not let through:");
        foreach ($scan['problems'] as $problem) {
            echo "    $problem\n";
        }
    }
    if ($scan['unused'] || $mailUnknown) {
        say('yellow', "\n  In the database, used nowhere - a migration deletes a key the code has stopped using:");
        foreach (array_merge($scan['unused'], $mailUnknown) as $key) {
            echo "    $key\n";
        }
    }

    echo "\n";
    $languages = $pdo->query(
        'SELECT l.code, l.name, l.enabled, (SELECT count(*) FROM translations t WHERE t.language_code = l.code) AS strings
           FROM languages l ORDER BY l.sort_order, l.name, l.code'
    )->fetchAll();
    foreach ($languages as $language) {
        printf("  %-6s %-20s %4d / %d%s\n", $language['code'], $language['name'], $language['strings'], count($keys), asBool($language['enabled']) ? '' : '   (not offered)');
    }
    echo "\n";

    if ($scan['missing'] || $mailMissing || $noEnglish || $scan['problems']) {
        say('red', '  Not clean.');

        return 1;
    }
    say('green', '  Clean.');

    return 0;
}

/** A language's own strings - not merged with English - to a file, or to the screen. */
function commandExport(PDO $pdo, string $code, ?string $file): int
{
    $result = translationsExport($pdo, $code);
    if (translationRefused($result)) {
        refused($result);
    }

    $values = (array) $result['values'];
    if ($file === null) {
        echo translationFileText($values);

        return 0;
    }
    translationFileWrite($file, $values);
    say('green', '  ' . count($values) . ' string' . (count($values) === 1 ? '' : 's') . " of \"$code\" written to $file");

    return 0;
}

/** A file of strings into a language: checked like a save in the admin console, written by nobody. */
function commandImport(PDO $pdo, string $code, string $file): int
{
    $values = translationFileRead($file);
    $result = translationsImport($pdo, $code, $values, null);
    if (translationRefused($result)) {
        refused($result);
    }

    if ($result['count'] > 0) {
        audit(null, null, 'translations.imported', 'language', $code, $result + ['via' => 'command line']);
    }
    say('green', '  ' . count($values) . ' string' . (count($values) === 1 ? '' : 's') . " in $file: {$result['count']} changed in \"$code\", the rest as they were (or empty, and passed over).");

    return 0;
}

$command = $argv[1] ?? '--status';
$usage = 'php translations.php --status | --export CODE [FILE] | --import CODE FILE';

if (in_array($command, ['--help', '-h'], true)) {
    echo $usage . "\n\n"
        . "  --status            the keys the frontend uses against the database's; exit 1 when one is missing or has no English string\n"
        . "  --export CODE       a language's own strings as flat JSON, to FILE or to the screen\n"
        . "  --import CODE FILE  and back in: checked as a save in the admin console is, all of it or none of it\n";
    exit(0);
}

requireSetUpOnCli();

try {
    $pdo = iamDb();
    $ready = translationsReady($pdo);
} catch (Throwable $e) {
    refuse('Cannot reach the database: ' . $e->getMessage());
}
if (!$ready) {
    refuse('This database has no strings yet: apply the migrations first (php migration.php).');
}

try {
    switch ($command) {
        case '--status':
            say('cyan', "\n== iam: the IAM's own strings\n");
            exit(commandStatus($pdo));

        case '--export':
            if (!isset($argv[2])) {
                refuse('usage: php translations.php --export CODE [FILE]');
            }
            exit(commandExport($pdo, (string) $argv[2], $argv[3] ?? null));

        case '--import':
            if (!isset($argv[2], $argv[3])) {
                refuse('usage: php translations.php --import CODE FILE');
            }
            exit(commandImport($pdo, (string) $argv[2], (string) $argv[3]));

        default:
            refuse("Unknown command $command. $usage");
    }
} catch (RuntimeException $e) {
    refuse('  ' . $e->getMessage());
}
