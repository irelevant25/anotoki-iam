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
 *
 * A confirmation link is good only while its address waits to be confirmed:
 * every one an account has goes once the address is (spendConfirmationLinks()).
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
          WHERE user_id = ? AND purpose = ? AND consumed_at IS NULL AND expires_at > CURRENT_TIMESTAMP',
    );
    $statement->execute([$userId, $purpose]);

    return (int) $statement->fetchColumn();
}

/**
 * The condition a token's insert asks of its account (016): the account's row
 * there and not anonymized - read with a key-share lock, so an anonymization
 * holding the row at that moment is waited for, and the row read again once
 * it is committed: then there is no account to issue for. The lock is the
 * insert's own and goes with its statement's transaction.
 */
function tokenAccountSql(PDO $pdo): string
{
    return 'EXISTS (SELECT 1 FROM users WHERE id = ?' . liveAccountSql($pdo) . ' FOR KEY SHARE)';
}

/**
 * Issues a link token and hands back the part that goes in the mail, or null
 * when the account already holds as many as it may - or is deleted
 * (tokenAccountSql()), even by an anonymization still committing when this
 * asks: then no token is kept and nothing is mailed. A token issued a moment
 * before an anonymization starts is deleted by it; the caller may still mail
 * it - to the address the account had a moment before - and the link opens
 * nothing (findOneTimeToken()).
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

    $insert = $pdo->prepare(
        "INSERT INTO user_tokens (user_id, purpose, token_hash, context, expires_at)
         SELECT ?, ?, ?, ?, CURRENT_TIMESTAMP + (? * INTERVAL '1 second')
          WHERE " . tokenAccountSql($pdo),
    );
    $insert->execute([$userId, $purpose, sha256($token), $context === null ? null : json_encode($context), $lifetimeSeconds, $userId]);

    return $insert->rowCount() === 1 ? $token : null;
}

/**
 * The row a link token names, if it is live and for this purpose.
 *
 * Null for every way a token can fail - never existed, wrong purpose, expired,
 * used, its account deleted since (016) - on purpose: telling those apart
 * would tell somebody holding a guess that the guess was nearly right.
 */
function findOneTimeToken(PDO $pdo, string $token, string $purpose): ?array
{
    if (!preg_match('/^[0-9a-f]{64}$/', $token)) {
        return null;
    }

    $live = accountDeletionReady($pdo) ? ' AND NOT EXISTS (SELECT 1 FROM users u WHERE u.id = user_tokens.user_id AND u.anonymized_at IS NOT NULL)' : '';
    $statement = $pdo->prepare(
        'SELECT * FROM user_tokens
          WHERE token_hash = ? AND purpose = ? AND consumed_at IS NULL AND expires_at > CURRENT_TIMESTAMP' . $live,
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
    $pdo->prepare('UPDATE user_tokens SET consumed_at = CURRENT_TIMESTAMP WHERE user_id = ? AND purpose = ? AND consumed_at IS NULL')->execute([$userId, $purpose]);
}

/**
 * Every confirmation link the account still has, spent: once its address is
 * confirmed - by a confirmation link, a reset link, Google's word, an
 * administrator -, when an administrator changes the address (the links went
 * to the old one), and when two-factor is switched on.
 *
 * The security review of 2026-10-19: a link left over - "send it again" mails
 * a second, and the first is never opened - stayed good for its 48 hours
 * after the other confirmed the address, and signed in by the mailbox alone:
 * past two-factor switched on meanwhile, in a session that said two factors
 * had been asked. A link of an address confirmed already opens no session now
 * (routes/login.php); this leaves none to open.
 */
function spendConfirmationLinks(PDO $pdo, int $userId): void
{
    revokeOneTimeTokens($pdo, $userId, TOKEN_EMAIL_CONFIRM);
}

/**
 * Whether a confirmation link was mailed for an account whose address is
 * confirmed now - the link spent, run out or live - and to that very address
 * (confirmationLinkToAddress()). Not for a link never mailed, one swept (a
 * month after it ran out), one of an address not confirmed now - an
 * anonymized account's never is (016) -, one mailed to an address the account
 * no longer has, or one from before links noted where they went.
 *
 * What the confirmation answers a link with that confirms nothing any more:
 * "the address is confirmed" - rather than "that link did not work" - to the
 * mailbox's owner opening an older mail. findOneTimeToken() tells no failure
 * apart, so that a near guess learns nothing; this tells apart only a link
 * that was mailed - 256 random bits nobody guesses - and says nothing of its
 * account but that the address it went to is confirmed. Nothing to the holder
 * of an address the account has left: whether it lives on, confirmed
 * somewhere else, is none of theirs (the review of d0e34fb).
 */
function linkOfConfirmedAddress(PDO $pdo, string $token): bool
{
    if (!preg_match('/^[0-9a-f]{64}$/', $token)) {
        return false;
    }

    $statement = $pdo->prepare('SELECT t.context, u.email FROM user_tokens t JOIN users u ON u.id = t.user_id WHERE t.token_hash = ? AND t.purpose = ? AND u.email_confirmed');
    $statement->execute([sha256($token), TOKEN_EMAIL_CONFIRM]);
    $link = $statement->fetch();

    return $link !== false && confirmationLinkToAddress($link, (string) $link['email']) === true;
}

/**
 * A mark of the address a confirmation link is mailed to: its SHA-256, lower
 * case - kept in the link's context (confirmationLinkContext()), never the
 * address itself.
 */
function confirmationAddressMark(string $email): string
{
    return sha256(strtolower(trim($email)));
}

/**
 * What a confirmation link carries in its context: the mark of the address it
 * is mailed to, beside the application a registration came from ($context).
 * A link confirms an address - and says it is confirmed - only while that is
 * still the account's address (confirmationLinkToAddress()).
 */
function confirmationLinkContext(string $email, ?array $context = null): array
{
    return ['address' => confirmationAddressMark($email)] + ($context ?? []);
}

/**
 * Whether a confirmation link (its row) was mailed to `$email`, the account's
 * address now: true; false when the account's address is another since - an
 * administrator changed it -; null for a link from before links noted where
 * they went (2026-10-20), of which nobody can tell.
 */
function confirmationLinkToAddress(array $link, string $email): ?bool
{
    $mark = tokenContext($link)['address'] ?? null;

    return is_string($mark) ? hash_equals($mark, confirmationAddressMark($email)) : null;
}

function tokenContext(array $token): array
{
    $context = is_string($token['context'] ?? null) ? json_decode($token['context'], true) : null;

    return is_array($context) ? $context : [];
}

/**
 * A six-digit sign-in code, or null when the account holds five already - or
 * is deleted (tokenAccountSql()), even by an anonymization still committing
 * when this asks: then nothing is mailed. One issued a moment before an
 * anonymization starts is deleted by it; its mail may still go, to the
 * address the account had, and the code signs nobody in.
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

    $insert = $pdo->prepare(
        "INSERT INTO user_tokens (user_id, purpose, token_hash, expires_at)
         SELECT ?, ?, ?, CURRENT_TIMESTAMP + (? * INTERVAL '1 second')
          WHERE " . tokenAccountSql($pdo),
    );
    $insert->execute([$userId, TOKEN_LOGIN_CODE, loginCodeHash($userId, $code), $lifetimeSeconds, $userId]);

    return $insert->rowCount() === 1 ? $code : null;
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
            AND consumed_at IS NULL AND expires_at > CURRENT_TIMESTAMP AND attempts < ?',
    );
    $statement->execute([$userId, TOKEN_LOGIN_CODE, loginCodeHash($userId, $code), TOKEN_MAX_ATTEMPTS]);

    if ($row = $statement->fetch()) {
        return $row;
    }

    $pdo->prepare(
        'UPDATE user_tokens SET attempts = attempts + 1
          WHERE user_id = ? AND purpose = ? AND consumed_at IS NULL AND expires_at > CURRENT_TIMESTAMP',
    )->execute([$userId, TOKEN_LOGIN_CODE]);

    $pdo->prepare(
        'UPDATE user_tokens SET consumed_at = CURRENT_TIMESTAMP
          WHERE user_id = ? AND purpose = ? AND consumed_at IS NULL AND attempts >= ?',
    )->execute([$userId, TOKEN_LOGIN_CODE, TOKEN_MAX_ATTEMPTS]);

    return null;
}
