<?php

/**
 * The IAM's migrations, run by the anotoki family's engine: anotoki/lib's
 * Migrator (vendor/anotoki/lib, namespace Anotoki\Lib\Migrations), which every
 * anotoki site runs - so a migration reads and applies the same in each
 * repository, and an update waiting behaves the same on each site.
 *
 * Here: the IAM's one set of files, migrations/iam, recorded in the
 * `migrations` table as it always was (id, filename, applied_at - the engine
 * reads and writes that shape as it is, and never changes it). The command
 * line (migration.php), the installer (setup.php) and the admin panel's
 * Migrations page all apply through iamMigrator(), which is the point of there
 * being one description of the schema. Each file runs in a transaction of its
 * own with its record, the run stops at the first file that fails, one apply
 * runs at a time (the engine's advisory lock), and nothing is ever un-applied:
 * the migrations that matter are irreversible anyway, and a rollback that half
 * works is worse than none.
 *
 * Beside it, the two things every way of building a database needs and the
 * engine does not do: making the database itself (ensureDatabase()), and the
 * first administrator of an empty one (createFirstAdmin()).
 */

// The library comes with Composer's autoloader. The installer and the command
// line tools load this file without the API's front controller, so it brings
// the autoloader itself.
require_once __DIR__ . '/../vendor/autoload.php';

use Anotoki\Lib\Migrations\Http\SiteState;
use Anotoki\Lib\Migrations\MigrationSet;
use Anotoki\Lib\Migrations\Migrator;

/** migrations/iam: the IAM's one set of files, labelled `iam` on the Migrations page and in its answers. */
function iamMigrationSet(): MigrationSet
{
    return new MigrationSet('iam', dirname(__DIR__) . '/migrations/iam');
}

/**
 * The engine on a connection (which must throw on errors - every connection
 * connectDatabase() makes does). `$options` are the Migrator's own (its
 * hooks); the IAM passes none - its tests do, to build a database up to a
 * file and no further.
 */
function iamMigrator(PDO $pdo, array $options = []): Migrator
{
    return new Migrator($pdo, [iamMigrationSet()], $options);
}

/**
 * Where the IAM stands, decided afresh whenever it is asked - by
 * /api/site-status, and by the family's gate in front of every route
 * (api/index.php) while the IAM is not set up:
 *
 * - not_set_up while config/*.local.php are missing or wrong
 *   (iamSetUpProblems()); the installer is offered only while
 *   storage/installed.lock - which a real host's install writes - is not
 *   there, so an IAM installed once never sends anybody to a setup.php that
 *   deleted itself;
 * - update_pending while a file of migrations/iam waits to be applied;
 * - unavailable while the database cannot be reached;
 * - ready otherwise.
 *
 * The IAM does not block while an update waits (blockWhilePending false),
 * unlike every other anotoki site: it is the door every site signs in through,
 * and its administrator has to sign in through it to press Apply. Its features
 * check their own tables meanwhile, as they always have (signInRulesReady(),
 * translationsReady(), accountApprovalReady(), announcementsReady(),
 * preferencesReady(), accountDeletionReady(), ...), and its administrators
 * see a line in the admin panel saying an update waits.
 */
function iamSiteState(): SiteState
{
    static $state = null;

    return $state ??= new SiteState(
        settingsProblem: static function (): ?string {
            $problems = iamSetUpProblems();

            return $problems === [] ? null : implode('; ', $problems);
        },
        installed: static fn(): bool => is_file(iamStorageDir() . '/installed.lock'),
        migrator: static fn(): Migrator => iamMigrator(iamDb()),
        blockWhilePending: false,
    );
}

/**
 * Makes the database when it is not there, and answers whether it had to.
 *
 * On most shared hosting the application's database user cannot CREATE
 * DATABASE at all; there it is made in the control panel first and this finds
 * it already there.
 */
function ensureDatabase(array $config, string $name): bool
{
    $server = new PDO(
        "pgsql:host={$config['host']};port={$config['port']};dbname=postgres;connect_timeout=5",
        (string) $config['username'],
        (string) ($config['password'] ?? ''),
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    $exists = (int) $server->query('SELECT count(*) FROM pg_catalog.pg_database WHERE datname = ' . $server->quote($name))->fetchColumn();

    if ($exists > 0) {
        return false;
    }

    // The name is the deployment's own configuration, never anything a request
    // reaches, and CREATE DATABASE takes no parameters: quoting it as an
    // identifier is the whole of what can be done.
    $server->exec('CREATE DATABASE "' . str_replace('"', '""', $name) . '"');

    return true;
}

/**
 * The first administrator of a fresh installation, holding ADMIN in every
 * application that has one - the IAM's and every site's (the owner's rule: the
 * setup's administrator administers everything; migration 007 gives the same
 * to administrators that were there before). Only when there are no accounts
 * at all, so it is a way to start and never a way in. A missing password is
 * generated for the caller to show once; the account is then flagged to choose
 * its own at the first sign-in.
 *
 * @return array{username: string, email: string, password: string, generated: bool}|null
 */
function createFirstAdmin(PDO $pdo, string $email, ?string $password = null, ?string $username = null): ?array
{
    if ((int) $pdo->query('SELECT count(*) FROM users')->fetchColumn() > 0) {
        return null;
    }

    $generated = $password === null || $password === '';
    $password = $generated ? bin2hex(random_bytes(12)) : $password;
    $username = $username ?: (strstr($email, '@', true) ?: 'admin');

    $pdo->beginTransaction();
    try {
        $insert = $pdo->prepare(
            'INSERT INTO users (username, email, password, email_confirmed, force_password_change) VALUES (?, ?, ?, TRUE, ?) RETURNING id'
        );
        $insert->execute([$username, $email, password_hash($password, PASSWORD_DEFAULT), $generated ? 'true' : 'false']);
        $userId = (int) $insert->fetchColumn();

        $pdo->prepare(
            "INSERT INTO user_roles (user_id, role_id)
             SELECT ?, r.id FROM application_roles r WHERE r.name = 'ADMIN'"
        )->execute([$userId]);

        $pdo->commit();
    } catch (\Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    return ['username' => $username, 'email' => $email, 'password' => $password, 'generated' => $generated];
}
