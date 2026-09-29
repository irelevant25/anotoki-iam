# Developing the anotoki IAM

From a fresh clone to signing in, then the day to day, the tests, and the
builds for the hosting.

## Prerequisites

| Tool | Version | Notes |
| --- | --- | --- |
| PHP | 8.2+ | with `pdo_pgsql`, `openssl`, `mbstring`, and `curl` or `allow_url_fopen`; `php` on the PATH |
| PostgreSQL | 14+ | a local server, and a login that may create databases (the installer and the tests make theirs) |
| Node.js | 22.12+ | for the Angular app |

`backend/vendor/` is committed (the hosting cannot run composer), so composer
is only needed to change PHP dependencies.

## First setup

The IAM reads everything that belongs to one installation - the database, the
master key, its public address, mail, Google - from `backend/config/*.local.php`.
Those files are gitignored and required: without them the API answers every
request with `503 not_set_up`, and the pages show "not set up yet". The
installer writes them.

### 1. The installer

```bash
cd backend
php -S localhost:8100 router.php
```

Open **http://localhost:8100/setup.php**. On this computer it is the development
setup: no setup token, and every field prefilled with development values -
issuer `http://localhost:4300`, PostgreSQL on `127.0.0.1:5432` as `postgres`,
the database `iam`, mail written to files. Fill in the database password, press
**Test connection** to see that it works, choose how the accounts start -

- **a new administrator** (email and password) - for an IAM of your own, or
- **the genshin site's accounts**, from its old `users` database on the same
  server (**Test connection** beside it checks it is the right one) - how the
  owner's own IAM started -

and **Save**. It writes the config files, creates the database, applies the
migrations and makes the accounts. The applications arrive registered with
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
npm install
npm start          # http://localhost:4300, proxying /api, /.well-known and /setup.php to :8100
```

Sign in at `http://localhost:4300/login`. The account page is `/account`, the
admin console `/admin`. The issuer is `http://localhost:4300` - the address the
pages are served from - and every site's development configuration names it.

## Day to day

| | Command | Address |
| --- | --- | --- |
| API and installer | `cd backend && php -S localhost:8100 router.php` | `http://localhost:8100` |
| Pages | `cd frontend && npm start` | `http://localhost:4300` |

Mail goes to `backend/storage/mail/` as `.eml` files - the confirmation link or
the sign-in code is in the newest one.

Command-line tools in `backend/`, all reading the same configuration:

```bash
php migration.php [--status|--dry-run]         # apply pending migrations (the installer does too)
php clients.php [--dev]                        # list the applications; --dev puts their development addresses back
php import-legacy.php [--from=users] [--dry-run]   # the one-time move of the genshin site's accounts
php create-admin.php you@example.com           # a first administrator on an empty IAM
php reset-password.php <email> [pw] [--clear-2fa]
```

## Tests

```bash
cd backend
php tests/run.php            # every tests/*.test.php, each in its own process
php ../sdk/php/test.php      # the token verifier's own cases
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
npx prettier --check "src/**/*.{ts,html,scss}"
```

## The SDKs

`sdk/js/anotoki-auth.mjs` is the source; after editing it, `node sdk/js/build.mjs`
regenerates the classic-script `anotoki-auth.js`, and the version goes up in the
headers of all three files. Sites vendor copies: tell them about a new version.

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
