# anotoki IAM

One account for every anotoki site. The IAM is where people sign up, sign in
and manage their account; the genshin site, Japanese Academy, Piano Academy,
the build analyzer and the survey site send them here and get them back
signed in. It is a small OpenID Connect provider - the protocol Keycloak
speaks - built for the owner's FTP-only PHP + PostgreSQL hosting, where it
runs as its own subdomain.

- **Sign-in**: password, a six-digit code by email, or Google; two-factor with
  an authenticator app and recovery codes; remembered devices; "keep me signed
  in"; forgotten passwords; confirmed addresses; a mail when the account signs
  in from a new browser.
- **Single sign-on**: signed in once, every other site bounces through without
  a form. Signing out ends it everywhere.
- **Account page**: username and language, password, Google, two-factor,
  sessions, the sites you can use, tokens for scripts - and deleting the
  account: closed at once, deleted thirty days later unless cancelled, the
  sites keeping what was made there under no name.
- **Admin panel**: accounts, per-site roles, sessions, the mail log, the sites
  themselves (redirect URIs, origins, roles that may sign in), switches - new
  accounts can be made to wait for an administrator's approval -,
  announcements (news mailed to everybody, the holders of some roles or some
  people, from a template, in every language), signing keys, migrations (the
  anotoki family's Migrations page), the family's admin tools - an audit log
  of every change, an error log of the server's failures and the pages' own,
  and backups of the database, encrypted under the master key and never
  restored in place - and the words: every sentence of the sign-in
  and account pages, of the IAM's own mails and of the announcements' templates is
  a string in the database, reworded there, and a new language is added
  there, with no build. English and Slovak come with it.
- **Installer**: `backend/setup.php` writes the configuration, builds the
  database and makes the first administrator, who holds ADMIN in the IAM and
  in every site - on a development machine as on a server. Until it has run, the IAM says it is not set up and does nothing
  else - and points at the installer only while it was never installed: a
  real host's install locks itself, deletes itself, and is never offered
  again.

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

## Hosting checklist

What the host and Cloudflare need for this site - check it before the first upload and after any change of
hosting:

- **PHP 8.2 or newer** with `pdo_pgsql`, `openssl`, `mbstring`, `json` and `zlib` (the backups); PHP's `mail()` switched on (the mails); `allow_url_fopen` on for sign-in with Google; `curl` recommended. `tools/host-check.php` checks all of it on the host: write a long
  random value into its `HOST_CHECK_TOKEN`, upload it beside `index.html`, open
  `https://<the site>/host-check.php?token=<that value>`, and delete it afterwards (the page has a button for
  it). It is never part of a build.
- **Cloudflare** (the zone's settings, the same for every anotoki site):
  - SSL/TLS **Full (strict)**: the connection from Cloudflare to the host is https - the site's secure cookies
    need it end to end (the host check says which it is).
  - Network -> **Pseudo IPv4 not "Overwrite headers"**: that setting would put a made-up IPv4 address into
    `CF-Connecting-IP` for IPv6 visitors.
  - The site believes the visitor's address in `CF-Connecting-IP` only on a connection from Cloudflare's
    published ranges (the anotoki library's list), and counts every limit by it.
  - Rules -> Transform Rules -> Modify Request Header: **Set static** `X-Anotoki-Origin` to a long random value
    (32 characters or more), for every request of the zone - and the same value in `config/cloudflare.local.php`
    (`<?php return ['origin_secret' => '...'];`, uploaded by hand, never committed). With both, `CF-Connecting-IP`
    is believed only on a request that carries the value, so nobody else's Cloudflare Worker can write itself a new
    address for every guess; without the file the site believes it from Cloudflare's ranges alone. Set the two
    together: a file the zone does not match - or one that cannot be read, a byte-order mark before its `<?php` -
    counts every visitor as their Cloudflare edge (`docs/operations.md`, "Behind Cloudflare").
- **After an upload**: the private folders (`storage/`, `config/`) answer 403 in a browser; the host check's
  "Who connects" says Cloudflare, not a proxy of the host's (behind one, every visitor would share one
  address); the admin panel's Sessions show visitors' own addresses, not Cloudflare's (else the origin secret's
  two halves differ); pending database updates are applied on the admin panel's Migrations page.
- **Keep with the backups**: `config/secret.local.php` (the master key, off the server) and, each time a backup is
  downloaded, `storage/records/account-deletions.jsonl` over SFTP - the record a restore by hand replays
  (`docs/operations.md`, "When the database is lost").

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
- Migrations 001-019, applied by the engine every anotoki site shares - the
  family's library, anotoki-lib (`anotoki/lib` in `backend/vendor/`,
  `@anotoki/lib` in the frontend); an installed IAM gets the later ones through
  the admin panel's Migrations page (or setup.php / `php migration.php` on
  this computer). Unlike the other sites, the IAM keeps working while an
  update waits - every site signs in through it - and tells only its
  administrators, in one line above the admin panel's pages. 008 is the top
  bar's words: three strings added, one gone; 009
  calls the admin area the admin panel ("Admin console" was 006's word),
  as every anotoki site does.
- New accounts can be made to wait for an administrator's approval
  (migration 012, the owner's decision of 2026-10-06): one switch for the
  whole IAM, Settings → New accounts need approval, off by default. While it
  is on, an account made by signing up signs in nowhere until it is approved
  on the admin panel's Approvals page, which mails the person.
- Announcements (migration 013, the owner's decision of 2026-10-06): news an
  administrator writes from a template - a new version of a site, a new site,
  a survey - a version per language, and sends to everybody, roles or people,
  from noreply, support or an added address at the same domain. Links to the
  anotoki sites only; every one ends with a link to stop the news, and the
  account page has the same switch. Sent in batches from its page, and kept:
  what, to whom, when, and how each mail went. Signing up offers the news in
  a box left unticked (migration 014).
- The account pages and the admin panel wear the anotoki family's one top
  bar (the survey site's is the reference): the account pages as its links,
  the language, the theme, the person's menu; a menu button on a phone, up to
  1040 px. The admin panel keeps its side navigation under it.
- Five wrong authenticator codes pause an account's codes for fifteen minutes,
  whatever addresses they come from (migration 019, the owner's decision of
  2026-10-13): the sign-in page then offers a code by email after the
  password (five wrong of those, and none until the pause ends), a recovery
  code still works, and the account's owner is mailed.
- Every deletion of an account asked for, cancelled and done is also a line
  of an add-only record beside the database; after a backup put back by hand,
  `php account-deletions.php --replay` does them again. The admin panel never
  restores.
- The pages and mails in English and Slovak, reworded in the admin panel.
- "Test connection" on the installer answers in the family's words, the
  survey's setup page's.
