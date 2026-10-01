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
   - the **database**, with **Test connection** to check it;
   - the **accounts**: **bring them over from `users`** (its own **Test
     connection** counts them and their admins), or start with a new
     administrator;
   - **the sites**: each one's production address (the examples in the fields
     are placeholders). They replace the development addresses the database
     was seeded with - `/auth/callback`, `/` and the origin at each; a site left
     blank has no address until the admin console gives it one;
   - the sender mailboxes and, optionally, the Google client id.
5. It runs the migrations, imports the accounts (or makes the admin), puts the
   sites' addresses in place, writes `database.local.php`, `secret.local.php`,
   `iam.local.php`, `mail.local.php` and `google.local.php`, writes
   `storage/installed.lock`, and deletes itself and its token. The migrations
   run before the accounts arrive, so the survey site's ADMIN - which 005 gives
   to the administrators an IAM already has - is nobody's yet: give it in the
   admin console (Users → the person → Roles).
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
attacker's failures lock out everybody else there, and the admin console shows
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
2. Check the admin console: the accounts, and the roles (genshin ADMIN/EDITOR,
   IAM ADMIN for the site's admins).
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
leaves them out), then **admin console → Migrations → Apply pending**. There is
no shell; the button runs the same engine as `php migration.php`.

A migration that seeds a site (005, the survey site) leaves it on the live IAM
without an address or a card on the landing page: a `localhost` address there
would hand codes to whatever listens on the visitor's machine. So in
**Admin → Applications → the site**, with `{site}` its https address, add the
redirect URI `{site}/auth/callback`, the logout redirect URI `{site}/`, the web
origin and the home page `{site}`, and switch on **Show on the landing page**;
then give its roles to whom they belong (Users → the person → Roles) - 005
itself gives the survey site's ADMIN to the IAM's administrators. A site made
by hand before its migration arrived keeps what it has.

### The sites' addresses

A fresh database registers the first-party applications with their development
addresses (the port table in [architecture.md](architecture.md)). On a real host
the installer replaces them with the production ones it is given, and removes
every loopback address either way - on a live IAM a `localhost` redirect URI
would hand a code to whatever listens on the visitor's own machine. Anything
after that - a new site, one a later migration seeded (above), a renamed
domain, a site left blank at install - is the admin console's **Applications**
page: exact redirect URIs, post-logout URIs, web origins, roles, the access
policy (`everyone` or `members`) and the access token lifetime.

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
- The admin console's Translations and Languages pages say to apply the pending
  update first. The first navigation after Apply brings the words in, with no
  page load.

A migration never writes over a string reworded in the console. Before a
release that changes strings, **export the live strings** (Admin → Translations
→ Export, one JSON file per language) and keep them: they are the owner's
wording, and the export is what puts it back if a release's rewording is not
wanted. On a computer with the repository, `php translations.php --import`
checks and loads such a file into a development database.

## Day to day

| Task | Where |
| --- | --- |
| Give somebody a role on a site | Admin → Users → the person → Roles |
| Let everyone use a members-only site | Admin → Applications → the site → Access: everyone |
| Somebody is locked out (lost password) | They use "Forgot password"; or Admin → Users → Set password (they must choose a new one at the next sign-in) |
| Somebody lost their phone and recovery codes | Admin → Users → Reset two-factor |
| End a session that should not be there | Admin → Sessions, or the person's own account page |
| Switch signing in off for everybody but IAM admins | Admin → Settings → Signing in |
| Rotate the signing key | Admin → Keys → Rotate. Tokens signed by the old key keep working until they expire; sites refetch the key set by themselves. Remove the retired key after a day. |
| A script needs to call a site's API | The account page → Tokens for scripts |
| Reword a sentence of the pages or a mail | Admin → Translations: edit, Save. Readers get it at their next page load; the change is in the audit log |
| Add a language | Admin → Languages → Add (it starts hidden), write its strings on Translations (or import a JSON file), then switch it on. Until a string is written the language reads it in English |
| Stop offering a language | Admin → Languages → switch it off: its strings and accounts stay, its readers get English. Deleting it sends its accounts to English and removes its strings; English and Slovak cannot be deleted |

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
php create-admin.php you@example.com       # a first admin on an empty IAM, instead
php clients.php --dev                      # put the sites' development addresses back
php reset-password.php <email> [password] [--clear-2fa]
php translations.php --status              # the frontend's keys against the database's, and how much of each language there is
php translations.php --export sk [file]    # a language's own strings as flat JSON
php translations.php --import sk file      # and back in, checked as the admin console checks a save
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
| "access_denied" for somebody who should get in | The application is members-only and they hold none of its roles. |
| Sign-in mail never arrives | `mail.local.php` still has the `file` driver (mail is in `storage/mail/`), or the sender is not a real mailbox on the hosted domain. |
| Google button missing | No `google.local.php`, the switch is off, or the page's origin is not an authorised JavaScript origin of the client. |
| The pages are English only, with no language switcher, after an upload | A migration with the words is pending: `/api/translations/...` answers 503 `translations_unavailable`. Admin → Migrations → Apply pending. |
| A page shows a key (`login.title`) where a word should be | The key has no string in the database: a migration that adds it is pending, or its English row was removed by hand. `php translations.php --status` on a copy names it. |
