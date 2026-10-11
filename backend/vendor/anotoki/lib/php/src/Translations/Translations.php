<?php

declare(strict_types=1);

namespace Anotoki\Lib\Translations;

use Anotoki\Lib\Support\Refusal;
use InvalidArgumentException;
use PDO;
use Throwable;

/**
 * A site's words, in the languages it speaks: the languages, the strings a
 * page asks for by key, and the grid the admin pages edit them in - on the
 * family's one schema (Schema).
 *
 * A key is the code's: migrations write the keys and their first strings, the
 * admin pages only ever change a string. English is what every other language
 * falls back to, key by key, so it cannot be switched off, deleted or left
 * without a string. The library's own keys are under `anotoki.` (LibraryWords).
 *
 * Nothing here throws for a refusal: a method answers with what it was asked
 * for, or with a Refusal, which the routes turn into the site's error body.
 * What it does throw is the database's failure. Built per request from a
 * connection that throws on errors (PDO::ERRMODE_EXCEPTION), as the Migrator is.
 */
final class Translations
{
    /** The language every other one falls back to. */
    public const FALLBACK = 'en';

    private const LANGUAGE = 'l.code, l.name, l.native_name, l.enabled, l.sort_order,
        (SELECT count(*) FROM translations t WHERE t.language_code = l.code) AS strings';

    private readonly TranslationsConfig $config;

    public function __construct(private readonly PDO $pdo, ?TranslationsConfig $config = null)
    {
        if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'pgsql') {
            throw new InvalidArgumentException('Translations work on PostgreSQL: give them a pgsql: connection.');
        }
        if ($pdo->getAttribute(PDO::ATTR_ERRMODE) !== PDO::ERRMODE_EXCEPTION) {
            throw new InvalidArgumentException('Translations need PDO::ERRMODE_EXCEPTION on their connection: a failed write must not pass unnoticed.');
        }
        $this->config = $config ?? new TranslationsConfig();
    }

    public function config(): TranslationsConfig
    {
        return $this->config;
    }

    /** Are the tables there (Schema::ready())? */
    public function ready(): bool
    {
        return Schema::ready($this->pdo);
    }

    /**
     * Not a 500 - this is expected between an upload and Apply, and a 500 would
     * be logged on every page load - and not not_set_up, which sends the pages to
     * the setup page. The pages read the English compiled into them meanwhile.
     */
    public static function unavailable(): Refusal
    {
        return new Refusal(503, 'translations_unavailable', 'The words are not in the database yet. An administrator applies the waiting database update on the Migrations page.');
    }

    public static function notFound(): Refusal
    {
        return new Refusal(404, 'not_found', 'There is no such language');
    }

    // ── Languages ────────────────────────────────────────────────────────────

    /**
     * The codes of the languages on offer, in the order a chooser shows them;
     * none while the tables are not there.
     *
     * @return list<string>
     */
    public function offeredCodes(): array
    {
        if (!$this->ready()) {
            return [];
        }

        return array_map('strval', $this->pdo->query('SELECT code FROM languages WHERE enabled ORDER BY sort_order, name, code')->fetchAll(PDO::FETCH_COLUMN));
    }

    /**
     * Every language, the ones switched off too, in the order a chooser shows
     * them, as the admin pages read them: {code, name, native_name, enabled,
     * sort_order, seeded, strings} and the site's own fields (LanguageUsage).
     *
     * @return list<array<string, mixed>>
     */
    public function languages(): array
    {
        return $this->shapes($this->pdo->query('SELECT ' . self::LANGUAGE . ' FROM languages l ORDER BY l.sort_order, l.name, l.code')->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * A new language, {code, name, native_name, enabled?}: after the ones there
     * are, and offered at once unless `enabled` says false - then it is there for
     * its strings to be written, and readers are offered it once somebody switches
     * it on. It has no strings yet, so offered at once it reads in English.
     *
     *   422 invalid_language     a code that is none; a name that is not one line of 1 to languageNameMax
     *                            characters; `enabled` not true or false; a body that is not an object
     *   409 language_exists      a code there is already
     *   422 too_many_languages   languagesMax there already
     *
     * @return array{language: array<string, mixed>}|Refusal
     */
    public function createLanguage(mixed $body): array|Refusal
    {
        if (!StringCheck::isMap($body)) {
            return self::invalidLanguage('Send the language as {code, name, native_name}');
        }

        $code = $body['code'] ?? null;
        if (!LanguageCode::ok($code)) {
            return self::invalidLanguage('A language\'s code is two small letters, or two and two with a dash between: "sk", "pt-br"');
        }
        $name = StringCheck::languageName($body['name'] ?? null, 'The name in English', 'name', $this->config, $code);
        if ($name instanceof Refusal) {
            return $name;
        }
        $nativeName = StringCheck::languageName($body['native_name'] ?? null, 'The name as its speakers write it', 'native_name', $this->config, $code);
        if ($nativeName instanceof Refusal) {
            return $nativeName;
        }
        // Left out, it is offered. Said, it is true or false and nothing that only
        // reads like one: "false" would otherwise be a language offered against its maker's word.
        $enabled = true;
        if (array_key_exists('enabled', $body)) {
            if (!is_bool($body['enabled'])) {
                return self::invalidLanguage('"enabled" is true or false');
            }
            $enabled = $body['enabled'];
        }

        $exists = new Refusal(409, 'language_exists', "There is a language \"$code\" already");

        $refusal = $this->transaction(function () use ($code, $name, $nativeName, $enabled, $exists): ?Refusal {
            // Whoever adds a language waits here for anybody else who is adding
            // one: the count below is then of rows that are really there, and the
            // most the site keeps holds. (Two transactions that only counted would
            // each miss the other's row, and both go past it.) Taken first, so
            // nothing is held while waiting. It stops only what writes the table:
            // the rows can still be read and held - a save of strings, say.
            $this->pdo->exec('LOCK TABLE languages IN SHARE ROW EXCLUSIVE MODE');

            $known = $this->pdo->prepare('SELECT 1 FROM languages WHERE code = ?');
            $known->execute([$code]);
            if ($known->fetchColumn() !== false) {
                return $exists;
            }
            if ((int) $this->pdo->query('SELECT count(*) FROM languages')->fetchColumn() >= $this->config->languagesMax) {
                return Refusal::invalid('too_many_languages', "The site keeps at most {$this->config->languagesMax} languages. Delete one that is not used first.");
            }
            $sortOrder = min((int) $this->pdo->query('SELECT COALESCE(MAX(sort_order), 0) + 1 FROM languages')->fetchColumn(), $this->config->sortOrderMax);

            // A row written past this method in this very moment: no row comes
            // back, and no error that would end the transaction.
            $insert = $this->pdo->prepare(
                'INSERT INTO languages (code, name, native_name, enabled, sort_order) VALUES (?, ?, ?, ?, ?)
                 ON CONFLICT (code) DO NOTHING RETURNING code'
            );
            $insert->execute([$code, $name, $nativeName, $enabled ? 'true' : 'false', $sortOrder]);

            return $insert->fetchColumn() === false ? $exists : null;
        });
        if ($refusal !== null) {
            return $refusal;
        }

        return ['language' => $this->language($code) ?? throw new \LogicException("The language $code was made and is not there.")];
    }

    /**
     * Changes a language: {name?, native_name?, enabled?, sort_order?} - what is
     * not sent stays. English cannot be switched off; any other language can, at
     * any time: it keeps its strings, and is only no longer offered.
     *
     *   404 not_found, 422 invalid_language (a sort_order that is not a whole number
     *   from 0 to sortOrderMax among them), 409 fallback_language (English switched off)
     *
     * @return array{language: array<string, mixed>, changed: array<string, mixed>}|Refusal  changed: what is
     *         now different, for the audit log
     */
    public function updateLanguage(string $code, mixed $body): array|Refusal
    {
        $row = LanguageCode::ok($code) ? $this->row($code) : null;
        if ($row === null) {
            return self::notFound();
        }
        if (!StringCheck::isMap($body)) {
            return self::invalidLanguage('Send what changes as {name, native_name, enabled, sort_order}');
        }

        $changes = [];
        foreach (['name' => 'The name in English', 'native_name' => 'The name as its speakers write it'] as $column => $what) {
            if (array_key_exists($column, $body)) {
                $text = StringCheck::languageName($body[$column], $what, $column, $this->config, $code);
                if ($text instanceof Refusal) {
                    return $text;
                }
                if ($text !== (string) $row[$column]) {
                    $changes[$column] = $text;
                }
            }
        }

        if (array_key_exists('enabled', $body)) {
            if (!is_bool($body['enabled'])) {
                return self::invalidLanguage('"enabled" is true or false');
            }
            if ($code === self::FALLBACK && !$body['enabled']) {
                return new Refusal(409, 'fallback_language', 'English cannot be switched off: every other language falls back to it');
            }
            if ($body['enabled'] !== self::bool($row['enabled'])) {
                $changes['enabled'] = $body['enabled'];
            }
        }

        if (array_key_exists('sort_order', $body)) {
            if (!is_int($body['sort_order']) || $body['sort_order'] < 0 || $body['sort_order'] > $this->config->sortOrderMax) {
                return self::invalidLanguage("The order is a whole number from 0 to {$this->config->sortOrderMax}");
            }
            if ($body['sort_order'] !== (int) $row['sort_order']) {
                $changes['sort_order'] = $body['sort_order'];
            }
        }

        if ($changes !== []) {
            // The columns are this method's own names, never a body's.
            $sets = implode(', ', array_map(static fn (string $column): string => $column . ' = ?', array_keys($changes)));
            $values = array_map(static fn (mixed $value): mixed => is_bool($value) ? ($value ? 'true' : 'false') : $value, array_values($changes));
            $this->pdo->prepare("UPDATE languages SET $sets WHERE code = ?")->execute([...$values, $code]);
        }

        $language = $this->language($code);

        return $language === null ? self::notFound() : ['language' => $language, 'changed' => $changes];
    }

    /**
     * Deletes a language and its strings (the foreign key cascades); a site's
     * tables that refer to it follow their own foreign keys. Never English, never
     * a released language (TranslationsConfig::$releasedLanguages): the next
     * migration with strings would fail without it - switch it off instead.
     *
     *   404 not_found, 409 fallback_language, 409 seeded_language, the site's own
     *   refusal (LanguageUsage::refuseDelete(): 409 language_in_use, say)
     *
     * @return array{strings: int, extras: array<string, int|string>, language: array<string, mixed>}|Refusal
     *         strings: how many went with it; extras: the site's fields of the language as it was, and what
     *         LanguageUsage::beforeDelete() answered
     */
    public function deleteLanguage(string $code): array|Refusal
    {
        if (!LanguageCode::ok($code)) {
            return self::notFound();
        }
        if ($code === self::FALLBACK) {
            return new Refusal(409, 'fallback_language', 'English cannot be deleted: every other language falls back to it');
        }
        if (in_array($code, $this->config->releasedLanguages, true)) {
            return new Refusal(409, 'seeded_language', 'This language is one the site is released in: every update brings strings for it, and would fail without it. Switch it off instead.');
        }

        $result = null;
        $refusal = $this->transaction(function () use ($code, &$result): ?Refusal {
            // The row held first: a save of strings holds the languages FOR SHARE,
            // so this waits until such a save is kept - and the count below is of
            // the strings that are really there. Nobody takes the language meanwhile.
            $locked = $this->pdo->prepare('SELECT ' . self::LANGUAGE . ' FROM languages l WHERE l.code = ? FOR UPDATE OF l');
            $locked->execute([$code]);
            $row = $locked->fetch(PDO::FETCH_ASSOC);
            if ($row === false) {
                return self::notFound();
            }

            $usage = $this->config->usage;
            $refused = $usage?->refuseDelete($this->pdo, $code);
            if ($refused !== null) {
                return $refused;
            }

            $extras = $usage === null ? [] : ($usage->extras($this->pdo, [$code])[$code] ?? []);
            $strings = $this->own($code, false);
            $extras = ($usage === null ? [] : $usage->beforeDelete($this->pdo, $code, $strings)) + $extras;

            $this->pdo->prepare('DELETE FROM languages WHERE code = ?')->execute([$code]);

            $result = ['strings' => count($strings), 'extras' => $extras, 'language' => $this->shape($row, $extras)];

            return null;
        });

        return $refusal ?? $result;
    }

    // ── The strings a page asks for ──────────────────────────────────────────

    /**
     * What the pages read their words from: GET /api/translations/{code}.
     * `values` is the language's strings with English under them, so a key with
     * no string in it reads in English; `languages` the ones offered; `english`
     * the fallback's own strings, for the admin pages, which are English whatever
     * the reader chose - left out when that is what `values` is. No key of a
     * server namespace is in either map.
     *
     * `language` is the language it is in (LanguageCode::resolve()): "sk-sk" is
     * answered by "sk" when only that is offered - the same bytes, under the same
     * tag - and what the site does not offer, or has switched off, by English.
     *
     * Without the tables, or with no English string at all, there is nothing to
     * answer with: 503 translations_unavailable, and the pages keep their compiled
     * English. A code that is none: 404, before any query.
     *
     * @return array{language: string, languages: list<array{code: string, name: string, native_name: string}>, values: object, english?: object}|Refusal
     */
    public function bundle(string $code): array|Refusal
    {
        if (!LanguageCode::ok($code)) {
            return self::notFound();
        }
        if (!$this->ready()) {
            return self::unavailable();
        }

        $english = $this->own(self::FALLBACK, true);
        if ($english === []) {
            return self::unavailable();
        }

        $offered = $this->pdo->query('SELECT code, name, native_name FROM languages WHERE enabled ORDER BY sort_order, name, code')->fetchAll(PDO::FETCH_ASSOC);
        $language = LanguageCode::resolve($code, array_map('strval', array_column($offered, 'code')), self::FALLBACK);

        $bundle = [
            'language' => $language,
            'languages' => array_map(static fn (array $row): array => ['code' => (string) $row['code'], 'name' => (string) $row['name'], 'native_name' => (string) $row['native_name']], $offered),
        ];

        if ($language === self::FALLBACK) {
            $bundle['values'] = (object) $english;

            return $bundle;
        }

        $values = $this->own($language, true) + $english;
        ksort($values, SORT_STRING);
        $bundle['values'] = (object) $values;
        $bundle['english'] = (object) $english;

        return $bundle;
    }

    // ── The admin pages' grid ────────────────────────────────────────────────

    /**
     * Every key with its strings in every language, and the languages:
     * {languages: [all], keys: [{name, description, values: {code: text}}]}, the
     * keys by name (so a plural's forms are together) - the server namespaces'
     * among them.
     *
     * @return array{languages: list<array<string, mixed>>, keys: list<array{name: string, description: string, values: object}>}
     */
    public function grid(): array
    {
        $keys = [];
        foreach ($this->pdo->query('SELECT name, description FROM translation_keys ORDER BY name COLLATE "C"')->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $keys[(string) $row['name']] = ['name' => (string) $row['name'], 'description' => (string) ($row['description'] ?? ''), 'values' => []];
        }
        foreach ($this->pdo->query('SELECT key_name, language_code, value FROM translations ORDER BY key_name COLLATE "C", language_code COLLATE "C"')->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (isset($keys[(string) $row['key_name']])) {
                $keys[(string) $row['key_name']]['values'][(string) $row['language_code']] = (string) $row['value'];
            }
        }

        return [
            'languages' => $this->languages(),
            'keys' => array_map(
                static fn (array $key): array => ['name' => $key['name'], 'description' => $key['description'], 'values' => (object) $key['values']],
                array_values($keys)
            ),
        ];
    }

    /**
     * Keeps strings: {key: {language: text}}, only the ones that changed. All of
     * it is checked first (StringCheck) and written in one transaction, or none of
     * it. A string sent as it already is stays as it was - with who wrote it and
     * when. A blank string in another language deletes that language's string. Of
     * two people changing the same string, the last save is kept.
     *
     * $userId is who is saving - from the session or the token, never from a
     * body - or null for the command line.
     *
     * What comes back is what the audit log keeps of it: the languages touched,
     * how many strings are now different, the names of their keys (the first
     * auditKeys, then how many more), and for every changed string of a server
     * namespace its old and new text whole, under the namespace's name - the
     * IAM's mails: who changed them to what must be answerable afterwards.
     *
     * @return array{languages: list<string>, count: int, keys: list<string>, more_keys?: int}|Refusal
     */
    public function save(mixed $values, ?int $userId): array|Refusal
    {
        $written = [];

        $refusal = $this->transaction(function () use ($values, $userId, &$written): ?Refusal {
            if ($this->config->beforeWrite !== null) {
                ($this->config->beforeWrite)($this->pdo, $userId);
            }

            // FOR SHARE: no language is deleted between the check and the writing.
            $languages = [];
            foreach ($this->pdo->query('SELECT code, name FROM languages FOR SHARE')->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $languages[(string) $row['code']] = (string) $row['name'];
            }

            $changes = StringCheck::changes($values, $this->keyNames(), $languages, $this->own(self::FALLBACK, false), $this->config);
            if ($changes instanceof Refusal) {
                return $changes;
            }

            $stored = $this->pdo->prepare('SELECT value FROM translations WHERE key_name = ? AND language_code = ? FOR UPDATE');
            $delete = $this->pdo->prepare('DELETE FROM translations WHERE key_name = ? AND language_code = ?');
            $upsert = $this->pdo->prepare(
                'INSERT INTO translations (key_name, language_code, value, updated_by) VALUES (?, ?, ?, ?)
                 ON CONFLICT (key_name, language_code) DO UPDATE
                    SET value = EXCLUDED.value, updated_at = CURRENT_TIMESTAMP, updated_by = EXCLUDED.updated_by
                  WHERE translations.value IS DISTINCT FROM EXCLUDED.value'
            );

            foreach ($changes as $change) {
                $stored->execute([$change['key'], $change['language']]);
                $old = $stored->fetchColumn();
                $old = $old === false ? null : (string) $old;
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

            return null;
        });

        return $refusal ?? $this->summary($written);
    }

    /**
     * A language's own strings as a flat {key: text} - not merged with English,
     * the server namespaces' among them, and {} when it has none.
     *
     * @return array{values: object}|Refusal
     */
    public function export(string $code): array|Refusal
    {
        if (!LanguageCode::ok($code) || $this->row($code) === null) {
            return self::notFound();
        }

        return ['values' => (object) $this->own($code, false)];
    }

    /**
     * A language's strings back in, from a flat {key: text}: the same checks as a
     * save. An empty string is a key nobody has translated yet - as an export
     * filled in by hand leaves it - and is passed over: an import deletes nothing,
     * English included. A key the site does not have is refused, empty or not.
     *
     * @return array{languages: list<string>, count: int, keys: list<string>, more_keys?: int}|Refusal what save() answers
     */
    public function import(string $code, mixed $body, ?int $userId): array|Refusal
    {
        if (!LanguageCode::ok($code) || $this->row($code) === null) {
            return self::notFound();
        }
        if (!StringCheck::isMap($body)) {
            return Refusal::invalid('invalid_value', 'Send the strings as one flat object: {key: text}');
        }

        $values = [];
        foreach ($body as $key => $text) {
            $clean = Text::clean($text);
            $values[$key] = $clean !== null && Text::blank($clean) ? [] : [$code => $text];
        }

        return $this->save($values, $userId);
    }

    /**
     * A person's data is deleted: the strings they saved stay, saved by nobody.
     * Nothing to do while the tables are not there. Answers how many it changed.
     */
    public function forgetWriter(int $userId): int
    {
        if (!$this->ready()) {
            return 0;
        }

        $statement = $this->pdo->prepare('UPDATE translations SET updated_by = NULL WHERE updated_by = ?');
        $statement->execute([$userId]);

        return $statement->rowCount();
    }

    /**
     * Every stored string a save would refuse as it stands - one a migration or
     * SQL wrote past the checks, or one the checks have since grown stricter
     * for (an owner's rewording of a library string without its {code}). For
     * the command line's --status and a site's catalogue test.
     *
     * @return list<array{key: string, language: string, code: string, message: string}>
     */
    public function storedRefusals(): array
    {
        $languages = [];
        foreach ($this->pdo->query('SELECT code, name FROM languages')->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $languages[(string) $row['code']] = (string) $row['name'];
        }
        $keys = $this->keyNames();
        $english = $this->own(self::FALLBACK, false);

        $refusals = [];
        foreach ($this->pdo->query('SELECT key_name, language_code, value FROM translations ORDER BY key_name COLLATE "C", language_code COLLATE "C"')->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $key = (string) $row['key_name'];
            $code = (string) $row['language_code'];
            $checked = StringCheck::changes([$key => [$code => (string) $row['value']]], $keys, $languages, $english, $this->config);
            if ($checked instanceof Refusal) {
                $refusals[] = ['key' => $key, 'language' => $code, 'code' => $checked->code, 'message' => $checked->message];
            }
        }

        return $refusals;
    }

    /**
     * Every key, by name.
     *
     * @return list<string>
     */
    public function keyNames(): array
    {
        return array_map('strval', $this->pdo->query('SELECT name FROM translation_keys ORDER BY name COLLATE "C"')->fetchAll(PDO::FETCH_COLUMN));
    }

    /**
     * The keys that have no string in a language.
     *
     * @return list<string>
     */
    public function keysWithout(string $code): array
    {
        $statement = $this->pdo->prepare(
            'SELECT k.name FROM translation_keys k
              WHERE NOT EXISTS (SELECT 1 FROM translations t WHERE t.key_name = k.name AND t.language_code = ?)
              ORDER BY k.name COLLATE "C"'
        );
        $statement->execute([$code]);

        return array_map('strval', $statement->fetchAll(PDO::FETCH_COLUMN));
    }

    // ── Inside ───────────────────────────────────────────────────────────────

    private static function invalidLanguage(string $message): Refusal
    {
        return Refusal::invalid('invalid_language', $message);
    }

    /** A boolean as PostgreSQL hands it to PDO: true, or 't' in some builds. */
    private static function bool(mixed $value): bool
    {
        return $value === true || $value === 't' || $value === 'true' || $value === 1 || $value === '1';
    }

    /**
     * A language's own strings, by key: [key => text], in the keys' order. With
     * $pages, only what a page may read: no key of a server namespace.
     *
     * @return array<string, string>
     */
    private function own(string $code, bool $pages): array
    {
        $sql = 'SELECT key_name, value FROM translations WHERE language_code = ?';
        $parameters = [$code];
        if ($pages) {
            foreach ($this->config->serverNamespaces as $namespace) {
                $sql .= ' AND key_name NOT LIKE ?';
                $parameters[] = $namespace . '.%';
            }
        }

        $statement = $this->pdo->prepare($sql . ' ORDER BY key_name COLLATE "C"');
        $statement->execute($parameters);

        $values = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $values[(string) $row['key_name']] = (string) $row['value'];
        }

        return $values;
    }

    /** @return ?array<string, mixed> the language's row, or null */
    private function row(string $code): ?array
    {
        $statement = $this->pdo->prepare('SELECT ' . self::LANGUAGE . ' FROM languages l WHERE l.code = ?');
        $statement->execute([$code]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    /** @return ?array<string, mixed> the language as the admin pages read it, or null */
    private function language(string $code): ?array
    {
        $row = $this->row($code);

        return $row === null ? null : $this->shapes([$row])[0];
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    private function shapes(array $rows): array
    {
        $extras = $this->config->usage?->extras($this->pdo, array_map(static fn (array $row): string => (string) $row['code'], $rows)) ?? [];

        return array_map(fn (array $row): array => $this->shape($row, $extras[(string) $row['code']] ?? []), $rows);
    }

    /**
     * @param array<string, mixed>      $row
     * @param array<string, int|string> $extras  after the library's fields, which they never replace
     * @return array<string, mixed>
     */
    private function shape(array $row, array $extras): array
    {
        return [
            'code' => (string) $row['code'],
            'name' => (string) $row['name'],
            'native_name' => (string) $row['native_name'],
            'enabled' => self::bool($row['enabled']),
            'sort_order' => (int) $row['sort_order'],
            'seeded' => in_array((string) $row['code'], $this->config->releasedLanguages, true),
            'strings' => (int) ($row['strings'] ?? 0),
        ] + $extras;
    }

    /**
     * @param list<array{key: string, language: string, old: ?string, new: ?string}> $written
     * @return array{languages: list<string>, count: int, keys: list<string>, more_keys?: int}
     */
    private function summary(array $written): array
    {
        $names = array_values(array_unique(array_column($written, 'key')));
        sort($names, SORT_STRING);
        $codes = array_values(array_unique(array_column($written, 'language')));
        sort($codes, SORT_STRING);

        $summary = ['languages' => $codes, 'count' => count($written), 'keys' => array_slice($names, 0, $this->config->auditKeys)];
        if (count($names) > $this->config->auditKeys) {
            $summary['more_keys'] = count($names) - $this->config->auditKeys;
        }

        // Whole, as long as the admin pages let such a string be; the clip is for
        // a string that reached the table without them (SQL), which must not bloat the log.
        $clip = fn (?string $text): ?string => $text === null ? null : Text::clip($text, $this->config->auditTextMax);
        foreach ($this->config->serverNamespaces as $namespace) {
            $cells = [];
            foreach ($written as $cell) {
                if (str_starts_with($cell['key'], $namespace . '.')) {
                    $cells[] = ['key' => $cell['key'], 'language' => $cell['language'], 'old' => $clip($cell['old']), 'new' => $clip($cell['new'])];
                }
            }
            if ($cells !== []) {
                $summary[$namespace] = $cells;
            }
        }

        return $summary;
    }

    /**
     * Runs $work in a transaction: committed when it answers null, rolled back
     * when it answers a refusal (which is then the answer) or throws.
     *
     * @param callable(): ?Refusal $work
     */
    private function transaction(callable $work): ?Refusal
    {
        $this->pdo->beginTransaction();
        try {
            $refusal = $work();
            if ($refusal !== null) {
                $this->pdo->rollBack();

                return $refusal;
            }
            $this->pdo->commit();

            return null;
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }
}
