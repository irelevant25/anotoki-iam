<?php

/**
 * create-admin.php - the first administrator of an empty IAM
 *
 *   php create-admin.php you@example.com [password]
 *
 * Only while there are no accounts at all, so it is a way to start and never a
 * way in. Without a password one is generated and printed once, and the account
 * is flagged to choose its own at the first sign-in. The account holds the
 * IAM's ADMIN role; roles in the sites are granted from the admin console.
 *
 * On a server without a shell, setup.php does this as part of the install.
 */

require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/api/migration_runner.php';

requireSetUpOnCli();

$email = $argv[1] ?? '';
$password = $argv[2] ?? null;

if ($email === '' || !str_contains($email, '@')) {
    fwrite(STDERR, "usage: php create-admin.php you@example.com [password]\n");
    exit(1);
}

if ($password !== null && strlen($password) < 8) {
    fwrite(STDERR, "The password needs at least 8 characters.\n");
    exit(1);
}

$created = createFirstAdmin(iamDb(), $email, $password);

if ($created === null) {
    fwrite(STDERR, "There are accounts already; make more from the admin console.\n");
    exit(1);
}

echo "Administrator created.\n";
echo '  username : ' . $created['username'] . "\n";
echo '  email    : ' . $created['email'] . "\n";

if ($created['generated']) {
    echo '  password : ' . $created['password'] . "\n";
    echo "  A new password will be asked for at the first sign-in.\n";
}
