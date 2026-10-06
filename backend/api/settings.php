<?php

/**
 * The switches an admin can throw without a deploy - a row each in `settings`.
 *
 * Read once per request and cached. Nothing here trusts the column: a value
 * that does not parse falls back to the default the caller asked for, because
 * a settings table edited into nonsense by hand should not take signing in down
 * with it.
 */

function settingsAll(bool $reload = false): array
{
    static $settings = null;

    if ($settings !== null && !$reload) {
        return $settings;
    }

    $settings = [];

    try {
        foreach (iamDb()->query('SELECT * FROM settings ORDER BY sort_order, name')->fetchAll() as $row) {
            $settings[$row['name']] = $row;
        }
    } catch (\PDOException) {
        // Before the first migration there is no table; nothing is overridden.
    }

    return $settings;
}

function settingBool(string $name, bool $default): bool
{
    $value = settingsAll()[$name]['value'] ?? null;

    if ($value === null || $value === '') {
        return $default;
    }

    return strtolower(trim((string) $value)) === 'true';
}

/**
 * Whether anybody may start a session right now.
 *
 * Every way in checks this - password, emailed code, Google, a confirmation
 * link, a password reset. A switch that only closed the password form would be
 * a lock on one of five doors.
 */
function loginEnabled(): bool
{
    return settingBool('login_enabled', true);
}

function registrationEnabled(): bool
{
    return settingBool('registration_enabled', true);
}

/**
 * Whether an account made by signing up - the form, or Google - waits for an
 * administrator's approval before it can sign in anywhere (api/accounts.php).
 * Off by default, and off while there is no such row (012 pending).
 */
function registrationApprovalRequired(): bool
{
    return settingBool('registration_approval', false);
}

function googleLoginEnabled(): bool
{
    return settingBool('google_login_enabled', true);
}

/**
 * Why this account may not sign in, or null when it may.
 *
 * IAM admins are exempt from the switch on purpose: whoever turned signing in
 * off has to be able to get back in and turn it on. Nobody is exempt from
 * waiting for approval - an account that waits was made by signing up, and
 * an administrator approves it from a session of their own.
 */
function signInRefusal(?array $user): ?array
{
    if ($user !== null && !empty($user['disabled'])) {
        return ['status' => 403, 'code' => 'account_disabled', 'error' => 'This account has been switched off'];
    }

    if ($user !== null && accountAwaitsApproval($user)) {
        return ['status' => 403, 'code' => 'approval_pending', 'error' => 'This account is waiting for an administrator\'s approval'];
    }

    if (!loginEnabled() && !($user !== null && isIamAdmin((int) $user['id']))) {
        return ['status' => 403, 'code' => 'login_disabled', 'error' => 'Signing in is switched off at the moment'];
    }

    return null;
}

/** Why a value is not allowed for a setting, or null when it is. */
function settingValueRefusal(array $setting, string $value): ?string
{
    return match ($setting['type']) {
        'boolean' => in_array($value, ['true', 'false'], true) ? null : "{$setting['name']} is a switch - expected 'true' or 'false'",
        'choice' => in_array($value, array_map('trim', explode(',', (string) $setting['options'])), true)
            ? null
            : "{$setting['name']} must be one of: {$setting['options']}",
        default => strlen($value) > 10000 ? "{$setting['name']} is too long" : null,
    };
}
