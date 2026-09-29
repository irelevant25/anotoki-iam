<?php

declare(strict_types=1);

/**
 * setup.php - the IAM's installer, on a development machine and on a real host.
 *
 * It asks for everything an installation needs, writes every config/*.local.php
 * itself, builds the database from the migrations, and brings the genshin
 * site's accounts over or makes the first administrator. The IAM does not run
 * without what it writes: until then the API answers 503 `not_set_up` and the
 * pages point here. It is served at /setup.php - by router.php under
 * `php -S`, and from the web root on a server.
 *
 * TWO WAYS OF RUNNING
 *
 * On this computer - the connection comes from a loopback address, the Host
 * header names localhost, and no proxy forwarded it - it is the development
 * setup. It is prefilled with development values and asks for no setup token;
 * the form carries a nonce instead (storage/setup-nonce), so a page on some
 * other site cannot drive it through the browser. It stays where it is: run it
 * again to change a setting. It shows what is configured but never a password
 * or the master key, and a password left blank keeps the one saved.
 *
 * Anywhere else it is a real host's one-time install, where anyone can reach
 * the page - so it first proves you own the server. On first view it writes a
 * random token into config/setup.local.php and asks for it back, which only
 * somebody with FTP/SFTP access can do. It also asks for the sites' production
 * addresses, which replace the development ones the migrations seed. When it is
 * done, storage/installed.lock makes it refuse to run again even if the
 * self-delete is denied; then config/setup.local.php and this file are deleted.
 * The lock holds on this computer too.
 *
 * WHAT IT WRITES
 *
 *   config/database.local.php  the connection entered here
 *   config/secret.local.php    a fresh master key - left alone once it exists,
 *                              because the signing keys and two-factor secrets
 *                              are encrypted with it
 *   config/iam.local.php       the IAM's public address (the OIDC issuer)
 *   config/mail.local.php      the sender addresses and how mail is sent
 *   config/google.local.php    the Google sign-in client id, only if one is given
 *
 * Whatever else those files hold - trusted proxies added by hand, say - is kept.
 * A file whose content would not change is not written again.
 *
 * "TEST CONNECTION"
 *
 * The buttons beside the database and the old accounts database post a small
 * JSON request to this same page, under the same nonce or token as the form,
 * and show the answer in place without submitting anything else. The script
 * behind them is served by this file too (?asset=js), since the pages' security
 * policy allows no inline script.
 *
 * THE ACCOUNTS
 *
 * Either brought over from the genshin site's `users` database (same server,
 * same credentials) - every account with its id, password, Google link and
 * two-factor, see api/legacy_import.php - or a first administrator made from
 * the form. Import before the genshin site's own migration that removes its
 * account tables; that migration waits for the marker the import leaves. A
 * database that already has accounts keeps them and gets neither.
 */

require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/iam.php';
require_once __DIR__ . '/config/secret.php';
require_once __DIR__ . '/api/helpers.php';
require_once __DIR__ . '/api/crypto.php';
require_once __DIR__ . '/api/applications.php';
require_once __DIR__ . '/api/migration_runner.php';
require_once __DIR__ . '/api/legacy_import.php';

/**
 * The sites the migrations seed with development addresses. A real host's
 * install asks for each one's address; the examples are only ever placeholders.
 */
const SETUP_SITES = [
    'genshin' => ['name' => 'Genshin Impact', 'example' => 'https://genshin.anotoki.eu'],
    'japanese-academy' => ['name' => 'Japanese Academy', 'example' => 'https://japanese.anotoki.eu'],
    'piano-academy' => ['name' => 'Piano Academy', 'example' => 'https://piano.anotoki.eu'],
    'build-analyzer' => ['name' => 'Build Analyzer', 'example' => 'https://builder.anotoki.eu'],
];

/** What a development machine starts from: the port table's IAM, a local PostgreSQL, mail into storage/mail. */
const SETUP_DEVELOPMENT = [
    'public_url' => 'http://localhost:4300',
    'db_host' => '127.0.0.1',
    'db_port' => '5432',
    'db_user' => 'postgres',
    'db_name' => 'iam',
    'legacy' => '',
    'accounts' => 'fresh',
    'email' => 'admin@localhost',
    'mail_send' => false,
    'mail_noreply' => 'noreply@localhost',
    'mail_support' => 'support@localhost',
    'mail_from_name' => 'anotoki',
    'google_client_id' => '',
];

/** Headers whose presence means a proxy relayed the request, so it is not from this computer whatever REMOTE_ADDR says. */
const SETUP_FORWARDING_HEADERS = ['HTTP_FORWARDED', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_FORWARDED_HOST', 'HTTP_X_FORWARDED_PROTO',
    'HTTP_X_REAL_IP', 'HTTP_CLIENT_IP', 'HTTP_TRUE_CLIENT_IP', 'HTTP_CF_CONNECTING_IP', 'HTTP_X_CLUSTER_CLIENT_IP'];

// ── Where it runs ────────────────────────────────────────────────────────────

/** @return array{config: string, storage: string, self: string} */
function setupContext(): array
{
    return ['config' => iamConfigDir(), 'storage' => iamStorageDir(), 'self' => __FILE__];
}

/** A loopback address: 127.0.0.0/8, ::1, or 127.x written as a v4-mapped v6 address. */
function setupLoopbackAddress(string $address): bool
{
    $address = strtolower(trim($address));

    if ($address === '::1') {
        return true;
    }
    if (str_starts_with($address, '::ffff:')) {
        $address = substr($address, 7);
    }

    return filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false && str_starts_with($address, '127.');
}

/** The Host header's name, without the port: `localhost`, `127.0.0.1`, `::1`, `iam.example`. */
function setupHostname(array $server): string
{
    $host = strtolower(trim((string) ($server['HTTP_HOST'] ?? '')));

    if (preg_match('/^\[([0-9a-f:.]+)\](:\d+)?$/', $host, $found)) {
        return $found[1];
    }

    return (string) preg_replace('/:\d+$/', '', $host);
}

/**
 * Whether this request is the development setup: from this computer, to this
 * computer, straight.
 *
 * All three, because each alone can be arranged from outside. A proxy on the
 * server connects from loopback on every visitor's behalf (hence: no
 * forwarding headers); anybody can send `Host: localhost` (hence: a loopback
 * connection); and a page that rebinds its own name to 127.0.0.1 reaches this
 * computer's servers with its own name as the Host (hence: the Host header).
 */
function setupIsLocal(array $server): bool
{
    foreach (SETUP_FORWARDING_HEADERS as $header) {
        if (isset($server[$header]) && trim((string) $server[$header]) !== '') {
            return false;
        }
    }

    return setupLoopbackAddress((string) ($server['REMOTE_ADDR'] ?? '')) && loopbackHost(setupHostname($server));
}

// ── Small things ─────────────────────────────────────────────────────────────

function setupEscape(string $text): string
{
    return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** A trimmed text field from a form or a JSON body; '' for anything that is not text. */
function setupString(array $source, string $key): string
{
    $value = $source[$key] ?? '';

    return is_scalar($value) ? trim((string) $value) : '';
}

/** A password field: as typed, never trimmed - a space can be part of one. */
function setupSecretField(array $source, string $key): string
{
    $value = $source[$key] ?? '';

    return is_string($value) ? $value : '';
}

/**
 * Writes a file only its owner can read, so a database password or the master
 * key is not left readable to other tenants of a shared host. The permissions
 * are a no-op on Windows, where they are ACLs; they take effect on the host.
 */
function setupWritePrivate(string $path, string $contents): bool
{
    $directory = dirname($path);
    if (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) {
        return false;
    }

    $previous = umask(0077);
    $ok = @file_put_contents($path, $contents, LOCK_EX) !== false;
    umask($previous);

    if ($ok) {
        @chmod($path, 0600);
        // A PHP file just rewritten may still be served from the opcode cache
        // for a moment; the next request must see the new configuration.
        if (function_exists('opcache_invalidate')) {
            @opcache_invalidate($path, true);
        }
    }

    return $ok;
}

function setupPhpFile(mixed $value): string
{
    return "<?php\n\n// Written by setup.php. Per-installation and never committed.\n\nreturn " . var_export($value, true) . ";\n";
}

/** The driver's sentence without its SQLSTATE preamble, short enough for a line on the page. */
function setupDriverMessage(\Throwable $error): string
{
    $message = trim((string) preg_replace('/^SQLSTATE\[[0-9A-Z]+\](\s*\[\d+\])?\s*/', '', $error->getMessage()));
    $message = trim((string) preg_replace('/\s+/', ' ', $message));

    return mb_strlen($message) > 300 ? mb_substr($message, 0, 300) . '…' : $message;
}

// ── Answers ──────────────────────────────────────────────────────────────────

/**
 * The referrer policy is same-origin, not no-referrer: under no-referrer a
 * browser sends `Origin: null` with a form post, and the development setup
 * refuses a post whose Origin is not its own. Nothing secret is ever in this
 * page's address - the token travels in the body.
 *
 * @return array{status: int, headers: array<string, string>, body: string}
 */
function setupResponse(int $status, string $contentType, string $body): array
{
    return [
        'status' => $status,
        'headers' => [
            'Content-Type' => $contentType,
            'Cache-Control' => 'no-store',
            'X-Robots-Tag' => 'noindex, nofollow',
            'Referrer-Policy' => 'same-origin',
            'X-Frame-Options' => 'DENY',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; script-src 'self'; style-src 'unsafe-inline'; connect-src 'self'; img-src 'self' data:; form-action 'self'; frame-ancestors 'none'; base-uri 'none'",
        ],
        'body' => $body,
    ];
}

/** The answer to a "Test connection": good, warn (it will work, with a caveat) or bad. */
function setupJson(int $status, string $tone, string $message): array
{
    return setupResponse($status, 'application/json; charset=utf-8', (string) json_encode(
        ['ok' => $tone !== 'bad', 'tone' => $tone, 'message' => $message],
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
    ));
}

function setupPage(string $heading, string $body, bool $local, string $tone = 'plain', int $status = 200): array
{
    $accent = ['plain' => '#1f5fbf', 'good' => '#1d8a55', 'bad' => '#c62f3b'][$tone] ?? '#1f5fbf';
    $mode = $local ? 'Development machine' : 'Real host';

    return setupResponse($status, 'text/html; charset=utf-8', '<!doctype html><html lang="en"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex, nofollow">'
        . '<meta name="color-scheme" content="light dark"><title>Set up · anotoki IAM</title><link rel="icon" href="data:,">'
        . '<style>' . str_replace('{accent}', $accent, setupStyles()) . '</style>'
        . '<script src="setup.php?asset=js" defer></script></head>'
        . '<body><main class="card"><p class="mode">' . $mode . '</p><h1>' . $heading . '</h1>' . $body . '</main></body></html>');
}

function setupStyles(): string
{
    return ':root{color-scheme:light dark;--bg:#f3f6fb;--card:#fff;--ink:#0f1b33;--muted:#5b6880;--line:#dde4ef;--accent:{accent};--good:#1d8a55;--warn:#a86400;--bad:#c62f3b}'
        . '@media (prefers-color-scheme:dark){:root{--bg:#070d1c;--card:#0e1830;--ink:#e8eefb;--muted:#9aa8c4;--line:#22325a;--good:#3fbf80;--warn:#e0a040;--bad:#f06470}}'
        . '*{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--ink);font:15px/1.5 system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;'
        . 'display:flex;min-height:100vh;align-items:flex-start;justify-content:center;padding:32px 16px}'
        . '.card{background:var(--card);border:1px solid var(--line);border-radius:14px;max-width:640px;width:100%;padding:26px 28px}'
        . '.mode{margin:0 0 6px;font-size:.72rem;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:var(--accent)}'
        . 'h1{font-size:1.35rem;margin:0 0 6px;line-height:1.3}'
        . 'h2{font-size:.78rem;text-transform:uppercase;letter-spacing:.06em;color:var(--muted);margin:26px 0 2px;padding-top:14px;border-top:1px solid var(--line)}'
        . 'p{margin:.5rem 0;color:var(--muted)}label{display:block;margin:12px 0 5px;font-weight:600;font-size:.9rem}'
        . '.row{display:flex;gap:12px}.row>div{flex:1;min-width:0}'
        . 'input[type=text],input[type=email],input[type=password],input[type=url]{width:100%;padding:10px 12px;border:1px solid var(--line);border-radius:9px;background:transparent;color:var(--ink);font:inherit}'
        . 'input:focus-visible,button:focus-visible,a:focus-visible{outline:2px solid var(--accent);outline-offset:2px}'
        . 'button{font:600 .95rem system-ui,sans-serif;cursor:pointer;border-radius:9px}'
        . 'button.primary{margin-top:24px;width:100%;padding:12px;border:0;background:var(--accent);color:#fff;font-size:1rem}'
        . 'button.test{margin-top:10px;padding:7px 13px;border:1px solid var(--line);background:transparent;color:var(--ink)}button.test[disabled]{opacity:.6;cursor:progress}'
        . 'code{background:rgba(127,127,127,.15);padding:1px 6px;border-radius:5px;font-size:.85em;overflow-wrap:anywhere}'
        . 'a{color:var(--accent)}'
        . '.err{background:rgba(198,47,59,.12);border:1px solid rgba(198,47,59,.4);color:var(--bad);border-radius:9px;padding:10px 12px;margin:12px 0}'
        . '.note{background:rgba(31,95,191,.08);border:1px solid rgba(31,95,191,.3);border-radius:9px;padding:11px 13px;margin:12px 0;color:var(--ink)}'
        . '.note p{color:var(--ink)}.ok{color:var(--good)}'
        . 'ul{margin:.5rem 0;padding-left:1.2rem;color:var(--muted)}li{margin:.2rem 0}.muted{font-size:.85rem;margin:5px 0 0}'
        . '.check{display:flex;gap:9px;align-items:flex-start;font-weight:400;margin:12px 0 2px}.check input{margin:.25rem 0 0}'
        . '.status{list-style:none;padding:0;margin:10px 0}.status li{display:flex;gap:8px;align-items:baseline;color:var(--ink)}'
        . '.status li::before{content:"";flex:none;width:8px;height:8px;border-radius:50%;background:var(--muted)}'
        . '.status li.good::before{background:var(--good)}.status li.bad::before{background:var(--bad)}.status li.warn::before{background:var(--warn)}'
        . '.result{margin:8px 0 0;font-size:.88rem;min-height:1.2em}.result.good{color:var(--good)}.result.warn{color:var(--warn)}.result.bad{color:var(--bad)}'
        . '[hidden]{display:none!important}'
        . '@media (max-width:480px){.row{flex-direction:column;gap:0}.card{padding:22px 18px}}';
}

/**
 * The page's own script: the "Test connection" buttons and the accounts
 * choice. Served from this file rather than inline, because the web root's
 * Content-Security-Policy (and this page's own) allow scripts from this origin
 * only - and the page must work where the security policy is strictest.
 */
function setupScript(): string
{
    return <<<'JS'
(function () {
  'use strict';
  var form = document.querySelector('form[data-setup]');
  if (!form) return;

  function field(name) {
    var element = form.elements.namedItem(name);
    return element && 'value' in element ? element.value : '';
  }

  Array.prototype.forEach.call(form.querySelectorAll('button[data-test]'), function (button) {
    button.addEventListener('click', function () {
      var out = document.getElementById(button.getAttribute('aria-controls'));
      out.className = 'result';
      out.textContent = 'Testing…';
      button.disabled = true;
      fetch(location.pathname, {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
        body: JSON.stringify({
          action: button.getAttribute('data-test'),
          nonce: field('nonce'), token: field('token'),
          db_host: field('db_host'), db_port: field('db_port'), db_user: field('db_user'),
          db_pass: field('db_pass'), db_name: field('db_name'), legacy: field('legacy')
        })
      })
        .then(function (response) { return response.json(); })
        .then(function (answer) {
          out.className = 'result ' + (answer.tone || (answer.ok ? 'good' : 'bad'));
          out.textContent = answer.message || (answer.ok ? 'It works.' : 'It did not work.');
        })
        .catch(function () {
          out.className = 'result bad';
          out.textContent = 'No answer from the installer - is the PHP server still running?';
        })
        .then(function () { button.disabled = false; });
    });
  });

  // Only the fields of the chosen way to start the accounts are shown.
  var choices = form.querySelectorAll('input[name="accounts"]');
  function showChoice() {
    var chosen = form.querySelector('input[name="accounts"]:checked');
    Array.prototype.forEach.call(form.querySelectorAll('[data-accounts]'), function (block) {
      block.hidden = !chosen || block.getAttribute('data-accounts') !== chosen.value;
    });
  }
  Array.prototype.forEach.call(choices, function (choice) { choice.addEventListener('change', showChoice); });
  showChoice();
})();
JS;
}

// ── Proof: a token on a real host, a nonce on this computer ─────────────────

function setupTokenFile(array $context): string
{
    return $context['config'] . '/setup.local.php';
}

function setupToken(array $context): ?string
{
    $file = setupTokenFile($context);

    if (!is_file($file)) {
        return null;
    }

    $config = (static fn(string $path): mixed => require $path)($file);
    $token = is_array($config) ? ($config['token'] ?? null) : null;

    return is_string($token) && $token !== '' ? $token : null;
}

/** The nonce the development form carries, made on first use. '' when storage/ cannot be written. */
function setupNonce(array $context): string
{
    $file = $context['storage'] . '/setup-nonce';
    $nonce = is_file($file) ? trim((string) @file_get_contents($file)) : '';

    if (!preg_match('/^[0-9a-f]{64}$/', $nonce)) {
        $nonce = bin2hex(random_bytes(32));
        if (!setupWritePrivate($file, $nonce)) {
            return '';
        }
    }

    return $nonce;
}

/**
 * Why a POST is refused, or null.
 *
 * On a real host the token is the whole of it. On this computer the nonce is,
 * with the Origin and Sec-Fetch-Site headers as a second line: another page
 * cannot read the nonce, and a browser names the page a request came from.
 * Another port on localhost is another origin, although the same "site".
 */
function setupRefusal(bool $local, array $server, array $input, array $context): ?string
{
    if (!$local) {
        $token = setupToken($context);

        return $token !== null && hash_equals($token, setupString($input, 'token'))
            ? null
            : 'That is not the setup token from config/setup.local.php.';
    }

    $origin = strtolower(trim((string) ($server['HTTP_ORIGIN'] ?? '')));
    $own = 'http' . (setupHttps($server) ? 's' : '') . '://' . strtolower(trim((string) ($server['HTTP_HOST'] ?? '')));
    if ($origin !== '' && $origin !== $own) {
        return 'That request came from another page (' . $origin . '), not from this installer.';
    }

    $site = strtolower(trim((string) ($server['HTTP_SEC_FETCH_SITE'] ?? '')));
    if ($site !== '' && !in_array($site, ['same-origin', 'none'], true)) {
        return 'That request came from another page, not from this installer.';
    }

    $nonce = setupNonce($context);

    return $nonce !== '' && hash_equals($nonce, setupString($input, 'nonce'))
        ? null
        : 'This form is out of date. Reload the page and try again.';
}

function setupHttps(array $server): bool
{
    return !empty($server['HTTPS']) && strtolower((string) $server['HTTPS']) !== 'off';
}

// ── What is configured, and what the form starts from ───────────────────────

/** A config/*.local.php of this installation, read fresh. */
function setupSavedFile(array $context, string $name): mixed
{
    $path = $context['config'] . '/' . $name . '.local.php';

    return is_file($path) ? (static fn(string $file): mixed => require $file)($path) : null;
}

/** @return array{database: ?array, iam: ?array, mail: ?array, google: ?array, secret: bool} */
function setupSaved(array $context): array
{
    $pick = static fn(mixed $value): ?array => is_array($value) ? $value : null;
    $secret = setupSavedFile($context, 'secret');

    return [
        'database' => $pick(setupSavedFile($context, 'database')),
        'iam' => $pick(setupSavedFile($context, 'iam')),
        'mail' => $pick(setupSavedFile($context, 'mail')),
        'google' => $pick(setupSavedFile($context, 'google')),
        'secret' => is_string($secret) && preg_match('/^[0-9a-f]{64}$/i', trim($secret)) === 1,
    ];
}

/**
 * What the form shows before anything is typed: what is saved - never a
 * password - and otherwise the development values on this computer, or on a
 * real host what its own address suggests.
 */
function setupValues(bool $local, array $server, array $saved): array
{
    if ($local) {
        $values = SETUP_DEVELOPMENT;
    } else {
        $hostname = setupHostname($server);
        $host = preg_match('/^[a-z0-9.:\[\]-]+$/', strtolower((string) ($server['HTTP_HOST'] ?? ''))) ? strtolower((string) $server['HTTP_HOST']) : '';
        $domain = (string) preg_replace('/^iam\./', '', $hostname);
        $values = [
            'public_url' => $host === '' ? '' : 'https://' . $host,
            'db_host' => '127.0.0.1',
            'db_port' => '5432',
            'db_user' => '',
            'db_name' => 'iam',
            'legacy' => 'users',
            'accounts' => 'import',
            'email' => '',
            'mail_send' => true,
            'mail_noreply' => $domain === '' ? '' : 'noreply@' . $domain,
            'mail_support' => $domain === '' ? '' : 'support@' . $domain,
            'mail_from_name' => 'anotoki',
            'google_client_id' => '',
        ];
    }
    $values['sites'] = array_fill_keys(array_keys(SETUP_SITES), '');

    if ($saved['database'] !== null) {
        $database = $saved['database'];
        $values['db_host'] = (string) ($database['host'] ?? $values['db_host']);
        $values['db_port'] = (string) ($database['port'] ?? $values['db_port']);
        $values['db_user'] = (string) ($database['username'] ?? $values['db_user']);
        $values['db_name'] = (string) ($database['databases']['iam'] ?? $values['db_name']);
        $values['legacy'] = (string) ($database['databases']['legacy'] ?? '');
    }
    if ($saved['iam'] !== null && isset($saved['iam']['public_url'])) {
        $values['public_url'] = (string) $saved['iam']['public_url'];
    }
    if ($saved['mail'] !== null) {
        $mail = $saved['mail'];
        $values['mail_send'] = ($mail['driver'] ?? '') === 'mail';
        $values['mail_noreply'] = (string) ($mail['senders']['noreply']['from'] ?? $mail['from'] ?? $values['mail_noreply']);
        $values['mail_support'] = (string) ($mail['senders']['support']['from'] ?? $values['mail_support']);
        $values['mail_from_name'] = (string) ($mail['from_name'] ?? $values['mail_from_name']);
    }
    if ($saved['google'] !== null) {
        $values['google_client_id'] = (string) ($saved['google']['client_id'] ?? '');
    }

    return $values;
}

/** The form as posted, over what it started from. Passwords are not in here - they are read where they are used. */
function setupPosted(array $values, array $post): array
{
    foreach (['public_url', 'db_host', 'db_port', 'db_user', 'db_name', 'legacy', 'accounts', 'email', 'mail_noreply', 'mail_support', 'mail_from_name', 'google_client_id'] as $key) {
        $values[$key] = setupString($post, $key);
    }
    $values['public_url'] = rtrim($values['public_url'], '/');
    $values['mail_send'] = isset($post['mail_send']);

    $sites = is_array($post['site'] ?? null) ? $post['site'] : [];
    foreach (array_keys(SETUP_SITES) as $clientId) {
        $values['sites'][$clientId] = rtrim(setupString($sites, $clientId), '/');
    }

    return $values;
}

/**
 * The connection to use: as typed, with a blank password meaning the saved one
 * - but only for the server and user it was saved for. Pointed anywhere else,
 * a blank password is blank: the saved one is never sent to a new host.
 */
function setupConnection(array $values, string $typedPassword, ?array $savedDatabase): array
{
    $password = $typedPassword;

    if ($password === '' && $savedDatabase !== null
        && (string) ($savedDatabase['host'] ?? '') === $values['db_host']
        && (string) ($savedDatabase['port'] ?? '') === $values['db_port']
        && (string) ($savedDatabase['username'] ?? '') === $values['db_user']) {
        $password = (string) ($savedDatabase['password'] ?? '');
    }

    return ['driver' => 'pgsql', 'host' => $values['db_host'], 'port' => $values['db_port'], 'username' => $values['db_user'], 'password' => $password];
}

/** A database name that is safe inside a DSN: no separators, quotes or spaces. */
function setupDatabaseName(string $name): bool
{
    return preg_match('/^[A-Za-z0-9_][A-Za-z0-9_.$-]{0,62}$/', $name) === 1;
}

/** A host that is safe inside a DSN: a name, an address, or a socket directory. */
function setupDatabaseHost(string $host): bool
{
    return preg_match('#^[A-Za-z0-9._:/\[\]-]{1,255}$#', $host) === 1;
}

/** @return string[] what is wrong with the connection fields, for the form and the test alike */
function setupConnectionErrors(array $values): array
{
    $errors = [];

    if (!setupDatabaseHost($values['db_host']) || preg_match('/^\d{1,5}$/', $values['db_port']) !== 1 || $values['db_user'] === '') {
        $errors[] = 'Fill in the database host, a numeric port and the username.';
    }
    if (!setupDatabaseName($values['db_name'])) {
        $errors[] = 'The IAM database needs a name of letters, digits and underscores.';
    }
    if ($values['legacy'] !== '' && !setupDatabaseName($values['legacy'])) {
        $errors[] = 'The old accounts database needs a name of letters, digits and underscores.';
    }

    return $errors;
}

/** @return string[] */
function setupErrors(bool $local, array $values, array $post): array
{
    $errors = [];

    if (!validPublicUrl($values['public_url'])) {
        $errors[] = 'The public address is scheme and host only, like http://localhost:4300 - no path. Plain http only for this computer.';
    } elseif (!$local && !str_starts_with($values['public_url'], 'https://')) {
        $errors[] = 'A real host is served over https: the session cookie is Secure, and tokens would travel in the open otherwise.';
    }

    $errors = array_merge($errors, setupConnectionErrors($values));

    // 'later' is offered on this computer only; on a real host it arrives only
    // from a form that found accounts already there (setupInstall() checks).
    if (!in_array($values['accounts'], ['import', 'fresh', 'later'], true)) {
        $errors[] = 'Choose how the accounts start.';
    } elseif ($values['accounts'] === 'import' && $values['legacy'] === '') {
        $errors[] = 'Name the old accounts database, or choose to start with a new administrator.';
    } elseif ($values['accounts'] === 'fresh') {
        $password = setupSecretField($post, 'password');
        if (!validEmailAddress($values['email'])) {
            $errors[] = 'Enter a valid email for the administrator.';
        }
        if (passwordRefusal($password) !== null) {
            $errors[] = 'The administrator password needs ' . PASSWORD_MIN . ' to ' . PASSWORD_MAX . ' characters.';
        } elseif ($password !== setupSecretField($post, 'confirm')) {
            $errors[] = 'The two administrator passwords do not match.';
        }
    }

    if (!validEmailAddress($values['mail_noreply']) || !validEmailAddress($values['mail_support'])) {
        $errors[] = 'Enter the no-reply and support addresses mail is sent from.';
    }
    if ($values['mail_from_name'] === '' || mb_strlen($values['mail_from_name']) > 100 || preg_match('/[\x00-\x1F\x7F]/', $values['mail_from_name'])) {
        $errors[] = 'The sender name is one line of at most 100 characters.';
    }
    if ($values['google_client_id'] !== '' && preg_match('/^[A-Za-z0-9._-]+\.apps\.googleusercontent\.com$/', $values['google_client_id']) !== 1) {
        $errors[] = 'That does not look like a Google client ID (it ends with .apps.googleusercontent.com). Leave it blank to switch Google sign-in off.';
    }

    if (!$local) {
        foreach (SETUP_SITES as $clientId => $site) {
            $address = $values['sites'][$clientId];
            if ($address !== '' && (!validOrigin($address) || onThisComputer($address))) {
                $errors[] = "{$site['name']}: the address is the site's https origin, like {$site['example']} - no path.";
            }
        }
    }

    return $errors;
}

// ── Test connection ──────────────────────────────────────────────────────────

/** @return array{0: string, 1: string} tone and sentence */
function setupTestDatabase(array $connection, string $name): array
{
    try {
        $pdo = connectDatabase($connection, $name, false);
    } catch (\Throwable $error) {
        $message = setupDriverMessage($error);
        if (!preg_match('/database ".*" does not exist/i', $message)) {
            return ['bad', 'Could not connect: ' . $message];
        }
        try {
            connectDatabase($connection, 'postgres', false);
        } catch (\Throwable) {
            return ['bad', 'Could not connect: ' . $message];
        }

        return ['warn', "The server answers and the login works, but there is no database '{$name}' yet. Saving creates it if this user may create databases; otherwise create it first (in the hosting panel, on a server)."];
    }

    $version = (string) $pdo->query('SHOW server_version')->fetchColumn();
    $files = migrationFiles('iam');
    $applied = (bool) $pdo->query("SELECT to_regclass('public.migrations') IS NOT NULL")->fetchColumn()
        ? array_values(array_intersect($files, appliedMigrations($pdo)))
        : [];
    $accounts = (bool) $pdo->query("SELECT to_regclass('public.users') IS NOT NULL")->fetchColumn()
        ? (int) $pdo->query('SELECT count(*) FROM users')->fetchColumn()
        : 0;

    if ($applied === []) {
        return ['good', "Connected to PostgreSQL {$version}. The database '{$name}' is empty - saving builds it from the " . count($files) . ' migrations.'];
    }

    $pending = count($files) - count($applied);

    return ['good', "Connected to PostgreSQL {$version}. '{$name}' has " . count($applied) . ' of ' . count($files) . ' migrations'
        . ($pending > 0 ? " ({$pending} to apply on saving)" : '') . " and {$accounts} account" . ($accounts === 1 ? '' : 's') . '.'];
}

/** @return array{0: string, 1: string} */
function setupTestLegacy(array $connection, string $name): array
{
    if ($name === '') {
        return ['bad', 'Name the old accounts database first.'];
    }

    try {
        $legacy = legacyConnect($connection, $name);
    } catch (\Throwable $error) {
        return ['bad', 'Could not connect: ' . setupDriverMessage($error)];
    }

    if (!legacyHasColumns($legacy, 'users', LEGACY_USER_COLUMNS)) {
        return ['bad', "Connected, but '{$name}' has no users table in the shape the genshin site leaves it (its migrations up to 031)."];
    }

    $accounts = (int) $legacy->query('SELECT count(*) FROM users')->fetchColumn();
    $admins = (int) $legacy->query("SELECT count(*) FROM users WHERE upper(role) = 'ADMIN'")->fetchColumn();
    $handedOver = (bool) $legacy->query("SELECT to_regclass('public.iam_handover') IS NOT NULL")->fetchColumn();

    $line = "Connected: {$accounts} account" . ($accounts === 1 ? '' : 's') . ", {$admins} of them admin" . ($admins === 1 ? '' : 's') . ', ready to bring over.';

    if ($admins === 0) {
        return ['warn', "Connected: {$accounts} account" . ($accounts === 1 ? '' : 's') . ', but none is an ADMIN, so nobody could administer the IAM after an import.'];
    }
    if ($handedOver) {
        return ['warn', $line . ' It has been imported once already; importing again needs an IAM database with no accounts.'];
    }

    return ['good', $line];
}

function setupTest(bool $local, array $server, array $input, array $context): array
{
    $saved = setupSaved($context);
    $values = setupPosted(setupValues($local, $server, $saved), $input);
    $errors = setupConnectionErrors($values);

    if ($errors !== []) {
        return setupJson(422, 'bad', implode(' ', $errors));
    }

    $connection = setupConnection($values, setupSecretField($input, 'db_pass'), $saved['database']);

    [$tone, $message] = match (setupString($input, 'action')) {
        'test_database' => setupTestDatabase($connection, $values['db_name']),
        'test_legacy' => setupTestLegacy($connection, $values['legacy']),
        default => ['bad', 'Unknown test.'],
    };

    return setupJson(200, $tone, $message);
}

// ── The install ──────────────────────────────────────────────────────────────

/**
 * Writes config/{name}.local.php when its content would change; `null`
 * removes it. Adds what it did to $written.
 */
function setupWriteConfig(array $context, string $name, mixed $value, array &$written): bool
{
    $path = $context['config'] . '/' . $name . '.local.php';

    if ($value === null) {
        if (is_file($path)) {
            if (!@unlink($path)) {
                return false;
            }
            $written[] = "removed config/{$name}.local.php";
        }
        return true;
    }

    if (is_file($path) && setupSavedFile($context, $name) === $value) {
        return true;
    }

    if (!setupWritePrivate($path, setupPhpFile($value))) {
        return false;
    }
    $written[] = "config/{$name}.local.php";

    return true;
}

/**
 * The sites' production addresses, over the development ones the migrations
 * seeded: every loopback redirect URI and origin goes - on a live IAM they
 * would hand codes to whatever listens on the visitor's own machine - and a
 * site given an address gets its callback, its landing after sign-out and its
 * origin there. A site left blank has no address until the admin console
 * gives it one.
 *
 * @return string[] a line per site
 */
function setupProductionAddresses(PDO $pdo, array $sites): array
{
    $log = [];
    $pdo->beginTransaction();

    try {
        foreach (SETUP_SITES as $clientId => $site) {
            $statement = $pdo->prepare('SELECT id, home_url FROM applications WHERE client_id = ?');
            $statement->execute([$clientId]);
            $application = $statement->fetch();
            if (!$application) {
                continue;
            }
            $id = (int) $application['id'];

            $uris = $pdo->prepare('SELECT id, uri FROM application_redirect_uris WHERE application_id = ?');
            $uris->execute([$id]);
            foreach ($uris->fetchAll() as $row) {
                if (onThisComputer($row['uri'])) {
                    $pdo->prepare('DELETE FROM application_redirect_uris WHERE id = ?')->execute([$row['id']]);
                }
            }
            $origins = $pdo->prepare('SELECT id, origin FROM application_origins WHERE application_id = ?');
            $origins->execute([$id]);
            foreach ($origins->fetchAll() as $row) {
                if (onThisComputer($row['origin'])) {
                    $pdo->prepare('DELETE FROM application_origins WHERE id = ?')->execute([$row['id']]);
                }
            }

            $address = (string) ($sites[$clientId] ?? '');
            if ($address === '') {
                if ($application['home_url'] === null || onThisComputer($application['home_url'])) {
                    $pdo->prepare('UPDATE applications SET home_url = NULL WHERE id = ?')->execute([$id]);
                }
                $log[] = "{$site['name']}: no address yet - give it one under Applications in the admin console.";
                continue;
            }

            $insert = $pdo->prepare('INSERT INTO application_redirect_uris (application_id, kind, uri) VALUES (?, ?, ?) ON CONFLICT DO NOTHING');
            $insert->execute([$id, 'login', $address . '/auth/callback']);
            $insert->execute([$id, 'logout', $address . '/']);
            $pdo->prepare('INSERT INTO application_origins (application_id, origin) VALUES (?, ?) ON CONFLICT DO NOTHING')->execute([$id, $address]);
            $pdo->prepare('UPDATE applications SET home_url = ? WHERE id = ?')->execute([$address, $id]);
            $log[] = "{$site['name']}: signs in from {$address}.";
        }

        $pdo->commit();
    } catch (\Throwable $error) {
        $pdo->rollBack();
        throw $error;
    }

    return $log;
}

/**
 * The database, then the accounts, then the applications' addresses, against
 * the connection from the form. Throws a plain sentence at the first failure,
 * before any configuration but the master key is written - so a failed
 * attempt is simply tried again.
 *
 * @return string[] a line per step
 */
function setupInstall(bool $local, array $connection, array $values, array $post): array
{
    $log = [];
    $name = $values['db_name'];

    try {
        if (ensureDatabase($connection, $name)) {
            $log[] = "Database: created '{$name}'.";
        }
    } catch (\Throwable) {
        // Most hosting will not let the application's user create databases;
        // the one made in the control panel is what the connection below finds.
    }

    try {
        $pdo = connectDatabase($connection, $name, false);
    } catch (\Throwable $error) {
        throw new RuntimeException("Cannot connect to the database '{$name}': " . setupDriverMessage($error) . ' Create it (in your hosting panel, on a server) and check the connection details.');
    }

    $result = applyPendingMigrations($pdo, 'iam');
    if ($result['failed'] !== null) {
        throw new RuntimeException("Migration {$result['failed']} failed: {$result['error']}");
    }
    $log[] = $result['applied'] === [] ? 'Database: up to date.' : 'Database: applied ' . count($result['applied']) . ' migration(s).';

    $accounts = (int) $pdo->query('SELECT count(*) FROM users')->fetchColumn();
    if ($accounts > 0) {
        $log[] = "Accounts: the database has {$accounts} already; they are left as they are.";
    } elseif ($values['accounts'] === 'import') {
        try {
            $legacy = legacyConnect($connection, $values['legacy']);
        } catch (\Throwable $error) {
            throw new RuntimeException("Cannot connect to the old accounts database '{$values['legacy']}' with the same credentials: " . setupDriverMessage($error));
        }
        $summary = importLegacyAccounts($pdo, $legacy, 'genshin', false, $values['public_url']);
        $log[] = "Accounts: brought over {$summary['accounts']}, with {$summary['identities']} Google link(s), {$summary['recovery_codes']} recovery code(s) and {$summary['sessions']} past session(s).";
        $log[] = "The old database now carries the marker the genshin site's clean-up migration waits for.";
    } elseif ($values['accounts'] === 'fresh') {
        $created = createFirstAdmin($pdo, $values['email'], setupSecretField($post, 'password'));
        $log[] = $created === null ? 'Accounts: there were some already, so no administrator was made.' : "Accounts: administrator {$created['email']} created.";
    } elseif (!$local) {
        // A real host installs once; one with no account could never be administered.
        throw new RuntimeException('Choose how the accounts start: bring them over, or make the first administrator.');
    } else {
        $log[] = 'Accounts: none yet. Run this again to import them or make an administrator, or use php create-admin.php.';
    }

    // The IAM's own entry points at the address it is served from.
    $pdo->prepare("UPDATE applications SET home_url = ? WHERE client_id = 'iam' AND home_url IS DISTINCT FROM ?")->execute([$values['public_url'], $values['public_url']]);

    if (!$local) {
        $log = array_merge($log, setupProductionAddresses($pdo, $values['sites']));
    }

    return $log;
}

function setupSave(bool $local, array $server, array $post, array $context): array
{
    $saved = setupSaved($context);
    $values = setupPosted(setupValues($local, $server, $saved), $post);
    $errors = setupErrors($local, $values, $post);

    if ($errors !== []) {
        return setupForm($local, $context, $saved, $values, $errors, 422);
    }

    $connection = setupConnection($values, setupSecretField($post, 'db_pass'), $saved['database']);

    // The master key before anything is encrypted with it - the import
    // encrypts two-factor secrets on the way in. An existing one is kept:
    // replacing it would make every signing key and two-factor secret already
    // stored with it unreadable.
    $written = [];
    $newSecret = !$saved['secret'];
    if ($newSecret && !setupWriteConfig($context, 'secret', bin2hex(random_bytes(32)), $written)) {
        return setupForm($local, $context, $saved, $values, ['The installer could not write config/secret.local.php: the config/ folder is not writable by the web server.'], 500);
    }
    forgetLocalConfig();

    try {
        $log = setupInstall($local, $connection, $values, $post);
    } catch (\Throwable $error) {
        return setupForm($local, $context, setupSaved($context), $values, [$error->getMessage()], 422);
    }

    $database = array_replace($saved['database'] ?? ['persistent' => true], [
        'driver' => 'pgsql',
        'host' => $values['db_host'],
        'port' => $values['db_port'],
        'username' => $values['db_user'],
        'password' => $connection['password'],
    ]);
    $database['databases'] = array_replace(is_array($database['databases'] ?? null) ? $database['databases'] : [], ['iam' => $values['db_name']]);
    if ($values['legacy'] === '') {
        unset($database['databases']['legacy']);
    } else {
        $database['databases']['legacy'] = $values['legacy'];
    }

    $mail = array_replace($saved['mail'] ?? [], [
        'driver' => $values['mail_send'] ? 'mail' : 'file',
        'from' => $values['mail_noreply'],
        'from_name' => $values['mail_from_name'],
    ]);
    $mail['senders'] = array_replace(is_array($mail['senders'] ?? null) ? $mail['senders'] : [], [
        'noreply' => ['from' => $values['mail_noreply'], 'from_name' => $values['mail_from_name']],
        'support' => ['from' => $values['mail_support'], 'from_name' => $values['mail_from_name']],
    ]);

    $files = [
        'database' => $database,
        'iam' => array_replace($saved['iam'] ?? [], ['public_url' => $values['public_url']]),
        'mail' => $mail,
        'google' => $values['google_client_id'] === '' ? null : array_replace($saved['google'] ?? [], ['client_id' => $values['google_client_id']]),
    ];
    $failed = [];
    foreach ($files as $name => $value) {
        if (!setupWriteConfig($context, $name, $value, $written)) {
            $failed[] = "config/{$name}.local.php";
        }
    }
    forgetLocalConfig();

    if ($failed !== []) {
        return setupForm($local, $context, setupSaved($context), $values, ['The database is ready, but ' . implode(', ', $failed) . ' could not be written: the config/ folder is not writable by the web server. Fix that and save again.'], 500);
    }

    return $local ? setupSavedHere($values, $log, $written, $newSecret) : setupInstalledThere($context, $values, $log, $written);
}

/** On this computer: the result, and the way back to the form. */
function setupSavedHere(array $values, array $log, array $written, bool $newSecret): array
{
    $problems = iamSetUpProblems();

    return setupPage('Saved', setupList($log)
        . '<p>' . ($written === [] ? 'The configuration was already as entered; no file changed.' : 'Wrote ' . setupCodes($written) . '.') . '</p>'
        . ($newSecret ? '<div class="note"><p><code>config/secret.local.php</code> is new: the master key the signing keys and two-factor secrets are encrypted with. Keep a copy if this database matters to you.</p></div>' : '')
        . ($problems === []
            ? '<p class="ok">The IAM is set up. With the PHP server running here, start the pages with <code>npm start</code> in <code>frontend/</code> and open <a href="' . setupEscape($values['public_url']) . '/login">' . setupEscape($values['public_url']) . '/login</a>.</p>'
            : '<div class="err">Still missing: ' . setupEscape(implode('; ', $problems)) . '</div>')
        . '<p>This page stays: <a href="setup.php">open it again</a> whenever a setting changes.</p>', true, 'good');
}

/** On a real host: the lock, then the installer's own removal. */
function setupInstalledThere(array $context, array $values, array $log, array $written): array
{
    $lock = $context['storage'] . '/installed.lock';

    // Checked, not assumed: the lock is what keeps a leftover installer from
    // being run a second time, so its absence is said out loud.
    $locked = setupWritePrivate($lock, (string) json_encode(['installed_at' => gmdate('c'), 'public_url' => $values['public_url']], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    $removed = setupRemoveInstaller($context);

    return setupPage('Installed', setupList($log)
        . '<p>Wrote ' . setupCodes($written) . '.</p>'
        . '<div class="note"><p>Download <code>config/secret.local.php</code> over FTP and keep it somewhere safe. It is the key the signing keys and two-factor secrets are encrypted with; without it they cannot be read.</p></div>'
        . ($locked ? '' : '<p class="err"><code>storage/installed.lock</code> could not be written, so nothing but deleting the installer stops it running again. Check that <code>storage/</code> is writable.</p>')
        . ($removed ? '<p class="ok">Removed <code>setup.php</code> and <code>config/setup.local.php</code>.</p>'
            : '<p class="err">Delete <code>setup.php</code> and <code>config/setup.local.php</code> by hand now' . ($locked ? ' (the lock stops it running again meanwhile).' : ' - nothing else stops it running again.') . '</p>')
        . '<p>Sign in at <a href="' . setupEscape($values['public_url']) . '/login">' . setupEscape($values['public_url']) . '/login</a>; the admin console is at <code>/admin</code>.</p>', false, 'good');
}

function setupRemoveInstaller(array $context): bool
{
    @unlink(setupTokenFile($context));

    return @unlink($context['self']) || !is_file($context['self']);
}

function setupList(array $lines): string
{
    return '<ul>' . implode('', array_map(static fn(string $line): string => '<li>' . setupEscape($line) . '</li>', $lines)) . '</ul>';
}

function setupCodes(array $items): string
{
    return implode(', ', array_map(static fn(string $item): string => '<code>' . setupEscape($item) . '</code>', $items));
}

// ── The form ─────────────────────────────────────────────────────────────────

/** How the database the saved configuration names is doing, for the form's status list. */
function setupDatabaseState(array $saved): ?array
{
    $database = $saved['database'];
    $name = (string) ($database['databases']['iam'] ?? '');

    if ($database === null || $name === '' || !setupDatabaseName($name) || !setupDatabaseHost((string) ($database['host'] ?? ''))) {
        return null;
    }

    try {
        $pdo = connectDatabase($database, $name, false);
        $accounts = (bool) $pdo->query("SELECT to_regclass('public.users') IS NOT NULL")->fetchColumn()
            ? (int) $pdo->query('SELECT count(*) FROM users')->fetchColumn()
            : 0;
        $files = migrationFiles('iam');
        $applied = (bool) $pdo->query("SELECT to_regclass('public.migrations') IS NOT NULL")->fetchColumn()
            ? count(array_intersect($files, appliedMigrations($pdo)))
            : 0;

        return ['connected' => true, 'name' => $name, 'accounts' => $accounts, 'applied' => $applied, 'total' => count($files)];
    } catch (\Throwable $error) {
        return ['connected' => false, 'name' => $name, 'error' => setupDriverMessage($error)];
    }
}

function setupForm(bool $local, array $context, array $saved, array $values, array $errors = [], int $status = 200): array
{
    $field = static function (string $name, string $label, string $type = 'text', string $extra = '') use ($values): string {
        $value = in_array($type, ['text', 'email', 'url'], true) ? ' value="' . setupEscape((string) ($values[$name] ?? '')) . '"' : '';

        return '<label for="' . $name . '">' . $label . '</label><input id="' . $name . '" name="' . $name . '" type="' . $type . '"' . $value . ($extra === '' ? '' : ' ' . $extra) . '>';
    };
    $radio = static fn(string $value, string $label): string =>
        '<label class="check"><input type="radio" name="accounts" value="' . $value . '"' . ($values['accounts'] === $value ? ' checked' : '') . '> <span>' . $label . '</span></label>';
    $test = static fn(string $action, string $label): string =>
        '<button type="button" class="test" data-test="' . $action . '" aria-controls="' . $action . '_result">' . $label . '</button>'
        . '<p class="result" id="' . $action . '_result" role="status" aria-live="polite"></p>';

    $state = setupDatabaseState($saved);
    $hasAccounts = $state !== null && !empty($state['connected']) && $state['accounts'] > 0;
    $savedPassword = $saved['database'] !== null && (string) ($saved['database']['password'] ?? '') !== '';

    $body = '';

    if ($local) {
        $problems = iamSetUpProblems();
        $items = [];
        foreach (['database', 'secret', 'iam', 'mail', 'google'] as $name) {
            $present = is_file($context['config'] . '/' . $name . '.local.php');
            $items[] = '<li class="' . ($present ? 'good' : ($name === 'google' ? '' : 'bad')) . '"><span><code>config/' . $name . '.local.php</code> '
                . ($present ? ($name === 'secret' ? 'present - never shown here' : 'present') : ($name === 'google' ? 'not there - Google sign-in is off' : 'missing')) . '</span></li>';
        }
        if ($state !== null) {
            $items[] = !empty($state['connected'])
                ? '<li class="good"><span>Database <code>' . setupEscape($state['name']) . '</code>: ' . $state['applied'] . ' of ' . $state['total'] . ' migrations, ' . $state['accounts'] . ' account' . ($state['accounts'] === 1 ? '' : 's') . '</span></li>'
                : '<li class="bad"><span>Database <code>' . setupEscape($state['name']) . '</code>: ' . setupEscape($state['error']) . '</span></li>';
        }
        $body .= '<p>This computer\'s IAM: prefilled with development values, and it stays - run it again whenever a setting changes. Passwords and the master key are never shown; leave a password blank to keep it.</p>'
            . '<ul class="status">' . implode('', $items) . '</ul>'
            . ($problems === [] ? '<p class="ok">The IAM is set up. Saving applies any pending migrations and rewrites what changed.</p>' : '');
    } else {
        $body .= '<p>This writes the configuration, builds the database, and brings the accounts over or makes the first administrator. Do it once, into a database you have made in your hosting panel.</p>'
            . '<div class="note"><p>A token was written to <code>config/setup.local.php</code>. Open that file over FTP/SFTP, copy the token and paste it below - that is how the installer knows it is you.</p></div>';
    }

    $body .= implode('', array_map(static fn(string $error): string => '<div class="err" role="alert">' . setupEscape($error) . '</div>', $errors));

    $body .= '<form method="post" action="setup.php" autocomplete="off" data-setup>';
    $body .= $local
        ? '<input type="hidden" name="nonce" value="' . setupEscape(setupNonce($context)) . '">'
        : $field('token', 'Setup token', 'password', 'required autocomplete="off"');

    $body .= '<h2>Address</h2>'
        . $field('public_url', 'Public address of this IAM', 'text', 'required inputmode="url" spellcheck="false"')
        . '<p class="muted">' . ($local
            ? 'Where its pages open: the Angular dev server, <code>http://localhost:4300</code>. It is the issuer every site\'s development configuration names.'
            : 'Where people will open it, like https://iam.anotoki.eu. It becomes the issuer every site is configured with, so choose it once.') . '</p>';

    $body .= '<h2>Database</h2>'
        . '<div class="row"><div>' . $field('db_host', 'Host', 'text', 'required spellcheck="false"') . '</div><div>' . $field('db_port', 'Port', 'text', 'required inputmode="numeric"') . '</div></div>'
        . '<div class="row"><div>' . $field('db_user', 'Username', 'text', 'required spellcheck="false"') . '</div><div>'
        . $field('db_pass', 'Password', 'password', 'autocomplete="new-password"' . ($savedPassword ? ' placeholder="saved - blank keeps it"' : '')) . '</div></div>'
        . $field('db_name', 'IAM database', 'text', 'required spellcheck="false"')
        . $test('test_database', 'Test connection');

    $body .= '<h2>Accounts</h2>';
    if ($hasAccounts) {
        $body .= '<p>The database has ' . $state['accounts'] . ' account' . ($state['accounts'] === 1 ? '' : 's') . ' already; saving leaves them as they are.</p>'
            . '<input type="hidden" name="accounts" value="later">'
            . $field('legacy', 'Old genshin accounts database (optional)', 'text', 'placeholder="users" spellcheck="false"')
            . '<p class="muted">Kept in the configuration for <code>php import-legacy.php</code>.</p>';
    } else {
        $body .= $radio('import', "Bring the accounts over from the genshin site's database" . ($local ? '' : ' (recommended)'))
            . '<div data-accounts="import">'
            . $field('legacy', 'Old accounts database', 'text', 'placeholder="users" spellcheck="false"')
            . '<p class="muted">On the same server, reachable with the same username and password. Every account keeps its id, password, Google link and two-factor; its admins become IAM admins.</p>'
            . $test('test_legacy', 'Test connection')
            . '</div>'
            . $radio('fresh', 'Start with a new administrator')
            . '<div data-accounts="fresh">'
            . $field('email', 'Administrator email', 'text', 'inputmode="email" spellcheck="false"')
            . '<div class="row"><div>' . $field('password', 'Password', 'password', 'minlength="' . PASSWORD_MIN . '" autocomplete="new-password"') . '</div><div>'
            . $field('confirm', 'Confirm', 'password', 'minlength="' . PASSWORD_MIN . '" autocomplete="new-password"') . '</div></div>'
            . '</div>'
            . ($local ? $radio('later', 'Not now - leave the database without accounts') : '');
    }

    if (!$local) {
        $body .= '<h2>The sites</h2><p>Each site\'s own address. The database arrives with the development ones; these replace them. Leave one blank to add it later in the admin console.</p>';
        foreach (SETUP_SITES as $clientId => $site) {
            $body .= '<label for="site_' . $clientId . '">' . setupEscape($site['name']) . '</label><input id="site_' . $clientId . '" name="site[' . $clientId . ']" type="text" inputmode="url" spellcheck="false"'
                . ' value="' . setupEscape($values['sites'][$clientId] ?? '') . '" placeholder="' . setupEscape($site['example']) . '">';
        }
        $body .= '<p class="muted">Each gets <code>/auth/callback</code> to land its sign-in on, <code>/</code> after signing out, and its origin for the token endpoint.</p>';
    }

    $body .= '<h2>Email</h2>'
        . '<label class="check"><input type="checkbox" name="mail_send"' . ($values['mail_send'] ? ' checked' : '') . '> <span>Send mail (PHP mail()). Unticked, every message is written to <code>storage/mail/</code> instead' . ($local ? ' - what a development machine wants.' : '.') . '</span></label>'
        . '<div class="row"><div>' . $field('mail_noreply', 'No-reply address', 'text', 'inputmode="email" spellcheck="false"') . '</div><div>' . $field('mail_support', 'Support address', 'text', 'inputmode="email" spellcheck="false"') . '</div></div>'
        . $field('mail_from_name', 'From name')
        . '<p class="muted">' . ($local ? 'On a real host both must be real mailboxes on its domain.' : 'Both must be real mailboxes on your own domain - the hosting refuses mail from any other.') . '</p>';

    $body .= '<h2>Google sign-in (optional)</h2>'
        . $field('google_client_id', 'Google OAuth client ID', 'text', 'placeholder="...apps.googleusercontent.com" spellcheck="false"')
        . '<p class="muted">Its "Authorised JavaScript origins" must include the public address above. Leave blank to switch Google sign-in off.</p>';

    $body .= '<button type="submit" class="primary">' . ($local ? 'Save' : 'Install') . '</button></form>';

    return setupPage($local ? 'Set up the IAM on this computer' : 'Install the anotoki IAM', $body, $local, 'plain', $status);
}

// ── One request ──────────────────────────────────────────────────────────────

/**
 * Everything the installer does for one request, as an answer - status,
 * headers, body - rather than output, so the tests can drive it in-process
 * with a server of their own making.
 *
 * @return array{status: int, headers: array<string, string>, body: string}
 */
function setupHandle(array $server, array $query, array $post, string $rawBody, array $context): array
{
    $method = strtoupper((string) ($server['REQUEST_METHOD'] ?? 'GET'));
    $local = setupIsLocal($server);

    if ($method === 'GET' && ($query['asset'] ?? null) === 'js') {
        return setupResponse(200, 'text/javascript; charset=utf-8', setupScript());
    }

    $json = null;
    if ($method === 'POST' && str_starts_with(strtolower((string) ($server['CONTENT_TYPE'] ?? '')), 'application/json')) {
        $json = json_decode($rawBody, true);
        if (!is_array($json)) {
            return setupJson(400, 'bad', 'That was not a JSON object.');
        }
    }

    if (is_file($context['storage'] . '/installed.lock')) {
        if ($json !== null) {
            return setupJson(409, 'bad', 'This IAM is installed; the installer is locked.');
        }
        if ($local) {
            return setupPage('Locked', '<p>This copy was installed as a real host: <code>storage/installed.lock</code> stops the installer. To use the development setup on this computer, delete that file and reload.</p>', true, 'bad', 409);
        }
        $removed = setupRemoveInstaller($context);

        return setupPage('Already installed', '<p>This IAM has been set up; <code>storage/installed.lock</code> stops the installer running again.</p>'
            . ($removed ? '<p class="ok">The setup files have been removed.</p>' : '<p class="err">Delete <code>setup.php</code> and <code>config/setup.local.php</code> by hand.</p>'), false, 'good', 409);
    }

    if ($local) {
        if (setupNonce($context) === '') {
            return setupPage('Cannot write to storage/', '<p>The installer keeps the form\'s nonce in <code>storage/</code>, and cannot write there. Give the web server write permission on it and reload.</p>', true, 'bad', 500);
        }
    } elseif (setupToken($context) === null && !setupWritePrivate(setupTokenFile($context), setupPhpFile(['token' => bin2hex(random_bytes(24))]))) {
        return setupPage('Cannot write to config/', '<p>The installer could not create <code>config/setup.local.php</code>: the <code>config/</code> folder is not writable by the web server. Give it write permission and reload.</p>', false, 'bad', 500);
    }

    $saved = setupSaved($context);

    if ($method === 'POST') {
        $refusal = setupRefusal($local, $server, $json ?? $post, $context);
        if ($refusal !== null) {
            return $json !== null
                ? setupJson(403, 'bad', $refusal)
                : setupForm($local, $context, $saved, setupPosted(setupValues($local, $server, $saved), $post), [$refusal], 403);
        }

        return $json !== null ? setupTest($local, $server, $json, $context) : setupSave($local, $server, $post, $context);
    }

    return setupForm($local, $context, $saved, setupValues($local, $server, $saved));
}

// The tests load this file for its functions and hand setupHandle() requests
// of their own; nothing is served while they do.
if (!defined('IAM_SETUP_NO_RUN')) {
    $answer = setupHandle($_SERVER, $_GET, $_POST, (string) file_get_contents('php://input'), setupContext());

    http_response_code($answer['status']);
    foreach ($answer['headers'] as $name => $value) {
        header($name . ': ' . $value);
    }
    echo $answer['body'];
}
