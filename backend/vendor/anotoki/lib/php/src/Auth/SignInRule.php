<?php

declare(strict_types=1);

namespace Anotoki\Lib\Auth;

use Anotoki\Lib\Support\AtomicFile;
use Anotoki\Lib\Support\HttpGet;
use Anotoki\Lib\Support\Log;
use Closure;

/**
 * Whether this site requires signing in - the IAM's "Sign-in required" switch
 * for the application, read from GET {issuer}/api/public/applications/{client_id}
 * - and whether the IAM knows the site at all. The IAM's PHP SDK's
 * anotoki_iam_login_required(), as a class, with the same table and the same
 * kept file, so a site that moves from the SDK keeps its answer.
 *
 * It fails closed: signing in is required unless the IAM said otherwise.
 *
 *   the IAM's answer                                  required          kept
 *   200 naming this client_id, login_required a bool  that value        yes
 *   404 (unknown or switched-off application)         true              yes, as true
 *   no answer, a 5xx, a redirect, anything else       the last kept,    the failure's time
 *                                                     else true
 *
 * - The answer is kept in the IAM client's cache folder for application_ttl
 *   (60 s); after a failure the IAM is not asked again for as long.
 * - One request asks at a time: the one that finds the kept answer old claims
 *   the refresh (a lock nobody waits for, and `tried_at` written into the kept
 *   answer before it asks); the others go on with the last value.
 * - It waits at most application_timeout (2 s).
 * - The answer holds for the rest of the request (a memo): Identify resets it
 *   at the start of every request, and so does reset().
 * - reason() says where it came from, for a log line: fresh, read,
 *   unknown_application, stale, never_read, backing_off, refreshing,
 *   not_configured - or site, when the site's own requiredWhen said so.
 *
 * known() is whether the IAM knows this site: false only once it answered 404
 * (the site unknown to it, or switched off) - then no visitor is sent to it
 * by the sign-in hint, whose hop would end on the IAM's error page. An IAM
 * never reached is not known to refuse the site: the browser's own look at
 * the IAM (the hint's discovery probe) decides then.
 *
 * The kept answer is what opens a site to visitors, so it belongs in a folder
 * only the site can write (IamClient's cache_dir); without one, the IAM is
 * asked on every request.
 */
final class SignInRule
{
    /** @var ?array{value: bool, reason: string, known: bool} */
    private ?array $memo = null;

    /**
     * @param ?Closure(): bool $requiredWhen  the site's own reason to require signing in whatever the IAM says
     *        (Japanese Academy: while its visitors' migration waits)
     * @param ?Closure(string $url, float $timeout): array{status: int, body: ?string} $fetch  how the IAM is
     *        asked (HttpGet::fetch() by default)
     */
    public function __construct(
        private readonly IamClient $iam,
        private readonly ?Closure $requiredWhen = null,
        private readonly ?Closure $fetch = null,
    ) {
    }

    /** Whether this site requires signing in: true unless the IAM said otherwise. */
    public function required(): bool
    {
        return $this->outcome()['value'];
    }

    /** Whether the IAM knows this site: false only after it answered 404. */
    public function known(): bool
    {
        return $this->outcome()['known'];
    }

    /** Where the answer came from (see the class comment). */
    public function reason(): string
    {
        return $this->outcome()['reason'];
    }

    /** Forgets the answer remembered for this request. */
    public function reset(): void
    {
        $this->memo = null;
    }

    /**
     * What the kept record says, before asking: [fetch, value, reason]. A pure
     * function of the record (record()'s shape, or null) and the time.
     *
     *   fresh        read less than $ttl ago: its value
     *   backing_off  the last ask failed less than $ttl ago: the last value, or true
     *   refreshing   another request claimed the ask and has not finished: the same
     *   otherwise    fetch
     *
     * @param ?array<string, mixed> $kept
     * @return array{fetch: bool, value: bool, reason: string}
     */
    public static function fromCache(?array $kept, int $now, int $ttl, int $timeout): array
    {
        $record = self::record($kept);
        $last = $record['login_required'];
        $value = $last ?? true;
        $recent = static fn (int $at, int $window): bool => $at > 0 && $at <= $now && $now - $at < $window;

        if ($last !== null && $recent($record['fetched_at'], $ttl)) {
            return ['fetch' => false, 'value' => $last, 'reason' => 'fresh'];
        }
        if ($recent($record['failed_at'], $ttl)) {
            return ['fetch' => false, 'value' => $value, 'reason' => 'backing_off'];
        }
        // A claim outlives its asker only when the asker died without a lock to release;
        // a few seconds past the timeout, somebody else may ask.
        $answeredSince = max($record['fetched_at'], $record['failed_at']);
        if ($record['tried_at'] > $answeredSince && $recent($record['tried_at'], $timeout + 3)) {
            return ['fetch' => false, 'value' => $value, 'reason' => 'refreshing'];
        }

        return ['fetch' => true, 'value' => $value, 'reason' => 'fetch'];
    }

    /**
     * What the IAM's answer means: [value, the record to keep, reason]. A pure
     * function of the HTTP status (0: no answer), the body, this client id, the
     * kept record (or null) and the time.
     *
     * @param ?array<string, mixed> $kept
     * @return array{value: bool, cache: array{login_required: ?bool, known: ?bool, fetched_at: int, failed_at: int, tried_at: int}, reason: string}
     */
    public static function fromAnswer(int $status, ?string $body, string $clientId, ?array $kept, int $now): array
    {
        $record = self::record($kept);

        if ($status === 200 && is_string($body)) {
            $answer = json_decode($body, true);
            // Only an answer about this application counts: a proxy's page, an answer for another
            // client or a login_required that is not a JSON boolean is no answer.
            if (is_array($answer) && ($answer['client_id'] ?? null) === $clientId
                && array_key_exists('login_required', $answer) && is_bool($answer['login_required'])) {
                $record['login_required'] = $answer['login_required'];
                $record['known'] = true;
                $record['fetched_at'] = $now;
                $record['failed_at'] = 0;

                return ['value' => $answer['login_required'], 'cache' => $record, 'reason' => 'read'];
            }
        } elseif ($status === 404) {
            $record['login_required'] = true;
            $record['known'] = false;
            $record['fetched_at'] = $now;
            $record['failed_at'] = 0;

            return ['value' => true, 'cache' => $record, 'reason' => 'unknown_application'];
        }

        $record['failed_at'] = $now;
        $last = $record['login_required'];

        return ['value' => $last ?? true, 'cache' => $record, 'reason' => $last === null ? 'never_read' : 'stale'];
    }

    /**
     * A kept record in its one shape: login_required (null: never read), known
     * (null: not said - a record the SDK kept, from before the library), and
     * three times. A record whose login_required is false came from a 200, so
     * the IAM knew the site then.
     *
     * @return array{login_required: ?bool, known: ?bool, fetched_at: int, failed_at: int, tried_at: int}
     */
    public static function record(mixed $record): array
    {
        $record = is_array($record) ? $record : [];
        $time = static fn (string $key): int => is_int($record[$key] ?? null) && $record[$key] > 0 ? $record[$key] : 0;
        $value = is_bool($record['login_required'] ?? null) ? $record['login_required'] : null;
        $known = is_bool($record['known'] ?? null) ? $record['known'] : ($value === false ? true : null);

        return [
            'login_required' => $value,
            'known' => $known,
            'fetched_at' => $time('fetched_at'),
            'failed_at' => $time('failed_at'),
            'tried_at' => $time('tried_at'),
        ];
    }

    /** The file the answer is kept in: the SDK's, so a site keeps its answer across the move. */
    public function cacheFile(): ?string
    {
        $directory = $this->iam->cacheDirectory();

        return $directory === null ? null : $directory . '/anotoki-iam-application-' . sha1($this->iam->issuer . ' ' . $this->iam->clientId) . '.json';
    }

    /** @return array{value: bool, reason: string, known: bool} */
    private function outcome(): array
    {
        if ($this->memo !== null) {
            return $this->memo;
        }

        $outcome = $this->fromIam();
        if ($this->requiredWhen !== null && ($this->requiredWhen)() === true) {
            $outcome['value'] = true;
            $outcome['reason'] = 'site';
        }
        if ($outcome['reason'] === 'stale' || $outcome['reason'] === 'never_read') {
            Log::say('[anotoki auth] the IAM did not say whether signing in is required here (' . $outcome['reason'] . '): '
                . ($outcome['value'] ? 'required' : 'not required, as it said last'));
        }

        return $this->memo = $outcome;
    }

    /** @return array{value: bool, reason: string, known: bool} */
    private function fromIam(): array
    {
        if (!$this->iam->configured()) {
            return ['value' => true, 'reason' => 'not_configured', 'known' => false];
        }

        // The tests' seam, like jwks_file: the file's content is the IAM's answer (a 200), a missing file
        // no answer at all. Read every time - nothing kept, nothing remembered - so a test can change it
        // between two requests.
        if ($this->iam->applicationFile !== null) {
            $body = is_file($this->iam->applicationFile) ? @file_get_contents($this->iam->applicationFile) : false;
            $answer = $body === false
                ? self::fromAnswer(0, null, $this->iam->clientId, null, time())
                : self::fromAnswer(200, $body, $this->iam->clientId, null, time());

            return self::summary($answer);
        }

        $file = $this->cacheFile();
        if ($file === null) {
            $answered = $this->ask();

            return self::summary(self::fromAnswer($answered['status'], $answered['body'], $this->iam->clientId, null, time()));
        }

        return self::summary($this->askOrKept($file));
    }

    /**
     * The kept answer, or a fresh one from the IAM, under the single-flight claim.
     *
     * @return array{value: bool, reason: string, cache?: array<string, mixed>}
     */
    private function askOrKept(string $file): array
    {
        $ttl = $this->iam->applicationTtl;
        $timeout = $this->iam->applicationTimeout;
        $kept = self::read($file);
        $step = self::fromCache($kept, time(), $ttl, $timeout);
        if (!$step['fetch']) {
            return $step + ['cache' => self::record($kept)];
        }

        $lock = self::lock($file . '.lock');
        if ($lock === false) {
            $kept = self::read($file);

            return ['value' => self::record($kept)['login_required'] ?? true, 'reason' => 'refreshing', 'cache' => self::record($kept)];
        }

        try {
            // Once more under the lock: another request may have finished asking between the first look and the lock.
            $kept = self::read($file);
            $step = self::fromCache($kept, time(), $ttl, $timeout);
            if (!$step['fetch']) {
                return $step + ['cache' => self::record($kept)];
            }

            $claim = self::record($kept);
            $claim['tried_at'] = time();
            $claimed = self::write($file, $claim);

            $answered = $this->ask();
            $outcome = self::fromAnswer($answered['status'], $answered['body'], $this->iam->clientId, $kept, time());
            $written = self::write($file, $outcome['cache']);

            if (!$claimed || !$written) {
                // Still answered - from what was read, failing closed - but nothing is kept, so every
                // request asks the IAM and waits for it.
                Log::say("[anotoki auth] cannot write $file; the IAM is asked on every request until the cache folder is writable");
            }

            return $outcome;
        } finally {
            self::unlock($lock);
        }
    }

    /** @return array{status: int, body: ?string} */
    private function ask(): array
    {
        $url = $this->iam->applicationUrl();
        $timeout = (float) $this->iam->applicationTimeout;

        return $this->fetch !== null ? ($this->fetch)($url, $timeout) : HttpGet::fetch($url, $timeout);
    }

    /**
     * @param array{value: bool, reason: string, cache?: array<string, mixed>} $outcome
     * @return array{value: bool, reason: string, known: bool}
     */
    private static function summary(array $outcome): array
    {
        $known = self::record($outcome['cache'] ?? null)['known'];

        return ['value' => $outcome['value'], 'reason' => $outcome['reason'], 'known' => $known !== false];
    }

    /** @return ?array<string, mixed> the kept record, or null when there is none to read */
    private static function read(string $file): ?array
    {
        if (!is_file($file)) {
            return null;
        }
        $record = json_decode((string) @file_get_contents($file), true);

        return is_array($record) ? self::record($record) : null;
    }

    /** Keeps the record, whole or not at all, so no reader sees half of it. */
    private static function write(string $file, array $record): bool
    {
        $directory = dirname($file);
        if (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) {
            return false;
        }

        return AtomicFile::write($file, (string) json_encode(self::record($record)));
    }

    /**
     * The refresh's lock, without waiting: a handle when it is ours, false when
     * another request holds it, null when there is no lock to be had (a folder
     * that cannot be written, a filesystem without locks) - then `tried_at`
     * alone keeps the others from asking at the same time.
     *
     * @return resource|false|null
     */
    private static function lock(string $file)
    {
        $directory = dirname($file);
        if (!is_dir($directory)) {
            @mkdir($directory, 0700, true);
        }
        $handle = @fopen($file, 'c');
        if ($handle === false) {
            return null;
        }
        if (flock($handle, LOCK_EX | LOCK_NB, $wouldBlock)) {
            return $handle;
        }
        fclose($handle);

        return $wouldBlock ? false : null;
    }

    /** @param resource|false|null $lock */
    private static function unlock($lock): void
    {
        if (is_resource($lock)) {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }
}
