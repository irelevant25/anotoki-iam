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
| Frontend | `frontend/` | Angular 21. The sign-in pages, the account page, the admin panel - drawn with the anotoki family's UI kit (`@anotoki/lib`). |
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
EDITOR (migration 007, the owner's decision), beside MEMBER - every account's,
the role that lets everybody in (010) - and EARLY_ACCESS, which lets in chosen
people while MEMBER may not sign in (023). Each site keeps its admin area for
ADMIN and EDITOR, checked on its own server against the token's `roles`. An
administrator of the IAM holds ADMIN in every application that has one - the
installer's and `create-admin.php`'s (`createFirstAdmin()`), an ADMIN brought
over from the genshin site, and every administrator there when 007 ran.

## Configuration and the installer

Everything that belongs to one installation - the database connection (and
the genshin site's old `users` database, for the one-time import), the master
key, the public address, mail, Google, the Cloudflare zone's origin secret -
is in `backend/config/*.local.php` and nowhere else: gitignored, never
defaulted from anything committed. The committed `config/*.php` beside them
read those files and hold only the tunables that are the same everywhere.
Each file is read cleanly or not at all (`readLocalConfigFile()`): one that
does not start with `<?php` - no `<?php` line, a byte-order mark or a line
before it - would send what it holds ahead of every answer, and with
`declare(strict_types=1)` after such a start PHP could not even compile it (a
fatal error no handler catches: every request a 500, the status too), so its
first five bytes are read alone and the file is passed over uncompiled
(Japanese Academy's review of 2026-10-18); what one that does start so
prints all the same - text after a closing tag - is caught and goes nowhere
and the file is passed over too. Each is said by its fault (and byte count),
never its text; so is one PHP cannot read. The origin secret's
(`cloudflare.local.php`) so passed over fails closed: a secret no request
carries, every request from Cloudflare's ranges its edge - and so does one
read cleanly that names no secret, or a folder in its place (2026-10-18):
only nothing at its path is no secret.

They are required. Until `database`, `secret`, `iam` and `mail` are all there
and sensible (`google` and `cloudflare` are optional; the installer never
writes `cloudflare`, the secret uploaded by hand), the API answers every request - discovery
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
keep an existing master key, keep whatever else the files hold - and save over
none they could not read cleanly -, and either
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

- **The engine** (`iamMigrator()`): three sets, in this order - the library's
  admin set, `anotoki_admin` (`vendor/anotoki/lib/backend/migrations/admin`: the
  admin tools' tables, "The admin panel's tools" below), the IAM's own, `iam`
  (`migrations/iam`), then the library's translations set,
  `anotoki_translations` (`vendor/anotoki/lib/backend/migrations/translations`:
  the tables of the words proved to be in the family's shape, and the
  library's own words; "The IAM's own words" below). The admin set comes first
  because the IAM's 018 moves its audit log into the library's table; the
  translations set last because the IAM's 006 made those tables long before
  the library had them. All three are recorded in the `migrations` table as it
  always was (`id`, `filename`, `applied_at` - the library reads that shape and
  never changes it): the IAM's files by their names, the library's as
  `set/name` (`anotoki_admin/001_audit_log.sql`,
  `anotoki_translations/001_languages_and_strings.sql`), which is also how the
  command line and the audit log name them. Each file in a transaction
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
  `signInDevicesReady()`, `auditMovedToLibrary()`), and of its pages only the admin panel says so:
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
  on Apply. Apply backs the database up first (`iamMigratorWithBackup()`, the
  library's `beforeApply`) and applies nothing when that backup cannot be
  made; applying is audited (`migrations.applied`, after the audit entries
  that waited for 018), and the strings are read again: a migration may have
  brought some.
- **Not set up**: the pages' `/not-set-up` is the family's
  `<anotoki-site-status>` in the IAM's own words - "This IAM is not set up
  yet", "Open the installer" - with the developer's line, while it was never
  installed; once installed, "The site is not available right now", asking
  again by itself, and never the installer. It is a page of its own (the
  owner's rule of 2026-10-18, the family's): on a route outside every frame,
  in a `<main>` of its own as the family's gate draws it on the sites that
  have one - no bar, no language or theme switch, no night sky, no footer.

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
                                           → session cookie `__Host-anotoki_iam`
                                             (`anotoki_iam` on plain http)
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

**The sign-in hint** (the owner's decision of 2026-10-07). Every session opened
here also sets `anotoki_signed_in`: a random value with nothing of the person
in it, readable by the sites' pages - under the issuer's parent domain on a
real host (`anotoki.eu`), and only while a site switched on signs in under that
domain; host-only `localhost` in development. A site that lets visitors in,
with no session of its own, reads it and makes the authorize above with
`prompt=none`: a live session here comes straight back with a code, no page
shown; none comes back `login_required`, and authorize clears the stale hint
for every site at once. Without the hint a site sends nobody anywhere - no
visitor without an account, no search engine. Both sign-outs, ending this
browser's own session and a deletion asked for clear it, and an account no
site lets in (its deletion pending) has none wherever the IAM sees it; a
site's single sign-on and the account pages give a live session one when its
browser has none, and a deletion cancelled or a forced change of password done
gives a new one, so the sites try again. It is never proof of anything: what a
hop brings back is a code, by every rule of any sign-in ([api.md](api.md), "The
sign-in hint cookie").

## What is stored

One PostgreSQL database, built by `backend/migrations/iam/*.sql` and nothing else.

| Table | Holds |
| --- | --- |
| `users` | Accounts: username and address (unique regardless of case; a username chosen or made since 2026-10-19 is letters and digits only - `usernameRefusal()` -, one from before is kept), the password hash (nullable), whether password sign-in is on, the TOTP secret **encrypted**, the flags (confirmed, forced change, disabled, approved - false while it waits for an administrator, news_mail - whether it gets the announcements), the language and how every site is to look - `theme`, `date_format`, `time_format` (015), and a deletion its owner asked for - `deletion_requested_at`, `deletion_due_at`, then `anonymized_at` (016). The id is the `sub` every site keys its data on: a deleted account's row stays, a placeholder, so it is never anybody else's. |
| `user_sign_in_devices` | The browsers an account signed in from at the IAM (016): the SHA-256 of the account's id and the browser's device cookie, first and last seen, the browser in a few words. Decides whether a sign-in is mailed; opens nothing. |
| `user_identities` | Who an account is at Google: provider + permanent subject. |
| `user_tokens` | One-time mailed secrets: confirmation link, reset link, six-digit sign-in code - hashes only, with `context` (which application a registration came from). A confirmation link is good only while its address waits to be confirmed: every one of the account's is spent once the address is confirmed, by any way, and when two-factor is switched on (026); and only for the address it was mailed to, a SHA-256 of which its context keeps (026 spent every live one that kept none). |
| `google_id_tokens_used` | The Google id tokens used here (026): the SHA-256 of what Google signed, when the token runs out, and - while the Google sign-in of the account it names waits for its second factor or was refused before anything changed, or the account page's connect waits for its proof or was refused - that account (`held_for_user`): held, the token is that account's alone; spent the moment it changes something (a session opened, an account made or claimed, an identity attached or connected), so it is good once. Housekeeping sweeps it once it has run out, held or spent; an account deleted leaves its holds spent. |
| `user_recovery_codes`, `user_trusted_devices` | Two-factor's way back in, and browsers that answered a code in the last 30 days - hashes only. |
| `sessions` | A signed-in browser at the IAM: the cookie's hash, a public `sid`, how it signed in, sliding expiry with an absolute cap. |
| `login_attempts`, `email_log` | The limiter's memory and the history; the mail log keeps no bodies. An attempt's `outcome` says how it went (`bad_totp` - a wrong authenticator code, which the pause counts by account; `totp_paused` - one refused unchecked during a pause; `bad_email_code` - a wrong code by email beside the password during a pause, five of which end the mailbox's standing in until it ends; `email_code_paused` - one refused unchecked then; ...). The pause itself is `users.totp_paused_until`, and `totp_count_from` where the count starts again (019). |
| `applications` (+ `application_redirect_uris`, `application_origins`, `application_roles`) | The sites: exact redirect URIs, the origins allowed to call the token endpoint, whether the site requires signing in (`login_required`, published to the sites' backends at `GET /api/public/applications/{client_id}`), token lifetime, their roles and which of them may sign in (`may_sign_in`). Every application is a public client and has the same roles - ADMIN, EDITOR, EARLY_ACCESS, MEMBER (the IAM's own entry ADMIN, MEMBER; EARLY_ACCESS since migration 023) - and every account holds MEMBER everywhere: triggers of migrations 010 and 011 keep it so for every account and application made later. `type`, `secret_hash`, `show_on_landing` and `access` stay, unread (`access` only while 010 is pending), until a later migration drops them - not a way back: once 010 is applied no build from before it goes on the host (`decisions.md`). `sort_order` is the order of the public list. |
| `user_roles` | Who holds which application role, and who granted it. |
| `auth_requests` | What an application asked for while the person signs in (and logouts waiting for confirmation). |
| `auth_codes`, `refresh_tokens` | Codes and refresh / personal tokens - hashes only; refresh tokens grouped into families for reuse detection. |
| `signing_keys` | RSA key pairs; the private half **encrypted**. One active, retired ones published for a day. |
| `settings` | The switches. |
| `anotoki_audit_log` | What was done: every admin change and every security-relevant change an account made to itself - who, what, to what, the details, the address, the time. The anotoki library's table (its set `anotoki_admin`, 001), the IAM's own `audit_log` with three columns the IAM leaves empty (a session, a scope); migration 018 moved every entry there with its id, and kept the old table, unread, as `audit_log_before_018` until a later migration drops it. Kept for good, its person scrubbed out when an account is deleted. |
| `anotoki_feedback` | What people sent through the family's feedback form (the library's table, its set `anotoki_admin`, 002; the IAM's since the owner's decision of 2026-10-19): the kind, the subject and the message, the page as a path, its language, the browser's name, the sender's account unless it was sent without a name, a reply address only as the sender left it, its status and who handled it. Nothing of where it came from. Spam 30 days and resolved 365 days after they were marked; when an account is deleted, its messages - and any whose reply address is its address - lose the address and the browser. |
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
  names and addresses, an announcement's address, a feedback message's reply
  address and browser, its words kept - and the row becomes a
  placeholder, switched off, that nothing changes again: its id stays
  reserved. Once committed, the address it had gets the last mail. A request
  that read the account a moment before writes nothing back onto the row (its
  writes ask for one not anonymized - `liveAccountSql()` - and
  `users_anonymized_check` keeps a placeholder without a way in), and what it
  leaves elsewhere is swept at every run (`anonymizedLeftovers()`).
- **The sites** read the deleted ids at `GET /api/public/anonymized-accounts`
  and scrub their own copies of the person's name and address, keeping the
  rest of their data under the id, nobody's now.
- **The record beside the database** (the owner's decision of 2026-10-13):
  each request, cancellation and anonymization is also a line of
  `storage/records/account-deletions.jsonl` - add-only, the account's id and
  the database's time, nothing of the person - written once its transaction
  committed (`recordAccountDeletionEvent()`; a line that cannot be written is
  the error log's, never the person's). A database lost and put back by hand
  from an older backup would bring back everybody deleted since: `php
  account-deletions.php --replay --since=<the backup's time>` does again what
  the record holds - in the order the events happened (their own times, not
  the file's), each account as its last event says, a request with its own
  times, an anonymization run now - keeps the ids the record names from being
  issued again, ends every session, token, remembered browser and mailed link
  the restore brought back, and warns when it leaves the IAM no live
  administrator (`replayAccountDeletions()`; docs/operations.md, "When the
  database is lost"). Its own changes are lines of the record too, so running
  it again changes nothing.

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

On HTTPS the cookie is `__Host-anotoki_iam` (a hardening of 2026-10-07): a
browser keeps a cookie of exactly that name only from the IAM's own host -
`Secure`, `Path=/`, no `Domain`. It is read by that exact name from the
`Cookie` header - a look-alike that only decodes to it is not it, and either
name sent twice is no session - and by that name alone: the bare
`anotoki_iam` is never read on HTTPS (the owner's decision of 2026-10-10), so
a session a sibling host plants under it - beside a planted sign-in hint or
not - signs nobody in, and a browser from before the prefix signs in once
more (the sites keep their tokens; as of 2026-10-11 the owner's is the only
such browser). A bare one that still arrives is expired by the answer that
uses the session, and nothing is set in its place. The cookie is read, set
and expired in `api/session.php` and nowhere else.

## The account's preferences

The theme, the date order and the clock are the account's (migration 015),
as its language is: the `preferences` claim beside `locale` tells every site,
and the account page - or a site, with the person's access token, through
`POST /api/oidc/preferences` - changes them. Signed in, the IAM's own pages
follow them like any site: the family's `ThemeService` (`@anotoki/lib/shell`,
given the account by `frontend/src/app/core/kit/kit.config.ts`) shows the
account's theme and saves a choice to it - one save at a time, the latest
last - and the `appDate` pipe writes the account's order and clock. Signed
out, each device keeps its own theme, as before.

## The legal pages

Terms, Privacy and Contact are the anotoki family's pages (anotoki-lib 0.6.0,
the owner's decisions of 2026-10-13; `@anotoki/lib/legal/pages`), on the
library's own paths - `/terms`, `/privacy`, `/contact` - and the IAM's
Privacy page is the one every anotoki site links to for the account itself.
Anybody may read them: they are in the family's frame
(`layouts/legal-layout`), whose bar is the account pages' for somebody
signed in and Sign in for anybody else (the top bar's `public` area), and
the session is asked for before the first page when one of them is where the
site was opened. The family's footer links to them (`<anotoki-site-footer>`,
anotoki-lib 0.6.3, the build analyzer's: a top border, the muted ink at
13 px) under every sign-in, account and legal page and - as in every anotoki
admin panel, the owner's decision of 2026-10-18 - in the admin panel, in
English there: Terms · Privacy · Contact (`<anotoki-legal-links>`), and
above them the IAM's row (the owner's decision of 2026-10-19; "The version,
What's new and feedback" below). The not-set-up page has none of it.

The words are the library's (`anotoki.legal.*`, written by its translations
set's 005); the facts are the IAM's - what it keeps, for how long, its
cookies and stored items, who else handles data
(`frontend/src/app/core/legal/legal-facts.ts`): its own strings (`legal.*`,
migration 020, English and informal Slovak, reworded on the Translations page
like any other) and, in the code only, every address a page links to (the
account's Security page, where an account is deleted; the operator,
Cloudflare and Google in the library), the cookies' names and the days. A
rewording can change words, never where a link goes (the 0.6.0 review). The
facts are written from the code as it is - the tables, the housekeeping, the
deletion, the backups' keeping, the session and device cookies - so a change
to any of those changes them too, and the day in `legal-facts.ts`. Only the
pages read them: their frame gives them, and the first load, which every
sign-in page shares, carries only the footer's links.

Google is among who else handles data in the IAM's own words: nothing is
loaded from Google until somebody presses the IAM's own Google button
(`frontend/src/app/ui/google-button`, in Google's branding; migration 022, the
owner's decision of 2026-10-14). The press fetches Google's script - once a
visit - and draws Google's own button in its place, whose press opens Google's
account chooser and hands the page the id token the server checks, as before.

## The version, What's new and feedback

The footer's row is the IAM's since the owner's decision of 2026-10-19, as on
every anotoki site: the version, What's new and Feedback.

- **The version** is the newest entry of `frontend/public/changelog.json` -
  1.0.0 first - which `frontend/package.json` repeats, held to it by a spec.
  The pages ask for the file once as they start, never waiting for it
  (`core/changelog`); the web root serves it `no-cache`. Without it the
  footer shows neither the version nor What's new.
- **What's new** is the IAM's own dialog (`ui/changelog-dialog`, its code with
  the first press): every version, newest first, with what it added, put
  right and changed - the file's sentences, in the reader's language where the
  file has them whole (English and informal Slovak), its own words page keys
  (`whatsNew.*`, migration 024). It opens only when asked and keeps nothing in
  the browser.
- **Feedback** is the family's form (`@anotoki/lib/feedback/form`, configured
  in `core/feedback`), which the footer and the Contact page open, sent to
  `POST /api/feedback`: from anybody, the sender the account this browser's
  session names - or nobody, or "without my name" -, the account's address
  written in as the reply address until the person changes it, the CSRF
  header like every write, the library's limits by the visitor's address
  bucket or the account. The messages are the library's table
  (`anotoki_feedback`), read on the admin panel's Feedback page ("The admin
  panel's tools" below); an account's deletion takes the reply address and
  the browser's name out of them, the words staying.

## The IAM's own words

What the sign-in pages, the account page and the IAM's own mails say - and the
announcements' templates - lives in the database (migration 006), so a word can be reworded and a language added in the
admin panel with no build and no upload. The admin panel itself is English,
written in its templates.

Since anotoki-lib 0.3.0 the code that keeps them is the anotoki family's,
built from the IAM's own: `Anotoki\Lib\Translations` on the server (the
checks of a string, the bundle, the grid, saves, the languages, the routes,
the command line) and `@anotoki/lib/translations` in the pages (the service,
the switcher, the admin pages), the same on every anotoki site
(`docs/decisions.md`, 2026-10-11). What is the IAM's is its configuration:
`backend/api/translations.php` (`iamTranslations()`; the mails' rules
`IamMailRules`; the accounts per language `IamLanguageUsage`; refusals in the
IAM's error body) and `frontend/src/app/core/i18n/translations.config.ts`.

- **The tables.** `languages`, `translation_keys`, `translations` (above), in the
  family's one shape - the library's own first migration
  (`anotoki_translations/001`) proves it and makes nothing on the IAM. A key
  is the code's: a migration writes it with its English and its Slovak, and the
  panel only ever changes a string. `users.language` references
  `languages (code)` - `ON UPDATE CASCADE ON DELETE SET DEFAULT`, so deleting a
  language sends its accounts back to English, which cannot be deleted or
  switched off. The CHECK that allowed only `en` and `sk` is gone.
- **The library's own words** are keys under `anotoki.` - its UI kit's (the
  dialogs, fields, page numbers, the top bar, the language and theme
  switches) and its status page's - written by its second migration
  (`anotoki_translations/002`, 54 keys in English and Slovak), and since 0.4
  its sites' sign-in screen's and feedback form's (`003`, `004`: 40 more,
  which no page of the IAM's shows) - and reworded on the Translations page
  like the IAM's. That migration writes a key's strings
  only where the key has none, so an owner's rewording - and a language left
  blank on purpose - stays; their descriptions are the library's, and their
  placeholders are the library's English's whatever a stored rewording says.
  Two later files of the set reword strings it wrote before: 0.6.1's `010`
  gives the Privacy page's two sentences on where the backups are
  (`anotoki.legal.backupsWherePlain`, `anotoki.legal.backupsWhereEncrypted`)
  their new words, in English and Slovak, but only while each is still
  `005`'s word for word - a rewording stays. An administrator cannot have
  saved 005's very words before it on any site: every site applies 005 and
  010 in the same Apply. 0.6.2's `011` rewords three more
  (`anotoki.legal.privacyAccountRecord`, `backupsKept`,
  `storageSignInPurpose`) only while each is still `005`'s word for word and
  nobody saved it (`updated_by IS NULL`) - none of the three is on the IAM's
  pages: its Privacy page says its own words for the account, its backups'
  keeping and its storage. 0.6.3's `012` writes the footer's four words
  (`anotoki.footer.*`: the version, What's new, Feedback, the fan clause) by
  `002`'s rule - the first three on the IAM's footer since 2026-10-19, the
  fan clause never.
  The IAM's language switcher said its two words as `lang.label` and
  `lang.button`; migration 017 moved them, with every string, writer and date,
  to `anotoki.language.label` and `anotoki.language.button`, and renamed
  `sessions.method.other` / `sessions.ended.other` to `.unknown` (the family's
  key scan reads `.other` as a plural form).
- **Offered.** `languageCodes()` (`api/helpers.php`) is the list the rest of the
  IAM reads - discovery's `ui_locales_supported`, `/api/public/config`,
  `ui_locales`, the `locale` claim, an account's language. It works on a
  database that has no `languages` table yet, and answers `en`, `sk` then; it
  answers the same, unremembered, when the database cannot be reached, so the
  two routes that read nothing else from it still answer. A language switched
  off keeps its strings and its accounts; its readers get English pages, English
  mails and `locale: "en"` until it is back.
- **How the pages get their words.** One request, `GET /api/translations/{code}`
  (the bundle - the library's route, the IAM's answer byte for byte): the
  language's strings with English under them, the offered languages, and English
  on its own for the admin panel. The library's `TranslationService`
  (`@anotoki/lib/translations`, configured in `core/i18n/translations.config.ts`
  under the IAM's storage names, `anotoki-iam:lang`, `:lang-cache`,
  `:localization-drafts`, so a visitor's choice survived the move) picks the
  language - this tab's `?lang=` (which the backend adds from an application's
  `ui_locales`), a choice made on the page, the account's while somebody is
  signed in, this device's last choice, the browser's, English - and fetches its
  bundle before the page switches: a page is never in a language it has no words
  for. A choice made on the page is saved to the account, one save at a time
  and the latest last. The first page waits up to three seconds for its words; a
  returning reader starts from the bundle cached in `localStorage` and checks it
  (a 304, usually) within a second. A Slovak browser's first wish, `sk-sk`, is
  answered by `sk`, so a first visit is one request. The ETag and `no-cache`
  make every load after the first a few bytes.
- **The last resort, on both sides.** Between an upload and "Apply pending" the
  bundle route answers `503 translations_unavailable` (so does a database out of
  reach), and the sign-in page must still be readable or nobody could sign in to
  press Apply. The frontend then reads the English compiled from
  `core/i18n/en.ts`, and the library's keys its built-in words; the mails read
  `MAIL_FALLBACK` in `api/mail.php`, key by key, whenever the database has
  nothing to say. Only then: once a bundle is in memory, a key of the IAM's it
  lacks shows as the key itself (and warns once), so a missing string is seen,
  not papered over - a library key never does: the database's string, else the
  library's built-in words. `en.ts` is also the list of the IAM's keys -
  `TranslationKey` comes from it, so an unknown key is a compile error, through
  the IAM's typed `t` pipe - and a backend test holds it to the database: its
  keys are exactly the seeded page keys and its English each seeded English
  string, as `MAIL_FALLBACK` is the seeded mail English. There is no Slovak in
  the build.
- **Changes reach open pages.** After a save, the admin pages call
  `TranslationService.reload()`, which asks for the wanted language again and
  settles against the languages that come with it (the one on the page may have
  just been switched off). A failed load is asked again on the next navigation.
- **The mails.** A mail is put together by the server - greeting, intro, then a
  line that is the link or the code, validity, ignore (the approval notice has
  no use for the last two) - in the recipient's language while it is offered. The link and the code are never part of a
  string and have no placeholder; which placeholders a mail string has is
  `MAIL_KEYS`, the code, not the stored English. The panel refuses an address
  in a mail's words, a line break, and a line longer than a mail line may be -
  `IamMailRules`, which the library's checks ask for every `mail.` string, in
  the IAM's order; `mail.` is a server namespace, never in a bundle.
- **The admin pages** are the library's (`@anotoki/lib/translations/admin`, on
  lazy routes): Translations (`/admin/translations`: the grid, a search,
  "Missing in", export and import of one language as JSON, the mails in blocks
  of their own with their rules) and Languages (`/admin/languages`: add,
  rename, order, offer or hide, delete - offering an export first), worded for
  the IAM in `pages/admin/localization.ts`. What is typed and not saved is kept
  by the library's `LocalizationDrafts`, mirrored to `sessionStorage` for the
  tab: a session that ends, or Sign out, leaves the page without asking, and
  the same administrator finds the drafts in their boxes after signing in
  again; only another person signing in drops them. The library's guard asks
  before a link or Back leaves a page with drafts - not once nobody is signed
  in - and closing the tab is the browser's `beforeunload` question.

## The admin panel's tools: the error log, the audit log, feedback, backups

Four pages of the admin panel are the anotoki library's (anotoki-lib 0.4, the
owner's decision of 2026-10-11 - feedback since the owner's decision of
2026-10-19; 0.8.1 now, both halves): the same pages, routes and
answers on every anotoki site. What is the IAM's is their configuration -
`api/admin_tools.php` and `api/audit.php` - and where they are mounted: inside
the admin group (`routes/admin.php`), behind the session, the IAM's ADMIN and
the CSRF header, each group asking the library's own ADMIN check besides
(`iamAdminContext()`: the same rule, so a group mounted anywhere else would
still let nobody in). Where a request comes from is the family's one rule
(`requestIp()`, the owner's decision of 2026-10-11): the connection's address,
or - on a connection from Cloudflare's published ranges, the IAM's trusted
proxies (`config/iam.php`; none wider than an IPv4 /8 or an IPv6 /16 is
believed) - the visitor's it names in `CF-Connecting-IP`, the one header read,
pinned in the code, and with the zone's origin secret
(`config/cloudflare.local.php`, the owner's decision of 2026-10-13) only on a
request that carries it in `X-Anotoki-Origin` - any other from the ranges is
the edge it came from; the limiter counts it, the audit log keeps it, a session
shows it, and a header from anywhere else is never believed.

- **The audit log** (`/admin/audit`) is `anotoki_audit_log`, written by the
  library's `AuditLog` through the IAM's `audit()`: what it always kept, behind
  a savepoint inside the caller's transaction, never throwing. Until migration
  018 has moved the old table in, an entry waits in
  `storage/logs/audit-pending.jsonl` and goes in after the Apply with its own
  time. An account's deletion scrubs it by the IAM's rules (the library's
  `scrubPeople()` and the IAM's personal-token rule), the kept old table too.
- **The error log** (`/admin/errors`) is files, `storage/logs/errors-*.jsonl`:
  what failed (an exception the IAM answered - with the failures it was thrown
  for -, every 5xx and 404, 405, 413, 415, 429, PHP's warnings and fatal
  errors) - files, so that it works while the database does not - and the
  pages' own errors (anotoki-lib 0.5.0, the owner's decision of 2026-10-11):
  `@anotoki/lib/error-report` reports them, through the app's HttpClient and so
  with the CSRF header, to `POST /api/client-errors` (the library's route,
  public and first-party, in neither CORS list; 20 an hour from one sender - a
  signed-in person by account, told apart by the session it only reads -, 500
  a day from the visitors, as many from the people signed in). The library's
  middleware sits outside every other (`api/index.php`), its handler wraps the
  IAM's error handler, its PHP handlers are chained to whatever was there. It
  keeps no query string, header, cookie, body, token or session, a message
  only redacted - by the library's rules with the IAM's names of secrets
  among them (`Redactor::addSecretNames()`), and the IAM's own prefixes and
  cookies taken out first on every way in, down an exception's whole chain
  (`iamRedactSecrets()`) - a trace without arguments, an address as a hash of
  its bucket (an IPv6 address its /64) keyed by the master key.
- **Feedback** (`/admin/feedback`) is `anotoki_feedback`: what people sent
  through the family's form (`POST /api/feedback`, "The version, What's new
  and feedback" above), by status, kind and words, each sender named as the
  account is named now; a new one opened is marked read by a PUT under the
  CSRF header, never by the GET (anotoki-lib 0.8.1); its status changed and its
  delete audited - the delete inside its own transaction. The library's
  limits are counted in `storage/cache` by a hash keyed by the master key,
  never in the database; what it keeps no longer - spam after 30 days,
  resolved after 365 - is pruned at most once a day after the answer,
  housekeeping included.
- **Backups** (`/admin/backups`) of the whole database, in
  `storage/backups`: COPY through PDO, every table in one snapshot, with the
  migrations it had applied; **encrypted** under a key derived from the master
  key, the rows never in the clear on the disk; made daily (after the answer
  of the day's first housekeeping - the library's `AfterResponse` runs after
  `$app->run()`), before every update, and by hand; made, downloaded (a
  single-use ticket for a minute, for the administrator it was issued to) and
  deleted only with the account page's proof (`accountProof()`); the page names
  who made each; **never restored here**. `php backup.php` reads one offline
  with the master key.

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
   the SDK. `typ` separates access tokens from ID tokens. A Google id token is
   taken only when it is Google's, for this IAM's client id, issued in the
   last five minutes, and never used here before: from the moment the Google
   sign-in finds the account it names it is that sign-in's alone - held for it
   while it waits for its code -, and the account page holds it for the
   account connecting it before it asks for the password; spent the moment it
   changes something, and remembered until it runs out (the security review of
   2026-10-19, and the reviews of its changes). The account page connects no
   Google whose address Google vouches for and another account has confirmed.
5. **Refresh tokens rotate; reuse ends the family.** A spent token gets one
   sibling within a minute (two tabs racing), never a second.
6. **Only hashes** of secrets that open things; **only ciphertext** of secrets
   that cannot be hashed (TOTP, signing keys).
7. **The same answer** whether or not an address has an account, wherever an
   endpoint mails an address; failures are **rate-limited** per address-and-source
   and per source. The source is the connection's address, or the visitor's
   that Cloudflare names in `CF-Connecting-IP` on a connection from its
   published ranges (the owner's decision of 2026-10-11) - that header alone,
   pinned in the code, never from anywhere else, never a configured range
   wider than an IPv4 /8 or an IPv6 /16, and with the zone's origin secret only
   on a request that carries it (2026-10-13: Cloudflare's ranges are every
   customer's; without it a request from them is the edge it came from), and
   anything at the secret file's path that yields no usable secret fails
   closed - only nothing there is no secret (2026-10-18) -
   counted by its bucket: an IPv4 address whole, an IPv6 address by its /64,
   so a network of one's own is no supply of fresh sources (`limiterSource()`).
   And wrong authenticator codes are counted **per account** too, whatever
   their sources: five within fifteen minutes pause its codes (rule 12).
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
12. **Two-factor holds on every way in, resets included** - and a confirmation
    link that would sign in, which asks for the code as a reset does; a
    session's `mfa` (and every token's `amr`) says a second factor only where
    its own sign-in checked one, never because the account has two-factor on
    (the security review of 2026-10-19). An authenticator code
    is accepted once, and every miss counts against the limiter. **Five wrong
    codes for an account pause its codes for fifteen minutes** (the owner's
    decision of 2026-10-13, migration 019): on every way that takes one, a code
    is then refused without being checked (`authenticator_paused`), one check
    at a time per account; a recovery code and a remembered device still work,
    and the password's sign-in may finish with a code mailed to the address -
    after the password, never in its place, and never by the mailbox alone;
    counted as the authenticator's codes are, five wrong in a pause and none
    until it ends; its session's `amr` says `email`, never `otp`. The owner is
    mailed once a pause. (So the password and the mailbox together sign in:
    the owner's choice, docs/decisions.md.)
13. **Proving an address on an account never confirmed clears it**: nothing its
    registrant set survives the owner's arrival. **A reset takes an account back
    completely** - sessions, personal tokens, devices, outside identities, the
    browsers it signed in from. **A confirmation link signs in only while its
    address waits to be confirmed** - decided in the write that confirms it -,
    only for the address it was mailed to - asked by that write too -, never
    past two-factor, and never in place of another account's session this
    browser has: every link of the account is spent once the address is
    confirmed, by any way, and when two-factor is switched on; one of an
    address confirmed already opens nothing, and the page sends a link only
    when the person presses its button, and names the account it signed in to
    (the security review of 2026-10-19, and the reviews of its changes).
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
17. **The sign-in hint opens nothing.** Its value is random per session, kept
    nowhere and derived from nothing - not the session's secret, hash or `sid`,
    not the account. Nothing reads it as more than "was it sent" - to clear it
    or set it again - and a site decides by it only whether a silent sign-in is
    worth a hop. An account no site lets in has none. It is the one IAM cookie
    a script reads and the one with a `Domain` - only one the family's sites
    sign in under; the session cookie stays host-only and `HttpOnly`,
    `__Host-` on HTTPS - where the bare name is never read, so a planted one
    beside a planted hint signs nobody in - and no CSRF or CORS exemption
    comes with the hint.
18. **A backup opens nothing without the master key, and nothing restores
    one here.** Every backup is encrypted under a key derived from the master
    key, its rows never in the clear on the disk; it is made, downloaded and
    deleted only with the account page's proof (the password, or a sign-in of
    the last 15 minutes) and audited; a download is a single-use ticket for
    one backup, one administrator and a minute. Restoring is off (kept off by
    the owner's decision of 2026-10-13): a database lost is put back by hand,
    and the add-only record of deletions beside it is replayed onto it - every
    deletion since done again, every session and token ended. The error log
    keeps no secret, token, cookie, header, body, query string or address, and
    no local configuration file's text is ever printed.

## Where the code lives

| File | |
| --- | --- |
| `backend/setup.php` | The installer: writes `config/*.local.php`, builds the database, the accounts, the sites' production addresses. |
| `backend/config/` | `config.php` (reading `*.local.php` - cleanly or not at all, `readLocalConfigFile()` - and whether the IAM is set up), and one loader per file: `db.php`, `iam.php`, `secret.php`, `mail.php`, `google.php`, `cloudflare.php` (the zone's origin secret). |
| `backend/api/index.php` | Front controller: requires, middleware order (the error log's outermost, the family's gate where "not set up" is answered), error handler; the library's after-the-answer runner after `$app->run()`. |
| `backend/api/migration_runner.php` | The IAM's migrations through anotoki/lib's engine (`iamMigrator()`: the library's admin set, the IAM's set, then the library's translations set; `iamMigrationLabel()` names a library file `set/name`), where the IAM stands (`iamSiteState()`), and the two things beside them: `ensureDatabase()`, `createFirstAdmin()`. |
| `backend/api/admin_tools.php` | The IAM's configuration of the library's admin tools: `iamAdminContext()` (who acts, who the ADMIN is, the proof, the address and its bucket, the people's names, the error body), `iamErrorLog()` with the IAM's names of secrets (`IAM_SECRET_NAMES`, given to the library's redactor) and its own prefixes and cookies (`iamRedactSecrets()`, down an exception's chain; `iamClientErrorRedaction()` on the pages' own errors), `iamFeedback()` (the feedback form's messages: the sender the session's account, `iamFeedbackSender()`; the limits' hashes keyed by the master key), `iamBackups()` (encrypted, never restored), `iamMigratorWithBackup()`. |
| `backend/api/audit.php` | `audit()`, every route's one way into the audit log - the library's `AuditLog` (`iamAuditLog()`), waiting in a file until 018 has run (`auditMovedToLibrary()`). |
| `backend/backup.php` | The backups from the command line - the library's command on the IAM's backups: list, make, inspect, extract; another IAM's with its master key. Never uploaded. |
| `backend/account-deletions.php` | The record of deletions from the command line: list it, and replay it onto a database put back by hand (`--replay --since=`, `--dry-run`, `--record=` a copy). Never uploaded. |
| `backend/vendor/anotoki/lib` | The anotoki family's library (Composer): the migration engine, the gate, the status and Migrations routes; the translations - the tables' migration set and the library's words, the checks of a string, the bundle and the admin routes, the command line's tools; the admin tools - their tables' set, the error log, the audit log, backups, their routes. Never edited here - updated by Composer (`docs/operations.md`). |
| `backend/api/routes/oidc.php` | Discovery, JWKS, authorize, token, userinfo, a site's save of its reader's preferences, logout, revoke, the public list of sites, the public "is sign-in required here?" answer. |
| `backend/api/routes/login.php` | Sign-in (password, emailed code, Google), registration, confirmation, resets, the pending request (accept/cancel), sign-out, stopping the news. |
| `backend/api/routes/account.php` | The account page. |
| `backend/api/routes/admin.php` | The admin panel. |
| `backend/api/oidc.php` | Requests, codes, refresh tokens, minting tokens. |
| `backend/api/session.php` | The session cookie (its `__Host-` name; the bare one never read on HTTPS, only expired), the sign-in hint, sliding expiry, revocation, the limiter. |
| `backend/api/keys.php`, `jwt.php`, `crypto.php` | Keys (made on first use), signing, encryption. |
| `backend/api/applications.php`, `accounts.php`, `settings.php` | The rules about who may use what. |
| `backend/api/account_deletion.php` | An account's deletion: asked, cancelled, done when due (housekeeping), and the public list of deleted ids; the record of each beside the database, and its replay after a restore by hand (`account-deletions.php`, the command line's). |
| `backend/api/authenticator_pause.php` | The one authenticator check of every way that takes a code (`authenticatorCheck()`), and its pause: five wrong codes for an account, fifteen minutes (019); the code by email that stands in during it on the password's sign-in, counted the same way (`pausedEmailCodeCheck()`). |
| `backend/api/sign_in_devices.php` | The browsers an account signed in from, the device cookie, and the mail for a new one. |
| `backend/api/totp.php`, `trusted_device.php`, `one_time_token.php`, `google_identity.php`, `mail.php` | Carried over from the genshin site, rules intact - and tightened by the security review of 2026-10-19: confirmation links spent with the address (`spendConfirmationLinks()`), a Google id token fresh and good once (`spendGoogleIdToken()`). |
| `backend/api/legacy_import.php` | The one-time move of the genshin site's accounts. |
| `backend/api/translations.php` | The IAM's configuration of the library's translations (`iamTranslations()`): the mails' rules (`IamMailRules`), the two sentences cut around `{app}`, the accounts per language (`IamLanguageUsage`), refusals in the IAM's error body; and the key scan's settings for the command line and the tests (`iamKeyScanner()`). The routes are in `routes/login.php` (the bundle) and `routes/admin.php`. |
| `backend/api/announcements.php` | Announcements: the senders, the checks of a draft and of its links, who it reaches, the mail's layout, the batches, the token that stops the news. |
| `backend/translations.php` | The strings from the command line - the library's command on the IAM's configuration, then the mails' keys against `MAIL_KEYS`: `--status`, `--export`, `--import`. Never uploaded. |
| `frontend/src/app/core/i18n/` | `translations.config.ts` (the IAM's configuration of `@anotoki/lib/translations`: its storage names, `en.ts` as the last resort, the account's language and its save), `translation.service.ts` (the library's service, seen with the IAM's keys), the typed `t` pipe, `en.ts` (the keys and the English of last resort). |
| `frontend/src/app/core/migrations/` | How the pages use `@anotoki/lib/migrations`: who is the IAM's ADMIN, the sign-in, the language and the IAM's own not-set-up words, the account's dates. |
| `frontend/src/app/core/kit/` | How the pages use the family's UI kit and shell (`@anotoki/lib/ui`, `@anotoki/lib/shell`; the styles are `@anotoki/lib/styles`, in `src/styles.scss`): the kit's words from the database (the IAM's own strings where it has one, `kit-words.ts`; the library's `anotoki.*` for the rest), the icons the IAM's templates name, the account's theme and the languages. |
| `frontend/src/app/core/legal/` | How the pages use the family's legal pages (`@anotoki/lib/legal`): `legal.config.ts` (the language, the database's words - in the first load, for the footer's links) and `legal-facts.ts` (the IAM's facts and every address the pages link to, given by the pages' frame). |
| `frontend/src/app/core/changelog/`, `ui/changelog-dialog/` | The IAM's versions (`public/changelog.json`, asked for as the pages start): the footer's version, and What's new - the IAM's own dialog, its code with the first press. |
| `frontend/src/app/core/feedback/` | How the pages use the family's feedback form (`@anotoki/lib/feedback`): the page's language, the database's words, the session's account and its address. |
| `frontend/src/app/layouts/` | `auth-layout` (the sign-in pages' night-sky frame), `top-bar` (the anotoki family's one bar, `<anotoki-top-bar>`, worded for the IAM: the account pages as links, the language, the theme, the person's menu; a menu button up to 1040 px), `legal-layout` (the legal pages' frame). The account pages, the legal pages and the admin panel (with its side navigation) are in the family's frame, `<anotoki-shell>`; every frame, the sign-in pages' too, has the family's footer under it (`<anotoki-site-footer>`, `@anotoki/lib/footer`: the IAM's version, What's new and Feedback, then Terms · Privacy · Contact). `/not-set-up` is on none of them (`app.routes.ts`). |
| `backend/api/housekeeping.php` | Sweeps dead requests, codes and old spent tokens, and deletes the accounts whose deletion is due, at most hourly, from ordinary traffic - the host has no cron; prunes the feedback kept no longer, at most daily. |
| `backend/tests/` | In-process tests - the app and the installer - against throwaway databases and temp configuration. |
