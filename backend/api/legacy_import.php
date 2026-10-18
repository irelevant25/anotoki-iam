<?php

/**
 * Bringing the accounts over from the genshin site's `users` database.
 *
 * Until the IAM, the accounts lived beside that site's translations and
 * settings, in a database called `users`. This copies them here once, with
 * everything that makes them work: the same ids (every created_by and user_id
 * in the site's own tables keeps pointing at the same person), password hashes
 * (nobody picks a new password), Google identities, two-factor secrets and
 * recovery codes, and the history - sessions, sign-in attempts, the mail log.
 *
 * Not brought over, on purpose:
 *   - live sessions. The tokens that named them were the site's own and stop
 *     working anyway; the rows come across as history, marked ended.
 *   - one-time tokens. Their links point at pages of the old site that are
 *     gone; anybody mid-confirmation asks for a new link.
 *   - remembered devices. The secret sat in the site's own browser storage,
 *     which the IAM's pages - another origin - cannot read.
 *
 * Roles move from one column to the IAM's per-application roles: the site's
 * ADMIN becomes ADMIN in every application that has one - genshin's, the IAM's
 * (those accounts already ran the account pages that now live here) and every
 * other site's, as the owner wants an administrator of the family to
 * administer all of it; EDITOR becomes genshin EDITOR; USER needs no role.
 *
 * All of it is one transaction on the IAM database, into an IAM with no
 * accounts yet. When it commits, a marker is written into the old database:
 * the genshin migration that removes the old account tables refuses to run
 * until it finds that marker, so the only copy of the passwords cannot be
 * dropped before this copy exists.
 */

// The import is recorded in the audit log (iamAuditLog()), and the installer
// loads this file without the API's front controller.
require_once __DIR__ . '/audit.php';

/**
 * The columns of the old users table the import reads - the shape the genshin
 * site's migrations up to 031 leave it in.
 */
const LEGACY_USER_COLUMNS = [
    'id',
    'role',
    'username',
    'email',
    'email_confirmed',
    'password',
    'language',
    'deleted',
    'created_at',
    'updated_at',
    'password_login_enabled',
    'totp_secret',
    'totp_enabled',
    'force_password_change',
];

/**
 * A connection to the old database, deliberately without the UTC setting.
 *
 * Its timestamps are TIMESTAMP without a zone, written in the server's default
 * zone; reading them back needs that zone, which a UTC session would hide.
 */
function legacyConnect(array $config, string $name): PDO
{
    $pdo = new PDO("pgsql:host={$config['host']};port={$config['port']};dbname={$name};connect_timeout=5", $config['username'], $config['password'] ?? '', [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec("SET client_encoding = 'UTF8'");

    return $pdo;
}

/** Whether a table and a set of its columns exist in a database. */
function legacyHasColumns(PDO $pdo, string $table, array $columns): bool
{
    $statement = $pdo->prepare("SELECT column_name FROM information_schema.columns WHERE table_schema = 'public' AND table_name = ?");
    $statement->execute([$table]);
    $present = $statement->fetchAll(PDO::FETCH_COLUMN);

    return $present !== [] && array_diff($columns, $present) === [];
}

/**
 * The rows of a legacy table with each timestamp column as epoch seconds.
 *
 * `col AT TIME ZONE <zone>` reads a zoneless timestamp as local time in that
 * zone - the zone it was written in - and EXTRACT(EPOCH) makes it an instant.
 */
function legacyRows(PDO $pdo, string $table, array $columns, array $timeColumns, string $zone): array
{
    $select = [];
    foreach ($columns as $column) {
        $select[] = in_array($column, $timeColumns, true) ? "EXTRACT(EPOCH FROM ({$column} AT TIME ZONE " . $pdo->quote($zone) . ")) AS {$column}" : $column;
    }

    return $pdo->query('SELECT ' . implode(', ', $select) . " FROM {$table} ORDER BY id")->fetchAll();
}

/** An epoch-seconds value, or null, as the argument to to_timestamp(). */
function epochOrNull(mixed $value): ?float
{
    return $value === null || $value === '' ? null : (float) $value;
}

/**
 * Copies the accounts. Returns what it did, or throws with a sentence saying
 * why it would not.
 *
 * `$dryRun` does everything inside the transaction and rolls it back, and
 * writes no marker - an honest rehearsal, conflicts and counts included.
 *
 * @return array{accounts: int, identities: int, recovery_codes: int, sessions: int, login_attempts: int, emails: int, roles: array<string,int>, time_zone: string, dry_run: bool}
 */
function importLegacyAccounts(PDO $iam, PDO $legacy, string $siteClientId = 'genshin', bool $dryRun = false, ?string $issuer = null): array
{
    // Everything here goes through the two connections it was handed, never
    // through iamDb(): the installer runs this before any config file exists.
    if ((int) $iam->query('SELECT count(*) FROM users')->fetchColumn() > 0) {
        throw new RuntimeException('The IAM already has accounts. The import only runs into an empty IAM, so nothing is ever merged by guesswork.');
    }

    $userColumns = LEGACY_USER_COLUMNS;

    if (!legacyHasColumns($legacy, 'users', $userColumns)) {
        throw new RuntimeException(
            "That database has no users table in the shape the genshin site left it (it needs the columns of its migrations up to 031). Bring the site's migrations up to date first.",
        );
    }

    $site = $iam->prepare('SELECT id FROM applications WHERE client_id = ?');
    $site->execute([$siteClientId]);
    if ($site->fetchColumn() === false) {
        throw new RuntimeException("There is no application '{$siteClientId}' here to give the site's roles to.");
    }

    $zone = (string) $legacy->query("SELECT current_setting('TimeZone')")->fetchColumn();
    $users = legacyRows($legacy, 'users', $userColumns, ['created_at', 'updated_at'], $zone);

    // The IAM's names and addresses are unique regardless of case; the old
    // table's were not. Two rows that only differ in case have to be settled
    // by a person, not by whichever the import happened to read first.
    foreach (['username', 'email'] as $field) {
        $seen = [];
        foreach ($users as $user) {
            $key = mb_strtolower((string) $user[$field]);
            if (isset($seen[$key])) {
                throw new RuntimeException("Accounts {$seen[$key]} and {$user['id']} have the same {$field} apart from letter case ('{$user[$field]}'). Rename one in the genshin admin panel first.");
            }
            $seen[$key] = $user['id'];
        }
    }

    $lastLogin = [];
    if (legacyHasColumns($legacy, 'user_sessions', ['user_id', 'created_at'])) {
        $statement = $legacy->query('SELECT user_id, EXTRACT(EPOCH FROM (max(created_at) AT TIME ZONE ' . $legacy->quote($zone) . ')) AS at FROM user_sessions GROUP BY user_id');
        foreach ($statement->fetchAll() as $row) {
            $lastLogin[(int) $row['user_id']] = $row['at'];
        }
    }

    $summary = ['accounts' => 0, 'identities' => 0, 'recovery_codes' => 0, 'sessions' => 0, 'login_attempts' => 0, 'emails' => 0, 'roles' => [], 'time_zone' => $zone, 'dry_run' => $dryRun];

    // The languages an account may have here, read once and before the
    // transaction, through this connection: the installer runs the import
    // before any config file is written, so nothing may reach for iamDb().
    $languages = languageCodes($iam);
    // Every application with an ADMIN role, read the same way: an ADMIN of the
    // old site becomes ADMIN of each.
    $adminClients = array_values(
        array_unique(
            array_merge(
                [$siteClientId, IAM_CLIENT_ID],
                $iam
                    ->query(
                        "SELECT a.client_id FROM application_roles r JOIN applications a ON a.id = r.application_id
                      WHERE r.name = 'ADMIN' ORDER BY a.client_id",
                    )
                    ->fetchAll(PDO::FETCH_COLUMN),
            ),
        ),
    );

    $iam->beginTransaction();

    try {
        $insertUser = $iam->prepare(
            'INSERT INTO users (id, username, email, email_confirmed, password, password_login_enabled, totp_secret, totp_enabled,
                                force_password_change, language, disabled, last_login_at, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, to_timestamp(?), COALESCE(to_timestamp(?), CURRENT_TIMESTAMP), to_timestamp(?))',
        );

        foreach ($users as $user) {
            $secret = $user['totp_secret'] !== null && $user['totp_secret'] !== '' ? encryptSecret((string) $user['totp_secret']) : null;
            // A language the IAM does not offer becomes English, as languageOrDefault() would make it.
            $language = strtolower(trim((string) $user['language']));

            $insertUser->execute([
                (int) $user['id'],
                $user['username'],
                $user['email'],
                pgBool(asBool($user['email_confirmed'])),
                $user['password'],
                pgBool(asBool($user['password_login_enabled'])),
                $secret,
                pgBool(asBool($user['totp_enabled']) && $secret !== null),
                pgBool(asBool($user['force_password_change'])),
                in_array($language, $languages, true) ? $language : 'en',
                pgBool(asBool($user['deleted'])),
                epochOrNull($lastLogin[(int) $user['id']] ?? null),
                epochOrNull($user['created_at']),
                epochOrNull($user['updated_at']),
            ]);
            $summary['accounts']++;

            $grants = match (strtoupper((string) $user['role'])) {
                'ADMIN' => array_map(static fn(string $clientId): array => [$clientId, 'ADMIN'], $adminClients),
                'EDITOR' => [[$siteClientId, 'EDITOR']],
                default => [],
            };

            foreach ($grants as [$clientId, $role]) {
                $iam->prepare(
                    'INSERT INTO user_roles (user_id, role_id)
                     SELECT ?, r.id FROM application_roles r JOIN applications a ON a.id = r.application_id
                      WHERE a.client_id = ? AND r.name = ?',
                )->execute([(int) $user['id'], $clientId, $role]);
                $summary['roles']["{$clientId} {$role}"] = ($summary['roles']["{$clientId} {$role}"] ?? 0) + 1;
            }
        }

        if (legacyHasColumns($legacy, 'user_identities', ['id', 'user_id', 'provider', 'subject', 'email', 'created_at'])) {
            $insert = $iam->prepare('INSERT INTO user_identities (id, user_id, provider, subject, email, created_at) VALUES (?, ?, ?, ?, ?, COALESCE(to_timestamp(?), CURRENT_TIMESTAMP))');
            foreach (legacyRows($legacy, 'user_identities', ['id', 'user_id', 'provider', 'subject', 'email', 'created_at'], ['created_at'], $zone) as $row) {
                $insert->execute([(int) $row['id'], (int) $row['user_id'], $row['provider'], $row['subject'], $row['email'], epochOrNull($row['created_at'])]);
                $summary['identities']++;
            }
        }

        // The same hash of the same normalised code, so every unused code on
        // somebody's piece of paper still works.
        if (legacyHasColumns($legacy, 'user_recovery_codes', ['id', 'user_id', 'code_hash', 'used_at', 'created_at'])) {
            $insert = $iam->prepare('INSERT INTO user_recovery_codes (id, user_id, code_hash, used_at, created_at) VALUES (?, ?, ?, to_timestamp(?), COALESCE(to_timestamp(?), CURRENT_TIMESTAMP))');
            foreach (legacyRows($legacy, 'user_recovery_codes', ['id', 'user_id', 'code_hash', 'used_at', 'created_at'], ['used_at', 'created_at'], $zone) as $row) {
                $insert->execute([(int) $row['id'], (int) $row['user_id'], $row['code_hash'], epochOrNull($row['used_at']), epochOrNull($row['created_at'])]);
                $summary['recovery_codes']++;
            }
        }

        // History only. The row keeps its id and its old token id as the sid,
        // so a change the genshin audit log pinned to "session 123" can still
        // be looked up here; the hash is of nothing any cookie will ever hold.
        $sessionColumns = ['id', 'user_id', 'token_id', 'method', 'ip', 'user_agent', 'created_at', 'last_seen_at', 'expires_at', 'revoked_at', 'revoked_reason'];
        if (legacyHasColumns($legacy, 'user_sessions', $sessionColumns)) {
            $insert = $iam->prepare(
                "INSERT INTO sessions (id, user_id, token_hash, sid, method, ip, user_agent, created_at, auth_time, last_seen_at,
                                       expires_at, absolute_expires_at, revoked_at, revoked_reason)
                 VALUES (?, ?, ?, ?, ?, ?, ?, to_timestamp(?), to_timestamp(?), to_timestamp(?), to_timestamp(?), to_timestamp(?),
                         COALESCE(to_timestamp(?), CURRENT_TIMESTAMP), ?)",
            );
            foreach (legacyRows($legacy, 'user_sessions', $sessionColumns, ['created_at', 'last_seen_at', 'expires_at', 'revoked_at'], $zone) as $row) {
                $created = epochOrNull($row['created_at']) ?? (float) time();
                $expires = epochOrNull($row['expires_at']) ?? $created;
                $insert->execute([
                    (int) $row['id'],
                    (int) $row['user_id'],
                    sha256(random_bytes(32)),
                    substr(str_pad((string) $row['token_id'], 32, '0'), 0, 32),
                    substr((string) $row['method'], 0, 16),
                    $row['ip'],
                    $row['user_agent'],
                    $created,
                    $created,
                    epochOrNull($row['last_seen_at']),
                    $expires,
                    $expires,
                    epochOrNull($row['revoked_at']),
                    $row['revoked_reason'] ?? 'migrated',
                ]);
                $summary['sessions']++;
            }
        }

        if (legacyHasColumns($legacy, 'user_login_attempts', ['id', 'email', 'user_id', 'ip', 'method', 'outcome', 'created_at'])) {
            $insert = $iam->prepare('INSERT INTO login_attempts (id, email, user_id, ip, method, outcome, created_at) VALUES (?, ?, ?, ?, ?, ?, COALESCE(to_timestamp(?), CURRENT_TIMESTAMP))');
            foreach (legacyRows($legacy, 'user_login_attempts', ['id', 'email', 'user_id', 'ip', 'method', 'outcome', 'created_at'], ['created_at'], $zone) as $row) {
                $insert->execute([
                    (int) $row['id'],
                    strtolower((string) $row['email']),
                    $row['user_id'] === null ? null : (int) $row['user_id'],
                    $row['ip'],
                    $row['method'],
                    $row['outcome'],
                    epochOrNull($row['created_at']),
                ]);
                $summary['login_attempts']++;
            }
        }

        if (legacyHasColumns($legacy, 'email_log', ['id', 'recipient', 'kind', 'subject', 'status', 'error', 'user_id', 'created_at'])) {
            $insert = $iam->prepare(
                'INSERT INTO email_log (id, recipient, kind, subject, status, error, user_id, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, COALESCE(to_timestamp(?), CURRENT_TIMESTAMP))',
            );
            foreach (legacyRows($legacy, 'email_log', ['id', 'recipient', 'kind', 'subject', 'status', 'error', 'user_id', 'created_at'], ['created_at'], $zone) as $row) {
                $insert->execute([
                    (int) $row['id'],
                    $row['recipient'],
                    $row['kind'],
                    $row['subject'],
                    $row['status'],
                    $row['error'],
                    $row['user_id'] === null ? null : (int) $row['user_id'],
                    epochOrNull($row['created_at']),
                ]);
                $summary['emails']++;
            }
        }

        // The ids were copied, so the sequences have to be told where to carry on.
        foreach (['users', 'user_identities', 'user_recovery_codes', 'sessions', 'login_attempts', 'email_log'] as $table) {
            $iam->exec("SELECT setval(pg_get_serial_sequence('{$table}', 'id'), GREATEST((SELECT COALESCE(max(id), 0) FROM {$table}), 1), (SELECT count(*) > 0 FROM {$table}))");
        }

        if (
            (int) $iam
                ->query(
                    "SELECT count(*) FROM user_roles ur JOIN application_roles r ON r.id = ur.role_id JOIN applications a ON a.id = r.application_id WHERE a.client_id = 'iam' AND r.name = 'ADMIN'",
                )
                ->fetchColumn() === 0
        ) {
            throw new RuntimeException(
                'None of those accounts is an ADMIN, so nobody could administer the IAM afterwards. Make one an admin in the genshin site first, or start with create-admin.php instead.',
            );
        }

        // Into the audit log in this transaction, by nobody (the library's
        // AuditLog on the import's own connection - the installer's is not
        // iamDb()'s). A rehearsal records nothing: it is rolled back whole.
        if (!$dryRun) {
            iamAuditLog($iam)->record(null, null, 'legacy.imported', null, null, $summary);
        }

        if ($dryRun) {
            $iam->rollBack();
            return $summary;
        }

        $iam->commit();
    } catch (\Throwable $e) {
        if ($iam->inTransaction()) {
            $iam->rollBack();
        }
        throw $e;
    }

    // The marker the genshin migration waits for. Written after the commit:
    // were it written first and the import then failed, the site would feel
    // free to drop the only copy of the accounts.
    $legacy->exec(
        'CREATE TABLE IF NOT EXISTS iam_handover (
            id          SMALLINT     PRIMARY KEY DEFAULT 1 CHECK (id = 1),
            exported_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
            iam_issuer  TEXT,
            accounts    INT          NOT NULL
        )',
    );
    $legacy
        ->prepare(
            'INSERT INTO iam_handover (id, iam_issuer, accounts) VALUES (1, ?, ?)
         ON CONFLICT (id) DO UPDATE SET exported_at = CURRENT_TIMESTAMP, iam_issuer = EXCLUDED.iam_issuer, accounts = EXCLUDED.accounts',
        )
        ->execute([$issuer ?? (function_exists('iamIssuer') ? iamIssuer() : null), $summary['accounts']]);

    return $summary;
}
