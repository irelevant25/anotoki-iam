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
included, all but `/api/site-status` - with the anotoki family's
`503 {code: "not_set_up", installed, message, setup?}`, and the pages show
"not set up yet" with a link to the installer - the link, and `setup`, only
while the IAM was never installed (below).

`backend/setup.php` writes them, and it is the way in on a development machine
as much as on a server:

| | On this computer | On a real host |
| --- | --- | --- |
| When | loopback `REMOTE_ADDR`, a `localhost` / `127.0.0.1` / `[::1]` Host, no forwarding headers | anything else |
| Proof | a nonce in the form (a page elsewhere cannot read it), plus the `Origin` and `Sec-Fetch-Site` headers | a token written to `config/setup.local.php`, read back from the server's files |
| Prefilled | development values: issuer `http://localhost:4300`, PostgreSQL on `127.0.0.1`, mail to files | what the address it is opened at suggests |
| The sites | keep their development addresses | their production addresses replace the loopback ones; a blank one waits for the admin panel |
| Afterwards | stays, re-runnable: shows what is saved, never a password or the key; a blank password keeps the saved one | `storage/installed.lock`, then it deletes itself and its token |

Both build the database with the anotoki family's migration engine (below),
keep an existing master key, keep whatever else the files hold, and either
bring the genshin site's accounts over or make the first administrator. "Test connection" beside
the database fields is a small JSON request to the same page, under the same
nonce or token, and answers in the words every anotoki setup page uses
(`setupTestDatabase()`): connected and empty, updates to apply, or up to date;
a database that does not exist yet, which Save creates when the user may
create databases (`ensureDatabase()`) or which is to be made in the hosting
panel first; a database there but closed to this user; no connection at all.

## Migrations, and an update waiting: the family's module

The schema is `backend/migrations/iam/*.sql`, applied by the engine every
anotoki site shares: **anotoki/lib** (`irelevant25/anotoki-lib` on GitHub - a
Composer package in `backend/vendor/anotoki/lib`, and `@anotoki/lib` in the
frontend, one version for both), whose migrations module is also the gate in
front of the API, the status the pages ask, the admin Migrations page and its
routes. The IAM wires it in `backend/api/migration_runner.php` and
`frontend/src/app/core/migrations/migrations.config.ts`.

- **The engine** (`iamMigrator()`): one set, `iam`, recorded in the
  `migrations` table as it always was (`id`, `filename`, `applied_at` - the
  library reads that shape and never changes it). Each file in a transaction
  of its own with its record; the run stops at the first that fails, which is
  rolled back whole and named with the database's words (`statement N of M:
  ...`). The command line, the installer and the admin panel apply through it,
  one at a time: each takes the same advisory lock, and the second is told
  another is running. A pooled (persistent) connection lets go of whatever
  lock its last request left (`connectDatabase()`), so a request killed by its
  time limit mid-apply cannot keep every later one "busy".
- **Where the IAM stands** (`iamSiteState()`), decided afresh whenever it is
  asked: `not_set_up` (a `config/*.local.php` missing or wrong),
  `update_pending` (a file waits), `unavailable` (no database), `ready`.
  `GET /api/site-status` says which - to anybody who asks, as on every anotoki
  site. The gate answers every other path `503 not_set_up` while the IAM is
  not set up - naming the installer only while `storage/installed.lock` is not
  there, so an IAM installed once never sends anybody to a `setup.php` that
  has deleted itself (the owner's rule). It is asked only then
  (`api/index.php`): in any other state a site that does not block lets
  everything through, and finding out which state it is would cost every
  request a connection and two queries.
- **The IAM does not block while an update waits** - the one anotoki site that
  does not (`blockWhilePending: false`; `docs/decisions.md`). Every other site
  closes behind a calm "being updated" page until its administrator applies
  the update - and that administrator signs in through the IAM. So the IAM
  keeps answering: its features check their own tables, as they always have
  (`signInRulesReady()`, `translationsReady()`, `accountApprovalReady()`,
  `announcementsReady()`, `preferencesReady()`, `accountDeletionReady()`,
  `signInDevicesReady()`), and of its pages only the admin panel says so:
  `<anotoki-update-banner>` - "A database update is waiting. Migrations" -
  above every page of it, which asks the status once. (The status path itself
  is public, as on every anotoki site: anybody may learn that an update
  waits - a timing signal, not a way in; `docs/decisions.md`.)
- **The Migrations page** is the family's `<anotoki-migrations-page>` at
  `/admin/migrations` (English, as the admin panel is): what is pending and
  applied (each file's SQL on View), what is recorded but missing, and
  "Apply pending" behind a confirmation. Its routes,
  `/api/admin/migrations[/file|/apply]`, sit in the admin group: the session
  and the IAM's ADMIN - nothing a pending migration makes - and the CSRF header
  on Apply. Applying is audited (`migrations.applied`), and the strings are
  read again: a migration may have brought some.
- **Not set up**: the pages' `/not-set-up` is the family's
  `<anotoki-site-status>` in the IAM's own words - "This IAM is not set up
  yet", "Open the installer" - with the developer's line, while it was never
  installed; once installed, "The site is not available right now", asking
  again by itself, and never the installer.

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
| `users` | Accounts: username and address (unique regardless of case), the password hash (nullable), whether password sign-in is on, the TOTP secret **encrypted**, the flags (confirmed, forced change, disabled, approved - false while it waits for an administrator, news_mail - whether it gets the announcements), the language and how every site is to look - `theme`, `date_format`, `time_format` (015), and a deletion its owner asked for - `deletion_requested_at`, `deletion_due_at`, then `anonymized_at` (016). The id is the `sub` every site keys its data on: a deleted account's row stays, a placeholder, so it is never anybody else's. |
| `user_sign_in_devices` | The browsers an account signed in from at the IAM (016): the SHA-256 of the account's id and the browser's device cookie, first and last seen, the browser in a few words. Decides whether a sign-in is mailed; opens nothing. |
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
| `announcements`, `announcement_recipients` | What administrators mailed to the people of the sites: the words per language, the template and the site, the sender and the link as they were, who it was for, who sent it and when, whether it is sending, sent or stopped - and one row per person: the address and the version it went in, and how it went. |
| `mail_senders` | The addresses added in the admin panel that an announcement may come from, beside config/mail.local.php's own. |

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

## New accounts that wait for approval

Settings → **New accounts need approval** (migration 012, off by default) is
one switch for the whole IAM - an account is one for every site, so a person
signs up for the account, never for one site. While it is on, an account made
by the registration form or by signing up with Google waits (`users.approved`
false): its address is confirmed by its link as ever, but every way in
refuses it `approval_pending` - `signInRefusal()` for the sign-in endpoints,
the confirmation and the reset link, `applicationAccessRefusal()` for
anything issued - until an administrator approves it on the admin panel's
**Approvals** page (or its page under Users). Approving mails the person once
their address is confirmed; declining switches the account off, deleting
nothing. Accounts an administrator makes never wait (the column's default),
and switching the setting off approves everybody still waiting. The sites see
nothing of it: a person waiting never gets as far as a code.

## Deleting an account

A person deletes their own account on the account page (migration 016,
`api/account_deletion.php`; the owner's decision of 2026-10-07): with the
password - or, without one, a sign-in of the last fifteen minutes - they ask,
and it is closed at once and deleted thirty days later unless they cancel.
That proof is `accountProof()`, and the changes that would let a session make
it for itself - a first password, Google connected, password sign-in switched
on or off - ask for it too, as does two-factor switched on (with the holder's
own authenticator it would lock the owner out; its setup turns away an account
without a password whose sign-in is too old before making a secret, so nobody
scans a code enable would refuse).

- **Closed at once.** Every other session ends, every refresh and personal
  token is revoked, and `applicationAccessRefusal()` answers
  `account_deletion_pending` on every way to a site - authorize sends the
  browser to the IAM's own page, accept refuses, the code exchange and every
  refresh are `invalid_grant`, userinfo and a site's save of preferences know
  no holder; announcements leave it out. The IAM's own sign-in keeps working,
  by every way in, because the account page is where it is cancelled; the
  admin panel does not
  (`requireAdmin()`), and the last-admin rule counts no administrator whose
  deletion is pending (`iamAdminCount()`), so the last one who stays can
  never leave or be removed. The person is mailed when they ask and when they
  cancel.
- **Deleted when due**, by housekeeping (`anonymizeDueAccounts()`), each
  account in a transaction of its own: everything of the person goes -
  sessions, tokens, devices, identities, roles, its sign-in attempts and
  mails - or is scrubbed, the events kept - the audit log's IP addresses,
  names and addresses, an announcement's address - and the row becomes a
  placeholder, switched off, that nothing changes again: its id stays
  reserved. Once committed, the address it had gets the last mail. A request
  that read the account a moment before writes nothing back onto the row (its
  writes ask for one not anonymized - `liveAccountSql()` - and
  `users_anonymized_check` keeps a placeholder without a way in), and what it
  leaves elsewhere is swept at every run (`anonymizedLeftovers()`).
- **The sites** read the deleted ids at `GET /api/public/anonymized-accounts`
  and scrub their own copies of the person's name and address, keeping the
  rest of their data under the id, nobody's now.

## A mail for a sign-in from a new browser

Every sign-in that opens a session (`completeSignIn()`, every way in, past
two-factor) notes the browser it came from (`api/sign_in_devices.php`): a
cookie of its own, `anotoki_iam_device`, a random secret for 400 days whose
hash, with the account's id, the account keeps. A browser the account has
not signed in from before is mailed about - once, at the IAM, never per site
and never for a site's single sign-on or refresh - unless it is the account's
first (every account's first sign-in after 016, and a new account's), which is
noted in silence. The cookie opens nothing and is not the two-factor
remembered device. A reset, or an address claimed, forgets the browsers.

## Announcements

The admin panel's **Announcements** (migration 013, `api/announcements.php`)
mails news to the people of the sites - never by itself: an administrator
writes it and presses Send.

- **The words.** Three templates - a new version of a site, a new site, a
  survey about one - are mail strings (`mail.newVersion.*`, ...), reworded on
  the Translations page; the composer fills a draft with them in every
  language on offer, `{project}` the chosen site's name, and the administrator
  changes them. A language left empty has no version: its readers get the
  English one, which is needed. The words are text: the server escapes every
  one into one fixed layout (`announcementMessage()`, plain text and HTML), and
  ends each mail with two more mail strings - why the person gets it, and the
  link that stops the news.
- **Links to the anotoki sites only** (the owner's choice): the button's link
  and any written out in the words must be at the IAM's host, a host an
  application is registered with, or the domain the IAM sends mail from or one
  under it; a mail address in the words, at that domain.
- **Who.** Everybody, the holders of some roles, some people - and of them only
  those who can be written to: switched on, the address confirmed, approved,
  not being deleted, and `news_mail` on. The list is made when Send is pressed, and each person
  asked again as their mail goes. A person signing up is offered the news in
  a box left unticked (migration 014) - with Google from the register page
  too; an account made any other way by its owner gets none until they switch
  it on.
- **From whom.** config/mail.local.php's senders (noreply, support), or one an
  administrator added at the domain the IAM sends from.
- **Sending.** The host has no cron and a request has half a minute, so the
  announcement's page sends it, batch after batch (20 mails or 10 seconds a
  request), while it is open: each mail in a transaction of its own, its row
  taken `FOR UPDATE SKIP LOCKED` - two pages pressing on never send one twice -
  and Stop waits for the mail on its way. With config/mail.local.php's
  `hourly_limit`, announcements keep to nine tenths of the host's hourly
  allowance and wait for room.
- **Stopping the news.** Every announcement ends with a link to `/unsubscribe`
  and carries it as `List-Unsubscribe`: a token naming the account, a MAC under
  a key derived from the master key for this use alone (HKDF), which does
  nothing but switch its news off - after the page
  asks, so a link checker opening it changes nothing. The account page has the
  same switch.
- **Kept.** Every announcement, with its words, its audience and each person's
  mail - and every mail in the email log, as ever.

## The account and admin pages

The IAM's own pages talk to its own API with the session cookie, from the same
origin. Every non-GET must carry `X-IAM-Request: 1`, which a page on another
origin cannot send without a CORS preflight those endpoints never answer, and an
`Origin` other than the IAM's own is refused. The admin panel needs the
`ADMIN` role of the application `iam`.

## The account's preferences

The theme, the date order and the clock are the account's (migration 015),
as its language is: the `preferences` claim beside `locale` tells every site,
and the account page - or a site, with the person's access token, through
`POST /api/oidc/preferences` - changes them. Signed in, the IAM's own pages
follow them like any site: `ThemeService` shows the account's theme and saves
a toggle to it, and the `appDate` pipe writes the account's order and clock.
Signed out, each device keeps its own theme, as before.

## The IAM's own words

What the sign-in pages, the account page and the IAM's own mails say - and the
announcements' templates - lives in the database (migration 006), so a word can be reworded and a language added in the
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
  line that is the link or the code, validity, ignore (the approval notice has
  no use for the last two) - in the recipient's language while it is offered. The link and the code are never part of a
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
   an account waiting for approval, a role that may sign in) - a switch on one
   door is a lock on one of five. An account whose deletion is pending is
   refused on every way to a site, and let in at the IAM only to cancel it.
9. **An account always keeps a way in**, and an admin cannot lock themselves out
   or remove the last admin - nor ask for their own deletion as the last one;
   an administrator whose deletion is pending counts as none.
10. **Revocation is at the refresh.** Signing out, disabling an account, asking
    for its deletion or taking a role away is felt by every site within one
    access-token lifetime; `userinfo` reflects it at once.
11. **Every write needs the CSRF header** except the four endpoints the sites
    call cross-origin with no cookie - token, userinfo, revoke, preferences -
    named exactly and compared on the percent-decoded path the router matches;
    none of the four ever reads the session cookie.
12. **Two-factor holds on every way in, resets included**; an authenticator code
    is accepted once, and every miss counts against the limiter.
13. **Proving an address on an account never confirmed clears it**: nothing its
    registrant set survives the owner's arrival. **A reset takes an account back
    completely** - sessions, personal tokens, devices, outside identities, the
    browsers it signed in from.
14. **Plain http only for this computer** in redirect URIs and web origins, and
    **no loopback address survives a real host's install**: the development
    addresses a fresh database is seeded with go as the production ones arrive,
    and a migration that seeds a site on an IAM installed already adds them only
    while the IAM's own entry has its home on this computer.
15. **The installer proves who is at the keyboard**: a token read from the
    server's own files on a real host; on this computer a nonce, and only when
    the connection, the Host header and the absence of forwarding headers all
    say it is this computer. A real host's install locks itself and goes.
16. **A deleted account stays deleted.** Its row stays, a placeholder, so its
    id - every site's `sub` - is never anybody else's, and nothing switches it
    on again or gives it a password or an address: not the admin panel
    (`409 account_anonymized`), not the command line. Nothing of the person
    is left in it or tied to it.

## Where the code lives

| File | |
| --- | --- |
| `backend/setup.php` | The installer: writes `config/*.local.php`, builds the database, the accounts, the sites' production addresses. |
| `backend/config/` | `config.php` (reading `*.local.php`, whether the IAM is set up), and one loader per file: `db.php`, `iam.php`, `secret.php`, `mail.php`, `google.php`. |
| `backend/api/index.php` | Front controller: requires, middleware order (the family's gate where "not set up" is answered), error handler. |
| `backend/api/migration_runner.php` | The IAM's migrations through anotoki/lib's engine (`iamMigrator()`), where the IAM stands (`iamSiteState()`), and the two things beside them: `ensureDatabase()`, `createFirstAdmin()`. |
| `backend/vendor/anotoki/lib` | The anotoki family's library (Composer): the migration engine, the gate, the status and Migrations routes. Never edited here - updated by Composer (`docs/operations.md`). |
| `backend/api/routes/oidc.php` | Discovery, JWKS, authorize, token, userinfo, a site's save of its reader's preferences, logout, revoke, the public list of sites, the public "is sign-in required here?" answer. |
| `backend/api/routes/login.php` | Sign-in (password, emailed code, Google), registration, confirmation, resets, the pending request (accept/cancel), sign-out, stopping the news. |
| `backend/api/routes/account.php` | The account page. |
| `backend/api/routes/admin.php` | The admin panel. |
| `backend/api/oidc.php` | Requests, codes, refresh tokens, minting tokens. |
| `backend/api/session.php` | The session cookie, sliding expiry, revocation, the limiter. |
| `backend/api/keys.php`, `jwt.php`, `crypto.php` | Keys (made on first use), signing, encryption. |
| `backend/api/applications.php`, `accounts.php`, `settings.php` | The rules about who may use what. |
| `backend/api/account_deletion.php` | An account's deletion: asked, cancelled, done when due (housekeeping), and the public list of deleted ids. |
| `backend/api/sign_in_devices.php` | The browsers an account signed in from, the device cookie, and the mail for a new one. |
| `backend/api/totp.php`, `trusted_device.php`, `one_time_token.php`, `google_identity.php`, `mail.php` | Carried over from the genshin site, rules intact. |
| `backend/api/legacy_import.php` | The one-time move of the genshin site's accounts. |
| `backend/api/translations.php` | The languages, the strings, the bundle, the admin panel's grid and every check of a string (`translationsCheck()`). |
| `backend/api/announcements.php` | Announcements: the senders, the checks of a draft and of its links, who it reaches, the mail's layout, the batches, the token that stops the news. |
| `backend/translations.php` | The strings from the command line: `--status`, `--export`, `--import`. Never uploaded. |
| `frontend/src/app/core/i18n/` | `translation.service.ts` (which language, the bundle, the `t` pipe's strings), `en.ts` (the keys and the English of last resort), `localization-drafts.service.ts`. |
| `frontend/src/app/core/migrations/` | How the pages use `@anotoki/lib/migrations`: who is the IAM's ADMIN, the sign-in, the language and the IAM's own not-set-up words, the account's dates. |
| `frontend/src/app/layouts/` | `auth-layout` (the sign-in pages' night-sky frame), `shell` (the account pages and the admin panel: the top bar, the panel's side navigation, the page), `top-bar` (the anotoki family's one bar: the account pages as links, the language, the theme, the person's menu; a menu button up to 1040 px). |
| `backend/api/housekeeping.php` | Sweeps dead requests, codes and old spent tokens, and deletes the accounts whose deletion is due, at most hourly, from ordinary traffic - the host has no cron. |
| `backend/tests/` | In-process tests - the app and the installer - against throwaway databases and temp configuration. |
