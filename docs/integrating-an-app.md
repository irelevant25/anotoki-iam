# Integrating an application with the anotoki IAM

Every anotoki site signs people in the same way: the site's frontend sends the
browser to the IAM, gets tokens back, and sends the access token to its own
backend, which checks it without calling the IAM. The backend asks the IAM one
thing, at most once a minute: whether the site requires signing in ("Is
sign-in required here?" below). This page is the whole contract;
[api.md](api.md) has the endpoint details.

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

In the IAM admin panel → **Applications** → **Add**, or in a migration (the
first-party ones are seeded by `backend/migrations/iam/002_first_party_applications.sql`,
and the survey site by `005_survey_application.sql` beside it):

| Field | For a browser app |
| --- | --- |
| `client_id` | short and fixed, e.g. `piano-academy` |
| client type | always public (migration 011): no secret - PKCE protects the code, and a request carrying a secret is refused |
| `redirect_uris` | exactly where the callback lands: `{frontend}/auth/callback`, e.g. `http://localhost:4500/auth/callback` |
| `post_logout_redirect_uris` | where to land after signing out: `{frontend}/` |
| `web_origins` | the site's origin, `{frontend}` - allowed to call the token endpoint |
| `login_required` | whether the site sends everybody to sign in (on), or lets visitors in without an account (off): genshin and the build analyzer off, every other site on |
| `roles` | fixed: `ADMIN`, `EDITOR` and `MEMBER` in every site, made with the application (migration 011) - only whether each may sign in is chosen |
| roles that may sign in | each role's `may_sign_in`; all of them, unless the admin panel says otherwise |

**Every first-party site is open to every account, and has ADMIN and EDITOR**
(the owner's decision, 2026-10-01; migration 007). Every account holds
`MEMBER` in every application (migration 010, 2026-10-05), and a sign-in to a
site needs a role there that may sign in - MEMBER may, so everybody gets in
until an admin takes somebody's MEMBER away (blocking them from that site) or
unticks MEMBER for the site (letting in only the roles given by hand).
Anybody signed in gets a token - with `roles: ["MEMBER"]`, for most people -
and the site decides what a visitor or a signed-in person may do there:
treat MEMBER as an ordinary signed-in person; no check needs it and none
should refuse it. The other roles are for its admin area: EDITOR or ADMIN opens it,
ADMIN alone the deleting and system pages. What each may do is the site's own
split - genshin's and the survey's as they were; in Japanese Academy, Piano
Academy and the build analyzer EDITOR rewords the site's strings (the
analyzer's also runs the syncs), and ADMIN does all that and adds and deletes
languages, applies database updates, switches sources on and off and deletes
a person's data. An administrator of the IAM holds ADMIN in every
application that has one - those there when 007 ran, the one the installer or
`create-admin.php` makes, and an ADMIN brought over from the genshin site.

An application for a few people - one registered in the admin panel - unticks
MEMBER under the roles that may sign in, and gives ADMIN or EDITOR by hand (what
`access: members` was before 010; the column is still there, unread).

The first-party sites arrive registered with their development addresses - the
port each one's dev server uses:

| Application | client_id | Frontend (ng serve) | Backend (php -S) |
| --- | --- | --- | --- |
| IAM | `iam` | `http://localhost:4300` | `:8100` |
| Genshin Impact | `genshin` | `http://localhost:4200` | `:8000` |
| Japanese Academy | `japanese-academy` | `http://localhost:4400` | `:8200` |
| Piano Academy | `piano-academy` | `http://localhost:4500` | `:8300` |
| Build Analyzer | `build-analyzer` | `http://localhost:4600` | `:8400` |
| Surveys | `survey` | `http://localhost:4700` | `:8500` |

On a real host the installer asks for each site's production address and puts
it in place of these; the admin panel changes them later. A site seeded after
an IAM was installed - the survey site, by 005 - gets these on it only while
that IAM is itself on this computer; on a live IAM the admin panel gives it
its https addresses instead. `php clients.php --dev` (in the IAM's `backend/`)
puts the development ones back on a development database that has drifted.

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
auth.user();                     // { sub, preferred_username, email, email_verified, locale, roles, preferences } | null
await auth.getAccessToken();     // string | null - refreshed when under 30 s remain
auth.login({ returnTo: location.pathname + location.search });   // redirect to the IAM
auth.register({ returnTo });     // the same, opening on the registration form
auth.logout();                   // everywhere: the IAM ends its session, lands on postLogoutRedirectUri
auth.logout({ local: true });    // this site only: spends the refresh token, no navigation
auth.fetch('/api/progress', { method: 'PUT', body });            // fetch with the bearer, one retry after a refresh on 401
await auth.savePreferences({ theme: 'dark' });                    // the account's, for every site (below)
auth.onChange(() => redraw());   // signed in / out / refreshed / a preference saved, also from other tabs
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

### Languages

`login({ uiLocales })` and `register({ uiLocales })` pass `ui_locales` - the
site's language first, space-separated tags - so the IAM's pages open in it
when the IAM offers it (by the whole code, else its first subtag; see
[api.md](api.md)). The IAM's languages are kept in its admin panel, and can be
added, switched off or deleted there at any time.

`user().locale` is the account's language while the IAM offers it, else `en`.
It is **not** a promise that the site speaks it: the IAM may offer a language
the site does not have, and a token minted before a language was switched off
carries it for that token's lifetime. A site checks `locale` against its own
languages and starts a newcomer in its default when it is not one of them.

### The account's preferences

The theme, how dates are written and the clock are the account's, like its
language (the owner's decision of 2026-10-06, `decisions.md`): one choice
every anotoki site follows - every page of it, its admin area included.
`user().preferences` holds them (the `preferences` claim, [api.md](api.md)):

| | Values | |
| --- | --- | --- |
| `theme` | `auto`, `light`, `dark` | `auto` follows the operating system |
| `date_format` | `dmy_dot` (6.10.2026, `d.M.yyyy`), `dmy_slash` (06/10/2026), `mdy_slash` (10/06/2026), `ymd_dash` (2026-10-06), `null` | `null`: the page language's own way |
| `time_format` | `24` (14:30), `12` (2:30 PM, the language's words for am and pm), `null` | `null`: the language's own clock |

What a site does with them:

- **Signed in, the account's.** The site shows the theme, writes dates and
  times in the account's order and clock, and speaks `locale` when it has that
  language. When `onChange` fires - another tab, a refresh, a save - it reads
  them again.
- **The site's own controls save to the account.** Its theme toggle, its
  language switcher, a date setting: for a signed-in person each choice is
  shown at once and saved with `savePreferences({ theme })`,
  `({ date_format })`, `({ time_format })` or `({ language })` - a language
  only when the IAM offers it (`ui_locales_supported`); one the IAM does not
  have stays the site's own, for the visit. Refused, the site puts the
  account's value back. The SDK updates `user()` from the answer at once, in
  every tab; the tokens carry the change from their next refresh.
- **Signed out, the device's.** A visitor's choices stay where they always
  were - the site's own storage - and the account's take over at sign-in.
- **No preferences, the site's own.** `preferences` is `null` from an IAM
  before migration 015 or a sign-in without the `profile` scope; the site
  then keeps its own settings as it did. A value the SDK does not know reads
  as the default.

A backend that wants them reads the same claim from the access token, as it
was when the token was minted.

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
6. `sub` is the user id. `roles` are this application's roles for them
   (`anotoki_iam_roles($claims)`). A first-party site checks them on the
   server for every admin route: `ADMIN` or `EDITOR` for its admin area,
   `ADMIN` alone for what only an administrator does. Its frontend may hide
   what a person cannot use, but only the server's check counts. `["MEMBER"]`
   (every account holds MEMBER) - or an empty list, from an IAM before 010 -
   is an ordinary signed-in person, never a reason to refuse the sign-in.

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

### Is sign-in required here?

Each application has a switch on its page in the admin panel, **Sign-in
required** (`login_required`). On - the default, and every first-party site
but two - the site sends everybody to sign in. Off - genshin and the build
analyzer - it lets visitors use it without an account. The IAM publishes the
switch for the sites' **backends** at
`GET {issuer}/api/public/applications/{client_id}`: `{"client_id", "name",
"login_required"}`, cacheable for 60 seconds; 404 for an unknown or
switched-off application and for the IAM's own entry (`docs/api.md`).

A site reads it through the PHP SDK (1.1.0 and later) - never with a fetch of
its own:

```php
$config['cache_dir'] = __DIR__ . '/../storage/cache';    // private: the kept answer is what opens the site to visitors
$required = anotoki_iam_login_required($config, $reason); // bool; $reason is for a log line
```

- **It fails closed.** A 404 is "sign-in required", and kept as that; no
  answer, a 5xx or garbage gives the last answer kept, or "required" when the
  site never read one. The answer is kept in `cache_dir` for a minute
  (`application_ttl`), the IAM is asked once at a time and waited for at most
  two seconds (`application_timeout`), and after a failure not asked again for
  a minute. The whole table, and why `cache_dir` must be the site's own folder:
  `sdk/README.md`.
- **The server decides, the frontend follows.** The site relays the answer to
  its own frontend as `loginRequired` in its public configuration (the
  survey's `/api/auth/config`), so the browser never asks the IAM; the
  frontend's use of it only spares people a page that would refuse them.
- In tests, `'application_file'` replaces asking the IAM, as `jwks_file`
  replaces the key set; a site's stand-in IAM serves the same route.

**Who a request is**, on a site that lets visitors in (their data kept under a
key the browser sends, `X-Visitor-Key`):

1. **A request with an `Authorization: Bearer ...` header is never a
   visitor's** - with or without a token after it. A verified token is that
   person; a bad or expired one answers 401, and the visitor's key is not even
   read. Only the Bearer scheme counts (`anotoki_iam_sent_bearer()`): the
   `Basic ...` a password-protected host sends with every request is no claim
   to be anybody.
2. Else, when sign-in is required: 401 `sign_in_required` on everything per
   person; the visitor's key is never looked up.
3. Else the visitor's key, if one was sent; else nobody.

A visitor never passes a check that means "a verified token" (the admin area,
anything of another person's). The frontend decides person or visitor when it
issues a request, from the page it is on - never from whether a token happens
to be there when it is sent: a person's request whose token has gone (a failed
refresh, signed out in another tab) is not sent at all, rather than sent as a
visitor's.

Blocking and visiting: taking somebody's MEMBER away in a site (or unticking
MEMBER there) makes the IAM refuse their next sign-in, refresh and personal
token there; the access token they already hold verifies until it expires
(minutes). On a site that lets visitors in they can still use it as a visitor
- blocking stops signing in, not visiting. To keep somebody out entirely, the
site must require sign-in.

### Users on the application's side

The IAM owns identity - and the language, theme, dates and clock (above). An
application keeps only what is its own - progress, its own settings - keyed by
the user id, plus, if it wants to show names, a small
copy of `preferred_username` refreshed from the token as requests arrive. It
never stores passwords, and never decides who someone is from anything but a
verified token.

Revocation: an access token is good until it expires (10 minutes by default).
Signing out at the IAM, disabling an account, or taking away the last role it
holds there that may sign in (its MEMBER, usually) stops the next refresh -
and a personal token's next exchange, however long before it was made - so
the application is back to anonymous within one token lifetime. Any other role
taken away - a site's ADMIN or EDITOR - is gone from the next access token.

## 4. Running without the IAM

None does. The owner's decision (2026-10-01): "There is no need from project
to support no iam alternative/version of the projects. The IAM should be the
only option." The study apps' old mode with sign-in switched off - one
configured user with every role, on this computer only - goes from each of
them; on a development machine every site signs in through a locally running
IAM, as the genshin and survey sites always have (each site's `DEV.md`). A
site's own tests sign in through a stand-in IAM instead (the survey's
`backend/tests/tools/fake-iam.php` is the pattern).

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
