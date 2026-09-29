# Decisions

The choices somebody might reasonably question, and why they went the way they
did. Newest last.

## 2026-09-27 - A small OpenID Connect provider of our own, not Keycloak

The owner's idea was "something like Keycloak". Keycloak itself is a Java
server; the hosting every anotoki site runs on is FTP-only PHP + PostgreSQL with
no shell and no long-running processes, so it cannot run there, and it would be
far more machinery than five small sites need. The genshin site already had a
careful sign-in - confirmed addresses, emailed codes, Google, two-factor with
recovery codes, remembered devices, revocable sessions, a limiter - so the IAM
is that code moved out and put behind the protocol Keycloak speaks. Any OIDC
library can use it; the first-party sites use the two small SDKs in `sdk/`.

## 2026-09-27 - Authorization code + PKCE in the browser, bearer tokens to each site's API

Every site already had its own API on its own origin, and the genshin site
already sent bearer tokens. Keeping that shape meant one integration pattern for
all of them: the frontend does the code flow with PKCE (a public client, no
secret), the API verifies RS256 access tokens locally against the cached JWKS.
The alternative - a backend-for-frontend per site, holding tokens server-side
behind its own cookie - keeps tokens out of JavaScript entirely and is the
stronger design; it costs a session store and a CSRF defence in every site, and
it is a change a site can make later without the IAM changing at all.

## 2026-09-27 - Short access tokens, rotating refresh tokens, revocation at the refresh

The genshin site re-read its session row on every request, so signing out was
instant. Doing that across sites would put a call to the IAM in every request of
every site. Instead access tokens live 10 minutes and are verified locally;
refresh tokens rotate with reuse detection and die with the IAM session. Signing
out, disabling an account or taking a role away therefore reaches every site
within one token lifetime - and `userinfo` knows at once, for anything that must.

## 2026-09-27 - A minute of forgiveness for a reused refresh token

Two tabs of one browser refreshing at the same moment present the same token
twice. The SDK serialises refreshes across tabs with the Web Locks API, but a
browser without it, or a tab that was asleep, still races. Within 60 seconds of
a rotation, a spent token gets a sibling successor instead of ending the family;
after that, reuse is treated as theft. Only hashes are stored, so "hand out the
same successor again" is impossible - a sibling is the honest version of it.

## 2026-09-27 - Roles belong to applications and are granted in the IAM

Keycloak's "client roles". genshin's EDITOR means nothing to the piano site; a
token lists the roles for the application it is for and no others. The admin
console grants them. The genshin site's single `role` column became: ADMIN ->
genshin ADMIN and IAM ADMIN (those accounts already ran the account pages that
moved here), EDITOR -> genshin EDITOR, USER -> nothing.

## 2026-09-27 - Preferences stay with the sites

Theme, date format, the genshin site's per-area themes: each site keeps its own.
The IAM keeps only the language, for its own pages and the mail it sends, and
passes it as the `locale` claim so a site can start a newcomer in it.

## 2026-09-27 - The accounts moved with their ids

Every created_by, updated_by and user_id in the genshin site's databases names
people by the id of the old `users` table. The import keeps those ids (and moves
the sequences past them), so none of that had to change. `sub` is that id as a
string.

## 2026-09-27 - The genshin site's clean-up waits for a marker

Its migration 040 drops the only copy of the password hashes that exists until
the import has run, so it refuses to run until the import has left an
`iam_handover` table in that database. The marker is written after the import's
transaction commits, never before.

## 2026-09-27 - Master-key encryption for TOTP secrets and signing keys

The genshin table held TOTP secrets in the clear ("2FA here is protection
against a stolen password, not against a stolen database"). The IAM is now the
one thing every site trusts, so a database dump should be worth less: TOTP
secrets and private keys are AES-256-GCM under a key kept in a config file, not
the database. Losing that file means re-doing two-factor setups and rotating the
signing key - no account is lost.

## 2026-09-27 - The IAM's own pages use a cookie, with a custom-header CSRF guard

They are same-origin with the API, so the cookie (HttpOnly, SameSite=Lax) is the
simplest thing that works and keeps the session out of JavaScript. SameSite=Lax
does not stop a sibling subdomain - *.anotoki.eu is one "site" - so every write
also needs `X-IAM-Request`, which forces a preflight the API never answers for
another origin.

## 2026-09-27 - Hand-written API client in the frontend

The genshin site generates its Angular client from the PHP route table. The IAM
API is small and changes rarely; typed services written by hand next to
`docs/api.md` are less machinery than a copy of that generator.

## 2026-09-27 - Local "no login" mode lives in the sites, not here

The study apps must keep working on the owner's own computer with no IAM
running. That is each site's configuration (`auth mode none`, acting as the
owner's id), never something the IAM grants.

## 2026-09-28 - The CSRF guard names its exceptions

It used to exempt "everything outside /api/ and under /api/oidc/", read from the
raw path - and the router matches the percent-decoded one, so `/%61pi/...`
reached first-party routes with no header at all (found by the security
review). Now every write needs the header except the three protocol endpoints
named exactly, compared on the path the router sees (`routedPath()`).

## 2026-09-28 - An address proved on an account never confirmed takes it over clean

Anybody can register an address they do not hold, with a password of their own,
and wait: when the address's owner later signed in with Google, the account was
confirmed and the registrant's password still worked. Now, whenever the owner
of the address proves it on such an account - Google vouching for it, or a reset
link reaching the mailbox - everything the registrant set goes first
(`claimUnconfirmedAccount()`): password, two-factor, recovery codes, devices,
sessions, tokens, identities. The confirmation link itself is the registrant's
own path and leaves the account as it is.

## 2026-09-28 - A reset takes the account back completely, and two-factor holds for it

A reset used to end the sessions only, and signed the person in on the strength
of the mailbox alone. Personal tokens (a year, no second factor) and an attached
Google outlived it, so somebody who had held the account for a while kept a way
in; and with two-factor on, the mailbox was enough to get past it. Now a reset
ends personal tokens and outside identities too, and with two-factor on it asks
for a code before the link is spent or anything changes.

## 2026-09-28 - Authenticator codes work once, and every miss counts

A code was good for its whole ~90-second window, and misses counted against the
limiter only after a password: the emailed-code and Google paths, and the
account page's "turn off" and "new recovery codes", could be guessed at freely.
Now the last accepted step is kept per account (migration 004) and a code from
it or before is refused; every refusal of a code is a counted attempt, on every
path. The emailed code is also checked before the authenticator, so a wrong one
no longer tells a stranger whether the account has two-factor.

## 2026-09-28 - One sibling per spent refresh token

The minute of forgiveness gave every reuse within it a successor, so a copied
token replayed inside that minute started a chain reuse detection never saw.
Now a spent token may have one sibling (claimed by a conditional UPDATE,
migration 004); a further use within the minute is reuse like any other.

## 2026-09-28 - Plain http only for this computer

Redirect URIs and web origins may be http only for localhost, 127.0.0.1 and
[::1]. Anywhere else a code or a token travelling over http can be read on its
way; the development addresses are all loopback.

## 2026-09-28 - Configuration only from config/*.local.php, and required

Every anotoki project now keeps what belongs to one installation - the database
(and the legacy one), secrets, the public address, mail, Google - in
`config/*.local.php` and nowhere else. The IAM used to fall back: to a committed
database template (127.0.0.1, `postgres`, no password), to `http://localhost:4300`
as the issuer, to mail from `noreply@localhost`, to an `IAM_SECRET` variable. A
server that lost a file quietly became a different IAM - one signing tokens for
an address nobody chose. Now a missing file stops it: every request answers
`503 not_set_up`, the pages say so, the command-line tools refuse. Tunables that
are the same everywhere (lifetimes, the cookie's name) stay committed. The tests
point the loaders at their own temp folders through constants only code can
define, so there is no environment variable left to set a value from outside.

## 2026-09-28 - The installer is how every installation starts, development included

`setup.php` was the first-install page of the hosting; a development machine was
set up by hand (copy a template, generate a key on the command line). Now it is
the way in everywhere, in two modes. On this computer - a loopback connection, a
localhost Host header, no forwarding headers, all three because each alone can
be arranged from outside (a proxy on the server, a forged Host, a name rebound
to 127.0.0.1) - it needs no token, prefills development values, can be run again
to change a setting, shows what is saved but never a password or the key, and
keeps a blank password (only for the host and user it was saved for, so the
saved one is never sent somewhere new). A web page elsewhere cannot drive it:
the form carries a nonce it cannot read, and Origin and Sec-Fetch-Site are
checked as a second line. On a real host it keeps the token, the lock and the
self-delete. "Test connection" is a JSON POST to the same page under the same
proof, not a separate endpoint to protect. Its script is served by the page
itself, because the web root's policy allows no inline script.

## 2026-09-28 - Defaults are development; a real host's install takes the loopback addresses out

The seeded applications used to carry their production addresses (migration
002), with the development ones added by `php clients.php --dev` and the rule
"never a localhost URI in a migration". Every anotoki project now runs its dev
servers on one fixed port table, so a fresh database registers each site at
its development address - `{frontend}/auth/callback`, `{frontend}/`, the
origin - and works on the machine it was built on. The reason for the old rule
still holds - on a live IAM a localhost redirect URI hands a code to whatever
listens on the visitor's machine - so it moved to where production begins: the
real-host install asks for each site's address (placeholders only, never a
value) and removes every loopback redirect URI and origin of the seeded sites,
whether an address was given or not. Migration 002 was changed in place for
this, before anything was deployed anywhere; the one development database that
had applied it was brought forward by hand.

## 2026-09-28 - Components are always three files

Many of the IAM's components had inline templates and styles; the anotoki
projects now keep every component as `.ts`, `.html` and `.scss`, so templates
are formatted and read as HTML and a component's shape is the same everywhere.
Group styles became partials each page's own stylesheet `@use`s. The move was
checked by parsing every template before and after with Angular's own template
parser: the same trees, apart from two trailing spaces at the end of a line,
which the browser does not draw.
