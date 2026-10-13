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
// "Behind Cloudflare"). Without the file, or with an empty value, there is no
// secret: CF-Connecting-IP is believed from Cloudflare's ranges alone, as
// before. A file that is there but cannot be read cleanly - one that prints
// (a byte-order mark, no <?php line), or that PHP cannot read - fails closed:
// its secret is one no request carries, so every request from Cloudflare's
// ranges counts as the edge it came from until the file is mended. A slip of
// the editor never switches the check off. The secret is never printed, never
// logged and never in an answer.
// ---------------------------------------------------------------------------

require_once __DIR__ . '/config.php';

/**
 * The origin secret, or null for none: no file, or no origin_secret in it that
 * is a string with something in it. Trimmed: a header's value never starts or
 * ends with a space, so a value written with one - a line break left at its
 * end - would never match. A file that is there and holds no secret is said in
 * PHP's error log, by its name alone.
 *
 * A file that is there but was passed over (localConfigProblem(): it prints,
 * or PHP cannot read it) is a secret no request carries - a random value of
 * this process, never written anywhere -, said in the error log as well: its
 * owner meant a secret, and believing CF-Connecting-IP from the ranges alone
 * would be the looser reading.
 */
function iamOriginSecret(): ?string
{
    if (localConfigProblem('cloudflare') !== null) {
        localConfigSaid('config/cloudflare.local.php cannot be read cleanly: no request carries its secret, so every request from Cloudflare\'s ranges counts as the edge it came from until the file is mended');

        return iamUnmatchableOriginSecret();
    }

    $config = localConfig('cloudflare');
    if ($config === null) {
        return null;
    }

    $secret = is_array($config) ? ($config['origin_secret'] ?? null) : null;
    $secret = is_string($secret) ? trim($secret) : '';
    if ($secret === '') {
        localConfigSaid('config/cloudflare.local.php holds no origin_secret (a string with something in it): CF-Connecting-IP is believed from Cloudflare\'s ranges alone');

        return null;
    }

    return $secret;
}

/**
 * A secret no request can carry: 256 random bits, made once a process and
 * never printed, logged or stored, so no header matches it.
 */
function iamUnmatchableOriginSecret(): string
{
    static $secret = null;

    return $secret ??= bin2hex(random_bytes(32));
}
