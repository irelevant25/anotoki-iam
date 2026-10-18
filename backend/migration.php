<?php

/**
 * migration.php - builds the IAM database from its migrations, and nothing else
 *
 *   php migration.php              create the database if missing, apply what is pending
 *   php migration.php --status     say what has run and what has not
 *   php migration.php --dry-run    say what would be applied, and apply nothing
 *
 * The migrations are the schema: there is no separate schema file, and a
 * database is what you get by applying 001 and everything after it in order.
 * A file already recorded as applied is never run again, so editing one that
 * has been applied changes nothing where it ran - write another instead.
 *
 * setup.php applies them too, every time it saves, and brings the accounts over
 * or makes the first administrator; this is for the migrations on their own. A
 * fresh database has no accounts - then either bring the old ones over from the
 * genshin site's `users` database -
 *
 *   php import-legacy.php --from=users
 *
 * - or start with a first administrator:
 *
 *   php create-admin.php you@example.com
 *
 * The engine is the anotoki family's (anotoki/lib's Migrator, iamMigrator() in
 * api/migration_runner.php), shared with the admin panel's Migrations page and
 * with setup.php, so a database built any of the three ways is the same
 * database - and only one of them applies at a time. It applies the library's
 * admin set first, then the IAM's files, then the library's translations set:
 * the library's are named here as the table records them,
 * `anotoki_admin/001_....sql`, `anotoki_translations/001_....sql`.
 *
 * As the admin panel's Apply does, it backs the database up first when
 * something was applied before (iamMigratorWithBackup(), storage/backups,
 * encrypted - `php backup.php --list` shows them), and applies nothing when
 * that backup cannot be made; and the audit entries that waited for migration
 * 018 go in after it.
 */

require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/iam.php';
require_once __DIR__ . '/config/secret.php';
require_once __DIR__ . '/api/migration_runner.php';
require_once __DIR__ . '/api/audit.php';
require_once __DIR__ . '/api/admin_tools.php';
require_once __DIR__ . '/api/account_deletion.php';

use Anotoki\Lib\Migrations\MigrationSet;

function colour(string $colour, string $text): string
{
    $codes = ['red' => 31, 'green' => 32, 'yellow' => 33, 'cyan' => 36, 'grey' => 90];

    return "\033[" . ($codes[$colour] ?? 0) . 'm' . $text . "\033[0m";
}

function say(string $colour, string $text): void
{
    echo colour($colour, $text) . "\n";
}

$options = getopt('', ['status', 'dry-run', 'help']);

if (isset($options['help'])) {
    echo file_get_contents(__FILE__, false, null, 0, 1500);
    exit(0);
}

requireSetUpOnCli();

$statusOnly = isset($options['status']);
$dryRun = isset($options['dry-run']);
$config = databaseConfig();
$name = (string) ($config['databases']['iam'] ?? '');

if ($name === '') {
    say('red', "No 'iam' database in config/database.local.php.");
    exit(1);
}

say('cyan', "\n== iam ({$name})");

// Each file is named as it starts and said to be done once the next one
// starts - or the run ends - which is after its transaction committed.
$current = null;
$migrator = null;

try {
    if (!$statusOnly && !$dryRun && ensureDatabase($config, $name)) {
        say('green', '  Database created.');
    }

    $pdo = iamDb();
    $migrator = iamMigratorWithBackup([
        'beforeFile' => static function (PDO $pdo, MigrationSet $set, string $file) use (&$current): void {
            if ($current !== null) {
                say('green', 'done');
            }
            $current = iamMigrationLabel($set->name, $file);
            echo '  ' . $current . ' ... ';
        },
    ]);
    // Read only: --status and --dry-run make nothing, not even the table.
    $status = $migrator->status();
} catch (\Throwable $e) {
    say('red', '  Cannot reach it: ' . $e->getMessage());
    exit(1);
}

$label = static fn(array $file): string => iamMigrationLabel($file['set'], $file['name']);
$files = iamMigrationFiles($migrator);
$pending = array_map($label, $status['pending']);

foreach ($status['missing'] as $record) {
    say('red', '  ! ' . $label($record) . ' is recorded as applied but is not ' . ($record['set'] === iamMigrationSet()->name ? 'in migrations/iam/' : "among the files of the set {$record['set']}"));
}

if ($statusOnly) {
    foreach ($files as $file) {
        $done = !in_array($file, $pending, true);
        echo '  ' . ($done ? colour('green', 'v') : colour('yellow', '.')) . ' ' . $file . ($done ? '' : colour('yellow', '   pending')) . "\n";
    }
    printf("  %d applied, %d pending\n", count($files) - count($pending), count($pending));
    exit(0);
}

if (!$pending) {
    say('green', '  Up to date - ' . count($files) . ' migrations.');
}

if ($dryRun) {
    foreach (array_map($label, $migrator->applicable()) as $file) {
        say('yellow', '  would apply ' . $file);
    }
    exit(0);
}

if ($pending) {
    try {
        $result = $migrator->apply();
    } catch (\Throwable $e) {
        // The connection itself failed: nothing the engine reports as a failed file.
        if ($current !== null) {
            echo "\n";
        }
        say('red', '  ' . $e->getMessage());
        exit(1);
    }

    if ($result['busy']) {
        say('red', '  Another database update is running (the admin panel, or the installer). Nothing was applied - run this again in a moment.');
        exit(1);
    }

    // The backup before the update could not be made: nothing ran (the engine's beforeApply).
    if ($result['failed'] === null && $result['error'] !== null) {
        say('red', '  ' . $result['error']);
        exit(1);
    }

    if ($result['failed'] !== null) {
        // Failed before its first line was written: the file before it committed.
        if ($current !== $label($result['failed'])) {
            if ($current !== null) {
                say('green', 'done');
            }
            echo '  ' . $label($result['failed']) . ' ... ';
        }
        say('red', 'FAILED');
        say('red', '    ' . $result['error']);
        say('yellow', '    Nothing from this file was applied. The migrations before it stand.');
        if ($result['note'] !== null) {
            say('grey', '  ' . $result['note']);
        }
        exit(1);
    }

    if ($current !== null) {
        say('green', 'done');
    }
    // The backup made before anything ran, by its name.
    if ($result['note'] !== null) {
        say('grey', '  ' . $result['note']);
    }

    // The audit entries that waited for 018, with their own times - and an
    // anonymized account's person out of them at once.
    flushWaitingAuditEntries($pdo);
}

if ((int) $pdo->query('SELECT count(*) FROM users')->fetchColumn() === 0) {
    say('yellow', "\n  There are no accounts yet. Either bring the genshin site's over:");
    echo "    php import-legacy.php --from=users\n";
    say('yellow', '  or start with a first administrator:');
    echo "    php create-admin.php you@example.com\n";
}

exit(0);
