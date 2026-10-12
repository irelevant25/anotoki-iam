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

finishTests();
