# Developing the anotoki IAM

From a fresh clone to signing in, then the day to day, the tests, and the
builds for the hosting. What is where inside each half, and its entry points:
[backend/README.md](backend/README.md) and [frontend/README.md](frontend/README.md).

## Prerequisites

| Tool | Version | Notes |
| --- | --- | --- |
| PHP | 8.2+ | with `pdo_pgsql`, `openssl`, `mbstring`, and `curl` or `allow_url_fopen`; `php` on the PATH |
| PostgreSQL | 14+ | a local server, and a login that may create databases (the installer and the tests make theirs) |
| Node.js | 22.12+ | for the Angular app |
| Bun | 1.4+ | installs the Angular app's packages (`npm start` runs `bun ci` first) |

`backend/vendor/` is committed (the hosting cannot run composer), so composer
is only needed to change PHP dependencies - the anotoki family's library among
them, `anotoki/lib`, whose Angular half `@anotoki/lib` comes from its GitHub
release with the frontend's packages (updating both:
[docs/operations.md](docs/operations.md)).

## First setup

The IAM reads everything that belongs to one installation - the database, the
master key, its public address, mail, Google - from `backend/config/*.local.php`.
Those files are gitignored and required: without them the API answers every
request with `503 not_set_up`, and the pages show "not set up yet", with the
way to the installer (on this computer always: only a real host's install
locks it away for good). The installer writes them.

### 1. The installer

```bash
cd backend
composer start          # php -S localhost:8100 router.php
```

Open **http://localhost:8100/setup.php**. On this computer it is the development
setup: no setup token, and every field prefilled with development values -
issuer `http://localhost:4300`, PostgreSQL on `127.0.0.1:5432` as `postgres`,
the database `anotoki_iam`, mail written to files. Fill in the database
password, press **Test connection** to see that it works, choose how the
accounts start -

- **a new administrator** (email and password) - for an IAM of your own, or
- **the genshin site's accounts**, from its old `users` database on the same
  server (**Test connection** beside it checks it is the right one) - how the
  owner's own IAM started -

and **Save**. It writes the config files, creates the database, applies the
migrations and makes the accounts - the administrator, or each old ADMIN,
holding ADMIN in the IAM and in every site. The applications arrive registered with
their development addresses (the port table in [README.md](README.md)).

The page stays: open it again whenever a setting changes. It shows what is
saved, never a password or the master key; a password left blank keeps the one
saved. `config/secret.local.php` is the master key the signing keys and
two-factor secrets are encrypted with - keep a copy if the database matters.

Google sign-in is optional: a client id from the Google Cloud console, with
`http://localhost:4300` among its authorised JavaScript origins.

### 2. The pages

```bash
cd frontend
npm start          # http://localhost:4300, proxying /api, /.well-known and /setup.php to :8100
```

`npm start` installs the packages before it serves - `bun ci`, exactly as
`bun.lock` lists them - so there is no separate install step. `bun ci` stops
when `bun.lock` and `package.json` disagree, so add or update packages with bun
(`bun add`, `bun update`), which keeps `bun.lock` in step.

Sign in at `http://localhost:4300/login`. The account page is `/account`, the
admin panel `/admin`. The issuer is `http://localhost:4300` - the address the
pages are served from - and every site's development configuration names it.

## Day to day

| | Command | Address |
| --- | --- | --- |
| API and installer | `cd backend && composer start` | `http://localhost:8100` |
| Pages | `cd frontend && npm start` | `http://localhost:4300` |

Mail goes to `backend/storage/mail/` as `.eml` files - the confirmation link or
the sign-in code is in the newest one.

Command-line tools in `backend/`, all reading the same configuration:

```bash
php migration.php [--status|--dry-run]         # apply pending migrations (the installer does too)
php clients.php [--dev]                        # list the applications; --dev puts their development addresses back
php import-legacy.php [--from=users] [--dry-run]   # the one-time move of the genshin site's accounts
php create-admin.php you@example.com           # a first administrator on an empty IAM (ADMIN in every site too)
php reset-password.php <email> [pw] [--clear-2fa]
php translations.php --status                  # the keys the pages use against the database's; how much of each language there is
php translations.php --export sk [file]        # a language's own strings as flat JSON
php translations.php --import sk file          # and back in, checked as a save in the admin panel is
```

The pages' and mails' words are in the database (Admin panel → Translations and
Languages). A new string is its key and English in
`frontend/src/app/core/i18n/en.ts` plus a migration with the key, English and
Slovak - the iam-migration skill says how; `tests/translations.test.php` holds
the two together.

## Tests

```bash
cd backend
php tests/run.php            # every tests/*.test.php, each in its own process
php ../sdk/php/test.php      # the PHP SDK's own cases: the token verifier and the sign-in reader
```

The tests drive the real Slim app and the installer in-process - no server -
each file against a throwaway `iam_test_*` database it makes and drops, with a
configuration of its own in a temp folder. They borrow only the PostgreSQL
server from this machine's `config/database.local.php`, so run the installer
once first. They cover the flows applications depend on, the attacks each rule
exists to stop, and the installer on this computer and on a real host. Add a
test with any change to the protocol endpoints or the installer.

To run them under a second PHP (the hosting's version), call that binary
instead of `php`: `run.php` starts each file with the PHP it runs under.

Frontend checks:

```bash
cd frontend
npx ng build                 # production; add --configuration development for a debug build
npx ng test --watch=false    # the unit tests: needs vitest and jsdom in devDependencies (frontend/README.md)
npx prettier --check "src/**/*.{ts,html,scss}"   # a checkout with CRLF line endings: add --end-of-line crlf
```

## The SDKs

`sdk/js/anotoki-auth.mjs` is the source; after editing it, `node sdk/js/build.mjs`
regenerates the classic-script `anotoki-auth.js`, and the version goes up in the
headers of all three files. `sdk/php/anotoki_iam.php` carries its version in its
header and in `ANOTOKI_IAM_SDK_VERSION`; it must still parse under PHP 7.4
(`php -l` with a 7.4 binary), and `sdk/php/test.php` runs on 7.4 and the newest
(it starts a stand-in IAM of its own with PHP's built-in server on a free local
port, and stops it). Sites vendor copies: tell them about a new version.

## Building for production

```bash
cd frontend && npm run build:deploy     # -> frontend/dist/iam/browser/ (with the web root's .htaccess)
cd ../backend && php build-dist.php     # -> backend/dist/ (runtime only: no tests, CLI tools or config)
```

On the server the same installer runs as a real host's one-time install: a
token to prove you own the server, the production addresses of the IAM and of
each site, then a lock and its own removal. The upload map and the order of the
move from the genshin site are in [docs/operations.md](docs/operations.md).

## Troubleshooting

| Symptom | Likely cause |
| --- | --- |
| Every page says "not set up yet"; the API answers 503 `not_set_up` | The installer has not run, or a `config/*.local.php` is missing - `setup.php` lists which. |
| `setup.php` asks for a setup token on this computer | It was not opened as `localhost` / `127.0.0.1` straight to PHP: a LAN address, another host name, or a proxy that adds forwarding headers makes it a real host's install. |
| The pages load but every call fails | The PHP server on :8100 is not running, or was started without `router.php`. |
| A site bounces to `/error?code=invalid_redirect_uri` | The site's dev server is not on its port in the table, or its addresses were changed - `php clients.php --dev` puts them back. |
| Signing in works, the site says "invalid token" | The site's issuer is not `http://localhost:4300` exactly, or it cannot reach `/.well-known/jwks.json` there (the Angular dev server must be running - it serves the issuer's URLs). |
| `tests/run.php` stops before any test | No `config/database.local.php` to borrow the PostgreSQL server from: run the installer first. |
| A page shows a key (`login.title`) where a word should be | The key has no string in the database - a migration is pending (`php migration.php`), or it was never written: `php translations.php --status` names it. |
