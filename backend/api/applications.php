<?php

/**
 * Applications - the anotoki sites - and who may use them with which roles.
 *
 * Everything that decides whether a code or a token is issued reads through
 * here, so the rules live in one place: an application must exist and be
 * enabled, a redirect URI must be one of its own byte for byte, and an account
 * may sign in to a site only while it holds a role there that may sign in.
 * Every account holds MEMBER in every application (migration 010's triggers),
 * so that is everybody until an administrator takes somebody's MEMBER away, or
 * unticks MEMBER's "may sign in" for the site.
 */

const IAM_CLIENT_ID = 'iam';

/** One application by client id, or null. */
function applicationByClientId(string $clientId): ?array
{
    if ($clientId === '' || strlen($clientId) > 64) {
        return null;
    }

    $statement = iamDb()->prepare('SELECT * FROM applications WHERE client_id = ?');
    $statement->execute([$clientId]);

    return $statement->fetch() ?: null;
}

function applicationById(int $id): ?array
{
    $statement = iamDb()->prepare('SELECT * FROM applications WHERE id = ?');
    $statement->execute([$id]);

    return $statement->fetch() ?: null;
}

/** The registered redirect URIs of one kind ('login' or 'logout'). */
function applicationRedirectUris(int $applicationId, string $kind): array
{
    $statement = iamDb()->prepare('SELECT uri FROM application_redirect_uris WHERE application_id = ? AND kind = ? ORDER BY id');
    $statement->execute([$applicationId, $kind]);

    return $statement->fetchAll(PDO::FETCH_COLUMN);
}

/**
 * Whether a redirect URI is registered for this application.
 *
 * Exact string equality, deliberately: no prefix, no pattern, no
 * normalisation. A redirect URI is where a code is sent, and every loosening
 * of the match is a way to send it somewhere the application does not control.
 */
function redirectUriRegistered(int $applicationId, string $kind, string $uri): bool
{
    return $uri !== '' && in_array($uri, applicationRedirectUris($applicationId, $kind), true);
}

function applicationOrigins(int $applicationId): array
{
    $statement = iamDb()->prepare('SELECT origin FROM application_origins WHERE application_id = ? ORDER BY id');
    $statement->execute([$applicationId]);

    return $statement->fetchAll(PDO::FETCH_COLUMN);
}

/** Whether any application lists this origin - for answering a CORS preflight. */
function originRegistered(string $origin): bool
{
    if ($origin === '') {
        return false;
    }

    $statement = iamDb()->prepare('SELECT 1 FROM application_origins WHERE origin = ? LIMIT 1');
    $statement->execute([$origin]);

    return (bool) $statement->fetchColumn();
}

/**
 * An absolute URL with no fragment - https, or http on this computer - is what
 * a redirect URI may be (loopbackHost() is in config/config.php).
 */
function validRedirectUri(string $uri): bool
{
    $parts = parse_url($uri);

    if ($parts === false || empty($parts['host']) || isset($parts['fragment']) || strlen($uri) > 500) {
        return false;
    }

    $scheme = $parts['scheme'] ?? '';

    return $scheme === 'https' || ($scheme === 'http' && loopbackHost($parts['host']));
}

/** scheme://host[:port], nothing more - https, or http on this computer. */
function validOrigin(string $origin): bool
{
    if (!preg_match('#^(https?)://([A-Za-z0-9.-]+)(:\d{1,5})?$#', $origin, $found)) {
        return false;
    }

    return $found[1] === 'https' || loopbackHost($found[2]);
}

/** The names of an account's roles in one application. */
function userRolesIn(int $userId, int $applicationId): array
{
    $statement = iamDb()->prepare(
        'SELECT r.name FROM user_roles ur JOIN application_roles r ON r.id = ur.role_id
          WHERE ur.user_id = ? AND r.application_id = ? ORDER BY r.name',
    );
    $statement->execute([$userId, $applicationId]);

    return $statement->fetchAll(PDO::FETCH_COLUMN);
}

/** Whether the account administers this IAM. Read fresh: a role taken away counts at once. */
function isIamAdmin(int $userId): bool
{
    $statement = iamDb()->prepare(
        "SELECT 1 FROM user_roles ur
           JOIN application_roles r ON r.id = ur.role_id
           JOIN applications a ON a.id = r.application_id
          WHERE ur.user_id = ? AND a.client_id = ? AND r.name = 'ADMIN'",
    );
    $statement->execute([$userId, IAM_CLIENT_ID]);

    return (bool) $statement->fetchColumn();
}

/**
 * Starts a transaction in which nothing else can change who administers the
 * IAM (a transaction-scoped advisory lock, released at commit or rollback).
 *
 * The last-admin rule is checked inside it. Checked outside, two admins taking
 * the role from each other at the same moment would each still see two admins,
 * and both would succeed.
 */
function lockIamAdmins(PDO $pdo): void
{
    $pdo->beginTransaction();
    $pdo->query('SELECT pg_advisory_xact_lock(' . IAM_ADMINS_LOCK . ')');
}

/**
 * The advisory lock key for lockIamAdmins() - any fixed number, the IAM's own.
 * 4260918 is taken too: migration 010's MEMBER triggers hold it.
 */
const IAM_ADMINS_LOCK = 4260917;

/**
 * The lock the MEMBER triggers take (010, 011, 016) before they give an
 * account or an application its MEMBER; an anonymization takes it before it
 * takes every role, so the two never cross (anonymizeAccount()).
 */
const MEMBER_ROLES_LOCK = 4260918;

/**
 * How many accounts administer the IAM: holding its ADMIN, switched on, and
 * not on their way out - an administrator whose own deletion is pending
 * administers nothing any more (requireAdmin()) and is gone when it is done,
 * so the last-admin rule must not count them as one who stays. `$besides`
 * leaves one account out: "would any be left without this one?" is the
 * question every last-admin check asks.
 */
function iamAdminCount(?int $besides = null): int
{
    $pdo = iamDb();
    $leaving = accountDeletionReady($pdo) ? ' AND u.deletion_due_at IS NULL AND u.anonymized_at IS NULL' : '';

    $statement = $pdo->prepare(
        "SELECT count(DISTINCT ur.user_id) FROM user_roles ur
           JOIN application_roles r ON r.id = ur.role_id
           JOIN applications a ON a.id = r.application_id
           JOIN users u ON u.id = ur.user_id AND u.disabled = FALSE{$leaving}
          WHERE a.client_id = ? AND r.name = 'ADMIN' AND ur.user_id <> ?",
    );
    $statement->execute([IAM_CLIENT_ID, $besides ?? 0]);

    return (int) $statement->fetchColumn();
}

/**
 * The answer of the approvals' routes while migration 012 is pending
 * (requireSignInRules()): a 503 that points at the Migrations page, not a 500.
 */
function signInRulesUnavailable(): array
{
    return [
        'status' => 503,
        'code' => 'sign_in_rules_unavailable',
        'error' => 'The sign-in rules are not in the database yet. Apply the pending migrations in the admin panel.',
    ];
}

/** Whether the account holds a role in the application that may sign in. Read fresh: a role taken away counts at once. */
function userMaySignInTo(int $userId, int $applicationId): bool
{
    $statement = iamDb()->prepare(
        'SELECT EXISTS (SELECT 1 FROM user_roles ur JOIN application_roles r ON r.id = ur.role_id
                         WHERE ur.user_id = ? AND r.application_id = ? AND r.may_sign_in)',
    );
    $statement->execute([$userId, $applicationId]);

    return asBool($statement->fetchColumn());
}

/**
 * Why this account may not use this application right now, or null.
 *
 * Checked when a code is issued (authorize, the sign-in page's accept, the
 * code exchange), for a personal token, and again at every refresh - so
 * taking somebody's MEMBER away, or unticking a role's "may sign in", locks
 * them out within one access token's lifetime rather than whenever their
 * session happens to end.
 *
 * The IAM's own entry is not asked about roles: its pages use their own
 * cookie, and Settings -> Signing in (signInRefusal()) is their only gate.
 *
 * An account waiting for an administrator's approval (api/accounts.php) is
 * refused `approval_pending`. It never has a session to get this far with:
 * this is the same switch on one more door.
 *
 * An account whose deletion is pending (api/account_deletion.php) is refused
 * `account_deletion_pending`: it keeps its session at the IAM - that is where
 * it is cancelled - and nothing at all is issued with it to any site, so
 * every site loses the person at the next refresh.
 *
 * @return array{status: int, code: string, error: string}|null
 */
function applicationAccessRefusal(array $application, array $user): ?array
{
    if (!$application['enabled']) {
        return ['status' => 403, 'code' => 'application_disabled', 'error' => $application['name'] . ' is switched off at the moment'];
    }

    if (!empty($user['disabled'])) {
        return ['status' => 403, 'code' => 'account_disabled', 'error' => 'This account has been switched off'];
    }

    if (!$user['email_confirmed']) {
        return ['status' => 403, 'code' => 'email_not_confirmed', 'error' => 'Confirm your email address first'];
    }

    if (accountAwaitsApproval($user)) {
        return ['status' => 403, 'code' => 'approval_pending', 'error' => 'This account is waiting for an administrator\'s approval'];
    }

    if (accountDeletionPending($user)) {
        return ['status' => 403, 'code' => 'account_deletion_pending', 'error' => 'This account is being deleted - cancel the deletion on the account page to use it again'];
    }

    if (rolesKeepOut($application, (int) $user['id'])) {
        return ['status' => 403, 'code' => 'access_denied', 'error' => 'This account cannot use ' . $application['name']];
    }

    return null;
}

/**
 * Whether the account's roles keep it out of the application - whatever else
 * (the application switched off, the account's own state) would: it holds no
 * role there that may sign in (migration 010's rule, each role's
 * may_sign_in). The IAM's own entry is never asked (its gate is Settings ->
 * Signing in). The rule before 010, on the applications' `access` column,
 * went with that column (migration 027): a database without 010 is no
 * database this build runs on - the query below fails, and nothing is let in.
 */
function rolesKeepOut(array $application, int $userId): bool
{
    return !asBool($application['system']) && !userMaySignInTo($userId, (int) $application['id']);
}

/** The public face of an application, as the sign-in pages show it. */
function applicationSummary(array $application): array
{
    return [
        'client_id' => $application['client_id'],
        'name' => $application['name'],
        'description' => $application['description'],
        'home_url' => $application['home_url'],
        'logo_url' => $application['logo_url'],
    ];
}
