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
is a page - `setup.php` for the first install, the admin console's Migrations
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
`503 not_set_up` to everything.

## First install

1. The owner creates the empty IAM database in the hosting panel.
2. Open `/setup.php` at the IAM's address. Opened from anywhere but that machine
   itself, it is a real host's install: the owner copies the token from
   `config/setup.local.php` over SFTP (the Test connection buttons need it too).
3. The public address - the IAM's https address, which becomes the issuer; the
   database; **accounts: bring them over from `users`** (the genshin site's
   database, same server); **each site's production address** (the fields show
   examples as placeholders; blank waits for the admin console); the mailboxes;
   the Google client id if Google sign-in is wanted (its authorised JavaScript
   origins must include the IAM's address).
4. The page reports what it did - the loopback addresses the database was
   seeded with are gone, the sites' production ones in place - locks itself
   (`storage/installed.lock`) and deletes itself. The owner downloads
   `config/secret.local.php` and keeps it safe.
5. The migrations ran before the accounts arrived, so nobody holds the survey
   site's ADMIN yet - 005 gives it only to administrators an IAM already has.
   Admin → Users gives it, and EDITOR, to whom they belong.

## Then the genshin site

Only after the import: deploy the genshin site's IAM build and apply its
migrations from its admin Migrations page. Its migration 040 removes the old
account tables and refuses to run until the import's `iam_handover` marker is
in its `users` database.

## Later updates

Upload the new build over the old; admin console → Migrations → Apply pending.

A migration that seeds a site (005, the survey site) leaves it on the live IAM
with no address and no landing card - never a `localhost` one there. Admin →
Applications → the site, `{site}` being its https address: redirect URI
`{site}/auth/callback`, logout redirect URI `{site}/`, web origin and home page
`{site}`, and "Show on the landing page". Then tell the owner to give its roles
(the survey site's EDITOR, ADMIN) to whom they belong; 005 gives ADMIN to the
IAM's administrators already. A site made by hand before its migration keeps
what it has.

The pages' and mails' words are rows in the database (migration 006 and every
later migration with strings). Between the upload and Apply pending the pages
read the English compiled into the build, the mails go out in English, there
is no language switcher, and `GET /api/translations/{code}` answers
`503 translations_unavailable` - expected in that window, not a fault; the
first navigation after Apply brings the words back. Before a release that
changes strings, have the owner export the live strings (Admin → Translations
→ Export, per language) and keep the files: a migration never writes over a
string reworded in the console, and the export is the owner's wording to put
back if a release's rewording is not wanted.

## Check afterwards

- `/.well-known/openid-configuration` at the IAM's address names that address as the issuer.
- `/.well-known/jwks.json` lists a key.
- Admin → Applications: every site has its https addresses and no `localhost` one.
- Sign in at `/login`; the admin console at `/admin` shows the accounts and roles.
- Each site's sign-in button reaches the IAM and comes back signed in.
- After Apply pending: `/api/translations/sk` answers 200, the sign-in page has
  its language switcher, and Admin → Translations shows the strings.
