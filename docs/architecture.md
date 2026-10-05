# How the anotoki IAM works

One place where people have an account, and every anotoki site signing them in
from there. This page is the map: the parts, how a sign-in travels through
them, what is stored, and the rules that keep it safe. The endpoint contract is
[api.md](api.md); how a site plugs in is [integrating-an-app.md](integrating-an-app.md);
running it is [operations.md](operations.md); why it is built this way is
[decisions.md](decisions.md).

## The parts

| Part | Where | What it is |
| --- | --- | --- |
| Backend | `backend/` | PHP 8.2+ + Slim 4 + PostgreSQL. The OpenID Connect provider, the JSON API the IAM's own pages use, and the installer. |
| Frontend | `frontend/` | Angular 21. The sign-in pages, the account page, the admin panel. |
| SDKs | `sdk/js`, `sdk/php` | The small first-party clients every site copies in: a browser client for the sign-in, and a PHP verifier for access tokens and reader of the site's sign-in switch. |
| Docs | `docs/` | This. |

On a development machine the Angular dev server on `:4300` proxies `/api`,
`/.well-known` and `/setup.php` to PHP on `:8100`, so the browser sees one
origin. On the hosting it is one web root on the IAM's own subdomain: the
Angular build at the root, `api/` beside it, and a rewrite that sends
`/.well-known/` to the API so discovery lives at the issuer's root as OIDC
requires - the same single origin. The **issuer** - the name every token
carries in `iss` - is that public address: `http://localhost:4300` in
development, the subdomain's https address on the hosting.

The sites that sign in through it are **applications** (OIDC clients):
`genshin`, `japanese-academy`, `piano-academy`, `build-analyzer`, `survey`, and
`iam` itself, which is not an OIDC client at all but owns the role that makes
somebody an administrator of this installation. A fresh database registers each
site with its development addresses - every anotoki project's dev server has a
fixed port, and every one lands its sign-in on `/auth/callback`:

| Application | client_id | Frontend (ng serve) | Backend (php -S) |
| --- | --- | --- | --- |
| IAM | `iam` | `http://localhost:4300` | `:8100` |
| Genshin Impact | `genshin` | `http://localhost:4200` | `:8000` |
| Japanese Academy | `japanese-academy` | `http://localhost:4400` | `:8200` |
| Piano Academy | `piano-academy` | `http://localhost:4500` | `:8300` |
| Build Analyzer | `build-analyzer` | `http://localhost:4600` | `:8400` |
| Surveys | `survey` | `http://localhost:4700` | `:8500` |

A real host's install replaces them with the production addresses typed into
the installer (see "Configuration and the installer" below). The survey site
came later, in migration 005, which also reaches IAMs installed already: it
brings its development addresses and a card on the landing page only while the
IAM itself is served from this computer - its own `iam` entry's home, which the
installer sets to the IAM's address. On a live IAM it arrives with neither, and
the admin panel gives it its https address.

Every one of these sites is open to every account and has the roles ADMIN and
EDITOR, nothing else (migration 007, the owner's decision): signing in never
asks for a role, and each site keeps its admin area for the two, checked on
its own server against the token's `roles`. An administrator of the IAM holds
ADMIN in every application that has one - the installer's and
`create-admin.php`'s (`createFirstAdmin()`), an ADMIN brought over from the
genshin site, and every administrator there when 007 ran.

## Configuration and the installer

Everything that belongs to one installation - the database connection (and
the genshin site's old `users` database, for the one-time import), the master
key, the public address, mail, Google - is in `backend/config/*.local.php` and
nowhere else: gitignored, never defaulted from anything committed. The
committed `config/*.php` beside them read those files and hold only the
tunables that are the same everywhere.

They are required. Until `database`, `secret`, `iam` and `mail` are all there
and sensible (`google` is optional), the API answers every request - discovery
included - with `503 {code: "not_set_up", setup_url: "/setup.php"}`, and the
pages show "not set up yet" with a link to the installer.

`backend/setup.php` writes them, and it is the way in on a development machine
as much as on a server:

| | On this computer | On a real host |
| --- | --- | --- |
| When | loopback `REMOTE_ADDR`, a `localhost` / `127.0.0.1` / `[::1]` Host, no forwarding headers | anything else |
| Proof | a nonce in the form (a page elsewhere cannot read it), plus the `Origin` and `Sec-Fetch-Site` headers | a token written to `config/setup.local.php`, read back from the server's files |
| Prefilled | development values: issuer `http://localhost:4300`, PostgreSQL on `127.0.0.1`, mail to files | what the address it is opened at suggests |
| The sites | keep their development addresses | their production addresses replace the loopback ones; a blank one waits for the admin panel |
| Afterwards | stays, re-runnable: shows what is saved, never a password or the key; a blank password keeps the saved one | `storage/installed.lock`, then it deletes itself and its token |

Both build the database with the shared migration engine, keep an existing
master key, keep whatever else the files hold, and either bring the genshin
site's accounts over or make the first administrator. "Test connection" beside
the database fields is a small JSON request to the same page, under the same
nonce or token, and answers in the words every anotoki setup page uses
(`setupTestDatabase()`): connected and empty, updates to apply, or up to date;
a database that does not exist yet, which Save creates when the user may
create databases (`ensureDatabase()`) or which is to be made in the hosting
panel first; a database there but closed to this user; no connection at all.

## A sign-in, end to end

```
 site (localhost:4200)                IAM (localhost:4300)                         site API
 ─────────────────────                ────────────────────                         ────────
 "Sign in" → PKCE verifier, state,
 nonce into sessionStorage
   ── GET /api/oidc/authorize ──────▶  client + redirect URI checked (exact match)
                                       request stored (30 min), id → browser
                                       ├─ signed in already, nothing asks for a page:
                                       │    code issued, 302 straight back   (SSO)
                                       └─ otherwise 302 /login?request={id}
                                           the page signs the person in:
                                           POST /api/login/password (+ totp …)
                                           → session cookie `anotoki_iam`
                                           POST /api/login/request/{id}/accept
   ◀──────────── 302 {redirect_uri}?code=…&state=…&iss=…
 state checked, then
   ── POST /api/oidc/token ─────────▶  code (60 s, once) + PKCE verifier +
      (CORS, registered origin)        redirect URI + client → tokens:
   ◀──────────── access (10 min) + refresh (rotating) + ID token
 ── GET /api/… Authorization: Bearer <access> ─────────────────────────────────▶ RS256 checked against
                                                                                 the cached JWKS; iss, aud,
                                                                                 typ at+jwt, exp; sub = user
 near expiry:
   ── POST /api/oidc/token (refresh) ▶  old refresh token spent, successor issued
```

Signing out of a site (the SDK's `logout()`) goes to `/api/oidc/logout` with the
ID token as a hint; the IAM ends its session, which spends every refresh token
issued under it - on every site - and sends the browser back to the site.

## What is stored

One PostgreSQL database, built by `backend/migrations/iam/*.sql` and nothing else.

| Table | Holds |
| --- | --- |
| `users` | Accounts: username and address (unique regardless of case), the password hash (nullable), whether password sign-in is on, the TOTP secret **encrypted**, the flags (confirmed, forced change, disabled), the language. The id is the `sub` every site keys its data on. |
| `user_identities` | Who an account is at Google: provider + permanent subject. |
| `user_tokens` | One-time mailed secrets: confirmation link, reset link, six-digit sign-in code - hashes only, with `context` (which application a registration came from). |
| `user_recovery_codes`, `user_trusted_devices` | Two-factor's way back in, and browsers that answered a code in the last 30 days - hashes only. |
| `sessions` | A signed-in browser at the IAM: the cookie's hash, a public `sid`, how it signed in, sliding expiry with an absolute cap. |
| `login_attempts`, `email_log` | The limiter's memory and the history; the mail log keeps no bodies. |
| `applications` (+ `application_redirect_uris`, `application_origins`, `application_roles`) | The sites: exact redirect URIs, the origins allowed to call the token endpoint, whether the site requires signing in (`login_required`, published to the sites' backends at `GET /api/public/applications/{client_id}`), token lifetime, their roles and which of them may sign in (`may_sign_in`). Every application is a public client and has the same roles - ADMIN, EDITOR, MEMBER (the IAM's own entry ADMIN, MEMBER) - and every account holds MEMBER everywhere: triggers of migrations 010 and 011 keep it so for every account and application made later. `type`, `secret_hash`, `show_on_landing` and `access` stay, unread (`access` only while 010 is pending), until a later migration drops them - not a way back: once 010 is applied no build from before it goes on the host (`decisions.md`). `sort_order` is the order of the public list. |
| `user_roles` | Who holds which application role, and who granted it. |
| `auth_requests` | What an application asked for while the person signs in (and logouts waiting for confirmation). |
| `auth_codes`, `refresh_tokens` | Codes and refresh / personal tokens - hashes only; refresh tokens grouped into families for reuse detection. |
| `signing_keys` | RSA key pairs; the private half **encrypted**. One active, retired ones published for a day. |
| `settings`, `audit_log` | The switches, and every change made from the admin panel. |
| `languages` | The languages the IAM speaks: `code` (`xx` or `xx-yy`, small letters), `name` in English, `native_name`, `enabled` (offered), `sort_order`. English and Slovak are seeded. |
| `translation_keys` | Every key the pages and the mails ask for, with a description for the translator. Written by migrations only. |
| `translations` | One string per key and language, who reworded it last (`updated_by`, null for a migration) and when. No empty string: a language without one has no row and reads the English. |

"Encrypted" means AES-256-GCM under the **master key** in
`config/secret.local.php`, which the installer makes once and never replaces. A
copy of the database without that file can neither mint a token nor compute
anybody's two-factor codes. "Hashes only" means SHA-256 of a 256-bit random
secret, so a copy of the table opens nothing.

## Tokens

- **Access token** - JWT, `RS256`, header `typ: at+jwt` (RFC 9068), 10 minutes
  (per application, 60-3600 s). Carries `sub`, `aud` = the client id, `sid`,
  `auth_time`, `scope`, `roles` (that application's roles only), and the profile
  claims the scopes allow. Sites verify it themselves; nothing calls the IAM per
  request.
- **ID token** - JWT, `RS256`, `typ: JWT`, an hour; for the site's frontend:
  `nonce`, `amr`, `at_hash`, profile claims, roles.
- **Refresh token** - `iam_rt_…`, opaque, rotated on every use, dies with its
  session. A spent one presented again ends its whole family, except within a
  minute of its rotation (two tabs racing).
- **Personal token** - `iam_pat_…`, made on the account page for a script,
  bound to one application, never rotated, until it expires or is revoked.

## The account and admin pages

The IAM's own pages talk to its own API with the session cookie, from the same
origin. Every non-GET must carry `X-IAM-Request: 1`, which a page on another
origin cannot send without a CORS preflight those endpoints never answer, and an
`Origin` other than the IAM's own is refused. The admin panel needs the
`ADMIN` role of the application `iam`.

## The IAM's own words

What the sign-in pages, the account page and the three mails say lives in the
database (migration 006), so a word can be reworded and a language added in the
admin panel with no build and no upload. The admin panel itself is English,
written in its templates.

- **The tables.** `languages`, `translation_keys`, `translations` (above). A key
  is the code's: a migration writes it with its English and its Slovak, and the
  panel only ever changes a string. `users.language` references
  `languages (code)` - `ON UPDATE CASCADE ON DELETE SET DEFAULT`, so deleting a
  language sends its accounts back to English, which cannot be deleted or
  switched off. The CHECK that allowed only `en` and `sk` is gone.
- **Offered.** `languageCodes()` (`api/helpers.php`) is the list the rest of the
  IAM reads - discovery's `ui_locales_supported`, `/api/public/config`,
  `ui_locales`, the `locale` claim, an account's language. It works on a
  database that has no `languages` table yet, and answers `en`, `sk` then; it
  answers the same, unremembered, when the database cannot be reached, so the
  two routes that read nothing else from it still answer. A language switched
  off keeps its strings and its accounts; its readers get English pages, English
  mails and `locale: "en"` until it is back.
- **How the pages get their words.** One request, `GET /api/translations/{code}`
  (the bundle): the language's strings with English under them, the offered
  languages, and English on its own for the admin panel. `TranslationService`
  (`frontend/src/app/core/i18n/`) picks the language - `?lang=` (which the
  backend adds from an application's `ui_locales`) or the switcher, then the
  account's, this device's last choice, the browser's, English - and fetches its
  bundle before the page switches: a page is never in a language it has no words
  for. The first page waits up to three seconds for its words; a returning
  reader starts from the bundle cached in `localStorage` and checks it (a 304,
  usually) within a second. A Slovak browser's first wish, `sk-sk`, is answered
  by `sk`, so a first visit is one request. The ETag and `no-cache` make every
  load after the first a few bytes.
- **The last resort, on both sides.** Between an upload and "Apply pending" the
  bundle route answers `503 translations_unavailable`, and the sign-in page must
  still be readable or nobody could sign in to press Apply. The frontend then
  reads the English compiled from `core/i18n/en.ts`; the mails read
  `MAIL_FALLBACK` in `api/mail.php`, key by key, whenever the database has
  nothing to say. Only then: once a bundle is in memory, a key it lacks shows as
  the key itself (and warns once), so a missing string is seen, not papered over.
  `en.ts` is also the list of keys - `TranslationKey` comes from it, so an
  unknown key is a compile error - and a backend test holds it to the database:
  its keys are exactly the seeded page keys and its English each seeded English
  string, as `MAIL_FALLBACK` is the seeded mail English. There is no Slovak in the
  build.
- **Changes reach open pages.** After a save, the admin pages call
  `TranslationService.reload()`, which asks for the wanted language again and
  settles against the languages that come with it (the one on the page may have
  just been switched off). A failed load is asked again on the next navigation.
- **The mails.** A mail is put together by the server - greeting, intro, then a
  line that is the link or the code, validity, ignore - in the recipient's
  language while it is offered. The link and the code are never part of a
  string and have no placeholder; which placeholders a mail string has is
  `MAIL_KEYS`, the code, not the stored English. The panel refuses an address
  in a mail's words, a line break, and a line longer than a mail line may be.
- **The admin pages.** Translations (`/admin/translations`: the grid, a search,
  export and import of one language as JSON) and Languages (`/admin/languages`:
  add, rename, order, offer or hide, delete), in
  `frontend/src/app/pages/admin/localization/`. What is typed and not saved is
  kept by `LocalizationDrafts` (`core/i18n/localization-drafts.service.ts`),
  mirrored to `sessionStorage` for the tab: a session that ends, or Sign out,
  leaves the page without asking, and the same administrator finds the drafts
  in their boxes after signing in again; only another person signing in drops
  them. `unsavedChangesGuard` asks before a link or Back leaves a page with
  drafts - not once nobody is signed in - and closing the tab is the browser's
  `beforeunload` question.

## The rules that carry the weight

Each of these is load-bearing; a change that loosens one needs a decision
written down, not a refactor.

1. **Redirect URIs match byte for byte.** No prefix, no pattern. An unknown
   client or URI is never redirected to; the IAM shows its own error page.
2. **PKCE (S256) is required** of every client - all are public since
   migration 011, and a request carrying a client secret is refused - and the
   verifier is checked at the token endpoint against the stored challenge.
3. **A code is good once, for 60 seconds**, for the client, redirect URI and
   challenge it was issued with. Used twice, it revokes what it produced.
4. **The algorithm is pinned** (RS256) wherever a token is read - here and in
   the SDK. `typ` separates access tokens from ID tokens.
5. **Refresh tokens rotate; reuse ends the family.** A spent token gets one
   sibling within a minute (two tabs racing), never a second.
6. **Only hashes** of secrets that open things; **only ciphertext** of secrets
   that cannot be hashed (TOTP, signing keys).
7. **The same answer** whether or not an address has an account, wherever an
   endpoint mails an address; failures are **rate-limited** per address-and-source
   and per source.
8. **Every way in checks the same switches** (login enabled, account disabled,
   a role that may sign in) - a switch on one door is a lock on one of five.
9. **An account always keeps a way in**, and an admin cannot lock themselves out
   or remove the last admin.
10. **Revocation is at the refresh.** Signing out, disabling an account or taking
    a role away is felt by every site within one access-token lifetime; `userinfo`
    reflects it at once.
11. **Every write needs the CSRF header** except the three protocol endpoints,
    named exactly and compared on the percent-decoded path the router matches.
12. **Two-factor holds on every way in, resets included**; an authenticator code
    is accepted once, and every miss counts against the limiter.
13. **Proving an address on an account never confirmed clears it**: nothing its
    registrant set survives the owner's arrival. **A reset takes an account back
    completely** - sessions, personal tokens, devices, outside identities.
14. **Plain http only for this computer** in redirect URIs and web origins, and
    **no loopback address survives a real host's install**: the development
    addresses a fresh database is seeded with go as the production ones arrive,
    and a migration that seeds a site on an IAM installed already adds them only
    while the IAM's own entry has its home on this computer.
15. **The installer proves who is at the keyboard**: a token read from the
    server's own files on a real host; on this computer a nonce, and only when
    the connection, the Host header and the absence of forwarding headers all
    say it is this computer. A real host's install locks itself and goes.

## Where the code lives

| File | |
| --- | --- |
| `backend/setup.php` | The installer: writes `config/*.local.php`, builds the database, the accounts, the sites' production addresses. |
| `backend/config/` | `config.php` (reading `*.local.php`, whether the IAM is set up), and one loader per file: `db.php`, `iam.php`, `secret.php`, `mail.php`, `google.php`. |
| `backend/api/index.php` | Front controller: requires, middleware order, error handler. |
| `backend/api/routes/oidc.php` | Discovery, JWKS, authorize, token, userinfo, logout, revoke, the public list of sites, the public "is sign-in required here?" answer. |
| `backend/api/routes/login.php` | Sign-in (password, emailed code, Google), registration, confirmation, resets, the pending request (accept/cancel), sign-out. |
| `backend/api/routes/account.php` | The account page. |
| `backend/api/routes/admin.php` | The admin panel. |
| `backend/api/oidc.php` | Requests, codes, refresh tokens, minting tokens. |
| `backend/api/session.php` | The session cookie, sliding expiry, revocation, the limiter. |
| `backend/api/keys.php`, `jwt.php`, `crypto.php` | Keys (made on first use), signing, encryption. |
| `backend/api/applications.php`, `accounts.php`, `settings.php` | The rules about who may use what. |
| `backend/api/totp.php`, `trusted_device.php`, `one_time_token.php`, `google_identity.php`, `mail.php` | Carried over from the genshin site, rules intact. |
| `backend/api/legacy_import.php` | The one-time move of the genshin site's accounts. |
| `backend/api/translations.php` | The languages, the strings, the bundle, the admin panel's grid and every check of a string (`translationsCheck()`). |
| `backend/translations.php` | The strings from the command line: `--status`, `--export`, `--import`. Never uploaded. |
| `frontend/src/app/core/i18n/` | `translation.service.ts` (which language, the bundle, the `t` pipe's strings), `en.ts` (the keys and the English of last resort), `localization-drafts.service.ts`. |
| `frontend/src/app/layouts/` | `auth-layout` (the sign-in pages' night-sky frame), `shell` (the account pages and the admin panel: the top bar, the panel's side navigation, the page), `top-bar` (the anotoki family's one bar: the account pages as links, the language, the theme, the person's menu; a menu button up to 1040 px). |
| `backend/api/housekeeping.php` | Sweeps dead requests, codes and old spent tokens, at most hourly, from the busy endpoints - the host has no cron. |
| `backend/tests/` | In-process tests - the app and the installer - against throwaway databases and temp configuration. |
