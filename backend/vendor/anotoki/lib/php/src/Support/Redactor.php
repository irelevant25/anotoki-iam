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
 * skipped because a regular expression could not read it.
 */
final class Redactor
{
    public const MARK = '{…}';

    /** What text() reads of a message at most: more than any line keeps, and no rule takes long on it. */
    public const TEXT_LIMIT = 4096;

    /** What a message reads as when a rule could not be run on it: never the text unredacted. */
    public const UNREADABLE = '{a message the redactor could not read}';

    /**
     * A message - its first 4,096 characters - with the personal data and the
     * secrets taken out (see the class). Should a rule fail (PCRE's limits), the
     * message is not given at all.
     */
    public static function text(string $text): string
    {
        $text = Utf8::prefix($text, self::TEXT_LIMIT);

        $rules = [
            // PostgreSQL's detail lines, from the first to the end: they quote rows and values.
            '/(?:^|\R)[ \t]*(?:DETAIL|HINT|CONTEXT|WHERE|QUERY|STATEMENT|LINE \d+):.*$/suD' => '',
            '/\s(?:DETAIL|HINT|CONTEXT):\s.*$/suD' => '',
            // A value echoed back at the end of a line: 'invalid input syntax for type uuid: "..."'.
            '/(:\s+)"(?:[^"\\\\\n]++|\\\\.|"")*+"(?=[ \t]*(?:$|\R))/mu' => '$1"' . self::MARK . '"',
            '/\b(Bearer|Basic)\s+[A-Za-z0-9._~+\/=-]+/iu' => '$1 ' . self::MARK,
            '/\beyJ[A-Za-z0-9_-]{5,}\.[A-Za-z0-9_-]{5,}\.[A-Za-z0-9_-]*/u' => '{token}',
            // name=value, name: value, and the same quoted - JSON's "password":"…", PHP's 'pass' => '…'.
            '/\b(pass(?:word|wd)?|pwd|secret|client_secret|token|access_token|refresh_token|id_token|api[_-]?key|access[_-]?key|authorization|cookie|session(?:[_-]?id)?|sid|ticket|code_verifier|signature|sig)\b(["\']?\s*(?:=>|[=:])\s*)("(?:[^"\\\\]|\\\\.)*"|\'(?:[^\'\\\\]|\\\\.)*\'|[^\s&,;"\']+)/iu' => '$1$2' . self::MARK,
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

                return preg_match('/[0-9A-Fa-f]/', $address) === 1 && filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false
                    ? '{ip}'
                    : $match[0];
            },
            $text
        );
        if ($text === null) {
            return self::UNREADABLE;
        }

        return preg_replace_callback(
            '/(?<![A-Za-z0-9_+=-])[A-Za-z0-9_+=-]{24,}(?![A-Za-z0-9_+=-])/u',
            static fn (array $match): string => self::isToken($match[0]) ? '{token}' : $match[0],
            $text
        ) ?? self::UNREADABLE;
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
