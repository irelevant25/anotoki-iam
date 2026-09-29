<?php

/**
 * What the installer's tests share: setup.php loaded for its functions, a copy
 * of it for the installer to delete in place of the real one, and requests
 * made the way a browser on this computer - or on the internet - makes them.
 *
 * Not a test file itself (run.php runs *.test.php only).
 */

declare(strict_types=1);

define('IAM_SETUP_NO_RUN', true);
require __DIR__ . '/../setup.php';

/** A browser on this computer, talking to `php -S localhost:8100`. */
const LOCAL_SERVER = ['REMOTE_ADDR' => '127.0.0.1', 'HTTP_HOST' => 'localhost:8100'];

/** A visitor on the internet, talking to a real host over https. */
const REMOTE_SERVER = ['REMOTE_ADDR' => '203.0.113.7', 'HTTP_HOST' => 'iam.example.test', 'HTTPS' => 'on'];

/** The installer's folders are this run's; `self`, which a real host's install deletes, is a copy. */
function setupTestContext(): array
{
    static $context = null;

    if ($context === null) {
        $copy = testEnvironment()['base'] . DIRECTORY_SEPARATOR . 'setup.php';
        copy(dirname(__DIR__) . DIRECTORY_SEPARATOR . 'setup.php', $copy);
        $context = ['config' => IAM_CONFIG_DIR, 'storage' => IAM_STORAGE_DIR, 'self' => $copy];
    }

    return $context;
}

/** One request to the installer: a form post, a JSON "Test connection", or a GET. */
function setupRequest(string $method, array $server, array $post = [], ?array $json = null, array $query = []): array
{
    $server += ['REQUEST_METHOD' => $method];
    $body = '';

    if ($json !== null) {
        $server['CONTENT_TYPE'] = 'application/json';
        $body = (string) json_encode($json);
    }

    return setupHandle($server, $query, $post, $body, setupTestContext());
}

/** The nonce a development form carries. */
function nonceFrom(array $answer): string
{
    return preg_match('/name="nonce" value="([0-9a-f]{64})"/', $answer['body'], $found) ? $found[1] : '';
}

/** A "Test connection" answer: {ok, tone, message}. */
function setupJsonAnswer(array $answer): array
{
    $decoded = json_decode($answer['body'], true);
    check(is_array($decoded) && isset($decoded['tone'], $decoded['message']), 'a JSON answer, got: ' . substr($answer['body'], 0, 200));

    return $decoded;
}
