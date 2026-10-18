<?php

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;

/**
 * Announcements: news an administrator writes and mails to the people of the
 * anotoki sites (migration 013, the owner's decision of 2026-10-06) - a new
 * version of a site, a new site, a survey about one, or a mail of their own.
 *
 * Nothing here sends by itself. An administrator picks a template, whose
 * starting words (the mail.newVersion.* ... strings, reworded on the
 * Translations page) fill the draft; changes them, in every language they want
 * a version in; picks the sender and who it is for - everybody, the holders of
 * some roles, some people by name - and presses Send. Each person gets the
 * version in their own language, or the English one where there is none.
 *
 * What keeps it honest:
 *
 *   - Only people who can be written to (announcementReachable()): switched
 *     on, the address confirmed, approved, not on their way out (a deletion
 *     asked for, migration 016), and the news not switched off - on the
 *     account page, or by the link at the end of every announcement
 *     (newsUnsubscribeToken()). Asked when the list is made, and again for
 *     each mail as it goes.
 *   - Links to the anotoki sites only (the owner's choice): the button's, and
 *     any written out in the words. A mail address in the words must be at a
 *     domain the IAM sends from.
 *   - From the installation's own addresses (config/mail.local.php), or one
 *     an administrator added at the same domain: the host sends as its own
 *     mailboxes only.
 *   - The words are text, never HTML: announcementMessage() escapes each of
 *     them into one fixed layout.
 *   - Every announcement is kept: its words, its sender, who it was for, who
 *     sent it and when, and how each person's mail went.
 *
 * The host has no cron, and a request has half a minute: the admin panel
 * sends in batches (announcementSendBatch()), each mail in a transaction of
 * its own with its row locked and skipped by any other - two tabs pressing on
 * never send one mail twice. One left half-sent is continued or stopped from
 * its page.
 *
 * A refusal is translationRefusal()'s shape (api/helpers.php), which the
 * routes answer with translationRefuse().
 */

/** The templates: three with starting words (the mail.<template>.* strings), and one that starts empty. */
const ANNOUNCEMENT_TEMPLATES = ['newVersion', 'newRelease', 'newSurvey', 'blank'];

/** What an administrator writes in each language, and the most characters each may have. */
const ANNOUNCEMENT_FIELDS = ['subject' => 150, 'heading' => 150, 'body' => 5000, 'button' => 60];

/** The parts that are one line: the subject is a header, the heading and the button one line on the page. */
const ANNOUNCEMENT_ONE_LINE = ['subject', 'heading', 'button'];

const ANNOUNCEMENT_LINK_MAX = 500;

/** How many people may be chosen by name, and how many roles named, for one announcement. */
const ANNOUNCEMENT_PEOPLE_MAX = 500;
const ANNOUNCEMENT_ROLES_MAX = 100;

/**
 * One press of Send: at most this many mails, for at most this long. The page
 * asks again until nobody is left - a request on the host must end well
 * inside its half a minute, and mail() can take a second.
 */
const ANNOUNCEMENT_BATCH_SIZE = 20;
const ANNOUNCEMENT_BATCH_SECONDS = 10;

/** Test mails an administrator may send to themselves in an hour. */
const ANNOUNCEMENT_TESTS_PER_HOUR = 20;

/**
 * Of the host's hourly allowance (config/mail.local.php's `hourly_limit`),
 * the share announcements may use: the rest is kept for the mails people ask
 * for - a sign-in code must not wait behind the news.
 */
const ANNOUNCEMENT_HOURLY_SHARE = 0.9;

/** An added sender's name: what the From line shows beside the address. */
const MAIL_SENDER_NAME_MAX = 60;

/**
 * Who can get an announcement, over `u` (users): switched on, the address
 * confirmed (an unconfirmed one may be anybody's), approved (one waiting has
 * no account yet, as far as its owner is concerned), and the news on.
 */
const ANNOUNCEMENT_REACHABLE = 'NOT u.disabled AND u.email_confirmed AND u.approved AND u.news_mail';

/**
 * ANNOUNCEMENT_REACHABLE - and, once migration 016 is there, not on the way
 * out: an account whose owner asked for its deletion is closed, and is sent
 * no news in its last thirty days (a deleted one is switched off already).
 */
function announcementReachable(PDO $pdo): string
{
    return ANNOUNCEMENT_REACHABLE . (accountDeletionReady($pdo) ? ' AND u.deletion_due_at IS NULL' : '');
}

// ── Ready? ───────────────────────────────────────────────────────────────────

/**
 * Whether migration 013 is applied: its tables and users.news_mail. A catalog
 * query that cannot fail, asked afresh every time like signInRulesReady(): a
 * build is uploaded before its migrations, and the admin panel's Apply runs
 * in the same process as the requests after it.
 */
function announcementsReady(PDO $pdo): bool
{
    return asBool(
        $pdo
            ->query(
                "SELECT to_regclass('public.announcements') IS NOT NULL
            AND to_regclass('public.announcement_recipients') IS NOT NULL
            AND to_regclass('public.mail_senders') IS NOT NULL
            AND EXISTS (SELECT 1 FROM pg_attribute WHERE attrelid = to_regclass('users') AND attname = 'news_mail' AND NOT attisdropped)",
            )
            ->fetchColumn(),
    );
}

/**
 * Whether the registration form offers the news from the sites (migration
 * 014's box): once 013 is there to keep the answer. False, not an error,
 * while the database cannot be reached - the sign-in pages' config, which
 * asks, answers then too, as it did before.
 */
function registrationNewsOffered(): bool
{
    try {
        return announcementsReady(iamDb());
    } catch (\PDOException) {
        return false;
    }
}

/** What needs 013 answers this while it is pending: a 503 that points at the Migrations page, not a 500. */
function announcementsUnavailable(): array
{
    return translationRefusal(503, 'announcements_unavailable', 'Announcements are not in the database yet. Apply the pending migrations in the admin panel.');
}

/** For the admin panel's announcement and sender routes: 503 until 013 is applied. */
function requireAnnouncements(): callable
{
    return function (Request $request, RequestHandler $handler): Response {
        if (!announcementsReady(iamDb())) {
            return translationRefuse(new \Slim\Psr7\Response(), announcementsUnavailable());
        }

        return $handler->handle($request);
    };
}

// ── The anotoki sites ────────────────────────────────────────────────────────

/**
 * The domains the IAM sends from: those of config/mail.local.php's addresses,
 * lowercase. An added sender must be at one of them, and a mail address in an
 * announcement's words too.
 *
 * @return string[]
 */
function mailDomains(): array
{
    $config = mailConfig();
    $addresses = [(string) ($config['from'] ?? '')];
    foreach ((array) ($config['senders'] ?? []) as $sender) {
        if (is_array($sender)) {
            $addresses[] = (string) ($sender['from'] ?? '');
        }
    }

    $domains = [];
    foreach ($addresses as $address) {
        $at = strrpos($address, '@');
        $domain = $at === false ? '' : strtolower(trim(substr($address, $at + 1)));
        if ($domain !== '') {
            $domains[$domain] = true;
        }
    }

    return array_keys($domains);
}

/**
 * The hosts the anotoki sites are at, as far as the IAM knows them: its own,
 * and every address an application is registered with - its home, its
 * redirect URIs, its origins. Lowercase.
 *
 * @return string[]
 */
function anotokiHosts(PDO $pdo): array
{
    $hosts = [];
    $urls = [
        iamIssuer(),
        ...$pdo->query('SELECT home_url FROM applications WHERE home_url IS NOT NULL')->fetchAll(PDO::FETCH_COLUMN),
        ...$pdo->query('SELECT uri FROM application_redirect_uris')->fetchAll(PDO::FETCH_COLUMN),
        ...$pdo->query('SELECT origin FROM application_origins')->fetchAll(PDO::FETCH_COLUMN),
    ];

    foreach ($urls as $url) {
        $host = parse_url((string) $url, PHP_URL_HOST);
        if (is_string($host) && $host !== '') {
            $hosts[strtolower($host)] = true;
        }
    }

    return array_keys($hosts);
}

/**
 * Whether a host is one of the anotoki sites: one the IAM knows (anotokiHosts()),
 * or a mail domain or any name under it - genshin.anotoki.eu under anotoki.eu,
 * a site whose application is not registered yet among them.
 */
function anotokiHost(string $host, array $hosts, array $domains): bool
{
    $host = strtolower(rtrim($host, '.'));

    if ($host === '' || in_array($host, $hosts, true)) {
        return $host !== '';
    }

    foreach ($domains as $domain) {
        if ($host === $domain || str_ends_with($host, '.' . $domain)) {
            return true;
        }
    }

    return false;
}

/**
 * Why an address cannot be an announcement's link, or null: https (http only
 * on this computer, like every address the IAM keeps), a host of the anotoki
 * sites, and nothing in it that makes it read as one host and lead to another
 * - a name before the host ("https://anotoki.eu@elsewhere.example"), a
 * backslash.
 *
 * Printable ASCII and nothing else. A space of any kind - a no-break space as
 * much as a plain one - ends a link in a mail program and lets another one
 * begin, under a button that seemed to lead home; and a letter outside ASCII
 * is written percent-encoded in an address anyway.
 */
function announcementLinkRefusal(string $link, array $hosts, array $domains): ?string
{
    if (strlen($link) > ANNOUNCEMENT_LINK_MAX) {
        return 'The link is longer than ' . ANNOUNCEMENT_LINK_MAX . ' characters';
    }
    if (preg_match('/[^\x21-\x7E]|["\'<>\\\\`]/', $link)) {
        return 'The link can have no spaces of any kind, quotes, angle brackets or backslashes, and no letters outside ASCII - percent-encode those';
    }

    $parts = parse_url($link);
    if ($parts === false || !isset($parts['scheme'], $parts['host']) || $parts['host'] === '') {
        return 'The link must be a whole address, starting with https://';
    }
    if (isset($parts['user']) || isset($parts['pass'])) {
        return 'The link cannot have a name or a password before its host';
    }

    $scheme = strtolower($parts['scheme']);
    $host = strtolower($parts['host']);
    if (!($scheme === 'https' || ($scheme === 'http' && loopbackHost($host)))) {
        return 'The link must start with https://';
    }
    if (!anotokiHost($host, $hosts, $domains)) {
        return 'The link leads to ' . textClip($host, 100) . ', which is not an anotoki site - only the IAM, the addresses of its applications, and the sites under the domain it sends mail from';
    }

    return null;
}

/**
 * What in an announcement's words reads as an address off the anotoki sites,
 * or null: a link written out - anything around a "://", or a "www." - whose
 * host is not one of theirs, or a mail address at a domain the IAM does not
 * send from. A mail program makes both clickable.
 *
 * Read the way a mail program reads it: every kind of space, and every
 * character that is not drawn, ends an address there - a no-break space
 * between two links makes two links of them, so it does here too. Each "://"
 * and each "www." is looked at on its own, wherever it stands, not only where
 * a word starts ("0https://..."): the scheme written before it, if any, must
 * be https (http on this computer), and the host after it - past any slashes,
 * as a browser skips them - one of the anotoki sites. A backslash, or a name
 * before the host, is refused outright. Only the host is judged: a path may
 * be in any script.
 *
 * A bare name ("example.com") is not looked for: like the mail strings' rule
 * (TRANSLATION_MAIL_ADDRESS) this stops the obvious, and what was sent is kept
 * whole for whoever asks later.
 *
 * Answers the word that is refused, as far as it can be shown.
 */
function announcementTextRefusal(string $text, array $hosts, array $domains): ?string
{
    $plain = preg_replace('/[\p{Z}\p{C}]/u', ' ', $text);
    if ($plain === null) {
        return textClip($text, 60);
    }

    // Punctuation a sentence puts right after an address is not part of it.
    $bare = static fn(string $found): string => rtrim($found, '.,;:!?)]}\'"');

    // The word an offset stands in: between the spaces around it.
    $word = static function (int $at) use ($plain): string {
        $start = strrpos(substr($plain, 0, $at), ' ');
        $start = $start === false ? 0 : $start + 1;
        $end = strpos($plain, ' ', $at);

        return textClip(substr($plain, $start, $end === false ? null : $end - $start), 100);
    };

    // Whether what follows a "://" (or a "www." itself), up to the next space,
    // leads off the sites, with the scheme written before it ('' for none).
    $offSite = static function (string $scheme, string $after) use ($hosts, $domains, $bare): bool {
        if (str_contains($after, '\\')) {
            return true;
        }
        $authority = $bare((string) preg_replace('~[/?#].*$~s', '', ltrim($after, '/')));
        if ($authority === '') {
            return false;
        }
        if (str_contains($authority, '@')) {
            return true;
        }
        $host = strtolower((string) preg_replace('~:[0-9]*$~', '', $authority));
        $scheme = strtolower($scheme);
        if ($scheme !== '' && $scheme !== 'https' && !($scheme === 'http' && loopbackHost($host))) {
            return true;
        }

        return !anotokiHost($host, $hosts, $domains);
    };

    for ($at = strpos($plain, '://'); $at !== false; $at = strpos($plain, '://', $at + 3)) {
        preg_match('~[A-Za-z0-9+.-]*$~', substr($plain, 0, $at), $scheme);
        $end = strpos($plain, ' ', $at);
        if ($offSite($scheme[0] ?? '', substr($plain, $at + 3, $end === false ? null : $end - $at - 3))) {
            return $word($at);
        }
    }
    for ($at = stripos($plain, 'www.'); $at !== false; $at = stripos($plain, 'www.', $at + 4)) {
        $end = strpos($plain, ' ', $at);
        $after = substr($plain, $at, $end === false ? null : $end - $at);
        // Something must follow it that could be a host: "Awww." is no address.
        if (preg_match('~^www\.[^.,;:!?)\]}\'"/?#]~i', $after) === 1 && $offSite('', $after)) {
            return $word($at);
        }
    }

    if (preg_match_all('~[^\s<>()\[\]"\',;:]+@([^\s<>()\[\]"\',;:]+)~u', $plain, $addresses, PREG_SET_ORDER) === false) {
        return textClip($text, 60);
    }
    foreach ($addresses as $found) {
        $domain = strtolower($bare($found[1]));
        $atHome = false;
        foreach ($domains as $own) {
            if ($domain === $own || str_ends_with($domain, '.' . $own)) {
                $atHome = true;
                break;
            }
        }
        if (!$atHome) {
            return textClip($bare($found[0]), 100);
        }
    }

    return null;
}

// ── Senders ──────────────────────────────────────────────────────────────────

/**
 * Every address an announcement may come from: the installation's own,
 * config/mail.local.php's senders (noreply and support), whose id is their
 * key there; then those added in the admin panel, whose id is their number.
 *
 * @return list<array{id: string, address: string, name: string, added: bool, created_at: ?string, created_by: ?string}>
 */
function announcementSenders(PDO $pdo): array
{
    $senders = [];

    $configured = (array) (mailConfig()['senders'] ?? []);
    foreach ($configured as $key => $entry) {
        // A key is a word, never a number - those are the added ones'.
        if (!is_string($key) || !is_array($entry) || preg_match('/^[a-z][a-z0-9_-]*$/', $key) !== 1) {
            continue;
        }
        $sender = mailSender($key);
        $senders[] = ['id' => $key, 'address' => $sender['from'], 'name' => $sender['from_name'], 'added' => false, 'created_at' => null, 'created_by' => null];
    }
    // An installation whose file has no senders map sends everything from
    // its one address.
    if ($senders === []) {
        $sender = mailSender(MAIL_SENDER_NOREPLY);
        $senders[] = ['id' => MAIL_SENDER_NOREPLY, 'address' => $sender['from'], 'name' => $sender['from_name'], 'added' => false, 'created_at' => null, 'created_by' => null];
    }

    $rows = $pdo
        ->query(
            'SELECT s.id, s.address, s.name, s.created_at, u.username AS created_by
           FROM mail_senders s LEFT JOIN users u ON u.id = s.created_by ORDER BY lower(s.address), s.id',
        )
        ->fetchAll();
    foreach ($rows as $row) {
        $senders[] = [
            'id' => (string) $row['id'],
            'address' => $row['address'],
            'name' => $row['name'],
            'added' => true,
            'created_at' => isoTime($row['created_at']),
            'created_by' => $row['created_by'],
        ];
    }

    return $senders;
}

/** One sender by its id, or null. */
function announcementSender(PDO $pdo, string $id): ?array
{
    foreach (announcementSenders($pdo) as $sender) {
        if ($sender['id'] === $id) {
            return $sender;
        }
    }

    return null;
}

/**
 * Adds a sender: an address at one of the mail domains, and the name the From
 * line shows. The local part is plain - letters, digits, dot, dash,
 * underscore, plus - and so is the name: letters, digits, spaces and a few
 * marks, nothing a header gives a meaning to (a comma in "anotoki, news"
 * would make the From line two addresses).
 *
 * @return array{sender: array}|array a refusal
 */
function mailSenderAdd(PDO $pdo, mixed $address, mixed $name, int $actorId): array
{
    $address = is_string($address) ? strtolower(trim($address)) : '';
    $name = is_string($name) ? trim((string) preg_replace('/\s+/u', ' ', $name)) : '';

    if (!validEmailAddress($address) || preg_match('/^[a-z0-9._+-]+@[a-z0-9.-]+$/', $address) !== 1) {
        return translationRefusal(422, 'invalid_email', 'That is not an address a mail can come from: letters, digits and . _ + - before the @');
    }
    $domain = substr($address, strrpos($address, '@') + 1);
    $domains = mailDomains();
    if (!in_array($domain, $domains, true)) {
        return translationRefusal(
            422,
            'foreign_domain',
            'Mail can come only from the domain the IAM sends from (' . implode(', ', $domains) . '): the host sends as its own mailboxes, and a mail from anywhere else is taken for a forgery',
        );
    }
    if ($name === '' || textLength($name) > MAIL_SENDER_NAME_MAX || preg_match('/^[\p{L}\p{M}\p{N} .\'&!?+_-]+$/u', $name) !== 1) {
        return translationRefusal(422, 'invalid_name', 'The name is up to ' . MAIL_SENDER_NAME_MAX . ' characters of letters, digits, spaces and . \' & ! ? + _ -');
    }
    foreach (announcementSenders($pdo) as $sender) {
        if (strtolower($sender['address']) === $address) {
            return translationRefusal(409, 'sender_exists', $address . ' is a sender already');
        }
    }

    try {
        $insert = $pdo->prepare('INSERT INTO mail_senders (address, name, created_by) VALUES (?, ?, ?) RETURNING id');
        $insert->execute([$address, $name, $actorId]);
        $id = (string) $insert->fetchColumn();
    } catch (\PDOException $e) {
        // Two administrators adding it at the same moment: the index decides.
        if ($e->getCode() === '23505') {
            return translationRefusal(409, 'sender_exists', $address . ' is a sender already');
        }
        throw $e;
    }

    return ['sender' => announcementSender($pdo, $id)];
}

// ── Drafts ───────────────────────────────────────────────────────────────────

/**
 * The templates' starting words in every language on offer: the language's
 * own mail strings over the English ones (mailWords()), {project} still in
 * them - the admin panel fills it with the name of the site chosen.
 *
 * @return array<string, array<string, array{subject: string, heading: string, body: string, button: string}>>
 */
function announcementTemplates(PDO $pdo): array
{
    $templates = [];

    foreach (languageCodes($pdo) as $code) {
        $words = mailWords($code);
        foreach (ANNOUNCEMENT_TEMPLATES as $template) {
            if ($template === 'blank') {
                continue;
            }
            foreach (array_keys(ANNOUNCEMENT_FIELDS) as $field) {
                $templates[$template][$code][$field] = $words['mail.' . $template . '.' . $field] ?? '';
            }
        }
    }

    return $templates;
}

/**
 * A draft as the admin panel sends it, checked - what a preview shows, a test
 * sends, and Send sends:
 *
 *   template   newVersion, newRelease, newSurvey or blank
 *   client_id  the site it is about - its name is the {project} the starting
 *              words had, and stands at the top of the mail. Needed by every
 *              template but blank.
 *   link       where the button leads, or none (then there is no button)
 *   sender     a sender's id (announcementSenders())
 *   content    {language: {subject, heading, body, button}}
 *
 * English is needed: everybody whose language has no version gets it. Another
 * language has all of its parts or none - a version with nothing in it is no
 * version. With `$language`, only what a reader of that language would get is
 * checked: its own version, or the English one.
 *
 * @return array{template: string, application: ?array{id: int, client_id: string, name: string}, link: ?string, sender: array, content: array<string, array<string, string>>}|array a refusal
 */
function announcementDraft(PDO $pdo, array $body, ?string $language = null): array
{
    $invalid = static fn(string $code, string $message, array $extra = []): array => translationRefusal(422, $code, $message, $extra);

    $template = $body['template'] ?? null;
    if (!is_string($template) || !in_array($template, ANNOUNCEMENT_TEMPLATES, true)) {
        return $invalid('invalid_template', 'The template is one of ' . implode(', ', ANNOUNCEMENT_TEMPLATES));
    }

    $hosts = anotokiHosts($pdo);
    $domains = mailDomains();

    $application = null;
    $clientId = field($body, 'client_id');
    if ($clientId !== '') {
        $row = applicationByClientId($clientId);
        if ($row === null) {
            return $invalid('unknown_application', 'There is no application ' . textClip($clientId, 64));
        }
        $application = ['id' => (int) $row['id'], 'client_id' => $row['client_id'], 'name' => $row['name']];
        // Its name stands at the top of the mail, beside "anotoki": held to
        // what the words are held to.
        if (($address = announcementTextRefusal((string) $row['name'], $hosts, $domains)) !== null) {
            return $invalid('foreign_address', 'The name of ' . textClip($clientId, 64) . ' has an address in it that is not on the anotoki sites (' . $address . ') - rename the application first');
        }
    } elseif ($template !== 'blank') {
        return $invalid('application_required', 'Choose the site the announcement is about');
    }

    $link = field($body, 'link');
    if ($link === '') {
        $link = null;
    } elseif ($refusal = announcementLinkRefusal($link, $hosts, $domains)) {
        return $invalid('invalid_link', $refusal);
    }

    $sender = announcementSender($pdo, field($body, 'sender'));
    if ($sender === null) {
        return $invalid('unknown_sender', 'Choose who the announcement comes from');
    }

    $content = $body['content'] ?? null;
    if (!is_array($content) || ($content !== [] && array_is_list($content))) {
        return $invalid('invalid_content', 'Send the words by language: {language: {subject, heading, body, button}}');
    }

    $offered = languageCodes($pdo);
    $names = $pdo->query('SELECT code, name FROM languages')->fetchAll(PDO::FETCH_KEY_PAIR);
    $versions = [];

    foreach ($content as $code => $fields) {
        $code = (string) $code;
        if (!in_array($code, $offered, true)) {
            return $invalid('unknown_language', 'The IAM does not offer the language "' . textClip($code, 20) . '"');
        }
        if ($language !== null && $code !== $language && $code !== TRANSLATION_FALLBACK) {
            continue;
        }
        if (!is_array($fields)) {
            return $invalid('invalid_content', 'Send the words by language: {language: {subject, heading, body, button}}');
        }

        $name = (string) ($names[$code] ?? $code);
        $version = [];
        foreach (array_keys(ANNOUNCEMENT_FIELDS) as $part) {
            $value = textClean($fields[$part] ?? '');
            if ($value === null) {
                return $invalid('invalid_content', "The $name $part is not text");
            }
            $value = textTrim(str_replace(["\r\n", "\r"], "\n", $value));
            $version[$part] = textBlank($value) ? '' : $value;
        }
        // No link, no button.
        if ($link === null) {
            $version['button'] = '';
        }
        if (implode('', $version) === '') {
            continue;
        }

        $needed = $link === null ? ['subject', 'heading', 'body'] : ['subject', 'heading', 'body', 'button'];
        foreach ($needed as $part) {
            if ($version[$part] === '') {
                return $invalid(
                    'incomplete_version',
                    $code === TRANSLATION_FALLBACK
                        ? "The English version needs its $part: everybody whose language has no version of its own gets it"
                        : "The $name version needs its $part too - or clear the $name version, and $name readers get the English one",
                    ['language' => $code],
                );
            }
        }

        foreach (ANNOUNCEMENT_FIELDS as $part => $max) {
            $value = $version[$part];
            if (textLength($value) > $max) {
                return $invalid('invalid_content', "The $name $part is longer than $max characters", ['language' => $code]);
            }
            if (in_array($part, ANNOUNCEMENT_ONE_LINE, true) && preg_match('/[\n\t\x{0085}\x{2028}\x{2029}]/u', $value)) {
                return $invalid('invalid_content', "The $name $part must be one line", ['language' => $code]);
            }
            // A {project} the site's name never went into would go out as
            // it is written.
            if (preg_match(TRANSLATION_PLACEHOLDER, $value, $left)) {
                return $invalid('placeholder_left', "The $name $part still has {$left[0]} in it - choose the site, or write what should stand there", ['language' => $code]);
            }
            if (($address = announcementTextRefusal($value, $hosts, $domains)) !== null) {
                return $invalid('foreign_address', "The $name $part has an address that is not on the anotoki sites: " . textClip($address, 100), ['language' => $code]);
            }
        }

        $versions[$code] = $version;
    }

    if (!isset($versions[TRANSLATION_FALLBACK])) {
        return $invalid('english_required', 'The English version is needed: everybody whose language has no version of its own gets it');
    }

    // The order the languages are offered in, English first.
    uksort(
        $versions,
        static fn(string $a, string $b): int => ($a === TRANSLATION_FALLBACK ? -1 : array_search($a, $offered, true)) <=> ($b === TRANSLATION_FALLBACK ? -1 : array_search($b, $offered, true)),
    );

    return ['template' => $template, 'application' => $application, 'link' => $link, 'sender' => $sender, 'content' => $versions];
}

// ── Who it is for ────────────────────────────────────────────────────────────

/**
 * Who an announcement is for, as the admin panel sends it, checked:
 * {everybody, roles: [{client_id, role}], user_ids: [id]} - everybody, or
 * whoever holds one of the roles or is one of the people. Each role and each
 * person must exist.
 *
 * @return array{everybody: bool, roles: list<array{client_id: string, role: string}>, user_ids: list<int>}|array a refusal
 */
function announcementAudience(PDO $pdo, mixed $audience): array
{
    $invalid = static fn(string $code, string $message): array => translationRefusal(422, $code, $message);

    if ($audience === null) {
        return $invalid('no_audience', 'Choose who it is for: everybody, some roles, or some people');
    }
    if (!is_array($audience)) {
        return $invalid('invalid_audience', 'Say who it is for: {everybody, roles: [{client_id, role}], user_ids: [id]}');
    }

    $everybody = $audience['everybody'] ?? false;
    $roles = $audience['roles'] ?? [];
    $userIds = $audience['user_ids'] ?? [];
    if (!is_bool($everybody) || !is_array($roles) || !array_is_list($roles) || !is_array($userIds) || !array_is_list($userIds)) {
        return $invalid('invalid_audience', 'Say who it is for: {everybody, roles: [{client_id, role}], user_ids: [id]}');
    }
    if (count($roles) > ANNOUNCEMENT_ROLES_MAX || count($userIds) > ANNOUNCEMENT_PEOPLE_MAX) {
        return $invalid('invalid_audience', 'At most ' . ANNOUNCEMENT_ROLES_MAX . ' roles and ' . ANNOUNCEMENT_PEOPLE_MAX . ' people by name - for more, choose a role, or everybody');
    }

    $grants = [];
    $exists = $pdo->prepare('SELECT 1 FROM application_roles r JOIN applications a ON a.id = r.application_id WHERE a.client_id = ? AND r.name = ?');
    foreach ($roles as $grant) {
        $clientId = is_array($grant) ? field($grant, 'client_id') : '';
        $role = is_array($grant) ? strtoupper(field($grant, 'role')) : '';
        $exists->execute([$clientId, $role]);
        if ($exists->fetchColumn() === false) {
            return $invalid('invalid_role', 'There is no role ' . textClip($role, 50) . ' in ' . textClip($clientId, 64));
        }
        $grants[$clientId . ' ' . $role] = ['client_id' => $clientId, 'role' => $role];
    }

    $ids = [];
    foreach ($userIds as $id) {
        if (!is_int($id) || $id <= 0) {
            return $invalid('invalid_audience', 'user_ids is a list of account ids');
        }
        $ids[$id] = true;
    }
    $ids = array_keys($ids);
    sort($ids);
    if ($ids !== []) {
        $found = $pdo->prepare('SELECT count(*) FROM users WHERE id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')');
        $found->execute($ids);
        if ((int) $found->fetchColumn() !== count($ids)) {
            return $invalid('unknown_user', 'One of the people chosen has no account any more - choose them again');
        }
    }

    if (!$everybody && $grants === [] && $ids === []) {
        return $invalid('no_audience', 'Choose who it is for: everybody, some roles, or some people');
    }

    return ['everybody' => $everybody, 'roles' => array_values($grants), 'user_ids' => $ids];
}

/**
 * The accounts an audience names, as SQL over `u` (users) and its parameters
 * - whether or not they can be written to.
 *
 * @return array{0: string, 1: list<mixed>}
 */
function announcementAudienceMatch(array $audience): array
{
    if ($audience['everybody']) {
        return ['TRUE', []];
    }

    $parts = [];
    $params = [];
    if ($audience['roles'] !== []) {
        $parts[] =
            'EXISTS (SELECT 1 FROM user_roles aur JOIN application_roles ar ON ar.id = aur.role_id JOIN applications aa ON aa.id = ar.application_id
                             WHERE aur.user_id = u.id AND (aa.client_id, ar.name) IN (' .
            implode(', ', array_fill(0, count($audience['roles']), '(?, ?)')) .
            '))';
        foreach ($audience['roles'] as $grant) {
            array_push($params, $grant['client_id'], $grant['role']);
        }
    }
    if ($audience['user_ids'] !== []) {
        $parts[] = 'u.id IN (' . implode(', ', array_fill(0, count($audience['user_ids']), '?')) . ')';
        array_push($params, ...$audience['user_ids']);
    }

    return ['(' . implode(' OR ', $parts) . ')', $params];
}

/**
 * How many an audience reaches, and why the others are left out - each
 * counted once, for the first reason that holds: switched off, on the way
 * out (a deletion asked for), unconfirmed, waiting, the news off - and how
 * many read which language (one switched off is read as English, as
 * everywhere).
 *
 * @return array{recipients: int, left_out: array{disabled: int, unconfirmed: int, waiting: int, news_off: int, deleting: int}, languages: array<string, int>}
 */
function announcementAudienceCount(PDO $pdo, array $audience): array
{
    [$match, $params] = announcementAudienceMatch($audience);
    $reachable = announcementReachable($pdo);
    $deleting = accountDeletionReady($pdo) ? 'u.deletion_due_at IS NOT NULL' : 'FALSE';

    $counts = $pdo->prepare(
        "SELECT count(*) FILTER (WHERE $reachable) AS recipients,
                count(*) FILTER (WHERE u.disabled) AS disabled,
                count(*) FILTER (WHERE NOT u.disabled AND $deleting) AS deleting,
                count(*) FILTER (WHERE NOT u.disabled AND NOT ($deleting) AND NOT u.email_confirmed) AS unconfirmed,
                count(*) FILTER (WHERE NOT u.disabled AND NOT ($deleting) AND u.email_confirmed AND NOT u.approved) AS waiting,
                count(*) FILTER (WHERE NOT u.disabled AND NOT ($deleting) AND u.email_confirmed AND u.approved AND NOT u.news_mail) AS news_off
           FROM users u WHERE $match",
    );
    $counts->execute($params);
    $row = $counts->fetch();

    $languages = $pdo->prepare("SELECT u.language, count(*) AS people FROM users u WHERE $match AND $reachable GROUP BY u.language");
    $languages->execute($params);
    $read = [];
    foreach ($languages->fetchAll() as $language) {
        $code = languageOrDefault($language['language'], $pdo);
        $read[$code] = ($read[$code] ?? 0) + (int) $language['people'];
    }
    ksort($read);

    return [
        'recipients' => (int) $row['recipients'],
        'left_out' => [
            'disabled' => (int) $row['disabled'],
            'unconfirmed' => (int) $row['unconfirmed'],
            'waiting' => (int) $row['waiting'],
            'news_off' => (int) $row['news_off'],
            // Last in the answer, first among the reasons after "switched
            // off": added by 016, after the four the composer had.
            'deleting' => (int) $row['deleting'],
        ],
        'languages' => $read,
    ];
}

// ── Kept ─────────────────────────────────────────────────────────────────────

/**
 * Makes an announcement and the list of the people it goes to - only those
 * who can be written to, each with the version they will get: their
 * language's, or English. Nothing is sent yet: announcementSendBatch() does.
 *
 * @return array{id: int, recipients: int}|array a refusal
 */
function announcementCreate(PDO $pdo, array $draft, array $audience, int $actorId): array
{
    [$match, $params] = announcementAudienceMatch($audience);
    $versions = array_keys($draft['content']);

    $pdo->beginTransaction();
    try {
        $insert = $pdo->prepare(
            'INSERT INTO announcements (template, application_id, application_name, sender_address, sender_name, link, content, audience, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?) RETURNING id',
        );
        $insert->execute([
            $draft['template'],
            $draft['application']['id'] ?? null,
            isset($draft['application']) ? textClip($draft['application']['name'], 100) : null,
            $draft['sender']['address'],
            textClip($draft['sender']['name'], 100),
            $draft['link'],
            json_encode($draft['content'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            json_encode($audience, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            $actorId,
        ]);
        $id = (int) $insert->fetchColumn();

        $recipients = $pdo->prepare(
            "INSERT INTO announcement_recipients (announcement_id, user_id, email, language)
             SELECT ?::int, u.id, u.email, CASE WHEN u.language IN (" .
                implode(', ', array_fill(0, count($versions), '?')) .
                ") THEN u.language ELSE 'en' END
               FROM users u WHERE $match AND " .
                announcementReachable($pdo),
        );
        $recipients->execute([$id, ...$versions, ...$params]);
        $count = $recipients->rowCount();

        if ($count === 0) {
            $pdo->rollBack();
            return translationRefusal(422, 'no_recipients', 'Nobody it is for can get it: they are switched off, unconfirmed, waiting for approval, or have stopped the news');
        }

        $pdo->commit();
    } catch (\Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    return ['id' => $id, 'recipients' => $count];
}

/** An announcement's row with its application, its sender's username, and how its mails went - over `a`. */
const ANNOUNCEMENT_SELECT = "SELECT a.*, ap.client_id, cu.username AS created_by_username,
        c.total, c.pending, c.sent, c.failed, c.skipped
   FROM announcements a
   LEFT JOIN applications ap ON ap.id = a.application_id
   LEFT JOIN users cu ON cu.id = a.created_by
   LEFT JOIN LATERAL (
        SELECT count(*) AS total,
               count(*) FILTER (WHERE r.status = 'pending') AS pending,
               count(*) FILTER (WHERE r.status = 'sent') AS sent,
               count(*) FILTER (WHERE r.status = 'failed') AS failed,
               count(*) FILTER (WHERE r.status = 'skipped') AS skipped
          FROM announcement_recipients r WHERE r.announcement_id = a.id
   ) c ON TRUE";

/**
 * An announcement's words as kept, in the order they were written in: English
 * first, then the other languages by code, each version's parts as the
 * composer has them. JSONB keeps its keys in an order of its own.
 *
 * @return array<string, array{subject: string, heading: string, body: string, button: string}>
 */
function announcementContent(string $json): array
{
    $content = json_decode($json, true);
    $versions = [];

    foreach (is_array($content) ? $content : [] as $code => $version) {
        foreach (array_keys(ANNOUNCEMENT_FIELDS) as $part) {
            $versions[(string) $code][$part] = is_array($version) ? (string) ($version[$part] ?? '') : '';
        }
    }
    uksort($versions, static fn(string $a, string $b): int => [$a !== TRANSLATION_FALLBACK, $a] <=> [$b !== TRANSLATION_FALLBACK, $b]);

    return $versions;
}

/** An announcement as the history lists it: the English subject stands for it. */
function announcementSummary(array $row): array
{
    $content = announcementContent((string) $row['content']);

    return [
        'id' => (int) $row['id'],
        'template' => $row['template'],
        'application' => $row['application_name'] === null ? null : ['client_id' => $row['client_id'], 'name' => $row['application_name']],
        'subject' => (string) ($content[TRANSLATION_FALLBACK]['subject'] ?? ''),
        'languages' => array_keys($content),
        'sender' => ['address' => $row['sender_address'], 'name' => $row['sender_name']],
        'link' => $row['link'],
        'status' => $row['status'],
        'counts' => [
            'total' => (int) $row['total'],
            'pending' => (int) $row['pending'],
            'sent' => (int) $row['sent'],
            'failed' => (int) $row['failed'],
            'skipped' => (int) $row['skipped'],
        ],
        'created_by' => $row['created_by_username'],
        'created_at' => isoTime($row['created_at']),
        'finished_at' => isoTime($row['finished_at']),
    ];
}

/**
 * Everything about one announcement: the summary, its words in each language,
 * and who it was for - the roles, and the people chosen by name as they are
 * now (one whose account is gone is left out of that list).
 */
function announcementDetail(PDO $pdo, int $id): ?array
{
    $statement = $pdo->prepare(ANNOUNCEMENT_SELECT . ' WHERE a.id = ?');
    $statement->execute([$id]);
    $row = $statement->fetch();

    if ($row === false) {
        return null;
    }

    $audience = json_decode((string) $row['audience'], true) ?: [];
    $people = [];
    $ids = array_values(array_filter((array) ($audience['user_ids'] ?? []), 'is_int'));
    if ($ids !== []) {
        $found = $pdo->prepare('SELECT id, username, email FROM users WHERE id IN (' . implode(',', array_fill(0, count($ids), '?')) . ') ORDER BY lower(username), id');
        $found->execute($ids);
        $people = array_map(static fn(array $person): array => ['id' => (int) $person['id'], 'username' => $person['username'], 'email' => $person['email']], $found->fetchAll());
    }

    return announcementSummary($row) + [
        'content' => announcementContent((string) $row['content']),
        'audience' => [
            'everybody' => asBool($audience['everybody'] ?? false),
            'roles' => array_map(
                static fn(mixed $grant): array => [
                    'client_id' => (string) (is_array($grant) ? $grant['client_id'] ?? '' : ''),
                    'role' => (string) (is_array($grant) ? $grant['role'] ?? '' : ''),
                ],
                array_values((array) ($audience['roles'] ?? [])),
            ),
            'users' => $people,
        ],
    ];
}

/**
 * The people an announcement went to, fifty a page: whom, at which address,
 * in which version, and how it went. `status` and `search` (name or address)
 * narrow it.
 *
 * @return array{total: int, page: int, pageSize: int, items: array}
 */
function announcementRecipients(PDO $pdo, int $id, array $query): array
{
    $where = ['r.announcement_id = ?'];
    $params = [$id];

    $status = field($query, 'status');
    if (in_array($status, ['pending', 'sent', 'failed', 'skipped'], true)) {
        $where[] = 'r.status = ?';
        $params[] = $status;
    }
    if (($search = field($query, 'search')) !== '') {
        $where[] = '(u.username ILIKE ? OR r.email ILIKE ?)';
        array_push($params, '%' . $search . '%', '%' . $search . '%');
    }

    $sql = ' WHERE ' . implode(' AND ', $where);
    $page = min(max(1, (int) ($query['page'] ?? 1)), 100000);
    $size = 50;

    $count = $pdo->prepare("SELECT count(*) FROM announcement_recipients r JOIN users u ON u.id = r.user_id$sql");
    $count->execute($params);

    $rows = $pdo->prepare(
        "SELECT r.user_id, u.username, r.email, r.language, r.status, r.sent_at
           FROM announcement_recipients r JOIN users u ON u.id = r.user_id
         $sql ORDER BY lower(u.username), r.user_id LIMIT $size OFFSET " .
            ($page - 1) * $size,
    );
    $rows->execute($params);

    return [
        'total' => (int) $count->fetchColumn(),
        'page' => $page,
        'pageSize' => $size,
        'items' => array_map(static fn(array $row): array => ['user_id' => (int) $row['user_id']] + isoTimes($row, ['sent_at']), $rows->fetchAll()),
    ];
}

// ── Stopping the news ────────────────────────────────────────────────────────

/**
 * The key the stop links are signed with: derived from the master key for this
 * one use (HKDF), so the key that encrypts the signing keys and the two-factor
 * secrets never signs anything itself. Its name is part of every link mailed
 * - another name would make every one of them stop working.
 */
function newsUnsubscribeKey(): string
{
    return hash_hkdf('sha256', iamMasterKey(), 32, 'anotoki-iam/news-unsubscribe/v1');
}

/**
 * The token in the link at the end of every announcement: the account's id
 * and a MAC of it (newsUnsubscribeKey()). It does one thing - switches that
 * account's news off - so it never expires and needs no row: whoever can read
 * the mail may stop it, which is what an unsubscribe link is.
 */
function newsUnsubscribeToken(int $userId): string
{
    return base64UrlEncode(pack('N', $userId)) . '.' . base64UrlEncode(substr(hash_hmac('sha256', pack('N', $userId), newsUnsubscribeKey(), true), 0, 16));
}

/** The account a token names, or null when it is not one of ours - checked in constant time. */
function newsUnsubscribeUser(string $token): ?int
{
    if (preg_match('/^([A-Za-z0-9_-]{6})\.[A-Za-z0-9_-]{22}$/D', $token, $parts) !== 1) {
        return null;
    }

    $id = base64UrlDecode($parts[1]);
    if (strlen($id) !== 4) {
        return null;
    }
    $userId = (int) unpack('N', $id)[1];

    return $userId > 0 && hash_equals(newsUnsubscribeToken($userId), $token) ? $userId : null;
}

/** The page the link opens: it asks before it switches anything off. */
function newsUnsubscribeUrl(int $userId): string
{
    return iamPageUrl('/unsubscribe', ['token' => newsUnsubscribeToken($userId)]);
}

// ── The mail ─────────────────────────────────────────────────────────────────

/** A body's paragraphs: split at an empty line, each trimmed, the empty ones gone. */
function announcementParagraphs(string $body): array
{
    return array_values(array_filter(array_map('trim', preg_split('/\n[ \t]*(?:\n[ \t]*)+/', $body) ?: []), static fn(string $paragraph): bool => $paragraph !== ''));
}

/**
 * One announcement as one reader gets it: the subject, the plain text, and
 * the HTML beside it - a fixed layout every word is escaped into, written the
 * way mail programs draw (tables, styles on the elements), light whatever the
 * reader's theme: the colours are the IAM's light palette.
 *
 * `$words` are mailWords() of the version's language, for the two lines every
 * announcement ends with.
 *
 * @param array{subject: string, heading: string, body: string, button: string} $version
 * @return array{subject: string, text: string, html: string}
 */
function announcementMessage(array $version, ?string $link, ?string $project, string $language, array $words, string $unsubscribeUrl): array
{
    $e = static fn(string $text): string => htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $paragraphs = announcementParagraphs($version['body']);
    $footer = (string) ($words['mail.news.footer'] ?? MAIL_FALLBACK['mail.news.footer']);
    $stop = (string) ($words['mail.news.stop'] ?? MAIL_FALLBACK['mail.news.stop']);
    $button = $link !== null && $version['button'] !== '';

    $text =
        $version['heading'] .
        "\n\n" .
        implode("\n\n", $paragraphs) .
        "\n\n" .
        ($button ? $version['button'] . ":\n" . $link . "\n\n" : '') .
        "-- \n" .
        $footer .
        "\n" .
        $stop .
        ":\n" .
        $unsubscribeUrl .
        "\n";

    $font = "-apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif";
    $body = '';
    foreach ($paragraphs as $paragraph) {
        $body .= '<p style="margin:0 0 16px;font-size:16px;line-height:1.6;color:#33405a;">' . nl2br($e($paragraph), false) . "</p>\n";
    }
    if ($button) {
        $body .=
            '<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:8px 0 0;"><tr>' .
            '<td style="border-radius:8px;background-color:#1f5fbf;">' .
            '<a href="' .
            $e((string) $link) .
            '" style="display:inline-block;padding:12px 24px;font-family:' .
            $font .
            ';font-size:16px;font-weight:600;line-height:1.25;color:#ffffff;text-decoration:none;border-radius:8px;">' .
            $e($version['button']) .
            "</a></td></tr></table>\n";
    }

    // The first words of the text, which a mailbox shows beside the subject.
    $preview = $e(textClip(preg_replace('/\s+/u', ' ', implode(' ', $paragraphs)) ?? '', 140));
    $logo = $e(iamIssuer() . '/anotoki.png');
    $site = $project !== null && $project !== '' ? '<span style="font-weight:400;color:#6b778d;"> &middot; ' . $e($project) . '</span>' : '';
    $lang = $e($language);
    $subject = $e($version['subject']);
    $heading = $e($version['heading']);
    $unsubscribe = $e($unsubscribeUrl);
    $footer = $e($footer);
    $stop = $e($stop);

    $html = <<<HTML
    <!DOCTYPE html>
    <html lang="{$lang}">
    <head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light">
    <meta name="supported-color-schemes" content="light">
    <title>{$subject}</title>
    </head>
    <body style="margin:0;padding:0;background-color:#f3f6fb;">
    <div style="display:none;max-height:0;overflow:hidden;opacity:0;color:transparent;">{$preview}</div>
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:#f3f6fb;">
    <tr><td align="center" style="padding:32px 16px;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="max-width:560px;">
    <tr><td style="padding:0 4px 16px;font-family:{$font};font-size:18px;font-weight:800;letter-spacing:-0.02em;color:#0f1b33;">
    <img src="{$logo}" width="28" height="23" alt="" style="vertical-align:middle;border:0;margin-right:8px;">anotoki{$site}
    </td></tr>
    <tr><td style="background-color:#ffffff;border:1px solid #dde4ef;border-radius:12px;padding:32px 28px;font-family:{$font};color:#0f1b33;">
    <h1 style="margin:0 0 16px;font-size:24px;line-height:1.3;font-weight:700;color:#0f1b33;">{$heading}</h1>
    {$body}</td></tr>
    <tr><td style="padding:20px 4px 0;font-family:{$font};font-size:13px;line-height:1.5;color:#5b6880;">
    {$footer}<br>
    <a href="{$unsubscribe}" style="color:#5b6880;text-decoration:underline;">{$stop}</a>
    </td></tr>
    </table>
    </td></tr>
    </table>
    </body>
    </html>

    HTML;

    return ['subject' => $version['subject'], 'text' => $text, 'html' => $html];
}

/**
 * The version a reader of `$language` gets of a draft, as the mail they would
 * get it in, with the link to stop the news as `$unsubscribeUrl`.
 *
 * @return array{subject: string, text: string, html: string, language: string}
 */
function announcementDraftMessage(array $draft, string $language, string $unsubscribeUrl): array
{
    $version = isset($draft['content'][$language]) ? $language : TRANSLATION_FALLBACK;

    return announcementMessage($draft['content'][$version], $draft['link'], $draft['application']['name'] ?? null, $version, mailWords($version), $unsubscribeUrl) + ['language' => $version];
}

/**
 * Whether an administrator may send themselves another test now: a test goes
 * only to their own address, but a page that sends one for every keystroke
 * would still fill their mailbox and the host's sending allowance.
 */
function announcementTestAllowed(PDO $pdo, int $actorId): bool
{
    $sent = $pdo->prepare("SELECT count(*) FROM email_log WHERE kind = ? AND user_id = ? AND created_at > CURRENT_TIMESTAMP - INTERVAL '1 hour'");
    $sent->execute([MAIL_KIND_ANNOUNCEMENT_TEST, $actorId]);

    return (int) $sent->fetchColumn() < ANNOUNCEMENT_TESTS_PER_HOUR;
}

/**
 * A draft's version for `$language`, to the administrator's own address and
 * nobody else's, the subject marked as a test. Its link to stop the news is
 * the administrator's own, and works.
 */
function announcementSendTest(array $draft, string $language, array $actor): bool
{
    $message = announcementDraftMessage($draft, $language, newsUnsubscribeUrl((int) $actor['id']));

    return sendMail(
        (string) $actor['email'],
        '[Test] ' . $message['subject'],
        $message['text'],
        MAIL_KIND_ANNOUNCEMENT_TEST,
        (int) $actor['id'],
        ['from' => $draft['sender']['address'], 'from_name' => $draft['sender']['name']],
        $message['html'],
        ['List-Unsubscribe' => '<' . newsUnsubscribeUrl((int) $actor['id']) . '>'],
    );
}

/**
 * Sends the next mails of an announcement that is sending: at most
 * ANNOUNCEMENT_BATCH_SIZE of them, for at most ANNOUNCEMENT_BATCH_SECONDS.
 *
 * Each in a transaction of its own: the announcement is read under a share
 * lock (Stop waits for the mail on its way, and the next one sees it
 * stopped), the next person's row is taken with FOR UPDATE SKIP LOCKED (a
 * second tab pressing on takes another), the person is asked again whether
 * they can be written to - switched off, or the news stopped, since the list
 * was made: skipped - and the mail goes to the address they have now. Once
 * nobody is left, the announcement is sent.
 *
 * Kept to the host's hourly allowance when config/mail.local.php names one
 * (announcementHourlyRoom()): with no room left nothing is sent, and `wait`
 * says in how many seconds there is.
 *
 * @return array{sent: int, failed: int, skipped: int, wait: ?int}
 */
function announcementSendBatch(PDO $pdo, int $id): array
{
    $done = ['sent' => 0, 'failed' => 0, 'skipped' => 0, 'wait' => null];

    $row = $pdo->prepare('SELECT * FROM announcements WHERE id = ?');
    $row->execute([$id]);
    $announcement = $row->fetch();
    if ($announcement === false || $announcement['status'] !== 'sending') {
        return $done;
    }

    $size = ANNOUNCEMENT_BATCH_SIZE;
    if (($room = announcementHourlyRoom($pdo)) !== null) {
        if ($room['room'] === 0) {
            return array_replace($done, ['wait' => $room['wait']]);
        }
        $size = min($size, $room['room']);
    }

    $content = json_decode((string) $announcement['content'], true) ?: [];
    $sender = ['from' => $announcement['sender_address'], 'from_name' => $announcement['sender_name']];

    // The words every mail ends with, read before the first transaction: a
    // query that failed inside one would take the mail's row with it.
    $words = [];
    $languages = $pdo->prepare("SELECT DISTINCT language FROM announcement_recipients WHERE announcement_id = ? AND status = 'pending'");
    $languages->execute([$id]);
    foreach ($languages->fetchAll(PDO::FETCH_COLUMN) as $language) {
        $words[$language] = mailWords((string) $language);
    }

    $status = $pdo->prepare('SELECT status FROM announcements WHERE id = ? FOR SHARE');
    $next = $pdo->prepare(
        'SELECT r.user_id, r.language, u.email, (' .
            announcementReachable($pdo) .
            ") AS reachable
           FROM announcement_recipients r JOIN users u ON u.id = r.user_id
          WHERE r.announcement_id = ? AND r.status = 'pending'
          ORDER BY r.user_id LIMIT 1 FOR UPDATE OF r SKIP LOCKED",
    );
    $mark = $pdo->prepare('UPDATE announcement_recipients SET status = ?, email = ?, sent_at = CURRENT_TIMESTAMP WHERE announcement_id = ? AND user_id = ?');

    $started = microtime(true);
    for ($count = 0; $count < $size && microtime(true) - $started < ANNOUNCEMENT_BATCH_SECONDS; $count++) {
        $pdo->beginTransaction();
        try {
            $status->execute([$id]);
            if ($status->fetchColumn() !== 'sending') {
                $pdo->rollBack();
                break;
            }

            $next->execute([$id]);
            $recipient = $next->fetch();
            if ($recipient === false) {
                $pdo->rollBack();
                break;
            }

            $userId = (int) $recipient['user_id'];
            if (!asBool($recipient['reachable'])) {
                $mark->execute(['skipped', $recipient['email'], $id, $userId]);
                $pdo->commit();
                $done['skipped']++;
                continue;
            }

            $language = isset($content[$recipient['language']]) ? (string) $recipient['language'] : TRANSLATION_FALLBACK;
            $unsubscribe = newsUnsubscribeUrl($userId);
            $message = announcementMessage($content[$language], $announcement['link'], $announcement['application_name'], $language, $words[$language] ?? mailWords($language), $unsubscribe);

            $ok = sendMail($recipient['email'], $message['subject'], $message['text'], MAIL_KIND_ANNOUNCEMENT, $userId, $sender, $message['html'], [
                'List-Unsubscribe' => '<' . $unsubscribe . '>',
            ]);

            $mark->execute([$ok ? 'sent' : 'failed', $recipient['email'], $id, $userId]);
            $pdo->commit();
            $done[$ok ? 'sent' : 'failed']++;
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    $pdo->prepare(
        "UPDATE announcements SET status = 'sent', finished_at = CURRENT_TIMESTAMP
          WHERE id = ? AND status = 'sending' AND NOT EXISTS (SELECT 1 FROM announcement_recipients WHERE announcement_id = ? AND status = 'pending')",
    )->execute([$id, $id]);

    return $done;
}

/** config/mail.local.php's `hourly_limit`: how many mails the host lets the IAM send in an hour, or null for no limit named. */
function announcementHourlyLimit(): ?int
{
    $limit = mailConfig()['hourly_limit'] ?? null;
    $limit = is_int($limit) || (is_string($limit) && ctype_digit($limit)) ? (int) $limit : 0;

    return $limit > 0 ? $limit : null;
}

/**
 * How many more mails the host's hourly allowance has room for now, or null
 * when config/mail.local.php names none (`hourly_limit`).
 *
 * Shared hosting counts every mail its accounts send in an hour, and past its
 * limit refuses them - or takes them and never sends them. Every mail the IAM
 * sent in the last hour counts (email_log), and announcements keep to
 * ANNOUNCEMENT_HOURLY_SHARE of the limit. With no room left, `wait` is the
 * seconds until the oldest of that hour's mails is an hour old.
 *
 * @return array{room: int, wait: int}|null
 */
function announcementHourlyRoom(PDO $pdo): ?array
{
    $limit = announcementHourlyLimit();
    if ($limit === null) {
        return null;
    }

    $hour = $pdo
        ->query(
            "SELECT count(*) AS sent, EXTRACT(EPOCH FROM (min(created_at) + INTERVAL '1 hour' - CURRENT_TIMESTAMP)) AS wait
           FROM email_log WHERE created_at > CURRENT_TIMESTAMP - INTERVAL '1 hour'",
        )
        ->fetch();
    $room = max(1, (int) floor($limit * ANNOUNCEMENT_HOURLY_SHARE)) - (int) $hour['sent'];

    return $room > 0 ? ['room' => $room, 'wait' => 0] : ['room' => 0, 'wait' => max(5, min(3600, (int) ceil((float) $hour['wait'])))];
}

/**
 * Stops an announcement that is sending: the mails not sent yet never go.
 * Waits for a mail on its way (announcementSendBatch() holds the row under a
 * share lock while it sends one). False when it was not sending.
 */
function announcementStop(PDO $pdo, int $id): bool
{
    $statement = $pdo->prepare("UPDATE announcements SET status = 'stopped', finished_at = CURRENT_TIMESTAMP WHERE id = ? AND status = 'sending'");
    $statement->execute([$id]);

    return $statement->rowCount() === 1;
}
