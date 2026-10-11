# Changelog

One version for both halves: the Composer package `anotoki/lib` (from the git tag)
and the npm package `@anotoki/lib` (`angular/projects/lib/package.json`). While the
version is 0.x, a minor version may break what a site uses - each such change is
said here, with what a site must do.

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
