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

The host is FTP/SFTP only: no shell, no cron. Everything that would be a command
is a page - `setup.php` for the first install, the admin panel's Migrations
page afterwards. `docs/operations.md` is the full reference; this is the order.

## Build

```bash
cd frontend && npm run build:deploy       # frontend/dist/iam/browser/ + .htaccess
cd ../backend && php tests/run.php        # must pass before anything ships
php build-dist.php                        # backend/dist/
```

## Upload (into the IAM subdomain's web root)

```
frontend/dist/iam/browser/*  ->  /
backend/dist/*               ->  /
backend/setup.php            ->  /          (first install only)
```

Never upload `config/*.local.php` from a development machine - the build leaves
them out and the server writes its own. Until it has, the IAM answers
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

Upload the new build over the old; admin panel → Migrations → Apply pending.
Meanwhile the IAM keeps working (it does not block while an update waits - the
other sites sign in through it); its administrators see "A database update is
waiting" above the admin panel's pages. The Migrations page is the anotoki
family's (anotoki/lib, `vendor/anotoki/lib` - uploaded with `vendor/`); one
apply at a time.

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
turning genshin's and the analyzer's visitors away). Until Apply, signing in
keeps the rule before 010 and the admin panel's Applications pages answer
"Apply the pending migrations" (the public answer the sites read is a 503:
a site keeps its last answer, or requires signing in). Before uploading, `php clients.php` (or the
Applications page) shows which applications are members-only: 010 unticks
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

The pages' and mails' words are rows in the database (migration 006 and every
later migration with strings). Between the upload and Apply pending the pages
read the English compiled into the build, the mails go out in English, there
is no language switcher, and `GET /api/translations/{code}` answers
`503 translations_unavailable` - expected in that window, not a fault; the
first navigation after Apply brings the words back. Before a release that
changes strings, have the owner export the live strings (Admin panel → Translations
→ Export, per language) and keep the files: a migration never writes over a
string reworded in the admin panel, and the export is the owner's wording to put
back if a release's rewording is not wanted.

## Check afterwards

- `/.well-known/openid-configuration` at the IAM's address names that address as the issuer.
- `/.well-known/jwks.json` lists a key.
- Admin panel → Applications: every site has its https addresses and no `localhost` one.
- Sign in at `/login`; the admin panel at `/admin` shows the accounts and roles.
- Each site's sign-in button reaches the IAM and comes back signed in.
- After Apply pending: `/api/translations/sk` answers 200, the sign-in page has
  its language switcher, and Admin panel → Translations shows the strings.
