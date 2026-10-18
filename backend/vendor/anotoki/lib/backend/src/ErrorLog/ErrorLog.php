<?php

declare(strict_types=1);

namespace Anotoki\Lib\ErrorLog;

use Anotoki\Lib\Admin\AdminContext;
use Anotoki\Lib\Auth\Http\Identify;
use Anotoki\Lib\Support\ClientAddress;
use Anotoki\Lib\Support\GuardedDirectory;
use Anotoki\Lib\Support\Keys;
use Anotoki\Lib\Support\Redactor;
use Anotoki\Lib\Support\Refusal;
use Anotoki\Lib\Support\Utf8;
use Closure;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use PDOException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\App;
use Slim\Exception\HttpException;
use Slim\Routing\RouteContext;
use Slim\Routing\RoutingResults;
use Throwable;

/**
 * What went wrong on a site, written down where its administrators can read it
 * - in files, on purpose, as genshin decided: the errors most worth seeing are
 * the ones where the database cannot be reached or an update waits, and a log
 * that needs the database loses exactly those.
 *
 * One JSON line per event in {directory}/errors-YYYY-MM-DD.jsonl (the UTC
 * date), a new part (errors-…-DD.2.jsonl, .3, ...) once a file passes 4 MB,
 * days older than 30 deleted when a day's first line is written, and no more
 * than 64 MB in all: past it the oldest days go first - today is never kept
 * out by the days before it - and only what today alone does not fit is
 * dropped and counted (errors-dropped.json). So is a line of a kind that has
 * 100 lines today already (`perFingerprintDay`): a flood of one failure - a
 * scanner's 404s - writes a hundred lines a day, not the whole budget. The
 * page says how many were dropped.
 *
 * A line (v 1): {v, at (ISO 8601 UTC, milliseconds), source (server, php or
 * client), level (error, warning or notice), status, type (the exception's
 * short class, or Response), code (the answer's code), message (redacted, at
 * most 500 characters), file (relative to the site's root), line, method,
 * route (the route's pattern: /api/admin/users/{id}), path (redacted, at most
 * 200), user_id, client (12 hex: an HMAC of the address bucket - never the
 * address), trace (at most 12 lines: frames, "file:line Class->function()" -
 * never a call's arguments - and for an exception thrown for another, each
 * cause's "Caused by Type (file:line): message", redacted, with its own
 * frames), fingerprint}. Never written: a query string, a header, a
 * cookie, a request's or an answer's body (an answer gives only its code and
 * its sentence, redacted), a token, the session.
 *
 * Every event of one kind - its type, where it was thrown, its status, and its
 * message with the changing parts taken out - shares a fingerprint, and one
 * request writes one line per fingerprint. The page shows five hundred of one
 * bug as one row saying five hundred.
 *
 * What is written: an exception the site answered (wrapHandler() around its
 * Slim error handler, or ErrorLogMiddleware::inner()); an answer with a status
 * worth seeing (ErrorLogMiddleware::outer(); by default every 5xx and 404,
 * 405, 413, 415, 429); PHP's warnings, notices and fatal errors
 * (installPhpHandlers()); the pages' own errors (recordClient(), the client
 * route). Never the log's own routes. Every recording method never throws and
 * never warns: a logger that can break a request is worse than one that loses
 * a line.
 */
final class ErrorLog
{
    public const VERSION = 1;

    /** The request attribute the outer middleware puts its RequestRecord under. */
    public const ATTRIBUTE = 'anotoki.errorLog';

    /** The answered statuses written by default, beside every 5xx. */
    public const DEFAULT_STATUSES = [404, 405, 413, 415, 429];

    /** The most lines a line's trace holds: an exception's frames - and, with a chain, its causes' lines and frames. */
    private const TRACE_LINES = 12;

    private const FATAL = E_ERROR | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR | E_USER_ERROR | E_RECOVERABLE_ERROR;
    private const WARNING = E_WARNING | E_USER_WARNING | E_CORE_WARNING | E_COMPILE_WARNING;

    /**
     * The answers that say a state the site is in, not a failure - an update waiting (the gate's update_pending, a
     * module's feature_unavailable), a site not set up yet: never written by default, however often they come.
     */
    public const EXPECTED_CODES = ['update_pending', 'feature_unavailable', 'not_set_up'];

    private const OPTIONS = [
        'root',
        'context',
        'clientKey',
        'actorId',
        'days',
        'responses',
        'key',
        'storage',
        'maxBytes',
        'totalBytes',
        'ignore',
        'ignoreExceptions',
        'app',
        'clientLimits',
        'perFingerprintDay',
        'expected',
        'trustedProxies',
        'clientHeader',
    ];

    private readonly string $directory;
    private readonly ?string $root;
    private readonly ?Closure $clientKey;
    private readonly ?Closure $actorId;
    private readonly int $days;
    private readonly Closure $responses;
    private ?string $key;
    private readonly string $storage;
    private readonly int $maxBytes;
    private readonly int $totalBytes;
    private readonly int $perFingerprintDay;

    /** @var list<string> the answers' codes that say a state, not a failure: never written */
    private readonly array $expected;

    /** @var list<string> */
    private readonly array $trustedProxies;
    private readonly string $clientHeader;

    /** @var list<string> paths never written down (the log's own routes): exact, or everything under them */
    private array $ignored;

    /** @var list<class-string> exceptions that are answers, not failures: left to the answer's line */
    private readonly array $ignoreExceptions;

    private readonly ?App $app;

    /** @var array{perHour: int, perDay: int} */
    private readonly array $clientLimits;

    /** The request being answered, set by the outer middleware: for a PHP warning, which comes without one. */
    private ?RequestRecord $current = null;

    /** @var array<string, true> fingerprints written outside any request (a command line) */
    private array $seen = [];

    private bool $writing = false;
    private bool $guarded = false;
    private bool $handlersInstalled = false;
    private ?string $reserve = null;

    /**
     * @param string $directory  the folder the log is kept in: the site's storage/logs
     * @param array{
     *   root?: string,
     *   context?: AdminContext,
     *   clientKey?: Closure(ServerRequestInterface): string,
     *   actorId?: Closure(ServerRequestInterface): (int|string|null),
     *   days?: int,
     *   responses?: list<int>|Closure(int): bool,
     *   key?: string,
     *   storage?: string,
     *   maxBytes?: int,
     *   totalBytes?: int,
     *   ignore?: list<string>,
     *   ignoreExceptions?: list<class-string>,
     *   app?: App,
     *   clientLimits?: array{perHour?: int, perDay?: int},
     *   perFingerprintDay?: int,
     *   expected?: list<string>,
     *   trustedProxies?: list<string>,
     *   clientHeader?: string,
     * } $options
     *   - root: the site's folder, cut from every file's path (backend/)
     *   - context / clientKey / actorId: who asked (an id) and from where (a keyed hash of the address bucket);
     *     without them, the person the auth module's Identify set (when a route of the library's admin tools
     *     ran), and the address by ClientAddress's rule over `trustedProxies` and `clientHeader` (or the context's)
     *   - expected: the answers' codes never written - states, not failures (EXPECTED_CODES by default)
     *   - days: kept, default 30
     *   - responses: the answered statuses written - a list (beside every 5xx), or a closure; default 404, 405,
     *     413, 415, 429 and every 5xx (genshin keeps every 4xx: fn (int $s) => $s >= 400)
     *   - key: the HMAC key of `client` (32 bytes or more; a site's secret through Keys::derive()); default the
     *     installation's key (Keys::installation($storage))
     *   - storage: the storage folder of the installation key; default the folder above $directory
     *   - maxBytes: a part's size, 4 MB; totalBytes: the whole log's, 64 MB
     *   - ignore: paths never written down - the log's routes add theirs (ErrorLogRoutes)
     *   - ignoreExceptions: classes that are a decided answer (genshin's ApiRefusal): their answer's line is written
     *   - app: the Slim app, to name a request's route pattern
     *   - clientLimits: the pages' own errors, an hour from one sender - an account, or a visitor's address bucket -
     *     (perHour, 20), and a day from the visitors in all and from the signed-in people in all (perDay, 500 each)
     *   - perFingerprintDay: the most lines of one kind (one fingerprint) a day, 100; past it they are counted
     */
    public function __construct(string $directory, array $options = [])
    {
        $unknown = array_diff(array_map('strval', array_keys($options)), self::OPTIONS);
        if ($unknown !== []) {
            throw new InvalidArgumentException('Unknown ErrorLog option: ' . implode(', ', $unknown) . '.');
        }

        $this->directory = rtrim($directory, '/\\');
        $root = $options['root'] ?? null;
        $this->root = is_string($root) && $root !== '' ? str_replace('\\', '/', rtrim((string) (realpath($root) ?: $root), '/\\')) . '/' : null;

        $context = $options['context'] ?? null;
        if ($context !== null && !($context instanceof AdminContext)) {
            throw new InvalidArgumentException('The option context must be an AdminContext.');
        }
        $this->clientKey = $options['clientKey'] ?? ($context !== null ? $context->clientKey(...) : null);
        $this->actorId = $options['actorId'] ?? ($context !== null ? $context->actorId(...) : null);
        $this->trustedProxies = array_values(array_filter($options['trustedProxies'] ?? ($context?->trustedProxies() ?? []), 'is_string'));
        $this->clientHeader = (string) ($options['clientHeader'] ?? ($context?->clientHeader() ?? ClientAddress::CLOUDFLARE_HEADER));
        $this->expected = array_values(array_filter($options['expected'] ?? self::EXPECTED_CODES, 'is_string'));

        $this->days = max(1, (int) ($options['days'] ?? 30));
        $responses = $options['responses'] ?? self::DEFAULT_STATUSES;
        if ($responses instanceof Closure) {
            $this->responses = $responses;
        } elseif (is_array($responses)) {
            $list = array_map('intval', $responses);
            $this->responses = static fn(int $status): bool => $status >= 500 || in_array($status, $list, true);
        } else {
            throw new InvalidArgumentException('The option responses is a list of statuses or a closure.');
        }

        $key = $options['key'] ?? null;
        if ($key !== null && (!is_string($key) || strlen($key) < 16)) {
            throw new InvalidArgumentException('The option key must be a string of at least 16 bytes.');
        }
        $this->key = $key;
        $this->storage = rtrim((string) ($options['storage'] ?? dirname($this->directory)), '/\\');
        $this->maxBytes = max(1024, (int) ($options['maxBytes'] ?? 4 * 1024 * 1024));
        $this->totalBytes = max($this->maxBytes, (int) ($options['totalBytes'] ?? 64 * 1024 * 1024));
        $this->perFingerprintDay = max(1, (int) ($options['perFingerprintDay'] ?? 100));
        $this->ignored = array_values(array_map('strval', $options['ignore'] ?? []));
        $this->ignoreExceptions = array_values($options['ignoreExceptions'] ?? []);
        $app = $options['app'] ?? null;
        if ($app !== null && !($app instanceof App)) {
            throw new InvalidArgumentException('The option app must be the Slim App.');
        }
        $this->app = $app;
        $limits = $options['clientLimits'] ?? [];
        $this->clientLimits = [
            'perHour' => max(1, (int) ($limits['perHour'] ?? 20)),
            'perDay' => max(1, (int) ($limits['perDay'] ?? 500)),
        ];
    }

    public function directory(): string
    {
        return $this->directory;
    }

    public function days(): int
    {
        return $this->days;
    }

    /** The reading half, for the admin page. */
    public function reader(): ErrorLogReader
    {
        return new ErrorLogReader($this->directory, $this->days);
    }

    /** Never writes down a request to $path, or under it (the log's own routes): the path as requested, base path and all. */
    public function ignorePath(string $path): void
    {
        $this->ignored[] = rtrim($path, '/');
    }

    /**
     * @internal ErrorLogMiddleware::own(), on the log's own routes: nothing of this request is written,
     * wherever the routes are mounted. Answers the request to hand on (with a record of its own where the
     * outer middleware gave it none).
     */
    public function markOwn(ServerRequestInterface $request): ServerRequestInterface
    {
        $record = $this->recordOf($request);
        if ($record === null) {
            $record = new RequestRecord($request);
            $request = $request->withAttribute(self::ATTRIBUTE, $record);
            $record->request = $request;
        }
        $record->own = true;

        return $request;
    }

    /** Whether an answer with this status is written down. */
    public function logsStatus(int $status): bool
    {
        return ($this->responses)($status);
    }

    // ─── Writing ─────────────────────────────────────────────────────────────

    /**
     * An exception, answered with $status (the site's answer's code with it, where
     * it is known). Not written when the status is not one the log keeps, the
     * request is to the log's own routes, or the exception is one the site calls
     * an answer (ignoreExceptions). An exception thrown for another keeps the
     * chain in its trace (traceWithCauses()); its fingerprint is its own alone.
     */
    public function record(Throwable $exception, ?ServerRequestInterface $request = null, int $status = 500, ?string $code = null): void
    {
        $this->guard(function () use ($exception, $request, $status, $code): void {
            if ($this->ignoresException($exception) || !$this->logsStatus($status) || ($request !== null && $this->ignoresPath($request))) {
                return;
            }
            if ($code === null && $exception instanceof PDOException) {
                $state = $exception->errorInfo[0] ?? $exception->getCode();
                $code = is_string($state) && preg_match('/^[0-9A-Z]{5}$/D', $state) === 1 ? $state : null;
            }

            $this->write(
                [
                    'source' => 'server',
                    'level' => $status >= 500 ? 'error' : 'warning',
                    'status' => $status,
                    'type' => $this->className($exception::class),
                    'code' => $code,
                    'message' => Redactor::text($exception->getMessage()),
                    'file' => $this->relative($exception->getFile()),
                    'line' => $exception->getLine(),
                    'trace' => $this->traceWithCauses($exception),
                ],
                $request,
            );
            $this->markHandled($request);
        });
    }

    /**
     * An answer worth seeing (its status is one the log keeps) that no exception
     * wrote down already - a route that answered 404 or 503 itself. Of its body,
     * only its code and its sentence (code, error or message of a JSON body).
     */
    public function recordResponse(ServerRequestInterface $request, ResponseInterface $response): void
    {
        $this->guard(function () use ($request, $response): void {
            $status = $response->getStatusCode();
            if (!$this->logsStatus($status) || $this->ignoresPath($request) || $this->recordOf($request)?->handled === true) {
                return;
            }
            [$code, $sentence] = self::answerWords($response);
            // A state, not a failure: an update waiting answers so on every visit until it is applied.
            if ($code !== null && in_array($code, $this->expected, true)) {
                return;
            }

            $this->write(
                [
                    'source' => 'server',
                    'level' => $status >= 500 ? 'error' : 'warning',
                    'status' => $status,
                    'type' => 'Response',
                    'code' => $code,
                    'message' => $sentence === null ? '' : Redactor::text($sentence),
                ],
                $request,
            );
        });
    }

    /**
     * A PHP error - a warning or a notice from the error handler, a fatal error
     * from the shutdown function - with the request being answered, if any.
     *
     * @param array{type: int, message: string, file?: string, line?: int} $error
     */
    public function recordPhp(array $error): void
    {
        $this->guard(function () use ($error): void {
            $type = (int) $error['type'];
            $request = $this->current?->request;
            if ($request !== null && $this->ignoresPath($request)) {
                return;
            }
            $fatal = ($type & self::FATAL) !== 0;
            $message = (string) $error['message'];
            // An exception nobody answered ends the script as "Uncaught ...": its own line, with its trace, says it.
            if ($fatal && str_starts_with($message, 'Uncaught ') && $this->current?->handled === true) {
                return;
            }
            // A fatal error's message may carry its own trace ("Stack trace:\n#0 ..."), arguments and all.
            $message = preg_split('/\R(?:Stack trace:|#0 )/', $message, 2)[0] ?? $message;

            $this->write(
                [
                    'source' => 'php',
                    'level' => $fatal ? 'error' : (($type & self::WARNING) !== 0 ? 'warning' : 'notice'),
                    'status' => $fatal ? 500 : 0,
                    'type' => self::phpErrorName($type),
                    'message' => Redactor::text($message),
                    'file' => $this->relative((string) ($error['file'] ?? '')),
                    'line' => (int) ($error['line'] ?? 0),
                ],
                $request,
            );
            if ($fatal) {
                $this->markHandled($request);
            }
        });
    }

    /**
     * A page's own error (the client route has checked the request): its type,
     * message, the page's path and up to 12 frames of its stack - each redacted.
     *
     * @param array{type?: mixed, message?: mixed, page?: mixed, stack?: mixed, status?: mixed} $report
     */
    public function recordClient(ServerRequestInterface $request, array $report): void
    {
        $this->guard(function () use ($request, $report): void {
            $type = is_string($report['type'] ?? null) && preg_match('/^[A-Za-z_$][A-Za-z0-9_$.]{0,63}$/D', $report['type']) === 1 ? $report['type'] : 'Error';
            $message = is_string($report['message'] ?? null) ? $report['message'] : '';
            $page = is_string($report['page'] ?? null) ? $report['page'] : '';
            $status = is_int($report['status'] ?? null) && $report['status'] >= 0 && $report['status'] < 1000 ? $report['status'] : 0;

            $frames = [];
            $stack = $report['stack'] ?? null;
            $lines = is_string($stack) ? preg_split('/\R/', $stack) : (is_array($stack) ? $stack : []);
            foreach ($lines ?: [] as $frame) {
                // The first line of a stack is the error itself ("TypeError: ..."), already said.
                if (!is_string($frame) || trim($frame) === '' || trim($frame) === trim($message) || str_starts_with(trim($frame), $type . ':')) {
                    continue;
                }
                $frames[] = Utf8::cut(Redactor::text(trim($frame)), 200);
                if (count($frames) === 12) {
                    break;
                }
            }

            $this->write(
                [
                    'source' => 'client',
                    'level' => 'error',
                    'status' => $status,
                    'type' => $type,
                    'message' => Redactor::text($message),
                    'trace' => $frames === [] ? null : $frames,
                ],
                $request,
                Redactor::path(str_starts_with($page, '/') ? $page : '/'),
            );
        });
    }

    /**
     * Whether the pages may write another error now: at most clientLimits.perHour
     * an hour from one sender - a signed-in person by their account, a visitor by
     * their address bucket - and perDay a day from the visitors in all, and as
     * many from the signed-in people in all: each its own budget, so visitors from
     * a few IPv6 /64s cannot use up the reports of the people signed in. Counted
     * in a small file beside the log (keyed hashes, never an address or an id);
     * null when it may, else 429 too_many.
     */
    public function clientAllowance(ServerRequestInterface $request): ?Refusal
    {
        try {
            if (!$this->ensureDirectory()) {
                return new Refusal(503, 'unavailable', 'The error log cannot be written right now.');
            }
            $handle = @fopen($this->directory . '/client-errors.json', 'c+');
            if ($handle === false) {
                return new Refusal(503, 'unavailable', 'The error log cannot be written right now.');
            }
            try {
                flock($handle, LOCK_EX);
                $state = json_decode((string) stream_get_contents($handle), true);
                $hour = gmdate('Y-m-d\TH');
                $day = gmdate('Y-m-d');
                if (!is_array($state) || ($state['day'] ?? null) !== $day) {
                    $state = ['day' => $day, 'total' => 0, 'members' => 0, 'hour' => $hour, 'clients' => []];
                }
                if (($state['hour'] ?? null) !== $hour) {
                    $state['hour'] = $hour;
                    $state['clients'] = [];
                }
                $actor = $this->actor($request);
                if ($actor !== null) {
                    $this->key ??= Keys::derive(Keys::installation($this->storage), 'error-log');
                    $client = 'a' . substr(hash_hmac('sha256', 'account:' . $actor, $this->key), 0, 12);
                    $budget = 'members';
                } else {
                    $client = $this->clientHash($request) ?? '?';
                    $budget = 'total';
                }
                $mine = (int) ($state['clients'][$client] ?? 0);
                $all = (int) ($state[$budget] ?? 0);
                if ($mine >= $this->clientLimits['perHour'] || $all >= $this->clientLimits['perDay']) {
                    $wait = $mine >= $this->clientLimits['perHour'] ? 3600 - (time() % 3600) : 86400 - (time() % 86400);

                    return new Refusal(429, 'too_many', 'That is a lot of errors. They are no longer written down for a while.', ['retry_after' => $wait]);
                }
                $state['clients'][$client] = $mine + 1;
                $state[$budget] = $all + 1;
                ftruncate($handle, 0);
                rewind($handle);
                fwrite($handle, (string) json_encode($state));
                fflush($handle);

                return null;
            } finally {
                flock($handle, LOCK_UN);
                fclose($handle);
            }
        } catch (Throwable) {
            return new Refusal(503, 'unavailable', 'The error log cannot be written right now.');
        }
    }

    // ─── Wiring ──────────────────────────────────────────────────────────────

    /**
     * The site's Slim error handler, with every exception it answers written
     * down - with the status and the code of the site's own answer. For
     * $errorMiddleware->setDefaultErrorHandler($log->wrapHandler($siteHandler)).
     */
    public function wrapHandler(callable $siteHandler): Closure
    {
        return function (
            ServerRequestInterface $request,
            Throwable $exception,
            bool $displayErrorDetails = false,
            bool $logErrors = true,
            bool $logErrorDetails = true,
        ) use ($siteHandler): ResponseInterface {
            try {
                $response = $siteHandler($request, $exception, $displayErrorDetails, $logErrors, $logErrorDetails);
            } catch (Throwable $failure) {
                $this->record($exception, $request, 500);
                throw $failure;
            }
            if ($response instanceof ResponseInterface) {
                [$code] = self::answerWords($response);
                $this->record($exception, $request, $response->getStatusCode(), $code);
            }

            return $response;
        };
    }

    /**
     * PHP's own errors, written down: a shutdown function for the fatal ones
     * (E_ERROR and its kin - a timeout, memory run out; 64 KB kept in reserve
     * and freed first, so the line can still be written), and - with $warnings
     * - an error handler for warnings and notices, chained to the one there was:
     * a site's handler that turns warnings into exceptions runs first (its
     * exception is written down where it is answered), and PHP's own handling
     * follows as before (the handler answers false). What @ silences is not
     * written. Once per log.
     */
    public function installPhpHandlers(bool $warnings = true): void
    {
        if ($this->handlersInstalled) {
            return;
        }
        $this->handlersInstalled = true;
        $this->reserve = str_repeat("\0", 65536);

        register_shutdown_function(function (): void {
            $this->reserve = null;
            $error = error_get_last();
            if (is_array($error) && ((int) $error['type'] & self::FATAL) !== 0) {
                $this->recordPhp($error);
            }
        });

        if (!$warnings) {
            return;
        }
        $previous = null;
        $previous = set_error_handler(function (int $type, string $message, string $file = '', int $line = 0) use (&$previous): bool {
            $handled = false;
            if ($previous !== null) {
                // A handler that throws makes this an exception, written down where it is answered.
                $handled = (bool) $previous($type, $message, $file, $line);
            }
            if ((error_reporting() & $type) !== 0) {
                $this->recordPhp(['type' => $type, 'message' => $message, 'file' => $file, 'line' => $line]);
            }

            return $handled;
        });
    }

    /** @internal the outer middleware: the request being answered from now on; answers the one before */
    public function enter(RequestRecord $record): ?RequestRecord
    {
        $before = $this->current;
        $this->current = $record;

        return $before;
    }

    /** @internal */
    public function leave(?RequestRecord $before): void
    {
        $this->current = $before;
    }

    /** Whether an exception is one the site answers as a decision (written as its answer, not as a failure). */
    public function ignoresException(Throwable $exception): bool
    {
        foreach ($this->ignoreExceptions as $class) {
            if ($exception instanceof $class) {
                return true;
            }
        }

        return false;
    }

    /** The status an exception stands for when nobody answered it: Slim's HTTP exceptions' own, else 500. */
    public static function statusOf(Throwable $exception): int
    {
        $code = $exception instanceof HttpException ? $exception->getCode() : 500;

        return $code >= 400 && $code < 600 ? $code : 500;
    }

    /** @internal marks the request's failure as written down */
    public function markHandled(?ServerRequestInterface $request): void
    {
        $record = $this->recordOf($request);
        if ($record !== null) {
            $record->handled = true;
        }
    }

    // ─── Inside ──────────────────────────────────────────────────────────────

    /** Runs $work, never letting anything out of it - an exception or a warning. */
    private function guard(Closure $work): void
    {
        if ($this->writing) {
            return;
        }
        $this->writing = true;
        set_error_handler(static fn(): bool => true);
        try {
            $work();
        } catch (Throwable) {
            // Deliberately silent: see the class.
        } finally {
            restore_error_handler();
            $this->writing = false;
        }
    }

    /** @param array<string, mixed> $fields */
    private function write(array $fields, ?ServerRequestInterface $request, ?string $path = null): void
    {
        $entry = [
            'v' => self::VERSION,
            'at' => self::now(),
            'source' => $fields['source'],
            'level' => $fields['level'],
            'status' => $fields['status'],
            'type' => $fields['type'],
            'code' => $fields['code'] ?? null,
            'message' => Utf8::cut((string) $fields['message'], 500),
            'file' => $fields['file'] ?? null,
            'line' => $fields['line'] ?? null,
            'method' => null,
            'route' => null,
            'path' => null,
            'user_id' => null,
            'client' => null,
            'trace' => $fields['trace'] ?? null,
        ];
        if ($request !== null) {
            $method = strtoupper($request->getMethod());
            $entry['method'] = preg_match('/^[A-Z]{1,12}$/D', $method) === 1 ? $method : '?';
            $entry['route'] = self::quietly(fn(): ?string => $this->routePattern($request));
            $entry['path'] = $path ?? Redactor::path($request->getUri()->getPath());
            $entry['user_id'] = self::quietly(fn(): int|string|null => $this->actor($request));
            $entry['client'] = self::quietly(fn(): ?string => $this->clientHash($request));
        }
        $entry['fingerprint'] = self::fingerprint((string) $entry['type'], (string) ($entry['file'] ?? ''), (int) ($entry['line'] ?? 0), (string) $entry['message'], (int) $entry['status']);

        $seen = &$this->seenFor($request);
        if (isset($seen[$entry['fingerprint']])) {
            return;
        }
        $seen[$entry['fingerprint']] = true;

        $line = json_encode($entry, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        if ($line === false) {
            return;
        }
        $file = $this->fileForToday();
        if ($file === null || !$this->takeTurn($entry['fingerprint'])) {
            $this->countDropped();

            return;
        }
        @file_put_contents($file, $line . "\n", FILE_APPEND | LOCK_EX);
    }

    /**
     * The file today's next line goes into, or null when today's own lines fill
     * the whole budget (the line is dropped and counted). A day's first file
     * prunes the days past keeping first; a new file over the budget makes room
     * by deleting the oldest days - never today's.
     */
    private function fileForToday(): ?string
    {
        if (!$this->ensureDirectory()) {
            return null;
        }
        $base = $this->directory . '/errors-' . gmdate('Y-m-d');
        for ($part = 1; $part <= 99; $part++) {
            $file = $base . ($part === 1 ? '' : '.' . $part) . '.jsonl';
            clearstatcache(true, $file);
            if (!is_file($file)) {
                if ($part === 1) {
                    $this->prune();
                }

                return $this->makeRoom() ? $file : null;
            }
            if ((int) @filesize($file) < $this->maxBytes) {
                return $file;
            }
        }

        return null;
    }

    /**
     * Under the total budget, the oldest days deleted to get there - a day's
     * files and its count, oldest first, never today's. Whether there is room.
     */
    private function makeRoom(): bool
    {
        $files = [];
        $total = 0;
        foreach (glob($this->directory . '/errors-*.jsonl') ?: [] as $file) {
            $size = (int) @filesize($file);
            $total += $size;
            if (preg_match('/^errors-(\d{4}-\d{2}-\d{2})[.]/', basename($file), $match) === 1) {
                $files[] = [$match[1], $file, $size];
            }
        }
        if ($total < $this->totalBytes) {
            return true;
        }
        sort($files);
        $today = gmdate('Y-m-d');
        foreach ($files as [$day, $file, $size]) {
            if ($total < $this->totalBytes || $day >= $today) {
                break;
            }
            if (@unlink($file)) {
                $total -= $size;
            }
        }

        return $total < $this->totalBytes;
    }

    /**
     * Whether one more line of this kind may be written today: at most
     * perFingerprintDay a day, counted in a small file of the day's. A count
     * that cannot be kept never keeps a line out.
     */
    private function takeTurn(string $fingerprint): bool
    {
        $handle = @fopen($this->directory . '/errors-' . gmdate('Y-m-d') . '.counts.json', 'c+');
        if ($handle === false) {
            return true;
        }
        try {
            flock($handle, LOCK_EX);
            $counts = json_decode((string) stream_get_contents($handle), true);
            $counts = is_array($counts) ? $counts : [];
            $written = (int) ($counts[$fingerprint] ?? 0);
            if ($written >= $this->perFingerprintDay) {
                return false;
            }
            $counts[$fingerprint] = $written + 1;
            ftruncate($handle, 0);
            rewind($handle);
            fwrite($handle, (string) json_encode($counts));
            fflush($handle);

            return true;
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /** Deletes the days past keeping, and the counts of every day before today. */
    private function prune(): void
    {
        $cutoff = gmdate('Y-m-d', time() - $this->days * 86400);
        foreach (glob($this->directory . '/errors-*.jsonl') ?: [] as $file) {
            if (preg_match('/^errors-(\d{4}-\d{2}-\d{2})[.]/', basename($file), $match) === 1 && $match[1] < $cutoff) {
                @unlink($file);
            }
        }
        $today = gmdate('Y-m-d');
        foreach (glob($this->directory . '/errors-*.counts.json') ?: [] as $file) {
            if (preg_match('/^errors-(\d{4}-\d{2}-\d{2})\.counts\.json$/D', basename($file), $match) === 1 && $match[1] < $today) {
                @unlink($file);
            }
        }
    }

    /** One more line dropped today (the log was full, or the kind had its lines for the day). */
    private function countDropped(): void
    {
        $file = $this->directory . '/errors-dropped.json';
        $handle = @fopen($file, 'c+');
        if ($handle === false) {
            return;
        }
        try {
            flock($handle, LOCK_EX);
            $counts = json_decode((string) stream_get_contents($handle), true);
            $counts = is_array($counts) ? $counts : [];
            $today = gmdate('Y-m-d');
            $counts[$today] = (int) ($counts[$today] ?? 0) + 1;
            $cutoff = gmdate('Y-m-d', time() - $this->days * 86400);
            $counts = array_filter($counts, static fn(mixed $count, string|int $day): bool => (string) $day >= $cutoff, ARRAY_FILTER_USE_BOTH);
            ftruncate($handle, 0);
            rewind($handle);
            fwrite($handle, (string) json_encode($counts));
            fflush($handle);
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    private function ensureDirectory(): bool
    {
        if ($this->guarded && is_dir($this->directory)) {
            return true;
        }

        return $this->guarded = GuardedDirectory::ensure($this->directory);
    }

    /** @return array<string, true> the fingerprints written for this request (or outside any) */
    private function &seenFor(?ServerRequestInterface $request): array
    {
        $record = $this->recordOf($request);
        if ($record !== null) {
            return $record->seen;
        }

        return $this->seen;
    }

    private function recordOf(?ServerRequestInterface $request): ?RequestRecord
    {
        $record = $request?->getAttribute(self::ATTRIBUTE);
        if ($record instanceof RequestRecord) {
            return $record;
        }

        return $this->current;
    }

    /** A request to the log's own routes - marked by them as they ran, or known by the path they are mounted at. */
    private function ignoresPath(ServerRequestInterface $request): bool
    {
        if ($this->recordOf($request)?->own === true) {
            return true;
        }
        $path = rtrim(rawurldecode($request->getUri()->getPath()), '/');
        foreach ($this->ignored as $ignored) {
            if ($path === $ignored || str_starts_with($path, $ignored . '/')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Who asked: the site's actorId, on the request the log was given and on the one a route of the library's
     * admin tools saw (later in the chain, with what the site's middleware set); else the person the auth
     * module's Identify set on either - so a site whose own reader is missing on a route (the admin tools'
     * routes carry no RecordPerson) still names the person.
     */
    private function actor(ServerRequestInterface $request): int|string|null
    {
        $requests = [$request];
        $later = $this->recordOf($request)?->latest;
        if ($later !== null && $later !== $request) {
            $requests[] = $later;
        }
        foreach ($requests as $each) {
            $actor = $this->actorId === null ? null : self::quietly(fn(): mixed => ($this->actorId)($each));
            if (is_int($actor) || (is_string($actor) && preg_match('/^[A-Za-z0-9_.:-]{1,64}$/D', $actor) === 1)) {
                return $actor;
            }
        }
        foreach ($requests as $each) {
            $person = Identify::person($each);
            if ($person !== null) {
                return $person->sub;
            }
        }

        return null;
    }

    private function clientHash(ServerRequestInterface $request): ?string
    {
        $bucket = null;
        if ($this->clientKey !== null) {
            $bucket = ($this->clientKey)($request);
        }
        if (!is_string($bucket) || $bucket === '') {
            // The library's one rule: a proxy's header from a trusted proxy alone, an IPv6 address as its /64.
            $address = ClientAddress::of($request, $this->trustedProxies, $this->clientHeader);
            $bucket = $address === null ? null : ClientAddress::bucket($address);
        }
        if ($bucket === null) {
            return null;
        }
        $this->key ??= Keys::derive(Keys::installation($this->storage), 'error-log');

        return substr(hash_hmac('sha256', $bucket, $this->key), 0, 12);
    }

    /** The route's pattern (/api/admin/users/{id}), when the request was routed or the app is known. */
    private function routePattern(ServerRequestInterface $request): ?string
    {
        $route = $request->getAttribute(RouteContext::ROUTE);
        if (is_object($route) && method_exists($route, 'getPattern')) {
            return Utf8::cut((string) $route->getPattern(), 200);
        }
        if ($this->app === null) {
            return null;
        }
        $results = $this->app->getRouteResolver()->computeRoutingResults($request->getUri()->getPath(), $request->getMethod());
        if ($results->getRouteStatus() !== RoutingResults::FOUND || $results->getRouteIdentifier() === null) {
            return null;
        }

        return Utf8::cut($this->app->getRouteCollector()->lookupRoute($results->getRouteIdentifier())->getPattern(), 200);
    }

    /**
     * An exception's trace with the chain it was thrown for (getPrevious(): a wrapper's cause - the scrub's failure
     * under People::afterRestore()'s sentence - is the failure itself). Each cause is a line of its own, "Caused by
     * Type (file:line): message" - its class short, its file relative, its message redacted and at most 500
     * characters, as the entry's own - followed by its frames that the exception it caused does not show already
     * (their shared end left out, as Java does). Within the entry's trace of 12 lines in all, whatever the chain:
     * at most 11 causes, each part keeping its first line while there is room (the frame the exception was thrown
     * from; a cause's own line), then one more line each in turn. An exception thrown for none keeps its 12 frames.
     *
     * @return list<string>
     */
    private function traceWithCauses(Throwable $exception): array
    {
        $parts = [$this->trace($exception->getTrace())];
        $enclosing = $exception->getTrace();
        $seen = [spl_object_id($exception) => true];
        for ($cause = $exception->getPrevious(); $cause !== null && count($parts) < self::TRACE_LINES; $cause = $cause->getPrevious()) {
            if (isset($seen[spl_object_id($cause)])) {
                break;
            }
            $seen[spl_object_id($cause)] = true;
            $frames = $cause->getTrace();
            $message = (string) preg_replace('/\s*\R\s*/u', ' ', Redactor::text($cause->getMessage()));
            $parts[] = [
                'Caused by ' . $this->className($cause::class) . ' (' . $this->relative($cause->getFile()) . ':' . $cause->getLine() . '): ' . Utf8::cut($message, 500),
                ...$this->trace(self::framesNotShared($frames, $enclosing)),
            ];
            $enclosing = $frames;
        }

        return self::fit($parts, self::TRACE_LINES);
    }

    /**
     * A cause's frames without the end it shares with the frames of the exception it caused - the same calls below
     * where the two were made, which that exception's own frames show already.
     *
     * @param list<array<string, mixed>> $frames
     * @param list<array<string, mixed>> $enclosing
     * @return list<array<string, mixed>>
     */
    private static function framesNotShared(array $frames, array $enclosing): array
    {
        $key = static fn(array $frame): string => ($frame['file'] ?? '') . ':' . ($frame['line'] ?? '') . ' ' . ($frame['class'] ?? '') . ($frame['type'] ?? '') . ($frame['function'] ?? '');
        $own = count($frames);
        for ($shared = count($enclosing); $own > 0 && $shared > 0 && $key($frames[$own - 1]) === $key($enclosing[$shared - 1]); $own--, $shared--) {
            // Back from the bottom of both stacks while they are the same.
        }

        return array_slice($frames, 0, $own);
    }

    /**
     * A trace's parts - the exception's frames, then each cause's line and frames - cut to $lines in all: every part
     * keeps its first line while there is room, then one more line each in turn, the order of the parts kept.
     *
     * @param list<list<string>> $parts
     * @return list<string>
     */
    private static function fit(array $parts, int $lines): array
    {
        $kept = array_fill(0, count($parts), 0);
        for ($round = 0; $lines > 0; $round++) {
            $more = false;
            foreach ($parts as $index => $part) {
                if ($lines > 0 && $round < count($part)) {
                    $kept[$index]++;
                    $lines--;
                    $more = true;
                }
            }
            if (!$more) {
                break;
            }
        }
        $out = [];
        foreach ($parts as $index => $part) {
            foreach (array_slice($part, 0, $kept[$index]) as $line) {
                $out[] = $line;
            }
        }

        return $out;
    }

    /**
     * A trace without a single call's arguments: "file:line Class->function()",
     * at most 12 frames, files relative to the root - the ones PHP writes into a
     * name too: an anonymous class is "class@anonymous" and the file and line it
     * was declared on, a closure of PHP 8.4 "{closure:file:line}".
     *
     * @param list<array<string, mixed>> $frames
     * @return list<string>
     */
    private function trace(array $frames): array
    {
        $lines = [];
        foreach (array_slice($frames, 0, self::TRACE_LINES) as $frame) {
            $where = isset($frame['file']) ? $this->relative((string) $frame['file']) . ':' . (int) ($frame['line'] ?? 0) : '[internal]';
            $class = isset($frame['class']) ? $this->className((string) $frame['class'], short: false) . ($frame['type'] ?? '->') : '';
            $lines[] = $where . ' ' . $class . $this->functionName((string) ($frame['function'] ?? '?')) . '()';
        }

        return $lines;
    }

    /**
     * A class's name (with $short, without its namespace) - for an anonymous class, whose name PHP makes of
     * "class@anonymous" (or its parent's name), a NUL and the absolute path of its file with the line and an
     * index: "class@anonymous" and that place, relative like every file the log names.
     */
    private function className(string $class, bool $short = true): string
    {
        $anonymous = strpos($class, '@anonymous');
        if ($anonymous === false) {
            return $short ? self::shortClass($class) : $class;
        }
        $kind = ($short ? self::shortClass(substr($class, 0, $anonymous)) : substr($class, 0, $anonymous)) . '@anonymous';
        $declared = substr($class, $anonymous + strlen('@anonymous'));
        $declared = ltrim($declared, "\0");
        if (preg_match('/^(.+?):(\d+)(?:\$[0-9a-f]+)?$/D', $declared, $match) === 1) {
            return $kind . '(' . $this->relative($match[1]) . ':' . $match[2] . ')';
        }

        return $kind;
    }

    /**
     * A function's name; a closure's as PHP 8.4 names it by where it is: "{closure:Foo::bar():42}" inside a
     * method or a function (kept), "{closure:/var/www/x/routes.php:42}" at a file's own scope (the file
     * relative), "{closure:{closure:…}:43}" inside another closure.
     */
    private function functionName(string $function): string
    {
        if (preg_match('/^\{closure:(.+):(\d+)\}$/sD', $function, $match) !== 1) {
            return $function;
        }
        $scope = $match[1];
        if (str_starts_with($scope, '{closure:')) {
            $scope = $this->functionName($scope);
        } elseif (!str_ends_with($scope, '()')) {
            $scope = $this->relative($scope);
        }

        return '{closure:' . $scope . ':' . $match[2] . '}';
    }

    /** A file's path relative to the site's root; outside it, its last two parts. */
    private function relative(string $file): string
    {
        if ($file === '') {
            return '';
        }
        $file = str_replace('\\', '/', $file);
        if ($this->root !== null && str_starts_with($file, $this->root)) {
            return substr($file, strlen($this->root));
        }
        $parts = explode('/', $file);

        return count($parts) > 2 ? '…/' . implode('/', array_slice($parts, -2)) : $file;
    }

    /**
     * The stable identity of a failure (genshin's): its type, where it was
     * thrown, its status, and its message with the changing parts taken out - a
     * quoted run that looks like a value (a digit or a path separator in it)
     * collapsed, numbers as #. Twelve hex characters.
     */
    public static function fingerprint(string $type, string $file, int $line, string $message, int $status = 0): string
    {
        $shape =
            preg_replace_callback('/"[^"]*"|\'[^\']*\'/', static fn(array $match): string => preg_match('/[\d\/\\\\]/', $match[0]) === 1 ? $match[0][0] . '…' . $match[0][0] : $match[0], $message) ??
            $message;
        $shape = preg_replace(['/\d+/', '/\s+/'], ['#', ' '], $shape) ?? $shape;

        return substr(md5($type . '|' . $file . '|' . $line . '|' . $status . '|' . $shape), 0, 12);
    }

    /** An answer's code and sentence from its JSON body ({code, error|message}); never anything else of it. */
    private static function answerWords(ResponseInterface $response): array
    {
        if (!str_contains(strtolower($response->getHeaderLine('Content-Type')), 'json')) {
            return [null, null];
        }
        $body = $response->getBody();
        $size = $body->getSize();
        if ($size === null || $size > 65536 || !$body->isSeekable()) {
            return [null, null];
        }
        $body->rewind();
        $data = json_decode($body->getContents(), true);
        $body->rewind();
        if (!is_array($data)) {
            return [null, null];
        }
        $code = is_string($data['code'] ?? null) && preg_match('/^[A-Za-z0-9_.:-]{1,64}$/D', $data['code']) === 1 ? $data['code'] : null;
        $sentence = $data['error'] ?? ($data['message'] ?? null);
        if (is_array($sentence)) {
            $sentence = $sentence['message'] ?? null;
        }

        return [$code, is_string($sentence) ? $sentence : null];
    }

    /** What $work answers, or null when it fails: one part of a line missing is no reason to lose the line. */
    private static function quietly(Closure $work): mixed
    {
        try {
            return $work();
        } catch (Throwable) {
            return null;
        }
    }

    private static function shortClass(string $class): string
    {
        $slash = strrpos($class, '\\');

        return $slash === false ? $class : substr($class, $slash + 1);
    }

    private static function phpErrorName(int $type): string
    {
        return match ($type) {
            E_ERROR => 'E_ERROR',
            E_WARNING => 'E_WARNING',
            E_PARSE => 'E_PARSE',
            E_NOTICE => 'E_NOTICE',
            E_CORE_ERROR => 'E_CORE_ERROR',
            E_CORE_WARNING => 'E_CORE_WARNING',
            E_COMPILE_ERROR => 'E_COMPILE_ERROR',
            E_COMPILE_WARNING => 'E_COMPILE_WARNING',
            E_USER_ERROR => 'E_USER_ERROR',
            E_USER_WARNING => 'E_USER_WARNING',
            E_USER_NOTICE => 'E_USER_NOTICE',
            E_RECOVERABLE_ERROR => 'E_RECOVERABLE_ERROR',
            E_DEPRECATED => 'E_DEPRECATED',
            E_USER_DEPRECATED => 'E_USER_DEPRECATED',
            default => 'E_' . $type,
        };
    }

    private static function now(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.v\Z');
    }
}
