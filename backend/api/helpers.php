<?php

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Small things every handler needs: answering in JSON, reading a body the same
 * way whatever it was sent as, where a request came from, and time.
 */

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
 * The caller's address, as far as it can be known.
 *
 * REMOTE_ADDR, unless it is one of the proxies config/iam.php is told to trust.
 * A forwarding header is one the caller writes, so believing it from anybody
 * would let them set their own address and walk past the rate limiter by
 * changing it each time. From a trusted proxy (Cloudflare) the connection is
 * the proxy's and the visitor's address is in the header it sets - believed
 * then, and only when it is an address at all.
 */
function requestIp(Request $request): ?string
{
    $ip = $request->getServerParams()['REMOTE_ADDR'] ?? null;

    if (!is_string($ip) || $ip === '') {
        return null;
    }

    $config = iamConfig();
    $proxies = (array) ($config['trusted_proxies'] ?? []);

    if ($proxies !== [] && ipInRanges($ip, $proxies)) {
        $forwarded = trim(explode(',', $request->getHeaderLine((string) ($config['client_ip_header'] ?? 'CF-Connecting-IP')))[0]);
        if (filter_var($forwarded, FILTER_VALIDATE_IP) !== false) {
            $ip = $forwarded;
        }
    }

    return substr($ip, 0, 45);
}

/** Whether an address (v4 or v6) is inside one of the CIDR ranges; a bare address is a range of one. */
function ipInRanges(string $ip, array $ranges): bool
{
    $address = @inet_pton($ip);

    if ($address === false) {
        return false;
    }

    foreach ($ranges as $range) {
        [$subnet, $bits] = array_pad(explode('/', (string) $range, 2), 2, null);
        $network = @inet_pton(trim((string) $subnet));

        if ($network === false || strlen($network) !== strlen($address)) {
            continue;
        }

        $bits = $bits === null ? strlen($address) * 8 : max(0, min(strlen($address) * 8, (int) $bits));
        $whole = intdiv($bits, 8);
        $rest = $bits % 8;

        if (substr($address, 0, $whole) !== substr($network, 0, $whole)) {
            continue;
        }
        if ($rest === 0) {
            return true;
        }

        $mask = (0xFF << (8 - $rest)) & 0xFF;
        if ((ord($address[$whole]) & $mask) === (ord($network[$whole]) & $mask)) {
            return true;
        }
    }

    return false;
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

const LANGUAGES = ['en', 'sk'];

function languageOrDefault(?string $language): string
{
    $language = strtolower(trim((string) $language));

    return in_array($language, LANGUAGES, true) ? $language : 'en';
}
