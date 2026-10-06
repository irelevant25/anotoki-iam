# Running the IAM

Installing it on the hosting, moving the genshin site's accounts over, the day
to day, and what to do when something goes wrong. Setting it up on a
development machine is [../DEV.md](../DEV.md); the installer is
the same page in both places.

## Production: the hosting

The host is FTP/SFTP only - no shell, no cron, no long-running processes - with
PHP 8.2+ (`pdo_pgsql`, `openssl`, `mbstring`, `curl` or `allow_url_fopen`) and
PostgreSQL. The IAM lives on a subdomain of its own, its own web root. HTTPS is
not optional: the session cookie is `Secure`, tokens travel in the open
otherwise, and the installer refuses a plain-http address on a real host.

### The builds

```bash
cd frontend && npm run build:deploy     # -> frontend/dist/iam/browser/   (with the web root's .htaccess)
cd ../backend && php build-dist.php     # -> backend/dist/                (runtime only: no tests, no CLI tools, no config)
```

Upload into the IAM's web root:

```
frontend/dist/iam/browser/*  ->  web root   (index.html, JS/CSS, .htaccess)
backend/dist/*               ->  web root   (api/, config/, vendor/, migrations/, storage/)
backend/setup.php            ->  web root   (first install only; it deletes itself)
```

The `.htaccess` from the Angular build passes `/api/` to `api/index.php`,
rewrites `/.well-known/openid-configuration` and `/.well-known/jwks.json` to
it too, lets real files (the installer among them) through, falls back to
`index.html` for the app's own routes, and sets the security headers.
`api/.htaccess` sends everything under it to `index.php`, and copies the
`Authorization` header into the environment for a host that runs PHP as
CGI / FastCGI, which drops it - without it no site's bearer token (userinfo, a
site's save of its reader's preferences) would reach PHP.
`config/`, `migrations/`, `storage/` and `vendor/` carry a deny-all `.htaccess`
of their own.

Until the installer has run, the API answers every request with
`503 not_set_up` and the pages say "not set up yet": `config/` holds only the
committed loaders, and every value that belongs to this installation comes from
the files the installer writes.

### First install

1. In the hosting panel, create an empty PostgreSQL database for the IAM.
   The old genshin `users` database must be on the same server and reachable with
   the same credentials if the accounts are to be brought over.
2. Upload as above, then open `setup.php` at the IAM's address.
3. It writes a token into `config/setup.local.php`; open that file over SFTP and
   paste the token into the form - that is the proof you own the server. The
   **Test connection** buttons need it too.
4. Fill in:
   - the **public address** - the IAM's own https address; it becomes the
     issuer every site is configured with, so choose it once;
   - the **database**, with **Test connection** to check it - it says whether
     it is empty, has updates to apply or is up to date; "does not exist yet -
     Save creates it" when this user may create databases, "Make it in the
     hosting panel first" when it may not;
   - the **accounts**: **bring them over from `users`** (its own **Test
     connection** counts them and their admins), or start with a new
     administrator;
   - **the sites**: each one's production address (the examples in the fields
     are placeholders). They replace the development addresses the database
     was seeded with - `/auth/callback`, `/` and the origin at each; a site left
     blank has no address until the admin panel gives it one;
   - the sender mailboxes and, optionally, the Google client id.
5. It runs the migrations, imports the accounts (or makes the admin), puts the
   sites' addresses in place, writes `database.local.php`, `secret.local.php`,
   `iam.local.php`, `mail.local.php` and `google.local.php`, writes
   `storage/installed.lock`, and deletes itself and its token. The
   administrator it makes - or every ADMIN it brings over from `users` - holds
   ADMIN in every application that has one: the IAM's and every site's (the
   owner's rule; `createFirstAdmin()`, `legacy_import.php`). Any other role -
   a site's EDITOR - is given in the admin panel (Users → the person →
   Roles).
6. **Download `config/secret.local.php` and keep it somewhere safe.** It is the
   master key; without it the signing keys and two-factor secrets cannot be read.

### Google sign-in

The Google button is drawn on the IAM's pages only. In the Google Cloud console,
the OAuth client's **Authorised JavaScript origins** must include the IAM's
public address - `http://localhost:4300` for development, and the hosted https
address. No redirect URI is needed: Google Identity Services hands the browser
an id token.

### Behind Cloudflare (or any proxy)

Every sign-in limit counts failures per address. Behind a proxy the address PHP
sees is the proxy's, so all visitors on one of its edges share one count - an
attacker's failures lock out everybody else there, and the admin panel shows
the proxy's addresses. Tell the IAM which proxies to believe, in
`config/iam.local.php` (by hand, over SFTP - the installer keeps what it finds
there):

```php
'trusted_proxies' => ['173.245.48.0/20', '103.21.244.0/22', /* ... */ '2400:cb00::/32' /* ... */],
'client_ip_header' => 'CF-Connecting-IP',
```

The ranges are Cloudflare's own list (https://www.cloudflare.com/ips/, v4 and
v6); refresh it when they change theirs. The header is believed only on
connections from those ranges.

### Moving the genshin site over - the order matters

1. Install the IAM with the import (above). Every account arrives with its id,
   password, Google link, two-factor and recovery codes; the old sessions arrive
   as history. The old `users` database gains an `iam_handover` marker.
2. Check the admin panel: the accounts, and the roles (genshin EDITOR for
   the site's editors; the site's admins hold ADMIN in the IAM and every site).
3. Deploy the genshin site's IAM build and apply its pending migrations from its
   admin Migrations page. Its migration `040_accounts_move_to_iam` removes the old
   account tables and refuses to run until it sees the marker. The site's own
   order for this step - its IAM configuration placed first, maintenance on,
   the migrations applied straight after the upload - is in its repository
   (`../anotoki-genshin-impact`), *Moving the live site to the IAM* in its
   backend's README.
4. People sign in once more - at the IAM, with the passwords they had.

Anything still using the old site's sign-in endpoints (`/api/auth/login`) stops
working at step 3 and moves to a personal token (below).

### Later updates

Upload the new build over the old one (never `config/*.local.php` - the build
leaves them out), then **admin panel → Migrations → Apply pending**. There is
no shell; the button runs the same engine as `php migration.php`.

A migration that seeds a site (005, the survey site) leaves it on the live IAM
without an address or a card on the landing page: a `localhost` address there
would hand codes to whatever listens on the visitor's machine. So in
**Admin panel → Applications → the site**, with `{site}` its https address, add the
redirect URI `{site}/auth/callback`, the logout redirect URI `{site}/`, the web
origin and the home page `{site}` (it is on the public list of sites while it
is switched on - there are no landing page settings since 011); then give its roles to whom they belong (Users → the person → Roles) - 005
itself gives the survey site's ADMIN to the IAM's administrators. A site made
by hand before its migration arrived keeps what it has.

**Migration 007** (every first-party site open to every account, ADMIN and
EDITOR in each, MEMBER gone) reaches an installed IAM the same way: through
**Apply pending** after an upload, re-running setup.php on this computer, or
`php migration.php` where there is a shell. It opens genshin, Japanese
Academy, Piano Academy, the build analyzer and the survey site to every
account, deletes MEMBER from the three sites that had it - with whatever was
granted of it - adds EDITOR beside their ADMIN, rewords their ADMIN only
where it still says what 002 said, and gives every administrator of the IAM
ADMIN in every application that has one. A site deleted in the admin panel
stays deleted; a first-party site's ADMIN or EDITOR deleted there comes back in
its usual words. Its gate stops it, and nothing of it is kept, if any of that
did not happen - a safety net nothing known trips on an installed IAM.

**Apply 007 after the sites, not before.** A Japanese Academy or a build
analyzer from before the family's change still asks for MEMBER; once 007 has
removed it, everybody who held it is refused there (only ADMIN holders get in)
until the site is updated. So upload each site's new version first - it takes a
token with no role and reads ADMIN / EDITOR - then apply 007. Then give EDITOR
to whoever rewords a site's strings.

**Migration 008** (the top bar's words: the phone's menu button, the role in
the person's menu and the language button's name added, "Account" gone)
belongs to the frontend with the family's top bar, and Apply pending applies
it together with 007 - the engine takes the files in order. So upload the IAM's
new build when 007 may be applied too (the sites updated first), and press
Apply pending right after: until then the bar shows those three keys where
their words should be (`shell.roleAdmin` under an administrator's name), and
an older build left in place after 008 would show `shell.account`. It only
adds strings and deletes the one key; its gate names its own keys.

**Migration 009** ("Admin panel") rewords the person's menu entry and the
account overview's card from 006's "Admin console" (Slovak "Administrácia")
to "Admin panel" ("Administrátorský panel"), the name every anotoki site
gives its admin area. It rewrites a string only while it still holds 006's
words: one reworded on the Translations page stays the owner's. It goes with
the build that says "admin panel" in the bar; applied before or after it, the
worst is a moment of the old name.

**Migration 010** (sign-in per site: MEMBER for everyone, roles that may sign
in, sign-in required per site - `docs/decisions.md`, 2026-10-05) gives every
application a MEMBER role ("Uses the site") and every account MEMBER in every
application, from then on by two database triggers; adds each role's "may
sign in" (all yes) and each application's "sign-in required" (genshin and
the build analyzer no, the rest yes). Nothing changes for anybody on the day
it runs: an application set to members only keeps letting in only the holders
of its other roles (its MEMBER may not sign in), a MEMBER somebody held there
becomes INVITED with its holders, and a members-only application nobody could
get into is switched off. Before uploading the build that brings it, `php
clients.php` lists each application's `access` - the members-only ones are
those whose MEMBER 010 will untick. It adds no strings.

**Apply 010 right after uploading its build, and before any site that reads
the sign-in switch** - the opposite of 007's order. Until Apply, signing in
everywhere keeps the rule before it, and the admin panel's Applications pages
answer "Apply the pending migrations" (the other pages work); the public
answer the sites read, `/api/public/applications/{client_id}`, is a 503,
so each site keeps its last answer, or requires signing in. MEMBER in a
token is harmless to the sites as they are: they read ADMIN and EDITOR.
`access` stays in the database, unread; a later migration drops it once both
IAMs run 010. **Once 010 is applied, never upload the build from before it
again** - it would let every account into a members-only site and let back in
anybody blocked by having MEMBER taken away. A problem after 010 is fixed
forward, with a new build (`docs/decisions.md`).

What 010 leaves unticked or switched off is said in its header: an
application set to "Members only" before it keeps its MEMBER from signing in -
only the holders of its other roles (ADMIN, EDITOR, INVITED) get in - and if
that site does not require signing in (genshin, the build analyzer), everybody
else uses it as a visitor. `php clients.php` shows each application's roles
that may sign in afterwards. If "Members only" was set for a test, tick MEMBER
again (Applications → the site → Settings → Roles). A members-only
application with no other role was switched off.

**Migration 011** (public clients only, the same roles everywhere, no landing
page settings - the owner's words of 2026-10-05, `docs/decisions.md`) makes
every application a public client and clears any client secret: the token and
revocation endpoints refuse a request carrying a secret, and authorize asks
every client for PKCE. Every anotoki site already signs in that way; an
application of another kind that kept a secret must switch to PKCE. It gives
every site ADMIN, EDITOR and MEMBER (the IAM's own entry ADMIN and MEMBER):
an application without ADMIN or EDITOR gets it, in the family's words, held
by nobody, and a role made before stays, read-only. A new application gets
the three from then on. The admin panel no longer adds, removes, renames or
rewords a role, offers no client type or secret, and has no landing page
settings: the public list of sites is every one switched on, in the order
they were added. `type`, the secret and the landing switch stay, unread, until
the migration that drops `access` - not a way back to a build from before
010. It adds no strings, and goes with 010, in the same Apply.

**Migration 012** (new accounts may wait for an administrator's approval - the
owner's decision of 2026-10-06, `docs/decisions.md`) adds the switch
Settings → **New accounts need approval**, off, and an `approved` flag on every
account, true for all there are: nothing changes until the switch is turned
on. It adds the words of the waiting pages and of the approval mail, in
English and Slovak. Between the upload and Apply, signing up and signing in
work as before and the admin panel's Approvals page says to apply the pending
update first. **Once 012 is applied, a build from before it never goes on the
host again** - it reads no `approved`, and would let every account waiting
for approval in; fix forward, or approve or decline everybody waiting first.

**Migration 013** (announcements - the owner's decision of 2026-10-06,
`docs/decisions.md`) adds the admin panel's **Announcements**: the tables
that keep what was sent and the senders added in the panel, the news switch
on every account (on for all there are; every announcement says how to stop
it), the three templates' words and the two lines every announcement ends
with, and the words of the account page's switch and the `/unsubscribe` page,
in English and Slovak. Nothing is sent by itself. Between the upload and
Apply the Announcements pages say to apply the pending update first, and the
account page shows no switch.

**Migration 014** (the news, offered at registration) adds the words of the
register page's box - "Send me news from anotoki sites", unticked - in English
and Slovak; no schema. It goes with 013, in the same Apply.

**Migration 015** (the theme, the dates and the clock are the account's - the
owner's decision of 2026-10-06, `docs/decisions.md`) adds the three columns,
`auto` and "automatic" for every account there is, the `preferences` claim and
`POST /api/oidc/preferences`, and the words of the account page's Dates and
Clock, in English and Slovak (rewording the language's and the appearance's
hints where they are still 006's). Between the upload and Apply nothing
changes: the account page keeps the theme per device, and the tokens carry no
`preferences`, so a site with SDK 1.2.0 keeps its own settings until then. The
sites follow it in any order after the upload - upload the IAM, Apply, then
the sites.

### The sites' addresses

A fresh database registers the first-party applications with their development
addresses (the port table in [architecture.md](architecture.md)). On a real host
the installer replaces them with the production ones it is given, and removes
every loopback address either way - on a live IAM a `localhost` redirect URI
would hand a code to whatever listens on the visitor's own machine. Anything
after that - a new site, one a later migration seeded (above), a renamed
domain, a site left blank at install - is the admin panel's **Applications**
page: exact redirect URIs, post-logout URIs, web origins, which of its roles
may sign in (the roles themselves are fixed: ADMIN, EDITOR, MEMBER), whether
the site requires signing in (sites pick a change up within about a minute),
and the access token lifetime.

### The words across an update

The pages' and the mails' words are rows in the database (migration 006 and
every migration with strings after it), so an update is still **upload, then
Apply pending** - and between the two:

- The pages read the English compiled into the build, and the mails go out in
  English: `GET /api/translations/{code}` answers `503 translations_unavailable`
  until the tables are there. That 503 is expected in that window, not a fault
  (and not `not_set_up`: the site is not sent to the installer's notice).
- Discovery and `/api/public/config` offer `en` and `sk`, and accounts keep
  their language.
- The admin panel's Translations and Languages pages say to apply the pending
  update first. The first navigation after Apply brings the words in, with no
  page load.

A migration never writes over a string reworded in the admin panel. Before a
release that changes strings, **export the live strings** (Admin panel → Translations
→ Export, one JSON file per language) and keep them: they are the owner's
wording, and the export is what puts it back if a release's rewording is not
wanted. On a computer with the repository, `php translations.php --import`
checks and loads such a file into a development database.

## Day to day

| Task | Where |
| --- | --- |
| Give somebody a role on a site | Admin panel → Users → the person → Roles: the site's EDITOR opens its admin area, ADMIN all of it |
| Let every account use a members-only application | Tick its MEMBER: Admin panel → Applications → the application → Settings → Roles (every account holds MEMBER everywhere, migration 010) |
| Make a site members-only (or admins only) | Untick its MEMBER (and EDITOR) there; then give the people who should get in a role by hand (Users → the person → Roles). The Members tab lists who holds a role beyond MEMBER, and who cannot sign in |
| Let visitors use a site without an account, or stop that | Admin panel → Applications → the site → Settings → **Sign-in required**. The site picks it up within about a minute |
| See who is blocked where | Users: a "Cannot sign in: {site}" badge per site; an application's Members tab: "Cannot sign in" |
| Block somebody from one site | Take that site's MEMBER (and any other role there that may sign in) from them: Admin panel → Users → the person → Roles. Their next sign-in and refresh are refused; the access token they hold works until it expires (minutes). A site that does not require signing in still lets them in as a visitor - blocking stops signing in, not visiting |
| Somebody is locked out (lost password) | They use "Forgot password"; or Admin panel → Users → Set password (they must choose a new one at the next sign-in) |
| Somebody lost their phone and recovery codes | Admin panel → Users → Reset two-factor |
| End a session that should not be there | Admin panel → Sessions, or the person's own account page |
| Switch signing in off for everybody but IAM admins | Admin panel → Settings → Signing in |
| Send news to the people of the sites | Admin panel → **Announcements** → New announcement: a template (new version, new release, new survey, blank), the site, the link and the sender; the words in each language (empty: that language's readers get English); who it is for - everybody, roles, people; Send me a test; Send. Keep its page open until it is sent - it goes in batches from there, and can be paused, continued and stopped. Only people with a confirmed address, approved, switched on and with the news on get it |
| Change the template's words, or the line every announcement ends with | Admin panel → Translations: the mails, "Announcements: ..." and "The end of every announcement" |
| Add an address announcements can come from | Admin panel → Announcements → Senders. Only at the domain the IAM sends from - and the mailbox must exist on the host. The installation's own (noreply, support) are config/mail.local.php's - never at a public mail provider: that domain is what counts as the anotoki sites' in an announcement's links |
| Keep announcements inside the host's hourly mail limit | `'hourly_limit' => 300` (the host's number) in config/mail.local.php: announcements then use nine tenths of it, counting every mail the IAM sent in the last hour, and wait on their page for room. The installer keeps the key when it runs again. Without it the composer warns, as an announcement could use up what the host allows - sign-in codes with it |
| Approve every new account before it can sign in | Admin panel → Settings → **New accounts need approval**. Accounts made with the form or with Google then wait on Admin panel → **Approvals** (the dashboard counts them): Approve mails them (once their address is confirmed), Decline switches the account off - not while its address is unconfirmed, as its registrant may not own it. Accounts made in the panel never wait; switching it off lets in everybody still waiting (a declined account stays declined) |
| Rotate the signing key | Admin panel → Keys → Rotate. Tokens signed by the old key keep working until they expire; sites refetch the key set by themselves. Remove the retired key after a day. |
| A script needs to call a site's API | The account page → Tokens for scripts |
| Reword a sentence of the pages or a mail | Admin panel → Translations: edit, Save. Readers get it at their next page load; the change is in the audit log |
| Add a language | Admin panel → Languages → Add (it starts hidden), write its strings on Translations (or import a JSON file), then switch it on. Until a string is written the language reads it in English |
| Stop offering a language | Admin panel → Languages → switch it off: its strings and accounts stay, its readers get English. Deleting it sends its accounts to English and removes its strings; English and Slovak cannot be deleted |

## Scripts: personal access tokens

Scripts that used to post an email and password to a site's `/api/auth/login`
(the genshin importer, the benchmark) use a personal token instead: made on the
account page for one application, shown once, exchanged for access tokens at
`POST {issuer}/api/oidc/token` with `grant_type=refresh_token`. It carries the
person's current roles, never rotates, and stops when revoked or expired.

## Development machine commands

```bash
cd backend
composer start                             # the API, and the installer at http://localhost:8100/setup.php
php migration.php                          # apply pending migrations
php import-legacy.php --dry-run            # rehearse the account import (from the configured 'legacy' database)
php import-legacy.php                      # the import itself (writes the marker)
php create-admin.php you@example.com       # a first admin on an empty IAM, instead (ADMIN in every application)
php clients.php --dev                      # put the sites' development addresses back
php reset-password.php <email> [password] [--clear-2fa]
php translations.php --status              # the frontend's keys against the database's, and how much of each language there is
php translations.php --export sk [file]    # a language's own strings as flat JSON
php translations.php --import sk file      # and back in, checked as the admin panel checks a save
php tests/run.php                          # every test, against throwaway databases
```

## When something goes wrong

| Symptom | Likely cause |
| --- | --- |
| Every request answers 503 `not_set_up`; the pages say "not set up yet" | The installer has not run, or a `config/*.local.php` is missing or wrong (on a real host: uploaded over, or deleted). On a development machine `setup.php` lists which; on a server, restore the file from your copy. |
| "The active signing key cannot be decrypted" | The master key is not the one the key was made with. Restore the right `secret.local.php`; failing that, delete the rows of `signing_keys` (everybody signs in again) and re-enable two-factor for those who had it. |
| `setup.php` says "Already installed" | `storage/installed.lock` is there. It deletes itself if it can; otherwise delete `setup.php` and `config/setup.local.php` by hand. |
| A site says "invalid_redirect_uri" before any page | Its redirect URI is not registered exactly as it sends it (scheme, host, port, path, trailing slash) - or it was left blank at install. |
| A site's token endpoint call fails in the browser with a CORS error | The site's origin is not in the application's web origins. |
| A site accepts nobody's token | The site's configured issuer is not exactly the IAM's public URL, or its client id is wrong, or it cannot reach `/.well-known/jwks.json`. |
| "access_denied" for somebody who should get in | They hold no role there that may sign in: their MEMBER was taken away (give it back under Users → the person → Roles), or the site's MEMBER may not sign in (the site was members-only before 010, or unticked since) and they hold none of its other roles - tick MEMBER, or give them a role that may sign in. |
| Somebody signed up and is told "Waiting for approval" | Settings → New accounts need approval is on: approve them on Admin panel → Approvals (or their page under Users). A declined account is switched off: switch it on under Users, and it waits again |
| A site's admin area refuses its owner | They hold neither its ADMIN nor its EDITOR: Admin panel → Users → the person → Roles. An account made in the admin panel, or made the IAM's ADMIN there, gets no site's ADMIN by itself; the installer's administrator, `create-admin.php`'s, an imported one and every administrator there when 007 ran were given all of them. |
| Somebody no longer an administrator still administers a site | Taking the IAM's ADMIN away takes nothing else: remove their ADMIN (and EDITOR) on each site too - Admin panel → Users → the person → Roles. |
| Sign-in mail never arrives | `mail.local.php` still has the `file` driver (mail is in `storage/mail/`), or the sender is not a real mailbox on the hosted domain. |
| An announcement stopped going out half-way | Its page was closed: it is sent only while the page is open - open it (Announcements → it) and press Continue sending. Waiting "for the host's hourly allowance" is `hourly_limit` at work; it goes on by itself |
| Announcement mails "failed", or never arrive | The host refused them: its hourly limit (set `hourly_limit`), or the sender is not a real mailbox on the hosted domain. Each person's status is on the announcement's page, and the Email log has every mail |
| Somebody gets no announcements | They did not tick "Send me news" when they signed up (or signed up with Google from the sign-in page, where nobody is asked), they stopped the news (the link at the end of one, or the switch on their account page - the audit log says which: `account.news_mail`), their address is not confirmed, they wait for approval, or they are switched off |
| Google button missing | No `google.local.php`, the switch is off, or the page's origin is not an authorised JavaScript origin of the client. |
| The pages are English only, with no language switcher, after an upload | A migration with the words is pending: `/api/translations/...` answers 503 `translations_unavailable`. Admin panel → Migrations → Apply pending. |
| A page shows a key (`login.title`) where a word should be | The key has no string in the database: a migration that adds it is pending, or its English row was removed by hand. `php translations.php --status` on a copy names it. |
