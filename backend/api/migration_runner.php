<?php

/**
 * The migration engine, with no opinion on who is driving.
 *
 * Reads php/migrations/{alias}/, applies the files a database has not seen,
 * and records each in its `migrations` table. It prints nothing and reads no
 * request, so the CLI (migration.php), the admin panel's "Apply pending" and
 * the first-run installer build a database the same way - which is the point of
 * there being one description of the schema. The same engine as the genshin
 * site's, so a migration reads the same in either repository.
 *
 * Nothing here un-applies anything: the migrations that matter are
 * irreversible anyway, and a rollback that half works is worse than none.
 */

function migrationsRoot(): string
{
    return dirname(__DIR__) . '/migrations';
}

/**
 * Splits a SQL file into statements. A semicolon only ends one outside a quoted
 * string, a dollar-quoted block - how every function and DO block is written -
 * and a comment.
 */
function splitSqlStatements(string $sql): array
{
    $statements = [];
    $current = '';
    $length = strlen($sql);
    $i = 0;

    while ($i < $length) {
        $character = $sql[$i];

        if ($character === '/' && ($sql[$i + 1] ?? '') === '*') {
            $end = strpos($sql, '*/', $i + 2);
            $i = $end === false ? $length : $end + 2;
            continue;
        }

        if ($character === '-' && ($sql[$i + 1] ?? '') === '-') {
            $end = strpos($sql, "\n", $i + 2);
            $i = $end === false ? $length : $end + 1;
            continue;
        }

        if ($character === '$') {
            $j = $i + 1;
            while ($j < $length && $sql[$j] !== '$' && (ctype_alnum($sql[$j]) || $sql[$j] === '_')) {
                $j++;
            }

            if (($sql[$j] ?? '') === '$') {
                $tag = substr($sql, $i, $j - $i + 1);
                $end = strpos($sql, $tag, $j + 1);

                if ($end !== false) {
                    $current .= substr($sql, $i, $end - $i + strlen($tag));
                    $i = $end + strlen($tag);
                    continue;
                }
            }
        }

        if ($character === "'") {
            $current .= $character;
            $i++;

            while ($i < $length) {
                $current .= $sql[$i];

                if ($sql[$i] === "'") {
                    if (($sql[$i + 1] ?? '') === "'") {
                        $current .= $sql[++$i];
                    } else {
                        $i++;
                        break;
                    }
                }

                $i++;
            }

            continue;
        }

        if ($character === ';') {
            if (trim($current) !== '') {
                $statements[] = trim($current);
            }
            $current = '';
            $i++;
            continue;
        }

        $current .= $character;
        $i++;
    }

    if (trim($current) !== '') {
        $statements[] = trim($current);
    }

    return $statements;
}

/** The files for one database, in apply order - natural order, so 100 does not sort before 11. */
function migrationFiles(string $alias): array
{
    $directory = migrationsRoot() . '/' . $alias;

    if (!is_dir($directory)) {
        return [];
    }

    $files = array_map('basename', glob($directory . '/*.sql') ?: []);
    natsort($files);

    return array_values($files);
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

function ensureMigrationsTable(PDO $pdo): void
{
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS migrations (
            id         SERIAL          PRIMARY KEY,
            filename   VARCHAR(255)    NOT NULL UNIQUE,
            applied_at TIMESTAMP       DEFAULT CURRENT_TIMESTAMP
        )'
    );
}

function appliedMigrations(PDO $pdo): array
{
    return $pdo->query('SELECT filename FROM migrations')->fetchAll(PDO::FETCH_COLUMN);
}

function pendingMigrations(PDO $pdo, string $alias): array
{
    ensureMigrationsTable($pdo);

    return array_values(array_diff(migrationFiles($alias), appliedMigrations($pdo)));
}

/**
 * Applies one file, all of it or none of it. PostgreSQL takes DDL inside a
 * transaction, and the row saying the file ran is written in the same one.
 */
function applyMigration(PDO $pdo, string $alias, string $filename): void
{
    $statements = splitSqlStatements((string) file_get_contents(migrationsRoot() . '/' . $alias . '/' . $filename));

    $pdo->beginTransaction();

    try {
        foreach ($statements as $index => $statement) {
            try {
                $pdo->exec($statement);
            } catch (PDOException $e) {
                throw new RuntimeException(
                    'statement ' . ($index + 1) . ' of ' . count($statements) . ': ' . $e->getMessage()
                    . "\n\n  " . implode("\n  ", array_slice(explode("\n", $statement), 0, 6))
                );
            }
        }

        $pdo->prepare('INSERT INTO migrations (filename) VALUES (?) ON CONFLICT DO NOTHING')->execute([$filename]);
        $pdo->commit();
    } catch (\Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

/**
 * Every pending file, stopping at the first failure - each migration is written
 * expecting the ones before it to have run.
 *
 * @return array{applied: string[], failed: ?string, error: ?string}
 */
function applyPendingMigrations(PDO $pdo, string $alias): array
{
    $applied = [];

    foreach (pendingMigrations($pdo, $alias) as $filename) {
        try {
            applyMigration($pdo, $alias, $filename);
            $applied[] = $filename;
        } catch (\Throwable $e) {
            return ['applied' => $applied, 'failed' => $filename, 'error' => $e->getMessage()];
        }
    }

    return ['applied' => $applied, 'failed' => null, 'error' => null];
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
