<?php

// ---------------------------------------------------------------------------
// config/mail.php - how the IAM sends mail, and as whom
// ---------------------------------------------------------------------------
// From config/mail.local.php, which setup.php writes:
//
//   return [
//       'driver' => 'file',              // or 'mail'
//       'from' => 'noreply@localhost',
//       'from_name' => 'anotoki',
//       'senders' => [
//           'noreply' => ['from' => 'noreply@localhost', 'from_name' => 'anotoki'],
//           'support' => ['from' => 'support@localhost', 'from_name' => 'anotoki'],
//       ],
//   ];
//
// The `file` driver sends nothing: it writes each message into storage/mail/
// (or 'directory', when the file names one), so registration, confirmation,
// resets and sign-in codes can be walked through on a machine with no mail
// server - the newest .eml there has the link. `mail` hands the message to
// PHP's mail(), which is what the hosting supports; its one hard rule is that
// `from` must be a real mailbox on the hosted domain.
//
// The links in the messages point at the IAM's own pages, built from the
// public URL in config/iam.php - there is no separate base URL to keep in step.
//
// One key setup.php does not write, and keeps when it finds it: the host's
// hourly allowance, for a host that has one -
//
//       'hourly_limit' => 300,
//
// Announcements (api/announcements.php) then keep to nine tenths of it,
// counting every mail the IAM sent in the last hour, and wait for room rather
// than go over; the rest is left for confirmations, codes and resets. Without
// it they go as fast as the page sends them.
// ---------------------------------------------------------------------------

require_once __DIR__ . '/config.php';

function mailConfig(): array
{
    $config = localConfig('mail');

    if (!is_array($config)) {
        throw new IamNotSetUp('config/mail.local.php is missing');
    }

    return $config;
}

/** Where the `file` driver writes its messages. */
function mailDirectory(): string
{
    $directory = trim((string) (mailConfig()['directory'] ?? ''));

    return $directory !== '' ? rtrim($directory, '/\\') : iamStorageDir() . '/mail';
}
