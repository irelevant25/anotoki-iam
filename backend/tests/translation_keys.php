<?php

declare(strict_types=1);

/**
 * Which keys the frontend asks for, what its compiled English says, and a
 * language's strings as a file - for translations.php (the command line) and
 * the tests. Functions only. It lives under tests/ because nothing of it is
 * for the server: tests/ is never uploaded, and tests/run.php runs only
 * *.test.php.
 *
 * The strings live in the database so they can be edited in the admin console.
 * The frontend still names every key in core/i18n/en.ts - a key that is not
 * there is a compile error - so what can drift is the other side: a key the
 * database has and no page asks for any more, or an English string that says
 * one thing in en.ts and another in the migrations. The scan and the
 * dictionary reader are what catch that.
 *
 * How a key is recognised in frontend/src/app (every .ts and .html, but the
 * specs and core/i18n/en.ts itself, which lists every key and uses none):
 *
 *   - Its shape: a namespace ([a-z][A-Za-z0-9]*), then one or more segments
 *     ([A-Za-z0-9][A-Za-z0-9_-]*), joined by dots: login.title,
 *     sessions.method.login_code.
 *   - A quoted literal of that shape whose namespace the database has is a
 *     key the code uses. The namespace is what tells 'login.title' from a
 *     file's name. Comments do not count: they explain keys at least as often
 *     as they use them.
 *   - In a template, a double-quoted string right after = is an attribute's
 *     value - an expression, [text]="error.message" - and not a literal; the
 *     single-quoted literals inside it are ('common.close' | t).
 *   - There are no plural families and no keys put together at run time in
 *     the IAM, and no file that declares any: a key built from parts cannot
 *     be seen in the source, and shows here as unused. That is on purpose.
 *   - The `mail.` keys are the server's (api/mail.php). They are left out:
 *     never looked for in the frontend, never counted as unused.
 *   - innerHTML anywhere in the frontend fails the scan: a string is text and
 *     is never drawn as HTML, and the IAM's pages have no use for it at all.
 */

/** A key: namespace.segment[.segment...]. */
const TRANSLATION_KEY_SHAPE = '[a-z][A-Za-z0-9]*(?:\.[A-Za-z0-9][A-Za-z0-9_-]*)+';

/** The namespace of the keys no page reads. */
const TRANSLATION_SERVER_NAMESPACE = 'mail';

/** The compiled English: the list of keys, and what a page reads while it has no strings from the server. */
const TRANSLATION_DICTIONARY_FILE = 'core/i18n/en.ts';

/** The frontend's sources, seen from backend/tests/. */
function translationKeysFrontendRoot(): string
{
    return str_replace('\\', '/', dirname(__DIR__, 2)) . '/frontend/src/app';
}

function translationKeyShapeOk(string $key): bool
{
    return preg_match('/^' . TRANSLATION_KEY_SHAPE . '$/D', $key) === 1;
}

/** A key's namespace: what stands before its first dot. */
function translationKeyNamespace(string $key): string
{
    return explode('.', $key, 2)[0];
}

/**
 * The files the scan reads, relative to $root (forward slashes), sorted.
 *
 * @return string[]
 */
function translationKeySourceFiles(string $root): array
{
    $root = rtrim(str_replace('\\', '/', $root), '/');
    $files = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        if (!$file->isFile()) {
            continue;
        }
        $relative = substr(str_replace('\\', '/', $file->getPathname()), strlen($root) + 1);
        if (!preg_match('/\.(ts|html)$/D', $relative)
            || str_ends_with($relative, '.spec.ts')
            || $relative === TRANSLATION_DICTIONARY_FILE) {
            continue;
        }
        $files[] = $relative;
    }
    sort($files, SORT_STRING);

    return $files;
}

/**
 * A source file without its comments, line for line (what is taken out
 * leaves its line breaks, so a place is still the line it was on). Line
 * endings become \n. In TypeScript a string is passed over first, so the //
 * of 'http://...' and the /* of 'image/*' stay what they are.
 */
function translationKeySourceText(string $source, bool $html): string
{
    $source = str_replace(["\r\n", "\r"], "\n", $source);
    $blank = static fn(string $text): string => str_repeat("\n", substr_count($text, "\n"));

    if ($html) {
        $text = preg_replace_callback('/<!--.*?-->/s', static fn(array $m): string => $blank($m[0]), $source);
    } else {
        $text = preg_replace_callback(
            '~("[^"\\\\\n]*(?:\\\\.[^"\\\\\n]*)*"|\'[^\'\\\\\n]*(?:\\\\.[^\'\\\\\n]*)*\'|`[^`\\\\]*(?:\\\\.[^`\\\\]*)*`)|//[^\n]*|/\*[^*]*\*+(?:[^/*][^*]*\*+)*/~s',
            static fn(array $m): string => ($m[1] ?? '') !== '' ? $m[0] : $blank($m[0]),
            $source
        );
    }
    if ($text === null) {
        throw new RuntimeException('The key scan could not read a source file: ' . preg_last_error_msg());
    }

    return $text;
}

/**
 * The quoted literals of a source (as translationKeySourceText() leaves it)
 * that have a key's shape, each with the lines it stands on. Whether one is a
 * key is for the caller, who knows the database's namespaces.
 *
 * @return array<string, int[]>
 */
function translationKeyLiterals(string $text, bool $html): array
{
    $found = [];
    preg_match_all('~([\'"])(' . TRANSLATION_KEY_SHAPE . ')\1~', $text, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
    foreach ($matches as $match) {
        [$quote, $offset] = $match[1];
        // A template's attribute value: an expression, not a literal.
        if ($html && $quote === '"' && $offset > 0 && $text[$offset - 1] === '=') {
            continue;
        }
        $found[$match[2][0]][] = substr_count($text, "\n", 0, $offset) + 1;
    }

    return $found;
}

/**
 * The frontend's keys against the database's - the `mail.` ones left out.
 *
 *   used      database keys the frontend asks for, each with where (file:line)
 *   missing   keys it asks for that the database does not have - the page would show the key
 *   unused    database keys nothing asks for
 *   problems  what fails the scan whatever the keys are
 *
 * @param string   $root         the frontend's src/app
 * @param string[] $databaseKeys every key the database has
 *
 * @return array{used: array<string, string[]>, missing: array<string, string[]>, unused: string[], problems: string[]}
 */
function translationKeysScan(string $root, array $databaseKeys): array
{
    $root = rtrim(str_replace('\\', '/', $root), '/');
    $pageKeys = array_values(array_filter(
        array_map('strval', $databaseKeys),
        static fn(string $key): bool => translationKeyNamespace($key) !== TRANSLATION_SERVER_NAMESPACE
    ));
    $has = array_fill_keys($pageKeys, true);
    $namespaces = [];
    foreach ($pageKeys as $key) {
        $namespaces[translationKeyNamespace($key)] = true;
    }

    $used = [];
    $missing = [];
    $problems = [];
    foreach (translationKeySourceFiles($root) as $relative) {
        $html = str_ends_with($relative, '.html');
        $text = translationKeySourceText((string) file_get_contents("$root/$relative"), $html);

        foreach (explode("\n", $text) as $index => $line) {
            if (stripos($line, 'innerHTML') !== false) {
                $problems[] = "$relative:" . ($index + 1) . ': innerHTML - a string is text, and nothing in the IAM is drawn as HTML';
            }
        }
        foreach (translationKeyLiterals($text, $html) as $literal => $lines) {
            $literal = (string) $literal;
            if (!isset($namespaces[translationKeyNamespace($literal)])) {
                continue;
            }
            $where = "$relative:" . $lines[0];
            if (isset($has[$literal])) {
                $used[$literal][] = $where;
            } else {
                $missing[$literal][] = $where;
            }
        }
    }

    ksort($used, SORT_STRING);
    ksort($missing, SORT_STRING);
    $unused = array_values(array_filter($pageKeys, static fn(string $key): bool => !isset($used[$key])));
    sort($unused, SORT_STRING);

    return ['used' => $used, 'missing' => $missing, 'unused' => $unused, 'problems' => $problems];
}

// ── The compiled English ─────────────────────────────────────────────────────

/** A quoted TypeScript string literal's value, escapes undone. */
function translationKeysUnquote(string $literal): string
{
    $quote = $literal[0];
    $inner = substr($literal, 1, -1);

    return (string) preg_replace_callback(
        '/\\\\(u\{[0-9A-Fa-f]{1,6}\}|u[0-9A-Fa-f]{4}|x[0-9A-Fa-f]{2}|.)/su',
        static function (array $m) use ($quote): string {
            $escape = $m[1];

            return match (true) {
                $escape === 'n' => "\n",
                $escape === 't' => "\t",
                $escape === 'r' => "\r",
                $escape[0] === 'u' && ($escape[1] ?? '') === '{' => mb_chr((int) hexdec(substr($escape, 2, -1)), 'UTF-8'),
                $escape[0] === 'u' && strlen($escape) === 5 => mb_chr((int) hexdec(substr($escape, 1)), 'UTF-8'),
                $escape[0] === 'x' && strlen($escape) === 3 => mb_chr((int) hexdec(substr($escape, 1)), 'UTF-8'),
                default => $escape, // \' \" \\ and anything else stand for themselves
            };
        },
        $inner
    );
}

/**
 * The entries of the frontend's dictionary (core/i18n/en.ts): [key => English].
 *
 * Read as text: `'key': 'value',` entries of the one object the file exports,
 * single or double quoted, with escapes, the value on the key's line or the
 * next (as the formatter breaks a long one). It throws unless what it read is
 * everything the object has, comments aside - a key it could not read, or one
 * it did not take for an entry at all, would otherwise pass as a key that is
 * not there.
 *
 * @return array<string, string>
 */
function translationKeysDictionary(string $path): array
{
    $raw = is_file($path) ? file_get_contents($path) : false;
    if ($raw === false) {
        throw new RuntimeException("There is no dictionary at $path.");
    }

    $text = translationKeySourceText($raw, false);
    if (!preg_match('/^export const \w+(?:\s*:[^=]+)?\s*=\s*\{\n(.*?)\n\}(?: as const)?;/ms', $text, $object)) {
        throw new RuntimeException("$path does not export one object of strings.");
    }

    $string = '\'(?:[^\'\\\\\n]|\\\\.)*\'|"(?:[^"\\\\\n]|\\\\.)*"';
    $entry = '~^[ \t]*(' . $string . ')[ \t]*:\s*(' . $string . ')[ \t]*,?[ \t]*$~mu';
    if (preg_match_all($entry, $object[1], $matches, PREG_SET_ORDER) === false) {
        throw new RuntimeException("$path could not be read: " . preg_last_error_msg() . '.');
    }

    $entries = [];
    foreach ($matches as $match) {
        $key = translationKeysUnquote($match[1]);
        if (array_key_exists($key, $entries)) {
            throw new RuntimeException("$path has the key $key twice.");
        }
        $entries[$key] = translationKeysUnquote($match[2]);
    }

    // Nothing of the object may be left unread. Counting the lines that look
    // like an entry would miss exactly what does not look like one - a key
    // without quotes (`save: 'Save',` is TypeScript too, and the formatter
    // leaves it so), a spread, a computed key, a value put together - and such
    // a key would be in the compiled English, in no test, and a bare key on
    // the page once the strings come from the database.
    $rest = preg_replace($entry, '', $object[1]);
    if ($rest === null) {
        throw new RuntimeException("$path could not be read: " . preg_last_error_msg() . '.');
    }
    $left = array_values(array_filter(array_map('trim', explode("\n", $rest)), static fn(string $line): bool => $line !== ''));
    if ($left !== []) {
        throw new RuntimeException("$path: could not read \"{$left[0]}\" - it is written in a way this reader does not know (" . count($left) . (count($left) === 1 ? ' such line).' : ' such lines).'));
    }
    if ($entries === []) {
        throw new RuntimeException("$path has no entries.");
    }

    return $entries;
}

// ── A language's strings as a file ───────────────────────────────────────────

/**
 * A language's strings as the text of a file: one flat JSON object, a key a
 * line, the letters as they are - so it can be worked on in an editor and
 * compared line by line.
 */
function translationFileText(array|object $values): string
{
    return json_encode((object) (array) $values, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
}

function translationFileWrite(string $path, array|object $values): void
{
    if (@file_put_contents($path, translationFileText($values)) === false) {
        throw new RuntimeException("Cannot write $path.");
    }
}

/**
 * The strings in a file: a flat JSON object, {key: text}. What each value is
 * is checked where the strings are kept (translationsCheck), not here.
 *
 * @return array<string, mixed>
 */
function translationFileRead(string $path): array
{
    $raw = is_file($path) ? @file_get_contents($path) : false;
    if ($raw === false) {
        throw new RuntimeException("There is no file $path.");
    }
    // An editor on Windows may have put a byte-order mark in front.
    if (str_starts_with($raw, "\xEF\xBB\xBF")) {
        $raw = substr($raw, 3);
    }
    try {
        $values = json_decode($raw, true, 16, JSON_THROW_ON_ERROR);
    } catch (JsonException $e) {
        throw new RuntimeException("$path is not JSON: " . $e->getMessage() . '.');
    }
    if (!is_array($values) || ($values !== [] && array_is_list($values))) {
        throw new RuntimeException("$path does not hold one flat object, {key: text}.");
    }

    return $values;
}
