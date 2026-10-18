<?php

declare(strict_types=1);

namespace Anotoki\Lib\Backups;

use PDO;
use RuntimeException;
use Throwable;

/**
 * PostgreSQL's COPY through PDO - the backups' way in and out of a table
 * without pg_dump (no host has it): COPY ... TO STDOUT into a file, and
 * COPY ... FROM STDIN out of one, in PostgreSQL's text format (one row a line;
 * tabs, newlines and backslashes escaped; \N for NULL - loadable by psql's
 * \copy anywhere). It needs no superuser and no shell, streams row by row
 * (memory stays flat), and carries every type the sites use as it is: BYTEA,
 * JSONB, arrays, NULLs.
 *
 * PHP 8.4 gave the driver its own class: on a Pdo\Pgsql connection (opened
 * with PDO::connect() or connect() below) the calls are copyToFile() and
 * copyFromFile(). On an older PHP, or a plain PDO, they are the PDO methods
 * pgsqlCopyToFile() and pgsqlCopyFromFile() - which PHP 8.5 deprecates; the
 * deprecation is silenced here, so open the backups' connection with
 * connect() where PHP has the class.
 */
final class Copy
{
    /** The text format's NULL, as the PHP calls take it (the SQL string E'\\N'). */
    private const NULL_AS = '\\\\N';

    /**
     * A connection the backups can COPY on: a Pdo\Pgsql (PDO::connect()) where PHP has the class,
     * else a PDO - with PDO::ERRMODE_EXCEPTION.
     *
     * @param array<int, mixed> $options
     */
    public static function connect(string $dsn, ?string $username = null, ?string $password = null, array $options = []): PDO
    {
        $options += [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION];
        if (class_exists(\Pdo\Pgsql::class) && method_exists(PDO::class, 'connect')) {
            /** @var PDO */
            return PDO::connect($dsn, $username, $password, $options);
        }

        return new PDO($dsn, $username, $password, $options);
    }

    /** Whether COPY can be done on this connection at all. */
    public static function available(PDO $pdo): bool
    {
        if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'pgsql') {
            return false;
        }

        return $pdo instanceof \Pdo\Pgsql || method_exists($pdo, 'pgsqlCopyToFile');
    }

    /**
     * COPY $table ($columns) TO $file. $table and $columns are quoted already (quote()).
     *
     * @throws RuntimeException when the database refuses
     */
    public static function toFile(PDO $pdo, string $table, string $columns, string $file): void
    {
        try {
            if ($pdo instanceof \Pdo\Pgsql) {
                $done = $pdo->copyToFile($table, $file, "\t", self::NULL_AS, $columns);
            } else {
                $done = @$pdo->pgsqlCopyToFile($table, $file, "\t", self::NULL_AS, $columns);
            }
        } catch (Throwable $e) {
            throw new RuntimeException("COPY of $table out of the database failed: " . $e->getMessage(), 0, $e);
        }
        if ($done !== true) {
            throw new RuntimeException("COPY of $table out of the database failed: " . self::lastError($pdo));
        }
    }

    /**
     * COPY $table ($columns) FROM $file. $table and $columns are quoted already (quote()).
     *
     * @throws RuntimeException when the database refuses (the transaction it runs in is then aborted)
     */
    public static function fromFile(PDO $pdo, string $table, string $columns, string $file): void
    {
        try {
            if ($pdo instanceof \Pdo\Pgsql) {
                $done = $pdo->copyFromFile($table, $file, "\t", self::NULL_AS, $columns);
            } else {
                $done = @$pdo->pgsqlCopyFromFile($table, $file, "\t", self::NULL_AS, $columns);
            }
        } catch (Throwable $e) {
            throw new RuntimeException("COPY of $table into the database failed: " . $e->getMessage(), 0, $e);
        }
        if ($done !== true) {
            throw new RuntimeException("COPY of $table into the database failed: " . self::lastError($pdo));
        }
    }

    /** A name as an SQL identifier: in double quotes, its own double quotes doubled. */
    public static function quote(string $identifier): string
    {
        return '"' . str_replace('"', '""', $identifier) . '"';
    }

    private static function lastError(PDO $pdo): string
    {
        $info = $pdo->errorInfo();

        return is_string($info[2] ?? null) ? $info[2] : 'no reason given';
    }
}
