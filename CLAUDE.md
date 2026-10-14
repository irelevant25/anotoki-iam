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
php translations.php --status             # the pages' keys against the database's; each language's coverage; the mails' keys against MAIL_KEYS
php translations.php --export sk [file]   # a language's own strings as flat JSON; --import sk file puts them back (both refuse while a migration waits)
php backup.php --list                     # the database's backups (encrypted; --create, --inspect=<id>, --extract=<id> <folder>; --from= --key= for a downloaded one)
php account-deletions.php --list          # the record of deletions asked, cancelled, done (storage/records); --replay --since=<the backup's time> [--dry-run] after a restore by hand
php build-dist.php                        # -> backend/dist/ for upload
composer update anotoki/lib --prefer-dist # the family's library, with frontend/package.json's @anotoki/lib URL (docs/operations.md)

# Frontend (from frontend/)
npm start                                 # bun ci (the packages in bun.lock), then ng serve on :4300, proxying /api, /.well-known and /setup.php to :8100
bun add <package>                         # add or update packages with bun: npm start's bun ci stops when bun.lock is behind package.json
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
  `users` database for the import), the master key, `public_url`, mail, Google,
  the Cloudflare zone's origin secret (`cloudflare.local.php`, optional,
  uploaded by hand: the installer neither asks for it nor writes it)
  - comes **only** from `backend/config/*.local.php` (gitignored), read through
  `config/config.php` (`localConfig()`). The committed `config/*.php` hold the
  loaders and the tunables that are the same everywhere; there is no template
  to fall back on. A file is read cleanly or not at all
  (`readLocalConfigFile()`, the round-3 lesson of 2026-10-13): what it prints
  when PHP requires it - no `<?php` line, a byte-order mark or a line before
  it - is caught, never sent, and the file is passed over (said by its fault
  and byte count, never its text; a required one leaves the IAM not set up,
  `iamSetUpProblems()` naming it; `cloudflare.local.php` so fails closed, a
  secret no request carries); so is one PHP cannot read. The installer
  reads through it too, and saves over no file it could not read (a master
  key with a BOM is still the key).
- They are **required**: the anotoki family's gate (anotoki/lib's `SiteGate`
  over `iamSiteState()`, `api/migration_runner.php`; asked only while the IAM
  is not set up, so a set-up IAM does no database work for it) answers every
  request but `/api/site-status` with `503 {code: "not_set_up", installed, message,
  setup?}` until `iamSetUpProblems()` is empty - `setup: "/setup.php"` only
  while `storage/installed.lock` is not there (the owner's rule: an IAM
  installed once never offers the installer again) - and the frontend turns
  that into `/not-set-up` (the family's `<anotoki-site-status>`, in the IAM's
  `notSetUp.*` words). The CLI tools stop with `requireSetUpOnCli()`.
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
- **New accounts may wait for approval** (migration 012, the owner's decision
  of 2026-10-06): Settings -> New accounts need approval, one switch for the
  whole IAM - never per site: accounts are shared by every site. While on, an
  account made by `/api/register` or a Google sign-up has `users.approved`
  false and every way in refuses it `approval_pending` (`signInRefusal()`,
  `applicationAccessRefusal()`, `currentSignIn()`) until an IAM ADMIN approves
  it (Approvals page; `POST /api/admin/users/{id}/approve|decline` - declining
  switches it off, deleting nothing - never while the address is unconfirmed,
  or a stranger's registration would keep the address's owner out). Only
  those two paths write false (`insertSignedUpAccount()`, which reads the
  switch under its row's lock); every other account is approved by the
  column's default. Switching the setting off approves everybody waiting and
  switched on. Once 012 is applied, no build from before it goes on the host
  again: it would let every waiting account in.
- **The theme, the dates and the clock are the account's** (migration 015,
  the owner's decision of 2026-10-06; `docs/decisions.md`), beside its
  language: `users.theme` / `date_format` / `time_format`, the `preferences`
  claim beside `locale` (profile scope), changed on the account page or by a
  site with the person's access token (`POST /api/oidc/preferences`, SDK
  `savePreferences()`). That endpoint is the fourth CSRF-exempt,
  CORS-for-sites path, beside token, userinfo and revoke - none of which may
  ever read the session cookie. Signed in, every site and the IAM's own pages
  follow the account (one theme everywhere, admin areas included); signed
  out, each device keeps its own.
- **Announcements** (migration 013, the owner's decision of 2026-10-06;
  `api/announcements.php`): news an administrator writes from a template (the
  `mail.newVersion|newRelease|newSurvey.*` strings) and sends - never by
  itself - to everybody, roles or people, a version per language (English
  needed; an empty one reads English). Only accounts switched on, confirmed,
  approved and with `news_mail` on get it, asked again as each mail goes;
  signing up offers it in a box left unticked (014: `/api/register` and a
  Google sign-up from the register page take `news_mail`, a yes is
  audited, claiming an unconfirmed account takes the registrant's yes away).
  Links and addresses in it to the anotoki sites only (the IAM's host, the
  applications' registered hosts, the mail domain and under it - read as a
  mail program reads them: any kind of space ends one, every `://` and `www.`
  judged on its own; the button's link printable ASCII; the site's name
  checked too); senders are config/mail.local.php's or added at the mail
  domain. The words are escaped into one fixed layout. Sent by the
  announcement's page in batches (`FOR UPDATE SKIP LOCKED`, one transaction a
  mail, at least once; `hourly_limit` paces it). Every one ends with
  `/unsubscribe?token=` (and `List-Unsubscribe`): a MAC under a key derived
  from the master key (HKDF - its name is in every link mailed, so it never
  changes), POSTed with the CSRF header after the page asks - no RFC 8058
  one-click, which would need a CSRF exemption.
- **An account is deleted thirty days after its owner asks** (migration 016,
  the owner's decisions of 2026-10-07; `api/account_deletion.php`):
  `POST /api/account/deletion` (the password, or without one a sign-in of the
  last 15 minutes) closes it at once - other sessions and every token end,
  every way to a site answers `account_deletion_pending`, it is no longer an
  administrator - while the IAM's own sign-in keeps working so its owner can
  cancel (`DELETE /api/account/deletion`); both are mailed. A first password,
  Google connected and password sign-in switched on or off ask for the same
  proof (`accountProof()`), so a session alone cannot make one. When due,
  housekeeping (ordinary traffic; no cron) mails "deleted", then anonymizes:
  everything of the person deleted or scrubbed, the row kept as a placeholder
  nothing revives (`409 account_anonymized`), its id never anybody else's. The
  sites keep their data, anonymous: they read the ids at
  `GET /api/public/anonymized-accounts` and scrub their own copies of the name
  and address. A backup is never restored to bring an account back.
- **A sign-in from a new browser is mailed** (016, `api/sign_in_devices.php`):
  the IAM's sign-in itself, never a site's single sign-on or a refresh. The
  browser is known by the `anotoki_iam_device` cookie (only a hash kept; it
  opens nothing); an account's first browser is noted in silence, so release
  day mails nobody; at most five an hour; a reset forgets the browsers.
- **Five wrong authenticator codes pause an account's codes for fifteen
  minutes** (migration 019, the owner's decision of 2026-10-13;
  `api/authenticator_pause.php`): counted for the account, whatever address
  they come from, within a rolling fifteen minutes, from nought again after a
  right code or a completed sign-in; on every way that takes a code - the
  password sign-in, the emailed code's, Google, a reset, the account page's
  turning two-factor off and new recovery codes (`authenticatorCheck()`, one
  check at a time per account: the row held). During the pause a code is
  refused unchecked (`403 authenticator_paused` with `paused_until`; refused
  ones count towards no next pause); a recovery code and a remembered browser
  still work; the password sign-in may finish with a code mailed to the
  address in the authenticator's place (`email_code`, after the password -
  never without it: `pausedEmailCodeCheck()`), counted as the authenticator's
  codes are - under the same hold on the row, five wrong in a pause
  (`bad_email_code`) and every one is refused unchecked until it ends
  (`email_code: false`, codes mailed since included; the security review of
  2026-10-13) -, which remembers no browser and is a session of its own
  method, `password_email`, its `amr` `["pwd", "email", "mfa"]` (never `otp`:
  the mailbox stood in). The trade, as the owner chose it when asked plainly:
  the password holder can start a pause at will and its mail lands in the same
  mailbox, so the password and the mailbox together sign in. The owner is
  mailed once a pause (`mail.authenticatorPaused.*`), and the audit log keeps
  `account.authenticator_paused`. Switching two-factor on with a new secret
  clears it.
- **A sign-in sets a hint every anotoki site reads** (the owner's decision
  of 2026-10-07; `docs/decisions.md`, `api/session.php`):
  `anotoki_signed_in`, a random value per session that opens nothing and
  says nothing of the person - readable by script, SameSite=Lax, Secure
  with the session cookie. Its `Domain` is the issuer's parent on a real
  host (`hint_cookie_domain` in iam.local.php names another the issuer is
  under), and only while an application switched on signs in under it;
  else host-only - and host-only `localhost` in development. The domain
  must be wholly the owner's (`hint_cookie_name` null switches the hint
  off). Set by `completeSignIn()` and again for a session still live
  without one (single sign-on, `requireSession()`), anew for a deletion
  cancelled or a forced password change done; never for an account no
  site lets in (`signInHintAllowed()`: a raced hint cleared where seen).
  Cleared by both sign-outs, ending this browser's own session, a deletion
  asked for, every no-session answer (the 401s, accept, authorize's
  `login_required`); kept on `interaction_required`. A setting passed over
  is logged at most once a day (a stamp in storage/cache). Nothing reads it
  as proof; no CSRF or CORS exemption comes with it. A site's silent hop
  and its loop protection are the family's library's, not the IAM's.
- **The session cookie is `__Host-anotoki_iam` on https** (a hardening of
  2026-10-07, `docs/decisions.md`): Secure, Path=/, never a Domain; plain
  `anotoki_iam` on http development. It is read by its exact name from the
  raw Cookie header (`cookieValuesNamed()`), never from the cookie params -
  Slim's parser URL-decodes names, so a look-alike like `__%48ost-anotoki_iam`
  would stand in for it - and either name sent twice is no session. On https
  the bare `anotoki_iam` is never read (the owner's decision of 2026-10-10,
  "option 2"), beside a `__Host-` one or without it: even with a live
  session's secret it opens nothing - no page, no code, no write, no
  sign-out - so one a sibling host plants, beside a planted hint or not,
  signs nobody in. No transition and nothing for old browsers (2026-10-11:
  the sites are not public yet; the owner's own browser signs in once
  more). A bare one that arrives is expired by the answer that uses the
  session (`withoutOldSessionCookie()`: `requireSession()`, authorize,
  `completeSignIn()`); every sign-out and dropping refusal expires both
  names. Read, set and expired in `api/session.php` only. The device cookie
  keeps its name.
- **The words are the family's library's code** (anotoki-lib 0.3.0, the
  owner's decisions of 2026-10-11; `docs/decisions.md`): the strings, the
  languages, the bundle, the admin routes, their checks and the command line
  are `Anotoki\Lib\Translations` (and `@anotoki/lib/translations` in the
  pages), on the IAM's own tables; what is the IAM's is its configuration,
  `api/translations.php` (`iamTranslations()`). The contract stayed: the same
  paths, shapes and codes (plus `strings` on a language and in a delete's
  answer, and the body's 415 / 400 `invalid_json` / 422 `empty_body`). The
  mails stay the IAM's (`MAIL_KEYS`, `MAIL_FALLBACK`, `mailWords()`,
  `mailFill()`, `mailMessage()`); their rules are `IamMailRules`, which the
  library's checks ask. `anotoki.` is the library's namespace: its own words
  (257 keys since 0.6.0: the kit's, the status page's, the legal pages' and
  more) come from its migration set
  `anotoki_translations`, which runs after the IAM's set everywhere
  (`iamMigrator()`), is recorded in `migrations` as `set/name`, and writes a
  key's strings only where the key has none. Migration 017 moved `lang.label` /
  `lang.button` there and renamed `sessions.*.other` to `.unknown`; once it is
  applied, no build from before it goes on the host again (it would show
  those four as keys). Both route groups stay behind `requireAdmin()`; the
  CSRF header and `audit()` as before.
- **The visitors' own addresses, through Cloudflare's ranges** (the owner's
  decision of 2026-10-11; `docs/decisions.md`): `config/iam.php`'s committed
  `trusted_proxies` are Cloudflare's published ranges (the library's
  `ClientAddress::CLOUDFLARE`; an installation's `iam.local.php` may name its
  own, or `[]`), and `requestIp()` is the family's one rule (the library's
  `ClientAddress`): `CF-Connecting-IP` believed only on a connection from
  them - never a header from anywhere else -, an IPv4 address written as IPv6
  the IPv4 one. That header is pinned in the code, never a setting
  (Cloudflare passes `X-Real-IP`, `True-Client-IP`, `X-Forwarded-For` on as
  the visitor wrote them), and a configured range wider than an IPv4 /8 or an
  IPv6 /16 (`0.0.0.0/0`, `::/0`) is refused (`iamTrustedProxies()`). The
  limiter counts the visitor, the audit log keeps their address, a session
  shows it; the error log keeps only a keyed hash of the address's bucket (an
  IPv6 address its /64: the IAM's `clientKey`) - a pseudonym while its key
  lasts, never "an address nobody can read back". Cloudflare's ranges are
  every Cloudflare customer's, so with the origin secret (the owner's decision
  of 2026-10-13, anotoki-lib 0.5.2: `config/cloudflare.local.php`
  `['origin_secret' => ...]`, `iamOriginSecret()`, trimmed; the same value in a
  Transform Rule of the zone that sets `X-Anotoki-Origin` on every request)
  `CF-Connecting-IP` is believed only on a request that carries it - any other
  from the ranges is the edge it came from, coarser, never looser; passed to
  `requestIp()` (and so the limiter and the audit log) and `iamAdminContext()`;
  without the file, as before; with one that cannot be read cleanly, a secret
  no request carries (fails closed: every request from the ranges its edge).
  Never printed or logged.
  `tests/addresses.test.php` holds the attacks it stops (a forged header from
  outside the ranges, the other headers from inside them, a catch-all range, a
  Worker writing a new address into every guess without the secret).
- **The admin panel's tools are the family's library's** (anotoki-lib 0.4.1,
  then 0.5.0 - the owner's decisions of 2026-10-11; `docs/decisions.md`): the
  error log, the audit log and backups - one page each, the library's routes
  inside the admin group (the session, the IAM's ADMIN, the CSRF header; each
  group asks the library's own ADMIN check besides, `iamAdminContext()`); not
  feedback, not the file explorer. The IAM's configuration is
  `api/admin_tools.php` and `api/audit.php`. **The audit log** is the library's `anotoki_audit_log`:
  `audit()` stays every route's one way in and keeps what it kept (who, what,
  to what, the details, `requestIp()`, the time), behind a savepoint, never
  throwing; migration 018 moved every entry of `audit_log` there with its id
  and kept the old table, unread but scrubbed, as `audit_log_before_018` until
  a later migration drops it once both IAMs run 018. Until 018 has run an
  entry waits in `storage/logs/audit-pending.jsonl` (`auditMovedToLibrary()`),
  flushed after the Apply, by `php migration.php` and by housekeeping (before
  its deletions) through `flushWaitingAuditEntries()`, which scrubs the
  anonymized accounts' people out of them at once - whenever a flush found
  entries waiting, not only when it put some in (one killed after its commit
  puts nothing in twice next time) - once 018 is applied, no
  build from before it goes on the host again. An account's deletion scrubs it
  by the IAM's rules (the library's `scrubPeople()` plus the personal-token
  rule). A change of the strings keeps each changed mail string's old and new
  text whole in an entry of its own, `translations.mail_changed`
  (`auditTranslations()`): one entry holds at most 64 KB. **The error log**
  is files in `storage/logs`, with no secret, token, cookie, header, body,
  query string or address: the IAM's names of secrets are the library's
  redactor's to take out (`IAM_SECRET_NAMES`, `Redactor::addSecretNames()`,
  0.5.0), and what no name says - its `iam_rt_` / `iam_pat_` prefixes and its
  cookies - the IAM takes out first (`iamRedactSecrets()`) on every way in:
  its error handler and `iamRecordError()` for every failure it writes itself,
  each down the exception's whole chain (`iamRedactException()` walks
  `getPrevious()`: a line keeps every cause's message); a middleware just
  inside the log's outer half (an exception nothing answered), its own PHP
  handlers (`iamInstallPhpHandlers()`), and the pages' own errors
  (`POST /api/client-errors`, the library's `ErrorLogRoutes::client()` in
  `routes/login.php`: `iamClientErrorRedaction()`, and `optionalSession()`,
  which only reads who is signed in - public and first-party, under the CSRF
  header, in neither CORS list; 20 an hour a sender, 500 a day from the
  visitors and as many from the people signed in; `@anotoki/lib/error-report`
  in the pages). Its client hashes are keyed by the master key. **Backups**
  (`storage/backups`) are ENCRYPTED under a key derived from the master key,
  made / downloaded (a single-use ticket: one backup, one administrator, a
  minute) / deleted only with `accountProof()` (the password, or a sign-in of
  the last 15 minutes) and audited, daily (after the answer, the library's
  `AfterResponse`), before every Apply (`iamMigratorWithBackup()`: no backup,
  nothing applied) and by hand, kept until the owner deletes them (no maximum
  age - the owner's decision of 2026-10-11: a deleted person's data stays in
  the backups made before, encrypted, until then) - and NEVER restored here (`restore: false`;
  the owner's decision of 2026-10-13 kept it off): a database lost is put back
  by hand, offline (`php backup.php --extract`, psql), and then
  `php account-deletions.php --replay --since=<the backup's time>` does again
  what the add-only record beside the database holds -
  `storage/records/account-deletions.jsonl`, a line for every deletion asked
  for, cancelled and done, ids and times, written once each commits; replayed
  in the order the events happened, by their own times, not the file's - and
  ends every session, token, remembered browser and mailed link, and warns
  when it leaves no live administrator (docs/operations.md, "When the
  database is lost"). The Backups page names who made
  each (the context's `names`). `php backup.php` reads one offline with the
  master key (`--inspect` the manifest alone, in memory). Backups are built
  only once the IAM is set up (their key is the master key's).

## Backend layout & conventions

- `api/index.php` builds the Slim app (`buildIamApp()`, built once; tests call
  it with `IAM_NO_RUN` defined) and fixes the middleware order: the error log
  (the library's, outermost) → the IAM's names of secrets out of an exception
  nothing answered (`iamRedactingMiddleware()`) → security headers → not set
  up → CORS → error
  handler (the IAM's, wrapped by the error log) → CSRF → body parsing →
  routes; after `$app->run()`, the library's `AfterResponse` (the day's backup).
- Routes by audience: `routes/oidc.php` (applications: discovery, JWKS,
  authorize, token, userinfo, preferences, logout, revoke), `routes/login.php` (the sign-in
  pages), `routes/account.php` (`requireSession()`), `routes/admin.php`
  (`requireSession()` + `requireAdmin()` = ADMIN of the application `iam`).
- Logic in plain function files beside them: `oidc.php` (requests, codes,
  refresh tokens, minting), `session.php` (cookie, sliding expiry, revocation,
  the limiter), `applications.php` (clients, redirect/origin checks and
  validation, roles, who may sign in), `accounts.php`, `keys.php` / `jwt.php` /
  `crypto.php`, `settings.php`, `audit.php`, `translations.php` (the IAM's
  configuration of the library's translations: `iamTranslations()`, the mails'
  rules `IamMailRules`, the accounts per language, refusals in the IAM's body,
  `iamKeyScanner()` for the CLI and the tests), and the carried-over `totp.php`,
  `trusted_device.php`, `one_time_token.php`, `google_identity.php`, `mail.php`
  (whose words now come from the database, `MAIL_FALLBACK` the last resort),
  `account_deletion.php` and `sign_in_devices.php` (016; the deletion record
  and its replay, `recordAccountDeletionEvent()` / `replayAccountDeletions()`,
  are `account_deletion.php`'s), `authenticator_pause.php` (019: the one
  authenticator check every way in calls, `authenticatorCheck()`),
  `migration_runner.php` (the family's library wired in: `iamMigrator()` - the
  library's admin set, the IAM's set, then the library's translations set -,
  `iamMigrationLabel()`, `iamSiteState()`; beside them `ensureDatabase()`,
  `createFirstAdmin()`), and `admin_tools.php` (the library's admin tools on
  the IAM: `iamAdminContext()`, `iamErrorLog()` with `IAM_SECRET_NAMES`,
  `iamRedactSecrets()` / `iamRedactException()` and `iamClientErrorRedaction()`,
  `iamBackups()`, `iamMigratorWithBackup()`; `audit.php` is `audit()` over the
  library's `AuditLog`, `iamAuditLog()`).
  `helpers.php` holds `requestIp()` (the library's `ClientAddress` over
  `config/iam.php`'s trusted proxies and `config/cloudflare.php`'s origin
  secret), `languageCodes()` / `languageOrDefault()`
  (read through the library's `offeredCodes()`, `en`/`sk` before the tables
  exist) and the text and refusal helpers the announcements use.
- Files under `storage/` go through `iamStorageDir()`, config through
  `localConfig()` - the tests point both at temp folders (`IAM_CONFIG_DIR`,
  `IAM_STORAGE_DIR`, constants only code can define). No environment variables.
- Plain PDO with `?` parameters everywhere; no query builder. Booleans bound
  through `pgBool()`, read through `asBool()`. Every connection runs in UTC and
  every timestamp column is TIMESTAMPTZ; answers carry ISO-8601 via `isoTime()`.
- Errors are `respondError($response, $status, 'code', 'sentence')`; the OIDC
  endpoints answer OAuth's `{error, error_description}` via `oauthError()`.
- Every admin change and security-relevant self-service change writes
  `audit()` - into the library's `anotoki_audit_log` since 018 (never an
  INSERT of its own: the command line and the import go through
  `audit()` / `iamAuditLog()` too).
- **Migrations are the schema**: `backend/migrations/iam/NNN_name.sql`, applied
  in order by the anotoki family's engine, anotoki/lib's `Migrator`
  (`iamMigrator()` in `api/migration_runner.php` - CLI, installer and the
  admin panel's Migrations page all use it, one apply at a time; the table
  `migrations` stays as it is), the library's admin set before them
  (`anotoki_admin`: the audit log's and feedback's tables) and its
  translations set after them (`anotoki_translations`), both in
  `vendor/anotoki/lib/php/migrations` and recorded as `set/NNN_....sql`. The
  Migrations page's Apply and `php migration.php` back the database up first
  (`iamMigratorWithBackup()`); the installer does not. Never edit an applied migration. A migration
  that changes data carries a `DO $$ … $$` gate that fails loudly. **An update
  waiting closes nothing** (`blockWhilePending: false`, the one anotoki site
  that does not block - every site signs in through it): each feature checks
  its own tables (`*Ready()`), and only the IAM's administrators are told, by
  `<anotoki-update-banner>` in the admin panel (`docs/decisions.md`).
- **The family's library** (`irelevant25/anotoki-lib`): `anotoki/lib` in
  `backend/vendor/anotoki/lib` (Composer, from GitHub) and `@anotoki/lib` in
  `frontend/package.json` (the release's tarball), one version for both
  halves, updated together where both change (`docs/operations.md`): 0.6.0,
  both halves (the migrations module, the translations module, the UI kit,
  the admin tools, the pages' error reporter, the legal pages; never 0.4.0,
  which fails on PHP 8.2). Never
  edit the vendored copy: a change goes to the library and comes back as a
  release. Its routes here: `GET /api/site-status` (first-party, public, no
  CORS), `/api/admin/migrations[/file|/apply]` inside the admin group, the
  bundle `GET /api/translations/{code}` (public, no cookie, no CORS), the
  pages' own errors `POST /api/client-errors` (first-party, public, the CSRF
  header, no CORS), the eight `/api/admin/languages|translations` routes, and
  the admin tools' `/api/admin/audit[/facets]`,
  `/api/admin/errors[/{fingerprint}]` and `/api/admin/backups/**` (the
  download `/{id}/file?ticket=` among them) - all inside the admin group.
- **Tests** (`backend/tests/*.test.php`, `lib.php`, `setup_lib.php`) drive the
  real app and the installer in-process with a `Browser` that keeps cookies,
  each file with its own temp configuration and `iam_test_*` databases, borrowing
  only the PostgreSQL server from `config/database.local.php`. Any change to the
  endpoints or the installer needs a test; a change to a security rule needs a
  test of the attack it stops. They must pass on PHP 8.5 (the owner's rule of
  2026-10-12); the hosts run 8.2 and up, so the code uses nothing 8.2 lacks.

## The rules that must not loosen

Each is load-bearing (see `docs/architecture.md` → "The rules that carry the
weight"): exact redirect-URI match and no redirect to an unverified one; PKCE
S256 for every client (all are public, no secrets); single-use 60-second codes that revoke on reuse;
pinned RS256 and `typ` checks wherever a token is read; rotating refresh tokens
with family revocation on reuse; hashes only for secrets that open things,
master-key ciphertext for TOTP secrets and signing keys; identical answers for
known and unknown addresses; the limiter on every mail-sending and sign-in
endpoint, counting the visitor - `CF-Connecting-IP` alone, pinned in the
code, believed only on a connection from the trusted proxies (Cloudflare's
ranges; none wider than an IPv4 /8 or an IPv6 /16) and, with the zone's
origin secret, only on a request that carries it in `X-Anotoki-Origin`;
five wrong authenticator codes pause an account's codes for fifteen minutes
on every way that takes one, a code by email standing in only after the
password and only until five of those are wrong; the same switches on every way in; no self-lockout, no removing the
last admin (one whose deletion is pending counts as none); the CSRF header on every first-party write; the sign-in hint opens nothing and no account no site lets in has one;
the session cookie never gets a Domain, and is __Host- on https, where the bare name is never read; no loopback address
left on a real host's install, nor seeded into a live IAM by a later migration;
backups only encrypted under the master key, made, downloaded and deleted only
with the account page's proof, never restored in place - a restore by hand
replays the deletion record; no secret, token,
cookie or address in the error log, and no local file's text printed;
the installer's proof (token, or nonce on this computer only). Changing one is
a decision for `docs/decisions.md`, not a refactor.

## Frontend layout & conventions

- **Every component is three files**: `x.component.ts`, `.html`, `.scss` - no
  inline `template` or `styles`, even when the `.scss` is empty. Styles shared
  by a group of pages are partials `@use`d by each page's own `.scss`
  (`pages/auth/_auth-page.scss`, `pages/admin/_admin-list.scss`,
  `pages/admin/_admin-field.scss`).
- `frontend/src/app/core/` - the hand-written API services and models
  (`core/api/`, mirroring `docs/api.md`; the translations' shapes are the
  library's own), the session state, the HTTP interceptor (adds
  `X-IAM-Request: 1` to every write; sends a 401 outside the sign-in endpoints
  to `/login?next=…`; sends a 503 `not_set_up` to `/not-set-up`; hands the
  migrations module's own requests their HttpErrorResponse back - the
  library's `siteStatusInterceptor` runs inside it; leaves the bundle's own
  request, `ANOTOKI_TRANSLATION_BUNDLE`, alone: its failure means "read the
  compiled English", never a page to send anybody to; gives a report of the
  pages' own errors, `ANOTOKI_ERROR_REPORT` (`provideAnotokiErrorReport()`
  in `app.config.ts`, beside `provideBrowserGlobalErrorListeners()`), the CSRF
  header like every write - never an exemption - and nothing else: no page
  moved, no session cleared, its HttpErrorResponse back as it came, a 429
  stopping the reporter), the tab's title in the family's form
  (`i18n/title.strategy.ts`: "Security · anotoki account", "Users · Admin
  panel · anotoki account"), the family's
  migrations module (`migrations/migrations.config.ts`:
  `provideAnotokiMigrations`, no gate and no boot check; the status page's
  words `siteStatusWords()` under the IAM's own `notSetUp.*`), the family's
  admin tools (`admin/admin-tools.config.ts`: `provideAnotokiAdmin` - the
  IAM's ADMIN, a person the logs name opened on the Users page, the account's
  dates, Migrations; what only the panel says and does - a refusal in
  `adminErrorText()`'s words, "Sign in again" (`FreshSignInService`: out, and
  back to the page), why restoring is off - handed over by the admin routes
  from `pages/admin/admin-tools.ts`, out of the first load), the family's
  UI kit and shell (`kit/kit.config.ts`: `provideAnotokiUi` - the kit's words
  from the database: the IAM's own keys where it has them, `kit/kit-words.ts`,
  the library's `anotoki.*` otherwise, `forKit()` - and its icons
  `kit/icons.ts`; `provideAnotokiShell`: the theme, the account's while signed
  in, and the languages, `forShell()`), the family's translations
  (`i18n/translations.config.ts`: `provideAnotokiTranslations` with the IAM's
  storage names `anotoki-iam:lang`, `:lang-cache`, `:localization-drafts`,
  `fallbackEnglish: en`, the account - its language decides while signed in,
  a choice is saved with `PUT /api/account/profile` through
  `SessionService.setFrom()` -, the session asked first in the account and
  admin areas, and the admin pages' words handed over by the admin routes;
  `i18n/translation.service.ts`: the library's `TranslationService` seen with
  the IAM's keys typed; `i18n/en.ts`: the keys and the English shown only
  while no bundle is in memory; the typed `t` pipe).
- The UI kit is the family's (anotoki-lib 0.6.0), each piece imported from its
  own entry point (`@anotoki/lib/ui/button`, `/config`, `/icon`, `ui/forms`,
  `ui/dialog`, `ui/toast`, ... - never the `@anotoki/lib/ui` barrel, which
  pulls every piece into the first load; what only lazy pages use, only they
  import), and `@anotoki/lib/styles` in `src/styles.scss` (the tokens - the
  IAM's palette is the family's - Manrope, the base, utilities, tables). The
  kit's markup is its own, BEM on its host class (`anotoki-top-bar__row`,
  `anotoki-button--primary`): a spec or a browser check reads those names.
  Build on it; a piece it lacks goes to the library.
  `frontend/src/app/ui/` keeps the IAM's own (`google-button`).
- Pages: the sign-in flow (`/login`, `/register`, `/confirm-email`,
  `/forgot-password`, `/reset-password`, `/logout`, `/error`, `/not-set-up`),
  the account page (`/account/**`, the family's frame at `maxWidth="88rem"`),
  the admin panel (`/admin/**`, English only; `/admin/translations` and
  `/admin/languages` are the family's `<anotoki-translations-page>` and
  `<anotoki-languages-page>` (`@anotoki/lib/translations/admin`, lazy; worded
  for the IAM - its mails, the accounts per language - by
  `pages/admin/localization.ts`, behind `anotokiUnsavedChangesGuard`, as the
  announcement pages are); `/admin/audit`, `/admin/errors` and
  `/admin/backups` are the family's admin tools (`@anotoki/lib/admin/audit`,
  `/errors`, `/backups`, each lazy: the audit log - the IAM's own page is
  gone; its filters are the address's query parameters -, the error log, and
  the backups - encrypted, restoring off with the IAM's reason, each made,
  downloaded and deleted behind the password or a sign-in of the last 15
  minutes); `/admin/migrations` is the family's `<anotoki-migrations-page>`,
  and `<anotoki-update-banner>` sits above the panel's pages; the Migrations
  page is `@anotoki/lib/migrations/page`, out of the first load), and the
  legal pages (`/terms`, `/privacy`, `/contact`: the family's
  `@anotoki/lib/legal/pages`, lazy, anybody's, in `layouts/legal-layout` -
  the family's frame, the top bar's `public` area - with the IAM's facts,
  `core/legal/legal-facts.ts`, given by that frame and out of the first load;
  the Privacy page is the one every anotoki site links to for the account;
  Terms · Privacy · Contact under the sign-in and account pages,
  `layouts/site-footer`, not in the admin panel). The facts are true of the
  code as it is: a change to what the IAM keeps, or for how long, changes
  their `legal.*` strings (by a migration) and `IAM_LEGAL_UPDATED`; every
  address a page links to is in the code, never a string (the 0.6.0 review).
- **The top bar** (`layouts/top-bar`) is the anotoki family's one bar,
  `<anotoki-top-bar>` from `@anotoki/lib/shell` (its menus, keys and fold are
  the library's, tested there), worded and wired for the IAM; the account
  pages, the legal pages and the admin panel are in the family's frame,
  `<anotoki-shell>` (on the legal pages, `area="public"`: the account's bar
  for somebody signed in, the brand, language, theme and Sign in otherwise).
  One row: brand, the account pages as links, the language (not in the admin
  panel), the theme (the family's segmented switch), the person's menu (the
  admin panel for the IAM's ADMIN, "Back to your account" from it, Sign out
  - no "Your anotoki account": this is it). It folds into a menu button up to
  `PHONE_BAR_MAX` (1040 px, measured in English and Slovak plus a scrollbar:
  re-measure when a link or its words change). Tab / Shift+Tab from any menu
  item: `preventDefault()`, the menu closes, the focus on its button - never
  left to the browser (with no zone the focus would fall to `<body>`). A
  language chosen in the bar is saved to the account by the family's
  translations, one save at a time and the latest choice last; a refusal is
  the note under the button. The language button's name holds the code it
  shows ("Language: Slovenčina (SK)", WCAG 2.5.3; `anotoki.language.button`).
  The admin panel keeps its side navigation under the bar. The sign-in pages
  keep their own frame (`layouts/auth-layout`, the family's switcher as
  segments).
- Zoneless and signals-first; OnPush everywhere; no UI library but the
  family's. Colours only from the family's `--anotoki-*` custom properties
  (written for light and dark by `styles.scss`) and the sign-in pages' night sky.
  Every page works at 390 px (360 px for the top bar).
- The auth and account pages' words, and the mails', are rows in the
  database (`languages`, `translation_keys`, `translations`, migration 006),
  reworded and added to on the admin panel's Translations and Languages
  pages; English and Slovak (informal, "ty") are seeded. The library's own
  words (`anotoki.*`: the kit's, the language switcher's, the status page's)
  are rows too, written by its set `anotoki_translations` - never in `en.ts`,
  and never shown as a key (built-in English and Slovak under the database's).
  An unknown key is a compile error (`TranslationKey` comes from `en.ts`); a
  key with no string shows as the key. A new string is its key and English in
  `en.ts` plus a migration with English and Slovak - there is no `sk.ts`
  (skill iam-migration).

## Deployment

One web root on the IAM's subdomain: the Angular build (`npm run build:deploy`,
which writes the `.htaccess` routing `/api/` and `/.well-known/` to PHP and
refusing `/config`, `/migrations`, `/storage` and `/vendor` outright) plus
`backend/dist/`. First install through `backend/setup.php` (uploaded by hand,
token proof, writes every `config/*.local.php`, takes the production addresses,
imports the genshin accounts or makes the first admin, locks and self-deletes);
later schema changes through the admin panel's Migrations page, which backs
the database up first. `config/secret.local.php` is the master key - never
commit it, and keep a copy off the server, with the backups downloaded from
the admin panel: they open with it alone (an older one, with the key it was
made under). Full steps: `docs/operations.md`.

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
