<?php

declare(strict_types=1);

namespace Anotoki\Lib\Auth;

use Anotoki\Lib\Support\AtomicFile;
use Anotoki\Lib\Support\HttpGet;
use Closure;

/**
 * The IAM's signing keys (its JWKS), as a site keeps them.
 *
 * Kept on disk in the IAM client's cache folder for an hour (cache_ttl), in
 * the file the IAM's PHP SDK used (anotoki-iam-jwks-{sha1 of the address}.json)
 * and in its shape, so a site that moves from the SDK to the library keeps its
 * copy. A kid that is not in the copy is worth one fetch - the IAM has just
 * rotated its key - but not more often than every five minutes, so a stream of
 * made-up kids cannot turn every request into a download. A key set that
 * cannot be fetched is no error: the copy kept is used, and a token signed by
 * a key not in it simply fails. After a failed fetch the IAM is not asked again
 * for five minutes either, so an IAM that is down does not make every request
 * wait for it once the hour is over.
 *
 * Only RSA keys meant for signatures with RS256 are kept (a key that says
 * another use or another algorithm is passed over).
 */
final class Jwks
{
    /** The least time between two fetches of the key set, in seconds. */
    public const REFETCH_EVERY = 300;

    /** @var array<string, array{fetched_at: int, failed_at: int, keys: array<string, array<string, string>>}> */
    private static array $memory = [];

    /**
     * @param ?Closure(string $url, float $timeout): array{status: int, body: ?string} $fetch  how the key set
     *        is fetched (HttpGet::fetch() by default)
     */
    public function __construct(private readonly IamClient $iam, private readonly ?Closure $fetch = null) {}

    /**
     * The key for a kid, fetching the set again once when it is not there.
     *
     * @return ?array{kty: string, kid: string, n: string, e: string}
     */
    public function key(string $kid): ?array
    {
        $keys = $this->keys();
        if (!isset($keys[$kid]) && $this->iam->jwksFile === null) {
            $keys = $this->keys(true);
        }

        return $keys[$kid] ?? null;
    }

    /**
     * The usable keys by kid. $refresh asks for a fresh copy, which is honoured
     * at most every five minutes.
     *
     * @return array<string, array{kty: string, kid: string, n: string, e: string}>
     */
    public function keys(bool $refresh = false): array
    {
        if ($this->iam->jwksFile !== null) {
            $document = json_decode((string) @file_get_contents($this->iam->jwksFile), true);

            return self::usable(is_array($document) ? $document : []);
        }

        $url = $this->iam->keySetUrl();
        $directory = $this->iam->cacheDirectory();
        $file = $directory === null ? null : $directory . '/anotoki-iam-jwks-' . sha1($url) . '.json';
        $now = time();
        $kept = $this->read($file, $url, $now);

        $fresh = $kept !== null && $kept['fetched_at'] + $this->iam->cacheTtl > $now;
        $lastTry = max($kept['fetched_at'] ?? 0, $kept['failed_at'] ?? 0);
        $mayFetch = $lastTry + self::REFETCH_EVERY < $now;

        if ($kept !== null && (!$mayFetch || ($fresh && !$refresh))) {
            return $kept['keys'];
        }

        $answer = $this->fetch !== null ? ($this->fetch)($url, (float) $this->iam->httpTimeout) : HttpGet::fetch($url, (float) $this->iam->httpTimeout);
        $document = $answer['status'] === 200 && is_string($answer['body']) ? json_decode($answer['body'], true) : null;

        if (!is_array($document) || !isset($document['keys']) || !is_array($document['keys'])) {
            $record = ['fetched_at' => $kept['fetched_at'] ?? 0, 'failed_at' => $now, 'keys' => $kept['keys'] ?? []];
            $this->write($file, $url, $record);

            return $record['keys'];
        }

        $record = ['fetched_at' => $now, 'failed_at' => 0, 'keys' => self::usable($document)];
        $this->write($file, $url, $record);

        return $record['keys'];
    }

    /**
     * The RSA signature keys of a JWKS document, by kid.
     *
     * @param array<mixed> $document
     * @return array<string, array{kty: string, kid: string, n: string, e: string}>
     */
    public static function usable(array $document): array
    {
        $keys = [];
        foreach (is_array($document['keys'] ?? null) ? $document['keys'] : [] as $key) {
            if (!is_array($key) || ($key['kty'] ?? null) !== 'RSA') {
                continue;
            }
            $kid = $key['kid'] ?? null;
            $n = $key['n'] ?? null;
            $e = $key['e'] ?? null;
            if (!is_string($kid) || $kid === '' || !is_string($n) || $n === '' || !is_string($e) || $e === '') {
                continue;
            }
            if ((isset($key['use']) && $key['use'] !== 'sig') || (isset($key['alg']) && $key['alg'] !== 'RS256')) {
                continue;
            }
            $keys[$kid] = ['kty' => 'RSA', 'kid' => $kid, 'n' => $n, 'e' => $e];
        }

        return $keys;
    }

    /**
     * A JWK's public key as PEM, for openssl - or null when it is not one.
     *
     * A JWK gives an RSA key as its modulus and exponent; openssl wants a PEM,
     * which is base64 around a DER SubjectPublicKeyInfo, so the two numbers are
     * wrapped back up in the structure they came out of.
     *
     * @param array<string, mixed> $jwk
     */
    public static function pem(array $jwk): ?string
    {
        $modulus = Base64Url::decode(is_string($jwk['n'] ?? null) ? $jwk['n'] : '');
        $exponent = Base64Url::decode(is_string($jwk['e'] ?? null) ? $jwk['e'] : '');
        if ($modulus === '' || $exponent === '') {
            return null;
        }

        // rsaEncryption (1.2.840.113549.1.1.1) with its NULL parameters.
        $algorithm = self::sequence("\x06\x09\x2a\x86\x48\x86\xf7\x0d\x01\x01\x01\x05\x00");
        $publicKey = self::sequence(self::integer($modulus) . self::integer($exponent));
        $bitString = "\x03" . self::length(strlen($publicKey) + 1) . "\x00" . $publicKey;

        return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode(self::sequence($algorithm . $bitString)), 64, "\n") . "-----END PUBLIC KEY-----\n";
    }

    /**
     * The copy kept, its stamps read as times that have been: one later than now is none (0), as the answer
     * about signing in reads its own (SignInRule::fromCache()) - a clock set back, or a copy from another
     * machine, would otherwise keep the set "fresh", and an unknown kid unfetched, until the clock caught up.
     *
     * @return ?array{fetched_at: int, failed_at: int, keys: array<string, array{kty: string, kid: string, n: string, e: string}>}
     */
    private function read(?string $file, string $url, int $now): ?array
    {
        if ($file === null) {
            $record = self::$memory[$url] ?? null;
        } else {
            $record = is_file($file) ? json_decode((string) @file_get_contents($file), true) : null;
        }
        if (!is_array($record)) {
            return null;
        }
        $stamp = static fn(mixed $at): int => is_int($at) && $at <= $now ? $at : 0;

        return [
            'fetched_at' => $stamp($record['fetched_at'] ?? null),
            'failed_at' => $stamp($record['failed_at'] ?? null),
            // What was kept is checked again on the way out: a copy is only as good as what it holds.
            'keys' => self::usable(['keys' => is_array($record['keys'] ?? null) ? array_values($record['keys']) : []]),
        ];
    }

    /** @param array{fetched_at: int, failed_at: int, keys: array<string, array<string, string>>} $record */
    private function write(?string $file, string $url, array $record): void
    {
        if ($file === null) {
            self::$memory[$url] = $record;

            return;
        }
        // Whole or not at all, so a request reading the copy while another writes it never sees half a file. A copy
        // that cannot be kept is no error: the key set is fetched again when it is needed.
        AtomicFile::write($file, (string) json_encode($record, JSON_UNESCAPED_SLASHES));
    }

    private static function length(int $length): string
    {
        if ($length < 128) {
            return chr($length);
        }
        $bytes = ltrim(pack('N', $length), "\x00");

        return chr(0x80 | strlen($bytes)) . $bytes;
    }

    /** A DER INTEGER; a leading zero keeps an unsigned value clear of the sign bit. */
    private static function integer(string $bytes): string
    {
        $bytes = ltrim($bytes, "\x00");
        if ($bytes === '') {
            $bytes = "\x00";
        }
        if (ord($bytes[0]) > 0x7f) {
            $bytes = "\x00" . $bytes;
        }

        return "\x02" . self::length(strlen($bytes)) . $bytes;
    }

    private static function sequence(string $contents): string
    {
        return "\x30" . self::length(strlen($contents)) . $contents;
    }
}
