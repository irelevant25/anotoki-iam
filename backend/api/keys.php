<?php

/**
 * The signing keys: making them, rotating them, and publishing their public
 * halves.
 *
 * There is always exactly one active key - made the first time anything needs
 * to sign, so a fresh installation needs no key-generation step - and it signs
 * every token. Rotating makes a new active key and retires the old one, which
 * stays published for a day: every token it signed has expired by then (an ID
 * token lives an hour, an access token ten minutes), and until then an
 * application holding one can still verify it.
 *
 * Applications cache the key set for up to an hour and refetch on an unknown
 * kid, so a rotation needs nothing from them.
 */

/** How long a retired key stays in the JWKS. */
const KEY_RETIRED_GRACE = 86400;

/**
 * The key that signs, made on first use. ['kid', 'private_pem', 'public_pem'].
 *
 * `$reload` is for the request that has just rotated it.
 */
function activeSigningKey(bool $reload = false): array
{
    static $key = null;

    if ($key !== null && !$reload) {
        return $key;
    }

    $row = iamDb()->query("SELECT * FROM signing_keys WHERE status = 'active'")->fetch();

    if (!$row) {
        $row = createFirstSigningKey();
    }

    $privatePem = decryptSecret($row['private_key']);
    if ($privatePem === null) {
        throw new RuntimeException(
            'The active signing key cannot be decrypted - config/secret.local.php is not the key it was made with. '
            . 'Restore that file, or rotate the key from the database (everybody will have to sign in again).'
        );
    }

    return $key = ['kid' => $row['kid'], 'private_pem' => $privatePem, 'public_pem' => jwkToPem($row['public_n'], $row['public_e'])];
}

/**
 * The first key of a fresh installation.
 *
 * Two requests arriving at an installation that has never signed anything
 * both find no key; the unique index on the active status lets one insert win
 * and the other do nothing, and both then read the winner.
 */
function createFirstSigningKey(): array
{
    $details = generateRsaKeyPair();
    $pdo = iamDb();

    $pdo->prepare(
        "INSERT INTO signing_keys (kid, public_n, public_e, private_key, status) VALUES (?, ?, ?, ?, 'active')
         ON CONFLICT (status) WHERE status = 'active' DO NOTHING"
    )->execute([newKid(), $details['n'], $details['e'], encryptSecret($details['private_pem'])]);

    return $pdo->query("SELECT * FROM signing_keys WHERE status = 'active'")->fetch();
}

/**
 * Rotation: a new RSA-2048 key becomes the active one, the old one retires.
 *
 * One transaction, so there is no moment with two active keys - the unique
 * index would refuse that anyway - or with none.
 */
function rotateSigningKey(): array
{
    $details = generateRsaKeyPair();
    $pdo = iamDb();

    $pdo->beginTransaction();
    try {
        $pdo->exec("UPDATE signing_keys SET status = 'retired', retired_at = CURRENT_TIMESTAMP WHERE status = 'active'");
        $insert = $pdo->prepare(
            "INSERT INTO signing_keys (kid, public_n, public_e, private_key, status) VALUES (?, ?, ?, ?, 'active') RETURNING kid"
        );
        $insert->execute([newKid(), $details['n'], $details['e'], encryptSecret($details['private_pem'])]);
        $kid = (string) $insert->fetchColumn();
        $pdo->commit();
    } catch (\Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    return activeSigningKey(true) + ['kid' => $kid];
}

/** A key id that says when the key was made and nothing else. */
function newKid(): string
{
    return gmdate('Ymd') . '-' . randomHex(4);
}

/**
 * openssl's key generation, with the Windows workaround.
 *
 * Some Windows builds of PHP cannot find openssl.cnf and refuse to generate a
 * key without one; an empty configuration is all generation needs.
 *
 * @return array{private_pem: string, n: string, e: string}
 */
function generateRsaKeyPair(): array
{
    $options = ['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA];
    $cnf = null;

    $key = @openssl_pkey_new($options);
    if ($key === false) {
        $cnf = tempnam(sys_get_temp_dir(), 'iam');
        file_put_contents($cnf, "[req]\ndistinguished_name=dn\n[dn]\n");
        $options['config'] = $cnf;
        $key = openssl_pkey_new($options);
    }

    $pem = '';
    $exported = $key !== false && openssl_pkey_export($key, $pem, null, $cnf ? ['config' => $cnf] : []);

    if ($cnf !== null) {
        @unlink($cnf);
    }

    if (!$exported) {
        throw new RuntimeException('openssl could not generate a signing key: ' . openssl_error_string());
    }

    $details = openssl_pkey_get_details($key);

    return [
        'private_pem' => $pem,
        'n' => base64UrlEncode($details['rsa']['n']),
        'e' => base64UrlEncode($details['rsa']['e']),
    ];
}

/** A published key by kid - the active one or a retired one inside its grace. */
function signingKeyByKid(string $kid): ?array
{
    if ($kid === '') {
        return null;
    }

    $statement = iamDb()->prepare(
        "SELECT kid, public_n, public_e FROM signing_keys
          WHERE kid = ? AND (status = 'active' OR retired_at > CURRENT_TIMESTAMP - (? * INTERVAL '1 second'))"
    );
    $statement->execute([$kid, KEY_RETIRED_GRACE]);
    $row = $statement->fetch();

    return $row ? ['kid' => $row['kid'], 'public_pem' => jwkToPem($row['public_n'], $row['public_e'])] : null;
}

/** The JWKS document. Makes the first key if there is none yet. */
function publishedKeys(): array
{
    activeSigningKey();

    $statement = iamDb()->prepare(
        "SELECT kid, public_n, public_e FROM signing_keys
          WHERE status = 'active' OR retired_at > CURRENT_TIMESTAMP - (? * INTERVAL '1 second')
          ORDER BY created_at DESC"
    );
    $statement->execute([KEY_RETIRED_GRACE]);

    return ['keys' => array_map(static fn(array $row): array => [
        'kty' => 'RSA',
        'use' => 'sig',
        'alg' => 'RS256',
        'kid' => $row['kid'],
        'n' => $row['public_n'],
        'e' => $row['public_e'],
    ], $statement->fetchAll())];
}

// A JWK's modulus and exponent back into the PEM openssl reads - the same DER
// wrapping the SDK does on the applications' side.

function derLength(int $length): string
{
    if ($length < 128) {
        return chr($length);
    }

    $bytes = ltrim(pack('N', $length), "\x00");

    return chr(0x80 | strlen($bytes)) . $bytes;
}

function derInteger(string $bytes): string
{
    $bytes = ltrim($bytes, "\x00");
    if ($bytes === '') {
        $bytes = "\x00";
    }
    if (ord($bytes[0]) > 0x7F) {
        $bytes = "\x00" . $bytes;
    }

    return "\x02" . derLength(strlen($bytes)) . $bytes;
}

function derSequence(string $contents): string
{
    return "\x30" . derLength(strlen($contents)) . $contents;
}

function jwkToPem(string $n, string $e): string
{
    $algorithm = derSequence("\x06\x09\x2a\x86\x48\x86\xf7\x0d\x01\x01\x01\x05\x00");
    $publicKey = derSequence(derInteger(base64UrlDecode($n)) . derInteger(base64UrlDecode($e)));
    $bitString = "\x03" . derLength(strlen($publicKey) + 1) . "\x00" . $publicKey;

    return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode(derSequence($algorithm . $bitString)), 64, "\n") . "-----END PUBLIC KEY-----\n";
}
