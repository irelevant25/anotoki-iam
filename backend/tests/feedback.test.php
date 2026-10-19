<?php

/**
 * Feedback on the IAM (the owner's decision of 2026-10-19): the anotoki
 * family's feedback form - `POST /api/feedback`, the library's
 * FeedbackRoutes::public() in routes/login.php - and the admin panel's
 * Feedback page's routes (FeedbackRoutes::admin(), routes/admin.php), on the
 * library's table anotoki_feedback (its set anotoki_admin, 002).
 *
 * Who sends: anybody - the account this browser's session names, by its id
 * alone and only read (optionalSession()), or nobody; never what a body says;
 * "without my name", neither the account nor an address. From where: the
 * IAM's own pages alone - the CSRF header and the IAM's Origin, never a CORS
 * answer. How many: the library's limits - 5 an hour from one sender, a
 * signed-in person by their account and anybody else by their address's
 * bucket (an IPv6 address its /64, the visitor's through Cloudflare's ranges
 * and never a header from anywhere else), 50 a day from the visitors
 * together and as many apart from the people signed in. Who reads: the IAM's
 * ADMIN alone, every change audited. What an account's deletion leaves: the
 * words, never the reply address or the browser's name. And what is kept no
 * longer goes at housekeeping, once a day.
 */

declare(strict_types=1);

require __DIR__ . '/lib.php';

use Anotoki\Lib\Support\AfterResponse;

bootTests();

const PASSWORD = 'correct horse';
const FEEDBACK = '/api/feedback';

/** A connection from one of Cloudflare's IPv6 edges (2606:4700::/32), which names its visitor. */
const CLOUDFLARE_EDGE_V6 = '2606:4700:10::6814:7';

$adminId = makeUser('admin', 'admin@example.test');
grantRole($adminId, 'iam', 'ADMIN');
$secondId = makeUser('second', 'second@example.test');
grantRole($secondId, 'iam', 'ADMIN');
$memberId = makeUser('member', 'member@example.test');
$siteAdminId = makeUser('siteadmin', 'siteadmin@example.test');
grantRole($siteAdminId, 'genshin', 'ADMIN');
grantRole($siteAdminId, 'genshin', 'EDITOR');

/** A browser signed in with a password, from an address of its own. */
function signedIn(string $email, string $ip): Browser
{
    $browser = new Browser($ip);
    $answer = $browser->signIn($email, PASSWORD);
    same(200, $answer->status, "$email signs in: " . $answer->body);

    return $browser;
}

/** A message as the family's form sends one - `$extra` over its fields. */
function message(string $text, array $extra = []): array
{
    return $extra + ['kind' => 'bug', 'message' => $text, 'page' => '/account/security', 'language' => 'en', 'website' => ''];
}

/** The kept messages whose words begin so, oldest first, as their rows are. */
function kept(string $start): array
{
    $statement = iamDb()->prepare('SELECT * FROM anotoki_feedback WHERE message LIKE ? ORDER BY id');
    $statement->execute([addcslashes($start, '%_\\') . '%']);

    return $statement->fetchAll(PDO::FETCH_ASSOC);
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

/** The newest audit entry of an action: [actor_id, target_type, target_id, details (its keys in order), ip]. */
function lastEntry(string $action): ?array
{
    $statement = iamDb()->prepare('SELECT actor_id, target_type, target_id, details, ip FROM anotoki_audit_log WHERE action = ? ORDER BY id DESC LIMIT 1');
    $statement->execute([$action]);
    $row = $statement->fetch();
    if ($row === false) {
        return null;
    }
    // jsonb keeps a map's keys in an order of its own.
    $details = (array) json_decode((string) $row['details'], true);
    ksort($details);

    return [$row['actor_id'] === null ? null : (int) $row['actor_id'], $row['target_type'], $row['target_id'], $details, $row['ip']];
}

/** Housekeeping as the next request after an hour runs it. */
function runHousekeeping(): void
{
    @unlink(iamStorageDir() . '/cache/housekeeping.stamp');
    iamHousekeeping();
}

// ─────────────────────────────────────────────────────────────────────────────

test('anybody may send: a visitor as nobody, the person signed in by their account\'s id - read from the session alone, never a body - and "without my name" neither', function () {
    global $memberId, $adminId;

    $visitor = new Browser('10.70.0.1');
    $answer = $visitor->post(FEEDBACK, message('A visitor: the sign-in page jumps', ['email' => 'visitor@example.test', 'user_id' => $adminId, 'id' => $adminId]), [
        'headers' => ['User-Agent' => 'Visitor Browser/1.0'],
    ]);
    same([201, ['ok' => true]], [$answer->status, $answer->json()], 'taken - and no id handed back');
    $row = kept('A visitor:')[0] ?? null;
    check($row !== null, 'kept');
    same(
        ['bug', null, 'visitor@example.test', '/account/security', 'en', 'Visitor Browser/1.0', 'new'],
        [$row['kind'], $row['user_id'], $row['contact_email'], $row['page'], $row['language'], $row['user_agent'], $row['status']],
        'nobody\'s, whatever ids its body named; its reply address as the form sent it, its page, language and browser',
    );
    foreach ($row as $column => $value) {
        check(!str_contains((string) $value, '10.70.0.1'), "nothing of where it came from: {$column}");
    }

    $member = signedIn('member@example.test', '10.70.0.2');
    $answer = $member->post(FEEDBACK, message('A member: an idea', ['kind' => 'idea', 'email' => 'member@example.test', 'user_id' => $adminId]));
    same(201, $answer->status, 'a signed-in person\'s: ' . $answer->body);
    same('', $answer->header('Set-Cookie'), 'the session only read: no cookie set or cleared');
    $row = kept('A member: an idea')[0] ?? [];
    same(
        [$memberId, 'idea', 'member@example.test'],
        [(int) $row['user_id'], $row['kind'], $row['contact_email']],
        'the account\'s id - the session\'s, not the body\'s - and the reply address it sent',
    );

    same(201, $member->post(FEEDBACK, message('A member, without my name', ['email' => 'member@example.test', 'anonymous' => true]))->status, 'without a name');
    $row = kept('A member, without my name')[0] ?? [];
    same([null, null], [$row['user_id'], $row['contact_email']], 'neither the account nor the address');

    // A token in a page's address - a reset link's - never goes with it: the page is a path.
    same(201, $visitor->post(FEEDBACK, message('A visitor: from a link', ['page' => '/reset-password?token=sEcReTtOkEn#x']))->status, 'from a page with a query');
    same('/reset-password', kept('A visitor: from a link')[0]['page'] ?? null, 'kept as its path alone');
    check(!str_contains(json_encode(kept('')), 'sEcReTtOkEn'), 'the token nowhere');

    // The robots' field filled in: the answer of a message kept, word for word, and nothing kept.
    $answer = $visitor->post(FEEDBACK, message('A robot: buy now', ['website' => 'https://spam.example']));
    same([201, ['ok' => true]], [$answer->status, $answer->json()], 'the robot told what a person is told');
    same([], kept('A robot:'), 'and nothing of it kept');
});

test('a session that lets nobody in sends as nobody: an account switched off is no sender', function () {
    global $memberId;
    $member = signedIn('member@example.test', '10.70.0.3');
    iamDb()
        ->prepare('UPDATE users SET disabled = TRUE WHERE id = ?')
        ->execute([$memberId]);
    try {
        same(201, $member->post(FEEDBACK, message('Switched off, still writing'))->status, 'taken as anybody\'s');
    } finally {
        iamDb()
            ->prepare('UPDATE users SET disabled = FALSE WHERE id = ?')
            ->execute([$memberId]);
    }
    $rows = kept('Switched off, still writing');
    same([1, null], [count($rows), $rows[0]['user_id'] ?? null], 'kept, nobody\'s');
});

test('the IAM\'s own pages alone: the CSRF header and the IAM\'s Origin, never a CORS answer - and what the route refuses, in the IAM\'s error body', function () {
    $before = count(kept(''));
    $browser = new Browser('10.70.1.1');
    same([403, 'csrf'], [($r = $browser->post(FEEDBACK, message('No header'), ['csrf' => false]))->status, $r->code()], 'without the CSRF header');
    same(
        [403, 'csrf'],
        [($r = $browser->post(FEEDBACK, message('A site\'s page'), ['headers' => ['Origin' => 'http://localhost:4200']]))->status, $r->code()],
        'from a site\'s origin, header and all',
    );
    same([403, 'csrf'], [($r = $browser->request('POST', '/%61pi/feedback', ['json' => message('Encoded'), 'csrf' => false]))->status, $r->code()], 'a path only spelt otherwise is no way past it');
    $preflight = $browser->request('OPTIONS', FEEDBACK, [
        'headers' => ['Origin' => 'http://localhost:4200', 'Access-Control-Request-Method' => 'POST', 'Access-Control-Request-Headers' => 'content-type, x-iam-request'],
    ]);
    same(['', ''], [$preflight->header('Access-Control-Allow-Origin'), $preflight->header('Access-Control-Allow-Headers')], 'a preflight is answered nothing that lets a page elsewhere send');
    same('', $browser->post(FEEDBACK, message('From here'), ['headers' => ['Origin' => 'http://localhost:4200']])->header('Access-Control-Allow-Origin'), 'nor an answer');
    same($before, count(kept('')), 'nothing kept');

    same([415, 'unsupported_media_type'], [($r = $browser->request('POST', FEEDBACK, ['form' => message('A form')]))->status, $r->code()], 'a form');
    same([400, 'invalid_json'], [($r = $browser->request('POST', FEEDBACK, ['body' => '{"message": ', 'headers' => ['Content-Type' => 'application/json']]))->status, $r->code()], 'no JSON');
    same([413, 'too_large'], [($r = $browser->post(FEEDBACK, message(str_repeat('x', 70000))))->status, $r->code()], 'more than 64 KB');
    same([422, 'message_required'], [($r = $browser->post(FEEDBACK, message('   ')))->status, $r->code()], 'no message');
    same([422, 'too_long'], [($r = $browser->post(FEEDBACK, message(str_repeat('é', 10001))))->status, $r->code()], 'a message past 10,000 characters');
    same([422, 'kind_unknown'], [($r = $browser->post(FEEDBACK, message('A kind of its own', ['kind' => 'admin'])))->status, $r->code()], 'a kind the IAM does not take');
    same(
        [422, 'email_invalid'],
        [($r = $browser->post(FEEDBACK, message('A reply address with more in it', ['email' => 'me@example.test?bcc=all@example.test'])))->status, $r->code()],
        'a reply address a mail link would read as more than an address',
    );
    check(is_string($r->json()['error'] ?? null), 'each in the IAM\'s body, {error, code}');
    same($before, count(kept('')), 'still nothing kept');
});

test('one sender, 5 an hour: a visitor by their address, a signed-in person by their account - and a message "without my name" counted by the address, never the account', function () {
    $countedId = makeUser('counted', 'counted@example.test');
    $visitor = new Browser('10.70.2.1');
    for ($i = 1; $i <= 5; $i++) {
        same(201, $visitor->post(FEEDBACK, message("The visitor's message {$i}", ['email' => 'counted-visitor@example.test']))->status, "the visitor's message {$i}");
    }
    $refused = $visitor->post(FEEDBACK, message('One too many from the visitor'));
    same([429, 'too_many'], [$refused->status, $refused->code()], 'the 6th within the hour: refused');
    $wait = (int) ($refused->json()['retry_after'] ?? 0);
    check($wait > 0 && $wait <= 3600 && (int) $refused->header('Retry-After') === $wait, 'saying how long to wait: ' . $refused->header('Retry-After'));
    same([], kept('One too many'), 'and not kept');
    same(201, (new Browser('10.70.2.2'))->post(FEEDBACK, message('Another visitor'))->status, 'another address is another sender');

    $counted = signedIn('counted@example.test', '10.70.2.1');
    for ($i = 1; $i <= 5; $i++) {
        same(201, $counted->post(FEEDBACK, message("The signed-in person's message {$i}"))->status, "the signed-in person's message {$i}, from the same address: their own budget");
    }
    same([429, 'too_many'], [($r = $counted->post(FEEDBACK, message('One too many from the signed-in person')))->status, $r->code()], 'their 6th: refused');
    same([$countedId], array_values(array_unique(array_map('intval', array_column(kept("The signed-in person's message"), 'user_id')))), 'each of theirs kept under their account');
    same(
        201,
        signedIn('counted@example.test', '10.70.2.4')->post(FEEDBACK, message('Nameless, the same person', ['anonymous' => true]))->status,
        'their message without a name, from an address of its own: not their account\'s - taken',
    );

    // Their address's bucket is the visitor's, spent: a message without their name is counted there - nothing in the count ties it to the account.
    same(
        [429, 'too_many'],
        [($r = signedIn('second@example.test', '10.70.2.1')->post(FEEDBACK, message('Nameless', ['anonymous' => true])))->status, $r->code()],
        'without a name, from a spent address: refused',
    );
    same(201, signedIn('second@example.test', '10.70.2.3')->post(FEEDBACK, message('Nameless from elsewhere', ['anonymous' => true]))->status, 'from an address of its own: taken');

    $log = errorLogText();
    foreach (['counted-visitor@example.test', "The visitor's message", 'One too many', '10.70.2.1'] as $what) {
        check(!str_contains($log, $what), "nothing of a message, and no address, in the error log: {$what}");
    }
});

test(
    'an IPv6 visitor is one sender by their /64 - through Cloudflare\'s edge, the network walked address by address stays one sender - and a header from anywhere else is never believed',
    function () {
        $send = static fn(string $visitor, string $text): TestResponse => (new Browser(CLOUDFLARE_EDGE_V6))->post(FEEDBACK, message($text), ['headers' => ['CF-Connecting-IP' => $visitor]]);
        for ($i = 1; $i <= 5; $i++) {
            same(201, $send(sprintf('2001:db8:70:bb:%x::1', $i), "From the network {$i}")->status, "an address of one /64, message {$i}");
        }
        same([429, 'too_many'], [($r = $send('2001:db8:70:bb:ffff:ffff:ffff:fffe', 'One more address of it'))->status, $r->code()], 'yet another address of the same /64: the same sender, refused');
        same(201, $send('2001:db8:70:bc::1', 'The neighbouring network')->status, 'the next /64 is another sender');

        // From outside Cloudflare's ranges the header is the sender's own words: a new address written into every message is still the connection.
        for ($i = 1; $i <= 5; $i++) {
            same(201, (new Browser('10.70.3.1'))->post(FEEDBACK, message("A forger's message {$i}"), ['headers' => ['CF-Connecting-IP' => "198.51.100.{$i}"]])->status, "the forger's message {$i}");
        }
        same(
            [429, 'too_many'],
            [($r = (new Browser('10.70.3.1'))->post(FEEDBACK, message('The forger again'), ['headers' => ['CF-Connecting-IP' => '198.51.100.99']]))->status, $r->code()],
            'a sixth, under yet another address it wrote: refused - counted as its connection',
        );
    },
);

test(
    'a deleted account\'s messages keep their words, never the reply address or the browser - its own, and a visitor\'s that named its address - and what a request on its way wrote after is swept',
    function () {
        $pdo = iamDb();
        $goneId = makeUser('gone', 'gone@example.test');
        $otherId = makeUser('other', 'other@example.test');

        $gone = new Browser('10.70.4.1');
        same(200, $gone->signIn('gone@example.test', PASSWORD)->status, 'the person signs in');
        $theirs = ['headers' => ['User-Agent' => 'Gone Browser/1.0']];
        same(201, $gone->post(FEEDBACK, message('Gone: with my address', ['email' => 'gone@example.test']), $theirs)->status, 'a message with their address');
        same(201, $gone->post(FEEDBACK, message('Gone: without an address'), $theirs)->status, 'one without an address');
        same(201, $gone->post(FEEDBACK, message('Gone: without my name', ['anonymous' => true]), $theirs)->status, 'one without their name');
        same(
            201,
            (new Browser('10.70.4.2'))->post(FEEDBACK, message('Gone, signed out: answer me here', ['email' => 'GONE@Example.Test']), ['headers' => ['User-Agent' => 'Phone Browser/2.0']])->status,
            'one sent signed out, naming their address',
        );
        same(
            201,
            signedIn('other@example.test', '10.70.4.3')->post(FEEDBACK, message('Other: mine', ['email' => 'other@example.test']), ['headers' => ['User-Agent' => 'Other Browser/3.0']])->status,
            'somebody else\'s',
        );

        same(200, $gone->post('/api/account/deletion', ['password' => PASSWORD])->status, 'the deletion asked for');
        $pdo->prepare("UPDATE users SET deletion_requested_at = CURRENT_TIMESTAMP - INTERVAL '30 days 1 minute', deletion_due_at = CURRENT_TIMESTAMP - INTERVAL '1 minute' WHERE id = ?")->execute([
            $goneId,
        ]);
        runHousekeeping();
        check(userById($pdo, $goneId)['anonymized_at'] !== null, 'deleted when due');

        // A message as its row is: [its words, the sender's id, the reply address, the browser]; null where there is none.
        $row = static function (string $start): ?array {
            $found = kept($start)[0] ?? null;

            return $found === null ? null : [$found['message'], $found['user_id'] === null ? null : (int) $found['user_id'], $found['contact_email'], $found['user_agent']];
        };
        same(['Gone: with my address', $goneId, null, null], $row('Gone: with my address'), 'their own: the words, under the placeholder\'s id - no address, no browser');
        same(['Gone: without an address', $goneId, null, null], $row('Gone: without an address'), 'the browser gone from one without an address too');
        same(['Gone: without my name', null, null, 'Gone Browser/1.0'], $row('Gone: without my name'), 'one without their name ties nothing to them, and stays as sent');
        same(
            ['Gone, signed out: answer me here', null, null, null],
            $row('Gone, signed out: answer me here'),
            'one sent signed out with their address, whatever its case: the address and the browser gone',
        );
        same(['Other: mine', $otherId, 'other@example.test', 'Other Browser/3.0'], $row('Other: mine'), 'somebody else\'s as it was');

        $admin = signedIn('admin@example.test', '10.70.4.9');
        $listed = array_values(
            array_filter(
                $admin->get('/api/admin/feedback?search=' . rawurlencode('Gone: with my'))->json()['items'] ?? [],
                static fn(array $item): bool => $item['message'] === 'Gone: with my address',
            ),
        );
        same(
            ['deleted-' . $goneId, true],
            [$listed[0]['user_name'] ?? null, array_key_exists('contact_email', $listed[0] ?? []) && $listed[0]['contact_email'] === null],
            'the Feedback page names the placeholder, never the name it had, and no address',
        );

        // A message a request on its way wrote a moment after the account went: the next run sweeps it.
        $pdo->prepare("INSERT INTO anotoki_feedback (kind, message, contact_email, user_id, user_agent) VALUES ('other', 'Gone: late', 'late@example.test', ?, 'Late Browser')")->execute([$goneId]);
        runHousekeeping();
        same(['Gone: late', $goneId, null, null], $row('Gone: late'), 'swept: the words stay, the address and the browser go');
    },
);

test('who may read: nobody 401, an account or a site\'s ADMIN and EDITOR 403, an administrator on the way out 403 - the IAM\'s ADMIN alone, every write with the CSRF header', function () {
    global $secondId;
    $id = (int) (kept('A member: an idea')[0]['id'] ?? 0);
    check($id > 0, 'a message to point the routes at');
    $routes = [
        ['GET', '/api/admin/feedback'],
        ['GET', '/api/admin/feedback/facets'],
        ['GET', '/api/admin/feedback/unread'],
        ['GET', "/api/admin/feedback/{$id}"],
        ['PUT', "/api/admin/feedback/{$id}/status"],
        ['DELETE', "/api/admin/feedback/{$id}"],
    ];
    $call = static fn(Browser $browser, string $method, string $path, array $options = []): TestResponse => $browser->request($method, $path, ['json' => ['status' => 'spam']] + $options);

    $nobody = new Browser('10.70.5.1');
    $member = signedIn('member@example.test', '10.70.5.2');
    $siteAdmin = signedIn('siteadmin@example.test', '10.70.5.3');
    $leaving = signedIn('second@example.test', '10.70.5.4');
    iamDb()
        ->prepare("UPDATE users SET deletion_requested_at = CURRENT_TIMESTAMP, deletion_due_at = CURRENT_TIMESTAMP + INTERVAL '30 days' WHERE id = ?")
        ->execute([$secondId]);
    try {
        foreach ($routes as [$method, $path]) {
            same([401, 'no_session'], [($r = $call($nobody, $method, $path))->status, $r->code()], "nobody: $method $path");
            same([403, 'forbidden'], [($r = $call($member, $method, $path))->status, $r->code()], "an account: $method $path");
            same([403, 'forbidden'], [($r = $call($siteAdmin, $method, $path))->status, $r->code()], "a site's ADMIN and EDITOR: $method $path");
            same([403, 'account_deletion_pending'], [($r = $call($leaving, $method, $path))->status, $r->code()], "an administrator whose deletion is pending: $method $path");
        }
    } finally {
        iamDb()
            ->prepare('UPDATE users SET deletion_requested_at = NULL, deletion_due_at = NULL WHERE id = ?')
            ->execute([$secondId]);
    }

    $admin = signedIn('admin@example.test', '10.70.5.5');
    foreach ($routes as [$method, $path]) {
        if ($method === 'GET') {
            continue;
        }
        same([403, 'csrf'], [($r = $call($admin, $method, $path, ['csrf' => false]))->status, $r->code()], "without the CSRF header: $method $path");
        same([403, 'csrf'], [($r = $call($admin, $method, $path, ['headers' => ['Origin' => 'https://elsewhere.example.test']]))->status, $r->code()], "from another origin: $method $path");
    }
    same(['new', 1], [kept('A member: an idea')[0]['status'] ?? null, count(kept('A member: an idea'))], 'and the message as it was: nothing refused did anything');
});

test('the IAM\'s ADMIN reads, marks and deletes - each sender by name, a status change and a delete audited, the delete inside its own transaction', function () {
    global $adminId, $memberId;
    $admin = signedIn('admin@example.test', '10.70.6.1');
    $pdo = iamDb();
    $new = static fn(): int => (int) $pdo->query("SELECT count(*) FROM anotoki_feedback WHERE status = 'new'")->fetchColumn();

    same([200, ['unread' => $new()]], [($r = $admin->get('/api/admin/feedback/unread'))->status, $r->json()], 'how many nobody opened yet');
    $page = $admin->get('/api/admin/feedback?status=new&search=' . rawurlencode('A member: an idea'));
    same([200, 1, 25, 1], [$page->status, $page->json()['page'] ?? null, $page->json()['pageSize'] ?? null, $page->json()['total'] ?? null], 'a page of 25, filtered');
    $item = $page->json()['items'][0] ?? [];
    same([$memberId, 'member', 'idea', 'member@example.test', null], [$item['user_id'], $item['user_name'], $item['kind'], $item['contact_email'], $item['handled_by_name']], 'the sender by name');
    $facets = $admin->get('/api/admin/feedback/facets')->json();
    same([['bug', 'idea', 'other'], $new()], [$facets['kinds'] ?? null, $facets['byStatus']['new'] ?? null], 'the page\'s choices and counts');

    $id = (int) $item['id'];
    $before = $new();
    same([200, 'read'], [($r = $admin->get("/api/admin/feedback/{$id}"))->status, $r->json()['message']['status'] ?? null], 'opened: read');
    same($before - 1, $new(), 'one fewer to read');

    $marked = $admin->put("/api/admin/feedback/{$id}/status", ['status' => 'resolved']);
    same(
        [200, 'resolved', $adminId, 'admin'],
        [$marked->status, $marked->json()['message']['status'] ?? null, $marked->json()['message']['handled_by'] ?? null, $marked->json()['message']['handled_by_name'] ?? null],
        'marked as resolved, by whom',
    );
    same([$adminId, 'feedback', (string) $id, ['from' => 'read', 'to' => 'resolved'], '10.70.6.1'], lastEntry('feedback.status_changed'), 'audited: who, what, from and to, the address');
    same([422, 'status_unknown'], [($r = $admin->put("/api/admin/feedback/{$id}/status", ['status' => 'gone']))->status, $r->code()], 'a status that is none');

    $createdAt = $marked->json()['message']['created_at'] ?? null;
    same([200, ['deleted' => true]], [($r = $admin->delete("/api/admin/feedback/{$id}"))->status, $r->json()], 'deleted');
    same([], kept('A member: an idea'), 'gone');
    $entry = lastEntry('feedback.deleted');
    same(
        [$adminId, 'feedback', (string) $id, 'idea', 'resolved', $createdAt, '10.70.6.1'],
        [$entry[0], $entry[1], $entry[2], $entry[3]['kind'] ?? null, $entry[3]['status'] ?? null, $entry[3]['created_at'] ?? null, $entry[4]],
        'audited with what it was and when it was sent',
    );
    check(isset($entry[3]['_txid']), 'written inside the delete\'s own transaction (the library\'s AuditLog)');
    same([404, 'not_found'], [($r = $admin->delete("/api/admin/feedback/{$id}"))->status, $r->code()], 'twice: there is none');
    same([404, 'not_found'], [($r = $admin->get('/api/admin/feedback/999999'))->status, $r->code()], 'one that never was');
});

test('the visitors\' day: 50 messages at most from the visitors together (the library\'s 300 lowered for the IAM) - and as many apart from the people signed in', function () {
    $pdo = iamDb();
    // Every message so far without an account was a visitor's or one without a name, each counted by its address.
    $visitors = static fn(): int => (int) $pdo->query('SELECT count(*) FROM anotoki_feedback WHERE user_id IS NULL')->fetchColumn();
    $refusedFresh = null;
    for ($sender = 1; $sender <= 80 && $refusedFresh === null; $sender++) {
        $browser = new Browser("10.71.{$sender}.1");
        for ($i = 1; $i <= 5; $i++) {
            $answer = $browser->post(FEEDBACK, message('The same thing again'));
            if ($answer->status !== 201) {
                if ($i === 1) {
                    $refusedFresh = $answer;
                }
                break;
            }
        }
    }
    check($refusedFresh !== null, 'a new visitor is refused at last');
    same([429, 'too_many'], [$refusedFresh->status, $refusedFresh->code()], 'too many');
    check((int) ($refusedFresh->json()['retry_after'] ?? 0) > 3600, 'until tomorrow: ' . ($refusedFresh->json()['retry_after'] ?? ''));
    same(50, $visitors(), 'once the visitors together sent 50 today');

    $signedIn = signedIn('second@example.test', '10.71.99.1');
    same(201, $signedIn->post(FEEDBACK, message('A signed-in person, after the visitors\' day'))->status, 'a signed-in person still sends: the people signed in have a day of their own');
});

test('what is kept no longer goes at housekeeping, once a day after the answer: spam 30 days on, resolved 365 - the rest stays', function () {
    $pdo = iamDb();
    $insert = $pdo->prepare("INSERT INTO anotoki_feedback (kind, message, status, handled_at) VALUES ('other', ?, ?, CURRENT_TIMESTAMP - CAST(? AS interval))");
    foreach (
        [
            ['Kept: old spam', 'spam', '31 days'],
            ['Kept: young spam', 'spam', '29 days'],
            ['Kept: old resolved', 'resolved', '366 days'],
            ['Kept: young resolved', 'resolved', '300 days'],
            ['Kept: old read', 'read', '900 days'],
        ]
        as $row
    ) {
        $insert->execute($row);
    }

    // Today's backup counted as made, so the run queues nothing else; the day's prune not claimed yet.
    file_put_contents(iamStorageDir() . '/cache/anotoki-backup-daily.json', json_encode(['date' => gmdate('Y-m-d'), 'status' => 'made']));
    @unlink(iamStorageDir() . '/cache/anotoki-feedback-prune.stamp');
    AfterResponse::shared()->clear();
    runHousekeeping();
    same(['Kept: old spam', 'Kept: young spam', 'Kept: old resolved', 'Kept: young resolved', 'Kept: old read'], array_column(kept('Kept:'), 'message'), 'nothing goes before the answer');
    same(1, AfterResponse::shared()->pending(), 'the prune queued for after it');
    AfterResponse::shared()->run(null, false);
    same(['Kept: young spam', 'Kept: young resolved', 'Kept: old read'], array_column(kept('Kept:'), 'message'), 'spam past 30 days and resolved past 365 gone; the rest kept');

    AfterResponse::shared()->clear();
    runHousekeeping();
    same(0, AfterResponse::shared()->pending(), 'and once a day: the next run queues none');
});

test('while the library\'s table is not there - a build before its migration - the form and the page answer 503, and nothing else breaks', function () {
    $pdo = iamDb();
    $admin = signedIn('admin@example.test', '10.70.8.1');
    $pdo->exec('ALTER TABLE anotoki_feedback RENAME TO anotoki_feedback_away');
    try {
        $answer = (new Browser('10.70.8.2'))->post(FEEDBACK, message('While it is away'));
        same([503, 'feature_unavailable', '60'], [$answer->status, $answer->code(), $answer->header('Retry-After')], 'the form: not open yet');
        same([503, 'feature_unavailable'], [($r = $admin->get('/api/admin/feedback'))->status, $r->code()], 'the page: the same');
        same(200, $admin->get('/api/admin/users')->status, 'the rest of the panel works');
        runHousekeeping();
        same(200, (new Browser('10.70.8.3'))->get('/api/public/config')->status, 'and housekeeping, and the sign-in pages');
    } finally {
        $pdo->exec('ALTER TABLE anotoki_feedback_away RENAME TO anotoki_feedback');
    }
});

finishTests();
