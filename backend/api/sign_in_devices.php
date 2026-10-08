<?php

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * The browsers an account has signed in from, and the mail when it signs in
 * from one it has not (migration 016, the owner's decision of 2026-10-07).
 *
 * The owner: "Not based on login to the project but iam in general so there
 * will be no duplicate emails". So it is decided here, at the IAM's own
 * sign-in - every way in ends in completeSignIn(), after two-factor when the
 * account has it - and never when a site uses the sign-in silently (single
 * sign-on at authorize) or refreshes its tokens: a person using five sites
 * from one browser hears of it once, when that browser first signs in.
 *
 * A browser is known by a cookie of its own, `anotoki_iam_device`: a random
 * secret, HttpOnly, Secure over https, SameSite=Lax, for 400 days - the most
 * a browser keeps a cookie - set again at every sign-in, so a browser in use
 * never forgets it. The table keeps the SHA-256 of the account's id and that
 * secret: one browser is a different row for every account signed in from
 * it, and the table never says that two accounts share one.
 *
 * It is NOT a credential and NOT the two-factor "remembered device"
 * (api/trusted_device.php). It opens nothing, skips nothing, and nothing
 * reads it but this file; a copy of it gains a thief only that one sign-in
 * goes unmailed - from a browser that has the password, the code, or the
 * Google account already.
 *
 * Release day must not mail everybody: an account with no browser known yet
 * - every account there is when 016 is applied, and every new one - has its
 * first one noted without a mail. From the second browser on, each new one is
 * mailed once.
 *
 * Nothing here may fail or hold up a sign-in. The table is asked about before
 * it is read - a build goes up before its migrations - and anything that goes
 * wrong is logged and passed over: the person is signed in either way.
 */

/** 400 days: what Chrome holds a cookie's lifetime to, and longer than any sign-in lasts. */
const SIGN_IN_DEVICE_LIFETIME = 400 * 86400;

/**
 * At most this many new-browser mails to one account in an hour - counted by
 * the browsers' own mailed_at, under the account's row lock. Every one
 * follows a sign-in that got past the password and the second factor - but
 * a client that keeps no cookie is a new browser every time, and whoever has
 * the password must not be able to bury the owner's mailbox with them. Five
 * warnings in an hour have said what there is to say.
 */
const SIGN_IN_DEVICE_MAILS_PER_HOUR = 5;

/** `anotoki_iam_device`: named after the session cookie's configured name, never its __Host- one - a browser keeps the cookie it has. */
function signInDeviceCookieName(): string
{
    return sessionCookieBaseName() . '_device';
}

/** Whether migration 016's table is there. A catalog query that cannot fail, asked afresh: the admin panel's Apply runs in this same process. */
function signInDevicesReady(PDO $pdo): bool
{
    return asBool($pdo->query("SELECT to_regclass('public.user_sign_in_devices') IS NOT NULL")->fetchColumn());
}

/** The browser's secret from its cookie, when it is one of ours in shape. */
function signInDeviceSecretFrom(Request $request): ?string
{
    $value = $request->getCookieParams()[signInDeviceCookieName()] ?? null;

    return is_string($value) && preg_match('/^[A-Za-z0-9_-]{40,64}$/D', $value) ? $value : null;
}

/** What the table keeps of a browser for one account: never the secret itself, and never the same for two accounts. */
function signInDeviceHash(int $userId, string $secret): string
{
    return sha256('sign-in-device:' . $userId . ':' . $secret);
}

function withSignInDeviceCookie(Response $response, string $secret): Response
{
    $parts = [signInDeviceCookieName() . '=' . $secret, 'Path=/', 'HttpOnly', 'SameSite=Lax', 'Max-Age=' . SIGN_IN_DEVICE_LIFETIME];

    if (sessionCookieSecure()) {
        $parts[] = 'Secure';
    }

    return $response->withAddedHeader('Set-Cookie', implode('; ', $parts));
}

/**
 * The browser and its system in a few words - "Firefox · Windows" - from a
 * fixed list, or null when nothing on it is recognised. The account page reads
 * a user agent the same way (frontend core/util/user-agent.ts). Order matters:
 * Edge and Opera say Chrome too, and Chrome says Safari.
 *
 * Only these words ever leave this function. The user agent is whatever the
 * browser - or whoever is signing in - chose to send, so none of it is put into
 * the mail or the table as it is.
 */
function signInDeviceLabel(?string $userAgent): ?string
{
    $agent = (string) $userAgent;

    $browsers = [
        '/Edg(e|A|iOS)?\//' => 'Edge',
        '/OPR\/|Opera/' => 'Opera',
        '/SamsungBrowser/' => 'Samsung Internet',
        '/Vivaldi/' => 'Vivaldi',
        '/Firefox\/|FxiOS/' => 'Firefox',
        '/Chrome\/|CriOS/' => 'Chrome',
        '/Safari\//' => 'Safari',
        '/curl\//' => 'curl',
    ];
    $systems = [
        '/Android/' => 'Android',
        '/iPhone/' => 'iPhone',
        '/iPad/' => 'iPad',
        '/Windows/' => 'Windows',
        '/Mac OS X|Macintosh/' => 'macOS',
        '/CrOS/' => 'ChromeOS',
        '/Linux/' => 'Linux',
    ];

    $found = static function (array $patterns) use ($agent): ?string {
        foreach ($patterns as $pattern => $name) {
            if (preg_match($pattern, $agent) === 1) {
                return $name;
            }
        }

        return null;
    };

    $label = implode(' · ', array_filter([$found($browsers), $found($systems)], static fn(?string $part): bool => $part !== null));

    return $label === '' ? null : $label;
}

/**
 * Notes the browser a sign-in came from, and answers the response with its
 * cookie (set again, so its 400 days start over). Called by completeSignIn()
 * once the session is open - the one place every way in ends.
 *
 * Known to this account: its last_seen_at, nothing else. Unknown: it is noted,
 * and the account is mailed - unless no browser was known for it at all (its
 * first, and every account's first sign-in after 016), which is noted in
 * silence, or five were mailed about in the last hour (each browser's
 * mailed_at). One sign-in at a time per account (its row locked for the
 * moment it takes), so two first sign-ins at once are one first and one
 * second, and two at the cap are one mail and none.
 *
 * The mail goes after the commit and through sendMail(), which never throws;
 * a failure anywhere is logged and the sign-in goes on.
 */
function noteSignInDevice(PDO $pdo, Request $request, Response $response, array $user): Response
{
    try {
        if (!signInDevicesReady($pdo)) {
            return $response;
        }

        $userId = (int) $user['id'];
        $secret = signInDeviceSecretFrom($request) ?? randomSecret(32);
        $hash = signInDeviceHash($userId, $secret);
        $label = signInDeviceLabel(requestUserAgent($request));

        $pdo->beginTransaction();
        try {
            // The account's row locked: one sign-in at a time is noted. And an
            // account deleted while this sign-in was on its way (its password
            // checked before, its session opened after) is noted nowhere.
            $account = $pdo->prepare('SELECT disabled FROM users WHERE id = ? FOR UPDATE');
            $account->execute([$userId]);
            if (asBool($account->fetchColumn())) {
                $pdo->rollBack();
                return $response;
            }

            $touch = $pdo->prepare('UPDATE user_sign_in_devices SET last_seen_at = CURRENT_TIMESTAMP, user_agent = ? WHERE user_id = ? AND token_hash = ?');
            $touch->execute([$label, $userId, $hash]);
            $known = $touch->rowCount() === 1;

            $mail = false;
            if (!$known) {
                $any = $pdo->prepare('SELECT EXISTS (SELECT 1 FROM user_sign_in_devices WHERE user_id = ?)');
                $any->execute([$userId]);
                $first = !asBool($any->fetchColumn());

                // Only to an address somebody proved - an unconfirmed one may
                // be anybody's - and no more than SIGN_IN_DEVICE_MAILS_PER_HOUR
                // an hour: counted and taken here, under the row's lock, so two
                // sign-ins at once cannot both take the last one.
                if (!$first && asBool($user['email_confirmed'])) {
                    $recent = $pdo->prepare("SELECT count(*) FROM user_sign_in_devices WHERE user_id = ? AND mailed_at > CURRENT_TIMESTAMP - INTERVAL '1 hour'");
                    $recent->execute([$userId]);
                    $mail = (int) $recent->fetchColumn() < SIGN_IN_DEVICE_MAILS_PER_HOUR;
                }

                $pdo->prepare('INSERT INTO user_sign_in_devices (user_id, token_hash, user_agent, mailed_at) VALUES (?, ?, ?, ' . ($mail ? 'CURRENT_TIMESTAMP' : 'NULL') . ')')
                    ->execute([$userId, $hash, $label]);
            }

            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        if ($mail) {
            sendNewSignInMail($user, $label, time());
        }

        return withSignInDeviceCookie($response, $secret);
    } catch (\Throwable $e) {
        error_log('[sign-in devices] could not note the browser of a sign-in: ' . $e->getMessage());

        return $response;
    }
}

/**
 * Forgets every browser an account has signed in from. A reset link, and the
 * owner of an address claiming an account never confirmed, take an account
 * back completely: a browser of whoever held it must not stay one the account
 * is not mailed about. The browser that takes it back is noted next, as the
 * first again. Before 016 there is nothing to forget.
 */
function forgetSignInDevices(PDO $pdo, int $userId): void
{
    if (signInDevicesReady($pdo)) {
        $pdo->prepare('DELETE FROM user_sign_in_devices WHERE user_id = ?')->execute([$userId]);
    }
}
