<?php

/**
 * import-legacy.php - bring the genshin site's accounts into this IAM, once
 *
 *   php import-legacy.php [--from=users] [--dry-run] [--site=genshin]
 *
 * `--from` is the name of the old `users` database on the same PostgreSQL
 * server (and with the same credentials) as the IAM's own; without it, the one
 * config/database.local.php names as 'legacy' (setup.php writes that when one
 * is entered). `--dry-run` does the whole import inside a transaction and
 * rolls it back, so the counts and any conflict show without anything being
 * written.
 *
 * What is copied and why is in api/legacy_import.php. The short version: every
 * account with its id, password, Google link, two-factor and recovery codes;
 * roles become per-application roles (ADMIN becomes ADMIN in the IAM and in
 * every site); the old sessions become history. People sign in once more, with the same password.
 *
 * Run it before the genshin site's migration that removes the old tables -
 * that migration waits for the marker this writes. On a server with no shell,
 * setup.php offers the same import during the install.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("import-legacy.php is a command-line tool.\n");
}

require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/iam.php';
require_once __DIR__ . '/config/secret.php';
require_once __DIR__ . '/api/helpers.php';
require_once __DIR__ . '/api/crypto.php';
require_once __DIR__ . '/api/applications.php';
require_once __DIR__ . '/api/audit.php';
require_once __DIR__ . '/api/legacy_import.php';

requireSetUpOnCli();

$options = getopt('', ['from:', 'dry-run', 'site:']);
$from = trim((string) ($options['from'] ?? (databaseConfig()['databases']['legacy'] ?? '')));

if ($from === '') {
    fwrite(
        STDERR,
        "usage: php import-legacy.php --from=<old users database> [--dry-run] [--site=genshin]\n" . "(or name it as 'legacy' under 'databases' in config/database.local.php - setup.php does)\n",
    );
    exit(1);
}

try {
    // Fail before touching anything if there is no master key: the two-factor
    // secrets are encrypted with it on the way in.
    iamMasterKey();

    $legacy = legacyConnect(databaseConfig(), $from);
    $summary = importLegacyAccounts(iamDb(), $legacy, (string) ($options['site'] ?? 'genshin'), isset($options['dry-run']));
} catch (\Throwable $e) {
    fwrite(STDERR, 'Not imported: ' . $e->getMessage() . "\n");
    exit(1);
}

echo $summary['dry_run'] ? "Dry run - nothing was written.\n" : "Imported.\n";
printf("  accounts        %d\n", $summary['accounts']);
printf("  Google links    %d\n", $summary['identities']);
printf("  recovery codes  %d\n", $summary['recovery_codes']);
printf("  sessions        %d (as history)\n", $summary['sessions']);
printf("  sign-in tries   %d\n", $summary['login_attempts']);
printf("  mail log        %d\n", $summary['emails']);
foreach ($summary['roles'] as $role => $count) {
    printf("  role %-10s %d\n", $role, $count);
}
echo "  old timestamps read in time zone {$summary['time_zone']}\n";

if (!$summary['dry_run']) {
    echo "\nThe old database now carries the iam_handover marker, so the genshin site's\n";
    echo "migration that removes its account tables will run.\n";
}
