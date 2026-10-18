<?php

declare(strict_types=1);

namespace Anotoki\Lib\Migrations;

/**
 * Splits a migration file into its statements, so a failure can say which
 * statement of which file it was ("statement 3 of 9: ...").
 *
 * A semicolon ends a statement only outside everything that can contain one:
 * `--` comments (to the end of the line), `/* *\/` comments (not nested),
 * `'...'` strings (`''` escapes a quote; in an `E'...'` string a backslash
 * escapes the next character too), `"..."` identifiers (`""` escapes) and
 * dollar-quoted blocks `$tag$...$tag$` (the tag letters, digits and `_`, or
 * empty: `$$`) - which is how every function and DO block is written, so
 * getting one wrong sends half a function body as a statement of its own. A
 * quote or block left open runs to the end of the text, as PostgreSQL reads
 * it.
 *
 * Comments are left out of the statements; where one sat between two words
 * the words stay apart, and a comment on a line of its own takes the line with
 * it, so the first lines of a statement (which a failure quotes) are SQL.
 */
final class Splitter
{
    /** The characters that may start something other than plain SQL text. */
    private const SPECIAL = "-/'\"\$;";

    /**
     * @return list<string> the statements, trimmed, without comments; empty ones dropped
     */
    public static function split(string $sql): array
    {
        $statements = [];
        $current = '';
        $length = strlen($sql);
        $i = 0;

        while ($i < $length) {
            // Plain text up to the next character that might start something: taken whole.
            $run = strcspn($sql, self::SPECIAL, $i);
            if ($run > 0) {
                $current .= substr($sql, $i, $run);
                $i += $run;
                continue;
            }

            $character = $sql[$i];
            $next = $sql[$i + 1] ?? '';

            if ($character === '-' && $next === '-') {
                [$current, $i] = self::lineComment($sql, $i, $current);
                continue;
            }

            if ($character === '/' && $next === '*') {
                [$current, $i] = self::blockComment($sql, $i, $current);
                continue;
            }

            if ($character === "'") {
                $end = self::quoted($sql, $i, "'", self::isEscapeString($sql, $i));
                $current .= substr($sql, $i, $end - $i);
                $i = $end;
                continue;
            }

            if ($character === '"') {
                $end = self::quoted($sql, $i, '"', false);
                $current .= substr($sql, $i, $end - $i);
                $i = $end;
                continue;
            }

            if ($character === '$') {
                $end = self::dollarQuoted($sql, $i);
                if ($end !== null) {
                    $current .= substr($sql, $i, $end - $i);
                    $i = $end;
                    continue;
                }
            }

            if ($character === ';') {
                self::push($statements, $current);
                $current = '';
                $i++;
                continue;
            }

            // A '-', '/' or '$' that starts nothing: plain text after all.
            $current .= $character;
            $i++;
        }

        self::push($statements, $current);

        return $statements;
    }

    /** @param list<string> $statements */
    private static function push(array &$statements, string $statement): void
    {
        $statement = trim($statement);
        if ($statement !== '') {
            $statements[] = $statement;
        }
    }

    /**
     * A `--` comment at $start, to the end of its line.
     *
     * @return array{string, int} the statement so far, and where to read on
     */
    private static function lineComment(string $sql, int $start, string $current): array
    {
        $before = rtrim($current, " \t");
        $end = strpos($sql, "\n", $start + 2);

        if ($end === false) {
            return [$before, strlen($sql)];
        }

        // Alone on its line: the line goes. After SQL: the line break stays, between that SQL and what follows.
        return [$before, self::startsLine($before) ? $end + 1 : $end];
    }

    /**
     * A `/* *\/` comment at $start; one never closed runs to the end of the text.
     *
     * @return array{string, int} the statement so far, and where to read on
     */
    private static function blockComment(string $sql, int $start, string $current): array
    {
        $length = strlen($sql);
        $close = strpos($sql, '*/', $start + 2);
        $after = $close === false ? $length : $close + 2;

        // Alone on its line(s): the line goes, its line break with it.
        $before = rtrim($current, " \t");
        $rest = $after;
        while ($rest < $length && ($sql[$rest] === ' ' || $sql[$rest] === "\t" || $sql[$rest] === "\r")) {
            $rest++;
        }
        if (self::startsLine($before) && ($rest >= $length || $sql[$rest] === "\n")) {
            return [$before, min($rest + 1, $length)];
        }

        // Between two words a comment is a space: they stay two words.
        if ($before !== '' && !ctype_space(substr($before, -1)) && $after < $length && !ctype_space($sql[$after])) {
            $before .= ' ';
        }

        return [$before, $after];
    }

    /** Is the statement so far empty, or does it end with a line break - so what comes next starts a line? */
    private static function startsLine(string $before): bool
    {
        return $before === '' || str_ends_with($before, "\n") || trim($before) === '';
    }

    /**
     * The end (one past the closing quote) of a string or quoted identifier
     * opening at $start; the end of the text when it is never closed.
     */
    private static function quoted(string $sql, int $start, string $quote, bool $backslashEscapes): int
    {
        $length = strlen($sql);
        $i = $start + 1;

        while ($i < $length) {
            $character = $sql[$i];

            if ($backslashEscapes && $character === '\\') {
                $i += 2;
                continue;
            }

            if ($character === $quote) {
                if (($sql[$i + 1] ?? '') === $quote) {
                    $i += 2;
                    continue;
                }

                return $i + 1;
            }

            $i++;
        }

        return $length;
    }

    /** Is the quote at $start the opening of an `E'...'` string: an E or e just before it, which is no part of a longer word? */
    private static function isEscapeString(string $sql, int $start): bool
    {
        if ($start < 1 || ($sql[$start - 1] !== 'E' && $sql[$start - 1] !== 'e')) {
            return false;
        }

        return $start < 2 || !self::isWordCharacter($sql[$start - 2]);
    }

    /**
     * The end (one past the closing tag) of a dollar-quoted block opening at
     * $start - the end of the text when it is never closed - or null when the
     * `$` opens none: no tag follows, or it is part of a word (PostgreSQL lets
     * a name hold a `$`) or a parameter like `$1`.
     */
    private static function dollarQuoted(string $sql, int $start): ?int
    {
        if ($start > 0 && self::isWordCharacter($sql[$start - 1])) {
            return null;
        }

        $length = strlen($sql);
        $i = $start + 1;
        while ($i < $length && self::isTagCharacter($sql[$i])) {
            $i++;
        }

        if ($i >= $length || $sql[$i] !== '$') {
            return null;
        }

        $tag = substr($sql, $start, $i - $start + 1);
        $close = strpos($sql, $tag, $i + 1);

        return $close === false ? $length : $close + strlen($tag);
    }

    /** A letter, digit or `_` (bytes of a UTF-8 letter count as letters, as PostgreSQL counts them). */
    private static function isTagCharacter(string $character): bool
    {
        return ctype_alnum($character) || $character === '_' || ord($character) >= 0x80;
    }

    /** A character of a name: a tag character or `$`. */
    private static function isWordCharacter(string $character): bool
    {
        return self::isTagCharacter($character) || $character === '$';
    }
}
