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
| `migration.php` | `php migration.php [--status\|--dry-run]` - the migrations for the configured database (a backup of it first, as the admin panel's Apply makes) |
| `backup.php` | `php backup.php --list\|--create ["description"]\|--inspect=<id>\|--extract=<id> <folder>` - the database's backups in `storage/backups` (encrypted under the master key; never restored in place); `--from=<folder> --key=<secret.local.php>` reads another IAM's - a download from the live one - with its master key |
| `account-deletions.php` | `php account-deletions.php --list [--since=<time>] [--record=<file>]` - the record of every deletion asked for, cancelled and done (`storage/records/account-deletions.jsonl`, ids and times); `--replay --since=<the backup's time> [--record=<file>] [--dry-run]` - after a backup was put back by hand, each account as its last event since says, then every session, token, remembered browser and mailed link ended (`../docs/operations.md`, "When the database is lost"); refused while migrations wait |
| `translations.php` | `--status` (the pages' keys against the database's, each language's coverage, the mails' keys against `MAIL_KEYS`), `--export sk [file]`, `--import sk file` - the anotoki library's command (`TranslationsCommand`) on the IAM's configuration |
| `clients.php` | `php clients.php [--dev]` - lists the applications (whether each requires signing in and which roles may; before 010, its `access`; a client 011 will make public); `--dev` gives the anotoki sites their development addresses back |
| `create-admin.php` | `php create-admin.php you@example.com [password]` - the first administrator of an IAM with no accounts, ADMIN in every application that has one |
| `reset-password.php` | `php reset-password.php <email> [pw] [--clear-2fa]` - somebody back into their account, from the shell |
| `import-legacy.php` | `php import-legacy.php [--from=users] [--dry-run] [--site=genshin]` - the one-time move of the genshin site's accounts; `--site` is the application whose EDITOR an old EDITOR becomes (an old ADMIN becomes ADMIN in every application) |
| `build-dist.php` | `php build-dist.php` - the runtime-only slice into `dist/`, to upload beside the Angular build |

Every command-line tool reads the configuration `setup.php` wrote and stops
with a sentence when there is none (`requireSetUpOnCli()`). None of them is
uploaded: `build-dist.php` leaves them, the tests and `setup.php` out.
`php backup.php --extract` writes a backup decrypted into a new folder of the
owner's - the database in the clear: only on a computer you trust, and delete
it when done. `--inspect` reads the manifest alone, in memory, and writes
nothing (anotoki-lib 0.5.0).

## Layout

```
api/
  index.php               the Slim app: the error log -> security headers -> not set up (the family's gate) -> CORS -> errors -> CSRF -> body -> routes;
                          then the library's after-the-answer runner (the day's backup)
  meddleware/             auth.php (requireSession, requireAdmin), security.php (headers, CSRF, CORS)
  routes/                 by audience: oidc.php (the sites), login.php (sign-in pages),
                          account.php (requireSession), admin.php (+ requireAdmin: ADMIN of `iam`)
  oidc.php, session.php, applications.php, accounts.php, keys.php, jwt.php, crypto.php,
  settings.php, audit.php, mail.php, totp.php, trusted_device.php,
  one_time_token.php, google_identity.php, housekeeping.php, helpers.php,
  announcements.php, account_deletion.php, sign_in_devices.php
                          the logic, in plain function files beside the routes (account_deletion.php keeps
                          the record of deletions beside the database, and its replay)
  authenticator_pause.php the one authenticator check of every way in that takes a code, and its pause
                          (019: five wrong codes, fifteen minutes; a code by email after the password meanwhile,
                          until five of those are wrong too)
  translations.php        the IAM's words through the anotoki family's library (anotoki/lib's Translations):
                          iamTranslations() - its configuration -, the mails' rules (IamMailRules), the accounts
                          per language, refusals in the IAM's body; iamKeyScanner() for the CLI and the tests
  migration_runner.php    the anotoki family's library wired in: iamMigrator() - its migration engine, the library's
                          admin set, the IAM's set and then the library's translations set, for the CLI, the installer
                          and the admin panel - and iamSiteState() - where the IAM stands, for the gate and
                          /api/site-status; ensureDatabase(), createFirstAdmin()
  admin_tools.php         the library's admin tools on the IAM: iamAdminContext() (who acts, the IAM's ADMIN, the
                          proof - accountProof() -, the address's bucket, the people's names, the error body),
                          iamErrorLog() with the IAM's names of secrets (IAM_SECRET_NAMES, the library's redactor's)
                          and its own prefixes and cookies (iamRedactSecrets(), down an exception's chain;
                          iamClientErrorRedaction() on the pages' own errors), iamBackups() (encrypted, never
                          restored), iamMigratorWithBackup(); audit.php is audit() over the library's AuditLog
  legacy_import.php       bringing the genshin site's accounts over
config/
  config.php, db.php, iam.php, mail.php, secret.php, google.php, cloudflare.php
                          the loaders and the tunables that are the same everywhere (committed)
  *.local.php             what one installation is - written by setup.php, gitignored, required (google.local.php
                          optional; cloudflare.local.php, the zone's origin secret, optional and uploaded by hand).
                          Each read cleanly or not at all: one that prints (no <?php line, a BOM) is passed over
migrations/iam/           NNN_name.sql - the schema and the seeded data; never edit an applied one (the library's
                          own sets are in vendor/anotoki/lib/php/migrations: anotoki_admin runs before it,
                          anotoki_translations after it)
storage/                  cache/, mail in development (storage/mail/*.eml), the setup nonce, the install lock;
                          logs/ (the error log, and audit entries waiting for 018), backups/ (the database's,
                          encrypted), records/ (account-deletions.jsonl, the add-only record a restore by hand
                          replays - download it with the backups) - each folder guarded by an .htaccess of its own
tests/                    run.php, lib.php, setup_lib.php and one *.test.php per area
vendor/                   Slim, the anotoki family's library (anotoki/lib, from GitHub - never edited here; its
                          migration files and words come with it) and their dependencies, committed
```

## Commands

```bash
composer start                            # http://localhost:8100: the API, and the installer at /setup.php
php tests/run.php                         # every test file, each in a process of its own
php migration.php --status                # what the configured database has applied and what is pending
composer update anotoki/lib --prefer-dist # the family's library: with the frontend's @anotoki/lib (../docs/operations.md)
php translations.php --status             # the keys the pages use against the database's strings
php backup.php --list                     # the database's backups (--create, --inspect=<id>, --extract=<id> <folder>)
php account-deletions.php --list          # the record of deletions; --replay --since=<time> [--dry-run] after a restore by hand
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
| `addresses.test.php` | where a request comes from (the owner's decision of 2026-10-11): Cloudflare's published ranges as the committed trusted proxies, an installation's own or none; `requestIp()` - the visitor from an edge (v4, v6, an IPv4 address written as IPv6), the connection from anywhere else, never a header that is no single address or `X-Forwarded-For`; the header pinned - `X-Real-IP`, `True-Client-IP`, `X-Forwarded-For` from an edge never believed, nor a setting naming one, a guesser rotating them one visitor to the limiter -; a catch-all range (`0.0.0.0/0`, `::/0`, wider than an IPv4 /8 or an IPv6 /16) or no range refused and said in PHP's log; the attack - a forged `CF-Connecting-IP` from outside the ranges counted under its connection, the limiter holding -; through Cloudflare each visitor counted apart, and the visitor's address in the session and the audit log. And the origin secret (the owner's decision of 2026-10-13, anotoki-lib 0.5.2): with `config/cloudflare.local.php` the header believed only with the zone's `X-Anotoki-Origin` - none, a wrong one: the edge; the right one: the visitor; from outside the ranges: the connection -, a Worker writing a new address into every guess one source to the limiter, the audit log, the session and the library's limits reading the same; the value trimmed, an empty or non-string one no secret, the file never asked for by the set-up check; a local file that prints (no `<?php` line, a byte-order mark, a line before it, text after a closing tag) or that PHP cannot read passed over and never printed - the origin secret's failing closed (every request from the ranges its edge, the file's own value too), a master key with a BOM leaving the IAM not set up, nothing of it in any answer |
| `authenticator-pause.test.php` | migration 019, the authenticator's pause: five wrong codes from five addresses pause an account's codes for fifteen minutes and the sixth - the right code - is refused unchecked; the five by five ways (the emailed code's sign-in, Google, a reset, the account page's two checks) one count, and every way refusing during the pause; a code by email signing in after the password - never with a wrong one, never by the mailbox alone, no browser remembered, a session of its own (`password_email`, `amr` pwd, email, mfa) -, guessing at it counted for the account (five wrong and none until the pause ends, codes mailed since included; eight sent at once from processes of their own, each waiting at the account's row: five checked; one sent while the fifth wrong code is checked refused with the pause, untried; a check begun before the pause and reading after it started afresh, so counted), and standing in for nothing outside a pause; the pause lifting after fifteen minutes; the owner mailed once a pause, in their language, never at an unproved address; a right code, a recovery code or a completed sign-in starting the count again; recovery codes and remembered browsers working during the pause; two-factor on again with a new secret clearing it; the window while 019 is pending |
| `deletion-record.test.php` | the record of deletions beside the database: a line for every deletion asked for, cancelled and done (the owner of an unconfirmed address's cancellation too), once committed, ids and the database's times, its folder guarded; one that cannot be written said in the error log, the request going on; the replay after a database put back from a backup (the library's restore, standing in for the owner's by hand) - a request, an anonymization and a cancelled request done again in order, every session from before ended, no second last mail -, a dry run changing nothing, a second run nothing more; a copy replayed (`--record=`), its account made after the backup keeping its id and the replay's lines going into it; events whose lines stand out of order replayed in the order they happened (a request made again by its first asking); a replay leaving no live administrator warning so, dry and for real, and an administrator whose deletion was asked again cancelling it; refused while migrations wait |
| `client-errors.test.php` | the pages' own errors (`POST /api/client-errors`, anotoki-lib 0.5.0): written as `source: client`, scrubbed by the library's rules, the IAM's names of secrets and its own prefixes and cookies; the IAM's pages alone (the CSRF header, its Origin, no CORS answer) and the route's refusals in the IAM's body; 20 an hour a sender - a visitor by address, a signed-in person by account, named in the line -, an IPv6 visitor one sender by its /64 through Cloudflare's edge, the visitors' 500 a day and the signed-in people's apart |
| `setup.test.php` | the IAM before it is set up (`503 not_set_up`, naming the installer; the status), and the installer on this computer: the nonce, "Test connection", saving - the database built from nothing -, running again; a file it reads that prints (no `<?php` line, a byte-order mark) never shown and never saved over - the master key kept -, and `config/cloudflare.local.php` never touched |
| `setup-remote.test.php` | the installer on a real host: the token, https, production addresses, lock and removal; an installed IAM that loses a configuration file, never naming the installer again |
| `migrations.test.php` | the anotoki family's migrations module in the IAM: `/api/site-status` (ready, unavailable, an update waiting), an update waiting closing nothing - every way in, a site's sign-in, the account page, the admin panel and its tools -, the admin Migrations routes (the three sets, the library's admin set, the IAM's and the library's translations set; the list, a file's SQL and no path past it, Apply - a backup of the database first, named in its note - and its audit, a library file as `set/name`; who may; the CSRF header; one apply at a time), the command line building a database from nothing, `--status`, `--dry-run`, waiting its turn, backing up first, and a pooled connection letting go of the lock a dead request left |
| `admin-tools.test.php` | the admin panel's tools from the anotoki library (the error log, the audit log, backups): who may - nobody, an account, a site's ADMIN and EDITOR, an administrator on the way out, the CSRF header, the library's own guard -; the audit log keeping what it kept, its answer and filters, never undoing the change it records, the IAM's rules scrubbing a deleted person out of it and out of the table 018 keeps; the error log writing a failure, a warning, a 404 - and no secret, cookie, header, query string or address, the IAM's own names of secrets included -, an exception thrown for another with every cause's message written and each scrubbed (the whole chain walked), its own routes never written, Clear audited; backups made, downloaded and deleted only with the proof (the password and its limiter, or a recent sign-in), encrypted - nothing in the clear on the disk, opened with the master key alone -, listed with their maker's name, the single-use ticket for one backup, one administrator and a minute, restore refused, the day's backup after the answer |
| `audit-log-into-the-library.test.php` | migration 018 and the library's admin set before the IAM's files: on a fresh IAM; on one shaped like an installed one, with a history of every shape - every entry moved exactly with its id, the next numbered after them, the old table kept -; a gate that fails, a library table not empty, an action the library does not take - nothing moved, nothing kept -; the window while they wait - every way in, audit() failing no request, its entries waiting in a file, the Audit page waiting, a backup and a deletion meanwhile - and Apply, with a backup first, bringing them in with their own times; a flush killed after its commit, the next one putting nothing in twice and still scrubbing the people deleted meanwhile |
| `import.test.php` | the genshin site's accounts brought over |
| `survey-application.test.php` | migration 005 on IAMs installed before it, and the survey site signing in |
| `every-site.test.php` | migration 007 on a fresh IAM and on one shaped like an installed one; the first administrator's ADMIN in every application |
| `top-bar-strings.test.php` | migration 008 (the top bar's words) on a fresh IAM (its button's name under the library's key since 017) and on one shaped like an installed one |
| `admin-panel-strings.test.php` | migration 009 ("Admin panel") rewording only 006's words, on a fresh IAM and on one shaped like an installed one |
| `legal-pages-strings.test.php` | migration 020, the legal pages' facts: its seventy keys, each described, in English and an informal Slovak, none holding an address (where a link goes is the code's) nor a placeholder, the gate naming every key the file writes; the sign-in pages' sky reworded from 006's "nothing is shared" - 006's words only, where no administrator saved it: a saved string kept with its writer, in 006's very words too; on a fresh IAM, on one built through 019 as its owner left it, and a gate that fails loudly when a string does not arrive - nothing left, and Apply again once the cause is gone |
| `informal-code-buttons.test.php` | migration 021: the sign-in page's two "email me a code" buttons in the informal Slovak, their English untouched, "pošlite" in none of the IAM's own Slovak strings; on a fresh IAM, on one built through 020 as the files left it, on one where an administrator saved both - one in its formal words on purpose, one reworded: both kept with their writer, the gate passing -, and on one with a Slovak string blanked - kept blank |
| `sign-in-rules.test.php` | migration 010 (MEMBER for everyone, roles that may sign in, sign-in required) on a fresh IAM and on one shaped like an installed one - who gets in, before and after; its triggers on every way an account or application is made; the rule through every way in, personal tokens and codes given before included; two saves of one application at once; the public contract (`/api/public/applications/{client_id}`) and the SDK reading its answer; the admin API's rules; the window while 010 is pending. And migration 011 (public clients only, the same roles everywhere, no landing page settings) on a fresh IAM and on one through 010: a client secret refused, PKCE of every client, fixed roles, a new application's three roles and its place last; the panel's lists of who is blocked where |
| `translations.test.php` | the IAM's words on the anotoki library's code (whose own suite holds what every site's words do): the window before 006 - every way in, the mails in the code's English, Apply bringing the strings -; the catalogue - `en.ts`, `MAIL_KEYS` / `MAIL_FALLBACK` and the seeded English held together, the library's words its file's, every stored string passing the checks, the frontend's scan with the IAM's settings -; the IAM's configuration and its rules (the mails' - one short line, the bytes, the code's placeholders, no address -, the `{app}` sentences); the mails byte for byte, never failing, never turned against their reader; the contract on every route - the bundle, 304, 404 before any query, who may, the CSRF header, the body's refusals, the audit with a mail string's old and new text, `strings` and `accounts`, `ui_locales`, the `locale` claim, discovery and the pages' config without a database -; the command line |
| `strings-through-the-library.test.php` | migration 017 and the library's set after it on a fresh IAM, and on one built through 016 as its owner left it: every string of the four moved keys there under the new names - word, writer, time, a blank kept blank -, the bundles before and after differing by the four names and the library's words alone; the window while they wait - the sign-in pages' words, every way in, the Translations page - and Apply |
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
`Migrator`, `iamMigrator()`, naming a library set's file `set/name`) and
`setup_lib.php` (the installer driven in-process). The frontend's keys and its
compiled English are read by the library's `KeyScanner` and
`EnglishDictionary` (`iamKeyScanner()` in `api/translations.php`), shared with
`translations.php`.

Any change to an endpoint or the installer comes with a test; a change to a
security rule, with a test of the attack it stops. They must pass on the newest
PHP and on 8.2 - `run.php` starts each file with the PHP it runs under.
