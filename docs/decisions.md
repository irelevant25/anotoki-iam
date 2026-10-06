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
panel grants them. The genshin site's single `role` column became: ADMIN ->
genshin ADMIN and IAM ADMIN (those accounts already ran the account pages that
moved here), EDITOR -> genshin EDITOR, USER -> nothing. (Since 2026-10-01 an
old ADMIN becomes ADMIN in every application that has one - below.)

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
owner's id), never something the IAM grants. (Withdrawn 2026-10-01: the IAM is
the only way in - below.)

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

## 2026-09-30 - A site seeded after installs gets development addresses only on this computer

Migration 002 seeded the first sites before any IAM was installed, so every
installation met them in a fresh database, and a real host's install took their
loopback addresses out. The survey site came later: its migration (005) also
reaches IAMs that are installed already - through the Migrations page, one of
them live - with no install after it to take anything out. So it decides by
where the IAM itself is served from: its own `iam` entry's home, which the
installer sets to the IAM's public address every time it runs. On this computer
the site gets its development addresses and a card on the landing page, as 002
gave the others; anywhere else it gets neither, and the admin panel gives it
its https address. It is the rule the installer keeps - never a loopback
address on a live IAM, where a localhost redirect URI would hand a code to
whatever listens on the visitor's own machine. A fresh database counts as this
computer (002 has just seeded the IAM at `localhost:4300`), so a real host's
install then treats the survey site like the others.

005 also gives the site's ADMIN to the IAM's administrators of the moment - the
survey site's own decision (its `docs/decisions.md`): they could give it to
themselves in the admin panel anyway. It is the one migration that gives
anybody a role, and on a fresh install it gives none either: there the
migrations run before the accounts exist, and the admin panel gives the role.
(Since 2026-10-01 007 does the same for every site, and the installer's
administrator is made with ADMIN everywhere - below.)

## 2026-09-30 - The IAM's words and mails live in the database

The sign-in and account pages were two dictionaries compiled into the build
(`en.ts`, `sk.ts`), and the mails were English and Slovak written in
`api/mail.php`. Rewording a sentence meant a build and an upload, and a third
language meant code. Like the other anotoki sites, the words became rows
(migration 006: `languages`, `translation_keys`, `translations`), edited on the
admin panel's Translations and Languages pages. `sk.ts` is gone; the admin
panel stays English, written in its templates. Slovak speaks to the reader
informally ("ty"), as the IAM always has.

- **A last resort, and only that.** A build is uploaded before its migrations
  are applied, from the panel, by somebody who has to sign in first - so the
  sign-in page must read without the tables. `en.ts` keeps the English the
  pages show while no bundle is in memory, and `MAIL_FALLBACK` the mails'
  English whenever the database has nothing to give. Once a bundle has arrived,
  a key it lacks shows as the key: the owner wants to see a missing string, not
  have English hide it. `en.ts` stays the list of keys, so an unknown key is
  still a compile error, and a test holds both files to the seeded English.
- **A mail's link and code are put in by the code.** A message is always
  greeting, intro, the link or the code on a line of its own, validity, ignore;
  no string holds the link, and there is no placeholder for it, so no rewording
  can drop it or move it. Which placeholders a mail string has is `MAIL_KEYS`,
  not the stored English.
- **No address in a mail's words.** Whoever can edit strings could otherwise
  make the IAM send its own phishing mail: a scheme, `www.` or an `@` is
  refused. A bare domain is not caught; the audit log is.
- **The audit log keeps a changed mail string whole**, old and new, up to the
  400 characters the panel lets a mail string be - a sentence added at the
  end of a reset mail must not be the part it leaves out. Page strings are
  logged by key.
- **A mail string is bounded in bytes as well** (900, less 400 for every
  `{username}` in a greeting): it goes out as one line, and a line of mail may
  be at most 998 bytes, which 400 characters of Japanese or Thai would pass.
- **Discovery and `/api/public/config` answer `en`, `sk` when the database is
  out of reach**, and do not remember it. They read nothing else from the
  database and answered without it before; they must not fail now on the one
  thing they ask it.
- **A regional code is answered by its base language**, in the bundle only:
  `sk-sk` gets `sk`'s very answer, so a Slovak browser's first visit is one
  request. What discovery offers and what `ui_locales` picks stay whole codes
  and first subtags.
- **Registration sends the preferred language**, not the one on the page:
  `preferred(config.languages)`, the reader's wish settled against what the
  server offers. Between an upload and "Apply pending" the page reads English,
  and an account made then would otherwise be English for good.
- **A new language starts hidden.** The Languages page adds it switched off,
  so nobody is offered a language that reads all in English; its strings are
  written, then it is offered. (The API offers it at once when `enabled` is left
  out, as before there was a choice.) A switched-off language keeps its strings
  and its accounts; its readers get English, and `locale: "en"`, until it is
  back. English cannot be switched off or deleted, and a language the
  migrations write strings for cannot be deleted - the next migration would
  fail on it.
- **The account page's Overview card stays** when there is no language to
  choose (one on offer, or none known yet): it also holds the appearance
  setting, and is then headed "Appearance". A language chosen there is fetched
  before the page switches, English too; when the account refuses it the page
  goes back to the language it was shown in, from memory, with no request.

## 2026-10-01 - "Test connection" answers in the family's words

Every anotoki site has a setup page, and the owner saw the IAM's answer a
database test differently from the survey's: the PostgreSQL version, counts of
migrations and accounts, a warning where the survey said plainly what Save
would do. One wording for the family now, the survey's, word for word:

- `Connected to the database "<name>": it is empty - Save makes the tables.` /
  `... N update(s) to apply - Save applies them.` / `... it is up to date.`
- `Connected to the server. The database "<name>" does not exist yet - Save creates it.`
- `Connected to the server, but there is no database "<name>" and this user may not create one. Make it in the hosting panel first.`
- `The server is there, but "<user>" cannot open the database "<name>": <reason>`
- `Cannot connect: <reason>`

The database is tried first (hosting often lets a user into its own database
only), the server's `postgres` database second - to tell a database that is
missing from one that is closed to this user, and whether the user may create
it (`rolcreatedb` or `rolsuper`). The IAM could make a missing database before
(Save calls `ensureDatabase()` and carries on when the user may not), so the
answer only had to say so truthfully: "Save creates it" is promised only when
the user may. A missing database is no longer a warning: it is either what
Save will do (good) or something to do first (bad). The old accounts
database's own test keeps its counts - nothing else in the family has one.

## 2026-10-01 - Every site open to every account, ADMIN and EDITOR in each

The owner, making the family work one way: "Every project should be available
for every user not just admin or editor", and "the admin panels only for
admins and editors with different rights". Japanese Academy, Piano Academy and
the build analyzer had been `members` sites with a MEMBER role ("may use the
app") and an ADMIN that was "the owner"; genshin and the survey were already
open, with ADMIN and EDITOR. Migration 007 makes them all one shape:

- every first-party site's `access` is `everyone` - whether a visitor must sign
  in at all is the site's own rule, not the IAM's;
- MEMBER goes from the three sites, and what was granted of it with it - an
  invitation to use a site that everybody may now use says nothing any more;
- EDITOR joins ADMIN there, in the split the owner chose: EDITOR rewords the
  site's strings (the analyzer's also runs the syncs); ADMIN does all that and
  adds and deletes languages, applies database updates, switches sources on
  and off and deletes a person's data. Genshin and the survey keep their split
  and their words.

Only what is there is touched: a site deleted in the admin panel stays
deleted, and an ADMIN description reworded there keeps its words (002's words
are the only ones replaced). The `members` rule itself stays in the IAM, for
an application the admin panel makes members-only.

MEMBER came back, for every account, with migration 010 (2026-10-05, below);
the `members` rule became "roles that may sign in".

## 2026-10-01 - The IAM's administrator administers every site

The owner: "When IAM is setup, the admin account from setup should have admin
role to all applications." A freshly installed IAM made its administrator ADMIN
of the IAM only, so the owner signed in to the survey site and found its admin
area closed to them; they could have given themselves each role in the admin
panel, which is exactly why holding them from the start costs nothing. So
ADMIN in every application that has an ADMIN role goes to:

- the administrator `createFirstAdmin()` makes - setup.php's new
  administrator and `create-admin.php`'s;
- an ADMIN brought over from the genshin site (`legacy_import.php`), who used
  to get genshin's and the IAM's;
- every administrator of the IAM when 007 runs (005's pattern for the survey,
  now for every application with an ADMIN role, one registered in the admin
  panel included).

It is a grant, not a rule: an administrator made later from the admin panel
gets the IAM's ADMIN only and is given the sites' there, and a site's ADMIN can
be taken away again like any role. Changing which roles anybody is given was a
permission decision, so it waited for the owner's explicit approval
(2026-10-02) before it was written.

## 2026-10-01 - The IAM is the only way in

The owner: "There is no need from project to support no iam alternative/version
of the projects. The IAM should be the only option." The study apps' local mode
with sign-in switched off (2026-09-27, above) goes from each of them; a site's
own tests sign in through a stand-in IAM instead. Nothing in the IAM changes:
that mode never was the IAM's.

## 2026-10-04 - The family's one top bar, on the account pages and the admin panel

The anotoki sites share one top bar (the survey site's `layouts/top-bar` is
the reference): brand, the site's sections as links, language, theme, the
person's menu, in one row that folds into a menu button on a phone. The IAM's
two rows - a bar with the brand, the theme and the person's menu, and the
account pages' navigation under it (a side list, a strip on a phone) - become
that one bar. What the IAM decides for itself:

- **The account pages are the bar's links**, and the family's "Your anotoki
  account" entry is left out of the person's menu: this site is that account,
  so the entry would only repeat Overview. In the admin panel the menu's way
  out is "Back to your account" instead (English, as the panel is).
- **The admin panel keeps its side navigation** under the bar: eleven
  sections are a list, not a row of links. Its bar has no links and no
  language; it reads English.
- **The sign-in pages keep their own night-sky frame** - nobody signed in,
  nothing for a bar to hold.
- **A language chosen in the bar is the account's**, as the overview's select
  makes it: left to the browser alone, the account's language would take the
  page back at the next load.
- **The fold is measured**: 1040 px - the row needs 1010 px in English and
  1005 in Slovak, plus a desktop scrollbar a media query counts. Above it only
  the person's name gives way.
- **Tab leaves a menu from its button.** The old bar let Tab move on by
  itself; with no zone the menu went only at the next render, so the focus
  landed on the next item of a menu about to vanish and then on `<body>`.
  Tab and Shift+Tab now close the menu with the focus on its button, in the
  bar's menus and the language button's alike.
- **One language save at a time, the latest choice last.** Each choice in the
  bar used to start its own save; two in flight (en, sk, en picked quickly)
  could be handled in either order and leave the account - the next visit,
  the mails - on a language the page no longer showed. A choice made while a
  save is on its way now waits for it, only the latest one is saved, and not
  at all when the account has it by then. Chained rather than cancelled: an
  abandoned request may still reach the server, last.
- **The language button's name holds its code**: "Language: Slovenčina (SK)"
  for a button that shows "SK" (`lang.button`, migration 008). The words on a
  button belong in its name (WCAG 2.5.3, label in name), or "click SK" finds
  nothing by voice. A family-wide choice: the survey site's bar, the
  reference, names its button the same way.
- **The admin panel's role field suggests EDITOR**, not MEMBER: since 007 no
  first-party site asks for MEMBER.

## 2026-10-04 - "Admin panel", in every anotoki site

The owner: every anotoki site names its admin area the same - "admin panel",
not "admin" nor the IAM's "admin console". The IAM's says so everywhere: the
person's menu and the account overview's card ("Admin panel", Slovak
"Administrátorský panel"), the bar's brand ("anotoki admin panel", its link
named "anotoki admin panel - home"), the tab's title ("Users · anotoki admin
panel"), the side navigation's name and the docs. The two strings were 006's,
applied on installed IAMs, so migration 009 rewords them - only where they
still hold 006's words: one reworded on the Translations page is the owner's.
The Migrations page, the model the other sites' Migrations pages follow, is
unchanged.

## 2026-10-05 - MEMBER for everyone, roles that may sign in, sign-in required per site

The owner: "I would like to be able to setup if log in is required for a
specific project and also what roles can log in to a specific project. The
default role for users should be member. Every user needs to have a role."
And the sites that do not require signing in let visitors in, with accounts of
their own on each site (the sites' business, not the IAM's). This takes back
007's "no MEMBER". Migration 010:

- **MEMBER ("Uses the site") in every application** - the sites, the owner's
  own, the IAM's entry - **and every account holds it in every one**. Kept by
  the database, not by PHP: a trigger on `users` gives a new account MEMBER
  everywhere, one on `applications` gives a new application MEMBER and every
  account. Six ways make an account or an application (registration, Google,
  the installer's and create-admin.php's administrator, the legacy import, the
  admin panel twice), and the tests' own fixtures besides; a missed one would
  now be a lock-out. Both triggers take one transaction-scoped advisory lock
  (4260918), so an account and an application made at the same moment still
  meet. MEMBER is never removed from an application nor renamed (the admin
  API refuses); taking it from a person is allowed - that is how somebody is
  blocked from one site.
- **Roles that may sign in** (`application_roles.may_sign_in`, default yes): a
  sign-in to a site - authorize, the sign-in page's accept, the code exchange,
  every refresh, a personal token - is refused (`403 access_denied`, "This
  account cannot use {name}", the words and pages that were there) unless the
  account holds a role there that may sign in. The IAM's own entry is never
  asked: its global Settings -> Signing in stays its pages' only gate. A site
  switched on keeps at least one role that may sign in; nobody able to is what
  switching it off is for. A body that leaves `may_sign_in` out keeps what each
  role has, so a script or an older panel cannot change who signs in by
  accident. A change of who may sign in, or of the switch below, is audited on
  its own (`application.sign_in_changed`, before and after); a new
  application's entry says what it starts with. Two saves of one application
  run one after the other (the save locks its row first), so together they
  cannot leave a site switched on with no role that may sign in.
- **Sign-in required, per application** (`applications.login_required`):
  genshin and the build analyzer off (visitors welcome, as today), every other
  site on, a new application on; the IAM's entry has none. The sites read it
  from the IAM's public contract, `GET /api/public/applications/{client_id}`
  (`{client_id, name, login_required}`, cacheable for a minute; 404 for an
  unknown, switched-off or system client id), through the PHP SDK's
  `anotoki_iam_login_required()` (1.1.0), which fails closed: a 404 is
  "required", and an IAM out of reach leaves the last answer or "required".
  The contract confirms to anybody who knows a client id that the site exists
  and its name - accepted: the sign-in page says as much to anybody who
  starts a sign-in there.
- **Nothing changes for anybody on the day it runs.** `access` is carried
  over: an `everyone` application lets every account in (MEMBER may sign in,
  and everybody holds it); a non-system `members` one lets in what it did
  (its MEMBER may not sign in, its other roles may). A MEMBER somebody held
  there was a way in, so it is renamed INVITED ("Was MEMBER before
  2026-10-05", INVITED_2 if taken) with its holders, and a fresh MEMBER goes
  beside it - 010 never stops for it. A `members` application left with no
  role that may sign in let nobody in, and is switched off. Tested on a
  database built through 009 by comparing who gets in where, account by
  account, before and after.
- **The `access` column stays**, unread once 010 is applied - but it is not
  a way back. **Once 010 is applied, the build before it must never go on
  the host again: fix forward.** That build reads `access`, and with every
  account holding MEMBER everywhere it would let every account into a
  members-only application and let back in anybody blocked by having MEMBER
  taken away (it ignores `may_sign_in`) - at authorize, every refresh, the
  code exchange and personal tokens alike. Holding MEMBER everywhere cannot
  be said in the old rule, so no change to the data makes that build fail
  closed. (A way back would first have to switch off every application
  whose MEMBER may not sign in, and would still let in everybody blocked by
  having MEMBER taken away.) While 010 is pending, `applicationAccessRefusal()` keeps the rule before it on that
  column (`signInRulesReady()`, a catalog query asked every time, never
  memoised: the admin panel's Apply runs in the same process as the requests
  after it), and the admin panel's application routes answer `503
  sign_in_rules_unavailable`, pointing at the Migrations page; the users
  routes keep working. **A later migration drops the column and that
  fallback, once both IAMs (this computer's and the host's) have run 010.**
- MEMBER now rides in every token's `roles`. No site needs it or refuses it;
  a site treats it as an ordinary signed-in person. Taking somebody's MEMBER
  away makes the IAM refuse their next sign-in and refresh, while the access
  token they hold verifies until it expires; a site that does not require
  signing in still lets them in as a visitor - blocking stops signing in, not
  visiting.
- **The admin panel lists who is blocked**, by one rule (`blockedFromSql()`,
  the users list's `blocked_in`, an application's Members tab, the role
  editor's warning in `sign-in-check.ts`): an account holding no role there
  that may sign in, whose MEMBER was taken away or which holds another role
  there. An account holding just MEMBER where MEMBER may not sign in was never
  invited, and is not "blocked" - or every ordinary account would be, on every
  members-only site, as the account page already leaves such a site out. The
  Members tab lists who holds a role beyond MEMBER, and the users filter by an
  application alone means the same: MEMBER, which everybody holds, would list
  everybody.

## 2026-10-05 - Public clients only, the same roles everywhere, no landing page settings

The owner, the same day: "Remove Landing page settings in applications of iam
admin console. Also, delete an option for adding a new role in applications
of iam admin console. All roles are static. Also remove Signing in Type option
in applications of iam admin console. It will be public only." Migration 011:

- **Public clients only.** Every application names itself with `client_id`
  and proves each sign-in with PKCE, which authorize now asks of every client
  whatever the row says. There are no client secrets: 011 makes every
  application public and clears `secret_hash`; the token and revocation
  endpoints refuse a request carrying a secret (`client_secret`, even empty,
  or HTTP Basic) with `401 invalid_client` "Public clients only - send
  client_id and the PKCE code_verifier, no secret", rather than reading half
  of it; discovery says `["none"]`; the admin panel has no Type, no secret
  and no "Generate secret" (the route is gone). No application on the owner's
  IAM was confidential; one elsewhere must use PKCE, as every anotoki site does.
- **Static roles.** Every site has ADMIN, EDITOR and MEMBER, the IAM's own
  entry ADMIN and MEMBER. 011's trigger (replacing 010's, same name, same
  lock) gives a new application all three, in the family's generic words
  (ADMIN "Everything in the site's admin panel", EDITOR "Edits the site's
  content in its admin panel", MEMBER "Uses the site"), MEMBER to every
  account and the others to nobody; 011 adds a missing ADMIN or EDITOR to an
  application the same way. Adding a role gives it to nobody: who holds one
  stays the owner's decision, made under Users. A role made before 011 (an
  owner's READER, 010's INVITED) stays, with its holders, read-only; a role
  reworded before keeps its words. The admin API refuses adding, removing,
  renaming or rewording a role; a save names each role and may change only
  whether it may sign in - one list of the roles with their ticks, not a role
  editor beside a checklist of the same roles.
- **No landing page settings.** The panel no longer shows or saves "Show on
  the landing page" and the order; a new application goes last. The public
  `GET /api/applications` lists every application that is switched on, but
  the IAM itself - the same five sites today. The anotoki.eu landing page
  keeps its own list (only its auditor compares).
- `type`, `secret_hash` and `show_on_landing` stay in the database, unread
  (not a way back: as with 010, no build from before it goes on the host
  again); the migration that drops 010's `access` drops them too.
  `sort_order` stays for good: it is the order of the list (a new
  application goes last), only no longer set in the panel.

## 2026-10-06 - The family's visitor counter, without the query string

The owner asked for Umami - the family's own, at `umami.anotoki.eu` - on every
anotoki site, the IAM included. `frontend/src/index.html` loads its script,
and the policy (`frontend/scripts/prepare-deploy.mjs`) names its origin beside
Google's, in `script-src` and `connect-src`. The counter sets no cookie,
stores nothing in the browser, and sends its reports without credentials (the
script's own request carries no cookie of the IAM's: its one cookie is
host-only).

- **No query string, no fragment** (`data-exclude-search="true"`,
  `data-exclude-hash="true"`): a confirm or reset link carries its token in
  the query, the script may read the address before the app has run, and a
  counter that kept the tokens would hold a way into somebody's account. The
  sites leave them out too: their sign-in's `code` arrives the same way, on
  the callback. What still goes: the path (an administrator's
  `/admin/users/{id}` among them), the title, the screen size, the language,
  and the referrer from another site without its query.
- **`async`, not the snippet's `defer`**: a deferred script and the app's own
  module run in document order, so the app would wait for the counter - and
  every sign-in of the family with it, for as long as a slow or unreachable
  `umami.anotoki.eu` takes to give up.
- **`prepare-deploy.mjs` refuses a build without the three**: the snippet
  Umami's dashboard hands out has none of them, and pasted again (a new id, a
  moved counter) it would send every reset token to the counter.
- **The price, accepted**: the script runs inside the IAM's own origin, on the
  sign-in pages too, so whoever controls what `umami.anotoki.eu` serves (its
  server, its host, the Cloudflare account in front of it) could act as anybody
  who opens an IAM page. The IAM trusts the family's counter that far. Keeping
  a copy of the tracker in `public/` (the counter would then receive reports
  and send no code), or pinning the file with an `integrity` hash, would take
  the trust back; neither is done.

## 2026-10-06 - New accounts may wait for an administrator's approval

The owner: "I would like to be able to turn on/off manual approvement of
registration per project/application or globally" - and then, before it was
done: "registration per project does not make any sense because registration
and accounts are shared across every projects so the global settings will be
enough". So one switch for the whole IAM, Settings -> **New accounts need
approval** (`registration_approval`, migration 012), off by default:

- **On, an account made by signing up waits** (`users.approved` false): the
  registration form and a Google sign-up, the only two ways a person makes an
  account for themselves. It signs in nowhere until an administrator of the
  IAM approves it - the IAM's own pages included - and every way in says so
  with the same refusal, `403 approval_pending`: password, emailed code,
  Google, the confirmation link, a reset link (`signInRefusal()`), and
  `applicationAccessRefusal()` for anything issued, should such an account
  ever hold anything. Its address is still confirmed by its link, and a
  reset still sets its password: both are the mailbox's proof, and neither is
  a way in. Nobody is exempt - not even an IAM administrator, as the switch
  for signing in exempts them: an account that waits was made by signing up,
  and its approval comes from an administrator's own session.
- **Accounts an administrator makes never wait**: the admin panel's, the
  installer's, `create-admin.php`'s, the legacy import's - the column's
  default is true, and only the two sign-up paths write false. Every account
  there was when 012 ran is approved.
- **Approve or decline** on the admin panel's Approvals page (or the
  person's page under Users); the dashboard counts who waits. Approving mails
  the person - the sign-in page's link, in their language - once their
  address is confirmed; one approved before that is signed in by its own
  confirmation link and needs no mail. Declining switches the account off:
  nothing is deleted, as nothing in the IAM is, the address stays taken (so a
  stranger declined cannot simply sign up again), and switching it on puts it
  back among the waiting - a declined account is not approved while switched
  off, so nobody is mailed "approved" who cannot sign in. Nobody is mailed a
  no.
- **Never a decline of an address nobody has confirmed** (`409
  not_confirmed`; found by the security review): anybody can register an
  address that is not theirs, and its owner takes such an account back with a
  reset link or Google (rule 13) - which nothing does for an account switched
  off, so a declined stranger's registration would keep the owner out of
  every site for good. Unconfirmed, the account cannot sign in anyway; once
  its owner has claimed it, it waits, and is judged like any other.
- **Google's word confirms an address that waits**: Google vouching for the
  address of an account that waits attaches Google and confirms the address
  before the `approval_pending` answer, as a confirmation link would - or the
  approval could never be mailed to it.
- **An account that waits is not made the IAM's administrator** (`422
  not_approved`): it signs in nowhere and administers nothing, yet the
  last-admin rule would count it as an administrator left.
- **Switching it off lets in everybody still waiting and switched on**, in the
  same transaction, and the log says how many (`approvals.released`): "off"
  means nobody waits, and turning it on again is about accounts made from then
  on - somebody let in meanwhile is not locked out afresh. A declined account
  stays declined. The waiting are not mailed then: among them may be the very
  strangers the switch was for. A sign-up reads the switch under a share lock
  on its row, in the transaction that makes the account, so it and a save
  switching it off go one after the other: none is made waiting just after
  the release.
- **Not a way back**: once 012 is applied, the build before it must never go
  on the host again. That build reads no `approved`, and would let every
  waiting account in. Fix forward - or decide on everybody waiting first.
- **The sites see nothing new.** A person waiting never gets as far as a
  site's code; going back from the sign-in page is a cancelled sign-in. No
  contract, SDK or site changes.

A switch per site (an account made while a site's switch is on waiting for
that site alone, shown to the administrators once the person asks for it) was
built first and taken out before release at the owner's word: an account is
one for every site, and a person signs up for the account, not for a site.
Keeping somebody off one site stays what it was - taking their MEMBER there.
