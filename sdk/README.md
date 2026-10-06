# The anotoki IAM SDKs

Two small files every anotoki site copies in. No package manager, no build
step: copy them, note the version from the header, and copy them again when the
version changes.

| File | For | Version |
| --- | --- | --- |
| `js/anotoki-auth.mjs` | The browser: sign-in with PKCE, token storage and refresh, logout, the account's preferences. ES module. | 1.2.0 |
| `js/anotoki-auth.js` | The same, as a classic script defining `window.AnotokiAuth`. **Generated** - `node sdk/js/build.mjs` after editing the `.mjs`. | 1.2.0 |
| `js/anotoki-auth.d.ts` | Types, for TypeScript importing the `.mjs`. | 1.2.0 |
| `php/anotoki_iam.php` | A site's backend: verifies access tokens against the IAM's published keys, and reads whether the IAM says the site requires sign-in. Plain functions, no dependencies, parses under PHP 7.4, nothing deprecated up to PHP 8.5. | 1.1.0 |

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
own (an Angular interceptor), `auth.refresh()` after a 401. `auth.user().preferences`
is the account's theme, date order and clock, which every site follows, and
`auth.savePreferences({ theme })` changes them for every site
([../docs/integrating-an-app.md](../docs/integrating-an-app.md)). The `.d.ts` says what
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

### Is sign-in required here? (1.1.0)

Whether a site asks everybody to sign in, or lets visitors use it, is switched
per application in the IAM's admin panel. The IAM publishes it at
`GET {issuer}/api/public/applications/{client_id}`: 200 `{"client_id", "name",
"login_required"}`, or 404 for an application it does not know, has switched
off, or its own entry (`iam`). A site never fetches that itself:

```php
$required = anotoki_iam_login_required($config, $reason);   // bool; $reason is for a log line
```

and relays it to its own frontend (`loginRequired` in its `/api/auth/config`),
so the browser never asks the IAM.

**It fails closed.** True - sign-in required - unless the IAM said otherwise:

| The IAM's answer | Returned | Kept |
| --- | --- | --- |
| 200 naming this `client_id`, with a JSON boolean `login_required` | that value | yes |
| 404 (unknown or switched-off application; also an IAM from before this contract) | `true` | yes, as `true` |
| no answer, a 5xx, a redirect, anything else - an answer for another client, garbage, a `login_required` that is not a boolean | the last value kept, or `true` when none was ever read | the failure's time |

- The answer is kept on disk in `cache_dir` for `application_ttl` seconds (60).
  After a failure the IAM is not asked again for as long; meanwhile the last
  value (or `true`) stands.
- One request asks at a time: the one that finds the kept answer old claims the
  refresh (a lock nobody waits for, and a claim written into the kept answer);
  the others go on with the last value.
- It waits at most `application_timeout` seconds (2) - not `http_timeout`,
  which is the key set's.
- The answer holds for the rest of the request. Something that serves several
  requests in one process - a test suite driving the app in-process, a worker -
  calls `anotoki_iam_login_required_reset()` before each.
- A cache folder that cannot be written still gives an answer (from what was
  read, failing closed), but every request then asks the IAM; it is logged
  (`error_log`).

**Give it a private `cache_dir`** - a folder of the site's own, outside the web
root, that only the site's PHP user can write (the same one as the key set's,
e.g. `storage/cache`). The kept answer is what opens a site to visitors: in the
system's shared temp directory, which is the default, anybody else on the host
could write `login_required: false` into it.

| Key | Default | |
| --- | --- | --- |
| `issuer`, `client_id` | required | as for tokens; without them the answer is `true` (`not_configured`) |
| `cache_dir` | the system temp dir | **set it** - see above |
| `application_ttl` | 60 | seconds an answer is kept, and a failure waited out |
| `application_timeout` | 2 | seconds to wait for the IAM |
| `application_file` | - | tests: the IAM's answer read from this file (below) |

`$reason` is one of `fresh`, `read`, `unknown_application`, `stale`,
`never_read`, `backing_off`, `refreshing`, `not_configured`.

### For a site that lets visitors in: who a request is

A site whose sign-in the owner may switch off keeps visitors' data under a key
the browser sends (`X-Visitor-Key`). Who a request is, in this order:

1. **An `Authorization: Bearer ...` header** - with or without a token after it -
   makes the request a person's: a verified token, or 401. A bad or expired token
   never falls back to the visitor's key; the key is not even read.
   `anotoki_iam_sent_bearer($header)` says whether the header claims that.
   **Only the Bearer scheme counts**: `Basic ...`, which a host protected by a
   password file sends with every request, is no claim to be anybody.
2. Else, when `anotoki_iam_login_required()` says true: 401 `sign_in_required`
   on everything per person. The visitor's key is never looked up.
3. Else the visitor's key, if one was sent.
4. Else nobody.

A visitor never passes a check that means "a verified token" (an admin area,
exporting or importing one's own data).

### Testing a site without the IAM

```php
$pair = anotoki_iam_test_keypair();
file_put_contents($dir . '/jwks.json', json_encode($pair['jwks']));
$config = ['issuer' => 'https://iam.test', 'client_id' => 'my-app', 'jwks_file' => $dir . '/jwks.json'];
$token = anotoki_iam_test_sign(anotoki_iam_test_claims($config, 7, ['EDITOR']), $pair['private_pem'], $pair['kid']);
```

For the sign-in switch, `'application_file' => $dir . '/application.json'`
replaces asking the IAM, as `jwks_file` replaces the key set: the file's content
is the IAM's 200 answer, a missing file is an IAM out of reach. It is read on
every call - nothing kept, nothing remembered - so a test can switch it between
two requests:

```php
file_put_contents($dir . '/application.json', json_encode(['client_id' => 'my-app', 'name' => 'My app', 'login_required' => false]));
```

A test that needs a 404, a 503 or the IAM's absence over time points `issuer` at
the site's stand-in IAM instead.

`php sdk/php/test.php` is the PHP file's own suite, on PHP 7.4 and up: the good
token and every way a token can be wrong; every kind of answer about sign-in,
the kept answer, the memo, the seam, and - against a stand-in it starts on a
free local port - the fetches, the back-off and the single-flight claim.
