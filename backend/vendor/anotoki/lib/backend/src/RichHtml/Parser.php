<?php

declare(strict_types=1);

namespace Anotoki\Lib\RichHtml;

/**
 * A text's HTML read into a tree, step for step as the Angular half reads it
 * (frontend/projects/lib/ui/rich-html/src/parse.ts) - so that both ends make the
 * same tree of the same text, and the server keeps what the page shows. A test
 * holds the two to the same cases (backend/tests/RichHtml/cases.json).
 *
 * Not PHP's DOM: libxml reads HTML its own way (an older one than the
 * browser's), and the two ends would disagree on the text they clean. What it
 * does of HTML's own rules: tags and their attributes (quoted or not, the first
 * of a name kept, names in lower case), character references (numeric ones, and
 * the named ones of ENTITIES), comments and declarations dropped, the elements
 * whose content is never markup (script, style, textarea, ...) skipped whole to
 * their end, void elements (br, img, ...) closed at once, a paragraph closed by a
 * block that starts in it, a list item by the next, a heading by the next, an end
 * tag closing what it names unless a block stands between. Nothing of what it
 * reads is kept but through the allow-list (RichHtml).
 *
 * A tree is a list of nodes: a string is a run of words, a RichElement an
 * element.
 */
final class Parser
{
    /**
     * The named character references read: the five every browser writes and those a person writes by hand. A name
     * not here stays as it was written - the same table as entities.ts.
     */
    public const ENTITIES = [
        'amp' => '&',
        'lt' => '<',
        'gt' => '>',
        'quot' => '"',
        'apos' => "'",
        'nbsp' => "\u{00A0}",
        'shy' => "\u{00AD}",
        'ensp' => "\u{2002}",
        'emsp' => "\u{2003}",
        'thinsp' => "\u{2009}",
        'zwnj' => "\u{200C}",
        'zwj' => "\u{200D}",
        'ndash' => "\u{2013}",
        'mdash' => "\u{2014}",
        'lsquo' => "\u{2018}",
        'rsquo' => "\u{2019}",
        'sbquo' => "\u{201A}",
        'ldquo' => "\u{201C}",
        'rdquo' => "\u{201D}",
        'bdquo' => "\u{201E}",
        'laquo' => "\u{00AB}",
        'raquo' => "\u{00BB}",
        'lsaquo' => "\u{2039}",
        'rsaquo' => "\u{203A}",
        'hellip' => "\u{2026}",
        'bull' => "\u{2022}",
        'middot' => "\u{00B7}",
        'prime' => "\u{2032}",
        'Prime' => "\u{2033}",
        'deg' => "\u{00B0}",
        'plusmn' => "\u{00B1}",
        'times' => "\u{00D7}",
        'divide' => "\u{00F7}",
        'minus' => "\u{2212}",
        'frac12' => "\u{00BD}",
        'frac14' => "\u{00BC}",
        'frac34' => "\u{00BE}",
        'sup2' => "\u{00B2}",
        'sup3' => "\u{00B3}",
        'micro' => "\u{00B5}",
        'para' => "\u{00B6}",
        'sect' => "\u{00A7}",
        'copy' => "\u{00A9}",
        'reg' => "\u{00AE}",
        'trade' => "\u{2122}",
        'euro' => "\u{20AC}",
        'cent' => "\u{00A2}",
        'pound' => "\u{00A3}",
        'yen' => "\u{00A5}",
        'larr' => "\u{2190}",
        'uarr' => "\u{2191}",
        'rarr' => "\u{2192}",
        'darr' => "\u{2193}",
        'harr' => "\u{2194}",
        'hearts' => "\u{2665}",
        'check' => "\u{2713}",
        'cross' => "\u{2717}",
    ];

    /** Elements with no content and no end tag. */
    private const VOID = ['area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input', 'link', 'meta', 'param', 'source', 'track', 'wbr'];

    /** Elements whose content is never markup: skipped whole, to their end tag (or to the end of the text). */
    private const RAW = ['script', 'style', 'textarea', 'title', 'xmp', 'iframe', 'noembed', 'noframes', 'noscript', 'plaintext'];

    /** Blocks: a paragraph open where one starts is closed first. */
    private const CLOSES_P = [
        'address',
        'article',
        'aside',
        'blockquote',
        'center',
        'details',
        'dialog',
        'dir',
        'div',
        'dl',
        'dd',
        'dt',
        'fieldset',
        'figcaption',
        'figure',
        'footer',
        'form',
        'h1',
        'h2',
        'h3',
        'h4',
        'h5',
        'h6',
        'header',
        'hgroup',
        'hr',
        'li',
        'main',
        'menu',
        'nav',
        'ol',
        'p',
        'pre',
        'section',
        'summary',
        'table',
        'ul',
    ];

    /** Where a search for an open element stops: it never reaches out of a table's cell or an object. */
    private const SCOPE = ['table', 'td', 'th', 'caption', 'template', 'object', 'applet', 'marquee', 'button'];

    private const HEADINGS = ['h1', 'h2', 'h3', 'h4', 'h5', 'h6'];

    /** The C1 references HTML reads as the windows-1252 characters of those numbers (&#150; is an en dash). */
    private const WINDOWS_1252 = [
        0x80 => 0x20ac,
        0x82 => 0x201a,
        0x83 => 0x0192,
        0x84 => 0x201e,
        0x85 => 0x2026,
        0x86 => 0x2020,
        0x87 => 0x2021,
        0x88 => 0x02c6,
        0x89 => 0x2030,
        0x8a => 0x0160,
        0x8b => 0x2039,
        0x8c => 0x0152,
        0x8e => 0x017d,
        0x91 => 0x2018,
        0x92 => 0x2019,
        0x93 => 0x201c,
        0x94 => 0x201d,
        0x95 => 0x2022,
        0x96 => 0x2013,
        0x97 => 0x2014,
        0x98 => 0x02dc,
        0x99 => 0x2122,
        0x9a => 0x0161,
        0x9b => 0x203a,
        0x9c => 0x0153,
        0x9e => 0x017e,
        0x9f => 0x0178,
    ];

    /** HTML's characters of white space between attributes. */
    private const SPACE = " \t\n\f\r";

    /** @var list<RichElement> The open elements, the root first. */
    private array $open;

    private string $words = '';

    private function __construct()
    {
        $this->open = [new RichElement('', [])];
    }

    /**
     * A text's HTML as a tree: its line ends as HTML reads them (\n), every element closed.
     *
     * @return list<string|RichElement>
     */
    public static function parse(string $html): array
    {
        $parser = new self();
        $source = str_replace(["\r\n", "\r"], "\n", $html);
        $length = strlen($source);
        $at = 0;
        while ($at < $length) {
            $lt = strpos($source, '<', $at);
            if ($lt === false) {
                $parser->words .= substr($source, $at);
                break;
            }
            $parser->words .= substr($source, $at, $lt - $at);
            $at = $lt;
            $next = $source[$at + 1] ?? '';

            if (substr($source, $at, 4) === '<!--') {
                // A comment: to its end, or to the text's end.
                $end = strpos($source, '-->', $at + 4);
                $at = $end === false ? $length : $end + 3;
                continue;
            }
            if ($next === '!' || $next === '?') {
                // A declaration, a CDATA section, a processing instruction: HTML reads them as comments, to the next '>'.
                $end = strpos($source, '>', $at + 2);
                $at = $end === false ? $length : $end + 1;
                continue;
            }
            if ($next === '/') {
                $after = $source[$at + 2] ?? '';
                if (self::isLetter($after)) {
                    $tag = self::readTag($source, $at + 2);
                    if ($tag === null) {
                        // An end tag the text ends inside: nothing.
                        $at = $length;
                        continue;
                    }
                    $parser->flush();
                    $parser->endTag($tag[0]);
                    $at = $tag[2];
                    continue;
                }
                // "</>" is nothing; "</ ...>" a comment, to the next '>'.
                $end = $after === '>' ? $at + 2 : strpos($source, '>', $at + 2);
                $at = $end === false ? $length : $end + 1;
                continue;
            }
            if (!self::isLetter($next)) {
                // A '<' that starts no tag is a word's.
                $parser->words .= '<';
                $at++;
                continue;
            }
            $tag = self::readTag($source, $at + 1);
            if ($tag === null) {
                // A start tag the text ends inside: nothing, as HTML drops it.
                $at = $length;
                continue;
            }
            $parser->flush();
            $at = $tag[2];
            if (in_array($tag[0], self::RAW, true)) {
                // Its content is no markup and is never kept: skipped to its end tag - all of the rest for plaintext.
                $found = $tag[0] !== 'plaintext' && preg_match('~</' . $tag[0] . '[\t\n\f\r />]~i', $source, $match, PREG_OFFSET_CAPTURE, $at) === 1;
                if (!$found) {
                    $at = $length;
                    continue;
                }
                $close = strpos($source, '>', $match[0][1]);
                $at = $close === false ? $length : $close + 1;
                continue;
            }
            $parser->startTag($tag[0], $tag[1]);
        }
        $parser->flush();

        return $parser->open[0]->children;
    }

    /** A text's character references, as HTML reads them: numeric ones with or without their semicolon, named ones with it. */
    public static function decodeReferences(string $text): string
    {
        if (!str_contains($text, '&')) {
            return $text;
        }

        return (string) preg_replace_callback(
            '/&(?:#([0-9]{1,8})|#[xX]([0-9a-fA-F]{1,8})|([A-Za-z][A-Za-z0-9]{1,31}));?/',
            static function (array $match): string {
                $whole = $match[0];
                if (isset($match[3])) {
                    return str_ends_with($whole, ';') && array_key_exists($match[3], self::ENTITIES) ? self::ENTITIES[$match[3]] : $whole;
                }
                $code = isset($match[1]) ? (int) $match[1] : (int) hexdec((string) $match[2]);
                $code = self::WINDOWS_1252[$code] ?? $code;
                if ($code === 0 || $code > 0x10ffff || ($code >= 0xd800 && $code <= 0xdfff)) {
                    return "\u{FFFD}";
                }

                return (string) mb_chr($code, 'UTF-8');
            },
            $text,
            -1,
            $count,
            PREG_UNMATCHED_AS_NULL,
        );
    }

    /** The words read since the last tag, as a node of the open element. */
    private function flush(): void
    {
        if ($this->words !== '') {
            $this->current()->children[] = self::decodeReferences($this->words);
            $this->words = '';
        }
    }

    private function current(): RichElement
    {
        return $this->open[count($this->open) - 1];
    }

    /** Closes the open elements from the one at $index of the stack up: it and all it holds. */
    private function closeFrom(int $index): void
    {
        $this->open = array_slice($this->open, 0, max(1, $index));
    }

    /**
     * Where the nearest open element of a tag is in the stack - never past an element $stops names; -1 where none is.
     *
     * @param callable(string): bool $stops
     */
    private function openAt(string $tag, callable $stops): int
    {
        for ($index = count($this->open) - 1; $index > 0; $index--) {
            if ($this->open[$index]->tag === $tag) {
                return $index;
            }
            if ($stops($this->open[$index]->tag)) {
                return -1;
            }
        }

        return -1;
    }

    private static function inScope(string $tag): bool
    {
        return in_array($tag, self::SCOPE, true);
    }

    /** Elements an inline end tag never closes through: HTML's "special" ones a text may hold. */
    private static function isSpecial(string $tag): bool
    {
        return in_array($tag, self::CLOSES_P, true) || in_array($tag, self::SCOPE, true) || in_array($tag, ['tbody', 'thead', 'tfoot', 'tr'], true);
    }

    /** @param list<array{0: string, 1: string}> $attrs */
    private function startTag(string $name, array $attrs): void
    {
        if (in_array($name, self::CLOSES_P, true)) {
            $p = $this->openAt('p', self::inScope(...));
            if ($p !== -1) {
                $this->closeFrom($p);
            }
        }
        if ($name === 'li') {
            for ($index = count($this->open) - 1; $index > 0; $index--) {
                $tag = $this->open[$index]->tag;
                if ($tag === 'li') {
                    $this->closeFrom($index);
                    break;
                }
                if (self::isSpecial($tag) && $tag !== 'address' && $tag !== 'div' && $tag !== 'p') {
                    break;
                }
            }
        }
        if (in_array($name, self::HEADINGS, true) && in_array($this->current()->tag, self::HEADINGS, true)) {
            $this->closeFrom(count($this->open) - 1);
        }
        $element = new RichElement($name, $attrs);
        $this->current()->children[] = $element;
        if (!in_array($name, self::VOID, true)) {
            $this->open[] = $element;
        }
    }

    private function endTag(string $name): void
    {
        if ($name === 'br') {
            // "</br>" is read as a line break.
            $this->current()->children[] = new RichElement('br', []);

            return;
        }
        if (in_array($name, self::VOID, true)) {
            return;
        }
        if (in_array($name, self::HEADINGS, true)) {
            // "</h3>" closes whichever heading is open.
            for ($index = count($this->open) - 1; $index > 0 && !self::inScope($this->open[$index]->tag); $index--) {
                if (in_array($this->open[$index]->tag, self::HEADINGS, true)) {
                    $this->closeFrom($index);

                    return;
                }
            }

            return;
        }
        $index = self::isSpecial($name) ? $this->openAt($name, self::inScope(...)) : $this->openAt($name, self::isSpecial(...));
        if ($index !== -1) {
            $this->closeFrom($index);
        }
    }

    /**
     * A tag read from its name's first letter: [its name, its attributes, where it ends] - or null where the text ends
     * inside it.
     *
     * @return ?array{0: string, 1: list<array{0: string, 1: string}>, 2: int}
     */
    private static function readTag(string $source, int $from): ?array
    {
        $length = strlen($source);
        $at = $from;
        while ($at < $length && !self::isSpace($source[$at]) && $source[$at] !== '/' && $source[$at] !== '>') {
            $at++;
        }
        $name = self::lower(substr($source, $from, $at - $from));
        $attrs = [];
        $seen = [];
        while ($at < $length) {
            $char = $source[$at];
            if (self::isSpace($char) || $char === '/') {
                $at++;
                continue;
            }
            if ($char === '>') {
                return [$name, $attrs, $at + 1];
            }
            // An attribute's name: to a space, '/', '>' or '=' (an '=' first is a name's own).
            $start = $at;
            $at++;
            while ($at < $length && !self::isSpace($source[$at]) && $source[$at] !== '/' && $source[$at] !== '>' && $source[$at] !== '=') {
                $at++;
            }
            $attribute = self::lower(substr($source, $start, $at - $start));
            while ($at < $length && self::isSpace($source[$at])) {
                $at++;
            }
            $value = '';
            if (($source[$at] ?? '') === '=') {
                $at++;
                while ($at < $length && self::isSpace($source[$at])) {
                    $at++;
                }
                $quote = $source[$at] ?? '';
                if ($quote === '"' || $quote === "'") {
                    $close = strpos($source, $quote, $at + 1);
                    if ($close === false) {
                        return null;
                    }
                    $value = substr($source, $at + 1, $close - $at - 1);
                    $at = $close + 1;
                } else {
                    $begin = $at;
                    while ($at < $length && !self::isSpace($source[$at]) && $source[$at] !== '>') {
                        $at++;
                    }
                    $value = substr($source, $begin, $at - $begin);
                }
            }
            // The first of a name is the element's, as HTML keeps it.
            if (!in_array($attribute, $seen, true)) {
                $seen[] = $attribute;
                $attrs[] = [$attribute, self::decodeReferences($value)];
            }
        }

        return null;
    }

    private static function isSpace(string $char): bool
    {
        return $char !== '' && str_contains(self::SPACE, $char);
    }

    private static function isLetter(string $char): bool
    {
        return $char !== '' && (($char >= 'a' && $char <= 'z') || ($char >= 'A' && $char <= 'Z'));
    }

    /** A name in lower case - its ASCII letters alone, as HTML lowers a tag's or an attribute's name. */
    private static function lower(string $name): string
    {
        return strtr($name, 'ABCDEFGHIJKLMNOPQRSTUVWXYZ', 'abcdefghijklmnopqrstuvwxyz');
    }
}
