<?php

/**
 * Sending mail: the four messages the IAM sends by itself, and the
 * announcements an administrator writes (api/announcements.php).
 *
 * Two drivers, chosen in config/mail.local.php (config/mail.php describes it):
 * `mail` hands the message to PHP's mail(), which is what the hosting
 * supports; `file`, what a development machine uses, writes it into
 * storage/mail/ so every flow can be walked through with no mail server.
 *
 * Nothing here throws. A message that cannot be sent must not take the request
 * with it - an account is still created, a password still reset - and what the
 * caller must never do is say whether it went, because "if that address has an
 * account, a message is on its way" is the whole of what keeps these endpoints
 * from answering whether somebody has an account.
 *
 * The words are strings in the database, like the pages' (migration 006): the
 * `mail.` keys, in the recipient's own language, reworded in the admin
 * panel. They used to be written here, because a template with a link in
 * the middle is easy to break in a grid. So what could break was taken out of
 * the words instead:
 *
 *   - A message is put together here, always the same way: greeting, intro,
 *     the line that is the link or the code, then validity and ignore - the
 *     two a notice of an administrator's approval has no use for. That line
 *     is never part of a string and there is no placeholder for it, so no
 *     rewording can drop the link, move it, or put another one in its place.
 *   - Which placeholders a string has is said by MAIL_KEYS - the code, not the
 *     stored English - and only those are filled in.
 *   - The panel refuses an address in a mail's words, a line break in any of
 *     them, a brace that is not a placeholder, a long subject, and a string
 *     too long for one line of mail once its {username}s are filled
 *     (translationsCheck()). For the subject sending relies on none of that:
 *     sendMail() makes it one line, and short, whatever it was handed.
 *   - The English of every string is kept here too (MAIL_FALLBACK), for a
 *     database that has no strings yet - a build is uploaded before its
 *     migrations are applied - or has lost one. A mail never carries a key.
 */

const MAIL_CONFIRM_HOURS = 48;
const MAIL_RESET_MINUTES = 60;
const MAIL_LOGIN_CODE_MINUTES = 10;

const MAIL_KIND_CONFIRMATION = 'confirmation';
const MAIL_KIND_PASSWORD_RESET = 'password_reset';
const MAIL_KIND_LOGIN_CODE = 'login_code';
const MAIL_KIND_ACCOUNT_APPROVED = 'account_approved';
const MAIL_KIND_ANNOUNCEMENT = 'announcement';
const MAIL_KIND_ANNOUNCEMENT_TEST = 'announcement_test';

/**
 * Kinds whose subject holds nothing secret. Default-deny: the sign-in code is in
 * its message's subject, so an unlisted kind is logged with no subject at all.
 */
const MAIL_SUBJECT_SAFE_KINDS = [MAIL_KIND_CONFIRMATION, MAIL_KIND_PASSWORD_RESET, MAIL_KIND_ACCOUNT_APPROVED, MAIL_KIND_ANNOUNCEMENT, MAIL_KIND_ANNOUNCEMENT_TEST];

const MAIL_SENDER_NOREPLY = 'noreply';
const MAIL_SENDER_SUPPORT = 'support';

/** No subject is longer than this when it goes out, whatever the database holds. */
const MAIL_SUBJECT_MAX = 200;

/**
 * Every string a mail is made of, and the placeholders each may use.
 *
 * This list is the authority, not the English in the database: a string is
 * filled with exactly these and nothing else, and the admin panel refuses a
 * string, in any language, that uses another or leaves one out. There is no
 * placeholder for the link or the code of a message's middle line on purpose.
 */
const MAIL_KEYS = [
    'mail.confirm.subject' => [],
    'mail.confirm.greeting' => ['username'],
    'mail.confirm.intro' => [],
    'mail.confirm.validity' => ['hours'],
    'mail.confirm.ignore' => [],
    'mail.reset.subject' => [],
    'mail.reset.greeting' => ['username'],
    'mail.reset.intro' => [],
    'mail.reset.validity' => ['minutes'],
    'mail.reset.ignore' => [],
    'mail.loginCode.subject' => ['code'],
    'mail.loginCode.greeting' => ['username'],
    'mail.loginCode.intro' => [],
    'mail.loginCode.validity' => ['minutes'],
    'mail.loginCode.ignore' => [],
    'mail.approved.subject' => [],
    'mail.approved.greeting' => ['username'],
    'mail.approved.intro' => [],
    // The announcements' templates (api/announcements.php): starting points the
    // admin panel fills a draft with, {project} put in there - not sent as
    // they stand.
    'mail.newVersion.subject' => ['project'],
    'mail.newVersion.heading' => ['project'],
    'mail.newVersion.body' => ['project'],
    'mail.newVersion.button' => ['project'],
    'mail.newRelease.subject' => ['project'],
    'mail.newRelease.heading' => ['project'],
    'mail.newRelease.body' => ['project'],
    'mail.newRelease.button' => ['project'],
    'mail.newSurvey.subject' => ['project'],
    'mail.newSurvey.heading' => ['project'],
    'mail.newSurvey.body' => ['project'],
    'mail.newSurvey.button' => [],
    // What every announcement ends with.
    'mail.news.footer' => [],
    'mail.news.stop' => [],
];

/**
 * The English of every mail string, as migrations 006, 012 and 013 seed it (a
 * test holds the two together). Used string by string whenever the database
 * has nothing to say: no tables yet, a query that failed, a row that is gone.
 */
const MAIL_FALLBACK = [
    'mail.confirm.subject' => 'Confirm your anotoki account',
    'mail.confirm.greeting' => 'Hello {username},',
    'mail.confirm.intro' => 'Open the link below to confirm this address and finish setting up your anotoki account - one account for every anotoki site:',
    'mail.confirm.validity' => 'The link is good for {hours} hours.',
    'mail.confirm.ignore' => 'If you did not sign up, you can ignore this message - the account cannot be used until somebody opens that link.',
    'mail.reset.subject' => 'Set a new anotoki password',
    'mail.reset.greeting' => 'Hello {username},',
    'mail.reset.intro' => 'Somebody asked to set a new password for this account. Open the link below to choose one:',
    'mail.reset.validity' => 'The link is good for {minutes} minutes and can only be used once.',
    'mail.reset.ignore' => 'If it was not you, nothing has changed and there is nothing to do. Your current password still works.',
    'mail.loginCode.subject' => 'Your anotoki sign-in code: {code}',
    'mail.loginCode.greeting' => 'Hello {username},',
    'mail.loginCode.intro' => 'Your sign-in code is:',
    'mail.loginCode.validity' => 'It is good for {minutes} minutes and can only be used once.',
    'mail.loginCode.ignore' => 'If you did not ask to sign in, you can ignore this message. Nobody can get in with this code unless they can also read this mailbox.',
    'mail.approved.subject' => 'Your anotoki account is approved',
    'mail.approved.greeting' => 'Hello {username},',
    'mail.approved.intro' => 'An administrator has approved your anotoki account. You can sign in now, the same way you signed up:',
    'mail.newVersion.subject' => 'A new version of {project}',
    'mail.newVersion.heading' => '{project} has a new version',
    'mail.newVersion.body' => 'A new version of {project} is out. Come and see what is new - your anotoki account signs you in as always.',
    'mail.newVersion.button' => 'Open {project}',
    'mail.newRelease.subject' => 'New on anotoki: {project}',
    'mail.newRelease.heading' => 'Meet {project}',
    'mail.newRelease.body' => '{project} is a new anotoki site, and your anotoki account already works there - no new sign-up needed.',
    'mail.newRelease.button' => 'Try {project}',
    'mail.newSurvey.subject' => 'A new survey about {project}',
    'mail.newSurvey.heading' => 'What do you think of {project}?',
    'mail.newSurvey.body' => 'We have put together a short survey about {project}. It takes a few minutes, and your answers shape what comes next.',
    'mail.newSurvey.button' => 'Take the survey',
    'mail.news.footer' => 'You get this email because you have an anotoki account.',
    'mail.news.stop' => 'Stop the news from anotoki sites',
];

/**
 * The address a mail goes out from: one of config/mail.local.php's senders by
 * its key, or - an announcement's - an address and a name given whole.
 *
 * @param string|array{from: string, from_name?: string} $sender
 * @return array{from: string, from_name: string}
 */
function mailSender(string|array $sender): array
{
    if (is_array($sender)) {
        return ['from' => (string) $sender['from'], 'from_name' => (string) ($sender['from_name'] ?? '')];
    }

    $config = mailConfig();
    $entry = $config['senders'][$sender] ?? null;
    $from = (string) ($config['from'] ?? 'noreply@localhost');
    $name = (string) ($config['from_name'] ?? '');

    return is_array($entry)
        ? ['from' => (string) ($entry['from'] ?? $from), 'from_name' => (string) ($entry['from_name'] ?? $name)]
        : ['from' => $from, 'from_name' => $name];
}

/**
 * Anything outside ASCII in a header is encoded, or Slovak arrives as mojibake.
 *
 * /D, because without it `$` also matches before a line break at the very
 * end: a text ending in one would pass as plain and carry it into the header.
 */
function mailEncodeHeader(string $text): string
{
    return preg_match('/^[\x20-\x7E]*$/D', $text) ? $text : '=?UTF-8?B?' . base64_encode($text) . '?=';
}

/**
 * The words of the mails in a language: every key of MAIL_KEYS, with a string.
 *
 * The language's own strings over the English ones, read in one query - and
 * the language's only while it is on offer: one switched off in the admin
 * panel is no longer spoken, in a mail either. Whatever the database does
 * not give - it has no tables yet, the query failed, a row was deleted - is
 * said with MAIL_FALLBACK, key by key. It never throws and never answers
 * with a key or an empty string.
 *
 * Whether the tables are there is asked first, in a query that cannot fail:
 * a failed one inside somebody's transaction would end everything after it.
 *
 * @return array<string, string>
 */
function mailWords(string $language): array
{
    $words = MAIL_FALLBACK;

    // What is not a language's code is asked about as English: bytes that are
    // not text would make the query itself fail.
    if (preg_match('/^[a-z]{2}(-[a-z]{2})?$/D', $language) !== 1) {
        $language = 'en';
    }

    try {
        $pdo = iamDb();

        if (!asBool($pdo->query("SELECT to_regclass('public.translations') IS NOT NULL AND to_regclass('public.languages') IS NOT NULL")->fetchColumn())) {
            return $words;
        }

        $statement = $pdo->prepare(
            "SELECT t.key_name, t.language_code, t.value
               FROM translations t JOIN languages l ON l.code = t.language_code
              WHERE t.key_name LIKE 'mail.%' AND (t.language_code = 'en' OR (t.language_code = ? AND l.enabled))"
        );
        $statement->execute([$language]);

        $english = [];
        $own = [];
        foreach ($statement->fetchAll() as $row) {
            if ($row['language_code'] === 'en') {
                $english[$row['key_name']] = (string) $row['value'];
            } else {
                $own[$row['key_name']] = (string) $row['value'];
            }
        }

        foreach (array_keys(MAIL_KEYS) as $key) {
            foreach ([$own[$key] ?? '', $english[$key] ?? ''] as $value) {
                // Nothing a reader could see is no string: spaces, or only
                // characters of no width - a subject of nothing, otherwise.
                if (!textBlank($value)) {
                    $words[$key] = $value;
                    break;
                }
            }
        }
    } catch (\Throwable $e) {
        error_log('[mail] could not read the words of a message, sending the built-in English: ' . $e->getMessage());
    }

    return $words;
}

/** The words for one recipient: in their language while it is offered, else English. Never throws. */
function mailWordsFor(array $user): array
{
    try {
        $language = languageOrDefault($user['language'] ?? null);
    } catch (\Throwable) {
        $language = 'en';
    }

    return mailWords($language);
}

/**
 * A mail string with its values in: only the placeholders MAIL_KEYS gives that
 * key, in one pass. Anything else in braces stays as it is written - a
 * "{link}" somebody put into a subject is six characters, never a link -
 * and a value is never read again, so a username of "{hours}" stays that.
 */
function mailFill(string $key, string $text, array $values): string
{
    $allowed = MAIL_KEYS[$key] ?? [];

    $filled = preg_replace_callback(
        '/\{([A-Za-z][A-Za-z0-9_]*)\}/',
        static fn(array $match): string => in_array($match[1], $allowed, true) && array_key_exists($match[1], $values)
            ? (string) $values[$match[1]]
            : $match[0],
        $text
    );

    return $filled ?? $text;
}

/**
 * A whole message: ['subject' => ..., 'body' => ...].
 *
 * $mail is 'confirm', 'reset', 'loginCode' or 'approved'. $line is the
 * message's middle line - the link, or the code - put in here and never by a
 * string. $after names the paragraphs under it: the link's or the code's
 * validity and the line for somebody who did not ask for it, which a notice
 * of an administrator's approval has no use for.
 *
 * @return array{subject: string, body: string}
 */
function mailMessage(string $mail, array $words, string $line, array $values, array $after = ['validity', 'ignore']): array
{
    $part = static function (string $name) use ($mail, $words, $values): string {
        $key = 'mail.' . $mail . '.' . $name;

        return mailFill($key, (string) ($words[$key] ?? MAIL_FALLBACK[$key]), $values);
    };

    return [
        'subject' => $part('subject'),
        'body' => implode("\n\n", [$part('greeting'), $part('intro'), $line, ...array_map($part, $after)]) . "\n",
    ];
}

/**
 * Sends one message, and logs it (email_log keeps no body).
 *
 * `$body` is plain text. With `$html` beside it, the message goes as
 * multipart/alternative - the announcements, which look like something: both
 * parts base64, whose lines stay short however long a paragraph somebody
 * typed. `$headers` adds headers of the caller's (List-Unsubscribe), each
 * kept to one line.
 *
 * @param string|array{from: string, from_name?: string} $sender
 * @param array<string, string> $headers
 */
function sendMail(string $to, string $subject, string $body, string $kind, ?int $userId = null, string|array $sender = MAIL_SENDER_NOREPLY, ?string $html = null, array $headers = []): bool
{
    // One line, and not a long one - here, whatever was checked when the words
    // were saved: a subject is a header, a line break in a header starts the
    // next one ("Bcc: ..."), and a string can also reach the database without
    // the admin panel. Done once, before the header, the file and the log.
    $subject = mb_substr((string) preg_replace('/[\r\n\t]/', ' ', $subject), 0, MAIL_SUBJECT_MAX, 'UTF-8');

    $resolved = mailSender($sender);
    $from = $resolved['from_name'] === '' ? $resolved['from'] : mailEncodeHeader($resolved['from_name']) . ' <' . $resolved['from'] . '>';

    $lines = [
        'From: ' . $from,
        'Reply-To: ' . $resolved['from'],
        'MIME-Version: 1.0',
    ];

    if ($html === null) {
        $lines[] = 'Content-Type: text/plain; charset=UTF-8';
        $lines[] = 'Content-Transfer-Encoding: 8bit';
        $content = $body;
    } else {
        $boundary = 'anotoki-' . bin2hex(random_bytes(12));
        $lines[] = 'Content-Type: multipart/alternative; boundary="' . $boundary . '"';
        $part = static fn(string $type, string $text): string => "--{$boundary}\nContent-Type: {$type}; charset=UTF-8\nContent-Transfer-Encoding: base64\n\n"
            . chunk_split(base64_encode($text), 76, "\n");
        $content = $part('text/plain', $body) . $part('text/html', $html) . "--{$boundary}--\n";
    }

    foreach ($headers as $name => $value) {
        $lines[] = $name . ': ' . preg_replace('/[\r\n]+/', ' ', $value);
    }

    $ok = (mailConfig()['driver'] ?? '') === 'mail'
        ? @mail($to, mailEncodeHeader($subject), $content, implode("\n", $lines))
        : mailToFile($to, $subject, $content, $lines);

    // Inside somebody's transaction - an announcement marks each of its mails
    // in one - a failed insert must not end it: the mail has gone, and the row
    // that says so must still be written, or it would be sent again. A
    // savepoint takes back the insert alone.
    try {
        $pdo = iamDb();
        $nested = $pdo->inTransaction();
        if ($nested) {
            $pdo->exec('SAVEPOINT email_log');
        }
        try {
            $pdo->prepare('INSERT INTO email_log (recipient, kind, subject, status, user_id) VALUES (?, ?, ?, ?, ?)')
                ->execute([$to, $kind, in_array($kind, MAIL_SUBJECT_SAFE_KINDS, true) ? $subject : null, $ok ? 'sent' : 'failed', $userId]);
        } catch (\Throwable $e) {
            if ($nested) {
                $pdo->exec('ROLLBACK TO SAVEPOINT email_log');
            }
            throw $e;
        }
        if ($nested) {
            $pdo->exec('RELEASE SAVEPOINT email_log');
        }
    } catch (\Throwable $e) {
        error_log('[email_log] could not record a send: ' . $e->getMessage());
    }

    return $ok;
}

/**
 * The development driver: one .eml per message. The name carries microseconds
 * and a random tail, because a resend pressed twice sends two inside a second
 * and a mailbox must never quietly lose the first.
 */
function mailToFile(string $to, string $subject, string $body, array $headers): bool
{
    $directory = mailDirectory();

    if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
        return false;
    }

    [$microseconds, $seconds] = explode(' ', microtime());
    $name = sprintf('%s.%s-%s-%s.eml', date('Ymd-His', (int) $seconds), substr($microseconds, 2, 6), bin2hex(random_bytes(2)), preg_replace('/[^A-Za-z0-9._-]+/', '_', $to));

    return @file_put_contents($directory . '/' . $name, implode("\n", [...$headers, 'To: ' . $to, 'Subject: ' . $subject, '', $body])) !== false;
}

function sendConfirmationMail(array $user, string $token): bool
{
    $text = mailMessage('confirm', mailWordsFor($user), iamPageUrl('/confirm-email', ['token' => $token]), [
        'username' => $user['username'],
        'hours' => MAIL_CONFIRM_HOURS,
    ]);

    // From support@ - a welcome is the one account message a person might answer.
    return sendMail($user['email'], $text['subject'], $text['body'], MAIL_KIND_CONFIRMATION, $user['id'] ?? null, MAIL_SENDER_SUPPORT);
}

function sendPasswordResetMail(array $user, string $token): bool
{
    $text = mailMessage('reset', mailWordsFor($user), iamPageUrl('/reset-password', ['token' => $token]), [
        'username' => $user['username'],
        'minutes' => MAIL_RESET_MINUTES,
    ]);

    return sendMail($user['email'], $text['subject'], $text['body'], MAIL_KIND_PASSWORD_RESET, $user['id'] ?? null);
}

/** The code is in the subject as well: seen in the notification, it need not even be opened. */
function sendLoginCodeMail(array $user, string $code): bool
{
    $text = mailMessage('loginCode', mailWordsFor($user), '    ' . $code, [
        'username' => $user['username'],
        'code' => $code,
        'minutes' => MAIL_LOGIN_CODE_MINUTES,
    ]);

    return sendMail($user['email'], $text['subject'], $text['body'], MAIL_KIND_LOGIN_CODE, $user['id'] ?? null);
}

/**
 * An administrator approved the account (api/accounts.php): it signs in now,
 * the way it signed up - the link is the sign-in page. From support@, like the
 * confirmation: both welcome somebody, and might be answered.
 */
function sendAccountApprovedMail(array $user): bool
{
    $text = mailMessage('approved', mailWordsFor($user), iamPageUrl('/login'), ['username' => $user['username']], []);

    return sendMail($user['email'], $text['subject'], $text['body'], MAIL_KIND_ACCOUNT_APPROVED, $user['id'] ?? null, MAIL_SENDER_SUPPORT);
}
