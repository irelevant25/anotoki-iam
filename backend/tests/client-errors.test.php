<?php

/**
 * The pages' own errors (anotoki-lib 0.5.0; the owner's decision of
 * 2026-10-11: every site's error log holds its pages' errors too):
 * `POST /api/client-errors`, the library's ErrorLogRoutes::client() in
 * routes/login.php, whose browser half is @anotoki/lib/error-report.
 *
 * What it writes - `source: client`, every text redacted: by the library's
 * rules, by the IAM's names of secrets (IAM_SECRET_NAMES, which the library
 * takes) and by its own prefixes and cookies (iamClientErrorRedaction(), as on
 * every way into the error log); who may send - the IAM's own pages alone: the
 * CSRF header and the IAM's Origin, never a CORS answer; and how many - at most
 * 20 an hour from one sender (a signed-in person by account, a visitor by the
 * bucket of their address: an IPv6 address's /64), 500 a day from the
 * visitors together and as many from the people signed in.
 */

declare(strict_types=1);

require __DIR__ . '/lib.php';

bootTests();

const PASSWORD = 'correct horse';
const REPORTS = '/api/client-errors';

/** A connection from one of Cloudflare's IPv6 edges (2606:4700::/32), which names its visitor. */
const CLOUDFLARE_EDGE_V6 = '2606:4700:10::6814:7';

/** A value of a secret's alphabet with no digit in it: no token-like run to the library's redactor. */
function lettersOnly(int $length = 43): string
{
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz';
    $out = '';
    for ($i = 0; $i < $length; $i++) {
        $out .= $alphabet[random_int(0, strlen($alphabet) - 1)];
    }

    return $out;
}

/** The error log's lines a page reported, decoded. */
function clientLines(): array
{
    $lines = [];
    foreach (glob(iamStorageDir() . '/logs/errors-*.jsonl') ?: [] as $file) {
        foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $entry = json_decode($line, true);
            if (($entry['source'] ?? null) === 'client') {
                $lines[] = $entry;
            }
        }
    }

    return $lines;
}

/** Everything the error log's folder holds, as one text. */
function errorLogText(): string
{
    $text = '';
    foreach (glob(iamStorageDir() . '/logs/*') ?: [] as $file) {
        $text .= is_file($file) ? (string) file_get_contents($file) : '';
    }

    return $text;
}

/** A report from a browser, as the family's reporter sends one; every 204 a visitor's report counted. */
function report(Browser $browser, string $message, array $options = []): TestResponse
{
    $answer = $browser->request('POST', REPORTS, ['json' => ['type' => 'Error', 'message' => $message, 'page' => '/account', 'stack' => []]] + $options);
    if ($answer->status === 204 && !isset($browser->cookies[sessionCookieName()])) {
        $GLOBALS['visitorReports'] = ($GLOBALS['visitorReports'] ?? 0) + 1;
    }

    return $answer;
}

$memberId = makeUser('member', 'member@example.test', PASSWORD);

// ─────────────────────────────────────────────────────────────────────────────

test('a page\'s error reaches the error log, written as the page\'s - its words scrubbed by the library\'s rules, the IAM\'s names of secrets and its own prefixes and cookies', function () {
    $planted = [
        'password' => lettersOnly(16),
        'code' => lettersOnly(),
        'recovery' => lettersOnly(),
        'pat' => lettersOnly(),
        'rt' => lettersOnly(),
        'cookie' => lettersOnly(),
        'device' => lettersOnly(),
        'query' => lettersOnly(),
        'credential' => lettersOnly(),
    ];
    $report = [
        'type' => 'TypeError',
        'message' =>
            "Cannot read x: new_password={$planted['password']} code: {$planted['code']} recovery_code=\"{$planted['recovery']}\" " .
            "iam_pat_{$planted['pat']} sent anotoki_iam={$planted['cookie']}; anotoki_iam_device={$planted['device']} credential={$planted['credential']} " .
            'for person@example.test from 198.51.100.9',
        'page' => '/account/security',
        'stack' => ["at render (http://localhost:4300/chunk-AB12.js?v={$planted['query']}:3:4)", "at iam_rt_{$planted['rt']} (http://localhost:4300/main.js:1:2)"],
    ];
    $browser = new Browser('10.50.0.1');
    $answer = $browser->post(REPORTS, $report);
    same([204, 'no-store'], [$answer->status, $answer->header('Cache-Control')], 'taken: ' . $answer->body);
    $GLOBALS['visitorReports'] = 1;

    $line = clientLines()[0] ?? null;
    check($line !== null, 'written');
    same(
        ['client', 'error', 'TypeError', '/account/security', null, REPORTS],
        [$line['source'], $line['level'], $line['type'], $line['path'], $line['user_id'], $line['route']],
        'as the page\'s own (its path the page\'s), by a visitor',
    );
    foreach (['new_password={…}', 'code: {…}', 'recovery_code={…}', 'iam_pat_{…}', 'anotoki_iam={…}', 'anotoki_iam_device={…}', 'credential={…}', '{email}', '{ip}'] as $mark) {
        check(str_contains((string) $line['message'], $mark), "the message keeps {$mark}: " . $line['message']);
    }
    same(2, count($line['trace'] ?? []), 'with the stack\'s frames');
    check(str_contains((string) $line['trace'][1], 'iam_rt_{…}'), 'a frame scrubbed too: ' . $line['trace'][1]);
    check(preg_match('/^[0-9a-f]{12}$/', (string) $line['client']) === 1, 'the sender as a keyed hash of its address');

    $text = errorLogText();
    foreach ($planted as $what => $secret) {
        check(!str_contains($text, $secret), "no {$what} in the error log");
    }
    foreach (['person@example.test', '198.51.100.9', '10.50.0.1'] as $address) {
        check(!str_contains($text, $address), "no {$address} in the error log");
    }
});

test(
    'every text of a report the library writes goes through the IAM\'s own rules first - its type, message, page and stack, a list of frames or one text - and one nested deeper than the library reads is refused whole',
    function () {
        $planted = ['type' => lettersOnly(), 'message' => lettersOnly(), 'page' => lettersOnly(), 'frame' => lettersOnly(), 'text' => lettersOnly(), 'deep' => lettersOnly()];
        $browser = new Browser('10.50.3.1');
        $send = static function (array $report) use ($browser): TestResponse {
            $answer = $browser->post(REPORTS, $report);
            if ($answer->status === 204) {
                $GLOBALS['visitorReports']++;
            }

            return $answer;
        };

        same(
            204,
            $send([
                'type' => "iam_pat_{$planted['type']}",
                'message' => "Failed with iam_rt_{$planted['message']}",
                'page' => "/account/iam_pat_{$planted['page']}",
                'stack' => ["at f (http://localhost:4300/main.js:1:2) iam_pat_{$planted['frame']}"],
                'status' => 0,
            ])->status,
            'a report whose every text holds a token, its stack a list of frames',
        );
        same(
            204,
            $send(['type' => 'Error', 'message' => 'A stack as one text', 'stack' => "Error: A stack as one text\n    at g (http://localhost:4300/main.js:3:4) sent anotoki_iam={$planted['text']}"])
                ->status,
            'one whose stack is one text',
        );
        $deep = ['frame' => "iam_pat_{$planted['deep']}"];
        for ($level = 0; $level < 40; $level++) {
            $deep = [$deep];
        }
        same(
            [400, 'invalid_json'],
            [($r = $send(['type' => 'Error', 'message' => "Deep iam_pat_{$planted['deep']}", 'stack' => $deep]))->status, $r->code()],
            'one nested deeper than the library reads: refused whole, never written unredacted',
        );

        $listed = array_values(array_filter(clientLines(), static fn(array $line): bool => str_starts_with((string) $line['message'], 'Failed with')))[0] ?? null;
        $text = array_values(array_filter(clientLines(), static fn(array $line): bool => $line['message'] === 'A stack as one text'))[0] ?? null;
        check($listed !== null && $text !== null, 'both written');
        same('Error', $listed['type'], 'a type that held a token is no identifier any more: "Error"');
        check(
            str_contains((string) $listed['message'], 'iam_rt_{…}') && str_contains((string) $listed['path'], 'iam_pat_{…}') && str_contains((string) ($listed['trace'][0] ?? ''), 'iam_pat_{…}'),
            'the message, the page and the frame each with the token taken out: ' . json_encode([$listed['message'], $listed['path'], $listed['trace']]),
        );
        check(str_contains((string) ($text['trace'][0] ?? ''), 'anotoki_iam={…}'), 'and a stack of one text, its cookie out: ' . json_encode($text['trace']));
        check(array_filter(clientLines(), static fn(array $line): bool => str_starts_with((string) $line['message'], 'Deep')) === [], 'nothing of the deep one');
        $log = errorLogText();
        foreach ($planted as $what => $secret) {
            check(!str_contains($log, $secret), "nothing of the {$what}'s token in the error log");
        }
    },
);

test('the IAM\'s own pages alone: the CSRF header and the IAM\'s Origin, never a CORS answer - and what the route refuses, refused in the IAM\'s error body', function () {
    $before = count(clientLines());
    $browser = new Browser('10.50.1.1');
    same([403, 'csrf'], [($r = $browser->post(REPORTS, ['message' => 'no header'], ['csrf' => false]))->status, $r->code()], 'without the CSRF header');
    same(
        [403, 'csrf'],
        [($r = $browser->post(REPORTS, ['message' => 'a site\'s page'], ['headers' => ['Origin' => 'http://localhost:4200']]))->status, $r->code()],
        'from a site\'s origin, header and all',
    );
    $preflight = $browser->request('OPTIONS', REPORTS, [
        'headers' => ['Origin' => 'http://localhost:4200', 'Access-Control-Request-Method' => 'POST', 'Access-Control-Request-Headers' => 'content-type, x-iam-request'],
    ]);
    same(['', ''], [$preflight->header('Access-Control-Allow-Origin'), $preflight->header('Access-Control-Allow-Headers')], 'a preflight is answered nothing that lets a page elsewhere send');
    same($before, count(clientLines()), 'nothing written');

    same([415, 'unsupported_media_type'], [($r = $browser->request('POST', REPORTS, ['form' => ['message' => 'a form']]))->status, $r->code()], 'a form: refused, as the route refuses it');
    same([400, 'invalid_json'], [($r = $browser->request('POST', REPORTS, ['body' => '{"message": ', 'headers' => ['Content-Type' => 'application/json']]))->status, $r->code()], 'no JSON');
    same([413, 'too_large'], [($r = $browser->post(REPORTS, ['message' => str_repeat('x', 9000)]))->status, $r->code()], 'more than 8 KB');
    same([422, 'message_required'], [($r = $browser->post(REPORTS, ['type' => 'Error']))->status, $r->code()], 'no message');
    same([422, 'message_required'], [($r = $browser->post(REPORTS, ['iam_pat_x', 'a list']))->status, $r->code()], 'a list');
    check(is_string($r->json()['error'] ?? null), 'each in the IAM\'s body, {error, code}');
    same($before, count(clientLines()), 'still nothing written');
});

test('one sender, 20 reports an hour: a visitor by their address, a signed-in person by their account - named in the line, and counted apart', function () {
    $visitor = new Browser('10.50.2.1');
    for ($i = 1; $i <= 20; $i++) {
        same(204, report($visitor, "The visitor's failure {$i}")->status, "the visitor's report {$i}");
    }
    $refused = report($visitor, 'One too many');
    same([429, 'too_many'], [$refused->status, $refused->code()], 'the 21st within the hour: refused');
    check((int) $refused->header('Retry-After') > 0 && ($refused->json()['retry_after'] ?? 0) > 0, 'saying how long to wait: ' . $refused->header('Retry-After'));
    same(204, report(new Browser('10.50.2.2'), 'Another visitor')->status, 'another address is another sender');

    global $memberId;
    $member = new Browser('10.50.2.1');
    same(200, $member->signIn('member@example.test', PASSWORD)->status, 'a person signs in from the same address');
    for ($i = 1; $i <= 20; $i++) {
        same(204, report($member, "The member's failure {$i}")->status, "the signed-in person's report {$i}: their own budget, not the address's");
    }
    same([429, 'too_many'], [($r = report($member, 'One too many'))->status, $r->code()], 'their 21st: refused');
    $theirs = array_values(array_filter(clientLines(), static fn(array $line): bool => str_starts_with((string) $line['message'], "The member's failure")));
    same([$memberId], array_values(array_unique(array_column($theirs, 'user_id'))), 'each line names the person (an id, nothing else of them)');
    check(!str_contains(errorLogText(), '10.50.2.1'), 'and never the address');
});

test('an IPv6 visitor is one sender by their /64 - through Cloudflare\'s edge, the visitor\'s network walked address by address stays one sender', function () {
    $send = static fn(string $visitor, string $message): TestResponse => report(new Browser(CLOUDFLARE_EDGE_V6), $message, ['headers' => ['CF-Connecting-IP' => $visitor]]);
    for ($i = 1; $i <= 20; $i++) {
        same(204, $send(sprintf('2001:db8:aa:bb:%x::1', $i), "From the network {$i}")->status, "an address of one /64, report {$i}");
    }
    same([429, 'too_many'], [($r = $send('2001:db8:aa:bb:ffff:ffff:ffff:fffe', 'One more address of it'))->status, $r->code()], 'yet another address of the same /64: the same sender, refused');
    same(204, $send('2001:db8:aa:bc::1', 'The neighbouring network')->status, 'the next /64 is another sender');

    $hashes = [];
    foreach (clientLines() as $line) {
        if (str_starts_with((string) $line['message'], 'From the network')) {
            $hashes[] = $line['client'];
        } elseif ($line['message'] === 'The neighbouring network') {
            $neighbour = $line['client'];
        }
    }
    same(1, count(array_unique($hashes)), 'the /64 one hash in the log');
    check(isset($neighbour) && !in_array($neighbour, $hashes, true), 'the next /64 another');
    check(!str_contains(errorLogText(), '2001:db8'), 'and no address of either');
});

test('the visitors\' day: 500 reports at most from the visitors together - and as many apart from the people signed in', function () {
    $sent = $GLOBALS['visitorReports'];
    $refusedFresh = null;
    for ($sender = 1; $sender <= 40 && $refusedFresh === null; $sender++) {
        $browser = new Browser("10.51.{$sender}.1");
        for ($i = 1; $i <= 20; $i++) {
            $answer = report($browser, 'The same failure again');
            if ($answer->status !== 204) {
                if ($i === 1) {
                    $refusedFresh = $answer;
                }
                break;
            }
        }
    }
    check($refusedFresh !== null, 'a new visitor is refused at last: ' . $sent . ' reports before this test');
    same([429, 'too_many'], [$refusedFresh->status, $refusedFresh->code()], 'too many');
    same(500, $GLOBALS['visitorReports'], 'once the visitors together sent 500 today');

    // The member's own hour is spent (the test before): another person's account.
    $otherId = makeUser('other', 'other@example.test', PASSWORD);
    $other = new Browser('10.51.99.2');
    same(200, $other->signIn('other@example.test', PASSWORD)->status, 'another signed-in person');
    same(204, report($other, 'A signed-in person\'s failure')->status, 'still reports: the people signed in have a day of their own');
    $line = array_values(array_filter(clientLines(), static fn(array $line): bool => $line['message'] === 'A signed-in person\'s failure'))[0] ?? null;
    same($otherId, $line['user_id'] ?? null, 'named in the line');
});

finishTests();
