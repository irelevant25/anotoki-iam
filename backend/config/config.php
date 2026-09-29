<?php

// ---------------------------------------------------------------------------
// config/config.php - the per-installation files, and whether they are there
// ---------------------------------------------------------------------------
// Everything that differs from one installation to the next lives in
// config/*.local.php and nowhere else: gitignored, written by setup.php - on a
// development machine exactly as on a server - and never filled in from
// anything committed. The committed files beside them read those files and
// keep only what is the same everywhere (lifetimes, the cookie's name).
//
//   database.local.php  required  the PostgreSQL connection; 'legacy' names the
//                                 genshin site's old `users` database for the
//                                 one-time import of its accounts
//   secret.local.php    required  the master key (config/secret.php says why)
//   iam.local.php       required  public_url - the issuer - and any tunable of
//                                 config/iam.php this installation overrides
//   mail.local.php      required  how mail is sent, and from which addresses
//   google.local.php    optional  the Google sign-in client id; without it the
//                                 Google button is not drawn
//
// Without the required ones the IAM does not run. The API answers every
// request with 503 `not_set_up`, the pages say so, and both point at
// setup.php. An identity provider that guessed the rest - a database called iam
// on 127.0.0.1, a sender at localhost, an issuer nobody chose - would be
// signing tokens for an address nobody configured.
// ---------------------------------------------------------------------------

/**
 * Thrown by whatever needs a per-installation value that is not there: the
 * command-line tools say it and stop; the API never gets that far, because
 * its first middleware answers 503 before any handler runs.
 */
final class IamNotSetUp extends RuntimeException
{
    public function __construct(string $problem)
    {
        parent::__construct("The IAM is not set up yet: {$problem}. Open setup.php to set it up (see DEV.md).");
    }
}

/**
 * Where the *.local.php files are read from: this folder.
 *
 * The tests run against configuration of their own and say so by defining
 * IAM_CONFIG_DIR before anything is loaded. It is a constant, so only code can
 * set it - nothing a request or the environment carries.
 */
function iamConfigDir(): string
{
    return defined('IAM_CONFIG_DIR') ? rtrim((string) constant('IAM_CONFIG_DIR'), '/\\') : __DIR__;
}

/** storage/ - mail in development, caches, the installer's lock. IAM_STORAGE_DIR, as above, for the tests. */
function iamStorageDir(): string
{
    return defined('IAM_STORAGE_DIR') ? rtrim((string) constant('IAM_STORAGE_DIR'), '/\\') : dirname(__DIR__) . '/storage';
}

function localConfigPath(string $name): string
{
    return iamConfigDir() . '/' . $name . '.local.php';
}

/** The cache behind localConfig(), by reference so forgetLocalConfig() can empty it. */
function &localConfigCache(): array
{
    static $cache = [];

    return $cache;
}

/**
 * What config/{name}.local.php returns, or null when there is no such file.
 *
 * Read once per process. The file is required inside a closure of its own, so
 * it sees none of the caller's variables.
 */
function localConfig(string $name): mixed
{
    $cache = &localConfigCache();

    if (!array_key_exists($name, $cache)) {
        $path = localConfigPath($name);
        $cache[$name] = is_file($path) ? (static fn(string $file): mixed => require $file)($path) : null;
    }

    return $cache[$name];
}

/** Makes the next localConfig() read the files again - the installer calls it after writing them. */
function forgetLocalConfig(): void
{
    $cache = &localConfigCache();
    $cache = [];
}

/**
 * The hosts a plain-http address may name: this computer, for development.
 * Anywhere else a code or a token sent over http can be read on its way.
 */
function loopbackHost(string $host): bool
{
    return in_array(strtolower(trim($host, '[]')), ['localhost', '127.0.0.1', '::1'], true);
}

/** Whether an address - a URL or an origin - points at this computer. */
function onThisComputer(?string $address): bool
{
    $host = $address === null ? null : parse_url($address, PHP_URL_HOST);

    return is_string($host) && loopbackHost($host);
}

/**
 * An issuer the IAM can be: scheme and host (and port), nothing after it -
 * https, or http on this computer, like every address it hands tokens to.
 */
function validPublicUrl(string $url): bool
{
    if (!preg_match('#^(https?)://(\[[0-9a-fA-F:.]+\]|[A-Za-z0-9.-]+)(:\d{1,5})?$#', $url, $found)) {
        return false;
    }

    return $found[1] === 'https' || loopbackHost($found[2]);
}

/**
 * What is missing or wrong in the per-installation files, a sentence each; an
 * empty list when the IAM is set up. The sentences name files and keys, never
 * a value, so they can be shown to whoever opens the installer.
 *
 * @return string[]
 */
function iamSetUpProblems(): array
{
    $problems = [];

    $database = localConfig('database');
    if (!is_array($database)) {
        $problems[] = 'config/database.local.php is missing';
    } elseif (trim((string) ($database['host'] ?? '')) === '' || trim((string) ($database['username'] ?? '')) === ''
        || !is_array($database['databases'] ?? null) || trim((string) ($database['databases']['iam'] ?? '')) === '') {
        $problems[] = 'config/database.local.php does not name a host, a username and the iam database';
    }

    $secret = localConfig('secret');
    if ($secret === null) {
        $problems[] = 'config/secret.local.php is missing';
    } elseif (!is_string($secret) || !preg_match('/^[0-9a-f]{64}$/i', trim($secret))) {
        $problems[] = 'config/secret.local.php is not a key of 64 hex characters';
    }

    $iam = localConfig('iam');
    if (!is_array($iam)) {
        $problems[] = 'config/iam.local.php is missing';
    } elseif (!validPublicUrl(rtrim(trim((string) ($iam['public_url'] ?? '')), '/'))) {
        $problems[] = 'config/iam.local.php has no valid public_url (scheme and host, like http://localhost:4300)';
    }

    $mail = localConfig('mail');
    if (!is_array($mail)) {
        $problems[] = 'config/mail.local.php is missing';
    } elseif (!in_array($mail['driver'] ?? null, ['file', 'mail'], true) || trim((string) ($mail['from'] ?? '')) === '') {
        $problems[] = 'config/mail.local.php does not say how mail is sent (driver file or mail) and from which address';
    }

    return $problems;
}

/** For the command-line tools: stop, saying why, unless the IAM is set up. */
function requireSetUpOnCli(): void
{
    $problems = iamSetUpProblems();

    if ($problems !== []) {
        fwrite(STDERR, "The IAM is not set up yet:\n  - " . implode("\n  - ", $problems) . "\n\n"
            . "Run the installer: `composer start` here, then open http://localhost:8100/setup.php\n"
            . "(DEV.md walks through it).\n");
        exit(1);
    }
}
