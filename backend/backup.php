<?php

/**
 * backup.php - the IAM's database backups, from the command line
 *
 *   php backup.php --list                       this IAM's backups (storage/backups), newest first
 *   php backup.php --create ["description"]     one made now - by nobody, in the audit log
 *   php backup.php --inspect=<id>               its header, its tables and their rows
 *   php backup.php --extract=<id> <folder>      everything into a new folder, decrypted and checked,
 *                                               and how to load it into an empty database (psql \copy)
 *
 * A backup downloaded from another IAM - the live one, from its admin panel's
 * Backups page - opens only with that IAM's master key (the copy of its
 * config/secret.local.php kept off the server):
 *
 *   php backup.php --from=<the folder it is in> --key=<that secret.local.php> --inspect=<id>
 *   php backup.php --from=<the folder it is in> --key=<that secret.local.php> --extract=<id> <folder>
 *
 * The anotoki family's command (anotoki/lib's BackupsCommand) on the IAM's
 * backups (iamBackups(), api/admin_tools.php): encrypted under a key derived
 * from the master key, and never restored in place - `--restore` is refused
 * here as on the Backups page (docs/decisions.md, 2026-10-11). What is
 * extracted is in the clear: every account's password hash, the two-factor
 * secrets and signing keys (still under the master key), every hash of a
 * session and a token - a folder of the owner's own, removed when done.
 * `--inspect` decrypts the whole database too, into a folder under the system's
 * temp folder that the library removes when it is done - and that a run stopped
 * half-way (Ctrl-C, a kill) leaves behind: only on a computer you trust, and
 * delete what an interrupted run left there (docs/operations.md, "Backups").
 *
 * Runs on the command line only, and is never uploaded (build-dist.php leaves
 * it out): a shell is the owner's already, so it asks no password.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("backup.php is a command-line tool.\n");
}

require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/iam.php';
require_once __DIR__ . '/config/secret.php';
require_once __DIR__ . '/api/admin_tools.php';

use Anotoki\Lib\Backups\Backups;
use Anotoki\Lib\Backups\Cli\BackupsCommand;
use Anotoki\Lib\Migrations\Migrator;
use Anotoki\Lib\Support\Keys;

requireSetUpOnCli();

$arguments = array_slice($argv, 1);
$option = static function (string $name) use (&$arguments): ?string {
    foreach ($arguments as $index => $argument) {
        if (str_starts_with($argument, "--{$name}=")) {
            unset($arguments[$index]);

            return substr($argument, strlen($name) + 3);
        }
    }

    return null;
};
$from = $option('from');
$keyFile = $option('key');
$arguments = array_values($arguments);

if ($from === null && $keyFile === null) {
    exit((new BackupsCommand(iamBackups()))->run($arguments));
}

// Another IAM's backup: read where it lies, with that IAM's key - and only read.
if ($from === null || $keyFile === null) {
    fwrite(STDERR, "Give both: --from=<the folder the backup is in> and --key=<that IAM's secret.local.php>.\n");
    exit(2);
}
if (!preg_match('/^--(list|inspect=|extract=)/', $arguments[0] ?? '')) {
    fwrite(STDERR, "Another IAM's backups are only read here: --list, --inspect=<id> or --extract=<id> <folder>.\n");
    exit(2);
}
if (!is_dir($from)) {
    fwrite(STDERR, "There is no folder {$from}.\n");
    exit(1);
}

// The key is read out of the file, not run: the one 64-hex string a secret.local.php returns.
$text = is_file($keyFile) ? (string) file_get_contents($keyFile) : '';
if (preg_match_all('/(?<![0-9a-fA-F])[0-9a-fA-F]{64}(?![0-9a-fA-F])/', $text, $found) !== 1) {
    fwrite(STDERR, "{$keyFile} is no master key: a secret.local.php holds one string of 64 hex characters.\n");
    exit(1);
}

$elsewhere = new Backups(static fn(): PDO => iamBackupConnection(), rtrim($from, '/\\'), [
    'site' => 'iam',
    'migrator' => static fn(PDO $pdo): Migrator => iamMigrator($pdo),
    'encryption' => Keys::derive((string) hex2bin($found[0][0]), 'iam-backup'),
    'restore' => false,
    // Nothing of this IAM's is written: no storage of its own for the tickets and stamps either.
    'storage' => sys_get_temp_dir() . '/anotoki-iam-backup-' . bin2hex(random_bytes(6)),
]);

exit((new BackupsCommand($elsewhere))->run($arguments));
