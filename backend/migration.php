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
 * The engine lives in api/migration_runner.php, shared with the admin panel's
 * "Apply pending" button and with setup.php, so a database built any of the
 * three ways is the same database.
 */

require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/api/migration_runner.php';

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

try {
    if (!$statusOnly && !$dryRun && ensureDatabase($config, $name)) {
        say('green', '  Database created.');
    }

    $pdo = iamDb();
    ensureMigrationsTable($pdo);
    $applied = appliedMigrations($pdo);
} catch (\Throwable $e) {
    say('red', '  Cannot reach it: ' . $e->getMessage());
    exit(1);
}

$files = migrationFiles('iam');
$pending = array_values(array_diff($files, $applied));

foreach (array_diff($applied, $files) as $file) {
    say('red', "  ! {$file} is recorded as applied but is not in migrations/iam/");
}

if ($statusOnly) {
    foreach ($files as $file) {
        $done = in_array($file, $applied, true);
        echo '  ' . ($done ? colour('green', 'v') : colour('yellow', '.')) . ' ' . $file . ($done ? '' : colour('yellow', '   pending')) . "\n";
    }
    printf("  %d applied, %d pending\n", count($files) - count($pending), count($pending));
    exit(0);
}

if (!$pending) {
    say('green', '  Up to date - ' . count($files) . ' migrations.');
}

foreach ($pending as $file) {
    if ($dryRun) {
        say('yellow', '  would apply ' . $file);
        continue;
    }

    echo '  ' . $file . ' ... ';

    try {
        applyMigration($pdo, 'iam', $file);
        say('green', 'done');
    } catch (\Throwable $e) {
        say('red', 'FAILED');
        say('red', '    ' . $e->getMessage());
        say('yellow', '    Nothing from this file was applied. The migrations before it stand.');
        exit(1);
    }
}

if (!$dryRun && (int) $pdo->query('SELECT count(*) FROM users')->fetchColumn() === 0) {
    say('yellow', "\n  There are no accounts yet. Either bring the genshin site's over:");
    echo "    php import-legacy.php --from=users\n";
    say('yellow', '  or start with a first administrator:');
    echo "    php create-admin.php you@example.com\n";
}

exit(0);
