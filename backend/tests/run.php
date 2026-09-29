<?php

/**
 * php tests/run.php - every tests/*.test.php, each in a process of its own.
 *
 * A process each because each file boots the app against its own throwaway
 * database, and the app is built once per process. The exit status is non-zero
 * when any file fails.
 */

$files = glob(__DIR__ . '/*.test.php') ?: [];
sort($files);
$failed = [];

foreach ($files as $file) {
    echo "\n== " . basename($file) . "\n";
    passthru(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($file), $status);
    if ($status !== 0) {
        $failed[] = basename($file);
    }
}

echo $failed === [] ? "\nEvery test file passed.\n" : "\nFailed: " . implode(', ', $failed) . "\n";
exit($failed === [] ? 0 : 1);
