# anotoki IAM

One account for every anotoki site. The IAM is where people sign up, sign in
and manage their account; the genshin site, Japanese Academy, Piano Academy,
the build analyzer and the survey site send them here and get them back
signed in. It is a small OpenID Connect provider - the protocol Keycloak
speaks - built for the owner's FTP-only PHP + PostgreSQL hosting, where it
runs as its own subdomain.

- **Sign-in**: password, a six-digit code by email, or Google; two-factor with
  an authenticator app and recovery codes; remembered devices; "keep me signed
  in"; forgotten passwords; confirmed addresses.
- **Single sign-on**: signed in once, every other site bounces through without
  a form. Signing out ends it everywhere.
- **Account page**: username and language, password, Google, two-factor,
  sessions, the sites you can use, tokens for scripts.
- **Admin panel**: accounts, per-site roles, sessions, the mail log, the sites
  themselves (redirect URIs, origins, roles that may sign in), switches, signing keys,
  migrations, an audit log of every change - and the words: every sentence of
  the sign-in and account pages and of the three mails is a string in the
  database, reworded there, and a new language is added there, with no build.
  English and Slovak come with it.
- **Installer**: `backend/setup.php` writes the configuration, builds the
  database and makes the first administrator, who holds ADMIN in the IAM and
  in every site - on a development machine as on a server. Until it has run, the IAM says it is not set up and does nothing
  else.

| Part | Folder | Stack |
| --- | --- | --- |
| Backend | `backend/` | PHP 8.2+, Slim 4, PostgreSQL |
| Frontend | `frontend/` | Angular 21, standalone, zoneless, signals |
| SDKs | `sdk/` | the browser client and the PHP token verifier every site copies |
| Docs | `docs/` | architecture, API, integration, operations, decisions |

On a development machine it runs at `http://localhost:4300` (the pages, with
the API behind them on `:8100`), and every anotoki site's own dev server has a
fixed port it is registered with:

| Application | client_id | Frontend | Backend |
| --- | --- | --- | --- |
| IAM | `iam` | `http://localhost:4300` | `:8100` |
| Genshin Impact | `genshin` | `http://localhost:4200` | `:8000` |
| Japanese Academy | `japanese-academy` | `http://localhost:4400` | `:8200` |
| Piano Academy | `piano-academy` | `http://localhost:4500` | `:8300` |
| Build Analyzer | `build-analyzer` | `http://localhost:4600` | `:8400` |
| Surveys | `survey` | `http://localhost:4700` | `:8500` |

- Getting it running: [DEV.md](DEV.md).
- Each half's layout, entry points and commands: [backend/README.md](backend/README.md)
  and [frontend/README.md](frontend/README.md).
- How it works: [docs/architecture.md](docs/architecture.md), then
  [docs/api.md](docs/api.md) for the endpoints.
- Making a site sign in through it: [docs/integrating-an-app.md](docs/integrating-an-app.md)
  and [sdk/README.md](sdk/README.md).
- Putting it on the hosting: [docs/operations.md](docs/operations.md).
- Why it is built the way it is: [docs/decisions.md](docs/decisions.md).

## Current state

- Every anotoki site signs in through it, and every first-party site is open
  to every account, with the roles ADMIN and EDITOR (migration 007, the
  owner's decisions of 2026-10-01) - and MEMBER, which every account holds in
  every application since migration 010 (2026-10-05): a sign-in needs a role
  that may sign in, so taking somebody's MEMBER blocks them from one site, and
  each site says whether it requires signing in (genshin and the build
  analyzer let visitors in; the sites' backends read it at
  `/api/public/applications/{client_id}`). The admin panel shows both on an
  application's page, and who is blocked where. Since migration 011 every
  application is a public client (PKCE, no secret) with the same fixed roles -
  ADMIN, EDITOR, MEMBER - and no landing page settings. ADMIN in every site was given to
  the installer's administrator, `create-admin.php`'s, one brought over from
  the genshin site, and every administrator there when 007 ran - a grant at
  those moments: promoting or removing an administrator later changes no
  site's roles. 007 goes on an installed IAM after the sites are updated.
- Migrations 001-009; an installed IAM gets 007, 008 and 009 through the
  admin panel's Migrations page (or setup.php / `php migration.php` on this
  computer). 008 is the top bar's words: three strings added, one gone; 009
  calls the admin area the admin panel ("Admin console" was 006's word),
  as every anotoki site does.
- The account pages and the admin panel wear the anotoki family's one top
  bar (the survey site's is the reference): the account pages as its links,
  the language, the theme, the person's menu; a menu button on a phone, up to
  1040 px. The admin panel keeps its side navigation under it.
- The pages and mails in English and Slovak, reworded in the admin panel.
- "Test connection" on the installer answers in the family's words, the
  survey's setup page's.
