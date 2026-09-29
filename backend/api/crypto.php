<?php

/**
 * Random secrets, hashes, and encryption with the master key.
 *
 * Everything that opens something - a session cookie, a code, a refresh token,
 * an emailed link - is 32 bytes from the CSPRNG, and only its SHA-256 is
 * stored. SHA-256 rather than password_hash because there is no dictionary to
 * slow anybody down from: 256 random bits cannot be guessed at any speed, and a
 * slow hash would only make every request slow.
 *
 * What cannot be one-way - a signing key's private half, a TOTP secret that
 * codes are computed from - is encrypted with AES-256-GCM under the master key
 * from config/secret.php. GCM authenticates as well as encrypts, so a value
 * tampered with in the table fails to decrypt rather than decrypting to
 * something else.
 */

/** URL-safe base64 without padding: safe in a cookie, a query string or a header. */
function base64UrlEncode(string $bytes): string
{
    return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
}

function base64UrlDecode(string $text): string
{
    if ($text === '' || !preg_match('/^[A-Za-z0-9_-]+$/', $text)) {
        return '';
    }

    $decoded = base64_decode(strtr($text, '-_', '+/'), true);

    return $decoded === false ? '' : $decoded;
}

/** A random secret, `$bytes` long, as base64url, with an optional recognisable prefix. */
function randomSecret(int $bytes = 32, string $prefix = ''): string
{
    return $prefix . base64UrlEncode(random_bytes($bytes));
}

/** A random identifier, `$bytes` long, as lowercase hex. */
function randomHex(int $bytes = 16): string
{
    return bin2hex(random_bytes($bytes));
}

function sha256(string $value): string
{
    return hash('sha256', $value);
}

/**
 * The master key's encryption, prefixed with a version so a later change of
 * algorithm can tell old values from new.
 */
function encryptSecret(string $plaintext): string
{
    $iv = random_bytes(12);
    $tag = '';
    $ciphertext = openssl_encrypt($plaintext, 'aes-256-gcm', iamMasterKey(), OPENSSL_RAW_DATA, $iv, $tag);

    if ($ciphertext === false) {
        throw new RuntimeException('openssl could not encrypt: ' . openssl_error_string());
    }

    return 'v1:' . base64UrlEncode($iv . $tag . $ciphertext);
}

/** The plaintext, or null when the value is not ours, was altered, or the key changed. */
function decryptSecret(?string $stored): ?string
{
    if ($stored === null || !str_starts_with($stored, 'v1:')) {
        return null;
    }

    $raw = base64UrlDecode(substr($stored, 3));

    if (strlen($raw) < 28) {
        return null;
    }

    $plaintext = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', iamMasterKey(), OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16));

    return $plaintext === false ? null : $plaintext;
}
