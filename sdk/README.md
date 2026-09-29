# The anotoki IAM SDKs

Two small files every anotoki site copies in. No package manager, no build
step: copy them, note the version from the header, and copy them again when the
version changes.

| File | For | Version |
| --- | --- | --- |
| `js/anotoki-auth.mjs` | The browser: sign-in with PKCE, token storage and refresh, logout. ES module. | 1.1.1 |
| `js/anotoki-auth.js` | The same, as a classic script defining `window.AnotokiAuth`. **Generated** - `node sdk/js/build.mjs` after editing the `.mjs`. | 1.1.1 |
| `js/anotoki-auth.d.ts` | Types, for TypeScript importing the `.mjs`. | 1.1.1 |
| `php/anotoki_iam.php` | A site's backend: verifies access tokens against the IAM's published keys. Plain functions, no dependencies, parses under PHP 7.4, nothing deprecated up to PHP 8.5. | 1.0.1 |

The contract they implement is [../docs/integrating-an-app.md](../docs/integrating-an-app.md).

## Browser

```js
import { createAuth } from './vendor/anotoki-auth.mjs';

const auth = createAuth({
  issuer: 'http://localhost:4300',            // the IAM's address; on a real host, its public URL
  clientId: 'piano-academy',
  redirectUri: location.origin + '/auth/callback',
  postLogoutRedirectUri: location.origin + '/',
});

const { returnTo, error } = await auth.init();   // finishes a callback, or restores the session
```

Then `auth.getAccessToken()`, `auth.fetch(url, init)`, `auth.login()`,
`auth.logout()`, `auth.user()`, `auth.onChange(cb)`; for an HTTP layer of its
own (an Angular interceptor), `auth.refresh()` after a 401. The `.d.ts` says what
each does.

Every anotoki site lands the sign-in on `/auth/callback`. A single-page app lets
`init()` replace the URL before its router starts; a multi-page site serves a small
page there that runs `init()` and then `location.replace(returnTo || '/')`.

The issuer in the examples is the development one - the IAM's pages on
`http://localhost:4300`. A site reads it from its own configuration, so the
same code signs in through the hosted IAM once that names the public address.

## PHP

```php
require_once __DIR__ . '/anotoki_iam.php';

$config = ['issuer' => 'http://localhost:4300', 'client_id' => 'piano-academy', 'cache_dir' => __DIR__ . '/../storage/cache'];
$token  = anotoki_iam_bearer_token(anotoki_iam_authorization_header());
$claims = $token === null ? null : anotoki_iam_verify_access_token($token, $config, $reason);

$userId = $claims ? anotoki_iam_user_id($claims) : null;   // int
$roles  = $claims ? anotoki_iam_roles($claims) : [];       // ['ADMIN', ...]
```

Under Apache as CGI/FastCGI the `Authorization` header is dropped unless it is
passed on - in the site's `.htaccess`:

```apache
CGIPassAuth On
SetEnvIf Authorization "(.*)" HTTP_AUTHORIZATION=$1
```

### Testing a site without the IAM

```php
$pair = anotoki_iam_test_keypair();
file_put_contents($dir . '/jwks.json', json_encode($pair['jwks']));
$config = ['issuer' => 'https://iam.test', 'client_id' => 'my-app', 'jwks_file' => $dir . '/jwks.json'];
$token = anotoki_iam_test_sign(anotoki_iam_test_claims($config, 7, ['MEMBER']), $pair['private_pem'], $pair['kid']);
```

`php sdk/php/test.php` is the verifier's own suite: the good token and every way
a token can be wrong.
