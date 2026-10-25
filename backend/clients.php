<?php

/**
 * clients.php - the applications, from the command line
 *
 *   php clients.php            list them, with their addresses
 *   php clients.php --dev      give the anotoki sites their development addresses back
 *
 * A database built from the migrations already has them (migrations 002 and
 * 005); --dev is for one that has drifted - an address removed in the admin
 * panel, a database from before a site moved to its port in the table below.
 * It makes each site's addresses on this computer exactly these: what is
 * missing is added, any other localhost address is taken out, and anything
 * that is not on this computer is left alone. The home address follows while
 * it is empty or on this computer too. A site the database does not have yet
 * is skipped - `php migration.php` brings it.
 *
 * It refuses when the IAM's public URL is not itself on this computer: on a
 * live IAM a localhost redirect URI would hand a code to whatever happens to be
 * listening on the visitor's own machine.
 *
 * The ports are the ones every anotoki project's dev server uses, and every one
 * lands its sign-in on /auth/callback.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("clients.php is a command-line tool.\n");
}

require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/iam.php';

const DEV_CLIENTS = [
    'genshin' => 'http://localhost:4200',
    'japanese-academy' => 'http://localhost:4400',
    'piano-academy' => 'http://localhost:4500',
    'build-analyzer' => 'http://localhost:4600',
    'survey' => 'http://localhost:4700',
];

requireSetUpOnCli();

$pdo = iamDb();

if (in_array('--dev', $argv, true)) {
    if (!onThisComputer(iamIssuer())) {
        fwrite(STDERR, "The IAM's public URL is " . iamIssuer() . " - development addresses are only for a development IAM.\n");
        exit(1);
    }

    $pdo->beginTransaction();

    $home = $pdo->prepare('UPDATE applications SET home_url = ? WHERE id = ?');
    $iam = $pdo->query("SELECT id, home_url FROM applications WHERE client_id = 'iam'")->fetch();
    if ($iam && ($iam['home_url'] === null || onThisComputer($iam['home_url'])) && $iam['home_url'] !== iamIssuer()) {
        $home->execute([iamIssuer(), $iam['id']]);
        echo '  iam: home is ' . iamIssuer() . "\n";
    }

    foreach (DEV_CLIENTS as $clientId => $frontend) {
        $statement = $pdo->prepare('SELECT id, home_url FROM applications WHERE client_id = ?');
        $statement->execute([$clientId]);
        $application = $statement->fetch();

        if (!$application) {
            echo "  skipped {$clientId}: not in this database\n";
            continue;
        }

        $id = (int) $application['id'];
        $wanted = ['login' => [$frontend . '/auth/callback'], 'logout' => [$frontend . '/']];
        $changes = [];

        foreach ($wanted as $kind => $uris) {
            $current = $pdo->prepare('SELECT id, uri FROM application_redirect_uris WHERE application_id = ? AND kind = ?');
            $current->execute([$id, $kind]);
            foreach ($current->fetchAll() as $row) {
                if (onThisComputer($row['uri']) && !in_array($row['uri'], $uris, true)) {
                    $pdo->prepare('DELETE FROM application_redirect_uris WHERE id = ?')->execute([$row['id']]);
                    $changes[] = "- {$kind} {$row['uri']}";
                }
            }
            foreach ($uris as $uri) {
                $insert = $pdo->prepare('INSERT INTO application_redirect_uris (application_id, kind, uri) VALUES (?, ?, ?) ON CONFLICT DO NOTHING');
                $insert->execute([$id, $kind, $uri]);
                if ($insert->rowCount() > 0) {
                    $changes[] = "+ {$kind} {$uri}";
                }
            }
        }

        $origins = $pdo->prepare('SELECT id, origin FROM application_origins WHERE application_id = ?');
        $origins->execute([$id]);
        foreach ($origins->fetchAll() as $row) {
            if (onThisComputer($row['origin']) && $row['origin'] !== $frontend) {
                $pdo->prepare('DELETE FROM application_origins WHERE id = ?')->execute([$row['id']]);
                $changes[] = "- origin {$row['origin']}";
            }
        }
        $insert = $pdo->prepare('INSERT INTO application_origins (application_id, origin) VALUES (?, ?) ON CONFLICT DO NOTHING');
        $insert->execute([$id, $frontend]);
        if ($insert->rowCount() > 0) {
            $changes[] = "+ origin {$frontend}";
        }

        if (($application['home_url'] === null || onThisComputer($application['home_url'])) && $application['home_url'] !== $frontend) {
            $home->execute([$frontend, $id]);
            $changes[] = "  home {$frontend}";
        }

        echo "  {$clientId}: " . ($changes === [] ? 'already as in the table' : "\n      " . implode("\n      ", $changes)) . "\n";
    }

    $pdo->commit();
}

// The columns named: on a database without 010's login_required this stops
// with the database's error, never a listing that says "sign-in required: no".
foreach ($pdo->query('SELECT id, client_id, name, enabled, system, login_required, home_url FROM applications ORDER BY sort_order, client_id')->fetchAll() as $application) {
    // Who gets in is whether the site requires signing in and which of its
    // roles may sign in (migration 010; the access column before it went in
    // 027). Every client is public (011): nothing to say of its kind, whose
    // column 027 dropped too.
    $who = $application['system'] ? 'the IAM itself' : 'sign-in required: ' . ($application['login_required'] ? 'yes' : 'no');
    echo "\n{$application['client_id']}  ({$application['name']}, {$who}" . ($application['enabled'] ? '' : ', DISABLED') . ")\n";
    echo '  home: ' . ($application['home_url'] ?? '-') . "\n";

    if (!$application['system']) {
        $maySignIn = $pdo->prepare('SELECT name FROM application_roles WHERE application_id = ? AND may_sign_in ORDER BY name');
        $maySignIn->execute([$application['id']]);
        echo '  may sign in: ' . (implode(', ', $maySignIn->fetchAll(PDO::FETCH_COLUMN)) ?: 'no role') . "\n";
    }

    $uris = $pdo->prepare('SELECT kind, uri FROM application_redirect_uris WHERE application_id = ? ORDER BY kind, uri');
    $uris->execute([$application['id']]);
    foreach ($uris->fetchAll() as $row) {
        echo "  {$row['kind']}: {$row['uri']}\n";
    }

    $origins = $pdo->prepare('SELECT origin FROM application_origins WHERE application_id = ? ORDER BY origin');
    $origins->execute([$application['id']]);
    foreach ($origins->fetchAll(PDO::FETCH_COLUMN) as $row) {
        echo "  origin: {$row}\n";
    }
}
