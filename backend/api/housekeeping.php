<?php

/**
 * Sweeping away the rows that stopped meaning anything.
 *
 * The host has no cron, so this runs from the endpoints that are busy anyway -
 * authorize and token - and at most once an hour, decided by the age of a
 * stamp file rather than a query, so an ordinary request pays for one stat()
 * and nothing else.
 *
 * What goes, and why it is safe:
 *   - authorization requests and codes a day past their expiry: they were good
 *     for half an hour and sixty seconds;
 *   - refresh tokens a month past the moment they stopped working (spent,
 *     revoked or expired): a spent token is kept that long so that presenting it
 *     again is still recognised as reuse, and a month is far past any leeway;
 *   - one-time mailed tokens a month past their expiry;
 *   - sign-in attempts older than a year: the limiter counts fifteen minutes,
 *     and "somebody has been trying" on the account page means recently.
 *
 * What stays: sessions, the mail log and the audit log, which are history and
 * the reason anybody can answer "when did this happen".
 */

const HOUSEKEEPING_INTERVAL = 3600;

function iamHousekeeping(): void
{
    $stamp = iamStorageDir() . '/cache/housekeeping.stamp';
    $last = @filemtime($stamp);

    if ($last !== false && time() - $last < HOUSEKEEPING_INTERVAL) {
        return;
    }

    // Claimed first, so two requests arriving together do not both sweep.
    $directory = dirname($stamp);
    if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
        return;
    }
    @touch($stamp);

    try {
        $pdo = iamDb();
        $pdo->exec("DELETE FROM auth_requests WHERE expires_at < CURRENT_TIMESTAMP - INTERVAL '1 day'");
        $pdo->exec("DELETE FROM auth_codes WHERE expires_at < CURRENT_TIMESTAMP - INTERVAL '1 day'");
        $pdo->exec(
            "DELETE FROM refresh_tokens
              WHERE COALESCE(revoked_at, used_at, expires_at) < CURRENT_TIMESTAMP - INTERVAL '30 days'
                AND (revoked_at IS NOT NULL OR used_at IS NOT NULL OR expires_at < CURRENT_TIMESTAMP)"
        );
        $pdo->exec("DELETE FROM user_tokens WHERE expires_at < CURRENT_TIMESTAMP - INTERVAL '30 days'");
        $pdo->exec("DELETE FROM login_attempts WHERE created_at < CURRENT_TIMESTAMP - INTERVAL '365 days'");
    } catch (\Throwable $e) {
        // Housekeeping failing is never a reason for the request it rode on to.
        error_log('[housekeeping] ' . $e->getMessage());
    }
}
