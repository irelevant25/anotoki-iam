<?php

// ---------------------------------------------------------------------------
// config/db.php - the PDO connection
// ---------------------------------------------------------------------------
// The connection comes from config/database.local.php, which setup.php
// writes:
//
//   return [
//       'driver' => 'pgsql',
//       'host' => '127.0.0.1',
//       'port' => '5432',
//       'databases' => [
//           'iam' => 'anotoki_iam',  // the IAM's own database
//           'legacy' => 'users',     // optional: the genshin site's old accounts,
//                                    // on the same server, for the one-time import
//       ],
//       'username' => '...',
//       'password' => '...',
//       'persistent' => true,
//   ];
//
// 'databases' maps the alias the code uses to the database's real name.
//
// Every connection runs in UTC. The tables are TIMESTAMPTZ, so the instant is
// stored correctly either way - but what comes back out as text, and what
// CURRENT_DATE means, follows the session's zone, and a server in another zone
// would otherwise hand back times that read an hour or two off.
// ---------------------------------------------------------------------------

require_once __DIR__ . '/config.php';

/** The connection settings. Throws IamNotSetUp when setup.php has not written them. */
function databaseConfig(): array
{
    $config = localConfig('database');

    if (!is_array($config)) {
        throw new IamNotSetUp('config/database.local.php is missing');
    }

    return $config + ['driver' => 'pgsql', 'port' => '5432', 'databases' => [], 'password' => '', 'persistent' => false];
}

function iamDb(): PDO
{
    return getDb('iam');
}

function getDb(string $alias = 'iam'): PDO
{
    static $connections = [];

    $config = databaseConfig();

    if (!isset($config['databases'][$alias]) || trim((string) $config['databases'][$alias]) === '') {
        throw new InvalidArgumentException("Unknown database alias '{$alias}'. Name it under 'databases' in config/database.local.php (setup.php does).");
    }

    $name = trim((string) $config['databases'][$alias]);

    // Keyed by where it goes, not by alias: once setup.php has written a new
    // connection, the same alias must not hand back the old server's.
    $key = implode("\0", [$config['host'], $config['port'], $config['username'], $name]);

    return $connections[$key] ??= connectDatabase($config, $name);
}

/**
 * A connection to one database on the configured server.
 *
 * Separate from getDb() because the installer and the importer connect with
 * details that are not (yet) in any config file.
 *
 * Persistent connections are opt-in: they only pay off where PHP keeps its
 * processes alive between requests, and a pooled connection may still be
 * holding a transaction its last request died inside - hence the ROLLBACK,
 * which Postgres answers with a warning when there is nothing to roll back.
 * Or the migration engine's advisory lock (anotoki/lib takes one for every
 * apply, and lets it go in a `finally` that a request killed by its time
 * limit never reaches): every later Apply would be told another is running
 * until that pooled connection closed. No request holds an advisory lock
 * from one request to the next, so whatever the last one left goes.
 *
 * The connect timeout keeps a wrong host from hanging a request - or the
 * installer's "Test connection" - for as long as the network cares to take.
 */
function connectDatabase(array $config, string $name, ?bool $persistent = null): PDO
{
    $persistent = $persistent ?? !empty($config['persistent']);
    $dsn = "pgsql:host={$config['host']};port={$config['port']};dbname={$name};connect_timeout=5";

    $pdo = new PDO($dsn, (string) $config['username'], (string) ($config['password'] ?? ''), $persistent ? [PDO::ATTR_PERSISTENT => true] : []);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec(($persistent ? 'ROLLBACK; SELECT pg_advisory_unlock_all(); ' : '') . "SET TIME ZONE 'UTC'; SET client_encoding = 'UTF8'");

    return $pdo;
}
