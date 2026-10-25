# Running the IAM

Installing it on the hosting, moving the genshin site's accounts over, the day
to day, and what to do when something goes wrong. Setting it up on a
development machine is [../DEV.md](../DEV.md); the installer is
the same page in both places.

## Production: the hosting

The host is FTP/SFTP only - no shell, no cron, no long-running processes - with
PHP 8.2+ (`pdo_pgsql`, `openssl`, `mbstring`, `zlib` - the backups compress
with it -, `curl` or `allow_url_fopen`) and PostgreSQL. The IAM lives on a
subdomain of its own, its own web root. HTTPS is not optional: the session
cookie is `Secure`, tokens travel in the open otherwise, and the installer
refuses a plain-http address on a real host.

### The release

The IAM's folder on the host, `anotoki.eu/sub/iam/`:

```
.htaccess   the switch: every request -> frontend/ (written once: anotoki-deploy switch)
frontend/   the built app, index.php (the front controller), .htaccess   } a release:
backend/    api/, config/ (the loaders, no *.local.php), migrations/,     } replaced whole
            vendor/, release.json                                         }
config/     *.local.php - the settings, the master key among them        never in a release
storage/    the lock, logs, backups, the deletion record, mail, caches    never in a release
```

```bash
cd backend && php tests/run.php                        # must pass before anything ships
cd ../frontend && bun run anotoki-deploy build         # npm run build:deploy (the build and its checks),
                                                       # then the release -> ../dist/frontend/ and ../dist/backend/
bun run anotoki-deploy deploy --backups <folder>       # a dry run: what goes up
bun run anotoki-deploy deploy --backups <folder> --go  # the live frontend/ and backend/ copied into <folder>,
                                                       # the release up beside them, checked, swapped in, the site checked
bun run anotoki-deploy rollback --go                   # the release before (kept as .old) back in
```

(`@anotoki/deploy`: a dev dependency of `frontend/`, from anotoki-lib's release.
`bun run` finds it whichever tool installed `node_modules/`; on Windows, `npx`
misses bun's links.) anotoki-deploy is the anotoki library's deploy tool, the
same for every site; the IAM's facts are in `backend/composer.json`,
`extra["anotoki-deploy"]`: what goes into `backend/` (never `setup.php`, the
command-line tools, the tests or a `*.local.php`), the routes (`/api`,
`/.well-known/openid-configuration`, `/.well-known/jwks.json` to the front
controller, which routes on the original path), the CSP and the headers. The SFTP
login is never in a repository: `ANOTOKI_DEPLOY_SECRETS` names a `secrets.json`,
with the host's pinned key in `hostkey.txt` beside it. The first time:
`bun run anotoki-deploy switch --go`, once - the folder's `.htaccess` becomes the
switch into `frontend/`; `backend/`, `config/` and `storage/` are never served.
The PHP finds its state with one rule (`iamStateRoot()`, `config/config.php`):
with `release.json` in `backend/`, `config/` and `storage/` are the ones beside
it; in a checkout, `backend/`'s own.

`backend/setup.php` is never in a release: for the first install it is uploaded
by hand into the live `backend/`, and it deletes itself.

The release's `frontend/.htaccess` sends `/api`, `/robots.txt`, `/sitemap.xml`,
`/.well-known/openid-configuration` and `/.well-known/jwks.json` to `index.php`
(which requires `backend/api/index.php`),
`/setup.php` to the installer while `backend/` has it, falls back to `index.html`
for the app's own routes (a missing file with an extension is a 404), sets the
security headers, and gets the `Authorization` header to PHP under CGI / FastCGI
(`CGIPassAuth`, and `SetEnvIf` where that cannot be said) - without it no site's
bearer token (userinfo, a site's save of its reader's preferences, the token
endpoint's Basic credentials) would reach PHP. `backend/` carries a deny-all
`.htaccess`, and `config/` and `storage/` - beside the release, never served
through the switch - keep theirs; every folder the anotoki library writes under `storage/`
(`logs/`, `backups/`, `cache/backup-tickets/`) gets another, with an empty
`index.html`, the first time it is written: what `storage/` holds - the error
log, the backups of the database - is never served. That rests on the host
reading `.htaccess` (Apache, LiteSpeed); on one that does not, `storage/` must
be kept out of the web root by other means.

Until the installer has run, the API answers every request with
`503 not_set_up` and the pages say "not set up yet", with a link to the
installer: `config/` holds only the committed loaders, and every value that
belongs to this installation comes from the files the installer writes. Once
it has run, `storage/installed.lock` is there, and nothing offers the installer
again - should a `config/*.local.php` go missing later, the pages say the IAM
"is not available right now" and keep asking by themselves.

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

The pages draw the IAM's own "Sign in with Google" button (in Google's
branding) and ask nothing of Google until somebody presses it (the owner's
decision of 2026-10-14): the press fetches Google's script - once a visit - and
puts Google's own button in its place, and a press on that opens Google's
account chooser. A browser that cannot reach Google keeps the IAM's button,
with a line under it saying so.

The id token Google hands over is good for five minutes here, and once (the
security review of 2026-10-19): the server's clock must be right within a
minute - NTP, as every host has it - or every Google sign-in reads as stale or
from the future. Somebody who takes longer than five minutes over the
two-factor code after pressing Google is sent back to press it again.

### Behind Cloudflare (or another proxy)

Every sign-in limit counts failures per address, the audit log keeps the
address of whoever acted, and the Sessions lists show one. Behind a proxy the
address PHP sees is the proxy's - all visitors on one of its edges would share
one count, an attacker's failures locking out everybody else there. So the IAM
believes the visitor's address Cloudflare names in `CF-Connecting-IP`, on a
connection from Cloudflare's published ranges (https://www.cloudflare.com/ips/,
v4 and v6) - and from nowhere else: those ranges are the trusted proxies of
`config/iam.php`, committed (the owner's decision of 2026-10-11,
`docs/decisions.md`): the anotoki library's list, `ClientAddress::CLOUDFLARE`,
which a release of the library refreshes when Cloudflare changes theirs. A
visitor who reaches the host without Cloudflare is counted by their own
connection, and what they write into the header is never read. The header is
`CF-Connecting-IP` and no other, pinned in the code: Cloudflare writes that one
itself and passes `X-Real-IP`, `True-Client-IP`, `X-Forwarded-For` and their
kin on as the visitor wrote them, so no setting names another (a
`client_ip_header` left in `iam.local.php` is not read).

An installation behind other proxies, or none, says so in
`config/iam.local.php` (by hand, over SFTP - the installer keeps what it finds
there):

```php
'trusted_proxies' => ['192.0.2.10', '198.51.100.0/24'],   // its own proxies: single addresses or CIDR ranges, v4 and v6
// behind no proxy at all: 'trusted_proxies' => [],
```

A range wider than an IPv4 `/8` or an IPv6 `/16` - `0.0.0.0/0` and `::/0`
above all, which would believe a header anybody can write - is refused, and so
is anything that is no range: it is never believed, and PHP's error log says
so once a day (`[trusted proxies] ... is not believed`).

In the Cloudflare zone, keep Network -> Pseudo IPv4 off "Overwrite headers":
that setting writes a stand-in IPv4 into `CF-Connecting-IP` for an IPv6
visitor, and the IAM would count every such visitor by the stand-in instead of
their own network (an IPv6 visitor is counted by their /64,
`docs/decisions.md`).

And Cloudflare's ranges are every Cloudflare customer's, not this zone's
alone: anybody's Worker reaches the host from them and writes the
`CF-Connecting-IP` it likes. So the zone adds a secret to every request
through it, and the IAM believes the header only on a request that carries it
(the owner's decision of 2026-10-13, `docs/decisions.md`; anotoki-lib 0.5.2):

1. Make a long random value - 32 characters or more, letters and digits, for
   example `php -r "echo bin2hex(random_bytes(24));"`. One value serves every
   anotoki site of the zone.
2. In the Cloudflare zone: Rules -> Transform Rules -> Modify Request Header
   -> Create rule, for every request of the zone (all incoming requests):
   **Set static**, header `X-Anotoki-Origin`, value the secret. Deploy it.
3. On the host, beside the other configuration, `config/cloudflare.local.php`
   - by hand, over SFTP: the installer neither asks for it nor writes it, and
   no build carries it:

   ```php
   <?php return ['origin_secret' => '<the same value>'];
   ```

   `<?php` its very first bytes: a file that does not start with them - no
   `<?php` line, a byte-order mark an editor put before it, a blank line - is
   never even compiled, and one that prints something of its own or that PHP
   cannot read is passed over too; each fails closed: a secret no request
   carries, so every visitor counts as their Cloudflare edge until the file
   is saved again (PHP's error log says so, by its start, its size or the
   kind of error, never its text). So does one read cleanly that names no
   secret - an empty value, a misspelt `origin_secret`, a forgotten `return`
   - and a folder in its place (2026-10-18, `docs/decisions.md`). Spaces or a
   line break around the value do not matter: it is trimmed.

Without the file - nothing at its path - the IAM believes `CF-Connecting-IP`
from Cloudflare's ranges alone, as before; to switch the secret off, delete
the file (an emptied one fails closed). With it, a request from the ranges
that does not carry the value counts as the Cloudflare edge it came through:
one source for the limiter, however many addresses it writes. The rule alone
changes nothing; the file without the rule, or with another value, counts
every visitor as their edge until the two agree - set them together, and keep
the value out of every file anybody else reads. To change it: the rule first,
then the file, within a minute (between the two, visitors count as their
edges).

### The sign-in hint's domain

Every sign-in also sets the sign-in hint, the cookie by which a site that lets
visitors in signs a person who is signed in at the IAM in without a click
([api.md](api.md), "The sign-in hint cookie"). On a real host it is set for
the parent of the IAM's host - `anotoki.eu` for `iam.anotoki.eu` - worked out
from the public address, so every site under that domain reads it: nothing to
set. Only while a site switched on signs in under that domain (one of its
redirect URIs, Admin panel → Applications); otherwise the hint stays the
IAM's host's alone, and the error log says why. A site under another domain
never gets it, and signs nobody in by itself. It comes with the build, no
migration: a session from before it gets its hint at its next single sign-on
or account page, and until a site reads it nothing changes.

**The domain must be wholly the owner's.** The hint goes with every request
to every host under it, and any script on any page there can read it. On a
hosting's own domain - a free subdomain of the provider's, where webmail, a
control panel or other customers live beside the sites - that is the
provider's hosts too: switch the hint off there instead
(`'hint_cookie_name' => null` in `config/iam.local.php`).

To name another domain - one the IAM's host is under - put it in
`config/iam.local.php` by hand (the installer keeps what it finds there):

```php
'hint_cookie_domain' => 'anotoki.eu',
```

A domain the IAM's host is not under, a single label, an address or
`localhost` is passed over: the hint is then the IAM's host's alone. Each such
thing is said in the error log at most once a day (a stamp in
`storage/cache/` remembers when). An IAM whose host has three labels on a
public suffix (`example.co.uk`) must name its own host there - one label less
is `co.uk`, which browsers refuse. A second IAM under the same domain (a test
one) gives its hint another name - `'hint_cookie_name' =>
'anotoki_signed_in_test'`, and the sites it serves are told - or the two
overwrite each other's hint.

### The session cookie's name

On HTTPS the session cookie is `__Host-anotoki_iam` (since 2026-10-07; plain
`anotoki_iam` before, and on a development machine still): a name only the
IAM's own host can set, read by that exact name - look-alikes and a name sent
twice are no session. Since the owner's decision of 2026-10-10 the bare
`anotoki_iam` is never read on HTTPS, so a session another host under the
domain plants under it signs nobody in.

**After the upload that brings this, everybody signs in at the IAM once
more** - as of 2026-10-11 only the owner, the one person who has signed in
(the sites are not public yet). A browser from before the `__Host-` name
holds its session under the bare one, which is no longer read: the IAM's
pages ask the person to sign in, and a site's silent sign-in finds nobody at
the IAM until they have. **The sites keep working**: they hold their own
tokens and refresh without the IAM's cookie, so nobody signed in on a site is
signed out of it. The old sessions run out by themselves (90 days at most) -
each person can end theirs on the account page's Sessions list - and the
bare cookies are expired at the next visit to the IAM. No migration, and
nothing for the sites to change.

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

`bun run anotoki-deploy deploy --backups <folder> --go` (a release never holds
`config/*.local.php` and never touches `config/` or `storage/`), then **admin
panel → Migrations → Apply pending**. There is
no shell; the button runs the same engine as `php migration.php` - the anotoki
family's (anotoki/lib, below), the one every anotoki site's Migrations page
runs.

Between the upload and Apply the IAM keeps working - every way in, the account
pages, the sites' sign-ins, the admin panel: it is the one anotoki site that
does not close while an update waits, since every other site's administrator
signs in through it to apply theirs. Its administrators see one line above
every page of the admin panel, **"A database update is waiting. Migrations"**;
nobody else is told anything. The Migrations page lists what is pending and
what is applied (**View** shows a file's SQL), asks before it applies, and
then says what happened: "Up to date", or which file stopped the run with what
the database said - that file is rolled back whole, and the ones before it
stand. Apply backs the database up first, by itself (a `before-migration`
backup, encrypted, on the Backups page - "Backups" below), and applies nothing
when that backup cannot be made; a backup of the hosting's own besides does no
harm. Only one apply runs at a time - the page, `php migration.php`, the
installer: a second is told "Another database update is running" and applies
nothing; look again in a moment.

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
get into is switched off. Before uploading the build that brought it, `php
clients.php` listed each application's `access` - the members-only ones were
those whose MEMBER 010 would untick. It adds no strings.

**Apply 010 right after uploading its build, and before any site that reads
the sign-in switch** - the opposite of 007's order. Until Apply, signing in
everywhere keeps the rule before it, and the admin panel's Applications pages
answer "Apply the pending migrations" (the other pages work); the public
answer the sites read, `/api/public/applications/{client_id}`, is a 503,
so each site keeps its last answer, or requires signing in. MEMBER in a
token is harmless to the sites as they are: they read ADMIN and EDITOR.
`access` stayed in the database, unread, until migration 027 dropped it (below)
once both IAMs ran 010 - and the rule before 010 went from the code with it.
**Once 010 is applied, never upload the build from before it
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
they were added. `type`, the secret and the landing switch stay, unread, for a
later migration (027 dropped 010's `access` alone) - not a way back to a build
from before 011. It adds no strings, and goes with 010, in the same Apply.

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

**Migration 016** (an account deleted at its owner's request, and a mail for
a sign-in from a new browser - the owner's decisions of 2026-10-07,
`docs/decisions.md`) adds the deletion's three dates to every account (none
set), the table of the browsers accounts signed in from (empty), the words of
the account page's deletion, its notice, the refusal page and the four mails
(asked, cancelled, deleted, a new browser) in English and Slovak, and makes a
new application give MEMBER to every account but a deleted one. Apply it right
after the upload. Until then nothing changes: asking for a deletion answers
that the update must be applied first, signing in sets no device cookie and
mails nobody, and the public list of deleted ids is empty. After it, each
account's first sign-in notes its browser without a mail - nobody is mailed
on release day; a second browser after that is. The sites need nothing new to
keep working: an account being deleted is simply refused to them. Each reads
`/api/public/anonymized-accounts` once its shared module arrives, and scrubs
its own copies of the names and addresses there.

**Migration 017 and the library's translations set** (the IAM's words through
the anotoki library, anotoki-lib 0.3.0 - `docs/decisions.md`, 2026-10-11): the
languages, the strings and their admin pages are the family's library's code
now, on the same tables. 017 moves the language switcher's two strings under
the library's keys (`lang.label` → `anotoki.language.label`, `lang.button` →
`anotoki.language.button`) with every language's string, who reworded it and
when, and renames `sessions.method.other` and `sessions.ended.other` to
`.unknown`. Then the library's own set, `anotoki_translations`, applies after
the IAM's in the same Apply: `001` proves the tables are in the family's shape
(it makes nothing on the IAM), `002` adds the library's words - 54 keys of its
kit and status page, in English and Slovak - writing only where a key has no
string, so no rewording is touched. The Migrations page lists the two as
`anotoki_translations/001_languages_and_strings.sql` and `/002_library_words.sql`.

**Before the upload, export every language** (Admin panel → Translations →
each language's Export, one JSON file each) and keep the files: the move keeps
every rewording - its gate proves it - and the export is what puts one back if
anything is not as it was. **Apply right after the upload.** Until then
nothing closes and every way in works - the pages read their words, the
library's from its built-in English and Slovak - but the account page's
Sessions list would show `sessions.method.unknown` for a way of signing in it
does not know. Once applied, a build from before it never goes back on the
host (it would show four keys as keys); fix forward.

**The library's admin set and migration 018** (the admin panel's tools from
the anotoki library, anotoki-lib 0.4.1 - `docs/decisions.md`, 2026-10-11): the
Error log, Audit log and Backups pages are the family's library's. The upload
brings, in this order: `anotoki_admin/001_audit_log.sql` and
`002_feedback.sql` (the library's tables - the audit log, and feedback, which
the IAM takes since 2026-10-19), then 018, which moves every entry of the IAM's
`audit_log` into the library's `anotoki_audit_log` with its id, and keeps the
old table, unread, as `audit_log_before_018` (migration 027 dropped it once
both IAMs ran 018, below); then the library's translations set's `003` and `004`
(the words of the sites' sign-in screen and feedback form - the IAM shows
the sign-in screen's never and the feedback form's since 1.0.0, and they are
in its Translations page like every library word).
**Apply right after the upload**; the first Apply makes the first backup
before it starts. Until then nothing closes and every way in works; the audit
entries made meanwhile wait in `storage/logs/audit-pending.jsonl` and go in
with the Apply, with their own times, and the Audit page says to apply the
update first. **Once 018 is applied, a build from before it never goes on the
host again** - it would lose every audit entry and stop the deletion of
accounts; fix forward.

**anotoki-lib 0.5.0** (both halves; `docs/decisions.md`, 2026-10-11) brings no
migration: nothing waits for Apply. From the upload on, the IAM counts and
keeps the visitors' own addresses through Cloudflare (above, "Behind
Cloudflare"), the Error log holds the pages' own errors too, and the Backups
page names who made each backup. The host needs `zlib` beside `mbstring`.
The backups' files are made the PHP user's alone (`0600`, folders `0700`):
where PHP runs as another user than the FTP account, take a backup off the
server with the Backups page's Download ("Backups" below).

Deleted accounts are made nobody's by housekeeping, which runs on ordinary
traffic (the sites' sign-ins and refreshes, the IAM's own pages, the sites
reading the list), at most an hour after one is due - no cron. **A database
backup is never restored to bring an account back.** Every deletion asked
for, cancelled and done is also a line of `storage/records/account-deletions.jsonl`
(since round 3): if the database is lost and an older backup must be put back,
"When the database is lost" (below) does again what the record holds.

**Round 3** (the owner's decisions of 2026-10-13): anotoki-lib **0.5.2** (the
PHP half; the frontend stays on the 0.5.0 tarball) and migration **019**.
Upload, then Apply on the Migrations page: 019 brings the authenticator's
pause - five wrong codes pause an account's codes for fifteen minutes, a code
by email standing in after the password until five of those are wrong too -
and its words in English and Slovak; until it is applied the authenticator is checked as before. Put
`config/cloudflare.local.php` on the host with the zone's Transform Rule
("Behind Cloudflare" above), both at once. Nothing to do for the record: its
folder `storage/records/` is made with its first line; download it with the
backups from then on.

**anotoki-lib 0.6.0** (both halves) and migrations **020** and **021**, later
in round 3: the family's legal pages - Terms, Privacy and Contact at `/terms`,
`/privacy` and `/contact`, linked from the foot of the sign-in and account
pages; the IAM's Privacy page is the one every anotoki site links to for the
account, so the IAM goes up before the sites that link to it. Upload, then
Apply on the Migrations page: it brings 020 (the IAM's facts for the pages,
in English and Slovak, and the sign-in pages' sky made true beside them - no
longer "nothing is shared"), 021 (the sign-in page's two "email me a code"
buttons in the informal Slovak) and the library's translations 005-009 (the
legal pages' words, the top bar's, the kit's new pieces'). Until then the
pages say the library's built-in words, and the IAM's facts show as their
keys - so Apply right after the upload. The hosting company and the mail
service are WebSupport s.r.o. (the owner named it on 2026-10-18: the
servers, the database and the mail) on the Privacy page
(`frontend/src/app/sites/main/legal/legal-facts.ts`, `processors`).

**anotoki-lib 0.6.1** (both halves) and migration **022**, at the end of round
3: Google only on a press - the sign-in, sign-up and account pages draw the
IAM's own Google button and fetch Google's script only when somebody presses it
(the owner's decision of 2026-10-14). Upload, then Apply on the Migrations
page: it brings 022 (that button's words, in English and Slovak, and the
Privacy page's sentence about Google made true: only a press loads anything
from Google) and the library's translations 010 (the Privacy page's sentence
on where the backups are: an administrator may keep a downloaded copy
elsewhere). Until then the button shows its keys (`google.signIn`) - so Apply
right after the upload. **Once 022 is applied, a build from before it never
goes on the host again** - it would load Google's script as each page opens,
while the Privacy page says only a press does; fix forward.

**anotoki-lib 0.6.2** (both halves), with the same upload: the library's
translations 011 rewords three sentences of the sites' Privacy page (none of
them on the IAM's, which says its own words there) - Apply brings it after
the IAM's own files. And a `config/*.local.php` that does not start with
`<?php` - a byte-order mark or a blank line before it - is passed over
before PHP compiles it: with `declare(strict_types=1)` after such a start
it was a fatal error that made every request a 500, the status included;
now the IAM says "not set up" (a required file) or fails closed (the origin
secret's), and PHP's error log names the file and that it "does not start
with <?php".

**anotoki-lib 0.6.3** (both halves), with the same upload: the family's
footer - the build analyzer's top border and Terms · Privacy · Contact -
under every page, the admin panel's too (no row above the links then: the IAM
had no version, What's new or feedback until 1.0.0, below), and `/not-set-up` with nothing of the
IAM round it. Apply brings the library's translations 012 (the footer's four
words - the first three on the IAM's footer since 1.0.0); nothing on the IAM's pages waits
for it. Before this upload, look at the server's `config/cloudflare.local.php`:
from it on, a file there that names no usable secret - blank, a misspelt key,
fewer than 32 characters, a placeholder left in - counts every visitor as
their edge. Put the zone's value in, or delete the file.

Migration **023** (the owner's decision of 2026-10-19): upload, then Apply.
Every site's application gains EARLY_ACCESS - it may sign in, and nobody holds
it until an administrator gives it - so who gets in where does not change; a
site that lets only ADMIN sign in (Japanese Academy, Piano Academy and the
build analyzer, on the live IAM) then lists ADMIN and EARLY_ACCESS as allowed:
untick EARLY_ACCESS there if it should stay ADMIN alone even once somebody is
given it. The sites need nothing. It is no one-way step: a build from before
023 still works on the database.

**1.0.0** (the owner's decision of 2026-10-19): the footer's row - the
version, What's new and Feedback - and the admin panel's Feedback page.
Upload both halves - the frontend's `changelog.json` beside `index.html`,
served `no-cache` by the release's `.htaccess` (`extra["anotoki-deploy"].noCache`
writes it) - then Apply: migration 024 brings What's new's six words. Until
then the dialog shows its keys; everything else works. The feedback form
needs no migration (the library's `anotoki_admin/002`, applied with 018) and
nothing new in `config/` - its limits are counted in `storage/cache`, keyed
by the master key. No mail is sent for a message: look at Admin panel →
Feedback now and then. **Never change an account because a message asks** -
clearing two-factor, resetting a password: a signed-out message's address is
only what its sender typed, and anybody holding a stolen password can write
one. Answer at the account's own address, and act only on what its owner
proves there. **Once 1.0.0 has taken a message, no build from before it goes
on the host again**: an older build neither takes a deleted person out of
their messages nor prunes them, and its Privacy page says the IAM takes no
feedback; fix forward.

Migration **026** (the security review of 2026-10-19 and the reviews of its
changes, `docs/decisions.md`): upload both halves, then Apply. It spends every
confirmation link an address already confirmed still holds, and every live
link mailed before links noted their address (somebody in the middle of
signing up presses "send it again"); ends the live sessions a confirmation or
reset link opened on an account with two-factor on that say `mfa` (with their
refresh tokens - their owners sign in once more; the account page lists them
"Ended by a security change"); makes the table that keeps a Google sign-in
from being used twice or taken by anybody else; and brings the confirmation
page's seven new words - the page now asks for a press ("Confirm my
address") before it confirms anything, and names the account it signed in
to. Until Apply the page shows those words as keys and a Google token is held
to its five minutes alone; everything else works. The sites need nothing: an
`amr` says `mfa` only where a second factor was checked, which is what it
always claimed.

After the Apply, look at the account page's **Tokens for scripts** of the
accounts with two-factor on (the owner's own, at least; the admin panel's
audit log names every one made, `account.token_created`): a session that a
leftover confirmation link opened could make a token for scripts, which
needs only the session and belongs to no session - so 026 does not end it.
Revoke one nobody remembers making. **Once 026 is applied, no build from
before it goes on the host again** - it would open the three holes again, and
its confirmation page would read a confirmation that signs nobody in as a
sign-in; fix forward.

**anotoki-lib 0.11.0 and migration 027** (2026-10-25; `docs/decisions.md`):
upload both halves, then Apply - its backup first, as ever. Apply brings the
library's `anotoki_admin/003` (the site settings' tables), the IAM's **027**
(it drops `applications.access`, the rule before 010, and
`audit_log_before_018`, the copy of the old audit log 018 kept - every entry
of it is in the audit log), and the library's translations `014` and `015`
(the words of its new pieces and of the site settings). Until Apply the admin
panel's Site settings page says the update comes first and no page shows an
announcement; everything else works. After it: the admin panel's System →
Site settings sets the announcement - the IAM's one part of the family's site
settings: no maintenance mode, no page access -, shown above the sign-in,
account and legal pages. The release's `.htaccess` now gives the front
controller `/robots.txt` and `/sitemap.xml` (`extra["anotoki-deploy"].routes`):
after the deploy, `/robots.txt` answers text naming
`https://<the IAM's address>/sitemap.xml`, and the sitemap lists the five
legal, About and Support pages. The sites need nothing. It is no one-way
step: a build from before 027 still runs on the database after it. Apply 027
right after the upload, as every update; a deletion that falls due in between
takes the person out of the old copy too, so the Apply's backup keeps nobody
deleted.

### The family's library (anotoki/lib)

What the anotoki sites share lives in one library,
[irelevant25/anotoki-lib](https://github.com/irelevant25/anotoki-lib), with two
halves and one version: the PHP package `anotoki/lib` (in `backend/vendor/`,
committed and uploaded like the rest of `vendor/`) and the Angular package
`@anotoki/lib` (the release's tarball, in `frontend/package.json`). It holds
the migrations module - the engine, the gate that answers "not set up", the
status the pages ask, the Migrations page and the admin line -, the
translations module, both halves - the tables of the words and the library's
own words (a migration set of its own, `anotoki_translations`, applied after
the IAM's), the checks of a string, the bundle and the admin routes, the
command line's tools; the service, the switcher and the Translations and
Languages pages -, the admin tools the IAM takes, both halves - the error log,
the audit log, feedback (its form too) and backups (a migration set of its own, `anotoki_admin`,
applied before the IAM's), their routes and their pages, and the reporter of
the pages' own errors - and, in the Angular half, the family's UI kit: its
styles and palette, its components, the top bar and the frame, the admin
panel's frame, the legal pages, the footer, the site settings' pieces and the dates. The IAM is on 0.11.0, both halves (`composer.json`'s `^0.11.0`, the lock
on v0.11.0, and the 0.11.0 tarball; never 0.4.0, which fails on PHP 8.2). Both halves come from GitHub, never
from a copy on disk; the Composer package brings the library's migration files
and words with it (`vendor/anotoki/lib/backend/migrations`, `/backend/resources`),
which a release carries with the rest of `vendor/` and which are denied
to the web like it.

To update it (its `CHANGELOG.md` says what changed - while it is 0.x, a minor
version may break things), both halves together where both changed, on a
development machine:

```bash
cd backend
composer update anotoki/lib --prefer-dist   # within the constraint in composer.json ("^0.11.0"); change it first for 0.12
# frontend/package.json: "@anotoki/lib": the new release's .tgz URL, then
cd ../frontend
bun install                         # bun.lock
npm install --package-lock-only     # package-lock.json, so the two lock files agree
```

The Composer update changes `composer.lock`, `vendor/anotoki/lib` and
`vendor/composer/` - all of it goes with the change. Then the tests, the
builds and the upload as for any update.

### The sites' addresses

A fresh database registers the first-party applications with their development
addresses (the port table in [architecture.md](architecture.md)). On a real host
the installer replaces them with the production ones it is given, and removes
every loopback address either way - on a live IAM a `localhost` redirect URI
would hand a code to whatever listens on the visitor's own machine. Anything
after that - a new site, one a later migration seeded (above), a renamed
domain, a site left blank at install - is the admin panel's **Applications**
page: exact redirect URIs, post-logout URIs, web origins, which of its roles
may sign in (the roles themselves are fixed: ADMIN, EDITOR, EARLY_ACCESS,
MEMBER), whether the site requires signing in (sites pick a change up within
about a minute), and the access token lifetime.

### The words across an update

The pages' and the mails' words are rows in the database (migration 006 and
every migration with strings after it, and the anotoki library's own set
after the IAM's), so an update is still **upload, then Apply pending** - and
between the two, on an IAM whose tables are not there yet (one from before
006):

- The pages read the English compiled into the build, and the mails go out in
  English: `GET /api/translations/{code}` answers `503 translations_unavailable`
  (with `Retry-After: 60`) until the tables are there. That 503 is expected in
  that window, not a fault (and not `not_set_up`: the site is not sent to the
  installer's notice). It is the answer too while the database cannot be
  reached.
- Discovery and `/api/public/config` offer `en` and `sk`, and accounts keep
  their language.
- The admin panel's Translations and Languages pages say to apply the pending
  update first. The first navigation after Apply brings the words in, with no
  page load.

On an IAM that has the tables, an update with new strings waits the same way:
the pages read the strings there are, and a key a new page asks for that no
string has yet shows as the key until Apply - the library's own keys never
do (they have built-in words). So **Apply right after the upload**.

A migration never writes over a string reworded in the admin panel, nor does
the library's. Before a release that changes strings, **export every language**
(Admin panel → Translations → Export, one JSON file per language) and keep
the files: they are the owner's wording, and the export is what puts it back
if a release's rewording is not wanted. On a computer with the repository,
`php translations.php --import` checks and loads such a file into a
development database.

### Backups

The admin panel's **Backups** page (the anotoki library's, `docs/decisions.md`
2026-10-11) keeps copies of the whole database in `storage/backups/`, made
without pg_dump (COPY through PHP's PostgreSQL driver):

- **Daily**, by itself: the first time housekeeping runs on a UTC day (any
  sign-in, any site's refresh, the IAM's own pages), after the answer has gone
  - and not while an update waits to be applied. The newest 14 are kept.
- **Before every update**: Apply on the Migrations page (and `php
  migration.php`) makes one first and names it; it applies nothing when that
  backup cannot be made. The newest 5 are kept.
- **By hand**: Back up now, with a description; kept until deleted (marked old
  after 90 days).

Each is **encrypted** under a key derived from the master key
(`config/secret.local.php`) and holds every table - every account's password
hash, the two-factor secrets and signing keys (encrypted under the master key
besides), every hash of a session and a token, the audit log. Making one,
downloading one and deleting one ask for the administrator's password (or,
without one, a sign-in of the last 15 minutes) and are in the audit log; the
page names who made each. A download is a link good once, for a minute.
**Keep the master key with the downloaded backups** (off the server, as ever):
without it they open to nothing, and a backup made before the master key was
ever replaced opens only with the old one - keep that one too.

Since anotoki-lib 0.5.0 the library seals a plain backup under the
installation's key, and only a sealed or an encrypted one counts as made here.
The IAM's are all encrypted - the cipher is their seal - so nothing changes for
them; there are no plain ones to be restored offline only. Every file of the
backups is the PHP user's alone from the moment it is made (`0600`, the
folders `0700`): on a host where PHP runs as its own user, not the FTP
account's, the FTP account cannot read `storage/backups` either - **download a
backup from the Backups page** to take it off the server.

**The IAM never restores a backup in place** (restore is off, the page says
why): it would bring back revoked tokens and sessions, old passwords, removed
roles and deleted people. When the database itself is lost, a backup is put
back by hand and the record of deletions replayed onto it ("When the database
is lost", below). A backup's data is read on a computer with the
repository and the IAM's master key:

```bash
cd backend
php backup.php --from=<the folder the downloaded file is in> --key=<a copy of the live config/secret.local.php> --inspect=<id>
php backup.php --from=<...> --key=<...> --extract=<id> <a new folder>
```

`--extract` writes everything decrypted into the new folder - the manifest, the
SQL of every migration the database had applied, each table's rows as
PostgreSQL's COPY text - and says how to load it into an empty database (the
schema files in order, then `psql \copy`, parents first). That folder is the
database in the clear: delete it when done, and run it only on a computer of
your own you trust. `--inspect` reads the manifest alone - the tables and
their rows, who made it - decrypting no further than its first chunks, in
memory: nothing of the database is written anywhere (anotoki-lib 0.5.0). A
backup is never restored to bring an account back (above, "A database backup
is never restored...").

**Backups are kept until you delete them - nothing deletes one for its age**
(the owner's decision of 2026-10-11, `docs/decisions.md`): the daily and
before-update ones go only as newer ones of their kind replace them (14 and 5
kept), a manual one never by itself. So **a deleted account's data stays in
every backup made before its deletion** - its row, its hashes, its address,
its history - until that backup is deleted. They are encrypted under the master
key, and the IAM never restores one, so none brings an account back; delete
the old manual ones when they are no longer wanted.

The backups take room on the host - their sizes and the disk's free space are
on the page; one is refused (`no_space`) while the disk has less than twice the
last one free. Delete old manual ones there. Only files the library made
itself, by their names, are ever deleted; anything else put in
`storage/backups/` stays.

**Keep the record of deletions with the backups.** Every deletion asked for,
cancelled and done is a line of `storage/records/account-deletions.jsonl` -
ids and times, nothing of the person - kept beside the database, add-only
(`docs/decisions.md`, 2026-10-13). It is what brings the deletions back after a
restore by hand (below), so it must outlive the database: it stays with the
server's files, and **whenever you download a backup, download that file over
SFTP too** (its folder is readable by the FTP account; the web never serves
it). `php account-deletions.php --list --record=<a copy>` reads one.

### When the database is lost

The IAM restores nothing in place (the Backups page's restore is off, and stays
off - the owner's decision of 2026-10-13). A lost or broken database is put
back by hand, on a computer with the repository, PHP 8.2+ and `psql`:

1. **Gather** the newest backup (downloaded from the Backups page, or
   `storage/backups/` over SFTP where the FTP account may read it), a copy of
   the live `config/secret.local.php` (the master key it was made under), and
   `storage/records/account-deletions.jsonl` - from the server over SFTP, or
   the copy downloaded with the backups when the server's own is gone too.
2. **Read the backup out**, into a new folder of your own:

   ```bash
   cd backend
   php backup.php --from=<the backup's folder> --key=<the secret.local.php copy> --extract=<id> <a new folder>
   ```

   It prints the migrations the backup had applied and a `\copy` line for each
   table. **The backup's time** is `snapshot_at` in `<the new folder>/manifest.json`
   (the database's clock when it was made; `--inspect=<id>` shows when it was
   made, within a moment of it).
3. **Load it into an empty database** - a new one made in the hosting panel,
   or the old one emptied. In `psql`, connected to it, from the new folder:
   each `schema/<set>/<file>.sql` in the order printed (`\i ...`), then each
   `\copy` line as printed (parents first), then every sequence as
   `manifest.json`'s `sequences` says (`SELECT setval('<sequence>', <value>, true);`).
4. **Point a configuration at it**: on this computer, copies of the live
   `config/*.local.php` - `database.local.php` naming the restored database
   (reachable from here), `secret.local.php` the live master key, and
   `iam.local.php`, `mail.local.php` (the `file` driver is enough for this).
5. **Apply what the backup lacks**: `php migration.php` (it backs the restored
   database up first, into this computer's `storage/backups`). The replay
   refuses while one waits.
6. **Replay the record**, first as a dry run, then for real:

   ```bash
   php account-deletions.php --replay --since=<snapshot_at> --record=<the record's copy> --dry-run
   php account-deletions.php --replay --since=<snapshot_at> --record=<the record's copy>
   ```

   From an hour before the backup's time on, in the order the events happened
   (by their own times, whatever order their lines stand in), each account
   ends as its last event says: a deletion asked for since the backup is asked
   again with its own times (its thirty days keep their date), a cancellation
   is made, an account deleted since is deleted again now (with no second
   "deleted" mail); what the backup holds already is "already so". Then every
   session, refresh and personal token, remembered browser and mailed link or
   code is ended: everybody signs in again. It prints ids and times only, and
   what it changed is in the audit log and in the record's copy, as lines of
   their own (`"replayed": true`). Run again, it changes nothing.

   Its summary ends with the IAM's live administrators afterwards. **A
   `WARNING` that none is left** means the backup is older than every
   administrator who stays - one made since is not in it, and the record knows
   deletions, not roles. Mend it before the IAM goes live: an administrator
   whose deletion was asked again signs in (the IAM's own sign-in works for a
   deletion pending) and cancels it on the account page - or give a live
   account the IAM's ADMIN by hand, in `psql`:

   ```sql
   INSERT INTO user_roles (user_id, role_id)
   SELECT <the account's id>, r.id FROM application_roles r JOIN applications a ON a.id = r.application_id
    WHERE a.client_id = 'iam' AND r.name = 'ADMIN'
   ON CONFLICT DO NOTHING;
   ```
7. **Accounts made after the backup are not in it.** Their people sign up
   again; the replay keeps every id the record names from being issued again.
   Any other id issued since - a site's `sub` for somebody's data - must not go
   to somebody else either: move the id sequence past the highest `sub` the
   sites hold (`SELECT setval('users_id_seq', <that id>);`; each site's own
   database lists the subs it knows). Passwords, roles and settings changed
   since the backup are as they were then.
8. **Check**: `php migration.php --status` is up to date; every account the
   record says was deleted (`php account-deletions.php --list --record=...`) is
   a `deleted-{id}` placeholder, every deletion pending has its date (Admin
   panel -> Users, once live); `php account-deletions.php --replay ... --dry-run`
   says nothing more to do, and names at least one live administrator, with
   no warning.
9. **Put it back live**: the restored database where the host reaches it (if it
   was loaded elsewhere, moved with `pg_dump` / the hosting panel's import),
   the host's `config/database.local.php` naming it, and **the record's copy -
   with the replay's lines - uploaded over the host's
   `storage/records/account-deletions.jsonl`**. Delete the extracted folder:
   it is the database in the clear.

### The error log

The admin panel's **Error log** page reads `storage/logs/errors-YYYY-MM-DD.jsonl`
(UTC days): every failure the IAM answered (500, with the failures it was
thrown for), the answers worth seeing (every 5xx, 404, 405, 413, 415, 429),
PHP's warnings and fatal errors, and the pages' own errors (`source: client`:
what broke in a visitor's or an administrator's browser, scrubbed there and
again on the server - at most 20 an hour from one sender, 500 a day from the
visitors and as many from the people signed in) - grouped by kind, a chart by
day, each with its trace and every occurrence. It keeps 30 days and 64 MB, a
hundred lines of one kind a day (the rest counted). No address, cookie, header,
body, query string, token or password is in it - the IAM's own names of
secrets (a password field, a code, a two-factor secret, ...) taken out by the
library's redactor, its cookies and tokens before it, on every way in -; a
person is an account id at most. **Clear** empties it (everything, or the days
before today or before the last week) and is audited. PHP's own error log still
gets a line for every 500, its message redacted the same way.

## Day to day

| Task | Where |
| --- | --- |
| Give somebody a role on a site | Admin panel → Users → the person → Roles: the site's EDITOR opens its admin area, ADMIN all of it |
| Let every account use a members-only application | Tick its MEMBER: Admin panel → Applications → the application → Settings → Roles (every account holds MEMBER everywhere, migration 010) |
| Make a site members-only (or admins only) | Untick its MEMBER there - for admins only, its EDITOR and EARLY_ACCESS too; then give the people who should get in a role by hand (Users → the person → Roles): EARLY_ACCESS for a beta's or a test's people, who are neither editors nor administrators. The Members tab lists who holds a role beyond MEMBER, and who cannot sign in |
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
| Somebody wants their account deleted | They do it themselves: the account page → **Delete my account**, with their password (or, without one, right after signing in). It is closed at once - signed out everywhere, refused by every site - mailed, and deleted thirty days later unless they cancel it on the same page. Nobody can ask or cancel for them, an administrator included; the last administrator of the IAM must make another one first. Then the row stays as `deleted-{id}`, switched off, and nothing about it can be changed |
| See whose deletion is pending, and who was deleted | Admin panel → Users: an account's deletion date while it is pending; the time it was deleted, and its placeholder name, after |
| Somebody got a "New sign-in" mail they do not recognise | Change the password (the account page → Security, or "Forgot password" - a reset also forgets every browser the account knew); everywhere else is signed out with it. The account page → Sessions shows where the account is signed in |
| Somebody got a "Wrong codes for your anotoki account" mail | Five wrong authenticator codes paused the account's codes for fifteen minutes (migration 019). If it was not them, somebody got past the first step - the password, as a rule: change the password (the account page → Security; meanwhile they sign in with their password and a code by email, or a recovery code). The audit log has `account.authenticator_paused` with the address it came from; the person's page under Users lists the attempts (`bad_totp`, then `totp_paused`; `bad_email_code` and `email_code_paused` for codes by email guessed at) |
| Somebody's authenticator codes are refused "for 15 minutes" | The pause: it ends by itself fifteen minutes after the fifth wrong code. Meanwhile the sign-in page offers a code by email after the password - until five of those are wrong in the pause too - and a recovery code works; nothing to switch off. Resetting two-factor (Users → the person) and setting it up again with a new secret ends it too |
| See what went wrong | Admin panel → **Error log**: failures grouped by kind, each with its trace and every occurrence; Clear when they are dealt with |
| See who did what | Admin panel → **Audit log**: who, what, about what, from where - filtered by person, action, what it is about and dates (the address keeps the filters, so a link to a filtered log works) |
| Back the database up, or take a copy home | Admin panel → **Backups** → Back up now / Download (the password asked each time). A copy opens only with the master key - "Backups" above |
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
php translations.php --status              # the frontend's keys against the database's, how much of each language there is, the mails' keys against MAIL_KEYS
php translations.php --export sk [file]    # a language's own strings as flat JSON
php translations.php --import sk file      # and back in, checked as the admin panel checks a save
php backup.php --list                      # the database's backups in storage/backups (--create, --inspect=<id>, --extract=<id> <folder>)
php account-deletions.php --list           # the record of deletions asked, cancelled and done (storage/records; --record=<a copy>)
php account-deletions.php --replay --since=<the backup's time> [--record=<a copy>] [--dry-run]   # after a restore by hand ("When the database is lost")
php tests/run.php                          # every test, against throwaway databases
```

## When something goes wrong

| Symptom | Likely cause |
| --- | --- |
| Every request answers 503 `not_set_up`; the pages say "not set up yet" | The installer has not run, or a `config/*.local.php` is missing or wrong. On a development machine `setup.php` lists which. A file that "does not start with <?php" was written by hand without its `<?php` line or with a byte-order mark or a blank line before it (an editor's "UTF-8 with BOM") - it is never compiled, so even one with `declare(strict_types=1)` after such a start answers "not set up", never a 500; one that "prints N bytes of its own" has text after a closing tag: save it again as plain UTF-8 starting with `<?php`. |
| Every visitor seems to come from a few Cloudflare addresses (the limiter refuses people who did nothing; Sessions shows `104.x`, `172.6x.x` and the like) | `config/cloudflare.local.php` has an origin secret the zone does not send: the Transform Rule is missing, or its value differs ("Behind Cloudflare" above). Set the rule's value and the file's to the same, or remove the file. Or the file cannot be read - a byte-order mark, no `<?php` line, a parse error - or names no secret - an empty value, a misspelt `origin_secret` - or a folder stands in its place, and fails closed, every visitor their edge: save it again as plain UTF-8 starting with `<?php`, its `origin_secret` the zone's value. PHP's error log says which: the file passed over and failing closed, or holding no origin_secret and failing closed. |
| The pages say "The site is not available right now", on an installed IAM | A `config/*.local.php` is missing or wrong (uploaded over, or deleted) - the API answers 503 `not_set_up` with `installed: true` - or the database does not answer (`/api/site-status` says `unavailable`). Restore the file from your copy; the installer is gone and is not offered. The pages notice by themselves |
| Migrations says "Another database update is running" | Another apply holds the lock - another administrator, `php migration.php`, the installer. Wait a moment and look again; nothing was applied |
| Apply says "The backup before the update could not be made, so nothing was applied" | The backup it makes first failed: `storage/backups/` is not writable by PHP, the disk is too full, or the host's PHP lacks COPY (pdo_pgsql) or AES-256-GCM (openssl) - the Backups page's status says which. Mend that and Apply again |
| The Audit log page says to apply the pending update first | Migration 018 (and the library's admin set) waits: Admin panel → Migrations → Apply pending. The entries made meanwhile are kept in `storage/logs/audit-pending.jsonl` and go in then |
| "The active signing key cannot be decrypted" | The master key is not the one the key was made with. Restore the right `secret.local.php`; failing that, delete the rows of `signing_keys` (everybody signs in again) and re-enable two-factor for those who had it. |
| `setup.php` says "Already installed" | `storage/installed.lock` is there. It deletes itself if it can; otherwise delete `setup.php` and `config/setup.local.php` by hand. |
| A site says "invalid_redirect_uri" before any page | Its redirect URI is not registered exactly as it sends it (scheme, host, port, path, trailing slash) - or it was left blank at install. |
| A site's token endpoint call fails in the browser with a CORS error | The site's origin is not in the application's web origins. |
| A site accepts nobody's token | The site's configured issuer is not exactly the IAM's public URL, or its client id is wrong, or it cannot reach `/.well-known/jwks.json`. |
| "access_denied" for somebody who should get in | They hold no role there that may sign in: their MEMBER was taken away (give it back under Users → the person → Roles), or the site's MEMBER may not sign in (the site was members-only before 010, or unticked since) and they hold none of its other roles - tick MEMBER, or give them a role that may sign in. |
| Somebody signed up and is told "Waiting for approval" | Settings → New accounts need approval is on: approve them on Admin panel → Approvals (or their page under Users). A declined account is switched off: switch it on under Users, and it waits again |
| Somebody can sign in at the IAM but no site lets them in: "Your account is being deleted" | They asked for their account's deletion. They cancel it on the account page (the notice at its top), and sign in to the sites again |
| An administrator is refused by the admin panel: "This account is being deleted" | They asked for their own account's deletion: cancelling it on the account page gives the panel back. Meanwhile they do not count as an administrator - the last one who stays cannot be removed |
| Setting a first password, connecting Google, switching password sign-in on or off, or turning two-factor on asks to "sign in again" | Those changes - like deleting the account - ask for the password where the account signs in with one, and otherwise a sign-in of the last 15 minutes: a session left open somewhere must not be able to make that proof for itself, nor lock the owner out. Sign out, sign in again, and do it within 15 minutes |
| Nobody is mailed about new browsers | Expected for each account's first browser after migration 016 (and a new account's first); from the second on, a new browser is mailed - at most five an hour per account |
| A site that lets visitors in does not sign in somebody who is signed in at the IAM | The site has not taken the family's library's sign-in yet; or it is not under the hint's domain, or no site switched on is, so the hint is the IAM's host's alone ("The sign-in hint's domain" above - the error log says so, once a day); or the hint is switched off (`hint_cookie_name` null); or, in development, the site is opened as `127.0.0.1` instead of `localhost`. Each hint is tried once per site: signing in at the IAM again makes a new one |
| A site's admin area refuses its owner | They hold neither its ADMIN nor its EDITOR: Admin panel → Users → the person → Roles. An account made in the admin panel, or made the IAM's ADMIN there, gets no site's ADMIN by itself; the installer's administrator, `create-admin.php`'s, an imported one and every administrator there when 007 ran were given all of them. |
| Somebody no longer an administrator still administers a site | Taking the IAM's ADMIN away takes nothing else: remove their ADMIN (and EDITOR) on each site too - Admin panel → Users → the person → Roles. |
| Sign-in mail never arrives | `mail.local.php` still has the `file` driver (mail is in `storage/mail/`), or the sender is not a real mailbox on the hosted domain. |
| An announcement stopped going out half-way | Its page was closed: it is sent only while the page is open - open it (Announcements → it) and press Continue sending. Waiting "for the host's hourly allowance" is `hourly_limit` at work; it goes on by itself |
| Announcement mails "failed", or never arrive | The host refused them: its hourly limit (set `hourly_limit`), or the sender is not a real mailbox on the hosted domain. Each person's status is on the announcement's page, and the Email log has every mail |
| Somebody gets no announcements | They did not tick "Send me news" when they signed up (or signed up with Google from the sign-in page, where nobody is asked), they stopped the news (the link at the end of one, or the switch on their account page - the audit log says which: `account.news_mail`), their address is not confirmed, they wait for approval, or they are switched off |
| Google button missing | No `google.local.php`, or the switch is off (Settings). |
| "Google could not be reached" under the Google button | A press could not fetch Google's script: the browser blocks `accounts.google.com` (an extension, a network's filter) or is offline. The other ways in work; another press tries again. |
| Google's own button does not come after a press, or its window refuses | The page's origin is not an authorised JavaScript origin of the client, or the client id in `google.local.php` is wrong. |
| The pages are English only, with no language switcher, after an upload | A migration with the words is pending: `/api/translations/...` answers 503 `translations_unavailable`. Admin panel → Migrations → Apply pending. |
| A page shows a key (`login.title`) where a word should be | The key has no string in the database: a migration that adds it is pending, or its English row was removed by hand. `php translations.php --status` on a copy names it. |
