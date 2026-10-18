<?php

// ---------------------------------------------------------------------------
// config/cloudflare.php - the secret the owner's Cloudflare zone adds
// ---------------------------------------------------------------------------
// The IAM believes the visitor's address Cloudflare names in CF-Connecting-IP
// on a connection from Cloudflare's published ranges (config/iam.php,
// api/helpers.php's requestIp()). But those ranges are every Cloudflare
// customer's, not the owner's zone alone: anybody's Worker can open a raw
// socket to the host from them and write the CF-Connecting-IP it likes - a
// fresh address for every request, past every per-address limit. So a
// Transform Rule of the owner's zone adds a secret header, X-Anotoki-Origin,
// to every request through it (the owner's decision of 2026-10-13,
// docs/decisions.md), and with the secret the IAM believes CF-Connecting-IP
// only on a request that carries it: any other request from Cloudflare's
// ranges counts as the edge it came from - coarser, never looser. The anotoki
// library checks it (ClientAddress, anotoki-lib 0.5.2), in constant time.
//
// From config/cloudflare.local.php - the same file in every anotoki site,
// uploaded by hand beside the others (setup.php neither asks for it nor writes
// it, and iamSetUpProblems() does not ask for it):
//
//   <?php return ['origin_secret' => '<a long random value, 32 characters or more>'];
//
// with the same value in the zone's Transform Rule (docs/operations.md,
// "Behind Cloudflare"). Without the file - nothing at all at its path - there
// is no secret: CF-Connecting-IP is believed from Cloudflare's ranges alone,
// as before. Anything there that yields no usable secret fails closed: its
// secret is one no request carries, so every request from Cloudflare's ranges
// counts as the edge it came from until the file is mended. That is a file
// that cannot be read cleanly - one that does not start with <?php (a
// byte-order mark, a blank line, no <?php line: never compiled), one that
// prints (text after a closing tag), one PHP cannot read - and, since
// 2026-10-18, one read cleanly that names no usable secret - blank, a value
// that is no string or only spaces, a misspelt key, no array, a forgotten
// return, fewer than 32 characters, a placeholder left in (< or >) - and a
// folder in the file's place (docs/decisions.md: whoever put
// something there meant a secret, and believing every Cloudflare customer's
// Worker would be the looser reading). Only deleting the file switches the
// secret off. Either way it is said in PHP's error log, once a day, by the
// file's name. The secret is never printed, never logged and never in an
// answer.
// ---------------------------------------------------------------------------

require_once __DIR__ . '/config.php';

/** The shortest origin secret believed: a shorter one, a Worker could guess. */
const IAM_ORIGIN_SECRET_MIN_LENGTH = 32;

/**
 * The origin secret, or null for none: nothing at config/cloudflare.local.php.
 * Trimmed: a header's value never starts or ends with a space, so a value
 * written with one - a line break left at its end - would never match.
 *
 * Anything there that yields no origin_secret that is a string of 32
 * characters or more, with no < or > (a placeholder left in), is a secret no
 * request carries - a random value of this process, never
 * written anywhere -, said in PHP's error log by the file's name alone: a file
 * passed over (localConfigProblem(): it does not start with <?php, it prints,
 * PHP cannot read it), one read cleanly that names none, a folder in its place
 * (localConfigThere()). Its owner meant a secret, and believing CF-Connecting-IP
 * from the ranges alone - every Cloudflare customer's Worker - would be the
 * looser reading; only deleting the file switches the secret off (the owner's
 * "do as you think is the best" of 2026-10-18, docs/decisions.md).
 */
function iamOriginSecret(): ?string
{
    if (!localConfigThere('cloudflare')) {
        return null;
    }

    if (localConfigProblem('cloudflare') !== null) {
        localConfigSaid(
            'config/cloudflare.local.php cannot be read cleanly: no request carries its secret, so every request from Cloudflare\'s ranges counts as the edge it came from until the file is mended',
        );

        return iamUnmatchableOriginSecret();
    }

    $config = localConfig('cloudflare');
    $secret = is_array($config) ? $config['origin_secret'] ?? null : null;
    $secret = is_string($secret) ? trim($secret) : '';
    if ($secret === '') {
        localConfigSaid(
            'config/cloudflare.local.php holds no origin_secret (a string with something in it): no request carries its secret, so every request from Cloudflare\'s ranges counts as the edge it came from until the file names one - deleting the file switches the secret off',
        );

        return iamUnmatchableOriginSecret();
    }

    // A value a Worker could guess is no secret either (the IAM's review of
    // 2026-10-18): a few characters, or the placeholder of these comments and
    // docs/operations.md pasted into the file and the zone's rule alike - the
    // limiter that stopped tripping would tell the guesser it was right.
    if (strlen($secret) < IAM_ORIGIN_SECRET_MIN_LENGTH || strpbrk($secret, '<>') !== false) {
        localConfigSaid(
            'config/cloudflare.local.php holds an origin_secret shorter than ' .
                IAM_ORIGIN_SECRET_MIN_LENGTH .
                ' characters, or a placeholder (with < or >): no request carries its secret, so every request from Cloudflare\'s ranges counts as the edge it came from until the file names the zone\'s long random value',
        );

        return iamUnmatchableOriginSecret();
    }

    return $secret;
}

/**
 * A secret no request can carry: 256 random bits, made once a process and
 * never printed, logged or stored, so no header matches it - the secret of a
 * cloudflare.local.php that is there but yields no usable one.
 */
function iamUnmatchableOriginSecret(): string
{
    static $secret = null;

    return $secret ??= bin2hex(random_bytes(32));
}
