<?php

// ---------------------------------------------------------------------------
// config/secret.php - the master key
// ---------------------------------------------------------------------------
// 32 random bytes, written as 64 hex characters, that encrypt what this
// database must not hold in the clear: the private halves of the signing keys
// and the two-factor secrets. A copy of the database without this key cannot
// mint a token and cannot generate anybody's codes.
//
// It lives in config/secret.local.php, returning the hex string - setup.php
// makes one the first time it runs and never replaces it afterwards. It fails
// closed: no key, no IAM. A default committed to the source would be a key
// everybody has.
//
// Keep a copy somewhere safe. Losing it means every signing key has to be
// replaced (everybody signs in again) and every two-factor setup has to be done
// again; the accounts themselves survive.
// ---------------------------------------------------------------------------

require_once __DIR__ . '/config.php';

function iamMasterKey(): string
{
    $hex = localConfig('secret');

    if (!is_string($hex) || !preg_match('/^[0-9a-f]{64}$/i', trim($hex))) {
        throw new IamNotSetUp('config/secret.local.php, the master key, is missing or is not 64 hex characters');
    }

    return (string) hex2bin(trim($hex));
}
