# Changelog

One version for both halves: the Composer package `anotoki/lib` (from the git tag)
and the npm package `@anotoki/lib` (`angular/projects/lib/package.json`). While the
version is 0.x, a minor version may break what a site uses - each such change is
said here, with what a site must do.

## 0.5.2 - 2026-10-13

Cloudflare's published ranges are every Cloudflare customer's, not the owner's zone alone: anybody's Worker can
open a raw socket to a host from them and write a `CF-Connecting-IP` of its choosing - a fresh address for
every request, past every per-address limit. `ClientAddress::of()`, `AdminContext` (`originSecret:`, its last
argument) and `AuditLog` (`'originSecret'`) take an origin secret: with one, the header is believed only on a
request that also carries that secret in `X-Anotoki-Origin` (`ClientAddress::ORIGIN_HEADER`), which a Transform
Rule of the owner's Cloudflare zone adds to every request through it (the owner's decision of 2026-10-13).
Without it such a request counts as the edge's address - coarser, never looser. The secret is compared in
constant time, marked `#[\SensitiveParameter]`, and never logged. A site given no secret behaves as in 0.5.1.

What a site may do: read the secret from its local settings and pass it wherever it passes `trustedProxies`.
Only the PHP half changed: a site takes it with `composer update anotoki/lib`; its frontend may stay on the
0.5.0 tarball.

## 0.5.1 - 2026-10-12

A restore set every sequence one step past the later of the backup's value and today's - by +1, and the
higher of the two. A sequence that counts down (a site's visitors: `visitor_ids`, INCREMENT BY -1,
MAXVALUE -1) was set back up instead: with one visitor ever made, to 0 - past its MAXVALUE, so the
restore failed whole (`500 restore_failed`, nothing restored) on every site that ever had a visitor; with
more, to an id a visitor had already been given. Each sequence now goes one of its own steps past the
later value in its own direction (the lower of the two for one that counts down), read from
`pg_sequence`; a sequence that counts up by more than 1 keeps its step too. Found by Piano Academy's
restore test. Only the PHP half changed: a site takes it with `composer update anotoki/lib`; its frontend
may stay on the 0.5.0 tarball (the same code but the version string).

## 0.5.0 - 2026-10-11

A minor version, not 0.4.2: it changes what a site's hooks are given and may do (the People delete, the
restore's hooks, sealed backups, `AdminContext`'s addresses), and `^0.4.1` would take a patch by itself. README.md,
"Upgrading from 0.4.x (0.5.0)", says what each site changes.

People, auth and the kit:
- A restore no longer brings back what was deleted for a person. Inside its transaction, before anybody sees the
  rows - its locks held, so a request that dies after the commit leaves nothing undone and no backup is made of the
  rows without it - `People::replay()` (Backups' new `beforeCommit`) does again every `person.data_deleted` and
  `feedback.deleted` (by the message's id and the time it was sent) the audit log holds from the backup's
  `snapshot_at` on: the database's clock, now in the manifest. Whatever fails rolls the restore back whole; so does
  a check of the IAM's list running across it ("restore again in a minute"), and - with something to do again - a
  backup with no time or a database with no audit log. Once it committed, `People::afterRestore()` runs the scrub
  of the IAM's deleted accounts, and the site's own work whatever the scrub did: not done is `after_restore_error`
  (its first failure chained), and the next request scrubs again. `People::restoreHooks()` gives both; the survey's,
  Piano Academy's and genshin's hand-written versions go. A scrub a restore overtook keeps the restored place in the
  IAM's list (it reads its place once).
- A restore that waits more than a minute between two of its statements is ended by PostgreSQL
  (`idle_in_transaction_session_timeout`, for its transaction alone) and rolled back with its connection: "the
  database ended its connection", nothing of it stayed. A delete hook that works on the site's own connection in
  place of the `$pdo` it is given waits on the restore's locks while the restore waits on it - no deadlock is seen
  (the restore's connection is idle), and every request to those tables would wait behind both for ever.
- The People page's delete forgets the person's feedback itself (`People::delete()`, `Feedback::forgetPeopleOn()`),
  in a transaction of the library's around the site's hook - behind a savepoint, so a hook that ends the
  transaction it was given is found, and inside a restore's a failing hook takes back its own work alone. With the
  site's `AuditLog`, its `person.data_deleted` is written inside that transaction (`AuditLog::recordInside()`): a
  delete is never made without the entry a restore does it again by - one that cannot be written undoes it, and
  while the audit log's table waits the route answers `503 feature_unavailable`. The entry names its transaction
  (`_txid`), and its time is when that transaction began: a restore does it again, as before, from the snapshot's
  time on - and, for an entry dated within the hour before it, when its transaction is not in the backup's snapshot
  (`txid_visible_in_snapshot()`, the manifest's new `txid_snapshot`). Never further back: a cluster's ids are its
  own, and after a move by `pg_dump` every older entry would seem unseen, its delete done again on what the person
  kept since. Entries and backups of before go by the time alone.
- Auth: the client's token requests (`@anotoki/lib/auth/session`) and the two screens (`@anotoki/lib/auth/screens`;
  the routes of before load them as they open) left the first load, the shared part `@anotoki/lib/auth/core`. The
  sign-in's first load: 18.9 -> 17.1 kB gzipped, 63.1 -> 54.7 kB raw (auth 34.4 -> 26.1); the gate's unchanged. A
  callback whose part does not load keeps the hint's silent hop silent, and goes back where it came from.
- The kit: Edge's own reveal button hidden in the password field; the folded bar's menu button marked when a link in
  it has a badge, its name saying which; the lazy pages' icons their own, out of the first load of a site that loads
  `@anotoki/lib/ui/icons` (~0.7 kB).

What a site must do: its People delete hook does ALL its work on the `$pdo` it is given - never the site's own
connection: Japanese Academy's `people_delete` (`db_tx`/`db_exec` on `db()`), Piano Academy's `peopleDelete`
(`dbTx`/`dbExec`/`db()`) and the build analyzer's `SitePeople::deleteData` (its `Db`, `Users`, `Feedback`) work on
their own today, which in a restore waits on the restore's locks: the restore is ended after a minute
(`500 restore_failed`), and every request to those tables waits until then. It begins, commits or rolls back no
transaction of its own (genshin's, the build analyzer's begin one) and takes a null request - in a replay, the
database's part alone (no copy first, as Japanese Academy keeps); then
`...$people->restoreHooks(delete: ..., defer: ..., then: ...)` among its Backups' options replaces its own. Feedback
deletes audited before 0.5.0 name no time and are not done again.

**Admin tools** - what the sites and their reviews found after 0.4.1 (README.md, "Admin tools"):
- Addresses (the owner's rule of 2026-10-11): `Support\ClientAddress` is the one reading wherever the library
  takes an address - `CF-Connecting-IP` believed only on a connection from `trustedProxies`
  (`ClientAddress::CLOUDFLARE`), an IPv4 address written as IPv6 (`::ffff:…`) read as the IPv4 one, an IPv6
  address counted by its /64, only keyed HMACs kept. A site behind
  Cloudflare passes `trustedProxies: ClientAddress::CLOUDFLARE` to its `AdminContext` (and to `AuditLog` where
  it keeps the address); without it, nothing but `REMOTE_ADDR` is read, as before.
- `AdminContext` `names` (optional): people's names by id - the backups' maker (`created_by_name`), the audit
  log's and feedback's people where their routes get no lookup of their own.
- Error log: an expected state (`update_pending`, `feature_unavailable`, `not_set_up`) is no longer written
  (`expected`); an anonymous class's and a closure's file is relative; on an admin tool's route the person is
  the one the route saw (or `Identify`'s); `Redactor::addSecretNames()` takes a site's own secrets' names (the
  IAM: in place of editing messages by reflection); the pages' reports count a signed-in person by account,
  apart from the visitors' day. `Redactor::text()` drops the run of characters its 4,096-character cut went
  through (half a JWT, an address or a token is one no rule knows) and takes a quoted secret the text ends inside
  to its end. An exception thrown for another keeps the chain in its line's `trace` - each cause's
  `Caused by Type (file:line): message`, redacted, and its own frames, within the trace's 12 lines - so a
  wrapper's sentence (`after_restore_error`) comes with its real failure; the fingerprint is the exception's own.
- `@anotoki/lib/error-report` (new): `provideAnotokiErrorReport()` sends the browser's errors to
  `ErrorLogRoutes::client()` through the app's HttpClient (the site's interceptors, its CSRF header) - scrubbed,
  at most 5 a page load, each once, never recursing, nothing read past what a report keeps, and nothing kept of
  the run a cut went through (the path: its segment).
- Backups: a plain backup is sealed under the installation's key, and only a sealed or encrypted one is restored
  here (a plain one made before 0.5.0: offline, `--extract`) - the seal vouches for the id and the SHA-256 alone,
  so a restore hashes the very bytes it reads; an encrypted backup carries no seal, and without the installation's
  key a plain one is made unsealed, never refused. The seal keeps out a file put into `storage/backups` by
  somebody who cannot replace that key (a copy from another installation, a changed file, whatever writes the
  backups folder alone) - not somebody who can write the whole storage folder, who can delete the key and make
  their own, as they can the IAM's key set kept there; the key stays in `storage/` (one in the database would be
  lost with it, every restore refused in the disaster a backup is for). A restore never moves a sequence back;
  `--inspect` reads the manifest alone; a HEAD never spends a download ticket; files the site's own user's alone
  (`0600`) from their creation (`tempnam()`: `Support\AtomicFile`), folders `0700` (every guarded folder too);
  the installation's key made whole before it is linked into place (`link()`: one key, whoever makes it at the
  same moment) - without `link()` (a host's `disable_functions`), its name claimed with an empty file only one
  process can make (`fopen('x')`) and only the claimant's key renamed over it, the others waiting while it is
  empty.
- Audit log: a flush empties the pending file under the lock its writers take (an entry written meanwhile waits
  and lands after) and never inserts an entry twice; the 64 KB cut keeps the details' keys; `recordInside()`
  writes an entry that must be there inside its change's transaction, or throws. Feedback: a
  signed-in sender is counted by account, in a day's budget of the people signed in apart from the visitors'
  (`allPerDay` each); a message "without my name" is counted as a visitor's; a count that cannot be kept
  answers `503 storage_unavailable`, which the error log writes; the reply address is only what the form sends;
  with the site's `AuditLog`, a delete's `feedback.deleted` is written inside the delete's transaction
  (`Feedback::deleteWithEntry()`: no delete without it, `503 feature_unavailable` while the audit log's table
  waits) and a restore matches it to the backup as it does a person's.
- `Support\Keys::installation()` is as safe as the storage folder, no safer: it keys the error log's client
  hashes, the feedback limits' buckets, the file explorer's preview links and the backups' seal, and whoever can
  write the whole folder can make it their own. A preview link alone reads one file of a root: a site with a
  secret outside `storage/` gives the file explorer `key:` (README, "File explorer").
- The pages: "Open Migrations" readable while an update waits; the error log's Range, the filters and each
  search with its button fit at 1000-1280 px beside a side navigation, and at 390 px.
- `composer.json` requires `ext-mbstring`, which the library uses.

## 0.4.1 - 2026-10-11

0.4.0 on PHP 8.2: a search on the admin Feedback page failed with `SQLSTATE[HY093]` - a 500. It said
`ILIKE ? ESCAPE '\'` four times. Before PHP 8.4, PDO reads the SQL it prepares by MySQL's rule: it takes
the `\'` in such a literal for an escaped quote, so the literal seems to run on to the next quote, and
the `?` inside are missed - fewer placeholders than parameters. PHP 8.4 reads PostgreSQL's SQL as
PostgreSQL does, which is why only CI's 8.2 job failed. The audit log's action filter said the same
(`action LIKE ? ESCAPE '\'`); nothing quoted came after it in its queries, so it held - until the next
change. Both `ESCAPE` clauses are gone: PostgreSQL's LIKE escapes with the backslash already, so a search
means what it meant (`%`, `_` and `\` are themselves). No other SQL of the library has a backslash before
a quote, and a test now reads every string of the library's code for one (`SqlLiteralsTest`, on every
PHP); the audit log is tested with every filter at once.

What a site does: go straight to 0.4.1 - `anotoki/lib ^0.4.1` and the 0.4.1 tarball - never 0.4.0.
Nothing else changed; 0.4.0's notes below are 0.4.1's.

## 0.4.0 - 2026-10-11

Two modules, both halves: **auth** - signing in through the anotoki IAM, the IAM's SDKs moved into the
library, the sign-in hint (the owner's decisions of 2026-10-07) and the screen of a sign-in that failed -
and **people** - each site's record of the accounts that use it, the scrub of the accounts the IAM
deleted, and the administrators' People page on the UI kit; and the **admin tools** every site's panel
shares, both halves (the
owner's request of 2026-10-07, step 4): the error log, the audit log, feedback, backups and the file
explorer - for the site's ADMIN alone (the owner's decision: EDITORs see none of them), each an admin
page of its own on a lazy route - and the visitors' feedback form, in their language. Nothing a site's
code uses of 0.3.0 changes; each module is taken when the site adopts it (README.md: "Auth", "People",
"Admin tools"). A site on the translations module gets two more library migrations to apply
(`anotoki_translations/003`, the sign-in page's words; `004`, the feedback form's).
The library's words are generated a namespace at a time: `LIBRARY_WORDS` is `@anotoki/lib/words`' now
(it was `@anotoki/lib/ui/config`'s), a module's words come with the module (its `provide*()` registers
them), and the kit's first loads and the translations module's carry the kit's words alone (README.md,
"Upgrading from 0.3.x").

PHP, auth (`Anotoki\Lib\Auth`):
- `IamClient` (the SDK's configuration keys), `AccessTokens` and `Jwks` (the SDK 1.1.0's rules: RS256
  and `typ` pinned, `kid` required; new: a header with `crit` refused, a `client_id` claim of another
  application refused, five minutes' rest after a failed fetch of the key set), `Person`, `Bearer`,
  `SignInRule` (the SDK's fail-closed table, single flight and kept file; new: `known()` - false after
  the IAM's 404 - and the site's `requiredWhen`).
- `Http\Identify` (who a request is, once: Bearer, then the sign-in rule, then the visitor key; never a
  cookie), `Http\RequirePerson`, `Http\RequireRole` (from the token, never the database),
  `Http\RequireCaller` (`refuse | empty | make`), `Http\SignInGate`, `Http\AuthRoutes`
  (`GET /api/auth/config` with `sign_in_available`; `configBody()` for a site's own boot answer).
- `Testing\TestIam`, `Testing\StandInIam` and `php/testing/stand-in-iam.php` - the family's one stand-in
  IAM (the survey's, Piano's and Japanese Academy's in one, with the hint cookie, `prompt=none`, the
  paged deleted accounts and any answer a test names); it ships in the Composer archive and answers
  nothing but under PHP's built-in server. `ext-openssl` is required now; `ext-curl` suggested.

PHP, people (`Anotoki\Lib\People`):
- The library's set `anotoki_people` (`php/migrations/people/001`): `anotoki_people_sync` and
  `anotoki_people_anonymized`; it names no site table.
- `PeopleTable` (the site's table through a mapping; no email), `PeopleRecorder` (from the token, at
  most every five minutes, never an anonymized row or a listed id), `AnonymizedAccounts` and `Scrubber`
  (the IAM's list from an inclusive `since`, an account per transaction, UPDATEs only, the site's
  hooks, a failed hook rolled back and kept as the error), `People`, `PeopleColumn`,
  `Http\RecordPerson`, `Http\PeopleUpkeep` (after the answer, at most hourly, while ready),
  `Http\PeopleRoutes` (list, search, paging, the site's columns and visitors line, the delete hook,
  sync now, the IAM's user page).

Auth and people, from the security review (2026-10-11):
- `PeopleRoutes::admin()` guards itself: the site's ADMIN from the token (`RequireRole::admin()` inside
  the group), so a site that forgets its check, or mounts the group where EDITORs are let in, opens
  neither the list nor the delete hook; a site's own check still answers first. New `audit:` and
  `context:`: a delete is recorded `person.data_deleted` (the counts), an asked-for scrub `people.synced`.
  A delete asks only its question, no recent sign-in (the owner's decision of 2026-10-11). A `q` that is
  not UTF-8 or holds a NUL is 422 `invalid_query`, not a 500.
- `Scrubber`: where a UNIQUE name column has `deleted-{sub}` taken, the IAM's `deleted-{sub}-{hex}` - a
  chosen name no longer stops the scrub for good.
- `AccessTokens`: a reason holds at most 64 of the token's own characters, in `[A-Za-z0-9._+-]`
  (`lastReasonKind()`: none); `Identify` says each kind of refusal to the log at most once a minute.
- A stamp later than now counts as none: `Jwks` (an unknown kid is fetched for), `PeopleUpkeep`,
  `Support\Throttle`.
- `IamClient`: the issuer and `jwks_url` are https - http only on this computer - or it throws;
  `HttpGet` asks over http and https alone (`CURLOPT_PROTOCOLS_STR`, and on its streams).
- Angular: the interceptor's and `AnotokiAuth.fetch()`'s origin rule tested against another origin in any
  spelling, a downgrade, another port and a dot-dot out of the API - no token, no key (no code changed).

PHP, the admin tools - what they share:
- `Admin\AdminContext` - the site's actor, proof (the IAM's `accountProof()`; the sites'
  `recentSignIn()` from the token's `auth_time`), address bucket and error body, given once.
- The library's migration set `anotoki_admin` (`Admin\Schema`, `php/migrations/admin`): `001`
  `anotoki_audit_log`, `002` `anotoki_feedback`. It runs **first** in a site's list.

PHP, the admin tools:
- `ErrorLog` - JSON lines in `storage/logs`, UTC days, parts past 4 MB, 30 days, 64 MB in all,
  redacted, traces without arguments, an HMAC of the address bucket instead of an address;
  `wrapHandler()`, `ErrorLogMiddleware::outer()/inner()`, `installPhpHandlers()` (fatals, chained
  warnings); `ErrorLogReader` (genshin's report, its own lines read too; `clear($before)` - every day,
  or the UTC days before one); `Http\ErrorLogRoutes` (`admin()` - `DELETE ?before=YYYY-MM-DD` for the
  days before one, `422 date_invalid` for anything else; `client()` for the pages' errors;
  `openPaths()`). By default every 5xx and 404, 405, 413, 415, 429 are written.
- `Audit\AuditLog` (`AuditRecorder`) - never throws, a savepoint inside the caller's transaction,
  a pending file while the table is missing (`flushPending()` after every Apply), the request's
  actor held by `AuditContextMiddleware`, `withScope()`, `page()`/`facets()`, `scrubPeople()`
  (the IAM's rules); `Http\AuditRoutes` (the IAM's answer).
- `Feedback\Feedback` - the open form (identity from the token, a honeypot, 5 an hour / 20 a day
  per address bucket and 300 a day in all, never an address kept, no id returned, no mail), the
  admin list with an unread count for the panel's badge, `prune()` (spam 30 days, resolved 365;
  the limits' counts kept a day, outside the database), `forgetPeople()`; `Http\FeedbackRoutes`.
- `Backups\Backups` - no pg_dump: every table by COPY through PDO in one snapshot, with the applied
  migrations, into `<id>.anotoki-backup` (a gzip'd ustar; the IAM's in AES-256-GCM chunks under a
  key derived from its master key); daily after the answer (14), before every update (5), before
  a restore (5), manual; restore in place only where the schema is the backup's, all or nothing,
  never touching the bookkeeping or the audit log (`restore: false` for the IAM); downloads by
  single-use 60-second tickets; `Http\BackupsRoutes` (the proof and an audit entry for every
  create, delete, download and restore; `openPaths()` without restore), `Cli\BackupsCommand`.
- `Files\FileExplorer` - roots (`FileRoot`) confined by real paths (`PathGuard`), genshin's upload
  rules (`UploadPolicy`: one extension per name, no script or markup part, bytes checked, a guard
  of RemoveHandler/RemoveType only), snapshot listings, a trash, signed previews;
  `Http\FilesRoutes`.

PHP, shared (`Anotoki\Lib\Support`):
- `AfterResponse` - work after the answer, without cron: one runner for the people's scrub and the
  admin tools' daily work. `AfterResponse::shared()->defer($work)` while answering (a module's
  `defer:` option takes it, or a site's own runner), `->run()` after `$app->run()` - PHP-FPM and
  LiteSpeed close the connection first; each piece on its own, a failure said in the log (redacted)
  or to the site's `$onError`, never stopping the next; work a piece queues runs too. A site that
  never calls `run()` has it run at the end of the request all the same (a shutdown function).
- `HttpGet` (a GET with a timeout, status 0 for no answer), `Log` (where the library's log lines go:
  PHP's error log, or the site's sink), `GuardedDirectory`, `Keys` (`derive()`: HKDF-SHA256
  `anotoki-lib/<purpose>/v1`; `installation()`: a key in `storage/anotoki-lib.key` for a site
  without a secret), `Redactor` (messages and paths without personal data or secrets; 4,096
  characters at most; fail closed), `Throttle`, `AtomicFile`, `Utf8`.
- One of each for the modules of both: the auth module's kept files (the IAM's key set, its answer
  about signing in) are written by `AtomicFile`; the admin tools' default reporters (the audit log's
  `onError`, the backups', feedback's and the file explorer's) say their lines through `Log`, as
  auth, people and the runner do - feedback's and the file explorer's redacted now too.

Angular (`@anotoki/lib/auth`, `@anotoki/lib/auth/testing`):
- `createAuthClient` - the IAM's browser SDK 1.2.0 ported, **with the same storage keys** (tokens,
  the sign-in in progress, discovery, the refresh lock): nobody is signed out by the switch.
- `AnotokiAuth` (Japanese Academy's state machine, for every site), `anotokiAuthInterceptor` (who a
  request is, decided when issued), `personGuard`, `personOrVisitorGuard`, `staffGuard`, `adminGuard`,
  `AccountPreferences` (genshin's outcomes; the site's `apply()` follows the account),
  `<anotoki-auth-callback>` (noindex), `SavesInFlight`. The shell's theme and the translations
  module's language follow the account through `AnotokiAuth` (README, "The browser").
- The sign-in hint: one silent hop per hint value, for a visitor, guarded by a marker in localStorage
  (one try a value, one a minute, ten minutes after the IAM did not answer); following the IAM - a hint
  gone or changed means one refresh at boot.
- `provideTestAnotokiAuth`, `FakeAuthClient` for a site's specs.
- On 0.3.0's structure: `<anotoki-auth-callback>` is drawn with the kit - the status page's frame, classes
  of its own (`anotoki-auth-callback__panel`, `__message`); the module imports the kit's pieces from their
  own entry points and the migrations module, never `@anotoki/lib/ui`; its top-level `HttpContextToken`s
  and words are marked pure.
- `<anotoki-sign-in-failure>` (`SignInFailureComponent`) - the screen of a sign-in that came back without
  one, could not start, or cannot be set up, a kit screen a site can route to: "Signing in did not work",
  why - from the failure's code alone - Try again (the sign-in once more, back to where it started; a
  sign-in that cannot be set up reloads the page) and Back (never to the callback or the screen itself),
  the kit's buttons (`[data-retry]`, `[data-back]`) in the status page's frame (`anotoki-sign-in-failure__panel`,
  `__title`, `__message`, `__actions`), noindex while it shows, in the visitor's language. The callback
  page shows it in its place; the module's `failureRoute` names a route of the site's own, in its frame,
  where the guards send a failed sign-in instead of the callback route.
- The sign-in page's words are library words: `anotoki.signIn.signingIn|failedTitle|notCompleted|expired|
  storageUnavailable|unavailable|failed|retry|back`, English and informal Slovak, in
  `php/resources/library-words.json`, generated into `@anotoki/lib/words/sign-in` (below) and read from
  there (`SIGN_IN_WORD_NAMES`, as the status page reads its own); an owner's rewording through
  `translate` (with the translations module, `libraryWord()`).
- `scripts/check-package.mjs` knows the two entry points: auth is eager (16 kB at most; 15.3 kB with the
  failure's screen), its testing helper imports it alone; a fifth first load, "the sign-in" (the gate and
  every eager part of auth, the failure's screen with it, 19.5 kB at most; 19.0 kB).

Angular, people (`@anotoki/lib/people`, `@anotoki/lib/people/page`):
- `@anotoki/lib/people` (0.2 kB, 0.5 kB at most: a site's app.config, or the page's route) -
  `provideAnotokiPeople()` (`apiBase`, `isAdmin`, `me`, `lead`, `formatDate`, `delete: { label, question,
  allowed, done }`, `visitorsLine`, `migrationsRoute` - its functions run in an injection context) and the
  types of the PHP half's PeopleRoutes::admin() answers.
- `@anotoki/lib/people/page` (9.7 kB, a lazy admin route) - `<anotoki-people-page>`: the union of what
  Piano Academy's, Japanese Academy's and the build analyzer's People pages showed and did, over the routes
  as built. A search by name or IAM id (as the typing rests, or at Enter; only the latest answer counts),
  50 a page, newest seen first; the name with "you" and, under it, never off the screen, "Open at the IAM"
  (for the IAM's administrators) and Delete in `danger-text`; the IAM id, first and last seen (a time no
  person on the page has is not drawn), the site's columns (count, text, time); an account the IAM deleted
  muted and said to be; where the scrub stands - last checked, scrubbed, or why it stopped - with Check
  now; Delete behind a question in the page's own dialog (the site's words, or the family's naming the
  counts), what went in the site's labels with its note; the visitors line; what the database lacks, with
  the way to Migrations. English; a table whose rows are cards in a page narrower than 640 px.
- `scripts/check-package.mjs` knows both: the configuration imports nothing of the package, the page the
  kit and the configuration - never the auth module.

Angular, the kit's fixes the sites found:
- `@anotoki/lib/styles`: the token `danger-text` (light `#b42833`, dark `#ff6b75`; 4.5:1 at least on every
  ground and on `danger-soft`, 5.4 and 5.2 at the least), the ink of the words that say something went
  wrong - a field's error, the translations pages' refusals, a danger badge read it - apart from the fill
  `danger`, so a site's own darker fill red (Japanese Academy's dark, 3:1 on its surfaces) no longer takes
  its error words down with it.
- `ui/forms`: `invalid` - aria-invalid and the danger edge without a message of its own (one message for a
  range's two dates, a form's summary) - on the text field, textarea, password field, select and code input.
- `shell`: `shortBrandMax`, when given, alone says where the brand is short - below `phoneMax` too, so a
  bar that folds wide (Piano Academy's at 1320 px) keeps "anotoki Piano Academy" where it fits; in
  development the bar warns when the folded row overflows with the full wordmark.
- `shell`: `ThemeService` says no refusal when the account went while a choice was on its way - signed
  out here or in another tab (no account any more, or `login_required`): the choice stays shown;
  `refused` holds only while there is an account.
- `ui/alert`: the heading breaks a word longer than its line (a migration file's name with its set at
  390 px).
- `migrations/page`: every file is named as its set's records name it, the same in Pending, Applied,
  Missing and what an Apply did - the site's one set's by the file's name alone, every other with its set
  (`anotoki_translations/003_sign_in_words.sql`; genshin's `users/041_news.sql`). Pending is one list in
  the order the files run, with no heading for a set (it read `migrations/<set>/` over bare names while
  Applied read `<set>/<file>`). A name breaks after its slash or between its words (`<wbr>`, nothing added
  to its text), never inside a word as it did at 390 px. A spec reading Pending's headings or names changes.
- `translations/admin`: no `beforeunload` question once nobody is signed in - on the way to the IAM's
  sign-in, the drafts wait in the tab for the same person, as the pages' route guard already let the page go.
- `scripts/check-package.mjs`: no bundle may name innerHTML, outerHTML, insertAdjacentHTML or
  bypassSecurityTrust, in any spelling, comments too - the IAM's deploy check's rule, refused before a
  release can reach it.

Translations, found by the sites moving onto 0.3.0 (the survey's first):
- `admin.allows` gains `importStrings`, `addLanguages` and `deleteLanguages`, each as its page's permission
  unless the site says otherwise; a control for what the site does not allow is not drawn (the survey's
  EDITORs see no Delete on the Languages page, which its server refuses them).
- The Translations page's note about what is typed promises nothing of a sign-out: a site's bar may ask
  before it (the survey's does).
- PHP: `invalid_placeholder` on a key with no placeholders is worded for it - "a brace belongs only around
  a placeholder, so take it out" - instead of "like {count}", a placeholder the key does not have.
- The Languages page takes the width the site's panel gives it, as the Translations page does (it stopped
  at 60rem), and the code's hint says what the check takes: "Two small letters, a region after a dash if
  needed: cs, de, pt-br" (it said "Two small letters" over its own `pt-br`).
- PHP: the module's refusals end with a full stop, as the family's others do - `StringCheck`'s,
  `Translations`' and `RequiredPlaceholders`'. A test comparing a whole message adds it.
- PHP: a key that is a number is a key the site does not have - an import of a JSON list or of
  `{"0": "…"}` (Piano Academy's), a save of `{"0": {…}}`, the command line's import of either file - refused
  `unknown_key` naming them, not `invalid_value` (PHP makes a list of an object whose keys are numbers); an
  empty list, or a body that is no object, stays `invalid_value`.

Angular, the library's words a namespace at a time, each module's with the module - so that a module's
words weigh neither on the kit's first loads nor on the translations module's:
- `bun run words` generates them per namespace from `php/resources/library-words.json` (still the one
  source; the PHP half and the SQL are unchanged): the kit's namespaces (`language`, `ui`, `topbar`,
  `theme`) into `ui/config/src/library-words.ts` - `KIT_LIBRARY_WORDS`, and `LibraryKey`, every key's name
  (a type); every other namespace into an entry point of its own, `@anotoki/lib/words/<namespace>`
  (`/site-status`, `/sign-in`), which its module alone imports; and `@anotoki/lib/words`, `LIBRARY_WORDS`
  of every namespace, for a site's specs and the showcase. `--check` also finds a namespace's folder the
  JSON no longer has, and `bun run words` removes it.
- **A module registers its words**: `provideLibraryWords(words)` (`@anotoki/lib/ui/config`, the multi
  provider `ANOTOKI_LIBRARY_WORDS`) among its `provide*()` function's providers -
  `provideAnotokiMigrations()` the status page's, `provideAnotokiAuth()` the sign-in page's, every later
  module its own. The translations module holds the kit's built-in words alone and reads every
  registered module's beside them, so a key of a module in use with no string is never shown as a key;
  a key of a module the site does not use, with no string, reads as the key (warned once).
- **Moved:** `LIBRARY_WORDS` is `@anotoki/lib/words`' (it was `@anotoki/lib/ui/config`'s and
  `@anotoki/lib/ui`'s); `LibraryKey` stays in `@anotoki/lib/ui/config`, as before. A site's spec that reads
  a module's key with no string in its bundle provides the module, or its words (README.md, "Upgrading
  from 0.3.x").
- The first loads, gzipped (every library word in `ui/config`, as 0.3.0 had them plus the sign-in page's
  -> now): a button 5.5 -> 4.8 kB, the bar 20.5 -> 19.8, the gate 8.6 -> 8.3, the words 7.1 -> 6.5 - the
  kit's words alone, and the gate the status page's beside them; the sign-in 18.5 -> 18.7 (two small files
  more). `ui/config` 3.1 -> 2.4 kB, ui and shell together 28.2 -> 27.5 (back under its 28 kB; 27.8 at
  0.3.0), translations 7.1 -> 7.3 (gathering the registered words). New entry points: `words` 0.2,
  `words/site-status` 0.5, `words/sign-in` 0.6.
- `scripts/check-package.mjs`: `ui/config` holds the kit's namespaces alone and each `words/<namespace>`
  one namespace; the words entry points' boundaries come from the package itself (a new namespace needs
  nothing there but its module's import); a module's words reach the translations module only through
  its registration - the translations module imports no words entry point, and a module that imports
  its words without `provideLibraryWords(...)` of them fails the build; and every first load carries the
  words of the namespaces its page says (a button, the bar and the words the kit's, the gate the status
  page's too). The generated files are left alone by prettier (`bun run format` would rewrite what `bun
  run words --check` holds byte for byte).

Angular, the admin tools (`@anotoki/lib/admin` and a page each - `/admin/errors`, `/admin/audit`,
`/admin/feedback`, `/admin/backups`, `/admin/files`; README.md, "The pages"):
- `provideAnotokiAdmin(() => config)` - `isAdmin` (a signal is followed), and as the site needs:
  `signInAgain` (the sites' proof), `personLink`, `migrationsRoute`, `apiBase` / `paths` (where the
  routes are), `resolveUrl` (an API on another origin), `formatDate`, `failureText` (the site's words for
  a refusal), `siteName`, `feedbackFields`, `restoreOffText`; and `FeedbackUnread`, the new feedback
  beside a site's admin navigation (asked for the ADMIN alone, the latest ask winning; the Feedback page
  keeps it in step). Eager where a site wants it: 0.9 kB.
- Each page an entry point of its own on a lazy route: English, for the site's ADMIN alone (anybody
  else is told whose it is, and nothing is asked), drawn with the kit's pieces from their own entry
  points, answering to its own width - a table a card a row at 390 px. A refusal is read whatever the
  site's error body (`{code, message}`, the IAM's `{error, code}`) and said in the page's words or the
  site's.
- The error log: the range, level, status and a search; a chart by day; the failures grouped by
  fingerprint, each with its facts, its trace and every occurrence; Clear - everything before today,
  older than a week, or everything.
- The audit log: who, the action or a family of them, what it is about, a date range - in the address;
  the server's facets; each field before and after (genshin's three shapes of details read alike);
  "Apply the pending database update first" while its table waits.
- Feedback: by status (each counted), kind, section and a search; a message opened in a dialog (the
  server marks it read), marked at once, deleted after a question; the site's extra fields by name.
- Backups: the status and the list; Back up now, Download (a single-use link the page opens) and
  Delete behind the site's proof - the IAM's password in a dialog, the sites' recent sign-in with "Sign
  in again"; Restore with what it would take away, the backup's last six characters typed, the proof
  and a 30-second wait; a restore whose answer was lost (502, 504, 524, the network) said to be maybe
  still running, and the list read again. The IAM's: encrypted, restoring off, its reason said.
- The file explorer: the roots, the folder as a path of links, a search, a grid or a list; uploads by
  the button or dropped, with a progress bar - what the root does not take refused before it is sent,
  the server's refusals file by file; a new folder, rename and move, delete into the trash after a
  question, with an Undo, the trash; previews from the signed links (pictures, sound, video, text as
  text; the rest a download).
- `@anotoki/lib/admin/parts`, the library's own: what the pages share - their requests and refusals,
  dates and sizes, a question dialog, the icons the kit lacks (folder, upload, music, film, file,
  archive, maximize), their styles' block - in one entry point, so a site's build places it once.
- Gzipped: errors 16.3 kB, audit 9.0, feedback 8.4, backups 15.2, files 23.5, parts 4.0.

Angular, the visitors' feedback form (`@anotoki/lib/feedback`, `@anotoki/lib/feedback/form`; README.md,
"The visitors' feedback form"):
- `provideAnotokiFeedback(() => config)` - `language`, `translate`, `sections`, `fields`, `signedIn`,
  `email`, and `kinds`, `subjectRequiredFor`, `words`, `endpoint`, `page`, `honeypot` as the site's
  server has them - registers the form's words with the translations module (0.9 kB).
- `<anotoki-feedback-form>` (`heading`, `headingLevel`, `closable`; `sent`, `closed`): the kinds, a
  section, a subject, the message, the site's extra fields by kind, "send it without my name" for
  somebody signed in, their address written in; checked before it is sent as the server checks; the
  server's refusals said kindly - too many (in the hour or today, from `retry_after`), not taking
  messages, too long, the network; a honeypot off the screen and out of the tab order; the thanks
  focused. Loaded where it is opened (5.9 kB): in a first load it would bring the kit's fields.
- Its words are library words, `anotoki.feedback.*` - 31, English and informal Slovak, in
  `php/resources/library-words.json`, generated into `@anotoki/lib/words/feedback` (1.4 kB): the
  site's database's through `translate`, then the site's `words`, then the built-in ones
  (`feedbackWords()`).

`scripts/check-package.mjs`: the new entry points' boundaries (a page imports its configuration, the
shared parts and the kit's lazy pieces, and nothing imports a page; the form imports its configuration
and the kit's fields); limits for the two eager ones (`admin` and `feedback`, 1.5 kB each); two more
first loads - "the badge" (the admin tools' configuration and `FeedbackUnread`, no words: 0.6 kB, 1 at
most) and "the words + feedback" (the translations module and the form's configuration, the kit's
words and the form's: 8.3 kB, 9 at most); a module's name holds no white space, so a page's own words
after `from` (the audit log's date filters) are never read as an import. The first loads before are as
they were.

PHP, translations: the library's set `anotoki_translations` gains `003_sign_in_words.sql` - the sign-in
page's nine keys (the failure screen's heading among them), by 002's rule (descriptions the library's;
strings only for a key that has none; a gate on English) - and `004_feedback_words.sql`, the feedback
form's 31 keys by the same rule. A site that applies the set gets them as any migration: the upload
waits for Apply.

What a site must do - auth and people (README.md, "Adopting auth and people, site by site"):
`anotoki/lib ^0.4` and the 0.4.0 tarball together; a migration of its own adding
`users.anonymized_at TIMESTAMPTZ`, then `People\Schema::migrationSet()` after its sets; its vendored
SDKs deleted (the IAM keeps `sdk/` frozen for third parties) and its wrappers replaced by the wiring in
README.md; `robots.txt` `Disallow: /auth/` where visitors are welcome (genshin, the build analyzer). The
boot configuration answers snake_case now - the Angular half reads the camelCase the sites answer today
too. A site with a People page of its own (Piano Academy, Japanese Academy, the build analyzer) routes
`@anotoki/lib/people/page` in its place, `provideAnotokiPeople()` with its words, and deletes its page and
its API glue; one that wants the sign-in failure in its frame routes `SignInFailureComponent` and names it
`failureRoute`.

What a site may do - the kit's fixes (README.md, "Upgrading from 0.3.x", 4): give `danger-text` where its
own red fill is too dark for words; pass `shortBrandMax` below `phoneMax` where its bar folds wide; mark
fields `invalid`; give the translations admin pages' per-action permissions (the survey:
`deleteLanguages: () => auth.isAdmin()`). A spec that reads the Migrations page's Pending headings or
names, a translations refusal's whole sentence (its full stop; `invalid_placeholder` for a key with no
placeholders) or `invalid_value` for keys that are numbers changes.

What a site must do - the admin tools: nothing until it takes them. Taking them:
`Admin\Schema::migrationSet()` **first** in every place that builds its Migrator (the site, its
installer, its command line) - genshin's order guard must let it through, and the upload then waits for
Apply like any migration; an `AdminContext` from its auth; the modules and their routes behind its ADMIN
check (the file and raw routes outside it); `ErrorLogRoutes::openPaths()` and
`BackupsRoutes::openPaths()` in its gate's open paths; `flushPending()` in its migrations' `afterApply`;
`dailyIfDue()` on requests and `beforeApplyHook()` in its Migrator. The IAM: `AfterResponse` after
`$app->run()`, its backups encrypted with restore off, and its `audit_log` moved into the library's
table by a migration of its own (`AuditLog`'s `ready` holds entries back until it ran). In its
Angular half: `provideAnotokiAdmin()` from its auth (the IAM: `siteName`, `failureText` and
`restoreOffText` too), the pages on lazy routes under its ADMIN guard, `FeedbackUnread` beside its
navigation's Feedback; where visitors write: `provideAnotokiFeedback()`, the form loaded as it is
opened, and `anotoki_translations/004` applied.

The admin tools after their security review (2026-10-11), before any site took them: every
`*Routes::admin()` group guards itself - `AdminContext`'s new `isAdmin` (a site must give it; 401 for
nobody, 403 for anybody else), the site's own middleware still first - and the deliberately open
`BackupsRoutes::file()` (new `samePerson`) and `FilesRoutes::raw()` are documented apart; tickets'
and previews' links and the error log's own routes hold under a site's groups and base path. COPY goes
through spools (a stream wrapper): sealed under a key held in memory where backups are encrypted, so no
row is in the clear on the disk; working folders and uploads a kill left behind go after an hour.
Feedback keeps nothing of where a message came from (002 has no `client_hash`; the limits
count a day in `storage/cache/anotoki-feedback-limits.json`; `storage` required; `keep.client_hash`
and `lock` gone; the honeypot answers 201) and refuses `? & = % #` or a space in an address (the page
encodes its mail link). Uploads: no markup at a file's start or early in text, an ISO box's own size,
no Windows device names, no root over code, secrets or storage, a guard with `AddType` and nosniff. The
error log drops its oldest days first and writes 100 lines of a kind a day (`perFingerprintDay`); the
redactor reads quoted secret names; the audit log's failures are a sentence and a code; the backups'
notes name no backup, an encrypting site reads no plain one, `BackupsCommand` runs on the command line
alone, `expires_in` is `ticketSeconds`; 001 and 002 say 0.4.0.

The order of the library's sets in a site's list: `[Admin\Schema::migrationSet(), ...$siteSets,
Translations\Schema::migrationSet(), People\Schema::migrationSet()]` - the admin set first, the others
after the site's own.

## 0.3.0 - 2026-10-11

The engine's library sets, the translations module - both halves -, the helpers the modules share, and
a Composer archive that ships the library's migration files and words; the kit's core a file per piece
(a smaller first load), its class names its own, and four of the IAM's looks back. Nothing a site's code
uses breaks; a site's specs that read the kit's markup by class change their selectors ("Angular, the
kit's class names"), and the little else that changes in what 0.2.0 had is said under "Angular, the
kit's words" and "Angular, the first load".

PHP, the engine (`Anotoki\Lib\Migrations`):
- **Library sets.** `MigrationSet::library($name, $dir)` is a set the library ships; the names that
  begin with `anotoki_` are theirs alone, and a site's set named so is refused (no site's is). A library
  set's files are recorded as `<set>/<file>` in a bookkeeping table without `folder`, wherever the set
  stands in the list, and as `folder = <set>` in one with it (genshin). The site's own sets are recorded
  as before; a new table takes the shape the site's own sets need (`folder` only for more than one of
  them); a record of a set that is not configured is listed as missing under its set's name; the hooks
  see the library's files with their set (`$set->library`). Nothing in a site's table changes, and a
  build without the library's set still reads every record of the site's own.
- What a site must do: nothing for the engine itself. To take the translations module, append
  `Translations\Schema::migrationSet()` to its sets - after its own - in every place that builds its
  Migrator (the site, its installer, its command line); check that its hooks let the library's files
  through (`ready`: they end with `-- end of NNN`; genshin's order guard: a third folder). The upload
  then waits for Apply, as any migration does.

PHP, translations (`Anotoki\Lib\Translations`):
- The library's migration set `anotoki_translations` (`php/migrations/translations`): `001` makes the
  three tables only where they are missing and refuses, naming every difference, a shape the library
  cannot work with - on the five aligned sites it makes nothing; genshin moves its tables first. `002`
  adds the library's own keys under `anotoki.` - the kit's words (`anotoki.ui|topbar|language|theme.*`)
  and the status page's (`anotoki.siteStatus.*`), 54 keys, en and sk - writing strings only for a key
  that has none.
- `Translations`, `TranslationsConfig`, `StringCheck`, `Text`, `Placeholders`, `LanguageCode`, `ETag`,
  `KeyRules` (with `Rules\RequiredPlaceholders`, `Rules\LibraryKeyRules`), `LanguageUsage`,
  `LibraryWords` (from `php/resources/library-words.json`, the one source of the library's words),
  `Schema`, `TranslationFile`.
- `Http\TranslationsRoutes::bundle|strings|languages|openPath` - the IAM's wire format (snake_case;
  the bundle byte for byte), bodies read by the library (415, 413, 422 `empty_body`, 400
  `invalid_json`), the site's `$error`, `$actor`, `$audit`, and its own checks on each route group.
  Compared with the IAM's routes: `strings` on every language, `{strings, …the site's fields}` from a
  delete (the IAM's `accounts` among them), `Retry-After: 60` on a 503, `charset=utf-8` and `no-store`
  on the JSON answers, and an empty body is 422 `empty_body`.
- `Cli\KeyScanner`, `Cli\EnglishDictionary`, `Cli\TranslationsCommand` - a site's `translations.php`
  in a few lines; the scan gives each aligned site's own result on its frontend.

PHP, shared (`Anotoki\Lib\Support`): `JsonResponse` (the no-store JSON answer the migrations routes and
the site gate now share), `JsonBody` (a request's JSON body, read from the stream), `Refusal`.

Angular, translations (`@anotoki/lib/translations`, new):
- `provideAnotokiTranslations()` and `TranslationService` - the language the pages are read in and its
  strings, from the site's bundle route with English under them: the family's ranking (this tab's
  `?lang=`, a choice made on the page, the account's language - before this device's while the account
  decides -, the browser's, English), a choice saved to the account one save at a time and the latest
  last (`languageNotSaved` when it is refused, a quiet refusal kept the site's own for the visit; the
  account page's `revertible` choice goes back), the way back from memory, the cache and the start's
  waits, `canAsk`, a site's own `fetchBundle` and `onBundleRefused`, English areas, `preferred()`,
  `settled()`, `reload()`, `noteAddress()`; plurals, `around()`, numbers; the storage keys a site already
  has (`storagePrefix`, `storageKeys`). A key without a string: the site's compiled English while no
  bundle is in memory, else the key; a library key never - the database's string, the site's
  `libraryWords`, the library's built-in words of the language, their English.
- The `translate` and `translatePlural` pipes; `forKit()` (the kit's `lookup` reads the database, so an
  owner's rewording reaches the kit), `forShell()` (the shell's language switcher), `siteStatusWords()`
  (the status page in the database's words), `anotokiUnsavedChangesGuard`.
- `@anotoki/lib/translations/admin` (lazy): `<anotoki-translations-page>` and `<anotoki-languages-page>`,
  drawn with the kit - the five sites' pages in one: coverage, tags, search, "Missing in", plural
  families, the server-only groups (the IAM's mails), export and import, drafts kept in the tab
  (`LocalizationDrafts`), refusals beside their key, the Languages page's rows, switch, export-then-delete
  and the site's own fields (`admin.languageNotes`); who may use them is the site's (`admin.allows`).
- `@anotoki/lib/translations/testing`: `provideTestTranslations()`, `TEST_LANGUAGES`.
- What a site must do: README, "Translations" - "A site moves in", per site.

Angular, the kit's words:
- The library's words are generated from `php/resources/library-words.json` (`bun run words`) into
  `ui/config/src/library-words.ts`, exported as `LIBRARY_WORDS` (and `LibraryKey`); the kit's
  `BUILT_IN_WORDS` and the status page's built-in words are read from it - no copy by hand. A spec, CI
  (`bun run words --check`, in the Angular job) and the PHP half's `LibraryWordsTest` fail when the file
  is stale.
- The library's Slovak `anotoki.language.notSaved` is the kit's released wording ("… do tvojho anotoki
  účtu - platí len …"), where the unreleased 002 had Piano Academy's ("… účtu anotoki – …"); the kit's
  words themselves are as 0.2.0 had them. `ANOTOKI_WORD_KEYS` now lists them in the JSON's order
  (`language.*` first).

Angular, the first load:
- **The kit's core is a file per piece.** A site's build (the Angular CLI's esbuild) places whole files in
  its chunks, and 0.2.0's `@anotoki/lib/ui` was one file: a first page that used one piece - the status
  page uses the button - took every piece any lazy page used into the first load. genshin's and the build
  analyzer's (the gate, and the Migrations page on a lazy route) took the Migrations page's alert, cards,
  badges, page header and icons with it: built from the packed package in a scratch app, their first
  load goes from 291.3 kB to 277.2 kB (gzipped 88.9 to 85.0; the library's part 39.1 kB to 25.1). Each
  piece is an entry point of its own - `@anotoki/lib/ui/config` (`provideAnotokiUi`, `AnotokiWords`, the
  kit's words, `LIBRARY_WORDS`), `/icon`, `/dom`, `/spinner`, `/button`, `/badge`, `/alert`, `/card`,
  `/empty-state`, `/error-state`, `/page-header`, `/avatar`, `/segmented`, `/autofocus` - and
  `@anotoki/lib/ui` re-exports them all: nothing has to change to keep building. The library imports each
  from its own entry point; a site that imports from `@anotoki/lib/ui` on its first page reaches every
  piece through it and keeps 0.2.0's first load until it imports them from theirs (README, "Upgrading from
  0.2.x"). genshin and the build analyzer import nothing of the kit: they have nothing to change.
- **Top-level code a site's build kept, used or not.** The Angular CLI marks a package's top-level calls
  pure in `@angular/*` alone, so the library's own - the kit's words and icons built as the module loads,
  the tabbable selector, the status page's words, the Migrations page's date format, the translations'
  `HttpContextToken`, the QR code's tables, a `1024 * 1024` - stayed in every bundle that imported
  anything of their entry point (8 kB of ui's and of migrations' alone: the library's every word). Each
  is a call marked `/* @__PURE__ */` now, or a literal.
- The Migrations page carries its own helpers (`messageOf`, the default date format): in
  `@anotoki/lib/migrations` they were in the gate's file. That entry point exports `ɵjsonObject` for the
  page instead of `ɵdefaultFormatDate` and `ɵmessageOf` - none of them a site's API.
- `scripts/check-package.mjs`: the core's limit holds its pieces together (15 kB); the library's imports
  of the core go to the pieces, never through `@anotoki/lib/ui`, which holds re-exports and nothing
  else; what a site's build keeps of an entry point nothing is used of - each file through Angular's own
  linker and optimizations (the CLI's `JavaScriptTransformer`), then the CLI's esbuild - is nothing; and
  four first loads (the gate, the bar, the words, a button), built beside lazy pages that use every other
  entry point whole, take no file their page does not use, gain at most 1 kB from what shares a file with
  them, and stay under their limits (9, 21.5, 7.5 and 5.5 kB gzipped; the gate's took 14.7 kB on 0.2.0's
  layout, the words' 16.1).

Angular, the kit's class names:
- **Every class the kit puts in a page is its own**, BEM on the component's host class: a part `.x` of
  `<anotoki-y>` is `anotoki-y__x` (`anotoki-alert__icon`, `anotoki-dialog__panel`), a state `is-x` /
  `has-x` of the component `anotoki-y--x` / `anotoki-y--has-x` (`anotoki-button--primary`), of a part
  `anotoki-y__part--x` (`anotoki-top-bar__link--active`); the fields' shared parts are `anotoki-field`
  and `anotoki-field__*`, the Translations and Languages pages' `anotoki-translations-admin__*`;
  `visually-hidden` is `anotoki-visually-hidden`. A site's global `.icon` (genshin's filled every Apply
  result's icon) or `.panel` (the build analyzer's framed the inside of both dialogs) no longer reaches
  in. Nothing looks different: the showcase's screenshots, every page and overlay in light and dark, are
  the same pixels before and after.
- What a site must do: a spec that reads the kit's markup by class changes its selectors by that rule -
  among them what 0.2.0 named: `[data-confirm] .description` -> `.anotoki-dialog__description`,
  `[data-result] .heading` -> `.anotoki-alert__heading`, `.card-text` / `.result-text` / `.result-note`
  -> `.anotoki-migrations-page__card-text` / `__result-text` / `__result-note`, the status page's Sign in
  `is-link` -> `anotoki-button--link`, the bar's section `is-active` -> `anotoki-top-bar__link--active`
  (in the phone menu `anotoki-menu-item--active`). Known: the IAM's top bar spec (`.switcher`, `.row`,
  `.nav`, `.actions`, `.menu-head`, `.wordmark`, `is-active`) and Japanese Academy's specs
  (`[data-result] .heading`, `anotoki-brand .wordmark`). README, "Upgrading from 0.2.x".

Angular, the IAM's look (found as the IAM moved onto the kit; its look is the owner's):
- The switch's thumb is white in dark too - a new token, `switch-thumb`, white in both themes - and its off
  track in dark `border-strong`, so the thumb stands out of it at 4.8:1 (on `subtle` it would be 2.9:1)
  and the track out of every ground at 3:1; light is as it was.
- A field's label is 1.55 high again (1.4 made every labelled field 2 px shorter).
- An avatar's initials are 0.45 of its size again (0.42).
- `<anotoki-shell maxWidth="88rem">`: the frame as wide as the site says - 84rem by default, 88rem with a
  side navigation - with the family's bar inside lined up; the IAM's account pages are 88rem.

Fixed - the shadows (found on Piano Academy): `@anotoki/lib/styles` wrote a one-item shadow - `shadow-sm`
in both themes, `mark-shadow` in dark, or any one-item value a site gives `root()` - as Sass reads it back,
`(0 1px 2px …,)`, which no property takes: wherever it was read the shadow was dropped (cards, the primary
button, the segmented control's choice, the switch's thumb, the translations pages' bars), and the anotoki
mark lost its light outline in dark, on every site since 0.2.0. `root()`, `palette()`, `shared()` and
`token()` write such a list as its one item now. `scripts/check-package.mjs` compiles the tokens - the
family's and one-item values of a site's - and reads every component's styles: none may hold `(…,)`.

Package: `@anotoki/lib` 0.3.0; `scripts/check-package.mjs` knows the three entry points (translations is
eager: 8 kB at most; its admin pages never in it), and the core's fourteen.

CI: the Angular job checks the library's words are the JSON's (`bun run words --check`) before it builds.

Package: `.gitattributes` is an allow-list - GitHub's archive of a tag, which Composer installs, holds
`php/src`, `php/migrations`, `php/resources`, `composer.json`, README.md and CHANGELOG.md, and nothing
else - with LF line endings pinned for the SQL and the JSON; CI builds the archive and checks it.

## 0.2.0 - 2026-10-08

Angular only; the PHP half is unchanged (a site may keep `vendor/anotoki/lib` at 0.1.x).
The UI kit the owner asked for (round 2, 2026-10-07): one kit for the family, built
from the IAM's (the owner's preferred design), with the fixes made in one site's copy
only applied to all.

**Breaking** (README, "Upgrading from 0.1.x", has every edit):
- The gate's pages are a *marked* template: `<ng-template anotokiSitePages>`, with
  `SitePagesDirective` in the component's imports. 0.1.1's `contentChild(TemplateRef)`
  also took the template of a control-flow block given as the gate's content - a site
  still on the 0.1.0 form with a leading `@if` (or `@switch`, `@for`) had that block
  drawn as "the pages" for everybody, whatever its condition. **0.1.1 said the 0.1.0
  form "still works"; for such content it did not.** Plain content (no template) shows
  as in 0.1.0 again; an unmarked `<ng-template>` is no longer the pages (nothing is
  drawn; development warns).
- The Migrations page moved to its own entry point, `@anotoki/lib/migrations/page`:
  it was in every site's first load with the gate (genshin's initial bundle went over
  its budget). `@anotoki/lib/migrations` no longer exports it.
- The Migrations page and the status page are drawn with the kit: a site's specs that
  read their DOM change a few selectors (README).

Fixed:
- The status page reloads once the site is ready again **without** first marking it
  ready (`SiteStatus.checkForReload()`): reporting "ready" reopened the gate and made
  the site's pages in the page about to go (Japanese Academy's exam was made three
  times before a reload).
- The status page's Sign in has a 44 px target (the kit's link button).

New:
- `@anotoki/lib/styles` (Sass, `@use "@anotoki/lib/styles" as anotoki`): the family's
  tokens as `--anotoki-*` - one palette (the IAM's), light and dark, with the badge
  inks darkened to 4.5:1 (`success-text` 5.1:1, `warning-text` 5.3:1) and `border-strong`,
  the edge of every control (fields, selects, code boxes, radio cards, segmented
  controls, the bar's round buttons), at 3:1 on every ground: light `#7a8ca5` (3.17:1
  on `bg`, 3.43:1 on `surface`), dark `#5e72a3` (4.07:1 on `bg`, 3.35:1 on `surface-2`);
  the decorative `border` stays - written by
  `root($options)`, where a site gives its own colours (light and dark) and font;
  `palette()`, `shared()`, `token()`; `fonts` (Manrope, from the package's new
  dependency `@fontsource-variable/manrope`, built into the site's output); `base`,
  `utilities($prefix)`, `tables($prefix)`.
- `@anotoki/lib/ui`: `provideAnotokiUi` (language, the site's words per language, a
  `lookup` hook for a translations module, icons), `AnotokiWords` (the kit's ~50
  words built in for English and Slovak, informal), the icon registry and
  `<anotoki-icon>`, the button, spinner (+ `delay`), badge, alert (+ `announce="polite"`),
  card (+ `headingLevel`), empty and error states, page header (+ `headingId`), avatar
  (+ `shape`), segmented (new: the theme and language switches are made of it),
  autofocus, `uniqueId`, `keepOnScreen`, `mediaQuery`, `tabbable`.
- Entry points of their own: `ui/icons` (the family's other icons, data only),
  `ui/menu` (menu button, menu, items, separator; popover), `ui/dialog` (dialog,
  `AnotokiDialog.open()`, confirm), `ui/toast`, `ui/forms` (text field, textarea,
  password field, select, checkbox, switch, radio group, code input), `ui/tabs`,
  `ui/pagination`, `ui/drawer`, `ui/tooltip` (new, WCAG 1.4.13), `ui/copy`, `ui/qr`.
- `@anotoki/lib/shell`: `provideAnotokiShell`, `ThemeService` (the account's theme
  from the first frame; one save at a time, the latest last), `latestChoiceSaver`,
  the theme switch (segmented, with the refusal note), the language switcher (menu,
  segments or select; notes; keys kept inside), the brand ("anotoki {site} · admin
  panel"), the top bar (the family's one bar: inputs and two actions, never a site's
  service) and the frame (`<anotoki-shell>`: skip link, any bar, side navigation,
  `<main>`); `assets/theme-boot.js` (its storage key from its own tag).
- The fixes made in one copy only, now everybody's: the dialog's Tab skips every
  `tabindex="-1"` element; Tab and Shift+Tab out of a menu close it with the focus on
  its button (`preventDefault()`); keys pressed in the bar stay in it; disabled items
  are left out of the arrows; a waiting Sign in / Sign out keeps its item and the
  focus on the menu's button; menus close when the bar folds and when the person or
  the links change; the breakpoints are signals; menus and notes are kept on screen;
  the toast region is never named by a key; ids from `uniqueId()`.
- Also fixed on the way: the spinner's arc (it was a whole ring: `stroke-dasharray: 60`
  on a 59.7 circumference), the brand's words spaced for screen readers, a dialog's
  width taken from an ancestor's custom property, the checkbox's edge and the switch's
  off track at 3:1 (WCAG 1.4.11).
- `angular/scripts/check-package.mjs` (run by `bun run build`): each entry point's size
  and import boundaries. `angular/projects/showcase`: a development page with every
  piece (never published).

## 0.1.1 - 2026-10-07

Angular only; the PHP half is unchanged (a site may keep `vendor/anotoki/lib` at 0.1.0).

- `<anotoki-site-gate>` takes the pages as a template -
  `<anotoki-site-gate><ng-template><router-outlet /></ng-template></anotoki-site-gate>` - and
  makes them only while the site is open. Content given as it is (the 0.1.0 form, still
  working) is made by Angular whether it shows or not, so a page behind the status page
  ran and sent requests - whose failures a site may show as toasts. Sites should switch to
  the template. (0.2.0: "still working" was wrong for content that starts with a
  control-flow block - see 0.2.0.)
- The ADMIN's "A database update is waiting" page asks again by itself, as the visitors'
  pages do: another administrator may apply the update meanwhile.
- README: the migrations routes are opened exactly (`~^/api/admin/migrations(/file|/apply)?$~D`,
  never a prefix - a crafted path under it would pass the gate), and patterns end with `D`.

## 0.1.0 - 2026-10-07

The first version: the migrations module, one behaviour for every site while its
database waits for an update (the owner's request of 2026-10-07).

PHP (`Anotoki\Lib\Migrations`):
- `Migrator` - reads, applies and records the files of one or more `MigrationSet`s,
  in every shape the sites' `migrations` tables have, never changing one; each file
  whole or not at all, stopping at the first failure; one apply at a time (an
  advisory lock, `busy` otherwise); drafts (`ready`), and the hooks `prepare`,
  `beforeFile`, `beforeApply`; `split: false` for a file sent in one `exec`.
- `Splitter` - a file's statements.
- `Http\SiteState`, `Http\SiteGate` (PSR-15) and `Http\MigrationsRoutes` - the
  states `not_set_up`, `update_pending`, `unavailable`, `ready`; the 503 answers
  (the setup page only while never installed; `Retry-After: 60` while an update
  waits); the public status route and the admin migrations routes.

Angular (`@anotoki/lib/migrations`):
- `SiteStatus`, `siteStatusInterceptor`, `provideAnotokiMigrations`.
- `<anotoki-site-gate>`, `<anotoki-site-status>` (words in English and Slovak,
  overridable), `<anotoki-update-banner>`, `<anotoki-migrations-page>`.
- `ANOTOKI_LIB_VERSION` from `@anotoki/lib`.
