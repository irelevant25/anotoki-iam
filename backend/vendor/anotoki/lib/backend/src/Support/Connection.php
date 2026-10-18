<?php

declare(strict_types=1);

namespace Anotoki\Lib\Support;

use PDO;
use Throwable;

/**
 * Whether the database ended a connection - as PostgreSQL ends a restore's that
 * sat idle inside its transaction past its limit (Backups) - told apart from a
 * statement that failed on a connection still there. A connection ended so took
 * its transaction with it: rolled back - unless its COMMIT was on its way.
 *
 * libpq marks a connection bad once a statement found it closed (pdo_pgsql
 * says "Bad connection."); a transaction PDO still calls open says nothing
 * then, and a rollback on it fails.
 *
 * @internal
 */
final class Connection
{
    /** Whether $pdo's connection is gone - asked once more with a statement of no effect when it seems there. */
    public static function lost(PDO $pdo): bool
    {
        if (self::bad($pdo)) {
            return true;
        }
        try {
            $pdo->query('SELECT 1');
        } catch (Throwable) {
            // Refused on a connection still there (a transaction in error): that says nothing more.
        }

        return self::bad($pdo);
    }

    private static function bad(PDO $pdo): bool
    {
        try {
            return $pdo->getAttribute(PDO::ATTR_CONNECTION_STATUS) === 'Bad connection.';
        } catch (Throwable) {
            return false;
        }
    }
}
