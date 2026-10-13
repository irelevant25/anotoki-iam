<?php

use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * The authenticator step, checked the same way on every way in that takes an
 * authenticator code - the password sign-in, the emailed code's, Google's, a
 * password reset, and the account page's turning two-factor off and making new
 * recovery codes - with its pause (migration 019, the owner's decision of
 * 2026-10-13; docs/decisions.md).
 *
 * Reaching the step means the first one was passed - the password, as a rule
 * (or a code mailed to the address, Google's word, a reset link, a signed-in
 * session). Six digits are all that is left, three of a million right at any
 * moment, and a guesser who spreads the guesses over many addresses walks past
 * every per-address limit (session.php's limiter). So, per account:
 *
 *   - After AUTHENTICATOR_PAUSE_AFTER (5) wrong codes - counted for the
 *     account, whatever address they come from, within a rolling
 *     AUTHENTICATOR_PAUSE_SECONDS (15 minutes), from nought again after a
 *     right code or a completed sign-in (restartAuthenticatorCount()) - the
 *     authenticator step pauses for AUTHENTICATOR_PAUSE_SECONDS from the fifth.
 *   - During the pause an authenticator code is refused without being checked
 *     - `authenticator_paused`, with when it ends: guessing gains nothing - and
 *     nothing refused then counts towards the next pause (`totp_paused`).
 *   - A recovery code still works (fifty bits, not guessable), as does a
 *     remembered browser (a 256-bit secret the caller checks first): neither is
 *     six digits. And the password's sign-in may finish with a code mailed to
 *     the address in the authenticator's place (pausedEmailCodeCheck(), from
 *     routes/login.php): after the right password, never without it, and
 *     counted as the authenticator's codes are - AUTHENTICATOR_PAUSE_AFTER (5)
 *     wrong ones in a pause, and the mailbox stands in no more until it ends.
 *   - The account's owner is mailed once a pause, by the request that begins
 *     it (sendAuthenticatorPausedMail()), and the audit log keeps it
 *     (`account.authenticator_paused`, by nobody, from the guesser's address).
 *
 * One check at a time for an account: each runs in a transaction of its own
 * that holds the account's row (FOR UPDATE), so a burst of guesses sent at once
 * is checked one after another - five, then the pause - never five each; the
 * emailed codes of a pause the same. The wrong codes are the limiter's own
 * attempts (login_attempts, `bad_totp` and `bad_email_code`, by account).
 * Before migration 019 is applied the step is checked as it was, with no pause
 * (authenticatorPauseReady()).
 */

/** Wrong authenticator codes for an account, within AUTHENTICATOR_PAUSE_SECONDS, that pause it - the owner's five. */
const AUTHENTICATOR_PAUSE_AFTER = 5;

/** The window the wrong codes are counted in, and how long the pause lasts - the owner's fifteen minutes. */
const AUTHENTICATOR_PAUSE_SECONDS = 900;

/**
 * Whether the database has migration 019's columns. A catalog query that
 * cannot fail, asked afresh every time like the others: the Migrations page's
 * Apply runs in the same process as the requests after it.
 */
function authenticatorPauseReady(PDO $pdo): bool
{
    return asBool($pdo->query(
        "SELECT (SELECT count(*) FROM pg_attribute WHERE attrelid = to_regclass('users') AND NOT attisdropped
                   AND attname IN ('totp_paused_until', 'totp_count_from')) = 2"
    )->fetchColumn());
}

/**
 * Whether what was typed could be an authenticator code: six digits, with the
 * spaces or hyphens an app may show them with, and nothing else - the code
 * totpMatchedStep() would read. A recovery code has letters in it.
 */
function authenticatorCodeShaped(string $value): bool
{
    return preg_match('/^[\d\s-]*$/D', $value) === 1 && strlen((string) preg_replace('/\D/', '', $value)) === TOTP_DIGITS;
}

/**
 * The authenticator step for one account: what was typed ($code - an
 * authenticator code, or where $recovery a recovery code) checked, its pause
 * kept, and every refusal recorded as an attempt under $method, the way the
 * limiter counts it. Outside any transaction (its own commits on its own).
 *
 * @return array{passed: true}|array{passed: false, code: string, error: string, extra: array<string, mixed>}
 *   refused: `totp_required` (nothing typed), `totp_invalid` (a wrong code; a
 *   wrong recovery code during a pause), `authenticator_paused` (its `extra`
 *   says until when; once the pause holds, `email_code_spent` whether its
 *   emailed codes are spent)
 */
function authenticatorCheck(PDO $pdo, Request $request, array $user, mixed $code, string $method, bool $recovery = true): array
{
    $userId = (int) $user['id'];
    $email = (string) $user['email'];
    $value = is_scalar($code) ? trim((string) $code) : '';
    $accepted = static fn(): bool => totpCodeAccepted($pdo, $user, $value) || ($recovery && consumeRecoveryCode($pdo, $userId, $value));

    // Before 019: as it was, with no pause.
    if (!authenticatorPauseReady($pdo)) {
        if ($value === '') {
            recordLoginAttempt($pdo, $request, $email, $userId, $method, 'totp_required');

            return authenticatorRefusal('totp_required');
        }
        if ($accepted()) {
            return ['passed' => true];
        }
        recordLoginAttempt($pdo, $request, $email, $userId, $method, 'bad_totp');

        return authenticatorRefusal('totp_invalid');
    }

    if ($pdo->inTransaction()) {
        throw new LogicException('The authenticator is checked outside any transaction: its pause commits on its own.');
    }

    $began = null;
    $pdo->beginTransaction();
    try {
        // The account's row, held to the commit: one check at a time for it.
        $row = $pdo->prepare('SELECT totp_paused_until, totp_paused_until > CURRENT_TIMESTAMP AS paused FROM users WHERE id = ? FOR UPDATE');
        $row->execute([$userId]);
        $state = $row->fetch();

        if ($state === false) {
            $pdo->rollBack();

            return authenticatorRefusal('totp_invalid');
        }

        if (asBool($state['paused'])) {
            $answer = authenticatorWhilePaused($pdo, $request, $user, $value, $method, $recovery, (string) $state['totp_paused_until']);
            $pdo->commit();

            return $answer;
        }

        if ($value === '') {
            recordLoginAttempt($pdo, $request, $email, $userId, $method, 'totp_required');
            $pdo->commit();

            return authenticatorRefusal('totp_required');
        }

        if ($accepted()) {
            // A right code: the wrong ones before it count no more.
            restartAuthenticatorCount($pdo, $userId);
            $pdo->commit();

            return ['passed' => true];
        }

        recordLoginAttempt($pdo, $request, $email, $userId, $method, 'bad_totp');

        $window = 'CURRENT_TIMESTAMP - (' . AUTHENTICATOR_PAUSE_SECONDS . " * INTERVAL '1 second')";
        $wrong = $pdo->prepare(
            "SELECT count(*) FROM login_attempts
              WHERE user_id = ? AND outcome = 'bad_totp'
                AND created_at > GREATEST({$window}, COALESCE((SELECT totp_count_from FROM users WHERE id = ?), '-infinity'::timestamptz))"
        );
        $wrong->execute([$userId, $userId]);

        if ((int) $wrong->fetchColumn() < AUTHENTICATOR_PAUSE_AFTER) {
            $pdo->commit();

            return authenticatorRefusal('totp_invalid');
        }

        // The fifth: the pause begins, and the count starts again from it.
        $paused = $pdo->prepare(
            'UPDATE users SET totp_paused_until = CURRENT_TIMESTAMP + (' . AUTHENTICATOR_PAUSE_SECONDS . " * INTERVAL '1 second'), totp_count_from = CURRENT_TIMESTAMP
              WHERE id = ? RETURNING totp_paused_until"
        );
        $paused->execute([$userId]);
        $began = (string) $paused->fetchColumn();
        audit($request, null, 'account.authenticator_paused', 'user', $userId, ['until' => isoTime($began), 'method' => $method]);

        $pdo->commit();
    } catch (\Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    // Once the pause is committed, and only by the request that began it: the
    // owner is told - at an address its owner proved, as every account mail.
    if (asBool($user['email_confirmed'])) {
        sendAuthenticatorPausedMail($user, signInDeviceLabel(requestUserAgent($request)), time());
    }

    return authenticatorRefusal('authenticator_paused', $began);
}

/**
 * The step while the account's codes are paused, inside authenticatorCheck()'s
 * transaction: a recovery code - no six digits - is checked as ever; anything
 * else that was typed, or nothing, is refused unchecked. Neither counts
 * towards the next pause. The refusal says, read under the same hold, whether
 * this pause's emailed codes are spent (`email_code_spent`): the password's
 * sign-in then offers none (routes/login.php, totpRefusal()).
 */
function authenticatorWhilePaused(PDO $pdo, Request $request, array $user, string $value, string $method, bool $recovery, string $until): array
{
    $userId = (int) $user['id'];

    if ($recovery && $value !== '' && !authenticatorCodeShaped($value)) {
        if (consumeRecoveryCode($pdo, $userId, $value)) {
            restartAuthenticatorCount($pdo, $userId);

            return ['passed' => true];
        }
        recordLoginAttempt($pdo, $request, (string) $user['email'], $userId, $method, 'bad_recovery');

        return authenticatorRefusal('totp_invalid');
    }

    recordLoginAttempt($pdo, $request, (string) $user['email'], $userId, $method, 'totp_paused');

    return authenticatorRefusal('authenticator_paused', $until)
        + ['email_code_spent' => wrongEmailCodesThisPause($pdo, $userId) >= AUTHENTICATOR_PAUSE_AFTER];
}

/**
 * The password's sign-in finished, during the pause, with a code mailed to the
 * address in the authenticator's place (routes/login.php, `email_code`; the
 * code is the emailed sign-in's own, POST /api/login/code) - checked under the
 * same hold on the account's row as authenticatorCheck(), and counted as the
 * authenticator's codes are: after AUTHENTICATOR_PAUSE_AFTER (5) wrong ones in
 * one pause, every emailed code is refused unchecked until the pause ends
 * (`authenticator_paused`, `email_code: false`) - one asked for since too.
 *
 * Without that count the mailbox would be six digits to guess at for as long
 * as the pause lasts: findLoginCode()'s five wrong tries put out only the
 * codes live at that moment, a guesser holding the password can have new ones
 * mailed, and a burst sent at once would be checked against the same live
 * codes before any of it counted. Under the hold, the count is read before the
 * code is: a burst is checked one at a time, five at most.
 *
 * @return null|array{passed: true}|array{passed: false, status: int, code: string, error: string, extra: array<string, mixed>}
 *   null while no pause holds (or 019 is not there): the authenticator step
 *   then asks as ever, and the code is not touched
 */
function pausedEmailCodeCheck(PDO $pdo, Request $request, array $user, string $code): ?array
{
    if (!authenticatorPauseReady($pdo)) {
        return null;
    }
    if ($pdo->inTransaction()) {
        throw new LogicException('The emailed code is checked outside any transaction: its count commits on its own.');
    }

    $userId = (int) $user['id'];
    $email = (string) $user['email'];

    for ($tries = 1; ; $tries++) {
        $pdo->beginTransaction();
        try {
            // The account's row, held to the commit, as authenticatorCheck() holds it.
            $row = $pdo->prepare(
                'SELECT totp_paused_until, CURRENT_TIMESTAMP < totp_paused_until - (' . AUTHENTICATOR_PAUSE_SECONDS . " * INTERVAL '1 second') AS began_before_it
                   FROM users WHERE id = ? AND totp_paused_until > CURRENT_TIMESTAMP FOR UPDATE"
            );
            $row->execute([$userId]);
            $state = $row->fetch();

            if ($state === false) {
                $pdo->commit();

                return null;
            }

            // An attempt is kept at its transaction's start (CURRENT_TIMESTAMP). One that began a moment
            // before the pause did - and waited for the row while the pause began - would be kept before
            // the pause's start, outside its count: a burst timed against the fifth wrong code would get
            // past the five. So such a check starts afresh, after the pause.
            if ($tries === 1 && asBool($state['began_before_it'])) {
                $pdo->commit();
                continue;
            }
            $until = (string) $state['totp_paused_until'];

            if (wrongEmailCodesThisPause($pdo, $userId) >= AUTHENTICATOR_PAUSE_AFTER) {
                recordLoginAttempt($pdo, $request, $email, $userId, SESSION_METHOD_PASSWORD_EMAIL, 'email_code_paused');
                $pdo->commit();

                return pausedEmailCodeRefusal($until);
            }

            $token = findLoginCode($pdo, $userId, $code);
            if ($token !== null && consumeOneTimeToken($pdo, (int) $token['id'])) {
                $pdo->commit();

                return ['passed' => true];
            }

            recordLoginAttempt($pdo, $request, $email, $userId, SESSION_METHOD_PASSWORD_EMAIL, 'bad_email_code');
            $spent = wrongEmailCodesThisPause($pdo, $userId) >= AUTHENTICATOR_PAUSE_AFTER;
            $pdo->commit();

            // The fifth wrong one says at once that the mailbox stands in no more.
            return $spent
                ? pausedEmailCodeRefusal($until)
                : ['passed' => false, 'status' => 400, 'code' => 'invalid_code', 'error' => 'That code is not valid', 'extra' => []];
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }
}

/** The emailed codes of this pause spent: the pause's own refusal, with no code by email offered. */
function pausedEmailCodeRefusal(string $until): array
{
    $refusal = authenticatorRefusal('authenticator_paused', $until);

    return [
        'passed' => false,
        'status' => 403,
        'code' => $refusal['code'],
        'error' => 'Too many wrong codes: codes from the authenticator app and by email are paused for this account until ' . isoTime($until),
        'extra' => $refusal['extra'] + ['email_code' => false],
    ];
}

/**
 * Wrong emailed codes for the account since its pause began - the pause's
 * end less AUTHENTICATOR_PAUSE_SECONDS -, nought while none holds. Counted
 * from the pause's own start, not the authenticator's count (a completed
 * sign-in moves that on): a guesser's tries stay counted whoever signs in
 * meanwhile.
 */
function wrongEmailCodesThisPause(PDO $pdo, int $userId): int
{
    $statement = $pdo->prepare(
        "SELECT count(*) FROM login_attempts
          WHERE user_id = ? AND outcome = 'bad_email_code'
            AND created_at >= (SELECT totp_paused_until - (" . AUTHENTICATOR_PAUSE_SECONDS . " * INTERVAL '1 second') FROM users WHERE id = ?)"
    );
    $statement->execute([$userId, $userId]);

    return (int) $statement->fetchColumn();
}

/** A refusal of the step, in the shape authenticatorCheck() answers with. */
function authenticatorRefusal(string $code, ?string $pausedUntil = null): array
{
    return match ($code) {
        'totp_required' => ['passed' => false, 'code' => $code, 'error' => 'A code from your authenticator app is needed', 'extra' => []],
        'authenticator_paused' => [
            'passed' => false,
            'code' => $code,
            'error' => 'Too many wrong codes: codes from the authenticator app are paused for this account until ' . isoTime($pausedUntil),
            'extra' => ['paused_until' => isoTime($pausedUntil)],
        ],
        default => ['passed' => false, 'code' => 'totp_invalid', 'error' => 'That code is not right', 'extra' => []],
    };
}

/**
 * The wrong codes before now count no more: a right code (above), and a
 * completed sign-in by any way in (completeSignIn()). A pause that holds runs
 * its fifteen minutes all the same.
 */
function restartAuthenticatorCount(PDO $pdo, int $userId): void
{
    if (authenticatorPauseReady($pdo)) {
        $pdo->prepare('UPDATE users SET totp_count_from = CURRENT_TIMESTAMP WHERE id = ?')->execute([$userId]);
    }
}

/**
 * The pause and its count gone: two-factor switched on again, with a secret of
 * its own (routes/account.php) - guesses at another secret say nothing of it.
 */
function clearAuthenticatorPause(PDO $pdo, int $userId): void
{
    if (authenticatorPauseReady($pdo)) {
        $pdo->prepare('UPDATE users SET totp_paused_until = NULL, totp_count_from = CURRENT_TIMESTAMP WHERE id = ?')->execute([$userId]);
    }
}
