<?php

/**
 * Time-based one-time passwords, and the codes for when the phone is gone.
 *
 * RFC 6238 over RFC 4226 - what every authenticator app speaks: a shared
 * secret, the clock in thirty-second steps, six digits from an HMAC of the step.
 * SHA-1 is not a mistake: most apps ignore the algorithm parameter and assume
 * it, the construction is HMAC (where SHA-1's collision weakness does not
 * apply), and the output is six digits living thirty seconds.
 *
 * The secret has to be kept in a form codes can be computed from, so it cannot
 * be hashed. It is encrypted with the master key instead (api/crypto.php), and
 * decrypted only to check a code.
 */

const TOTP_STEP = 30;
const TOTP_DIGITS = 6;

/** One step either side of now: a code typed as it turns over still works. */
const TOTP_WINDOW = 1;

const TOTP_RECOVERY_COUNT = 10;

const TOTP_BASE32_ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

/** The name an authenticator app shows the entry under. */
const TOTP_ISSUER = 'anotoki';

function base32Encode(string $bytes): string
{
    $bits = '';
    foreach (str_split($bytes) as $byte) {
        $bits .= str_pad(decbin(ord($byte)), 8, '0', STR_PAD_LEFT);
    }

    $encoded = '';
    foreach (str_split($bits, 5) as $chunk) {
        $encoded .= TOTP_BASE32_ALPHABET[bindec(str_pad($chunk, 5, '0', STR_PAD_RIGHT))];
    }

    return $encoded;
}

function base32Decode(string $encoded): string
{
    $encoded = strtoupper(rtrim($encoded, '='));
    $bits = '';

    foreach (str_split($encoded) as $character) {
        $index = strpos(TOTP_BASE32_ALPHABET, $character);
        if ($index === false) {
            return '';
        }
        $bits .= str_pad(decbin($index), 5, '0', STR_PAD_LEFT);
    }

    $bytes = '';
    foreach (str_split($bits, 8) as $chunk) {
        if (strlen($chunk) === 8) {
            $bytes .= chr(bindec($chunk));
        }
    }

    return $bytes;
}

/** A fresh secret: 160 bits, what RFC 4226 recommends for SHA-1. */
function totpSecret(): string
{
    return base32Encode(random_bytes(20));
}

/** The account's secret in the clear, for checking one code - or null. */
function totpSecretOf(array $user): ?string
{
    return decryptSecret($user['totp_secret'] ?? null);
}

function totpCode(string $secret, int $counter): string
{
    $key = base32Decode($secret);

    if ($key === '') {
        return '';
    }

    $hash = hash_hmac('sha1', pack('J', $counter), $key, true);
    $offset = ord($hash[19]) & 0x0F;
    $binary = ((ord($hash[$offset]) & 0x7F) << 24)
        | (ord($hash[$offset + 1]) << 16)
        | (ord($hash[$offset + 2]) << 8)
        | ord($hash[$offset + 3]);

    return str_pad((string) ($binary % (10 ** TOTP_DIGITS)), TOTP_DIGITS, '0', STR_PAD_LEFT);
}

/**
 * The time step a code belongs to, if it is one of the ones valid around now;
 * null if it is none of them.
 *
 * hash_equals on every candidate, and every candidate checked, so neither how
 * much of a guess was right nor which step matched shows in the timing.
 */
function totpMatchedStep(string $secret, string $code, ?int $now = null): ?int
{
    $code = preg_replace('/\D/', '', $code);

    if (strlen($code) !== TOTP_DIGITS || $secret === '') {
        return null;
    }

    $counter = intdiv($now ?? time(), TOTP_STEP);
    $matched = null;

    for ($step = -TOTP_WINDOW; $step <= TOTP_WINDOW; $step++) {
        if (hash_equals(totpCode($secret, $counter + $step), $code)) {
            $matched = $counter + $step;
        }
    }

    return $matched;
}

/** Whether a code is one of the ones valid around now - its arithmetic only; see totpCodeAccepted() for a sign-in. */
function totpVerify(string $secret, string $code, ?int $now = null): bool
{
    return totpMatchedStep($secret, $code, $now) !== null;
}

/**
 * Takes a step as used, if no code from it or a later one has been accepted.
 * Conditional, so two requests racing with the same code cannot both win.
 */
function totpSpendStep(PDO $pdo, int $userId, int $step): bool
{
    $statement = $pdo->prepare(
        'UPDATE users SET totp_last_step = ? WHERE id = ? AND (totp_last_step IS NULL OR totp_last_step < ?)'
    );
    $statement->execute([$step, $userId, $step]);

    return $statement->rowCount() === 1;
}

/**
 * An authenticator code for this account, accepted once: right, and from a
 * step later than any code accepted before (migration 004).
 */
function totpCodeAccepted(PDO $pdo, array $user, string $code): bool
{
    $secret = totpSecretOf($user);
    $step = $secret === null ? null : totpMatchedStep($secret, $code);

    return $step !== null && totpSpendStep($pdo, (int) $user['id'], $step);
}

function totpUri(string $secret, string $account): string
{
    return 'otpauth://totp/' . rawurlencode(TOTP_ISSUER) . ':' . rawurlencode($account) . '?' . http_build_query([
        'secret' => $secret,
        'issuer' => TOTP_ISSUER,
        'algorithm' => 'SHA1',
        'digits' => TOTP_DIGITS,
        'period' => TOTP_STEP,
    ]);
}

/**
 * Ten recovery codes, shown once, stored as hashes. They are as powerful as the
 * password, hence hashed like one and single-use; fifty bits each is past
 * guessing and still short enough to write on paper.
 */
function generateRecoveryCodes(PDO $pdo, int $userId): array
{
    $pdo->prepare('DELETE FROM user_recovery_codes WHERE user_id = ?')->execute([$userId]);

    $insert = $pdo->prepare('INSERT INTO user_recovery_codes (user_id, code_hash) VALUES (?, ?)');
    $codes = [];

    for ($index = 0; $index < TOTP_RECOVERY_COUNT; $index++) {
        $raw = substr(base32Encode(random_bytes(10)), 0, 10);
        $insert->execute([$userId, sha256($raw)]);
        $codes[] = substr($raw, 0, 5) . '-' . substr($raw, 5, 5);
    }

    return $codes;
}

/** Spends a recovery code if it is one of this account's unused ones. Hyphens and case do not matter. */
function consumeRecoveryCode(PDO $pdo, int $userId, string $code): bool
{
    $normalised = strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', $code));

    if ($normalised === '') {
        return false;
    }

    $statement = $pdo->prepare(
        'UPDATE user_recovery_codes SET used_at = CURRENT_TIMESTAMP WHERE user_id = ? AND code_hash = ? AND used_at IS NULL'
    );
    $statement->execute([$userId, sha256($normalised)]);

    return $statement->rowCount() === 1;
}

function recoveryCodesRemaining(PDO $pdo, int $userId): int
{
    $statement = $pdo->prepare('SELECT count(*) FROM user_recovery_codes WHERE user_id = ? AND used_at IS NULL');
    $statement->execute([$userId]);

    return (int) $statement->fetchColumn();
}

/**
 * The second factor, checked the same way at every sign-in: the app's current
 * code or one of the recovery codes, which is spent by being used.
 */
function totpChallengePassed(PDO $pdo, array $user, ?string $code): bool
{
    if (empty($user['totp_enabled'])) {
        return true;
    }

    $code = trim((string) $code);

    if ($code === '') {
        return false;
    }

    return totpCodeAccepted($pdo, $user, $code) || consumeRecoveryCode($pdo, (int) $user['id'], $code);
}
