<?php

use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Browsers that have already answered a two-factor code.
 *
 * Asking on every sign-in from the same laptop is a nuisance, and nuisance is
 * what makes people turn two-factor off. So a browser can be remembered: it
 * holds a secret and presents it next time, and the six digits are skipped -
 * nothing else is. The password, the emailed code or Google still has to be
 * right, so a stolen device token is not a way in on its own.
 */

/** Thirty days. "Remember me" that lasts forever is not a second factor. */
const TRUSTED_DEVICE_LIFETIME = 2592000;

const TRUSTED_DEVICE_TOUCH_INTERVAL = 3600;

function issueTrustedDevice(PDO $pdo, int $userId, Request $request): string
{
    $token = randomHex(32);

    $pdo->prepare(
        "INSERT INTO user_trusted_devices (user_id, token_hash, user_agent, ip, last_used_at, expires_at)
              VALUES (?, ?, ?, ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP + (? * INTERVAL '1 second'))"
    )->execute([$userId, sha256($token), requestUserAgent($request), requestIp($request), TRUSTED_DEVICE_LIFETIME]);

    return $token;
}

/** Whether this account already answered a code from this browser. The device must be the same account's. */
function trustedDeviceAccepted(PDO $pdo, int $userId, mixed $token): bool
{
    if (!is_string($token) || !preg_match('/^[0-9a-f]{64}$/', $token)) {
        return false;
    }

    $statement = $pdo->prepare(
        'SELECT id, last_used_at FROM user_trusted_devices
          WHERE token_hash = ? AND user_id = ? AND revoked_at IS NULL AND expires_at > CURRENT_TIMESTAMP'
    );
    $statement->execute([sha256($token), $userId]);
    $device = $statement->fetch();

    if (!$device) {
        return false;
    }

    if ($device['last_used_at'] === null || time() - strtotime((string) $device['last_used_at']) >= TRUSTED_DEVICE_TOUCH_INTERVAL) {
        $pdo->prepare('UPDATE user_trusted_devices SET last_used_at = CURRENT_TIMESTAMP WHERE id = ?')->execute([$device['id']]);
    }

    return true;
}

/** Forgets every remembered browser of an account - wherever the password or the second factor changes. */
function revokeTrustedDevices(PDO $pdo, int $userId): int
{
    $statement = $pdo->prepare('UPDATE user_trusted_devices SET revoked_at = CURRENT_TIMESTAMP WHERE user_id = ? AND revoked_at IS NULL');
    $statement->execute([$userId]);

    return $statement->rowCount();
}

function trustedDeviceCount(PDO $pdo, int $userId): int
{
    $statement = $pdo->prepare(
        'SELECT count(*) FROM user_trusted_devices WHERE user_id = ? AND revoked_at IS NULL AND expires_at > CURRENT_TIMESTAMP'
    );
    $statement->execute([$userId]);

    return (int) $statement->fetchColumn();
}
