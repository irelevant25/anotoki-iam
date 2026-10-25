---
name: deploy-iam
description: >
  Build and ship the IAM to its FTP-only hosting, run the installer as a real
  host's one-time install, move the genshin site's accounts over, and apply
  later updates. Use for "deploy the IAM", "build for production", "install on
  the server", "import the accounts on production", "apply migrations on the
  live IAM".
---

# Deploy the IAM

The host is SFTP only: no shell, no cron. Everything that would be a command
is a page - `setup.php` for the first install, the admin panel's Migrations
page afterwards. `docs/operations.md` is the full reference; this is the order.
Ask for (and never print, store in a repository, or put in a commit) the SFTP
login, and confirm with the owner before `--go`: every anotoki site signs in
through the IAM.

The IAM's folder on the host, `anotoki.eu/sub/iam/`:

```
.htaccess   the switch: every request -> frontend/ (written once: anotoki-deploy switch)
frontend/   the built app, index.php (the front controller), .htaccess   } a release:
backend/    api/, config/ (the loaders, no *.local.php), migrations/,     } replaced whole
            vendor/, release.json                                         }
config/     *.local.php - the settings, the master key among them        never in a release
storage/    the lock, logs, backups, the deletion record, mail, caches    never in a release
```

## Build and release

```bash
cd backend && php tests/run.php                        # must pass before anything ships
cd ../frontend && bun run anotoki-deploy build         # npm run build:deploy (the build and its checks),
                                                       # then the release -> ../dist/frontend/ and ../dist/backend/
bun run anotoki-deploy deploy --backups <folder>       # a dry run: what goes up
bun run anotoki-deploy deploy --backups <folder> --go  # the live frontend/ and backend/ copied into <folder>,
                                                       # the release up beside them, checked, swapped in, the site checked
bun run anotoki-deploy rollback --go                   # the release before (kept as .old) back in
```

(`@anotoki/deploy`: a dev dependency of `frontend/`, from anotoki-lib's release.
`bun run` finds it whichever tool installed `node_modules/`; on Windows, `npx`
misses bun's links.) anotoki-deploy is the anotoki library's deploy tool, the
same for every site; the IAM's facts are in `backend/composer.json`,
`extra["anotoki-deploy"]`: what goes into `backend/` (never `setup.php`, the
command-line tools, the tests or a `*.local.php`), the routes (`/api`,
`/.well-known/openid-configuration`, `/.well-known/jwks.json` to the front
controller, which routes on the original path), the CSP and the headers. The SFTP
login is never in a repository: `ANOTOKI_DEPLOY_SECRETS` names a `secrets.json`,
with the host's pinned key in `hostkey.txt` beside it. The first time:
`bun run anotoki-deploy switch --go`, once - the folder's `.htaccess` becomes the
switch into `frontend/`; `backend/`, `config/` and `storage/` are never served.
The PHP finds its state with one rule (`iamStateRoot()`, `config/config.php`):
with `release.json` in `backend/`, `config/` and `storage/` are the ones beside
it; in a checkout, `backend/`'s own.

`backend/setup.php` is never in a release: for a first install, upload it by hand
into the live `backend/` (it deletes itself when done). Never upload
`config/*.local.php` from a development machine - the server writes its own. Until it has, the IAM answers
`503 not_set_up` to everything but `/api/site-status` - naming the installer
until the install has written `storage/installed.lock`, never after.

## First install

1. The owner creates the empty IAM database in the hosting panel.
2. Open `/setup.php` at the IAM's address. Opened from anywhere but that machine
   itself, it is a real host's install: the owner copies the token from
   `config/setup.local.php` over SFTP (the Test connection buttons need it too).
3. The public address - the IAM's https address, which becomes the issuer; the
   database (Test connection says "does not exist yet - Save creates it" when
   the user may create databases, "Make it in the hosting panel first" when it
   may not); **accounts: bring them over from `users`** (the genshin site's
   database, same server); **each site's production address** (the fields show
   examples as placeholders; blank waits for the admin panel); the mailboxes;
   the Google client id if Google sign-in is wanted (its authorised JavaScript
   origins must include the IAM's address).
4. The page reports what it did - the loopback addresses the database was
   seeded with are gone, the sites' production ones in place - locks itself
   (`storage/installed.lock`) and deletes itself. The owner downloads
   `config/secret.local.php` and keeps it safe.
5. The administrator the install made - or every ADMIN it brought over from
   `users` - holds ADMIN in every application that has one, the IAM's and
   every site's (the owner's rule). Admin panel → Users gives a site's EDITOR to whom
   it belongs.

## Then the genshin site

Only after the import: deploy the genshin site's IAM build and apply its
migrations from its admin Migrations page. Its migration 040 removes the old
account tables and refuses to run until the import's `iam_handover` marker is
in its `users` database.

## Later updates

`bun run anotoki-deploy deploy --backups <folder> --go`; admin panel → Migrations → Apply pending.
Meanwhile the IAM keeps working (it does not block while an update waits - the
other sites sign in through it); its administrators see "A database update is
waiting" above the admin panel's pages. The Migrations page is the anotoki
family's (anotoki/lib, `vendor/anotoki/lib` - uploaded with `vendor/`); one
apply at a time. Apply backs the database up first (encrypted, on the Backups
page) and applies nothing when it cannot; an upload never overwrites
`storage/` (its logs and backups) or `config/*.local.php`.

The move onto anotoki-lib 0.4.1's admin tools brings the library's admin set
(`anotoki_admin/001`, `/002`), migration 018 (the audit log moved into the
library's table, every entry with its id; the old table kept as
`audit_log_before_018` - which 027 dropped) and the translations set's `003` and `004`: apply
right after the upload - the Audit log page waits for it, and the entries made
meanwhile wait in `storage/logs/audit-pending.jsonl` until then. Once 018 is
applied, no build from before it goes on the host again. Download a backup
afterwards (Backups → Download, the password asked) and keep it with the copy
of `config/secret.local.php`: it opens with that key alone.

anotoki-lib 0.5.0 brings no migration. It counts the visitors' own addresses
through Cloudflare's ranges (the committed `config/iam.php`; a host behind
other proxies, or none, says so in `iam.local.php` - `docs/operations.md`,
"Behind Cloudflare"), writes the pages' own errors into the Error log, and
needs PHP's `zlib` beside `mbstring`. The backups' files are the PHP user's
alone (`0600`): take one off the server with the Backups page's Download.

anotoki-lib 0.6.0 brings the legal pages - Terms, Privacy and Contact at
`/terms`, `/privacy` and `/contact`, the Privacy page the one every anotoki
site links to for the account: the IAM goes up before the sites. Apply right
after the upload: it brings 020 (the IAM's facts for the pages), 021 (two
Slovak buttons informal) and the library's translations 005-009; until then
the facts show as their keys.

anotoki-lib 0.6.1 and migration 022 bring Google only on a press: the IAM's
own Google button first, Google's script only after somebody presses it.
Apply right after the upload: it brings 022 (the button's words, and the
Privacy page's sentence about Google) and the library's translations 010 (the
Privacy page's sentence on where the backups are); until then the button
shows its key (`google.signIn`).

anotoki-lib 0.6.2 comes with it: the library's translations 011 reword three
of the sites' Privacy sentences (none of them on the IAM's pages) - the same
Apply brings it. And a `config/*.local.php` that does not start with `<?php`
is passed over before PHP compiles it: one saved with a byte-order mark or a
blank line before `declare(strict_types=1)` now answers "not set up" (the
origin secret's: fails closed) instead of a 500 on every request, and PHP's
error log names it ("does not start with <?php") - save it again as plain
UTF-8 starting with `<?php`.

Migration 023 brings EARLY_ACCESS to every site's application (upload, then
Apply): nobody holds it, so nobody new gets in; `docs/operations.md` says what
the ADMIN-only sites then list, and how to keep them ADMIN alone.

anotoki-lib 0.6.3 brings the family's footer: the build analyzer's top border
and Terms · Privacy · Contact under every page of the IAM, the admin panel's
too (no row above them then: the IAM had no version, What's new or feedback
until 1.0.0, below), and `/not-set-up` with nothing of the IAM round it. The
same Apply brings the library's translations 012 (the footer's four words,
the first three on the IAM's footer since 1.0.0); nothing on the IAM's pages
waits for it.

1.0.0 (the owner's decision of 2026-10-19) brings the footer's row - the
version, What's new and Feedback - and the admin panel's Feedback page. Release it
(the frontend's `changelog.json` beside `index.html`, served `no-cache` by the
release's `.htaccess`), then Apply:
migration 024 brings What's new's six words (until then the dialog shows its
keys). The feedback form needs no migration and nothing in `config/`. After
the upload, check the footer reads "v1.0.0 · What's new · Feedback" and a
message sent reaches Admin panel → Feedback. A later release adds its entry to
`frontend/public/changelog.json` and moves `frontend/package.json`'s version
(and `package-lock.json`'s two) with it.

A migration that seeds a site (005, the survey site) leaves it on the live IAM
with no address - never a `localhost` one there. Admin panel →
Applications → the site, `{site}` being its https address: redirect URI
`{site}/auth/callback`, logout redirect URI `{site}/`, web origin and home page
`{site}` (no landing page settings since 011: every site switched on is listed). Then tell the owner to give its roles
(the survey site's EDITOR, ADMIN) to whom they belong; 005 gives ADMIN to the
IAM's administrators already. A site made by hand before its migration keeps
what it has.

Migration 007 arrives the same way (Apply pending; on this computer re-running
setup.php or `php migration.php` does it): every first-party site open to
every account, ADMIN and EDITOR in each, MEMBER gone with its grants, and every
IAM administrator ADMIN in every application that has one; a first-party
site's ADMIN or EDITOR deleted in the admin panel comes back. Apply it only
AFTER the sites are updated to the family's rules: an older Japanese Academy or
build analyzer still asks for MEMBER, which 007 removes, and would refuse
everybody but its ADMINs. Tell the owner to give each site's EDITOR to whom it
belongs.

Migration 008 (the top bar's words) ships with the frontend that has the
family's top bar, and Apply pending applies it together with 007 (in order):
upload that build only when 007 may go on too, and apply right after - in
between, the bar shows three keys where its words should be. Migration 009
("Admin panel") only rewords two strings that still hold 006's words; it
can go with that build or any later one.

Migration 010 (MEMBER for every account, roles that may sign in, sign-in
required per site) goes the other way round from 007: upload the IAM's build,
**Apply pending at once**, and only then any site that reads the sign-in
switch (a site reading it from an IAM without 010 requires signing in,
turning genshin's and the analyzer's visitors away). (A build since 027 runs
on no database without 010: the rule before it went with the `access`
column.) Before uploading, the Applications page showed which applications
were members-only: 010 unticks
their MEMBER, so only holders of their other roles keep getting in - tell the
owner which, and that ticking MEMBER opens one to every account. **Once 010
is applied, never upload a build from before it again** (it would let every
account into members-only sites and let blocked people back in): a problem
after 010 is fixed forward, with a new build.

Migration 011 (public clients only, the same roles everywhere, no landing page
settings) goes in the same Apply. It needs nothing first: every anotoki site
already signs in as a public client with PKCE. Tell the owner that an
application of another kind that kept a client secret must switch to PKCE (a
request carrying a secret is refused from the upload on, whatever Apply), that
every site now has ADMIN, EDITOR and MEMBER (new ones held by nobody), and
that the public list of sites is every one switched on.

anotoki-lib 0.11.0 and migration 027 (2026-10-25): upload both halves, then
Apply - the library's `anotoki_admin/003` (the site settings' tables), the
IAM's `027` (drops `applications.access` and `audit_log_before_018`) and the
translations set's `014` and `015`. Apply right after the upload. Then the
admin panel's System → Site settings sets the announcement (the IAM's one
part: no maintenance, no page access); the release's `.htaccess` sends
`/robots.txt` and `/sitemap.xml` to the front controller - check both after
the deploy (read-only GETs: robots.txt names `https://<the IAM>/sitemap.xml`,
the sitemap lists the five legal, About and Support pages). A build from
before 027 still runs on the database after it.

The pages' and mails' words are rows in the database (migration 006 and every
later migration with strings, and the anotoki library's own set,
`anotoki_translations`, applied after the IAM's). On an IAM without the tables
yet, between the upload and Apply pending the pages read the English compiled
into the build, the mails go out in English, there is no language switcher,
and `GET /api/translations/{code}` answers `503 translations_unavailable` -
expected in that window, not a fault; the first navigation after Apply brings
the words back. Before a release that changes strings - the move onto the
library (017 and `anotoki_translations/001`, `/002`) among them - have the
owner export every language (Admin panel → Translations → Export, per
language) and keep the files: a migration never writes over a string reworded
in the admin panel, nor does the library's, and the export is the owner's
wording to put back if a release's rewording is not wanted. Apply right after
the upload: until 017 is applied the account page's Sessions list would show a
key for a way of signing in it does not know.

## Check afterwards

- `/.well-known/openid-configuration` at the IAM's address names that address as the issuer.
- `/.well-known/jwks.json` lists a key.
- Admin panel → Applications: every site has its https addresses and no `localhost` one.
- Sign in at `/login`; the admin panel at `/admin` shows the accounts and roles.
- Each site's sign-in button reaches the IAM and comes back signed in.
- After Apply pending: `/api/translations/sk` answers 200 (with the library's
  `anotoki.*` words among its values), the sign-in page has its language
  switcher, Admin panel → Migrations has nothing pending (the library's two
  files listed as `anotoki_translations/...`), and Admin panel → Translations
  shows the strings.
- Admin panel → Backups lists the backup Apply made (`before-migration`,
  encrypted) and says restoring is off; Audit log lists the old entries with
  their ids; Error log opens (empty is fine).
