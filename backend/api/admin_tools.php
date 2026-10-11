<?php

use Anotoki\Lib\Admin\AdminContext;
use Anotoki\Lib\Backups\Backups;
use Anotoki\Lib\Backups\Copy;
use Anotoki\Lib\ErrorLog\ErrorLog;
use Anotoki\Lib\Migrations\Migrator;
use Anotoki\Lib\Support\AfterResponse;
use Anotoki\Lib\Support\Keys;
use Anotoki\Lib\Support\Refusal;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;
use Slim\App;

/**
 * The admin panel's tools from the anotoki family's library (anotoki-lib 0.4,
 * the owner's decision of 2026-10-11): the error log, the audit log (its
 * writer is api/audit.php) and backups - one implementation, and one page
 * each, for every anotoki site. Here is what is the IAM's about them:
 *
 *   - iamAdminContext(): who acts (the session's account), who the ADMIN is
 *     (the IAM's own entry's ADMIN, as requireAdmin() says), the proof a
 *     backup asks for (accountProof(): the password, or a sign-in of the last
 *     15 minutes - never a session alone), the address a request counts as
 *     (requestIp()), and the IAM's error body;
 *   - iamErrorLog(): storage/logs, every line redacted by the library;
 *   - iamBackups(): storage/backups, ENCRYPTED under a key derived from the
 *     master key, and never restored here (below).
 *
 * The routes are mounted in routes/admin.php, inside the admin group: the
 * session, the IAM's ADMIN, the CSRF header on every write - and the
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
        // The address the IAM's limiter counts: the trusted proxy's header from
        // a trusted proxy, the connection's otherwise.
        clientKey: static fn(Request $request): string => requestIp($request) ?? '',
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
 * The IAM's own names of what opens something, taken out of a message before
 * the library's error log reads it - whose redactor then does the family's
 * part: `password=` and `secret=` and their kin, Bearer credentials, JWTs,
 * runs of a token's alphabet, e-mail and IP addresses, PostgreSQL's DETAIL.
 * Its names are the family's; these are the IAM's: its cookies (the session's
 * under its __Host- name and the bare one, and the device cookie, as
 * config/iam.php names them), its refresh and personal tokens by their
 * prefixes, the bodies' fields that carry a credential (a current or new
 * password, a remembered device's token, a code - the sign-in's, the mailed
 * one, an authenticator's -, a recovery code, Google's credential) and a
 * two-factor secret. Their value goes whatever it looks like: one with no
 * digit in it is no token-like run to the library. Nothing the IAM throws
 * names one; this is for what might.
 */
function iamRedactSecrets(string $text): string
{
    $cookie = preg_quote((string) (iamConfig()['cookie_name'] ?? 'anotoki_iam'), '/');
    $rules = [
        '/((?:__Host-)?' . $cookie . '(?:_device)?)=[^;,\s"\']*/' => '$1={…}',
        '/\biam_(rt|pat)_[A-Za-z0-9_-]*/' => 'iam_$1_{…}',
        '/\b(current_password|new_password|device_token|totp_secret|totp|recovery_codes?|code|credential)\b(["\']?\s*(?:=>|[=:])\s*)("(?:[^"\\\\]|\\\\.)*"|\'(?:[^\'\\\\]|\\\\.)*\'|[^\s&,;"\']+)/i' => '$1$2{…}',
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
 * An exception's message with the IAM's names taken out (iamRedactSecrets()),
 * in place - the error log reads it next, with its type, place and trace as
 * they were (an exception cannot be copied). For the IAM's own error handler,
 * before the library writes the line.
 */
function iamRedactException(\Throwable $exception): void
{
    $message = $exception->getMessage();
    $redacted = iamRedactSecrets($message);
    if ($redacted === $message) {
        return;
    }
    try {
        (new \ReflectionProperty($exception instanceof \Exception ? \Exception::class : \Error::class, 'message'))->setValue($exception, $redacted);
    } catch (\Throwable) {
        // As it was: the library's redactor reads it all the same.
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

/** PHP's fatal errors: what a shutdown function finds when the script was stopped by one. */
const IAM_FATAL_ERRORS = E_ERROR | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR | E_USER_ERROR | E_RECOVERABLE_ERROR;

/**
 * PHP's own errors into the error log, as the library's installPhpHandlers()
 * writes them - a shutdown function for the fatal ones (a timeout, memory run
 * out, an exception nobody caught; 64 KB kept in reserve and freed first), and
 * an error handler for warnings and notices, chained to whatever was there -
 * with the IAM's own names of secrets out of the message first
 * (iamRedactSecrets()), which the library's handlers cannot be told. What `@`
 * silences is not written, and PHP's own handling after them is as it was.
 * Once a process.
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
 * the log can tell one address from another and nobody can work an address
 * back out of it - and no other key file is kept. Before the IAM is set up
 * there is no master key: a key for this process alone then, as a line written
 * before anybody could sign in needs to say nothing of who sent it.
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
 *     deleted since - reviewed first (docs/decisions.md, 2026-10-11).
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

    $pdo = Copy::connect(
        "pgsql:host={$config['host']};port={$config['port']};dbname={$name};connect_timeout=5",
        (string) $config['username'],
        (string) ($config['password'] ?? '')
    );
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
