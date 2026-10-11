<?php

declare(strict_types=1);

namespace Anotoki\Lib\Feedback;

use Anotoki\Lib\Admin\Schema;
use Anotoki\Lib\Audit\AuditLog;
use Anotoki\Lib\Support\GuardedDirectory;
use Anotoki\Lib\Support\Keys;
use Anotoki\Lib\Support\Log;
use Anotoki\Lib\Support\Redactor;
use Anotoki\Lib\Support\Refusal;
use Anotoki\Lib\Support\Throttle;
use Anotoki\Lib\Support\Utf8;
use Closure;
use InvalidArgumentException;
use PDO;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;

/**
 * What visitors tell a site, in the library's table anotoki_feedback (Admin\Schema,
 * 002): the public form's submissions, and the admin page's list.
 *
 * The form is open to everybody, so what it keeps and what it lets through are
 * the point. The owner's decisions (genshin's, 2026-10-07): who comes only from
 * the token; no network address and no submitter hash is kept with a message;
 * no mail is sent. So:
 * - who: only from the token (the site's $person), never from the body; "send
 *   without my name" drops the id and the address; a signed-in sender who
 *   gives no address is answered at the account's;
 * - a message row holds nothing of where it came from. The limits - 5 an hour
 *   and 20 a day from one address bucket (the site's trusted proxy's header,
 *   never X-Forwarded-For from anybody), 300 a day from everybody - are counted
 *   apart: a keyed hash of the bucket and the time, in a file of the site's
 *   storage (never the database, so never a backup), each time gone once a day
 *   old - the limits look back a day at most. A message is kept under that
 *   file's lock, so two at once never both slip under a limit;
 * - a honeypot field that a person never sees - filled, the answer is a kept
 *   message's, word for word, and nothing is kept; a body of at most 64 KB; the
 *   page as a path, never its query; an address with nothing in it a mail
 *   link would read as more than an address (? & = % #, a space);
 * - no id handed back, no mail: the admin panel counts what is new
 *   (unreadCount()). A site that wants a mail passes `notify` (run after the
 *   answer).
 *
 * While the table is missing (a build before its migrations), everything
 * answers 503 feature_unavailable. Kept: spam 30 days, resolved 365 days
 * (prune(), daily, after an answer).
 */
final class Feedback
{
    public const STATUSES = ['new', 'read', 'resolved', 'spam'];

    /** The most a submission's body may weigh. */
    public const BODY_LIMIT = 64 * 1024;

    public const PAGE_SIZE = 25;

    /** The limits' count, under the site's storage: a day of keyed hashes and times, nothing else. */
    public const LIMITS_FILE = 'cache/anotoki-feedback-limits.json';

    private const OPTIONS = [
        'kinds', 'sections', 'fields', 'limits', 'honeypot', 'signedInOnly', 'subjectRequiredFor', 'key', 'storage',
        'keep', 'notify', 'defer',
    ];

    /** @var list<string> */
    private readonly array $kinds;
    /** @var list<string> */
    private readonly array $sections;
    /** @var array<string, int> */
    private readonly array $fields;
    /** @var array{perHour: int, perDay: int, allPerDay: int} */
    private readonly array $limits;
    private readonly ?string $honeypot;
    private readonly bool $signedInOnly;
    /** @var list<string> */
    private readonly array $subjectRequiredFor;
    private ?string $key;
    private readonly string $storage;
    /** @var array{spam: int, resolved: int} */
    private readonly array $keep;
    private readonly ?Closure $notify;
    private readonly ?Closure $defer;

    /**
     * @param Closure(): PDO $pdo  the site's connection
     * @param array{
     *   kinds?: list<string>,
     *   sections?: list<string>,
     *   fields?: array<string, int>,
     *   limits?: array{perHour?: int, perDay?: int, allPerDay?: int},
     *   honeypot?: ?string,
     *   signedInOnly?: bool,
     *   subjectRequiredFor?: list<string>,
     *   key?: string,
     *   storage: string,
     *   keep?: array{spam?: int, resolved?: int},
     *   notify?: Closure(array<string, mixed>): void,
     *   defer?: Closure(callable): void,
     * } $options
     *   - kinds: what a message can be (default bug, idea, other); sections: the site's places it can be about
     *     (none: no section kept); fields: the site's extra fields, name => most characters (into `details`)
     *   - limits: perHour 5, perDay 20 from one address bucket; allPerDay 300 from everybody
     *   - honeypot: the hidden field's name ('website'; null: none)
     *   - key: the HMAC key of the buckets (a site's secret through Keys::derive()); default the installation's
     *     key from `storage`
     *   - storage: the site's storage folder (the limits' count, the installation key, the daily prune's stamp)
     *   - keep: days kept - spam 30, resolved 365
     *   - notify: told of every message kept, after the answer (through `defer`)
     */
    public function __construct(private readonly Closure $pdo, array $options = [])
    {
        $unknown = array_diff(array_map('strval', array_keys($options)), self::OPTIONS);
        if ($unknown !== []) {
            throw new InvalidArgumentException('Unknown Feedback option: ' . implode(', ', $unknown) . '.');
        }
        $kinds = array_values($options['kinds'] ?? ['bug', 'idea', 'other']);
        foreach ($kinds as $kind) {
            if (!is_string($kind) || preg_match('/^[a-z][a-z0-9_]{0,31}$/D', $kind) !== 1) {
                throw new InvalidArgumentException('A kind of feedback is small letters, digits and _ (at most 32): ' . json_encode($kind) . ' is not.');
            }
        }
        if ($kinds === []) {
            throw new InvalidArgumentException('Feedback needs at least one kind.');
        }
        $this->kinds = $kinds;
        $this->sections = array_values(array_map(static fn (mixed $section): string => Utf8::prefix((string) $section, 100), $options['sections'] ?? []));
        $fields = [];
        foreach ($options['fields'] ?? [] as $name => $limit) {
            if (!is_string($name) || preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $name) !== 1 || !is_int($limit) || $limit < 1 || $limit > 10000) {
                throw new InvalidArgumentException('An extra field is a name (small letters, digits, _) and its most characters (1-10000).');
            }
            $fields[$name] = $limit;
        }
        $this->fields = $fields;
        $limits = $options['limits'] ?? [];
        $this->limits = [
            'perHour' => max(1, (int) ($limits['perHour'] ?? 5)),
            'perDay' => max(1, (int) ($limits['perDay'] ?? 20)),
            'allPerDay' => max(1, (int) ($limits['allPerDay'] ?? 300)),
        ];
        $honeypot = array_key_exists('honeypot', $options) ? $options['honeypot'] : 'website';
        $this->honeypot = is_string($honeypot) && $honeypot !== '' ? $honeypot : null;
        $this->signedInOnly = (bool) ($options['signedInOnly'] ?? false);
        $this->subjectRequiredFor = array_values(array_intersect($this->kinds, $options['subjectRequiredFor'] ?? []));
        $key = $options['key'] ?? null;
        if ($key !== null && (!is_string($key) || strlen($key) < 16)) {
            throw new InvalidArgumentException('The option key must be a string of at least 16 bytes.');
        }
        $this->key = $key;
        $storage = $options['storage'] ?? null;
        if (!is_string($storage) || $storage === '') {
            throw new InvalidArgumentException('Feedback needs the site\'s storage folder: the limits are counted there.');
        }
        $this->storage = rtrim($storage, '/\\');
        $keep = $options['keep'] ?? [];
        $this->keep = [
            'spam' => max(1, (int) ($keep['spam'] ?? 30)),
            'resolved' => max(1, (int) ($keep['resolved'] ?? 365)),
        ];
        $this->notify = $options['notify'] ?? null;
        $this->defer = $options['defer'] ?? null;
    }

    /** Whether the table is there (asked afresh every time). */
    public function ready(): bool
    {
        try {
            return Schema::has(($this->pdo)(), Schema::FEEDBACK_TABLE);
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * What the public form sends, kept - or why not:
     *   201 {ok}    kept (no id is handed out) - and, word for word, the answer when the honeypot was filled,
     *               which keeps nothing
     *   401 sign_in_required (signedInOnly), 422 kind_unknown | message_required | subject_required |
     *   subject_too_long | section_unknown | email_invalid | too_long (+ field), 429 too_many (+ retry_after),
     *   503 feature_unavailable (no table yet, or the limits cannot be counted)
     *
     * @param array<string, mixed> $body  the form's fields: kind, section?, subject?, message, email?, page?,
     *                                    language?, anonymous?, details?: {the site's fields}, the honeypot
     * @param ?array{id?: int|string|null, email?: ?string} $person  the signed-in sender, from the token; null: nobody
     * @param string $clientKey  the address bucket the site counts (AdminContext::clientKey())
     * @return array{status: int, body: array<string, mixed>}|Refusal
     */
    public function submit(ServerRequestInterface $request, array $body, ?array $person, string $clientKey): array|Refusal
    {
        $kept = ['status' => 201, 'body' => ['ok' => true]];
        if (!$this->ready()) {
            return self::unavailable();
        }
        if ($this->honeypot !== null && is_string($body[$this->honeypot] ?? null) && trim($body[$this->honeypot]) !== '') {
            return $kept;
        }
        if ($this->signedInOnly && $person === null) {
            return new Refusal(401, 'sign_in_required', 'Sign in to send feedback.');
        }

        $row = $this->validate($body, $person);
        if ($row instanceof Refusal) {
            return $row;
        }
        $row['user_agent'] = self::nullable(Utf8::prefix(trim($request->getHeaderLine('User-Agent')), 300));

        $id = $this->withinLimits($clientKey, function () use ($row): int {
            $statement = ($this->pdo)()->prepare(
                'INSERT INTO ' . Schema::FEEDBACK_TABLE . ' (kind, section, subject, message, details, contact_email, user_id, page, language, user_agent)
                 VALUES (?, ?, ?, ?, CAST(? AS jsonb), ?, ?, ?, ?, ?) RETURNING id'
            );
            $statement->execute([
                $row['kind'], $row['section'], $row['subject'], $row['message'],
                json_encode($row['details'] === [] ? new \stdClass() : $row['details'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                $row['contact_email'], $row['user_id'], $row['page'], $row['language'], $row['user_agent'],
            ]);

            return (int) $statement->fetchColumn();
        });
        if ($id instanceof Refusal) {
            return $id;
        }

        if ($this->notify !== null) {
            $notify = $this->notify;
            $this->later(function () use ($notify, $id): void {
                $row = $this->get($id);
                if ($row !== null) {
                    $notify($row);
                }
            });
        }
        $this->pruneIfDue();

        return $kept;
    }

    /**
     * A page of messages, newest first: {total, page, pageSize: 25, items}. Filters: kind, status,
     * section, search (in the subject, the message, the address and the extra fields).
     *
     * @param array<string, mixed> $filter
     * @return array{total: int, page: int, pageSize: int, items: list<array<string, mixed>>}
     */
    public function page(array $filter = [], int $page = 1): array
    {
        $page = max(1, $page);
        $where = [];
        $parameters = [];
        foreach (['kind', 'status', 'section'] as $column) {
            $value = is_string($filter[$column] ?? null) ? trim($filter[$column]) : '';
            if ($value !== '') {
                $where[] = $column . ' = ?';
                $parameters[] = $value;
            }
        }
        $search = is_string($filter['search'] ?? null) ? trim($filter['search']) : '';
        if ($search !== '') {
            // LIKE's escape character is the backslash already, so no ESCAPE '\' clause: before PHP 8.4, PDO's
            // reading of the SQL takes the \' in such a literal for an escaped quote and misses every ? after it.
            $where[] = '(subject ILIKE ? OR message ILIKE ? OR contact_email ILIKE ? OR details::text ILIKE ?)';
            $needle = '%' . addcslashes($search, '%_\\') . '%';
            array_push($parameters, $needle, $needle, $needle, $needle);
        }
        $sql = $where === [] ? '' : ' WHERE ' . implode(' AND ', $where);
        $pdo = ($this->pdo)();

        $count = $pdo->prepare('SELECT count(*) FROM ' . Schema::FEEDBACK_TABLE . $sql);
        $count->execute($parameters);
        $rows = $pdo->prepare(
            'SELECT ' . self::columns() . ' FROM ' . Schema::FEEDBACK_TABLE . $sql
            . ' ORDER BY created_at DESC, id DESC LIMIT ' . self::PAGE_SIZE . ' OFFSET ' . (($page - 1) * self::PAGE_SIZE)
        );
        $rows->execute($parameters);

        return [
            'total' => (int) $count->fetchColumn(),
            'page' => $page,
            'pageSize' => self::PAGE_SIZE,
            'items' => array_map(self::item(...), $rows->fetchAll(PDO::FETCH_ASSOC)),
        ];
    }

    /** @return ?array<string, mixed> one message, or null */
    public function get(int $id): ?array
    {
        $statement = ($this->pdo)()->prepare('SELECT ' . self::columns() . ' FROM ' . Schema::FEEDBACK_TABLE . ' WHERE id = ?');
        $statement->execute([$id]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? self::item($row) : null;
    }

    /** A new message opened: read. Not a decision anybody made, so nobody is named for it. */
    public function markRead(int $id): void
    {
        ($this->pdo)()->prepare("UPDATE " . Schema::FEEDBACK_TABLE . " SET status = 'read', updated_at = now() WHERE id = ? AND status = 'new'")->execute([$id]);
    }

    /**
     * A message's status set by an administrator (handled_by, handled_at): the message as it is now, null
     * when there is none, 422 status_unknown.
     *
     * @return array<string, mixed>|Refusal|null
     */
    public function setStatus(int $id, string $status, int|string|null $actorId): array|Refusal|null
    {
        if (!in_array($status, self::STATUSES, true)) {
            return Refusal::invalid('status_unknown', 'A message is new, read, resolved or spam.', ['statuses' => self::STATUSES]);
        }
        $statement = ($this->pdo)()->prepare(
            'UPDATE ' . Schema::FEEDBACK_TABLE . ' SET status = ?, handled_by = ?, handled_at = now(), updated_at = now() WHERE id = ?'
        );
        $statement->execute([$status, self::id($actorId), $id]);

        return $statement->rowCount() === 0 ? null : $this->get($id);
    }

    /** Deletes a message; whether there was one. */
    public function delete(int $id): bool
    {
        $statement = ($this->pdo)()->prepare('DELETE FROM ' . Schema::FEEDBACK_TABLE . ' WHERE id = ?');
        $statement->execute([$id]);

        return $statement->rowCount() > 0;
    }

    /**
     * What the page's filters offer: {kinds, statuses, sections, fields, byStatus, byKind}.
     *
     * @return array<string, mixed>
     */
    public function facets(): array
    {
        $pdo = ($this->pdo)();
        $byStatus = array_fill_keys(self::STATUSES, 0);
        foreach ($pdo->query('SELECT status, count(*) FROM ' . Schema::FEEDBACK_TABLE . ' GROUP BY status')->fetchAll(PDO::FETCH_NUM) as [$status, $count]) {
            $byStatus[(string) $status] = (int) $count;
        }
        $byKind = array_fill_keys($this->kinds, 0);
        foreach ($pdo->query('SELECT kind, count(*) FROM ' . Schema::FEEDBACK_TABLE . ' GROUP BY kind')->fetchAll(PDO::FETCH_NUM) as [$kind, $count]) {
            $byKind[(string) $kind] = (int) $count;
        }
        $sections = $this->sections;
        foreach ($pdo->query('SELECT DISTINCT section COLLATE "C" FROM ' . Schema::FEEDBACK_TABLE . ' WHERE section IS NOT NULL ORDER BY 1 LIMIT 200')->fetchAll(PDO::FETCH_COLUMN) as $section) {
            if (!in_array((string) $section, $sections, true)) {
                $sections[] = (string) $section;
            }
        }

        return [
            'kinds' => $this->kinds,
            'statuses' => self::STATUSES,
            'sections' => $sections,
            'fields' => $this->fields,
            'byStatus' => $byStatus,
            'byKind' => $byKind,
        ];
    }

    /** How many messages nobody opened yet - the admin panel's badge. */
    public function unreadCount(): int
    {
        return (int) ($this->pdo)()->query("SELECT count(*) FROM " . Schema::FEEDBACK_TABLE . " WHERE status = 'new'")->fetchColumn();
    }

    /**
     * For a dashboard: {total, new, last7, last30, byKind}.
     *
     * @return array{total: int, new: int, last7: int, last30: int, byKind: array<string, int>}
     */
    public function summary(): array
    {
        $pdo = ($this->pdo)();
        $row = $pdo->query(
            "SELECT count(*), count(*) FILTER (WHERE status = 'new'),
                    count(*) FILTER (WHERE created_at >= now() - interval '7 days'),
                    count(*) FILTER (WHERE created_at >= now() - interval '30 days')
               FROM " . Schema::FEEDBACK_TABLE
        )->fetch(PDO::FETCH_NUM);
        $byKind = [];
        foreach ($pdo->query('SELECT kind, count(*) FROM ' . Schema::FEEDBACK_TABLE . ' GROUP BY kind ORDER BY kind COLLATE "C"')->fetchAll(PDO::FETCH_NUM) as [$kind, $count]) {
            $byKind[(string) $kind] = (int) $count;
        }

        return ['total' => (int) $row[0], 'new' => (int) $row[1], 'last7' => (int) $row[2], 'last30' => (int) $row[3], 'byKind' => $byKind];
    }

    /**
     * People whose accounts were anonymized: their messages lose the address
     * they gave and their browser's name. The message stays (the owner's
     * decision): it is the site's to keep. Answers how many changed.
     *
     * @param list<int|string> $ids
     */
    public function forgetPeople(array $ids): int
    {
        $ids = array_values(array_filter(array_map('strval', $ids), static fn (string $id): bool => ctype_digit($id) && strlen($id) <= 18));
        if ($ids === []) {
            return 0;
        }
        $statement = ($this->pdo)()->prepare(
            'UPDATE ' . Schema::FEEDBACK_TABLE . ' SET contact_email = NULL, user_agent = NULL, updated_at = now()
              WHERE user_id = ANY (CAST(? AS bigint[])) AND (contact_email IS NOT NULL OR user_agent IS NOT NULL)'
        );
        $statement->execute(['{' . implode(',', $ids) . '}']);

        return $statement->rowCount();
    }

    /**
     * What is kept no longer: spam after `keep.spam` days, resolved messages
     * after `keep.resolved` days (from when they were resolved). Answers
     * {spam, resolved}.
     *
     * @return array{spam: int, resolved: int}
     */
    public function prune(): array
    {
        $pdo = ($this->pdo)();
        $spam = $pdo->prepare("DELETE FROM " . Schema::FEEDBACK_TABLE . " WHERE status = 'spam' AND COALESCE(handled_at, updated_at) < now() - CAST(? AS interval)");
        $spam->execute([$this->keep['spam'] . ' days']);
        $resolved = $pdo->prepare("DELETE FROM " . Schema::FEEDBACK_TABLE . " WHERE status = 'resolved' AND COALESCE(handled_at, updated_at) < now() - CAST(? AS interval)");
        $resolved->execute([$this->keep['resolved'] . ' days']);

        return ['spam' => $spam->rowCount(), 'resolved' => $resolved->rowCount()];
    }

    /** prune(), at most once a day, after the answer (through `defer`). */
    public function pruneIfDue(): void
    {
        try {
            if (!Throttle::due($this->storage . '/cache/anotoki-feedback-prune.stamp', 86400)) {
                return;
            }
        } catch (Throwable) {
            return;
        }
        $this->later(function (): void {
            if ($this->ready()) {
                $this->prune();
            }
        });
    }

    public static function unavailable(): Refusal
    {
        return new Refusal(503, 'feature_unavailable', 'Feedback is not open yet: a database update waits. Try again in a few minutes.');
    }

    // ─── Inside ──────────────────────────────────────────────────────────────

    /**
     * @param array<string, mixed> $body
     * @param ?array<string, mixed> $person
     * @return array<string, mixed>|Refusal
     */
    private function validate(array $body, ?array $person): array|Refusal
    {
        $kind = $body['kind'] ?? null;
        if (!is_string($kind) || !in_array($kind, $this->kinds, true)) {
            return Refusal::invalid('kind_unknown', 'Say what kind of message this is: ' . implode(', ', $this->kinds) . '.', ['kinds' => $this->kinds]);
        }
        $message = is_string($body['message'] ?? null) ? trim($body['message']) : '';
        if ($message === '') {
            return Refusal::invalid('message_required', 'Write a message.');
        }
        if (Utf8::length($message) > 10000) {
            return Refusal::invalid('too_long', 'The message is longer than 10000 characters.', ['field' => 'message', 'max' => 10000]);
        }
        $subject = is_string($body['subject'] ?? null) ? trim($body['subject']) : '';
        if (Utf8::length($subject) > 200) {
            return Refusal::invalid('subject_too_long', 'The subject is longer than 200 characters.', ['field' => 'subject', 'max' => 200]);
        }
        if ($subject === '' && in_array($kind, $this->subjectRequiredFor, true)) {
            return Refusal::invalid('subject_required', 'Give it a subject.', ['field' => 'subject']);
        }
        $section = is_string($body['section'] ?? null) ? trim($body['section']) : '';
        if ($section !== '' && !in_array($section, $this->sections, true)) {
            return Refusal::invalid('section_unknown', 'That is none of the places this site lists.', ['field' => 'section']);
        }

        $anonymous = ($body['anonymous'] ?? false) === true;
        $email = is_string($body['email'] ?? null) ? trim($body['email']) : '';
        if ($email !== '' && !self::plainAddress($email)) {
            return Refusal::invalid('email_invalid', 'That does not look like an e-mail address.', ['field' => 'email']);
        }
        if ($email === '' && !$anonymous && is_string($person['email'] ?? null) && self::plainAddress($person['email'])) {
            $email = $person['email'];
        }

        $details = [];
        $given = is_array($body['details'] ?? null) ? $body['details'] : [];
        foreach ($this->fields as $field => $limit) {
            $value = $given[$field] ?? null;
            if (!is_string($value) || trim($value) === '') {
                continue;
            }
            $value = trim($value);
            if (Utf8::length($value) > $limit) {
                return Refusal::invalid('too_long', "The field $field is longer than $limit characters.", ['field' => $field, 'max' => $limit]);
            }
            $details[$field] = $value;
        }

        $page = is_string($body['page'] ?? null) ? trim($body['page']) : '';
        $page = explode('#', explode('?', $page, 2)[0], 2)[0];
        $page = str_starts_with($page, '/') && !str_starts_with($page, '//') ? Utf8::prefix($page, 300) : '';
        $language = is_string($body['language'] ?? null) ? strtolower(trim($body['language'])) : '';

        return [
            'kind' => $kind,
            'section' => self::nullable($section),
            'subject' => self::nullable($subject),
            'message' => $message,
            'details' => $details,
            'contact_email' => $anonymous ? null : self::nullable($email),
            'user_id' => $anonymous ? null : self::id($person['id'] ?? null),
            'page' => self::nullable($page),
            'language' => preg_match('/^[a-z]{2}(-[a-z]{2})?$/D', $language) === 1 ? $language : null,
        ];
    }

    /**
     * $keep run under the limits (see the class) - its answer, or 429 too_many with the seconds until one
     * more fits, or 503 when the count cannot be kept. Counted in a file of the site's storage under its
     * lock: a keyed hash of each bucket that sent a message in the last day, and when, nothing else.
     *
     * @param Closure(): int $keep
     */
    private function withinLimits(string $clientKey, Closure $keep): int|Refusal
    {
        $unavailable = new Refusal(503, 'feature_unavailable', 'Feedback cannot be taken right now. Try again in a few minutes.');
        if (!GuardedDirectory::ensure(dirname($this->storage . '/' . self::LIMITS_FILE))) {
            return $unavailable;
        }
        $handle = @fopen($this->storage . '/' . self::LIMITS_FILE, 'c+');
        if ($handle === false) {
            return $unavailable;
        }
        try {
            if (!flock($handle, LOCK_EX)) {
                return $unavailable;
            }
            $now = time();
            $state = json_decode((string) stream_get_contents($handle), true);
            $sent = [];
            foreach (is_array($state['sent'] ?? null) ? $state['sent'] : [] as $bucket => $times) {
                // A day old, a time gone; a time to come (a clock set back) is no time at all.
                $recent = array_values(array_filter(is_array($times) ? $times : [], static fn (mixed $at): bool => is_int($at) && $at > $now - 86400 && $at <= $now));
                if ($recent !== [] && preg_match('/^[0-9a-f]{64}$/D', (string) $bucket) === 1) {
                    $sent[(string) $bucket] = $recent;
                }
            }
            $bucket = hash_hmac('sha256', $clientKey, $this->key());
            $mine = $sent[$bucket] ?? [];
            $lastHour = array_values(array_filter($mine, static fn (int $at): bool => $at > $now - 3600));
            if (count($lastHour) >= $this->limits['perHour']) {
                return self::tooMany(min($lastHour) + 3600 - $now, 'That is a lot of messages in one hour. Try again later.');
            }
            if (count($mine) >= $this->limits['perDay']) {
                return self::tooMany(min($mine) + 86400 - $now, 'That is a lot of messages in one day. Try again tomorrow.');
            }
            $everybody = $sent === [] ? [] : array_merge(...array_values($sent));
            if (count($everybody) >= $this->limits['allPerDay']) {
                return self::tooMany(min($everybody) + 86400 - $now, 'The site has had a lot of messages today. Try again tomorrow.');
            }

            $id = $keep();
            $sent[$bucket][] = $now;
            ftruncate($handle, 0);
            rewind($handle);
            fwrite($handle, (string) json_encode(['v' => 1, 'sent' => $sent]));
            fflush($handle);

            return $id;
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    private static function tooMany(int $seconds, string $message): Refusal
    {
        return new Refusal(429, 'too_many', $message, ['retry_after' => max(1, $seconds)]);
    }

    /**
     * An address a mail link takes as an address and nothing more: a valid one, at most 254 characters,
     * with none of ? & = % # (a mailto: link reads them as its headers - "?bcc=" - or as its end) and no
     * space or control character.
     */
    private static function plainAddress(string $email): bool
    {
        return strlen($email) <= 254
            && preg_match('/[?&=%#\s\x00-\x1F\x7F]/', $email) !== 1
            && filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }

    private function key(): string
    {
        return $this->key ??= Keys::derive(Keys::installation((string) $this->storage), 'feedback');
    }

    private function later(Closure $work): void
    {
        if ($this->defer !== null) {
            ($this->defer)($work);

            return;
        }
        try {
            $work();
        } catch (Throwable $e) {
            // The library's log line, redacted: a site's notify may name the sender's address in its failure.
            Log::say('[feedback] ' . Redactor::text($e->getMessage()));
        }
    }

    private static function columns(): string
    {
        return 'id, ' . AuditLog::isoColumn('created_at') . ' AS created_at, ' . AuditLog::isoColumn('updated_at') . ' AS updated_at, kind, status, section, subject, message,
                details::text AS details, contact_email, user_id, page, language, user_agent, handled_by, '
            . AuditLog::isoColumn('handled_at') . ' AS handled_at';
    }

    /**
     * A row for the page.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function item(array $row): array
    {
        $details = json_decode((string) $row['details'], true);

        return [
            'id' => (int) $row['id'],
            'created_at' => (string) $row['created_at'],
            'updated_at' => (string) $row['updated_at'],
            'kind' => (string) $row['kind'],
            'status' => (string) $row['status'],
            'section' => $row['section'],
            'subject' => $row['subject'],
            'message' => (string) $row['message'],
            'details' => is_array($details) ? $details : [],
            'contact_email' => $row['contact_email'],
            'user_id' => $row['user_id'] === null ? null : (int) $row['user_id'],
            'page' => $row['page'],
            'language' => $row['language'],
            'user_agent' => $row['user_agent'],
            'handled_by' => $row['handled_by'] === null ? null : (int) $row['handled_by'],
            'handled_at' => $row['handled_at'],
        ];
    }

    private static function nullable(string $value): ?string
    {
        return $value === '' ? null : $value;
    }

    private static function id(mixed $id): ?int
    {
        if (is_int($id) && $id >= 0) {
            return $id;
        }

        return is_string($id) && ctype_digit($id) && strlen($id) <= 18 ? (int) $id : null;
    }
}
