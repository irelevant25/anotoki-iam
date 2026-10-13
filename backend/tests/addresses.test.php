<?php

/**
 * Where a request comes from (the owner's decision of 2026-10-11,
 * docs/decisions.md): the anotoki sites are behind Cloudflare, so the IAM's
 * trusted proxies are Cloudflare's published ranges in its committed
 * configuration (config/iam.php: the anotoki library's
 * ClientAddress::CLOUDFLARE), which an installation may replace in
 * iam.local.php. A connection from one of them names the visitor in
 * CF-Connecting-IP, and the visitor is who the limiter counts, the audit log
 * keeps and a session shows (requestIp(), the family's one rule). From
 * anywhere else the header is its sender's own words and never believed - the
 * attack that stops: a guesser writing a new address into every request to
 * walk past the limiter.
 *
 * And the origin secret (the owner's decision of 2026-10-13, anotoki-lib
 * 0.5.2): Cloudflare's ranges are every Cloudflare customer's, so anybody's
 * Worker can write the CF-Connecting-IP it likes from them. With
 * config/cloudflare.local.php the header is believed only on a request that
 * carries the zone's secret in X-Anotoki-Origin; without it a request from the
 * ranges is the edge it came from - one source to the limiter, however many
 * addresses it writes. A local file that prints (no <?php line, a byte-order
 * mark) is passed over and never printed - and a cloudflare.local.php passed
 * over so, or one PHP cannot read, fails closed: a secret no request carries.
 */

declare(strict_types=1);

require __DIR__ . '/lib.php';

use Anotoki\Lib\Support\ClientAddress;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Factory\ServerRequestFactory;

bootTests();

const PASSWORD = 'correct horse';

/** A connection from one of Cloudflare's edges: inside 173.245.48.0/20 - and one inside 2606:4700::/32. */
const CLOUDFLARE_EDGE = '173.245.48.7';
const CLOUDFLARE_EDGE_V6 = '2606:4700:10::6814:7';

/** A connection from anywhere else. */
const ELSEWHERE = '203.0.113.50';

/** A request as the server sees one: the connection's address, and the headers its sender wrote. */
function requestFrom(?string $remote, array $headers = []): ServerRequestInterface
{
    $request = (new ServerRequestFactory())->createServerRequest('POST', TEST_ISSUER . '/api/login/password', $remote === null ? [] : ['REMOTE_ADDR' => $remote]);
    foreach ($headers as $name => $value) {
        $request = $request->withHeader($name, $value);
    }

    return $request;
}

/** A password sign-in from a connection, with an address written into CF-Connecting-IP. */
function signInVia(string $connection, string $written, string $email, string $password): TestResponse
{
    return (new Browser($connection))->request('POST', '/api/login/password', ['json' => ['email' => $email, 'password' => $password], 'headers' => ['CF-Connecting-IP' => $written]]);
}

/** The addresses the limiter counted an address's attempts under, each once. */
function attemptAddresses(string $email): array
{
    $statement = iamDb()->prepare('SELECT DISTINCT ip FROM login_attempts WHERE email = ? ORDER BY ip');
    $statement->execute([$email]);

    return $statement->fetchAll(PDO::FETCH_COLUMN);
}

/** Runs $body with config/iam.php's tunables replaced as an installation's iam.local.php replaces them. */
function withIamConfig(array $local, callable $body): void
{
    writeTestConfig('iam', ['public_url' => TEST_ISSUER] + $local);
    forgetLocalConfig();
    try {
        $body();
    } finally {
        writeTestConfig('iam', ['public_url' => TEST_ISSUER]);
        forgetLocalConfig();
    }
}

// ─────────────────────────────────────────────────────────────────────────────

test('the committed configuration trusts Cloudflare\'s published ranges; an installation names its own proxies, or none', function () {
    same(ClientAddress::CLOUDFLARE, iamConfig()['trusted_proxies'], 'by default: Cloudflare\'s ranges, as the anotoki library keeps them for every site');
    same(ClientAddress::CLOUDFLARE, iamTrustedProxies(), 'every one of them believed');
    same([15, 7], [count(array_filter(ClientAddress::CLOUDFLARE, static fn(string $range): bool => !str_contains($range, ':'))), count(array_filter(ClientAddress::CLOUDFLARE, static fn(string $range): bool => str_contains($range, ':')))],
        'its fifteen IPv4 ranges and seven IPv6 ones');
    check(in_array('173.245.48.0/20', ClientAddress::CLOUDFLARE, true) && in_array('2606:4700::/32', ClientAddress::CLOUDFLARE, true), 'among them the two these tests connect from');
    check(!array_key_exists('client_ip_header', iamConfig()), 'and no setting names the header: CF-Connecting-IP is pinned in the code');

    withIamConfig(['trusted_proxies' => []], static function (): void {
        same([], iamConfig()['trusted_proxies'], 'an installation behind no proxy: none');
        same(CLOUDFLARE_EDGE, requestIp(requestFrom(CLOUDFLARE_EDGE, ['CF-Connecting-IP' => '198.51.100.7'])), 'and then nobody\'s header is believed, a Cloudflare edge\'s neither');
    });
    withIamConfig(['trusted_proxies' => ['10.0.0.7', '192.168.1.128/25']], static function (): void {
        same('198.51.100.7', requestIp(requestFrom('10.0.0.7', ['CF-Connecting-IP' => '198.51.100.7'])), 'proxies of its own: a single address is a range of one');
        same('10.0.0.8', requestIp(requestFrom('10.0.0.8', ['CF-Connecting-IP' => '198.51.100.7'])), 'its neighbour is not');
        same('198.51.100.7', requestIp(requestFrom('192.168.1.130', ['CF-Connecting-IP' => '198.51.100.7'])), 'a prefix that ends inside a byte: in');
        same('192.168.1.127', requestIp(requestFrom('192.168.1.127', ['CF-Connecting-IP' => '198.51.100.7'])), 'and just outside it: out');
        same(CLOUDFLARE_EDGE, requestIp(requestFrom(CLOUDFLARE_EDGE, ['CF-Connecting-IP' => '198.51.100.7'])), 'Cloudflare no longer, once the installation names others');
    });
});

test('requestIp(): the visitor from Cloudflare\'s ranges, v4 and v6; the connection from anywhere else; never a header that is no single address, and never X-Forwarded-For', function () {
    same('198.51.100.7', requestIp(requestFrom(CLOUDFLARE_EDGE, ['CF-Connecting-IP' => '198.51.100.7'])), 'from an IPv4 edge: the visitor');
    same('2001:db8:1:2::7', requestIp(requestFrom(CLOUDFLARE_EDGE_V6, ['CF-Connecting-IP' => '2001:db8:1:2::7'])), 'from an IPv6 edge: the visitor');
    same('198.51.100.7', requestIp(requestFrom('::ffff:' . CLOUDFLARE_EDGE, ['CF-Connecting-IP' => '198.51.100.7'])), 'an edge\'s IPv4 address written as IPv6 (a server listening on both) is the IPv4 one, and trusted');
    same('198.51.100.7', requestIp(requestFrom(CLOUDFLARE_EDGE, ['CF-Connecting-IP' => '::ffff:198.51.100.7'])), 'and so is a visitor\'s');
    same(CLOUDFLARE_EDGE, requestIp(requestFrom(CLOUDFLARE_EDGE)), 'an edge that names nobody: the edge');

    same(ELSEWHERE, requestIp(requestFrom(ELSEWHERE, ['CF-Connecting-IP' => '198.51.100.7'])), 'from anywhere else: the connection, whatever the header says');
    same('173.245.64.1', requestIp(requestFrom('173.245.64.1', ['CF-Connecting-IP' => '198.51.100.7'])), 'just past one of the ranges: not Cloudflare');
    same('2606:4701::1', requestIp(requestFrom('2606:4701::1', ['CF-Connecting-IP' => '198.51.100.7'])), 'just past an IPv6 one: not Cloudflare either');
    same(CLOUDFLARE_EDGE, requestIp(requestFrom(CLOUDFLARE_EDGE, ['CF-Connecting-IP' => 'not an address'])), 'a header that is no address: the edge');
    same(CLOUDFLARE_EDGE, requestIp(requestFrom(CLOUDFLARE_EDGE, ['CF-Connecting-IP' => '198.51.100.7, 198.51.100.8'])), 'nor a list of them');
    same(CLOUDFLARE_EDGE, requestIp(requestFrom(CLOUDFLARE_EDGE, ['X-Forwarded-For' => '198.51.100.7'])), 'X-Forwarded-For is never read');
    same(null, requestIp(requestFrom(null)), 'no connection\'s address: none');
});

test('the header is pinned: from a Cloudflare address, X-Real-IP, True-Client-IP and X-Forwarded-For - which Cloudflare passes on as the visitor wrote them - are never believed, nor a setting that names one', function () {
    foreach (['X-Real-IP', 'True-Client-IP', 'X-Forwarded-For', 'X-Client-IP', 'Forwarded'] as $header) {
        same(CLOUDFLARE_EDGE, requestIp(requestFrom(CLOUDFLARE_EDGE, [$header => '198.51.100.7'])), "{$header} from an IPv4 edge: the edge");
        same(CLOUDFLARE_EDGE_V6, requestIp(requestFrom(CLOUDFLARE_EDGE_V6, [$header => '198.51.100.7'])), "{$header} from an IPv6 edge: the edge");
        same('198.51.100.8', requestIp(requestFrom(CLOUDFLARE_EDGE, [$header => '198.51.100.7', 'CF-Connecting-IP' => '198.51.100.8'])), "{$header} beside CF-Connecting-IP: Cloudflare's");
    }

    // An iam.local.php from before the header was pinned, or one somebody wrote by hand: it is not read.
    withIamConfig(['client_ip_header' => 'X-Real-IP'], static function (): void {
        same(CLOUDFLARE_EDGE, requestIp(requestFrom(CLOUDFLARE_EDGE, ['X-Real-IP' => '198.51.100.7'])), 'a setting that names X-Real-IP: still not believed');
        same('198.51.100.8', requestIp(requestFrom(CLOUDFLARE_EDGE, ['X-Real-IP' => '198.51.100.7', 'CF-Connecting-IP' => '198.51.100.8'])), 'CF-Connecting-IP still is');
    });

    // Where it counts: a guesser behind Cloudflare writing a new X-Real-IP into every try is one visitor to the limiter.
    makeUser('pinned', 'pinned@example.test', PASSWORD);
    for ($guess = 0; $guess < ATTEMPT_MAX_PER_EMAIL_IP; $guess++) {
        (new Browser(CLOUDFLARE_EDGE))->request('POST', '/api/login/password', ['json' => ['email' => 'pinned@example.test', 'password' => "wrong {$guess}"],
            'headers' => ['CF-Connecting-IP' => '198.51.100.90', 'X-Real-IP' => "198.51.100.{$guess}", 'True-Client-IP' => "203.0.113.{$guess}"]]);
    }
    $refused = (new Browser(CLOUDFLARE_EDGE))->request('POST', '/api/login/password', ['json' => ['email' => 'pinned@example.test', 'password' => PASSWORD],
        'headers' => ['CF-Connecting-IP' => '198.51.100.90', 'X-Real-IP' => '198.51.100.200', 'True-Client-IP' => '203.0.113.200']]);
    same([429, 'too_many_attempts'], [$refused->status, $refused->code()], 'the eleventh, under yet other headers: refused');
    same(['198.51.100.90'], attemptAddresses('pinned@example.test'), 'every try counted as the visitor Cloudflare named');
});

test('a catch-all range is refused, never believed - 0.0.0.0/0, ::/0, anything wider than an IPv4 /8 or an IPv6 /16 - and so is what is no range at all', function () {
    $configured = ['0.0.0.0/0', '::/0', '10.0.0.0/7', '2001::/15', 'not a range', '203.0.113.0/33', '203.0.113.0/', '203.0.113.0/x', '203.0.113.0/24', '2001:db8:aa::/48', '10.0.0.0/8', '2a00::/16', '192.0.2.9'];
    withIamConfig(['trusted_proxies' => $configured], static function (): void {
        same(['203.0.113.0/24', '2001:db8:aa::/48', '10.0.0.0/8', '2a00::/16', '192.0.2.9'], iamTrustedProxies(), 'believed: the ranges no wider than a /8 (v4) or a /16 (v6), and a single address');
        same(8, count(glob(iamStorageDir() . '/cache/said-*.logged') ?: []), 'each of the eight refused said in PHP\'s error log - at most once a day, by its stamp');
        same('198.51.100.20', requestIp(requestFrom('198.51.100.20', ['CF-Connecting-IP' => '198.51.100.7'])), '0.0.0.0/0 configured: a connection from anywhere is still nobody\'s proxy');
        same('11.1.2.3', requestIp(requestFrom('11.1.2.3', ['CF-Connecting-IP' => '198.51.100.7'])), 'nor one inside a /7');
        same('2001:db9::1', requestIp(requestFrom('2001:db9::1', ['CF-Connecting-IP' => '198.51.100.7'])), '::/0 and a /15 configured: an IPv6 connection from anywhere neither');
        same('198.51.100.7', requestIp(requestFrom('203.0.113.9', ['CF-Connecting-IP' => '198.51.100.7'])), 'what is no wider than allowed is believed as before');
        same('198.51.100.7', requestIp(requestFrom('2001:db8:aa:1::9', ['CF-Connecting-IP' => '198.51.100.7'])), 'an IPv6 range of its own too');
        same('198.51.100.7', requestIp(requestFrom('192.0.2.9', ['CF-Connecting-IP' => '198.51.100.7'])), 'and a single address');
    });
    foreach (['0.0.0.0/0' => 'a range wider than a /8', '::/0' => 'a range wider than a /16', '127.0.0.0/1' => 'a range wider than a /8', '203.0.113.0/33' => 'no CIDR range', 'example.com' => 'no address or CIDR range'] as $range => $why) {
        same($why, trustedProxyRefusal($range), "{$range}: refused");
    }
    same(null, trustedProxyRefusal('104.16.0.0/13'), 'a range of Cloudflare\'s: believed');
});

test('the attack: a forged CF-Connecting-IP from outside Cloudflare\'s ranges is not believed - every guess counted under its connection, and the limiter holds', function () {
    makeUser('target', 'target@example.test', PASSWORD);
    for ($guess = 0; $guess < ATTEMPT_MAX_PER_EMAIL_IP; $guess++) {
        same([401, 'invalid_credentials'], [($r = signInVia(ELSEWHERE, "198.51.100.{$guess}", 'target@example.test', "wrong {$guess}"))->status, $r->code()], "guess {$guess}, a new address written in");
    }
    same([429, 'too_many_attempts'], [($r = signInVia(ELSEWHERE, '198.51.100.250', 'target@example.test', PASSWORD))->status, $r->code()],
        'then, with yet another address written in: refused - the right password too');
    same([ELSEWHERE], attemptAddresses('target@example.test'), 'every attempt counted under the connection\'s address, none under what its sender wrote');
    same(200, signInVia('203.0.113.51', '198.51.100.250', 'target@example.test', PASSWORD)->status, 'another connection is somebody else');
});

test('the attack: one IPv6 /64 walked address by address is one source - refused at 10 against an account and at 50 in all - and the next /64 is not', function () {
    $from = static fn(string $visitor, string $email, string $password): TestResponse => signInVia(CLOUDFLARE_EDGE_V6, $visitor, $email, $password);

    makeUser('walked', 'walked@example.test', PASSWORD);
    for ($guess = 1; $guess <= ATTEMPT_MAX_PER_EMAIL_IP; $guess++) {
        same(401, $from(sprintf('2001:db8:5:6:%x::1', $guess), 'walked@example.test', "wrong {$guess}")->status, "guess {$guess} at the account, from a new address of the /64");
    }
    same([429, 'too_many_attempts'], [($r = $from('2001:db8:5:6:ffff:ffff:ffff:fffe', 'walked@example.test', PASSWORD))->status, $r->code()],
        'the eleventh, from yet another address of it: refused - the right password too');
    same(['2001:db8:5:6::/64'], attemptAddresses('walked@example.test'), 'every guess counted under the /64, as kept');
    same(200, $from('2001:db8:5:7::1', 'walked@example.test', PASSWORD)->status, 'the next /64 is somebody else: signed in');

    for ($try = 1; $try <= ATTEMPT_MAX_PER_IP; $try++) {
        same(401, $from(sprintf('2001:db8:9:9:%x::%x', $try, $try), "nobody{$try}@example.test", 'whatever')->status, "a try at a list, account {$try}, a new address of another /64");
    }
    same([429, 'too_many_attempts'], [($r = $from('2001:db8:9:9:abcd::1', 'somebody-else@example.test', 'whatever'))->status, $r->code()],
        'the 51st, at an account it never tried, from yet another address of the /64: refused');
    same(401, $from('2001:db8:9:a::1', 'somebody-else@example.test', 'whatever')->status, 'the next /64 is not');
});

test('through Cloudflare: each visitor counted apart - one guesser on an edge locks out nobody else there - and the visitor is who the session shows and the audit log keeps', function () {
    $userId = makeUser('shared', 'shared@example.test', PASSWORD);
    for ($guess = 0; $guess < ATTEMPT_MAX_PER_EMAIL_IP; $guess++) {
        same(401, signInVia(CLOUDFLARE_EDGE, '198.51.100.66', 'shared@example.test', "wrong {$guess}")->status, "the guesser's guess {$guess}, through the edge");
    }
    same([429, 'too_many_attempts'], [($r = signInVia(CLOUDFLARE_EDGE, '198.51.100.66', 'shared@example.test', PASSWORD))->status, $r->code()], 'the guesser is stopped');
    same(['198.51.100.66'], attemptAddresses('shared@example.test'), 'counted as the visitor it is, not as the edge');

    $owner = new Browser(CLOUDFLARE_EDGE);
    $visitor = ['headers' => ['CF-Connecting-IP' => '2001:db8:77:1::5']];
    same(200, $owner->request('POST', '/api/login/password', ['json' => ['email' => 'shared@example.test', 'password' => PASSWORD]] + $visitor)->status, 'the owner, through the same edge, signs in');
    $session = iamDb()->prepare('SELECT ip FROM sessions WHERE user_id = ? ORDER BY id DESC LIMIT 1');
    $session->execute([$userId]);
    same('2001:db8:77:1::5', $session->fetchColumn(), 'the session shows the owner\'s address');

    same(200, $owner->request('PUT', '/api/account/profile', ['json' => ['username' => 'shared2']] + $visitor)->status, 'a change the audit log keeps');
    $entry = iamDb()->prepare("SELECT ip FROM anotoki_audit_log WHERE action = 'account.username_changed' AND actor_id = ? ORDER BY id DESC LIMIT 1");
    $entry->execute([$userId]);
    same('2001:db8:77:1::5', $entry->fetchColumn(), 'kept with the visitor\'s address');

    $forged = new Browser(ELSEWHERE);
    same(200, $forged->request('POST', '/api/login/password', ['json' => ['email' => 'shared@example.test', 'password' => PASSWORD], 'headers' => ['CF-Connecting-IP' => '2001:db8:77:1::5']])->status, 'from elsewhere, the owner\'s address written in');
    same(200, $forged->request('PUT', '/api/account/profile', ['json' => ['username' => 'shared3'], 'headers' => ['CF-Connecting-IP' => '2001:db8:77:1::5']])->status, 'another change');
    $entry->execute([$userId]);
    same(ELSEWHERE, $entry->fetchColumn(), 'kept with the connection\'s address: the written one is nobody\'s word');
});

// ── The origin secret (anotoki-lib 0.5.2) ────────────────────────────────────

/** The zone's secret in these tests: a long random value, as docs/operations.md asks. */
const ORIGIN_SECRET = 'k3Jx9vQ2mT7wR4pL8sN1bY6cF0hD5gAzE2uW7iO4';
const ORIGIN_HEADER = 'X-Anotoki-Origin';

/** Runs $body with config/cloudflare.local.php holding $contents - its bytes as they are. */
function withCloudflareFile(string $contents, callable $body): void
{
    $file = localConfigPath('cloudflare');
    file_put_contents($file, $contents);
    forgetLocalConfig();
    try {
        $body();
    } finally {
        @unlink($file);
        forgetLocalConfig();
    }
}

/** Runs $body with config/cloudflare.local.php returning ['origin_secret' => $secret], written as a file should be. */
function withOriginSecret(mixed $secret, callable $body): void
{
    withCloudflareFile("<?php\n\nreturn " . var_export(['origin_secret' => $secret], true) . ";\n", $body);
}

/** What PHP's error log was given while $body ran (error_log() into a file of this run's own). */
function loggedWhile(callable $body): string
{
    $file = testEnvironment()['base'] . DIRECTORY_SEPARATOR . 'php-errors-' . bin2hex(random_bytes(4)) . '.log';
    $before = ini_set('error_log', $file);
    try {
        $body();
    } finally {
        ini_set('error_log', $before === false ? '' : $before);
    }

    return is_file($file) ? (string) file_get_contents($file) : '';
}

/** $call's answer, and what it printed - which must be nothing. */
function printedBy(callable $call): array
{
    $level = ob_get_level();
    ob_start();
    try {
        $answer = $call();
    } finally {
        $printed = (string) ob_get_clean();
    }
    same($level, ob_get_level(), 'no output buffer left open');

    return [$answer, $printed];
}

test('the origin secret: from Cloudflare\'s ranges CF-Connecting-IP is believed only with the zone\'s X-Anotoki-Origin - without it, or with a wrong one, the request is the edge; from outside the ranges the connection, whatever it carries', function () {
    // (a) No secret configured: as before 0.5.2 - Cloudflare's ranges alone.
    same(null, iamOriginSecret(), 'no config/cloudflare.local.php: no secret');
    same('198.51.100.7', requestIp(requestFrom(CLOUDFLARE_EDGE, ['CF-Connecting-IP' => '198.51.100.7'])), 'no secret: the visitor Cloudflare names');

    withOriginSecret(ORIGIN_SECRET, static function (): void {
        same(ORIGIN_SECRET, iamOriginSecret(), 'the secret, read');

        // (b) A secret, and no header: the edge - a Worker of anybody's, writing what it likes.
        same(CLOUDFLARE_EDGE, requestIp(requestFrom(CLOUDFLARE_EDGE, ['CF-Connecting-IP' => '198.51.100.7'])), 'without X-Anotoki-Origin: the edge');
        same(CLOUDFLARE_EDGE_V6, requestIp(requestFrom(CLOUDFLARE_EDGE_V6, ['CF-Connecting-IP' => '2001:db8:1:2::7'])), 'from an IPv6 edge too');

        // (c) A wrong one: the edge, however near it comes.
        foreach (['another value' => 'not the zone\'s secret', 'the secret cut short' => substr(ORIGIN_SECRET, 0, -1), 'the secret and more' => ORIGIN_SECRET . 'x',
            'in capitals' => strtoupper(ORIGIN_SECRET), 'the secret twice' => ORIGIN_SECRET . ', ' . ORIGIN_SECRET, 'empty' => ''] as $what => $sent) {
            same(CLOUDFLARE_EDGE, requestIp(requestFrom(CLOUDFLARE_EDGE, ['CF-Connecting-IP' => '198.51.100.7', ORIGIN_HEADER => $sent])), "a wrong one ({$what}): the edge");
        }

        // (d) The right one: the visitor.
        same('198.51.100.7', requestIp(requestFrom(CLOUDFLARE_EDGE, ['CF-Connecting-IP' => '198.51.100.7', ORIGIN_HEADER => ORIGIN_SECRET])), 'the right one: the visitor');
        // A header's value never carries the spaces around it (HTTP drops them, and so does the server's
        // reading here) - which is why the file's value is trimmed too (below).
        same('198.51.100.7', requestIp(requestFrom(CLOUDFLARE_EDGE, ['CF-Connecting-IP' => '198.51.100.7', ORIGIN_HEADER => ' ' . ORIGIN_SECRET . "\t"])), 'the spaces around a header\'s value are no part of it');
        same('2001:db8:1:2::7', requestIp(requestFrom(CLOUDFLARE_EDGE_V6, ['CF-Connecting-IP' => '2001:db8:1:2::7', ORIGIN_HEADER => ORIGIN_SECRET])), 'from an IPv6 edge too');
        same('198.51.100.7', requestIp(requestFrom(CLOUDFLARE_EDGE, ['CF-Connecting-IP' => '198.51.100.7', strtolower(ORIGIN_HEADER) => ORIGIN_SECRET])), 'the header\'s name in any case');

        // (e) The right one from outside the ranges: the connection - the secret makes nobody a proxy.
        same(ELSEWHERE, requestIp(requestFrom(ELSEWHERE, ['CF-Connecting-IP' => '198.51.100.7', ORIGIN_HEADER => ORIGIN_SECRET])), 'the right one from outside Cloudflare\'s ranges: the connection');

        // And the header stays pinned: the secret makes no other header believed.
        same(CLOUDFLARE_EDGE, requestIp(requestFrom(CLOUDFLARE_EDGE, ['X-Real-IP' => '198.51.100.7', ORIGIN_HEADER => ORIGIN_SECRET])), 'with the secret, X-Real-IP is still nobody\'s word');
        same(CLOUDFLARE_EDGE, requestIp(requestFrom(CLOUDFLARE_EDGE, ['CF-Connecting-IP' => 'not an address', ORIGIN_HEADER => ORIGIN_SECRET])), 'nor a header that is no address');
    });

    same('198.51.100.7', requestIp(requestFrom(CLOUDFLARE_EDGE, ['CF-Connecting-IP' => '198.51.100.7'])), 'the file gone: the visitor again, as before');
});

test('the attack it stops: a Worker writing a new CF-Connecting-IP into every guess, without the zone\'s secret, is one source - its edge - and the limiter holds; through the zone each visitor is still counted apart', function () {
    makeUser('worker-target', 'worker-target@example.test', PASSWORD);

    withOriginSecret(ORIGIN_SECRET, static function (): void {
        for ($guess = 0; $guess < ATTEMPT_MAX_PER_EMAIL_IP; $guess++) {
            $sent = $guess % 2 === 0 ? [] : [ORIGIN_HEADER => 'a guess at the secret ' . $guess];
            $answer = (new Browser(CLOUDFLARE_EDGE))->request('POST', '/api/login/password', ['json' => ['email' => 'worker-target@example.test', 'password' => "wrong {$guess}"],
                'headers' => ['CF-Connecting-IP' => "198.51.100.{$guess}"] + $sent]);
            same(401, $answer->status, "guess {$guess}: a new address written in, and no secret or a wrong one");
        }
        $refused = (new Browser(CLOUDFLARE_EDGE))->request('POST', '/api/login/password', ['json' => ['email' => 'worker-target@example.test', 'password' => PASSWORD],
            'headers' => ['CF-Connecting-IP' => '198.51.100.250']]);
        same([429, 'too_many_attempts'], [$refused->status, $refused->code()], 'the eleventh, from yet another address it wrote: refused - the right password too');
        same([CLOUDFLARE_EDGE], attemptAddresses('worker-target@example.test'), 'every guess counted under the edge it came from, none under what it wrote');

        // The account's owner comes through the zone itself: a visitor of their own, not the edge the Worker spent.
        $owner = (new Browser(CLOUDFLARE_EDGE))->request('POST', '/api/login/password', ['json' => ['email' => 'worker-target@example.test', 'password' => PASSWORD],
            'headers' => ['CF-Connecting-IP' => '198.51.100.77', ORIGIN_HEADER => ORIGIN_SECRET]]);
        same(200, $owner->status, 'the owner, through the zone, signs in: ' . $owner->body);
    });
});

test('with the secret, what the audit log keeps, a session shows, and the library\'s limits and error log count is one reading: the visitor through the zone, the edge without it', function () {
    $userId = makeUser('zoned', 'zoned@example.test', PASSWORD);

    withOriginSecret(ORIGIN_SECRET, static function () use ($userId): void {
        $through = ['CF-Connecting-IP' => '198.51.100.88', ORIGIN_HEADER => ORIGIN_SECRET];
        $browser = new Browser(CLOUDFLARE_EDGE);
        same(200, $browser->request('POST', '/api/login/password', ['json' => ['email' => 'zoned@example.test', 'password' => PASSWORD], 'headers' => $through])->status, 'signed in through the zone');
        $session = iamDb()->prepare('SELECT ip FROM sessions WHERE user_id = ? ORDER BY id DESC LIMIT 1');
        $session->execute([$userId]);
        same('198.51.100.88', $session->fetchColumn(), 'the session shows the visitor');

        $entry = iamDb()->prepare("SELECT ip FROM anotoki_audit_log WHERE action = 'account.username_changed' AND actor_id = ? ORDER BY id DESC LIMIT 1");
        same(200, $browser->request('PUT', '/api/account/profile', ['json' => ['username' => 'zoned2'], 'headers' => $through])->status, 'a change through the zone');
        $entry->execute([$userId]);
        same('198.51.100.88', $entry->fetchColumn(), 'kept with the visitor\'s address');
        same(200, $browser->request('PUT', '/api/account/profile', ['json' => ['username' => 'zoned3'], 'headers' => ['CF-Connecting-IP' => '198.51.100.88']])->status,
            'a change without the secret - the session\'s cookie in a Worker\'s hands, say');
        $entry->execute([$userId]);
        same(CLOUDFLARE_EDGE, $entry->fetchColumn(), 'kept with the edge\'s: the written address is nobody\'s word');

        // The library's own limits (the pages' errors) and the error log's hash read through the admin context.
        same(CLOUDFLARE_EDGE, iamAdminContext()->clientKey(requestFrom(CLOUDFLARE_EDGE, ['CF-Connecting-IP' => '198.51.100.88'])), 'the library counts the edge without the secret');
        same('198.51.100.88', iamAdminContext()->clientKey(requestFrom(CLOUDFLARE_EDGE, $through)), 'and the visitor with it');
        same('2001:db8:9:8::/64', iamAdminContext()->clientKey(requestFrom(CLOUDFLARE_EDGE_V6, ['CF-Connecting-IP' => '2001:db8:9:8::1', ORIGIN_HEADER => ORIGIN_SECRET])), 'an IPv6 visitor by its /64');
    });
});

test('config/cloudflare.local.php: its value trimmed; an empty one, one of spaces, or one that is no string is no secret - said in PHP\'s error log by the file\'s name, never a value - and the set-up check never asks for the file', function () {
    withOriginSecret("  \t" . ORIGIN_SECRET . "\n", static function (): void {
        same(ORIGIN_SECRET, iamOriginSecret(), 'trimmed: a header\'s value never starts or ends with a space');
        same('198.51.100.7', requestIp(requestFrom(CLOUDFLARE_EDGE, ['CF-Connecting-IP' => '198.51.100.7', ORIGIN_HEADER => ORIGIN_SECRET])), 'and so it matches the header');
    });

    $log = loggedWhile(static function (): void {
        foreach (['empty' => '', 'spaces' => " \t  ", 'a number' => 12345678901234567890, 'a list' => [ORIGIN_SECRET], 'null' => null] as $what => $value) {
            withOriginSecret($value, static function () use ($what): void {
                same(null, iamOriginSecret(), "{$what}: no secret");
                same('198.51.100.7', requestIp(requestFrom(CLOUDFLARE_EDGE, ['CF-Connecting-IP' => '198.51.100.7'])), "{$what}: Cloudflare's ranges alone, as with no file");
            });
        }
        withCloudflareFile("<?php\n\nreturn ['secret' => " . var_export(ORIGIN_SECRET, true) . "];\n", static function (): void {
            same(null, iamOriginSecret(), 'the secret under another name: none');
        });
    });
    check(str_contains($log, 'config/cloudflare.local.php holds no origin_secret'), 'said in PHP\'s error log: ' . $log);
    check(!str_contains($log, ORIGIN_SECRET), 'and never the value in it');

    same([], iamSetUpProblems(), 'without the file the IAM is set up: it is never asked for');
    withOriginSecret(ORIGIN_SECRET, static function (): void {
        same([], iamSetUpProblems(), 'and with it nothing there changes');
    });
});

/**
 * A cloudflare.local.php that is there but was passed over fails closed: the
 * secret is one no request carries, so a request from Cloudflare's ranges is
 * its edge - without the header, and with the very value the file holds (it
 * was never read) - and from outside the ranges the connection, as ever.
 */
function failsClosed(?string $read, string $what): void
{
    check(is_string($read) && preg_match('/^[0-9a-f]{64}$/D', $read) === 1 && $read !== ORIGIN_SECRET, "{$what}: a secret no request carries, not the file's");
    same($read, iamOriginSecret(), "{$what}: the same one all through the process");
    same(CLOUDFLARE_EDGE, requestIp(requestFrom(CLOUDFLARE_EDGE, ['CF-Connecting-IP' => '198.51.100.7'])), "{$what}: fails closed - from Cloudflare's ranges, the edge");
    same(CLOUDFLARE_EDGE, requestIp(requestFrom(CLOUDFLARE_EDGE, ['CF-Connecting-IP' => '198.51.100.7', ORIGIN_HEADER => ORIGIN_SECRET])), "{$what}: the edge with the file's own value too");
    same(ELSEWHERE, requestIp(requestFrom(ELSEWHERE, ['CF-Connecting-IP' => '198.51.100.7'])), "{$what}: from elsewhere, the connection");
}

test('a local file that prints - no <?php line, a byte-order mark or a line before it, text after a closing tag - is passed over and never printed: the origin secret\'s fails closed, every request from Cloudflare\'s ranges its edge, and a required one leaves the IAM not set up; its fault said by its byte count, never its text', function () {
    $secret = var_export(['origin_secret' => ORIGIN_SECRET], true);
    $cases = [
        'no <?php line' => ["return {$secret};\n", strlen("return {$secret};\n")],
        'a byte-order mark' => ["\xEF\xBB\xBF<?php\n\nreturn {$secret};\n", 3],
        'a line before its <?php' => ["\n<?php\n\nreturn {$secret};\n", 1],
        'text after a closing tag' => ["<?php \$kept = 1; ?>\n" . ORIGIN_SECRET . "\n<?php return {$secret};\n", strlen(ORIGIN_SECRET) + 1],
    ];

    foreach ($cases as $what => [$contents, $bytes]) {
        // Each case said afresh: what was said today is not said again (sayOncePerDay()'s stamps).
        array_map('unlink', glob(iamStorageDir() . '/cache/said-*.logged') ?: []);
        $log = loggedWhile(static function () use ($contents, $bytes, $what): void {
            withCloudflareFile($contents, static function () use ($bytes, $what): void {
                [$read, $printed] = printedBy(static fn(): ?string => iamOriginSecret());
                same('', $printed, "{$what}: nothing of it printed");
                same("prints {$bytes} bytes of its own (text outside its PHP: no <?php line, a byte-order mark or a line before it, text after a closing tag)", localConfigProblem('cloudflare'), "{$what}: its fault, by its bytes");
                failsClosed($read, $what);
            });
        });
        check(str_contains($log, "config/cloudflare.local.php prints {$bytes} bytes of its own"), "{$what}: said in PHP's error log: " . $log);
        check(str_contains($log, 'config/cloudflare.local.php cannot be read cleanly: no request carries its secret'), "{$what}: and that it fails closed: " . $log);
        check(!str_contains($log, ORIGIN_SECRET), "{$what}: never the secret");
    }

    $log = loggedWhile(static function (): void {
        withCloudflareFile("<?php\n\nreturn ['origin_secret' => '" . ORIGIN_SECRET . "' '" . ORIGIN_SECRET . "'];\n", static function (): void {
            [$read, $printed] = printedBy(static fn(): ?string => iamOriginSecret());
            same('', $printed, 'one PHP cannot read: nothing printed');
            same('could not be read (ParseError on line 3)', localConfigProblem('cloudflare'), 'said by the kind of error and its line');
            failsClosed($read, 'one PHP cannot read');
        });
    });
    check(str_contains($log, 'config/cloudflare.local.php could not be read (ParseError on line 3)') && !str_contains($log, ORIGIN_SECRET), 'logged without the parse error\'s words, which quote the file: ' . $log);

    same('198.51.100.7', requestIp(requestFrom(CLOUDFLARE_EDGE, ['CF-Connecting-IP' => '198.51.100.7'])), 'the file gone: Cloudflare\'s ranges alone again, as with no file');

    // A required file, the same: the master key with a byte-order mark before it.
    $file = localConfigPath('secret');
    $key = (string) file_get_contents($file);
    $hex = trim((string) localConfig('secret'));
    file_put_contents($file, "\xEF\xBB\xBF" . $key);
    forgetLocalConfig();
    try {
        [$problems, $printed] = printedBy(static fn(): array => iamSetUpProblems());
        same('', $printed, 'the key is not printed');
        same(['config/secret.local.php prints 3 bytes of its own (text outside its PHP: no <?php line, a byte-order mark or a line before it, text after a closing tag)'], $problems,
            'the set-up check names the file and its fault, never a value');
        [$answer, $printed] = printedBy(static fn(): TestResponse => (new Browser())->get('/api/public/config'));
        same('', $printed, 'nothing printed ahead of the answer');
        same([503, 'not_set_up'], [$answer->status, $answer->code()], 'the IAM is not set up while it prints');
        check(!str_contains($answer->body, $hex) && str_starts_with($answer->body, '{'), 'and the answer holds nothing of it');
    } finally {
        file_put_contents($file, $key);
        forgetLocalConfig();
    }
    same([], iamSetUpProblems(), 'corrected, the IAM is set up again');
});

finishTests();
