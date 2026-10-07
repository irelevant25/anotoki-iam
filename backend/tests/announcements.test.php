<?php

/**
 * Announcements (migration 013, the owner's decision of 2026-10-06): news an
 * administrator writes from a template and mails to everybody, to the holders
 * of some roles, or to some people, from one of the installation's addresses
 * or one added in the panel - each person in their own language where there is
 * a version in it, links to the anotoki sites only, a link at the end of every
 * one to stop the news, and every announcement kept with how each mail went.
 *
 * Sending is the page's, batch after batch, each mail in its own transaction:
 * a row another sender holds is left to it, a person who stopped the news or
 * was switched off since the list was made is skipped, and Stop stops.
 *
 * 013 reaches installed IAMs too: applied to one built through 012 everybody
 * gets the news. And a build goes up before its migrations are applied: with
 * 013 pending the account page and the rest of the panel work as before, and
 * the announcements answer 503 until Apply.
 */

declare(strict_types=1);

require __DIR__ . '/lib.php';

// This run's own database is a fresh IAM, built from every migration, 013 included.
bootTests();

$adminId = makeUser('admin', 'admin@example.test');
grantRole($adminId, 'iam', 'ADMIN');
$memberId = makeUser('member', 'member@example.test');

const NEWS_MIGRATION = '013_announcements.sql';
const NEWS_AT_REGISTRATION = '014_news_at_registration.sql';
const GOOGLE_TEST_CLIENT = 'iam-test.apps.googleusercontent.com';

/**
 * A Google id token for this IAM, signed by a key it is given as Google's: the
 * key cache in this run's storage, which googleSigningKeys() reads before it
 * would ask Google, and a client id in this run's configuration.
 */
function googleToken(string $subject, string $email, string $name): string
{
    static $key = null;

    if ($key === null) {
        $pair = generateRsaKeyPair();
        $key = openssl_pkey_get_private($pair['private_pem']);
        @mkdir(iamStorageDir() . '/cache', 0700, true);
        file_put_contents(iamStorageDir() . '/cache/google-jwks.json', json_encode([
            'expires_at' => time() + 3600,
            'keys' => ['news-test' => ['kty' => 'RSA', 'kid' => 'news-test', 'n' => $pair['n'], 'e' => $pair['e']]],
        ]));
        writeTestConfig('google', ['client_id' => GOOGLE_TEST_CLIENT]);
        forgetLocalConfig();
    }

    $header = base64UrlEncode((string) json_encode(['alg' => 'RS256', 'kid' => 'news-test', 'typ' => 'JWT']));
    $payload = base64UrlEncode((string) json_encode([
        'iss' => 'https://accounts.google.com', 'aud' => GOOGLE_TEST_CLIENT, 'sub' => $subject, 'email' => $email,
        'email_verified' => true, 'name' => $name, 'iat' => time(), 'exp' => time() + 600,
    ]));
    openssl_sign("{$header}.{$payload}", $signature, $key, OPENSSL_ALGO_SHA256);

    return "{$header}.{$payload}." . base64UrlEncode($signature);
}

/** Whether the account with this address gets the news. */
function newsOfAddress(string $email): bool
{
    $statement = iamDb()->prepare('SELECT news_mail FROM users WHERE email = ?');
    $statement->execute([$email]);

    return asBool($statement->fetchColumn());
}

function admin(): Browser
{
    $browser = new Browser('10.13.0.1');
    same(200, $browser->signIn('admin@example.test', 'correct horse')->status, 'the admin signs in');

    return $browser;
}

/** A draft as the composer sends it: the newVersion template about genshin, in English, from noreply. */
function draft(array $overrides = []): array
{
    return array_replace([
        'template' => 'newVersion',
        'client_id' => 'genshin',
        'link' => 'http://localhost:4200/',
        'sender' => 'noreply',
        'content' => [
            'en' => ['subject' => 'Genshin site 5.2', 'heading' => 'The Genshin site has a new version', 'body' => "New characters are in.\n\nCome and see.", 'button' => 'Open the site'],
        ],
    ], $overrides);
}

/** Makes accounts in bulk, confirmed, English, with no password: their ids. */
function readers(string $prefix, int $count, string $language = 'en'): array
{
    $statement = iamDb()->prepare(
        "INSERT INTO users (username, email, email_confirmed, language)
         SELECT ? || g, ? || g || '@example.test', TRUE, ? FROM generate_series(1, ?) g RETURNING id"
    );
    $statement->execute([$prefix, $prefix, $language, $count]);

    return array_map('intval', $statement->fetchAll(PDO::FETCH_COLUMN));
}

/** The whole newest message to an address: its headers, subject, and the decoded plain and HTML parts. */
function newsMailTo(string $email): ?array
{
    $files = glob(testEnvironment()['mail'] . DIRECTORY_SEPARATOR . '*.eml') ?: [];
    sort($files);

    foreach (array_reverse($files) as $file) {
        $raw = (string) file_get_contents($file);
        if (!str_contains($raw, "To: $email\n")) {
            continue;
        }
        [$head, $body] = explode("\n\n", $raw, 2);
        $headers = [];
        foreach (explode("\n", $head) as $line) {
            [$name, $value] = explode(': ', $line, 2) + [1 => ''];
            $headers[$name] = $value;
        }
        $parts = [];
        if (preg_match('/boundary="([^"]+)"/', $headers['Content-Type'] ?? '', $boundary)) {
            foreach (explode('--' . $boundary[1], $body) as $part) {
                if (preg_match('/^\nContent-Type: (text\/(?:plain|html)); charset=UTF-8\nContent-Transfer-Encoding: base64\n\n(.*)$/s', $part, $found)) {
                    $parts[$found[1]] = base64_decode(str_replace("\n", '', $found[2]), true);
                }
            }
        }

        return ['headers' => $headers, 'subject' => $headers['Subject'] ?? '', 'text' => $parts['text/plain'] ?? null, 'html' => $parts['text/html'] ?? null, 'raw' => $raw];
    }

    return null;
}

/** How many messages went to an address. */
function mailCountTo(string $email): int
{
    $count = 0;
    foreach (glob(testEnvironment()['mail'] . DIRECTORY_SEPARATOR . '*.eml') ?: [] as $file) {
        $count += str_contains((string) file_get_contents($file), "To: $email\n") ? 1 : 0;
    }

    return $count;
}

/** The token of the stop link in the newest announcement to an address. */
function stopToken(string $email): string
{
    preg_match('#/unsubscribe\?token=([A-Za-z0-9_.-]+)#', (string) (newsMailTo($email)['text'] ?? ''), $found);
    check(isset($found[1]), "a stop link was mailed to {$email}");

    return $found[1];
}

/** Sends an announcement to the end, batch after batch, as the page does: the batches it took. */
function sendAll(Browser $admin, int $id): int
{
    for ($batches = 1; $batches <= 50; $batches++) {
        $sent = $admin->post("/api/admin/announcements/{$id}/send");
        same(200, $sent->status, 'a batch: ' . $sent->body);
        if ($sent->json()['status'] !== 'sending') {
            return $batches;
        }
    }
    check(false, 'it should be sent within fifty batches');

    return 0;
}

/** An array with its keys in order, all the way down: JSONB hands its keys back in an order of its own. */
function keysSorted(mixed $value): mixed
{
    if (!is_array($value)) {
        return $value;
    }
    if (!array_is_list($value)) {
        ksort($value);
    }

    return array_map('keysSorted', $value);
}

/** The details of the newest audit entry of an action, keys in order; null for none. */
function lastAuditOf(string $action): ?array
{
    $statement = iamDb()->prepare('SELECT details FROM audit_log WHERE action = ? ORDER BY id DESC LIMIT 1');
    $statement->execute([$action]);
    $details = $statement->fetchColumn();

    return $details === false || $details === null ? null : keysSorted(json_decode((string) $details, true));
}

function newsOn(int $userId): bool
{
    $statement = iamDb()->prepare('SELECT news_mail FROM users WHERE id = ?');
    $statement->execute([$userId]);

    return asBool($statement->fetchColumn());
}

/** Points the app at another of this run's databases, forgetting what it remembered of the old one. */
function useIamDatabase(string $name): void
{
    $environment = testEnvironment();
    file_put_contents($environment['config'] . DIRECTORY_SEPARATOR . 'database.local.php',
        "<?php\n\n\$config = require " . var_export(developmentDatabaseFile(), true) . ";\n"
        . "\$config['databases'] = ['iam' => " . var_export(throwawayDatabase($name), true) . "];\n"
        . "\$config['persistent'] = false;\n\nreturn \$config;\n");
    forgetLocalConfig();
    settingsAll(true);
    languageCodes(null, true);
    activeSigningKey(true);
}

// ── 013 itself ───────────────────────────────────────────────────────────────

test('a fresh IAM has 013: the switch on for everybody, nothing sent, no sender added', function () {
    $pdo = iamDb();
    check(in_array(NEWS_MIGRATION, appliedMigrations($pdo), true), '013 recorded');
    same(true, announcementsReady($pdo), 'its tables are there');
    same(0, (int) $pdo->query('SELECT count(*) FROM users WHERE NOT news_mail')->fetchColumn(), 'everybody gets the news');
    same(0, (int) $pdo->query('SELECT count(*) FROM announcements')->fetchColumn(), 'nothing sent');
    same(0, (int) $pdo->query('SELECT count(*) FROM mail_senders')->fetchColumn(), 'no sender added');
    same(true, newsOn(makeUser('newcomer', 'newcomer@example.test')), 'an account made now gets it too');
});

test('an IAM through 012, as its owner left it: 013 gives everybody the news', function () {
    $pdo = buildIamThrough('_owner', '012_approval_of_new_accounts.sql');
    $insert = $pdo->prepare('INSERT INTO users (username, email, password, email_confirmed, disabled) VALUES (?, ?, ?, ?, ?)');
    foreach ([['owner', true, false], ['newcomer', false, false], ['gone', true, true]] as [$name, $confirmed, $disabled]) {
        $insert->execute([$name, "{$name}@example.test", null, pgBool($confirmed), pgBool($disabled)]);
    }

    $result = applyMigrationsThrough($pdo, NEWS_MIGRATION);
    same([[NEWS_MIGRATION], null], [$result['applied'], $result['failed']], '013 applies (' . ($result['error'] ?? '') . ')');
    same(3, (int) $pdo->query('SELECT count(*) FROM users WHERE news_mail')->fetchColumn(), 'every account there was gets the news');
    same(14, (int) $pdo->query("SELECT count(*) FROM translation_keys WHERE name LIKE 'mail.new%' OR name LIKE 'mail.news.%'")->fetchColumn(), 'the templates\' and the endings\' mail strings');
});

// ── The composer ─────────────────────────────────────────────────────────────

test('the composer starts from the templates in every language, the senders, the sites and their roles', function () {
    $compose = admin()->get('/api/admin/announcements/compose');
    same(200, $compose->status, 'compose: ' . $compose->body);
    $json = $compose->json();

    same(['newVersion', 'newRelease', 'newSurvey'], array_keys($json['templates']), 'three templates with words; blank starts empty');
    same(['subject' => 'A new version of {project}', 'heading' => '{project} has a new version', 'body' => MAIL_FALLBACK['mail.newVersion.body'], 'button' => 'Open {project}'],
        $json['templates']['newVersion']['en'], 'in English, {project} still in them');
    same('Nová verzia: {project}', $json['templates']['newVersion']['sk']['subject'], 'and in Slovak');
    same('Take the survey', $json['templates']['newSurvey']['en']['button'], 'the survey\'s button');
    same(['en', 'sk'], array_column($json['languages'], 'code'), 'the languages on offer, English first');
    same([['noreply', 'noreply@localhost', false], ['support', 'support@localhost', false]],
        array_map(static fn(array $sender): array => [$sender['id'], $sender['address'], $sender['added']], $json['senders']), 'the installation\'s senders');
    $genshin = array_values(array_filter($json['applications'], static fn(array $application): bool => $application['client_id'] === 'genshin'))[0] ?? null;
    same(['ADMIN', 'EDITOR', 'MEMBER'], $genshin['roles'] ?? null, 'a site with its roles');
    same('admin@example.test', $json['email'], 'where a test goes');
    same(150, $json['limits']['subject'], 'the limits');
    same(['driver' => 'file', 'hourly_limit' => null], $json['pacing'], 'how fast it may go: as fast as the page sends it, no limit named');
});

test('who it reaches: everybody, roles, people - and who is left out, and why, each once', function () {
    $pdo = iamDb();
    $admin = admin();
    [$editor, $off, $unconfirmed, $waiting, $disabled, $slovak, $leaving] = readers('aud', 7);
    grantRole($editor, 'genshin', 'EDITOR');
    $pdo->prepare('UPDATE users SET news_mail = FALSE WHERE id = ?')->execute([$off]);
    $pdo->prepare('UPDATE users SET email_confirmed = FALSE WHERE id = ?')->execute([$unconfirmed]);
    $pdo->prepare('UPDATE users SET approved = FALSE WHERE id = ?')->execute([$waiting]);
    // Switched off and unconfirmed: counted as switched off, the first reason.
    $pdo->prepare('UPDATE users SET disabled = TRUE, email_confirmed = FALSE WHERE id = ?')->execute([$disabled]);
    $pdo->prepare("UPDATE users SET language = 'sk' WHERE id = ?")->execute([$slovak]);
    // Its deletion asked for (016), and unconfirmed besides: counted as on its way out, the reason before.
    $pdo->prepare("UPDATE users SET deletion_requested_at = CURRENT_TIMESTAMP, deletion_due_at = CURRENT_TIMESTAMP + INTERVAL '30 days', email_confirmed = FALSE WHERE id = ?")->execute([$leaving]);

    $count = static fn(array $audience): array => $admin->post('/api/admin/announcements/audience', ['audience' => $audience])->json();

    $everybody = $count(['everybody' => true]);
    $total = (int) $pdo->query('SELECT count(*) FROM users')->fetchColumn();
    same(['disabled' => 1, 'unconfirmed' => 1, 'waiting' => 1, 'news_off' => 1, 'deleting' => 1], $everybody['left_out'], 'everybody: one left out for each reason');
    same($total - 5, $everybody['recipients'], 'and the rest reached');
    same(1, $everybody['languages']['sk'] ?? null, 'one reads Slovak');

    same(['recipients' => 1, 'left_out' => ['disabled' => 0, 'unconfirmed' => 0, 'waiting' => 0, 'news_off' => 0, 'deleting' => 0], 'languages' => ['en' => 1]],
        $count(['roles' => [['client_id' => 'genshin', 'role' => 'EDITOR']]]), 'the genshin site\'s editors');
    same(['recipients' => 2, 'left_out' => ['disabled' => 1, 'unconfirmed' => 0, 'waiting' => 0, 'news_off' => 1, 'deleting' => 1], 'languages' => ['en' => 1, 'sk' => 1]],
        $count(['roles' => [['client_id' => 'genshin', 'role' => 'editor']], 'user_ids' => [$off, $disabled, $slovak, $editor, $leaving]]), 'roles and people together, each person once');
    $pdo->prepare('UPDATE users SET deletion_requested_at = NULL, deletion_due_at = NULL WHERE id = ?')->execute([$leaving]);

    foreach ([
        [[], 'no_audience'],
        [['everybody' => false, 'roles' => [], 'user_ids' => []], 'no_audience'],
        [['roles' => [['client_id' => 'genshin', 'role' => 'OWNER']]], 'invalid_role'],
        [['user_ids' => [999999]], 'unknown_user'],
        [['user_ids' => ['1']], 'invalid_audience'],
        [['everybody' => 'yes'], 'invalid_audience'],
    ] as [$audience, $code]) {
        $refused = $admin->post('/api/admin/announcements/audience', ['audience' => $audience]);
        same([422, $code], [$refused->status, $refused->code()], json_encode($audience));
    }
    same([422, 'invalid_audience'], [($r = $admin->post('/api/admin/announcements/audience', ['audience' => 'everybody']))->status, $r->code()], 'not an object');

    // Back to how the rest of the file expects everybody.
    $pdo->prepare('DELETE FROM user_roles WHERE user_id IN (?, ?, ?, ?, ?, ?)')->execute([$editor, $off, $unconfirmed, $waiting, $disabled, $slovak]);
    $pdo->prepare('UPDATE users SET disabled = TRUE WHERE id IN (?, ?, ?, ?, ?, ?)')->execute([$editor, $off, $unconfirmed, $waiting, $disabled, $slovak]);
});

test('a draft is checked the same for a preview, a test and Send', function () {
    $admin = admin();
    $preview = static fn(array $draft): TestResponse => $admin->post('/api/admin/announcements/preview', $draft);
    // Refused by Send, which checks every version - and so nothing is kept.
    $refused = static function (array $draft, string $code, string $what) use ($admin): void {
        $answer = $admin->post('/api/admin/announcements', $draft + ['audience' => ['user_ids' => [(int) $GLOBALS['memberId']]]]);
        same([422, $code], [$answer->status, $answer->code()], $what . ': ' . $answer->body);
    };
    $english = draft()['content']['en'];

    same(200, $preview(draft())->status, 'the draft as it is');
    $refused(draft(['template' => 'newsletter']), 'invalid_template', 'a template there is not');
    $refused(draft(['client_id' => '']), 'application_required', 'a template about a site needs the site');
    same(200, $preview(draft(['template' => 'blank', 'client_id' => '']))->status, 'a blank one need not be about one');
    $refused(draft(['client_id' => 'nothing']), 'unknown_application', 'a site there is not');
    $refused(draft(['sender' => 'ceo']), 'unknown_sender', 'a sender there is not');
    $refused(draft(['content' => []]), 'english_required', 'no words');
    $refused(draft(['content' => ['sk' => $english]]), 'english_required', 'no English: those without a version get it');
    $refused(draft(['content' => ['en' => ['subject' => 'x'] + $english, 'de' => $english]]), 'unknown_language', 'a language the IAM does not offer');
    $refused(draft(['content' => ['en' => $english, 'sk' => ['subject' => 'Nová verzia']]]), 'incomplete_version', 'half a Slovak version');
    same(200, $preview(draft(['content' => ['en' => $english, 'sk' => ['subject' => ' ', 'heading' => '', 'body' => "\u{200B}", 'button' => '']]]))->status, 'an empty one is no version');
    $refused(draft(['content' => ['en' => ['button' => ''] + $english]]), 'incomplete_version', 'a link needs a button');
    same(200, $preview(draft(['link' => '', 'content' => ['en' => ['button' => ''] + $english]]))->status, 'no link, no button');
    $refused(draft(['content' => ['en' => ['subject' => "Line\nbreak"] + $english]]), 'invalid_content', 'a subject is one line');
    $refused(draft(['content' => ['en' => ['heading' => str_repeat('x', 151)] + $english]]), 'invalid_content', 'a heading of 151 characters');
    $refused(draft(['content' => ['en' => ['body' => str_repeat('x', 5001)] + $english]]), 'invalid_content', 'a body of 5001');
    $refused(draft(['content' => ['en' => ['subject' => 'A new version of {project}'] + $english]]), 'placeholder_left', 'the site\'s name never went in');
    $refused(draft(['content' => ['en' => ['body' => 42] + $english]]), 'invalid_content', 'words that are not text');
    same(0, (int) iamDb()->query('SELECT count(*) FROM announcements')->fetchColumn(), 'nothing refused is kept');
});

test('links to the anotoki sites only: the button\'s, and any written in the words', function () {
    $admin = admin();
    $pdo = iamDb();
    $answer = static fn(array $draft): TestResponse => $admin->post('/api/admin/announcements/preview', $draft);
    $english = draft()['content']['en'];

    foreach ([
        'http://localhost:4200/news?from=mail&x=1#top' => 'a site\'s address, http on this computer',
        'http://localhost:4300/account' => 'the IAM\'s own',
        'https://localhost/' => 'https anywhere it is allowed',
    ] as $link => $what) {
        same(200, $answer(draft(['link' => $link]))->status, $what);
    }
    foreach ([
        'https://elsewhere.example/' => 'another host',
        'http://genshin.example.org/' => 'http to a host that is not this computer',
        'https://localhost@elsewhere.example/' => 'a name before the host',
        'https://elsewhere.example\\@localhost/' => 'a backslash',
        'https://localhost/"onmouseover="x' => 'a quote',
        'javascript:alert(1)' => 'not a web address',
        'localhost:4200' => 'not a whole address',
        'https://localhost.elsewhere.example/' => 'a host that only starts like one',
        'https://localhost/' . str_repeat('x', 500) => 'longer than 500',
    ] as $link => $what) {
        $refused = $answer(draft(['link' => $link]));
        same([422, 'invalid_link'], [$refused->status, $refused->code()], $what . ': ' . $refused->body);
    }

    // A site whose application has an https address of its own.
    $home = $pdo->query("SELECT home_url FROM applications WHERE client_id = 'piano-academy'")->fetchColumn();
    $mail = mailConfig();
    $pdo->exec("UPDATE applications SET home_url = 'https://piano.example.org' WHERE client_id = 'piano-academy'");
    same(200, $answer(draft(['link' => 'https://piano.example.org/lessons']))->status, 'an application\'s registered host');
    same(422, $answer(draft(['link' => 'https://other.example.org/']))->status, 'not its neighbour');

    // Under the domain the IAM sends from: every site there, registered or not.
    writeTestConfig('mail', ['driver' => 'file', 'from' => 'noreply@anotoki.test', 'from_name' => 'anotoki',
        'senders' => ['noreply' => ['from' => 'noreply@anotoki.test', 'from_name' => 'anotoki'], 'support' => ['from' => 'support@anotoki.test', 'from_name' => 'anotoki']],
        'directory' => testEnvironment()['mail']]);
    forgetLocalConfig();
    try {
        same(200, $answer(draft(['link' => 'https://survey.anotoki.test/s/12']))->status, 'a site under the mail domain');
        same(200, $answer(draft(['link' => 'https://anotoki.test/']))->status, 'the domain itself');
        same(422, $answer(draft(['link' => 'https://anotoki.test.elsewhere.example/']))->status, 'not one that only starts like it');

        foreach ([
            'Read more at https://genshin.anotoki.test/news.' => 'a site\'s address, a full stop after it',
            'Read more at www.anotoki.test, or ask support@anotoki.test.' => 'www. and an address at the domain',
            'Version 5.2 (the big one): node.js, 3.14 and more.' => 'what only looks like a name of a host',
        ] as $body => $what) {
            same(200, $answer(draft(['content' => ['en' => ['body' => $body] + $english]]))->status, $what);
        }
        foreach ([
            ['body', 'Read more at https://elsewhere.example/news.'],
            ['body', 'Or at www.elsewhere.example'],
            ['body', 'Write to help@elsewhere.example'],
            ['heading', 'Visit ftp://anotoki.test/files'],
            ['button', 'https://elsewhere.example'],
            ['subject', 'Mail me: me@gmail.com'],
        ] as [$part, $text]) {
            $refused = $answer(draft(['content' => ['en' => [$part => $text] + $english]]));
            same([422, 'foreign_address'], [$refused->status, $refused->code()], "$part: $text");
        }
    } finally {
        writeTestConfig('mail', $mail);
        forgetLocalConfig();
        $pdo->prepare("UPDATE applications SET home_url = ? WHERE client_id = 'piano-academy'")->execute([$home]);
    }
});

test('a space of any kind ends a link, as in a mail program: what follows is judged on its own', function () {
    $admin = admin();
    $answer = static fn(array $draft): TestResponse => $admin->post('/api/admin/announcements/preview', $draft);
    $english = draft()['content']['en'];

    foreach ([
        "Read more at http://localhost:4200/\u{00A0}https://evil.example/login" => 'a no-break space between two links',
        "http://localhost:4200/\u{00A0}www.evil.example" => 'a no-break space before www.',
        "http://localhost:4200/\u{2003}https://evil.example" => 'an em space',
        "http://localhost:4200/\u{202F}https://evil.example" => 'a narrow no-break space',
        "http://localhost:4200/\u{3000}https://evil.example" => 'an ideographic space',
        "http://localhost:4200/\u{200B}https://evil.example" => 'a space of no width',
        '0https://evil.example' => 'a scheme right after a digit',
        '_https://evil.example' => 'a scheme right after an underscore',
        'xhttps://localhost/' => 'a scheme that only ends like https',
        'https:///evil.example' => 'slashes a browser skips',
        'https://localhost\@evil.example' => 'a backslash',
        'Visit ://evil.example' => 'no scheme at all',
        "support@localhost\u{00A0}x@evil.example" => 'an address after a no-break space',
    ] as $body => $what) {
        $refused = $answer(draft(['content' => ['en' => ['body' => $body] + $english]]));
        same([422, 'foreign_address'], [$refused->status, $refused->code()], "$what: " . $refused->body);
    }

    foreach ([
        'Read more at http://localhost:4200/postavy/Čiaková - in Slovak.' => 'a path in any script',
        "Two links: http://localhost:4200/\u{00A0}http://localhost:4300/account" => 'two links, both home',
        'Awww. Release 2:// is out.' => 'a www. and a :// with no host after them',
        '(see www.localhost:4200)' => 'www. at home, in brackets',
    ] as $body => $what) {
        same(200, $answer(draft(['content' => ['en' => ['body' => $body] + $english]]))->status, $what);
    }

    foreach ([
        "http://localhost:4200/\u{00A0}https://evil.example/login" => 'a no-break space',
        "http://localhost:4200/\u{200B}x" => 'a space of no width',
        'http://localhost:4200/postavy/Čiaková' => 'a letter outside ASCII',
    ] as $link => $what) {
        $refused = $answer(draft(['link' => $link]));
        same([422, 'invalid_link'], [$refused->status, $refused->code()], "the button's link with $what");
    }
    same(200, $answer(draft(['link' => 'http://localhost:4200/postavy/%C4%8Ciakov%C3%A1']))->status, 'percent-encoded, it is fine');
});

test('the site\'s name stands at the top of every mail: it is held to what the words are', function () {
    $pdo = iamDb();
    $admin = admin();
    $name = $pdo->query("SELECT name FROM applications WHERE client_id = 'genshin'")->fetchColumn();
    $pdo->exec("UPDATE applications SET name = 'Genshin https://evil.example/login' WHERE client_id = 'genshin'");

    try {
        $refused = $admin->post('/api/admin/announcements/preview', draft());
        same([422, 'foreign_address'], [$refused->status, $refused->code()], 'refused: ' . $refused->body);
    } finally {
        $pdo->prepare("UPDATE applications SET name = ? WHERE client_id = 'genshin'")->execute([$name]);
    }
    same(200, $admin->post('/api/admin/announcements/preview', draft())->status, 'renamed back, it is fine');
});

test('the preview: the reader\'s version in the fixed layout, every word escaped, the English one where there is none', function () {
    $admin = admin();
    $preview = $admin->post('/api/admin/announcements/preview', draft([
        'link' => 'http://localhost:4200/news?a=1&b=2',
        'content' => [
            'en' => ['subject' => 'News & more', 'heading' => '<b>Hello</b>', 'body' => "First <script>alert(1)</script> line\nsecond line\n\n\n\nA \"quoted\" paragraph.", 'button' => 'Open it'],
            'sk' => ['subject' => 'Novinky', 'heading' => 'Ahoj', 'body' => 'Prvý odsek.', 'button' => 'Otvoriť'],
        ],
        'language' => 'en',
    ]));
    same(200, $preview->status, 'preview: ' . $preview->body);
    $json = $preview->json();
    same(['subject', 'text', 'html', 'language', 'sender'], array_keys($json), 'what a preview has');
    same(['News & more', 'en'], [$json['subject'], $json['language']], 'the subject as it is, and the version');
    same(['address' => 'noreply@localhost', 'name' => 'anotoki'], $json['sender'], 'and who it comes from');
    check(str_contains($json['html'], '&lt;b&gt;Hello&lt;/b&gt;'), 'the heading escaped');
    check(str_contains($json['html'], 'First &lt;script&gt;alert(1)&lt;/script&gt; line<br>' . "\n" . 'second line</p>'), 'a line break inside a paragraph kept, escaped');
    check(str_contains($json['html'], 'A &quot;quoted&quot; paragraph.</p>'), 'a paragraph after empty lines');
    check(!str_contains($json['html'], '<script>') && !str_contains($json['html'], '<b>'), 'no markup of the words survives');
    check(str_contains($json['html'], 'href="http://localhost:4200/news?a=1&amp;b=2"'), 'the button\'s link');
    check(str_contains($json['html'], '> &middot; Genshin'), 'the site\'s name at the top: ' . $json['html']);
    check(str_contains($json['html'], 'href="http://localhost:4300/unsubscribe"'), 'the stop link of a preview has no token');
    check(str_contains($json['html'], 'You get this email because you have an anotoki account.'), 'the ending, from the mail strings');
    same(2, substr_count($json['html'], '<a '), 'two links: the button and the stop link');
    check(str_starts_with($json['text'], "<b>Hello</b>\n\nFirst <script>alert(1)</script> line\nsecond line\n\nA \"quoted\" paragraph.\n\nOpen it:\nhttp://localhost:4200/news?a=1&b=2\n\n-- \n"), 'the plain text: ' . $json['text']);

    $slovak = $admin->post('/api/admin/announcements/preview', draft(['content' => ['en' => draft()['content']['en'], 'sk' => ['subject' => 'Novinky', 'heading' => 'Ahoj', 'body' => 'Prvý odsek.', 'button' => 'Otvoriť']], 'language' => 'sk']))->json();
    same(['Novinky', 'sk'], [$slovak['subject'], $slovak['language']], 'the Slovak version');
    check(str_contains($slovak['html'], 'lang="sk"') && str_contains($slovak['html'], 'Túto správu dostávaš'), 'in Slovak, ending and all');

    $none = $admin->post('/api/admin/announcements/preview', draft(['language' => 'sk']))->json();
    same(['Genshin site 5.2', 'en'], [$none['subject'], $none['language']], 'no Slovak version: a Slovak reader gets the English one');
    same([422, 'unknown_language'], [($r = $admin->post('/api/admin/announcements/preview', draft(['language' => 'de'])))->status, $r->code()], 'a language there is not');
    // A half-written Slovak version does not stop the English preview.
    same(200, $admin->post('/api/admin/announcements/preview', draft(['content' => ['en' => draft()['content']['en'], 'sk' => ['subject' => 'Nová']], 'language' => 'en']))->status, 'previewing English while Slovak is half done');
});

test('a test goes to the administrator\'s own address only, marked, and is limited', function () {
    $pdo = iamDb();
    $admin = admin();
    $before = mailCountTo('member@example.test');

    $test = $admin->post('/api/admin/announcements/test', draft(['language' => 'en', 'to' => 'member@example.test', 'email' => 'member@example.test']));
    same([200, true, 'admin@example.test'], [$test->status, $test->json()['sent'] ?? null, $test->json()['email'] ?? null], 'sent: ' . $test->body);
    same($before, mailCountTo('member@example.test'), 'nothing to anybody else, whatever the body says');
    $mail = newsMailTo('admin@example.test');
    same('[Test] Genshin site 5.2', $mail['subject'], 'the subject marked');
    same('anotoki <noreply@localhost>', $mail['headers']['From'] ?? null, 'from the sender chosen');
    check(str_starts_with($mail['headers']['Content-Type'] ?? '', 'multipart/alternative; boundary="anotoki-'), 'plain text and HTML');
    check(str_contains((string) $mail['html'], 'The Genshin site has a new version'), 'the HTML');
    $token = stopToken('admin@example.test');
    same('<http://localhost:4300/unsubscribe?token=' . $token . '>', $mail['headers']['List-Unsubscribe'] ?? null, 'List-Unsubscribe: the page, the administrator\'s own link');
    same(['announcement_test', 'sent', '[Test] Genshin site 5.2'], array_values($pdo->query("SELECT kind, status, subject FROM email_log WHERE recipient = 'admin@example.test' ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_NUM) ?: []), 'in the email log');
    same(keysSorted(['language' => 'en', 'subject' => 'Genshin site 5.2']), lastAuditOf('announcement.tested'), 'and in the audit log');
    same(0, (int) $pdo->query('SELECT count(*) FROM announcements')->fetchColumn(), 'a test keeps no announcement');

    $refused = $admin->post('/api/admin/announcements/test', draft(['link' => 'https://elsewhere.example/', 'language' => 'en']));
    same([422, 'invalid_link'], [$refused->status, $refused->code()], 'checked like Send');

    $pdo->prepare("INSERT INTO email_log (recipient, kind, subject, status, user_id) SELECT 'admin@example.test', 'announcement_test', 'x', 'sent', ? FROM generate_series(1, ?)")
        ->execute([(int) $GLOBALS['adminId'], ANNOUNCEMENT_TESTS_PER_HOUR]);
    $limited = $admin->post('/api/admin/announcements/test', draft(['language' => 'en']));
    same([429, 'too_many_tests'], [$limited->status, $limited->code()], 'twenty an hour');
    $pdo->exec("UPDATE email_log SET created_at = created_at - INTERVAL '2 hours' WHERE kind = 'announcement_test'");
    same(200, $admin->post('/api/admin/announcements/test', draft(['language' => 'en']))->status, 'and then again');
});

// ── Sending ──────────────────────────────────────────────────────────────────

test('Send: the people it can reach, each in their language, in batches, from the sender chosen, every mail kept', function () {
    $pdo = iamDb();
    $admin = admin();
    $english = readers('batch', 41);
    $slovak = readers('slovak', 3, 'sk');
    foreach ([...$english, ...$slovak] as $id) {
        grantRole($id, 'piano-academy', 'EDITOR');
    }
    [$off] = readers('quiet', 1);
    grantRole($off, 'piano-academy', 'EDITOR');
    $pdo->prepare('UPDATE users SET news_mail = FALSE WHERE id = ?')->execute([$off]);

    $created = $admin->post('/api/admin/announcements', draft([
        'template' => 'newRelease',
        'client_id' => 'piano-academy',
        'link' => 'http://localhost:4500/',
        'sender' => 'support',
        'content' => [
            'en' => ['subject' => 'Meet the Piano Academy', 'heading' => 'Meet the Piano Academy', 'body' => 'It is new.', 'button' => 'Try it'],
            'sk' => ['subject' => 'Predstavujeme Piano Academy', 'heading' => 'Predstavujeme Piano Academy', 'body' => 'Je nová.', 'button' => 'Vyskúšať'],
        ],
        'audience' => ['roles' => [['client_id' => 'piano-academy', 'role' => 'EDITOR']]],
    ]));
    same(201, $created->status, 'made: ' . $created->body);
    $announcement = $created->json();
    $id = $announcement['id'];
    same(['sending', ['total' => 44, 'pending' => 44, 'sent' => 0, 'failed' => 0, 'skipped' => 0]], [$announcement['status'], $announcement['counts']], 'forty-four to send, the one who stopped the news not among them');
    same(['newRelease', ['client_id' => 'piano-academy', 'name' => 'Piano Academy'], ['address' => 'support@localhost', 'name' => 'anotoki'], ['en', 'sk']],
        [$announcement['template'], $announcement['application'], $announcement['sender'], $announcement['languages']], 'what it is');
    same(['everybody' => false, 'roles' => [['client_id' => 'piano-academy', 'role' => 'EDITOR']], 'users' => []], $announcement['audience'], 'whom it is for');
    same('admin', $announcement['created_by'], 'who sent it');
    same(keysSorted(['subject' => 'Meet the Piano Academy', 'sender' => 'support@localhost', 'recipients' => 44, 'audience' => ['everybody' => false, 'roles' => [['client_id' => 'piano-academy', 'role' => 'EDITOR']], 'people' => 0]]),
        lastAuditOf('announcement.created'), 'in the audit log');
    same(0, mailCountTo('batch1@example.test'), 'nothing sent before the first batch');

    $first = $admin->post("/api/admin/announcements/{$id}/send")->json();
    same([ANNOUNCEMENT_BATCH_SIZE, 44 - ANNOUNCEMENT_BATCH_SIZE, 'sending'], [$first['counts']['sent'], $first['counts']['pending'], $first['status']], 'a batch at a time');
    same(2, sendAll($admin, $id), 'and two more');
    $done = $admin->get("/api/admin/announcements/{$id}")->json();
    same(['sent', ['total' => 44, 'pending' => 0, 'sent' => 44, 'failed' => 0, 'skipped' => 0]], [$done['status'], $done['counts']], 'all sent');
    check($done['finished_at'] !== null, 'when it finished');
    same(0, mailCountTo('quiet1@example.test'), 'nobody who stopped the news');

    $mail = newsMailTo('batch7@example.test');
    same(['Meet the Piano Academy', 'anotoki <support@localhost>', 'support@localhost'], [$mail['subject'], $mail['headers']['From'] ?? null, $mail['headers']['Reply-To'] ?? null], 'English, from support');
    check(str_contains((string) $mail['html'], 'href="http://localhost:4500/"') && str_contains((string) $mail['text'], "Try it:\nhttp://localhost:4500/"), 'the button in both parts');
    same(1, mailCountTo('batch7@example.test'), 'once');
    $sk = newsMailTo('slovak2@example.test');
    same('Predstavujeme Piano Academy', $sk['subject'], 'a Slovak reader gets the Slovak version');
    check(str_contains((string) $sk['text'], 'Zastaviť novinky zo stránok anotoki:'), 'its ending in Slovak too');

    $token = stopToken('slovak2@example.test');
    same('<http://localhost:4300/unsubscribe?token=' . $token . '>', $sk['headers']['List-Unsubscribe'] ?? null, 'the person\'s own stop link, in the header too');
    same($slovak[1], newsUnsubscribeUser($token), 'naming them');

    same(44, (int) $pdo->query("SELECT count(*) FROM email_log WHERE kind = 'announcement' AND subject IN ('Meet the Piano Academy', 'Predstavujeme Piano Academy')")->fetchColumn(), 'each in the email log');
    $sent = $admin->get("/api/admin/announcements/{$id}/recipients?status=sent&search=slovak")->json();
    same([3, ['sk', 'sk', 'sk'], ['sent', 'sent', 'sent']], [$sent['total'], array_column($sent['items'], 'language'), array_column($sent['items'], 'status')], 'the recipients, as they went');
    same(['user_id', 'username', 'email', 'language', 'status', 'sent_at'], array_keys($sent['items'][0]), 'what is kept of each');
    same([44, 50], [($page = $admin->get("/api/admin/announcements/{$id}/recipients")->json())['total'], $page['pageSize']], 'fifty a page');
    $far = $admin->get("/api/admin/announcements/{$id}/recipients?page=99999999999999999999");
    same([200, 100000, []], [$far->status, $far->json()['page'] ?? null, $far->json()['items'] ?? null], 'a page past any there can be: empty, not an error');

    $again = $admin->post("/api/admin/announcements/{$id}/send");
    same([200, 'sent'], [$again->status, $again->json()['status']], 'pressing on once it is sent changes nothing');
    same(1, mailCountTo('batch7@example.test'), 'nobody gets it twice');

    $history = $admin->get('/api/admin/announcements')->json();
    same([$id, 'Meet the Piano Academy', 44], [$history['items'][0]['id'], $history['items'][0]['subject'], $history['items'][0]['counts']['sent']], 'the history, newest first');
    check(!array_key_exists('content', $history['items'][0]), 'without the words');

    $pdo->exec("DELETE FROM user_roles WHERE role_id IN (SELECT r.id FROM application_roles r JOIN applications a ON a.id = r.application_id WHERE a.client_id = 'piano-academy' AND r.name = 'EDITOR')");
});

test('between the list and the mail: who was switched off or stopped the news is skipped; a new address is used', function () {
    $pdo = iamDb();
    $admin = admin();
    [$stays, $leaves, $quiets, $moves] = readers('late', 4);

    $created = $admin->post('/api/admin/announcements', draft(['audience' => ['user_ids' => [$stays, $leaves, $quiets, $moves]]]));
    same([201, 4], [$created->status, $created->json()['counts']['pending'] ?? null], 'four to send');
    $pdo->prepare('UPDATE users SET disabled = TRUE WHERE id = ?')->execute([$leaves]);
    $pdo->prepare('UPDATE users SET news_mail = FALSE WHERE id = ?')->execute([$quiets]);
    $pdo->prepare("UPDATE users SET email = 'moved@example.test' WHERE id = ?")->execute([$moves]);

    sendAll($admin, $created->json()['id']);
    $done = $admin->get('/api/admin/announcements/' . $created->json()['id'])->json();
    same(['sent', 2, 2], [$done['status'], $done['counts']['sent'], $done['counts']['skipped']], 'two sent, two skipped');
    same([1, 0, 0, 0, 1], [mailCountTo('late1@example.test'), mailCountTo('late2@example.test'), mailCountTo('late3@example.test'), mailCountTo('late4@example.test'), mailCountTo('moved@example.test')], 'to the address the account has now');
    same(['moved@example.test'], array_column($admin->get('/api/admin/announcements/' . $created->json()['id'] . '/recipients?search=moved')->json()['items'], 'email'), 'and that is the address kept');
    same(['everybody' => false, 'roles' => [], 'users' => ['late1', 'late2', 'late3', 'late4']], array_replace($done['audience'], ['users' => array_column($done['audience']['users'], 'username')]), 'the people chosen by name');
});

test('a row another sender holds is left to it: two tabs pressing on never send a mail twice', function () {
    $admin = admin();
    $ids = readers('tab', 3);
    $created = $admin->post('/api/admin/announcements', draft(['audience' => ['user_ids' => $ids]]))->json();

    // A second connection - the other tab's request - holds the first row while it sends.
    $other = connectDatabase(databaseConfig(), testEnvironment()['db'], false);
    $other->beginTransaction();
    $other->prepare('SELECT 1 FROM announcement_recipients WHERE announcement_id = ? AND user_id = ? FOR UPDATE')->execute([$created['id'], $ids[0]]);

    $batch = $admin->post("/api/admin/announcements/{$created['id']}/send")->json();
    same([2, 1, 'sending'], [$batch['counts']['sent'], $batch['counts']['pending'], $batch['status']], 'the two it could take, sent; the held one left');
    same(0, mailCountTo('tab1@example.test'), 'not sent by this tab');

    $other->rollBack();
    same(1, sendAll($admin, $created['id']), 'once let go, the next batch sends it');
    same([1, 1, 1], [mailCountTo('tab1@example.test'), mailCountTo('tab2@example.test'), mailCountTo('tab3@example.test')], 'each once');
});

test('a host with an hourly allowance: announcements keep to nine tenths of it, and wait for room', function () {
    $pdo = iamDb();
    $admin = admin();
    $mail = mailConfig();
    $ids = readers('paced', 30);
    // What earlier tests sent is an hour old: the allowance starts empty.
    $pdo->exec("UPDATE email_log SET created_at = created_at - INTERVAL '2 hours'");
    writeTestConfig('mail', $mail + ['hourly_limit' => 30]);
    forgetLocalConfig();

    try {
        same(['driver' => 'file', 'hourly_limit' => 30], $admin->get('/api/admin/announcements/compose')->json()['pacing'] ?? null, 'the composer is told');
        $created = $admin->post('/api/admin/announcements', draft(['audience' => ['user_ids' => $ids]]))->json();
        $first = $admin->post("/api/admin/announcements/{$created['id']}/send")->json();
        same([20, null], [$first['counts']['sent'], $first['wait']], 'a batch');
        $second = $admin->post("/api/admin/announcements/{$created['id']}/send")->json();
        same([27, 'sending', null], [$second['counts']['sent'], $second['status'], $second['wait']], 'up to 27 of the 30 an hour');
        $third = $admin->post("/api/admin/announcements/{$created['id']}/send")->json();
        same([27, 'sending'], [$third['counts']['sent'], $third['status']], 'then nothing');
        check(is_int($third['wait']) && $third['wait'] >= 5 && $third['wait'] <= 3600, 'and how long to wait: ' . json_encode($third['wait']));
        same(0, mailCountTo('paced30@example.test'), 'the last not sent');

        $pdo->exec("UPDATE email_log SET created_at = created_at - INTERVAL '2 hours'");
        same(1, sendAll($admin, $created['id']), 'an hour on, the rest');
        same(1, mailCountTo('paced30@example.test'), 'sent');
    } finally {
        writeTestConfig('mail', $mail);
        forgetLocalConfig();
    }
});

test('a mail that went is marked sent even when the email log will not take it: it is never sent twice', function () {
    $pdo = iamDb();
    $admin = admin();
    $ids = readers('unlogged', 2);
    $created = $admin->post('/api/admin/announcements', draft(['audience' => ['user_ids' => $ids]]))->json();
    $pdo->exec("ALTER TABLE email_log ADD CONSTRAINT no_announcements CHECK (kind <> 'announcement') NOT VALID");

    try {
        $sent = $admin->post("/api/admin/announcements/{$created['id']}/send");
        same([200, 'sent', 2], [$sent->status, $sent->json()['status'] ?? null, $sent->json()['counts']['sent'] ?? null], 'sent, and marked so: ' . $sent->body);
    } finally {
        $pdo->exec('ALTER TABLE email_log DROP CONSTRAINT no_announcements');
    }
    same([1, 1], [mailCountTo('unlogged1@example.test'), mailCountTo('unlogged2@example.test')], 'once each');
    same(0, (int) $pdo->query("SELECT count(*) FROM email_log WHERE recipient LIKE 'unlogged%'")->fetchColumn(), 'with no row in the log, which would not take one');
});

test('Stop: what is not sent never goes', function () {
    $pdo = iamDb();
    $admin = admin();
    $ids = readers('stop', 25);
    $created = $admin->post('/api/admin/announcements', draft(['audience' => ['user_ids' => $ids]]))->json();
    $admin->post("/api/admin/announcements/{$created['id']}/send");

    $stopped = $admin->post("/api/admin/announcements/{$created['id']}/stop");
    same([200, 'stopped', 20, 5], [$stopped->status, $stopped->json()['status'], $stopped->json()['counts']['sent'], $stopped->json()['counts']['pending']], 'stopped after a batch: ' . $stopped->body);
    same(keysSorted(['sent' => 20, 'not_sent' => 5]), lastAuditOf('announcement.stopped'), 'in the audit log');
    same([409, 'stopped'], [($r = $admin->post("/api/admin/announcements/{$created['id']}/send"))->status, $r->code()], 'pressing on is refused');
    same([409, 'not_sending'], [($r = $admin->post("/api/admin/announcements/{$created['id']}/stop"))->status, $r->code()], 'it is stopped already');
    same(0, mailCountTo('stop25@example.test'), 'the last never went');
    same(5, (int) $pdo->query("SELECT count(*) FROM announcement_recipients WHERE announcement_id = {$created['id']} AND status = 'pending'")->fetchColumn(), 'and is kept as not sent');
    same([404, 404], [$admin->post('/api/admin/announcements/999999/stop')->status, $admin->post('/api/admin/announcements/999999/send')->status], 'one there is not');
});

test('nobody to send to: refused, and nothing is kept', function () {
    $pdo = iamDb();
    $admin = admin();
    [$quiet] = readers('alone', 1);
    $pdo->prepare('UPDATE users SET news_mail = FALSE WHERE id = ?')->execute([$quiet]);
    $before = (int) $pdo->query('SELECT count(*) FROM announcements')->fetchColumn();

    $refused = $admin->post('/api/admin/announcements', draft(['audience' => ['user_ids' => [$quiet]]]));
    same([422, 'no_recipients'], [$refused->status, $refused->code()], 'nobody can get it');
    same([422, 'no_audience'], [($r = $admin->post('/api/admin/announcements', draft()))->status, $r->code()], 'nobody chosen');
    same([422, 'invalid_link'], [($r = $admin->post('/api/admin/announcements', draft(['link' => 'https://elsewhere.example/', 'audience' => ['everybody' => true]])))->status, $r->code()], 'a draft that is refused');
    same($before, (int) $pdo->query('SELECT count(*) FROM announcements')->fetchColumn(), 'none kept');
});

// ── Stopping the news ────────────────────────────────────────────────────────

test('the link at the end of every announcement stops the news, with no session, and only that account\'s', function () {
    $pdo = iamDb();
    $admin = admin();
    [$reader, $neighbour] = readers('stopper', 2);
    $created = $admin->post('/api/admin/announcements', draft(['audience' => ['user_ids' => [$reader, $neighbour]]]))->json();
    sendAll($admin, $created['id']);
    $token = stopToken('stopper1@example.test');

    $stranger = new Browser('10.13.5.1');
    same([403, 'csrf'], [($r = $stranger->post('/api/news/unsubscribe', ['token' => $token], ['csrf' => false]))->status, $r->code()], 'the CSRF header, like every first-party write');
    same(true, newsOn($reader), 'which changed nothing');

    $stopped = $stranger->post('/api/news/unsubscribe', ['token' => $token]);
    same([200, ['news_mail' => false]], [$stopped->status, $stopped->json()], 'stopped, nobody signed in');
    same([false, true], [newsOn($reader), newsOn($neighbour)], 'that account\'s news, and nobody else\'s');
    same(keysSorted(['by' => 'link', 'news_mail' => false]), lastAuditOf('account.news_mail'), 'in the audit log');
    same(200, $stranger->post('/api/news/unsubscribe', ['token' => $token])->status, 'once more: the same answer');
    same(1, (int) $pdo->query("SELECT count(*) FROM audit_log WHERE action = 'account.news_mail' AND target_id = '{$reader}'")->fetchColumn(), 'and nothing more recorded');

    [$id, $mac] = explode('.', $token);
    $forged = base64UrlEncode(pack('N', $neighbour)) . '.' . $mac;
    $other = newsUnsubscribeToken($neighbour);
    foreach ([
        '' => 'no token',
        $forged => 'another account\'s id under this one\'s MAC',
        $id . '.' . strrev($mac) => 'a MAC that is not ours',
        substr($token, 0, -1) => 'part of it',
        $token . 'x' => 'more than it',
        strtoupper($other) => 'another\'s in capitals',
        base64UrlEncode(pack('N', 0)) . '.' . base64UrlEncode(substr(hash_hmac('sha256', 'news-unsubscribe:0', 'guess', true), 0, 16)) => 'account 0, guessed',
        base64UrlEncode(pack('N', $reader)) . '.' . base64UrlEncode(substr(hash_hmac('sha256', pack('N', $reader), iamMasterKey(), true), 0, 16)) => 'signed with the master key itself: the links have a key of their own',
    ] as $wrong => $what) {
        $refused = $stranger->post('/api/news/unsubscribe', ['token' => $wrong]);
        same([400, 'invalid_token'], [$refused->status, $refused->code()], $what);
    }
    same(true, newsOn($neighbour), 'the neighbour still gets the news');

    // Stopped, they are not written to again - chosen by name or not.
    $refused = $admin->post('/api/admin/announcements', draft(['audience' => ['user_ids' => [$reader]]]));
    same([422, 'no_recipients'], [$refused->status, $refused->code()], 'not even by name');
});

test('the account page\'s switch', function () {
    $member = new Browser('10.13.6.1');
    same(200, $member->signIn('member@example.test', 'correct horse')->status, 'the member signs in');
    same(true, $member->get('/api/account')->json()['news_mail'] ?? null, 'on');

    $off = $member->put('/api/account/profile', ['news_mail' => false]);
    same([200, false], [$off->status, $off->json()['news_mail'] ?? null], 'switched off');
    same(keysSorted(['by' => 'account', 'news_mail' => false]), lastAuditOf('account.news_mail'), 'recorded');
    same(true, $member->put('/api/account/profile', ['news_mail' => true])->json()['news_mail'] ?? null, 'and on');
    same([422, 'invalid_input'], [($r = $member->put('/api/account/profile', ['news_mail' => 'no']))->status, $r->code()], 'true or false');
    same([403, 'csrf'], [($r = $member->put('/api/account/profile', ['news_mail' => false], ['csrf' => false]))->status, $r->code()], 'with the CSRF header');
    same(true, newsOn((int) $GLOBALS['memberId']), 'still on');
});

// ── The news, offered at registration (014) ─────────────────────────────────

test('an IAM through 013, as its owner left it: 014 adds the registration box\'s words', function () {
    $pdo = buildIamThrough('_box', NEWS_MIGRATION);
    // What the owner may have done in the panel: a Slovak string blanked elsewhere.
    $pdo->exec("DELETE FROM translations WHERE key_name = 'common.save' AND language_code = 'sk'");

    $result = applyMigrationsThrough($pdo, NEWS_AT_REGISTRATION);
    same([[NEWS_AT_REGISTRATION], null], [$result['applied'], $result['failed']], '014 applies (' . ($result['error'] ?? '') . ')');
    $strings = $pdo->query("SELECT key_name, language_code FROM translations WHERE key_name IN ('register.newsMail', 'register.newsMailHint')")->fetchAll(PDO::FETCH_NUM);
    same(4, count($strings), 'both, in English and in Slovak');
    same(0, (int) $pdo->query("SELECT count(*) FROM translations WHERE key_name = 'common.save' AND language_code = 'sk'")->fetchColumn(), 'and the owner\'s blank stays blank');
});

test('the registration form offers the news, unticked: an account gets it only when the person asks', function () {
    $pdo = iamDb();
    same(true, (new Browser('10.13.9.1'))->get('/api/public/config')->json()['registration_news'] ?? null, 'the sign-in pages are told the box is there');

    $register = static fn(string $name, array $extra = []): TestResponse => (new Browser('10.13.9.2'))->post('/api/register',
        ['username' => $name, 'email' => "{$name}@example.test", 'password' => 'a long password', 'language' => 'en'] + $extra);

    same(201, $register('silent')->status, 'made, the box not sent at all');
    same(false, newsOfAddress('silent@example.test'), 'no news: nobody said yes');
    same(keysSorted(['via' => 'register']), lastAuditOf('account.created'), 'the log has no yes');

    same(201, $register('keen', ['news_mail' => true])->status, 'made, the box ticked');
    same(true, newsOfAddress('keen@example.test'), 'the news');
    same(keysSorted(['via' => 'register', 'news_mail' => true]), lastAuditOf('account.created'), 'the yes kept with the account\'s making: when, and from where');

    same(201, $register('unticked', ['news_mail' => false])->status, 'made, the box unticked');
    same(false, newsOfAddress('unticked@example.test'), 'no news');

    same([422, 'invalid_input'], [($r = $register('odd', ['news_mail' => 'yes']))->status, $r->code()], 'true or false');
    same(0, (int) $pdo->query("SELECT count(*) FROM users WHERE username = 'odd'")->fetchColumn(), 'and nothing made');
});

test('signing up with Google: the register page\'s box goes with the credential; from the sign-in page nobody was asked', function () {
    $google = static fn(string $subject, string $email, array $extra = []): TestResponse => (new Browser('10.13.9.3'))->post('/api/login/google',
        ['credential' => googleToken($subject, $email, ucfirst($subject))] + $extra);

    same(201, ($r = $google('g-keen', 'gkeen@example.test', ['news_mail' => true]))->status, 'made, the box ticked on the register page: ' . $r->body);
    same(true, newsOfAddress('gkeen@example.test'), 'the news');
    same('google', lastAuditOf('account.created')['via'] ?? null, 'made by Google');
    same(true, lastAuditOf('account.created')['news_mail'] ?? null, 'with the yes');

    same(201, $google('g-plain', 'gplain@example.test')->status, 'made from the sign-in page');
    same(false, newsOfAddress('gplain@example.test'), 'no news: nobody was asked');
    same(201, $google('g-odd', 'godd@example.test', ['news_mail' => 'yes'])->status, 'anything but true is no - a sign-in is not refused for it');
    same(false, newsOfAddress('godd@example.test'), 'no news');

    same(200, $google('g-plain', 'gplain@example.test', ['news_mail' => true])->status, 'signing in again, with the box');
    same(false, newsOfAddress('gplain@example.test'), 'a sign-in is no sign-up: the account page has the switch');
});

test('the owner of an address takes back an account a stranger registered with it: the stranger\'s yes to the news goes', function () {
    $made = (new Browser('10.13.9.4'))->post('/api/register',
        ['username' => 'squatter', 'email' => 'rightful@example.test', 'password' => 'a long password', 'language' => 'en', 'news_mail' => true]);
    same(201, $made->status, 'a stranger registers the address, the box ticked');
    same(true, newsOfAddress('rightful@example.test'), 'the news, by the stranger\'s word');

    $owner = (new Browser('10.13.9.5'))->post('/api/login/google', ['credential' => googleToken('g-rightful', 'rightful@example.test', 'Rightful')]);
    same(200, $owner->status, 'its owner signs in with Google: ' . $owner->body);
    same(false, newsOfAddress('rightful@example.test'), 'the account is theirs now, and they never said yes');

    // The other way an owner takes such an account back: a reset link that reached the mailbox.
    same(201, (new Browser('10.13.9.6'))->post('/api/register',
        ['username' => 'squatter2', 'email' => 'rightful2@example.test', 'password' => 'a long password', 'language' => 'en', 'news_mail' => true])->status, 'another stranger, another address, the box ticked');
    same(200, (new Browser('10.13.9.7'))->post('/api/password/forgot', ['email' => 'rightful2@example.test'])->status, 'its owner asks for a reset');
    preg_match('#/reset-password\?token=([0-9a-f]{64})#', (string) (lastMailTo('rightful2@example.test')['body'] ?? ''), $found);
    check(isset($found[1]), 'a reset link was mailed');
    $reset = (new Browser('10.13.9.7'))->post('/api/password/reset', ['token' => $found[1] ?? '', 'password' => 'the owner\'s own password']);
    same(200, $reset->status, 'and sets a password by it: ' . $reset->body);
    same(false, newsOfAddress('rightful2@example.test'), 'the stranger\'s yes went with the rest');
});

// ── Senders ──────────────────────────────────────────────────────────────────

test('senders: the installation\'s own, and those added at the domain it sends from', function () {
    $pdo = iamDb();
    $admin = admin();

    $listed = $admin->get('/api/admin/mail-senders')->json();
    same([['noreply', 'support'], ['localhost']], [array_column($listed['senders'], 'id'), $listed['domains']], 'the installation\'s two, and its domain');

    $added = $admin->post('/api/admin/mail-senders', ['address' => ' News@LocalHost ', 'name' => "anotoki  news"]);
    same(201, $added->status, 'added: ' . $added->body);
    $sender = $added->json()['sender'];
    same(['news@localhost', 'anotoki news', true, 'admin'], [$sender['address'], $sender['name'], $sender['added'], $sender['created_by']], 'kept lowercase, its spaces tidied');
    same(keysSorted(['address' => 'news@localhost', 'name' => 'anotoki news']), lastAuditOf('mail_sender.added'), 'recorded');

    foreach ([
        [['address' => 'news@elsewhere.example', 'name' => 'x'], 'foreign_domain', 'another domain'],
        [['address' => 'news@sub.localhost', 'name' => 'x'], 'foreign_domain', 'a name under it'],
        [['address' => 'not an address', 'name' => 'x'], 'invalid_email', 'not an address'],
        [['address' => '"a b"@localhost', 'name' => 'x'], 'invalid_email', 'a quoted local part'],
        [['address' => 'other@localhost', 'name' => 'anotoki, news'], 'invalid_name', 'a comma, which would make two addresses of the From line'],
        [['address' => 'other@localhost', 'name' => "anotoki\r\nBcc: x@elsewhere.example"], 'invalid_name', 'a line break, which would start another header'],
        [['address' => 'other@localhost', 'name' => 'anotoki <x@localhost>'], 'invalid_name', 'angle brackets'],
        [['address' => 'other@localhost', 'name' => ''], 'invalid_name', 'no name'],
        [['address' => 'other@localhost', 'name' => str_repeat('x', 61)], 'invalid_name', 'a long name'],
        [['address' => 'NEWS@localhost', 'name' => 'x'], 'sender_exists', 'one there is'],
        [['address' => 'support@localhost', 'name' => 'x'], 'sender_exists', 'one of the installation\'s'],
    ] as [$body, $code, $what]) {
        $refused = $admin->post('/api/admin/mail-senders', $body);
        same($code, $refused->code(), $what . ': ' . $refused->body);
    }
    same('invalid_email', $admin->post('/api/admin/mail-senders', ['address' => 'tým@localhost', 'name' => 'x'])->code(), 'nor letters outside the plain ones');

    // Sent from it, the announcement keeps it after it is gone.
    [$reader] = readers('fromnews', 1);
    $created = $admin->post('/api/admin/announcements', draft(['sender' => $sender['id'], 'audience' => ['user_ids' => [$reader]]]))->json();
    sendAll($admin, $created['id']);
    same('anotoki news <news@localhost>', newsMailTo('fromnews1@example.test')['headers']['From'] ?? null, 'from the address added');

    $removed = $admin->delete('/api/admin/mail-senders/' . $sender['id']);
    same([200, ['noreply', 'support']], [$removed->status, array_column($removed->json()['senders'], 'id')], 'removed');
    same(keysSorted(['address' => 'news@localhost', 'name' => 'anotoki news']), lastAuditOf('mail_sender.removed'), 'recorded');
    same(['address' => 'news@localhost', 'name' => 'anotoki news'], $admin->get('/api/admin/announcements/' . $created['id'])->json()['sender'], 'what was sent from it says so still');
    same(404, $admin->delete('/api/admin/mail-senders/' . $sender['id'])->status, 'gone');
    same([422, 'unknown_sender'], [($r = $admin->post('/api/admin/announcements/preview', draft(['sender' => $sender['id']])))->status, $r->code()], 'and not a sender any more');
    same(0, (int) $pdo->query('SELECT count(*) FROM mail_senders')->fetchColumn(), 'none left');
});

// ── Who may ──────────────────────────────────────────────────────────────────

test('only the IAM\'s administrators, and every write with the CSRF header', function () {
    $member = new Browser('10.13.7.1');
    same(200, $member->signIn('member@example.test', 'correct horse')->status, 'the member signs in');
    $calls = [
        ['GET', '/api/admin/announcements'], ['GET', '/api/admin/announcements/compose'], ['POST', '/api/admin/announcements/audience'],
        ['POST', '/api/admin/announcements/preview'], ['POST', '/api/admin/announcements/test'], ['POST', '/api/admin/announcements'],
        ['GET', '/api/admin/announcements/1'], ['GET', '/api/admin/announcements/1/recipients'], ['POST', '/api/admin/announcements/1/send'],
        ['POST', '/api/admin/announcements/1/stop'], ['GET', '/api/admin/mail-senders'], ['POST', '/api/admin/mail-senders'], ['DELETE', '/api/admin/mail-senders/1'],
    ];
    foreach ($calls as [$method, $path]) {
        same([403, 'forbidden'], [($r = $member->request($method, $path, $method === 'GET' || $method === 'DELETE' ? [] : ['json' => draft()]))->status, $r->code()], "$method $path, to a member");
        same([401, 'no_session'], [($r = (new Browser('10.13.7.2'))->request($method, $path, $method === 'GET' || $method === 'DELETE' ? [] : ['json' => draft()]))->status, $r->code()], "$method $path, to nobody");
    }

    $admin = admin();
    foreach (array_filter($calls, static fn(array $call): bool => $call[0] !== 'GET') as [$method, $path]) {
        same([403, 'csrf'], [($r = $admin->request($method, $path, ['csrf' => false] + ($method === 'DELETE' ? [] : ['json' => draft()])))->status, $r->code()], "$method $path without the header");
    }
});

// ── With 013 pending: a build uploaded before Apply ──────────────────────────

test('with 013 pending the account page and the rest of the panel work, the announcements wait for Apply', function () {
    $main = testEnvironment()['db'];
    $window = throwawayDatabase($main . '_window');
    buildIamThrough('_window', '012_approval_of_new_accounts.sql');
    useIamDatabase($window);

    try {
        $pdo = iamDb();
        same(false, announcementsReady($pdo), 'no announcements yet');
        $adminId = makeUser('admin', 'admin@example.test');
        grantRole($adminId, 'iam', 'ADMIN');
        makeUser('person', 'person@example.test');

        $person = new Browser('10.13.8.1');
        same(200, $person->signIn('person@example.test', 'correct horse')->status, 'signing in');
        same([200, null], [($r = $person->get('/api/account'))->status, array_key_exists('news_mail', $r->json()) ? $r->json()['news_mail'] : 'missing'], 'the account page, with no switch to show');
        same(200, $person->put('/api/account/profile', ['language' => 'sk'])->status, 'the profile saves');
        same([503, 'announcements_unavailable'], [($r = $person->put('/api/account/profile', ['news_mail' => false]))->status, $r->code()], 'the switch waits');
        same(false, (new Browser('10.13.8.9'))->get('/api/public/config')->json()['registration_news'] ?? null, 'the registration form offers no box');
        same(201, (new Browser('10.13.8.9'))->post('/api/register',
            ['username' => 'early', 'email' => 'early@example.test', 'password' => 'a long password', 'language' => 'en', 'news_mail' => true])->status, 'and signing up works as before, whatever a page sends');
        same([503, 'announcements_unavailable'], [($r = $person->post('/api/news/unsubscribe', ['token' => 'AAAAAQ.AAAAAAAAAAAAAAAAAAAAAA']))->status, $r->code()], 'and so does the link');

        $admin = admin();
        foreach (['GET /api/admin/announcements', 'GET /api/admin/announcements/compose', 'POST /api/admin/announcements', 'GET /api/admin/mail-senders'] as $call) {
            [$method, $path] = explode(' ', $call);
            same([503, 'announcements_unavailable'], [($r = $admin->request($method, $path, $method === 'GET' ? [] : ['json' => draft()]))->status, $r->code()], $call);
        }
        same(200, $admin->get('/api/admin/users')->status, 'the users pages answer');
        same(200, $admin->get('/api/admin/emails')->status, 'and the email log');
        same([403, 'forbidden'], [($r = $person->get('/api/admin/announcements'))->status, $r->code()], 'to anybody not an administrator, what it always says - not that 013 is pending');

        $applied = $admin->post('/api/admin/migrations/apply');
        same([200, [NEWS_MIGRATION], null], [$applied->status, array_slice($applied->json()['applied'] ?? [], 0, 1), $applied->json()['failed'] ?? null], 'Apply pending: ' . $applied->body);
        same(true, announcementsReady($pdo), 'from the next request on, in the same process');
        same(200, $admin->get('/api/admin/announcements/compose')->status, 'the composer answers');
        same(false, $person->put('/api/account/profile', ['news_mail' => false])->json()['news_mail'] ?? null, 'the switch works');
    } finally {
        useIamDatabase($main);
    }

    same($main, iamDb()->query('SELECT current_database()')->fetchColumn(), 'back on this run\'s own database');
});

finishTests();
