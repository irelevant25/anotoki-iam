<?php

declare(strict_types=1);

namespace Anotoki\Lib\People;

use Anotoki\Lib\Auth\IamClient;
use Anotoki\Lib\Auth\Person;
use Anotoki\Lib\Support\HttpGet;
use Closure;

/**
 * The IAM's list of the accounts it deleted, a page at a time:
 * GET {issuer}/api/public/anonymized-accounts?since=&after= (the IAM's
 * docs/api.md). Each answer is checked whole before anything is taken from it:
 * an id that is no IAM id, a time that is not whole seconds in UTC, a `next`
 * that does not move on - and the answer is no answer.
 *
 * The tests' seam, IamClient's anonymized_file, is the whole list
 * ({"accounts": [{sub, anonymized_at}]}), paged here as the IAM pages it.
 */
final class AnonymizedAccounts
{
    /** The most accounts the IAM gives in one answer - and the seam's page. */
    public const PAGE = 500;

    private const TIME = '/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ$/D';

    /**
     * @param ?Closure(string $url, float $timeout): array{status: int, body: ?string} $fetch  how the IAM is
     *        asked (HttpGet::fetch() by default)
     */
    public function __construct(private readonly IamClient $iam, private readonly ?Closure $fetch = null)
    {
    }

    /**
     * One page: the accounts anonymized at or after $since (all of them without
     * it), or at $since with an id above $after.
     *
     * @return array{accounts: list<array{sub: int, anonymized_at: string}>, next: ?array{since: string, after: string}, error: ?string}
     */
    public function page(?string $since, ?string $after = null): array
    {
        if (!$this->iam->configured()) {
            return self::failure('The site names no IAM (issuer and client_id).');
        }

        if ($this->iam->anonymizedFile !== null) {
            $body = is_file($this->iam->anonymizedFile) ? @file_get_contents($this->iam->anonymizedFile) : false;
            $answer = $body === false ? ['status' => 0, 'body' => null] : ['status' => 200, 'body' => self::pageOfFile($body, $since, $after)];
        } else {
            $url = $this->iam->anonymizedUrl($since, $after);
            $timeout = (float) $this->iam->anonymizedTimeout;
            $answer = $this->fetch !== null ? ($this->fetch)($url, $timeout) : HttpGet::fetch($url, $timeout);
        }

        if ($answer['status'] === 0) {
            return self::failure('The IAM could not be reached.');
        }
        if ($answer['status'] !== 200 || !is_string($answer['body'])) {
            return self::failure('The IAM answered ' . $answer['status'] . '.');
        }

        return self::read($answer['body'], $since, $after) ?? self::failure('The IAM\'s answer could not be read.');
    }

    /**
     * An answer's accounts and next, or null when it is not the IAM's answer.
     *
     * @return ?array{accounts: list<array{sub: int, anonymized_at: string}>, next: ?array{since: string, after: string}, error: null}
     */
    public static function read(string $body, ?string $since, ?string $after): ?array
    {
        $answer = json_decode($body, true);
        if (!is_array($answer) || !array_key_exists('accounts', $answer) || !is_array($answer['accounts']) || !array_is_list($answer['accounts'])) {
            return null;
        }

        $accounts = [];
        $previous = null;
        foreach ($answer['accounts'] as $account) {
            $sub = is_array($account) ? Person::userId($account['sub'] ?? null) : null;
            $at = is_array($account) ? ($account['anonymized_at'] ?? null) : null;
            if ($sub === null || !is_string($at) || preg_match(self::TIME, $at) !== 1 || strtotime($at) === false) {
                return null;
            }
            // In the order they went: by time, then by id - what `since` and `next` rely on.
            if ($previous !== null && [strtotime($at), $sub] <= $previous) {
                return null;
            }
            $previous = [strtotime($at), $sub];
            $accounts[] = ['sub' => $sub, 'anonymized_at' => $at];
        }

        $next = $answer['next'] ?? null;
        if ($next !== null) {
            $nextSince = is_array($next) ? ($next['since'] ?? null) : null;
            $nextAfter = is_array($next) ? ($next['after'] ?? null) : null;
            if (!is_string($nextSince) || preg_match(self::TIME, $nextSince) !== 1 || Person::userId($nextAfter) === null || $accounts === []) {
                return null;
            }
            // A next that does not move past what was asked would be asked for ever.
            $from = [$since === null ? PHP_INT_MIN : (int) strtotime($since), $after === null ? 0 : (int) $after];
            if ([(int) strtotime($nextSince), (int) $nextAfter] <= $from) {
                return null;
            }
            $next = ['since' => $nextSince, 'after' => (string) $nextAfter];
        }

        return ['accounts' => $accounts, 'next' => $next, 'error' => null];
    }

    /** The seam's file, answered as the IAM answers: sorted, from $since (and after $after), a page at a time. */
    private static function pageOfFile(string $body, ?string $since, ?string $after): string
    {
        $all = json_decode($body, true);
        $accounts = is_array($all) && is_array($all['accounts'] ?? null) ? array_values(array_filter($all['accounts'], 'is_array')) : null;
        if ($accounts === null) {
            return $body;
        }
        usort($accounts, static fn (array $a, array $b): int => [strtotime((string) ($a['anonymized_at'] ?? '')), (int) ($a['sub'] ?? 0)] <=> [strtotime((string) ($b['anonymized_at'] ?? '')), (int) ($b['sub'] ?? 0)]);
        $from = $since === null ? null : (int) strtotime($since);
        $accounts = array_values(array_filter($accounts, static function (array $account) use ($from, $after): bool {
            $at = (int) strtotime((string) ($account['anonymized_at'] ?? ''));

            return $from === null || ($after === null ? $at >= $from : ($at > $from || ($at === $from && (int) ($account['sub'] ?? 0) > (int) $after)));
        }));
        $page = array_slice($accounts, 0, self::PAGE);
        $last = $page === [] ? null : $page[count($page) - 1];

        return (string) json_encode([
            'accounts' => $page,
            'next' => count($accounts) > self::PAGE && $last !== null ? ['since' => $last['anonymized_at'], 'after' => (string) $last['sub']] : null,
        ], JSON_UNESCAPED_SLASHES);
    }

    /** @return array{accounts: list<never>, next: null, error: string} */
    private static function failure(string $error): array
    {
        return ['accounts' => [], 'next' => null, 'error' => $error];
    }
}
