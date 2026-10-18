<?php

declare(strict_types=1);

namespace Anotoki\Lib\People\Http;

use Anotoki\Lib\People\People;
use Anotoki\Lib\Support\AfterResponse;
use Anotoki\Lib\Support\Log;
use Closure;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * The scrub of the IAM's deleted accounts, carried by ordinary traffic - the
 * hosting has no cron: at most once an hour, after the answer has gone
 * (AfterResponse), while the site is ready. Which request carries it is
 * claimed with a stamp file, {the IAM client's cache folder}/anotoki-people.stamp,
 * touched before the work (as the IAM's own housekeeping does), and the run
 * itself takes an advisory lock. The sites asking is also part of what keeps
 * the IAM's deletions on time: its list's request runs its housekeeping.
 *
 * Inside the site's SiteGate (added before it), so a site that is not set up
 * never runs it; `ready` is asked only when the hour is up - the site's
 * SiteState saying `ready`, so no run meets a migration half applied.
 */
final class PeopleUpkeep implements MiddlewareInterface
{
    public const STAMP = 'anotoki-people.stamp';

    /** At most one run in this many seconds. */
    public const EVERY = 3600;

    /**
     * @param Closure(): bool $ready  the site is ready (SiteState::state() === SiteState::READY)
     * @param ?Closure(callable): void $defer  how the work is put off till after the answer: a site's own runner;
     *                                         default AfterResponse::shared()->defer()
     */
    public function __construct(private readonly People $people, private readonly Closure $ready, private readonly ?Closure $defer = null) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $response = $handler->handle($request);
        $this->soon();

        return $response;
    }

    /** Claims the hour and puts the run off till after the answer; whether it did. */
    public function soon(): bool
    {
        $directory = $this->people->iam->cacheDirectory();
        if ($directory === null) {
            return false;
        }
        $stamp = $directory . '/' . self::STAMP;
        clearstatcache(true, $stamp);
        $last = @filemtime($stamp);
        // A stamp later than now is none: a clock set back would otherwise hold the scrub off until it caught up.
        if ($last !== false && $last <= time() && $last > time() - self::EVERY) {
            return false;
        }
        if (!($this->ready)()) {
            return false;
        }
        // Claimed before the work: the requests arriving meanwhile see the hour taken.
        if (!@touch($stamp)) {
            Log::say("[anotoki people] cannot write $stamp: the scrub of the IAM's deleted accounts does not run");

            return false;
        }

        // The scrub says in the log why it stopped, and keeps it for the People page.
        $work = function (): void {
            $this->people->scrubber()->run();
        };
        $this->defer !== null ? ($this->defer)($work) : AfterResponse::shared()->defer($work);

        return true;
    }
}
