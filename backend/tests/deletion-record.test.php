<?php

/**
 * The record of account deletions (the owner's decision of 2026-10-13): every
 * deletion asked for, cancelled and done is a line of an add-only record
 * beside the database - storage/records/account-deletions.jsonl, ids and the
 * database's times - written once it committed; a failure to write is the
 * error log's, loudly, never the person's.
 *
 * The attack, or the accident, it stops: the database lost and an older backup
 * put back by hand - every deletion made since would be gone with it, and an
 * anonymized person's name, address and history back. `php
 * account-deletions.php --replay --since=<the backup's time>` does them again
 * in order - a request with its own times, a cancellation, an anonymization
 * run now - and ends every way back in the restore brought back. A dry run
 * changes nothing; a second run nothing more. The IAM's own restore stays off:
 * the tests restore with a Backups of their own, as the owner would by hand.
 */

declare(strict_types=1);

require __DIR__ . '/lib.php';

use Anotoki\Lib\Backups\Backups;
use Anotoki\Lib\Migrations\Migrator;
use Anotoki\Lib\Support\Keys;

// This run's own database is a fresh IAM, built from every migration.
bootTests();

const PASSWORD = 'correct horse';
const FAREWELL = 'Your anotoki account is deleted';

/** The record's lines as they stand, each decoded. */
function recordLines(): array
{
    $file = accountDeletionRecordFile();
    if (!is_file($file)) {
        return [];
    }

    return array_map(static fn(string $line): array => (array) json_decode($line, true), array_values(array_filter(explode("\n", (string) file_get_contents($file)), static fn(string $line): bool => $line !== '')));
}

/** A browser signed in with a password, from an address of its own. */
function signedIn(string $email, string $ip): Browser
{
    $browser = new Browser($ip);
    same(200, $browser->signIn($email, PASSWORD)->status, "{$email} signs in");

    return $browser;
}

/** A column of an account's, as the record writes a time: ISO-8601 in UTC, to the microsecond. */
function recordTimeOf(int $userId, string $column): ?string
{
    $value = iamDb()->query('SELECT ' . recordTimeSql($column) . " FROM users WHERE id = {$userId}")->fetchColumn();

    return $value === false || $value === null ? null : (string) $value;
}

/** php account-deletions.php with these arguments, run as the file runs it: [status, what it printed, what it said wrong]. */
function command(array $arguments): array
{
    $out = fopen('php://memory', 'w+');
    $err = fopen('php://memory', 'w+');
    $status = accountDeletionsCommand($arguments, $out, $err);
    rewind($out);
    rewind($err);

    return [$status, (string) stream_get_contents($out), (string) stream_get_contents($err)];
}

/** Where each account's deletion stands: [asked at, due at, deleted] - the times exactly as the database holds them. */
function deletionStates(array $ids): array
{
    $states = [];
    foreach ($ids as $id) {
        $states[$id] = [recordTimeOf($id, 'deletion_requested_at'), recordTimeOf($id, 'deletion_due_at'), recordTimeOf($id, 'anonymized_at') !== null];
    }

    return $states;
}

/** All a replay may change: the accounts' rows, every live way back in, the ids' sequence, the record, the audit log. */
function everything(PDO $pdo): array
{
    return [
        'users' => $pdo->query('SELECT id, username, email, deletion_requested_at::text, deletion_due_at::text, anonymized_at::text, disabled FROM users ORDER BY id')->fetchAll(PDO::FETCH_NUM),
        'sessions' => (int) $pdo->query('SELECT count(*) FROM sessions WHERE revoked_at IS NULL AND expires_at > CURRENT_TIMESTAMP AND absolute_expires_at > CURRENT_TIMESTAMP')->fetchColumn(),
        'tokens' => (int) $pdo->query('SELECT count(*) FROM refresh_tokens WHERE revoked_at IS NULL AND expires_at > CURRENT_TIMESTAMP')->fetchColumn(),
        'devices' => (int) $pdo->query('SELECT count(*) FROM user_trusted_devices WHERE revoked_at IS NULL AND expires_at > CURRENT_TIMESTAMP')->fetchColumn(),
        'mailed' => (int) $pdo->query('SELECT count(*) FROM user_tokens WHERE consumed_at IS NULL AND expires_at > CURRENT_TIMESTAMP')->fetchColumn(),
        'sequence' => $pdo->query('SELECT last_value FROM users_id_seq')->fetchColumn(),
        'record' => count(recordLines()),
        'audit' => (int) $pdo->query("SELECT count(*) FROM anotoki_audit_log WHERE action LIKE 'account.%'")->fetchColumn(),
    ];
}

/** The mails to an address with this subject. */
function mailsWithSubject(string $email, string $subject): int
{
    $found = 0;
    foreach (glob(testEnvironment()['mail'] . DIRECTORY_SEPARATOR . '*.eml') ?: [] as $file) {
        $text = (string) file_get_contents($file);
        if (str_contains($text, "To: $email\n") && str_contains($text, "Subject: $subject\n")) {
            $found++;
        }
    }

    return $found;
}

/**
 * A Backups of this IAM's that may restore - the tests' stand-in for the owner
 * putting a backup back by hand (php backup.php --extract and psql): the
 * IAM's own never restores.
 */
function restorer(): Backups
{
    return new Backups(static fn(): PDO => iamBackupConnection(), iamStorageDir() . '/backups', [
        'site' => 'iam',
        'migrator' => static fn(PDO $pdo): Migrator => iamMigrator($pdo),
        'encryption' => Keys::derive(iamMasterKey(), 'iam-backup'),
        'restore' => true,
    ]);
}

// ─────────────────────────────────────────────────────────────────────────────

test('every deletion asked for, cancelled and done is a line of the record, once it committed: ids and the database\'s times, nothing of the person', function () {
    $id = makeUser('leaving', 'leaving@example.test', PASSWORD);
    $browser = signedIn('leaving@example.test', '10.20.1.1');

    same(200, $browser->post('/api/account/deletion', ['password' => PASSWORD])->status, 'asked');
    same([['at' => recordTimeOf($id, 'deletion_requested_at'), 'event' => 'requested', 'user_id' => $id, 'due' => recordTimeOf($id, 'deletion_due_at')]], recordLines(),
        'the request: when it was asked, which account, and when it is due - to the microsecond, as the database holds them');
    same(409, $browser->post('/api/account/deletion', ['password' => PASSWORD])->status, 'asked twice: refused');
    same(1, count(recordLines()), 'and no line for it');

    same(200, $browser->delete('/api/account/deletion')->status, 'cancelled');
    $cancelled = recordLines()[1] ?? [];
    same(['event' => 'cancelled', 'user_id' => $id], ['event' => $cancelled['event'] ?? null, 'user_id' => $cancelled['user_id'] ?? null], 'the cancellation');
    same(['at', 'event', 'user_id'], array_keys($cancelled), 'and nothing else');
    same(409, $browser->delete('/api/account/deletion')->status, 'cancelled with nothing asked: refused');
    same(2, count(recordLines()), 'and no line for it');

    same(200, $browser->post('/api/account/deletion', ['password' => PASSWORD])->status, 'asked again');
    iamDb()->exec("UPDATE users SET deletion_requested_at = CURRENT_TIMESTAMP - INTERVAL '30 days 1 minute', deletion_due_at = CURRENT_TIMESTAMP - INTERVAL '1 minute' WHERE id = {$id}");
    same(true, anonymizeAccount(iamDb(), $id), 'done when due');
    same(['at' => recordTimeOf($id, 'anonymized_at'), 'event' => 'anonymized', 'user_id' => $id], recordLines()[3] ?? null, 'the anonymization, at its own time');
    same(4, count(recordLines()), 'four events, in the order they happened');

    $text = (string) file_get_contents(accountDeletionRecordFile());
    check(!str_contains($text, 'leaving'), 'neither the name nor the address in it');
    $folder = dirname(accountDeletionRecordFile());
    check(str_contains((string) @file_get_contents($folder . '/.htaccess'), 'Require all denied') && is_file($folder . '/index.html'), 'its folder never served, like the library\'s');
});

test('the owner claiming an account never confirmed cancels the deletion its registrant asked for: a line too', function () {
    $id = makeUser('squatter', 'squatted@example.test', 'the squatters password', false);
    iamDb()->exec("UPDATE users SET deletion_requested_at = CURRENT_TIMESTAMP, deletion_due_at = CURRENT_TIMESTAMP + INTERVAL '30 days' WHERE id = {$id}");
    $before = count(recordLines());

    (new Browser('10.20.2.1'))->post('/api/password/forgot', ['email' => 'squatted@example.test']);
    $files = glob(testEnvironment()['mail'] . DIRECTORY_SEPARATOR . '*.eml') ?: [];
    sort($files);
    preg_match('/token=([0-9a-f]{64})/', (string) file_get_contents((string) end($files)), $link);
    same(200, (new Browser('10.20.2.2'))->post('/api/password/reset', ['token' => $link[1] ?? '', 'password' => 'the real owners password'])->status, 'the mailbox\'s owner resets');

    same(null, recordTimeOf($id, 'deletion_due_at'), 'the registrant\'s deletion is gone');
    same([$before + 1, 'cancelled', $id], [count(recordLines()), recordLines()[$before]['event'] ?? null, recordLines()[$before]['user_id'] ?? null], 'and the record says so');
});

test('a record that cannot be written is said loudly in the error log - the event, its account\'s id and time - and never fails the person\'s request', function () {
    $file = accountDeletionRecordFile();
    $kept = (string) file_get_contents($file);
    unlink($file);
    mkdir($file);   // a folder where the file should be: nothing can be added to it
    try {
        $id = makeUser('unrecorded', 'unrecorded@example.test', PASSWORD);
        $browser = signedIn('unrecorded@example.test', '10.20.3.1');
        same(200, $browser->post('/api/account/deletion', ['password' => PASSWORD])->status, 'asked all the same');
        check(recordTimeOf($id, 'deletion_due_at') !== null, 'and the deletion is made');

        $log = implode('', array_map(static fn(string $day): string => (string) file_get_contents($day), glob(iamStorageDir() . '/logs/errors-*.jsonl') ?: []));
        check(str_contains($log, 'The account deletion record') && str_contains($log, "requested of account {$id}"), 'said in the error log: ' . substr($log, -400));
        check(!str_contains($log, 'unrecorded@example.test') && !str_contains($log, 'unrecorded,'), 'with nothing of the person');
    } finally {
        rmdir($file);
        file_put_contents($file, $kept);
    }
});

test('the replay: a database put back from before a request, an anonymization and a cancelled request - the replay does them again, in order, and ends every way back in; a dry run changes nothing, a second run nothing more', function () {
    $pdo = iamDb();
    $asker = makeUser('asker', 'asker@example.test', PASSWORD);
    $goner = makeUser('goner', 'goner@example.test', PASSWORD);
    $changer = makeUser('changer', 'changer@example.test', PASSWORD);
    // The goner's deletion was asked for long before the backup, and is due.
    $pdo->exec("UPDATE users SET deletion_requested_at = CURRENT_TIMESTAMP - INTERVAL '31 days', deletion_due_at = CURRENT_TIMESTAMP - INTERVAL '1 day' WHERE id = {$goner}");
    // A session, a remembered browser and a mailed link that live in the backup - an administrator's.
    grantRole(makeUser('stays', 'stays@example.test', PASSWORD), 'iam', 'ADMIN');
    $stays = signedIn('stays@example.test', '10.20.4.1');
    $stays->post('/api/password/forgot', ['email' => 'stays@example.test']);

    $backup = iamBackups()->create(Backups::MANUAL, 'before the events');
    check(is_array($backup), 'a backup: ' . json_encode($backup));
    $since = new DateTimeImmutable((string) iamBackups()->manifest($backup['id'])['snapshot_at']);
    $sinceText = $since->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z');

    // After it: the asker asks, the goner goes, the changer asks and changes their mind.
    same(200, signedIn('asker@example.test', '10.20.4.2')->post('/api/account/deletion', ['password' => PASSWORD])->status, 'the asker asks');
    same(true, anonymizeAccount($pdo, $goner), 'the goner is deleted');
    same(1, mailsWithSubject('goner@example.test', FAREWELL), 'and told so, once');
    $changing = signedIn('changer@example.test', '10.20.4.3');
    same(200, $changing->post('/api/account/deletion', ['password' => PASSWORD])->status, 'the changer asks');
    same(200, $changing->delete('/api/account/deletion')->status, 'and cancels');
    $truth = deletionStates([$asker, $goner, $changer]);

    // The database lost, and the backup put back by hand.
    $restored = restorer()->restore($backup['id']);
    check(is_array($restored), 'restored: ' . json_encode($restored));
    same('goner', $pdo->query("SELECT username FROM users WHERE id = {$goner}")->fetchColumn(), 'restored, the goner\'s name and address are back - what the record is for');
    same([null, null, false], deletionStates([$asker])[$asker], 'and the asker\'s request is gone');

    // A dry run: what it would do, and nothing changed.
    $before = everything($pdo);
    [$status, $out, $err] = command(['--replay', "--since={$sinceText}", '--dry-run']);
    same([0, ''], [$status, $err], 'a dry run: ' . $out);
    check(str_contains($out, "requested   #{$asker}") && str_contains($out, 'would be asked again'), 'it says what it would do: ' . $out);
    check(str_contains($out, "anonymized  #{$goner}") && str_contains($out, 'would be deleted again now'), 'to each account, by its id: ' . $out);
    check(!str_contains($out, 'asker@') && !str_contains($out, 'goner@'), 'ids only');
    check(str_contains($out, 'Live administrators of the IAM afterwards: 1.') && !str_contains($out, 'WARNING'), 'and the administrators it would leave - one, so no warning: ' . $out);
    same($before, everything($pdo), 'and changes nothing');

    // The replay.
    [$status, $out, $err] = command(['--replay', "--since={$sinceText}"]);
    same([0, ''], [$status, $err], 'replayed: ' . $out);
    same($truth, deletionStates([$asker, $goner, $changer]), 'the asker asked with the very times of the record, the goner deleted again, the changer not');
    same('deleted-' . $goner, $pdo->query("SELECT username FROM users WHERE id = {$goner}")->fetchColumn(), 'the goner nobody again');
    same(1, mailsWithSubject('goner@example.test', FAREWELL), 'with no second last mail');
    same([0, 0, 0, 0], array_values(array_intersect_key(everything($pdo), array_flip(['sessions', 'tokens', 'devices', 'mailed']))), 'every session, token, remembered browser and mailed link ended');
    same(401, $stays->get('/api/account')->status, 'a session from before the backup is over: everybody signs in again');
    $entry = $pdo->query("SELECT details FROM anotoki_audit_log WHERE action = 'account.deletions_replayed' ORDER BY id DESC LIMIT 1")->fetchColumn();
    same(1, json_decode((string) $entry, true)['anonymized'] ?? null, 'the audit log keeps the replay');

    // A second run: nothing more.
    $after = everything($pdo);
    [$status, $out] = command(['--replay', "--since={$sinceText}"]);
    same(0, $status, 'run again: ' . $out);
    same($after, everything($pdo), 'a second run changes nothing more, and writes no line');
    check(str_contains($out, 'Asked again: 0, cancelled again: 0, deleted again: 0'), 'it says so: ' . $out);
});

test('a copy of the record replayed (--record=): an account made after the backup keeps its id from being issued again; the replay\'s own lines go into the copy; a line that is no event is passed over; --list shows ids and times', function () {
    $pdo = iamDb();
    $present = makeUser('in-the-copy', 'in-the-copy@example.test', PASSWORD);
    $highest = (int) $pdo->query('SELECT max(id) FROM users')->fetchColumn();
    $copy = testEnvironment()['base'] . DIRECTORY_SEPARATOR . 'record-copy.jsonl';
    $now = gmdate('Y-m-d\TH:i:s.000000\Z');
    $due = gmdate('Y-m-d\TH:i:s.000000\Z', time() + 30 * 86400);
    file_put_contents($copy, implode("\n", [
        json_encode(['at' => $now, 'event' => 'requested', 'user_id' => $highest + 7, 'due' => $due]),
        json_encode(['at' => $now, 'event' => 'anonymized', 'user_id' => $highest + 7]),
        'not an event at all',
        json_encode(['at' => $now, 'event' => 'forgotten', 'user_id' => 1]),
        json_encode(['at' => $now, 'event' => 'requested', 'user_id' => $present, 'due' => $due]),
    ]) . "\n");
    $ownBefore = count(recordLines());

    [$status, $out, $err] = command(['--list', "--record={$copy}"]);
    same(0, $status, $out);
    same("2 line(s) of the record are no event: passed over.\n", $err, 'the lines that are no event, counted');
    check(str_contains($out, '#' . ($highest + 7)) && str_contains($out, "3 event(s).\n"), 'its events, by id: ' . $out);

    [$status, $out] = command(['--replay', '--since=' . gmdate('Y-m-d'), "--record={$copy}"]);
    same(0, $status, $out);
    check(str_contains($out, 'not in this database'), 'an account the database does not hold: ' . $out);
    $next = (int) $pdo->query("SELECT nextval('users_id_seq')")->fetchColumn();
    check($next > $highest + 7, "the next account gets an id past it: {$next}");

    same([$now, $due], [recordTimeOf($present, 'deletion_requested_at'), recordTimeOf($present, 'deletion_due_at')], 'an account the database holds: asked again, with the copy\'s times');
    $copyLines = array_values(array_filter(explode("\n", (string) file_get_contents($copy)), static fn(string $line): bool => $line !== ''));
    $added = (array) json_decode((string) end($copyLines), true);
    same([6, 'requested', $present, true], [count($copyLines), $added['event'] ?? null, $added['user_id'] ?? null, $added['replayed'] ?? null], 'the replay\'s line went into the copy it read');
    same($ownBefore, count(recordLines()), 'and none into this IAM\'s own record');
});

test('the replay puts the events in the order they happened - by their own times, a request made again by its first asking - not the order their lines stand in the file: an account\'s last event decides', function () {
    $x = makeUser('order-x', 'order-x@example.test', PASSWORD);
    $y = makeUser('order-y', 'order-y@example.test', PASSWORD);
    $z = makeUser('order-z', 'order-z@example.test', PASSWORD);
    $at = static fn(int $minutesAgo): string => gmdate('Y-m-d\TH:i:s', time() - $minutesAgo * 60) . '.000000Z';
    $due = static fn(int $minutesAgo): string => gmdate('Y-m-d\TH:i:s', time() - $minutesAgo * 60 + 30 * 86400) . '.000000Z';
    [$xAsked, $xDue, $yAsked, $yDue] = [$at(60), $due(60), $at(40), $due(40)];
    $copy = testEnvironment()['base'] . DIRECTORY_SEPARATOR . 'record-out-of-order.jsonl';
    file_put_contents($copy, implode("\n", array_map(static fn(array $event): string => (string) json_encode($event), [
        // X asked, then cancelled - the cancellation's line written first.
        ['at' => $at(50), 'event' => 'cancelled', 'user_id' => $x],
        ['at' => $xAsked, 'event' => 'requested', 'user_id' => $x, 'due' => $xDue],
        // Y cancelled, then asked again - the request's line written first.
        ['at' => $yAsked, 'event' => 'requested', 'user_id' => $y, 'due' => $yDue],
        ['at' => $at(45), 'event' => 'cancelled', 'user_id' => $y],
        // Z: a request a replay made again - its line written then, its asking long before - and a cancellation between the two.
        ['at' => $at(10), 'event' => 'requested', 'user_id' => $z, 'due' => $due(90), 'requested_at' => $at(90), 'replayed' => true],
        ['at' => $at(30), 'event' => 'cancelled', 'user_id' => $z],
    ])) . "\n");

    [$status, $out, $err] = command(['--replay', '--since=' . gmdate('Y-m-d\TH:i:s\Z', time() - 7200), "--record={$copy}"]);
    same([0, ''], [$status, $err], 'replayed: ' . $out);
    same([null, null, false], deletionStates([$x])[$x], 'X: asked, then cancelled - not asked, though the cancellation stands first in the file');
    same([$yAsked, $yDue, false], deletionStates([$y])[$y], 'Y: cancelled, then asked - asked, with that request\'s own times');
    same([null, null, false], deletionStates([$z])[$z], 'Z: asked, then cancelled - a request made again counts from when it was asked, not from when the replay wrote it');
    $request = strpos($out, "{$xAsked}  requested   #{$x}  due {$xDue}  a later event of the account decides");
    check($request !== false && $request < (int) strpos($out, "cancelled   #{$x}"), 'printed in the order it happened, the earlier event giving way: ' . $out);
});

test('the replay says how many live administrators the restored IAM has, and warns when it leaves none - an administrator made after the backup is not in it, and the one in it asked for a deletion since', function () {
    $pdo = iamDb();
    // In the backup, the IAM's one administrator.
    $pdo->exec("DELETE FROM user_roles WHERE role_id IN (SELECT r.id FROM application_roles r JOIN applications a ON a.id = r.application_id WHERE a.client_id = 'iam' AND r.name = 'ADMIN')");
    $first = makeUser('first-admin', 'first-admin@example.test', PASSWORD);
    grantRole($first, 'iam', 'ADMIN');
    $backup = iamBackups()->create(Backups::MANUAL, 'one administrator');
    check(is_array($backup), 'a backup: ' . json_encode($backup));
    $since = (new DateTimeImmutable((string) iamBackups()->manifest($backup['id'])['snapshot_at']))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z');

    // After it: a second administrator, so the first may ask for a deletion - one stays.
    grantRole(makeUser('second-admin', 'second-admin@example.test', PASSWORD), 'iam', 'ADMIN');
    same(200, signedIn('first-admin@example.test', '10.20.7.1')->post('/api/account/deletion', ['password' => PASSWORD])->status, 'the first asks: the second administers on');
    same(1, iamAdminCount(), 'one live administrator');

    // The backup put back: the second is not in it, and the record asks the first's deletion again.
    check(is_array(restorer()->restore($backup['id'])), 'restored');
    [$status, $out, $err] = command(['--replay', "--since={$since}", '--dry-run']);
    same([0, ''], [$status, $err], 'a dry run: ' . $out);
    check(str_contains($out, 'Live administrators of the IAM afterwards: 0.') && str_contains($out, 'WARNING: the replay would leave the restored IAM with no live administrator'), 'the dry run warns: ' . $out);
    same(1, iamAdminCount(), 'and changes nothing: the first administers until the replay');

    [$status, $out, $err] = command(['--replay', "--since={$since}"]);
    same([0, ''], [$status, $err], 'replayed: ' . $out);
    check(str_contains($out, 'WARNING: the replay leaves the restored IAM with no live administrator') && str_contains($out, 'When the database is lost'), 'the replay warns, and says where the way back is: ' . $out);
    same(0, iamAdminCount(), 'none: the first\'s deletion is asked again');
    same(0, json_decode((string) $pdo->query("SELECT details FROM anotoki_audit_log WHERE action = 'account.deletions_replayed' ORDER BY id DESC LIMIT 1")->fetchColumn(), true)['administrators'] ?? null,
        'the audit log keeps it too');

    // The way back it names: the first signs in - the IAM's own sign-in works for a deletion pending - and cancels.
    same(200, signedIn('first-admin@example.test', '10.20.7.2')->delete('/api/account/deletion')->status, 'the first cancels');
    same(1, iamAdminCount(), 'an administrator again');
    [, $out] = command(['--replay', "--since={$since}", '--dry-run']);
    check(str_contains($out, 'Live administrators of the IAM afterwards: 1.') && !str_contains($out, 'WARNING'), 'and the replay, asked again, says so - the cancellation is the last event now: ' . $out);
});

test('the replay refuses while migrations wait, and is asked rightly or not at all', function () {
    $pdo = buildIamThrough('_old', '018_audit_log_into_the_library.sql');
    $refused = replayAccountDeletions($pdo, [], new DateTimeImmutable('2026-10-01T00:00:00Z'), false);
    check(isset($refused['refused']) && str_contains($refused['refused'], '019_authenticator_pause.sql') && str_contains($refused['refused'], 'php migration.php'), 'refused, naming what waits: ' . json_encode($refused));

    same(2, command(['--replay'])[0], 'a replay with no --since');
    same(2, command(['--replay', '--since=last tuesday'])[0], 'a --since that is no time');
    same(2, command([])[0], 'nothing asked');
    same(1, command(['--list', '--record=' . testEnvironment()['base'] . '/no-such-record.jsonl'])[0], 'a record that is not there');
    same(0, command(['--help'])[0], '--help');
});

finishTests();
