<?php

/**
 * account-deletions.php - the record of account deletions, and its replay after a restore
 *
 *   php account-deletions.php --list [--since=<time>] [--record=<file>]
 *       the record's events, oldest first: when, what, which account - ids and
 *       times only
 *
 *   php account-deletions.php --replay --since=<the backup's time> [--record=<file>] [--dry-run]
 *       after a backup was put back by hand: every deletion asked for,
 *       cancelled or done since the backup was made, done again in order - a
 *       request with its own times (the thirty days keep their date), a
 *       cancellation, an anonymization run now - then every session, refresh
 *       and personal token, remembered browser and mailed link or code ended:
 *       everybody signs in again. --dry-run says what it would do and changes
 *       nothing.
 *
 * The record is storage/records/account-deletions.jsonl (api/account_deletion.php):
 * a line for every deletion asked for, cancelled and done, written beside the
 * database as each commits - so that a database lost and put back from an older
 * backup does not bring back the people deleted since. --record= reads a copy
 * of it: the one downloaded with the backups, when the server's own is gone.
 * <time> is ISO-8601 (2026-10-13T08:00:00Z, or a date) - the backup's: the time
 * `php backup.php --inspect=<id>` says it was made, or snapshot_at in an
 * extracted backup's manifest.json. The events from an hour before it on are
 * done again: a transaction that began before the backup's snapshot may have
 * committed after it, and one the backup already holds is "already so".
 *
 * It refuses while migrations wait (`php migration.php` first), and works on
 * the configured database (config/database.local.php) - on the owner's
 * computer, a copy of the live IAM's configuration naming the restored
 * database (docs/operations.md, "When the database is lost"). Each change it
 * makes is a line of the record and of the audit log, so it can be run again:
 * the second run changes nothing. The IAM's restore in the admin panel stays
 * off; this is the documented way back.
 *
 * Runs on the command line only, and is never uploaded (build-dist.php leaves
 * it out). It loads the API's code as the tests do (IAM_NO_RUN): an
 * anonymization here is the very one housekeeping runs.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("account-deletions.php is a command-line tool.\n");
}

define('IAM_NO_RUN', true);
require __DIR__ . '/api/index.php';

requireSetUpOnCli();

exit(accountDeletionsCommand(array_slice($argv, 1), STDOUT, STDERR));
