# anotoki · IAM

The identity provider for every anotoki site: one account, signed in once, used
on the genshin site, Japanese Academy, Piano Academy and the build analyzer. A
small OpenID Connect provider (authorization code + PKCE, RS256 tokens) plus the
sign-in pages, the account page, the admin console and an installer, built for
FTP-only PHP + PostgreSQL hosting, where it is a subdomain of its own.

| Part | Folder | Stack |
| --- | --- | --- |
| Backend | `backend/` | PHP 8.2+, Slim 4, PostgreSQL |
| Frontend | `frontend/` | Angular 21, standalone + zoneless, signals |
| SDKs | `sdk/` | `js/anotoki-auth.mjs` (+ generated `.js`, `.d.ts`) and `php/anotoki_iam.php`, copied into every site |
| Docs | `docs/` | `architecture.md`, `api.md`, `integrating-an-app.md`, `operations.md`, `decisions.md` |

Read `docs/architecture.md` before changing anything; `docs/api.md` is the
contract the frontend and every site depend on. `README.md` says what it is,
`DEV.md` how to get it running.

## Commands

```
# Backend (from backend/)
composer start                            # dev API (php -S localhost:8100 router.php), and the installer at /setup.php
php tests/run.php                         # every test, each file against a throwaway iam_test_ database
php migration.php [--status|--dry-run]    # apply migrations (setup.php does too)
php clients.php [--dev]                   # list the applications; --dev restores their development addresses
php import-legacy.php [--from=users] [--dry-run]   # the one-time move of the genshin site's accounts
php create-admin.php you@example.com      # first admin on an empty IAM
php reset-password.php <email> [pw] [--clear-2fa]
php build-dist.php                        # -> backend/dist/ for upload

# Frontend (from frontend/)
npm start                                 # ng serve on :4300, proxying /api, /.well-known and /setup.php to :8100
npx ng build                              # production; --configuration development for a debug build
npm run build:deploy                      # production build + the web root's .htaccess
npx prettier --check "src/**/*.{ts,html,scss}"

# SDKs (from the repo root)
node sdk/js/build.mjs                     # regenerate anotoki-auth.js after editing the .mjs
php sdk/php/test.php                      # the token verifier's cases
```

## Configuration and the installer

- Everything that differs per installation - the database (and the legacy
  `users` database for the import), the master key, `public_url`, mail, Google
  - comes **only** from `backend/config/*.local.php` (gitignored), read through
  `config/config.php` (`localConfig()`). The committed `config/*.php` hold the
  loaders and the tunables that are the same everywhere; there is no template
  to fall back on.
- They are **required**: `setUpRequiredMiddleware()` answers every request with
  `503 {code: "not_set_up", setup_url: "/setup.php"}` until `iamSetUpProblems()`
  is empty, and the frontend turns that into `/not-set-up`. The CLI tools stop
  with `requireSetUpOnCli()`.
- **`backend/setup.php` writes them**, in development too. On this computer
  (loopback `REMOTE_ADDR`, a localhost `Host`, no forwarding headers): no token,
  a nonce in the form (`storage/setup-nonce`) plus Origin / Sec-Fetch-Site
  checks, development values prefilled, re-runnable, never shows a password or
  the key, a blank password keeps the saved one (for the same host and user
  only), no self-delete. On a real host: the token in `config/setup.local.php`,
  https only, the sites' production addresses in place of the seeded
  development ones, then `storage/installed.lock` and self-delete. "Test
  connection" is a JSON POST to the same page under the same nonce or token.
  `setupHandle()` returns an answer instead of printing it, so
  `tests/setup*.test.php` drive it in-process.
- **Defaults are development.** The issuer on a development machine is
  `http://localhost:4300`; migration 002 seeds the sites with the port table's
  addresses (`README.md`); the installer prefills development values; docs and
  SDK examples use them. Production addresses are typed into the installer on
  the real host or the admin console - an example like
  `https://genshin.anotoki.eu` is only ever a placeholder.

## Backend layout & conventions

- `api/index.php` builds the Slim app (`buildIamApp()`, built once; tests call
  it with `IAM_NO_RUN` defined) and fixes the middleware order: security headers
  → not set up → CORS → error handler → CSRF → body parsing → routes.
- Routes by audience: `routes/oidc.php` (applications: discovery, JWKS,
  authorize, token, userinfo, logout, revoke), `routes/login.php` (the sign-in
  pages), `routes/account.php` (`requireSession()`), `routes/admin.php`
  (`requireSession()` + `requireAdmin()` = ADMIN of the application `iam`).
- Logic in plain function files beside them: `oidc.php` (requests, codes,
  refresh tokens, minting), `session.php` (cookie, sliding expiry, revocation,
  the limiter), `applications.php` (clients, redirect/origin checks and
  validation, roles, access policy), `accounts.php`, `keys.php` / `jwt.php` /
  `crypto.php`, `settings.php`, `audit.php`, and the carried-over `totp.php`,
  `trusted_device.php`, `one_time_token.php`, `google_identity.php`, `mail.php`.
- Files under `storage/` go through `iamStorageDir()`, config through
  `localConfig()` - the tests point both at temp folders (`IAM_CONFIG_DIR`,
  `IAM_STORAGE_DIR`, constants only code can define). No environment variables.
- Plain PDO with `?` parameters everywhere; no query builder. Booleans bound
  through `pgBool()`, read through `asBool()`. Every connection runs in UTC and
  every timestamp column is TIMESTAMPTZ; answers carry ISO-8601 via `isoTime()`.
- Errors are `respondError($response, $status, 'code', 'sentence')`; the OIDC
  endpoints answer OAuth's `{error, error_description}` via `oauthError()`.
- Every admin change and security-relevant self-service change writes
  `audit()`.
- **Migrations are the schema**: `backend/migrations/iam/NNN_name.sql`, applied
  in order by the shared engine (`api/migration_runner.php` - CLI, installer and
  the admin console's "Apply pending" all use it). Never edit an applied
  migration. A migration that changes data carries a `DO $$ … $$` gate that
  fails loudly.
- **Tests** (`backend/tests/*.test.php`, `lib.php`, `setup_lib.php`) drive the
  real app and the installer in-process with a `Browser` that keeps cookies,
  each file with its own temp configuration and `iam_test_*` databases, borrowing
  only the PostgreSQL server from `config/database.local.php`. Any change to the
  endpoints or the installer needs a test; a change to a security rule needs a
  test of the attack it stops. They must pass on the newest PHP and on 8.2.

## The rules that must not loosen

Each is load-bearing (see `docs/architecture.md` → "The rules that carry the
weight"): exact redirect-URI match and no redirect to an unverified one; PKCE
S256 for public clients; single-use 60-second codes that revoke on reuse;
pinned RS256 and `typ` checks wherever a token is read; rotating refresh tokens
with family revocation on reuse; hashes only for secrets that open things,
master-key ciphertext for TOTP secrets and signing keys; identical answers for
known and unknown addresses; the limiter on every mail-sending and sign-in
endpoint; the same switches on every way in; no self-lockout, no removing the
last admin; the CSRF header on every first-party write; no loopback address
left on a real host's install; the installer's proof (token, or nonce on this
computer only). Changing one is a decision for `docs/decisions.md`, not a
refactor.

## Frontend layout & conventions

- **Every component is three files**: `x.component.ts`, `.html`, `.scss` - no
  inline `template` or `styles`, even when the `.scss` is empty. Styles shared
  by a group of pages are partials `@use`d by each page's own `.scss`
  (`pages/auth/_auth-page.scss`, `pages/admin/_admin-list.scss`).
- `frontend/src/app/core/` - the hand-written API services and models
  (`core/api/`, mirroring `docs/api.md`), the session state, the HTTP
  interceptor (adds `X-IAM-Request: 1` to every write; sends a 401 outside the
  sign-in endpoints to `/login?next=…`; sends a 503 `not_set_up` to
  `/not-set-up`), translations (en/sk dictionaries, the `t` pipe), theme.
- `frontend/src/app/ui/` - the app's own small UI kit; build on it.
- Pages: the sign-in flow (`/login`, `/register`, `/confirm-email`,
  `/forgot-password`, `/reset-password`, `/logout`, `/error`, `/not-set-up`),
  the account page (`/account/**`), the admin console (`/admin/**`, English
  only).
- Zoneless and signals-first; OnPush everywhere; no UI library. Colours only
  from the CSS custom properties in `styles.scss`, defined for light and dark.
  Every page works at 390 px.
- The auth and account pages are bilingual (English, Slovak); a missing key is
  a compile error.

## Deployment

One web root on the IAM's subdomain: the Angular build (`npm run build:deploy`,
which writes the `.htaccess` routing `/api/` and `/.well-known/` to PHP) plus
`backend/dist/`. First install through `backend/setup.php` (uploaded by hand,
token proof, writes every `config/*.local.php`, takes the production addresses,
imports the genshin accounts or makes the first admin, locks and self-deletes);
later schema changes through the admin console's Migrations page.
`config/secret.local.php` is the master key - never commit it, and keep a copy
off the server. Full steps: `docs/operations.md`.

## Working conventions

- **The contract moves with the code.** A change to an endpoint's shape updates
  `docs/api.md`, the frontend's `core/api/`, and - for anything in the OIDC half
  - `docs/integrating-an-app.md` and the SDKs, in the same change.
- **SDK versions**: bump the header of all three JS files (and the PHP file)
  when their code changes; the sites vendor copies and need telling.
- **Comment style**: explain *why*, in plain prose, where a reader would wonder.
- **Nothing sensitive in a published file**: no credentials, no absolute paths
  from anybody's machine, no machine-specific tool paths; sibling repositories
  are named relatively (`../anotoki-genshin-impact`).
- **Stage only your own edits**, by path. Commit only when asked; end commit
  messages with the Co-Authored-By trailer.
- **Shut the dev servers down** (PHP on 8100, `ng serve` on 4300) before ending
  a task that started them.
- **Secrets**: `config/*.local.php` are gitignored. Never print the database
  password, the master key, client secrets, or any token.

## Agents & skills

In `.claude/agents/` and `.claude/skills/`. Backend work → **iam-backend-engineer**,
frontend work → **iam-frontend-engineer**, before calling a change done →
**iam-security-reviewer** (read-only).

- **skill: register-application** - add a site as a client, and what the site
  itself must do.
- **skill: iam-migration** - write and apply a migration in the house style.
- **skill: deploy-iam** - build, first install, the move from the genshin site,
  later updates.
- **agent: iam-backend-engineer** - PHP/Slim work in the conventions above.
- **agent: iam-frontend-engineer** - Angular work in the conventions above.
- **agent: iam-security-reviewer** - reviews a change against the protocol rules.
