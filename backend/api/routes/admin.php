<?php

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

// ---------------------------------------------------------------------------
// The admin console
//
// Everything here needs the ADMIN role in the IAM itself. It is the genshin
// site's Accounts, Sessions and Email Log pages, moved to where the accounts
// now are, plus what a shared sign-in adds: applications, their roles and who
// holds them, the switches, the signing keys, and the migrations - applied from
// here because the production host has no shell.
//
// Every change is written to the audit log with who made it.
// ---------------------------------------------------------------------------

function actor(Request $request): array
{
    return $request->getAttribute('user');
}

/** Every role an account holds, across applications. */
function rolesOf(PDO $pdo, int $userId): array
{
    $statement = $pdo->prepare(
        'SELECT a.client_id, r.name AS role FROM user_roles ur
           JOIN application_roles r ON r.id = ur.role_id
           JOIN applications a ON a.id = r.application_id
          WHERE ur.user_id = ? ORDER BY a.sort_order, a.client_id, r.name'
    );
    $statement->execute([$userId]);

    return $statement->fetchAll();
}

const ADMIN_USER_SELECT = "SELECT u.id, u.username, u.email, u.email_confirmed, u.language, u.disabled, u.totp_enabled,
        u.force_password_change, (u.password IS NOT NULL) AS has_password, u.last_login_at, u.created_at, u.updated_at,
        EXISTS (SELECT 1 FROM user_identities i WHERE i.user_id = u.id AND i.provider = 'google') AS google_connected,
        (SELECT i.email FROM user_identities i WHERE i.user_id = u.id AND i.provider = 'google' LIMIT 1) AS google_email,
        COALESCE((SELECT json_agg(json_build_object('client_id', a.client_id, 'role', r.name) ORDER BY a.sort_order, r.name)
                    FROM user_roles ur JOIN application_roles r ON r.id = ur.role_id
                    JOIN applications a ON a.id = r.application_id WHERE ur.user_id = u.id), '[]') AS roles
   FROM users u";

function adminUserShape(array $row): array
{
    return [
        'id' => (int) $row['id'],
        'username' => $row['username'],
        'email' => $row['email'],
        'email_confirmed' => asBool($row['email_confirmed']),
        'language' => $row['language'],
        'disabled' => asBool($row['disabled']),
        'totp_enabled' => asBool($row['totp_enabled']),
        'force_password_change' => asBool($row['force_password_change']),
        'has_password' => asBool($row['has_password']),
        'google_connected' => asBool($row['google_connected']),
        'google_email' => $row['google_email'],
        'roles' => json_decode((string) $row['roles'], true) ?: [],
        'last_login_at' => isoTime($row['last_login_at']),
        'created_at' => isoTime($row['created_at']),
        'updated_at' => isoTime($row['updated_at']),
    ];
}

function adminUser(PDO $pdo, int $id): ?array
{
    $statement = $pdo->prepare(ADMIN_USER_SELECT . ' WHERE u.id = ?');
    $statement->execute([$id]);
    $row = $statement->fetch();

    return $row ? adminUserShape($row) : null;
}

function adminUserDetail(PDO $pdo, int $id): ?array
{
    $account = adminUser($pdo, $id);

    if ($account === null) {
        return null;
    }

    $identities = $pdo->prepare('SELECT id, provider, email, created_at FROM user_identities WHERE user_id = ? ORDER BY id');
    $identities->execute([$id]);

    $attempts = $pdo->prepare(
        'SELECT id, email, ip, method, outcome, created_at FROM login_attempts WHERE user_id = ? ORDER BY created_at DESC, id DESC LIMIT 50'
    );
    $attempts->execute([$id]);

    $sessions = array_map(static fn(array $row): array => sessionEntry($row), sessionsFor($pdo, $id));

    return [
        'account' => $account,
        'identities' => array_map(static fn(array $row): array => ['id' => (int) $row['id']] + isoTimes($row, ['created_at']), $identities->fetchAll()),
        'sessions' => $sessions,
        'login_attempts' => array_map(static fn(array $row): array => ['id' => (int) $row['id']] + isoTimes($row, ['created_at']), $attempts->fetchAll()),
        'tokens' => personalTokensOf($pdo, $id),
        'active_sessions' => count(array_filter($sessions, static fn(array $session): bool => $session['active'])),
        'trusted_devices' => trustedDeviceCount($pdo, $id),
        'recovery_codes_remaining' => $account['totp_enabled'] ? recoveryCodesRemaining($pdo, $id) : 0,
        'failed_since_last_login' => failedAttemptsSinceLastLogin($pdo, $id),
    ];
}

/**
 * The role ids for a set of grants, or the reason one of them does not exist.
 *
 * @return array{ids: int[]}|array{error: string}
 */
function roleIdsFor(PDO $pdo, array $grants): array
{
    $ids = [];

    foreach ($grants as $grant) {
        $clientId = is_array($grant) ? trim((string) ($grant['client_id'] ?? '')) : '';
        $role = is_array($grant) ? strtoupper(trim((string) ($grant['role'] ?? ''))) : '';

        $statement = $pdo->prepare('SELECT r.id FROM application_roles r JOIN applications a ON a.id = r.application_id WHERE a.client_id = ? AND r.name = ?');
        $statement->execute([$clientId, $role]);
        $id = $statement->fetchColumn();

        if ($id === false) {
            return ['error' => "There is no role $role in $clientId"];
        }

        $ids[(int) $id] = true;
    }

    return ['ids' => array_keys($ids)];
}

/** Whether a set of role ids contains the IAM's own ADMIN. */
function grantsIamAdmin(PDO $pdo, array $roleIds): bool
{
    if ($roleIds === []) {
        return false;
    }

    $placeholders = implode(',', array_fill(0, count($roleIds), '?'));
    $statement = $pdo->prepare(
        "SELECT 1 FROM application_roles r JOIN applications a ON a.id = r.application_id
          WHERE r.id IN ($placeholders) AND a.client_id = ? AND r.name = 'ADMIN'"
    );
    $statement->execute([...$roleIds, IAM_CLIENT_ID]);

    return (bool) $statement->fetchColumn();
}

$app->group('/api/admin', function (\Slim\Routing\RouteCollectorProxy $group) {

    // ── Overview ─────────────────────────────────────────────────────────────

    $group->get('/stats', function (Request $request, Response $response) {
        $pdo = iamDb();
        $count = static fn(string $sql): int => (int) $pdo->query($sql)->fetchColumn();

        return respondJson($response, [
            'users' => $count('SELECT count(*) FROM users WHERE disabled = FALSE'),
            'disabled' => $count('SELECT count(*) FROM users WHERE disabled = TRUE'),
            'admins' => iamAdminCount(),
            'active_sessions' => $count('SELECT count(*) FROM sessions WHERE revoked_at IS NULL AND expires_at > CURRENT_TIMESTAMP AND absolute_expires_at > CURRENT_TIMESTAMP'),
            'applications' => $count('SELECT count(*) FROM applications WHERE system = FALSE'),
            'signins_24h' => $count("SELECT count(*) FROM login_attempts WHERE outcome = 'ok' AND created_at > CURRENT_TIMESTAMP - INTERVAL '1 day'"),
            'failed_24h' => $count("SELECT count(*) FROM login_attempts WHERE outcome NOT IN ('ok', 'sent') AND created_at > CURRENT_TIMESTAMP - INTERVAL '1 day'"),
            'emails_24h' => $count("SELECT count(*) FROM email_log WHERE created_at > CURRENT_TIMESTAMP - INTERVAL '1 day'"),
        ]);
    });

    // ── Users ────────────────────────────────────────────────────────────────

    $group->get('/users', function (Request $request, Response $response) {
        $query = $request->getQueryParams();
        $where = ['1 = 1'];
        $params = [];

        if (($search = field($query, 'search')) !== '') {
            $where[] = '(u.username ILIKE ? OR u.email ILIKE ?)';
            $params[] = '%' . $search . '%';
            $params[] = '%' . $search . '%';
        }

        $status = field($query, 'status');
        if ($status === 'enabled' || $status === 'disabled') {
            $where[] = 'u.disabled = ' . ($status === 'disabled' ? 'TRUE' : 'FALSE');
        }

        // Yes/no questions about an account, each taking yes, no, or '' for
        // "do not ask" - a table, because five near-identical ifs is five
        // places for a typo to hide.
        $flags = [
            'confirmed' => 'u.email_confirmed',
            'twoFactor' => 'u.totp_enabled',
            'google' => "EXISTS (SELECT 1 FROM user_identities i WHERE i.user_id = u.id AND i.provider = 'google')",
            'mustChange' => 'u.force_password_change',
        ];
        foreach ($flags as $parameter => $expression) {
            $value = field($query, $parameter);
            if ($value === 'yes' || $value === 'no') {
                $where[] = '(' . $expression . ') = ' . ($value === 'yes' ? 'TRUE' : 'FALSE');
            }
        }

        if (($language = field($query, 'language')) !== '') {
            $where[] = 'u.language = ?';
            $params[] = $language;
        }

        if (($clientId = field($query, 'application')) !== '') {
            $role = strtoupper(field($query, 'role'));
            $where[] = 'EXISTS (SELECT 1 FROM user_roles ur JOIN application_roles r ON r.id = ur.role_id
                                 JOIN applications a ON a.id = r.application_id
                                WHERE ur.user_id = u.id AND a.client_id = ?' . ($role !== '' ? ' AND r.name = ?' : '') . ')';
            $params[] = $clientId;
            if ($role !== '') {
                $params[] = $role;
            }
        }

        $statement = iamDb()->prepare(ADMIN_USER_SELECT . ' WHERE ' . implode(' AND ', $where) . ' ORDER BY u.created_at DESC, u.id DESC');
        $statement->execute($params);

        return respondJson($response, array_map('adminUserShape', $statement->fetchAll()));
    });

    $group->get('/users/filters', function (Request $request, Response $response) {
        $pdo = iamDb();
        $applications = [];

        foreach ($pdo->query('SELECT id, client_id, name FROM applications ORDER BY sort_order, name')->fetchAll() as $application) {
            $roles = $pdo->prepare('SELECT name FROM application_roles WHERE application_id = ? ORDER BY name');
            $roles->execute([(int) $application['id']]);
            $applications[] = ['client_id' => $application['client_id'], 'name' => $application['name'], 'roles' => $roles->fetchAll(PDO::FETCH_COLUMN)];
        }

        return respondJson($response, [
            'total' => (int) $pdo->query('SELECT count(*) FROM users WHERE disabled = FALSE')->fetchColumn(),
            'disabled' => (int) $pdo->query('SELECT count(*) FROM users WHERE disabled = TRUE')->fetchColumn(),
            'admins' => iamAdminCount(),
            'languages' => $pdo->query('SELECT DISTINCT language FROM users ORDER BY language')->fetchAll(PDO::FETCH_COLUMN),
            'applications' => $applications,
            'passwordMinLength' => PASSWORD_MIN,
        ]);
    });

    $group->get('/users/{id:[0-9]+}', function (Request $request, Response $response, array $args) {
        $user = adminUser(iamDb(), (int) $args['id']);

        return $user ? respondJson($response, $user) : respondError($response, 404, 'not_found', 'Not found');
    });

    // Everything about one account, spread over many tables, gathered so the
    // console can show a person rather than a row. What it deliberately does
    // not carry: the password hash, the TOTP secret, recovery codes, device
    // tokens - not even their hashes.
    $group->get('/users/{id:[0-9]+}/detail', function (Request $request, Response $response, array $args) {
        $detail = adminUserDetail(iamDb(), (int) $args['id']);

        return $detail ? respondJson($response, $detail) : respondError($response, 404, 'not_found', 'Not found');
    });

    // An account made by hand, for somebody who should not have to register.
    $group->post('/users', function (Request $request, Response $response) {
        $pdo = iamDb();
        $body = requestBody($request);
        $username = field($body, 'username');
        $email = field($body, 'email');
        $password = (string) ($body['password'] ?? '');

        if ($refusal = usernameRefusal($username)) {
            return respondError($response, 422, 'invalid_username', $refusal);
        }
        if (!validEmailAddress($email)) {
            return respondError($response, 422, 'invalid_email', 'That does not look like an email address');
        }
        if ($refusal = passwordRefusal($password)) {
            return respondError($response, 422, 'invalid_password', $refusal);
        }
        if (emailTaken($pdo, $email)) {
            return respondError($response, 409, 'email_taken', 'That email address is already taken');
        }
        if (usernameTaken($pdo, $username)) {
            return respondError($response, 409, 'username_taken', 'That username is already taken');
        }

        $roles = roleIdsFor($pdo, is_array($body['roles'] ?? null) ? $body['roles'] : []);
        if (isset($roles['error'])) {
            return respondError($response, 422, 'invalid_role', $roles['error']);
        }

        $pdo->beginTransaction();
        try {
            // Confirmed: an admin typed the address in, so there is nobody to
            // confirm it to. The forced change is optional - the same form
            // makes an account for somebody in the room and for somebody who
            // will be sent the password.
            $insert = $pdo->prepare(
                'INSERT INTO users (username, email, password, email_confirmed, language, force_password_change)
                 VALUES (?, ?, ?, TRUE, ?, ?) RETURNING id'
            );
            $insert->execute([$username, $email, password_hash($password, PASSWORD_DEFAULT), languageOrDefault($body['language'] ?? null), pgBool(!empty($body['force_password_change']))]);
            $id = (int) $insert->fetchColumn();

            $grant = $pdo->prepare('INSERT INTO user_roles (user_id, role_id, granted_by) VALUES (?, ?, ?)');
            foreach ($roles['ids'] as $roleId) {
                $grant->execute([$id, $roleId, (int) actor($request)['id']]);
            }

            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        audit($request, (int) actor($request)['id'], 'user.created', 'user', $id, ['username' => $username, 'roles' => $body['roles'] ?? []]);

        return respondJson($response, adminUser($pdo, $id), 201);
    });

    $group->put('/users/{id:[0-9]+}', function (Request $request, Response $response, array $args) {
        $pdo = iamDb();
        $id = (int) $args['id'];
        $body = requestBody($request);
        $target = userById($pdo, $id);

        if ($target === null) {
            return respondError($response, 404, 'not_found', 'Not found');
        }

        $changes = [];

        if (array_key_exists('username', $body)) {
            $username = field($body, 'username');
            if ($refusal = usernameRefusal($username)) {
                return respondError($response, 422, 'invalid_username', $refusal);
            }
            if (usernameTaken($pdo, $username, $id)) {
                return respondError($response, 409, 'username_taken', 'That username is already taken');
            }
            $changes['username'] = $username;
        }

        if (array_key_exists('email', $body)) {
            $email = field($body, 'email');
            if (!validEmailAddress($email)) {
                return respondError($response, 422, 'invalid_email', 'That does not look like an email address');
            }
            if (emailTaken($pdo, $email, $id)) {
                return respondError($response, 409, 'email_taken', 'That email address is already taken');
            }
            $changes['email'] = $email;
        }

        if (array_key_exists('language', $body)) {
            $changes['language'] = languageOrDefault($body['language']);
        }
        if (array_key_exists('email_confirmed', $body)) {
            $changes['email_confirmed'] = pgBool(!empty($body['email_confirmed']));
        }
        // Settable both ways: required of an existing account whose password
        // was read out over the phone, or taken back off one flagged by mistake.
        if (array_key_exists('force_password_change', $body)) {
            $changes['force_password_change'] = pgBool(!empty($body['force_password_change']));
        }

        if ($changes === []) {
            return respondError($response, 422, 'nothing_to_change', 'Nothing to change');
        }

        $sets = implode(', ', array_map(static fn(string $column): string => $column . ' = ?', array_keys($changes)));
        $pdo->prepare("UPDATE users SET $sets WHERE id = ?")->execute([...array_values($changes), $id]);
        audit($request, (int) actor($request)['id'], 'user.updated', 'user', $id, array_keys($changes));

        return respondJson($response, adminUser($pdo, $id));
    });

    // A new password without the old one, which is what "I am locked out"
    // needs. Every session of the account ends - this is what an admin reaches
    // for when an account is in somebody else's hands - except the admin's own
    // current one, if they are resetting themselves.
    $group->put('/users/{id:[0-9]+}/password', function (Request $request, Response $response, array $args) {
        $pdo = iamDb();
        $id = (int) $args['id'];
        $body = requestBody($request);
        $password = (string) ($body['password'] ?? '');

        if (userById($pdo, $id) === null) {
            return respondError($response, 404, 'not_found', 'Not found');
        }
        if ($refusal = passwordRefusal($password)) {
            return respondError($response, 422, 'invalid_password', $refusal);
        }

        $force = array_key_exists('force_password_change', $body) ? !empty($body['force_password_change']) : true;
        $pdo->prepare('UPDATE users SET password = ?, password_login_enabled = TRUE, force_password_change = ? WHERE id = ?')
            ->execute([password_hash($password, PASSWORD_DEFAULT), pgBool($force), $id]);

        $session = $request->getAttribute('session');
        $keep = (int) actor($request)['id'] === $id ? (int) $session['id'] : null;
        $ended = revokeSessionsFor($pdo, $id, SESSION_REVOKED_PASSWORD, $keep);
        revokeTrustedDevices($pdo, $id);
        audit($request, (int) actor($request)['id'], 'user.password_set', 'user', $id, ['force_change' => $force]);

        return respondJson($response, [
            'message' => 'Password changed',
            'note' => $ended === 1 ? '1 session was signed out.' : $ended . ' sessions were signed out.',
        ]);
    });

    // Switching an account off ends every session and refresh token it holds,
    // on every site. Nothing is destroyed; switching it back on restores it.
    $group->put('/users/{id:[0-9]+}/enabled', function (Request $request, Response $response, array $args) {
        $pdo = iamDb();
        $id = (int) $args['id'];
        $body = requestBody($request);

        if (!array_key_exists('enabled', $body)) {
            return respondError($response, 422, 'invalid_input', 'enabled is required');
        }

        $enabled = (bool) $body['enabled'];

        if (userById($pdo, $id) === null) {
            return respondError($response, 404, 'not_found', 'Not found');
        }

        // Locking yourself out of the only admin account is a five-second
        // mistake and a long evening with a SQL prompt.
        if (!$enabled && $id === (int) actor($request)['id']) {
            return respondError($response, 422, 'self_lockout', 'You cannot switch off your own account');
        }

        lockIamAdmins($pdo);
        try {
            if (!$enabled && isIamAdmin($id) && iamAdminCount() <= 1) {
                $pdo->rollBack();
                return respondError($response, 422, 'last_admin', 'This is the only IAM administrator left');
            }

            $pdo->prepare('UPDATE users SET disabled = ? WHERE id = ?')->execute([pgBool(!$enabled), $id]);
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        if (!$enabled) {
            revokeSessionsFor($pdo, $id, SESSION_REVOKED_DISABLED);
            $pdo->prepare("UPDATE refresh_tokens SET revoked_at = CURRENT_TIMESTAMP, revoked_reason = 'account_disabled'
                            WHERE user_id = ? AND revoked_at IS NULL")->execute([$id]);
        }

        audit($request, (int) actor($request)['id'], $enabled ? 'user.enabled' : 'user.disabled', 'user', $id);

        return respondJson($response, adminUser($pdo, $id));
    });

    // Two-factor off, for somebody who lost the phone and the recovery codes.
    $group->delete('/users/{id:[0-9]+}/2fa', function (Request $request, Response $response, array $args) {
        $pdo = iamDb();
        $id = (int) $args['id'];

        if (userById($pdo, $id) === null) {
            return respondError($response, 404, 'not_found', 'Not found');
        }

        $pdo->prepare('UPDATE users SET totp_enabled = FALSE, totp_secret = NULL WHERE id = ?')->execute([$id]);
        $pdo->prepare('DELETE FROM user_recovery_codes WHERE user_id = ?')->execute([$id]);
        revokeTrustedDevices($pdo, $id);
        audit($request, (int) actor($request)['id'], 'user.2fa_reset', 'user', $id);

        return respondJson($response, adminUser($pdo, $id));
    });

    // The complete set of an account's roles, replacing what it had.
    $group->put('/users/{id:[0-9]+}/roles', function (Request $request, Response $response, array $args) {
        $pdo = iamDb();
        $id = (int) $args['id'];
        $actorId = (int) actor($request)['id'];
        $grants = requestBody($request)['roles'] ?? null;

        if (userById($pdo, $id) === null) {
            return respondError($response, 404, 'not_found', 'Not found');
        }
        if (!is_array($grants)) {
            return respondError($response, 422, 'invalid_input', 'roles must be a list of { client_id, role }');
        }

        $roles = roleIdsFor($pdo, $grants);
        if (isset($roles['error'])) {
            return respondError($response, 422, 'invalid_role', $roles['error']);
        }

        $keepsAdmin = grantsIamAdmin($pdo, $roles['ids']);
        if (!$keepsAdmin && $id === $actorId && isIamAdmin($id)) {
            return respondError($response, 422, 'self_lockout', 'You cannot take the IAM ADMIN role from yourself');
        }

        $before = rolesOf($pdo, $id);

        lockIamAdmins($pdo);
        try {
            if (!$keepsAdmin && isIamAdmin($id) && iamAdminCount() <= 1) {
                $pdo->rollBack();
                return respondError($response, 422, 'last_admin', 'This is the only IAM administrator left');
            }

            $pdo->prepare('DELETE FROM user_roles WHERE user_id = ?')->execute([$id]);
            $insert = $pdo->prepare('INSERT INTO user_roles (user_id, role_id, granted_by) VALUES (?, ?, ?)');
            foreach ($roles['ids'] as $roleId) {
                $insert->execute([$id, $roleId, $actorId]);
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        audit($request, $actorId, 'user.roles_set', 'user', $id, ['before' => $before, 'after' => rolesOf($pdo, $id)]);

        return respondJson($response, adminUserDetail($pdo, $id));
    });

    // ── Sessions ─────────────────────────────────────────────────────────────

    $group->get('/sessions', function (Request $request, Response $response) {
        $pdo = iamDb();
        $query = $request->getQueryParams();
        $where = ['1 = 1'];
        $params = [];

        if (($search = field($query, 'search')) !== '') {
            $where[] = '(u.username ILIKE ? OR u.email ILIKE ? OR s.ip ILIKE ? OR s.sid = ?)';
            array_push($params, '%' . $search . '%', '%' . $search . '%', '%' . $search . '%', $search);
        }
        if (($userId = (int) ($query['user_id'] ?? 0)) > 0) {
            $where[] = 's.user_id = ?';
            $params[] = $userId;
        }
        if (($method = field($query, 'method')) !== '') {
            $where[] = 's.method = ?';
            $params[] = $method;
        }

        $live = 's.revoked_at IS NULL AND s.expires_at > CURRENT_TIMESTAMP AND s.absolute_expires_at > CURRENT_TIMESTAMP';
        $status = field($query, 'status');
        if ($status === 'active') {
            $where[] = $live;
        } elseif ($status === 'ended') {
            $where[] = 'NOT (' . $live . ')';
        }

        $filter = implode(' AND ', $where);
        $statement = $pdo->prepare(
            "SELECT s.*, u.username, u.email,
                    COALESCE((SELECT json_agg(DISTINCT a.name) FROM refresh_tokens rt JOIN applications a ON a.id = rt.application_id
                               WHERE rt.session_id = s.id), '[]') AS applications
               FROM sessions s JOIN users u ON u.id = s.user_id
              WHERE $filter ORDER BY s.created_at DESC, s.id DESC LIMIT 200"
        );
        $statement->execute($params);

        $counted = $pdo->prepare("SELECT count(*) FROM sessions s JOIN users u ON u.id = s.user_id WHERE $filter");
        $counted->execute($params);

        return respondJson($response, [
            'sessions' => array_map(static fn(array $row): array => sessionEntry($row) + [
                'user_id' => (int) $row['user_id'],
                'username' => $row['username'],
                'email' => $row['email'],
            ], $statement->fetchAll()),
            'total' => (int) $counted->fetchColumn(),
            'active' => (int) $pdo->query("SELECT count(*) FROM sessions s WHERE $live")->fetchColumn(),
            'methods' => $pdo->query('SELECT DISTINCT method FROM sessions ORDER BY method')->fetchAll(PDO::FETCH_COLUMN),
        ]);
    });

    $group->delete('/sessions/{id:[0-9]+}', function (Request $request, Response $response, array $args) {
        $pdo = iamDb();
        $session = sessionById($pdo, (int) $args['id']);

        if ($session === null) {
            return respondError($response, 404, 'not_found', 'Not found');
        }

        revokeSession($pdo, (int) $session['id'], SESSION_REVOKED_ADMIN);
        audit($request, (int) actor($request)['id'], 'session.ended', 'session', (int) $session['id'], ['user_id' => (int) $session['user_id']]);

        return respondJson($response, ['message' => 'Session ended']);
    });

    // ── Email log ────────────────────────────────────────────────────────────

    $group->get('/emails/filters', function (Request $request, Response $response) {
        $pdo = iamDb();

        return respondJson($response, [
            'kinds' => $pdo->query('SELECT DISTINCT kind FROM email_log ORDER BY kind')->fetchAll(PDO::FETCH_COLUMN),
            'statuses' => $pdo->query('SELECT DISTINCT status FROM email_log ORDER BY status')->fetchAll(PDO::FETCH_COLUMN),
        ]);
    });

    $group->get('/emails', function (Request $request, Response $response) {
        $query = $request->getQueryParams();
        $pdo = iamDb();
        $where = [];
        $params = [];

        foreach (['kind' => 'e.kind = ?', 'status' => 'e.status = ?'] as $name => $condition) {
            if (($value = field($query, $name)) !== '') {
                $where[] = $condition;
                $params[] = $value;
            }
        }
        if (($recipient = field($query, 'recipient')) !== '') {
            $where[] = 'e.recipient ILIKE ?';
            $params[] = '%' . $recipient . '%';
        }
        if (($from = field($query, 'from')) !== '' && strtotime($from) !== false) {
            $where[] = 'e.created_at >= ?';
            $params[] = $from;
        }
        if (($to = field($query, 'to')) !== '' && strtotime($to) !== false) {
            $where[] = "e.created_at < (?::date + INTERVAL '1 day')";
            $params[] = $to;
        }

        $sql = $where ? ' WHERE ' . implode(' AND ', $where) : '';
        $page = max(1, (int) ($query['page'] ?? 1));
        $size = 50;

        $count = $pdo->prepare("SELECT count(*) FROM email_log e$sql");
        $count->execute($params);

        $rows = $pdo->prepare(
            "SELECT e.id, e.recipient, e.kind, e.subject, e.status, e.error, e.user_id, u.username, e.created_at
               FROM email_log e LEFT JOIN users u ON u.id = e.user_id
             $sql ORDER BY e.created_at DESC, e.id DESC LIMIT $size OFFSET " . (($page - 1) * $size)
        );
        $rows->execute($params);

        return respondJson($response, [
            'total' => (int) $count->fetchColumn(),
            'page' => $page,
            'pageSize' => $size,
            'items' => array_map(static fn(array $row): array => isoTimes($row, ['created_at']), $rows->fetchAll()),
        ]);
    });

    // ── Audit log ────────────────────────────────────────────────────────────

    $group->get('/audit', function (Request $request, Response $response) {
        $query = $request->getQueryParams();
        $pdo = iamDb();
        $where = [];
        $params = [];

        if (($actor = field($query, 'actor')) !== '') {
            $where[] = 'u.username ILIKE ?';
            $params[] = '%' . $actor . '%';
        }
        if (($action = field($query, 'action')) !== '') {
            $where[] = 'l.action LIKE ?';
            $params[] = $action . '%';
        }
        if (($targetType = field($query, 'target_type')) !== '') {
            $where[] = 'l.target_type = ?';
            $params[] = $targetType;
        }

        $sql = $where ? ' WHERE ' . implode(' AND ', $where) : '';
        $page = max(1, (int) ($query['page'] ?? 1));
        $size = 50;

        $count = $pdo->prepare("SELECT count(*) FROM audit_log l LEFT JOIN users u ON u.id = l.actor_id$sql");
        $count->execute($params);

        $rows = $pdo->prepare(
            "SELECT l.id, l.actor_id, u.username AS actor_username, l.action, l.target_type, l.target_id, l.details, l.ip, l.created_at
               FROM audit_log l LEFT JOIN users u ON u.id = l.actor_id
             $sql ORDER BY l.created_at DESC, l.id DESC LIMIT $size OFFSET " . (($page - 1) * $size)
        );
        $rows->execute($params);

        return respondJson($response, [
            'total' => (int) $count->fetchColumn(),
            'page' => $page,
            'pageSize' => $size,
            'items' => array_map(static fn(array $row): array => ['id' => (int) $row['id'], 'details' => json_decode((string) $row['details'], true)]
                + isoTimes($row, ['created_at']), $rows->fetchAll()),
        ]);
    });

    // ── Applications ─────────────────────────────────────────────────────────

    $group->get('/applications', function (Request $request, Response $response) {
        $pdo = iamDb();
        $ids = $pdo->query('SELECT id FROM applications ORDER BY sort_order, name')->fetchAll(PDO::FETCH_COLUMN);

        return respondJson($response, array_map(static fn($id) => adminApplication($pdo, (int) $id), $ids));
    });

    $group->get('/applications/{id:[0-9]+}', function (Request $request, Response $response, array $args) {
        $application = adminApplication(iamDb(), (int) $args['id']);

        return $application ? respondJson($response, $application) : respondError($response, 404, 'not_found', 'Not found');
    });

    $group->post('/applications', function (Request $request, Response $response) {
        $pdo = iamDb();
        $body = requestBody($request);
        $clientId = strtolower(field($body, 'client_id'));

        if (!preg_match('/^[a-z0-9][a-z0-9-]{1,63}$/', $clientId)) {
            return respondError($response, 422, 'invalid_client_id', 'The client id is 2-64 lowercase letters, digits and dashes');
        }
        if (applicationByClientId($clientId) !== null) {
            return respondError($response, 409, 'client_id_taken', 'That client id is already taken');
        }

        $insert = $pdo->prepare("INSERT INTO applications (client_id, name) VALUES (?, ?) RETURNING id");
        $insert->execute([$clientId, field($body, 'name') ?: $clientId]);
        $id = (int) $insert->fetchColumn();

        $saved = saveApplication($pdo, $id, $body, true);
        if (isset($saved['error'])) {
            $pdo->prepare('DELETE FROM applications WHERE id = ?')->execute([$id]);
            return respondError($response, 422, 'invalid_application', $saved['error']);
        }

        $secret = null;
        if (($body['type'] ?? 'public') === 'confidential') {
            $secret = randomSecret(32, 'iam_cs_');
            $pdo->prepare('UPDATE applications SET secret_hash = ? WHERE id = ?')->execute([password_hash($secret, PASSWORD_DEFAULT), $id]);
        }

        audit($request, (int) actor($request)['id'], 'application.created', 'application', $clientId);

        return respondJson($response, ['application' => adminApplication($pdo, $id), 'client_secret' => $secret], 201);
    });

    $group->put('/applications/{id:[0-9]+}', function (Request $request, Response $response, array $args) {
        $pdo = iamDb();
        $id = (int) $args['id'];
        $application = applicationById($id);

        if ($application === null) {
            return respondError($response, 404, 'not_found', 'Not found');
        }

        $saved = saveApplication($pdo, $id, requestBody($request), false);
        if (isset($saved['error'])) {
            return respondError($response, 422, 'invalid_application', $saved['error']);
        }

        audit($request, (int) actor($request)['id'], 'application.updated', 'application', $application['client_id'], $saved['changed']);

        return respondJson($response, adminApplication($pdo, $id));
    });

    $group->delete('/applications/{id:[0-9]+}', function (Request $request, Response $response, array $args) {
        $pdo = iamDb();
        $application = applicationById((int) $args['id']);

        if ($application === null) {
            return respondError($response, 404, 'not_found', 'Not found');
        }
        if (asBool($application['system'])) {
            return respondError($response, 422, 'system_application', 'The IAM itself cannot be deleted');
        }

        // Cascades take its URIs, origins, roles and grants, codes and tokens
        // with it; every token it issued stops working at the next refresh.
        $pdo->prepare('DELETE FROM applications WHERE id = ?')->execute([(int) $application['id']]);
        audit($request, (int) actor($request)['id'], 'application.deleted', 'application', $application['client_id']);

        return respondJson($response, ['message' => 'Application deleted']);
    });

    $group->post('/applications/{id:[0-9]+}/secret', function (Request $request, Response $response, array $args) {
        $pdo = iamDb();
        $application = applicationById((int) $args['id']);

        if ($application === null) {
            return respondError($response, 404, 'not_found', 'Not found');
        }
        if ($application['type'] !== 'confidential') {
            return respondError($response, 422, 'public_client', 'A public client has no secret');
        }

        $secret = randomSecret(32, 'iam_cs_');
        $pdo->prepare('UPDATE applications SET secret_hash = ? WHERE id = ?')->execute([password_hash($secret, PASSWORD_DEFAULT), (int) $application['id']]);
        audit($request, (int) actor($request)['id'], 'application.secret_rotated', 'application', $application['client_id']);

        return respondJson($response, ['client_secret' => $secret]);
    });

    $group->get('/applications/{id:[0-9]+}/members', function (Request $request, Response $response, array $args) {
        $statement = iamDb()->prepare(
            "SELECT u.id AS user_id, u.username, u.email, json_agg(r.name ORDER BY r.name) AS roles
               FROM user_roles ur JOIN application_roles r ON r.id = ur.role_id JOIN users u ON u.id = ur.user_id
              WHERE r.application_id = ? GROUP BY u.id, u.username, u.email ORDER BY u.username"
        );
        $statement->execute([(int) $args['id']]);

        return respondJson($response, ['members' => array_map(static fn(array $row): array => [
            'user_id' => (int) $row['user_id'],
            'username' => $row['username'],
            'email' => $row['email'],
            'roles' => json_decode((string) $row['roles'], true) ?: [],
        ], $statement->fetchAll())]);
    });

    // ── Settings ─────────────────────────────────────────────────────────────

    $group->get('/settings', function (Request $request, Response $response) {
        return respondJson($response, ['settings' => adminSettingRows()]);
    });

    // One request for the whole form, checked before anything is written: half
    // a saved form is worse than a refused one.
    $group->put('/settings', function (Request $request, Response $response) {
        $incoming = requestBody($request)['settings'] ?? null;

        if (!is_array($incoming)) {
            return respondError($response, 422, 'invalid_input', 'settings must be a list of { name, value }');
        }

        $known = settingsAll(true);
        $changes = [];

        foreach ($incoming as $entry) {
            $name = is_array($entry) ? (string) ($entry['name'] ?? '') : '';
            if (!isset($known[$name])) {
                return respondError($response, 422, 'unknown_setting', "There is no setting called '$name'");
            }
            $value = (string) ($entry['value'] ?? '');
            if ($refusal = settingValueRefusal($known[$name], $value)) {
                return respondError($response, 422, 'invalid_value', $refusal);
            }
            if ($value !== (string) $known[$name]['value']) {
                $changes[$name] = $value;
            }
        }

        $pdo = iamDb();
        $update = $pdo->prepare('UPDATE settings SET value = ?, updated_at = CURRENT_TIMESTAMP, updated_by = ? WHERE name = ?');
        foreach ($changes as $name => $value) {
            $update->execute([$value, (int) actor($request)['id'], $name]);
        }

        if ($changes !== []) {
            settingsAll(true);
            audit($request, (int) actor($request)['id'], 'settings.changed', 'settings', null, $changes);
        }

        return respondJson($response, ['settings' => adminSettingRows()]);
    });

    // ── Signing keys ─────────────────────────────────────────────────────────

    $group->get('/keys', function (Request $request, Response $response) {
        activeSigningKey();
        $rows = iamDb()->query('SELECT kid, alg, status, created_at, retired_at FROM signing_keys ORDER BY created_at DESC')->fetchAll();

        return respondJson($response, ['keys' => array_map(static fn(array $row): array => isoTimes($row, ['created_at', 'retired_at']), $rows)]);
    });

    $group->post('/keys/rotate', function (Request $request, Response $response) {
        $key = rotateSigningKey();
        audit($request, (int) actor($request)['id'], 'keys.rotated', 'key', $key['kid']);

        return respondJson($response, ['message' => 'A new signing key is active', 'kid' => $key['kid']]);
    });

    $group->delete('/keys/{kid}', function (Request $request, Response $response, array $args) {
        $statement = iamDb()->prepare("DELETE FROM signing_keys WHERE kid = ? AND status = 'retired'");
        $statement->execute([(string) $args['kid']]);

        if ($statement->rowCount() !== 1) {
            return respondError($response, 422, 'not_retired', 'Only a retired key can be removed');
        }

        audit($request, (int) actor($request)['id'], 'keys.removed', 'key', (string) $args['kid']);

        return respondJson($response, ['message' => 'Key removed']);
    });

    // ── Migrations ───────────────────────────────────────────────────────────

    $group->get('/migrations', function (Request $request, Response $response) {
        $pdo = iamDb();
        $pending = pendingMigrations($pdo, 'iam');
        $applied = $pdo->query('SELECT filename, applied_at FROM migrations ORDER BY id')->fetchAll();

        return respondJson($response, [
            'applied' => array_map(static fn(array $row): array => isoTimes($row, ['applied_at']), $applied),
            'pending' => $pending,
        ]);
    });

    $group->post('/migrations/apply', function (Request $request, Response $response) {
        $result = applyPendingMigrations(iamDb(), 'iam');
        audit($request, (int) actor($request)['id'], 'migrations.applied', null, null, $result);

        return respondJson($response, $result, $result['failed'] === null ? 200 : 500);
    });
})->add(requireAdmin())->add(requireSession());

// ─────────────────────────────────────────────────────────────────────────────
// Applications, in the admin console's shape
// ─────────────────────────────────────────────────────────────────────────────

function adminApplication(PDO $pdo, int $id): ?array
{
    $application = applicationById($id);

    if ($application === null) {
        return null;
    }

    $roles = $pdo->prepare(
        'SELECT r.name, r.description, (SELECT count(*) FROM user_roles ur WHERE ur.role_id = r.id) AS members
           FROM application_roles r WHERE r.application_id = ? ORDER BY r.name'
    );
    $roles->execute([$id]);

    return [
        'id' => $id,
        'client_id' => $application['client_id'],
        'name' => $application['name'],
        'description' => $application['description'],
        'home_url' => $application['home_url'],
        'logo_url' => $application['logo_url'],
        'type' => $application['type'],
        'enabled' => asBool($application['enabled']),
        'access' => $application['access'],
        'access_token_ttl' => (int) $application['access_token_ttl'],
        'redirect_uris' => applicationRedirectUris($id, 'login'),
        'post_logout_redirect_uris' => applicationRedirectUris($id, 'logout'),
        'web_origins' => applicationOrigins($id),
        'roles' => array_map(static fn(array $row): array => ['name' => $row['name'], 'description' => $row['description'], 'members' => (int) $row['members']], $roles->fetchAll()),
        'show_on_landing' => asBool($application['show_on_landing']),
        'sort_order' => (int) $application['sort_order'],
        'has_secret' => !empty($application['secret_hash']),
        'system' => asBool($application['system']),
        'created_at' => isoTime($application['created_at']),
        'updated_at' => isoTime($application['updated_at']),
    ];
}

/**
 * Writes the fields of an application that are present in the body.
 *
 * All of it in one transaction: the URIs, origins and roles are replaced as
 * sets, and an application left with half its redirect URIs is one that fails
 * in a way nobody would guess.
 *
 * @return array{changed: string[]}|array{error: string}
 */
function saveApplication(PDO $pdo, int $id, array $body, bool $creating): array
{
    $application = applicationById($id);
    $system = asBool($application['system']);
    $changes = [];

    $text = ['name' => 100, 'description' => 2000, 'home_url' => 500, 'logo_url' => 500];
    foreach ($text as $column => $max) {
        if (array_key_exists($column, $body)) {
            $value = field($body, $column);
            if ($column === 'name' && $value === '') {
                return ['error' => 'The name cannot be empty'];
            }
            if (mb_strlen($value) > $max) {
                return ['error' => "$column is too long"];
            }
            if (in_array($column, ['home_url', 'logo_url'], true) && $value !== '' && !validRedirectUri($value)) {
                return ['error' => "$column must be an absolute http(s) URL"];
            }
            $changes[$column] = $value === '' && $column !== 'name' ? null : $value;
        }
    }

    if (array_key_exists('type', $body)) {
        if (!in_array($body['type'], ['public', 'confidential'], true)) {
            return ['error' => 'type is public or confidential'];
        }
        $changes['type'] = $body['type'];
    }

    if (array_key_exists('access', $body)) {
        if (!in_array($body['access'], ['everyone', 'members'], true)) {
            return ['error' => 'access is everyone or members'];
        }
        $changes['access'] = $body['access'];
    }

    if (array_key_exists('access_token_ttl', $body)) {
        $ttl = (int) $body['access_token_ttl'];
        if ($ttl < 60 || $ttl > 3600) {
            return ['error' => 'The access token lifetime is between 60 and 3600 seconds'];
        }
        $changes['access_token_ttl'] = $ttl;
    }

    foreach (['enabled', 'show_on_landing'] as $flag) {
        if (array_key_exists($flag, $body)) {
            if ($system && $flag === 'enabled' && empty($body['enabled'])) {
                return ['error' => 'The IAM itself cannot be switched off'];
            }
            $changes[$flag] = pgBool(!empty($body[$flag]));
        }
    }

    if (array_key_exists('sort_order', $body)) {
        $changes['sort_order'] = (int) $body['sort_order'];
    }

    $lists = [];
    foreach (['redirect_uris' => 'login', 'post_logout_redirect_uris' => 'logout'] as $field => $kind) {
        if (array_key_exists($field, $body)) {
            if (!is_array($body[$field])) {
                return ['error' => "$field must be a list"];
            }
            $uris = array_values(array_unique(array_map(static fn($uri): string => trim((string) $uri), $body[$field])));
            foreach ($uris as $uri) {
                if (!validRedirectUri($uri)) {
                    return ['error' => "'$uri' is not an absolute http(s) URL without a fragment"];
                }
            }
            $lists[$kind] = $uris;
        }
    }

    $origins = null;
    if (array_key_exists('web_origins', $body)) {
        if (!is_array($body['web_origins'])) {
            return ['error' => 'web_origins must be a list'];
        }
        $origins = array_values(array_unique(array_map(static fn($origin): string => rtrim(trim((string) $origin), '/'), $body['web_origins'])));
        foreach ($origins as $origin) {
            if (!validOrigin($origin)) {
                return ['error' => "'$origin' is not an origin (scheme://host[:port])"];
            }
        }
    }

    $roles = null;
    if (array_key_exists('roles', $body)) {
        if (!is_array($body['roles'])) {
            return ['error' => 'roles must be a list'];
        }
        $roles = [];
        foreach ($body['roles'] as $role) {
            $name = strtoupper(trim((string) (is_array($role) ? ($role['name'] ?? '') : $role)));
            if (!preg_match('/^[A-Z][A-Z0-9_]{0,49}$/', $name)) {
                return ['error' => "'$name' is not a role name (capitals, digits, underscores)"];
            }
            $roles[$name] = is_array($role) ? (trim((string) ($role['description'] ?? '')) ?: null) : null;
        }
        if ($system && !array_key_exists('ADMIN', $roles)) {
            return ['error' => 'The IAM must keep its ADMIN role'];
        }
    }

    $pdo->beginTransaction();
    try {
        if ($changes !== []) {
            $sets = implode(', ', array_map(static fn(string $column): string => $column . ' = ?', array_keys($changes)));
            $pdo->prepare("UPDATE applications SET $sets WHERE id = ?")->execute([...array_values($changes), $id]);
        }

        foreach ($lists as $kind => $uris) {
            $pdo->prepare('DELETE FROM application_redirect_uris WHERE application_id = ? AND kind = ?')->execute([$id, $kind]);
            $insert = $pdo->prepare('INSERT INTO application_redirect_uris (application_id, kind, uri) VALUES (?, ?, ?)');
            foreach ($uris as $uri) {
                $insert->execute([$id, $kind, $uri]);
            }
        }

        if ($origins !== null) {
            $pdo->prepare('DELETE FROM application_origins WHERE application_id = ?')->execute([$id]);
            $insert = $pdo->prepare('INSERT INTO application_origins (application_id, origin) VALUES (?, ?)');
            foreach ($origins as $origin) {
                $insert->execute([$id, $origin]);
            }
        }

        if ($roles !== null) {
            // A role somebody still holds is not removed from under them: the
            // grants would cascade away silently. Take it from them first.
            $existing = $pdo->prepare(
                'SELECT r.id, r.name, (SELECT count(*) FROM user_roles ur WHERE ur.role_id = r.id) AS members FROM application_roles r WHERE r.application_id = ?'
            );
            $existing->execute([$id]);
            foreach ($existing->fetchAll() as $row) {
                if (!array_key_exists($row['name'], $roles)) {
                    if ((int) $row['members'] > 0) {
                        throw new DomainException("The role {$row['name']} is still held by {$row['members']} account(s) - take it from them first");
                    }
                    $pdo->prepare('DELETE FROM application_roles WHERE id = ?')->execute([(int) $row['id']]);
                }
            }
            $upsert = $pdo->prepare(
                'INSERT INTO application_roles (application_id, name, description) VALUES (?, ?, ?)
                 ON CONFLICT (application_id, name) DO UPDATE SET description = EXCLUDED.description'
            );
            foreach ($roles as $name => $description) {
                $upsert->execute([$id, $name, $description]);
            }
        }

        $pdo->commit();
    } catch (DomainException $e) {
        $pdo->rollBack();
        return ['error' => $e->getMessage()];
    } catch (\Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    return ['changed' => array_merge(array_keys($changes), array_keys($lists), $origins !== null ? ['web_origins'] : [], $roles !== null ? ['roles'] : [])];
}

function adminSettingRows(): array
{
    $statement = iamDb()->query(
        'SELECT s.name, s.group_name, s.type, s.options, s.value, s.updated_at, u.username AS updated_by
           FROM settings s LEFT JOIN users u ON u.id = s.updated_by ORDER BY s.sort_order, s.name'
    );

    return array_map(static fn(array $row): array => [
        'name' => $row['name'],
        'group_name' => $row['group_name'],
        'type' => $row['type'],
        'options' => $row['options'] === null || $row['options'] === '' ? [] : array_map('trim', explode(',', (string) $row['options'])),
        'value' => $row['value'],
        'updated_at' => isoTime($row['updated_at']),
        'updated_by' => $row['updated_by'],
    ], $statement->fetchAll());
}
