<?php

/**
 * Applications - the anotoki sites - and who may use them with which roles.
 *
 * Everything that decides whether a code or a token is issued reads through
 * here, so the rules live in one place: an application must exist and be
 * enabled, a redirect URI must be one of its own byte for byte, and an account
 * may use a 'members' application only while it holds one of its roles.
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
          WHERE ur.user_id = ? AND r.application_id = ? ORDER BY r.name'
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
          WHERE ur.user_id = ? AND a.client_id = ? AND r.name = 'ADMIN'"
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

/** The advisory lock key for lockIamAdmins() - any fixed number, the IAM's own. */
const IAM_ADMINS_LOCK = 4260917;

/** How many accounts can administer the IAM and are not switched off. */
function iamAdminCount(): int
{
    $statement = iamDb()->prepare(
        "SELECT count(DISTINCT ur.user_id) FROM user_roles ur
           JOIN application_roles r ON r.id = ur.role_id
           JOIN applications a ON a.id = r.application_id
           JOIN users u ON u.id = ur.user_id AND u.disabled = FALSE
          WHERE a.client_id = ? AND r.name = 'ADMIN'"
    );
    $statement->execute([IAM_CLIENT_ID]);

    return (int) $statement->fetchColumn();
}

/**
 * Why this account may not use this application right now, or null.
 *
 * Checked when a code is issued and again at every refresh, so taking away
 * somebody's last role in a members-only application locks them out within one
 * access token's lifetime rather than whenever their session happens to end.
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

    if ($application['access'] === 'members' && userRolesIn((int) $user['id'], (int) $application['id']) === []) {
        return ['status' => 403, 'code' => 'access_denied', 'error' => 'This account cannot use ' . $application['name']];
    }

    return null;
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

/**
 * Whether a client secret is right, for a confidential client.
 *
 * password_verify, because a client secret is a long-lived credential typed
 * into a config file - the kind of thing that ends up somewhere it should not -
 * and a stolen hash of it should be as useless as a stolen password hash.
 */
function clientSecretValid(array $application, string $secret): bool
{
    return $secret !== '' && !empty($application['secret_hash']) && password_verify($secret, $application['secret_hash']);
}
