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
(Reversed 2026-10-06: the theme, the dates and the clock are the account's
too, and every site follows them - below.)

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
  again); the migration that drops 010's `access` drops them too - and so it
  did: migration 027, with the owner's answer of 2026-10-25 (below).
  `sort_order` stays for good: it is the order of the list (a new
  application goes last), only no longer set in the panel.

## 2026-10-06 - The family's visitor counter, without the query string

The owner asked for Umami - the family's own, at `umami.anotoki.eu` - on every
anotoki site, the IAM included. `frontend/src/index.html` loads its script,
and the policy (`frontend/scripts/prepare-deploy.mjs`) names its origin beside
Google's, in `script-src` and `connect-src`. The counter sets no cookie,
stores nothing in the browser, and sends its reports without credentials (the
script's own request carries no cookie of the IAM's that opens anything: the
session cookie is host-only. Since the owner's decision of 2026-10-07, below,
the sign-in hint goes with it - a random value that says nothing of the
person and opens nothing).

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

## 2026-10-06 - Announcements: news an administrator mails to the people of the sites

The owner: "Sending an email to all user with a specific role (I can select
more and like that can send email to pretty much everyone)", from "some
pretty template what user will see when they receive an email. I will just
fill or change some template text" - a new version of a site, a new site, a
survey about one; "also with option to send an email to a specific user";
from a chosen address - "support@anotoki.eu, noreply@anotoki.eu. Those will be
predefined with an option to setup a new"; and "those sended emails store so
I can check what and when I have send in the past". Asked, the owner chose a
version of the words per language - "but it should not be automatic. I will
send it manually via IAM" - an unsubscribe link with a switch on the account
page, and links to the anotoki sites only. Migration 013 and the admin
panel's **Announcements**:

- **Written, never sent by itself.** A template's words (mail strings, so
  they are reworded on the Translations page like any mail's) fill a draft in
  every language on offer, the chosen site's name for `{project}`; the
  administrator changes them, a language at a time, and presses Send. A
  language left empty has no version: its readers get the English one, which
  is needed. A version has all of its parts or none.
- **Text, in one fixed layout.** The words are never HTML: the server escapes
  every one into the IAM's layout (plain text and HTML in one mail), with one
  button - the link - and two endings of its own (why the person gets it, the
  link that stops the news).
- **Links to the anotoki sites only**: the button's link, and any link or mail
  address written in the words, at the IAM's host, a host an application is
  registered with, or the domain the IAM sends mail from and the names under
  it. Like the mail strings' rule, a bare name ("example.com") is not looked
  for: the obvious is stopped, and what was sent is kept whole.
- **Who.** Everybody, the holders of chosen roles, chosen people - in any mix -
  and of them only who can be written to: switched on, the address confirmed
  (an unconfirmed one may be anybody's), approved, the news on. A person chosen
  by name who stopped the news does not get it either: "no more news" is a
  promise.
- **From whom.** The installation's own senders (config/mail.local.php, which
  the installer writes and the panel does not change), and senders added in
  the panel - only at the domain the IAM sends from, because the host sends as
  its own mailboxes and anything else is taken for a forgery. A sender's name
  is letters, digits, spaces and a few marks: nothing a From header gives a
  meaning to.
- **Sent by the page, in batches.** The host has no cron and a request has half
  a minute, so the announcement's own page asks for batch after batch while it
  is open; it can be paused, continued, and stopped. Each mail is a
  transaction of its own with its row locked and skipped by any other, so two
  pages never send one mail twice; a person switched off or with the news
  stopped since the list was made is skipped. With `hourly_limit` in
  config/mail.local.php, announcements keep to nine tenths of the host's
  hourly allowance, the rest left for codes and resets, and wait for room.
- **Stopping the news, without signing in.** Every announcement ends with a
  link - and a `List-Unsubscribe` header - to `/unsubscribe` with a token: the
  account id (in the clear - it is the account's `sub`) and a MAC of it under
  a key derived from the master key for this one use (HKDF, found by the
  security review: the master key encrypts, and should sign nothing itself).
  It can only switch that account's news off and never expires; the key's
  name is in every link mailed, so it stays. The page asks first: link
  checkers open every link in a mail. The account page has the same switch.
- **No one-click unsubscribe (RFC 8058).** A mail program's one-click POST
  carries no `X-IAM-Request`, so offering it would make the unsubscribe the
  first first-party write without the CSRF header - a rule that must not
  loosen. Not needed at the anotoki sites' volume; the header names the page.
- **Kept.** Every announcement: the words of each version, the template, the
  site, the link and the sender as they were, who it was for, who sent it and
  when, and each person's mail - the address and the version it went in, and
  whether it went. A test goes to the administrator's own address only.
- **What "the anotoki sites" are rests with the administrators and the
  installation.** Registering an application's address in the panel (audited)
  adds its host; the domains of config/mail.local.php's senders, and every name
  under them, count too - so a sender there must never be at a public mail
  provider, whose every host and address would then count as an anotoki site.
  Links and addresses are read as a mail program reads them (any space ends
  one; every `://` and `www.` judged on its own), the button's link is
  printable ASCII, and the site's name - at the top of every mail - is held to
  the same check: all three found by the security review.
- **At least once.** A mail is marked sent in the transaction that sends it: a
  process that dies between the two sends that one again. A failed email log
  write does not - a savepoint keeps it apart from the mark.
- **No pace unless named.** Without `hourly_limit` an announcement goes as fast
  as its page sends it - the host's limit is not the IAM's to guess - and the
  composer says so where mail really leaves, as it could use up what the host
  allows and sign-in codes with it.
- **Offered at registration, unticked** (migration 014). The security review
  noted that a person signing up was never asked; told, the owner: "add [an]
  offer checkbox in registration for sign-up for announcements". The
  register page offers the news in a box of its own, unticked - an offer, so
  a yes is a choice the person made, kept in the audit log with the account's
  making. Signing up with Google from the register page carries the box's
  answer over; from the sign-in page nobody was asked, and the account gets
  none. Either way the account page's switch turns it on later. A yes given
  with an address its registrant did not own goes when the owner takes the
  account back, with the rest of what the registrant set. Accounts an
  administrator makes, the import brings, and every one there was before 013
  keep the news on, as 013 left them - every announcement says how to stop it.

## 2026-10-06 - The theme, the dates and the clock are the account's, for every site

The owner: "the genshin impact has its own appereance, dates, clock and
language settings. I think it should be in the iam only and apply for every
project/application." Asked, the owner chose that a site's own controls save
to the account for a signed-in person - not only the IAM's account page - and
one theme everywhere: every site, every area of it, its admin area included
(the genshin site had a theme of its own for its admin area). This reverses
"Preferences stay with the sites" (2026-09-27). Migration 015:

- **The account keeps them**, beside the language it already kept:
  `users.theme` (`auto`, `light`, `dark`; `auto`, the operating system's,
  for every account there is), `date_format` (the genshin site's four orders,
  or null for the page language's own way) and `time_format` (`24`, `12`, or
  null). Closed sets, CHECKs on the columns.
- **The sites are told them in the tokens**: a `preferences` claim beside
  `locale`, under the `profile` scope, in the access token, the ID token and
  userinfo. A token says what was true when it was minted; a refresh brings a
  new ID token. Left out while 015 is pending, and a site keeps its own.
- **A site saves them with the person's access token**:
  `POST /api/oidc/preferences` - the SDK's `savePreferences()`, 1.2.0. It is
  the first endpoint through which a site writes to the account, so it is held
  to the narrowest shape: the bearer token is the only proof (verified as
  userinfo verifies it - the account switched on and not waiting); it needs
  the `profile` scope the claims are read under; it changes the four
  preferences and nothing else, on the token's own account only; it answers
  cross-origin to a registered site's origin only. And, found by the security
  review, more than userinfo asks, being a write: a sign-in behind the token
  (a script's personal token has no reader whose choices there are to save,
  so a leaked one changes nothing here); an `Origin`, when there is one, of
  the token's own application, as at the token endpoint; and the person still
  let into that site by the rules a refresh keeps - a site switched off, or
  somebody kept out of it, writes nothing with the tokens still held. Not a
  scope of its own: every site asking is a first-party one, and asks for
  `profile` - a scope for the write would be one more thing each site must
  ask for, guarding against nobody but the sites themselves. It reads no cookie, so it
  joins token, userinfo and revoke as the fourth path exempt from the CSRF
  header (`CSRF_EXEMPT_PATHS`, `CORS_CLIENT_PATHS` - rule 11 of
  architecture.md): a page elsewhere cannot put the person's token into a
  request, which is all the header guards against there. None of the four
  may ever read the session cookie: the header is what stands between the
  cookie and a page on a sibling subdomain, which a browser counts as the
  same site. Not audited: how the pages look is not who gets in.
- **Signed in, the account's; signed out, the device's.** The IAM's pages and
  every site show the account's choices once somebody is signed in, and a
  toggle anywhere saves to the account. A visitor - the sign-in pages, a site
  that lets visitors in - keeps each device's own choice, as before, and the
  account's takes over at sign-in. A device's earlier choice is not carried
  into the account: which of a person's devices should win is not knowable,
  and one choice made once fixes every site.
- **The language stays the account's** as it was: a site saves it only when
  the IAM offers it, and checks `locale` against its own languages.
- **The sites follow**: each vendors SDK 1.2.0, reads `user().preferences`,
  and saves its controls' choices through it; the genshin site drops its own
  copy of these settings - its per-area themes with them.

## 2026-10-07 - An account is deleted on its owner's request, thirty days later; a sign-in from a new browser is mailed

Two decisions of the owner, one migration (016).

**Deleting an account.** The owner: the account's data "will not be deleted
immediately but after 30 days", and the sites "keep everything but just
change the account to anonymous one so no one will know whose data it is";
the person can cancel within the thirty days by signing in and pressing
Cancel. So:

- **Closed at once, deleted in thirty days.** The GDPR's right to erasure asks
  for it "without undue delay" (art. 17), and an answer within a month (art.
  12(3)). The thirty days are the person's own: a month in which a click
  undoes it, which a deletion done on the spot - or by somebody holding the
  session for a minute - could not offer. Meanwhile nothing of the account is
  used: every other session ends, every refresh and personal token is
  revoked, nothing is issued to any site (`account_deletion_pending` on
  every way to a site), so each site loses the person within one access
  token's lifetime, and no announcement is mailed to it. The IAM's own
  sign-in keeps working, by every way in, because that is where Cancel is;
  its admin panel does not.
- **Asked with proof.** The password, wherever it is a way in - a session left
  open somewhere must not be enough to close an account - and, without one, a
  sign-in of the last fifteen minutes. Wrong passwords count against the
  sign-in limiter, as everywhere. Asking and cancelling are both mailed:
  either done with a stolen session is news the owner must get. Cancelling is
  never limited - nobody about to lose an account is told to wait to keep it.
- **No session makes that proof for itself** (found by the security review).
  A session could give a Google-only account a password of its holder's
  choosing, connect its holder's own Google to sign in afresh with, or switch
  password sign-in off so a fresh sign-in is all the deletion asks - so a
  first password, Google connected and password sign-in switched on or off
  ask for the same proof themselves; and so does two-factor switched on,
  which with the holder's own authenticator, and its recovery codes, would
  lock the owner out until an administrator reset it (its setup, which opens
  nothing, already turns away an account without a password whose sign-in is
  too old - before a QR code is drawn that enable would refuse). One rule for
  all five (`accountProof()`), asked before anything else is read; and a
  change of password counts its wrong current passwords like a sign-in, so a
  stolen session cannot guess its way to the password either.
- **Only the person.** An administrator can neither ask nor cancel for
  anybody (not asked for). The last administrator of the IAM cannot ask, and
  an administrator who asked is no administrator meanwhile - not counted by
  the last-admin rule, so two cannot leave at once and the one who stays
  cannot be removed. A deletion asked for on an account whose address nobody
  proved goes when the address's owner claims it, with everything else the
  registrant set (rule 13; found by the security review).
- **Anonymized, not deleted.** When it is due, housekeeping deletes or scrubs
  everything of the person (api.md, "Deleting the account", says what, table
  by table): sessions, tokens, devices, identities, roles, the attempts and
  mails of the account or its address; IP addresses, names and addresses out
  of the audit log, whose events stay; an announcement's address. The row
  stays, a placeholder (`deleted-{id}`, `deleted-{id}@deleted.invalid` - a
  domain that never delivers), switched off for good: its id is the `sub`
  every site keys its data on, and is never anybody else's; every foreign key
  still holds; and the admin panel changes nothing about it - switched on
  again, it would be somebody signing in as an id whose data every site has
  made nobody's. The address is free: it can make a new account, with a new
  id. Then, once that is committed, the address it had is mailed once more,
  "Your anotoki account is deleted" - after, not before (found by the
  security review): never for a deletion that did not happen, never twice.
- **What races it puts nothing back** (found by the security review). A
  request that read the account a moment before it went and writes after -
  a reset link asked for, a sign-in, an administrator's "switch on", a new
  application's MEMBER - finds no row to write to (every such write asks for
  one not anonymized), is issued no link or code, and the database itself
  keeps an anonymized row switched off, unconfirmed, without password,
  password sign-in or two-factor (`users_anonymized_check`). An anonymization
  takes the IAM's administrators' lock and the MEMBER triggers' lock before
  the account's row, in that order like every other path, so it and a role
  change or a new application go one after the other. What such a request
  leaves elsewhere is swept at every run.
- **The sites keep the data, not the person.** `GET
  /api/public/anonymized-accounts` lists the deleted ids and when - ids and
  times, nothing else, cacheable, in no CORS list - and each site's backend
  scrubs its own copies of the name and address under them (a shared module
  is to come). Anybody may learn that an id was deleted and when; nobody,
  the IAM included, can learn whose it was.
- **No cron.** The host has none, so housekeeping rides ordinary traffic -
  the sites' authorize and token calls, every IAM page's first request and
  every account page, and the sites reading the list - and runs at most once
  an hour: a deletion is done within the hour after it is due. One account a
  transaction, one at a time, so the list's times rise as they were
  committed - to the second, which is why a site asks again from the newest
  second it has seen (inclusive) rather than keeping a paging cursor.
- **Backups.** They are kept for disasters and age out on their own schedule;
  they are never restored to bring an account back. A restore of one older
  than a deletion is followed by deleting again every account deleted since -
  the sites' own lists of the ids they scrubbed say which.

**A mail for a sign-in from a new browser.** The owner: "Not based on login
to the project but iam in general so there will be no duplicate emails" -
and only from a new device or browser. So:

- **At the IAM's sign-in, once per browser.** Every way in ends in one place
  (`completeSignIn()`, past two-factor), and that is where it is decided -
  never at a site's single sign-on or refresh, which open no session. A
  person using five sites from one browser hears of it once.
- **A cookie of its own, no credential.** `anotoki_iam_device`, a random
  secret for 400 days, HttpOnly and SameSite=Lax; the table keeps the SHA-256
  of the account's id and the secret, so it cannot match two accounts that
  share a browser. It opens nothing and skips nothing - not the two-factor
  "remembered device", which it is kept apart from: the worst a stolen copy
  does is spare its thief one mail, for a sign-in it already had every
  credential for.
- **The first browser in silence.** An account with no browser noted - every
  account on the day 016 is applied, and every new account - has its first
  one noted without a mail: otherwise release day would mail everybody, and a
  new account would be told about its own first sign-in.
- **The mail says when, which and where to look** - the time in UTC, the
  browser and its system by name from a fixed list (never the user agent as
  sent: it is whatever the browser chose to say, a link included), the page
  of every place the account is signed in, and "if it was not you, change
  your password". At most five an hour: a client that keeps no cookie is a
  new browser every time, and the password alone must not be a way to bury
  the owner's mailbox - counted by the browsers mailed about, and taken under
  the account's lock, so two sign-ins at once cannot both take the last one.
  A mail that cannot be sent stops no sign-in.
- **A reset forgets the browsers**, and so does the owner of an address
  claiming an account never confirmed: whoever held the account must not
  keep a browser its owner is never told about. The browser that takes it
  back is noted next, as the first.

## 2026-10-07 - The family's migrations module; the IAM does not close while an update waits

The owner, on the sites behaving differently while a migration waits:

> When there is a new migration, some sites behave differently than others.
> Japanese won't let me go to the admin or anywhere if I am not logged in, but
> I cannot log in because a migration is waiting. Genshin gives me no warning.
> Piano and survey show me a setup error with buttons to the setup page or the
> migrations page. This needs to be consistent. I do not want logged-in users
> or visitors to see the error about what is going on. The admin should see
> it. If the setup was already done it should never offer that button again,
> especially as setup.php is no longer there.

So what the sites share went into one library, anotoki-lib
(`irelevant25/anotoki-lib`: a Composer package and a release tarball for
Angular, one version), starting with the migrations - one engine, one gate,
one status, one Migrations page on every site. The IAM took 0.1.0:

- **The engine is the library's** (`Migrator`) in place of the IAM's own,
  which it grew from: the same files, the same `migrations` table, read and
  written as it is; each file in a transaction of its own; the run stops at
  the first failure. New with it: one apply at a time (an advisory lock - and
  a pooled connection lets go of one its last request left), a file's SQL on
  the Migrations page, and an Apply that failed answers 200 with what failed
  (it was a 500).
- **The IAM does not block** (`blockWhilePending: false`), where every other
  site now closes to everybody but its ADMIN - who still reaches Migrations -
  until the update is applied. The IAM is the door every site signs in
  through: closed, it would keep every site's administrator from applying
  their own update, and its own administrator from applying the IAM's. It
  always kept working while an update waited, each feature checking its own
  tables, and still does; only its administrators are told, by one line
  above the admin panel's pages.
- **The installer is offered only while the IAM was never installed**
  (`storage/installed.lock`, which a real host's install writes and a
  development machine never does). An installed IAM that loses its
  configuration says it is not available right now - never "open the
  installer": setup.php has deleted itself. The 503 `not_set_up` is the
  family's answer now, `{code, installed, message, setup?}` in place of
  `{error, code, setup_url}`, and `/api/site-status` answers in every state.
- **The not-set-up page keeps the IAM's words** where it has them - the
  database's `notSetUp.*`, reworded on the Translations page - and takes the
  family's for the rest (the installed IAM's "not available" page). It goes
  back to the page that sent it there by itself once the IAM answers, so that
  address is resolved as the browser will read it and kept only while it
  stays on the IAM (`sameOriginPath()`): a crafted `?next=` is no way off-site.
- **The gate is asked only while the IAM is not set up.** A site that does
  not block passes everything in every other state, and the library's gate
  would learn which state it is from the database on every request - a
  connection and two queries in front of discovery and the token endpoint,
  and a second connect timeout when the database host is gone. Its answers
  are the same; the status path still asks the database.
- **The status stays public**, as the family has it: anybody may learn that
  an update waits, or that the database does not answer - the window between
  an upload and Apply, while the IAM runs on the fallbacks each feature keeps
  for its missing tables. Those are built to be safe, so it is a timing
  signal, not a way in; telling only administrators would need the banner to
  read an admin route instead - a change to the family's module, for every
  site or none.

## 2026-10-07 - A sign-in hint for the family's sites - the IAM's first cookie with a Domain, and the only one a script reads

The owner chose automatic sign-in on the anotoki sites by a hint cookie, over
one hop to the IAM on every new visit: signing in at the IAM also sets a
harmless "someone is signed in" cookie every anotoki site can read, and a site
that sees it while it has no session of its own signs the person in with one
`prompt=none` hop to the IAM and back. A hop on every new visit would send
every visitor without an account, and every search engine, to the IAM and
back; with the hint nobody is sent anywhere who never signed in. The sites'
half - the hop and its loop protection - comes later, in the family's
library; until a site reads the cookie, it changes nothing.

- **`anotoki_signed_in`, with nothing in it.** A random value per session
  (sixteen bytes), kept nowhere and derived from nothing - not the session's
  secret, its hash or its `sid`, not the account or its address. It proves
  nothing, and nothing reads it as proof: the IAM looks only at whether it was
  sent, to clear it or set it again, and a site only decides by it whether a
  silent sign-in is worth a hop. Who the person is comes from the code that
  hop brings back - the same authorize, PKCE, exact redirect URI and
  `applicationAccessRefusal()` as any sign-in: no new way in. A new value
  means a new sign-in, which is what the sites' "each value tried once" keys
  on.
- **Read by script, and sent to every anotoki host**: the IAM's first cookie
  with a `Domain` - the parent of the issuer's host (`anotoki.eu` for
  `iam.anotoki.eu`), derived from the issuer at every request, since an
  installed IAM has no installer left to ask; `hint_cookie_domain` in
  `config/iam.local.php` names another domain the issuer is under, and
  anything else there is passed over. Either way only while a site switched
  on signs in under that domain (found by the security review): an IAM on a
  hosting's subdomain would otherwise hand the hint to every customer of the
  hosting. Not `HttpOnly`, as the sites' pages read it; `SameSite=Lax`;
  `Secure` with the session cookie; it lasts as long as the session can. On a
  development machine it is host-only `localhost`, which every dev server's
  port reads - and a loopback issuer never sets a `Domain`.
- **The session cookie stays host-only and `HttpOnly`**, and never gets a
  `Domain`: one with a domain would go with every request to every sibling
  host - and on HTTPS it is now `__Host-` (the next entry). No new CSRF or
  CORS exemption either - the hint gives a page nothing to send.
- **Set** by every way in (`completeSignIn()`), and again where a live session
  finds the browser without one - a site's single sign-on, the account pages -
  so sessions from before it get one too; a new one where the sites are to
  try again - a deletion cancelled, a forced change of password done. Never
  for an account no site lets in, and one that reaches such an account's
  browser anyway - a request racing the deletion - is cleared wherever the IAM
  sees it, `interaction_required` included. **Cleared** by both sign-outs,
  ending this browser's own session, a deletion asked for, every answer with
  no session behind it - the 401s, and any authorize that carries it with no
  session behind it, `prompt=none` answered `login_required` among them - so
  the first site that tries a stale hint cleans it up for every site.
  **Kept** on `interaction_required`: that session is good for other sites,
  and the site that asked keeps its own note not to try the same value again.
- **What it costs.** A session ended elsewhere or run out leaves the hint
  stale until that browser next reaches the IAM: one wasted hop, by the first
  site that tries. A sibling host can set or delete it (cookie tossing): at
  worst a hop the sites' own limits allow, or no automatic sign-in. On a
  shared browser where somebody is signed in at the IAM, a site signs the next
  person in as them without a click - single sign-on already does so at one.
  And privacy, plainly: the value lives as long as the session can - up to 90
  days for a remembered one - and goes with every request to every host under
  `anotoki.eu`: the landing page, `umami.anotoki.eu`, every site's API, which
  could tell one browser's requests apart within one sign-in. Any script on
  any anotoki page can read it, not only the site's own: the visitor
  counter's, Google's sign-in script on the IAM's sign-in page, anything a
  site embeds. And on shared hosting some hosts under the domain may be the
  hosting's, not the owner's - webmail, a control panel - which is why the
  domain must be the owner's alone (operations.md). It tells all of them only
  that somebody signed in, never who; it is set only once somebody has.
- **It changes what the visitor counter's entry says** (2026-10-06, above):
  "its one cookie is host-only" held of the session cookie, and still does;
  the hint, and only the hint, now goes with the counter's script request
  too. The landing page still sets no cookie; it receives this one.

## 2026-10-07 - The session cookie's __Host- name (a hardening)

Not the owner's words: a hardening the security review of the sign-in hint
asked for, decided by the coordinator of that work. The session cookie was
host-only and `HttpOnly`, yet a sibling subdomain could still plant one under
the same name for the whole domain (`anotoki_iam=...; Domain=anotoki.eu;
Path=/api`). The browser sends the cookie with the longer path first, and PHP
keeps the first of two cookies with one name - so a page on any anotoki host,
or the hosting itself on shared hosting, could stand a session of its own in
for the browser's: sign a person into the planter's account, or keep them in
it after they signed in themselves. Older than the hint, which made those
hosts no more trusted but more numerous in what they receive.

- **`__Host-anotoki_iam` on HTTPS.** A browser keeps a cookie of exactly that
  name only when the host itself sets it - `Secure`, `Path=/`, no `Domain`.
  The IAM reads it by that exact name, from the `Cookie` header as sent (found
  by the delta review): Slim's parser, which builds the cookie params,
  URL-decodes names and keeps the first of each, so `__%48ost-anotoki_iam` - a
  name no prefix rule covers, which any host under the domain may set, sent
  first by a longer path - came out as `__Host-anotoki_iam` and stood in for
  the real one. Either name sent twice is no session: only the IAM's own host
  sets a `__Host-` cookie, so two mean something is wrong. When the `__Host-`
  cookie comes, the bare name is not read at all. Plain-http development keeps
  `anotoki_iam`: a `__Host-` cookie must be `Secure` (`cookie_secure` on gives
  it the prefix there too).
- **Nobody is signed out** - the transition the owner ended on 2026-10-10
  (option 2, below). A browser from before carries `anotoki_iam`: it
  is read while no `__Host-` cookie comes, and the next answer that uses the
  session - the account pages (every IAM page asks for the account first), a
  site's single sign-on, a sign-in - sets the same secret under the new name,
  for as long as the session lasts, and expires the old one with the
  attributes it was set with. Every sign-out and every refusal that drops the
  cookie expires both names. The device cookie keeps its name,
  `anotoki_iam_device`: under a new one every browser would be a new one, and
  everybody would be mailed.
- **What stayed while the bare name was read**: a browser with no
  `__Host-` cookie - nobody signed in at the IAM there - still took a bare
  `anotoki_iam`, so one planted there could still sign that browser into the
  planter's session, as before; it never overrode the browser's own, and a
  sign-in ended it. The sign-in hint made it worse: planted beside it, it
  made a site that lets visitors in hop to the IAM, which signed the visitor
  into the planter's account there without a click. Whether to close it
  sooner than the ninety days the sessions from before could last was left
  to the owner, who closed it at once: since 2026-10-10 (option 2, below)
  the bare name is never read on HTTPS.
- **One place.** The cookie is read (`sessionSecretFrom()`), set, expired and
  renamed in `api/session.php` and nowhere else; the four endpoints the sites
  call cross-origin read no cookie, and rename none.

## 2026-10-07 - The family's UI kit: one palette, Manrope, one theme switch, one admin wordmark

The owner's decisions, for every anotoki site, with anotoki-lib 0.2.0 - the
release that put the family's UI kit, its frame and its theme into the
library beside the migrations. Since 0.2.0 the IAM's frontend is drawn by
them: the kit (`@anotoki/lib/ui` and its entry points - buttons, fields,
dialogs, toasts, menus, tabs and the rest), the shell (`<anotoki-shell>`,
`<anotoki-top-bar>`, the language switcher, the theme switch, and
`ThemeService` with its boot script) and the styles (`@anotoki/lib/styles`:
the tokens, the font, the base, the utilities, the tables). The IAM's own kit
under `ui/`, its theme service, its frame and its boot script are gone; `ui/`
keeps the Google button, which is the IAM's alone. A piece the kit lacks goes
to the library, not back into `ui/`.

- **One palette, the library's, that a site overrides.** The kit draws only
  with the `--anotoki-*` properties, light and dark, and their values are the
  IAM's palette, which became the family's. A site whose colours differ
  overrides the properties it needs (`anotoki.root((light: ..., dark: ...))`
  in its `styles.scss`), never a component. The IAM overrides none; the night
  sky of its sign-in pages stays its own.
- **Manrope on every site**, from the library's styles (`anotoki.fonts`); the
  IAM's own copy of the font went.
- **The family's segmented theme switch** - light, dark, system in one radio
  group - in the bar and on the sign-in pages, in place of the IAM's own of
  the same shape. Signed in, the theme is still the account's (2026-10-06):
  the shell reads `preferences.theme` and saves a choice there.
- **The admin wordmark is "anotoki {site} · admin panel"** on every site. The
  IAM's is "anotoki account · admin panel", in place of 2026-10-04's "anotoki
  admin panel"; on a phone "account", with "admin panel" small under it, as
  the bar shortens every site's; its link is named with the words shown
  ("anotoki account admin panel - home").
- **The IAM's words stay the database's.** A word of the kit or the bar that
  the IAM said before is still its string, through the kit's `lookup`
  (`shared/kit/kit-words.ts`), reworded on the Translations page like the rest;
  only what the IAM never said is the kit's own English and Slovak. The admin
  panel stays English, and "Your anotoki account" stays out of the IAM's own
  menu (2026-10-04): this site is that account.
- **The look is kept.** The kit was drawn from the IAM's pieces; where it
  differs - the fields' edges at 3:1 contrast, the spinner, 44 px targets for
  links, the theme switch - the family's is meant. The backend stays on
  anotoki/lib 0.1.0: 0.2.0 changed only the Angular half
  (`docs/operations.md`).

## 2026-10-10 - The owner's decision: option 2 - the bare session cookie is never read on HTTPS

The owner's choice ("option 2") between the two ways to close what the
`__Host-` hardening of 2026-10-07 left open (above): not reading the bare
`anotoki_iam` for up to ninety days more, until every session from before
the prefix had run out, but stopping at once. It replaces that entry's
transition.

- **On HTTPS the session cookie is `__Host-anotoki_iam` and nothing else.**
  `sessionSecretFrom()` reads that exact name from the `Cookie` header and
  never the bare one - neither beside it nor without it. A cookie under the
  bare name, even one carrying a live session's secret, opens nothing: not
  the account pages or the admin panel, not authorize (`prompt=none`
  answers `login_required` and clears a hint sent with it), not the sign-in
  page's accept; a sign-out by it ends no session. The exact name as sent,
  and either name sent twice being no session, stay as they were.
  Plain-http development keeps the bare name as its one name.
- **What it closes**: a browser with no `__Host-` session took a bare
  `anotoki_iam` as its session, so any host under the domain could plant
  its own session there and sign the browser into the planter's account at
  the IAM - and, with a hint planted beside it, have a site that lets
  visitors in hop to the IAM and come back with the planter's code, without
  a click. That hop now finds nobody signed in: `login_required`, and the
  planted hint goes.
- **Everybody signed in at the IAM signs in there once more** after the
  upload that brings it: a session from before the prefix is held under the
  bare name, which is not read. **The sites are not affected**: they hold
  their own tokens and refresh without the IAM's cookie, so the old session
  lives on for them until it runs out (ninety days at most) or is ended on
  the account page's Sessions list. Signing in again does not replace it -
  it was never read as this browser's. A browser already holding the
  `__Host-` cookie notices nothing.
- **The old cookie leaves the browsers.** Every answer that uses the
  session - the account pages and the admin panel (every IAM page asks for
  the account first), authorize, every sign-in - expires a bare one sent
  over HTTPS, with the attributes the IAM set it with (this host,
  `Path=/`); every sign-out and every refusal that drops the cookie expires
  both names, as before. Nothing is set in its place: the transition's
  renaming (`withSessionCookieRenamed()`) is gone, replaced by
  `withoutOldSessionCookie()`. One planted with a `Domain` is beyond a
  host-only expiry; it opens nothing either. The four endpoints the sites
  call cross-origin still read no cookie and set none.
- **What it costs**, beside the one sign-in: from a browser that still
  holds only the bare cookie, a site's sign-out ends that site's own
  sign-in but not the old session - the IAM no longer knows that browser
  holds it - so the other sites signed in under it stay signed in until it
  runs out or the person ends it on the Sessions page.

## 2026-10-11 - The owner's clarification: nobody but the owner has signed in yet

The anotoki sites are not public yet: the owner is the only person who has
ever signed in, testing. So there are no browsers from before the prefix to
look after, and option 2 (above) stands in its plain form - no transition,
and nothing that handles an old browser. The one browser that held a bare
`anotoki_iam` session is the owner's, which signs in at the IAM once more
after the upload; what option 2 costs (above) reaches nobody else. A
refinement that would have let a sign-out end the session held under the
bare name - so that an old browser's sign-out still reached every site - was
written on 2026-10-10 and dropped the next day, unmerged: with no old
browsers, it was code for nothing.

## 2026-10-11 - The IAM's words through the family's library (anotoki-lib 0.3.0)

Five anotoki sites kept their words in the same three tables, written by the
IAM first and copied four times, each copy a little different. anotoki-lib
0.3.0 made them one implementation - `Anotoki\Lib\Translations` and
`@anotoki/lib/translations` - built from the IAM's, and the IAM moved onto it
first. What is the IAM's own is now its configuration
(`backend/api/translations.php`, `iamTranslations()`); the owner's decisions
for the move:

- **The contract stays.** `GET /api/translations/{code}` and the eight admin
  routes keep their paths, their shapes (snake_case was the IAM's already) and
  their codes; the bundle is the IAM's byte for byte. `docs/api.md` gains what
  the library adds: `strings` on every language and in a delete's answer
  (`{strings, accounts}`), and the body's refusals - `415
  unsupported_media_type`, `400 invalid_json`, `422 empty_body`. What else a
  client may notice: the 503 carries `Retry-After: 60`, and a database out of
  reach is that 503 too, not a 500 (the pages keep their compiled English;
  nothing is logged on every page load); a body is read by the library as it
  came, so `[]` is no longer taken for an empty object (`{}` is one), and a
  `{code}` that is no code is a 404 before the body is looked at; an export is
  a download in the command line's file format.
- **The mails stay the IAM's**: `MAIL_KEYS`, `MAIL_FALLBACK`, `mailWords()`,
  `mailFill()` and `mailMessage()` in `api/mail.php`, unchanged. Their checks
  are a rule class of the IAM's (`IamMailRules`), which the library asks for
  every `mail.` string in the IAM's order - one line, 150 or 400 characters,
  the bytes of one line of mail, a brace, exactly the code's placeholders, no
  address - with the IAM's sentences; `mail` is a server namespace, never in a
  bundle, and a save's audit keeps each changed mail string's old and new text
  whole. Every test of them was kept, against the library's checks. One
  difference: a string is trimmed of exactly what the browser trims, so a
  next-line character (U+0085) at the end of a mail string is refused as a line
  break where it used to be trimmed away. And one rule more, from the security
  review of the move: a mail string may not hold a character that turns the
  direction of the text - the embeddings and overrides U+202A-202E, the
  isolates U+2066-2069, the marks U+200E, U+200F and U+061C - nor a C1 control
  (U+0080-009F); refused (`invalid_value`) like a line break, not dropped. The
  address check reads the stored order and a mail program the drawn one: after
  a right-to-left override, "moc.live//:sptth" is no address to the check and
  `https://evil.com` to the reader. A page's string keeps them - the page draws
  it in the order the panel shows.
- **Permissions do not change.** Both of the library's route groups sit in
  the admin group: the session, the IAM's ADMIN for all eight (the IAM has no
  EDITOR of its own panel), the CSRF header on every write, `audit()` for
  every change with the same actions and details.
- **The IAM still never closes while an update waits.** After the upload, 017
  and the library's set wait for Apply on a live IAM, and everything runs on
  the new code meanwhile: the sign-in pages read their words (the library's own
  keys from its built-in words until they are in the database), every way in
  works, the Translations page works - and a rewording saved there survives the
  update (`tests/strings-through-the-library.test.php`). Apply right after the
  upload all the same: until then the account page's Sessions list would show
  two keys as keys for a way of signing in it does not know.
- **Every rewording survives.** The library writes its own words
  (`anotoki_translations/002`) only for a library key that has no string at
  all, and its keys are its namespace, `anotoki.`. The IAM's language
  switcher said two of them itself - `lang.label`, `lang.button` - and the
  library's switcher replaces it, so migration 017 copies every string of the
  two, in every language, with who wrote it and when, under
  `anotoki.language.label` and `anotoki.language.button`, and then deletes the
  old keys: an owner's rewording stays, a Slovak string the owner blanked stays
  blank. Its gate names its own keys and proves every string arrived as it was.
- **Two keys renamed**: `sessions.method.other` and `sessions.ended.other`
  became `.unknown` - the account page's words for a way of signing in, or a
  reason a session ended, it does not know. The family's key scan reads a key
  ending in `.one`, `.few` or `.other` as a form of a plural family; these
  were none, and the IAM has no plurals. Renamed in place: the strings follow
  by the foreign key's `ON UPDATE CASCADE`, rewordings included.
- **The library's set runs after the IAM's**, wherever the IAM's database is
  built - the admin panel's Apply, the installer, `php migration.php` all use
  `iamMigrator()`. First, it would make the tables on a fresh database before
  006, whose gate counts its own strings. In the `migrations` table, which
  has no `folder`, a library file is recorded as `set/name`
  (`anotoki_translations/001_languages_and_strings.sql`) beside the IAM's bare
  names; nothing in the table changes, and the command line, the Migrations
  page and the audit log name the library's files the same way.
- **English and Slovak stay the released languages**; neither can be deleted.
- **The command line keeps its name and flags** (`--status`, `--export`,
  `--import`) over the library's command, and adds the mails' keys against
  `MAIL_KEYS` to `--status`. New with the library: it refuses while a
  migration waits - a database behind the code does not have the release's
  strings.
- **The tests**: `tests/translations.test.php` keeps what is the IAM's (the
  window before 006, the mails byte for byte, the mails' rules, the catalogue
  against `en.ts` and `MAIL_KEYS`, the contract on every route, `ui_locales`,
  the locale claim, discovery and the pages' config without a database); the
  generic parts - the checks every site's strings keep, the languages' limit
  and its lock, the body's reading, the scan, the file format - are the
  library's own suite's. The IAM's scan of its frontend runs on the library's
  `KeyScanner` with the IAM's settings (`iamKeyScanner()`).
- **No way back to a build from before it** once 017 is applied: that build
  asks for `lang.*` and `sessions.*.other`, and would show them as keys. A
  problem after it is fixed forward. Its engine would list the library's two
  records as missing files - harmless, but confusing.

## 2026-10-11 - The admin panel's tools from the family's library; backups encrypted, never restored here

anotoki-lib 0.4 (0.4.1 - 0.4.0 fails on PHP 8.2) brings the admin tools every
anotoki site's panel shares: the error log, the audit log, feedback, backups
and the file explorer, each a page of the library's, for the site's ADMIN
alone (the owner's decision of 2026-10-07). The IAM takes three - the error
log, the audit log and backups; not feedback (its visitors write to the sites)
and not the file explorer (it serves no files). Its configuration of them is
`backend/api/admin_tools.php` and `backend/api/audit.php`; what was decided:

- **One audit log, the library's, with every entry the IAM ever wrote.** The
  library's table `anotoki_audit_log` is the IAM's `audit_log` with three more
  columns (a session, and what an entry belongs to) the IAM leaves empty. Its
  set `anotoki_admin` runs first, before the IAM's files - the family's order,
  in the installer, the command line and the Migrations page alike - and the
  IAM's migration 018 moves every row of `audit_log` into it with its id, its
  time, its address and its details exactly, proving it in its gate (as many,
  each equal, the next number after the highest). `audit()` stays the IAM's one
  function every route calls, and keeps what it kept: who, what, to what, the
  details, the address (`requestIp()`), the time; written behind a savepoint
  inside the caller's transaction, never throwing. The old table is kept,
  unread, as `audit_log_before_018`, and scrubbed by an account's deletion like
  the log, until a later migration drops it once both IAMs run 018 - the
  family's pattern for moved data (the sites drop theirs the same way).
- **The IAM never closes while 018 waits.** Between the upload and Apply,
  `audit()` writes into neither table: an entry waits in
  `storage/logs/audit-pending.jsonl` and goes in after the Apply with its own
  time (`flushWaitingAuditEntries()`, from the Apply, `php migration.php`
  and housekeeping - before its deletions -, which scrubs the people of the
  accounts deleted meanwhile out of them as they go in, not an hour later).
  So nothing writes into `audit_log` while it moves, every
  way in works, and no request fails for its entry
  (`tests/audit-log-into-the-library.test.php`). The Audit page asks for the
  update first meanwhile. **Once 018 is applied, no build from before it goes
  on the host again**: its `audit()` would lose every entry, its Audit page
  fail, and its anonymization stop on the old table's name. Fix forward.
- **The deletion of an account scrubs the library's table by the IAM's rules**:
  the library's `scrubPeople()` is the IAM's two rules made the family's (no
  address on the person's own entries or on those about them nobody else made;
  their names and addresses out of the details of what was about them), and
  the IAM adds its third (a personal token's name).
- **A change of the strings keeps every mail string whole** (from the security
  review of 2026-10-11): one audit entry holds at most 64 KB of details, and
  the library keeps nothing but the names of details that do not fit - a save
  of every mail string, long, in two languages would have kept none of its
  texts, and an administrator could pad a save to hide the one rewording that
  matters. So the save's or import's entry keeps its summary (and how many mail
  strings changed), and each changed mail string has an entry of its own,
  `translations.mail_changed`, with its old and new text whole
  (`auditTranslations()`).
- **The error log keeps no secret.** The library's redactor takes the family's
  names of secrets and every run that looks like a token; it has no way to be
  told a site's own, so the IAM takes its own names out first - its session
  and device cookies, its `iam_rt_` and `iam_pat_` tokens, the body fields
  that carry a credential, a two-factor secret (`iamRedactSecrets()`) - since
  a random value with no digit in it is no token-like run to the library. On
  every way into the log: the IAM's error handler; a middleware just inside the
  log's outer half, for an exception nothing answered (thrown by the security
  headers, the gate or the CORS check); PHP's warning and fatal-error handlers,
  which are the IAM's own for that (`iamInstallPhpHandlers()`, the library's
  `installPhpHandlers()` with the names taken out first); and every failure
  the IAM writes itself (`iamRecordError()`). Nothing the
  IAM throws names one; this is for what might. No route for the pages' own
  errors: nothing asked for one. The client hashes are keyed by the master
  key, so no other key file is kept.
- **Backups: encrypted, always.** Every table, every account's password hash,
  the encrypted two-factor secrets and signing keys, every hash of a session
  and a token: AES-256-GCM chunks under a key derived from the master key, the
  rows sealed as COPY hands them over - never in the clear on the disk. A
  downloaded backup opens only with the master key (`php backup.php --from
  --key`, on the owner's computer), and an older one only with the master key
  it was made under.
- **Backups: the proof the account page asks, never a session alone.** Making,
  downloading and deleting one asks for the administrator's password where it
  is a way in, otherwise a sign-in of the last 15 minutes (`accountProof()`, its
  wrong passwords counted by the limiter); each is audited. A download is a
  single-use ticket for a minute, for one backup and the administrator it was
  issued to, its route inside the admin group as well.
- **Backups: never restored here.** An IAM put back as it was would bring
  back revoked refresh tokens and sessions, old passwords, removed roles and
  deleted people. Restore stays off (`restore: false`) until an `afterRestore`
  exists that ends every session and token and deletes again every account
  deleted since - and has been reviewed; then it is a decision of its own. A
  backup's data is read offline meanwhile (`php backup.php --extract`).
- **Backups are made by themselves**: daily (the first housekeeping run of a
  UTC day, after the answer - the host has no cron; 14 kept) and before every
  update the Migrations page or `php migration.php` applies (5 kept; one that
  cannot be made applies nothing); by hand, until deleted. The installer makes
  none: it builds the database it was just given.
- **Backups are kept until the owner deletes them - no maximum age** (the
  owner's decision of 2026-10-11, on the security review). Nothing deletes a
  backup for its age: the daily and before-migration ones go only as newer
  ones of their kind push them out (14 and 5 kept), whatever their age, and a
  manual one stays until the owner deletes it. The consequence, accepted: a
  deleted person's data - their row, hashes, address, history - stays in every
  backup made before their deletion until the owner deletes that backup. Those
  backups are encrypted under the master key, and the IAM never restores one,
  so no backup brings an account back; a restore made by hand elsewhere must
  delete again the accounts deleted since (`docs/operations.md`).
- **Where they live**: `storage/backups` and `storage/logs`, denied to the web
  twice (the build's `storage/.htaccess`, and each folder's own the library
  writes, with an empty `index.html`). Backup names carry 128 random bits; the
  two files of known names name no backup. The genshin site's old pg_dump
  folders and the sites' own copies are none of the IAM's business, and no
  file of the IAM's is deleted by this change.

## 2026-10-11 - The owner's decision: the visitors' own addresses, through Cloudflare's ranges

Every anotoki site is behind Cloudflare, which connects to the site on each
visitor's behalf and names the visitor in `CF-Connecting-IP`. The IAM could
believe that header from proxies listed in `iam.local.php` - and listed none,
so behind Cloudflare every visitor was one of its edges. The owner's decision
of 2026-10-11, for every anotoki site: **the visitors' real addresses, through
Cloudflare's published ranges.** A change of a security rule (architecture.md,
"The rules that carry the weight", 7), made here as the owner's:

- **Who is believed.** The trusted proxies are Cloudflare's published ranges
  (https://www.cloudflare.com/ips/, fifteen IPv4 and seven IPv6 ranges as of
  2026-10-11), in the IAM's committed configuration (`config/iam.php`: the
  anotoki library's `ClientAddress::CLOUDFLARE`, one list for every site, and
  its header `CF-Connecting-IP`). On a connection from one of them the visitor
  is the address the header names (Cloudflare writes it itself, over whatever
  a visitor sent); on any other connection the header is its sender's own
  words, and never believed. **The header is pinned in the code** - no
  setting names another: Cloudflare passes `X-Real-IP`, `True-Client-IP`,
  `X-Forwarded-For` and their kin on as the visitor wrote them, so a setting
  pointing at one would believe whatever a visitor writes (the IAM's
  `client_ip_header` setting is gone, and one left in `iam.local.php` is not
  read). Nor is a header that is no single address. An IPv4 address written
  as IPv6 is the IPv4 one. An installation behind other proxies names them in
  `iam.local.php`, one behind none `[]`: the connection's address alone, as
  before. **A catch-all range is refused**: one wider than an IPv4 `/8` or an
  IPv6 `/16` (`0.0.0.0/0`, `::/0`), or anything that is no range, is never
  believed - `iamTrustedProxies()` leaves it out and PHP's error log says so
  once a day.
- **The family's one rule**: `requestIp()` reads through the library's
  `ClientAddress`, as the library's error log does - the IAM's own matcher of
  ranges (`ipInRanges()`) is gone.
- **What it changes.** The sign-in limiter counts each visitor - a guesser on
  one of Cloudflare's edges no longer locks out everybody else behind it, nor
  spends their per-source allowance -, an IPv6 one by its /64 (below); the
  audit log keeps the visitor's address, the account page's Sessions list and
  the admin panel show it.
- **What it stops** (`tests/addresses.test.php`): a guesser writing a new
  address into every request from outside Cloudflare's ranges is counted
  under its connection, every guess, and refused at the limit like anybody
  else - the right password too. From a Cloudflare address, `X-Real-IP`,
  `True-Client-IP` and `X-Forwarded-For` are never believed: a guesser writing
  a new one into every try is still the one visitor Cloudflare named. A
  catch-all range configured is believed for nobody.
- **What it costs**: those columns hold the people's own addresses, not
  Cloudflare's - personal data, kept as long as before (the sessions and the
  audit log as history, a sign-in attempt a year) and taken out as before when
  an account is deleted. The error log keeps none: a keyed hash of
  the address's bucket (an IPv4 address whole, an IPv6 address its /64), as
  the library's own limits count it - the pages' own errors (below). That hash
  is a pseudonym, stable while its key (derived from the master key) lasts:
  whoever holds the key could hash every IPv4 address and find the one it was.
  The IAM's sign-in limiter counts the same bucket (`limiterSource()`), and
  keeps it as an attempt's address: an IPv4 address whole, an IPv6 address
  its /64 ("2001:db8:aa:bb::/64").
- **Tightened on 2026-10-12** (the security review): the limiter first
  counted an IPv6 address whole - and with the visitor's own address now its
  source, anybody holding a /64 (one subscriber's network, 2^64 addresses)
  could guess a password, or have `/api/register` mail an address, without a
  ceiling, each try from a fresh address. It counts the /64 since: one /64
  walked address by address is refused at 10 tries against an account and
  at 50 in all, and the next /64 is somebody else (`tests/addresses.test.php`).
  No migration: rows counted the old way leave the fifteen-minute window.
- **What it trusts, and the choice left to the owner.** Cloudflare's ranges
  are every Cloudflare customer's, not the owner's zone alone: a Worker's raw
  socket leaves from Cloudflare's addresses and can write a `CF-Connecting-IP`
  of its own, which the IAM then believes - a guesser who pays for a Worker
  picks the source the limiter counts. Two ways to pin the trust to the
  owner's zone, neither done here: a secret header that a Transform Rule of
  the zone adds to every request, checked with `hash_equals()` before
  `CF-Connecting-IP` is believed; or Authenticated Origin Pulls (the origin
  accepts only Cloudflare's client certificate for the zone). Which one, if
  either, is the owner's decision.

## 2026-10-11 - anotoki-lib 0.5.0: the pages' own errors, the library's names of secrets, the tab's title

Both halves of the IAM take anotoki-lib 0.5.0 (`composer.json` `^0.5.0`, the
0.5.0 tarball). Beside the addresses (above), what was decided:

- **The pages' own errors are in the error log** (the owner's decision of
  2026-10-11, for every anotoki site - it replaces the 0.4 entry's "No route
  for the pages' own errors"): `POST /api/client-errors`, the library's
  `ErrorLogRoutes::client()`, and in the pages `@anotoki/lib/error-report`, the
  app's `ErrorHandler`. A report is a first-party write like any other: the
  CSRF header and the IAM's own Origin - the reporter goes through the app's
  HttpClient, so the IAM's interceptor gives it the header; never an
  exemption - and in neither CORS list. Public, as a sign-in page fails too.
  The library's limits: 20 an hour from one sender - a signed-in person by
  account, told apart by the session, which the route only reads
  (`optionalSession()`); a visitor by the bucket of their address - and 500 a
  day from the visitors together, as many from the people signed in. Scrubbed
  in the browser before it leaves, and again on the server by every rule a
  line goes through - the IAM's own prefixes and cookies included
  (`iamClientErrorRedaction()`). The interceptor moves no page and clears no
  session for a report's failure, and hands it back as it came (a 429 stops
  the reporter for the page load).
- **The IAM's names of secrets are the library's to take out**
  (`Redactor::addSecretNames()`, `IAM_SECRET_NAMES` in `api/admin_tools.php`):
  a current or new password, a remembered device's token, a code, a recovery
  code, Google's credential, a two-factor secret - in place of the rule the
  IAM ran over every message itself. What no name can say stays the IAM's
  (`iamRedactSecrets()`): its refresh and personal tokens by their prefixes,
  its cookies by their configured names - on every way into the log, now the
  pages' own errors too, and **down an exception's whole chain**: since 0.5.0 a
  line keeps each cause's message ("Caused by ..."), and the library's own
  backups and restores throw their sentence with the failure as its cause, so
  `iamRedactException()` walks `getPrevious()`.
- **The Backups page names who made a backup** (the context's `names`: the
  account's username as it is now, an anonymized one's placeholder).
- **The audit entries that waited for 018 are scrubbed whenever a flush found
  them**, not only when it put some in: a flush killed after its commit,
  before it emptied the file, left them in the log unscrubbed, and the next
  one puts none in twice - so its count of nought had skipped the scrub
  (`flushWaitingAuditEntries()`; housekeeping's sweep would have caught them
  within the hour).
- **Backups**: the IAM's are encrypted - their cipher is their seal - and
  restoring stays off, so the library's seal of plain backups changes nothing
  here. Their files are the PHP user's alone from their creation (`0600`,
  folders `0700`); `--inspect` reads a backup's manifest alone, in memory, so
  an interrupted one leaves nothing of the database behind.
- **The tab's title in the family's form**: "Users · Admin panel · anotoki
  account" in the admin panel (it was "Users · anotoki admin panel"), as every
  anotoki site's admin area says it; "Security · anotoki account" elsewhere,
  as before.

## 2026-10-13 - The owner's decision: the visitor's address only through the owner's Cloudflare zone (the origin secret)

The 2026-10-11 entry left a choice to the owner: Cloudflare's published ranges
are every Cloudflare customer's, not the owner's zone alone, and anybody's
Worker can open a raw socket to the host from them and write the
`CF-Connecting-IP` it likes - a fresh address for every guess, past the
sign-in limiter. The owner chose the secret header, for every anotoki site
(round 3, step 2). A change of a security rule (architecture.md, "The rules
that carry the weight", 7), made here as the owner's:

- **How.** A Transform Rule of the owner's zone (Rules -> Transform Rules ->
  Modify Request Header, "Set static") adds `X-Anotoki-Origin` with a long
  random value to every request through the zone. With the same value in
  `config/cloudflare.local.php` (`['origin_secret' => '...']`), the IAM
  believes `CF-Connecting-IP` only on a request from Cloudflare's ranges that
  also carries it; any other request from the ranges is the edge it came from
  - coarser, never looser. The anotoki library checks it (anotoki-lib 0.5.2,
  `ClientAddress::of()`'s fourth argument): compared in constant time, never
  logged. From outside the ranges the secret makes nobody a proxy.
- **The setting.** An optional local file, the same in every anotoki site,
  uploaded by hand: the installer neither asks for it nor writes it, and
  `iamSetUpProblems()` does not ask for it. Read through `localConfig()`
  (`iamOriginSecret()`, `config/cloudflare.php`), trimmed - a header's value
  never starts or ends with a space, so a value saved with a line break at its
  end would never match; an empty value, or one that is no string, is no
  secret (said in PHP's error log once a day, by the file's name - since
  2026-10-18 it fails closed instead, below). A file that
  is there but cannot be read cleanly - it prints (a byte-order mark, no
  `<?php` line), or PHP cannot read it - fails closed (the security review of
  2026-10-13): a secret no request carries (random, of the process, never
  written anywhere), so every request from the ranges counts as its edge until
  the file is mended, said in the error log - a slip of the editor never
  switches the check off. Never printed, logged or in an answer;
  `origin_secret` is among the redactor's names besides.
- **Where.** `requestIp()` - and so the limiter, the audit log and the
  sessions - and `iamAdminContext()` (the library's limits and the error log's
  hash, which read `requestIp()` through the IAM's `clientKey` anyway).
- **What it costs.** Nothing for a visitor who comes through the zone. Should
  the Transform Rule be missing, or its value differ from the file's, every
  visitor is counted as the edge they came through, as before 2026-10-11: the
  two are set together (docs/operations.md, "Behind Cloudflare").
- **What it stops** (`tests/addresses.test.php`): with the secret, a request
  from the ranges without the header, or with a wrong one (cut short, longer,
  another case, twice), is the edge; with it, the visitor; the right one from
  outside the ranges, the connection. A Worker writing a new address into
  every guess without the secret is one source - the eleventh guess is refused,
  every one counted under the edge - while the account's owner, coming through
  the zone, signs in.

The round's lesson from the five sites, applied here: **a local file is read
cleanly or not at all.** A `config/*.local.php` written by hand without its
`<?php` line, with a byte-order mark or a blank line before it, or with text
after a closing tag that comes before its `return`, prints what is outside its
PHP when PHP requires it - all of it, the master key or the origin secret
included, where the line is missing - ahead of every answer. `localConfig()`
reads every file through `readLocalConfigFile()`: what it prints is caught and
goes nowhere, and the file is passed over - said in PHP's error log by its
byte count, never its text; a required one leaves the IAM not set up, and
`iamSetUpProblems()` names it and its fault; the origin secret's fails closed
(above). One PHP cannot read is passed
over the same way, said by the kind of error and its line alone (a parse
error's message can quote the file). The installer reads its files through it
too, never shows one, and saves over none it could not read - a master key with
a byte-order mark before it is still the key every signing key and two-factor
secret is encrypted with, and a fresh one in its place would make them all
unreadable (`setupSavedProblems()`, `tests/setup.test.php`).

## 2026-10-13 - The owner's decision: five wrong authenticator codes pause an account's codes for fifteen minutes

The owner's numbers: five wrong codes, fifteen minutes. Reaching the
authenticator step means the first one was passed - the password, as a rule.
Six digits are all that is left, three in a million right at any moment, and
the limiter counts sources: a guesser with many of them guessed at one account
without a ceiling of its own. A change of a security rule (architecture.md,
"The rules that carry the weight", 7 and 12), made here as the owner's
(migration 019, `api/authenticator_pause.php`):

- **The count.** Five wrong codes for an account - counted for the account,
  whatever address they come from, within a rolling fifteen minutes, and from
  nought again after a right code or a completed sign-in - pause its codes for
  fifteen minutes from the fifth. One check at a time for an account (its row
  held), so a burst sent at once is checked one after another: five, then the
  pause. The wrong codes are the limiter's own attempts (`bad_totp`, by
  account); `users.totp_paused_until` and `totp_count_from` keep the rest.
- **The pause.** A code is refused without being checked - `403
  authenticator_paused` with `paused_until` - and nothing refused meanwhile
  counts towards the next pause (`totp_paused`). On every way that takes an
  authenticator code (the same switches on every way in): the password sign-in,
  the emailed code's, Google, a password reset, and the account page's turning
  two-factor off and new recovery codes. Not the confirmation of a new secret
  when two-factor is switched on: that is a secret just shown to the session
  itself, and switching on with a new secret clears the pause.
- **What still works.** A recovery code (fifty bits: not guessable) and a
  remembered browser (a 256-bit secret), as before. And the password's sign-in
  may finish with a code mailed to the address in the authenticator's place:
  the emailed sign-in's own code (`POST /api/login/code`; five live at most, ten
  minutes each), sent back as `email_code` with the password - checked only
  after the password was found right, and only while the codes are paused.
  Its wrong ones are counted for the account as the authenticator's are (the
  security review of 2026-10-13: without it, codes could be asked for again
  and again, each five tries more): under the same hold on the account's row,
  read before the code is, five wrong in a pause (`bad_email_code`) and every
  code by email is refused unchecked until the pause ends - one mailed since,
  the right one, too (`403 authenticator_paused`, `email_code: false`,
  `email_code_paused`); a burst sent at once is checked one at a time, five at
  most. Holding the mailbox alone gets nobody anywhere: the emailed code's own
  sign-in still asks for the authenticator, paused with it. A browser is not
  remembered for such a sign-in, and its session says the mailbox stood in:
  method `password_email`, `amr` `["pwd", "email", "mfa"]` - never `otp`,
  which is an authenticator's code.
- **The trade, said plainly.** During a pause the password and the mailbox
  sign in without the authenticator, and the pause is the password holder's
  to start: five wrong codes, typed on purpose, begin it at any moment, and
  the mail that tells the owner lands in that same mailbox. So in practice
  the password and the mailbox together sign in; the authenticator stands
  only between the account and somebody who holds the password without the
  mailbox. The owner's choice of 2026-10-13, confirmed when asked plainly
  after the security review: the alternative - during a pause, recovery codes
  and remembered browsers alone, no code by email - was offered and declined,
  so that somebody holding the password cannot lock the owner out by
  guessing. Outside a pause a mailed code stands in for nothing.
- **The owner is told**, once a pause - by the request that begins it, at an
  address its owner proved: somebody got past the first step (the password, a
  mailed code, or Google) and typed five wrong codes; if it was not them,
  somebody knows the password or can get into the mailbox - change the
  password (the account page's security). Its words in English and Slovak
  (`mail.authenticatorPaused.*`, 019). The audit log keeps
  `account.authenticator_paused`, by nobody, from the guesser's address.
- **The admin panel** adds no page: its sign-in attempts list says
  `totp_paused` for a code refused unchecked, `bad_recovery` for a wrong
  recovery code during a pause, `bad_email_code` for a wrong code by email and
  `email_code_paused` for one refused unchecked after five; Sessions (and the
  account page's) say "Password and emailed code" for `password_email`.
- **With 019 pending** the step is checked as before, with no pause.
- **What it stops** (`tests/authenticator-pause.test.php`): five wrong codes
  from five addresses pause it, and the sixth - the right code - is refused
  unchecked and not spent; the five may come by five ways; a code by email signs
  in during the pause, never with a wrong password and never by the mailbox
  alone; five wrong codes by email and none stands in until the pause ends,
  one mailed since and the right one included, and eight sent at once (each
  waiting at the account's row) are four refusals, the fifth's pause and three
  unchecked - five checked; a check whose transaction began before the pause
  did, reading the row after it, starts afresh so its try is counted (an
  attempt is kept at its transaction's start); such a sign-in's ID token says
  `pwd`, `email`, `mfa`; outside a pause a code by email stands in for nothing; the pause
  lifts after fifteen minutes and the count starts again; one mail a pause, in
  the account's language, none to an unproved address; a right code, a
  recovery code or a completed sign-in starts the count again; recovery codes
  and remembered browsers work during the pause.

## 2026-10-13 - The owner's decision: the IAM's restore stays off; a record of every deletion, replayed after a restore by hand

The IAM never restores a backup in place (2026-10-11). But were its database
lost and an older backup put back by hand, every deletion asked for,
cancelled or done since would be lost with it - an anonymized person's name,
address and history back, and a request's thirty days gone. The owner's
decision: keep the restore off, and keep a record beside the database that a
restore by hand replays (`api/account_deletion.php`, `account-deletions.php`):

- **The record.** `storage/records/account-deletions.jsonl`, add-only, a line
  for every event - `requested` (with when it is due), `cancelled`,
  `anonymized` - with the account's id and the database's own time to the
  microsecond: nothing of the person. Written once the event's transaction
  committed, never for one rolled back; a cancellation by the owner of an
  unconfirmed address (`claimUnconfirmedAccount()`) is one too. A line that
  cannot be written is the error log's, loudly - the event, its account's id
  and time - and never the person's: the deletion was made. Its folder is
  guarded against the web like the library's (a deny-all `.htaccess`, an empty
  `index.html`) and readable by the FTP account: the owner downloads it with
  the backups.
- **The replay.** `php account-deletions.php --replay --since=<the backup's time>
  [--record=<a copy>] [--dry-run]`, on the owner's computer against the
  restored database: the events from an hour before the backup's time on (a
  transaction that began before its snapshot may have committed after it), in
  the order they happened - by their own times, not the file's order, since a
  line is written once its transaction committed and two a moment apart can
  stand the other way round; a request a replay made again by when it was
  first asked (the security review of 2026-10-13) -, each account's last event
  deciding where it ends - a request with its own times, a cancellation, an
  anonymization run now, with no second last mail - and only where the
  database does not say so already. Then the ids the record
  names but the database lacks (accounts made after the backup) are kept from
  being issued again (the id sequence moved past them), and every way back in
  the restore brought back is ended: live sessions, refresh and personal
  tokens, remembered browsers, mailed links and codes - everybody signs in
  again. Refused while migrations wait. Its changes are lines of the record
  (`"replayed": true`, at the time they were made) and entries of the audit
  log, so a backup made between the restore and the replay is covered by the
  next replay too. A dry run changes nothing; a second run nothing more. It
  says how many live administrators the IAM has afterwards, and warns when
  none (the same review): the backup may be older than every administrator who
  stays - one made since is not in it, and the record knows deletions, not
  roles - while the last-admin rule let the one in it ask for a deletion. The
  way back: an administrator whose deletion was asked again signs in and
  cancels it, or a live account is given the IAM's ADMIN in the database by
  hand (docs/operations.md), before the IAM goes live again.
- **What it does not do.** Accounts made after the backup are lost (their ids
  kept only where the record names them); a password, a role or a setting
  changed since comes back as it was. The steps are docs/operations.md's,
  "When the database is lost".
- **Why the panel's restore stays off.** All of this would have to run inside
  the library's restore, with an `afterRestore` reviewed as the 2026-10-11
  entry asks; the owner keeps it off, and the replay is the documented way back.
- **What it stops** (`tests/deletion-record.test.php`): a database put back
  from before a request, an anonymization and a cancelled request - the
  replay does all three again, the request with its very times, and ends every
  session from before; a dry run changes nothing; a second run nothing more;
  an account the database lacks keeps its id; events whose lines stand out of
  order are replayed in the order they happened; a replay that leaves no live
  administrator says so, dry and for real; it refuses while migrations wait.

## 2026-10-14 - anotoki-lib 0.6.0: the legal pages, with the IAM's facts

Both halves of the IAM take anotoki-lib 0.6.0 (`composer.json` `^0.6.0`, the
0.6.0 tarball). The owner's decisions of 2026-10-13, for every anotoki site:
**Terms, Privacy and Contact** from the library - their structure and the
family's words its own, each site giving its facts -, the operator Code Hero
s.r.o. (the family's default, written to at the support address), Slovak law,
16 the age for an account without a parent's permission, the hosting company
"to be named" until the owner named it (2026-10-18: WebSupport s.r.o., the
servers, the database and the mail). The texts are a good-faith draft for
the owner, not legal advice. What the IAM decided with them:

- **The pages are anybody's, in the family's frame.** On the library's paths
  (`/terms`, `/privacy`, `/contact`) - the IAM's Privacy page is the one every
  site links to for the account - in the frame the account pages have, whose
  bar is theirs for somebody signed in and Sign in for anybody else (the top
  bar's `public` area: no row of account links that would each be the sign-in
  page). Not the night-sky frame: a long page of text beside the sky reads
  badly, and the sky's column is the sign-in form's. The session is asked for
  before the first page when a legal page is where the site was opened, as on
  the account pages, so the bar and the account's language are right at once.
  The footer's links are on every sign-in and account page; not in the admin
  panel, which is English and the administrators'. (Since 2026-10-18 the admin
  panel has them too, in the family's footer - below.)
- **The facts are the code's, and say what the code does.** Twelve parts of
  what the IAM keeps - the account, two-factor authentication, Google, the
  sessions and the sign-in attempts with their addresses, the remembered and
  the known browsers, the sites and their sign-ins, the mails, the news, the
  approvals, the deletion and what stays -, the audit log and the backups in
  its own words, its cookies and stored items by name, and Google and the mail
  service in words true of it: Google's button is loaded from Google wherever
  it is drawn (the sign-in, sign-up and account pages, while Google sign-in is
  switched on), so the library's "only if you choose to sign in with Google"
  would not be; news goes to the accounts with it switched on, which is not
  only those who asked (an account made by an administrator, brought over
  from the genshin site or there before 013 starts with it on); the backups
  are made daily, before an update and by hand, never restored here, and a
  downloaded one is kept off the server. Where the code keeps something
  without a limit - the sessions and the remembered browsers with their
  addresses, the mail log, the audit log, the deletion record, the
  placeholder - the page says so. Migration 020 writes the strings (English and
  informal Slovak); a change to what the IAM keeps, or for how long, changes
  them and the day in `legal-facts.ts`. Terms' first heading is the IAM's own
  ("About the anotoki account"; the library's "What {site} is" read "What
  anotoki account is"), read in place of the library's word
  (`legal.config.ts`, as the kit's words are). And the sign-in pages' sky,
  which said "Nothing is shared with anybody outside anotoki" - untrue beside
  the page that names who else handles data -, now says "Nothing is sold or
  used for ads; Privacy says who else handles data" (020 rewords 006's words
  only, and only where no administrator saved the string - `updated_by` -: a
  saved one is the owner's choice, in 006's very words too, and stays with its
  writer; the security review of the pass).
- **Addresses only in the code** (the 0.6.0 review): every link of the pages -
  the account's Security page, where an account is deleted; the operator,
  Cloudflare, Google and the authority in the library - is the code's, never a
  string an administrator rewords; `tests/legal-pages-strings.test.php` holds
  020's strings free of any, and `legal-facts.spec.ts` shows a rewording
  changing a link's words and never its address.
- **Out of the first load, what only the pages read**: the root configuration
  (the language, the database's words) is there for the footer's links; the
  facts come with the pages' frame (`sites/main/legal/legal-layout`). The facts' English
  is in `en.ts`, as every page string is - the first load's growth (about
  10 kB of text before compression, 2.5 kB after).
- **The Slovak "ty" everywhere** (021): the sign-in page's two buttons that
  have a code emailed asked in the formal plural ("pošlite"); 019's had
  followed 006's on purpose, and both are informal now - where no
  administrator saved them on the Translations page (`updated_by`): a saved
  string stays, in the formal words too.

## 2026-10-14 - The owner's decision: Google only on a press (and anotoki-lib 0.6.1)

While Google sign-in was switched on, the sign-in, sign-up and account pages
fetched Google's script (`https://accounts.google.com/gsi/client`) as they
opened, to draw Google's own button: every visitor's browser contacted Google,
also one who never used it - and the Privacy page had to say so. The owner's
decision: Google is contacted only when somebody presses the button.

- **The IAM's own button first** (`sites/main/common/google-button`): a real `<button>` in
  Google's published branding for a sign-in button - the "G" in its four
  colours, drawn by the page (never fetched), Google's light and dark fill,
  stroke and words, a pill -, the IAM's words in the page's language ("Sign in
  with Google", "Sign up with Google", "Continue with Google": migration 022,
  English and Slovak), the kit's 44 px. Its colours are Google's, by its terms:
  beside the sign-in pages' night sky, the one place a page draws in colours
  that are not the family's tokens.
- **A press** fetches Google's script - once a page life;
  `GoogleSignInService.load()`, which nothing but a press calls - and draws
  Google's own button in the same place: only Google's carries its account
  chooser, so choosing the account is a second press, on Google's button. A
  live region says it is ready. The focus moves onto the frame that holds
  Google's button - which is Google's, where no page can put the focus -: the
  next Tab is Google's button, and Enter opens it. The id token comes back as
  before; the server's check (`google_identity.php`) is unchanged.
- **No script** - blocked, offline, not there within 15 seconds - or one that
  will not draw: the IAM's button stays, and a line under it says Google could
  not be reached (an alert, and the button's description); everything else on
  the page works, and another press tries again.
- **The Privacy page says so**: 022 rewords 020's `legal.google.role` - "your
  browser loads the button from Google" wherever it is drawn - to "only when
  you press ... does your browser load anything from Google", and its
  description; 020's words only, where no administrator saved the string
  (`updated_by`). The IAM keeps its own sentence over the library's ("only if
  you choose to sign in with Google"), which would leave out the press itself:
  Google sees the browser before anybody chooses an account.
- **Unchanged**: the CSP (it allowed Google's script, frame and style
  already), the "or" above the button (whenever there is one), the theme and
  the language followed (Google's button drawn again in the page's).
- **Tested**: the button's spec (no script, no frame and no request of any
  kind before a press; the script once; Google's button drawn, said and
  focused; the token out; the failure line; the words), 022's
  (`tests/google-button-strings.test.php`, on a fresh IAM and on installed
  ones as an owner may have left them), and a browser check with every request
  watched - none to Google before a press on the three pages, at 1280 and 390
  px, light and dark, English and Slovak.

Both halves take **anotoki-lib 0.6.1** with it: the library's translations 010
make the Privacy page's sentence on where the backups are true where an
administrator keeps a downloaded copy elsewhere, as `docs/operations.md` asks
the owner to. Nothing of the IAM's changed for it.

## 2026-10-18 - A local file is never compiled unless it starts with `<?php` (and anotoki-lib 0.6.2)

Japanese Academy's review found a hole in the round's lesson of 2026-10-13
("a local file is read cleanly or not at all", above), and it is the whole
family's: the output buffer catches what a local file prints, but not a file
PHP cannot compile. A `config/*.local.php` saved with a byte-order mark or a
blank line before `<?php declare(strict_types=1);` is a compile-time fatal
error ("strict_types declaration must be the very first statement", exit 255)
that no `try`, no error handler and no output buffer catches: every request -
`/api/site-status` included - answered 500 instead of "not set up", and a
`cloudflare.local.php` saved so never failed closed. (The files the installer
writes have no `declare`; one written or copied by hand may.)

- **`readLocalConfigFile()` reads a file's first five bytes before anything
  else** and, unless they are `<?php` (in any case), passes the file over
  without requiring it: its value null, its problem that it "does not start
  with <?php", said by that fault alone, never its text. What passing over
  means is as before: a required file leaves the IAM not set up
  (`iamSetUpProblems()` names it), the origin secret's fails closed, the
  installer shows none and saves over none. A file PHP may not open is no
  file either ("could not be read (PHP may not open it)"). It covers a bad
  start only: a file that starts with `<?php` and still cannot be compiled (a
  `declare` after another statement, a `break` outside a loop) stays a fatal
  error raised before anything can act - every request a 500, nothing printed,
  nothing served looser - and no slip of the hand makes one (the IAM's review
  of the change, 2026-10-18).
- **Nothing a good file needs is lost**: every file the installer writes
  starts with `<?php`, and one whose `<?php` comes after anything else would
  print that ahead of every answer anyway - so a byte-order mark, a line
  before it or no `<?php` line at all is now said by its start, not by the
  bytes it printed; "prints N bytes of its own" is left for text after a
  closing tag, or an echo.
- **Tested** (`tests/addresses.test.php`): both starts before
  `declare(strict_types=1)` for `cloudflare.local.php` (fails closed: a forged
  `CF-Connecting-IP` from Cloudflare's ranges is the edge, the file's own value
  too) and for `database.local.php` (not set up - the status says so, every
  other address 503 `not_set_up`, never a 500 -, nothing printed, nothing of
  the file in any answer or the log); with the check taken out the process
  ends at the require (PHP's fatal error, exit 255).
  `tests/setup.test.php`'s installer page names both of its broken files by
  their start.

Both halves take **anotoki-lib 0.6.2** with it: the library's translations 011
make three of the Privacy page's sentences true on every site (a feedback
message's reply address, the backups kept by count, the sign-in's note on a
site that signs in without a click), each only while it is still the
library's first wording and nobody saved it. None of the three is on the
IAM's Privacy page, which says its own words for the account, its backups and
its storage. Nothing of the IAM's changed for it.

## 2026-10-18 - A `cloudflare.local.php` that names no secret fails closed (the owner's "do as you think is the best")

The rule of 2026-10-13 (above) left one reading looser than the rest: a
`config/cloudflare.local.php` read cleanly that names no usable secret -
blank, a value that is no string or only spaces, a misspelt or another key, no
array, a forgotten `return` - counted as no secret, and so did a folder in the
file's place: `CF-Connecting-IP` believed from Cloudflare's ranges alone, so
every Cloudflare customer's Worker could write itself a new address for every
guess. Asked about it on the day of round 3's deploy, the owner said "do as
you think is the best". The whole family reads it alike (the build analyzer and
the survey first, the other sites with their footers). A rule of "The rules
that carry the weight" (7), tightened:

- **Only nothing there is no secret.** `iamOriginSecret()` returns null only
  when nothing is at the file's path (`localConfigThere()`, asked with the
  file, once a process: a file, read cleanly or not, a folder, a link to
  nowhere all count). Anything there that yields no `origin_secret` that is a
  string with something in it is a secret no request carries, as a file that
  cannot be read cleanly already was: every request from the ranges counts as
  its edge until the file names one. It is said in PHP's error log once a day
  by the file's name, never a value. Whoever put a file there meant a secret,
  and a slip in it never switches the check off; deleting the file does. A
  value a Worker could guess counts as none too (the review of the change):
  fewer than 32 characters, or a placeholder of the docs left in (`<` or
  `>`) - a guesser would know it was right when the limiter stopped tripping.
- **What it costs.** Nothing while the file and the zone's Transform Rule
  agree. A file emptied on purpose now counts every visitor as their edge, as
  `docs/operations.md`'s troubleshooting row says: delete it instead.
- **Tested** (`tests/addresses.test.php`): each of those files, and a folder
  in its place, fails closed. A forged `CF-Connecting-IP` from the ranges is
  the edge without the header, with an empty one and with the file's own
  value, for the IAM's limits and the library's alike. Through the app, a
  Worker's guesses, each with a new address written in, are counted under
  the edge. With the file deleted, Cloudflare's ranges alone decide again,
  and the set-up check never asks for the file, whatever it holds. With the
  old reading put back, both tests fail.

## 2026-10-18 - The family's footer under every page, the admin panel's too, and the status page bare (anotoki-lib 0.6.3)

After round 3's deploy the owner compared the six sites' footers - their
order, their words, a top border or none, links missing - and chose the build
analyzer's: "I like the footer of builder the most. The order, texts and the
top border." And of the page a site shows while it cannot be used: "I prefer
nor header nor footer to be there." anotoki-lib 0.6.3 made both the family's.

- **One footer, the family's** (`<anotoki-site-footer>`,
  `@anotoki/lib/footer`), in place of the IAM's own `layouts/site-footer`
  (Terms · Privacy · Contact, no border). It is the build analyzer's - a top
  border, the muted ink at 13 px, 20/16/24 px round it, everything centred -
  with a row above the links for the version, What's new and Feedback. The
  IAM has none of the three - its package is 0.0.0, it keeps no notes of its
  releases and it takes no feedback (its Contact page gives the address
  alone) - so it gives none and invents no version: its footer is the border
  and Terms · Privacy · Contact, which read the IAM's `provideAnotokiLegal()`
  as before. No page of the IAM says the footer's own words
  (`anotoki.footer.*`, the library's translations 012, listed on the
  Translations page like every library word), and no word of the IAM's went
  with its old footer, which said only the library's.
- **The admin panel has it too** (the owner: the footer in every admin
  panel), in English like the panel - the rule of 2026-10-14 above, "not in
  the admin panel", no longer holds.
- **The status page bare.** `/not-set-up` was a page of the sign-in pages'
  frame - the night sky, the language and theme switches, the footer. It is a
  route of its own now, on no frame: the family's `<anotoki-site-status>` in a
  `<main>` of its own, as the family's gate draws it on the sites that have
  one (the IAM has none: it never closes while an update waits). Nothing on
  it switches the language or the theme: it reads as the page starts.
- **Tested**: `app.routes.spec.ts` draws the four frames with the footer
  (after the page and last in its frame, Terms · Privacy · Contact alone, in
  the page's language - English in the admin panel - and the database's
  words) and the IAM's own routes for `/not-set-up`: bare in each closed
  state, and back by `location` to the page it came from once the IAM answers. With
  the old routes and the old admin panel put back, eight of its tests fail.
  The browser check looked at all of it at 1280 and 390 px, light and dark,
  English and Slovak.

## 2026-10-19 - The owner's decision: EARLY_ACCESS, a fourth role in every site

The owner: "Need to add another role like: Early access. With same
permissions as Member. The idea is, that when I will have a new project and
will allow specific people to participate in the Early access or beta version
or for testing purposes, I need to separate them from members so they will
have access there without making them admins or editors. This apply for every
website so far except the iam of course." It changes the rule of 2026-10-05
above, "the same roles everywhere", which were three. Migration 023:

- **EARLY_ACCESS in every site** - the first-party ones, the owner's own, every
  one made later -, "Uses the site while it lets in only chosen people (a
  beta, a test); given by an administrator". It may sign in, and nobody holds
  it until an administrator of the IAM gives it (Users → the person → Roles),
  as ADMIN and EDITOR are given and taken; MEMBER stays the one role every
  account holds. A fourth fixed role, not one the owner adds per site: 011
  took adding roles out of the admin panel, and the sites read one
  vocabulary.
- **A beta**: untick the site's MEMBER and give the testers EARLY_ACCESS. They
  sign in; an account holding only the MEMBER everybody holds is refused on
  every way in (`access_denied`), as on any members-only site. When the beta
  is over, tick MEMBER again; EARLY_ACCESS unticked lets nobody in by it, and
  its holders are then listed as blocked there.
- **Never the IAM's own entry** ("except the iam of course"): it keeps ADMIN
  and MEMBER, and the admin API refuses an EARLY_ACCESS there, as any role
  it does not have.
- **In the token's `roles` like any role.** A site treats it as it treats
  MEMBER - an ordinary signed-in person -, and it opens no admin area, which
  stays ADMIN's and EDITOR's (the family's `Person::isStaff()` is ADMIN or
  EDITOR): the sites need nothing new.
- **Who is blocked where counts it as every role** (`blockedFromSql()`,
  `sign-in-check.ts`, unchanged): a way in where it may sign in, an
  invitation where it may not.
- **Like every role since 011** it is never removed from a site, renamed or
  reworded in the admin panel; a site that wants no early access gives it to
  nobody, or unticks it. 016's `roles_for_application()` is made again with
  the fourth role, and a system entry still gets MEMBER alone.
- **On the installed IAM** 023 adds an EARLY_ACCESS nobody holds to every
  site that has none, and changes no role and no grant: Japanese Academy,
  Piano Academy and the build analyzer, which let only ADMIN in since
  2026-10-06, let in exactly whom they did. Not a one-way step like 010: a
  build from before it runs on the database as before - every rule reads the
  roles from the database -, and the build that brings it gives an
  application made before Apply the fourth role itself.
- **Tested** (`tests/early-access.test.php`, and the frontend's
  `sign-in-check.spec.ts`): 023 on a fresh IAM and on one built through 022 as
  its owner left it - every role, grant and way in as before, an
  EARLY_ACCESS of the owner's own kept -, its gate failing loudly and leaving
  nothing; a beta through the real endpoints - the testers in, with the role
  in the access token, the ID token and userinfo, an account without it
  refused on every way in -; the panel's lists; a new application's four
  roles; none in the IAM's entry; the window while 023 is pending.

## 2026-10-19 - The owner's decision: feedback in the IAM, its version 1.0.0 and What's new

The owner asked for the row the family's footer has on every other anotoki
site - the version, What's new and Feedback - on the IAM too, starting at
version 1.0.0. It changes two entries above: the admin tools' of 2026-10-11
("not feedback (its visitors write to the sites)") and the footer's of
2026-10-18 (the IAM "has none of the three ... so it gives none and invents
no version"). What was decided with it:

- **One version, one source**: the newest entry of the frontend's
  `public/changelog.json` - 1.0.0 first, a short summary of what the IAM does
  for people today - in the anotoki sites' changelog format, with the same
  lists in the IAM's informal Slovak beside the English
  (`translations.sk`). `frontend/package.json` (and `package-lock.json`'s
  two) say 1.0.0 too, held to the file by a spec. The footer shows it
  ("v1.0.0", read out "Version 1.0.0"); the list is asked for once a page
  load as the IAM starts, never waited for, and a list that cannot be had
  shows neither the version nor What's new. The web root's `.htaccess`
  serves the file `no-cache`: it has no hash in its name.
- **What's new is the IAM's own dialog** (`shared/changelog-dialog`, its code
  with the first press), opened by the footer's What's new and never by
  itself: nothing about what a browser has seen is kept, so nothing new is
  stored in it. Its own words - the heading, a version's name, Latest, Added,
  Fixed, Changed - are page keys (`whatsNew.*`, migration 024, English and
  Slovak); the sentences are the file's, in the reader's language where the
  file has them whole, else in English, marked so.
- **Feedback is the family's** (anotoki-lib's `Feedback`, its form and its
  admin page), as on the sites, under the IAM's rules:
  - `POST /api/feedback` (`routes/login.php`) takes a message from anybody -
    the sign-in pages have the footer too. Its sender is the account this
    browser's session names, by its id alone - the session only read
    (`optionalSession()`), never a body's word - or nobody; "send it without
    my name" keeps neither the account nor an address. A first-party write:
    the CSRF header and the IAM's own Origin, in neither CORS list, never an
    exemption. The library's limits, counted by the IAM's `clientKey` (the
    visitor's address through Cloudflare's ranges and the origin secret, an
    IPv6 address by its /64): 5 an hour and 20 a day from one sender, a
    signed-in person by their account and anybody else - and a message
    without a name - by their address; 50 a day from the visitors together
    and as many apart from the people signed in (the library's 300 lowered
    after the security review: the IAM's feedback is rare, and a disk filled
    with messages would stop every site's sign-in). The count is a file in
    `storage/cache` - a keyed hash of each sender and when, gone a day later,
    never in the database - under a key derived from the master key, as the
    error log's client hashes are: no other key file. Nothing of where a
    message came from is kept with it.
  - The form writes the account's address in as the reply address, until the
    person types their own or clears it - the IAM holds it already (the
    survey, which keeps nobody's address, writes none in). The server takes
    the sender from the session cookie whatever the page knows, so the form
    asks for the session first where a page never needed it (a sign-in
    page): its "without my name" and the address it writes in are then true
    of the message that goes. The IAM's interceptor gives the message its
    CSRF header and hands its failure back as it came - the library marks it
    as anybody's (`ISSUED_AS 'anybody'`) - so the form says each refusal in
    the reader's words and no page moves for it, as for the pages' own error
    reports.
  - The admin panel's Feedback page (`/admin/feedback`, the family's,
    lazy; a link in the side navigation, which has no count beside it - the
    page counts what is new; no mail is sent) and its routes, inside the
    admin group like every admin tool: a status change and a delete audited
    by the library's writer on the IAM's log (`iamAuditLog()`), the delete
    inside its own transaction.
  - **Kept** as the library keeps it - spam 30 days and resolved 365 days
    after they were marked, anything else until an administrator deletes it
    -, pruned at most once a day after the answer: when a message comes, when
    the Feedback page is opened, and at housekeeping, so a day nobody does
    either prunes too. Like every table, the messages are in the backups made
    after them, encrypted, until those go; and a database put back by hand
    from an older backup brings back a message an administrator deleted
    since - its deletion's audit entry names it and when it was sent.
  - **An account's deletion** takes the person out of their messages and
    keeps the words, as the Privacy page says: the messages it sent lose the
    reply address and the browser's name (the library's
    `Feedback::forgetPeopleOn()`), and so does any message whose reply
    address is the account's address - one sent signed out -, the IAM's own
    rule beside the library's, as the sign-in attempts and the mails of the
    address go with the account (`scrubFeedback()`, in the anonymization's
    transaction, and again at every housekeeping for a message a request on
    its way wrote). The words stay, under the placeholder's id. A message sent
    "without my name" keeps its browser's name: nothing ties it to anybody.
    What the address rule cannot reach (the security review of 2026-10-19),
    as with the sign-in attempts and the mails: a signed-out message naming
    an address the account had before a change, and one committed in the
    very transaction of the anonymization - matched by id the sweep repairs
    every message that was the account's own; one only typed with an address
    keeps it. The Privacy page promises "their messages", those by id.
- **The Privacy page** (`legal-facts.ts`) has the feedback part, the
  library's words - what a message keeps, what for, how long, the reply
  address only if the sender leaves one, what an account's deletion takes -
  true of the code as it is: the IAM changes none of the library's keeping.
  The Contact page offers the form first, the address for everything else.
  `IAM_LEGAL_UPDATED` 2026-10-19.
- **The first load** grows by 12.7 kB raw (4.6 kB sent): the form's words,
  which `provideAnotokiFeedback()` registers with the translations module,
  the kit's dialog service What's new opens in, the changelog's service. The
  build's warning budget goes from 500 to 525 kB, its reason beside it.
- **Tested**: `tests/feedback.test.php` - a visitor's message and a person's,
  the sender from the session alone whatever the body names, without a name,
  an account switched off sending as nobody, a page's query and the robots'
  field; the CSRF header and the Origin (a path only spelt otherwise
  included), no CORS answer, the refusals in the IAM's body; the limits - one
  sender's hour by address and by account, a message without a name counted
  by the address, an IPv6 network by its /64 through Cloudflare's edge, a
  forged `CF-Connecting-IP` from outside the ranges counted as its
  connection, the visitors' day apart from the people signed in -, nothing of
  a message in the error log; the admin routes refused to nobody, an account,
  a site's ADMIN and an administrator on the way out, every write's CSRF
  header, then read, marked and deleted, each audited; the deletion's scrub
  and the sweep after it; the prune at housekeeping; the table not there.
  `tests/whats-new-strings.test.php` holds 024. In the frontend: the
  changelog (its file against `package.json`, the Slovak, the service), the
  dialog, the form as the IAM sets it up behind its interceptor, the
  interceptor's handing back, the frames' footer and the Privacy and Contact
  pages.

## 2026-10-19 - The owner's decision: the new feedback counted beside Feedback (anotoki-lib 0.8.1)

Later the same day the owner took the count the entry above left out: beside
Feedback in the admin panel's side navigation, the number of messages nobody
has opened yet - the library's `FeedbackUnread`, asked once as an
administrator opens the panel and kept up by the Feedback page, drawn for an
administrator alone (the count lives as long as the tab, so whoever signs in
after an administrator without a reload never sees theirs).

The same release closes two findings of that day's security review:

- **Opening a message changes nothing on a GET.** The GET of a message marked
  a new one read, and a page on a sibling subdomain is same-site: its `<img>`
  went with the Lax session cookie. The GET is read-only now; the page marks
  a new message read by `PUT /api/admin/feedback/{id}/read`, a write of the
  admin group under the CSRF header, unaudited as the GET's marking was.
- **The Feedback page names only a proven sender.** A message sent signed out
  says "Not signed in", its reply address on a line of its own, marked as
  typed by the sender and not confirmed - the form takes any address anybody
  types.

Tested: the GET leaves a message new and the count as it was, the PUT marks
it and is refused like every admin write (`tests/feedback.test.php`); the
count beside Feedback for an administrator, and none for whoever signs in
after them in the same tab (`app.routes.spec.ts`).

## 2026-10-19 - The owner's decision: usernames are letters and digits

The same day's security review found (its Low 1) that a username could be
anything but empty, longer than 100 characters or a control character
(`usernameRefusal()`): it could look like an email address, or hold a
character that turns the direction of the text (U+202E and the others). The
admin panel's Feedback page names a signed-in sender by their username, so an
account named `jan@example.com` showed as "From jan@example.com" - the lure
anotoki-lib 0.8.1 took away from the messages sent signed out (above) - and
every site shows the name too (`preferred_username`). The owner: "add
validation so the username may contains only [a-zA-Z0-9]".

- **The rule** (`usernameRefusal()`, `api/helpers.php`): letters and digits
  only - `a-z`, `A-Z`, `0-9` -, at least one and at most 100. Anything else -
  an address, a space, a dot, a dash, an accented letter, a letter of another
  script (a Cyrillic a, U+0430, is no Latin one), a character that turns the
  text - is `422 invalid_username`, its sentence saying what is allowed.
  Uniqueness stays regardless of case (`usernameTaken()`).
- **Every way a name is chosen**: registration, the account page, the admin
  panel's create and edit. The installer and `create-admin.php` take no
  username - the first administrator's is made (below) -, and
  `createFirstAdmin()` holds one it is given to the rule.
- **Names from before are kept** - no transition: the owner is the only user
  today. A body that sends the account's own name back, unchanged (the spaces
  round it aside), changes nothing and is not checked (`usernameUnchanged()`),
  so an older name never stands in the way of saving the language, the theme
  or an address; only a new name is held to the rule. The import from the
  genshin site (`import-legacy.php`, the one-time move, done) moved the names
  it found and is left as it is. A deleted account's placeholder keeps its
  `deleted-{id}`: a dash no chosen name can have, so no account is named like
  one. The mails keep their room for the longest name there can be, an older
  one of 100 characters of four bytes each (`IamMailRules`).
- **The names the IAM makes fit** (`usernameFrom()`): a Google sign-up's -
  Google's name for the person, else the address before the `@`, else
  `player`, then a number while it is taken (`availableUsername()`; the name
  at most 90, so the number fits in 100) - and the first administrator's
  default - the address before the `@`, else `admin` (`createFirstAdmin()`).
  The Latin letters with a mark are written plain - Slovak's and Czech's
  first (á ä č ď é ě í ĺ ľ ň ó ô ö ŕ ř š ť ú ů ü ý ž), the rest of Latin-1's
  and Latin Extended-A's, Romanian's ș and ț; ß ss, æ ae, œ oe, þ th - by a
  table in the code (`USERNAME_PLAIN_LETTERS`), not the intl extension, which
  a host may lack; everything else is left out, spaces included. "František
  Pastorek" is `FrantisekPastorek`, `frantisek.pastorek@…` `frantisekpastorek`;
  a name of only another script's letters falls back to the address, then to
  `player` or `admin`.
- **The pages' words** (migration 025): `error.invalidUsername` says what is
  allowed - "Use only letters and digits (a–z, A–Z, 0–9), at most 100." and
  its informal Slovak - where it still says 006's "That username cannot be
  used." and no administrator saved it; a new key, `common.usernameHint`
  ("Letters and digits only (a–z, A–Z, 0–9).", the Slovak naming the accents
  besides), is the line under the field on the register page and the account
  page; and `register.usernameHint` - "What you would like to be called.",
  which asked for a name as people write theirs - goes, with its strings. Once
  025 is applied, no build from before it goes on the host again: it would
  take any name again - an address, a turned text, the lure this closes (the
  security review of 2026-10-19) - and show `register.usernameHint` as a key;
  fix forward. The register page and
  the account page ask the same rule before they send (`shared/auth/username.ts`),
  and so do the admin panel's create and edit, in its English; the server
  stays the rule.
- **Tested** (`tests/usernames.test.php`): `jan@example.com`, a right-to-left
  override, a space, a dot, an accented letter, a dash, a Cyrillic look-alike
  and a 101st character refused on registration, the account page's rename and
  the admin panel's create and edit, nothing written; letters and digits
  taken; an account with an older name saving its language and its theme with
  its name sent back, and an administrator changing its address; a Google
  sign-up's name and the first administrator's made to fit (the examples
  above, an accent written apart, the fallbacks, the number, the length); the
  legacy import keeping a name the rule refuses; 025 on a fresh IAM, on one
  built through 024 as the files left it and as its owner left it, and its
  gate failing loudly. In the frontend, the rule's spec
  (`shared/auth/username.spec.ts`).

## 2026-10-19 - The owner's decision: a release is frontend/ and backend/, replaced whole

The IAM was two builds - the Angular app with its `.htaccess` (`npm run
build:deploy`) and `backend/dist/` (`build-dist.php`) - merged by hand into one
web root and uploaded over the old files. FTP never deletes, so every earlier
build's hashed bundles and removed PHP files stayed, mixed with
`config/*.local.php` (the master key among them) and `storage/`: nothing could be
deleted wholesale, and nobody could tell what was stale. Now the anotoki
library's anotoki-deploy builds one release from `backend/composer.json`'s
`extra["anotoki-deploy"]` (the same tool for every site): `frontend/` (the app,
`index.php`, `.htaccess` - `/api` and `/.well-known/openid-configuration` and
`/jwks.json` to the front controller, the CSP and the headers that
`prepare-deploy.mjs` wrote before) and `backend/` (`api/`, `config/`'s loaders,
`migrations/`, `vendor/` - never `setup.php`, the command-line tools or a
`*.local.php`), put up beside the live one, checked, and swapped in by renames,
the one before kept for a rollback. The folder's `.htaccess`, the switch, sends
every request into `frontend/`: `backend/`, `config/` and `storage/` are never
served - by construction, no longer by a `[F]` rule and the folders' own deny
files alone. The PHP finds its state with one rule, `iamStateRoot()`: with
`release.json` in `backend/`, the folder above it; in a checkout, `backend/`
itself. `prepare-deploy.mjs` keeps its three checks (no inline script, no string
of the library drawn as HTML, the visitor counter's tag), run with every
release's build. The Authorization header reaches PHP through `CGIPassAuth` (and
`SetEnvIf`) in `frontend/.htaccess`: `api/.htaccess`'s `E=` copy is gone with
`api/`'s own folder rules.

## 2026-10-19 - The security review's three older findings: a confirmation link left over, a Google token used again, a confirmation opened for somebody else

The IAM's security review of 2026-10-19 (of the commit that answers a site's
`prompt=login` with a sign-in after the request) found three holes older than
that commit, in how a sign-in is proved. The owner: "Fix all three". None
loosens a rule; each adds to one (`docs/architecture.md`, rules 4, 12, 13).

- **Medium: a confirmation link left over signed in past two-factor, and said
  it had asked for it.** "Send it again" mails a second link; the first, never
  opened, stayed good for its 48 hours after the second confirmed the address,
  and `POST /api/register/confirm` never asked whether the address was
  confirmed already, nor for a second factor. Reproduced by the reviewer:
  register, a second link, confirmed by it; two-factor switched on, so a
  password sign-in asks for the code; then whoever holds only the mailbox
  posts the first link from another browser - a 30-day session,
  `{"method":"email_link","mfa":true}`, and ID tokens whose `amr` said
  `["email","otp","mfa"]`: `completeSignIn()` recorded `mfa` as the account's
  two-factor switch, whether or not a code was checked. Now:
  - **Every confirmation link of an account is spent once its address is
    confirmed** - by a link, a reset, Google's claim, an administrator, the
    command line's `reset-password.php` -, when an administrator changes the
    address (the links went to the old one), and when two-factor is switched
    on (`spendConfirmationLinks()`, `api/one_time_token.php`). Migration 026
    spends the ones an address confirmed still held from before it.
  - **A link of an address confirmed already opens no session**: `200
    {"signed_in": false, "reason": "already_confirmed"}` - nothing of the
    account -, and the page offers the sign-in page. A link that would sign in
    an account with two-factor on (one the genshin site's import brought,
    unconfirmed) asks for its code as a reset does, under the authenticator's
    pause (`authenticatorCheck()`, attempts under `email_confirm`), before
    anything is spent or changed; no remembered browser stands in, as on a
    reset. And it never opens a session for an account with two-factor on
    without a code checked by that request (`sign_in`, if two-factor came on
    while the link was on its way).
  - **`mfa`, and so `amr`, says what the sign-in checked**:
    `completeSignIn()` takes it from each way in (`secondFactorAnswer()`, which
    was `totpRefusal()`, answers whether a factor was checked), never from the
    account. True for an authenticator or recovery code checked now, for a
    remembered browser standing in for one (as before), and for the
    password's sign-in finished by a code by email during the pause
    (`password_email`, as defined); false otherwise - Google claiming an
    account whose registrant had switched two-factor on (it goes with the rest
    of the registrant's), a reset of such an account, a new account. A browser
    is remembered only by a sign-in that checked a second factor.
- **Low: a Google id token could be used again within its hour** - Google
  signs one for an hour, and `googleVerifyIdToken()` asked only `exp`; since
  that commit a reused one answered a site's "Sign in again" too. Now a token
  is taken only while its `iat` is at most five minutes old, with the clocks'
  minute either way (`GOOGLE_ID_TOKEN_MAX_AGE`, `GOOGLE_CLOCK_SKEW`; none
  without an `iat`), and only once: spent the moment it changes something - a
  session opened, an account made or claimed, an identity attached, Google
  connected on the account page (`spendGoogleIdToken()`) - and refused ever
  after, `400 invalid_token` like any token that does not verify. Not before:
  a sign-in stopped for its second factor spends nothing, and the page sends
  the same token again with the code (the login page, given a refused token
  at that step, goes back to the form, where Google's button is). The store
  is a table, the house's way for a secret that is good once (codes, links,
  refresh tokens): `google_id_tokens_used`, migration 026 - the SHA-256 of
  what Google signed, `header.payload`, and when the token runs out; swept by
  housekeeping after that. Of what Google signed, not of the whole token:
  base64 leaves bits of the signature's last character unread, so the same
  signature can be written several ways, each verifying, and a hash of the
  whole would take each for a new token (`tests/google-tokens.test.php` writes
  one so). The row says nothing of the person - no address; an account's
  number only while a sign-in or a connect holds it (the reviews below) - and
  lives until housekeeping's first run after the token ran out (`exp` and a
  minute; housekeeping comes with traffic), so the Privacy page, which lists
  what the IAM keeps of a person, is unchanged. Before 026 is applied a token is held to its five
  minutes alone (`googleTokensReady()`). A nonce bound to the page was not
  added: the freshness and the single use are what was asked, and Google's
  script still loads only on a press.
- **Low: opening somebody else's confirmation link signed this browser in to
  their account.** The page posted the link as soon as it opened: an
  attacker's own new account's link, sent to a victim, signed the victim's
  browser in to the attacker's account, ended the victim's session, and a
  sign-in a site had waiting in that browser then got a code for the
  attacker's account. Now the rule is the server's - the link never puts its
  account's session in place of another account's this browser has: it
  confirms the address (it is the link's to confirm), the session stays, and
  the answer says so, `{"signed_in": false, "reason": "other_account"}` - and
  the page's: it sends the link only when the person presses "Confirm my
  address", and then offers to sign out and sign in with the confirmed
  account, or to stay. A browser with no session is signed in by the press, as
  before.
- **Migration 026** brings the table, spends the leftover links, and the
  confirmation page's six words (`confirm.readyText`, `confirm.submit`,
  `confirm.totpText`, `confirm.signInText`, `confirm.otherAccountText`,
  `confirm.signOutAndIn`) in English and an informal Slovak. Once it is applied,
  no build from before it goes on the host again: it would open the three
  holes again, and its confirmation page would read a confirmation without a
  session as a sign-in; fix forward.
- **Tested** (`tests/confirmation-links.test.php`,
  `tests/google-tokens.test.php`, each run once against the code from before,
  failing where the holes were): the reviewer's sequence step by step - no
  session, no cookie, no code for a waiting site, with the code neither; a
  link confirmed and opened again; every way an address is confirmed - a
  reset, Google, an administrator, a changed address - and two-factor on
  spending the links; a live link of an address confirmed opening nothing;
  the code asked for by a link, under the pause, a recovery code standing in;
  `amr` per way in - the password (alone, with a code, a remembered browser),
  the emailed code, Google, a reset, a confirmation link, each with two-factor
  off and on, and Google's claim of a registrant's account; another account's
  link leaving the session, so a waiting site's code is the signed-in
  account's; 026 on a fresh IAM, on one built through 025 with leftover links,
  and its gate failing loudly. Google: a used token refused from another
  browser and written another way; six minutes old, two minutes ahead, no
  `iat`, refused at the sign-in and on the account page; the second factor's
  step spending nothing; every way of spending it, across the sign-in and the
  account page; two at once; the sweep; the window while 026 waits. The
  frontend's `confirm-email.component.spec.ts`: nothing sent as the page
  opens, the press, `other_account` leaving the session and offering sign-out,
  `already_confirmed`, the code's step.

## 2026-10-20 - The review of that change: a Google token held for its sign-in, and six smaller things

The security review of d0e34fb (the entry above) found one Low and six Nits,
no more; all are fixed, and migration 026 - applied on no live IAM yet - was
amended rather than followed by a 027.

- **Low: a Google token left unspent at the second-factor stop could be spent
  elsewhere.** The Google sign-in spent a token only at its first change, so
  one stopped at `totp_required` - or refused `unverified_email`,
  `registration_disabled` - stayed free for its five minutes, and
  `POST /api/account/google` took any fresh, unspent token: whoever saw a
  victim's token (two-factor on, no Google yet) connected the victim's Google
  to an account of their own, the victim's code then failed, and every later
  sign-in with that Google account landed in the attacker's account - a
  site's waiting sign-in too. Now, from the moment the Google sign-in finds the
  account a token names - by its identity, else by the address Google vouches
  for - the token is held for that account's sign-in (`held_for_user` in
  `google_id_tokens_used`, `holdGoogleIdToken()`), before anything else is
  asked, the limiter included: so every answer after that leaves it held or
  spent, never free. Held, it is refused to everything else - the account
  page's connect (which, since the review of 632a464, holds it for its own
  account first; the check of "used here" that once stood for it,
  `googleVerifyIdToken()`, no route asks any more and only the tests keep -
  it refused any token used here,
  however) and another account's sign-in - and the same account's sign-in
  claims the hold when it spends it (`UPDATE ... WHERE held_for_user = ?`,
  one row: a racing request loses). A token that names no account is spent at
  once, since nothing a second try with it could cure follows: an account
  made of it, or `unverified_email`, `registration_disabled`. The hold names
  the account by its number until housekeeping sweeps the row - its first run
  after the token ran out (`exp` and a minute), which comes with traffic, so
  an hour and more -: a sign-in's own state while its token lives, nothing of
  the person beyond the number, so the Privacy page stays as it is. (Most
  such stops are also among the sign-in attempts - a wrong code, a wrong
  password -, but not all: a `429` or a refusal of the account records no
  attempt.) An account deleted meanwhile leaves its holds spent
  (`anonymizedLeftovers()`), and a `users` row deleted outright too (`ON
  DELETE SET NULL`). The
  alternative, Google's nonce bound to the browser by a cookie, was not
  simpler: it needs a cookie and a round trip before every press, and still
  the hold for the code's step.
- **The confirmation's "only while the address waits" is decided in the
  write**: `UPDATE users SET email_confirmed = TRUE WHERE id = ? AND NOT
  email_confirmed`. A link whose request read the account a moment before a
  reset, Google, an administrator or another link confirmed it opened a
  session all the same - after a reset, one that could outlive "a reset
  takes an account back completely"; two racing links gave two sessions. Now
  the write finds the address confirmed, the links are spent, and the answer
  is `already_confirmed`.
- **The docs said a refusal of the account spends nothing**, while the
  sign-in by the address spent the token before it: for an account confirmed
  all along the token is now spent just before the identity is attached, its
  first change - a refusal before that leaves it held -, and for one never
  confirmed just before the registrant's settings are taken.
- **Sessions the hole had opened kept saying `mfa`**: `amrFor()` reads
  `sessions.mfa` at every mint, so a session a leftover link opened went on
  minting `["email","otp","mfa"]` for up to 30 days. 026 ends the live
  `email_link` sessions of accounts with two-factor on whose `mfa` is true,
  with their refresh tokens, for a `security_change` - as the IAM ends one.
  A reset's session among them had its code asked: its owner signs in once
  more, the cost of telling the two apart nowhere else.
- **`already_confirmed` for a link of an account moved elsewhere** told the
  old address's holder that the account lived on, confirmed. A confirmation
  link now notes the address it was mailed to - the SHA-256 of it, lower
  case, in the link's context, never the address -, and confirms that
  address, or says it is confirmed, only while it is still the account's;
  otherwise `invalid_token`. Links mailed before the mark are not taken as
  confirmed (`invalid_token`), a cost of a few days' old mail at most.
- **The confirmation page names the account it signed in to** ("Signed in as
  {name} ({email}).", `confirm.signedInAs`, 026's seventh key, in English and
  informal Slovak): somebody who pressed a stranger's link sees whose account
  it is before a site's waiting sign-in takes it.
- **Tests** (`tests/google-tokens.test.php`, `tests/confirmation-links.test.php`,
  `confirm-email.component.spec.ts`): the Low's attack - a token stopped at
  `totp_required` refused by the account page (failing against d0e34fb), then
  finishing the victim's own sign-in -; `unverified_email` and
  `registration_disabled` spending at once, refused by the account page
  after (failing against d0e34fb); a refusal of the account holding the token
  until it lifts, and a held token naming another account by then refused;
  the hold claimed once, by its own account; a deleted account's and a deleted
  row's holds spent. The write's race - the address confirmed between the
  read and the write, by a trigger at the moment the link is spent (failing
  against d0e34fb) -; two-factor switched on in the very write that confirms
  (`sign_in`); the mark of the address, and a link of a moved account,
  spent or live, saying nothing (failing against d0e34fb); 026 ending the
  sessions that say `mfa` - and nothing else - and its gate failing on one
  left; the page naming the account.

## 2026-10-20 - The review of that follow-up: the account page holds a Google token too, and five smaller things

The security review of 632a464 (the entry above) found one Low and five Nits;
all are fixed, and migration 026 - still applied on no live IAM - was amended
once more rather than followed by a 027.

- **Low: the account page's connect left a token free while it waited for
  the password.** `POST /api/account/google` asked for its proof before it
  held the token, so after a wrong password - or a sign-in too old, the
  limiter, a `409` - the token stayed free, and the page sends the same one
  again with the right password. Whoever saw it meanwhile connected it to an
  account of their own; the victim's retry was refused, and every later
  sign-in with that Google account landed in the other account (the reviewer's
  probe). Now the connect checks Google's claims and holds the token for the
  account connecting it before anything else (`holdGoogleIdToken()`): spent,
  or held for another account, it is `400 invalid_token`; every refusal after
  that leaves it held for this account, so the retry takes it and nobody
  else's connect or sign-in does; connecting claims the hold
  (`spendGoogleIdToken(..., $userId)`). The cost: a token refused
  `409 google_taken` - that Google account connected to another one already -
  is no longer free for that Google account's own sign-in; Google's button
  gives a fresh token.
- **And the connect takes no Google account whose address another account
  has confirmed** (the reviewer's optional hardening, `409 email_taken`):
  where Google vouches for an address another account here has confirmed, the
  sign-in would attach that Google account to it, so it is that account's to
  connect - connected elsewhere, every later sign-in with it would land there.
  Only a confirmed address counts, where the reviewer said "another account's
  address": anybody can register an address they do not hold, the sign-in
  hands such an account to whoever Google vouches for
  (`claimUnconfirmedAccount()`), and a stranger's registration must not keep
  the address's owner from connecting their Google, as it must not keep them
  out by a decline (`routes/admin.php`).
- **Nit: the confirmation's address check was on the read only.** The write
  that confirms now asks for the address its request read too (`AND
  lower(email) = lower(?)`, case aside as the mark is), and a link that
  confirmed nothing - the write found the address confirmed, or a request
  racing it spent the link first - says `already_confirmed` only while its
  mark is the account's address now (`confirmedMeanwhileFor()`); otherwise
  `invalid_token`. An administrator moving the account at that very moment:
  the new address is neither confirmed by a link to the old one nor said to
  be.
- **Nit: links from before the mark.** 026 also spends every live
  confirmation link that notes no address: mailed by a build from before the
  note, one may have gone to an address its account has left since - a build
  from before 026 moved an address and spent nothing - and nothing could
  tell. Its gate fails on one left. Somebody in the middle of signing up
  presses "send it again".
- **Nit: two orderings held to by tests**, no code changed: the limiter's
  `429` on a token naming an account with two-factor on leaves it held, and
  the account page refuses it; a confirmed account found by its address (no
  Google yet), switched off, gets `403` with the token held and nothing
  attached.
- **Nit: tokens for scripts after 026.** A session a leftover link opened
  could make a token for scripts, which belongs to no session, so 026 does not
  end it: `docs/operations.md` tells the owner to look at the account page's
  "Tokens for scripts" of the accounts with two-factor on after the Apply.
- **Nit: three wordings** - a hold lasts until housekeeping's first run after
  the token ran out (with traffic), and a `429` or a refusal of the account
  records no sign-in attempt (the entry above); housekeeping sweeps held rows
  too (`housekeeping.php`); and 026 no longer says a database that ran its
  first version gets the hold by applying again - the engine never runs a
  recorded file twice: such a database deletes 026's row from `migrations`
  and applies again, every statement in it safe to run twice.
- **Tests** (`tests/google-tokens.test.php`,
  `tests/confirmation-links.test.php`): the Low's probe - a wrong password,
  then whoever saw the token connecting nothing, the victim's retry connecting
  it, and the victim's Google signing the victim in (failing against
  632a464) -, and the same after a sign-in too old, the limiter and
  `google_already_connected`; `google_taken` holding the token (failing
  against 632a464); the address of another account confirmed refused, the
  address's own account connecting it, a stranger's unconfirmed registration
  not counted (failing against 632a464); the two orderings (passing against
  632a464 too: held to now). An account moved while a link's request ran, by
  a trigger at the moment the link is spent; moved and confirmed; outrun by a
  request that spent the link first and moved and confirmed it (each failing
  against 632a464); the address in other letters still its address, and an
  outrun link of its own address saying so. 026 spending every link that
  notes no address - the one to an address its account left confirming
  nothing after, a waiting address's noted link confirming it - and its gate
  failing on one left (both failing against 632a464).
- **The review of 62af218** (2026-10-20, four optional nits; the owner: "You
  can do the optional as well"). A confirmation link is taken only when it
  notes the address it was mailed to and that address is the account's: one
  noting none - or noting it as anything but the mark - is `invalid_token`,
  never `already_confirmed` (026 spent every such link; the route now says it
  is none of ours, which also makes 026's looser test of a note - its SQL asks
  `->> 'address' IS NULL`, the code `is_string()` - moot). `googleVerifyIdToken()`,
  which no route asked any more, went into the tests (`google-tokens.test.php`),
  so no future way in can reach for a check of "used here" that holds nothing.
  026 itself is applied and stays as it is; its note on a development database
  that ran an earlier version of it holds for every earlier version.

## 2026-10-24 - The owner's decision: the frontend in the family's folders, the genshin site's

"The admin panels in all projects should look like the genshin one... I mean
the UI structure itself and the frontend folder structure as well (like
frontend/src/app -> api, shared, sites and frontend/src/app/sites -> admin,
main)." The folders came first, a move with nothing else changed; the admin
panel's frame - a left column like the genshin site's, the family's from
anotoki-lib 0.10.0 - comes after.

- **Where a file goes is decided by who uses it.** `api/` is the hand-written
  API layer (`core/api/` before). `shared/` is what both halves use: the rest
  of `core/`, What's new (`changelog-dialog/`), the sessions' words
  (`i18n/session-labels.ts`, which the admin panel's sessions read as the
  account's do) and the top bar (`top-bar/`: the admin panel's frame draws it
  too, until the family's admin frame takes its place there). `sites/main/` is
  the IAM's own pages - the sign-in pages with their frame and card (`auth/`),
  the account pages (`account/`), the legal pages' frame and facts (`legal/`)
  and what they share (`common/`: the Google button, Google's script, the
  remembered-device token); `sites/admin/` is the admin panel - its frame, a
  folder per group of pages and what those share (`shared/`). What only one
  half uses is in that half.
- **The routes in two halves**: `app.routes.ts` holds `/admin`
  (`sites/admin/admin.routes.ts`, lazy as before) and then
  `sites/main/main.routes.ts`'s routes. The genshin site loads its main half
  with a `loadChildren`; the IAM imports its routes instead, so every address,
  guard and title, the first load and every lazy chunk stay as they were.
  `/admin` comes first: the sign-in pages' not-found page would swallow it.
- **The backend's scan of the pages' keys** reads the compiled English where it
  now is (`TRANSLATION_DICTIONARY_FILE`, `shared/i18n/en.ts`).

## 2026-10-24 - The owner's decision: the admin panel in the family's admin frame (anotoki-lib 0.10.0)

The second step of the same request: "The main idea is to have the menu on
the left, not on top, and on the right the content" - every admin panel like
the genshin site's, so that it is never mistaken for the site. anotoki-lib
0.10.0 made genshin's frame the family's, `<anotoki-admin-frame>`
(`@anotoki/lib/admin/frame`), and the IAM's admin panel is in it
(`sites/admin/app.component`, named as genshin's).

- **No top bar in the admin panel.** The column on the left is its frame:
  the panel's name as the bar said it ("anotoki account · admin panel",
  linking to the dashboard), who is signed in (the username and the address),
  "Back to your account", Sign out - the bar's very function, now
  `SignOutService`, which both call - and the theme (the family's switch over
  the shell's ThemeService: the account's theme, as on every page). Then the
  sixteen pages the side navigation had, nothing added or taken away, under
  headings: the dashboard, then Access (Users, Approvals, Sessions,
  Applications), Mail (Announcements, Email log), Localization (Translations,
  Languages) and System (Settings, Signing keys, Audit log, Error log,
  Feedback - the new ones counted beside it, for an administrator alone, as
  before -, Backups, Migrations). The update line above the page, the footer
  under it, as before; the frame's own words are the kit's, which the IAM
  says with its keys, in English in the panel.
- **The closed panel keeps its words and its way out.** "You don't have
  access" and "Your account is being deleted", each with "Go to your
  account", are drawn with no frame round them - nothing suggests there is
  more to see -, the footer under them. Who may use the panel is as before:
  the IAM's ADMIN, the server's `requireAdmin()` unchanged.
- **The top bar is the account and legal pages' alone**: its admin area (the
  admin wordmark, "Back to your account", no language switch) is gone, and
  the bar moved to `sites/main/common/top-bar`. Nothing the bar shows on
  those pages changed, so its fold (`PHONE_BAR_MAX`, 1040 px) stays.

## 2026-10-25 - The owner's yes: the leftovers of 010, 011 and 018 dropped (migration 027)

Five things were kept "until a later migration drops it, once both IAMs run"
the file that left them over, and both IAMs - this computer's and the host's -
have run 010, 011 and 018 since. The owner said yes to dropping 010's and
018's now, and - asked the same day about 011's three, which the entry of
2026-10-05 said would go with 010's `access` - yes to those too, in the same
027 (applied nowhere yet, so extended rather than followed by a 028). The
five: `applications.access`, `applications.type`, `applications.secret_hash`,
`applications.show_on_landing` and `audit_log_before_018`.

- **`applications.access`**, the rule before 010 ('everyone', or 'members':
  a role there held), was read only while 010 was pending:
  `signInRulesReady()` asked for 010's columns on every sign-in and fell back
  to the old column without them, the admin panel's application routes and
  the sites' public contract answered `503 sign_in_rules_unavailable`, and the
  users pages showed nobody blocked. All of it went: who may sign in is each
  role's `may_sign_in`, as it has been on both IAMs since 010 was applied, and
  nothing that decides a sign-in changed (`rolesKeepOut()` asks the one rule).
  A database without 010 is now none this build runs on, and on one it fails
  closed: the rule asks for a column that is not there, and no code reaches a
  site; the sites' public contract names its columns, so it is a 500, which
  every SDK reads as "sign-in required" - never `login_required: false` read
  off a key a whole row does not have, cached for a minute, which would open
  every site that requires signing in to visitors (the security review of
  2026-10-25 found that hole in the first cut) -, and the admin panel's
  application page fails the same way, its roles' query naming `may_sign_in`
  (`tests/sign-in-rules.test.php`). The approvals' `503
  sign_in_rules_unavailable` while 012 is pending stays.
- **`applications.type`, `secret_hash` and `show_on_landing`**, which 011
  left unread when every client became public and the landing page settings
  went. Nothing read them, as was checked before they went: the token and
  revocation endpoints refuse a request carrying a client secret
  (`client_secret` in the body, even empty, or HTTP Basic) by what it
  carries, before any row is read (`clientSentSecret()`) - the refusal never
  asked `secret_hash`; authorize asks every client for a PKCE challenge
  whatever its row, and the code exchange redeems no code without one; the
  admin panel refuses a body's `type` other than public by the body and
  ignores its `show_on_landing`; the public list is every site switched on,
  in `sort_order`, which stays; `php clients.php` printed `type` and no
  longer does; the SDKs and the pages never knew them. Nothing that decides a
  sign-in changed: `tests/sign-in-rules.test.php` shows it on a database
  without the columns (public clients only, the code exchange's refusal of a
  code without a challenge among it - untested until the review of
  2026-10-25), and `tests/dropped-leftovers.test.php`'s window before the
  drop and after it, with the survey site's row edited by hand to say a
  confidential client holding that very secret: the secret refused all three
  ways it can be sent, at both endpoints, spending nothing; no code from
  authorize without a challenge; no tokens for a code without one.
- **`audit_log_before_018`**, the IAM's own audit log as 018 left it, every
  entry of it in the library's `anotoki_audit_log` (018 proved the move in its
  gate), unread since and scrubbed by an account's deletion like the log -
  still, for as long as it is there (below). 027 drops it whole, losing no
  event. The old table under its old name, `audit_log` - while 018 waits, on a
  database put back by hand from a backup made before it - is still scrubbed,
  and the entries that wait for 018 still wait in their file.
- **027 is one migration**, the five drops, its gate first asking that 010
  and 018 are what they say (each role's `may_sign_in`, each application's
  `login_required`, 010's two triggers; the library's audit table, the old
  name gone) - on a database without them it would drop the one thing it still
  reads, so it stops, naming all that is missing - and after the drops proving
  all five gone and `sort_order`, 010's columns and the library's table still
  there. (The first cut's gate collected what was missing with `missing ||
  '...'`, which PostgreSQL reads as an array literal: it stopped all the same,
  dropping nothing, but in PostgreSQL's words about the gate, naming only the
  first thing missing; it uses `array_append()` now, and its test asks for
  its own words, two causes at once.) Tested on an IAM built through 009 as
  an owner left it - a confidential client with its secret, a card taken off
  the landing page among it -, then 010 to 026: the code itself asked, for
  every account and every application, before and after - the same answer,
  account by account - and every application's other columns as they were
  (`tests/dropped-leftovers.test.php`).
- It is no one-way step for a build from 018 on: one from before 027 still
  runs on a database that ran it, reading none of the five (its
  `php clients.php` alone looked at `type`, only to print it). An older build
  goes on the host in no case - none from before 010, 011 or 018 once those
  are applied - and with 027 applied one from before 011 would also skip PKCE
  for every client: it reads the missing `type` as a client that is not
  public, so authorize asks for no challenge, the code exchange redeems a code
  without one, and no client secret is checked (the security review of
  2026-10-25 caught the first wording, which said "a build from before 027").
- **The window is scrubbed too.** Between the upload and the Apply, a
  deletion that falls due takes the person out of the old copy as it always
  did - their addresses, the names in what was about them, a token's name -
  and the Apply's backup, made before 027 drops the copy, keeps nobody
  deleted (the security review of 2026-10-25: the first cut scrubbed only
  the library's table, and that backup would have kept the person until the
  owner deleted it). Once 027 has run there is no copy, and nothing to do
  (`tests/dropped-leftovers.test.php`, the window).

## 2026-10-25 - The owner's requests: site settings (the announcement alone), robots.txt and sitemap.xml, the library's pieces, one admin menu (anotoki-lib 0.11.0)

"I would like the projects to use the library primarily": genshin's site
settings made general, robots.txt and sitemap.xml on every site, the pieces
each site drew itself from the library, and every admin menu in one shape.

- **The announcement alone.** The family's site settings are Getting in
  (maintenance), the announcement and Page access; the IAM takes the
  announcement (the owner's decision): closed for maintenance the IAM would
  stop every site's sign-in and refresh, and the admin panel's own way in; a
  page switched off, or kept for some readers, could hide a sign-in page or
  the account page where a deletion is asked. So the IAM's `SiteState` gets no
  settings (its state is never `maintenance`), no `PageAccessGate` is mounted,
  and the library refuses a switch of either part (`part_not_offered`); a row
  of either put into the tables by hand is read by nothing
  (`tests/site-settings.test.php`). The bar is drawn above the sign-in, account
  and legal pages' frames - never the admin panel's, where an administrator
  needs no notice of their own words - and read once as the pages start, never
  waited for: the IAM governs no page by it, so nothing has to be known before
  the first page is drawn, and its failure leaves no bar and moves no page.
  The admin routes sit beside the admin group (the library mounts them at their
  own path) under the same checks - the session, `requireAdmin()`, the
  library's ADMIN check, the CSRF header - each change audited.
- **robots.txt and sitemap.xml** from the PHP: robots kept out of every
  sign-in page and the account pages (some carry a mailed token in their
  address) and out of `/.well-known/` - the discovery document and the key set
  are for applications, never a page for a search, and robots.txt binds
  crawlers alone, so no OIDC client or SDK is touched; the sitemap lists the
  legal pages, About and Support, nothing else of the IAM's. Both by the
  configured issuer, never a request's `Host`.
- **The dates** are the family's (`@anotoki/lib/ui/dates`) in place of the
  IAM's `appDate` and `formatAppDate()`: the same day, time and moment for
  the reader, in the page's locale and the account's date order and clock.
  How long ago differs a little, as the family counts it: under a minute
  "just now" (was "now"), minutes and hours counted down rather than rounded,
  and days by the reader's calendar ("yesterday" for any time yesterday); the
  date from a week away, as before. The admin panel's own pages take their
  writer from the panel's routes, so the dates' code stays out of the sign-in
  pages' first load.
- **One admin menu**: the IAM's own pages at the top with no heading
  (Dashboard, Users, Approvals, Sessions, Applications, Announcements, Email
  log - the Access and Mail headings gone), Localization, then System - the
  IAM's own (Settings, Signing keys), then the family's in the family's order
  and words (Site settings, Feedback, Error log, Audit log, Backups,
  Migrations; the family's People page is none of the IAM's: its Users page
  is the accounts). Who sees the panel is unchanged.
