<?php
/*
 * The anotoki host check - the anotoki IAM
 *
 * One standalone PHP file that tells what this server's PHP has and lacks for %SITE_NAME%, and how
 * requests reach it: through Cloudflare, straight from the visitor, or through a proxy of the host's. It is
 * not part of the site and never part of a build (it lives in tools/, which no build copies).
 *
 * How to use it:
 *   1. Write a long random value (32 characters or more) into HOST_CHECK_TOKEN below.
 *   2. Upload this file into the site's web root (beside index.html and api/).
 *   3. Open https://<the site's address>/host-check.php?token=<that value>
 *      (?token=...&format=json gives the same as JSON).
 *   4. Delete it: the page has a button for that, or delete it over SFTP.
 *
 * It prints nothing of the site's settings, no password, no key and no environment variable - only what
 * PHP reports about itself and about the request you just made. It runs on old PHP too (5.6 and up), so a
 * subdomain still set to an old PHP gets a readable answer instead of a blank page. Without the right token
 * it answers 404, like a file that is not there. From the command line (php host-check.php) it needs no
 * token - but the command line's PHP is often not the one the web server runs.
 */

define('HOST_CHECK_TOKEN', '');

// What this site needs (each copy of this file says it for its own site).
$site = array(
    'name' => 'the anotoki IAM',
    'php' => '8.2.0',
    'required' => array(
        'pdo' => 'the database',
        'pdo_pgsql' => 'the database (PostgreSQL)',
        'json' => 'every answer the site gives',
        'mbstring' => 'text handling (the shared library)',
        'zlib' => 'the backups (each is compressed)',
        'openssl' => 'signing tokens, the encrypted secrets and backups',
    ),
    'recommended' => array(
        'curl' => 'asking other servers (PHP streams do it otherwise)',
    ),
    'functions' => array(
        'mail' => 'the mails: confirmations, password resets, sign-in notices',
    ),
    'ini_on' => array(
        'allow_url_fopen' => 'sign-in with Google, which the IAM asks with PHP streams',
    ),
    'outbound' => 'needed',
    'asks' => 'Google (sign-in with Google)',
    'uploads_mb' => 0,
);

// Cloudflare's published ranges (https://www.cloudflare.com/ips-v4 and /ips-v6), as the anotoki library
// knows them (ClientAddress::CLOUDFLARE, fetched 2026-10-11).
$cloudflare = array(
    '173.245.48.0/20', '103.21.244.0/22', '103.22.200.0/22', '103.31.4.0/22', '141.101.64.0/18',
    '108.162.192.0/18', '190.93.240.0/20', '188.114.96.0/20', '197.234.240.0/22', '198.41.128.0/17',
    '162.158.0.0/15', '104.16.0.0/13', '104.24.0.0/14', '172.64.0.0/13', '131.0.72.0/22',
    '2400:cb00::/32', '2606:4700::/32', '2803:f800::/32', '2405:b500::/32', '2405:8100::/32',
    '2a06:98c0::/29', '2c0f:f248::/32',
);

$cli = PHP_SAPI === 'cli';

if (!$cli) {
    header('Cache-Control: no-store');
    header('X-Robots-Tag: noindex, nofollow');
    header('X-Content-Type-Options: nosniff');
    header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; form-action 'self'");
    $given = isset($_POST['token']) ? (string) $_POST['token'] : (isset($_GET['token']) ? (string) $_GET['token'] : '');
    if (strlen(HOST_CHECK_TOKEN) < 32 || !function_exists('hash_equals') || !hash_equals(HOST_CHECK_TOKEN, $given)) {
        header('HTTP/1.1 404 Not Found');
        echo 'Not found';
        exit;
    }
    if (isset($_POST['delete'])) {
        $gone = @unlink(__FILE__);
        header('Content-Type: text/plain; charset=utf-8');
        echo $gone ? "The host check is deleted.\n" : "It could not delete itself: delete host-check.php over SFTP.\n";
        exit;
    }
}

$checks = array();

function hc_add(&$checks, $status, $label, $detail)
{
    $checks[] = array('status' => $status, 'label' => $label, 'detail' => $detail);
}

function hc_disabled()
{
    $list = array();
    foreach (explode(',', (string) ini_get('disable_functions')) as $name) {
        $name = strtolower(trim($name));
        if ($name !== '') {
            $list[] = $name;
        }
    }
    return $list;
}

function hc_can($function)
{
    return function_exists($function) && !in_array(strtolower($function), hc_disabled(), true);
}

function hc_bytes($value)
{
    $value = trim((string) $value);
    if ($value === '' || $value === '-1') {
        return $value === '-1' ? -1 : 0;
    }
    $number = (float) $value;
    switch (strtolower(substr($value, -1))) {
        case 'g':
            $number *= 1024;
            // fall through
        case 'm':
            $number *= 1024;
            // fall through
        case 'k':
            $number *= 1024;
    }
    return (int) $number;
}

function hc_in_range($ip, $cidr)
{
    $parts = explode('/', $cidr, 2);
    $net = @inet_pton($parts[0]);
    $address = @inet_pton($ip);
    if ($net === false || $address === false || strlen($net) !== strlen($address)) {
        return false;
    }
    $bits = isset($parts[1]) ? (int) $parts[1] : strlen($net) * 8;
    $whole = (int) floor($bits / 8);
    $rest = $bits % 8;
    if ($whole > 0 && substr($net, 0, $whole) !== substr($address, 0, $whole)) {
        return false;
    }
    if ($rest === 0) {
        return true;
    }
    $mask = (0xff << (8 - $rest)) & 0xff;
    return (ord($net[$whole]) & $mask) === (ord($address[$whole]) & $mask);
}

function hc_in_any($ip, $ranges)
{
    foreach ($ranges as $range) {
        if (hc_in_range($ip, $range)) {
            return true;
        }
    }
    return false;
}

function hc_writable($dir)
{
    $probe = rtrim($dir, '/\\') . '/.host-check-' . str_replace('.', '', uniqid('', true));
    if (@file_put_contents($probe, 'x') !== 1) {
        return false;
    }
    @unlink($probe);
    return true;
}

function hc_fetch($url)
{
    if (extension_loaded('curl') && hc_can('curl_init') && hc_can('curl_exec')) {
        $handle = curl_init($url);
        curl_setopt($handle, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($handle, CURLOPT_CONNECTTIMEOUT, 4);
        curl_setopt($handle, CURLOPT_TIMEOUT, 8);
        $body = curl_exec($handle);
        $code = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
        $error = curl_error($handle);
        if (PHP_VERSION_ID < 80000) {
            curl_close($handle); // a no-op since PHP 8.0, deprecated in 8.5
        }
        return array('via' => 'curl', 'code' => $code, 'error' => $error, 'body' => is_string($body) ? $body : '');
    }
    if (ini_get('allow_url_fopen')) {
        $context = stream_context_create(array('http' => array('timeout' => 8, 'ignore_errors' => true)));
        $body = @file_get_contents($url, false, $context);
        // PHP 8.4 and later name the answer's headers through a function; older PHP fills a local variable,
        // read here by name so that 8.5 does not warn about it.
        $headers = function_exists('http_get_last_response_headers') ? http_get_last_response_headers() : null;
        if ($headers === null) {
            $defined = get_defined_vars();
            $headers = isset($defined['http_response_header']) ? $defined['http_response_header'] : array();
        }
        $code = 0;
        if (isset($headers[0]) && preg_match('/\s(\d{3})(\s|$)/', $headers[0], $match)) {
            $code = (int) $match[1];
        }
        return array('via' => 'PHP streams', 'code' => $code, 'error' => $body === false ? 'no answer' : '', 'body' => (string) $body);
    }
    return null;
}

// --- PHP itself ---------------------------------------------------------------------------------------

$version = PHP_VERSION;
if (version_compare($version, $site['php'], '>=')) {
    hc_add($checks, 'ok', 'PHP version', 'PHP ' . $version . ' (' . $site['name'] . ' needs ' . $site['php'] . ' or newer).');
} else {
    hc_add($checks, 'fail', 'PHP version', 'PHP ' . $version . ': ' . $site['name'] . ' needs ' . $site['php']
        . ' or newer - choose a newer PHP for this subdomain in the hosting panel.');
}
hc_add($checks, 'info', 'PHP runs as', PHP_SAPI . (isset($_SERVER['SERVER_SOFTWARE']) ? ', under ' . $_SERVER['SERVER_SOFTWARE'] : '') . '.');

foreach ($site['required'] as $extension => $why) {
    if (extension_loaded($extension)) {
        hc_add($checks, 'ok', 'Extension ' . $extension, 'Loaded (' . $why . ').');
    } else {
        hc_add($checks, 'fail', 'Extension ' . $extension, 'Missing - needed for ' . $why . '. Turn it on in the hosting panel\'s PHP settings.');
    }
}
foreach ($site['recommended'] as $extension => $why) {
    if (extension_loaded($extension)) {
        hc_add($checks, 'ok', 'Extension ' . $extension, 'Loaded (' . $why . ').');
    } else {
        hc_add($checks, 'warn', 'Extension ' . $extension, 'Missing - wanted for ' . $why . '; the site runs without it.');
    }
}
if (extension_loaded('pdo')) {
    $drivers = class_exists('PDO') ? PDO::getAvailableDrivers() : array();
    if (in_array('pgsql', $drivers, true)) {
        hc_add($checks, 'ok', 'PDO driver for PostgreSQL', 'Available (drivers: ' . implode(', ', $drivers) . ').');
    } else {
        hc_add($checks, 'fail', 'PDO driver for PostgreSQL', 'Not available (drivers: ' . (empty($drivers) ? 'none' : implode(', ', $drivers)) . ').');
    }
}

foreach ($site['functions'] as $function => $why) {
    if (hc_can($function)) {
        hc_add($checks, 'ok', 'Function ' . $function . '()', 'Available (' . $why . ').');
    } else {
        hc_add($checks, 'fail', 'Function ' . $function . '()', 'Missing or switched off (disable_functions) - needed for ' . $why . '.');
    }
}

foreach ($site['ini_on'] as $setting => $why) {
    if (ini_get($setting)) {
        hc_add($checks, 'ok', $setting, 'On (' . $why . ').');
    } else {
        hc_add($checks, 'warn', $setting, 'Off - needed for ' . $why . '.');
    }
}

// The library's key file is put in place with link() where it can be; without it, a fallback is used.
if (hc_can('link')) {
    hc_add($checks, 'ok', 'Function link()', 'Available (the shared library makes its key file with it).');
} else {
    hc_add($checks, 'warn', 'Function link()', 'Switched off - the shared library uses its fallback for its key file; nothing to do.');
}
if (hc_can('fastcgi_finish_request') || hc_can('litespeed_finish_request')) {
    hc_add($checks, 'ok', 'Work after the answer', 'Available: the daily backup and the clean-ups run after the visitor has the page.');
} else {
    hc_add($checks, 'info', 'Work after the answer', 'Not available under this PHP (' . PHP_SAPI . '): the daily backup and the clean-ups run before the answer ends, so that one request is slower.');
}
$disabled = hc_disabled();
hc_add($checks, 'info', 'Switched-off functions', empty($disabled) ? 'None.' : implode(', ', $disabled) . '.');

// --- Settings -----------------------------------------------------------------------------------------

$memory = hc_bytes(ini_get('memory_limit'));
if ($memory === -1 || $memory >= 128 * 1024 * 1024) {
    hc_add($checks, 'ok', 'memory_limit', ini_get('memory_limit') . '.');
} else {
    hc_add($checks, 'warn', 'memory_limit', ini_get('memory_limit') . ' - 128M or more is safer for backups and imports.');
}
$seconds = (int) ini_get('max_execution_time');
if ($seconds === 0 || $seconds >= 30) {
    hc_add($checks, 'ok', 'max_execution_time', ($seconds === 0 ? 'No limit' : $seconds . ' s') . '.');
} else {
    hc_add($checks, 'warn', 'max_execution_time', $seconds . ' s - 30 s or more is safer for backups and updates.');
}
if ($site['uploads_mb'] > 0) {
    $need = $site['uploads_mb'] * 1024 * 1024;
    $upload = hc_bytes(ini_get('upload_max_filesize'));
    $post = hc_bytes(ini_get('post_max_size'));
    if (!ini_get('file_uploads')) {
        hc_add($checks, 'fail', 'File uploads', 'file_uploads is off - the admin\'s file explorer cannot upload.');
    } elseif ($upload < $need || ($post !== -1 && $post !== 0 && $post < $need)) {
        hc_add($checks, 'warn', 'File uploads', 'upload_max_filesize ' . ini_get('upload_max_filesize') . ', post_max_size ' . ini_get('post_max_size')
            . ' - the site takes files up to ' . $site['uploads_mb'] . ' MB; raise both to at least ' . $site['uploads_mb'] . 'M.');
    } else {
        hc_add($checks, 'ok', 'File uploads', 'upload_max_filesize ' . ini_get('upload_max_filesize') . ', post_max_size ' . ini_get('post_max_size') . '.');
    }
}
if (ini_get('display_errors') && strtolower((string) ini_get('display_errors')) !== 'off' && strtolower((string) ini_get('display_errors')) !== 'stderr') {
    hc_add($checks, 'warn', 'display_errors', 'On - PHP would show its errors to visitors. The site switches it off itself, but off in the hosting panel is better.');
} else {
    hc_add($checks, 'ok', 'display_errors', 'Off.');
}
$basedir = (string) ini_get('open_basedir');
hc_add($checks, 'info', 'open_basedir', $basedir === '' ? 'Not set.' : $basedir);

// --- Asking other servers -----------------------------------------------------------------------------

$fetched = hc_fetch('https://www.cloudflare.com/ips-v4');
if ($fetched === null) {
    hc_add($checks, $site['outbound'] === 'none' ? 'info' : 'fail', 'Asking other servers', 'Neither curl nor allow_url_fopen: ' . $site['name'] . ' cannot ask ' . $site['asks'] . '.');
} elseif ($fetched['code'] === 200) {
    hc_add($checks, 'ok', 'Asking other servers', 'Works over https (through ' . $fetched['via'] . '): ' . $site['name'] . ' can ask ' . $site['asks'] . '.');
    $published = array();
    foreach (preg_split('/\s+/', trim($fetched['body'])) as $line) {
        if ($line !== '') {
            $published[] = $line;
        }
    }
    $known = array();
    foreach ($cloudflare as $range) {
        if (strpos($range, ':') === false) {
            $known[] = $range;
        }
    }
    sort($published);
    sort($known);
    if (!empty($published) && $published !== $known) {
        hc_add($checks, 'warn', 'Cloudflare\'s ranges', 'Cloudflare publishes other IPv4 ranges than the sites know: the shared library\'s list (ClientAddress::CLOUDFLARE) needs refreshing.');
    } elseif (!empty($published)) {
        hc_add($checks, 'ok', 'Cloudflare\'s ranges', 'The IPv4 ranges Cloudflare publishes today are the ones the sites know.');
    }
} elseif (stripos($fetched['error'], 'certificate') !== false) {
    hc_add($checks, 'fail', 'Asking other servers', 'Outgoing https reaches the network, but PHP cannot check certificates (' . $fetched['error']
        . '): it has no CA bundle. The host must set one (curl.cainfo / openssl.cafile) - ' . $site['name'] . ' needs it to ask ' . $site['asks'] . '.');
} else {
    hc_add($checks, 'warn', 'Asking other servers', 'A test request to Cloudflare got ' . ($fetched['code'] > 0 ? 'HTTP ' . $fetched['code'] : 'no answer')
        . ($fetched['error'] !== '' ? ' (' . $fetched['error'] . ')' : '') . ' through ' . $fetched['via'] . ' - outgoing https may be blocked; ' . $site['name'] . ' needs to ask ' . $site['asks'] . '.');
}

// --- Folders ------------------------------------------------------------------------------------------

$root = __DIR__;
$found = false;
foreach (array('storage', 'config', 'backend/storage', 'backend/config') as $folder) {
    $path = $root . '/' . $folder;
    if (is_dir($path)) {
        $found = true;
        if (hc_writable($path)) {
            hc_add($checks, 'ok', 'Folder ' . $folder . '/', 'Writable.');
        } else {
            hc_add($checks, 'fail', 'Folder ' . $folder . '/', 'Not writable by PHP - the site cannot keep its ' . (strpos($folder, 'storage') !== false ? 'logs, cache and backups' : 'settings') . '.');
        }
    }
}
if (!$found) {
    if (hc_writable($root)) {
        hc_add($checks, 'ok', 'Web root', 'Writable: the setup page can make the site\'s config/ and storage/ folders.');
    } else {
        hc_add($checks, 'fail', 'Web root', 'Not writable by PHP, and no config/ or storage/ folder yet: the setup page cannot make them.');
    }
}
$temp = sys_get_temp_dir();
if ($temp !== '' && hc_writable($temp)) {
    hc_add($checks, 'ok', 'Temporary folder', 'Writable.');
} else {
    hc_add($checks, 'warn', 'Temporary folder', 'Not writable (' . ($temp === '' ? 'none set' : 'the system\'s') . ') - the site keeps its temporary files under storage/ instead.');
}

// --- How requests reach this server -------------------------------------------------------------------

if (!$cli) {
    $remote = isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : '';
    if (preg_match('/^::ffff:(\d{1,3}(\.\d{1,3}){3})$/i', $remote, $mapped)) {
        $remote = $mapped[1];
    }
    $connecting = isset($_SERVER['HTTP_CF_CONNECTING_IP']) ? trim((string) $_SERVER['HTTP_CF_CONNECTING_IP']) : '';
    $private = array('10.0.0.0/8', '172.16.0.0/12', '192.168.0.0/16', '127.0.0.0/8', '100.64.0.0/10', '169.254.0.0/16', '::1/128', 'fc00::/7', 'fe80::/10');
    if ($remote === '') {
        hc_add($checks, 'warn', 'Who connects', 'The server gives no REMOTE_ADDR.');
    } elseif (hc_in_any($remote, $cloudflare)) {
        hc_add($checks, 'ok', 'Who connects', 'Cloudflare (' . $remote . '): the sites read the visitor from CF-Connecting-IP' . ($connecting !== '' ? ' - here ' . $connecting . ', which should be your own address' : ' - but this request carries none') . '.');
    } elseif (hc_in_any($remote, $private)) {
        hc_add($checks, 'fail', 'Who connects', 'A proxy of the host\'s (' . $remote . '): every visitor would share that address and the limits would hit everybody. Ask the host to give PHP the real address (for Apache: mod_remoteip, trusting Cloudflare\'s ranges).');
    } elseif ($connecting !== '' && $connecting === $remote) {
        hc_add($checks, 'ok', 'Who connects', 'The visitor (' . $remote . '): the host gives PHP the address Cloudflare names already; the sites count it as it is.');
    } elseif ($connecting !== '') {
        hc_add($checks, 'warn', 'Who connects', $remote . ' - not one of Cloudflare\'s ranges, though the request carries CF-Connecting-IP. Either the sites\' list of Cloudflare\'s ranges is old, or something else stands in front.');
    } else {
        hc_add($checks, 'warn', 'Who connects', $remote . ' - straight to the server, not through Cloudflare. Fine for a test; the sites are meant to be reached through Cloudflare.');
    }
    $https = (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off') || (isset($_SERVER['SERVER_PORT']) && (int) $_SERVER['SERVER_PORT'] === 443);
    if ($https) {
        hc_add($checks, 'ok', 'https to this server', 'The connection to this server is https (Cloudflare\'s SSL/TLS mode Full or Full (strict)).');
    } else {
        hc_add($checks, 'fail', 'https to this server', 'The connection to this server is plain http - with Cloudflare that is its "Flexible" mode. Set SSL/TLS to "Full (strict)": the sites\' secure cookies need https end to end.');
    }
    $secret = isset($_SERVER['HTTP_X_ANOTOKI_ORIGIN']) && $_SERVER['HTTP_X_ANOTOKI_ORIGIN'] !== '';
    hc_add($checks, $secret ? 'ok' : 'info', 'Cloudflare\'s secret header', $secret
        ? 'X-Anotoki-Origin is there (its value is not shown): the Transform Rule works.'
        : 'No X-Anotoki-Origin header: the Cloudflare Transform Rule that adds it is not set up (yet).');
}

// --- The answer ---------------------------------------------------------------------------------------

$count = array('ok' => 0, 'warn' => 0, 'fail' => 0, 'info' => 0);
foreach ($checks as $check) {
    $count[$check['status']]++;
}
$summary = $count['fail'] > 0
    ? $count['fail'] . ' to fix, ' . $count['warn'] . ' to look at'
    : ($count['warn'] > 0 ? 'Nothing to fix; ' . $count['warn'] . ' to look at' : 'Everything ' . $site['name'] . ' needs is here');

if ($cli) {
    echo 'The anotoki host check - ' . $site['name'] . "\n" . $summary . "\n\n";
    foreach ($checks as $check) {
        printf("%-5s %-28s %s\n", strtoupper($check['status']), $check['label'], $check['detail']);
    }
    exit($count['fail'] > 0 ? 1 : 0);
}

if (isset($_GET['format']) && $_GET['format'] === 'json') {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(array('site' => $site['name'], 'php' => PHP_VERSION, 'summary' => $summary, 'counts' => $count, 'checks' => $checks));
    exit;
}

function hc_h($text)
{
    return htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8');
}

header('Content-Type: text/html; charset=utf-8');
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Host check · <?php echo hc_h($site['name']); ?></title>
<style>
:root { --ink: #1c2230; --muted: #5b6475; --line: #d9dee8; --page: #f6f7fa; --card: #fff; --ok: #1d7a4a; --warn: #8a5a00; --fail: #b3261e; --info: #3d5a99; }
@media (prefers-color-scheme: dark) { :root { --ink: #e7eaf1; --muted: #a3abbd; --line: #343b4b; --page: #12151c; --card: #1a1f29; --ok: #6fd3a0; --warn: #f0c060; --fail: #ff8a80; --info: #9db8ff; } }
* { box-sizing: border-box; }
body { margin: 0; padding: 24px 16px; background: var(--page); color: var(--ink); font: 15px/1.5 system-ui, -apple-system, "Segoe UI", sans-serif; }
main { max-width: 960px; margin: 0 auto; }
h1 { font-size: 22px; margin: 0 0 4px; }
p.summary { margin: 0 0 20px; color: var(--muted); }
table { width: 100%; border-collapse: collapse; background: var(--card); border: 1px solid var(--line); border-radius: 8px; overflow: hidden; }
th, td { text-align: left; vertical-align: top; padding: 10px 12px; border-top: 1px solid var(--line); }
th { border-top: 0; font-size: 13px; color: var(--muted); }
td.status { font-weight: 700; white-space: nowrap; }
.ok { color: var(--ok); } .warn { color: var(--warn); } .fail { color: var(--fail); } .info { color: var(--info); }
form { margin-top: 20px; }
button { font: inherit; padding: 8px 16px; border-radius: 6px; border: 1px solid var(--fail); background: transparent; color: var(--fail); cursor: pointer; }
@media (max-width: 600px) { th:nth-child(1), td:nth-child(1) { display: none; } td { display: block; border-top: 0; padding: 4px 12px; } tr { display: block; border-top: 1px solid var(--line); padding: 8px 0; } thead { display: none; } }
</style>
</head>
<body>
<main>
<h1>Host check · <?php echo hc_h($site['name']); ?></h1>
<p class="summary"><?php echo hc_h($summary); ?>. PHP <?php echo hc_h(PHP_VERSION); ?>.</p>
<table>
<thead><tr><th>Status</th><th>Check</th><th>What it found</th></tr></thead>
<tbody>
<?php foreach ($checks as $check): ?>
<tr><td class="status <?php echo hc_h($check['status']); ?>"><?php echo hc_h(strtoupper($check['status'])); ?></td><td><span class="<?php echo hc_h($check['status']); ?>"><?php echo hc_h($check['label']); ?></span></td><td><?php echo hc_h($check['detail']); ?></td></tr>
<?php endforeach; ?>
</tbody>
</table>
<form method="post" action="">
<input type="hidden" name="token" value="<?php echo hc_h($given); ?>">
<button type="submit" name="delete" value="1">Delete this host check from the server</button>
</form>
</main>
</body>
</html>
