<?php

/**
 * Sending mail, and the four messages worth sending.
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
 * The bodies are here rather than in a translation table: a multi-line template
 * with a link in the middle is easy to break in a grid and nobody notices until
 * somebody stops receiving their reset. Each carries both languages, chosen by
 * the recipient's own setting.
 */

const MAIL_CONFIRM_HOURS = 48;
const MAIL_RESET_MINUTES = 60;
const MAIL_LOGIN_CODE_MINUTES = 10;

const MAIL_KIND_CONFIRMATION = 'confirmation';
const MAIL_KIND_PASSWORD_RESET = 'password_reset';
const MAIL_KIND_LOGIN_CODE = 'login_code';

/**
 * Kinds whose subject holds nothing secret. Default-deny: the sign-in code is in
 * its message's subject, so an unlisted kind is logged with no subject at all.
 */
const MAIL_SUBJECT_SAFE_KINDS = [MAIL_KIND_CONFIRMATION, MAIL_KIND_PASSWORD_RESET];

const MAIL_SENDER_NOREPLY = 'noreply';
const MAIL_SENDER_SUPPORT = 'support';

/** @return array{from: string, from_name: string} */
function mailSender(string $sender): array
{
    $config = mailConfig();
    $entry = $config['senders'][$sender] ?? null;
    $from = (string) ($config['from'] ?? 'noreply@localhost');
    $name = (string) ($config['from_name'] ?? '');

    return is_array($entry)
        ? ['from' => (string) ($entry['from'] ?? $from), 'from_name' => (string) ($entry['from_name'] ?? $name)]
        : ['from' => $from, 'from_name' => $name];
}

/** Anything outside ASCII in a header is encoded, or Slovak arrives as mojibake. */
function mailEncodeHeader(string $text): string
{
    return preg_match('/^[\x20-\x7E]*$/', $text) ? $text : '=?UTF-8?B?' . base64_encode($text) . '?=';
}

function sendMail(string $to, string $subject, string $body, string $kind, ?int $userId = null, string $sender = MAIL_SENDER_NOREPLY): bool
{
    $resolved = mailSender($sender);
    $from = $resolved['from_name'] === '' ? $resolved['from'] : mailEncodeHeader($resolved['from_name']) . ' <' . $resolved['from'] . '>';

    $headers = [
        'From: ' . $from,
        'Reply-To: ' . $resolved['from'],
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=UTF-8',
        'Content-Transfer-Encoding: 8bit',
    ];

    $ok = (mailConfig()['driver'] ?? '') === 'mail'
        ? @mail($to, mailEncodeHeader($subject), $body, implode("\n", $headers))
        : mailToFile($to, $subject, $body, $headers);

    try {
        iamDb()->prepare('INSERT INTO email_log (recipient, kind, subject, status, user_id) VALUES (?, ?, ?, ?, ?)')
            ->execute([$to, $kind, in_array($kind, MAIL_SUBJECT_SAFE_KINDS, true) ? $subject : null, $ok ? 'sent' : 'failed', $userId]);
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

function mailText(array $byLanguage, ?string $language): array
{
    return $byLanguage[$language ?? 'en'] ?? $byLanguage['en'];
}

function sendConfirmationMail(array $user, string $token): bool
{
    $link = iamPageUrl('/confirm-email', ['token' => $token]);

    $text = mailText([
        'en' => [
            'subject' => 'Confirm your anotoki account',
            'body' => "Hello {$user['username']},\n\n"
                . "Open the link below to confirm this address and finish setting up your anotoki account - one account for every anotoki site:\n\n"
                . "$link\n\n"
                . 'The link is good for ' . MAIL_CONFIRM_HOURS . " hours.\n\n"
                . "If you did not sign up, you can ignore this message - the account cannot be used until somebody opens that link.\n",
        ],
        'sk' => [
            'subject' => 'Potvrď svoj účet anotoki',
            'body' => "Ahoj {$user['username']},\n\n"
                . "Otvor odkaz nižšie, potvrď túto adresu a dokonči vytvorenie účtu anotoki - jedného účtu pre všetky stránky anotoki:\n\n"
                . "$link\n\n"
                . 'Odkaz platí ' . MAIL_CONFIRM_HOURS . " hodín.\n\n"
                . "Ak si sa neregistroval, túto správu môžeš ignorovať - účet sa nedá použiť, kým niekto ten odkaz neotvorí.\n",
        ],
    ], $user['language'] ?? 'en');

    // From support@ - a welcome is the one account message a person might answer.
    return sendMail($user['email'], $text['subject'], $text['body'], MAIL_KIND_CONFIRMATION, $user['id'] ?? null, MAIL_SENDER_SUPPORT);
}

function sendPasswordResetMail(array $user, string $token): bool
{
    $link = iamPageUrl('/reset-password', ['token' => $token]);

    $text = mailText([
        'en' => [
            'subject' => 'Set a new anotoki password',
            'body' => "Hello {$user['username']},\n\n"
                . "Somebody asked to set a new password for this account. Open the link below to choose one:\n\n"
                . "$link\n\n"
                . 'The link is good for ' . MAIL_RESET_MINUTES . " minutes and can only be used once.\n\n"
                . "If it was not you, nothing has changed and there is nothing to do. Your current password still works.\n",
        ],
        'sk' => [
            'subject' => 'Nastavenie nového hesla anotoki',
            'body' => "Ahoj {$user['username']},\n\n"
                . "Niekto požiadal o nastavenie nového hesla k tomuto účtu. Otvor odkaz nižšie a zvoľ si ho:\n\n"
                . "$link\n\n"
                . 'Odkaz platí ' . MAIL_RESET_MINUTES . " minút a dá sa použiť len raz.\n\n"
                . "Ak si to nebol ty, nič sa nezmenilo a nemusíš nič robiť. Tvoje súčasné heslo stále platí.\n",
        ],
    ], $user['language'] ?? 'en');

    return sendMail($user['email'], $text['subject'], $text['body'], MAIL_KIND_PASSWORD_RESET, $user['id'] ?? null);
}

/** The code is in the subject as well: seen in the notification, it need not even be opened. */
function sendLoginCodeMail(array $user, string $code): bool
{
    $text = mailText([
        'en' => [
            'subject' => "Your anotoki sign-in code: $code",
            'body' => "Hello {$user['username']},\n\n"
                . "Your sign-in code is:\n\n    $code\n\n"
                . 'It is good for ' . MAIL_LOGIN_CODE_MINUTES . " minutes and can only be used once.\n\n"
                . "If you did not ask to sign in, you can ignore this message. Nobody can get in with this code unless they can also read this mailbox.\n",
        ],
        'sk' => [
            'subject' => "Tvoj prihlasovací kód anotoki: $code",
            'body' => "Ahoj {$user['username']},\n\n"
                . "Tvoj prihlasovací kód je:\n\n    $code\n\n"
                . 'Platí ' . MAIL_LOGIN_CODE_MINUTES . " minút a dá sa použiť len raz.\n\n"
                . "Ak si o prihlásenie nežiadal, túto správu môžeš ignorovať. S týmto kódom sa nikto nedostane dnu, pokiaľ nevie čítať aj túto schránku.\n",
        ],
    ], $user['language'] ?? 'en');

    return sendMail($user['email'], $text['subject'], $text['body'], MAIL_KIND_LOGIN_CODE, $user['id'] ?? null);
}
