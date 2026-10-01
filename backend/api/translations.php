<?php

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;

/**
 * The IAM's own words, in the languages it speaks: the languages, the strings
 * a page asks for by key, the words of the mails, and the grid the admin
 * console edits them in.
 *
 * A key is the code's: migrations write the keys and their first strings
 * (migrations/iam/006 and after), the console only ever changes a string.
 * English is what every other language falls back to, key by key, so it cannot
 * be switched off, deleted, or left without a string.
 *
 * A string is text and is drawn as text - never HTML. What stands in for a
 * value is a {placeholder}. Which ones a page's key has is fixed by its
 * English string: the code fills them in, and a translation that invented one
 * would show it to the reader as it is written. Which ones a mail's key has is
 * fixed by MAIL_KEYS (api/mail.php), and a mail's words are held to more than
 * a page's: they go out to a mailbox under the IAM's name.
 *
 * Nothing here throws for a refusal. A function answers with what it was asked
 * for, or with a refusal - ['status', 'code', 'error', 'extra'] - which the
 * route turns into respondError() (translationRefused(), translationRefuse()).
 *
 * The list of offered codes the rest of the IAM reads is languageCodes() in
 * api/helpers.php, which works before these tables exist; every change to a
 * language here tells it to read again.
 */

/** The language every other one falls back to. */
const TRANSLATION_FALLBACK = 'en';

/**
 * The languages the migrations write strings for. They cannot be deleted: the
 * next migration with strings would fail on the missing language, on a host
 * with no shell to mend it from. tests/translations.test.php keeps this true.
 */
const TRANSLATION_SEEDED_LANGUAGES = ['en', 'sk'];

/** The largest body a save or an import of strings may have: every string of a language is a few dozen kB. */
const TRANSLATIONS_BODY_LIMIT = 1024 * 1024;

/** How long one string may be - the longest a page has is a few sentences. */
const TRANSLATION_VALUE_MAX = 2000;

/** A mail's subject is one line of at most this many characters; any other string of a mail, of at most the second. */
const TRANSLATION_MAIL_SUBJECT_MAX = 150;
const TRANSLATION_MAIL_VALUE_MAX = 400;

/**
 * A mail string is also bounded in bytes. Each becomes one line of the
 * message, sent as it is (8bit, never wrapped), and a line of mail may be at
 * most 998 bytes: a server on the way may refuse a longer one, while mail()
 * has long since said "sent". 400 characters are 800 bytes in the scripts the
 * IAM is released in, but 1200 in Japanese or Thai, so the characters alone
 * do not hold the line.
 *
 * A greeting is filled with the username, which is not the string's to bound:
 * at most 100 characters (usernameRefusal(), users.username), which can be
 * 400 bytes. That room is kept for every {username} the string has, not once
 * for the string: mailFill() fills each one, and nothing stops a greeting from
 * naming its reader twice. So the bytes a mail string may have are the first
 * number less the second for each {username} in it - 500 with one, 100 with
 * two, and with three there is no room left at all. The other values - a
 * number of hours or minutes, the code - are no longer than the placeholders
 * they stand in for.
 */
const TRANSLATION_MAIL_LINE_OCTETS = 900;
const TRANSLATION_MAIL_USERNAME_OCTETS = 400;

/** A placeholder in a string: {name}. The same expression as the frontend's (core/i18n) and mailFill()'s. */
const TRANSLATION_PLACEHOLDER = '/\{([A-Za-z][A-Za-z0-9_]*)\}/';

/**
 * Page keys that must keep a placeholder in every language: the sign-in and
 * registration pages cut these two sentences around {app} to set the
 * application's name apart, and a string without it would lose the name.
 */
const TRANSLATION_REQUIRED_PLACEHOLDERS = [
    'login.continueTo' => ['app'],
    'register.forApp' => ['app'],
];

/**
 * What an address looks like in a mail's words: a scheme, "www.", or an @.
 * A mail's only link is the one the server puts in; a second one, written by
 * whoever can edit strings, would be a phishing mail sent by the IAM itself.
 * It stops the obvious link, not a bare domain - the audit log, which keeps
 * the old and the new text of every changed mail string, is what catches the rest.
 */
const TRANSLATION_MAIL_ADDRESS = '~(://|\bwww\.|@)~i';

const LANGUAGES_MAX = 20;
const LANGUAGE_NAME_MAX = 50;
const LANGUAGE_SORT_MAX = 1000;

/**
 * How much of a save the audit log names: the first keys - and a changed mail
 * string's old and new text whole. "Whole" is as long as the console lets a
 * mail string be: the log is what says afterwards who made a reset mail say
 * what, and a sentence added at the end of a paragraph must not be the part
 * it leaves out - once the string is put back, the log is the only place that
 * sentence is kept. The clip is for a string that reached the table without
 * the console (SQL), which can be of any length and must not bloat the log.
 */
const TRANSLATION_AUDIT_KEYS = 100;
const TRANSLATION_AUDIT_TEXT = TRANSLATION_MAIL_VALUE_MAX;

// ── Text ─────────────────────────────────────────────────────────────────────

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
 * The text without the spaces around it - every kind of space, as the
 * browser's trim() takes them away, not only the ASCII ones PHP's trim() knows.
 *
 * A text the expression cannot get through - a run of more than a million
 * spaces inside it, which a body of 1 MiB has room for - comes back as it is,
 * never as an empty one. Whoever measures it next refuses it as too long;
 * taken for nothing, it would have deleted the string it was sent for.
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
 * otherwise make a string that can never be saved, however often it is tried.
 */
function textClean(mixed $value): ?string
{
    if (!is_string($value) || preg_match('//u', $value) !== 1) {
        return null;
    }

    return textWithoutControls($value);
}

// ── Refusals ─────────────────────────────────────────────────────────────────

/** A refusal, in the shape signInRefusal() uses, with what respondError() adds to the answer. */
function translationRefusal(int $status, string $code, string $error, array $extra = []): array
{
    return ['status' => $status, 'code' => $code, 'error' => $error, 'extra' => $extra];
}

/** Whether what a function here answered is a refusal. No result of theirs has these three keys. */
function translationRefused(mixed $result): bool
{
    return is_array($result) && isset($result['status'], $result['code'], $result['error']) && is_int($result['status']);
}

function translationRefuse(Response $response, array $refusal): Response
{
    return respondError($response, $refusal['status'], $refusal['code'], $refusal['error'], $refusal['extra'] ?? []);
}

/**
 * Whether the tables are there: a build is uploaded before its migrations are
 * applied, and this file runs on a database that has not heard of 006 yet.
 * A query that cannot fail.
 */
function translationsReady(PDO $pdo): bool
{
    return asBool($pdo->query(
        "SELECT to_regclass('public.languages') IS NOT NULL
            AND to_regclass('public.translation_keys') IS NOT NULL
            AND to_regclass('public.translations') IS NOT NULL"
    )->fetchColumn());
}

/**
 * Not a 500 - this is expected between an upload and "Apply pending", and a
 * 500 would be logged on every page load - and not `not_set_up`, which sends
 * the IAM's pages to the installer's notice. The pages read their compiled
 * English meanwhile.
 */
function translationsUnavailable(): array
{
    return translationRefusal(503, 'translations_unavailable', 'The strings are not in the database yet. Apply the pending migrations in the admin console.');
}

/** For the admin console's language and string routes: 503 until the tables are there. */
function requireTranslations(): callable
{
    return function (Request $request, RequestHandler $handler): Response {
        if (!translationsReady(iamDb())) {
            return translationRefuse(new \Slim\Psr7\Response(), translationsUnavailable());
        }

        return $handler->handle($request);
    };
}

/**
 * Whether a save or an import is larger than it may be - by what it says of
 * itself (Content-Length) and by what actually arrived.
 */
function translationsBodyTooLarge(Request $request): bool
{
    $declared = trim($request->getHeaderLine('Content-Length'));

    if ($declared !== '' && (!ctype_digit($declared) || strlen($declared) > 12 || (int) $declared > TRANSLATIONS_BODY_LIMIT)) {
        return true;
    }

    $size = $request->getBody()->getSize();

    return ($size ?? strlen((string) $request->getBody())) > TRANSLATIONS_BODY_LIMIT;
}

// ── Languages ────────────────────────────────────────────────────────────────

/**
 * A language code as the IAM writes them: "sk", "pt-br". Asked before any
 * query that takes a code from an address - anything else is no language, and
 * bytes that are not UTF-8 never reach the database (which would refuse them:
 * a 500 for what is only a wrong address).
 */
function languageCodeOk(mixed $code): bool
{
    return is_string($code) && preg_match('/^[a-z]{2}(-[a-z]{2})?$/D', $code) === 1;
}

function languageNotFound(): array
{
    return translationRefusal(404, 'not_found', 'There is no such language');
}

function languageInvalid(string $message): array
{
    return translationRefusal(422, 'invalid_language', $message);
}

const LANGUAGE_SELECT = 'SELECT l.code, l.name, l.native_name, l.enabled, l.sort_order,
        (SELECT count(*) FROM users u WHERE u.language = l.code) AS accounts
   FROM languages l';

/** A language as the admin console reads it; `accounts` is how many accounts have it. */
function languageShape(array $row): array
{
    return [
        'code' => $row['code'],
        'name' => $row['name'],
        'native_name' => $row['native_name'],
        'enabled' => asBool($row['enabled']),
        'sort_order' => (int) $row['sort_order'],
        'seeded' => in_array($row['code'], TRANSLATION_SEEDED_LANGUAGES, true),
        'accounts' => (int) ($row['accounts'] ?? 0),
    ];
}

/** Every language, the ones switched off too, in the order a chooser shows them. */
function languagesAll(PDO $pdo): array
{
    return array_map('languageShape', $pdo->query(LANGUAGE_SELECT . ' ORDER BY l.sort_order, l.name, l.code')->fetchAll());
}

/** The language with this code, or null - without a query when it is not a code at all. */
function languageRow(PDO $pdo, mixed $code): ?array
{
    if (!languageCodeOk($code)) {
        return null;
    }

    $statement = $pdo->prepare(LANGUAGE_SELECT . ' WHERE l.code = ?');
    $statement->execute([$code]);

    return $statement->fetch() ?: null;
}

/**
 * One of a language's two names: text of 1 to 50 characters - or the refusal.
 *
 * @return string|array
 */
function languageName(mixed $value, string $what): string|array
{
    $text = textClean($value);
    if ($text === null) {
        return languageInvalid("$what is not text");
    }

    $text = textTrim($text);
    if (textBlank($text) || textLength($text) > LANGUAGE_NAME_MAX || preg_match('/[\r\n\t]/', $text)) {
        return languageInvalid("$what must be one line of 1 to " . LANGUAGE_NAME_MAX . ' characters');
    }

    return $text;
}

/**
 * A new language, {code, name, native_name, enabled?}: after the ones there
 * are, and offered at once unless `enabled` says false - then it is there for
 * its strings to be written, and the pages, discovery and the accounts hear of
 * it when somebody switches it on. It has no strings yet, so offered at once
 * it reads in English until somebody translates it.
 *
 * @return array{language: array}|array a refusal
 */
function languageCreate(PDO $pdo, mixed $body): array
{
    if (!is_array($body) || ($body !== [] && array_is_list($body))) {
        return languageInvalid('Send the language as {code, name, native_name}');
    }

    $code = $body['code'] ?? null;
    if (!languageCodeOk($code)) {
        return languageInvalid('A language\'s code is two small letters, or two and two with a dash between: "sk", "pt-br"');
    }

    $name = languageName($body['name'] ?? null, 'The name in English');
    if (is_array($name)) {
        return $name;
    }
    $nativeName = languageName($body['native_name'] ?? null, 'The name as its speakers write it');
    if (is_array($nativeName)) {
        return $nativeName;
    }
    // Left out, it is offered - as it was before there was a choice. Said, it
    // is true or false and nothing that only reads like one: "false" would
    // otherwise be a language offered against its maker's word.
    $enabled = true;
    if (array_key_exists('enabled', $body)) {
        if (!is_bool($body['enabled'])) {
            return languageInvalid('"enabled" is true or false');
        }
        $enabled = $body['enabled'];
    }

    $exists = translationRefusal(409, 'language_exists', "There is a language \"$code\" already");

    $pdo->beginTransaction();
    try {
        // Whoever adds a language waits here for anybody else who is adding
        // one: the count below is then of rows that are really there, and the
        // most the IAM keeps holds. (Two transactions that only counted would
        // each miss the other's row, and both go past it.) Taken first, so
        // nothing is held while waiting. It stops only what writes the table:
        // the rows can still be read and locked - a save of strings, an
        // account taking a language.
        $pdo->exec('LOCK TABLE languages IN SHARE ROW EXCLUSIVE MODE');

        $known = $pdo->prepare('SELECT 1 FROM languages WHERE code = ?');
        $known->execute([$code]);
        if ($known->fetchColumn()) {
            $pdo->rollBack();
            return $exists;
        }

        if ((int) $pdo->query('SELECT count(*) FROM languages')->fetchColumn() >= LANGUAGES_MAX) {
            $pdo->rollBack();
            return translationRefusal(422, 'too_many_languages', 'The IAM keeps at most ' . LANGUAGES_MAX . ' languages. Delete one that is not used first.');
        }

        $sortOrder = min((int) $pdo->query('SELECT COALESCE(MAX(sort_order), 0) + 1 FROM languages')->fetchColumn(), LANGUAGE_SORT_MAX);

        // A row written past this function in this very moment: no row comes
        // back, and no error that would end the transaction.
        $insert = $pdo->prepare(
            'INSERT INTO languages (code, name, native_name, enabled, sort_order) VALUES (?, ?, ?, ?, ?)
             ON CONFLICT (code) DO NOTHING RETURNING code'
        );
        $insert->execute([$code, $name, $nativeName, pgBool($enabled), $sortOrder]);
        if ($insert->fetchColumn() === false) {
            $pdo->rollBack();
            return $exists;
        }

        $pdo->commit();
    } catch (\Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    languageCodes(null, true);

    return ['language' => languageShape(languageRow($pdo, $code))];
}

/**
 * Changes a language: {name?, native_name?, enabled?, sort_order?} - what is
 * not sent stays. English cannot be switched off; any other language can, at
 * any time: its strings and its accounts keep it, it is only no longer offered.
 *
 * `changed` is what is now different, for the audit log.
 *
 * @return array{language: array, changed: array}|array a refusal
 */
function languageUpdate(PDO $pdo, mixed $code, mixed $body): array
{
    $row = languageRow($pdo, $code);
    if ($row === null) {
        return languageNotFound();
    }
    if (!is_array($body) || ($body !== [] && array_is_list($body))) {
        return languageInvalid('Send what changes as {name, native_name, enabled, sort_order}');
    }

    $changes = [];

    foreach (['name' => 'The name in English', 'native_name' => 'The name as its speakers write it'] as $column => $what) {
        if (array_key_exists($column, $body)) {
            $text = languageName($body[$column], $what);
            if (is_array($text)) {
                return $text;
            }
            if ($text !== $row[$column]) {
                $changes[$column] = $text;
            }
        }
    }

    if (array_key_exists('enabled', $body)) {
        if (!is_bool($body['enabled'])) {
            return languageInvalid('"enabled" is true or false');
        }
        if ($code === TRANSLATION_FALLBACK && !$body['enabled']) {
            return translationRefusal(409, 'fallback_language', 'English cannot be switched off: every other language falls back to it');
        }
        if ($body['enabled'] !== asBool($row['enabled'])) {
            $changes['enabled'] = $body['enabled'];
        }
    }

    if (array_key_exists('sort_order', $body)) {
        if (!is_int($body['sort_order']) || $body['sort_order'] < 0 || $body['sort_order'] > LANGUAGE_SORT_MAX) {
            return languageInvalid('The order is a whole number from 0 to ' . LANGUAGE_SORT_MAX);
        }
        if ($body['sort_order'] !== (int) $row['sort_order']) {
            $changes['sort_order'] = $body['sort_order'];
        }
    }

    if ($changes !== []) {
        $sets = implode(', ', array_map(static fn(string $column): string => $column . ' = ?', array_keys($changes)));
        $values = array_map(static fn(mixed $value): mixed => is_bool($value) ? pgBool($value) : $value, array_values($changes));
        $pdo->prepare("UPDATE languages SET $sets WHERE code = ?")->execute([...$values, $code]);
        languageCodes(null, true);

        $row = languageRow($pdo, $code);
        if ($row === null) {
            return languageNotFound();
        }
    }

    return ['language' => languageShape($row), 'changed' => $changes];
}

/**
 * Deletes a language and its strings. Never English, never a language the
 * migrations write strings for. The accounts that had it go back to English -
 * the foreign key's ON DELETE SET DEFAULT - and `accounts` says how many.
 *
 * @return array{accounts: int, strings: int, language: array}|array a refusal
 */
function languageDelete(PDO $pdo, mixed $code): array
{
    if (!languageCodeOk($code)) {
        return languageNotFound();
    }
    if ($code === TRANSLATION_FALLBACK) {
        return translationRefusal(409, 'fallback_language', 'English cannot be deleted: every other language falls back to it');
    }
    if (in_array($code, TRANSLATION_SEEDED_LANGUAGES, true)) {
        return translationRefusal(409, 'seeded_language', 'This language is one the IAM is released in: every update brings strings for it, and would fail without it. Switch it off instead.');
    }

    $pdo->beginTransaction();
    try {
        // Locked, so no account takes the language between the count and the delete.
        $locked = $pdo->prepare('SELECT code, name, native_name, enabled, sort_order FROM languages WHERE code = ? FOR UPDATE');
        $locked->execute([$code]);
        $row = $locked->fetch();
        if (!$row) {
            $pdo->rollBack();
            return languageNotFound();
        }

        $accounts = $pdo->prepare('SELECT count(*) FROM users WHERE language = ?');
        $accounts->execute([$code]);
        $accounts = (int) $accounts->fetchColumn();

        $strings = $pdo->prepare('SELECT count(*) FROM translations WHERE language_code = ?');
        $strings->execute([$code]);
        $strings = (int) $strings->fetchColumn();

        $pdo->prepare('DELETE FROM languages WHERE code = ?')->execute([$code]);
        $pdo->commit();
    } catch (\Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    languageCodes(null, true);

    return ['accounts' => $accounts, 'strings' => $strings, 'language' => languageShape($row + ['accounts' => $accounts])];
}

// ── The strings a page asks for ──────────────────────────────────────────────

/** A map that goes out as a JSON object, empty or not: PHP would write an empty one as []. */
function translationMap(array $map): object
{
    return (object) $map;
}

/**
 * A language's own strings, by key: [key => text], in the keys' order.
 * With $pages, only what a page may read: the mail words are the server's.
 */
function translationsOf(PDO $pdo, string $code, bool $pages = false): array
{
    $statement = $pdo->prepare(
        'SELECT key_name, value FROM translations WHERE language_code = ?'
        . ($pages ? " AND key_name NOT LIKE 'mail.%'" : '')
        . ' ORDER BY key_name COLLATE "C"'
    );
    $statement->execute([$code]);

    $values = [];
    foreach ($statement->fetchAll() as $row) {
        $values[$row['key_name']] = $row['value'];
    }

    return $values;
}

/**
 * What the pages read their words from: GET /api/translations/{code}, for a
 * code that is one (languageCodeOk). `values` is the language's strings with
 * English under them, so a key with no string in it reads in English;
 * `languages` the ones offered; `english` the fallback's own strings, for the
 * admin console, which is always English - left out when that is what `values`
 * is. No `mail.` key goes out, in either map.
 *
 * `language` says which language it really is. A code with a region that is
 * not offered itself is answered by its language when that one is: "sk-sk" -
 * a Slovak browser's first wish - by "sk", in this one request rather than
 * with English and a second request before the first page. It is the very
 * answer "sk" gets, byte for byte, so under the same tag. An offered "pt-br"
 * is itself. Anything else the IAM does not have, or has switched off, is
 * answered in English. (Only here: what discovery offers and what ui_locales
 * picks are whole codes, as they were.)
 *
 * Without the tables, or with no English string at all, there is nothing to
 * answer with: a refusal (503), and the pages keep their compiled English.
 *
 * @return array{language: string, languages: array, values: object, english?: object}|array a refusal
 */
function translationBundle(PDO $pdo, string $code): array
{
    if (!translationsReady($pdo)) {
        return translationsUnavailable();
    }

    $english = translationsOf($pdo, TRANSLATION_FALLBACK, true);
    if ($english === []) {
        return translationsUnavailable();
    }

    $offered = $pdo->query('SELECT code, name, native_name FROM languages WHERE enabled ORDER BY sort_order, name, code')->fetchAll();
    $codes = array_column($offered, 'code');
    $base = substr($code, 0, 2); // of a code that is one (languageCodeOk): its language, without the region
    $language = match (true) {
        in_array($code, $codes, true) => $code,
        in_array($base, $codes, true) => $base,
        default => TRANSLATION_FALLBACK,
    };

    $bundle = [
        'language' => $language,
        'languages' => array_map(static fn(array $row): array => ['code' => $row['code'], 'name' => $row['name'], 'native_name' => $row['native_name']], $offered),
    ];

    if ($language === TRANSLATION_FALLBACK) {
        $bundle['values'] = translationMap($english);

        return $bundle;
    }

    $values = translationsOf($pdo, $language, true) + $english;
    ksort($values, SORT_STRING);
    $bundle['values'] = translationMap($values);
    $bundle['english'] = translationMap($english);

    return $bundle;
}

/**
 * Does the browser hold this very answer already? If-None-Match compared the
 * weak way, which is what a conditional GET asks for: any of the tags it
 * lists, or *. A tag comes back as whatever compressed the answer on its way
 * out left it: with W/ in front (nginx and CDNs weaken a tag they compress),
 * or with -gzip, -br or -zstd inside the quotes (an Apache that compresses
 * appends the name of the packing - mod_deflate and mod_brotli do, and cannot
 * be told not to from an .htaccess) - and without taking that off again,
 * behind such an Apache no browser would ever be told "the same as you have".
 * The words are the same whatever they were packed in, and the tag is an MD5
 * in hex, which never ends so itself.
 */
function translationsNotModified(string $ifNoneMatch, string $etag): bool
{
    foreach (explode(',', $ifNoneMatch) as $candidate) {
        $candidate = trim($candidate);
        if (str_starts_with($candidate, 'W/')) {
            $candidate = substr($candidate, 2);
        }
        if ($candidate === '*' || $candidate === $etag || preg_replace('/-(?:gzip|br|zstd)"$/D', '"', $candidate) === $etag) {
            return true;
        }
    }

    return false;
}

// ── The admin console's grid ─────────────────────────────────────────────────

/**
 * Every key with its strings in every language, and the languages:
 * {languages: [all], keys: [{name, description, values: {en: "...", sk: "..."}}]},
 * the keys by name - the `mail.` ones among them.
 */
function translationGrid(PDO $pdo): array
{
    $keys = [];
    foreach ($pdo->query('SELECT name, description FROM translation_keys ORDER BY name COLLATE "C"')->fetchAll() as $row) {
        $keys[$row['name']] = ['name' => $row['name'], 'description' => (string) ($row['description'] ?? ''), 'values' => []];
    }
    foreach ($pdo->query('SELECT key_name, language_code, value FROM translations ORDER BY language_code')->fetchAll() as $row) {
        $keys[$row['key_name']]['values'][$row['language_code']] = $row['value'];
    }

    return [
        'languages' => languagesAll($pdo),
        'keys' => array_map(
            static fn(array $key): array => ['name' => $key['name'], 'description' => $key['description'], 'values' => translationMap($key['values'])],
            array_values($keys)
        ),
    ];
}

/**
 * The placeholders a string uses, each once, in the order they first appear.
 *
 * @return string[]
 */
function translationPlaceholders(string $text): array
{
    preg_match_all(TRANSLATION_PLACEHOLDER, $text, $matches);

    return array_values(array_unique($matches[1]));
}

/**
 * Checks strings somebody wants kept - a save of the grid, an import, the
 * command line - and says what to write. Everything is checked before
 * anything is written; the first thing wrong is refused (422), with the key
 * and the language it is about (and the placeholder, where it is about one):
 *
 *   invalid_value         not {key: {language: text}}; a value that is not text, or too long;
 *                         a mail string with a line break or a tab in it, or of more bytes
 *                         than a line of mail may have once every {username} in it is filled
 *   unknown_key           a key the IAM does not have (with `keys`: every such key sent)
 *   unknown_language      a language the IAM does not have
 *   fallback_required     an empty English string - every other language falls back to it
 *   invalid_placeholder   a { or } that is not part of a {placeholder}, in a page's string or a
 *                         mail's: "{ count }", "{počet}", "{count", "{{count}}"
 *   placeholder_changed   an English string whose {placeholders} are not exactly the stored English string's
 *   unknown_placeholder   another language's string with a {placeholder} the English one does not have;
 *                         a mail string, in any language, with one MAIL_KEYS does not give its key
 *   placeholder_missing   login.continueTo or register.forApp without {app}; a mail string, in any
 *                         language, without a placeholder MAIL_KEYS gives its key
 *   address_in_mail       a mail string with something that reads as an address in it
 *
 * An empty string in another language means "no string of its own": the row
 * goes, and the language reads that key in English. Empty is whatever has
 * nothing in it to read (textBlank()) - spaces, or only characters of no
 * width, which look like a cleared field and are one. A page's translation may
 * leave a placeholder out, but never add one: the code fills in only the ones
 * the English string has. For a key with no English string yet, the English
 * sent with it is what counts. And a brace is only ever part of a placeholder:
 * the page, and mailFill(), fill in what is written exactly as {name} and
 * leave anything else as it stands, so a placeholder with a space in it, or
 * with its name translated, would be read by everybody as braces and a word.
 *
 * A mail's words are held to more, because they go out under the IAM's name
 * to somebody's mailbox: exactly the placeholders the code fills for that key
 * - the code is the authority, not the stored English -, one line, a short
 * one, and no address.
 *
 * It reads nothing itself, so it can be tested without a database.
 *
 * @param mixed                 $values    {key: {language code: text}}
 * @param string[]              $keys      every key there is
 * @param array<string, string> $languages every language there is: code => its name in English
 * @param array<string, string> $english   the stored English string of every key that has one
 *
 * @return array{changes: array<array{key: string, language: string, value: ?string}>}|array a refusal; value null: delete that string
 */
function translationsCheck(mixed $values, array $keys, array $languages, array $english): array
{
    $isMap = static fn(mixed $value): bool => is_array($value) && ($value === [] || !array_is_list($value));
    $invalid = static fn(string $code, string $message, array $extra = []): array => translationRefusal(422, $code, $message, $extra);

    if (!$isMap($values)) {
        return $invalid('invalid_value', 'Send the strings by key and language: {key: {language: text}}');
    }

    $known = array_fill_keys($keys, true);
    // What is quoted back in a refusal is never longer than a key can be.
    $quote = static fn(string $text): string => '"' . textClip($text, 200) . '"';
    // With every placeholder taken out, is a brace left? What is left would be
    // shown to the reader as it is written - in any language, on a page or in
    // a mail. (A failed replace leaves the text, which is then judged whole.)
    $strayBrace = static fn(string $text): bool => preg_match('/[{}]/', preg_replace(TRANSLATION_PLACEHOLDER, '', $text) ?? $text) === 1;
    // The sentence names one of the key's own placeholders: told "like
    // {count}" on a key whose placeholder is {app}, a translator would add one
    // the key does not have.
    $braceRefusal = static fn(string $name, string $key, array $placeholders, array $about): array => $invalid(
        'invalid_placeholder',
        "The $name string of \"$key\" has a { or } that is not part of a placeholder. A placeholder is written exactly as the English string has it, like {"
            . ($placeholders[0] ?? 'count') . '}: no spaces, and its name is not translated.',
        $about
    );
    $changes = [];

    foreach ($values as $key => $cells) {
        $key = (string) $key; // PHP made a number of a key like "12"
        if (!$isMap($cells)) {
            return $invalid('invalid_value', 'The strings of ' . $quote($key) . ' are not given by language: {language: text}', ['key' => textClip($key, 200)]);
        }
        if (!isset($known[$key])) {
            $unknown = array_values(array_filter(array_map('strval', array_keys($values)), static fn(string $name): bool => !isset($known[$name])));

            return $invalid(
                'unknown_key',
                count($unknown) === 1
                    ? 'The IAM has no key ' . $quote($key) . '. Keys are made by the code, never here.'
                    : 'The IAM has no such keys: ' . implode(', ', array_map($quote, array_slice($unknown, 0, 20))) . (count($unknown) > 20 ? ' and ' . (count($unknown) - 20) . ' more' : '') . '. Keys are made by the code, never here.',
                ['key' => textClip($key, 200), 'keys' => array_map(static fn(string $name): string => textClip($name, 200), $unknown)]
            );
        }

        $mail = str_starts_with($key, 'mail.');

        // The placeholders the key has. A mail's: what the code fills for it.
        // A page's: its stored English string's - or, for a key that has none
        // yet, those of the English string sent with it.
        $placeholders = $mail
            ? (MAIL_KEYS[$key] ?? [])
            : translationPlaceholders($english[$key] ?? textClean($cells[TRANSLATION_FALLBACK] ?? null) ?? '');

        foreach ($cells as $code => $value) {
            $code = (string) $code;
            $about = ['key' => $key, 'language' => textClip($code, 20)];
            if (!isset($languages[$code])) {
                return $invalid('unknown_language', 'The IAM has no language ' . $quote($code), $about);
            }
            $name = $languages[$code];

            $text = textClean($value);
            if ($text === null) {
                return $invalid('invalid_value', "The $name string of \"$key\" is not text", $about);
            }
            $text = textTrim($text);
            // Empty is what reads as empty (textBlank()): a zero-width space
            // alone is no English string, and no translation either.
            if (textBlank($text)) {
                if ($code === TRANSLATION_FALLBACK) {
                    return $invalid('fallback_required', "\"$key\" needs its English string: every other language falls back to it", $about);
                }
                $changes[] = ['key' => $key, 'language' => $code, 'value' => null];
                continue;
            }

            $used = translationPlaceholders($text);

            if ($mail) {
                // Refused, not dropped: a line break in a subject is how a
                // header is smuggled in, and one in a paragraph breaks the
                // message's shape.
                if (preg_match('/[\r\n\t\x{0085}\x{2028}\x{2029}]/u', $text)) {
                    return $invalid('invalid_value', "The $name string of \"$key\" must be one line: a mail's words have no line breaks or tabs", $about);
                }
                $max = str_ends_with($key, '.subject') ? TRANSLATION_MAIL_SUBJECT_MAX : TRANSLATION_MAIL_VALUE_MAX;
                if (textLength($text) > $max) {
                    return $invalid('invalid_value', "The $name string of \"$key\" is longer than $max characters", $about);
                }
                // And in bytes: the string is one line of the message as
                // sent. Where the key takes the username, room is kept for it
                // as often as the string names it - every {username} is
                // filled, and each may become 400 bytes.
                $named = in_array('username', $placeholders, true) ? substr_count($text, '{username}') : 0;
                $room = TRANSLATION_MAIL_LINE_OCTETS - $named * TRANSLATION_MAIL_USERNAME_OCTETS;
                if (strlen($text) > $room) {
                    return $invalid(
                        'invalid_value',
                        $named > 1
                            ? "The $name string of \"$key\" is too long for one line of a mail: it has {username} $named times, and room for the longest name there can be is kept for each - "
                                . ($room > 0 ? "which leaves $room bytes, and it has " . strlen($text) : 'which leaves none')
                            : "The $name string of \"$key\" is too long for one line of a mail: at most $room bytes, and its letters take more than one each - it has " . strlen($text),
                        $about
                    );
                }
                if ($strayBrace($text)) {
                    return $braceRefusal($name, $key, $placeholders, $about);
                }
                $missing = array_values(array_diff($placeholders, $used));
                if ($missing !== []) {
                    return $invalid('placeholder_missing', "The $name string of \"$key\" must keep {{$missing[0]}}: the IAM puts a value there", $about + ['placeholder' => $missing[0]]);
                }
                $unknown = array_values(array_diff($used, $placeholders));
                if ($unknown !== []) {
                    return $invalid('unknown_placeholder', "The $name string of \"$key\" cannot use {{$unknown[0]}}: the IAM has no value for it there", $about + ['placeholder' => $unknown[0]]);
                }
                if (preg_match(TRANSLATION_MAIL_ADDRESS, $text)) {
                    return $invalid('address_in_mail', "The $name string of \"$key\" reads as if it had an address in it. A mail's only link is the one the IAM puts in.", $about);
                }

                $changes[] = ['key' => $key, 'language' => $code, 'value' => $text];
                continue;
            }

            if (textLength($text) > TRANSLATION_VALUE_MAX) {
                return $invalid('invalid_value', "The $name string of \"$key\" is longer than " . TRANSLATION_VALUE_MAX . ' characters', $about);
            }
            if ($strayBrace($text)) {
                return $braceRefusal($name, $key, $placeholders, $about);
            }

            $missing = array_values(array_diff(TRANSLATION_REQUIRED_PLACEHOLDERS[$key] ?? [], $used));
            if ($missing !== []) {
                return $invalid('placeholder_missing', "The $name string of \"$key\" must keep {{$missing[0]}}: the page sets what stands there apart from the rest of the sentence", $about + ['placeholder' => $missing[0]]);
            }

            if ($code === TRANSLATION_FALLBACK) {
                $changed = array_merge(array_diff($placeholders, $used), array_diff($used, $placeholders));
                if ($changed !== []) {
                    $placeholder = $changed[0];

                    return $invalid(
                        'placeholder_changed',
                        in_array($placeholder, $placeholders, true)
                            ? "The English string of \"$key\" must keep {{$placeholder}}: the page puts a value there"
                            : "The English string of \"$key\" cannot use {{$placeholder}}: the page has no value for it",
                        $about + ['placeholder' => $placeholder]
                    );
                }
            } else {
                $unknown = array_values(array_diff($used, $placeholders));
                if ($unknown !== []) {
                    return $invalid('unknown_placeholder', "The $name string of \"$key\" uses {{$unknown[0]}}, which its English string does not have", $about + ['placeholder' => $unknown[0]]);
                }
            }

            $changes[] = ['key' => $key, 'language' => $code, 'value' => $text];
        }
    }

    return ['changes' => $changes];
}

/**
 * Keeps strings: {key: {language: text}}, only the ones that changed. All of
 * it is checked first (translationsCheck) and written in one transaction, or
 * none of it. A string sent as it already is stays as it was - with who wrote
 * it and when. Two people changing the same string: the last save is kept.
 *
 * $userId is who is saving - the session's account - or null for the command line.
 *
 * What comes back is what the audit log keeps of it: the languages touched,
 * how many strings are now different, the names of their keys (the first
 * hundred, then how many more), and for every changed `mail.` string the old
 * and the new text - they are the words of a password reset, and who changed
 * them to what must be answerable afterwards. They are public words, not secrets.
 *
 * @return array{languages: string[], count: int, keys: string[], more_keys?: int, mail?: array}|array a refusal
 */
function translationsSave(PDO $pdo, mixed $values, ?int $userId): array
{
    $written = [];

    $pdo->beginTransaction();
    try {
        // FOR SHARE: no language is deleted between the check and the writing.
        $languages = array_column($pdo->query('SELECT code, name FROM languages FOR SHARE')->fetchAll(), 'name', 'code');
        $keys = $pdo->query('SELECT name FROM translation_keys')->fetchAll(PDO::FETCH_COLUMN);

        $checked = translationsCheck($values, $keys, $languages, translationsOf($pdo, TRANSLATION_FALLBACK));
        if (translationRefused($checked)) {
            $pdo->rollBack();

            return $checked;
        }

        $stored = [];
        foreach ($pdo->query('SELECT key_name, language_code, value FROM translations FOR UPDATE')->fetchAll() as $row) {
            $stored[$row['key_name']][$row['language_code']] = $row['value'];
        }

        $delete = $pdo->prepare('DELETE FROM translations WHERE key_name = ? AND language_code = ?');
        $upsert = $pdo->prepare(
            'INSERT INTO translations (key_name, language_code, value, updated_by) VALUES (?, ?, ?, ?)
             ON CONFLICT (key_name, language_code) DO UPDATE
                SET value = EXCLUDED.value, updated_at = CURRENT_TIMESTAMP, updated_by = EXCLUDED.updated_by'
        );

        foreach ($checked['changes'] as $change) {
            $old = $stored[$change['key']][$change['language']] ?? null;
            if ($old === $change['value']) {
                continue; // as it already is: whose it was, and from when
            }

            if ($change['value'] === null) {
                $delete->execute([$change['key'], $change['language']]);
            } else {
                $upsert->execute([$change['key'], $change['language'], $change['value'], $userId]);
            }

            $written[] = ['key' => $change['key'], 'language' => $change['language'], 'old' => $old, 'new' => $change['value']];
        }

        $pdo->commit();
    } catch (\Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    $names = array_values(array_unique(array_column($written, 'key')));
    sort($names, SORT_STRING);
    $codes = array_values(array_unique(array_column($written, 'language')));
    sort($codes, SORT_STRING);

    $summary = ['languages' => $codes, 'count' => count($written), 'keys' => array_slice($names, 0, TRANSLATION_AUDIT_KEYS)];
    if (count($names) > TRANSLATION_AUDIT_KEYS) {
        $summary['more_keys'] = count($names) - TRANSLATION_AUDIT_KEYS;
    }

    $clip = static fn(?string $text): ?string => $text === null ? null : textClip($text, TRANSLATION_AUDIT_TEXT);
    $mail = [];
    foreach ($written as $cell) {
        if (str_starts_with($cell['key'], 'mail.')) {
            $mail[] = ['key' => $cell['key'], 'language' => $cell['language'], 'old' => $clip($cell['old']), 'new' => $clip($cell['new'])];
        }
    }
    if ($mail !== []) {
        $summary['mail'] = $mail;
    }

    return $summary;
}

/**
 * A language's own strings as a flat {key: text} - not merged with English,
 * the `mail.` ones among them, and {} when it has none.
 *
 * @return array{values: object}|array a refusal
 */
function translationsExport(PDO $pdo, mixed $code): array
{
    if (languageRow($pdo, $code) === null) {
        return languageNotFound();
    }

    return ['values' => translationMap(translationsOf($pdo, $code))];
}

/**
 * A language's strings back in, from a flat {key: text}: the same checks as a
 * save. An empty string is a key nobody has translated yet - as an export
 * filled in by hand leaves it - and is passed over: an import deletes nothing.
 * A key the IAM does not have is refused, empty or not.
 *
 * @return array what translationsSave() answers, or a refusal
 */
function translationsImport(PDO $pdo, mixed $code, mixed $body, ?int $userId): array
{
    if (languageRow($pdo, $code) === null) {
        return languageNotFound();
    }
    if (!is_array($body) || ($body !== [] && array_is_list($body))) {
        return translationRefusal(422, 'invalid_value', 'Send the strings as one flat object: {key: text}');
    }

    $values = [];
    foreach ($body as $key => $text) {
        $clean = textClean($text);
        $values[$key] = $clean !== null && textBlank($clean) ? [] : [$code => $text];
    }

    return translationsSave($pdo, $values, $userId);
}
