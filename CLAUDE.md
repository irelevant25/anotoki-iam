# anotoki · IAM

The identity provider for every anotoki site: one account, signed in once, used
on the genshin site, Japanese Academy, Piano Academy, the build analyzer and the
survey site. A small OpenID Connect provider (authorization code + PKCE, RS256
tokens) plus the sign-in pages, the account page, the admin panel and an
installer, built for FTP-only PHP + PostgreSQL hosting, where it is a subdomain
of its own.

| Part | Folder | Stack |
| --- | --- | --- |
| Backend | `backend/` | PHP 8.2+, Slim 4, PostgreSQL |
| Frontend | `frontend/` | Angular 21, standalone + zoneless, signals |
| SDKs | `sdk/` | `js/anotoki-auth.mjs` (+ generated `.js`, `.d.ts`) and `php/anotoki_iam.php`, copied into every site |
| Docs | `docs/` | `architecture.md`, `api.md`, `integrating-an-app.md`, `operations.md`, `decisions.md` |

Read `docs/architecture.md` before changing anything; `docs/api.md` is the
contract the frontend and every site depend on. `README.md` says what it is,
`DEV.md` how to get it running, `backend/README.md` and `frontend/README.md`
what is where in each half (keep them in step with a change to the layout, an
entry point or a command).

## Commands

```
# Backend (from backend/)
composer start                            # dev API (php -S localhost:8100 router.php), and the installer at /setup.php
php tests/run.php                         # every test, each file against a throwaway iam_test_ database
php migration.php [--status|--dry-run]    # apply migrations (setup.php does too)
php clients.php [--dev]                   # list the applications; --dev restores their development addresses
php import-legacy.php [--from=users] [--dry-run]   # the one-time move of the genshin site's accounts
php create-admin.php you@example.com      # first admin on an empty IAM, ADMIN in every application
php reset-password.php <email> [pw] [--clear-2fa]
php translations.php --status             # the pages' keys against the database's; each language's coverage
php translations.php --export sk [file]   # a language's own strings as flat JSON; --import sk file puts them back
php build-dist.php                        # -> backend/dist/ for upload

# Frontend (from frontend/)
npm start                                 # ng serve on :4300, proxying /api, /.well-known and /setup.php to :8100
npx ng build                              # production; --configuration development for a debug build
npm run build:deploy                      # production build + the web root's .htaccess
npx ng test --watch=false                 # unit tests (Vitest): needs vitest + jsdom in devDependencies (frontend/README.md)
npx prettier --check "src/**/*.{ts,html,scss}"   # a checkout with CRLF line endings: add --end-of-line crlf

# SDKs (from the repo root)
node sdk/js/build.mjs                     # regenerate anotoki-auth.js after editing the .mjs
php sdk/php/test.php                      # the PHP SDK's cases: the token verifier, the sign-in reader (7.4 and up)
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
  connection" is a JSON POST to the same page under the same nonce or token,
  and answers in the family's words - the survey's setup page's, word for word
  (`setupTestDatabase()`, `docs/decisions.md`); change them in every anotoki
  setup page or in none. The defaults are the family's too: the database
  `anotoki_iam`, PostgreSQL on 5432. `setupHandle()` returns an answer instead of printing it, so
  `tests/setup*.test.php` drive it in-process.
- **Defaults are development.** The issuer on a development machine is
  `http://localhost:4300`; migration 002 seeds the sites with the port table's
  addresses (`README.md`), and 005 the survey site - which reaches installed
  IAMs too, so it adds them only while the IAM's own `iam` entry has its home
  on this computer; the installer prefills development values; docs and SDK
  examples use them. Production addresses are typed into the installer on
  the real host or the admin panel - an example like
  `https://genshin.anotoki.eu` is only ever a placeholder.
- **Every first-party site is open to every account, with ADMIN and EDITOR**
  (migration 007, the owner's decisions; `docs/decisions.md`): every
  account may sign in to each (MEMBER, below); each site checks ADMIN / EDITOR from the token
  for its own admin area. The IAM's administrators are GIVEN ADMIN in every
  application that has one at set moments - `createFirstAdmin()` (setup.php,
  create-admin.php), the legacy import, and 005 / 007 for those there when they
  ran. It is a grant, not a rule the IAM keeps: making somebody the IAM's ADMIN
  in the admin panel gives no site's ADMIN, and taking it away takes none.
  007 goes on an installed IAM only after the sites are updated (an older site
  still asks for MEMBER). Who is given a role outside the admin panel is the owner's
  decision, never a refactor's.
- **Every account holds MEMBER in every application** (migration 010,
  2026-10-05; `docs/decisions.md`): two database triggers give a new account
  MEMBER everywhere and a new application MEMBER for every account. A sign-in
  to a site - every way in, every refresh - needs a role there whose
  `may_sign_in` is true (`applicationAccessRefusal()`, `rolesKeepOut()`);
  taking someone's MEMBER blocks them from that site, unticking MEMBER makes
  the site members-only. The IAM's own entry is never asked: Settings ->
  Signing in is its gate. Each application's `login_required` says whether
  the site requires signing in (genshin and the build analyzer: no), the one
  thing the sites read from `GET /api/public/applications/{client_id}`
  (three fields, 404 unless enabled and not system; the SDK fails closed). MEMBER
  can be taken from people, never removed from an application. The old
  `access` column stays, unread, and is read only while 010 is pending
  (`signInRulesReady()`, never memoised); a later migration drops it once
  both IAMs run 010 - it is no way back: once 010 is applied, a build from
  before it never goes on the host again (it would fail open); fix forward.
  010 goes on an installed IAM right after its upload, before any site
  reads the sign-in switch.
- **Public clients only, the same roles everywhere** (migration 011, the
  owner's words of 2026-10-05): every application proves each sign-in with
  PKCE and has no secret (a request carrying one is refused); every site has
  ADMIN, EDITOR and MEMBER, the IAM's entry ADMIN and MEMBER, made with the
  application - the admin panel only ticks which may sign in, never adds,
  removes, renames or rewords a role; there are no landing page settings
  (the public list is every site switched on). The panel shows who is
  blocked where by one rule (`blockedFromSql()`, `sign-in-check.ts`).

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
  validation, roles, who may sign in), `accounts.php`, `keys.php` / `jwt.php` /
  `crypto.php`, `settings.php`, `audit.php`, `translations.php` (languages,
  strings, the bundle, every check of a string), and the carried-over `totp.php`,
  `trusted_device.php`, `one_time_token.php`, `google_identity.php`, `mail.php`
  (whose words now come from the database, `MAIL_FALLBACK` the last resort).
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
  the admin panel's "Apply pending" all use it). Never edit an applied
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
S256 for every client (all are public, no secrets); single-use 60-second codes that revoke on reuse;
pinned RS256 and `typ` checks wherever a token is read; rotating refresh tokens
with family revocation on reuse; hashes only for secrets that open things,
master-key ciphertext for TOTP secrets and signing keys; identical answers for
known and unknown addresses; the limiter on every mail-sending and sign-in
endpoint; the same switches on every way in; no self-lockout, no removing the
last admin; the CSRF header on every first-party write; no loopback address
left on a real host's install, nor seeded into a live IAM by a later migration;
the installer's proof (token, or nonce on this computer only). Changing one is
a decision for `docs/decisions.md`, not a refactor.

## Frontend layout & conventions

- **Every component is three files**: `x.component.ts`, `.html`, `.scss` - no
  inline `template` or `styles`, even when the `.scss` is empty. Styles shared
  by a group of pages are partials `@use`d by each page's own `.scss`
  (`pages/auth/_auth-page.scss`, `pages/admin/_admin-list.scss`).
- `frontend/src/app/core/` - the hand-written API services and models
  (`core/api/`, mirroring `docs/api.md`), the session state, the HTTP
  interceptor (adds `X-IAM-Request: 1` to every write; sends a 401 outside the
  sign-in endpoints to `/login?next=…`; sends a 503 `not_set_up` to
  `/not-set-up`), translations (`i18n/translation.service.ts`: the language
  and its bundle from `GET /api/translations/{code}`; `i18n/en.ts`: the keys
  and the English shown only while no bundle is in memory; the `t` pipe), theme.
- `frontend/src/app/ui/` - the app's own small UI kit; build on it.
- Pages: the sign-in flow (`/login`, `/register`, `/confirm-email`,
  `/forgot-password`, `/reset-password`, `/logout`, `/error`, `/not-set-up`),
  the account page (`/account/**`), the admin panel (`/admin/**`, English
  only).
- **The top bar** (`layouts/top-bar`, in `layouts/shell`) is the anotoki
  family's one bar; the survey site's `layouts/top-bar` is the reference.
  One row: brand, the account pages as links, the language (`app-language-switcher
  [compact]`, not in the admin panel), the theme, the person's menu (the
  admin panel for the IAM's ADMIN, "Back to your account" from it, Sign out
  - no "Your anotoki account": this is it). It folds into a menu button up to
  `PHONE_BAR_MAX` (1040 px, measured in English and Slovak plus a scrollbar:
  re-measure when a link or its words change). Tab / Shift+Tab from any menu
  item: `preventDefault()`, the menu closes, the focus on its button - never
  left to the browser (with no zone the focus would fall to `<body>`). A
  language chosen in the bar is saved to the account, one save at a time and
  the latest choice last. The language button's name holds the code it shows
  ("Language: Slovenčina (SK)", WCAG 2.5.3). The admin panel keeps
  its side navigation under the bar. The sign-in pages keep their own frame
  (`layouts/auth-layout`).
- Zoneless and signals-first; OnPush everywhere; no UI library. Colours only
  from the CSS custom properties in `styles.scss`, defined for light and dark.
  Every page works at 390 px (360 px for the top bar).
- The auth and account pages' words, and the three mails', are rows in the
  database (`languages`, `translation_keys`, `translations`, migration 006),
  reworded and added to on the admin panel's Translations and Languages
  pages; English and Slovak (informal, "ty") are seeded. An unknown key is a
  compile error (`TranslationKey` comes from `en.ts`); a key with no string
  shows as the key. A new string is its key and English in `en.ts` plus a
  migration with English and Slovak - there is no `sk.ts` (skill
  iam-migration).

## Deployment

One web root on the IAM's subdomain: the Angular build (`npm run build:deploy`,
which writes the `.htaccess` routing `/api/` and `/.well-known/` to PHP) plus
`backend/dist/`. First install through `backend/setup.php` (uploaded by hand,
token proof, writes every `config/*.local.php`, takes the production addresses,
imports the genshin accounts or makes the first admin, locks and self-deletes);
later schema changes through the admin panel's Migrations page.
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
