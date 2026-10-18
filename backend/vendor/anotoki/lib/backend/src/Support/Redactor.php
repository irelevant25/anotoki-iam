<?php

declare(strict_types=1);

namespace Anotoki\Lib\Support;

/**
 * What the library writes down about a failure, with the personal data and the
 * secrets taken out - for the error log, which keeps a message for 30 days and
 * shows it to an administrator.
 *
 * text(): a message.
 *   - PostgreSQL's detail lines go whole, from the first of them to the end:
 *     DETAIL ("Key (email)=(a@b.c) already exists", "Failing row contains
 *     (...)" - the row itself), HINT, CONTEXT (a COPY's line and value),
 *     WHERE, QUERY, STATEMENT, LINE n (the query echoed back);
 *   - a value PostgreSQL echoes at the end of a line ('invalid input syntax for
 *     type integer: "..."') becomes "{…}";
 *   - Bearer and Basic credentials, JWTs (eyJ…), a secret written as
 *     name=value or name: value (password, token, secret, cookie, ticket, ...),
 *     its name quoted or not ({"password":"…"}, 'pass' => '…'), a URL's query
 *     string;
 *   - e-mail addresses ({email}), IPv4 and IPv6 addresses ({ip});
 *   - a run of 24 or more characters of a token's alphabet (base64url, hex)
 *     that mixes letters and digits, or is all hex ({token}) - a session id, a
 *     reset token, a backup's random name. Words alone stay (a class name such
 *     as HttpMethodNotAllowedException is no token).
 * path(): a request's path (never its query, which is not given): a segment
 *   longer than 40 characters, or one that looks like a token, an address or
 *   an e-mail address, becomes {…}; numbers and words stay
 *   (/api/admin/users/42 stays as it is). At most 200 characters.
 *
 * Text that is not UTF-8 has its stray bytes replaced first, so no rule is
 * skipped because a regular expression could not read it. A text longer than
 * text() reads loses, with what it does not read, the run of characters its
 * cut went through - half a secret is one no rule knows - and a quoted secret
 * the text ends inside is taken out to the end.
 */
final class Redactor
{
    public const MARK = '{…}';

    /** What text() reads of a message at most: more than any line keeps, and no rule takes long on it. */
    public const TEXT_LIMIT = 4096;

    /** What a message reads as when a rule could not be run on it: never the text unredacted. */
    public const UNREADABLE = '{a message the redactor could not read}';

    /** The names a secret is written under (name=value, name: value), as a pattern's alternation. */
    private const SECRET_NAMES = 'pass(?:word|wd)?|pwd|secret|client_secret|token|access_token|refresh_token|id_token|api[_-]?key|access[_-]?key|authorization|cookie|session(?:[_-]?id)?|sid|ticket|code_verifier|signature|sig';

    /** @var list<string> the site's own secret names (addSecretNames()) */
    private static array $siteNames = [];

    /**
     * A site's own names a secret is written under - the IAM's `totp_secret`, `recovery_code` - beside the
     * library's: from now on every message is redacted of `name=value` and `name: value` (quoted or not) of
     * them too. For the whole process, once, while the site starts: the supported way, never a message
     * edited by reflection. A name is letters, digits, _, - and . (at most 64), compared without case.
     */
    public static function addSecretNames(string ...$names): void
    {
        foreach ($names as $name) {
            if (preg_match('/^[A-Za-z](?:[A-Za-z0-9_.-]{0,62}[A-Za-z0-9_])?$/D', $name) !== 1) {
                throw new \InvalidArgumentException("A secret's name is letters, digits, _, - and . (at most 64): \"$name\" is not.");
            }
            if (!in_array(strtolower($name), self::$siteNames, true)) {
                self::$siteNames[] = strtolower($name);
            }
        }
    }

    /** @internal the tests: the library's names alone again */
    public static function forgetSiteSecretNames(): void
    {
        self::$siteNames = [];
    }

    /**
     * A message - its first 4,096 characters - with the personal data and the
     * secrets taken out (see the class). Should a rule fail (PCRE's limits), the
     * message is not given at all.
     */
    public static function text(string $text): string
    {
        $whole = Utf8::scrub($text);
        $text = Utf8::prefix($whole, self::TEXT_LIMIT);
        if (strlen($text) < strlen($whole)) {
            $text = self::withoutTheCutRun($text);
        }
        $names = self::SECRET_NAMES . implode('', array_map(static fn(string $name): string => '|' . preg_quote($name, '/'), self::$siteNames));

        $rules = [
            // PostgreSQL's detail lines, from the first to the end: they quote rows and values.
            '/(?:^|\R)[ \t]*(?:DETAIL|HINT|CONTEXT|WHERE|QUERY|STATEMENT|LINE \d+):.*$/suD' => '',
            '/\s(?:DETAIL|HINT|CONTEXT):\s.*$/suD' => '',
            // A value echoed back at the end of a line: 'invalid input syntax for type uuid: "..."'.
            '/(:\s+)"(?:[^"\\\\\n]++|\\\\.|"")*+"(?=[ \t]*(?:$|\R))/mu' => '$1"' . self::MARK . '"',
            '/\b(Bearer|Basic)\s+[A-Za-z0-9._~+\/=-]+/iu' => '$1 ' . self::MARK,
            '/\beyJ[A-Za-z0-9_-]{5,}\.[A-Za-z0-9_-]{5,}\.[A-Za-z0-9_-]*/u' => '{token}',
            // name=value, name: value, and the same quoted - JSON's "password":"…", PHP's 'pass' => '…' - a quoted
            // one the text ends inside (cut) to its end: its words before the cut are the secret's too.
            '/\b(' . $names . ')\b(["\']?\s*(?:=>|[=:])\s*)("(?:[^"\\\\]|\\\\.)*(?:"|\z)|\'(?:[^\'\\\\]|\\\\.)*(?:\'|\z)|[^\s&,;"\']+)/iu' => '$1$2' . self::MARK,
            '/(\bhttps?:\/\/[^\s?#"\'<>]*)\?[^\s#"\'<>]*/iu' => '$1?' . self::MARK,
            '/\?[A-Za-z0-9_.\[\]-]+=[^\s#"\'<>]*/u' => '?' . self::MARK,
            '/[^\s@<>()\[\]{}"\',;:\/\\\\]+@[^\s@<>()\[\]{}"\',;:\/\\\\]+\.[^\s@<>()\[\]{}"\',;:\/\\\\.]+/u' => '{email}',
            '/(?<![\d.])(?:(?:25[0-5]|2[0-4]\d|1?\d?\d)\.){3}(?:25[0-5]|2[0-4]\d|1?\d?\d)(?![\d.])/u' => '{ip}',
        ];
        foreach ($rules as $pattern => $replacement) {
            $text = preg_replace($pattern, $replacement, $text);
            if ($text === null) {
                return self::UNREADABLE;
            }
        }

        // IPv6: a candidate the shape allows, kept only when it is an address indeed - "10:01:02" and
        // "Foo::bar" are none (the candidate may not touch a letter, a digit or an underscore).
        $text = preg_replace_callback(
            '/(?<![\w:.%])(?:[0-9A-Fa-f]{0,4}:){2,7}[0-9A-Fa-f]{0,4}(?:%[0-9A-Za-z]+)?(?![\w:.])/u',
            static function (array $match): string {
                $address = explode('%', $match[0], 2)[0];

                return preg_match('/[0-9A-Fa-f]/', $address) === 1 && filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false ? '{ip}' : $match[0];
            },
            $text,
        );
        if ($text === null) {
            return self::UNREADABLE;
        }

        return preg_replace_callback('/(?<![A-Za-z0-9_+=-])[A-Za-z0-9_+=-]{24,}(?![A-Za-z0-9_+=-])/u', static fn(array $match): string => self::isToken($match[0]) ? '{token}' : $match[0], $text) ??
            self::UNREADABLE;
    }

    /**
     * A request's path with every segment that could be a secret or a person's
     * replaced by {…} (see the class); at most $limit characters.
     */
    public static function path(string $path, int $limit = 200): string
    {
        $path = Utf8::scrub(explode('#', explode('?', $path, 2)[0], 2)[0]);

        $segments = explode('/', $path);
        foreach ($segments as $index => $segment) {
            if ($segment === '') {
                continue;
            }
            $plain = rawurldecode($segment);
            if (strlen($plain) > 40 || self::segmentIsPrivate($plain)) {
                $segments[$index] = self::MARK;
            }
        }

        return Utf8::cut(implode('/', $segments), $limit);
    }

    /**
     * A text cut before it was redacted, without the run of non-space characters the cut went through (/\S*$/):
     * half a token, an address or a JWT is something no rule knows any more, and would stay as it is. Back to the
     * last white space byte by byte - one is always ASCII, so no character is split - in time that grows with
     * the run alone.
     */
    private static function withoutTheCutRun(string $text): string
    {
        return substr($text, 0, strlen($text) - strcspn(strrev($text), " \t\n\v\f\r"));
    }

    /** A run of a token's alphabet that mixes letters and digits, or is all hex: random, not a word. */
    private static function isToken(string $run): bool
    {
        if (ctype_xdigit(str_replace(['-', '_'], '', $run)) && preg_match('/\d/', $run) === 1) {
            return true;
        }

        return preg_match('/\d/', $run) === 1 && preg_match('/[A-Za-z]/', $run) === 1;
    }

    private static function segmentIsPrivate(string $segment): bool
    {
        if (str_contains($segment, '@') || filter_var($segment, FILTER_VALIDATE_IP) !== false) {
            return true;
        }
        if (preg_match('/^eyJ[A-Za-z0-9_-]+\./', $segment) === 1) {
            return true;
        }
        // 16 or more hex characters in a row (a backup's name, a hash), or a run of 20 or more of base64url
        // that has small letters, capitals and digits - random. A slug ("raiden-shogun-2024") is none.
        if (preg_match('/[0-9a-fA-F]{16,}/', $segment) === 1) {
            return true;
        }
        foreach (preg_split('/[^A-Za-z0-9_-]+/', $segment) ?: [] as $run) {
            if (strlen($run) >= 20 && preg_match('/\d/', $run) === 1 && preg_match('/[a-z]/', $run) === 1 && preg_match('/[A-Z]/', $run) === 1) {
                return true;
            }
        }

        return false;
    }
}
