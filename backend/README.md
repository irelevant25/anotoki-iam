# anotoki IAM - backend

The identity provider's PHP half: the OpenID Connect endpoints every anotoki
site signs in through, the API behind the IAM's own pages (sign-in, account,
admin panel), the installer, and the command-line tools. PHP 8.2+, Slim 4,
plain PDO on PostgreSQL. Built for FTP-only hosting: `vendor/` is committed and
nothing here needs a shell on the server.

The contract with the frontend and with every site is
[`../docs/api.md`](../docs/api.md); how it fits together,
[`../docs/architecture.md`](../docs/architecture.md). Setting it up on this
computer is [`../DEV.md`](../DEV.md).

## Entry points

| File | What it is |
| --- | --- |
| `router.php` | PHP's built-in server made to behave like the hosting's Apache: `/setup.php` is the installer, everything else goes to `api/index.php` (`composer start`) |
| `api/index.php` | the front controller: builds the Slim app (`buildIamApp()`) and fixes the middleware order |
| `setup.php` | the installer - writes `config/*.local.php`, creates the database when the user may, applies the migrations, makes the first accounts. On this computer a nonce and re-runnable; on a host a token, then a lock and its own removal |
| `migration.php` | `php migration.php [--status\|--dry-run]` - the migrations for the configured database |
| `translations.php` | `--status` (the pages' keys against the database's), `--export sk [file]`, `--import sk file` |
| `clients.php` | `php clients.php [--dev]` - lists the applications (whether each requires signing in and which roles may; before 010, its `access`; a client 011 will make public); `--dev` gives the anotoki sites their development addresses back |
| `create-admin.php` | `php create-admin.php you@example.com [password]` - the first administrator of an IAM with no accounts, ADMIN in every application that has one |
| `reset-password.php` | `php reset-password.php <email> [pw] [--clear-2fa]` - somebody back into their account, from the shell |
| `import-legacy.php` | `php import-legacy.php [--from=users] [--dry-run] [--site=genshin]` - the one-time move of the genshin site's accounts; `--site` is the application whose EDITOR an old EDITOR becomes (an old ADMIN becomes ADMIN in every application) |
| `build-dist.php` | `php build-dist.php` - the runtime-only slice into `dist/`, to upload beside the Angular build |

Every command-line tool reads the configuration `setup.php` wrote and stops
with a sentence when there is none (`requireSetUpOnCli()`). None of them is
uploaded: `build-dist.php` leaves them, the tests and `setup.php` out.

## Layout

```
api/
  index.php               the Slim app: security headers -> not set up (the family's gate) -> CORS -> errors -> CSRF -> body -> routes
  meddleware/             auth.php (requireSession, requireAdmin), security.php (headers, CSRF, CORS)
  routes/                 by audience: oidc.php (the sites), login.php (sign-in pages),
                          account.php (requireSession), admin.php (+ requireAdmin: ADMIN of `iam`)
  oidc.php, session.php, applications.php, accounts.php, keys.php, jwt.php, crypto.php,
  settings.php, audit.php, translations.php, mail.php, totp.php, trusted_device.php,
  one_time_token.php, google_identity.php, housekeeping.php, helpers.php,
  announcements.php, account_deletion.php, sign_in_devices.php
                          the logic, in plain function files beside the routes
  migration_runner.php    the anotoki family's library wired in: iamMigrator() - its migration engine, for the
                          CLI, the installer and the admin panel - and iamSiteState() - where the IAM stands,
                          for the gate and /api/site-status; ensureDatabase(), createFirstAdmin()
  legacy_import.php       bringing the genshin site's accounts over
config/
  config.php, db.php, iam.php, mail.php, secret.php, google.php
                          the loaders and the tunables that are the same everywhere (committed)
  *.local.php             what one installation is - written by setup.php, gitignored, required
migrations/iam/           NNN_name.sql - the schema and the seeded data; never edit an applied one
storage/                  cache/, mail in development (storage/mail/*.eml), the setup nonce, the install lock
tests/                    run.php, lib.php, setup_lib.php, translation_keys.php and one *.test.php per area
vendor/                   Slim, the anotoki family's library (anotoki/lib, from GitHub - never edited here) and
                          their dependencies, committed
```

## Commands

```bash
composer start                            # http://localhost:8100: the API, and the installer at /setup.php
php tests/run.php                         # every test file, each in a process of its own
php migration.php --status                # what the configured database has applied and what is pending
composer update anotoki/lib --prefer-dist # the family's library: with the frontend's @anotoki/lib (../docs/operations.md)
php translations.php --status             # the keys the pages use against the database's strings
php build-dist.php                        # -> dist/ for the hosting
```

The development database is `anotoki_iam` on PostgreSQL's port 5432 - the
installer's defaults; a server on another port is typed into the installer.

## Tests

`php tests/run.php` runs every `tests/*.test.php` in a process of its own. Each
file drives the real Slim app and the installer in-process (no server, no
port) against a throwaway `iam_test_*` database it makes from the migrations
and drops at the end, with a configuration and storage of its own in a temp
folder. Only the PostgreSQL server is borrowed, from this machine's
`config/database.local.php` - so run the installer once first.

| File | Covers |
| --- | --- |
| `oidc.test.php` | the sign-in flow the sites depend on, and the attacks each protocol rule stops |
| `setup.test.php` | the IAM before it is set up (`503 not_set_up`, naming the installer; the status), and the installer on this computer: the nonce, "Test connection", saving - the database built from nothing -, running again |
| `setup-remote.test.php` | the installer on a real host: the token, https, production addresses, lock and removal; an installed IAM that loses a configuration file, never naming the installer again |
| `migrations.test.php` | the anotoki family's migrations module in the IAM: `/api/site-status` (ready, unavailable, an update waiting), an update waiting closing nothing - every way in, a site's sign-in, the account page, the admin panel -, the admin Migrations routes (the list, a file's SQL and no path past it, Apply and its audit; who may; the CSRF header; one apply at a time), the command line building a database from nothing, `--status`, `--dry-run`, waiting its turn, and a pooled connection letting go of the lock a dead request left |
| `import.test.php` | the genshin site's accounts brought over |
| `survey-application.test.php` | migration 005 on IAMs installed before it, and the survey site signing in |
| `every-site.test.php` | migration 007 on a fresh IAM and on one shaped like an installed one; the first administrator's ADMIN in every application |
| `top-bar-strings.test.php` | migration 008 (the top bar's words) on a fresh IAM and on one shaped like an installed one |
| `admin-panel-strings.test.php` | migration 009 ("Admin panel") rewording only 006's words, on a fresh IAM and on one shaped like an installed one |
| `sign-in-rules.test.php` | migration 010 (MEMBER for everyone, roles that may sign in, sign-in required) on a fresh IAM and on one shaped like an installed one - who gets in, before and after; its triggers on every way an account or application is made; the rule through every way in, personal tokens and codes given before included; two saves of one application at once; the public contract (`/api/public/applications/{client_id}`) and the SDK reading its answer; the admin API's rules; the window while 010 is pending. And migration 011 (public clients only, the same roles everywhere, no landing page settings) on a fresh IAM and on one through 010: a client secret refused, PKCE of every client, fixed roles, a new application's three roles and its place last; the panel's lists of who is blocked where |
| `translations.test.php` | the strings: `en.ts`, the mails' fallback and the seeded English held together |
| `announcements.test.php` | migration 013 (announcements) on a fresh IAM and on one through 012: the composer, who an audience reaches and why the others are left out, a draft's checks - links and addresses to the anotoki sites only -, the preview's layout with every word escaped, a test to the administrator alone, sending in batches with each person's version, skipping who can no longer be written to, a row another sender holds, the host's hourly allowance, Stop; the link that stops the news and its forgeries, the account page's switch, the senders, who may call it, the window while 013 is pending; and migration 014, the news offered at registration - the form's box, Google from either page, and a stranger's yes gone when the owner claims the account |
| `approvals.test.php` | migration 012 (new accounts waiting for an administrator's approval) on a fresh IAM and on one through 011: an account made by signing up - the form, a sign-up started from a site, Google - refused on every way in until approved, and every door refusing a waiting account's session and tokens should it hold any; approving and its mail, declining - never an address nobody confirmed, which its owner takes back; accounts made by an administrator never waiting; switching it off letting in everybody waiting, never one declined, and a sign-up going after a switch-off rather than beside it; the window while 012 is pending |
| `preferences.test.php` | migration 015 (the theme, the dates and the clock are the account's) on a fresh IAM and on one through 014: the account page, the `preferences` claim, a site's save with the person's token - never the cookie - and what it asks of the token; CORS; the window while 015 is pending |
| `account-deletion.test.php` | migration 016's deletion on a fresh IAM and on one through 015 (a new application giving MEMBER to all but a deleted account): asking - the password, or a sign-in of the last 15 minutes; the limiter, the last administrator, twice, the CSRF header -; the proof no session makes for itself (a first password, Google, password sign-in on or off, two-factor on - its setup turning a stale sign-in without a password away before a secret is made) and a change of password's limiter; the account closed at once (sessions, tokens, every site, the news) while the IAM's own sign-in works by every way in; cancelling, and the owner of an unconfirmed address taking a registrant's deletion back; housekeeping deleting what is due - every table scrubbed or kept, the row a placeholder nothing revives, the last mail only after the commit - on ordinary traffic; the races with it (the database refusing a way in on the placeholder, a reset link asked for meanwhile waiting for it in another process, the roles lock, the leftovers swept); the public list of deleted ids and its paging; the window while 016 is pending |
| `sign-in-mail.test.php` | migration 016's mail for a sign-in from a new browser: the first browser in silence, a second mailed once, every way in past two-factor, never single sign-on or a refresh; a mail that fails, the cookie as no credential, a browser shared by two accounts, a user agent never written into the mail, five mails an hour at most, a reset forgetting the browsers; the window while 016 is pending |
| `sign-in-hint.test.php` | the sign-in hint the sites read (`anotoki_signed_in`): set by every way in past two-factor, a new value each time; again for a session still live without one (single sign-on, the account and admin pages - not after a handler ended it) and anew for a deletion cancelled or a forced password change done; never for an account no site lets in (waiting, switched off mid-sign-in, its deletion pending - a raced hint cleared where seen); cleared by both sign-outs, ending this browser's own session, a deletion asked for, every no-session answer (the 401s, accept, an authorize with no session behind it), kept by `interaction_required`; its attributes on this computer and on a real host, the domain derived or configured and used only while a site signs in under it, a setting passed over said in the log at most once a day, null switching it off; the attacks - a forged or copied hint opening nothing anywhere, nothing of the person in it, no CSRF or CORS exemption. And the session cookie's `__Host-` name on https, where the bare `anotoki_iam` is never read (the owner's decision of 2026-10-10): a bare cookie, even with a live session's secret, opening nothing anywhere and expired in the answer while the sites keep their tokens, one planted beside a planted hint signing nobody in, a `__Host-` session beside one read by its exact name from the `Cookie` header (a look-alike that only decodes to it passed over, either name sent twice no session), the four cross-origin endpoints setting no cookie (sent a bare one, its session live or revoked), plain-http development unchanged |

Beside them, not tests themselves (`run.php` runs `*.test.php` only): `lib.php`
(the harness - throwaway databases, the in-process `Browser`, which sends its
jar as a real `Cookie` header and parses the cookie params from it with
Slim's own parser, as production does, fixtures,
`buildIamThrough()` / `applyMigrationsThrough()` for a database up to one migration,
and `appliedMigrations()`, `pendingMigrations()`, `applyMigration()`, ... - the
names the IAM's own engine had, now a few lines each over the library's
`Migrator`, `iamMigrator()`),
`setup_lib.php` (the installer driven in-process) and `translation_keys.php`
(the frontend's keys and English, shared with `translations.php`).

Any change to an endpoint or the installer comes with a test; a change to a
security rule, with a test of the attack it stops. They must pass on the newest
PHP and on 8.2 - `run.php` starts each file with the PHP it runs under.
