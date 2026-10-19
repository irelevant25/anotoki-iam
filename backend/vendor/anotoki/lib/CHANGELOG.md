# Changelog

One version for both halves: the Composer package `anotoki/lib` (from the git tag)
and the npm package `@anotoki/lib` (`frontend/projects/lib/package.json`). While the
version is 0.x, a minor version may break what a site uses - each such change is
said here, with what a site must do.

## 0.8.1 - 2026-10-19

Two findings of the IAM's security review of 2026-10-19, and the count the IAM's admin panel puts beside Feedback.

**The admin Feedback page names only a proven sender.** A message sent without an account showed the address it
carried as its sender - "From jan@example.com", in the list and the dialog - though the form takes any address
anybody types: somebody with a stolen password could post "please clear my two-factor" signed out, under the
victim's address, and an administrator might believe the "From". Now "From" is the account the sender's token proved
(its name, linked to the person's page as before; "Person 42" where the site has no name for it), else "Not signed
in". The address stands apart, on a line of its own: "Reply address (typed by the sender, not confirmed)" - for a
signed-in account's message too, as the form lets anybody change the address it writes in. Its mail link is as it was.

**Opening a message changes nothing on a GET.** `FeedbackRoutes::admin()`'s `GET $base/{id}` marked a new message
read, and a page on a sibling subdomain is same-site: its `<img src=".../api/admin/feedback/12">` went with the Lax
session cookie and marked messages read under a signed-in administrator. The GET is read-only now, and
`PUT $base/{id}/read` (new; no body needed, or `{}`) marks a new message read and answers `{message}` as the GET does -
one that is not new stays as it is; nobody named, nothing audited, as before. It is a write of the admin group, so the
site's own middleware on the group guards it as every admin write (the IAM's CSRF header). The Feedback page asks the
GET, then the PUT for a new message; a refused PUT is said in the dialog, and the message stays new.

**A count beside a section of the frame's side navigation.** `<anotoki-shell [nav]>`'s sections (`ShellNavItem`, the
side navigation the IAM's admin panel draws) take `badge` and `badgeLabel`, as the top bar's links and
`@anotoki/lib/ui/side-nav`'s (which has had them since 0.6.0): the number drawn as the top bar draws it, filled, at the
row's end, `aria-hidden`; heard after the label with what it counts - the kit's `topbar.linkCount` ("Feedback, 3 new",
"Feedback, nové: 3"; marked as the kit's language on a page whose language has no kit words) or the site's
`badgeLabel` ("Reviews, 12 reviews due"). A 0, null, `''` or `'0'` shows nothing. The unread feedback beside the
admin panel's Feedback: `{ link: '/admin/feedback', label: 'Feedback', badge: unread.count() }`, with
`FeedbackUnread` (`refresh()` once; the Feedback page keeps it in step).

- No new words: the Feedback page's are the administrators' English, written in the page - none is a key; the count
  says the kit's `anotoki.topbar.linkCount`, in the database since 006. Nothing to apply.
- The package check weighs what the release builds, on Linux: a Windows checkout's CRLF, baked into a template's or a
  stylesheet's string as an escaped `\r\n`, is taken out before an entry point is weighed - it weighed the shell about
  0.1 kB more on such a checkout than in CI. No limit changed: the shell is 13.96 kB of 14 with the count, ui + shell
  28.38 of 28.5, and every first load is as it was (the bar's takes no frame).
- Tests: PHPUnit - a GET (an image's request too) leaves a message new and the unread count as it was, the PUT marks
  it and the count follows, again it changes nothing, it never takes a resolved message back to read, it is the
  ADMIN's alone, and 404, 422 and 503 as every route; Vitest - both kinds of sender in the list and the dialog, the PUT
  as a new message opens and none for one already read, a refused PUT, and the shell's count (heard in English and
  Slovak, in the site's words, nothing at 0, the kit's English marked on a page in another language).

What a site must do: take `anotoki/lib` 0.8.1 and the 0.8.1 tarball together (Composer's `^0.8.0` reaches 0.8.1) -
the page's PUT needs the PHP half's route, and a 0.8.0 page against the new GET marks nothing read. Nothing else, where
the site's admin group already guards its writes (the IAM's CSRF header; the sites' bearer token). A site that draws
its admin navigation in `<anotoki-shell [nav]>` may put the unread count beside Feedback, as above.

## 0.8.0 - 2026-10-19

**The About and Support pages** (the owner's words of 2026-10-19, approved word for word): two pages every anotoki
site shows, the same on each (README.md, "About and Support").

- `@anotoki/lib/legal/about` (new), `<anotoki-about-page>`: "About anotoki" - "The name" (あの時 drawn in Japanese,
  `lang="ja"`, its reading *ano toki* in italics, `lang="ja-Latn"`), "Made by one person", "The projects" (Japanese
  Academy, Piano Academy, Genshin Impact, Build Analyzer, Surveys - each name a link to its site, the fan line under
  Genshin Impact and the Build Analyzer) and the last line, whose "support it" links to the Support page.
- `@anotoki/lib/legal/support` (new), `<anotoki-support-page>`: "Support anotoki", the owner's text, the kit's button
  that is a link - "Support on Ko-fi", to https://ko-fi.com/projectanotoki in a new tab, `rel="noopener noreferrer"` -
  and "Thank you!".
- Every address they link to is the library's code, never a string: the five sites (`ANOTOKI_PROJECTS`), the Ko-fi
  page (`SUPPORT_URL`) and the Support page's path. Their words, `anotoki.about.*` (19 keys) and `anotoki.support.*`
  (4), English and impersonal Slovak, are read by the pages themselves (`READ_BY_THEIR_PAGES`, as `anotoki.legal.*`),
  generated into `@anotoki/lib/words/about` and `/support`; the legal configuration gives the language, an owner's
  rewording and the paths - `LegalPaths` gains `about` and `support` (`/about`, `/support` by default) - and they
  work without it too.

**The footer's links: About · Support · Terms · Privacy · Contact.** `<anotoki-legal-links>` - and so the family's
footer - draws About and Support first, to the legal configuration's paths, in the words "About" / "O projekte" and
"Support" / "Podporiť" (a verb, never "Podpora"): `anotoki.legalLinks.about`, `.support`. On a phone (under 28rem)
the five are two lines - About · Support, then the legal pages - so no line begins with a dot (Slovak's five need 407
px of a line).

**The feedback dialog** (the footer's and the Contact page's; the owner's rules of 2026-10-19):

- Nothing typed is lost until the message is sent or the page is loaded afresh: the form's fields are the page's
  draft (`FeedbackDraft`, in memory alone - never the browser's storage), so a dialog closed - Escape, a click
  outside it, Cancel - and opened again shows them. A message sent empties the draft ("Send another" keeps who sends
  it, as before).
- **Clear** ("Clear" / "Vymazať"; "Clear the form" / "Vymazať formulár" to a screen reader, `anotoki.feedback.clear`,
  `.clearName`), at the left of the buttons, apart from Cancel and Send: the kit's quiet red outlined in the danger
  red - an outline, never a filled ground; drawn in the form's own entry point, so no first load carries it. It
  empties every field and the draft (a signed-in person's address is written in again, as on a new form) and puts
  the focus on the message.
- The buttons are the dialog's own footer, always in view: only the form scrolls when it is taller than the window,
  at 390 px too. `<anotoki-feedback-dialog>` (new, `@anotoki/lib/feedback/form`) is the form in the kit's dialog with
  the form's `actions` in the dialog's footer slot (`actionsOutside`); Send names the form (its `form` attribute), so
  Enter in a field still sends. The footer and the Contact page both open it - the Contact page's dialog gains
  Cancel - and `@anotoki/lib/footer/feedback`, the footer's own dialog of 0.6.3, is gone (a site imported nothing of
  it). A form on a page of a site's own keeps its draft and has Clear too.

- Words: `anotoki_translations/013_about_and_support_words.sql` writes the 27 new keys by 002's rule (only where a
  key has no string; its gate names its own keys), and the footer links' group's description, which names five links
  now - 288 library keys.
- The package check: `@anotoki/lib/legal/about` 3.0 kB gzipped (its words 2.4), `@anotoki/lib/legal/support` 1.7
  (0.5), each importing the legal configuration, the core's pieces and its own words; the feedback form 7.3 (6.0),
  with the kit's dialog among what it may import; the footer and the Contact page take it by import() alone. Every
  first load is as it was but the footer's (7.8 kB alone, 8.2 beside lazy pages; 7.6 and 8.1 before) and the legal
  links' (7.6 and 8.4; 7.4 and 8.2): their two more links, their words and their two lines on a phone.
- Tests: Vitest - the About and Support pages (their words in both languages, Slovak addressing nobody, the links to
  the five sites, Ko-fi and the Support path, an owner's rewording, no address in a word, without the configuration),
  the footer's five links, the dialog's buttons in its footer slot, Clear, the draft kept through Escape, Cancel, the
  close button, the backdrop and a failed send, and emptied when sent; PHPUnit - 013's words, a site's own kept, the
  links' group's description, its gate.

What a site must do: take `anotoki/lib ^0.8.0` and the 0.8.0 tarball together (0.x: `^0.7` does not take 0.8.0).
Route `/about` and `/support` - lazy, anybody's, in the frame its legal pages are in - as README.md shows, at the
paths of its legal configuration: the footer links to them from this version on. Apply `anotoki_translations/013` on
the Migrations page (until then the pages and the links say the library's built-in words). A site that opened the
feedback form in a dialog of its own (`<anotoki-feedback-form closable>`) may take `<anotoki-feedback-dialog>`
instead, for the buttons in the dialog's footer; one that typed a whole `LegalPaths` adds `about` and `support`.

## 0.7.2 - 2026-10-19

**The feedback form says whom its message is for** (Angular). The form posted with a plain `http.post`, so
`anotokiAuthInterceptor` took it as the page's `caller()` - a person's whenever the sign-in is set up, signed in or
not - and a person's request with no token is never sent: "Sign in to send a message", and the page followed the
session (`auth.refused()`), losing what was typed. The survey and Piano Academy each marked it in an interceptor of
their own. Now the form sends it as `issuedAs('anybody')`, a new way to say whom a request is for: the person's, with
the token, while somebody is signed in; the visitor's, with the key, while the page is shown to a visitor; nobody's
otherwise. Never refused before it is sent for want of a token (with none it goes as nobody's), and its failure is
handed back as it came, as a report's: no fresh token, no retry, no page following, no sign-in noted as required -
only a visitor key the server no longer knows is forgotten and tried once more, as on any visitor's request. A site
that takes signed-in people's feedback alone (`signedInOnly`) answers 401 `sign_in_required`, and the form says its
kind words in place; what was typed stays. A site's own request may say `issuedAs('anybody')` too.

- `ISSUED_AS`, `Caller` and `issuedAs()` are an entry point of their own, `@anotoki/lib/auth/marker` - importing
  nothing, as `@anotoki/lib/error-report/marker` holds `ANOTOKI_ERROR_REPORT` - which the form names without the auth
  module. `@anotoki/lib/auth` names all three as before: a site's imports stay as they are. `ISSUED_AS` and
  `issuedAs()` take `'anybody'` beside `'caller'`; `Caller` is the three it was.
- `scripts/check-package.mjs`: the marker imports nothing and weighs at most 0.5 kB; the auth module and the
  feedback form may import it, the form never the auth module.

**The library's words in the reader's language when the server is out of reach** (Angular). While no bundle is in
memory - the database out of reach, the site not set up (nothing is asked while `canAsk()` is false), a first answer
still on its way - the translations module knew no language but English, so a Slovak reader read the status page in
English. Now `TranslationService.language` is then what the family's ranking settles on among the languages the
library has built-in words for (`en`, `sk`) in place of the offered ones - this tab's `?lang=`, a choice made before
(the module's stored choice), the account's language, the browser's languages (a Czech browser's Slovak too) - never
a language with no built-in words, English otherwise. `effectiveLanguage`, `<html lang>`, `locale` and the kit's words (`forKit()`) follow it, and so does the
status page, through `siteStatusWords()` and the `language` a site hands `provideAnotokiMigrations()`. The site's own
keys read its compiled English as before, the English areas stay English, `signInLanguage` stays undefined, and once a
bundle comes, the page is in its language: nothing changed with a bundle in memory.

**CI checks the formatting**: `bun run format:check` at the root, a job of its own.

What a site must do: take the 0.7.2 tarball (Composer's `^0.7.0` reaches 0.7.2; the PHP half did not change). A site
may now drop its own `feedbackSenderInterceptor` (the survey's `core/feedback.ts`, Piano Academy's
`core/site-admin.ts`) and its place among its interceptors, with its spec: the form says whom its message is for
itself. One that keeps it changes nothing - it leaves alone a request that says whom it is for. A site that hands
`siteStatusWords()` words of its own read with `t()` from its own keys (the IAM's not-set-up page, `notSetUp.*`) hands
them in its compiled English while no bundle is in memory, keyed by `effectiveLanguage()` - the reader's language now:
key them `'en'` while `ready()` is false, and the library's built-in words of the reader's language show in their
place, or leave them as they are (its English, as before).

## 0.7.1 - 2026-10-19

**The footer says its version short** (the owner, 2026-10-19: "display version like v0.5.0 ... not the whole word
version"): `<anotoki-site-footer>` shows "v0.5.0" where it showed "Version 0.5.0". Its words
`anotoki.footer.version` ("Version {version}", the site's own rewording first) stay, for a screen reader alone, in
their language as before - so no translation changes and nothing to apply. What a site must do: take the 0.7.1
tarball (Composer's `^0.7.0` reaches 0.7.1); nothing else.

## 0.7.0 - 2026-10-18

**The library's folders named as in every other anotoki project** (the owner's choice of 2026-10-18): the Angular
workspace `angular/` is now `frontend/`, and the PHP half `php/` is now `backend/` - moved whole with git, so each
file's history follows it. Nothing either half does changed; every path that named the old folders moved with them:
composer.json's autoload and autoload-dev (`backend/src/`, `backend/tests/`), phpunit.xml.dist, `.gitattributes` (the
Composer archive's allow-list, the LF rule of the migration files and the words) and `.gitignore`, the workflows (the
Angular jobs in `frontend/`, the tag held to `frontend/projects/lib/package.json`, the tarball packed in
`frontend/dist/lib`), the archive's check, the words generator (`frontend/scripts/library-words.mjs`, reading
`backend/resources/library-words.json` - the files it writes say so), the spec and the showcase that read the JSON,
the code's comments and README.md. The released migration files are not edited: their comments name `php/migrations`
and `php/resources`, where those were when each was released.

- The Composer package: GitHub's archive of the tag holds `backend/src`, `backend/migrations`, `backend/resources` and
  `backend/testing` beside composer.json, README.md and CHANGELOG.md - the same files, under `backend/`. The classes
  and their namespace (`Anotoki\Lib\`) are as they were, and the library finds its own files where they are now (each
  module's `Schema::migrationSet()`, `LibraryWords::file()`, `StandInIam::script()`). A site's bookkeeping table
  records a library file by its set and its name (`anotoki_translations/012_footer_words.sql`), never by its folder:
  nothing to apply.
- The npm package: nothing in it moved - the same entry points, `styles/` and `assets/theme-boot.js`; only its
  package.json's `repository.directory` (`frontend/projects/lib`) and its README's pointer to the words' source.

**The family's formatting setup** (the owner's decision of 2026-10-18: one look for the code of every project, both
halves): Prettier at the repository's root, the family's one setup, the same files in every anotoki repository - a
package.json for Prettier alone (`bun run format`, `bun run format:check`; Prettier 3.8.1 and `@prettier/plugin-php`
0.25.0, the PHP formatted for PHP 8.2), `.prettierrc.json`, `.prettierignore` and `.editorconfig` - in place of the
Angular workspace's own `.prettierrc`, `.prettierignore`, `.editorconfig`, its `prettier` dependency and its `format`
script. Then every file formatted with it, in a commit of its own, with no change in behaviour; `endOfLine: auto`
keeps each file's line endings. Prettier leaves alone the generated words (held byte for byte to the JSON by `bun run
words --check` and `LibraryWordsTest`), the migration files and the Markdown. The Composer archive leaves the setup
out, as it leaves out everything at the root but composer.json, README.md and CHANGELOG.md (`.gitattributes`; the
archive's check refuses anything else).

What a site must do: take `anotoki/lib ^0.7.0` and the 0.7.0 tarball together (0.x: `^0.6` does not take 0.7.0).
Every `vendor/anotoki/lib/php/...` it names becomes `vendor/anotoki/lib/backend/...`: its migration runner's library
migration folders where it names them itself (until then it finds none of the library's files there - those applied
show as missing, and none waits); the stand-in IAM its tests and browser checks start,
`vendor/anotoki/lib/backend/testing/stand-in-iam.php`; a build script or a test of the upload that names the library's
files (its migrations, `resources/library-words.json`, `src/...`); and its docs. Nothing in its frontend: its imports
(`@anotoki/lib/...`), `@use "@anotoki/lib/styles"` and the `theme-boot.js` asset (`node_modules/@anotoki/lib/assets`)
are where they were.

## 0.6.3 - 2026-10-18

**The family's footer** (the owner's choice of 2026-10-18, after the deploy: the six live sites' footers differed - their
order, their words, a top border or none, links missing - "I like the footer of builder the most. The order, texts and
the top border."): `@anotoki/lib/footer` (new), `<anotoki-site-footer>`, the build analyzer's footer in the kit's look
(README.md, "Footer").

- A top border, centred, the muted ink at 0.8125rem; a row - "Version X" (`version`, its figures of one width), "What's
  new" (`whatsNew`; pressed, `(whatsNew)`: the site opens its own) and "Feedback" (`feedback`), the kit's small link
  buttons naming the dialog they open, 44 px where the pointer is a finger, a dot between two parts, drawn and never
  read - a part that is off takes its dot with it, and with none the row is not drawn; Terms · Privacy · Contact always
  (`<anotoki-legal-links>`, the site's `provideAnotokiLegal()`); the fan clause where the site asks for it
  (`fan="hoyoverse"`). A `<footer>` landmark; wraps at 390 px; light and dark; not printed, as the bar is not.
- Feedback opens the library's feedback form in the kit's dialog, named by the button's words, with the form's
  Cancel and its Close after the thanks; their code comes with the first press - `@anotoki/lib/footer/feedback` (new,
  the footer's own, 0.9 kB gzipped) - the button busy meanwhile, and the focus is back on the button once the dialog
  goes. With the dialog drawn by the footer itself its first load took 2.0 kB more beside lazy pages, which use the rest
  of ui/dialog's one file.
- Its words: `anotoki.footer.version` ("Version {version}"), `anotoki.footer.whatsNew`, `anotoki.footer.feedback`
  ("Feedback", the build analyzer's word) and `anotoki.footer.fanHoyoverse` (the fan clause's first sentence), English and impersonal Slovak, in
  `php/resources/library-words.json`, generated into `@anotoki/lib/words/footer` and read by the footer itself through
  `pieceWords()` - the site's string first, then the built-in words - never registered (`READ_BY_THEIR_PAGES`: the
  footer has no provide*() of its own); `anotoki_translations/012_footer_words.sql` writes them by 002's rule, its gate
  naming its own keys (261 library keys).
- The package check: the footer 2.6 kB gzipped of its 3; its first load ("the footer": with the legal configuration its
  links read) 7.6 kB alone and 8.0 beside lazy pages, of 9, carrying the kit's, the legal links' and its own words and
  no others; every other first load as it was.
- The showcase: the family's footer under every page (the library's version, What's new, Feedback; `?fan=1`,
  `?feedback=0`) and its samples in the gallery - looked at beside the build analyzer's at 1280 and 390 px, light and
  dark, English and Slovak: the same paddings, sizes, gaps and heights, the family's colours.

**The status page: no header, no footer** (the owner's rule of 2026-10-18): the gate is the outermost of what a site
draws, the site's frame - its bar, its side navigation, its footer - inside its pages (its layout routes, or the marked
template with the outlet); the IAM, with no gate, shows its status page on a route outside its frame (README.md,
"Footer"). `<anotoki-site-gate>` draws the status page in a `<main>` of its own (`anotoki-site-gate__status`): drawn
with nothing of the site round it, it is a whole page.

**Lists compared as sets** (the build analyzer's live database, PostgreSQL 14 on `en_US.utf8`, which sorts text skipping
its punctuation, refused an Apply whose gate compared catalog names in byte order with TEXT in the database's order):
the library holds one such comparison, `anotoki_translations/001`'s check of the primary keys (sorted attnames against
sorted TEXT). It runs only as 001 is applied, never on an installed database, and its names (`code`; `name`;
`key_name`, `language_code`) sort alike in every collation, so it refuses nothing; released, it stays as it is. Every
other check of the library's sets goes name by name, and the runtime orders by the bytes (`COLLATE "C"`, the catalog's
`name`, PHP's sort) or compares sets - nothing to change. `SchemaTest` applies the library's three sets over each
aligned site's tables on ICU's `en-US-u-ka-shifted` (PostgreSQL 15+; skipped elsewhere) and reads the keys there in byte
order, and CLAUDE.md has the rule: a gate that compares lists compares them as sets, or sorts both sides `COLLATE "C"`.

- Tests: Vitest - the footer (its row and its absences with no stray dot, What's new's output, the bare attributes, the
  feedback form in the dialog with its code fetched by the press and the focus back after Cancel, Escape and the close
  button, the fan clause only when asked, Slovak, the site's own words first, English marked so on a page of another
  language) and the gate's status page in its landmark, a framed site's bar and footer not drawn with it (873 tests);
  PHPUnit - 012's words, a site's own kept, its gate; the library's sets on a punctuation-skipping collation (531, 1
  skipped).

What a site must do: replace its own footer with `<anotoki-site-footer>` in the family's frame of every layout - its pages
and its admin panel (genshin: its admin panel only) - giving it `version`, `whatsNew` and `(whatsNew)`, `feedback` where
it takes feedback, and `fan="hoyoverse"` on a fan site; its own footer, its feedback dialog and their words go (the
footer opens the form itself). Apply `anotoki_translations/012` on the Migrations page (four keys; until then the footer
says the library's built-in words). Put its frame inside its pages, the gate outermost: Piano Academy and Japanese
Academy, whose bar and footer stand round the gate, move them into the gate's marked template - and Piano's `<main>`
round the gate goes, the gate drawing one for its status page; the IAM shows its not-set-up page on a route outside its
frame. A site's spec that read the status page as the gate's own child finds it in `main.anotoki-site-gate__status`.

## 0.6.2 - 2026-10-18

**Three sentences of the Privacy page, made true on every site** (found by the survey's and Piano Academy's site
passes of 0.6.0):

- `anotoki.legal.privacyAccountRecord` said a site "does not keep your account's e-mail address", but a site that
  takes feedback keeps the reply address the form fills in from the account. It now says the address is kept only as
  a feedback message's reply address, if the sender leaves it in the form - and that the account's deletion takes the
  reply address out with the name (as the scrub always did: `Feedback::scrub`).
- `anotoki.legal.backupsKept` said "the daily copies for {daily} days", but the library keeps the `{daily}` newest by
  count, and makes a daily copy only on a day the site is used. It now says so; its description too.
- `anotoki.legal.storageSignInPurpose` said "when the site last signed you in by itself", which a site that never signs
  anybody in without a click (no sign-in hint) does not do. It now says it of a site that does.

`anotoki_translations/011_privacy_words_made_true.sql` rewords each of the six strings only while it is still 005's
word for word and nobody saved it (`updated_by IS NULL`): a site's own wording and an administrator's deliberate save
stay - the rule every rewording file keeps from now on (CLAUDE.md). Tests: PHPUnit - 011 on a site built through 010,
with an administrator's save and a site's own words kept, and its gate; Vitest - the Privacy page's new words.

What a site must do: apply `anotoki_translations/011` on the Migrations page with the rest of 0.6's set. A site that
worded one of the three in its own facts keeps its own words.

## 0.6.1 - 2026-10-14

**The Privacy page's backups, made true** (found by the IAM's site pass of 0.6.0): the two sentences on where the
backups are said the copies "stay on the site's own server", but an administrator may download one from the Backups
page and keep it elsewhere - the IAM's operations guide asks its owner to. `anotoki.legal.backupsWherePlain` and
`anotoki.legal.backupsWhereEncrypted` now say so ("... an administrator may also keep a downloaded copy elsewhere", the
encrypted one "encrypted the same way"), in English and the impersonal Slovak.

- `anotoki_translations/010_backups_where_words.sql` rewords each of the four strings only while it is still 005's
  word for word - a site that reworded either sentence on its Translations page keeps its words -, and its gate checks
  that both keys have English and that no string is left in 005's words. The Angular half's built-in words follow
  (`bun run words`).
- Tests: PHPUnit - 010 on a database in 005's words, with a site's own Slovak kept, and its gate; Vitest - the Privacy
  page says the new words.

What a site must do: apply `anotoki_translations/010` on the Migrations page with the rest of 0.6.0's set (on a site
that applied 005 already, until then the page reads the old sentence).

## 0.6.0 - 2026-10-14

**The legal pages** (the first part of 0.6.0; the owner's decisions of 2026-10-13): **Terms**, **Privacy** and
**Contact** for every anotoki site - their structure and the family's shared wording are the library's, each site
gives its own facts (README.md, "Legal pages"). The texts are a good-faith draft for the owner, not legal advice.

- `@anotoki/lib/legal` (new): `provideAnotokiLegal()` - the site's facts (`LegalFacts`: its name and what it is, what
  it keeps, what for and how long, its visitors, the feedback form, the error log, the audit log, the backups, its
  cookies and browser storage, counting visits, who else handles data, where the account lives, the fan clause),
  its paths, and the operator (`LEGAL_OPERATOR`: Code Hero s.r.o., linked to its site, run from Slovakia; the
  support address kept in two parts and joined only as a page draws it) - and `<anotoki-legal-links>`, Terms ·
  Privacy · Contact for a footer, which registers its words (`anotoki.legalLinks.*`). 1.5 kB gzipped in a first load.
- `@anotoki/lib/legal/pages` (new): `TermsPageComponent`, `PrivacyPageComponent`, `ContactPageComponent`, each for a
  lazy route - drawn with the kit, a heading per section, the list of sections moving the focus, 390 px; the Contact
  page opens the feedback form in the kit's dialog (`@anotoki/lib/feedback/form`, its code loaded with the press, the
  focus given back to the button after) on a site that takes feedback, and gives the address alone elsewhere. The
  pages read their words (`anotoki.legal.*`) themselves and never register them: pages of text, they come with the
  pages alone. `scripts/check-package.mjs` lets those pages import them unregistered (`READ_BY_THEIR_PAGES`), refuses
  any other importer, and holds every first load free of them (a new first load: the words + the legal links).
- The library's words: `anotoki.legal.*` (118 keys) and `anotoki.legalLinks.*` (4), English and Slovak - the Slovak
  impersonal (no "ty", no "Vy"), so that the pages fit the formal survey too - in `php/resources/library-words.json`,
  generated into `@anotoki/lib/words/legal` and `/legal-links` (`bun run words` says in their headers how each is
  read); `anotoki_translations/005_legal_words.sql` writes them by 002's rule (a key's strings only where it has none)
  with its gate on English. 216 library keys in all.
- Tests: the PHP half's schema test compares the database's words with the JSON in PHP's order (a Slovak server
  collation reads "ch" as one letter after "h"), and the command test reads the coverage's columns as the command
  writes them (a count of three digits); Vitest covers the pages, the footer's links and the words (703 tests).

What a site must do: nothing to keep building - the pages are new. To show them: apply `anotoki_translations/005` on
the Migrations page (122 keys: about 10 kB of text a language in the site's bundle); give `provideAnotokiLegal()` its
facts - the site's own words as keys of its own in its database, English and Slovak - and add the three lazy routes;
put `<anotoki-legal-links>` in the footer (in the family's frame, `[shellFooter]`), or the three links in its own
footer with the same words (`anotoki.legalLinks.*`); name the hosting company once the owner gives it ("to be named"
until then). Per site: `feedback` on genshin, Piano Academy, Japanese Academy and the build analyzer (the IAM and the
survey take none); `visitors` where it has them; `fanDisclaimer: 'hoyoverse'` on genshin and the build analyzer; the
IAM `account.isIam`, `backups: { encrypted: true }`, its audit log in its own words, `google` and `mail` among the
processors, its session and device cookies - and its own Privacy page at the address the other sites link to.

**The admin pages' fixes** (the second part of 0.6.0; each found by a site's review or UI test in rounds 2 and 3):

- The base `code` style no longer shows through the admin pages - in the pages' own styles, `@anotoki/lib/styles`' base
  unchanged: the Translations page breaks a key only after a dot ("anotoki.legal." then "backupsWhereEncrypted": a
  `<wbr>` after each dot, which adds nothing to what a screen reader hears or a copy takes), inside a word only when a
  word alone is wider than the line, where the base broke code anywhere ("anotoki.feedback.anonymou|s"); the Backups
  page's id reads as an id - small, muted, its own width - not a grey bar the width of its cell; the Errors page's path
  in a row keeps its chip around the path instead of stretching it across the cell.
- The Errors page says where a page's own error (`source: client`) happened: the page's path in its row ("/kanji",
  "and 1 more page"), as its details' Page, in its occurrences' Page column and under "On the pages" - no longer the
  report's own route in the row (`POST /api/client-errors`) nor its method in the details ("POST /kanji"). The PHP
  half writes the lines as before: old lines read the same way.
- The Errors page's Range lines up with Level and Status: its label at the fields' gap, the segmented control (38 px)
  on the middle of the selects (44 px), where its label sat 6 px below theirs.
- `anotokiAuthInterceptor` leaves a report of the page's own error alone, as the README said an interceptor may: a
  request marked `ANOTOKI_ERROR_REPORT` goes as any request - the token (or, with none to send, not at all), a
  visitor's key - and its failure comes back as it came: no fresh token and no retry, no page following a session that
  ended (nothing cleared, no reload, no sign-in), no key forgotten, no sign-in noted as required. The mark is an entry
  point of its own, `@anotoki/lib/error-report/marker` (a token, 0.1 kB), which the auth module reads without the
  reporter - through `@anotoki/lib/error-report` the sign-in's first load would have grown 2.3 kB gzipped beside lazy
  pages; that entry point names it too, so a site's import stays as it is.
- No refusal or answer carries a failure's own words any more - a TypeError's names the absolute path of the file it was
  called in (a site's mutation run found one in a `restore_failed`), a database's its host, its user and the values it
  quotes: a fixed sentence of the library's says what failed and that the error log says why, and the failure goes to
  the site's `onError` (its error log, redacted there). Backups: `backup_failed`, `before_restore_failed`,
  `restore_failed` (and its `backup.restore_failed` entry in the audit log, kept for good; a restore a hook ended still
  says what had loaded may have stayed, and names the backup of before), `archive_invalid`, `ticket_failed`,
  `database_unavailable`, the status card's problem, `after_restore_error`, and the Apply's error when the backup before
  it fails; `onError` now hears `archive_invalid`, `ticket_failed`, `database_unavailable` and the status card too.
  People: a scrub a hook stopped says so in the library's words on the People page (`sync.error`), what the hook threw
  going to the server's log, redacted (it went there unredacted). `JsonBody`'s `invalid_json` says "The body is not
  JSON." without the parser's words. Left as they are, on purpose: the Migrations page's error - PostgreSQL's words
  about the site's own SQL, and a site hook's own refusal (genshin's order guard): what that page is for - and the
  command lines, which speak to the owner's own terminal.
- The top bar: a link with a number beside it is heard "Feedback, 3 new" - the number seen alone (`aria-hidden`), what
  it counts heard after the link's name: the kit's new `topbar.linkCount` ("{count} new", Slovak "nové: {count}") or
  the site's own words, `TopBarLink.badgeLabel` ("125 reviews due"), in the page's language and marked so inside a link
  marked as another; the folded bar's button "Menu: Feedback, 3 new" (links apart by "; "). The words are visually
  hidden, as the kit hides any: Chromium's tree puts a space before their comma ("Feedback , 3 new"), which is not
  heard. `anotoki_translations/006_top_bar_words.sql` writes the key by 002's rule, its gate on English (217 library
  keys).
- Tests: PHPUnit - a real TypeError in a restore's hook and in its after-restore work (its absolute path in the error
  log, never in the answer or the audit entry), a database that cannot be asked and a tickets folder that cannot be
  written, a hook's TypeError in the scrub, the JSON body's sentence, 006's words and gate; the tests that read a
  refusal's words for what failed read the error log's. Vitest - the report's 401 through the real reporter and the
  interceptor, one with no token to send and a visitor's; a page's own error's row and details; a key's pieces; the
  bar's link heard in English and Slovak, with the site's words, inside a link of another language.

What a site must do: apply `anotoki_translations/006` on the Migrations page (one key; until then the kit's built-in
words say it). A site whose link's number counts something else than new things gives `badgeLabel` in its words -
Japanese Academy's Kanji, its reviews due: without it the link is heard "Kanji, 125 new". A site's spec that reads a
badged link's `textContent` finds the hidden words after the number ("Feedback 3, 3 new"): it reads the badge
(`.anotoki-top-bar__badge`) or the name a screen reader is given. A site's test that reads a Backups refusal's,
`after_restore_error`'s or the People scrub's words for what failed reads its `onError` (the error log) instead; one
that reads `invalid_json`'s words in brackets, the plain sentence.

**The kit's simple pieces** (the third part of 0.6.0): what the sites still drew themselves at round 2's end
(README.md, "0.6.0's pieces" and the core's pieces). Every new piece is an entry point of its own, never re-exported by
`@anotoki/lib/ui` and outside every first load; the core's pieces gained what only they can hold.

- `@anotoki/lib/ui/chips` (new): `button[anotokiChip]` - a small round-ended button with a `tone` (a step's state),
  `pressed` (a toggle) or `checked` (a radio), a check before the words of a chosen one, 44 px where the pointer is a
  finger; `<anotoki-chip-group>` - choice chips that wrap, one at a time (a radio group: one Tab stop, the arrows, Home
  and End over the chips that cannot be chosen) or several (`multiple`, toggles, `[(values)]` in the options' order),
  a field of the kit's (label, hint, error, required, optional, disabled), an option's words in another language
  (`lang`), a quieter note (a count); `<anotoki-chip-list>` - removable chips, each button named with its chip's words
  ("Remove: Furina"), the focus going on to the next chip once the page takes one away. (Piano's step chips,
  Japanese Academy's choice rows, genshin's chips and toggle groups.)
- `@anotoki/lib/ui/segmented`: options may be `disabled` (shown, passed over by the arrows; the Tab stop the first that
  can be chosen) and carry a colour `mark` (a CSS colour of the site's, the build analyzer's grades), and options too
  wide for their line wrap onto another instead of running off it (the pill a rounded box then) - nothing changes
  where they fit. The kit's select shows no marks: a native `<option>` holds words alone.
- `@anotoki/lib/ui/badge`: `wrap` - long words wrap inside the badge (Japanese Academy's paper).
- `@anotoki/lib/ui/button`: `variant="danger-ghost"`, the quiet red of a row's Delete (genshin); a small link button
  (`variant="link" size="sm"`) is 44 px high and wide where the pointer is a finger (`pointer: coarse`; 24 px for a
  mouse, as before) - Japanese Academy's "All", "None" and "▶ Listen".
- `@anotoki/lib/ui/card`: `tone="highlight"`, the page's call to action (Piano's Continue card); `@anotoki/lib/ui/link-card`
  (new): `a[anotokiLinkCard]`, a card that is one link as a whole, its focus ring round the card, development warning
  about a control inside (the build analyzer's grid of characters).
- `@anotoki/lib/ui/stat-tiles` (new): `<anotoki-stat-tiles>` of `<anotoki-stat-tile label value note>` - figures in a
  grid as wide as the page allows, a number written the page's way (Piano's Stats).
- `@anotoki/lib/ui/progress` (new): `<anotoki-progress-bar>` - `role="progressbar"` with its value, ends and name, the
  value said as "45 %", "3 of 10" or the page's own words, moving on its own with no value, a tone or the site's own
  colour (`--anotoki-progress-color`) (the build analyzer's score bars).
- `@anotoki/lib/ui/icons`: the build analyzer's own icons, the family's now - `iconRocket`, `iconUpload`,
  `iconLightbulb`, `iconPause`, `iconMinus`, `iconWrench`, `iconGamepad`, `iconSave` (the admin pages' own copy of
  upload is the same drawing).
- `@anotoki/lib/ui/piece-words` (new): `pieceWords()`, how a piece of its own entry point reads its own words - the
  site's string first (provideAnotokiUi's `lookup`: the database's), then the built-in words of the page's language,
  then English. Such a piece has no provide*() function, and provideAnotokiUi is in every first load: its words are
  read by the piece itself, never registered, as the legal pages read theirs (`READ_BY_THEIR_PAGES` in both scripts).
- `@anotoki/lib/ui/disclosure` (new): `<anotoki-disclosure>` - a button with `aria-expanded` and `aria-controls`, in a
  heading of the page's outline with `headingLevel`, its content made at once and hidden, or given as
  `<ng-template anotokiDisclosurePanel>` made only while open and dropped when closed - or kept once made (`keep`); a
  card's look or `plain`. `<anotoki-accordion>`: one open at a time or `multiple`, Down, Up, Home and End between its own
  disclosures' buttons (Japanese Academy's, the build analyzer's and genshin's `<details>`).
- `@anotoki/lib/ui/stepper` (new): `<anotoki-stepper>` - a named list of steps, the current one `aria-current="step"`
  heard "Step 3 of 4: Import", one done heard "…, done" with a check, `navigable` steps done as buttons back to them,
  the marks alone where the row is narrow (its own width: a container query); `<anotoki-step-dots>` - the same for the
  ear, dots or pills of short words for the eye (the build analyzer's wizard, Piano's lesson dots).
- `@anotoki/lib/ui/labelled-group` (new): `<anotoki-labelled-group>` - a label drawn as the fields', naming a group of
  controls (role="group"), labelling one native control (`for`), or shown beside a control that names itself
  (`selfNamed`), stacked at a field's height or `inline` (Japanese Academy's choice labels). The Errors page's Range and
  the Feedback dialog's Mark as are drawn with it now (their own label classes gone; the Range still lines up with the
  selects beside it; Mark as in the text's ink, as every field's label, where it was muted).
- `@anotoki/lib/ui/page-header`: a lead with markup of its own, `[pageLead]` content beside the plain `lead` (genshin).
- `@anotoki/lib/styles` (tables): `.table-middle`, every cell on its row's middle (genshin); stacked (`.table-stack`),
  a number's cell starts at its label (Piano) and a badge or a button keeps its own width instead of stretching across
  the column.
- `@anotoki/lib/ui/dialog`: `size="xl"`, 64rem (genshin's editors and previews); `DialogSize` names the four.
- `@anotoki/lib/ui/spinner`: `showLabel` and `inline` take a bare attribute (Piano).
- `@anotoki/lib/ui/select` and `@anotoki/lib/ui/field` (new): the select alone, for a first page that draws one (Piano's
  piano box in its bar, a `@defer` until now), and the fields' base they share; `@anotoki/lib/ui/forms` names both as
  before. Measured by the package check's new first load "a select": 7.0 kB gzipped beside lazy pages, where the select
  from `ui/forms` took every field with it - 11.2.
- The admin tools' Backups page: a restore that failed is headed "The restore failed", the answer's own sentence under
  it - rolled back whole, or ended with what it had loaded perhaps still there -, where the heading said "nothing of it
  stayed" of both.
- The library's words: `anotoki.chips.remove`, `anotoki.progress.count`, `anotoki.stepper.stepOf` and
  `anotoki.stepper.done`, English and impersonal Slovak, in `php/resources/library-words.json`, generated into
  `@anotoki/lib/words/chips`, `/progress` and `/stepper`; `anotoki_translations/007_kit_piece_words.sql` writes them by
  002's rule, its gate naming its own keys (221 library keys).
- The package check: the ui and the shell together may weigh 28.5 kB gzipped (28 before; 28.3 now, 27.96 before): the
  segmented control's, the button's, the badge's, the card's, the page header's and the spinner's additions are the core
  pieces' own. The bar's first load is 20.2 kB of its 21.5 (19.9 before), a button's 5.0 of 5.5 (4.9), the gate's 8.5 of 9
  (8.4); every new piece stays out of every first load, and a select alone has a first load of its own (7.0 of 7.5).
- Tests: Vitest for every piece - its roles, its aria states, its keyboard, its words in English and Slovak and the
  site's own -, the segmented control's disabled options and marks, the dialog's xl, the spinner's bare attributes, the
  page header's lead, and the restore's heading with each answer (760 tests); PHPUnit for 007's words, its rule and its
  gate (515, 1 skipped).

What a site must do: nothing to keep building - everything is new, and the defaults are as before but three that change
how a page looks where it was broken or small: a segmented control too wide for its line wraps instead of running off
it, a small link button is 44 px on a touch screen, and a stacked table's badges keep their own width. To use the new
words in a site's own wording, apply `anotoki_translations/007` on the Migrations page (four keys; until then the pieces
say the library's built-in words). A site that draws the select on its first page imports it from
`@anotoki/lib/ui/select` (its `@defer` may go).

**The kit's navigation pieces** (the fourth part of 0.6.0): what the sites still drew themselves - Japanese Academy's
section tabs, the build analyzer's and genshin's tabs with a count or an icon, genshin's tabs cut off on a phone with
no hint that they scroll, genshin's grouped admin navigation (README.md, "0.6.0's pieces"). The IAM's admin panel keeps
its own side navigation for now.

- `@anotoki/lib/ui/tabs`: a tab takes an `icon` before its label and the kit's `badge` after it (a count or a short word,
  a `badgeTone`) - seen as it is and heard after the label with a comma, as itself or in the site's words
  (`badgeLabel`): "Issues, 3", "Issues, 3 open" - and a `lang` on a label of another language; `count` is as it was.
  `<anotoki-link-tabs>` (new): a section's pages drawn as tabs - a named `nav` of ordinary links (`routerLink`, each a
  Tab stop, never the ARIA tabs pattern), the page's link `aria-current="page"`: the router's (`exact`, else the most
  specific of those the address is at or under) or the one `current` names whatever the address says. Tabs and link
  tabs that do not fit their line scroll sideways in the kit's strip, never the page: the edge with more beyond it
  fades under a small arrow (28 px to a mouse, 44 to a finger) that moves the row; the arrows are out of the tab order,
  `aria-hidden` and never take the focus (the keyboard and a screen reader reach every tab itself, each brought into
  view clear of the fade as it takes the focus); the chosen tab, the page's link, is brought into view as it is drawn
  and whenever it changes. The tabs' markup gained a strip around the tablist (`anotoki-tab-strip`, its scroller
  `anotoki-tab-strip__scroller`) and a span around each label (`anotoki-tabs__label`): a tab's words and the tablist's
  children are as they were.
- `@anotoki/lib/ui/side-nav` (new): `<anotoki-side-nav>` - a named `nav` of links in groups under headings that name
  their lists (`headingLevel`: headings of the page's outline), optionally `collapsible` (a button with `aria-expanded`
  and `aria-controls`; `collapsed` starts closed; the group of the page's link opens by itself); links with an icon and
  a badge (filled as the top bar's, heard "Feedback, 3 new" - the bar's `topbar.linkCount` - or in the site's words),
  pages elsewhere in a new tab, the page's link `aria-current="page"`. Up to the site's `phoneMax` it moves into the
  kit's drawer, opened by its own button or by the site's anywhere in its page (`[anotokiSideNavTrigger]`): the button
  says `aria-expanded`, `aria-haspopup="dialog"` and `aria-controls` while the drawer is there; the focus goes in and
  comes back to the button, the drawer closes on a link, Escape, the backdrop and its Close, and nothing of the
  navigation is in the tab order while it is shut.
- `@anotoki/lib/ui/current-link` (new): `currentLink()`, which of a navigation's links is the page's - what the link
  tabs and the side navigation mark, a site's own navigation too.
- The library's words: `anotoki.tabs.scrollBack` and `anotoki.tabs.scrollForward` (the arrows' tooltips, "Earlier tabs"
  and "More tabs"), English and impersonal Slovak, read by the tabs themselves (`@anotoki/lib/words/tabs`,
  `READ_BY_THEIR_PAGES`); `anotoki_translations/008_tab_words.sql` writes them by 002's rule, its gate naming its own
  keys (223 library keys). `anotoki.topbar.linkCount`'s description names the side navigation too (006, unreleased,
  its description alone).
- The package check: the new entry points are outside every first load and the core; ui + shell stay 28.34 kB of 28.5
  (the shell 13.92 of 14), every first load as it was; `ui/tabs` 5.1 kB gzipped (2.0 before), `ui/side-nav` 5.1,
  `ui/current-link` 0.6 - lazy pages.
- Tests: Vitest for the tabs' icons, badges and words, the strip (its fades, its arrows, what it brings into view, at
  once or gliding, reduced motion, the focus), the link tabs (the nav, the router's page, `exact`, `current`, a click),
  `currentLink()` (paths relative to the page, after every navigation), the side navigation (its groups, headings,
  badges in English and Slovak, a page elsewhere, collapsible groups and the tab order, the drawer and its focus, the
  site's own button, a turned tablet, groups made again) (791 tests); PHPUnit for 008's words, its rule and its gate
  (518, 1 skipped).

What a site must do: nothing to keep building - the new pieces are new, and a row of tabs that fits looks as it did; a
row that does not fit now fades at its edge under an arrow where it was cut. To word the arrows in a site's own way,
apply `anotoki_translations/008` on the Migrations page (two keys; until then the tabs say the library's built-in
words). A site's spec that reads a tab's `textContent` finds a badge's hidden words after it ("Issues 3, 3"): it reads
the badge (`anotoki-badge`) or the name a screen reader is given.

**The kit's input pieces** (the fifth part of 0.6.0): the inputs genshin and the build analyzer still drew themselves
(README.md, "0.6.0's input pieces"). Each an entry point of its own, never re-exported by `@anotoki/lib/ui` and outside
every first load; each a field of the kit's (label, hint, error, invalid, optional, disabled). What the browser checks
in them is a convenience, never a defence: the server checks every value again.

- `@anotoki/lib/ui/date-field` (new): `<anotoki-date-field>` - a date typed in the reader's date format (`format`: the
  anotoki account's `date_format`, or null for the page language's own; typing lenient - any separator, a 0 or none, an
  ISO date in any format) or chosen in a calendar opened by a button inside the field (or Alt+Down): a modal dialog
  under it, a grid of the month's days (Intl's names, in the page's language) - the arrow keys, Home and End, Page Up
  and Page Down (with Shift a year), Enter, Escape back to the field, Tab round the dialog; every day named whole, the
  chosen one `aria-selected`, today `aria-current="date"`, `min` and `max` struck through and stopping the keyboard. The
  value is ISO: `'YYYY-MM-DD'`, `''` for none; `dayMonth` (genshin's birthdays) a day and a month without a year,
  `'--MM-DD'`, 29 February included. A text that is no date, or one outside `min` and `max`, is no value (`''`), and the
  field says why once left. Genshin's `hideYear` stored its birthdays in year 4 (`0004-02-29`): it reads `--02-29` now.
- `@anotoki/lib/ui/slider` (new): `<anotoki-slider>` - the native range input in the kit's look (the keyboard, a finger
  and a screen reader as everywhere; a 44 px box), marks under the track with their words, the value shown and heard in
  words where it has them (`aria-valuetext`: a tick's words, or the page's `valueText`) - genshin's three difficulties
  were heard "0, 1, 2".
- `@anotoki/lib/ui/color-field` (new): `<anotoki-color-field>` - the device's colour picker behind a colour box and
  the colour's code beside it, kept in step (a typed code checked: `#rrggbb`, `#rgb`, with or without `#`), suggested
  colours as a radio group, each with its name - never a colour alone. The value is `'#rrggbb'` lower-case, `''` for
  none (the box crossed out, never black). `readColor()` reads a code the field's way.
- `@anotoki/lib/ui/file-picker` (new): `<anotoki-file-picker>` - files chosen with a real button (the device's chooser)
  or dropped on the area round it (never the only way; the drop words not said on a touch screen); the kinds taken
  (`accept`, as the attribute says them) and the largest size (`maxSize`) checked in the browser - a file refused with
  its reason (role="alert"); one file or `multiple` (never the same twice); the files listed with a remove button each,
  the focus going on as one goes; a screen reader told what was chosen. It never uploads: `files` is the browser's
  `File` objects. The browser's check is a convenience: the server checks every file again.
- `@anotoki/lib/ui/combobox` (new): `<anotoki-combobox>` - the ARIA 1.2 combobox with a list box (genshin's characters
  with their portraits): typing filters, accents and case aside, by names and `keywords`, the names starting with it
  first; Down, Up, Alt+Down, Enter, Escape; the active option by aria-activedescendant, the focus kept in the field; a
  picture beside its name with an empty alt; "No matches"; a long list drawn `limit` at a time with how many more wait
  ("30 more: keep typing"), both told to a screen reader; one at a time (`[(value)]`) or several (`multiple`,
  `[(values)]`, the chosen ones the kit's removable chips over the field). The list box in the top layer, as wide as the
  field.
- `@anotoki/lib/ui/rich-text` (new): `<anotoki-rich-text-toolbar>` - the ARIA toolbar pattern over an editor (one Tab
  stop, Left/Right/Home/End, `aria-pressed` for a format on, drawn with a ring), twelve buttons with the kit's icons and
  names (`tools`, `'|'` between groups), `unavailable` and `disabled` kept in the row aria-disabled, a site's own
  controls in its row and Tab stop (`[anotokiToolbarItem]`). It holds no text and inserts none: a press is `(command)`
  for the site's editor - a library's (TipTap), or a plain contenteditable through `[anotokiRichTextEditable]`
  (`run()` with the browser's own editing, the selection put back first; `link()` refusing every address but a web,
  mail or the site's own - `safeLinkAddress()`; `active()`; a paste or a drop of markup comes in as plain text, a drop of
  files not at all). The site cleans what its editor holds before it shows it, and its server what it keeps.
- `@anotoki/lib/ui/icons`: `iconIndent` and `iconOutdent`, drawn as the family's others (the toolbar's own copies).
- The library's words: `anotoki.dateField.*` (6: the calendar's button and name "Choose a date: {field}", the month
  buttons, "Write the date like {example}.", the earliest and latest date), `anotoki.colorField.*` (3: the colour box's
  and the swatches' names after the label, the code's message), `anotoki.filePicker.*` (9: the button, the drop words,
  "{name} is too large: {size}, at most {max}.", the kind refused, one file only, "Remove: {name}", "Chosen: {names}"),
  `anotoki.combobox.*` (3: "No matches", "{count} more: keep typing to narrow the list.", "{field}: chosen") and
  `anotoki.richText.*` (13: "Formatting" and the twelve buttons), English and impersonal Slovak, read by the pieces
  themselves (`@anotoki/lib/words/date-field`, `/color-field`, `/file-picker`, `/combobox`, `/rich-text`,
  `READ_BY_THEIR_PAGES`); `anotoki_translations/009_input_words.sql` writes the 34 by 002's rule, its gate naming its own
  keys (257 library keys). Month and day names are never words: Intl writes them.
- The package check: every first load as it was (ui + shell 28.34 kB of 28.5); the input pieces are lazy, each held to
  a limit of its own (`LAZY_LIMITS`, kB gzipped: `ui/date-field` 9.4 of 10 - the field, its calendar and the dates'
  reading and writing -, `ui/slider` 3.5 of 4, `ui/color-field` 4.3 of 4.5, `ui/file-picker` 5.2 of 5.5,
  `ui/combobox` 6.7 of 7, `ui/rich-text` 5.4 of 6); no bundle names an HTML sink. The pieces that draw the family's
  icons carry their own copies (`own-copies.spec.ts`: the calendar, the picker's two, the toolbar's twelve).
- Tests: Vitest - the dates' reading and writing in the four formats and the languages' own, the field's typing, its
  messages, min and max, the calendar's dialog, grid, keys, focus and words in English and Slovak, a day and a month; the
  slider's label, words, marks and fill; the colour field's code, box, swatches and words; the file picker's checks,
  chooser, drops, list, focus and words; the combobox's filter, keys, active option, pictures, notes, one and several,
  chips and words; the toolbar's role, names, pressed states, one Tab stop and arrows with a site's own item, presses and
  words, and the contenteditable's commands, kept selection, states, links and pastes (861 tests); PHPUnit for 009's
  words, its rule and its gate (520, 1 skipped).

What a site must do: nothing to keep building - the pieces are new. To word them in its own way, apply
`anotoki_translations/009` on the Migrations page (until then they say the library's built-in words). Genshin, as it
takes them: its birthdays from `0004-MM-DD` to `--MM-DD` (or back, at its API), the account's `date_format` into
`format`; its own toolbar's buttons for the kit's (its font menus and colour pickers as `[anotokiToolbarItem]`s), its
editor's `execCommand` calls through `[anotokiRichTextEditable]` - its paste then plain text -, and its own allow-list
kept for showing the editor's text.

**The security review of 0.6.0** (the whole of it, read-only, 2026-10-14): no High, no Medium; its three Lows fixed.

- `[anotokiRichTextEditable]`: a paste or a drop that comes with no data transfer (no browser does this on a
  contenteditable today) lets in at most the event's own text as text, never what the browser brought - it failed open.
- `<anotoki-segmented>`: an option's colour mark is bound as `background-color`, so a mark is a colour and never a
  picture (`url(...)` from another host).
- The package check refuses every way a string becomes markup, as the IAM's deploy check does: `setHTMLUnsafe`,
  `parseHTMLUnsafe`, `srcdoc`, `document.write`, `DOMParser`, `parseFromString` and `createContextualFragment` beside
  `innerHTML`, `outerHTML`, `insertAdjacentHTML` and the sanitizer's bypasses - for the five sites with no deploy check
  of their own, it is the only gate.

What a site must do, from the review: keep every address it gives `provideAnotokiLegal()` (the account's pages, the
processors', the operator's) in its code, never in a string an administrator can reword; a site that takes the file
picker checks each file's kind by its content, and its size, on its server; genshin keeps its server's allow-list for
the editor's HTML (`href` schemes too) and the one that shows it - the kit is no defence for either.

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
