<?php

/**
 * reset-password.php - let somebody back into their account, from the command line
 *
 *   php reset-password.php <email> [new-password] [--clear-2fa]
 *
 * For when the password is lost and the emailed reset cannot be used - on a
 * development machine, where mail goes to storage/mail/ rather than out, or
 * whenever the mailbox is out of reach. Filesystem access to this install is
 * the gate, which is why it runs on the command line only and is not shipped by
 * build-dist.php. On the shell-less production host, an IAM admin does the same
 * from the admin panel (set password, reset two-factor).
 *
 * It does the whole "let me back in": the password, password sign-in switched
 * back on, the address confirmed, the account switched back on, and the
 * limiter's memory of failed attempts cleared. Every session of the account
 * ends. Two-factor is left alone unless --clear-2fa is given, so losing a
 * password does not quietly turn it off.
 *
 * With no password given, one is generated, printed once, and flagged to be
 * replaced at the first sign-in.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("reset-password.php is a command-line tool.\n");
}

require_once __DIR__ . '/config/db.php';

requireSetUpOnCli();

$args = array_slice($argv, 1);
$clearTwoFactor = in_array('--clear-2fa', $args, true);
$positional = array_values(array_filter($args, static fn(string $arg): bool => !str_starts_with($arg, '--')));
$email = $positional[0] ?? null;
$given = $positional[1] ?? null;

if ($email === null) {
    echo "Usage: php reset-password.php <email> [new-password] [--clear-2fa]\n";
    exit(1);
}

$pdo = iamDb();
$statement = $pdo->prepare('SELECT id, username, email, totp_enabled FROM users WHERE lower(email) = lower(?)');
$statement->execute([$email]);
$user = $statement->fetch();

if (!$user) {
    echo "No account with the email '{$email}'.\n";
    exit(1);
}

// An account deleted at its owner's request (migration 016) is never let back
// in: its id is the `sub` whose data every site has made nobody's.
$hasDeletion = (bool) $pdo->query(
    "SELECT EXISTS (SELECT 1 FROM pg_attribute WHERE attrelid = to_regclass('users') AND attname = 'anonymized_at' AND NOT attisdropped)"
)->fetchColumn();
if ($hasDeletion) {
    $anonymized = $pdo->prepare('SELECT anonymized_at FROM users WHERE id = ?');
    $anonymized->execute([$user['id']]);
    if ($anonymized->fetchColumn() !== null) {
        echo "That account was deleted at its owner's request; its id stays reserved and nobody signs in to it again.\n";
        exit(1);
    }
}

if ($given !== null && (strlen($given) < 8 || strlen($given) > 72)) {
    echo "The password needs 8 to 72 characters.\n";
    exit(1);
}

$password = $given ?? bin2hex(random_bytes(6));
$forceChange = $given === null;

// The flags are literals, not input; only the hash and the id are bound.
$sql = 'UPDATE users SET password = ?, password_login_enabled = TRUE, email_confirmed = TRUE, disabled = FALSE, '
    . 'force_password_change = ' . ($forceChange ? 'TRUE' : 'FALSE')
    . ($clearTwoFactor ? ', totp_enabled = FALSE, totp_secret = NULL' : '')
    . ' WHERE id = ?';
$pdo->prepare($sql)->execute([password_hash($password, PASSWORD_DEFAULT), $user['id']]);

if ($clearTwoFactor) {
    $pdo->prepare('DELETE FROM user_recovery_codes WHERE user_id = ?')->execute([$user['id']]);
}

$pdo->prepare('DELETE FROM login_attempts WHERE lower(email) = lower(?)')->execute([$email]);
$pdo->prepare("UPDATE sessions SET revoked_at = CURRENT_TIMESTAMP, revoked_reason = 'password_changed' WHERE user_id = ? AND revoked_at IS NULL")->execute([$user['id']]);
$pdo->prepare("UPDATE refresh_tokens SET revoked_at = CURRENT_TIMESTAMP, revoked_reason = 'password_changed' WHERE user_id = ? AND kind = 'rotating' AND revoked_at IS NULL")->execute([$user['id']]);
$pdo->prepare("INSERT INTO audit_log (action, target_type, target_id, details) VALUES ('user.password_reset_cli', 'user', ?, ?)")
    ->execute([(string) $user['id'], json_encode(['clear_2fa' => $clearTwoFactor])]);

echo "Reset access for {$user['username']} <{$user['email']}>.\n";
echo $forceChange ? "  New password: {$password}\n  A new one will be asked for at the first sign-in.\n" : "  The password is the one you gave.\n";
echo "  Password sign-in is on, the address confirmed, the account enabled; failed attempts forgotten; every session ended.\n";

if ($clearTwoFactor && $user['totp_enabled']) {
    echo "  Two-factor authentication has been turned off.\n";
} elseif ($user['totp_enabled']) {
    echo "  Two-factor is still ON. If that is lost too, run again with --clear-2fa.\n";
}
