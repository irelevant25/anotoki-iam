<?php

declare(strict_types=1);

namespace Anotoki\Lib\People;

use Anotoki\Lib\Migrations\MigrationSet;

/**
 * The people module's own tables - where the scrub stands, and the ids it
 * scrubbed - as the library's migration set `anotoki_people`
 * (backend/migrations/people). It names no table of a site's: the site's own
 * migration adds users.anonymized_at, before this set runs.
 *
 *   new Migrator($pdo, [...$siteSets, \Anotoki\Lib\People\Schema::migrationSet()])
 */
final class Schema
{
    /** The set's name: what the bookkeeping table and the Migrations page call it. */
    public const SET = 'anotoki_people';

    public static function directory(): string
    {
        return dirname(__DIR__, 2) . '/migrations/people';
    }

    public static function migrationSet(): MigrationSet
    {
        return MigrationSet::library(self::SET, self::directory());
    }
}
