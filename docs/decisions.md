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
  again); the migration that drops 010's `access` drops them too.
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
- **Nobody is signed out.** A browser from before carries `anotoki_iam`: it
  is read while no `__Host-` cookie comes, and the next answer that uses the
  session - the account pages (every IAM page asks for the account first), a
  site's single sign-on, a sign-in - sets the same secret under the new name,
  for as long as the session lasts, and expires the old one with the
  attributes it was set with. Every sign-out and every refusal that drops the
  cookie expires both names. The device cookie keeps its name,
  `anotoki_iam_device`: under a new one every browser would be a new one, and
  everybody would be mailed.
- **What stays until the bare name is no longer read**: a browser with no
  `__Host-` cookie - nobody signed in at the IAM there - still takes a bare
  `anotoki_iam`, so one planted there can still sign that browser into the
  planter's session, as before; it never overrides the browser's own, and a
  sign-in ends it. The sign-in hint makes it worse: planted beside it, it
  makes a site that lets visitors in hop to the IAM, which signs the visitor
  into the planter's account there without a click. Whether to close it
  sooner is the owner's decision; until then a later release stops reading
  the bare name, once every session from before has ended - ninety days at
  most.
- **One place.** The cookie is read (`sessionSecretFrom()`), set, expired and
  renamed in `api/session.php` and nowhere else; the four endpoints the sites
  call cross-origin read no cookie, and rename none.
