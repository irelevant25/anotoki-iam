<?php

declare(strict_types=1);

namespace Anotoki\Lib\RichHtml;

use Anotoki\Lib\Support\Utf8;

/**
 * The allow-list of a text's HTML - what the family's editors write and its
 * pages show (@anotoki/lib/ui/rich-html: the same rules, the same steps, the same
 * output for the same text; a test holds both halves to the same cases,
 * backend/tests/RichHtml/cases.json). A site cleans every text it keeps with it,
 * whatever sent it: the page that shows it cleans it again, but what reaches the
 * database must not be able to do anything but look like text.
 *
 * ```php
 * $html = RichHtml::clean($body['text'] ?? '');            // or ['classes' => ['tag']]: genshin's guides
 * if (RichHtml::isBlank($html)) { $html = ''; }             // an emptied editor's <p></p> is no text
 * ```
 *
 * What is not named here does not survive. Elements: paragraphs and lines (p,
 * div, br), headings h2 to h4, lists (ul, ol, li), and the words' own formats (b,
 * strong, i, em, u, s, strike, mark, span, font, a). Elements whose content is no
 * text to read (scripts, styles, frames, forms, pictures, media, svg, math) go
 * with all they hold; every other is unwrapped to what it holds. Attributes: a
 * link's address - a web address with its host, or a mail address, nothing else
 * - and every link opening in a new tab with no way back to the page; a numbered
 * list's start; <font>'s size (1 to 7), face and colour; inline styles of five
 * properties alone - color, background-color, font-size, font-family,
 * margin-left - each value as those properties take it, and bounded; and the
 * class names a site names for its texts, none by default. Control characters
 * (but tab and line feed) and those that turn the text's direction are dropped.
 * Nothing here can run, load anything or move out of the text's box.
 *
 * Not PHP's DOM extension, which reads HTML another way than the browser (the
 * two ends would keep different texts), nor needed: nothing here but PCRE and
 * mbstring.
 */
final class RichHtml
{
    /** The elements a text keeps, with what they hold. */
    public const ELEMENTS = ['p', 'div', 'h2', 'h3', 'h4', 'ul', 'ol', 'li', 'br', 'b', 'strong', 'i', 'em', 'u', 's', 'strike', 'mark', 'span', 'font', 'a'];

    /** The elements a text loses with everything inside them: what they hold is no text to read. Every other is unwrapped. */
    public const DROPPED = [
        'script',
        'style',
        'template',
        'iframe',
        'frame',
        'frameset',
        'object',
        'embed',
        'applet',
        'svg',
        'math',
        'noscript',
        'noembed',
        'noframes',
        'textarea',
        'select',
        'option',
        'button',
        'input',
        'form',
        'head',
        'title',
        'meta',
        'link',
        'base',
        'img',
        'picture',
        'video',
        'audio',
        'source',
        'track',
        'canvas',
        'map',
        'area',
        'dialog',
        'xmp',
        'plaintext',
    ];

    /** The style properties a text keeps: what the editors' sizes, fonts, colours and indent write. */
    public const STYLES = ['color', 'background-color', 'font-size', 'font-family', 'margin-left'];

    /** A colour: #rgb, #rgba, #rrggbb, #rrggbbaa, rgb() and rgba() of numbers, or a colour's name. */
    private const COLOR = '~^(?:#[0-9a-f]{3,4}|#[0-9a-f]{6}|#[0-9a-f]{8}|rgba?\([0-9.,%\t\n\f\r /]{1,60}\)|[a-z]{3,20})$~i';

    /** The spaces round a value taken away: HTML's and CSS's own alone (space, tab, line feed, form feed, carriage return), as the Angular half trims it. */
    private const TRIMMED = " \t\n\f\r";

    /** A font's names: letters, digits, spaces, commas, hyphens and quotes - no parenthesis, no semicolon, no escape. */
    private const FONT = '~^[a-z0-9 ,\'"-]{1,100}$~i';

    private const FONT_SIZE_WORD = '~^(?:xx-small|x-small|small|medium|large|x-large|xx-large|xxx-large|smaller|larger)$~i';

    private const LENGTH = '~^(\d{1,4}(?:\.\d{1,4})?)(px|pt|em|rem|%)$~i';

    /** The largest of each unit a size may be: 72 px, and as much in the others. */
    private const FONT_SIZE_MAX = ['px' => 72, 'pt' => 54, 'em' => 4.5, 'rem' => 4.5, '%' => 450];

    /** The deepest an indent may be: 400 px, and as much in em. */
    private const MARGIN_MAX = ['px' => 400, 'em' => 25, 'rem' => 25];

    /** Characters a text never keeps: controls but tab and line feed, and those that turn the text's direction. */
    private const CONTROLS = '/[\x{0000}-\x{0008}\x{000B}-\x{001F}\x{007F}-\x{009F}\x{202A}-\x{202E}\x{2066}-\x{2069}]/u';

    /**
     * What the browser counts as a space (String.prototype.trim(), JavaScript's \s), character for character - so
     * both ends find the same texts blank.
     */
    private const SPACE = '[\t\n\x0B\f\r \x{A0}\x{1680}\x{2000}-\x{200A}\x{2028}\x{2029}\x{202F}\x{205F}\x{3000}\x{FEFF}]';

    private const BLOCKS = ['p', 'div', 'h2', 'h3', 'h4', 'ul', 'ol', 'li'];

    /**
     * A text's HTML through the allow-list, as HTML again - the editors' own text comes out as it went in. Bytes that
     * are no UTF-8 are read as "?" first.
     *
     * @param array{classes?: list<string>} $options the class names a site's texts may carry (genshin's "tag")
     */
    public static function clean(string $html, array $options = []): string
    {
        return self::write(self::cleanNodes(Parser::parse(Utf8::scrub($html)), $options['classes'] ?? []));
    }

    /**
     * A text's words without its markup, one space between them: for a summary, a list, a search, a CSV - what a
     * reader of it reads (what the allow-list drops is no words of it).
     */
    public static function text(string $html): string
    {
        $words = self::words(self::cleanNodes(Parser::parse(Utf8::scrub($html)), []));
        $spaced = (string) preg_replace('/' . self::SPACE . '+/u', ' ', $words);

        return (string) preg_replace('/^' . self::SPACE . '+|' . self::SPACE . '+$/u', '', $spaced);
    }

    /** Whether a text has no words at all - what an emptied editor leaves behind (<p></p>, <p><br></p>). */
    public static function isBlank(string $html): bool
    {
        return self::text($html) === '';
    }

    /**
     * Where a link of a text may point: a web address with its host (http:, https:) or a mail address (mailto:, with
     * an @), at most 2000 characters, with no space, control, quote, angle bracket, backtick or backslash in it - or
     * null. Never javascript:, data:, vbscript:, file:, an address of the site's own, nor "//host" with no scheme.
     */
    public static function linkAddress(string $address): ?string
    {
        $href = (string) preg_replace('/^[\t\n\f\r ]+|[\t\n\f\r ]+$/', '', Utf8::scrub($address));
        if (mb_strlen($href, 'UTF-8') > 2000 || preg_match('/[\x{0000}-\x{0020}\x{007F}-\x{00A0}"\'<>`\\\\]/u', $href) === 1) {
            return null;
        }

        return preg_match('~^(?:https?://[^/?#]+|mailto:[^?#]+@)~i', $href) === 1 ? $href : null;
    }

    /**
     * A tree through the allow-list: what is not named in it is dropped with its content, or unwrapped to it.
     *
     * @param list<string|RichElement> $nodes
     * @param list<string> $classes
     * @return list<string|RichElement>
     */
    public static function cleanNodes(array $nodes, array $classes = [], bool $inLink = false): array
    {
        $clean = [];
        foreach ($nodes as $node) {
            if (is_string($node)) {
                $text = (string) preg_replace(self::CONTROLS, '', $node);
                if ($text !== '') {
                    $clean[] = $text;
                }
                continue;
            }
            if (in_array($node->tag, self::DROPPED, true)) {
                continue;
            }
            // A link inside a link, or one to an address a text may not point at, is its words alone.
            $href = $node->tag === 'a' ? self::linkAddress($node->attribute('href') ?? '') : null;
            if (!in_array($node->tag, self::ELEMENTS, true) || ($node->tag === 'a' && ($href === null || $inLink))) {
                array_push($clean, ...self::cleanNodes($node->children, $classes, $inLink));
                continue;
            }
            $clean[] = new RichElement($node->tag, self::attributes($node, $classes, $href), $node->tag === 'br' ? [] : self::cleanNodes($node->children, $classes, $inLink || $node->tag === 'a'));
        }

        return $clean;
    }

    /**
     * A tree as HTML: every element closed, every value quoted, every word escaped.
     *
     * @param list<string|RichElement> $nodes
     */
    public static function write(array $nodes): string
    {
        $html = '';
        foreach ($nodes as $node) {
            if (is_string($node)) {
                $html .= strtr($node, ['&' => '&amp;', '<' => '&lt;', '>' => '&gt;', "\u{00A0}" => '&nbsp;']);
                continue;
            }
            $html .= '<' . $node->tag;
            foreach ($node->attrs as [$name, $value]) {
                $html .= ' ' . $name . '="' . strtr($value, ['&' => '&amp;', '"' => '&quot;', '<' => '&lt;', '>' => '&gt;', "\u{00A0}" => '&nbsp;']) . '"';
            }
            $html .= '>';
            if ($node->tag !== 'br') {
                $html .= self::write($node->children) . '</' . $node->tag . '>';
            }
        }

        return $html;
    }

    /**
     * The attributes an element of the allow-list keeps, in the allow-list's order.
     *
     * @param list<string> $classes
     * @return list<array{0: string, 1: string}>
     */
    private static function attributes(RichElement $element, array $classes, ?string $href): array
    {
        $kept = [];
        $tag = $element->tag;
        if ($tag === 'br') {
            return $kept;
        }
        if ($tag === 'a' && $href !== null) {
            array_push($kept, ['target', '_blank'], ['rel', 'noopener noreferrer'], ['href', $href]);
        }
        if ($tag === 'ol') {
            $start = trim($element->attribute('start') ?? '', self::TRIMMED);
            if (preg_match('/^\d{1,6}$/', $start) === 1 && (int) $start > 1 && (int) $start <= 100000) {
                $kept[] = ['start', (string) (int) $start];
            }
        }
        if ($tag === 'font') {
            $size = trim($element->attribute('size') ?? '', self::TRIMMED);
            $face = trim($element->attribute('face') ?? '', self::TRIMMED);
            $color = trim($element->attribute('color') ?? '', self::TRIMMED);
            if (preg_match('/^[1-7]$/', $size) === 1) {
                $kept[] = ['size', $size];
            }
            if (preg_match(self::FONT, $face) === 1) {
                $kept[] = ['face', $face];
            }
            if (preg_match(self::COLOR, $color) === 1) {
                $kept[] = ['color', $color];
            }
        }
        $names = [];
        foreach (preg_split('/[\t\n\f\r ]+/', $element->attribute('class') ?? '') ?: [] as $name) {
            if ($name !== '' && in_array($name, $classes, true) && !in_array($name, $names, true)) {
                $names[] = $name;
            }
        }
        if ($names !== []) {
            $kept[] = ['class', implode(' ', $names)];
        }
        $style = self::style($element->attribute('style') ?? '');
        if ($style !== '') {
            $kept[] = ['style', $style];
        }

        return $kept;
    }

    /** A style attribute's declarations the allow-list keeps, written as a browser writes them: "color: red; font-size: 12px;". */
    private static function style(string $style): string
    {
        $kept = [];
        foreach (explode(';', $style) as $declaration) {
            $colon = strpos($declaration, ':');
            if ($colon === false) {
                continue;
            }
            $property = strtolower(trim(substr($declaration, 0, $colon), self::TRIMMED));
            $value = self::styleValue($property, trim(substr($declaration, $colon + 1), self::TRIMMED));
            if ($value !== null && in_array($property, self::STYLES, true)) {
                // The last of a property is the one in force, as in CSS; in the place of its first.
                $kept[$property] = $value;
            }
        }
        $written = [];
        foreach ($kept as $property => $value) {
            $written[] = $property . ': ' . $value . ';';
        }

        return implode(' ', $written);
    }

    /** A style's value as its property takes it, or null where it does not. */
    private static function styleValue(string $property, string $value): ?string
    {
        switch ($property) {
            case 'color':
            case 'background-color':
                return preg_match(self::COLOR, $value) === 1 ? $value : null;
            case 'font-family':
                return preg_match(self::FONT, $value) === 1 ? $value : null;
            case 'font-size':
                if (preg_match(self::FONT_SIZE_WORD, $value) === 1) {
                    return $value;
                }
                if (preg_match(self::LENGTH, $value, $length) !== 1) {
                    return null;
                }
                $size = (float) $length[1];

                return $size > 0 && $size <= self::FONT_SIZE_MAX[strtolower($length[2])] ? $value : null;
            case 'margin-left':
                if ($value === '0') {
                    return $value;
                }
                if (preg_match(self::LENGTH, $value, $length) !== 1) {
                    return null;
                }
                $max = self::MARGIN_MAX[strtolower($length[2])] ?? null;

                return $max !== null && (float) $length[1] <= $max ? $value : null;
            default:
                return null;
        }
    }

    /**
     * The words of a tree: a space for each line break and after each block.
     *
     * @param list<string|RichElement> $nodes
     */
    private static function words(array $nodes): string
    {
        $words = '';
        foreach ($nodes as $node) {
            if (is_string($node)) {
                $words .= $node;
            } elseif ($node->tag === 'br') {
                $words .= ' ';
            } else {
                $words .= self::words($node->children) . (in_array($node->tag, self::BLOCKS, true) ? ' ' : '');
            }
        }

        return $words;
    }
}
