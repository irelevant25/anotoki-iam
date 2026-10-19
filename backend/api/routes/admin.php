<?php

use Anotoki\Lib\Audit\Http\AuditRoutes;
use Anotoki\Lib\Backups\Http\BackupsRoutes;
use Anotoki\Lib\ErrorLog\Http\ErrorLogRoutes;
use Anotoki\Lib\Feedback\Http\FeedbackRoutes;
use Anotoki\Lib\Migrations\Http\MigrationsRoutes;
use Anotoki\Lib\Migrations\Migrator;
use Anotoki\Lib\Translations\Http\TranslationsRoutes;
use Anotoki\Lib\Translations\Translations;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;

// ---------------------------------------------------------------------------
// The admin panel
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
          WHERE ur.user_id = ? ORDER BY a.sort_order, a.client_id, r.name',
    );
    $statement->execute([$userId]);

    return $statement->fetchAll();
}

/**
 * Whether the account `$user` counts as blocked from the application `$application`
 * (SQL over those two aliases): it holds no role there that may sign in, and
 * either its MEMBER there was taken away or it holds another role there. An
 * account holding just MEMBER where MEMBER may not sign in (a members-only
 * site) was simply never invited - it is not listed, as the account page does
 * not list such a site to it, or every ordinary account would be "blocked"
 * there. Reads may_sign_in: only once 010 is applied.
 */
function blockedFromSql(string $user, string $application): string
{
    return "NOT EXISTS (SELECT 1 FROM user_roles bur JOIN application_roles br ON br.id = bur.role_id
                         WHERE bur.user_id = {$user}.id AND br.application_id = {$application}.id AND br.may_sign_in)
            AND (NOT EXISTS (SELECT 1 FROM user_roles bur JOIN application_roles br ON br.id = bur.role_id
                              WHERE bur.user_id = {$user}.id AND br.application_id = {$application}.id AND br.name = 'MEMBER')
                 OR EXISTS (SELECT 1 FROM user_roles bur JOIN application_roles br ON br.id = bur.role_id
                             WHERE bur.user_id = {$user}.id AND br.application_id = {$application}.id AND br.name <> 'MEMBER'))";
}

/**
 * The admin panel's account rows. `blocked_in` - the switched-on sites the
 * account is blocked from (blockedFromSql()) - needs 010's may_sign_in; while
 * 010 is pending it is empty, so the users pages keep working in the minutes
 * between an upload and Apply. `approved` needs 012, and reads as true while
 * it is pending: nobody can wait before it. The deletion's two times need
 * 016, and are null while it is pending; an anonymized account holds no role
 * anywhere, and is blocked nowhere - there is nobody to block.
 */
function adminUserSelect(PDO $pdo): string
{
    $deletion = accountDeletionReady($pdo);
    $blockedIn = signInRulesReady($pdo)
        ? "COALESCE((SELECT json_agg(a.client_id ORDER BY a.sort_order, a.client_id) FROM applications a
                      WHERE a.system = FALSE AND a.enabled AND " .
            blockedFromSql('u', 'a') .
            "), '[]')"
        : "'[]'";
    if ($deletion) {
        $blockedIn = "CASE WHEN u.anonymized_at IS NOT NULL THEN '[]' ELSE {$blockedIn} END";
    }
    $approved = accountApprovalReady($pdo) ? 'u.approved' : 'TRUE';
    $deletionColumns = $deletion
        ? 'CASE WHEN u.anonymized_at IS NULL THEN u.deletion_due_at END AS deletion_due_at, u.anonymized_at'
        : 'NULL::timestamptz AS deletion_due_at, NULL::timestamptz AS anonymized_at';

    return "SELECT u.id, u.username, u.email, u.email_confirmed, u.language, u.disabled, u.totp_enabled,
        u.force_password_change, (u.password IS NOT NULL) AS has_password, u.last_login_at, u.created_at, u.updated_at,
        EXISTS (SELECT 1 FROM user_identities i WHERE i.user_id = u.id AND i.provider = 'google') AS google_connected,
        (SELECT i.email FROM user_identities i WHERE i.user_id = u.id AND i.provider = 'google' LIMIT 1) AS google_email,
        COALESCE((SELECT json_agg(json_build_object('client_id', a.client_id, 'role', r.name) ORDER BY a.sort_order, r.name)
                    FROM user_roles ur JOIN application_roles r ON r.id = ur.role_id
                    JOIN applications a ON a.id = r.application_id WHERE ur.user_id = u.id), '[]') AS roles,
        {$blockedIn} AS blocked_in,
        {$approved} AS approved,
        {$deletionColumns}
   FROM users u";
}

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
        'blocked_in' => json_decode((string) $row['blocked_in'], true) ?: [],
        'approved' => asBool($row['approved']),
        'last_login_at' => isoTime($row['last_login_at']),
        'created_at' => isoTime($row['created_at']),
        'updated_at' => isoTime($row['updated_at']),
        // The deletion its owner asked for: when it is due, while it is
        // pending; when it was done. An anonymized account changes no more
        // (anonymizedRefusal()).
        'deletion_due_at' => isoTime($row['deletion_due_at']),
        'anonymized_at' => isoTime($row['anonymized_at']),
    ];
}

function adminUser(PDO $pdo, int $id): ?array
{
    $statement = $pdo->prepare(adminUserSelect($pdo) . ' WHERE u.id = ?');
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

    $attempts = $pdo->prepare('SELECT id, email, ip, method, outcome, created_at FROM login_attempts WHERE user_id = ? ORDER BY created_at DESC, id DESC LIMIT 50');
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
          WHERE r.id IN ($placeholders) AND a.client_id = ? AND r.name = 'ADMIN'",
    );
    $statement->execute([...$roleIds, IAM_CLIENT_ID]);

    return (bool) $statement->fetchColumn();
}

/**
 * Switches an account off: every session and refresh token it holds ends, on
 * every site. Refused for the last administrator of the IAM - checked under
 * lockIamAdmins(), so two administrators switching each other off at the same
 * moment cannot both win. "The last" is whether any other would be left
 * (iamAdminCount() besides it), so an administrator on their way out - their
 * deletion pending - can be switched off while one who stays remains. Whether
 * the account may be switched off by this actor at all (not themselves) is
 * the caller's.
 *
 * @return array{status: int, code: string, error: string}|null
 */
function switchOffAccount(PDO $pdo, int $id): ?array
{
    lockIamAdmins($pdo);
    try {
        if (isIamAdmin($id) && iamAdminCount($id) === 0) {
            $pdo->rollBack();
            return ['status' => 422, 'code' => 'last_admin', 'error' => 'This is the only IAM administrator left'];
        }

        $pdo->prepare('UPDATE users SET disabled = TRUE WHERE id = ?')->execute([$id]);
        $pdo->commit();
    } catch (\Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    revokeSessionsFor($pdo, $id, SESSION_REVOKED_DISABLED);
    $pdo->prepare(
        "UPDATE refresh_tokens SET revoked_at = CURRENT_TIMESTAMP, revoked_reason = 'account_disabled'
                    WHERE user_id = ? AND revoked_at IS NULL",
    )->execute([$id]);

    return null;
}

/**
 * The accounts waiting for an administrator's approval (Settings -> New
 * accounts need approval), oldest first - only those switched on: one that is
 * switched off was declined, or is somebody's business other than approval.
 */
function waitingAccounts(PDO $pdo): array
{
    $rows = $pdo
        ->query(
            'SELECT id, username, email, email_confirmed, created_at FROM users
          WHERE NOT approved AND NOT disabled ORDER BY created_at, id',
        )
        ->fetchAll();

    return array_map(
        static fn(array $row): array => [
            'user_id' => (int) $row['id'],
            'username' => $row['username'],
            'email' => $row['email'],
            'email_confirmed' => asBool($row['email_confirmed']),
            'created_at' => isoTime($row['created_at']),
        ],
        $rows,
    );
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
            'awaiting_approval' => accountApprovalReady($pdo) ? $count('SELECT count(*) FROM users WHERE NOT approved AND NOT disabled') : 0,
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

        // An application without a role: a role there beyond MEMBER, which
        // every account holds (010) - "everybody" would be no filter at all.
        // With a role, MEMBER included, exactly that role.
        if (($clientId = field($query, 'application')) !== '') {
            $role = strtoupper(field($query, 'role'));
            $where[] =
                'EXISTS (SELECT 1 FROM user_roles ur JOIN application_roles r ON r.id = ur.role_id
                                 JOIN applications a ON a.id = r.application_id
                                WHERE ur.user_id = u.id AND a.client_id = ?' .
                ($role !== '' ? ' AND r.name = ?' : " AND r.name <> 'MEMBER'") .
                ')';
            $params[] = $clientId;
            if ($role !== '') {
                $params[] = $role;
            }
        }

        $statement = iamDb()->prepare(adminUserSelect(iamDb()) . ' WHERE ' . implode(' AND ', $where) . ' ORDER BY u.created_at DESC, u.id DESC');
        $statement->execute($params);

        return respondJson($response, array_map('adminUserShape', $statement->fetchAll()));
    });

    $group->get('/users/filters', function (Request $request, Response $response) {
        $pdo = iamDb();
        $applications = [];
        // Which roles let somebody in, for the role editor's "cannot sign in"
        // warning; every role while 010 is pending (the rule before it had
        // no such thing).
        $ready = signInRulesReady($pdo);

        foreach ($pdo->query('SELECT id, client_id, name, system FROM applications ORDER BY sort_order, name')->fetchAll() as $application) {
            $roles = $pdo->prepare('SELECT name, ' . ($ready ? 'may_sign_in' : 'TRUE') . ' AS may_sign_in FROM application_roles WHERE application_id = ? ORDER BY name');
            $roles->execute([(int) $application['id']]);
            $rows = $roles->fetchAll();
            $applications[] = [
                'client_id' => $application['client_id'],
                'name' => $application['name'],
                'system' => asBool($application['system']),
                'roles' => array_column($rows, 'name'),
                'sign_in_roles' => array_values(array_column(array_filter($rows, static fn(array $row): bool => asBool($row['may_sign_in'])), 'name')),
            ];
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
    // panel can show a person rather than a row. What it deliberately does
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
        // English when none is named. One that is named must be on offer: it
        // used to be turned into English without a word, and the admin who
        // picked it found out from the account's first mail.
        $language = 'en';
        if (($body['language'] ?? null) !== null) {
            $language = is_string($body['language']) ? strtolower(trim($body['language'])) : '';
            if (!in_array($language, languageCodes(), true)) {
                return respondError($response, 422, 'invalid_language', 'That is not a language the IAM offers');
            }
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
                 VALUES (?, ?, ?, TRUE, ?, ?) RETURNING id',
            );
            $insert->execute([$username, $email, password_hash($password, PASSWORD_DEFAULT), $language, pgBool(!empty($body['force_password_change']))]);
            $id = (int) $insert->fetchColumn();

            // The new account holds MEMBER everywhere already (010's
            // trigger), so a body naming a MEMBER asks for what is there.
            $grant = $pdo->prepare('INSERT INTO user_roles (user_id, role_id, granted_by) VALUES (?, ?, ?) ON CONFLICT DO NOTHING');
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
        if ($refusal = anonymizedRefusal($target)) {
            return respondError($response, $refusal['status'], $refusal['code'], $refusal['error']);
        }

        $changes = [];
        $nameSentUnchanged = false;

        if (array_key_exists('username', $body)) {
            if (usernameUnchanged($body, $target)) {
                // The name the account has, sent back: no change, so never
                // held to the rule - a name from before 2026-10-19 that does
                // not fit must not stop an administrator changing anything
                // else. And never written: the copy read above may be older
                // than a rename the account made meanwhile, which writing it
                // back would undo (the security review of 2026-10-19).
                $nameSentUnchanged = true;
            } else {
                // A new name keeps the rule (letters and digits, usernameRefusal()).
                $username = field($body, 'username');
                if ($refusal = usernameRefusal($username)) {
                    return respondError($response, 422, 'invalid_username', $refusal);
                }
                if (usernameTaken($pdo, $username, $id)) {
                    return respondError($response, 409, 'username_taken', 'That username is already taken');
                }
                $changes['username'] = $username;
            }
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
            $language = is_string($body['language']) ? strtolower(trim($body['language'])) : '';
            if (in_array($language, languageCodes(), true)) {
                $changes['language'] = $language;
            } elseif ($language !== $target['language']) {
                // Refused rather than turned into English. The one the account
                // already has is let through as no change at all: a language
                // switched off since must not stand in the way of renaming
                // somebody who still has it.
                return respondError($response, 422, 'invalid_language', 'That is not a language the IAM offers');
            }
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
            // Only the name it has, sent back: the account as it is, nothing written or audited.
            if ($nameSentUnchanged) {
                return respondJson($response, adminUser($pdo, $id));
            }

            return respondError($response, 422, 'nothing_to_change', 'Nothing to change');
        }

        $sets = implode(', ', array_map(static fn(string $column): string => $column . ' = ?', array_keys($changes)));
        $updated = $pdo->prepare("UPDATE users SET $sets WHERE id = ?" . liveAccountSql($pdo));
        $updated->execute([...array_values($changes), $id]);
        // Deleted since it was read above: nothing written onto the placeholder.
        if ($updated->rowCount() === 0 && ($refusal = anonymizedRefusal(userById($pdo, $id) ?? []))) {
            return respondError($response, $refusal['status'], $refusal['code'], $refusal['error']);
        }
        // A confirmation link names the account, not the address it went to:
        // an address changed, confirmed or to be confirmed again here leaves
        // none of the links mailed before good - one would confirm an address
        // it never reached, or sign in by a mailbox (spendConfirmationLinks()).
        $addressChanged = isset($changes['email']) && strtolower((string) $changes['email']) !== strtolower((string) $target['email']);
        $confirmationChanged = isset($changes['email_confirmed']) && !empty($body['email_confirmed']) !== asBool($target['email_confirmed']);
        if ($addressChanged || $confirmationChanged) {
            spendConfirmationLinks($pdo, $id);
        }
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
        $target = userById($pdo, $id);

        if ($target === null) {
            return respondError($response, 404, 'not_found', 'Not found');
        }
        if ($refusal = anonymizedRefusal($target)) {
            return respondError($response, $refusal['status'], $refusal['code'], $refusal['error']);
        }
        if ($refusal = passwordRefusal($password)) {
            return respondError($response, 422, 'invalid_password', $refusal);
        }

        $force = array_key_exists('force_password_change', $body) ? !empty($body['force_password_change']) : true;
        $set = $pdo->prepare('UPDATE users SET password = ?, password_login_enabled = TRUE, force_password_change = ? WHERE id = ?' . liveAccountSql($pdo));
        $set->execute([password_hash($password, PASSWORD_DEFAULT), pgBool($force), $id]);
        // Deleted since it was read above: no password goes onto the placeholder.
        if ($set->rowCount() === 0 && ($refusal = anonymizedRefusal(userById($pdo, $id) ?? []))) {
            return respondError($response, $refusal['status'], $refusal['code'], $refusal['error']);
        }

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
        $target = userById($pdo, $id);

        if ($target === null) {
            return respondError($response, 404, 'not_found', 'Not found');
        }
        // Switched on again, an anonymized account would be its old id back -
        // the `sub` whose data every site has made nobody's.
        if ($refusal = anonymizedRefusal($target)) {
            return respondError($response, $refusal['status'], $refusal['code'], $refusal['error']);
        }

        // Locking yourself out of the only admin account is a five-second
        // mistake and a long evening with a SQL prompt.
        if (!$enabled && $id === (int) actor($request)['id']) {
            return respondError($response, 422, 'self_lockout', 'You cannot switch off your own account');
        }

        if (!$enabled) {
            if ($refusal = switchOffAccount($pdo, $id)) {
                return respondError($response, $refusal['status'], $refusal['code'], $refusal['error']);
            }
        } else {
            // Decided in the UPDATE too: deleted since it was read above, it
            // stays switched off.
            $on = $pdo->prepare('UPDATE users SET disabled = FALSE WHERE id = ?' . liveAccountSql($pdo));
            $on->execute([$id]);
            if ($on->rowCount() === 0 && ($refusal = anonymizedRefusal(userById($pdo, $id) ?? []))) {
                return respondError($response, $refusal['status'], $refusal['code'], $refusal['error']);
            }
        }

        audit($request, (int) actor($request)['id'], $enabled ? 'user.enabled' : 'user.disabled', 'user', $id);

        return respondJson($response, adminUser($pdo, $id));
    });

    // ── Approvals ────────────────────────────────────────────────────────────
    //
    // The accounts made by signing up while Settings -> New accounts need
    // approval is on (api/accounts.php), waiting for an administrator. All
    // three answer 503 sign_in_rules_unavailable while migration 012 is
    // pending.

    $group->get('/approvals', function (Request $request, Response $response) {
        return respondJson($response, ['accounts' => waitingAccounts(iamDb()), 'account_approval' => registrationApprovalRequired()]);
    });

    // Yes, to an account that waits and is switched on - one declined is
    // switched on first, and waits again. The person is mailed once their
    // address is confirmed: an account approved before that is signed in by
    // its confirmation link, and needs no mail to say so.
    $group->post('/users/{id:[0-9]+}/approve', function (Request $request, Response $response, array $args) {
        $pdo = iamDb();
        $id = (int) $args['id'];
        $target = userById($pdo, $id);

        if ($target === null) {
            return respondError($response, 404, 'not_found', 'Not found');
        }
        if ($refusal = anonymizedRefusal($target)) {
            return respondError($response, $refusal['status'], $refusal['code'], $refusal['error']);
        }
        $approved = approveAccount($pdo, $id);
        if ($approved === null) {
            return respondError(
                $response,
                409,
                'not_waiting',
                $target['username'] .
                    (accountAwaitsApproval($target) && asBool($target['disabled'])
                        ? ' is switched off - switch the account on first, and it waits for approval again'
                        : ' is not waiting for approval'),
            );
        }
        audit($request, (int) actor($request)['id'], 'user.approved', 'user', $id);

        $mailed = asBool($approved['email_confirmed']) && sendAccountApprovedMail($approved);

        return respondJson($response, ['account' => adminUser($pdo, $id), 'mailed' => $mailed]);
    });

    // No: the account is switched off. Nothing is deleted, its address stays
    // taken, and switching it on again puts it back among the waiting.
    // Nobody is mailed.
    //
    // Not while its address is unconfirmed: anybody can register an address
    // they do not hold, and the address's owner takes such an account back by
    // a reset link or Google (claimUnconfirmedAccount()) - which nothing does
    // for an account switched off. Declined, a stranger's registration would
    // keep the owner out for good. Unconfirmed, it cannot sign in anyway.
    $group->post('/users/{id:[0-9]+}/decline', function (Request $request, Response $response, array $args) {
        $pdo = iamDb();
        $id = (int) $args['id'];
        $target = userById($pdo, $id);

        if ($target === null) {
            return respondError($response, 404, 'not_found', 'Not found');
        }
        if ($refusal = anonymizedRefusal($target)) {
            return respondError($response, $refusal['status'], $refusal['code'], $refusal['error']);
        }
        if (!accountAwaitsApproval($target) || asBool($target['disabled'])) {
            return respondError($response, 409, 'not_waiting', $target['username'] . (asBool($target['disabled']) ? ' is switched off already' : ' is not waiting for approval'));
        }
        if (!asBool($target['email_confirmed'])) {
            return respondError(
                $response,
                409,
                'not_confirmed',
                $target['username'] .
                    '\'s address is not confirmed: whoever registered it may not own it, and declining would keep its owner out. It cannot sign in until the address is confirmed anyway',
            );
        }
        if ($refusal = switchOffAccount($pdo, $id)) {
            return respondError($response, $refusal['status'], $refusal['code'], $refusal['error']);
        }
        audit($request, (int) actor($request)['id'], 'user.declined', 'user', $id);

        return respondJson($response, ['account' => adminUser($pdo, $id), 'mailed' => false]);
    });

    // Two-factor off, for somebody who lost the phone and the recovery codes.
    $group->delete('/users/{id:[0-9]+}/2fa', function (Request $request, Response $response, array $args) {
        $pdo = iamDb();
        $id = (int) $args['id'];
        $target = userById($pdo, $id);

        if ($target === null) {
            return respondError($response, 404, 'not_found', 'Not found');
        }
        if ($refusal = anonymizedRefusal($target)) {
            return respondError($response, $refusal['status'], $refusal['code'], $refusal['error']);
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
        $target = userById($pdo, $id);

        if ($target === null) {
            return respondError($response, 404, 'not_found', 'Not found');
        }
        if ($refusal = anonymizedRefusal($target)) {
            return respondError($response, $refusal['status'], $refusal['code'], $refusal['error']);
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
        // An account that waits for approval signs in nowhere, so it can
        // administer nothing - yet the last-admin rule would count it as an
        // administrator left. It is approved first.
        if ($keepsAdmin && !isIamAdmin($id) && accountAwaitsApproval($target)) {
            return respondError($response, 422, 'not_approved', $target['username'] . ' is waiting for approval - approve the account before making it an administrator of the IAM');
        }

        $before = rolesOf($pdo, $id);

        lockIamAdmins($pdo);
        try {
            if (!$keepsAdmin && isIamAdmin($id) && iamAdminCount($id) === 0) {
                $pdo->rollBack();
                return respondError($response, 422, 'last_admin', 'This is the only IAM administrator left');
            }
            // Read again under the lock an anonymization takes too: deleted
            // since it was read above, it is given no role.
            if ($refusal = anonymizedRefusal(userById($pdo, $id) ?? [])) {
                $pdo->rollBack();
                return respondError($response, $refusal['status'], $refusal['code'], $refusal['error']);
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
              WHERE $filter ORDER BY s.created_at DESC, s.id DESC LIMIT 200",
        );
        $statement->execute($params);

        $counted = $pdo->prepare("SELECT count(*) FROM sessions s JOIN users u ON u.id = s.user_id WHERE $filter");
        $counted->execute($params);

        return respondJson($response, [
            'sessions' => array_map(
                static fn(array $row): array => sessionEntry($row) + [
                    'user_id' => (int) $row['user_id'],
                    'username' => $row['username'],
                    'email' => $row['email'],
                ],
                $statement->fetchAll(),
            ),
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
        $ended = respondJson($response, ['message' => 'Session ended']);

        // The administrator's own session, from this very browser: answered
        // as a sign-out is, its cookie and its sign-in hint gone with it.
        return (int) $session['id'] === (int) $request->getAttribute('session')['id'] ? withoutSignInHint(withoutSessionCookie($ended)) : $ended;
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
             $sql ORDER BY e.created_at DESC, e.id DESC LIMIT $size OFFSET " .
                ($page - 1) * $size,
        );
        $rows->execute($params);

        return respondJson($response, [
            'total' => (int) $count->fetchColumn(),
            'page' => $page,
            'pageSize' => $size,
            'items' => array_map(static fn(array $row): array => isoTimes($row, ['created_at']), $rows->fetchAll()),
        ]);
    });

    // ── Announcements ────────────────────────────────────────────────────────
    //
    // News an administrator mails to the people of the sites, and the
    // addresses it may come from (api/announcements.php). A group of their
    // own, like the strings': 503 `announcements_unavailable` until migration
    // 013 has been applied.
    //
    // Sending is the page's: Send makes the announcement and its list of
    // people, then the page asks for batch after batch (/send) until nobody is
    // left. The draft is checked the same way for a preview, a test and Send
    // (announcementDraft()).

    $group
        ->group('', function (\Slim\Routing\RouteCollectorProxy $news) {
            // What was sent, newest first, fifty a page.
            $news->get('/announcements', function (Request $request, Response $response) {
                $pdo = iamDb();
                $page = min(max(1, (int) ($request->getQueryParams()['page'] ?? 1)), 100000);
                $size = 50;
                $rows = $pdo->query(ANNOUNCEMENT_SELECT . " ORDER BY a.created_at DESC, a.id DESC LIMIT $size OFFSET " . ($page - 1) * $size)->fetchAll();

                return respondJson($response, [
                    'total' => (int) $pdo->query('SELECT count(*) FROM announcements')->fetchColumn(),
                    'page' => $page,
                    'pageSize' => $size,
                    'items' => array_map('announcementSummary', $rows),
                ]);
            });

            // Everything the composer starts from: the templates' words in every
            // language on offer, the languages, the senders, the sites and their
            // roles, and the administrator's own address - where a test goes.
            $news->get('/announcements/compose', function (Request $request, Response $response) {
                $pdo = iamDb();
                $roles = $pdo->prepare('SELECT name FROM application_roles WHERE application_id = ? ORDER BY name');
                $applications = [];
                foreach ($pdo->query('SELECT id, client_id, name, home_url, system, enabled FROM applications ORDER BY sort_order, name')->fetchAll() as $application) {
                    $roles->execute([(int) $application['id']]);
                    $applications[] = [
                        'client_id' => $application['client_id'],
                        'name' => $application['name'],
                        'home_url' => $application['home_url'],
                        'system' => asBool($application['system']),
                        'enabled' => asBool($application['enabled']),
                        'roles' => $roles->fetchAll(PDO::FETCH_COLUMN),
                    ];
                }

                $languages = array_values(array_filter(iamTranslations($pdo)->languages(), static fn(array $language): bool => $language['enabled']));
                usort($languages, static fn(array $a, array $b): int => ($b['code'] === TRANSLATION_FALLBACK) <=> ($a['code'] === TRANSLATION_FALLBACK));

                return respondJson($response, [
                    'templates' => announcementTemplates($pdo),
                    'languages' => array_map(static fn(array $language): array => ['code' => $language['code'], 'name' => $language['name'], 'native_name' => $language['native_name']], $languages),
                    'senders' => announcementSenders($pdo),
                    'applications' => $applications,
                    'email' => actor($request)['email'],
                    'limits' => ANNOUNCEMENT_FIELDS + ['link' => ANNOUNCEMENT_LINK_MAX, 'people' => ANNOUNCEMENT_PEOPLE_MAX],
                    // How fast it may go: the composer says so when mail really
                    // leaves and no hourly limit is named - an announcement could
                    // then use up what the host allows, codes and resets with it.
                    'pacing' => ['driver' => (string) (mailConfig()['driver'] ?? 'file'), 'hourly_limit' => announcementHourlyLimit()],
                ]);
            });

            // {audience}: how many it reaches, and why the others are left out.
            $news->post('/announcements/audience', function (Request $request, Response $response) {
                $pdo = iamDb();
                $audience = announcementAudience($pdo, requestBody($request)['audience'] ?? null);
                if (translationRefused($audience)) {
                    return translationRefuse($response, $audience);
                }

                return respondJson($response, announcementAudienceCount($pdo, $audience));
            });

            // A draft and a language: the mail a reader of that language would
            // get - its own version, or the English one. The link to stop the
            // news leads to the page without a token.
            $news->post('/announcements/preview', function (Request $request, Response $response) {
                $pdo = iamDb();
                $body = requestBody($request);
                $language = field($body, 'language') ?: TRANSLATION_FALLBACK;
                if (!in_array($language, languageCodes($pdo), true)) {
                    return respondError($response, 422, 'unknown_language', 'The IAM does not offer that language');
                }
                $draft = announcementDraft($pdo, $body, $language);
                if (translationRefused($draft)) {
                    return translationRefuse($response, $draft);
                }

                return respondJson(
                    $response,
                    announcementDraftMessage($draft, $language, iamPageUrl('/unsubscribe')) + [
                        'sender' => ['address' => $draft['sender']['address'], 'name' => $draft['sender']['name']],
                    ],
                );
            });

            // The same, to the administrator's own address - and to nobody else's,
            // so that this is never a way to mail anybody anything.
            $news->post('/announcements/test', function (Request $request, Response $response) {
                $pdo = iamDb();
                $actor = actor($request);
                $body = requestBody($request);
                $language = field($body, 'language') ?: TRANSLATION_FALLBACK;
                if (!in_array($language, languageCodes($pdo), true)) {
                    return respondError($response, 422, 'unknown_language', 'The IAM does not offer that language');
                }
                $draft = announcementDraft($pdo, $body, $language);
                if (translationRefused($draft)) {
                    return translationRefuse($response, $draft);
                }
                if (!announcementTestAllowed($pdo, (int) $actor['id'])) {
                    return respondError($response, 429, 'too_many_tests', 'That is ' . ANNOUNCEMENT_TESTS_PER_HOUR . ' tests in an hour - the next one in a little while');
                }

                $sent = announcementSendTest($draft, $language, $actor);
                audit($request, (int) $actor['id'], 'announcement.tested', null, null, [
                    'language' => isset($draft['content'][$language]) ? $language : TRANSLATION_FALLBACK,
                    'subject' => $draft['content'][$language]['subject'] ?? $draft['content'][TRANSLATION_FALLBACK]['subject'],
                ]);

                return respondJson($response, ['sent' => $sent, 'email' => $actor['email']]);
            });

            // A draft and its audience: the announcement, and the list of people
            // it goes to. Nothing is sent until the page asks for the first batch.
            $news->post('/announcements', function (Request $request, Response $response) {
                $pdo = iamDb();
                $body = requestBody($request);
                $actorId = (int) actor($request)['id'];

                $draft = announcementDraft($pdo, $body);
                if (translationRefused($draft)) {
                    return translationRefuse($response, $draft);
                }
                $audience = announcementAudience($pdo, $body['audience'] ?? null);
                if (translationRefused($audience)) {
                    return translationRefuse($response, $audience);
                }
                $created = announcementCreate($pdo, $draft, $audience, $actorId);
                if (translationRefused($created)) {
                    return translationRefuse($response, $created);
                }

                audit($request, $actorId, 'announcement.created', 'announcement', $created['id'], [
                    'subject' => $draft['content'][TRANSLATION_FALLBACK]['subject'],
                    'sender' => $draft['sender']['address'],
                    'recipients' => $created['recipients'],
                    'audience' => ['everybody' => $audience['everybody'], 'roles' => $audience['roles'], 'people' => count($audience['user_ids'])],
                ]);

                return respondJson($response, announcementDetail($pdo, $created['id']), 201);
            });

            $news->get('/announcements/{id:[0-9]+}', function (Request $request, Response $response, array $args) {
                $detail = announcementDetail(iamDb(), (int) $args['id']);

                return $detail ? respondJson($response, $detail) : respondError($response, 404, 'not_found', 'Not found');
            });

            $news->get('/announcements/{id:[0-9]+}/recipients', function (Request $request, Response $response, array $args) {
                $pdo = iamDb();
                $id = (int) $args['id'];
                if (announcementDetail($pdo, $id) === null) {
                    return respondError($response, 404, 'not_found', 'Not found');
                }

                return respondJson($response, announcementRecipients($pdo, $id, $request->getQueryParams()));
            });

            // The next batch: the announcement as it stands after it, and `wait` -
            // the seconds until the host's hourly allowance has room again, when
            // it had none (announcementHourlyRoom()). One sent already answers as
            // it is; one stopped is refused.
            $news->post('/announcements/{id:[0-9]+}/send', function (Request $request, Response $response, array $args) {
                $pdo = iamDb();
                $id = (int) $args['id'];
                $detail = announcementDetail($pdo, $id);

                if ($detail === null) {
                    return respondError($response, 404, 'not_found', 'Not found');
                }
                if ($detail['status'] === 'stopped') {
                    return respondError($response, 409, 'stopped', 'This announcement was stopped - what was not sent then is never sent');
                }
                $wait = null;
                if ($detail['status'] === 'sending') {
                    $wait = announcementSendBatch($pdo, $id)['wait'];
                    $detail = announcementDetail($pdo, $id);
                }

                return respondJson($response, $detail + ['wait' => $wait]);
            });

            // Stopped: what is not sent yet never goes.
            $news->post('/announcements/{id:[0-9]+}/stop', function (Request $request, Response $response, array $args) {
                $pdo = iamDb();
                $id = (int) $args['id'];

                if (announcementDetail($pdo, $id) === null) {
                    return respondError($response, 404, 'not_found', 'Not found');
                }
                if (!announcementStop($pdo, $id)) {
                    return respondError($response, 409, 'not_sending', 'It is not sending any more');
                }

                $detail = announcementDetail($pdo, $id);
                audit($request, (int) actor($request)['id'], 'announcement.stopped', 'announcement', $id, [
                    'sent' => $detail['counts']['sent'],
                    'not_sent' => $detail['counts']['pending'],
                ]);

                return respondJson($response, $detail);
            });

            // The senders: the installation's own (config/mail.local.php), which
            // the panel cannot change, and those added here.
            $news->get('/mail-senders', function (Request $request, Response $response) {
                return respondJson($response, ['senders' => announcementSenders(iamDb()), 'domains' => mailDomains()]);
            });

            $news->post('/mail-senders', function (Request $request, Response $response) {
                $pdo = iamDb();
                $body = requestBody($request);
                $result = mailSenderAdd($pdo, $body['address'] ?? null, $body['name'] ?? null, (int) actor($request)['id']);
                if (translationRefused($result)) {
                    return translationRefuse($response, $result);
                }

                $sender = $result['sender'];
                audit($request, (int) actor($request)['id'], 'mail_sender.added', 'mail_sender', $sender['id'], ['address' => $sender['address'], 'name' => $sender['name']]);

                return respondJson($response, ['sender' => $sender, 'senders' => announcementSenders($pdo), 'domains' => mailDomains()], 201);
            });

            // What was sent from it keeps its address: an announcement holds its
            // sender as it was.
            $news->delete('/mail-senders/{id:[0-9]+}', function (Request $request, Response $response, array $args) {
                $pdo = iamDb();
                $statement = $pdo->prepare('DELETE FROM mail_senders WHERE id = ? RETURNING address, name');
                $statement->execute([(int) $args['id']]);
                $removed = $statement->fetch();

                if ($removed === false) {
                    return respondError($response, 404, 'not_found', 'Not found');
                }
                audit($request, (int) actor($request)['id'], 'mail_sender.removed', 'mail_sender', (int) $args['id'], ['address' => $removed['address'], 'name' => $removed['name']]);

                return respondJson($response, ['senders' => announcementSenders($pdo), 'domains' => mailDomains()]);
            });
        })
        ->add(requireAnnouncements());

    // ── The admin tools: audit log, error log, feedback, backups ────────────
    //
    // The anotoki family's (anotoki-lib 0.4; feedback since the owner's decision
    // of 2026-10-19; api/admin_tools.php, api/audit.php), one page each in the
    // admin panel. Their groups sit in this one, under its checks - the
    // session, the IAM's ADMIN, the CSRF header on every write - and each asks
    // the library's own ADMIN check (iamAdminContext()'s isAdmin, the same
    // rule) before anything else besides.

    $tools = iamAdminContext();

    // GET /audit (the IAM's answer, AuditEntry, with actor_session, scope_type
    // and scope_id beside it) and /audit/facets - 503 feature_unavailable until
    // the library's table and 018 are there.
    AuditRoutes::admin($group, '/audit', iamAuditLog(), $tools, auditActorNames(...));

    // GET /errors, /errors/{fingerprint}; DELETE /errors[?before=] - audited
    // errors.cleared. The log's own routes are never written into it.
    ErrorLogRoutes::admin($group, '/errors', iamErrorLog(), $tools, iamAuditLog());

    // What people sent through the family's feedback form (POST /api/feedback,
    // routes/login.php; iamFeedback()): GET /feedback?kind=&status=&section=
    // &search=&page= (25 a page, newest first, the sender and whoever handled
    // it by name - an anonymized account's placeholder), /feedback/facets,
    // /feedback/unread, /feedback/{id} (reading changes nothing - anotoki-lib
    // 0.8.1); PUT /feedback/{id}/read (a new one becomes read, as the page opens
    // it, under the CSRF header); PUT
    // /feedback/{id}/status {status: new|read|resolved|spam} - audited
    // feedback.status_changed; DELETE /feedback/{id} - audited feedback.deleted
    // by the library's AuditLog inside the delete's own transaction, so no
    // message goes without its entry (503 feature_unavailable while the audit
    // log's table waits). 503 feature_unavailable while the library's table is
    // not there.
    FeedbackRoutes::admin($group, '/feedback', iamFeedback(), $tools, iamAuditLog());

    // Backups: encrypted under the master key, never restored here
    // (iamBackups()). Made, downloaded and deleted with the proof the account
    // page asks (the password, or a sign-in of the last 15 minutes), each
    // audited. The download itself (GET /backups/{id}/file?ticket=) is a
    // navigation: its single-use ticket is good for one minute, one backup and
    // the administrator it was issued to - and it sits in this group too, so
    // the session and the IAM's ADMIN are asked as well.
    //
    // The backups' key is derived from the master key, which there is none of
    // until the IAM is set up: then every request is answered 503 by the gate
    // in front of the routes, and these are not mounted at all.
    if (iamSetUpProblems() === []) {
        BackupsRoutes::admin($group, '/backups', iamBackups(), $tools);
        BackupsRoutes::file($group, '/backups', iamBackups(), $tools, samePerson: true);
    }

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

        // Last in the list (there are no landing page settings to place it).
        // The database gives it ADMIN, EDITOR, EARLY_ACCESS and MEMBER, and
        // MEMBER to every account (023's trigger); the insert below covers the
        // minutes between an upload and Apply, when an older trigger made fewer
        // (010's MEMBER alone, 011's three).
        $insert = $pdo->prepare('INSERT INTO applications (client_id, name, sort_order)
                                 VALUES (?, ?, (SELECT COALESCE(MAX(sort_order), 0) + 1 FROM applications)) RETURNING id');
        $insert->execute([$clientId, field($body, 'name') ?: $clientId]);
        $id = (int) $insert->fetchColumn();
        $role = $pdo->prepare('INSERT INTO application_roles (application_id, name, description) VALUES (?, ?, ?) ON CONFLICT (application_id, name) DO NOTHING');
        foreach (SITE_ROLES as $name => $description) {
            $role->execute([$id, $name, $description]);
        }

        $saved = saveApplication($pdo, $id, $body, true);
        if (isset($saved['error'])) {
            $pdo->prepare('DELETE FROM applications WHERE id = ?')->execute([$id]);
            return respondError($response, 422, 'invalid_application', $saved['error']);
        }

        // Who may sign in from the start - visitors welcome, or MEMBER
        // unticked - is a security setting like any later change of it.
        $application = adminApplication($pdo, $id);
        $signInRoles = array_values(array_map(static fn(array $role): string => $role['name'], array_filter($application['roles'], static fn(array $role): bool => $role['may_sign_in'])));
        audit($request, (int) actor($request)['id'], 'application.created', 'application', $clientId, [
            'login_required' => $application['login_required'],
            'sign_in_roles' => $signInRoles,
        ]);

        return respondJson($response, ['application' => $application], 201);
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
        // Who may sign in, or whether visitors are let in, is a change to
        // security: a row of its own, with what it was and what it is.
        if ($saved['sign_in'] !== []) {
            audit($request, (int) actor($request)['id'], 'application.sign_in_changed', 'application', $application['client_id'], $saved['sign_in']);
        }

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

    // Who holds a role here beyond MEMBER (every account holds MEMBER: listing
    // it would list everybody), with all their roles here; and who is blocked
    // from it (blockedFromSql(): MEMBER taken away, or holding only roles that
    // may not sign in) among the accounts that are switched on. The IAM's own
    // entry blocks nobody: Settings -> Signing in is its gate.
    $group->get('/applications/{id:[0-9]+}/members', function (Request $request, Response $response, array $args) {
        $pdo = iamDb();
        $application = applicationById((int) $args['id']);

        if ($application === null) {
            return respondError($response, 404, 'not_found', 'Not found');
        }

        $statement = $pdo->prepare(
            "SELECT u.id AS user_id, u.username, u.email, json_agg(r.name ORDER BY r.name) AS roles
               FROM user_roles ur JOIN application_roles r ON r.id = ur.role_id JOIN users u ON u.id = ur.user_id
              WHERE r.application_id = ? GROUP BY u.id, u.username, u.email
             HAVING bool_or(r.name <> 'MEMBER') ORDER BY u.username",
        );
        $statement->execute([(int) $application['id']]);

        $blocked = [];
        if (!asBool($application['system'])) {
            $blockedRows = $pdo->prepare(
                'SELECT u.id AS user_id, u.username, u.email FROM users u, applications a
                                           WHERE a.id = ? AND NOT u.disabled AND ' .
                    blockedFromSql('u', 'a') .
                    ' ORDER BY u.username',
            );
            $blockedRows->execute([(int) $application['id']]);
            $blocked = array_map(static fn(array $row): array => ['user_id' => (int) $row['user_id'], 'username' => $row['username'], 'email' => $row['email']], $blockedRows->fetchAll());
        }

        return respondJson($response, [
            'members' => array_map(
                static fn(array $row): array => [
                    'user_id' => (int) $row['user_id'],
                    'username' => $row['username'],
                    'email' => $row['email'],
                    'roles' => json_decode((string) $row['roles'], true) ?: [],
                ],
                $statement->fetchAll(),
            ),
            'blocked' => $blocked,
        ]);
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
        $letIn = null;
        $pdo->beginTransaction();
        try {
            $update = $pdo->prepare('UPDATE settings SET value = ?, updated_at = CURRENT_TIMESTAMP, updated_by = ? WHERE name = ?');
            foreach ($changes as $name => $value) {
                $update->execute([$value, (int) actor($request)['id'], $name]);
            }

            // New accounts no longer wait for approval: neither do the ones
            // that were waiting. Approved with the switch, so turning it on
            // again is about accounts made from then on.
            if (($changes['registration_approval'] ?? null) === 'false') {
                $letIn = releaseWaitingAccounts($pdo);
            }

            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        if ($changes !== []) {
            settingsAll(true);
            audit($request, (int) actor($request)['id'], 'settings.changed', 'settings', null, $changes);
        }
        if ($letIn !== null) {
            audit($request, (int) actor($request)['id'], 'approvals.released', 'settings', 'registration_approval', ['accounts' => $letIn]);
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

    // ── Languages and strings ────────────────────────────────────────────────
    //
    // The IAM's own words, through the anotoki family's routes (anotoki/lib's
    // TranslationsRoutes, on the IAM's configuration, api/translations.php):
    // the languages it offers, and the strings of the pages and the mails.
    // Both groups sit in this one, under its checks - the session, the IAM's
    // ADMIN (the IAM has no EDITOR of its own panel) - and the CSRF header on
    // every write, as everything here. Then the library's, in its order: a
    // {code} that is none is a 404 before any query; a body is read by the
    // library from the raw request (JSON only, at most 1 MB, not empty, a map
    // where a map belongs); 503 `translations_unavailable` until migration
    // 006 has been applied - which is done from this panel, a few lines below.
    //
    // Who saved a string is the session's account, and nothing a body says.
    // Every change is audited, as it always was: language.created, .updated,
    // .deleted, translations.saved, .imported - a changed mail string with its
    // old and new text whole.

    $translations = static fn(): Translations => iamTranslations();
    $writer = static fn(Request $request): ?int => (int) actor($request)['id'];
    // A save's or an import's changed mail strings each in an entry of their
    // own (auditTranslations()): one entry holds at most 64 KB.
    $audited = static function (Request $request, string $action, ?string $type, ?string $id, array $details): void {
        auditTranslations($request, (int) actor($request)['id'], $action, $type, $id, $details);
    };

    TranslationsRoutes::strings($group, '', $translations, $writer, translationRefusalAnswer(...), $audited);

    // A language added, changed or deleted: what the IAM offers - discovery,
    // the pages' config, ui_locales, the accounts' languages - is read again.
    TranslationsRoutes::languages($group, '', $translations, translationRefusalAnswer(...), $audited)->add(static function (Request $request, RequestHandler $handler): Response {
        $response = $handler->handle($request);
        languageCodes(null, true);

        return $response;
    });

    // ── Migrations ───────────────────────────────────────────────────────────

    // The anotoki family's Migrations page's routes (anotoki/lib's
    // MigrationsRoutes, its page in the frontend): the files and what the
    // database has of them, a file's SQL, and Apply - under this group's
    // checks, which need nothing a pending migration makes (sessions, users,
    // roles are 001's). The CSRF header guards Apply like every write here.
    // Apply backs the database up first (iamMigratorWithBackup(): its name is
    // the answer's `note`), and a backup that cannot be made applies nothing.
    MigrationsRoutes::admin($group, '/migrations', static fn(): Migrator => iamMigratorWithBackup(), static function (Request $request, array $result): void {
        // A migration may have brought the languages table, or a language.
        languageCodes(null, true);
        // The audit entries that waited for 018 go in now, with their own times,
        // and an anonymized account's person out of them at once.
        flushWaitingAuditEntries(iamDb());
        // The files by the IAM's names for them: a library set's as `set/name`
        // (iamMigrationLabel()), since every set's numbers start at 001. A
        // failure's error is kept as its first line, redacted, no path
        // (iamMigrationErrorForAudit()): the answer below still has it whole.
        $label = static fn(array $file): string => iamMigrationLabel($file['set'], $file['name']);
        audit($request, (int) actor($request)['id'], 'migrations.applied', null, null, [
            'applied' => array_map($label, $result['applied']),
            'failed' => $result['failed'] === null ? null : $label($result['failed']),
            'error' => iamMigrationErrorForAudit($result['error']),
        ]);
    });
})
    ->add(requireSignInRules())
    ->add(requireAdmin())
    ->add(requireSession());

// ─────────────────────────────────────────────────────────────────────────────
// Applications, in the admin panel's shape
// ─────────────────────────────────────────────────────────────────────────────

/**
 * The roles every site has, in the words the database's trigger gives a new
 * application (011's three, and 023's EARLY_ACCESS - the owner's decision of
 * 2026-10-19: chosen people let in while the site's MEMBER may not sign in;
 * the IAM's own entry: ADMIN and MEMBER). Roles are fixed (the owner's
 * decision, 2026-10-05): the admin panel says only whether each may sign in.
 */
const SITE_ROLES = [
    'ADMIN' => "Everything in the site's admin panel",
    'EDITOR' => "Edits the site's content in its admin panel",
    'EARLY_ACCESS' => 'Uses the site while it lets in only chosen people (a beta, a test); given by an administrator',
    'MEMBER' => 'Uses the site',
];

function adminApplication(PDO $pdo, int $id): ?array
{
    $application = applicationById($id);

    if ($application === null) {
        return null;
    }

    $roles = $pdo->prepare(
        'SELECT r.name, r.description, r.may_sign_in, (SELECT count(*) FROM user_roles ur WHERE ur.role_id = r.id) AS members
           FROM application_roles r WHERE r.application_id = ? ORDER BY r.name',
    );
    $roles->execute([$id]);

    // The access column is still there (read only while 010 is pending; a
    // later migration drops it) but means nothing now: login_required and
    // each role's may_sign_in say who gets in. type, the secret,
    // show_on_landing and sort_order are there too and mean nothing to the
    // panel: every client is public, and there are no landing page settings.
    return [
        'id' => $id,
        'client_id' => $application['client_id'],
        'name' => $application['name'],
        'description' => $application['description'],
        'home_url' => $application['home_url'],
        'logo_url' => $application['logo_url'],
        'enabled' => asBool($application['enabled']),
        'login_required' => asBool($application['login_required']),
        'access_token_ttl' => (int) $application['access_token_ttl'],
        'redirect_uris' => applicationRedirectUris($id, 'login'),
        'post_logout_redirect_uris' => applicationRedirectUris($id, 'logout'),
        'web_origins' => applicationOrigins($id),
        'roles' => array_map(
            static fn(array $row): array => [
                'name' => $row['name'],
                'description' => $row['description'],
                'may_sign_in' => asBool($row['may_sign_in']),
                'members' => (int) $row['members'],
            ],
            $roles->fetchAll(),
        ),
        'system' => asBool($application['system']),
        'created_at' => isoTime($application['created_at']),
        'updated_at' => isoTime($application['updated_at']),
    ];
}

/**
 * Writes the fields of an application that are present in the body.
 *
 * All of it in one transaction: the URIs and origins are replaced as sets,
 * and an application left with half its redirect URIs is one that fails in a
 * way nobody would guess.
 *
 * The roles are fixed (011, the owner's decision): a body names each role the
 * application has, and may change only whether it may sign in. Adding,
 * removing, renaming or rewording one is refused. Who may sign in is each
 * role's may_sign_in, and a site switched on keeps at least one role that
 * may. Every client is public, and there are no landing page settings:
 * `type` other than public is refused, `show_on_landing` and `sort_order` are
 * ignored.
 *
 * @return array{changed: string[], sign_in: array}|array{error: string}
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

    // Public clients only: there is nothing to choose, and a body asking for
    // anything else is told so rather than quietly made public.
    if (array_key_exists('type', $body) && $body['type'] !== 'public') {
        return ['error' => 'Every application is a public client: it names itself and proves each sign-in with PKCE - there are no client secrets'];
    }

    // `access` is not read any more (010): who may sign in is each role's
    // may_sign_in, below. A body that still sends it - the admin panel from
    // before 010 - changes nothing by it.

    // The login_required in the database is also an OIDC error code
    // (routes/oidc.php); here it is the site's own switch, published to the
    // sites at /api/public/applications/{client_id}.
    if (array_key_exists('login_required', $body)) {
        if ($system) {
            return ['error' => 'The IAM\'s own pages have no sign-in switch - Settings -> Signing in is theirs'];
        }
        if (!is_bool($body['login_required'])) {
            return ['error' => 'login_required is true or false'];
        }
        $changes['login_required'] = pgBool($body['login_required']);
    }

    if (array_key_exists('access_token_ttl', $body)) {
        $ttl = (int) $body['access_token_ttl'];
        if ($ttl < 60 || $ttl > 3600) {
            return ['error' => 'The access token lifetime is between 60 and 3600 seconds'];
        }
        $changes['access_token_ttl'] = $ttl;
    }

    if (array_key_exists('enabled', $body)) {
        if ($system && empty($body['enabled'])) {
            return ['error' => 'The IAM itself cannot be switched off'];
        }
        $changes['enabled'] = pgBool(!empty($body['enabled']));
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

    // The roles are fixed: a body may say, per role the application has,
    // whether it may sign in - nothing else. may_sign_in left out keeps what
    // the role has, so a script that knows nothing of it cannot change who
    // signs in by accident.
    $maySignIn = null;
    if (array_key_exists('roles', $body)) {
        if (!is_array($body['roles'])) {
            return ['error' => 'roles must be a list of { name, may_sign_in }'];
        }
        $current = $pdo->prepare('SELECT name, description FROM application_roles WHERE application_id = ? ORDER BY name');
        $current->execute([$id]);
        $current = $current->fetchAll(PDO::FETCH_KEY_PAIR);
        $fixed = 'only whether each may sign in can change';

        $maySignIn = [];
        foreach ($body['roles'] as $role) {
            if (!is_array($role)) {
                return ['error' => 'roles must be a list of { name, may_sign_in }'];
            }
            $name = strtoupper(trim(is_string($role['name'] ?? null) ? $role['name'] : ''));
            if (!array_key_exists($name, $current)) {
                return ['error' => "The roles are fixed - '" . mb_substr($name, 0, 50) . "' is not one of this application's (" . implode(', ', array_keys($current)) . "); $fixed"];
            }
            if (array_key_exists('description', $role)) {
                $description = is_string($role['description']) ? (trim($role['description']) ?: null) : $role['description'];
                if ($description !== $current[$name]) {
                    return ['error' => "The roles are fixed - $name keeps its description; $fixed"];
                }
            }
            if (array_key_exists('may_sign_in', $role)) {
                if (!is_bool($role['may_sign_in'])) {
                    return ['error' => "may_sign_in of $name is true or false"];
                }
                $maySignIn[$name] = $role['may_sign_in'];
            }
        }
        // A role left out is a role taken away, which the roles being fixed
        // rules out - said so rather than read as "unchanged". MEMBER in its
        // own words: it is how people are blocked, one by one. A new
        // application may name only the roles whose tick it sets.
        if (!$creating) {
            $named = array_map(static fn(array $role): string => strtoupper(trim(is_string($role['name'] ?? null) ? $role['name'] : '')), $body['roles']);
            foreach (array_keys($current) as $name) {
                if (!in_array($name, $named, true)) {
                    return [
                        'error' =>
                            $name === 'MEMBER'
                                ? 'Every application keeps its MEMBER role - take it from people instead (Users -> the person -> Roles)'
                                : "The roles are fixed - $name cannot be removed; $fixed",
                    ];
                }
            }
        }
    }

    $pdo->beginTransaction();
    try {
        // Two saves of one application run one after the other: the second
        // waits here for the first to commit and then reads its roles and
        // switch, so the check below (a site switched on keeps a role that
        // may sign in) holds against both together - read without the lock,
        // one save unticking MEMBER and another ADMIN and EDITOR at the same
        // moment would each see the other's roles still ticked.
        $locked = $pdo->prepare('SELECT enabled, login_required FROM applications WHERE id = ? FOR UPDATE');
        $locked->execute([$id]);
        $locked = $locked->fetch(PDO::FETCH_ASSOC);
        if ($locked === false) {
            throw new DomainException('There is no such application');
        }
        $application['enabled'] = $locked['enabled'];
        $application['login_required'] = $locked['login_required'];

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

        $existing = $pdo->prepare('SELECT name, may_sign_in FROM application_roles WHERE application_id = ? ORDER BY name');
        $existing->execute([$id]);
        $wasAllowed = array_map('asBool', $existing->fetchAll(PDO::FETCH_KEY_PAIR));
        $signInBefore = array_keys(array_filter($wasAllowed));
        $signInAfter = $signInBefore;

        // The IAM's own entry is never asked about roles at sign-in (Settings
        // -> Signing in is its gate), so its roles keep the true they have and
        // a body cannot make them mean something.
        if ($maySignIn !== null && !$system) {
            $update = $pdo->prepare('UPDATE application_roles SET may_sign_in = ? WHERE application_id = ? AND name = ?');
            foreach ($maySignIn as $name => $allowed) {
                if ($allowed !== $wasAllowed[$name]) {
                    $update->execute([pgBool($allowed), $id, $name]);
                }
            }
            $signInAfter = array_keys(array_filter(array_merge($wasAllowed, $maySignIn)));
            sort($signInAfter);
        }

        // Nobody able to sign in is what switching the application off is
        // for, and says so honestly. One switched off (as 010 left a
        // members-only one nobody could get into) may be edited still.
        $enabledAfter = array_key_exists('enabled', $changes) ? !empty($body['enabled']) : asBool($application['enabled']);
        if (!$system && $enabledAfter && $signInAfter === []) {
            throw new DomainException('At least one role must be allowed to sign in - switch the application off instead');
        }

        $pdo->commit();
    } catch (DomainException $e) {
        $pdo->rollBack();
        return ['error' => $e->getMessage()];
    } catch (\Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    // What changed about who gets in, for the audit: the roles that may sign
    // in, and whether signing in is required, each as before and after.
    $signIn = [];
    if ($signInAfter !== $signInBefore) {
        $signIn['sign_in_roles'] = ['before' => $signInBefore, 'after' => $signInAfter];
    }
    if (array_key_exists('login_required', $changes) && asBool($changes['login_required']) !== asBool($application['login_required'])) {
        $signIn['login_required'] = ['before' => asBool($application['login_required']), 'after' => asBool($changes['login_required'])];
    }

    return [
        'changed' => array_merge(array_keys($changes), array_keys($lists), $origins !== null ? ['web_origins'] : [], $maySignIn !== null ? ['roles'] : []),
        'sign_in' => $signIn,
    ];
}

function adminSettingRows(): array
{
    $statement = iamDb()->query(
        'SELECT s.name, s.group_name, s.type, s.options, s.value, s.updated_at, u.username AS updated_by
           FROM settings s LEFT JOIN users u ON u.id = s.updated_by ORDER BY s.sort_order, s.name',
    );

    return array_map(
        static fn(array $row): array => [
            'name' => $row['name'],
            'group_name' => $row['group_name'],
            'type' => $row['type'],
            'options' => $row['options'] === null || $row['options'] === '' ? [] : array_map('trim', explode(',', (string) $row['options'])),
            'value' => $row['value'],
            'updated_at' => isoTime($row['updated_at']),
            'updated_by' => $row['updated_by'],
        ],
        $statement->fetchAll(),
    );
}
