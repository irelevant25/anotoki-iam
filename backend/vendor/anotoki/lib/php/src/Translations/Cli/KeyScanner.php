<?php

declare(strict_types=1);

namespace Anotoki\Lib\Translations\Cli;

use FilesystemIterator;
use InvalidArgumentException;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;

/**
 * Which keys a site's frontend asks for, against the keys its database has.
 *
 * The strings live in the database so they can be reworded in the admin
 * pages, which means nothing stops a template asking for a key no migration
 * ever wrote: the page shows the key itself, and the mistake is released. The
 * scan is what catches it - and the other way round, a key left in the
 * database that no page asks for any more.
 *
 * How a key is recognised in the frontend's sources (every .ts and .html under
 * the root, but what `exclude` leaves out):
 *
 *   - Its shape: a namespace ([a-z][A-Za-z0-9]*), then one or more segments
 *     ([A-Za-z0-9][A-Za-z0-9_-]*), joined by dots: home.title, aboutYou.age.18-24.
 *   - A quoted literal of that shape whose namespace the database has is a key
 *     the code uses; the namespace is what tells 'home.title' from a file's
 *     name. Comments do not count: they explain keys as often as they use them.
 *   - In a template, a double-quoted string right after = is an attribute's
 *     value - an expression, [text]="error.message" - and not a literal; the
 *     single-quoted literals inside it are ('common.close' | translate). A
 *     template literal with nothing put into it (`home.title`) is a literal
 *     like the quoted ones.
 *   - A literal L is answered by the key L, or by the plural family L.one,
 *     L.few, L.other (`pluralForms`): the code asks for a family by its name.
 *   - Where the literal stands right beside what asks for it - 'L' | translate
 *     in a template (`pipes`), .t('L') in TypeScript (`calls`) - how it is
 *     asked for must fit what the database has: a family asked for as one
 *     string, or one string as a family, shows the key though both "exist".
 *   - Keys put together at run time cannot be seen in the source. They are
 *     declared in `dynamicKeys` (dynamic-keys.ts: 'prefix.': ['tail', ...]),
 *     read as text; each must exist, and a key under a declared prefix that is
 *     not listed is unused like any other. What the source does show is the
 *     putting together - a literal that ends in a dot ('take.refused.' + kind),
 *     a template literal that goes on with ${...} - and its prefix must be one
 *     that is declared, or the scan fails.
 *   - innerHTML given a translated string fails the scan (`innerHtml`
 *     'translated'), or innerHTML anywhere (`innerHtml` 'anywhere', the IAM's):
 *     a string is text and is never drawn as HTML.
 *   - The namespaces in `ignoreNamespaces` are never looked for and never
 *     counted unused: the library's own (`anotoki`, whose keys its Angular half
 *     asks for from node_modules), and a site's server namespaces (the IAM's
 *     `mail`).
 */
final class KeyScanner
{
    /** A key: namespace.segment[.segment...]. */
    public const KEY_SHAPE = '[a-z][A-Za-z0-9]*(?:\.[A-Za-z0-9][A-Za-z0-9_-]*)+';

    /** What keys are put together from: namespace.[segment.]* - a key's beginning, up to and with a dot. */
    private const PREFIX_SHAPE = '[a-z][A-Za-z0-9]*\.(?:[A-Za-z0-9][A-Za-z0-9_-]*\.)*';

    private readonly string $root;

    /** @var list<string> */
    private readonly array $exclude;

    /** @var list<string> */
    private readonly array $ignoreNamespaces;

    /**
     * @param string                $root              the frontend's sources (src/app)
     * @param list<string>          $exclude           globs, relative to the root, of files the scan does not read
     *                                                 ('**' any folders, '*' anything but a '/'); the specs always
     * @param ?string               $dynamicKeys       where the keys put together at run time are declared, relative
     *                                                 to the root (core/i18n/dynamic-keys.ts), or null for none
     * @param list<string>          $ignoreNamespaces  namespaces never looked for, never unused - anotoki always
     * @param array<string, string> $pipes             a template's pipes: name => 'one' | 'family'
     * @param array<string, string> $calls             TypeScript's calls .name('key'): name => 'one' | 'family'
     * @param list<string>          $pluralForms       the forms of a plural family
     * @param string                $innerHtml         'translated': innerHTML given a translated string fails;
     *                                                 'anywhere': innerHTML anywhere in a source fails
     */
    public function __construct(
        string $root,
        array $exclude = [],
        private readonly ?string $dynamicKeys = null,
        array $ignoreNamespaces = [],
        private readonly array $pipes = ['translate' => 'one', 'translatePlural' => 'family'],
        private readonly array $calls = ['t' => 'one', 'around' => 'one', 'has' => 'one', 'plural' => 'family', 'aroundPlural' => 'family'],
        private readonly array $pluralForms = ['one', 'few', 'other'],
        private readonly string $innerHtml = 'translated',
    ) {
        $this->root = rtrim(str_replace('\\', '/', $root), '/');
        $this->exclude = array_values(array_unique(['**/*.spec.ts', '*.spec.ts', ...$exclude]));
        $this->ignoreNamespaces = array_values(array_unique(['anotoki', ...$ignoreNamespaces]));

        foreach ([...array_values($pipes), ...array_values($calls)] as $kind) {
            if ($kind !== 'one' && $kind !== 'family') {
                throw new InvalidArgumentException('A pipe or a call asks for \'one\' string or a \'family\'.');
            }
        }
        foreach ([...array_keys($pipes), ...array_keys($calls)] as $name) {
            if (preg_match('/^[A-Za-z_$][A-Za-z0-9_$]*$/D', (string) $name) !== 1) {
                throw new InvalidArgumentException("\"$name\" is not a pipe's or a method's name.");
            }
        }
        if ($innerHtml !== 'translated' && $innerHtml !== 'anywhere') {
            throw new InvalidArgumentException('innerHtml is \'translated\' or \'anywhere\'.');
        }
    }

    public function root(): string
    {
        return $this->root;
    }

    public static function shapeOk(string $key): bool
    {
        return preg_match('/^' . self::KEY_SHAPE . '$/D', $key) === 1;
    }

    /** A key's namespace: what stands before its first dot. */
    public static function namespaceOf(string $key): string
    {
        return explode('.', $key, 2)[0];
    }

    /**
     * The frontend's keys against the database's - the ignored namespaces left out.
     *
     *   used      database keys the frontend asks for, each with where (file:line)
     *   missing   keys it asks for that the database does not have - the page would show the key
     *   unused    database keys nothing asks for
     *   problems  what else fails the scan, each with its place: a key asked for the wrong way, a key
     *             put together from a prefix that is not declared, innerHTML, a list of declared tails
     *             that cannot be read
     *
     * @param list<string> $databaseKeys every key the database has
     * @return array{used: array<string, list<string>>, missing: array<string, list<string>>, unused: list<string>, problems: list<string>}
     */
    public function scan(array $databaseKeys): array
    {
        if (!is_dir($this->root)) {
            throw new RuntimeException("There is no frontend to read the keys from at {$this->root}.");
        }

        $pageKeys = array_values(array_filter(
            array_map('strval', $databaseKeys),
            fn (string $key): bool => !in_array(self::namespaceOf($key), $this->ignoreNamespaces, true)
        ));
        $has = array_fill_keys($pageKeys, true);
        $namespaces = [];
        foreach ($pageKeys as $key) {
            $namespaces[self::namespaceOf($key)] = true;
        }

        $hasFamily = function (string $literal) use ($has): bool {
            foreach ($this->pluralForms as $form) {
                if (isset($has["$literal.$form"])) {
                    return true;
                }
            }

            return false;
        };

        // The keys put together at run time, first: the files are held to the prefixes declared there.
        $dynamic = ['prefixes' => [], 'keys' => [], 'problems' => []];
        if ($this->dynamicKeys !== null && is_file("{$this->root}/{$this->dynamicKeys}")) {
            $dynamic = self::dynamic((string) file_get_contents("{$this->root}/{$this->dynamicKeys}"));
        }

        $asked = [];
        $problems = [];
        foreach ($this->sourceFiles() as $relative) {
            // The file of run-time keys is read as what it is, above: its strings are prefixes and tails,
            // not keys. A tail has dots of its own there ('meet.title' under 'path.step.'), and read as a
            // literal it would be taken for a key of the namespace it happens to begin with.
            if ($relative === $this->dynamicKeys) {
                continue;
            }
            $html = str_ends_with($relative, '.html');
            $text = self::sourceText((string) file_get_contents("{$this->root}/$relative"), $html);

            foreach ($this->innerHtmlProblems($text, $html) as $line => $problem) {
                $problems[] = "$relative:$line: $problem";
            }
            foreach (self::literals($text, $html) as $literal => $lines) {
                if (isset($namespaces[self::namespaceOf((string) $literal)])) {
                    $asked[(string) $literal][] = "$relative:" . $lines[0];
                }
            }
            // Asked for in the way the database has it? The other way round both
            // "exist" and the page still shows the key.
            foreach ($this->asks($text, $html) as $ask) {
                $literal = $ask['literal'];
                if (!isset($namespaces[self::namespaceOf($literal)])) {
                    continue;
                }
                $forms = implode(', .', $this->pluralForms);
                if ($ask['family'] && !$hasFamily($literal)) {
                    $problems[] = "$relative:{$ask['line']}: '$literal' is asked for as a plural, and there is no $literal.$forms - the page would show the key";
                } elseif (!$ask['family'] && !isset($has[$literal]) && $hasFamily($literal)) {
                    $problems[] = "$relative:{$ask['line']}: '$literal' is a plural family and is asked for as one string - the page would show the key";
                }
            }
            // A key put together here from a prefix nobody declared: its keys could
            // be missing from the database and nothing would say so.
            foreach (self::built($text, $html) as $built) {
                if (isset($namespaces[self::namespaceOf($built['prefix'])]) && !isset($dynamic['prefixes'][$built['prefix']])) {
                    $problems[] = "$relative:{$built['line']}: a key is put together from '{$built['prefix']}', which "
                        . ($this->dynamicKeys ?? 'no file of dynamic keys') . ' does not declare';
                }
            }
        }

        foreach ($dynamic['problems'] as $problem) {
            $problems[] = "{$this->dynamicKeys}: $problem";
        }
        foreach ($dynamic['keys'] as $key) {
            $asked[$key][] = (string) $this->dynamicKeys;
        }

        $used = [];
        $missing = [];
        foreach ($asked as $literal => $where) {
            $literal = (string) $literal;
            $family = array_map(static fn (string $form): string => "$literal.$form", $this->pluralForms);
            $forms = array_values(array_filter($family, static fn (string $key): bool => isset($has[$key])));

            if (isset($has[$literal])) {
                $used[$literal] = array_merge($used[$literal] ?? [], $where);
            }
            if ($forms !== []) {
                // Asked for by the family's name: every form of it is used - and one
                // that is not there is missing, unless the name itself is a key.
                foreach ($family as $key) {
                    if (isset($has[$key])) {
                        $used[$key] = array_merge($used[$key] ?? [], $where);
                    } elseif (!isset($has[$literal])) {
                        $missing[$key] = array_merge($missing[$key] ?? [], $where);
                    }
                }
            } elseif (!isset($has[$literal])) {
                $missing[$literal] = array_merge($missing[$literal] ?? [], $where);
            }
        }

        ksort($used, SORT_STRING);
        ksort($missing, SORT_STRING);
        $unused = array_values(array_filter($pageKeys, static fn (string $key): bool => !isset($used[$key])));
        sort($unused, SORT_STRING);

        return ['used' => $used, 'missing' => $missing, 'unused' => $unused, 'problems' => $problems];
    }

    /**
     * The files the scan reads, relative to the root (forward slashes), sorted.
     *
     * @return list<string>
     */
    public function sourceFiles(): array
    {
        $patterns = array_map(self::globPattern(...), $this->exclude);
        $files = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->root, FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if (!$file->isFile()) {
                continue;
            }
            $relative = substr(str_replace('\\', '/', $file->getPathname()), strlen($this->root) + 1);
            if (preg_match('/\.(ts|html)$/D', $relative) !== 1) {
                continue;
            }
            foreach ($patterns as $pattern) {
                if (preg_match($pattern, $relative) === 1) {
                    continue 2;
                }
            }
            $files[] = $relative;
        }
        sort($files, SORT_STRING);

        return $files;
    }

    /**
     * A source file without its comments, line for line (what is taken out leaves
     * its line breaks, so a place is still the line it was on). Line endings
     * become \n. In TypeScript a string is passed over first, so the // of
     * 'http://...' and the /* of 'image/*' stay what they are.
     */
    public static function sourceText(string $source, bool $html): string
    {
        $source = str_replace(["\r\n", "\r"], "\n", $source);
        $blank = static fn (string $text): string => str_repeat("\n", substr_count($text, "\n"));

        if ($html) {
            $text = preg_replace_callback('/<!--.*?-->/s', static fn (array $m): string => $blank($m[0]), $source);
        } else {
            $text = preg_replace_callback(
                '~("[^"\\\\\n]*(?:\\\\.[^"\\\\\n]*)*"|\'[^\'\\\\\n]*(?:\\\\.[^\'\\\\\n]*)*\'|`[^`\\\\]*(?:\\\\.[^`\\\\]*)*`)|//[^\n]*|/\*[^*]*\*+(?:[^/*][^*]*\*+)*/~s',
                static fn (array $m): string => ($m[1] ?? '') !== '' ? $m[0] : $blank($m[0]),
                $source
            );
        }
        if ($text === null) {
            throw new RuntimeException('The key scan could not read a source file: ' . preg_last_error_msg());
        }

        return $text;
    }

    /**
     * The keys declared in a dynamic-keys file: an object of 'prefix.': ['tail', ...].
     * It is read as text, so the tails must be written out - a list made by code
     * (a spread, a map over a constant) cannot be read, and is a problem.
     *
     * @return array{prefixes: array<string, list<string>>, keys: list<string>, problems: list<string>}
     */
    public static function dynamic(string $source): array
    {
        $text = self::sourceText($source, false);
        $prefixes = [];
        $keys = [];
        $problems = [];

        preg_match_all('~([\'"])(' . self::PREFIX_SHAPE . ')\1\s*:\s*(\[[^\]]*\])?~', $text, $matches, PREG_SET_ORDER);
        foreach ($matches as $match) {
            $prefix = $match[2];
            $list = $match[3] ?? '';
            preg_match_all('~\'([^\'\\\\\n]*)\'|"([^"\\\\\n]*)"~', $list, $tails, PREG_SET_ORDER);
            if ($list === '' || preg_match('/^\[[\s,]*\]$/D', (string) preg_replace('~\'[^\'\\\\\n]*\'|"[^"\\\\\n]*"~', '', $list)) !== 1) {
                $problems[] = "the tails of '$prefix' are not written out as a list of strings, so they cannot be read";
                continue;
            }
            $prefixes[$prefix] = [];
            foreach ($tails as $tail) {
                $tail = ($tail[1] ?? '') !== '' ? $tail[1] : ($tail[2] ?? '');
                if (!self::shapeOk($prefix . $tail)) {
                    $problems[] = "'$prefix' + '$tail' is not a key";
                    continue;
                }
                $prefixes[$prefix][] = $tail;
                $keys[] = $prefix . $tail;
            }
        }
        if ($matches === []) {
            $problems[] = 'no \'prefix.\': [tails] could be read from it';
        }

        return ['prefixes' => $prefixes, 'keys' => array_values(array_unique($keys)), 'problems' => $problems];
    }

    /**
     * The literals of a source that have a key's shape - quoted, or a template
     * literal with nothing put into it - each with the lines it stands on.
     *
     * @return array<string, list<int>>
     */
    private static function literals(string $text, bool $html): array
    {
        $found = [];
        preg_match_all('~([\'"`])(' . self::KEY_SHAPE . ')\1~', $text, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
        foreach ($matches as $match) {
            [$quote, $offset] = $match[1];
            // A template's attribute value: an expression, not a literal.
            if ($html && $quote === '"' && $offset > 0 && $text[$offset - 1] === '=') {
                continue;
            }
            $found[$match[2][0]][] = self::line($text, $offset);
        }

        return $found;
    }

    /**
     * The literals that stand right beside what asks for them: in a template
     * 'L' | pipe, in TypeScript .call('L'). Prettier writes the literal of an
     * interpolation with double quotes ({{ "home.questions" | translatePlural: n }}),
     * so every quote counts.
     *
     * @return list<array{literal: string, family: bool, line: int}>
     */
    private function asks(string $text, bool $html): array
    {
        $names = $html ? $this->pipes : $this->calls;
        if ($names === []) {
            return [];
        }
        // Longest first: translatePlural before translate.
        $list = array_keys($names);
        usort($list, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));
        $alternatives = implode('|', array_map(static fn (string $name): string => preg_quote($name, '~'), $list));

        $pattern = $html
            ? '~([\'"`])(' . self::KEY_SHAPE . ')\1\s*\|\s*(' . $alternatives . ')\b~'
            : '~\.(' . $alternatives . ')\(\s*([\'"`])(' . self::KEY_SHAPE . ')\2~';
        preg_match_all($pattern, $text, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);

        $asks = [];
        foreach ($matches as $match) {
            $name = $match[$html ? 3 : 1][0];
            $asks[] = [
                'literal' => $match[$html ? 2 : 3][0],
                'family' => $names[$name] === 'family',
                'line' => self::line($text, $match[0][1]),
            ];
        }

        return $asks;
    }

    /**
     * Where a source puts a key together at run time, as far as that can be seen:
     * a literal that ends in a dot ('take.refused.' + kind), or the beginning of a
     * template literal that goes on with ${...} - with its line.
     *
     * @return list<array{prefix: string, line: int}>
     */
    private static function built(string $text, bool $html): array
    {
        $built = [];
        preg_match_all('~([\'"`])(' . self::PREFIX_SHAPE . ')\1~', $text, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
        foreach ($matches as $match) {
            [$quote, $offset] = $match[1];
            if ($html && $quote === '"' && $offset > 0 && $text[$offset - 1] === '=') {
                continue;
            }
            $built[$offset] = ['prefix' => $match[2][0], 'line' => self::line($text, $offset)];
        }
        // `take.refused.${kind}`, and `take.refused${kind}` just as much: whatever of a key stands before the ${.
        preg_match_all('~`([a-z][A-Za-z0-9]*\.[A-Za-z0-9._-]*)\$\{~', $text, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
        foreach ($matches as $match) {
            $built[$match[0][1]] = ['prefix' => $match[1][0], 'line' => self::line($text, $match[0][1])];
        }
        ksort($built);

        return array_values($built);
    }

    /**
     * What the scan says of innerHTML in a source, by line.
     *
     * @return array<int, string>
     */
    private function innerHtmlProblems(string $text, bool $html): array
    {
        $problems = [];

        if ($this->innerHtml === 'anywhere') {
            foreach (explode("\n", $text) as $index => $line) {
                if (stripos($line, 'innerHTML') !== false) {
                    $problems[$index + 1] = 'innerHTML - a string is text, and nothing here is drawn as HTML';
                }
            }

            return $problems;
        }

        if (!$html) {
            return [];
        }
        // The attribute's whole value, however many lines it is written on - and
        // only the value: a translated string elsewhere on the line is drawn as text.
        $pipes = array_map(static fn (string $name): string => preg_quote($name, '~'), array_keys($this->pipes));
        $calls = array_map(static fn (string $name): string => preg_quote($name, '~'), array_keys($this->calls));
        $translated = '~' . implode('|', array_filter([
            $pipes === [] ? '' : '\|\s*(?:' . implode('|', $pipes) . ')\b',
            $calls === [] ? '' : '\.(?:' . implode('|', $calls) . ')\(',
        ])) . '~';
        preg_match_all('~\[?innerHTML\]?\s*=\s*(["\'])(.*?)\1~is', $text, $bindings, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
        foreach ($bindings as $binding) {
            if ($translated !== '~~' && preg_match($translated, $binding[2][0]) === 1) {
                $problems[self::line($text, $binding[0][1])] = 'innerHTML is given a translated string - a string is text, and is never drawn as HTML';
            }
        }

        return $problems;
    }

    /** The line of a text a place in it is on. */
    private static function line(string $text, int $offset): int
    {
        return substr_count($text, "\n", 0, $offset) + 1;
    }

    /** A glob - '**' any folders, '*' anything but '/', '?' one character but '/' - as an expression. */
    private static function globPattern(string $glob): string
    {
        $pattern = '';
        $length = strlen($glob);
        for ($i = 0; $i < $length; $i++) {
            $character = $glob[$i];
            if ($character === '*' && ($glob[$i + 1] ?? '') === '*') {
                // '**/' is any number of folders, none too; '**' at the end anything at all.
                if (($glob[$i + 2] ?? '') === '/') {
                    $pattern .= '(?:.*/)?';
                    $i += 2;
                } else {
                    $pattern .= '.*';
                    $i++;
                }
            } elseif ($character === '*') {
                $pattern .= '[^/]*';
            } elseif ($character === '?') {
                $pattern .= '[^/]';
            } else {
                $pattern .= preg_quote($character, '~');
            }
        }

        return '~^' . $pattern . '$~D';
    }
}
