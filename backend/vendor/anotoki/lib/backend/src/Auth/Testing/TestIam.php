<?php

declare(strict_types=1);

namespace Anotoki\Lib\Auth\Testing;

use Anotoki\Lib\Auth\Base64Url;
use Anotoki\Lib\Auth\IamClient;
use InvalidArgumentException;
use RuntimeException;

/**
 * An IAM for a site's tests, without the IAM: a key pair of its own, tokens
 * signed with it, and the files a site's IamClient reads in place of asking
 * the IAM - the key set (jwks_file), the answer about signing in
 * (application_file) and the deleted accounts (anonymized_file). Never used by
 * a running site; a site's verifier trusts only the key set its settings name.
 *
 *   $iam = TestIam::create($dir);                       // https://iam.test, client test-site
 *   $client = $iam->client();                           // an IamClient on the files in $dir
 *   $token = $iam->token(7, ['EDITOR']);                // an access token for user 7
 *   $iam->signInRule(false);                            // the IAM says: visitors welcome
 *   $iam->anonymized(['12' => '2026-11-06T10:00:00Z']); // the IAM deleted account 12 then
 *
 * The same folder serves the stand-in IAM (StandInIam), which speaks the
 * protocol itself for what a file cannot: the browser's sign-in, the hint
 * cookie, a 404, an IAM that is down.
 */
final class TestIam
{
    public const KEY_FILE = 'key.json';
    public const JWKS_FILE = 'jwks.json';
    public const APPLICATION_FILE = 'application.json';
    public const ANONYMIZED_FILE = 'anonymized.json';

    /**
     * @param array<string, mixed> $jwks
     */
    private function __construct(
        public readonly string $dir,
        public readonly string $issuer,
        public readonly string $clientId,
        public readonly string $kid,
        private readonly string $privatePem,
        private readonly array $jwks,
    ) {}

    /**
     * A test IAM keeping its files in $dir (made when missing): the key pair
     * already there, or a new one.
     */
    public static function create(string $dir, string $issuer = 'https://iam.test', string $clientId = 'test-site', ?string $kid = null): self
    {
        $dir = rtrim(str_replace('\\', '/', $dir), '/');
        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new RuntimeException("TestIam: the folder $dir cannot be made.");
        }

        $keyFile = $dir . '/' . self::KEY_FILE;
        $kept = is_file($keyFile) ? json_decode((string) file_get_contents($keyFile), true) : null;
        if (!is_array($kept) || !is_string($kept['private_pem'] ?? null) || !is_string($kept['kid'] ?? null) || !is_array($kept['jwks'] ?? null) || ($kid !== null && $kept['kid'] !== $kid)) {
            $kept = self::keypair($kid);
            file_put_contents($keyFile, json_encode($kept, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
        }
        file_put_contents($dir . '/' . self::JWKS_FILE, json_encode($kept['jwks'], JSON_UNESCAPED_SLASHES));

        return new self($dir, rtrim($issuer, '/'), $clientId, $kept['kid'], $kept['private_pem'], $kept['jwks']);
    }

    /**
     * A fresh RSA key pair: [kid, private_pem, jwks - a JWKS document].
     *
     * Windows builds of PHP need to be told where openssl.cnf is, and not all of
     * them ship one where OpenSSL looks: a configuration that says nothing is
     * enough for making a key.
     *
     * @return array{kid: string, private_pem: string, jwks: array{keys: list<array<string, string>>}}
     */
    public static function keypair(?string $kid = null): array
    {
        $options = ['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA];
        $key = @openssl_pkey_new($options);
        $config = null;
        if ($key === false) {
            $config = tempnam(sys_get_temp_dir(), 'cnf');
            file_put_contents($config, "[req]\ndistinguished_name=dn\n[dn]\n");
            $options['config'] = $config;
            $key = openssl_pkey_new($options);
        }
        $exported = $key !== false && openssl_pkey_export($key, $privatePem, null, $options);
        if ($config !== null) {
            @unlink($config);
        }
        if (!$exported || $key === false) {
            throw new RuntimeException('openssl could not make a test key: ' . openssl_error_string());
        }

        $details = openssl_pkey_get_details($key);
        $kid ??= bin2hex(random_bytes(8));

        return [
            'kid' => $kid,
            'private_pem' => (string) $privatePem,
            'jwks' => [
                'keys' => [
                    [
                        'kty' => 'RSA',
                        'use' => 'sig',
                        'alg' => 'RS256',
                        'kid' => $kid,
                        'n' => Base64Url::encode($details['rsa']['n']),
                        'e' => Base64Url::encode($details['rsa']['e']),
                    ],
                ],
            ],
        ];
    }

    /**
     * An IamClient that reads this folder instead of asking an IAM: its key
     * set, its answer about signing in, its deleted accounts, and a cache
     * folder of its own. $overrides are IamClient::fromArray()'s keys.
     *
     * @param array<string, mixed> $overrides
     */
    public function client(array $overrides = []): IamClient
    {
        return IamClient::fromArray(
            $overrides + [
                'issuer' => $this->issuer,
                'client_id' => $this->clientId,
                'cache_dir' => $this->dir . '/cache',
                'jwks_file' => $this->dir . '/' . self::JWKS_FILE,
                'application_file' => $this->dir . '/' . self::APPLICATION_FILE,
                'anonymized_file' => $this->dir . '/' . self::ANONYMIZED_FILE,
            ],
        );
    }

    /**
     * An access token's claims that verify against client(): this IAM, this
     * client, ten minutes, a session, the profile.
     *
     * @param list<string> $roles
     * @param array<string, mixed> $extra  claims to add or replace (null removes one)
     * @return array<string, mixed>
     */
    public function claims(int $sub, array $roles = ['MEMBER'], array $extra = []): array
    {
        $now = time();
        $claims = array_merge(
            [
                'iss' => $this->issuer,
                'sub' => (string) $sub,
                'aud' => $this->clientId,
                'client_id' => $this->clientId,
                'iat' => $now,
                'exp' => $now + 600,
                'jti' => bin2hex(random_bytes(8)),
                'sid' => bin2hex(random_bytes(16)),
                'auth_time' => $now,
                'scope' => 'openid profile email',
                'roles' => array_values($roles),
                'preferred_username' => 'user' . $sub,
                'email' => 'user' . $sub . '@example.test',
                'email_verified' => true,
                'locale' => 'en',
            ],
            $extra,
        );

        return array_filter($claims, static fn(mixed $value): bool => $value !== null);
    }

    /**
     * An access token for user $sub.
     *
     * @param list<string> $roles
     * @param array<string, mixed> $claims  claims to add or replace (null removes one)
     */
    public function token(int $sub, array $roles = ['MEMBER'], array $claims = []): string
    {
        return $this->sign($this->claims($sub, $roles, $claims));
    }

    /**
     * Any JWT signed with this IAM's key: $typ and $alg are written into the
     * header as given (a test of what must be refused says "JWT" or "none").
     *
     * @param array<string, mixed> $claims
     * @param array<string, mixed> $header  more of the header (crit, a kid of another key)
     */
    public function sign(array $claims, string $typ = 'at+jwt', string $alg = 'RS256', array $header = []): string
    {
        $head = Base64Url::encode(json_encode($header + ['alg' => $alg, 'typ' => $typ, 'kid' => $this->kid], JSON_UNESCAPED_SLASHES));
        $payload = Base64Url::encode(json_encode($claims, JSON_UNESCAPED_SLASHES));
        if (!openssl_sign($head . '.' . $payload, $signature, $this->privatePem, OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('openssl could not sign the test token.');
        }

        return $head . '.' . $payload . '.' . Base64Url::encode($signature);
    }

    /**
     * The IAM's answer about this application, as a running IAM gives it:
     * whether its site requires signing in. Null removes the file: the IAM out
     * of reach. (A 404 or a 503 needs the stand-in: StandInIam.)
     */
    public function signInRule(?bool $loginRequired, ?string $clientId = null): void
    {
        $file = $this->dir . '/' . self::APPLICATION_FILE;
        if ($loginRequired === null) {
            @unlink($file);

            return;
        }
        file_put_contents($file, json_encode(['client_id' => $clientId ?? $this->clientId, 'name' => 'Test site', 'login_required' => $loginRequired]));
    }

    /**
     * The accounts the IAM deleted, by id: [sub => anonymized_at (ISO 8601,
     * whole seconds, UTC)]. Null removes the file: the IAM out of reach.
     *
     * @param ?array<int|string, string> $accounts
     */
    public function anonymized(?array $accounts): void
    {
        $file = $this->dir . '/' . self::ANONYMIZED_FILE;
        if ($accounts === null) {
            @unlink($file);

            return;
        }
        $list = [];
        foreach ($accounts as $sub => $at) {
            if (!is_string($at) || preg_match('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ$/D', $at) !== 1) {
                throw new InvalidArgumentException("TestIam: account $sub's time is not ISO 8601 in whole seconds, UTC ('2026-11-06T10:00:00Z').");
            }
            $list[] = ['sub' => (string) $sub, 'anonymized_at' => $at];
        }
        file_put_contents($file, json_encode(['accounts' => $list], JSON_UNESCAPED_SLASHES));
    }

    /** @return array<string, mixed> the key set this IAM publishes */
    public function jwks(): array
    {
        return $this->jwks;
    }
}
