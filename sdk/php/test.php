<?php

/**
 * The verifier's own checks: php sdk/php/test.php
 *
 * Makes a key pair, publishes it as a JWKS file, and throws tokens at
 * anotoki_iam_verify_access_token() - the good one, and each way a token can be
 * wrong. Exit status 0 when every case comes out as it should.
 */

require __DIR__ . '/anotoki_iam.php';

$dir = sys_get_temp_dir() . '/anotoki-iam-sdk-test-' . bin2hex(random_bytes(4));
mkdir($dir);

$pair = anotoki_iam_test_keypair('test-key');
$other = anotoki_iam_test_keypair('test-key');   // same kid, different key: a forgery
file_put_contents($dir . '/jwks.json', json_encode($pair['jwks']));

$config = [
    'issuer' => 'https://iam.example.test',
    'client_id' => 'app',
    'jwks_file' => $dir . '/jwks.json',
];

$good = anotoki_iam_test_claims($config, 7, ['EDITOR', 'editor', 'ADMIN']);
$sign = static fn(array $claims, string $typ = 'at+jwt', ?array $key = null, string $alg = 'RS256') =>
    anotoki_iam_test_sign($claims, ($key ?? $pair)['private_pem'], ($key ?? $pair)['kid'], $typ, $alg);

$cases = [
    'a good token' => [$sign($good), true],
    'an ID token (typ JWT)' => [$sign($good, 'JWT'), false],
    'alg none in the header' => [$sign($good, 'at+jwt', null, 'none'), false],
    'signed by another key with the same kid' => [$sign($good, 'at+jwt', $other), false],
    'another issuer' => [$sign(['iss' => 'https://evil.test'] + $good), false],
    'another audience' => [$sign(['aud' => 'other-app'] + $good), false],
    'audience list containing this app' => [$sign(['aud' => ['other-app', 'app']] + $good), true],
    'expired' => [$sign(['exp' => time() - 120] + $good), false],
    'expired within the leeway' => [$sign(['exp' => time() - 30] + $good), true],
    'not valid yet' => [$sign(['nbf' => time() + 600] + $good), false],
    'no subject' => [$sign(['sub' => ''] + $good), false],
    'subject not a number' => [$sign(['sub' => 'admin'] + $good), false],
    'unknown kid' => [anotoki_iam_test_sign($good, $pair['private_pem'], 'nope'), false],
    'tampered payload' => [(function () use ($sign, $good) {
        [$h, , $s] = explode('.', $sign($good));
        return $h . '.' . anotoki_iam_base64url_encode(json_encode(['sub' => '1'] + $good)) . '.' . $s;
    })(), false],
    'garbage' => ['not.a.token', false],
    'two parts' => ['abc.def', false],
];

$failures = 0;

foreach ($cases as $name => [$token, $shouldPass]) {
    $claims = anotoki_iam_verify_access_token($token, $config, $reason);
    $passed = $claims !== null;
    $ok = $passed === $shouldPass;
    $failures += $ok ? 0 : 1;
    printf("%s %-42s %s\n", $ok ? 'ok  ' : 'FAIL', $name, $passed ? 'accepted' : 'refused: ' . $reason);
}

// The helpers.
$claims = anotoki_iam_verify_access_token($sign($good), $config);
$checks = [
    'user id is an int' => anotoki_iam_user_id($claims) === 7,
    'roles are cleaned' => anotoki_iam_roles($claims) === ['EDITOR', 'ADMIN'],
    'bearer is parsed' => anotoki_iam_bearer_token('bearer abc.def.ghi') === 'abc.def.ghi',
    'other schemes are not' => anotoki_iam_bearer_token('Basic abc') === null,
    'pem round trip' => anotoki_iam_jwk_to_pem($pair['jwks']['keys'][0]) !== null,
];

foreach ($checks as $name => $ok) {
    $failures += $ok ? 0 : 1;
    printf("%s %s\n", $ok ? 'ok  ' : 'FAIL', $name);
}

// The download path, against a JWKS served from a file:// URL is not possible
// through curl's HTTP-only defaults, so the cache is exercised directly: a
// cached set is used without any download.
$cacheConfig = ['issuer' => 'https://iam.example.test', 'client_id' => 'app', 'cache_dir' => $dir];
$cacheFile = $dir . '/anotoki-iam-jwks-' . sha1('https://iam.example.test/.well-known/jwks.json') . '.json';
file_put_contents($cacheFile, json_encode(['fetched_at' => time(), 'keys' => anotoki_iam_usable_keys($pair['jwks'])]));
$cached = anotoki_iam_verify_access_token($sign($good), $cacheConfig, $reason) !== null;
$failures += $cached ? 0 : 1;
printf("%s %s\n", $cached ? 'ok  ' : 'FAIL', 'verifies from the on-disk cache' . ($cached ? '' : ': ' . $reason));

array_map('unlink', glob($dir . '/*'));
rmdir($dir);

echo $failures === 0 ? "\nall good\n" : "\n$failures failed\n";
exit($failures === 0 ? 0 : 1);
