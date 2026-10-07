<?php

/**
 * Sweeping away the rows that stopped meaning anything, and deleting the
 * accounts whose deletion is due.
 *
 * The host has no cron, so this runs from requests that come anyway - a
 * site's authorize and token calls, the IAM's own pages (their first request,
 * /api/public/config, and every account and admin page's), and the sites'
 * reading of the anonymized ids - and does its work at most once an hour,
 * decided by the age of a stamp file rather than a query, so an ordinary
 * request pays for one stat() and nothing else.
 *
 * What goes, and why it is safe:
 *   - authorization requests and codes a day past their expiry: they were good
 *     for half an hour and sixty seconds;
 *   - refresh tokens a month past the moment they stopped working (spent,
 *     revoked or expired): a spent token is kept that long so that presenting it
 *     again is still recognised as reuse, and a month is far past any leeway;
 *   - one-time mailed tokens a month past their expiry;
 *   - sign-in attempts older than a year: the limiter counts fifteen minutes,
 *     and "somebody has been trying" on the account page means recently;
 *   - the accounts whose deletion is due, thirty days after their owners asked
 *     (api/account_deletion.php): made nobody's, each in a transaction of its
 *     own, then mailed - an hour late at the most;
 *   - what a request on its way left of a deleted account's person after it
 *     went - a session, a token, a mail logged, an audit entry's IP or name
 *     (anonymizedLeftovers()), at every run.
 *
 * What stays: sessions, the mail log and the audit log, which are history and
 * the reason anybody can answer "when did this happen". Of a deleted
 * account's person, nothing: the anonymization takes what was there, and the
 * run after a request that raced it takes what that request wrote - an hour
 * at the most, during which none of it opens anything (the account is
 * switched off, and users_anonymized_check keeps every way in off its row).
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
    } catch (\Throwable $e) {
        // Housekeeping failing is never a reason for the request it rode on to.
        error_log('[housekeeping] ' . $e->getMessage());
        return;
    }

    try {
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
        error_log('[housekeeping] ' . $e->getMessage());
    }

    // Apart from the sweep, so neither stops the other.
    try {
        anonymizeDueAccounts($pdo);
    } catch (\Throwable $e) {
        error_log('[housekeeping] the accounts due for deletion: ' . $e->getMessage());
    }
}
