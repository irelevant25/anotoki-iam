<?php

use Anotoki\Lib\Support\ClientAddress;
use Anotoki\Lib\Translations\Translations;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Small things every handler needs: answering in JSON, reading a body the same
 * way whatever it was sent as, where a request came from, and time.
 */

// languageCodes() below reads through the family's library, and the installer
// and the command-line tools load this file without the API's front
// controller - so it brings Composer's autoloader itself, as
// api/migration_runner.php does; and requestIp() the origin secret's loader.
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../config/cloudflare.php';

function respondJson(Response $response, mixed $data, int $status = 200): Response
{
    $response->getBody()->write((string) json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

    return $response
        ->withStatus($status)
        ->withHeader('Content-Type', 'application/json');
}

/** A refusal in the one shape the frontend reads: a sentence and a code. */
function respondError(Response $response, int $status, string $code, string $message, array $extra = []): Response
{
    return respondJson($response, ['error' => $message, 'code' => $code] + $extra, $status);
}

/**
 * The request body as an array, whatever it arrived as.
 *
 * Slim's body parser handles JSON and form encoding; anything it could not
 * parse arrives as null, and a handler would rather see an empty body than
 * branch on that everywhere.
 */
function requestBody(Request $request): array
{
    $body = $request->getParsedBody();

    return is_array($body) ? $body : [];
}

/** A string field from a body or query, trimmed; '' when absent or not text. */
function field(array $source, string $name): string
{
    $value = $source[$name] ?? '';

    return is_scalar($value) ? trim((string) $value) : '';
}

/**
 * The caller's address, as far as it can be known - by the anotoki family's
 * one rule (anotoki/lib's ClientAddress, the owner's of 2026-10-11), the same
 * the library's error log reads: REMOTE_ADDR, unless the connection is from
 * one of the proxies config/iam.php trusts (Cloudflare's published ranges,
 * iamTrustedProxies()) - then the visitor's address Cloudflare names in
 * CF-Connecting-IP, when it is an address at all. A forwarding header is one
 * the caller writes, so believing it from anybody else would let them set
 * their own address and walk past the rate limiter by changing it each time.
 * The header is pinned here, never a setting: Cloudflare writes
 * CF-Connecting-IP itself, and passes X-Real-IP, True-Client-IP and
 * X-Forwarded-For through as the visitor wrote them. An IPv4 address written
 * as IPv6 (::ffff:203.0.113.9, from a server listening on both) is the IPv4
 * address it is.
 *
 * Cloudflare's ranges are every Cloudflare customer's, though: anybody's
 * Worker reaches the host from them and writes the CF-Connecting-IP it likes.
 * With the origin secret (config/cloudflare.php, the owner's decision of
 * 2026-10-13) the header is believed only on a request that also carries the
 * secret in X-Anotoki-Origin, which the owner's zone adds to every request
 * through it; any other request from those ranges is the edge it came from -
 * one source to the limiter, however many addresses it writes.
 *
 * What every limit counts, the audit log keeps and a session shows. A
 * connection's own address that is no address at all - a server's oddity -
 * is still one sender to the limiter, as it came; none, null.
 */
function requestIp(Request $request): ?string
{
    $ip = ClientAddress::of($request, iamTrustedProxies(), ClientAddress::CLOUDFLARE_HEADER, iamOriginSecret());

    if ($ip === null) {
        $remote = $request->getServerParams()['REMOTE_ADDR'] ?? null;
        $ip = is_string($remote) && trim($remote) !== '' ? trim($remote) : null;
    }

    return $ip === null ? null : substr($ip, 0, 45);
}

/** The widest trusted range believed: a shorter prefix than these trusts a whole region of the internet - or everybody. */
const TRUSTED_PROXY_MIN_PREFIX_V4 = 8;
const TRUSTED_PROXY_MIN_PREFIX_V6 = 16;

/**
 * The proxies whose CF-Connecting-IP is believed: config/iam.php's
 * `trusted_proxies` (Cloudflare's published ranges, or an installation's own),
 * each a CIDR range or a single address. One that is no range at all, or wider
 * than an IPv4 /8 or an IPv6 /16 - 0.0.0.0/0 and ::/0 above all, which would
 * believe a header anybody can write - is refused, never believed, and said in
 * PHP's error log at most once a day.
 *
 * @return list<string>
 */
function iamTrustedProxies(): array
{
    static $kept = [];

    $configured = array_values(array_filter((array) (iamConfig()['trusted_proxies'] ?? []), 'is_string'));
    $key = implode("\n", $configured);
    if (isset($kept[$key])) {
        return $kept[$key];
    }

    $believed = [];
    foreach ($configured as $range) {
        $refusal = trustedProxyRefusal($range);
        if ($refusal === null) {
            $believed[] = trim($range);
        } else {
            sayOncePerDay('trusted proxies', "{$refusal}: {$range} is not believed (config/iam.local.php, trusted_proxies)");
        }
    }

    return $kept[$key] = $believed;
}

/** Why a configured trusted range is refused, or null when it is one to believe. */
function trustedProxyRefusal(string $range): ?string
{
    [$address, $bits] = array_pad(explode('/', trim($range), 2), 2, null);
    $packed = @inet_pton((string) $address);
    if ($packed === false) {
        return 'no address or CIDR range';
    }
    if ($bits === null) {
        return null;
    }
    $length = strlen($packed) * 8;
    if (!ctype_digit($bits) || strlen($bits) > 3 || (int) $bits > $length) {
        return 'no CIDR range';
    }
    $widest = $length === 32 ? TRUSTED_PROXY_MIN_PREFIX_V4 : TRUSTED_PROXY_MIN_PREFIX_V6;

    return (int) $bits < $widest ? "a range wider than a /{$widest}" : null;
}

/**
 * A line in PHP's error log about a setting passed over - at most once a day
 * for each thing it says. A stamp file under storage/cache remembers when it
 * last did: under PHP-FPM or CGI every request starts afresh, so a flag in
 * memory would say it at every request. Where no stamp can be kept, once per
 * process.
 */
function sayOncePerDay(string $topic, string $what): void
{
    static $saidWithoutStamp = [];

    $stamp = iamStorageDir() . '/cache/said-' . substr(hash('sha256', $topic . "\n" . $what), 0, 16) . '.logged';
    clearstatcache(true, $stamp);
    $last = @filemtime($stamp);
    if ($last !== false && time() - $last < 86400) {
        return;
    }

    $directory = dirname($stamp);
    if (!((is_dir($directory) || @mkdir($directory, 0775, true)) && @touch($stamp))) {
        if (isset($saidWithoutStamp[$topic . "\n" . $what])) {
            return;
        }
        $saidWithoutStamp[$topic . "\n" . $what] = true;
    }

    error_log("[{$topic}] {$what}");
}

function requestUserAgent(Request $request): ?string
{
    $agent = trim($request->getHeaderLine('User-Agent'));

    return $agent === '' ? null : substr($agent, 0, 255);
}

/**
 * A database timestamp as ISO-8601 in UTC, or null.
 *
 * Connections run in UTC (config/db.php), so what Postgres hands back is
 * already UTC; this only puts it in the shape the frontend and tokens use.
 */
function isoTime(?string $value): ?string
{
    if ($value === null || $value === '') {
        return null;
    }

    $time = strtotime($value);

    return $time === false ? null : gmdate('Y-m-d\TH:i:s\Z', $time);
}

/** Every timestamp column of a row, in the ISO form. */
function isoTimes(array $row, array $columns): array
{
    foreach ($columns as $column) {
        if (array_key_exists($column, $row)) {
            $row[$column] = isoTime($row[$column]);
        }
    }

    return $row;
}

/**
 * A boolean in the one form the driver and PostgreSQL both accept.
 *
 * PDO binds PHP `false` as the empty string, which Postgres refuses as a
 * boolean. So a bound boolean always goes through this.
 */
function pgBool(bool $value): string
{
    return $value ? 'true' : 'false';
}

/** Postgres booleans come back as bools from pdo_pgsql, but a CASE may make text. */
function asBool(mixed $value): bool
{
    return $value === true || $value === 't' || $value === 'true' || $value === 1 || $value === '1';
}

/**
 * A path on this site to go to after something, or null.
 *
 * Only a path: a full URL, a protocol-relative "//elsewhere" or a backslash
 * trick would turn "come back here afterwards" into an open redirect.
 */
function safeLocalPath(?string $path): ?string
{
    if (!is_string($path) || $path === '' || $path[0] !== '/' || str_starts_with($path, '//') || str_contains($path, '\\')) {
        return null;
    }

    return $path;
}

/** Deliberately looser than FILTER_VALIDATE_EMAIL, which refuses admin@localhost. */
function validEmailAddress(string $email): bool
{
    if (strlen($email) > 255 || preg_match('/\s/', $email) || substr_count($email, '@') !== 1) {
        return false;
    }

    [$local, $domain] = explode('@', $email);

    return $local !== '' && $domain !== '' && !str_starts_with($domain, '.') && !str_ends_with($domain, '.');
}

/** Long enough to be worth having, short enough that nobody argues. */
const PASSWORD_MIN = 8;

/** bcrypt reads only the first 72 bytes; a longer password would be silently truncated. */
const PASSWORD_MAX = 72;

function passwordRefusal(string $password): ?string
{
    if (strlen($password) < PASSWORD_MIN) {
        return 'The password needs at least ' . PASSWORD_MIN . ' characters';
    }

    if (strlen($password) > PASSWORD_MAX) {
        return 'The password can be at most ' . PASSWORD_MAX . ' bytes long';
    }

    return null;
}

/** Usernames: something, not too long, no control characters. */
function usernameRefusal(string $username): ?string
{
    if ($username === '') {
        return 'The username cannot be empty';
    }

    if (mb_strlen($username) > 100) {
        return 'The username can be at most 100 characters';
    }

    if (preg_match('/[\x00-\x1F\x7F]/', $username)) {
        return 'The username cannot contain control characters';
    }

    return null;
}

/**
 * Whether a text has nothing in it a reader could see: no character at all,
 * or only spaces and the characters that are drawn as nothing - a zero-width
 * space or joiner, a soft hyphen, a byte-order mark, a direction mark.
 *
 * A string pasted from a web page can be exactly that, and it looks like an
 * empty field. Kept as a string it would be one: an English string nobody can
 * read, a mail with a subject of nothing, a translation that hides the English
 * it was meant to fall back to. So what counts as "empty" is asked here, in
 * one place, and not with === ''.
 *
 * Only the question is widened, never the text: a string with a letter in it
 * keeps the joiner inside a word and the direction mark at its end. Bytes that
 * are not UTF-8 are nothing to read either.
 *
 * It lives here rather than in api/translations.php because api/mail.php asks
 * it too, inside the try that keeps a mail from ever failing - a function that
 * was not loaded there would quietly turn every mail into English.
 */
function textBlank(string $text): bool
{
    return preg_match('/[^\s\p{Z}\p{Cf}]/u', $text) !== 1;
}

// ── Text and refusals, for the announcements and the routes ──────────────────
//
// The strings of the pages and the mails are checked by the family's library
// (Anotoki\Lib\Translations\Text measures them as the browser does). These are
// the IAM's own, which the announcements' words (api/announcements.php) and a
// few answers use, as they did before the strings moved into the library.

/** The first $max characters of a UTF-8 string. */
function textClip(string $text, int $max): string
{
    return preg_match('/^.{0,' . $max . '}/us', $text, $m) ? $m[0] : '';
}

/** How many characters: code points, which is at most what the browser counts (UTF-16 units). */
function textLength(string $text): int
{
    return (int) preg_match_all('/./us', $text);
}

/**
 * The text without the spaces around it - every kind of space, not only the
 * ASCII ones PHP's trim() knows.
 *
 * A text the expression cannot get through - a run of more than a million
 * spaces inside it - comes back as it is, never as an empty one: whoever
 * measures it next refuses it as too long.
 */
function textTrim(string $text): string
{
    return preg_replace('/^[\s\p{Z}\x{FEFF}]+|[\s\p{Z}\x{FEFF}]+$/u', '', $text) ?? $text;
}

/** The text without control characters, tabs and line breaks aside. */
function textWithoutControls(string $text): string
{
    return (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $text);
}

/**
 * A text field's value as it is kept: valid UTF-8 without control characters
 * but tabs and line breaks - or null for what is not text at all. The controls
 * are dropped rather than refused: one pasted from a word processor would
 * otherwise make a text that can never be saved, however often it is tried.
 */
function textClean(mixed $value): ?string
{
    if (!is_string($value) || preg_match('//u', $value) !== 1) {
        return null;
    }

    return textWithoutControls($value);
}

/**
 * A refusal as the announcements' functions answer one - ['status', 'code',
 * 'error', 'extra'], the shape signInRefusal() uses - which a route turns into
 * respondError() with translationRefuse(). (Named for where it was first used:
 * the strings' checks, which are the library's now, with a refusal object of
 * their own.)
 */
function translationRefusal(int $status, string $code, string $error, array $extra = []): array
{
    return ['status' => $status, 'code' => $code, 'error' => $error, 'extra' => $extra];
}

/** Whether what a function answered is such a refusal. No result of theirs has these three keys. */
function translationRefused(mixed $result): bool
{
    return is_array($result) && isset($result['status'], $result['code'], $result['error']) && is_int($result['status']);
}

function translationRefuse(Response $response, array $refusal): Response
{
    return respondError($response, $refusal['status'], $refusal['code'], $refusal['error'], $refusal['extra'] ?? []);
}

/**
 * The codes of the languages on offer, in the order a chooser shows them.
 *
 * They are rows of `languages` (migration 006), kept in the admin panel, and
 * read through the family's library (Translations::offeredCodes()). But a
 * build is uploaded before its migrations are applied - from the panel, by
 * somebody who has to sign in first - so this must answer on a database that
 * has no such table yet. The library asks whether the tables are there before
 * reading them: a query that cannot fail, so it is safe inside a transaction,
 * where a failed one would end everything after it. Without the tables, or
 * with nothing on offer in them, the answer is the two languages of before -
 * which is exactly what the CHECK on users.language still allows then.
 *
 * A connection that is handed in is read every time and never remembered:
 * the installer and the import work on databases of their own, before any
 * configuration exists. Only the IAM's own database is remembered for the
 * request (as settingsAll() is); whatever changes the languages - the admin
 * panel, or applying migrations - asks again with $reload.
 *
 * The IAM's own database that cannot be reached, or cannot be asked, is
 * answered like one without the table - and that answer is not remembered, so
 * the next call asks again. Discovery and the pages' first request
 * (/api/public/config) read nothing else from the database, and answered
 * without it before the languages moved there: they still must, not fail on
 * the one thing they now ask it. Nothing is let through by it: whatever then
 * writes a language runs its own queries on that connection and fails there.
 * A connection that is handed in is not covered - the installer and the
 * import must see their own errors.
 *
 * It lives here rather than in api/translations.php because setup.php and
 * the command-line tools load this file and not that one.
 *
 * @return string[]
 */
function languageCodes(?PDO $pdo = null, bool $reload = false): array
{
    static $offered = null;

    $read = static function (PDO $pdo): array {
        // None while the tables are not there.
        $codes = (new Translations($pdo))->offeredCodes();

        return $codes === [] ? ['en', 'sk'] : $codes;
    };

    if ($pdo !== null) {
        return $read($pdo);
    }

    if ($offered === null || $reload) {
        try {
            $offered = $read(iamDb());
        } catch (\PDOException) {
            // Forgotten as well as not remembered: what was read before a
            // reload that failed is no longer known to be true.
            $offered = null;

            return ['en', 'sk'];
        }
    }

    return $offered;
}

/**
 * A language somebody sent, when it is one on offer - else English. What is
 * not text at all (a number, a list, nothing) is English too.
 */
function languageOrDefault(mixed $language, ?PDO $pdo = null): string
{
    if (!is_string($language)) {
        return 'en';
    }

    $language = strtolower(trim($language));

    return in_array($language, languageCodes($pdo), true) ? $language : 'en';
}
