<?php

use Anotoki\Lib\Support\Refusal;
use Anotoki\Lib\Translations\Cli\KeyScanner;
use Anotoki\Lib\Translations\KeyRules;
use Anotoki\Lib\Translations\LanguageUsage;
use Anotoki\Lib\Translations\Placeholders;
use Anotoki\Lib\Translations\Rules\RequiredPlaceholders;
use Anotoki\Lib\Translations\Schema;
use Anotoki\Lib\Translations\Text;
use Anotoki\Lib\Translations\Translations;
use Anotoki\Lib\Translations\TranslationsConfig;
use Psr\Http\Message\ResponseInterface as Response;

/**
 * The IAM's own words - the languages it speaks, the strings a page asks for
 * by key, the words of its mails, and the grid the admin panel edits them in -
 * through the anotoki family's library (anotoki/lib's Anotoki\Lib\Translations,
 * which was built from the IAM's own code). This file is what is the IAM's
 * about them: the library's configuration, the rules of its mails, and how
 * the routes answer (api/routes/login.php has the bundle, api/routes/admin.php
 * the admin panel's eight routes).
 *
 * What the library does, as the IAM did before: a key is the code's -
 * migrations write the keys and their first strings (migrations/iam/006 and
 * after; the library's own under `anotoki.`, in its set anotoki_translations,
 * which runs after the IAM's) - and the panel only ever changes a string.
 * English is what every other language falls back to, key by key, so it cannot
 * be switched off, deleted, or left without a string. A string is text and is
 * drawn as text - never HTML. What stands in for a value is a {placeholder};
 * which ones a page's key has is fixed by its English string, which ones a
 * library key has by the library's English.
 *
 * What is the IAM's own:
 *
 *   - The mails' words (`mail.*`, api/mail.php) are the server's: never in a
 *     bundle, and held to more than a page's - they go out to a mailbox under
 *     the IAM's name (IamMailRules). Which placeholders a mail key has is
 *     MAIL_KEYS, the code, not the stored English. A save keeps every
 *     changed mail string's old and new text whole in the audit log, each in
 *     an entry of its own (auditTranslations()).
 *   - The two sentences the sign-in and registration pages cut around {app}
 *     keep it in every language (TRANSLATION_REQUIRED_PLACEHOLDERS).
 *   - English and Slovak are the languages the IAM is released in: the
 *     migrations write strings for them, so neither can be deleted.
 *   - Each language says how many accounts have it (IamLanguageUsage); a
 *     language deleted sends them back to English (users.language's foreign
 *     key, ON DELETE SET DEFAULT).
 *   - Refusals answer in the IAM's body, {error, code, ...extra}
 *     (translationRefusalAnswer()).
 *
 * The list of offered codes the rest of the IAM reads is languageCodes() in
 * api/helpers.php, which works before these tables exist.
 */

/** The language every other one falls back to. */
const TRANSLATION_FALLBACK = Translations::FALLBACK;

/**
 * The languages the migrations write strings for. They cannot be deleted: the
 * next migration with strings would fail on the missing language, on a host
 * with no shell to mend it from. tests/translations.test.php keeps this true.
 */
const TRANSLATION_SEEDED_LANGUAGES = ['en', 'sk'];

/** The namespace of the keys only the server reads: the mails' words. */
const TRANSLATION_SERVER_NAMESPACE = 'mail';

/** A placeholder in a string: {name}. The family's expression - the pages', mailFill()'s, the announcements'. */
const TRANSLATION_PLACEHOLDER = Placeholders::PATTERN;

/**
 * Page keys that must keep a placeholder in every language: the sign-in and
 * registration pages cut these two sentences around {app} to set the
 * application's name apart, and a string without it would lose the name.
 */
const TRANSLATION_REQUIRED_PLACEHOLDERS = [
    'login.continueTo' => ['app'],
    'register.forApp' => ['app'],
];

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
 * two, and with three there is no room left at all. A {date} (the deletion
 * mail's day, mailDate()) is ten bytes at most - "2026-11-06" - and its ten
 * are kept the same way. The other values - a number of hours or minutes,
 * the code - are no longer than the placeholders they stand in for.
 */
const TRANSLATION_MAIL_LINE_OCTETS = 900;
const TRANSLATION_MAIL_USERNAME_OCTETS = 400;
const TRANSLATION_MAIL_DATE_OCTETS = 10;

/**
 * What an address looks like in a mail's words: a scheme, "www.", or an @.
 * A mail's only link is the one the server puts in; a second one, written by
 * whoever can edit strings, would be a phishing mail sent by the IAM itself.
 * It stops the obvious link, not a bare domain - the audit log, which keeps
 * the old and the new text of every changed mail string, is what catches the rest.
 */
const TRANSLATION_MAIL_ADDRESS = '~(://|\bwww\.|@)~i';

/**
 * Characters a mail's words never hold: the ones that turn the direction of
 * the text, or of a part of it - the embeddings and overrides (U+202A-202E),
 * the isolates (U+2066-2069), the left-to-right and right-to-left marks
 * (U+200E, U+200F) and the Arabic letter mark (U+061C) - and the C1 control
 * characters (U+0080-009F). The panel's checks read a string in the order it
 * is stored, and a mail program draws it in the order those characters say: a
 * right-to-left override before "moc.live//:sptth" passes as no address and
 * shows the reader one. A control character is no letter of any language, and
 * a mail program may draw it as anything at all.
 */
const TRANSLATION_MAIL_TURNING = '/[\x{0080}-\x{009F}\x{061C}\x{200E}\x{200F}\x{202A}-\x{202E}\x{2066}-\x{2069}]/u';

/** The frontend's compiled English, the list of its keys - under frontend/src/app. */
const TRANSLATION_DICTIONARY_FILE = 'core/i18n/en.ts';

/**
 * The rules of the mails' words, beyond every string's (the library's
 * StringCheck asks them, in the order KeyRules describes - the IAM's order).
 * Only `mail.` keys; every other key is not this rule's.
 *
 * Before the family's checks of the length and the braces (checkFirst()):
 * one line - a line break in a subject is how a header is smuggled in, and one
 * in a paragraph breaks the message's shape, so it is refused, not dropped -
 * then no character that turns the text's direction and no C1 control
 * (TRANSLATION_MAIL_TURNING: the address check below reads the stored order,
 * the reader the drawn one), then the length (150 for a subject, 400 for the
 * rest), then the bytes of one line of mail. After them (check()): exactly the
 * placeholders MAIL_KEYS gives the key, in every language, and no address.
 */
final class IamMailRules implements KeyRules
{
    /** @param array<string, list<string>> $placeholders  MAIL_KEYS: each mail key's placeholders */
    public function __construct(private readonly array $placeholders) {}

    public function placeholders(string $key): ?array
    {
        return self::isMail($key) ? array_values($this->placeholders[$key] ?? []) : null;
    }

    public function checkFirst(string $key, string $code, string $text, string $languageName, array $placeholders): ?Refusal
    {
        if (!self::isMail($key)) {
            return null;
        }
        $about = ['key' => $key, 'language' => $code];

        if (preg_match('/[\r\n\t\x{0085}\x{2028}\x{2029}]/u', $text) === 1) {
            return Refusal::invalid('invalid_value', "The $languageName string of \"$key\" must be one line: a mail's words have no line breaks or tabs", $about);
        }
        // Refused, not dropped, as a line break is: what the panel showed is what goes out.
        if (preg_match(TRANSLATION_MAIL_TURNING, $text) === 1) {
            return Refusal::invalid(
                'invalid_value',
                "The $languageName string of \"$key\" has a character that turns the direction of the text, or a control character: a mail program would show its words in another order than the panel checks them in",
                $about,
            );
        }
        $max = str_ends_with($key, '.subject') ? TRANSLATION_MAIL_SUBJECT_MAX : TRANSLATION_MAIL_VALUE_MAX;
        if (Text::length($text) > $max) {
            return Refusal::invalid('invalid_value', "The $languageName string of \"$key\" is longer than $max characters", $about);
        }

        // In bytes: the string is one line of the message as sent. Where the
        // key takes the username, room is kept for it as often as the string
        // names it - every {username} is filled, and each may become 400
        // bytes. A {date}'s ten, too. Counted only where the code fills it:
        // elsewhere {username} stays seven letters in braces, and is refused
        // as that below.
        $named = in_array('username', $placeholders, true) ? substr_count($text, '{username}') : 0;
        $dated = in_array('date', $placeholders, true) ? substr_count($text, '{date}') : 0;
        $room = TRANSLATION_MAIL_LINE_OCTETS - $named * TRANSLATION_MAIL_USERNAME_OCTETS - $dated * TRANSLATION_MAIL_DATE_OCTETS;
        if (strlen($text) > $room) {
            return Refusal::invalid(
                'invalid_value',
                $named > 1
                    ? "The $languageName string of \"$key\" is too long for one line of a mail: it has {username} $named times, and room for the longest name there can be is kept for each - " .
                        ($room > 0 ? "which leaves $room bytes, and it has " . strlen($text) : 'which leaves none')
                    : "The $languageName string of \"$key\" is too long for one line of a mail: at most $room bytes, and its letters take more than one each - it has " . strlen($text),
                $about,
            );
        }

        return null;
    }

    public function check(string $key, string $code, string $text, string $languageName, array $placeholders): ?Refusal
    {
        if (!self::isMail($key)) {
            return null;
        }
        $about = ['key' => $key, 'language' => $code];
        $used = Placeholders::names($text);

        $missing = array_values(array_diff($placeholders, $used));
        if ($missing !== []) {
            return Refusal::invalid('placeholder_missing', "The $languageName string of \"$key\" must keep {{$missing[0]}}: the IAM puts a value there", $about + ['placeholder' => $missing[0]]);
        }
        $unknown = array_values(array_diff($used, $placeholders));
        if ($unknown !== []) {
            return Refusal::invalid(
                'unknown_placeholder',
                "The $languageName string of \"$key\" cannot use {{$unknown[0]}}: the IAM has no value for it there",
                $about + ['placeholder' => $unknown[0]],
            );
        }
        if (preg_match(TRANSLATION_MAIL_ADDRESS, $text) === 1) {
            return Refusal::invalid('address_in_mail', "The $languageName string of \"$key\" reads as if it had an address in it. A mail's only link is the one the IAM puts in.", $about);
        }

        return null;
    }

    private static function isMail(string $key): bool
    {
        return str_starts_with($key, TRANSLATION_SERVER_NAMESPACE . '.');
    }
}

/**
 * How many accounts have each language - the admin panel's `accounts`, beside
 * the library's fields of a language, and in the answer and the audit entry of
 * a delete (counted under the language's lock, so it is how many went back to
 * English). The IAM refuses no delete for its accounts, and does nothing before
 * one: the foreign key sends them to English.
 */
final class IamLanguageUsage implements LanguageUsage
{
    public function extras(PDO $pdo, array $codes): array
    {
        if ($codes === []) {
            return [];
        }

        $extras = array_fill_keys($codes, ['accounts' => 0]);
        $statement = $pdo->prepare('SELECT language, count(*) FROM users WHERE language IN (' . implode(', ', array_fill(0, count($codes), '?')) . ') GROUP BY language');
        $statement->execute(array_values($codes));
        foreach ($statement->fetchAll(PDO::FETCH_NUM) as [$code, $count]) {
            $extras[(string) $code]['accounts'] = (int) $count;
        }

        return $extras;
    }

    public function refuseDelete(PDO $pdo, string $code): ?Refusal
    {
        return null;
    }

    public function beforeDelete(PDO $pdo, string $code, array $strings): array
    {
        return [];
    }
}

/** The library's configuration, as the IAM has it. */
function iamTranslationsConfig(): TranslationsConfig
{
    static $config = null;

    return $config ??= new TranslationsConfig(
        releasedLanguages: TRANSLATION_SEEDED_LANGUAGES,
        serverNamespaces: [TRANSLATION_SERVER_NAMESPACE],
        rules: [new IamMailRules(MAIL_KEYS), new RequiredPlaceholders(TRANSLATION_REQUIRED_PLACEHOLDERS)],
        usage: new IamLanguageUsage(),
        // A changed mail string is kept whole in the audit log: "whole" is as
        // long as the panel lets a mail string be. The log is what says
        // afterwards who made a reset mail say what, and a sentence added at
        // the end of a paragraph must not be the part it leaves out - once the
        // string is put back, the log is the only place that sentence is
        // kept. The clip is for a string that reached the table without the
        // panel (SQL), which can be of any length and must not bloat the log.
        auditTextMax: TRANSLATION_MAIL_VALUE_MAX,
    );
}

/** The IAM's words on a connection - its own database unless another is handed in. */
function iamTranslations(?PDO $pdo = null): Translations
{
    return new Translations($pdo ?? iamDb(), iamTranslationsConfig());
}

/**
 * Whether the tables are there: a build is uploaded before its migrations are
 * applied, and this runs on a database that has not heard of 006 yet. A query
 * that cannot fail, so it is safe inside a transaction too.
 */
function translationsReady(PDO $pdo): bool
{
    return Schema::ready($pdo);
}

/** A refusal of the library's, in the IAM's one error body: {error, code, ...extra}. */
function translationRefusalAnswer(Response $response, Refusal $refusal): Response
{
    return respondError($response, $refusal->status, $refusal->code, $refusal->message, $refusal->extra);
}

/**
 * A change of the strings into the audit log - the admin panel's save and
 * import, and the command line's import - as the library's routes and command
 * hand it over: the summary (the languages, how many strings changed, the first
 * hundred keys) in the entry of the change itself, and every changed mail
 * string's old and new text whole in an entry of its own,
 * `translations.mail_changed` (its key the target; the language, the old and
 * the new text, and the change it was part of in its details).
 *
 * One entry keeps at most 64 KB of details, and the library's AuditLog cuts
 * details that do not fit - its texts shorter, its lists to their first
 * items, then its keys alone. A save of every mail string, long, in two
 * languages is more than that: kept as one entry it would keep none of them
 * whole - and padding a save so would cut the one rewording that matters. A
 * mail string is at most 400 characters (the library clips one that came past
 * the panel), so each entry of its own fits.
 */
function auditTranslations(?\Psr\Http\Message\ServerRequestInterface $request, ?int $actorId, string $action, ?string $type, ?string $id, array $details): void
{
    $mails = $details[TRANSLATION_SERVER_NAMESPACE] ?? null;
    if (!is_array($mails) || $mails === []) {
        audit($request, $actorId, $action, $type, $id, $details);

        return;
    }

    unset($details[TRANSLATION_SERVER_NAMESPACE]);
    audit($request, $actorId, $action, $type, $id, $details + ['mail_changed' => count($mails)]);
    foreach ($mails as $cell) {
        audit(
            $request,
            $actorId,
            'translations.mail_changed',
            'translation',
            is_string($cell['key'] ?? null) ? $cell['key'] : null,
            [
                'language' => $cell['language'] ?? null,
                'old' => $cell['old'] ?? null,
                'new' => $cell['new'] ?? null,
                'part_of' => $action,
            ] + (isset($details['via']) ? ['via' => $details['via']] : []),
        );
    }
}

// ── For the command line and the tests - never the running IAM ──────────────

/** The frontend's sources, seen from here (frontend/src/app): on a computer with the repository only. */
function iamFrontendSources(): string
{
    return str_replace('\\', '/', dirname(__DIR__, 2)) . '/frontend/src/app';
}

/**
 * The family's scan of a frontend (anotoki/lib's KeyScanner), set for the
 * IAM's: every .ts and .html under frontend/src/app but the specs and en.ts
 * itself (which lists every key and uses none); the `t` pipe; the mails'
 * keys, which are the server's, and the library's `anotoki.` ones, which the
 * library's own pages ask for, never looked for; and innerHTML anywhere fails
 * the scan - a string is text, and the IAM's pages have no use for HTML at
 * all. There are no plural families in the IAM and no keys put together at
 * run time: a key built from parts cannot be seen in the source - the scan
 * refuses one put together from a literal that ends in a dot - and a key
 * nothing names shows as unused. That is on purpose.
 *
 * $root is for the tests, which hold this very configuration to sources of
 * their own.
 */
function iamKeyScanner(?string $root = null): KeyScanner
{
    return new KeyScanner($root ?? iamFrontendSources(), exclude: [TRANSLATION_DICTIONARY_FILE], ignoreNamespaces: [TRANSLATION_SERVER_NAMESPACE], pipes: ['t' => 'one'], innerHtml: 'anywhere');
}
