<?php

declare(strict_types=1);

namespace Anotoki\Lib\Admin;

use Anotoki\Lib\Migrations\MigrationSet;
use PDO;

/**
 * The admin tools' tables - anotoki_audit_log (001) and anotoki_feedback (002) -
 * and the library's migration set that makes them (php/migrations/admin).
 *
 * The set runs FIRST in a site's list, before the site's own sets, in every
 * place that builds its Migrator (the site, its installer, its command line):
 * `new Migrator($pdo, [Schema::migrationSet(), ...$siteSets, ...])`. The
 * sites' own migrations build on it - they move their old rows into its
 * tables - and nothing else makes an anotoki_* table, so it never meets one of
 * a site's. The error log and the backups need no table at all.
 *
 * While a file of it waits (a build is uploaded before its migrations are
 * applied), the modules ask afresh whether their table is there (has(), never
 * memoised) and do without: the audit log keeps its entries in a file until
 * the table comes (AuditLog::flushPending()), feedback answers 503
 * feature_unavailable.
 */
final class Schema
{
    /** The set's name: what the bookkeeping table and the Migrations page call it. */
    public const SET = 'anotoki_admin';

    public const AUDIT_TABLE = 'anotoki_audit_log';
    public const FEEDBACK_TABLE = 'anotoki_feedback';

    public static function directory(): string
    {
        return dirname(__DIR__, 2) . '/migrations/admin';
    }

    public static function migrationSet(): MigrationSet
    {
        return MigrationSet::library(self::SET, self::directory());
    }

    /**
     * Is the table there (in the connection's schema)? Asked afresh every time:
     * one PHP process may serve a request before Apply and one after it. A query
     * that cannot fail, so it is safe inside a transaction too.
     */
    public static function has(PDO $pdo, string $table): bool
    {
        $statement = $pdo->prepare('SELECT to_regclass(?) IS NOT NULL');
        $statement->execute([$table]);
        $there = $statement->fetchColumn();

        return $there === true || $there === 't' || $there === 1 || $there === '1';
    }
}
