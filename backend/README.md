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
  index.php               the Slim app: security headers -> not set up -> CORS -> errors -> CSRF -> body -> routes
  meddleware/             auth.php (requireSession, requireAdmin), security.php (headers, CSRF, CORS, not set up)
  routes/                 by audience: oidc.php (the sites), login.php (sign-in pages),
                          account.php (requireSession), admin.php (+ requireAdmin: ADMIN of `iam`)
  oidc.php, session.php, applications.php, accounts.php, keys.php, jwt.php, crypto.php,
  settings.php, audit.php, translations.php, mail.php, totp.php, trusted_device.php,
  one_time_token.php, google_identity.php, housekeeping.php, helpers.php
                          the logic, in plain function files beside the routes
  migration_runner.php    the migration engine (CLI, installer, admin panel), createFirstAdmin()
  legacy_import.php       bringing the genshin site's accounts over
config/
  config.php, db.php, iam.php, mail.php, secret.php, google.php
                          the loaders and the tunables that are the same everywhere (committed)
  *.local.php             what one installation is - written by setup.php, gitignored, required
migrations/iam/           NNN_name.sql - the schema and the seeded data; never edit an applied one
storage/                  cache/, mail in development (storage/mail/*.eml), the setup nonce, the install lock
tests/                    run.php, lib.php, setup_lib.php, translation_keys.php and one *.test.php per area
vendor/                   Slim and its dependencies, committed
```

## Commands

```bash
composer start                            # http://localhost:8100: the API, and the installer at /setup.php
php tests/run.php                         # every test file, each in a process of its own
php migration.php --status                # what the configured database has applied and what is pending
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
| `setup.test.php` | the installer on this computer: the nonce, "Test connection", saving, running again |
| `setup-remote.test.php` | the installer on a real host: the token, https, production addresses, lock and removal |
| `import.test.php` | the genshin site's accounts brought over |
| `survey-application.test.php` | migration 005 on IAMs installed before it, and the survey site signing in |
| `every-site.test.php` | migration 007 on a fresh IAM and on one shaped like an installed one; the first administrator's ADMIN in every application |
| `top-bar-strings.test.php` | migration 008 (the top bar's words) on a fresh IAM and on one shaped like an installed one |
| `admin-panel-strings.test.php` | migration 009 ("Admin panel") rewording only 006's words, on a fresh IAM and on one shaped like an installed one |
| `sign-in-rules.test.php` | migration 010 (MEMBER for everyone, roles that may sign in, sign-in required) on a fresh IAM and on one shaped like an installed one - who gets in, before and after; its triggers on every way an account or application is made; the rule through every way in, personal tokens and codes given before included; two saves of one application at once; the public contract (`/api/public/applications/{client_id}`) and the SDK reading its answer; the admin API's rules; the window while 010 is pending. And migration 011 (public clients only, the same roles everywhere, no landing page settings) on a fresh IAM and on one through 010: a client secret refused, PKCE of every client, fixed roles, a new application's three roles and its place last; the panel's lists of who is blocked where |
| `translations.test.php` | the strings: `en.ts`, the mails' fallback and the seeded English held together |

Beside them, not tests themselves (`run.php` runs `*.test.php` only): `lib.php`
(the harness - throwaway databases, the in-process `Browser`, fixtures,
`buildIamThrough()` / `applyMigrationsThrough()` for a database up to one migration),
`setup_lib.php` (the installer driven in-process) and `translation_keys.php`
(the frontend's keys and English, shared with `translations.php`).

Any change to an endpoint or the installer comes with a test; a change to a
security rule, with a test of the attack it stops. They must pass on the newest
PHP and on 8.2 - `run.php` starts each file with the PHP it runs under.
