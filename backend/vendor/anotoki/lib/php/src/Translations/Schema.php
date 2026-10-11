<?php

declare(strict_types=1);

namespace Anotoki\Lib\Translations;

use Anotoki\Lib\Migrations\MigrationSet;
use PDO;

/**
 * The tables of the words - `languages`, `translation_keys`, `translations`,
 * the family's one schema - and the library's migration set that makes them
 * where they are missing, proves their shape where they are there, and adds
 * the library's own words (php/migrations/translations).
 *
 * A site adds the set to its Migrator after its own:
 * `new Migrator($pdo, [...$siteSets, Schema::migrationSet()])` - the sites' own
 * early migrations made these tables themselves, so on an installed site the
 * library's first file finds them and only checks them.
 */
final class Schema
{
    /** The set's name: what the bookkeeping table and the Migrations page call it. */
    public const SET = 'anotoki_translations';

    public static function directory(): string
    {
        return dirname(__DIR__, 2) . '/migrations/translations';
    }

    public static function migrationSet(): MigrationSet
    {
        return MigrationSet::library(self::SET, self::directory());
    }

    /**
     * Are the three tables there? A build is uploaded before its migrations are
     * applied, and the words are asked for on a database that has not heard of
     * them yet. A query that cannot fail - so it is safe inside a transaction too.
     */
    public static function ready(PDO $pdo): bool
    {
        $ready = $pdo->query(
            "SELECT to_regclass('languages') IS NOT NULL
                AND to_regclass('translation_keys') IS NOT NULL
                AND to_regclass('translations') IS NOT NULL"
        )->fetchColumn();

        return $ready === true || $ready === 't' || $ready === 1 || $ready === '1';
    }
}
