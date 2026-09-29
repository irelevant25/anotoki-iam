<?php

/**
 * The one-time secrets that arrive by mail: a confirmation link, a password
 * reset, a six-digit sign-in code. Each proves whoever holds it can read one
 * mailbox, and is good once and not for long.
 *
 * Only the SHA-256 is stored, so the table says nothing about any live token.
 * The links are 256 random bits; the code is six digits because a person types
 * it, and everything about it is arranged around that being weak - ten minutes,
 * five outstanding, and five wrong guesses put them all out.
 */

const TOKEN_EMAIL_CONFIRM = 'email_confirm';
const TOKEN_PASSWORD_RESET = 'password_reset';
const TOKEN_LOGIN_CODE = 'login_code';

/** Wrong guesses at a code before every live one for that account is spent. */
const TOKEN_MAX_ATTEMPTS = 5;

/**
 * Live tokens one account may hold for one purpose. What stops a resend button
 * posting mail to somebody all afternoon; reaching it is not reported - the
 * answer is the same - it just stops another message going out.
 */
const TOKEN_MAX_OUTSTANDING = 5;

function outstandingTokenCount(PDO $pdo, int $userId, string $purpose): int
{
    $statement = $pdo->prepare(
        'SELECT count(*) FROM user_tokens
          WHERE user_id = ? AND purpose = ? AND consumed_at IS NULL AND expires_at > CURRENT_TIMESTAMP'
    );
    $statement->execute([$userId, $purpose]);

    return (int) $statement->fetchColumn();
}

/**
 * Issues a link token and hands back the part that goes in the mail, or null
 * when the account already holds as many as it may.
 *
 * `$context` travels with it - the application a registration started from,
 * so the confirmation can offer the way back.
 */
function issueOneTimeToken(PDO $pdo, int $userId, string $purpose, int $lifetimeSeconds, ?array $context = null): ?string
{
    if (outstandingTokenCount($pdo, $userId, $purpose) >= TOKEN_MAX_OUTSTANDING) {
        return null;
    }

    // Hex rather than base64: it survives being pasted out of a mail client
    // and back into a URL without anything needing escaping.
    $token = randomHex(32);

    $pdo->prepare(
        "INSERT INTO user_tokens (user_id, purpose, token_hash, context, expires_at)
              VALUES (?, ?, ?, ?, CURRENT_TIMESTAMP + (? * INTERVAL '1 second'))"
    )->execute([$userId, $purpose, sha256($token), $context === null ? null : json_encode($context), $lifetimeSeconds]);

    return $token;
}

/**
 * The row a link token names, if it is live and for this purpose.
 *
 * Null for every way a token can fail - never existed, wrong purpose, expired,
 * used - on purpose: telling those apart would tell somebody holding a guess
 * that the guess was nearly right.
 */
function findOneTimeToken(PDO $pdo, string $token, string $purpose): ?array
{
    if (!preg_match('/^[0-9a-f]{64}$/', $token)) {
        return null;
    }

    $statement = $pdo->prepare(
        'SELECT * FROM user_tokens
          WHERE token_hash = ? AND purpose = ? AND consumed_at IS NULL AND expires_at > CURRENT_TIMESTAMP'
    );
    $statement->execute([sha256($token), $purpose]);

    return $statement->fetch() ?: null;
}

/**
 * Marks a token used, and answers whether this call is the one that did it.
 * The condition is in the UPDATE, so two requests racing with one token cannot
 * both be told they won.
 */
function consumeOneTimeToken(PDO $pdo, int $tokenId): bool
{
    $statement = $pdo->prepare('UPDATE user_tokens SET consumed_at = CURRENT_TIMESTAMP WHERE id = ? AND consumed_at IS NULL');
    $statement->execute([$tokenId]);

    return $statement->rowCount() === 1;
}

/** Spends every live token of a purpose for one account. */
function revokeOneTimeTokens(PDO $pdo, int $userId, string $purpose): void
{
    $pdo->prepare(
        'UPDATE user_tokens SET consumed_at = CURRENT_TIMESTAMP WHERE user_id = ? AND purpose = ? AND consumed_at IS NULL'
    )->execute([$userId, $purpose]);
}

function tokenContext(array $token): array
{
    $context = is_string($token['context'] ?? null) ? json_decode($token['context'], true) : null;

    return is_array($context) ? $context : [];
}

/**
 * A six-digit sign-in code, or null when the account holds five already.
 *
 * The hash mixes in the account id: six digits collide, and the index on the
 * hash is unique. It is also what makes a code only ever a code for one
 * account.
 */
function issueLoginCode(PDO $pdo, int $userId, int $lifetimeSeconds): ?string
{
    if (outstandingTokenCount($pdo, $userId, TOKEN_LOGIN_CODE) >= TOKEN_MAX_OUTSTANDING) {
        return null;
    }

    $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

    $pdo->prepare(
        "INSERT INTO user_tokens (user_id, purpose, token_hash, expires_at)
              VALUES (?, ?, ?, CURRENT_TIMESTAMP + (? * INTERVAL '1 second'))"
    )->execute([$userId, TOKEN_LOGIN_CODE, loginCodeHash($userId, $code), $lifetimeSeconds]);

    return $code;
}

function loginCodeHash(int $userId, string $code): string
{
    return sha256($userId . ':' . $code);
}

/**
 * The token row for a code, if it is that account's and still live.
 *
 * A wrong code counts against every live code the account holds, and the fifth
 * puts them all out - so guessing costs the guesser the codes, not the account.
 */
function findLoginCode(PDO $pdo, int $userId, string $code): ?array
{
    $statement = $pdo->prepare(
        'SELECT * FROM user_tokens
          WHERE user_id = ? AND purpose = ? AND token_hash = ?
            AND consumed_at IS NULL AND expires_at > CURRENT_TIMESTAMP AND attempts < ?'
    );
    $statement->execute([$userId, TOKEN_LOGIN_CODE, loginCodeHash($userId, $code), TOKEN_MAX_ATTEMPTS]);

    if ($row = $statement->fetch()) {
        return $row;
    }

    $pdo->prepare(
        'UPDATE user_tokens SET attempts = attempts + 1
          WHERE user_id = ? AND purpose = ? AND consumed_at IS NULL AND expires_at > CURRENT_TIMESTAMP'
    )->execute([$userId, TOKEN_LOGIN_CODE]);

    $pdo->prepare(
        'UPDATE user_tokens SET consumed_at = CURRENT_TIMESTAMP
          WHERE user_id = ? AND purpose = ? AND consumed_at IS NULL AND attempts >= ?'
    )->execute([$userId, TOKEN_LOGIN_CODE, TOKEN_MAX_ATTEMPTS]);

    return null;
}
