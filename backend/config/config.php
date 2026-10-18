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
//   cloudflare.local.php optional the secret the owner's Cloudflare zone adds
//                                 to every request (config/cloudflare.php);
//                                 uploaded by hand - setup.php neither asks
//                                 for it nor writes it
//
// A file is read cleanly or not at all. One that does not start with <?php -
// written by hand without that line, with a byte-order mark or a blank line
// before it - would send what it holds, the master key or a password among it,
// ahead of every answer, and with declare(strict_types=1) after such a start
// PHP could not even compile it (a fatal error, every request a 500): it is
// passed over before PHP compiles it. What one that does start so prints all
// the same - text after a closing tag - is caught and goes nowhere. Either
// way the file counts as not there (localConfigProblem() says why, by its
// fault and never a value in it); so does one PHP cannot read.
//
// Without the required ones the IAM does not run. The API answers every
// request with 503 `not_set_up` (all but /api/site-status, which says so
// itself - the anotoki family's gate, api/migration_runner.php), the pages say
// so, and both point at setup.php while the IAM was never installed
// (storage/installed.lock). An identity provider that guessed the rest - a database called
// anotoki_iam on 127.0.0.1, a sender at localhost, an issuer nobody chose -
// would be signing tokens for an address nobody configured.
// ---------------------------------------------------------------------------

/**
 * Thrown by whatever needs a per-installation value that is not there: the
 * command-line tools say it and stop; the API never gets that far, because
 * its gate answers 503 before any handler runs - but /api/site-status's,
 * which reads only whether the files are complete (iamSetUpProblems()) and
 * whether the install's lock is there.
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

/**
 * The cache behind localConfig(), by reference so forgetLocalConfig() can
 * empty it: for each file, what it returned and why it was passed over.
 */
function &localConfigCache(): array
{
    static $cache = [];

    return $cache;
}

/**
 * What config/{name}.local.php returns, or null when there is no such file -
 * or when it could not be read cleanly (localConfigProblem() says how).
 *
 * Read once per process (readLocalConfigFile()).
 */
function localConfig(string $name): mixed
{
    return localConfigEntry($name)['value'];
}

/**
 * Why config/{name}.local.php was passed over - "does not start with <?php
 * (...)", "prints 12 bytes of its own (...)", "could not be read (ParseError on
 * line 2)" - or null when it was read cleanly, or is not there. It names the
 * file's fault, never a value in it.
 */
function localConfigProblem(string $name): ?string
{
    return localConfigEntry($name)['problem'];
}

/**
 * One file's entry in the cache, read the first time it is asked for. A file
 * passed over is said in PHP's error log - at most once a day where the IAM
 * keeps its stamps (sayOncePerDay(), api/helpers.php) - by its name and its
 * fault alone.
 *
 * @return array{value: mixed, problem: ?string}
 */
function localConfigEntry(string $name): array
{
    $cache = &localConfigCache();

    if (!array_key_exists($name, $cache)) {
        $path = localConfigPath($name);
        $cache[$name] = is_file($path) ? readLocalConfigFile($path) : ['value' => null, 'problem' => null];
        if ($cache[$name]['problem'] !== null) {
            localConfigSaid("config/{$name}.local.php {$cache[$name]['problem']}: it is passed over, nothing in it read");
        }
    }

    return $cache[$name];
}

/**
 * Requires one *.local.php - read cleanly, or not at all. The installer reads
 * the files it writes through here too (setup.php).
 *
 * A file that does not start with <?php - written by hand with a byte-order
 * mark, a blank line or a space before it, or without that line at all - is
 * passed over before PHP ever compiles it: required, it would print what comes
 * before (all of it, the master key, a database password, the origin secret,
 * where the line is missing) ahead of every answer, and one with
 * declare(strict_types=1) after such a start is a compile-time fatal error
 * ("strict_types declaration must be the very first statement") that no try,
 * no handler and no output buffer catches - every request, /api/site-status
 * too, would be a 500 instead of "not set up", and a cloudflare.local.php
 * would never fail closed (Japanese Academy's review of 2026-10-18, the
 * family's rule since). Its first five bytes are read alone, never the rest:
 * its value null, its problem that it does not start with <?php. One PHP may
 * not open is no file either. This covers a bad start only: a file that does
 * start with <?php and still cannot be compiled (a declare after another
 * statement, a break outside a loop) stays a fatal error PHP raises before
 * anything here can act - every request a 500, nothing printed, nothing
 * served looser - and no slip of the hand makes one (the IAM's review of
 * 2026-10-18).
 *
 * A file that does start with it is required inside a closure of its own, so
 * it sees none of the caller's variables, with whatever it prints all the same
 * caught - text after a closing tag (that comes before its return), an echo, a
 * buffer of its own left open: it goes nowhere, and the file is no file, its
 * problem the number of bytes it printed. One PHP cannot read (a ParseError)
 * is no file either, said by the kind of error and its line alone - a parse
 * error's message can quote the file.
 *
 * @return array{value: mixed, problem: ?string}
 */
function readLocalConfigFile(string $path): array
{
    $head = @file_get_contents($path, false, null, 0, 5);
    if ($head === false) {
        return ['value' => null, 'problem' => 'could not be read (PHP may not open it)'];
    }
    if (strtolower($head) !== '<?php') {
        return ['value' => null, 'problem' => 'does not start with <?php (a byte-order mark, a blank line or a space before it, or no <?php line at all)'];
    }

    $level = ob_get_level();
    $value = null;
    $failure = null;
    $printed = 0;

    ob_start();
    try {
        $value = (static fn(string $file): mixed => require $file)($path);
    } catch (\Throwable $e) {
        $failure = 'could not be read (' . get_class($e) . ' on line ' . $e->getLine() . ')';
    } finally {
        // Every buffer down to the caller's, one the file opened and left open included.
        while (ob_get_level() > $level) {
            $buffer = ob_get_clean();
            if ($buffer === false) {
                break;
            }
            $printed += strlen($buffer);
        }
    }

    if ($failure !== null) {
        return ['value' => null, 'problem' => $failure];
    }
    if ($printed > 0) {
        return ['value' => null, 'problem' => "prints {$printed} bytes of its own (text outside its PHP: text after a closing tag, or something it echoes)"];
    }

    return ['value' => $value, 'problem' => null];
}

/**
 * A line in PHP's error log about a local file passed over: at most once a day
 * through sayOncePerDay() where api/helpers.php is loaded - the API and the
 * command line - else as it comes.
 */
function localConfigSaid(string $what): void
{
    if (function_exists('sayOncePerDay')) {
        sayOncePerDay('local configuration', $what);
    } else {
        error_log("[local configuration] {$what}");
    }
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

    // A required file there but passed over is said for what it is, not as missing.
    $passedOver = static fn(string $name): ?string => ($why = localConfigProblem($name)) === null ? null : "config/{$name}.local.php {$why}";

    $database = localConfig('database');
    if (($why = $passedOver('database')) !== null) {
        $problems[] = $why;
    } elseif (!is_array($database)) {
        $problems[] = 'config/database.local.php is missing';
    } elseif (trim((string) ($database['host'] ?? '')) === '' || trim((string) ($database['username'] ?? '')) === ''
        || !is_array($database['databases'] ?? null) || trim((string) ($database['databases']['iam'] ?? '')) === '') {
        $problems[] = 'config/database.local.php does not name a host, a username and the iam database';
    }

    $secret = localConfig('secret');
    if (($why = $passedOver('secret')) !== null) {
        $problems[] = $why;
    } elseif ($secret === null) {
        $problems[] = 'config/secret.local.php is missing';
    } elseif (!is_string($secret) || !preg_match('/^[0-9a-f]{64}$/i', trim($secret))) {
        $problems[] = 'config/secret.local.php is not a key of 64 hex characters';
    }

    $iam = localConfig('iam');
    if (($why = $passedOver('iam')) !== null) {
        $problems[] = $why;
    } elseif (!is_array($iam)) {
        $problems[] = 'config/iam.local.php is missing';
    } elseif (!validPublicUrl(rtrim(trim((string) ($iam['public_url'] ?? '')), '/'))) {
        $problems[] = 'config/iam.local.php has no valid public_url (scheme and host, like http://localhost:4300)';
    }

    $mail = localConfig('mail');
    if (($why = $passedOver('mail')) !== null) {
        $problems[] = $why;
    } elseif (!is_array($mail)) {
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
