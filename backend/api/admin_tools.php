<?php

use Anotoki\Lib\Admin\AdminContext;
use Anotoki\Lib\Backups\Backups;
use Anotoki\Lib\Backups\Copy;
use Anotoki\Lib\ErrorLog\ErrorLog;
use Anotoki\Lib\ErrorLog\Http\ErrorLogRoutes;
use Anotoki\Lib\Migrations\Migrator;
use Anotoki\Lib\Support\AfterResponse;
use Anotoki\Lib\Support\ClientAddress;
use Anotoki\Lib\Support\Keys;
use Anotoki\Lib\Support\Redactor;
use Anotoki\Lib\Support\Refusal;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;
use Slim\App;
use Slim\Psr7\Factory\StreamFactory;

/**
 * The admin panel's tools from the anotoki family's library (anotoki-lib 0.5,
 * the owner's decisions of 2026-10-11): the error log, the audit log (its
 * writer is api/audit.php) and backups - one implementation, and one page
 * each, for every anotoki site. Here is what is the IAM's about them:
 *
 *   - iamAdminContext(): who acts (the session's account), who the ADMIN is
 *     (the IAM's own entry's ADMIN, as requireAdmin() says), the proof a
 *     backup asks for (accountProof(): the password, or a sign-in of the last
 *     15 minutes - never a session alone), where a request comes from (the
 *     family's rule over Cloudflare's ranges, requestIp()), the people's names
 *     (the Backups page's "By"), and the IAM's error body;
 *   - iamErrorLog(): storage/logs, every line redacted by the library - with
 *     the IAM's own names of secrets (IAM_SECRET_NAMES) and, on every way in,
 *     its own prefixes and cookies (iamRedactSecrets()) - the pages' own
 *     errors too (iamClientErrorRedaction(), routes/login.php);
 *   - iamBackups(): storage/backups, ENCRYPTED under a key derived from the
 *     master key, and never restored here (below).
 *
 * The admin routes are mounted in routes/admin.php, inside the admin group:
 * the session, the IAM's ADMIN, the CSRF header on every write - and the
 * library's own ADMIN check inside each group besides.
 */

// The command line (migration.php, backup.php) loads this file without the
// API's front controller: what it needs, it brings.
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/secret.php';
require_once __DIR__ . '/audit.php';
require_once __DIR__ . '/migration_runner.php';

/**
 * The IAM's own names a secret is written under: the bodies' fields that
 * carry a credential - a current or a new password, a remembered device's
 * token, a code (the sign-in's, the mailed one - `email_code` where it stands
 * in for the authenticator during its pause -, an authenticator's), a
 * recovery code, Google's credential - a two-factor secret, and the origin
 * secret of config/cloudflare.local.php. Given to the
 * library's redactor once, as the process starts (Redactor::addSecretNames(),
 * anotoki-lib 0.5.0): from then on every message the library writes down - an
 * exception's and each of its causes', a PHP warning's, a page's own error,
 * PHP's error log line for a 500 - loses `name=value` and `name: value` of
 * them, quoted or not, whatever the value looks like (one with no digit in it
 * is no token-like run to the library). `password`, `token`, `secret`,
 * `cookie` and their kin are the family's names already - each a whole word:
 * `secret` takes nothing out of `origin_secret=`, nor `code` out of
 * `email_code=`. What a name cannot
 * say - a refresh or personal token by its prefix, the IAM's cookies by their
 * configured names - is iamRedactSecrets()'s.
 */
const IAM_SECRET_NAMES = ['current_password', 'new_password', 'device_token', 'totp_secret', 'totp', 'recovery_code', 'recovery_codes', 'code', 'email_code', 'credential', 'origin_secret'];
Redactor::addSecretNames(...IAM_SECRET_NAMES);

/**
 * What the library's admin tools need to know of the IAM, given once. Every
 * answer comes from the request requireSession() and requireAdmin() have
 * already let through - the account is the request's `user` attribute, its
 * session the `session` one - never from a body.
 */
function iamAdminContext(): AdminContext
{
    static $context = null;

    return $context ??= new AdminContext(
        actorId: static function (Request $request): ?int {
            $user = $request->getAttribute('user');

            return is_array($user) && isset($user['id']) ? (int) $user['id'] : null;
        },
        // The IAM's ADMIN, as requireAdmin() decides it - which ran first: an
        // administrator whose own deletion is pending administers nothing.
        isAdmin: static function (Request $request): bool {
            $user = $request->getAttribute('user');

            return is_array($user) && isset($user['id']) && isIamAdmin((int) $user['id']) && !accountDeletionPending($user);
        },
        // A backup made, downloaded or deleted asks what the account page asks
        // before what a session alone must not do: the password where it is a
        // way in, else a sign-in of the last 15 minutes. A backup holds every
        // account's hashes and encrypted secrets; a session left open on
        // somebody's computer is no way to carry them off.
        proof: static function (Request $request, array $body): array {
            $user = $request->getAttribute('user');
            $session = $request->getAttribute('session');
            if (!is_array($user) || !is_array($session)) {
                return ['status' => 401, 'code' => 'no_session', 'error' => 'Sign in first'];
            }

            return accountProof(iamDb(), $request, $user, $session, $body['password'] ?? null, ATTEMPT_METHOD_BACKUP);
        },
        proofKind: static function (Request $request): string {
            $user = $request->getAttribute('user');

            return is_array($user) && passwordLoginAvailable($user) ? AdminContext::PROOF_PASSWORD : AdminContext::PROOF_RECENT_SIGN_IN;
        },
        // Where a request comes from, as the library's limits count it (the
        // pages' own errors) and its error log hashes it: the IAM's one
        // reading of the address (requestIp() - the visitor's from
        // Cloudflare's ranges, the connection's otherwise), in the family's
        // bucket - an IPv4 address whole, an IPv6 address its /64, one
        // subscriber's whole network, so a sender cannot walk past a limit
        // through the addresses of its own network. The proxies themselves -
        // the ranges iamTrustedProxies() believes, the header the library's
        // own, CF-Connecting-IP, never another - and the owner's zone's
        // secret, without which a request from those ranges is the edge it
        // came from (config/cloudflare.php, anotoki-lib 0.5.2), for whatever
        // of the library reads them from here.
        clientKey: static function (Request $request): string {
            $ip = requestIp($request);

            return $ip === null ? '' : ClientAddress::bucket($ip);
        },
        trustedProxies: iamTrustedProxies(),
        originSecret: iamOriginSecret(),
        // The people's names by id, for the pages that show who did what - the
        // Backups page's "By": each account's username as it is now (an
        // anonymized account's placeholder, never the name it had), as the
        // Audit page names them (auditActorNames()).
        names: static fn(array $ids): array => auditActorNames(array_values(array_filter($ids, static fn(mixed $id): bool => is_int($id) && $id > 0 && $id <= 2147483647))),
        // The IAM's error body, {error, code} - the frontend's one shape.
        error: static fn(Response $response, Refusal $refusal): Response => respondError($response, $refusal->status, $refusal->code, $refusal->message, $refusal->extra),
    );
}

/**
 * The error log: one JSON line per failure in storage/logs/errors-YYYY-MM-DD.jsonl
 * (UTC days, 30 kept, 64 MB at most), written by the library and read on the
 * admin panel's Error log page. What it writes is the library's rule: never a
 * query string, a header, a cookie, a body, a token or the session; a message
 * through its redactor (secrets named `password=`, Bearer credentials, JWTs,
 * runs of a token's alphabet, e-mail and IP addresses); a trace without a
 * call's arguments; the address as a keyed hash of it, never itself.
 *
 * Its first caller gives it the app (api/index.php), which names a request's
 * route.
 */
function iamErrorLog(?App $app = null): ErrorLog
{
    static $log = null;

    return $log ??= new ErrorLog(iamStorageDir() . '/logs', [
        'root' => dirname(__DIR__),
        'context' => iamAdminContext(),
        'app' => $app,
        'key' => iamErrorLogKey(),
    ]);
}

/**
 * What of the IAM's opens something and no name can say, taken out of a text
 * before the library's error log reads it - whose redactor then does the
 * rest: the family's names of a secret (`password=`, `secret=`, `cookie=` and
 * their kin) and the IAM's own (IAM_SECRET_NAMES, given to it above), Bearer
 * credentials, JWTs, runs of a token's alphabet, e-mail and IP addresses,
 * PostgreSQL's DETAIL. These are the IAM's: its cookies (the session's under
 * its __Host- name and the bare one, and the device cookie, as config/iam.php
 * names them) and its refresh and personal tokens by their prefixes - whose
 * value goes whatever it looks like: one with no digit in it is no
 * token-like run to the library. Nothing the IAM throws names one; this is
 * for what might.
 */
function iamRedactSecrets(string $text): string
{
    $cookie = preg_quote((string) (iamConfig()['cookie_name'] ?? 'anotoki_iam'), '/');
    $rules = [
        '/((?:__Host-)?' . $cookie . '(?:_device)?)=[^;,\s"\']*/' => '$1={…}',
        '/\biam_(rt|pat)_[A-Za-z0-9_-]*/' => 'iam_$1_{…}',
    ];
    foreach ($rules as $pattern => $replacement) {
        $text = preg_replace($pattern, $replacement, $text);
        if ($text === null) {
            return '{a message the IAM could not read}';
        }
    }

    return $text;
}

/**
 * What the audit log keeps, for good, of a failed Apply's error
 * (`migrations.applied`): its first line, through the library's redactor
 * (PostgreSQL's DETAIL and a value echoed at a line's end, addresses, tokens)
 * and the IAM's own names of secrets, every absolute file path taken out, at
 * most 500 characters. PostgreSQL's whole message can quote a row
 * (`Key (email)=(...) already exists`) and a hook's can name a path on the
 * server; the administrator who pressed Apply still sees the whole error on
 * the Migrations page - only what is kept for good is cut (the security
 * review of anotoki-lib 0.6.0, 2026-10-14).
 */
function iamMigrationErrorForAudit(?string $error): ?string
{
    if ($error === null || trim($error) === '') {
        return $error;
    }
    // The first line, split as the bytes come (\r\n, \r or \n), never as UTF-8:
    // a message that is not UTF-8 makes a /u split fail, and the whole of it
    // would stay. Made UTF-8 after the cut.
    $first = '';
    foreach (preg_split('/\r\n|\r|\n/', $error) ?: [] as $line) {
        if (trim($line) !== '') {
            $first = trim($line);
            break;
        }
    }
    $first = mb_scrub($first, 'UTF-8');
    // A value PostgreSQL quotes at the end of the line whose closing quote fell
    // on a later line (a value with a line break in it): left open by the cut,
    // it goes whole.
    $first = preg_replace('/(:\s+)"(?:[^"]|"")*$/u', '$1"{…}"', $first) ?? '';
    $text =
        preg_replace(
            [
                // C:\... or C:/... - a drive letter not inside a word (so never https:// or a SQLSTATE).
                '#(?<![\w])[A-Za-z]:[\\\\/][^\s"\'<>]*#u',
                // \\server\share\... - a Windows network path.
                '#\\\\\\\\[^\s"\'<>]+#u',
                // file:///..., phar:///..., stream:/... - a path behind any scheme but the web's.
                '#(?<![\w.+-])(?!https?:)[A-Za-z][A-Za-z0-9+.-]*:/+[^\s"\'<>]*#u',
                // /two/or/more/segments - not inside a word, and never a URL's own slashes.
                '#(?<![\w.~:/-])/(?:[^\s/"\'<>]+/)+[^\s/"\'<>]*#u',
                // What a path with a space in it leaves after the rules above
                // ("Jane Doe\iam\x.php", "Doe/www/x.php"): a word with a backslash
                // in it, or one with two slashes in it.
                '#[^\s"\'<>{}]*\\\\[^\s"\'<>]*#u',
                '#(?<=^|[\s"\'(])[^\s"\'<>/{}]+/[^\s"\'<>/]+/[^\s"\'<>]*#u',
            ],
            '{path}',
            iamRedactSecrets(Redactor::text($first)),
        ) ?? '{a message the IAM could not read}';

    return mb_strlen($text) > 500 ? mb_substr($text, 0, 500) . '…' : $text;
}

/**
 * An exception's message, and each of its causes' - the whole chain
 * getPrevious() walks -, with the IAM's own prefixes and cookies taken out
 * (iamRedactSecrets()), in place: the error log reads them next, with their
 * types, places and traces as they were (an exception cannot be copied). Since
 * anotoki-lib 0.5.0 a line keeps every cause's message too ("Caused by ...":
 * a wrapper's sentence comes with its real failure - the library's own
 * backups and restores throw theirs with the failure as the cause), and the
 * library's names reach none of these. For the IAM's own error handler and
 * every failure it writes itself, before the library writes the line.
 */
function iamRedactException(\Throwable $exception): void
{
    $seen = [];
    for ($each = $exception; $each !== null && !isset($seen[spl_object_id($each)]); $each = $each->getPrevious()) {
        $seen[spl_object_id($each)] = true;
        $message = $each->getMessage();
        $redacted = iamRedactSecrets($message);
        if ($redacted === $message) {
            continue;
        }
        try {
            (new \ReflectionProperty($each instanceof \Exception ? \Exception::class : \Error::class, 'message'))->setValue($each, $redacted);
        } catch (\Throwable) {
            // As it was: the library's redactor reads it all the same.
        }
    }
}

/**
 * A failure into the error log, the IAM's own names of secrets out of it first
 * (iamRedactException()): how every caller that writes one itself writes it -
 * the day's backup failing, housekeeping, the work after the answer.
 */
function iamRecordError(\Throwable $exception, ?Request $request = null, int $status = 500): void
{
    iamRedactException($exception);
    iamErrorLog()->record($exception, $request, $status);
}

/**
 * Just inside the error log's outer half (api/index.php): an exception that
 * leaves every other part of the IAM unanswered - thrown by the security
 * headers, the not-set-up gate or the CORS check (its database), all outside
 * the error handler - has the IAM's own names of secrets taken out of its
 * message before the outer half writes it, and goes on as it was.
 */
function iamRedactingMiddleware(): \Closure
{
    return static function (Request $request, RequestHandler $handler): Response {
        try {
            return $handler->handle($request);
        } catch (\Throwable $exception) {
            iamRedactException($exception);
            throw $exception;
        }
    };
}

/**
 * On the pages' own errors (`POST /api/client-errors`, the library's
 * ErrorLogRoutes::client(), routes/login.php): the IAM's own prefixes and
 * cookies out of a report's words (iamRedactSecrets()) before the library
 * reads them, as on every other way into the error log. The browser scrubs a
 * report before it leaves (@anotoki/lib/error-report) and the library
 * redacts it again by the family's names and the IAM's - but neither knows
 * that `iam_pat_` begins a personal token whatever follows it.
 *
 * The library reads the body itself, from the stream, 8 KB at most - so it is
 * read here as the library reads it, and handed on as a body of its own: the
 * report's texts (its type, message, page and stack) redacted, as JSON again.
 * What the route refuses whatever it holds - not JSON, more than it takes by
 * its Content-Length - goes on untouched, and what is not a JSON object, or
 * more than the route takes, goes on as it came, for the route to refuse in
 * its own words.
 */
function iamClientErrorRedaction(): \Closure
{
    return static function (Request $request, RequestHandler $handler): Response {
        $limit = ErrorLogRoutes::CLIENT_BODY_LIMIT;
        $type = strtolower(trim(explode(';', $request->getHeaderLine('Content-Type'), 2)[0]));
        $declared = trim($request->getHeaderLine('Content-Length'));
        if ($type !== 'application/json' || ($declared !== '' && (!ctype_digit($declared) || strlen($declared) > 15 || (int) $declared > $limit))) {
            return $handler->handle($request);
        }

        $stream = $request->getBody();
        if ($stream->isSeekable()) {
            $stream->rewind();
        }
        $raw = '';
        while (!$stream->eof() && strlen($raw) <= $limit) {
            $chunk = $stream->read($limit + 1 - strlen($raw));
            if ($chunk === '') {
                break;
            }
            $raw .= $chunk;
        }

        $body = $raw;
        // PHP's own depth, never stricter than the library's reading (JsonBody, 32 today): a body only this
        // middleware could not read would reach the library as it came, unredacted.
        $report = strlen($raw) <= $limit ? json_decode($raw, false, 512) : null;
        if ($report instanceof \stdClass) {
            $redact = static fn(mixed $value): mixed => is_string($value) ? iamRedactSecrets($value) : $value;
            foreach (['type', 'message', 'page'] as $field) {
                if (property_exists($report, $field)) {
                    $report->{$field} = $redact($report->{$field});
                }
            }
            if (property_exists($report, 'stack')) {
                $report->stack = is_array($report->stack) ? array_map($redact, $report->stack) : $redact($report->stack);
            }
            // Never the words unredacted: a report that cannot be written again is an empty one, which the route refuses.
            $body = json_encode($report, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) ?: '{}';
        }

        return $handler->handle($request->withBody((new StreamFactory())->createStream($body))->withHeader('Content-Length', (string) strlen($body)));
    };
}

/** PHP's fatal errors: what a shutdown function finds when the script was stopped by one. */
const IAM_FATAL_ERRORS = E_ERROR | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR | E_USER_ERROR | E_RECOVERABLE_ERROR;

/**
 * PHP's own errors into the error log, as the library's installPhpHandlers()
 * writes them - a shutdown function for the fatal ones (a timeout, memory run
 * out, an exception nobody caught; 64 KB kept in reserve and freed first), and
 * an error handler for warnings and notices, chained to whatever was there -
 * with the IAM's own prefixes and cookies out of the message first
 * (iamRedactSecrets()), which the library's handlers cannot be told: its
 * redactor takes the IAM's names of secrets (IAM_SECRET_NAMES), never a rule
 * of a site's own. What `@` silences is not written, and PHP's own handling
 * after them is as it was. Once a process.
 */
function iamInstallPhpHandlers(ErrorLog $log): void
{
    static $installed = false;
    if ($installed) {
        return;
    }
    $installed = true;

    $reserve = str_repeat("\0", 65536);
    register_shutdown_function(static function () use (&$reserve, $log): void {
        $reserve = null;
        $error = error_get_last();
        if (is_array($error) && ((int) $error['type'] & IAM_FATAL_ERRORS) !== 0) {
            $log->recordPhp(['message' => iamRedactSecrets((string) $error['message'])] + $error);
        }
    });

    $previous = null;
    $previous = set_error_handler(static function (int $type, string $message, string $file = '', int $line = 0) use (&$previous, $log): bool {
        // A handler before this one that throws makes it an exception, written where it is answered.
        $handled = $previous !== null && (bool) $previous($type, $message, $file, $line);
        if ((error_reporting() & $type) !== 0) {
            $log->recordPhp(['type' => $type, 'message' => iamRedactSecrets($message), 'file' => $file, 'line' => $line]);
        }

        return $handled;
    });
}

/**
 * The key of the error log's client hashes. Derived from the master key, for
 * this purpose alone (Keys::derive(): HKDF, its info naming the purpose), so
 * the log can tell one address from another without writing any down - and no
 * other key file is kept. A keyed hash is a pseudonym, stable for as long as
 * its key lasts: whoever holds the key - the master key's holder - could hash
 * every IPv4 address and find which one it was; without the key, nobody can.
 * Before the IAM is set up there is no master key: a key for this process
 * alone then, as a line written before anybody could sign in needs to say
 * nothing of who sent it.
 */
function iamErrorLogKey(): string
{
    return iamSetUpProblems() === [] ? Keys::derive(iamMasterKey(), 'iam-error-log') : random_bytes(32);
}

/**
 * Backups of the IAM's database, by the library (no pg_dump: COPY through PDO,
 * every table in one snapshot, with the migrations it has applied), in
 * storage/backups - never served (storage/.htaccess, and the library's own
 * guard in the folder).
 *
 *   - ENCRYPTED, always: AES-256-GCM chunks under a key derived from the
 *     master key (Keys::derive(iamMasterKey(), 'iam-backup')), the rows sealed
 *     as COPY hands them over - never in the clear on the disk. A backup holds
 *     every account's password hash, the encrypted two-factor secrets and
 *     signing keys, every hash of a session and a token: without the master
 *     key a downloaded copy opens nothing, and with a changed master key an
 *     older backup opens only with the old one (docs/operations.md).
 *   - NEVER RESTORED here (restore: false). An IAM put back as it was would
 *     bring back revoked refresh tokens and sessions, old passwords, removed
 *     roles and deleted people. It is switched on only with an afterRestore
 *     that ends every session and token and deletes again every account
 *     deleted since - reviewed first (docs/decisions.md, 2026-10-11); the
 *     owner kept it off (2026-10-13). A database lost is put back by hand,
 *     offline, and the record of deletions kept beside it replayed onto it
 *     (account-deletions.php, api/account_deletion.php; docs/operations.md,
 *     "When the database is lost").
 *   - Daily (the first housekeeping run of a UTC day, after the answer; 14
 *     kept), before every update the Migrations page or the command line
 *     applies (5 kept), and by hand (until deleted).
 *   - Each made, downloaded or deleted with the proof (iamAdminContext()) and
 *     an audit entry.
 */
function iamBackups(): Backups
{
    static $backups = null;

    return $backups ??= new Backups(static fn(): PDO => iamBackupConnection(), iamStorageDir() . '/backups', [
        'site' => 'iam',
        'migrator' => static fn(PDO $pdo): Migrator => iamMigrator($pdo),
        'encryption' => Keys::derive(iamMasterKey(), 'iam-backup'),
        'restore' => false,
        'audit' => iamAuditLog(),
        'defer' => AfterResponse::shared()->defer(...),
        // The daily backup's failure on the Error log page, never on the page that carried it.
        'onError' => static function (\Throwable $e): void {
            iamRecordError($e);
        },
    ]);
}

/**
 * A connection of the backups' own to the configured database: a backup is a
 * transaction of its own (REPEATABLE READ, READ ONLY), never inside one a
 * request holds. Opened by the library (Copy::connect(): the driver's own
 * class where PHP has it, so COPY raises no deprecation), never pooled, in UTC
 * and UTF-8 like every connection of the IAM's.
 */
function iamBackupConnection(): PDO
{
    $config = databaseConfig();
    $name = trim((string) ($config['databases']['iam'] ?? ''));

    $pdo = Copy::connect("pgsql:host={$config['host']};port={$config['port']};dbname={$name};connect_timeout=5", (string) $config['username'], (string) ($config['password'] ?? ''));
    $pdo->exec("SET TIME ZONE 'UTC'; SET client_encoding = 'UTF8'");

    return $pdo;
}

/**
 * The engine as an update applies it - the Migrations page's Apply and
 * `php migration.php` - on the configured database, with a backup of the
 * database as it was before anything runs (the library's beforeApply: once
 * for each list of pending files; one that cannot be made stops the Apply
 * with nothing applied). The installer applies without one: what it builds is
 * the database it was just given, and a new installation has nothing to keep.
 */
function iamMigratorWithBackup(array $options = []): Migrator
{
    return iamMigrator(iamDb(), ['beforeApply' => iamBackups()->beforeApplyHook()] + $options);
}
