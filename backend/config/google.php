<?php

// ---------------------------------------------------------------------------
// config/google.php - Google sign-in, if this installation has it
// ---------------------------------------------------------------------------
// config/google.local.php returns ['client_id' => '...apps.googleusercontent.com'],
// written by setup.php when a client id is entered. It is the one optional
// file: no file means no Google - the button is not drawn and the endpoints
// refuse.
//
// Google's "Authorised JavaScript origins" for that client must list the IAM's
// own public address (http://localhost:4300 on a development machine) - the
// button is drawn on the IAM's pages, never on the sites.
// ---------------------------------------------------------------------------

require_once __DIR__ . '/config.php';

function googleClientId(): ?string
{
    $config = localConfig('google');
    $value = is_array($config) ? trim((string) ($config['client_id'] ?? '')) : '';

    return $value === '' ? null : $value;
}
