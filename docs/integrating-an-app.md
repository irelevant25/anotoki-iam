# Integrating an application with the anotoki IAM

Every anotoki site signs people in the same way: the site's frontend sends the
browser to the IAM, gets tokens back, and sends the access token to its own
backend, which checks it without calling the IAM. This page is the whole
contract; [api.md](api.md) has the endpoint details.

```
 browser                        IAM (localhost:4300)               site backend
 ───────                        ────────────────────               ────────────
 1. login()  ── redirect ──▶  /api/oidc/authorize
                               (signs in, or already signed in)
 2.          ◀── redirect ──   {redirect_uri}?code=…&state=…
 3. code + verifier ──POST──▶ /api/oidc/token
             ◀──────────────   access_token (10 min), refresh_token, id_token
 4. fetch /api/… with  Authorization: Bearer <access_token>  ──────────────▶
                                                                  verify RS256 signature
                                                                  against the cached JWKS,
                                                                  iss, aud, exp, typ
 5. before expiry: refresh_token ──POST──▶ /api/oidc/token  → new pair
```

## 1. Register the application

In the IAM admin console → **Applications** → **Add**, or in a migration (the
first-party ones are seeded by `backend/migrations/iam/002_first_party_applications.sql`):

| Field | For a browser app |
| --- | --- |
| `client_id` | short and fixed, e.g. `piano-academy` |
| `type` | `public` - a browser cannot keep a secret; PKCE protects the code instead |
| `redirect_uris` | exactly where the callback lands: `{frontend}/auth/callback`, e.g. `http://localhost:4500/auth/callback` |
| `post_logout_redirect_uris` | where to land after signing out: `{frontend}/` |
| `web_origins` | the site's origin, `{frontend}` - allowed to call the token endpoint |
| `access` | `everyone` (any account) or `members` (only accounts given a role here) |
| `roles` | the application's own roles, e.g. `ADMIN`, `EDITOR`, `MEMBER` |

The first-party sites arrive registered with their development addresses - the
port each one's dev server uses:

| Application | client_id | Frontend (ng serve) | Backend (php -S) |
| --- | --- | --- | --- |
| IAM | `iam` | `http://localhost:4300` | `:8100` |
| Genshin Impact | `genshin` | `http://localhost:4200` | `:8000` |
| Japanese Academy | `japanese-academy` | `http://localhost:4400` | `:8200` |
| Piano Academy | `piano-academy` | `http://localhost:4500` | `:8300` |
| Build Analyzer | `build-analyzer` | `http://localhost:4600` | `:8400` |

On a real host the installer asks for each site's production address and puts
it in place of these; the admin console changes them later. `php clients.php --dev`
(in the IAM's `backend/`) puts the development ones back on a development
database that has drifted.

## 2. The frontend: the JavaScript SDK

`sdk/js/anotoki-auth.mjs` (ES module), `sdk/js/anotoki-auth.js` (classic script,
defines `window.AnotokiAuth`) and `sdk/js/anotoki-auth.d.ts` (types). No
dependencies, no build step. **Copy them into the application** (vendored, like
any other third-party file) and note the SDK version from the file header.

```js
import { createAuth } from './vendor/anotoki-auth.mjs';
// or, as a classic script: const { createAuth } = window.AnotokiAuth;

const auth = createAuth({
  issuer: 'http://localhost:4300',     // from the site's own configuration; this is the development IAM
  clientId: 'piano-academy',
  redirectUri: location.origin + '/auth/callback',
  postLogoutRedirectUri: location.origin + '/',
});

// Once, before the app draws itself. Finishes a callback when this page load is
// one, and otherwise restores the session from storage (refreshing if needed).
const { returnTo } = await auth.init();
if (returnTo) history.replaceState(null, '', returnTo);

auth.isAuthenticated();          // boolean
auth.user();                     // { sub, preferred_username, email, email_verified, locale, roles } | null
await auth.getAccessToken();     // string | null - refreshed when under 30 s remain
auth.login({ returnTo: location.pathname + location.search });   // redirect to the IAM
auth.register({ returnTo });     // the same, opening on the registration form
auth.logout();                   // everywhere: the IAM ends its session, lands on postLogoutRedirectUri
auth.logout({ local: true });    // this site only: spends the refresh token, no navigation
auth.fetch('/api/progress', { method: 'PUT', body });            // fetch with the bearer, one retry after a refresh on 401
auth.onChange(() => redraw());   // signed in / out / refreshed, also from other tabs
auth.accountUrl();               // the IAM account page, for a "Manage account" link
```

What it does for you:

- **PKCE + state + nonce** for every login, kept in `sessionStorage` until the
  callback.
- **Tokens in `localStorage`** under `anotoki-auth:{clientId}`, so every tab and
  every page of a multi-page site shares one session. Refreshes are serialised
  across tabs with the Web Locks API, so two tabs never spend the same refresh
  token.
- **Checks the ID token's `iss`, `aud`, `nonce` and `exp`** on the way in. (The
  signature is the backend's job; the ID token only tells the frontend who is
  signed in.)

Angular applications import the `.mjs` beside its `.d.ts` from a core service and
wrap it in signals; the functional HTTP interceptor awaits `getAccessToken()`.

### The callback route

`redirectUri` must be a path the site serves (for an SPA, a route that renders
nothing but "Signing in…"). `init()` recognises it by its path plus the `code` /
`error` and `state` query parameters, exchanges the code, and hands back
`returnTo` - the address the person was on when they pressed "Sign in".

## 3. The backend: verifying the access token

The rule is short and all of it matters:

1. Read `Authorization: Bearer <token>`. Anything else is anonymous.
2. The header must say `alg: RS256` and `typ: at+jwt`. Never let the token choose
   the algorithm. `typ` is what stops an ID token being replayed as an access
   token.
3. Find the key by `kid` in the IAM's JWKS (`{issuer}/.well-known/jwks.json`),
   cached on disk for an hour; an unknown `kid` is worth one refetch, no more
   often than every five minutes.
4. Verify the signature with openssl.
5. `iss` must equal the configured issuer, `aud` must be (or contain) this
   application's `client_id`, `exp` must be in the future and `iat`/`nbf` not in
   it (60 seconds of leeway).
6. `sub` is the user id. `roles` are this application's roles for them.

`sdk/php/anotoki_iam.php` does exactly that with no dependencies, and parses under
PHP 7.4 so it drops into any of the PHP projects:

```php
require_once __DIR__ . '/anotoki_iam.php';

$config = [
    'issuer'    => 'http://localhost:4300',   // the development IAM; a site reads it from its own config
    'client_id' => 'piano-academy',
    'cache_dir' => __DIR__ . '/../storage/cache',   // JWKS cache
];

$token  = anotoki_iam_bearer_token($_SERVER['HTTP_AUTHORIZATION'] ?? null);
$claims = $token === null ? null : anotoki_iam_verify_access_token($token, $config, $reason);
// $claims: array | null ; $reason explains a null for logs, never for the client
```

For tests, `'jwks_file' => '/path/to/test-jwks.json'` replaces the download, and
`anotoki_iam_test_sign(array $claims, string $privatePem, string $kid)` mints a
token with a test key (see `sdk/README.md`).

### Users on the application's side

The IAM owns identity. An application keeps only what is its own - progress,
preferences - keyed by the user id, plus, if it wants to show names, a small
copy of `preferred_username` refreshed from the token as requests arrive. It
never stores passwords, and never decides who someone is from anything but a
verified token.

Revocation: an access token is good until it expires (10 minutes by default).
Signing out at the IAM, disabling an account, or taking away the last role in a
`members` application stops the next refresh, so the application is back to
anonymous within one token lifetime.

## 4. Running without the IAM

An application that the owner also runs as a private copy on their own
computer - the study apps: Japanese Academy, Piano Academy, the build analyzer -
can run with sign-in switched off. Then the backend acts as one configured user
(`local_user_id`, the owner's IAM id) with every role, and the frontend draws no
sign-in button. The mode is per deployment configuration, never a request
parameter; it answers only this computer (loopback, a local Host header), and a
hosted deployment always runs with the IAM.

It is a choice per application, not a requirement. The genshin site has no such
mode: it is one public site with an admin panel, and locally it signs in through
a locally running IAM like it does in production (its `DEV.md`).

## 5. Scripts

Scripts that call an application's API as a person (an importer, a benchmark)
use a **personal access token**: the IAM account page → *Tokens for scripts* →
choose the application, a name and an expiry; copy the `iam_pat_...` shown once.

```
POST {issuer}/api/oidc/token
grant_type=refresh_token&client_id=genshin&refresh_token=iam_pat_...
→ { "access_token": "eyJ...", "expires_in": 600, ... }
```

Then call the API with the access token as usual, and exchange again when it
expires. The token carries the person's current roles, so a revoked role or a
disabled account stops a script as well.
