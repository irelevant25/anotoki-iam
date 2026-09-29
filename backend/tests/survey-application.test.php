<?php

/**
 * Migration 005, the survey site: on a development IAM it arrives with its
 * development addresses, like every site in 002; on an IAM already installed
 * on a real host it arrives with none - a live IAM never sends a code to
 * localhost - and the IAM's administrators administer it.
 */

declare(strict_types=1);

require __DIR__ . '/lib.php';

bootTests();

/** The survey site's application, its addresses and roles, as a database holds them. */
function surveySite(PDO $pdo): array
{
    $site = $pdo->query("SELECT id, name, home_url, access, type, show_on_landing FROM applications WHERE client_id = 'survey'")->fetch();
    $uris = $pdo->prepare('SELECT kind, uri FROM application_redirect_uris WHERE application_id = ? ORDER BY kind');
    $uris->execute([$site['id']]);
    $origins = $pdo->prepare('SELECT origin FROM application_origins WHERE application_id = ?');
    $origins->execute([$site['id']]);
    $roles = $pdo->prepare('SELECT name FROM application_roles WHERE application_id = ? ORDER BY name');
    $roles->execute([$site['id']]);

    return $site + ['uris' => $uris->fetchAll(), 'origins' => $origins->fetchAll(PDO::FETCH_COLUMN), 'roles' => $roles->fetchAll(PDO::FETCH_COLUMN)];
}

test('a development IAM registers the survey site with its development addresses and its two roles', function () {
    $site = surveySite(iamDb());
    same('Surveys', $site['name'], 'the name');
    same(['everyone', 'public'], [$site['access'], $site['type']], 'open to every account, a browser client');
    same('http://localhost:4700', $site['home_url'], 'at its port in the table');
    same([['kind' => 'login', 'uri' => 'http://localhost:4700/auth/callback'], ['kind' => 'logout', 'uri' => 'http://localhost:4700/']], $site['uris'], 'its callback and landing');
    same(['http://localhost:4700'], $site['origins'], 'its origin');
    same(['ADMIN', 'EDITOR'], $site['roles'], 'its roles');
    same(true, (bool) $site['show_on_landing'], 'a card on the landing page, like the other sites');
});

test('an IAM on a real host gets the site without a localhost address, and its administrators administer it', function () {
    $name = throwawayDatabase(testEnvironment()['db'] . '_live');
    ensureDatabase(testServer(), $name);
    $pdo = connectDatabase(testServer(), $name, false);

    // An IAM installed before the survey site existed: everything up to 004, its own public address.
    foreach (migrationFiles('iam') as $file) {
        if ($file === '005_survey_application.sql') {
            break;
        }
        applyMigration($pdo, 'iam', $file);
    }
    $pdo->exec("UPDATE applications SET home_url = 'https://iam.example.test' WHERE client_id = 'iam'");
    $insert = $pdo->prepare('INSERT INTO users (username, email) VALUES (?, ?) RETURNING id');
    $insert->execute(['owner', 'owner@example.test']);
    $owner = (int) $insert->fetchColumn();
    $insert->execute(['player', 'player@example.test']);
    $player = (int) $insert->fetchColumn();
    $pdo->prepare("INSERT INTO user_roles (user_id, role_id) SELECT ?, r.id FROM application_roles r JOIN applications a ON a.id = r.application_id WHERE a.client_id = 'iam' AND r.name = 'ADMIN'")->execute([$owner]);

    applyMigration($pdo, 'iam', '005_survey_application.sql');

    $site = surveySite($pdo);
    same(null, $site['home_url'], 'no address until the admin console gives it one');
    same([], $site['uris'], 'no localhost callback on a live IAM');
    same([], $site['origins'], 'nor origin');
    same(false, (bool) $site['show_on_landing'], 'nor a card on the landing page that would lead nowhere');
    same(['ADMIN', 'EDITOR'], $site['roles'], 'its roles all the same');

    $roles = $pdo->prepare("SELECT u.username FROM user_roles ur JOIN users u ON u.id = ur.user_id JOIN application_roles r ON r.id = ur.role_id
                             JOIN applications a ON a.id = r.application_id WHERE a.client_id = 'survey' AND r.name = 'ADMIN'");
    $roles->execute();
    same(['owner'], $roles->fetchAll(PDO::FETCH_COLUMN), 'the IAM\'s administrator administers the survey site; nobody else got anything');
    check($player > 0, 'the ordinary account is there');
    $pdo = null;
});

test('a survey application made by hand before the migration is left as it is, and only gets its roles', function () {
    $name = throwawayDatabase(testEnvironment()['db'] . '_byhand');
    ensureDatabase(testServer(), $name);
    $pdo = connectDatabase(testServer(), $name, false);
    foreach (migrationFiles('iam') as $file) {
        if ($file === '005_survey_application.sql') {
            break;
        }
        applyMigration($pdo, 'iam', $file);
    }
    $pdo->exec("UPDATE applications SET home_url = 'https://iam.example.test' WHERE client_id = 'iam'");
    $pdo->exec("INSERT INTO applications (client_id, name, type, access, home_url) VALUES ('survey', 'Our surveys', 'public', 'members', 'https://survey.example.test')");

    applyMigration($pdo, 'iam', '005_survey_application.sql');

    $site = surveySite($pdo);
    same(['Our surveys', 'members', 'https://survey.example.test'], [$site['name'], $site['access'], $site['home_url']], 'what the console said stands');
    same(['ADMIN', 'EDITOR'], $site['roles'], 'and the roles the site needs are there');
    $pdo = null;
});

finishTests();
