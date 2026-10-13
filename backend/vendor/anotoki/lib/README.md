# anotoki-lib

What the anotoki sites share - the IAM, genshin, the survey site, Piano Academy,
Japanese Academy and the build analyzer - in one library with two halves and one
version:

| Half | Package | Where |
| --- | --- | --- |
| PHP 8.2+, Slim 4, PostgreSQL | `anotoki/lib` (Composer) | `composer.json`, `php/src/`, `php/migrations/`, `php/resources/`, `php/testing/` |
| Angular 21 (standalone, zoneless, signals) | `@anotoki/lib` (npm tarball) | `angular/projects/lib/` |

Version 0.5.2 holds:

- **migrations** - the engine that brings a site's database up to date, with
  the library's own migration sets beside a site's, and one behaviour for every
  site while an update waits (both halves);
- **the UI kit** (Angular) - the family's styles and palette
  (`@anotoki/lib/styles`), its components (`@anotoki/lib/ui` and its entry
  points, the core a file per piece) and its top bar and frame
  (`@anotoki/lib/shell`);
- **translations** (both halves) - a site's words, its languages and their admin
  pages, one implementation for every site (the section "Translations" below);
- **auth** (both halves) - signing in through the anotoki IAM, with the sign-in
  hint and the screen of a sign-in that failed (the section "Auth");
- **people** (both halves) - each site's record of the accounts that use it, the
  scrub of the accounts the IAM deleted and the administrators' People page (the
  section "People");
- **the admin tools** (both halves) - the error log, the audit log, feedback,
  backups and the file explorer, each an admin page, and the visitors' feedback
  form in their language - and the pages' own errors reported to the error log
  (`@anotoki/lib/error-report`) (the section "Admin tools").

## The behaviour

The server decides a state for every request:

| state | when |
| --- | --- |
| `not_set_up` | the site's settings files are missing or invalid |
| `update_pending` | the settings are fine and at least one migration file is ready to apply |
| `unavailable` | the settings are fine but the database cannot be reached |
| `ready` | otherwise |

- `not_set_up`: every API path answers `503 {"code":"not_set_up","installed":…,"message":…}`,
  with `"setup":"/setup.php"` only while the site was never installed
  (`storage/installed.lock`, which a site's installer writes on a real host).
- `update_pending` (every site but the IAM blocks): every API path answers
  `503 {"code":"update_pending","message":"The site is being updated. Try again in a few minutes."}`
  with `Retry-After: 60` - except the site's open paths: its boot/sign-in
  configuration, its translations, its health check, the status path and the
  admin migrations routes. So people can sign in, the pages have their words,
  and an administrator reaches Apply.
- What people see:
  - visitors and signed-in people who are not the site's ADMIN: "The site is
    being updated", in their language, checking again by itself and reloading
    when the site is back; no error, no code, no button but a quiet "Sign in"
    while nobody is signed in (how an administrator gets in);
  - the site's ADMIN: "A database update is waiting" and "Open Migrations"; the
    Migrations page works, nothing else does;
  - never set up: "This site is not set up yet" and "Open the setup page";
  - set up once but not answering, or unavailable: "The site is not available
    right now", checking again by itself - never the setup page.
- The IAM keeps answering while an update waits (`blockWhilePending: false`);
  its admin panel shows its administrators one line, "A database update is
  waiting", linking to Migrations.

## Installing it in a site

PHP - in `backend/composer.json`:

```json
"repositories": [{ "type": "vcs", "url": "https://github.com/irelevant25/anotoki-lib" }],
"require": { "anotoki/lib": "^0.4.1" }
```

then `php composer.phar update anotoki/lib --prefer-dist` in `backend/`. Composer
takes the version from the git tag and installs GitHub's archive of it, which
holds the PHP half only - its code (`php/src`), the migration files of the
library's sets (`php/migrations`), its resources (`php/resources`) and, from
0.4.0, the stand-in IAM a site's tests start (`php/testing`), with
`composer.json`, this README and CHANGELOG.md: `.gitattributes` is an
allow-list, and CI builds the archive and checks it
(`.github/scripts/check-archive.sh`). Where the site uploads `vendor/`,
`vendor/anotoki/lib` goes with it - kept from being served as the site's own
`migrations/` is (a deny-all `.htaccess`), since it holds SQL too. (0.2.0
changed only the Angular half; 0.3.0 and 0.4.0 changed both: a site takes the
constraint and the tarball of one version together. 0.4.1 is 0.4.0 working on
PHP 8.2 - a site goes straight to `^0.4.1` and the 0.4.1 tarball.)

Angular - in `frontend/package.json`:

```json
"@anotoki/lib": "https://github.com/irelevant25/anotoki-lib/releases/download/v0.4.1/anotoki-lib-0.4.1.tgz"
```

then `bun install` in `frontend/` (and `npm install` too where the site keeps a
`package-lock.json`, so both lock files agree). Peer dependencies:
`@angular/core`, `@angular/common`, `@angular/router` ^21.2 and `rxjs` ^7.8. The
package brings Manrope's font files with it (its dependency
`@fontsource-variable/manrope`); see "Setting a site up" below.

**Updating** to a new version: PHP - change the constraint where the new
version needs it (0.x: a minor version may break - read CHANGELOG.md), then the
same `composer update anotoki/lib --prefer-dist`; Angular - the new release's URL
in `package.json`, then `bun install`, and the edits in "Upgrading" below.

## The PHP half

```php
use Anotoki\Lib\Migrations\Http\MigrationsRoutes;
use Anotoki\Lib\Migrations\Http\SiteGate;
use Anotoki\Lib\Migrations\Http\SiteState;
use Anotoki\Lib\Migrations\MigrationSet;
use Anotoki\Lib\Migrations\Migrator;

$migrator = fn (): Migrator => new Migrator(db(), [new MigrationSet('survey', __DIR__ . '/../migrations/survey')]);

$state = new SiteState(
    settingsProblem: fn (): ?string => settingsProblem(),          // null once config/*.local.php are complete
    installed: fn (): bool => is_file(storageDir() . '/installed.lock'),
    migrator: $migrator,                                            // only called once the settings are complete
    // blockWhilePending: false,                                    // the IAM: keep answering while an update waits
);

$app->add(new SiteGate($state, $app->getResponseFactory(), [
    '/api/auth/config',                     // exact paths...
    '~^/api/translations/[a-z-]+$~D',       // ...or a regular expression when it starts with '~' (end it with D)
    '/api/health',
    '~^/api/admin/migrations(/file|/apply)?$~D', // exactly the three routes - never a prefix
]));

MigrationsRoutes::status($app, $state);                            // GET /api/site-status
MigrationsRoutes::admin($app, '/api/admin/migrations', $migrator, function ($request, array $result): void {
    // after every Apply that was not busy: audit, clear caches
})->add($requireAdmin);                                             // the site's ADMIN check (from the token)
```

- `Migrator` - `files($set)`, `status()` and `applicable()` (read only: they never
  create anything), `apply()`, `source($set, $name)`, `ensureTable()`. Each file
  runs in a transaction of its own with its record; the run stops at the first
  file that fails (rolled back whole; later sets never run), and the result
  names it with the database's error: `statement N of M: <message>` and the
  statement's first six lines. One apply at a time: every apply takes
  `pg_try_advisory_lock` (`DEFAULT_LOCK`, or the site's `lock`) and answers
  `busy` when another holds it. The connection is used as given and needs
  `PDO::ERRMODE_EXCEPTION`.
- Options: `table` (`migrations`), `lock`, `split` (false sends a file in one
  `exec`), and hooks: `ready(set, name, sql)` (false: a draft - it and every file
  after it wait; drafts never stop the site), `prepare(pdo)` (write paths only,
  before the table is created or used: adopt an older shape there),
  `beforeFile(pdo, set, name)` (inside the file's transaction),
  `beforeApply(pdo, files)` (once; its string is the result's `note`; throwing
  ends the apply with nothing applied).
- The bookkeeping table is read and written in every shape the sites have, and
  never changed: the file in `filename` (or `name`), the set in `folder` where
  the table has one (required with more than one set of the site's own),
  `applied_at` a TIMESTAMP holding UTC or a TIMESTAMPTZ (answered as ISO 8601
  UTC, `…Z`). A missing table is created as `(id SERIAL PRIMARY KEY, filename
  VARCHAR(255) NOT NULL, applied_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, UNIQUE
  (filename))`, with `folder VARCHAR(64) NOT NULL` and `UNIQUE (folder,
  filename)` for more than one set of the site's own. A table it cannot read (no
  file column) counts as nothing applied until `prepare` adopts it.
- **Library sets** (0.3.0): `MigrationSet::library($name, $dir)` is a
  set the library ships - `Translations\Schema::migrationSet()` is one - and the
  names that begin with `anotoki_` are theirs alone (a site's set named so is
  refused). A site lists them with its own, in the order they run:
  `[new MigrationSet('iam', …), Schema::migrationSet()]`. In a table without
  `folder` a library set's file is recorded as `<set>/<file>`
  (`anotoki_translations/001_languages_and_strings.sql`), wherever the set stands
  in the list; in one with `folder` (genshin) as `folder = <set>`. The site's own
  sets are recorded as before - the first by its file names alone - so adding a
  library set changes nothing in a site's table, a new table still has the shape
  the site's own sets need, and a build without the library set still reads
  every record of the site's own (it lists the library's as missing). A record
  of a set that is not configured is listed as missing under that set's name.
  `ready`, `beforeFile` and `beforeApply` see the library's files with their set
  (`$set->library` is true); a draft holds every file after it, the library's
  too; all of it under the site's one advisory lock.
- `Splitter::split($sql)` - the statements of a file: a `;` ends one only outside
  comments, strings (`'…'`, `E'…'`), quoted names and dollar-quoted blocks.
- `MigrationsRoutes::admin` answers `GET $base` (`Migrator::status()`),
  `GET $base/file?set=&name=` (the SQL, or 404) and `POST $base/apply` (200 with
  `applied`, `failed`, `error`, `note` - a failed file is still a 200 - or 409
  `busy`). The site's admin check must not need tables a pending migration makes.

## The Angular half

One secondary entry point per family of pieces: a site's build places whole
files in its chunks, and an entry point is one file - a file a first page and a
lazy page both use goes to the first load whole, with everything any page uses
of it - so what only lazy pages use has an entry point of its own and stays out
of a site's first load. The kit's core is a file per piece (`ui/config`,
`ui/button`, ..., below), and `@anotoki/lib/ui` re-exports them all: **import
each piece from its own entry point**. A first page that imports anything from
`@anotoki/lib/ui` reaches every piece through it, and then every piece any page
uses is in the first load again.

| Entry point | Holds | Eager in a site |
| --- | --- | --- |
| `@anotoki/lib/styles` | Sass: the tokens (one palette, light and dark), Manrope, the base, utilities, tables | the site's `styles.scss` |
| `@anotoki/lib/ui` | re-exports the core's pieces below, nothing of its own | - |
| `@anotoki/lib/ui/config` | `provideAnotokiUi`, `AnotokiWords`, the kit's words (`KIT_LIBRARY_WORDS`), every library key's name (`LibraryKey`), a module's registration of its words (`provideLibraryWords`, `ANOTOKI_LIBRARY_WORDS`) | yes |
| `@anotoki/lib/ui/icon`, `/dom` | `<anotoki-icon>`, `AnotokiIcons` and the kit's own icons; `uniqueId`, `keepOnScreen`, `mediaQuery`, `tabbable`, `isShown` | yes (the bar) |
| `@anotoki/lib/ui/button`, `/spinner` | the button, the spinner | yes (the status page) |
| `@anotoki/lib/ui/badge`, `/alert`, `/card`, `/empty-state`, `/error-state`, `/page-header`, `/avatar`, `/segmented`, `/autofocus` | one piece each | where a first page uses it |
| `@anotoki/lib/ui/icons` | the family's other icons, data only (`iconTrash`, `iconUsers`, ...) - a site's alone: no entry point imports it (0.5.0: the lazy pages carry their own copies) | what the site registers |
| `@anotoki/lib/ui/menu` | menu button, menu, menu item, separator; popover | yes (the bar) |
| `@anotoki/lib/ui/dialog` | dialog, `AnotokiDialog.open()`, `ConfirmService` + `<anotoki-confirm-host>` | where the host is in the root |
| `@anotoki/lib/ui/toast` | `ToastService` + `<anotoki-toast-host>` | yes |
| `@anotoki/lib/ui/forms` | text field, textarea, password field, select, checkbox, switch, radio group, code input | lazy pages only |
| `@anotoki/lib/ui/tabs`, `/pagination`, `/drawer`, `/tooltip`, `/copy`, `/qr` | one piece each | lazy pages |
| `@anotoki/lib/shell` | theme service and switch, language switcher, brand, top bar, frame, `latestChoiceSaver`; `assets/theme-boot.js` | yes (every page) |
| `@anotoki/lib/migrations` | site status, interceptor, gate, status page, update banner | yes (the gate) |
| `@anotoki/lib/migrations/page` | the admin Migrations page | a lazy admin route |
| `@anotoki/lib/translations` | the translation service, the `translate` and `translatePlural` pipes, the kit's and the shell's wiring, the status page's words, the admin pages' guard | yes (every page) |
| `@anotoki/lib/translations/admin` | the admin Translations and Languages pages, their drafts | lazy admin routes |
| `@anotoki/lib/translations/testing` | `provideTestTranslations()`, a stand-in for a site's specs | a site's specs |
| `@anotoki/lib/auth` (0.4.0) | signing in through the IAM: its browser client, `AnotokiAuth`, the interceptor, the guards, `AccountPreferences`, the routes of the callback page and the failure's screen ("Auth" below) | yes (every page of a site that signs in) |
| `@anotoki/lib/auth/core` (0.5.0) | what the auth module's parts share: the client's types and helpers, the configuration and browser tokens, the built-in words, `SignInSession`; the library's own - `@anotoki/lib/auth` names all of it | with the module |
| `@anotoki/lib/auth/screens` (0.5.0) | the callback page and the failure's screen themselves | as their route opens |
| `@anotoki/lib/auth/session` (0.5.0) | the client's token requests: a callback's code exchange, a refresh, a preferences save; the library's own | the first time one is made |
| `@anotoki/lib/auth/testing` (0.4.0) | `provideTestAnotokiAuth()`, `FakeAuthClient`: the module without an IAM, for a site's specs | a site's specs |
| `@anotoki/lib/people` (0.4.0) | `provideAnotokiPeople()` and the shape of the People API ("People" below) | where the site configures the page (its app.config, or the page's route) |
| `@anotoki/lib/people/page` (0.4.0) | the admin People page | a lazy admin route |
| `@anotoki/lib/admin` (0.4.0) | the admin tools' configuration (`provideAnotokiAdmin`) and `FeedbackUnread`, the new feedback beside a site's admin navigation ("Admin tools" below) | where the admin navigation is |
| `@anotoki/lib/admin/errors`, `/audit`, `/feedback`, `/backups`, `/files` (0.4.0) | the admin tools' pages, one each | lazy admin routes |
| `@anotoki/lib/admin/parts` (0.4.0) | what those pages share - their requests, failures, dates, question dialog and styles; the library's own, a site imports nothing of it | with the first of them |
| `@anotoki/lib/feedback` (0.4.0) | the visitors' feedback form's configuration (`provideAnotokiFeedback`, which registers its words) and its words | yes, beside the translations module |
| `@anotoki/lib/feedback/form` (0.4.0) | `<anotoki-feedback-form>`, on the kit's fields | loaded when the person opens it |
| `@anotoki/lib/error-report` (0.5.0) | `provideAnotokiErrorReport()`: the browser's errors in the site's error log ("Admin tools" below) | yes (every page) |
| `@anotoki/lib/words/<namespace>` (0.4.0, generated) | one module's library words: `/site-status` (the status page's), `/sign-in` (the sign-in page's), `/feedback` (the feedback form's) | with its module, which alone imports it and registers it with the translations module |
| `@anotoki/lib/words` (0.4.0, generated) | `LIBRARY_WORDS`, every module's words (the kit's and each namespace's) | never - a site's specs, the showcase |

Every component is standalone, OnPush and zoneless-safe; colours only through
`--anotoki-*` custom properties, each read with the family's own value as its
fallback (on `light-dark()`), so a page that sets no tokens still gets the
family look; each component sets every property it relies on, so a site's
element rules (`button { ... }`) do not show through. Every class the kit puts
in a page is its own - BEM on the component's host class, so a site's global
`.icon`, `.panel` or `.is-active` never reaches in: a part `anotoki-<component>__<part>`
(`anotoki-alert__icon`), a state of the component `anotoki-<component>--<state>`
(`anotoki-button--primary`), a state of a part `anotoki-<component>__<part>--<state>`
(`anotoki-top-bar__link--active`); the fields' shared parts are `anotoki-field`
and `anotoki-field__*`, and every component hides a word from sight with
`anotoki-visually-hidden`. A site's spec that reads the kit's markup reads these
names. Words the kit says come built in for English and Slovak; names (a site's
sections, brand area, "Back to ...") are never kit words - the site passes them
worded.

### Setting a site up

```json
// angular.json - build options: the kit's boot script next to index.html
"assets": [{ "glob": "**/*", "input": "public" }, { "glob": "theme-boot.js", "input": "node_modules/@anotoki/lib/assets", "output": "/" }],
"styles": ["src/styles.scss"]
```

```html
<!-- index.html, in <head>: the stored theme before the first paint (a file: no inline script for the CSP) -->
<script src="theme-boot.js" data-storage-key="anotoki-survey:theme"></script>
```

```scss
// src/styles.scss
@use "@anotoki/lib/styles" as anotoki;

@include anotoki.root;        // the family's palette; or root((light: (...), dark: (...), font-sans: ...)) - below
@include anotoki.fonts;       // Manrope's @font-face rules (its files come with the package)
@include anotoki.base;        // the page's font, colours, type, links, code, the focus ring, reduced motion
@include anotoki.utilities;   // .stack, .cluster, .form-grid, .muted, .facts, .skeleton ... ("anotoki-" prefix if they collide)
@include anotoki.tables;      // .table, .table-scroll, .table-stack
```

```ts
// app.config.ts - with the library's translations module (`TranslationService` from @anotoki/lib/translations;
// "Translations" below): the kit's language and its words from the site's database, the switcher's languages
provideAnotokiUi(() => ({
  ...inject(TranslationService).forKit(),        // language: 'en' in an admin panel; lookup: the database's anotoki.<key>
  words: { sk: { 'topbar.account': 'Váš anotoki účet' } },   // optional: the site's own words, per language
  icons: { trash: iconTrash, users: iconUsers },  // the icons the site's own templates name
})),
provideAnotokiShell(() => {
  const auth = inject(AuthService);
  return {
    theme: { storageKey: 'anotoki-survey:theme', account: () => auth.preferences()?.theme ?? null, save: (theme) => auth.savePreferences({ theme }) },
    languages: inject(TranslationService).forShell(),   // or a site's own: { current, offered, choose, notSaved, clearNotSaved }
  };
}),
provideAppInitializer(() => inject(ThemeService)),   // the account's theme from the first frame
```

```html
<!-- the app's root template, once each -->
<anotoki-confirm-host />
<anotoki-toast-host />
```

Remove the site's own copies as it moves (its `ui/` pieces, its theme service,
its `theme-boot.js`, its `styles.scss` tokens): two theme services must never
run in one site. A site that loaded Manrope itself (`node_modules/@fontsource-variable/manrope/index.css`
in `angular.json`) drops that line for `anotoki.fonts`.

### `@anotoki/lib/styles`

- `root($options)` - the tokens on `:root`: light; dark under
  `prefers-color-scheme: dark` (unless `data-theme="light"`) and under
  `data-theme="dark"` (what `ThemeService` and `theme-boot.js` write); each
  palette with its `color-scheme`. Options: `light` and `dark` (maps of colours)
  and any shared token (`font-sans`, `font-mono`, `radius`, `control-height`,
  ...). A misspelt token name is a compile error.
- `palette(light | dark, $overrides)` and `shared($overrides)` - for a site that
  switches themes on a selector of its own.
- `token(name)` - `var(--anotoki-<name>, <the family's value>)`, for a site's own styles.
- `fonts` - Manrope, the family's font (`font-sans` names it first). Its files
  come from the package's dependency `@fontsource-variable/manrope`; the site's
  Angular build copies the subsets into its output (latin, latin-ext for Slovak,
  cyrillic, greek, vietnamese - each with its `unicode-range`, so a browser
  downloads only what a page uses).
- `base`, `utilities($prefix: "")`, `tables($prefix: "")` - the IAM's global
  styles; a site whose own `.page`, `.lead`, `.card` would collide passes
  `"anotoki-"`.

The tokens (each `--anotoki-<name>`, light and dark): `bg`, `surface`,
`surface-2`, `surface-3`, `surface-raised`, `border`, `border-strong`, `text`,
`muted`, `subtle`, `primary`, `primary-hover`, `primary-contrast`,
`primary-soft`, `primary-soft-text`, `accent`, `link`, `danger`, `danger-hover`,
`danger-contrast`, `danger-soft`, `danger-border`, `danger-text` (0.4.0),
`success`, `success-text`,
`success-soft`, `success-border`, `warning`, `warning-text`, `warning-soft`,
`warning-border`, `info`, `info-soft`, `info-border`, `ring`, `focus`,
`backdrop`, `skeleton`, `skeleton-shine`, `switch-thumb` (the switch's knob:
white in both themes), `shadow-sm`, `shadow`, `shadow-lg`, `mark-shadow`,
`brand-area` (the bar's area word; `primary-soft-text` unless set); the same in
both themes: `font-sans`, `font-mono`, `radius-sm`, `radius`,
`radius-lg`, `radius-xl`, `control-height`, `control-height-sm`,
`topbar-height`, `duration`, `ease`. The palette is the IAM's; text on every
ground the kit draws reaches 4.5:1 in both themes (badges: `success-text`
5.1:1, `warning-text` 5.3:1 on their soft grounds), and `border-strong` - the
edge of every control: fields, selects, code boxes, radio cards, segmented
controls, the bar's round buttons - 3:1 on every ground (WCAG 1.4.11; light
`#7a8ca5`, dark `#5e72a3`). The decorative `border` (cards, separators) stays light.
`danger-text` is the ink of words that say something went wrong - a field's
error, a refusal, a danger badge, the People page's Delete - at 4.5:1 at least on
every ground and on `danger-soft` (light `#b42833`, 5.4:1 at the least; dark
`#ff6b75`, 5.2:1), apart from `danger`, the fill of a button under white words: a
site whose own red fill is darker (Japanese Academy's dark `#ae4a3f`, 3:1 on its
surfaces) keeps its error words readable, or names its words red for them
(`dark: (danger: #ae4a3f, danger-text: <its words red>)`).

A site with a palette of its own (Japanese, Piano) gives its colours, light and
dark, and keeps the family's for the rest:

```scss
@include anotoki.root((
  light: (bg: #f6f5f2, surface: #ffffff, text: #1d1d1f, primary: #3a5f8a, primary-hover: #2f4f74, link: #3a5f8a),
  dark: (bg: #16161a, surface: #1e1e23, text: #eceaf0, primary: #6b9fd8, primary-contrast: #0e1420, link: #6b9fd8),
  font-sans: ("Manrope Variable", system-ui, sans-serif),
));
```

A site may also define its old names as aliases while its own components move
(`--ink: var(--anotoki-text)`).

### `@anotoki/lib/ui` and the core's pieces

Each piece below is imported from its own entry point, named beside it
(`import { ButtonComponent } from '@anotoki/lib/ui/button'`); `@anotoki/lib/ui`
re-exports every one, for a lazy page or a spec.

`provideAnotokiUi(factory)` (`ui/config`; optional; the factory runs in an injection
context): `language` (the page's, a signal is followed; default `'en'`),
`words` (per language, the site's words over the built-in ones), `lookup` (a
site's string for a kit word, or null / `''` for the kit's - checked first:
every site's database holds the kit's words as `anotoki.<key>`, and the
translations module's `forKit()` reads them there), `icons`.

**Words** - `AnotokiWords.t(key, params?)` (`ui/config`), reactive. Resolution per key:
`lookup(key)`, then `words[language]` (or its primary subtag: `sk-SK` -> `sk`),
then the built-in words of the language, then the built-in English - never a
key. `lang` is the language the words are in (`'en'` on a page nobody wrote
words for) and `foreignLang` is set where that is not the page's: the kit puts
it in a `lang` attribute. The built-in Slovak is informal ("ty"); a formal site
overrides it (the survey: `words: { sk: { 'topbar.menuButton': 'Vaša ponuka: {name}', ... } }` -
with the translations module, in its database: "A site moves in" below).
The keys: `ui.close`, `ui.dismiss`, `ui.notifications`, `ui.cancel`,
`ui.confirm`, `ui.retry`, `ui.optional`, `ui.loading`, `ui.showPassword`,
`ui.hidePassword`, `ui.copy`, `ui.copied`, `ui.copyToClipboard`,
`ui.copiedToClipboard`, `ui.copyFailed`, `ui.qrCode`, `ui.or`, `ui.pages`,
`ui.previous`, `ui.next`, `ui.range`, `ui.pageOf`, `ui.nothingToShow`,
`topbar.skipToContent`, `topbar.sections`, `topbar.phoneMenu`,
`topbar.menuButton`, `topbar.menu`, `topbar.roleAdmin`, `topbar.roleEditor`,
`topbar.account`, `topbar.admin`, `topbar.signIn`, `topbar.signOut`,
`topbar.saving`, `language.label`, `language.button`, `language.notLoaded`,
`language.notSaved`, `theme.label`, `theme.light`, `theme.dark`, `theme.auto`,
`theme.autoHint`, `theme.notSaved` - the library's words under the namespaces
`anotoki.ui|topbar|language|theme.*`, English and Slovak generated from
`php/resources/library-words.json` ("The library's own words" below;
`KIT_LIBRARY_WORDS` has them by their library keys, `@anotoki/lib/words`'
`LIBRARY_WORDS` every module's). A site words its own menu items with them too:
`words.t('topbar.account')` is "Tvoj anotoki účet" in Slovak.

**Icons** - `<anotoki-icon name="..." [size] [strokeWidth] [filled] [label]>`
(`ui/icon`, with `AnotokiIcons`, `KIT_ICONS`, `circle()`, `rect()`), decorative
unless labelled. The kit's own: `sun`, `moon`, `monitor`, `user`,
`shield`, `logIn`, `logOut`, `chevronDown`, `chevronLeft`, `chevronRight`,
`menu`, `x`, `check`, `alert`, `info`, `checkCircle`, `xCircle`, `eye`,
`eyeOff`, `copy`, `refresh`, `arrowLeft`, `externalLink`, `sparkle`, `search`,
`plus`; the rest from `@anotoki/lib/ui/icons`, registered by name (`icons`
above, or an `ANOTOKI_ICONS` multi provider on a lazy route). An unknown name
draws nothing and warns once in development. The library's lazy pages (the
admin tools', the People page, the translations admin) carry their own copies
of the family's icons they draw (0.5.0) - one file is one chunk, and a site
that loads `ui/icons` up front for its own would otherwise carry theirs in its
first load.

**Pieces** (selectors `anotoki-*`; slot attributes as the IAM's):

- `button[anotokiButton], a[anotokiButton]` (`ui/button`) - `variant` (`primary`, `secondary`,
  `ghost`, `danger`, `link`), `size` (`sm`, `md`, `lg`), `loading`, `disabled`
  (on a link: aria-disabled and out of the tab order), `block`, `iconOnly` (give
  it an aria-label), `type` (`button` by default). 44 px high (34 for `sm`); a
  `link` button reads as a link with a 44 px target (24 px for `sm`).
- `anotoki-spinner` (`ui/spinner`) - `size`, `label` (a bare `label` says the kit's "Loading…"),
  `showLabel`, `inline`, `delay` (ms before it shows).
- `anotoki-badge` (`ui/badge`) - `tone`, `dot`, `mono`. `anotoki-alert` (`ui/alert`) - `tone`, `heading`,
  `announce` (`true`: role="alert", `'polite'`: role="status"); `[alertActions]`.
- `anotoki-card` (`ui/card`) - `heading`, `subheading`, `headingId`, `headingLevel` (2, 3),
  `flush`, `tone`; `[cardActions]`.
- `anotoki-empty-state` (`ui/empty-state`) - `icon`, `heading`, `text`; content: actions.
  `anotoki-error-state` (`ui/error-state`) - `heading`, `message`, `retryLabel`; `(retry)`.
- `anotoki-page-header` (`ui/page-header`) - `heading`, `lead`, `headingId` (`page-title`);
  `[pageEyebrow]`, `[pageMeta]`, content: actions.
- `anotoki-avatar` (`ui/avatar`) - `name`, `imageUrl`, `size`, `shape` (`rounded`, `circle`).
- `anotoki-segmented` (`ui/segmented`) - `label`, `options` (`{ value, label, icon?, hint?, name?, lang? }`),
  `value` + `(valueChange)` (or `[(value)]`), `iconsOnly`, `size`, `busy`: a radio
  group, one Tab stop, arrows move the choice and the focus once it is made.
- `[anotokiAutofocus]` (`ui/autofocus`); `uniqueId()`, `keepOnScreen(element)`,
  `mediaQuery(query)`, `tabbable(root)`, `isShown(element)` (`ui/dom`).

**`ui/menu`** - `[anotokiMenuTrigger]="menu"`, `<anotoki-menu #menu label align closeOnSelect>`,
`button[anotokiMenuItem], a[anotokiMenuItem]` (`radio`, `disabled`, `stay`),
`<anotoki-menu-separator>`. The family's rules: drawn only while open, in the
top layer under its button and kept on screen; focus into it (the checked or
the first item); arrows (skipping disabled items), Home, End; Escape back to
the button; **Tab and Shift+Tab close it with the focus on its button**; a
click outside closes it where the focus is; choosing an item closes it.
`[anotokiPopoverTrigger]` + `<anotoki-popover label autoFocus>` - the same for a
box of controls (a labelled group; Escape, the focus leaving and a click outside close it).

**`ui/dialog`** - `<anotoki-dialog heading description size dismissible busy (dismiss)>`
in a page's `@if` (`[dialogFooter]`); `AnotokiDialog.open(Component, { data, heading, size, dismissible })`
-> `AnotokiDialogRef` (`close(result)`, `closed`, `busy`, `heading`); the
component injects `AnotokiDialogRef` and `ANOTOKI_DIALOG_DATA` and puts its
buttons in `<ng-template anotokiDialogFooter>`. `ConfirmService.ask({ title, message, confirmLabel, cancelLabel, tone })`
-> `Promise<boolean>`, drawn by `<anotoki-confirm-host>`, the focus on Cancel.

**`ui/toast`** - `ToastService.show(message, tone, duration)`, `success`,
`info`, `warning` (8 s), `error` (9 s), `dismiss(id)`; at most four;
`<anotoki-toast-host>` is a polite region, always in the page.

**`ui/forms`** - every field: `label`, `[(value)]`, `name`, `hint`, `error`
(role="alert", tied by aria-describedby, aria-invalid), `invalid` (0.4.0:
aria-invalid and the danger edge without a message of its own - for fields
whose words are said once elsewhere, under a range's two dates, in a form's
summary; the text field, textarea, password field, select and code input),
`required`, `optional`, `disabled`, `hideLabel`, `autofocus`, `focus()`.
`anotoki-text-field` (`type`, `autocomplete`, `inputmode`, `placeholder`,
`readonly`, `maxlength`, `minlength`, `min`, `max`, `pattern`, `spellcheck`,
`autocapitalize`, `(blurred)`, `[fieldPrefix]` / `[fieldSuffix]`),
`anotoki-textarea` (`rows`, `readonly`, ...), `anotoki-password-field`
(`autocomplete` required, `[labelAside]`; its Show the only one - Edge's own
reveal and clear buttons hidden, 0.5.0), `anotoki-select` (`options`,
`placeholder`, `inline`), `anotoki-checkbox` / `anotoki-switch`
(`[(checked)]`, `hint`, the switch's `busy`), `anotoki-radio-group`,
`anotoki-code-input` (`length`, `(complete)`).

**Smaller entry points** - `ui/tabs` `<anotoki-tabs #tabs="anotokiTabs" label [tabs] [(active)]>`
(the page draws the panels with `tabs.panelId(id)` / `tabs.tabId(id)`);
`ui/pagination` `<anotoki-pagination [page] [pageSize] [total] [busy] (pageChange)>`;
`ui/drawer` `<anotoki-drawer heading subheading backLabel (dismiss) (back)>`
(`[drawerActions]`, `[drawerFooter]`); `ui/tooltip` `[anotokiTooltip]="text"`
(`anotokiTooltipDelay`; WCAG 1.4.13); `ui/copy` `<anotoki-copy-button text label variant size iconOnly>`
and `copyText()`; `ui/qr` `<anotoki-qr-code value label>` and `encodeQr()`.

### `@anotoki/lib/shell`

`provideAnotokiShell(factory)`: `theme` (`storageKey` - the same as the boot
script's `data-storage-key`; `account` - the account's theme, null while there
is none; `save` - rejects when the account refuses, and a rejection whose `code`
is `login_required` - nobody signed in any more - is no refusal; `themeColor`) and
`languages` (`current`, `offered`, `choose` -> true once the page reads in it,
`notSaved`, `clearNotSaved`).

- `ThemeService` - `mode`, `resolved`, `refused`, `set(mode)`,
  `dismissRefusal()`. Writes `<html data-theme>`, its color-scheme and
  `<meta name="theme-color">`; follows the device while `auto`; keeps the mode
  in localStorage (nothing for auto); shows the account's theme from its first
  moment and whenever it changes - not while its own save is out; one save at a
  time, the latest last; a refusal puts the account's theme back and sets
  `refused` - only while there is an account: a save that failed because the
  account went meanwhile (signed out here or in another tab) leaves the choice
  shown and says nothing (0.4.0).
- `latestChoiceSaver(save, onRefused)` - that rule for a site's own saves (the
  language): `const saveLanguage = latestChoiceSaver((code) => api.save(code), () => notSaved.set(true))`.
- `<anotoki-theme-toggle [iconsOnly]>` - light / dark / as the device is set
  (a segmented radio group); a note under it when the account refuses.
- `<anotoki-language-switcher appearance="menu | segmented | select | auto" (changed)>` -
  draws nothing with fewer than two languages; the menu's button holds the
  code it shows ("Language: Slovenčina (SK)"); the last choice wins; notes for
  a language that could not be loaded and one the account refused; the keys
  pressed in it stay in it.
- `<anotoki-brand area panel showName link label current lang size logo>` -
  "anotoki survey · admin panel"; without the name, the area alone and the
  panel small under it. `logo` is the site's own file (`anotoki.png`).
- `<anotoki-shell [nav] navLabel skipLabel maxWidth>` - the frame: a skip link (moving
  the focus by hand), the site's bar in `[topBar]` (the family's or a site's
  own header - genshin keeps its own), a side navigation (15rem; a strip under
  the bar up to 860 px), `<main id="main">`, `[shellFooter]`. 84rem wide, 88rem
  with a side navigation, or what `maxWidth` says (a CSS length: the IAM's
  account pages `maxWidth="88rem"`); the family's bar inside lines up with it.
- `<anotoki-top-bar #bar="anotokiTopBar">` - the family's one bar:

| Input | |
| --- | --- |
| `brand` | `{ area, short?, panel?, link, label, current?, lang? }` - `panel: 'admin panel'` in an admin panel |
| `links` | `{ id, label, path, under?, lang?, badge? }[]` - the page's link marked `aria-current`, its section `anotoki-top-bar__link--active` (in the phone menu `anotoki-menu-item--active`) |
| `navLabel` | the links' name (the kit's "Main navigation") |
| `person` | `{ name, email?, role? }`, or null - the site words the role |
| `menuItems` | `{ id, label, icon?, path? or href?, newTab?, lang? }[]` - between the menu's head and Sign out |
| `signIn`, `signOut` | functions, or null; one that returns a promise keeps its item waiting ("Saving…", the focus on the menu's button) |
| `languages` | the language switcher (where the site's pages are translated) |
| `phoneMax` | the site's measured fold, px (760 by default) |
| `nameMin` | the person's name shows from here (phoneMax + 201) |
| `shortBrandMax` | the brand short up to here (phoneMax by default) - the survey's admin panel: 960. Given, it alone decides (0.4.0): below phoneMax too, a folded bar keeps the full wordmark above it - a bar that folds wide (Piano Academy's 1320 px) measures where "anotoki Piano Academy" still fits beside the language, the theme and the menu button |
| `width` | `wide` (84rem) or `narrow` (49rem); `--anotoki-topbar-max-width` overrides; in a frame with a side navigation, 88rem, and in one with a `maxWidth`, that |
| `phoneMark` | `{ badge?, label? }` (the button's name says the count) or `{ dot, description? }`. Without one (0.5.0), a link inside with a badge puts a dot there, the button's name saying which: "Menu: Feedback 3" |
| `menuNote` | `{ title, text? }` heading both menus |
| `isolateKeys` | no key pressed in the bar reaches `document` (true) |

  `(languageChanged)` tells a choice made in the bar; content `[topBarWidgets]`
  stands before the language (a widget reads `bar.phone()`); `closeMenu(returnFocus)`.
  A menu closes when the bar folds or unfolds, when the person changes and when
  the links do; in development the bar warns once when its row overflows above
  `phoneMax`, or folded with the full wordmark above `shortBrandMax` (measure again).

A site keeps a thin wrapper where its roles, status and words meet the bar - the
survey's, for example:

```html
<!-- layouts/top-bar/top-bar.component.html -->
<anotoki-top-bar [brand]="brand()" [links]="links()" [person]="person()" [menuItems]="items()"
    [signIn]="signIn" [signOut]="signOut" [languages]="chooser()" [phoneMax]="760" [shortBrandMax]="area() === 'admin' ? 960 : null" [width]="width()" />
```

```ts
protected readonly brand = computed(() => this.area() === 'admin'
  ? { area: this.i18n.t('brand.area'), panel: this.i18n.t('brand.adminPanel'), link: '/admin', label: this.i18n.t('topbar.homeAdmin') }
  : { area: this.i18n.t('brand.area'), link: '/', label: this.i18n.t('topbar.home') });
protected readonly person = computed(() => { const me = this.auth.person(); return me && { name: me.name, email: me.email,
  role: this.auth.isAdmin() ? this.words.t('topbar.roleAdmin') : this.auth.isStaff() ? this.words.t('topbar.roleEditor') : null }; });
protected readonly signOut = () => this.signOutAfterSaves();   // a promise: the item waits
```

### `@anotoki/lib/migrations` and `@anotoki/lib/migrations/page`

```ts
// app.config.ts
provideHttpClient(withInterceptors([siteStatusInterceptor /* , the site's own */])),
provideAnotokiMigrations(() => {
  const auth = inject(AuthService);                 // the factory runs in an injection context
  const i18n = inject(TranslationService);
  return {
    statusUrl: '/api/site-status',
    apiBase: '/api/admin/migrations',
    migrationsRoute: '/admin/migrations',           // the site's route showing <anotoki-migrations-page>
    isAdmin: () => auth.roles().includes('ADMIN'),  // ADMIN of this site, from the token (signals work)
    isSignedIn: () => auth.signedIn(),
    signIn: () => auth.signIn(),                    // returning to the current page
    language: () => i18n.language(),                // 'en' and 'sk' built in; others read English
    formatDate: (iso) => i18n.formatDate(iso),      // optional: default en-GB, '6 Oct 2026, 10:01'
    onUpToDate: () => i18n.reload(),                // optional: after an Apply leaves nothing to apply
    // setupUrl: '/setup.php', retrySeconds: 30, words: { sk: { updatingTitle: '…' } }
  };
}),
provideAppInitializer(() => inject(SiteStatus).check()),   // early in the boot; the boot must not stop on update_pending
```

```html
<!-- the app's template: the gate around the router outlet, and around nothing else - the pages as a marked template
     (SitePagesDirective in the component's imports), made only while the site is open -->
<anotoki-site-gate><ng-template anotokiSitePages><router-outlet /></ng-template></anotoki-site-gate>
```

```ts
// the admin routes: the page's own entry point, never in the first load
{ path: 'migrations', loadComponent: () => import('@anotoki/lib/migrations/page').then((m) => m.MigrationsPageComponent) }
```

- `SiteStatus` - `state` (`'unknown' | 'ready' | 'update-pending' | 'not-set-up' |
  'unavailable'`), `installed`, `blocked`, `check()`, `checkForReload()` (the
  status page's: a `ready` answer is returned, not reported - the page reloads
  without the gate making the pages first), `report(code, installed?)`, `markReady()`.
- `siteStatusInterceptor` - a 503 whose body's `code` is `update_pending` or
  `not_set_up` tells `SiteStatus` (the request still fails).
- `<anotoki-site-gate>` - its pages while the site is ready (or not asked yet),
  or for the ADMIN on the Migrations route while an update waits;
  `<anotoki-site-status>` otherwise. The pages as `<ng-template anotokiSitePages>`
  are made only while they show; content given as it is (no template) shows as
  in 0.1.0 but is made anyway, so a page behind the status page would run. An
  unmarked `<ng-template>` is not the pages (a control-flow block is a
  template too): nothing is drawn, and development says so.
- `<anotoki-site-status>` - the page of "The behaviour" above, with the kit's
  buttons (the quiet Sign in: a link button, 44 px high).
- `<anotoki-update-banner>` - the IAM's one line for its administrators.
- `<anotoki-migrations-page>` (`@anotoki/lib/migrations/page`) - the admin page
  (English; for the site's ADMIN only), drawn with the kit: Pending (in the
  order they run, with drafts), Applied (with each file's SQL in a dialog),
  Missing, and "Apply pending" behind a question whose focus starts on Cancel.
  Every file is named as its set's records name it, the same in every card and
  in what an Apply did: the site's one set's by the file's name alone, every
  other with its set (`anotoki_translations/003_sign_in_words.sql`; genshin's
  two sets, `users/041_news.sql`). A name breaks after its slash or between its
  words (`<wbr>`, which adds nothing to its text), never inside a word.

A site that does not block while an update waits (the IAM, `blockWhilePending:
false`) puts **no** gate around its outlet and does not ask at boot: the status
path does say `update_pending` there, and the gate would close the site to
everybody. It uses the interceptor, `<anotoki-update-banner>` in its admin panel
(which asks the server itself once an ADMIN is there), the Migrations page, and
`<anotoki-site-status>` where it shows its "not set up" page.

Theming: the module reads the family's tokens (`@anotoki/lib/styles`), each with
the family's value as its fallback; a site's 0.1 mapping of the 13
`--anotoki-*` properties keeps working (the "pending" ink reads
`--anotoki-warning-text`, then `--anotoki-warning`).

## Upgrading from 0.1.x

0.2.0 breaks two things a site does with the migrations module, and the markup
of the Migrations page; everything else is new.

1. **The gate's template is marked.** In the app's template
   `<anotoki-site-gate><ng-template>` becomes
   `<anotoki-site-gate><ng-template anotokiSitePages>`, and `SitePagesDirective`
   joins `SiteGateComponent` in that component's `imports`
   (`import { SiteGateComponent, SitePagesDirective } from '@anotoki/lib/migrations'`).
   Without the marker an `<ng-template>` is not the pages: the site draws
   nothing (development warns). Content given as it is, without a template,
   works as in 0.1.0.
2. **The Migrations page has its own entry point.**
   `import('@anotoki/lib/migrations').then((m) => m.MigrationsPageComponent)` becomes
   `import('@anotoki/lib/migrations/page').then((m) => m.MigrationsPageComponent)`;
   a static import (genshin's admin routes, Japanese's and the build analyzer's
   wrappers) changes `from '@anotoki/lib/migrations'` to
   `from '@anotoki/lib/migrations/page'`. `@anotoki/lib/migrations` no longer
   exports it (a re-export would pull it back into the first load).
3. **The Migrations page is drawn with the kit** - a site's specs that read its
   DOM: the question is an `<anotoki-dialog data-confirm>` made when asked
   (`dialog[data-confirm]` -> `anotoki-dialog[data-confirm] dialog`, or
   `[data-confirm] h2`; its sentence is `[data-confirm] .description`), the SQL
   dialog `anotoki-dialog[data-sql]`; an Apply's outcome is an
   `<anotoki-alert data-result>` whose heading is `.heading`
   (`[data-result] h2` -> `[data-result] .heading`); the cards are
   `anotoki-card[data-pending|data-applied|data-missing]`, the badges
   `anotoki-badge[data-kind]`. `[data-apply]`, `[data-confirm-apply]`,
   `[data-confirm-cancel]`, `[data-copy]`, `[data-close]`, `.card-text`,
   `.result-text` and `.result-note` stay.
4. The status page's buttons are the kit's (`anotoki-button`; Sign in
   `is-link`, no more `.button.quiet`). Its words and behaviour are the same,
   except that it reloads without first marking the site ready.

## Upgrading from 0.2.x (0.3.0)

1. **The kit's core, a piece at a time.** Nothing has to change to keep
   building: `@anotoki/lib/ui` re-exports every piece. For the smaller first
   load a site imports each piece from its own entry point - in every file its
   first page loads, best everywhere: `provideAnotokiUi`, `AnotokiWords`,
   `ANOTOKI_UI_CONFIG`, `ANOTOKI_ICONS`, `LIBRARY_WORDS` (0.4.0:
   `@anotoki/lib/words`'), `BUILT_IN_WORDS`, `fillWords` and the words' types
   from `@anotoki/lib/ui/config`;
   `IconComponent`, `AnotokiIcons`, `KIT_ICONS`, `circle`, `rect` from
   `@anotoki/lib/ui/icon`; `uniqueId`, `keepOnScreen`, `mediaQuery`,
   `tabbable`, `isShown` from `@anotoki/lib/ui/dom`; each component from its own
   (`ButtonComponent` from `@anotoki/lib/ui/button`, `AlertComponent` from
   `@anotoki/lib/ui/alert`, ...: the table in "The Angular half"). A site that
   imports nothing of the kit itself - genshin, the build analyzer: the gate,
   and the Migrations page on a lazy route - has nothing to change.
2. **The kit's class names** are its own ("The Angular half" above has the
   rule). A spec that reads the kit's markup by class changes its selectors:
   `.x` inside `<anotoki-y>` becomes `.anotoki-y__x`, `is-x` on it
   `anotoki-y--x`, `is-x` on its part `.p` `anotoki-y__p--x`; a field's parts are
   `.anotoki-field__label`, `.anotoki-field__error`, ...; `visually-hidden` is
   `anotoki-visually-hidden`. What 0.2.0 named here: `[data-confirm] .description`
   -> `[data-confirm] .anotoki-dialog__description`, `[data-result] .heading` ->
   `[data-result] .anotoki-alert__heading`, `.card-text`, `.result-text`,
   `.result-note` -> `.anotoki-migrations-page__card-text`, `__result-text`,
   `__result-note`, Sign in's `is-link` -> `anotoki-button--link`, the bar's
   `is-active` -> `anotoki-top-bar__link--active` (a phone menu's item:
   `anotoki-menu-item--active`). The IAM's top bar spec reads `.switcher`,
   `.row`, `.nav`, `.actions`, `.menu-head`, `.wordmark` and `is-active`
   (`.anotoki-language-switcher__switcher`, `.anotoki-top-bar__row`, ...,
   `.anotoki-brand__wordmark`); Japanese Academy's `[data-result] .heading` and
   `anotoki-brand .wordmark`.
3. **The IAM's look**: the switch's thumb is white in dark, a field's label
   1.55 high and an avatar's initials 0.45 of it again, and the small shadows and
   the mark's light outline in dark are back (0.2.0 wrote them as `(…,)`, which
   no property takes) - by themselves; the IAM's account pages give their frame
   its 88rem: `<anotoki-shell maxWidth="88rem">`.

## Upgrading from 0.3.x (0.4.0, 0.4.1)

Take 0.4.1, never 0.4.0: 0.4.0's search on the admin Feedback page fails on
PHP 8.2 (CHANGELOG.md). Everything below is the same for both.

1. **The library's words, a namespace at a time.** `LIBRARY_WORDS` (every
   library word) is `@anotoki/lib/words`' now, no longer `@anotoki/lib/ui/config`'s
   or `@anotoki/lib/ui`'s: a site that imports it changes the import (none is
   known to). `LibraryKey` stays in `@anotoki/lib/ui/config`, every key's name as
   before (the build analyzer's `WordKey` reads it); the kit's own share is
   `KIT_LIBRARY_WORDS` there. The kit's first loads no longer carry the status
   page's words, nor any other module's ("The library's own words").
2. **A module's words come with the module.** The translations module holds the
   kit's built-in words alone; a module's (the status page's, the sign-in
   page's) arrive with its `provide*()` function, which registers them
   (`provideLibraryWords`). A site's app needs nothing; a site's spec that reads
   a module's key with no string in its bundle provides that module, or its
   words: `provideLibraryWords(SITE_STATUS_LIBRARY_WORDS)` (from
   `@anotoki/lib/ui/config` and `@anotoki/lib/words/site-status`).
3. A site on the translations module applies `anotoki_translations/003` (the
   sign-in page's words) and `004` (the feedback form's) as any migration; the
   modules 0.4.0 brings are taken when the site adopts them ("Auth", "People",
   "Admin tools").
4. **The kit's fixes the sites found** - nothing to change to keep building;
   what a site may do, or what its specs read:
   - `danger-text`, the ink of error words, apart from the fill `danger`: a site
     with a red fill of its own that is too dark for words (Japanese Academy's
     dark) gets the family's `danger-text`, or gives its words red
     (`root((dark: (danger-text: …)))`). A field's error, the translations pages'
     refusals and a danger badge read it now.
   - `shortBrandMax`, given, alone says where the brand is short - below
     `phoneMax` too: a bar that folds wide (Piano Academy, the IAM) measures where
     its full wordmark still fits and passes it, and keeps "anotoki <area>" above.
     No site passes one below its `phoneMax` today, so nothing moves by itself.
   - A field takes `invalid` (aria-invalid and the edge, no message).
   - The Migrations page names a file as its set's records do, in Pending as in
     Applied: Pending is one list in the order the files run, with no heading for
     a set (`[data-pending] h3` is gone), and a file of any set but the site's one
     reads `<set>/<file>` there too (`anotoki_translations/003_sign_in_words.sql`).
     A spec that reads a name by its `textContent` reads it whole.
   - The translations admin pages: `admin.allows.importStrings`, `addLanguages`
     and `deleteLanguages`, each as its page's permission unless the site says
     otherwise (the survey: `deleteLanguages: () => auth.isAdmin()`); the
     Translations page's note about what is typed no longer mentions signing out;
     no `beforeunload` question once nobody is signed in. The Languages page
     takes the width the site's panel gives it (it stopped at 60rem), and its
     code's hint reads "Two small letters, a region after a dash if needed: cs,
     de, pt-br".
   - The PHP half's `invalid_placeholder` for a key with no placeholders reads
     "… has a { or }, and its English string has no placeholders: a brace belongs
     only around a placeholder, so take it out." (a test reading the message).
   - The PHP half's refusals end with a full stop, as the family's others do
     (`StringCheck`'s, `Translations`' and `RequiredPlaceholders`'): a test that
     reads a whole message adds it. An import or a save whose keys are numbers - a
     JSON list, `{"0": "…"}` - is refused `unknown_key` naming them, as any key the
     site does not have; it was `invalid_value`.

## Upgrading from 0.4.x (0.5.0)

0.5.0 changes what a site's hooks are given and may do, so it is a minor
version: `^0.4.1` never takes it by itself. Each site moves on purpose, its
PHP and its Angular half together (`anotoki/lib ^0.5.0`, the 0.5.0 tarball).

1. **The People delete hook** (every site with the People page) runs inside
   the library's transaction (`People::delete()`), and does ALL its work on
   the `$pdo` it is given - never on the site's own connection: Japanese
   Academy's `people_delete` (`db_tx`/`db_exec` on `db()`), Piano Academy's
   `peopleDelete` (`dbTx`/`dbExec`/`db()`) and the build analyzer's
   `SitePeople::deleteData` (its `Db`, `Users`, `Feedback`) work on their own
   today. On the People page that is the same connection; in a restore it is
   not: the site's own waits on the restore's locks while the restore waits on
   the hook, and every request to those tables waits behind both - PostgreSQL
   ends such a restore after a minute (rolled back, `500 restore_failed`: "the
   database ended its connection"). The hook begins, commits or rolls back no
   transaction of its own - a savepoint is fine. genshin's and the build
   analyzer's begin one today: they would be refused with a `LogicException`
   saying so. It takes `?ServerRequestInterface $request`: null when a restore
   does the delete again - and then it does the database's part alone, and
   skips what only a person asking does: Japanese Academy's JSON copy of what
   is deleted is not made for a replay. The library forgets the person's
   feedback with it: a hook that calls `Feedback::forgetPeople()` itself drops
   it. With the site's `AuditLog` as `audit:`, the delete's `person.data_deleted`
   is written inside the delete's transaction - no delete without it: while the
   audit log's table waits for its update, `503 feature_unavailable`.
2. **A restore does the deletes again inside its transaction.** The site's
   Backups take both hooks from People - `...$people->restoreHooks(delete:
   $deleteHook, defer: ..., then: ...)` among its options (`beforeCommit`, the
   deletes done since the backup; `afterRestore`, the scrub of the IAM's deleted
   accounts) - and the hand-written versions go: the survey's
   `surveyScrubAfterRestore()`, Piano Academy's `peopleScrubAfterRestore()` (its
   new generations become `then:`), genshin's `backupRestored()`. A restore is
   refused - "restore again in a minute" - while a check of the IAM's list is
   running, and rolled back whole when a delete cannot be done again.
3. **Only a backup made here is restored here.** A plain backup is sealed under
   the installation's key; one made before 0.5.0 has no seal and is restored
   offline (`--extract`) only - make a backup right after the update. The seal
   keeps out a file put into `storage/backups` by somebody who cannot replace
   that key - not somebody who can write the whole storage folder ("Backups").
4. **Addresses** (the admin tools): a site behind Cloudflare passes
   `trustedProxies: ClientAddress::CLOUDFLARE` to its `AdminContext` (and to its
   `AuditLog` where it keeps the address); without it, nothing but
   `REMOTE_ADDR` is read, and its visitors share Cloudflare's bucket. `names:`
   (people's names by id) is optional. The IAM gives its own secrets' names to
   `Redactor::addSecretNames()` in place of editing messages.
5. **The host**: `ext-mbstring` is required, and the backups need `ext-zlib`.
6. **Angular** - nothing has to change to keep building:
   - `AuthCallbackComponent` and `SignInFailureComponent` from
     `@anotoki/lib/auth` are routes now that load their screen as it is shown
     (`@anotoki/lib/auth/screens`): a site's routes stay as they are. A spec that
     renders one and reads its markup at once imports it from
     `@anotoki/lib/auth/screens`, or waits a turn and reads it inside the route
     (`anotoki-auth-callback-route`, `anotoki-sign-in-failure-route`).
   - The folded bar's menu button carries a dot when a link inside has a badge
     and the site gives no `phoneMark`, its name saying which ("Menu: Feedback
     3"): a spec reading the button's name sees that.
   - `provideAnotokiErrorReport()` (`@anotoki/lib/error-report`) sends the
     browser's errors to the site's error log - each site adds it when its
     backend has `ErrorLogRoutes::client()`.

## Translations (0.3.0)

Every site keeps its words in three tables - `languages`, `translation_keys`,
`translations` - which the IAM wrote first and the survey, Piano Academy,
Japanese Academy and the build analyzer copied. `Anotoki\Lib\Translations` is
one implementation of them for every site: the schema, the bundle the pages
read, the admin pages' API, the checks of a string, the command line. One wire
format, the IAM's (snake_case; anotoki-iam's `docs/api.md` is the contract, and
the bundle is the IAM's byte for byte). The library decides nobody's
permissions: each route group takes the site's own checks. The Angular half,
`@anotoki/lib/translations`, is the pages' side of it: the language and the
strings, the pipes, the kit's words and the switcher from the site's database,
and the admin Translations and Languages pages ("The Angular half" below).

### The schema: the library's set `anotoki_translations`

```php
$migrator = new Migrator($pdo, [new MigrationSet('survey', __DIR__ . '/../migrations/survey'), Schema::migrationSet()], $options);
```

- The set comes **after** the site's own: the sites' early migrations made these
  tables themselves (Japanese Academy's with a plain `CREATE TABLE`). A site born
  without them may list it first.
- `001_languages_and_strings.sql` makes the tables only where they are missing
  (named constraints, `updated_by` an id without a foreign key), with English
  and Slovak, and proves the shape the library relies on where they are there:
  the columns of compatible types, the three primary keys, the two foreign keys
  of `translations` cascading on update and delete, the `CHECK (value <> '')`,
  no column of the site's own that must be filled and has no default, English
  switched on. It refuses any other shape, naming every difference (genshin's
  today: `languages` keyed by name, TIMESTAMP stamps, no CHECK) - such a site
  moves its tables into the one shape in a migration of its own first. It
  tolerates each site's own: `updated_by` INT or BIGINT and a foreign key from
  it to the site's people, other tables referring to `languages (code)`,
  constraint names, columns of its own with a default. On the five aligned
  sites it makes nothing.
- `002_library_words.sql` adds the library's keys, under `anotoki.`: the kit's
  words (`anotoki.ui|topbar|language|theme.*` - its dialogs, notifications,
  fields, page numbers, the top bar, the language and theme switches) and the
  status page's (`anotoki.siteStatus.*`), 54 keys in English and Slovak. Their
  descriptions are the library's; their strings are written only for a library
  key that has none, so a site that took a key over - its own words, a language
  left blank on purpose - keeps them, and so does an owner's rewording.
- `003_sign_in_words.sql` (0.4.0) adds the auth module's sign-in page's words
  (`anotoki.signIn.*`: "Signing you in…", the failure's heading "Signing in did
  not work", why a sign-in came back without one, Try again, Back), 9 keys in
  English and Slovak, by 002's rule.
- `004_feedback_words.sql` (0.4.0) adds the visitors' feedback form's words
  (`anotoki.feedback.*`: its title, the kinds, the fields and their hints, Send,
  the thanks, every refusal it says), 31 keys in English and informal Slovak, by
  002's rule.
- The house rules: every library file ends with `-- end of NNN` (Japanese
  Academy's draft rule accepts it), has LF line endings, one string a line with
  `''` for an apostrophe and nothing else escaped, names no site table, runs
  whole or statement by statement (`split`), and is never edited once released.
  A site migration never depends on a library file and writes only the base
  columns; a site's gate never counts keys outside its own namespaces (`WHERE
  name NOT LIKE 'anotoki.%'`); `anotoki.` is the library's namespace; a site that
  words a library string its own way writes the key first (`INSERT … ON CONFLICT
  DO NOTHING`, then `UPDATE … WHERE value = '<the library's words>'` for a
  database that has the library's already).

### The PHP half

```php
use Anotoki\Lib\Translations\Http\TranslationsRoutes;
use Anotoki\Lib\Translations\Rules\RequiredPlaceholders;
use Anotoki\Lib\Translations\Translations;
use Anotoki\Lib\Translations\TranslationsConfig;

$translations = fn (): Translations => new Translations(db(), new TranslationsConfig(
    releasedLanguages: ['en', 'sk'],                    // the migrations write strings for them: never deleted
    // serverNamespaces: ['mail'],                      // the IAM: never in a bundle; whole in the audit summary
    // rules: [new RequiredPlaceholders(['login.continueTo' => ['app']]), $iamMailRules],
    // usage: $languageUsage,                           // the site's fields, refusal and work around a delete
    // extraCheck: fn (string $field, string $text, array $about): ?Refusal => …,
    // beforeWrite: fn (PDO $pdo, ?int $userId) => …,   // inside a save's transaction, first
));

TranslationsRoutes::bundle($app, $translations, $error);                            // GET /api/translations/{code}
TranslationsRoutes::strings($app, '/api/admin', $translations, $actor, $error, $audit)->add($requireEditor);
TranslationsRoutes::languages($app, '/api/admin', $translations, $error, $audit)->add($requireAdmin);
// and in the gate's open paths: TranslationsRoutes::openPath() - '~^/api/translations/[^/]+$~D'
```

- `Translations` (built per request from a connection with
  `PDO::ERRMODE_EXCEPTION`, as the Migrator is) - `ready()`, `offeredCodes()`,
  `bundle($code)` (`{language, languages, values, english?}`: the language's
  strings over English, no key of a server namespace, `sk-sk` answered by `sk`;
  503 `translations_unavailable` without the tables or an English string),
  `languages()` (`{code, name, native_name, enabled, sort_order, seeded,
  strings}` and the site's fields), `createLanguage()` (under `LOCK TABLE …
  SHARE ROW EXCLUSIVE`, last in the order, at most `languagesMax`),
  `updateLanguage()` (what is sent; `changed` for the audit; English stays on),
  `deleteLanguage()` (never English, never a released language; the row held
  `FOR UPDATE`; its strings cascade), `grid()`, `save($values, $userId)` (one
  transaction; a string sent as it is keeps its writer and time; a blank one in
  another language goes; the IAM's audit summary), `export()`, `import()` (a flat
  `{key: text}`; a blank is passed over, an unknown key refused - a number too,
  as a JSON list's items or `{"0": …}` come: `unknown_key`),
  `forgetWriter($id)`, `storedRefusals()`, `keyNames()`, `keysWithout($code)`.
  None of them throws for a refusal: they answer a `Support\Refusal` (status,
  code, message, extra).
- `StringCheck::changes()` - the IAM's checks in the IAM's order, the changes
  sorted by key and language (one lock order for every save): `invalid_value`,
  `unknown_key` (+ `keys`; a key is read as a name, so a number PHP made of one
  is a key the site does not have), `unknown_language`, `fallback_required`,
  `invalid_placeholder`, `placeholder_changed`, `unknown_placeholder`, and what a
  site's rules add. Text is measured as the browser does (`Text`: its `trim()`
  set, blank when nothing is to be seen, code points); a run of a million spaces
  is too long, never empty.
- `KeyRules` - a site's rules for some of its keys: `placeholders($key)` (the
  key's fixed placeholders, in every language), `checkFirst()` (before the
  family's length and brace checks: a mail string's one line, its length and
  bytes) and `check()` (after them: a placeholder a key must keep, an address in
  a mail). The IAM's mail rules become one of these, in the IAM;
  `Rules\RequiredPlaceholders` and `Rules\LibraryKeyRules` (an `anotoki.*` key
  has the library's placeholders, whatever a site's stored English says) ship.
- `LanguageUsage` - `extras()` (the IAM's `accounts`), `refuseDelete()` (the
  survey's 409 `language_in_use`), `beforeDelete()` (Japanese Academy's copy of
  the strings; throwing cancels the delete).
- `TranslationsRoutes` - `bundle()` (public, reads no token and no cookie; `ETag`
  and `Cache-Control: no-cache`, 304 compared the weak way, `-gzip`/`-br`/`-zstd`
  tags too; a database that cannot be asked is a 503 as well), `strings()`
  (`GET $base/languages`, `GET|PUT $base/translations`,
  `GET $base/translations/{code}/export` - a download in the file's format -,
  `PUT $base/translations/{code}/import`) and `languages()` (`POST
  $base/languages`, `PUT|DELETE $base/languages/{code}` - the delete answers
  `{strings, …the site's fields}`). In order: a `{code}` that is none, 404 before
  any query; the body - read by the library from the raw stream, JSON only (415),
  at most `bodyLimit` (413), not empty (422 `empty_body`), JSON (400
  `invalid_json`), `{}` kept apart from `[]`; then 503 `translations_unavailable`
  with `Retry-After: 60` while the tables are missing; then the work. JSON
  answers carry `Cache-Control: no-store`. `$error(ResponseInterface, Refusal)`
  is the site's error body (the IAM's `{error, code, …extra}`; by default
  `{code, message, …extra}`), `$actor(request)` the writer's id (from the session
  or the token, never a body), `$audit(request, action, type, id, details)` the
  site's log: `language.created|updated|deleted`, `translations.saved|imported`.
- The command line - `Cli\TranslationsCommand` wrapped by a site's short
  `translations.php` (never uploaded): `--status` (missing keys, keys without
  English and the scan's problems fail; unused keys and stored strings a save
  would refuse warn; each language's coverage), `--export CODE [FILE]`,
  `--import CODE FILE` (written by nobody, audited `via: command line`); it
  refuses while the tables are missing or a migration waits.
  `Cli\KeyScanner($root, exclude, dynamicKeys, ignoreNamespaces, pipes, calls,
  pluralForms, innerHtml)` is the family's scan of a frontend (the IAM: pipe `t`,
  no plural forms, `innerHtml: 'anywhere'`, `mail` ignored; `anotoki` always
  ignored). `Cli\EnglishDictionary` reads a site's compiled English (a
  TypeScript object or JSON parts) to hold it to the seeded English.

### The library's own words

`php/resources/library-words.json`, `{"keys": {name: {description, en, sk}}}`,
is the one source of the `anotoki.*` keys: `LibraryWords` reads it (and
`LibraryKeyRules` takes the placeholders from it), and a test holds what the
library's migrations write on a new database to it. A new library string is an
entry there and a new library migration; a rewording of a released one is a
migration that changes it only where it still has the earlier words. The
Angular half reads the same file and keeps no copy by hand: `bun run words`
(`angular/scripts/library-words.mjs`) generates its built-in words a namespace
at a time (`anotoki.<namespace>.<word>`; committed), each where only its readers
import it:

- the kit's namespaces (`language`, `ui`, `topbar`, `theme`) in
  `angular/projects/lib/ui/config/src/library-words.ts` - `KIT_LIBRARY_WORDS`
  and `LibraryKey` (every key's name, a type) of `@anotoki/lib/ui/config`, which
  the kit reads (`AnotokiWords`), and the translations module too;
- every other namespace in an entry point of its own,
  `angular/projects/lib/words/<namespace>/` - `@anotoki/lib/words/site-status`
  (the status page's), `@anotoki/lib/words/sign-in` (the sign-in page's),
  `@anotoki/lib/words/feedback` (the feedback form's) - which its module alone
  imports, and registers with the translations module from its `provide*()`
  function: `provideLibraryWords(SITE_STATUS_LIBRARY_WORDS)` in
  `provideAnotokiMigrations()`, `provideLibraryWords(SIGN_IN_LIBRARY_WORDS)` in
  `provideAnotokiAuth()`, `provideLibraryWords(FEEDBACK_LIBRARY_WORDS)` in
  `provideAnotokiFeedback()` (the multi provider `ANOTOKI_LIBRARY_WORDS` of
  `@anotoki/lib/ui/config`);
- `@anotoki/lib/words` (`angular/projects/lib/words/`): `LIBRARY_WORDS`, every
  namespace's, for a site's specs and the showcase - no module imports it.

A spec (`library-words.spec.ts`), CI (`bun run words --check`, which also finds a
namespace's folder the JSON no longer has) and the PHP half's `LibraryWordsTest`
fail when a file is stale; prettier leaves them alone (`bun run format` would
rewrite what `--check` holds byte for byte). So a page has the library's words
while the database cannot be reached, and never shows a key of the kit's or of a
module it uses as a key - and a first load carries the words its page says: the
kit's (a button, the bar, the translations module), the status page's beside
them (the gate), the sign-in page's with the auth module, the feedback form's
with its configuration. A module that brings
words brings a namespace, an entry point of its own and its registration:
`scripts/check-package.mjs` derives the entry point's boundaries, holds every
first load to the namespaces its page says, and refuses a module that imports
its words without registering them, and the translations module importing any.

### The Angular half: `@anotoki/lib/translations`

```ts
// app.config.ts
provideHttpClient(withInterceptors([siteStatusInterceptor, authInterceptor])),   // the bundle and the admin routes go through it
provideAnotokiTranslations(() => {
  const auth = inject(AuthService);                     // the factory runs in an injection context, the first time the module needs it
  return {
    storagePrefix: 'anotoki-survey',                    // -> 'anotoki-survey:language', ':language-cache', ':localization-drafts'
    // storageKeys: { language: 'academy-language', cache: 'academy-language-cache', drafts: 'academy-admin-drafts' },
    fallbackEnglish: en,                                // optional: the site's compiled English, read while no bundle is in memory
    account: {
      language: () => auth.user()?.locale ?? null,      // the account's language (a signal is followed)
      decides: () => auth.preferences() !== null,       // signed in with an IAM that keeps the person's choices
      save: (code) => auth.savePreferences({ language: code }),   // a choice made on the page, to the account
      // quiet: (error) => ...,                         // a refusal said nothing about (default: code invalid_request)
      userKey: () => auth.userKey(),                    // whose drafts the admin pages keep; null: nobody signed in
    },
    canAsk: () => auth.status() === 'ready',            // optional: nothing is asked while the site cannot run
    admin: {
      allows: { strings: () => auth.isStaff(), languages: () => auth.isAdmin() },
      routes: { translations: '/admin/translations', languages: '/admin/languages', migrations: '/admin/migrations' },
    },
    // bundleUrl '/api/translations', fetchBundle(code), onBundleRefused(refusal), englishOnly(url) (default /admin and under it),
    // locales { en: 'en-GB', sk: 'sk-SK' }, browserAliases { cs: 'sk' }, waits { cache: 1000, first: 3000, retry: 500, settle: 2000 },
    // libraryWords { sk: { 'anotoki.siteStatus.unavailableText': '...' } }, admin { apiBase, siteName, leads, groups, pagesLead,
    // languageNotes { row, beforeDelete, afterDelete }, failureText, importMaxBytes }
  };
}),
provideAnotokiUi(() => ({ ...inject(TranslationService).forKit(), icons: { ... } })),
provideAnotokiShell(() => ({ theme: { ... }, languages: inject(TranslationService).forShell() })),
provideAnotokiMigrations(() => {
  const i18n = inject(TranslationService);
  return { ..., language: () => i18n.effectiveLanguage(), get words() { return siteStatusWords(i18n); } };
}),
provideAppInitializer(() => inject(TranslationService).init()),   // after the sign-in has put the address back
```

```ts
// the site's title strategy, after every navigation (the service never injects the Router: NG0200)
this.i18n.noteAddress(url);
// the admin routes: the pages' own entry point, never in the first load; the guard is the eager one's
{ path: 'translations', canDeactivate: [anotokiUnsavedChangesGuard], loadComponent: () => import('@anotoki/lib/translations/admin').then((m) => m.TranslationsPageComponent) },
{ path: 'languages', canDeactivate: [anotokiUnsavedChangesGuard], loadComponent: () => import('@anotoki/lib/translations/admin').then((m) => m.LanguagesPageComponent) },
```

- `TranslationService` (root) - `language`, `languages`, `ready`, `englishOnly`,
  `effectiveLanguage` (English in the English areas), `locale`, `languageNotSaved`,
  `storedEnglishLang`, `signInLanguage`; `t(key, params)`, `plural(key, count, params)`
  (`Intl.PluralRules`, a form the family lacks reads `.other`, `{count}` written
  as the language writes numbers), `around()` and `aroundPlural()` (a sentence cut
  at a placeholder for markup: `[before, after, found]`), `has()`, `number()`,
  `libraryWord()`; `setLanguage(code, { remember?, save?, revertible? })` (the last
  choice wins; the words are fetched before the page switches; the language the
  last choice took off the page is kept in memory - the way back needs no
  request), `clearLanguageNotSaved()`, `preferred(offered)` (a new account's
  language), `reload()` (after an admin change or an Apply), `noteAddress(url)`,
  `init()`, `settled()` (a guard waits at most 2 s for the language just wanted).
- Which language, strongest first (the family's ranking): this tab's `?lang=`
  (taken out of the address, kept in sessionStorage), a choice made on the page,
  the account's language, the browser's languages (`cs` reads `sk` while no Czech
  is offered), English. While the account decides, it comes before this
  device's remembered choice - only the tab's `?lang=` and a choice of this visit
  come first; a change of it is followed when it comes; a choice is saved to it,
  one save at a time and the latest last; a quiet refusal (the IAM does not
  offer the language) keeps it the site's own for the visit, any other sets
  `languageNotSaved` (the switcher's note). Otherwise this device's choice comes
  first and the account's is adopted only while nothing is stored. A choice made
  before signing in holds for the visit.
- Words, for each key: the bundle's string; while no bundle is in memory, the
  site's compiled English (`fallbackEnglish`), then the key (warned once). With a
  bundle, a key it lacks is shown as the key, warned once: the owner wants to see
  it. A library key (`anotoki.*`) is never a key: the database's string (an
  owner's rewording), then the site's `libraryWords`, then the library's
  built-in words of the language, then their English. The built-in words are the
  kit's, and those each module the site uses registers from its `provide*()`
  function (`provideLibraryWords`): a module's come with the module alone, and a
  key of a module the site does not use, with no string, reads as the key
  (warned once). `<html lang>` follows `effectiveLanguage`.
- The start: the cache (`<prefix>:language-cache`, in force only until the
  server answers) - a second's wait with one, three without and one more try
  after half a second; two requests at most otherwise (the first wish, then what
  the list that came settles on). Nothing is asked while `canAsk()` is false (the
  cache is taken in whatever language it is in), and a page that is behind asks
  again once per navigation. The bundle goes through the site's HttpClient,
  marked `ANOTOKI_TRANSLATION_BUNDLE` (a site's interceptor leaves its failure
  alone), or the site's own `fetchBundle`; a refusal with an answer is the
  site's to read (`onBundleRefused`: a 503 `not_set_up`, `update_pending`).
- Pipes `translate` and `translatePlural` (impure; `string` keys). A site with
  typed keys declares its own two-line pipes over the service with its key type
  (the IAM's `t`) and imports those.
- `forKit()` - the kit's `language` (the page's) and `lookup` (the database's
  `anotoki.<key>`, then `libraryWords`), so an owner's rewording on the
  Translations page reaches the kit; `forShell()` - the shell's switcher's
  languages under their own names, the choice through `setLanguage()`, the note.
  `siteStatusWords(i18n, overrides?)` - the status page's words from the
  database (`overrides` win: a site's own page around the library's).
- `<anotoki-translations-page>` (English; for `admin.allows.strings`) - each
  language's coverage (only the plural forms its numbers take count), tags,
  export (the server's file and name) and import (for `admin.allows.importStrings`,
  as `strings` unless the site says otherwise; at most 1 MiB, a byte-order
  mark taken off, a flat map, a question with the count of the strings in it);
  a search over keys, descriptions and text (stored and typed; diacritics and
  case aside; rows decided as the search changes, never while typing);
  "Missing in X"; plural families as one block with the numbers each form is
  for and samples for 1, 3 and 12; the keys only the server reads in blocks of
  their own (`admin.groups`: the IAM's mails, their titles and parts' order);
  columns stacked above three languages or in a narrow page; Save sends only
  the changed boxes and keeps what is typed while it is out; a refusal beside
  its key (brought into view, its box focused); a key or a language gone
  meanwhile re-reads the grid; Discard behind a question; before the tables
  exist, a link to `admin.routes.migrations`.
- `<anotoki-languages-page>` (English; for `admin.allows.languages`) - each row's
  names and place with its own Save and Undo; the offered switch saving at once
  (back when refused; English's off); "Fallback" and "Released with the site"
  tags; the site's own fields in words (`admin.languageNotes.row`: the IAM's
  accounts); delete only for a language not released, and only for
  `admin.allows.deleteLanguages` - offering an export of
  its strings first, then a question naming them (and `beforeDelete`'s words),
  then what went (`afterDelete`: Japanese Academy's copy); a new language hidden
  unless asked otherwise, added only for `admin.allows.addLanguages`. Both
  `*Languages` follow `languages` unless the site says otherwise - the survey lets
  its EDITORs name, order and offer the languages, and its ADMIN alone delete one
  (`deleteLanguages: () => auth.isAdmin()`): what the site does not allow is not
  drawn. Both pages read again after every change
  (`reload()`), ask their questions in their own dialog (nothing in the site's
  root), say what happened in the page, and work at 390 px.
- `LocalizationDrafts` - what is typed and not saved, in sessionStorage
  (`<prefix>:localization-drafts`) with whose it is: kept through a reload and a
  sign-out, dropped when somebody else signs in; one `beforeunload` question -
  never once nobody is signed in (the page is on its way to the sign-in, and the
  drafts wait in the tab). The Translations page's note promises nothing of a
  sign-out: a site's own bar may ask before it (the survey's does).
  `anotokiUnsavedChangesGuard` asks the page (`HoldsUnsavedChanges.canLeave()`),
  never once nobody is signed in.
- `@anotoki/lib/translations/testing` - `provideTestTranslations(values, { language, languages, english })`
  and `TEST_LANGUAGES`: the real service with that bundle in memory, no HTTP.

### A site moves in

What every site does (with the PHP half's "What a site must do" in CHANGELOG.md):
the library's set appended to its Migrator; one site migration in the site's own
style, released with the code, that moves the site's own copies of the
library's words under the library's keys with every language, writer and date
(`language.button` -> `anotoki.language.button`, ...: their rows copied into a
temporary table, inserted under the library's key `ON CONFLICT DO NOTHING`,
the old keys deleted, and a gate that proves every value moved) - so `002`
leaves them as they were, a language left blank included; the site's
translations code replaced by `Translations` + `TranslationsRoutes` and
`provideAnotokiTranslations()`; its switcher, admin pages, drafts and guard by
the library's; `noteAddress()` from its title strategy; its catalogue test
leaving `anotoki.` out. Export every language from the admin panel before the
upload. Per site:

- **The IAM** - `storageKeys: { language: 'anotoki-iam:lang', cache: 'anotoki-iam:lang-cache', drafts: 'anotoki-iam:localization-drafts' }`,
  `fallbackEnglish: en` (en.ts stays: the key list and the last resort), its typed
  `t` pipe over the library's service (`TranslationKey | LibraryKey`);
  `account.language` the session user's language, `decides` signed in, `save` its
  `PUT /api/account/profile` (the account page's select:
  `setLanguage(code, { revertible: true })`), `userKey` the user's id;
  `fetchBundle` through its `PublicApi` (or its interceptor reads
  `ANOTOKI_TRANSLATION_BUNDLE`); `preferred()` for registration, `settled()` in
  its account and admin guards; `admin.allows` ADMIN for both, `siteName: 'the IAM'`,
  `groups` its mails (`MAILS` titles, `MAIL_PARTS` order, the rules lead),
  `languageNotes` its `accounts`, `failureText` its `adminErrorText`; the
  switcher `<anotoki-language-switcher appearance="auto">` on the sign-in pages
  and the bar's. 017 moves `lang.label`/`lang.button` and renames
  `sessions.method.other`/`sessions.ended.other` (`.unknown`).
- **build-analyzer** - `storageKeys` its `build-analyzer-language`,
  `-language-cache`, `build-analyzer-localization-drafts`; `fallbackEnglish: en`
  (the four JSON parts) and its typed `translate`/`translatePlural`; `canAsk`
  `MetaStore.loaded()`; `failureText` its owner errors; 006 moves `language.button`,
  `language.notLoaded`.
- **The survey** - `storagePrefix: 'anotoki-survey'` (its keys already);
  `canAsk` `auth.status() === 'ready'`; formal Slovak ("Vy"): its 010 writes
  before the library's set, in formal Slovak, every library key whose Slovak
  speaks to the reader - `anotoki.ui.copyFailed`, `anotoki.topbar.menuButton`,
  `anotoki.topbar.menu`, `anotoki.topbar.account`, `anotoki.language.notLoaded`,
  `anotoki.language.notSaved`, `anotoki.theme.notSaved`,
  `anotoki.siteStatus.unavailableText` (an `INSERT … ON CONFLICT DO NOTHING`,
  then `UPDATE … WHERE value = '<the library's words>'`) - and keeps the same
  words as `libraryWords: { sk: { ... } }` for the time without a database;
  `allows.languages` ADMIN only.
- **Piano Academy** - `storagePrefix: 'piano-academy'`; `kept-english.ts` as
  `fallbackEnglish` (less the three `language.*`); 008.
- **Japanese Academy** - `storageKeys` `academy-language`, `academy-language-cache`,
  `academy-admin-drafts`; `fetchBundle` its plain `fetch()` with `X-Time-Zone`,
  its `refused()` as `onBundleRefused`, `canAsk` false while `SiteStatus`
  blocks; `languageNotes.afterDelete` names its copy (`answer.copy`); 009, after
  its draft 008 is finished.
- **genshin** - after its tables move into the one shape (its 049):
  `storageKeys` `anotoki-genshin-impact-language`, `-language-cache`;
  `account.save` its `AccountPreferences.save('language', code)`; its `translate`
  pipe name stays (the library's pipe has it); the library's pages replace
  `sites/admin/localization`.

## Auth (0.4.0)

Every anotoki site signs in through the anotoki IAM, the same way: the browser
goes to the IAM (authorization code with PKCE), brings tokens back, and sends
the access token to the site's own server, which checks it without asking the
IAM. The IAM's docs/integrating-an-app.md is the contract. This module is that
contract once, for every site: the IAM's SDKs (`sdk/js/anotoki-auth.mjs` 1.2.0,
`sdk/php/anotoki_iam.php` 1.1.0) moved into the library - the IAM keeps its
`sdk/` frozen, for everybody outside the family - with the sites' own wrappers
around them made one, and the **sign-in hint** (the owner's decision of
2026-10-07): a person signed in at the IAM is signed in, without a click, on a
site that lets visitors in.

### The server: `Anotoki\Lib\Auth`

```php
use Anotoki\Lib\Auth\AccessTokens;
use Anotoki\Lib\Auth\Http\AuthRoutes;
use Anotoki\Lib\Auth\Http\Identify;
use Anotoki\Lib\Auth\Http\RequireCaller;
use Anotoki\Lib\Auth\Http\RequirePerson;
use Anotoki\Lib\Auth\Http\RequireRole;
use Anotoki\Lib\Auth\IamClient;
use Anotoki\Lib\Auth\SignInRule;

$iam = IamClient::fromArray(['cache_dir' => storageDir() . '/cache'] + iamSettings());  // the SDK's keys: the settings file stays
$rule = new SignInRule($iam);                                    // requiredWhen: fn (): bool => … - the site's own reason

AuthRoutes::config($app, $rule, $iam, extra: fn (): array => ['visitor_days' => VISITOR_KEEP_DAYS]);   // GET /api/auth/config
$app->group('/api/admin', $adminRoutes)->add(RequireRole::any('ADMIN', 'EDITOR'));
MigrationsRoutes::admin($app, '/api/admin/migrations', $migrator)->add(RequireRole::admin());
$app->get('/api/surveys/{id}', $survey)->add(new RequireCaller('empty'));   // a person's, a visitor's, or nobody's

// Who a request is, once, for every route - inside the SiteGate (Slim runs the last added first):
$app->add(new Identify(new AccessTokens($iam), $rule, $app->getResponseFactory(),
    visitor: fn (string $key): ?SiteVisitor => visitorFind($key),  // the site's visitor, null for a key it does not know
));
$app->add(new SiteGate($state, $app->getResponseFactory(), [AuthRoutes::openPath(), /* … */]));
```

- `IamClient` - the IAM and the application: `fromArray()` takes the SDK's keys
  (`issuer`, `client_id`, `cache_dir`, `jwks_file`, `application_file`, … and
  `anonymized_file`), so every `config/iam.local.php` stays as it is. Give it a
  **private `cache_dir`** (the site's `storage/cache`): the key set and the
  answer about signing in are kept there, in the SDK's own files - a site that
  moves keeps them; without one nothing is kept, and the IAM is asked on every
  request. The `issuer` (and a `jwks_url`) is **https** - http only on this
  computer (`localhost`, `127.0.0.1`, `[::1]`), as in development; anything else
  throws: the keys tokens are checked with come from there. What the library
  asks the IAM (`Support\HttpGet`) goes over http or https alone - never a
  file, a PHP stream or another protocol, by curl's rule
  (`CURLOPT_PROTOCOLS_STR`) and its own.
- `AccessTokens::verify($token): ?Person` - the SDK's rules: RS256 and `typ`
  `at+jwt` pinned (never read from the token), a `kid` of the IAM's key set
  (`Jwks`: an hour on disk, one fetch per unknown `kid` at most every five
  minutes, and five minutes' rest after a failed fetch - a stamp later than
  now counting as none), no `crit`, `iss` exact, `aud` (and `client_id`, when
  there is one) this application, the times with 60 s of leeway, `sub` an IAM
  id. Why a token failed is `lastReason()`, for the log only: the library's
  words, with at most 64 characters of the token's own (its `typ`, its `kid`)
  in `[A-Za-z0-9._+-]`, anything else a `?`; `lastReasonKind()` is the same
  without them.
- `Person` - `sub`, `name` (preferred_username), `email`, `locale`,
  `preferences`, `roles` (upper case), `sid`, `authTime`, `scope`, `claims`;
  `has()`, `isAdmin()`, `isStaff()`, `signedInWithin($seconds)`.
- `SignInRule` - whether the site requires signing in: the IAM's switch (its
  public answer about the application), **failing closed** - a 404 is "required",
  no answer keeps the last one or "required"; a minute on disk, one request
  asking at a time, two seconds' wait, one answer per request (Identify resets
  it). `known()` is false once the IAM answered 404 (a site it does not know or
  has switched off): the boot configuration's `sign_in_available`.
- `Http\Identify` - in this order: a `Bearer` header is a person's - a verified
  token or 401 `invalid_token`, never the visitor; while signing in is required,
  nobody (the visitor key is not read); an `X-Visitor-Key` is the site's
  visitor, or 401 `visitor_unknown`; else nobody. It sets `anotoki.person`,
  `anotoki.visitor`, `anotoki.caller`; it reads no table but the site's
  visitor closure. **No cookie is ever read as somebody** - the IAM's sign-in
  hint least of all. A refused token is said to the library's `Log` at most
  once a minute for each kind of refusal (a stamp per kind in the `cache_dir`,
  `anotoki-auth-refused-*.stamp`), an expired one never - tokens made up by the
  thousand are a line a minute; a site's own `log:` hears every reason.
- `Http\RequirePerson` (401 `sign_in_required`), `Http\RequireRole` (from the
  token, never the database - the Migrations routes keep working while `users`
  waits; 403 `forbidden`, or `->refusing('admin_only', '…')`),
  `Http\RequireCaller('refuse'|'empty'|'make')` (the "owner" check:
  `anotoki.nobody` or `anotoki.makeVisitor` for the route), `Http\SignInGate`
  (genshin's one gate: while signing in is required, every path but the open
  ones needs a person).
- Answers are `{code, message}` with `Cache-Control: no-store` and, on a 401,
  `WWW-Authenticate: Bearer realm="<client id>"` (`error="invalid_token"` for a
  token that was sent); `responder:` keeps a site's own shape while it adopts.
- `Http\AuthRoutes::config()` - `GET /api/auth/config` → `{issuer, client_id,
  login_required, sign_in_available, …extra}`; `configBody()` for a site that
  puts it in its own boot answer (genshin's `/api/settings/public`, the build
  analyzer's `/api/meta`); `openPath()` for the SiteGate.
- Testing (`Anotoki\Lib\Auth\Testing`, shipped): `TestIam::create($dir)` - a key
  pair, `token($sub, $roles, $claims)`, `sign()` for the tokens that must be
  refused, `client()` (an IamClient on its files), `signInRule(false)`,
  `anonymized([...])`; `StandInIam::start($dir)` - **the family's one stand-in
  IAM** (`php/testing/stand-in-iam.php`) on a free port: discovery, keys,
  authorize with PKCE (and `prompt=none`'s `login_required` /
  `interaction_required`), token with rotation, revoke, logout, preferences,
  the public application answer, the paged deleted accounts, and the sign-in
  hint set and cleared as the IAM does; its state is `POST /_test/state`. For a
  browser check: `STAND_IN_DIR=<dir> STAND_IN_ISSUER=http://localhost:8597 php -S
  localhost:8597 vendor/anotoki/lib/php/testing/stand-in-iam.php`. It answers
  nothing but under PHP's built-in server.

### The browser: `@anotoki/lib/auth`

```ts
// app.config.ts - beside the gate, the shell and the translations module, each from its own entry point
provideHttpClient(withInterceptors([siteStatusInterceptor, anotokiAuthInterceptor /* , the site's own */])),
provideAnotokiAuth(() => {
  const i18n = inject(TranslationService);              // the factory runs in an injection context; so do its functions
  return {
    config: '/api/auth/config',                         // or () => inject(SiteSettings).auth(): the site's own boot answer
    visitors: () => inject(VisitorKey),                 // current(), remember(), forget(); none: no visitors
    language: () => i18n.language(),                    // ui_locales, and the sign-in page's language
    translate: (key) => i18n.libraryWord(key),          // an owner's rewording of anotoki.signIn.*, as the kit's lookup
    beforeSignIn: () => inject(SignInChecks).ready(),   // settle the writes, ask about unsaved work; false stops it
    beforeSignOut: () => inject(SignInChecks).ready(),
    // preferences: () => inject(SitePreferences),      // apply(preferences, locale) - below
    // api: { prefixes: ['/api/'], skip: [...], visitorPrefixes: ['/api/surveys'] }, headers: () => ({ 'X-Time-Zone': … }),
    // hint: { cookieName: 'anotoki_signed_in' } | false, makesVisitor, forbiddenRoute, failureRoute: '/sign-in-failed', words
  };
}),
provideAnotokiShell(() => {
  const auth = inject(AnotokiAuth);
  return {
    theme: { storageKey: 'anotoki-survey:theme', account: () => auth.preferences()?.theme ?? null, save: (theme) => auth.savePreferences({ theme }) },
    languages: inject(TranslationService).forShell(),
  };
}),
provideAnotokiTranslations(() => {
  const auth = inject(AnotokiAuth);
  return {
    storagePrefix: 'anotoki-survey',
    account: { language: () => auth.locale(), decides: () => auth.preferences() !== null, save: (code) => auth.savePreferences({ language: code }), userKey: () => auth.sub() },
    // ...
  };
}),
provideAppInitializer(() => {
  const status = inject(SiteStatus), auth = inject(AnotokiAuth), i18n = inject(TranslationService);   // inject before the first await
  return status.check().then(() => auth.init()).then(() => i18n.init());   // the words after the sign-in put the address back
}),

// app.routes.ts - each route loads its screen (@anotoki/lib/auth/screens) as it opens: neither is in the first load
{ path: 'auth/callback', component: AuthCallbackComponent },
{ path: 'sign-in-failed', component: SignInFailureComponent },                 // optional: the module's failureRoute, in the site's frame
{ path: '', canActivateChild: [personOrVisitorGuard], children: [/* … */] },   // personGuard where only a person may
{ path: 'admin', canActivate: [staffGuard], children: [/* … */] },             // adminGuard for ADMIN alone
```

The shell's theme and the translations module's language follow the account
through `AnotokiAuth` (`preferences()`, `locale()`, `savePreferences()`: one save
at a time, rejected with the IAM's `code`); `AccountPreferences` is for the rest -
the dates and the clock, and a site that keeps its own theme or language
controls (genshin's header) - and, with `preferences`, hands the site what to
show.

- **The same storage as the SDK**: the tokens in localStorage under
  `anotoki-auth:{clientId}`, the sign-in in progress in sessionStorage under
  `anotoki-auth:{clientId}:login:{state}`, discovery under
  `anotoki-auth:discovery:{issuer}`, refreshes under the Web Lock
  `anotoki-auth-refresh:{storageKey}` - nobody is signed out by the switch, and a
  sign-in in flight while the site is uploaded still lands. The client
  (`createAuthClient`) is the SDK's code, ported: PKCE, state and nonce, the ID
  token's checks, `safeReturnTo`, RFC 9207, rotation, `savePreferences`' own-token
  rule. What runs only at a moment is loaded then (0.5.0): its token requests - a
  callback's code exchange, a refresh, a preferences save - are
  `@anotoki/lib/auth/session`, loaded the first time one is made (one that cannot
  load is the IAM out of reach: the session stays, a save is refused `network`).
  The module's shared part is `@anotoki/lib/auth/core`; a site imports from
  `@anotoki/lib/auth`, which names it all.
- `AnotokiAuth` - `init()` reads the boot configuration (snake_case, or the
  camelCase the sites answer today), finishes a callback, restores the session,
  follows the IAM, and - for a visitor - may make the hint's hop. Signals:
  `state` (`starting | signed-in | visitor | signed-out | unavailable`), `user`,
  `signedIn`, `sub`, `roles`, `isAdmin`, `isStaff`, `displayName`,
  `loginRequired` (failing closed), `visitorsWelcome`, `signInAvailable`,
  `signInFailure` (`{code, returnTo}`, never the IAM's sentence),
  `preferences`, `locale`. Methods: `signIn({returnTo, prompt, maxAge})`,
  `register()`, `signOut({local})`, `token()` (none for another person than the
  page's), `refresh()`, `caller()`, `fetch(url, init, as)`,
  `savePreferences()`, `accountUrl()`, `noteSignInRequired()`. Another person
  signing in in another tab - or anybody, on a page shown to a visitor - loads
  the page afresh.
- `anotokiAuthInterceptor` - who a request of the site's API is, decided when
  it is issued (`issuedAs('person' | 'visitor' | 'nobody' | 'caller')`,
  `CARRIES_VISITOR_KEY` for the move): a person's with the token or never sent
  (a 401 `sign_in_required` of its own), one retry after a 401 for the same
  person, a 502 when an answer to a person's request made a visitor; a
  visitor's with the key (the first save going alone), a key brought kept, a
  `visitor_unknown` key forgotten and tried once more, `sign_in_required` noted;
  writes counted in `SavesInFlight`, which a sign-in waits for.
- `personGuard`, `personOrVisitorGuard`, `staffGuard`, `adminGuard` - a sign-in
  started for the page asked for; every page while `SiteStatus.blocked()`; the
  failure's screen when a sign-in came back without one, could not start or
  cannot be set up (sent to the IAM again, the browser would go round) - on the
  module's `failureRoute`, else the callback route.
- `AccountPreferences.save(name, value)` → `saved | refused(code) | superseded |
  device` (genshin's), one save at a time; with the site's `preferences`, its
  `apply(preferences, locale)` is given what to show - a choice on its way as
  made, a refused one taken back, a language the IAM does not offer kept for the
  visit.
- The two screens are `@anotoki/lib/auth/screens` (0.5.0), out of the first
  load: `AuthCallbackComponent` and `SignInFailureComponent` from
  `@anotoki/lib/auth` are what a route names, as before - each a route
  (`anotoki-auth-callback-route`, `anotoki-sign-in-failure-route`) that loads its
  screen into itself as it is shown; a route may load a screen directly
  (`loadComponent: () => import('@anotoki/lib/auth/screens').then((m) =>
  m.AuthCallbackComponent)`), and a template draws one from there. The screens ask
  the session through `SignInSession` (`provideAnotokiAuth()` provides
  `AnotokiAuth` as it), never the service itself.
- `<anotoki-auth-callback>` - "Signing you in…" on the callback route
  (`anotoki-auth-callback__panel`, `__message`), or in its place the failure's
  screen; `noindex` while it shows.
- `<anotoki-sign-in-failure>` (`SignInFailureComponent`, 0.4.0) - the screen of a
  sign-in that came back without one, could not start, or cannot be set up: "Signing
  in did not work", why - from the failure's code alone, never the IAM's
  sentence - and Try again (the sign-in once more, back to where it started; a
  sign-in that cannot be set up loads the page afresh) and Back (to that page
  without signing in; never the callback or the screen itself). The status page's
  frame, drawn with the kit whatever the site's own styles say (classes
  `anotoki-sign-in-failure__panel`, `__title`, `__message` with role="alert",
  `__actions`; the buttons `[data-retry]`, `[data-back]`); `noindex` while it
  shows; with nothing to say - opened by hand - on to the home page. The callback
  page shows it, and so can a route of the site's own, in its frame under its bar:
  name it the module's `failureRoute` and the guards send the browser there; a
  site that lets visitors in may send a visitor there itself when
  `signInFailure()` says a sign-in of theirs failed.
- The words of both are the library's
  `anotoki.signIn.*` (`php/resources/library-words.json`, every site's database
  through `anotoki_translations/003`, built in from `@anotoki/lib/words/sign-in`):
  an owner's rewording through `translate`, then the site's `words`, then the
  built-in English and informal Slovak - in the visitor's language (`language`).
- `@anotoki/lib/auth/testing` - `provideTestAnotokiAuth({user, roles,
  loginRequired, visitors, cookie})` and `FakeAuthClient` for a site's specs
  (then `await TestBed.inject(AnotokiAuth).init()`).

### The sign-in hint

The IAM sets `anotoki_signed_in` wherever it opens a session - a random value
per session, readable by script, for every host under its parent domain on a
real host (host-only `localhost` in development: open the sites as `localhost`,
never `127.0.0.1`) - and clears it when its session ends. It says somebody
signed in at the IAM in this browser, never who. A site never writes it.

- **The hop**: at boot, on a site that lets visitors in, for a visitor (no
  tokens of the site's own), on a top-level page that is not prerendering and
  not the callback route, while the site is not blocked, when the cookie is
  there in its shape and the IAM knows the site - one `prompt=none` sign-in,
  after the IAM has answered its discovery document (1.5 s). Its error, whatever
  it is, is never shown.
- **Loop protection**: a marker in localStorage, `anotoki-auth:{clientId}:hint` =
  `{value, at, failed?}`, written and read back before the hop (no storage, no
  hop): each value once, at most one hop a minute whatever the value, ten
  minutes after the IAM did not answer. Signing out (everywhere or here) and a
  refresh refused under the value the session was made with mark it tried.
- **Following the IAM**: when the hint is gone or another one since the session
  was made, `init()` refreshes once (waiting at most five seconds) - signed out
  at the IAM is signed out here at the next page load.
- A site that requires signing in sends people to the IAM as ever
  (`personGuard`), and its sessions follow the IAM the same way.
- **Search engines** never get the cookie, so are never sent anywhere. A site
  keeps them off the callback: its `robots.txt` says `Disallow: /auth/`, and
  `<anotoki-auth-callback>` and `<anotoki-sign-in-failure>` set `noindex`.

## People (0.4.0)

Each site keeps its own record of the accounts that use it - its `users`
table, keyed by the IAM's `sub` (every foreign key, every visitor and two
sites' per-person locks live there) - and blanks its copies of a person's name
when the IAM deletes their account, thirty days after they asked: the data
stays, nobody's now. No site keeps a copy of anybody's email (the owner's
decision of 2026-10-07). The administrators' People page is the Angular half,
on the UI kit ("The People page" below).

### The schema

- The site's own migration adds `users.anonymized_at TIMESTAMPTZ` (and nothing
  else) before the library's set runs.
- The library's set `anotoki_people` (`People\Schema::migrationSet()`, after the
  site's own sets) makes `anotoki_people_sync` (one row: `since`, `checked_at`,
  `ok_at`, `error`, `scrubbed`) and `anotoki_people_anonymized` (every id the
  IAM listed: `sub`, `anonymized_at`, `scrubbed_at`, `had_row`). It names no
  table of a site's; applied on databases built from each site's own
  migrations, it changes no site table.

### The PHP half: `Anotoki\Lib\People`

```php
use Anotoki\Lib\People\Http\PeopleRoutes;
use Anotoki\Lib\People\Http\PeopleUpkeep;
use Anotoki\Lib\People\Http\RecordPerson;
use Anotoki\Lib\People\People;
use Anotoki\Lib\People\PeopleColumn;
use Anotoki\Lib\People\PeopleTable;

$people = new People(
    new PeopleTable(name: 'preferred_username', firstSeen: 'created_at', lastSeen: 'last_seen_at'),
    fn (): PDO => db(),
    $iam,
    hooks: [fn (PDO $pdo, int $sub, ?array $former) => /* the site's copies of the name elsewhere */ null],
);

// The page's delete: ALL of it on the $pdo it is given - never db(), which in a restore waits on the restore's locks -
// inside the library's transaction (it begins, commits or rolls back none); its request is null when a restore does
// it again - then the database's part alone, no copy kept first. The person's feedback is forgotten with it, by the
// library (0.5.0).
$deleteHook = fn (PDO $pdo, int $sub, ?ServerRequestInterface $request): ?array => ['deleted' => ['Progress' => …]];

$app->group('/api', $siteRoutes)->add(new RecordPerson($people));            // never on the Migrations routes
PeopleRoutes::admin($app, '/api/admin/people', $people,                       // guards itself: the site's ADMIN
    columns: [new PeopleColumn('notes', 'Notes', 'count', fn (PDO $pdo, array $subs): array => …)],
    visitors: fn (PDO $pdo): array => ['count' => …],
    delete: $deleteHook,                                                         // null: nobody with that id
    audit: $auditLog,                                                            // person.data_deleted (in the delete's transaction), people.synced
)->add(RequireRole::admin()->refusing('admin_only'));                            // a site's own words, where it has them
$app->add(new PeopleUpkeep($people, fn (): bool => $state->state() === SiteState::READY));   // inside the SiteGate
// and after $app->run(): \Anotoki\Lib\Support\AfterResponse::shared()->run();   (a site's own runner: PeopleUpkeep's defer)

// The site's Backups ("Backups" below): a restore does again what was deleted since its backup, inside its
// transaction (beforeCommit), and scrubs once it committed (afterRestore) - 0.5.0.
$backups = new Backups($connect, $directory, [
    ...$people->restoreHooks(delete: $deleteHook),                              // defer:, then: - the site's runner, its own work
    /* … */
]);
```

- `PeopleTable` - the site's table and columns: `name` (genshin `username`, the
  build analyzer `name`), `firstSeen`, `lastSeen` (null where people have none),
  `updatedAt`, `nameFallback` (a NOT NULL name and a token without one), read
  from the catalog: a name clipped to the column, a TIMESTAMP written as UTC.
- `PeopleRecorder::seen($pdo, $person, $extra)` - one statement: the row made on
  a first request, the name kept to the token's (a token without one keeps the
  stored name), last-seen at most every five minutes, the site's own columns
  (`$extra`; a NULL keeps them); never an anonymized row, never a row for an id
  the IAM listed. `Http\RecordPerson` calls it for the routes a site names; a
  site that needs the row inside its own transaction or lock calls it there.
- `Scrubber::run()` - asks the IAM's `GET /api/public/anonymized-accounts` from
  `since` (inclusive), following `next` within the run (at most 8 s); each
  account in a transaction of its own: listed in `anotoki_people_anonymized`, its
  row held `FOR UPDATE`, the name `deleted-{sub}` and `anonymized_at` the IAM's
  time - only UPDATEs, never a visitor's row; where a UNIQUE name column has
  `deleted-{sub}` taken already (somebody chose it), the IAM's free form
  `deleted-{sub}-{eight hex digits}`, so a chosen name never stops the scrub -
  then the site's hooks with the row
  as it was (`['name', 'anonymized_at']`, null when the site never knew the
  person). A hook that throws rolls its account back and stops the run (the
  error is kept for the People page); an IAM that does not answer keeps `since`;
  one run at a time; idle until the column and the tables are there. Hooks must
  be idempotent - an id comes again.
- `Http\PeopleUpkeep` - the scrub after the answer (`Support\AfterResponse`, or
  the site's own runner as `defer`), at most hourly (the stamp
  `{cache_dir}/anotoki-people.stamp`; one later than now counts as none), while
  the site is ready; no cron.
- `Http\PeopleRoutes::admin()` - `GET $base?q=&page=` (50 a page, newest seen
  first; `{people: [{sub, name, first_seen_at, last_seen_at, anonymized_at,
  iam_url, …columns}], columns, total, page, page_size, visitors, sync, ready,
  problems}`; `q` finds a name or an id - 422 `invalid_query` for one that is
  not UTF-8 or holds a NUL, or is longer than any name), `DELETE $base/{sub}`
  (the site's hook; 404 `not_found`), `POST $base/sync` (the scrub now).
  `iam_url` is the account in the IAM's admin panel, for its administrators.
  **The group guards itself**: the site's ADMIN, from the token
  (`RequireRole::admin()` inside it, behind the site's `Identify`) - a site that
  forgets its own check, or mounts the group inside one that lets EDITORs in,
  opens nothing; a site's own check on the returned group still answers first,
  in its words. With `audit:` (the site's `AuditLog`) a delete is recorded
  `person.data_deleted` (target `user` {sub}, the counts by label) and an
  asked-for scrub `people.synced` (its state, handled, scrubbed), by the
  token's sub - or `context:`'s `actorId()`. The delete's entry is written
  inside the delete's own transaction (`AuditLog::recordInside()`): a restore
  does the delete again by it, so no delete is made without it - one that
  cannot be written undoes the delete, and while the audit log's table waits
  for its update the route answers `503 feature_unavailable` (and
  `Retry-After: 60`) and deletes nothing; another `AuditRecorder` records
  after the delete, as before. A delete asks for no proof of a recent sign-in,
  unlike a backup's restore: the page's question is all it asks (the owner's
  decision of 2026-10-11).
- `People::delete($pdo, $sub, $hook, $request, $entry)` (0.5.0) - the page's
  delete: the site's hook and, where the site has the admin tools' feedback,
  that person's messages without the address and browser they were sent with
  (`Feedback::forgetPeopleOn()`; the words stay), and the route's `$entry`
  (its audit entry; a replay writes none), in one transaction of the library's
  - or, inside a caller's (a restore's), behind a savepoint of its own, so a
  hook that fails takes back its own work and nothing else. The hook does ALL
  its work on the `$pdo` it is given - never the site's own connection, which
  in a restore waits on the restore's locks while the restore waits on the
  hook - and begins, commits or rolls back no transaction of its own (a
  savepoint is fine): one that begins one is told so, and one that ends the
  transaction it was given - a commit and a new BEGIN would pass for it still
  open - is found by the savepoint being gone.
- `People::restoreHooks(delete:, defer:, then:)` (0.5.0) - both of a restore's
  hooks for the site's Backups, spread among its options: `replay()` as
  `beforeCommit`, `afterRestore()` as `afterRestore`.
- `People::replay(delete:)` - for Backups' `beforeCommit`: a restore puts the
  data back as it was when the backup was made, and the audit log, never
  restored, says what was done since - from the backup's `snapshot_at` on (the
  database's clock, which writes the audit log too; a backup of before 0.5.0
  has its `created_at` alone, PHP's). Inside the restore's transaction, before
  anybody sees its rows - so a request that dies after the commit leaves
  nothing undone, and no backup is made of the rows without it - every
  `person.data_deleted` is done again (`People::delete()` with the site's hook,
  its request null) and every `feedback.deleted` where it is the same message:
  its id and the time it was sent (an entry of before 0.5.0 names no time and is
  passed over). A `person.data_deleted` or a `feedback.deleted` written inside
  its delete's transaction names that transaction (`_txid`), and its time is
  when the transaction began - just before the backup's snapshot, it may have
  committed after it: such an entry, dated within the hour before the backup's
  time, is done again when its transaction is not in the backup's snapshot
  (`txid_visible_in_snapshot()`, the manifest's `txid_snapshot`); every entry
  from the snapshot's time on is done again, as before. Further back the id is
  not asked: an id is one PostgreSQL cluster's, and after a move by `pg_dump`
  every older entry would seem unseen - its delete done again on whatever was
  kept here since. Entries and
  backups of before go by the time alone. No check of the IAM's deleted
  accounts may run across a restore - one begun before it would keep its own
  place in the IAM's list over the restored one: the restore takes the check's
  lock until it commits, and is refused while a check holds it ("restore again
  in a minute"). Whatever fails rolls the restore back whole, as does - with
  something to do again - a backup that says no time, one whose snapshot cannot
  be read, or a database without the audit log.
- `People::afterRestore(defer:, then:)` - for Backups' `afterRestore`, once the
  restore committed: the scrub of the IAM's deleted accounts at once (the
  restore brought back the scrub's place of that day). One that does not go
  through - the IAM out of reach, a failure of any kind - is thrown, with the
  site's own failure, in one sentence: the restore's `after_restore_error`
  (the first failure that threw chained: the error log's line shows it as
  `Caused by …` with its frames); the upkeep's hourly stamp then goes after the
  answer (`defer`, the site's runner), so the next request scrubs again.
  `then:` is the site's own work after a restore (Piano Academy's new
  generations), run whatever the scrub did. The survey's, Piano Academy's and
  genshin's hand-written versions of all this go.

### The People page: `@anotoki/lib/people` and `@anotoki/lib/people/page`

```ts
// app.config.ts (or the page's route's providers) - a token and a function: @anotoki/lib/people
provideAnotokiPeople(() => {
  const auth = inject(AnotokiAuth);
  return {
    me: () => auth.sub(),                              // "you" on the reader's own row
    lead: 'Everybody who signed in and whom Piano Academy keeps anything of, the most recently seen first.',
    delete: {                                          // only where the site's PeopleRoutes has its delete hook
      label: 'Delete their progress',
      // question: (person, columns) => ({ title, message, confirm }),   the family's names the counts
      // allowed: (person) => person['accounts'] !== 0 || person['imports'] !== 0,
      done: (person) => person.sub === auth.sub() && inject(ProgressStore).reload(),
    },
    visitorsLine: (visitors) => `${visitors.count} visitors, practising without signing in.`,
    migrationsRoute: '/admin/migrations',
    // apiBase: '/api/admin/people', isAdmin: () => auth.isAdmin(), formatDate: (iso) => …
  };
}),

// the admin routes: the page's own entry point, never in the first load
{ path: 'people', canActivate: [adminGuard], loadComponent: () => import('@anotoki/lib/people/page').then((m) => m.PeoplePageComponent) }
```

`<anotoki-people-page>` - English, for the site's ADMIN (`isAdmin`; nothing is
asked of the server for anybody else), over the routes above - the union of what
Piano Academy's, Japanese Academy's and the build analyzer's pages showed and did:

- a search by name (any case, anywhere in it) or IAM id, as the typing rests or
  at Enter (only the latest answer counts), and paging, 50 a page, the newest
  seen first;
- each person: the name - "you" on the reader's own row, "No name" where the
  site never learned one - and under it what can be done, never off the screen
  however many columns the site has: "Open at the IAM" (`iam_url`, a new tab; a
  note says it is for the IAM's administrators) and, with `delete`, Delete in
  `danger-text`; the IAM id; first and last seen (a time no person on the page
  has is not drawn: the build analyzer has no last-seen); the site's
  `PeopleColumn`s - a count right-aligned (none: 0), text, a time; none: a dash,
  "none" to a screen reader;
- an account the IAM deleted: muted, under the IAM's placeholder name, "Deleted
  at the IAM on {day}";
- where the scrub stands (`sync`): when the IAM's deleted accounts were last
  checked and how many were scrubbed here, or why the last check stopped (in
  `danger-text`), with Check now (`POST sync`: what it did - scrubbed, none, part
  of the list, the IAM not answering, another check running - then the list again);
- Delete behind a question in the page's own dialog (the focus on Cancel; the
  site's `question`, or the family's, which names the counts and "This is you."),
  what went said with the site's labels and its `note`, the site's `done` after
  it, then the list again (a 404: "not here any more"); `allowed` disables it
  where there is nothing to delete;
- the visitors line (`visitorsLine`, or the family's), the database's
  `problems` with the way to `migrationsRoute`, a 503 (no table yet) with Look
  again, any other failure with Try again.

A table that answers to the width it is given (a container query): in a page
narrower than 640 px each person is a card - the name and what can be done, then
each value under its label. The page's classes are its own,
`anotoki-people-page__*`; its markers `[data-people]`, `[data-person="<sub>"]`,
`[data-iam]`, `[data-delete]`, `[data-confirm]` (`[data-confirm-cancel]`,
`[data-confirm-delete]`), `[data-sync]`, `[data-sync-now]`, `[data-outcome]`,
`[data-found]`, `[data-visitors]`. It replaces Piano Academy's
`pages/admin/people/*`, Japanese Academy's `admin/people/*` and the build
analyzer's `pages/admin/people/*` with their API glue; the survey and genshin may
take it with one route.

### Adopting auth and people, site by site

In one release per site: `anotoki/lib ^0.4.1` and the 0.4.1 tarball; the site's
migration adding `users.anonymized_at`, then `People\Schema::migrationSet()` in
its sets (after `Translations\Schema::migrationSet()` where it has it); the
vendored SDKs deleted; the wiring above; its sign-in words' keys
(`anotoki.signIn.*`) taken over where it rewords them. Existing sign-ins
survive: the storage keys are the same.

| Site | Mapping | Scrub hooks | Deleted | Notes |
| --- | --- | --- | --- | --- |
| survey | `preferred_username`, `first_seen_at`, `last_seen_at` | - | `api/anotoki_iam.php`, `core/auth/anotoki-auth.*`, `tests/tools/fake-iam.php`; `requestUser`/`usersSeen`/`requireUser`/`requireRole`/`requireTokenRole`/`requireTaker` | its migration also drops `users.email` once the results, the response page and the CSV stop showing it (the CHECK `users_visitor_check` rewritten without it); `api: { skip: [..., '/api/images/'], visitorPrefixes: ['/api/surveys'] }` |
| piano | `preferred_username`, `created_at`, `last_seen_at` | - | `api/anotoki_iam.php`, `core/auth/anotoki-auth.*`, its fake IAM; `requestUser`/`usersSeen`/`requireOwner` | the Migrations routes take `RequireRole::admin()` - `users` is no longer written there; `peopleDelete()` is the delete hook (under `storeLockGeneration()`), its counts the columns |
| japanese | `preferred_username`, `created_at`, `last_seen_at`; `extra: time_zone` | `exam_attempts.candidate` → `''`, `meta - 'candidate'`, the report's header → "Unnamed candidate" | `src/anotoki_iam.php`, `core/auth/sdk/*`, `tests/tools/stand-in-iam.php`, the SDK version-pin test | `SignInRule(requiredWhen: fn () => !visitors_ready())`; `RequireRole` with `admin_only` / `staff_only`; `api: { prefixes: ['/api/', '/report/'] }`, `headers: X-Time-Zone`; its 008 is a draft - the people set waits behind it |
| build analyzer | `name`, `created_at`, no last-seen (or `last_seen_at`: its CHECK allows it), `updatedAt: 'updated_at'`, `nameFallback: "user $sub"` | `sync_runs.log`: "by {former}" → "by a deleted person" | `src/Support/Iam/*`, `SignInPolicy`, `IamSignInPolicy`, `UserProvisioner`, `core/iam/*`, its fake IAM | `/api/meta` carries `AuthRoutes::configBody()`; a bad token now answers `invalid_token`; the hint acts here; `robots.txt` `Disallow: /auth/` |
| genshin | `username`, `created_at`, no last-seen, `nameFallback: "user$sub"` | `feedback`: `username` → `deleted-{sub}`, `email`, `ip`, `user_agent` → NULL (the words stay) | `api/anotoki_iam.php`, `shared/local-lib/iam/*` | its migration is `users/050_people.sql`; `users.email` and `feedback.email` go once nothing reads them; `SignInGate` with its open prefixes; the library's routes in `SKIP_PREFIXES`; the hint acts here; `robots.txt` `Disallow: /auth/` |

## Admin tools (0.4.0)

Five tools every site's admin panel shares, for the site's **ADMIN alone**
(EDITORs see none of them - the owner's decision of 2026-10-07). The library
decides nobody's permissions, and mounts no admin route unguarded: every
`*Routes::admin()` group asks the site's own ADMIN check, given once in its
`AdminContext` (`isAdmin`), before anything else - `401 sign_in_required` for
nobody, `403 forbidden` for anybody else - and a context without one mounts
none (a `LogicException` while the site builds its app). The site still adds
its own middleware to the group it gets back, which runs first (the IAM:
`requireSession()` + `requireAdmin()`, and its CSRF header on every write, as
on every first-party write). The groups may sit inside the site's own groups,
under its base path: every link the server hands out names where its route is.
Each tool has its page in the Angular half, an entry point of its own on a lazy
admin route, in English as every admin page ("The pages" below); the visitors'
side of feedback is a form in their language ("The visitors' feedback form").

Two routes are **deliberately outside** every admin check, a navigation and an
`<img>` sending no Bearer header: the backups' download (`BackupsRoutes::file()`
- its single-use ticket is the check) and the file explorer's previews
(`FilesRoutes::raw()` - its signed five-minute link is the check). A site whose
sign-in rides on a cookie (the IAM) may mount `file()` inside its own session
check and pass `samePerson: true`: the ticket then opens the file for the person
it was issued to alone (the ticket's `by`).

| tool | namespace | kept in | sites |
| --- | --- | --- | --- |
| error log | `Anotoki\Lib\ErrorLog` | files, `storage/logs/errors-*.jsonl` | every site |
| audit log | `Anotoki\Lib\Audit` | table `anotoki_audit_log` | every site |
| feedback | `Anotoki\Lib\Feedback` | table `anotoki_feedback` | genshin, piano, Japanese, build analyzer |
| backups | `Anotoki\Lib\Backups` | files, `storage/backups/*.anotoki-backup` | every site (the IAM's encrypted) |
| file explorer | `Anotoki\Lib\Files` | the site's folders; `storage/trash/` | genshin |

The error log and the backups need no table: they work while the database is
down or an update waits. Every folder the tools write under storage gets a
deny-all `.htaccess` and an empty `index.html` where it has none
(`Support\GuardedDirectory`). Every time they write is UTC, ISO 8601 with `Z`
on the wire, TIMESTAMPTZ in the tables.

### What a site gives once: `AdminContext`

```php
use Anotoki\Lib\Admin\AdminContext;

$context = new AdminContext(
    actorId: fn ($request) => $request->getAttribute('user')['id'] ?? null,   // from the session or the token, never a body
    isAdmin: fn ($request): bool => Identify::person($request)?->isAdmin() ?? false,  // the site's ADMIN; the IAM: its own entry's ADMIN
    proof: AdminContext::recentSignIn(fn ($request) => $request->getAttribute('claims')['auth_time'] ?? null),  // the sites: 10 minutes
    // the IAM: proof: fn ($request, array $body) => accountProof($pdo, $request, $user, $session, $body['password'] ?? null, 'backup'),
    //          proofKind: 'password' (or a closure: 'recent_sign_in' where the account has no password)
    trustedProxies: ClientAddress::CLOUDFLARE,                                // whose CF-Connecting-IP is believed (0.5.0)
    originSecret: $settings['origin_secret'] ?? null,                         // and only with the zone's X-Anotoki-Origin (0.5.2)
    names: fn (array $ids): array => usernamesOf($ids),                       // people's names by id, for the pages (0.5.0)
    // clientHeader: 'CF-Connecting-IP', clientKey: fn ($request) => ...,     a site's own reading of the address instead
    error: fn ($response, Refusal $refusal) => ...,                            // optional: the site's error body
);
```

The proof guards every backup made, deleted, downloaded and restored: the IAM's
`accountProof()` (its password, or a sign-in of the last 15 minutes; its
`array` answers are taken as they are), the sites' token `auth_time`
(`403 reauth_required`; the page offers "Sign in again" with `prompt: 'login'`).
A context without a proof refuses those (`proof_unavailable`).

Where a request comes from is one rule wherever the library reads it
(`Support\ClientAddress`, the owner's of 2026-10-11): the connection's
`REMOTE_ADDR`, or - only on a connection from one of `trustedProxies` - the
address its `clientHeader` names (Cloudflare's `CF-Connecting-IP`;
`ClientAddress::CLOUDFLARE` holds Cloudflare's published ranges). A header from
anybody else is never believed, and `X-Forwarded-For` never read. Cloudflare's
ranges are every Cloudflare customer's, not the owner's zone alone - anybody's
Worker can open a raw socket to the host from them and write the header it
likes: a site given an origin secret (`originSecret`, 0.5.2; the owner's
decision of 2026-10-13) believes the header only on a request that also
carries that secret in `X-Anotoki-Origin` (`ClientAddress::ORIGIN_HEADER`),
which a Transform Rule of the owner's zone adds to every request through it.
Without it such a request counts as the edge's address: coarser, never looser;
the secret is compared in constant time and never logged. An IPv4
address written as IPv6 (`::ffff:203.0.113.9`, from a server listening on `[::]`
for both) is read as the IPv4 address it is. The limits count an IPv4 address
whole and an IPv6 address by its /64, and what is kept of it is a keyed HMAC
(the error log's `client`, the feedback's limits), never the address. Without `trustedProxies` the visitors behind Cloudflare share the
edge's bucket: stricter, never looser. `names` gives the people's names by id -
a backup's maker (`created_by_name`), the audit log's and the feedback's people
where their routes get no lookup of their own; an id it does not know has none,
and a lookup that fails gives none.

### The schema: the library's set `anotoki_admin`, FIRST

```php
use Anotoki\Lib\Admin\Schema;

$migrator = new Migrator($pdo, [Schema::migrationSet(), ...$siteSets, \Anotoki\Lib\Translations\Schema::migrationSet(), \Anotoki\Lib\People\Schema::migrationSet()], $options);
```

- `001_audit_log.sql` makes `anotoki_audit_log` (the IAM's columns plus
  `actor_session`, `scope_type`, `scope_id`), `002_feedback.sql` makes
  `anotoki_feedback`. Each proves the shape of a table of that name that is
  there already, ends with `-- end of NNN` (Japanese Academy's draft rule takes
  it), and names no site table. Released files are never edited.
- It runs **first** - in the site, its installer and its command line alike:
  the sites' own files move their old rows into its tables (the IAM's
  `audit_log`, genshin's `audit_logs` and `feedback`), and nothing else makes an
  `anotoki_*` table. It is recorded as `anotoki_admin/<file>` in a bookkeeping
  table without `folder`, as `folder = anotoki_admin` in genshin's.
- While a file of it waits, the modules ask `Schema::has()` (a `to_regclass`,
  never memoised) and do without: the audit log keeps entries in a pending file,
  feedback answers `503 feature_unavailable` (`Retry-After: 60`).
- A site's hooks see its files: genshin's `migrationsOrderRefusal()` must let a
  set before `users` through; the build analyzer's `beforeFile` and Japanese
  Academy's `upkeep_before_apply()` must not mind them.

### Error log

```php
use Anotoki\Lib\ErrorLog\ErrorLog;
use Anotoki\Lib\ErrorLog\ErrorLogMiddleware;
use Anotoki\Lib\ErrorLog\Http\ErrorLogRoutes;

$errorLog = new ErrorLog(storageDir() . '/logs', [
    'root' => dirname(__DIR__),        // file paths are written relative to it
    'context' => $context,             // the user id, and an HMAC of the address bucket
    'app' => $app,                     // the route's pattern in each line
    // 'key' => Keys::derive(appSecret(), 'error-log'),   default: the installation's key (storage/anotoki-lib.key)
    // 'responses' => fn (int $status) => $status >= 400, default: every 5xx and 404, 405, 413, 415, 429
    // 'expected' => [],                                  default: update_pending, feature_unavailable, not_set_up (0.5.0)
    // 'ignoreExceptions' => [ApiRefusal::class],         a site's decided answers: logged as their answer
]);
Redactor::addSecretNames('totp_secret', 'recovery_code');               // a site's own names of a secret, once (0.5.0)
$errorLog->installPhpHandlers();                                        // fatals (shutdown) and warnings (chained)
$errors = $app->addErrorMiddleware(false, true, true);
$errors->setDefaultErrorHandler($errorLog->wrapHandler($siteHandler));  // or ErrorLogMiddleware::inner() inside a site's own
$app->add(ErrorLogMiddleware::outer($errorLog));                        // added last: outside the error middleware
ErrorLogRoutes::admin($app, '/api/admin/errors', $errorLog, $context, $audit); // guarded by the context; ->add() the site's own
ErrorLogRoutes::client($app, '/api/client-errors', $errorLog, $context); // the pages' own errors (optional)
```

- One JSON line (`v: 1`) per event: `at` (UTC, ms), `source` (server, php,
  client), `level`, `status`, `type`, `code`, `message` (redacted, 500 at
  most), `file` (relative), `line`, `method`, `route`, `path` (redacted),
  `user_id`, `client` (12 hex of an HMAC of the address bucket - never an
  address), `trace` (12 lines at most: frames, never a call's arguments - and
  for an exception thrown for another, the chain: each cause's own line,
  `Caused by Type (file:line): message`, redacted and 500 characters at most,
  with its frames the one it caused does not show; at most 11 causes, each
  keeping its line, the frames shared out in turn), `fingerprint` (the
  exception's own, never its causes'). Never
  a query string, a header, a cookie, a body (an answer gives only its `code`
  and its sentence), a token or the session. Every file a line names is
  relative - an anonymous class's and a closure's too, whose names PHP makes of
  the absolute path. `user_id` is the context's `actorId` - on an admin tool's
  route, of the request the route saw (the site's own middleware on the group
  sets who asks inside the outer middleware), else the person `Identify` set.
- An answer whose code says a state, not a failure - `update_pending` (the gate
  while an update waits), `feature_unavailable` (a tool whose table waits),
  `not_set_up` - is not written (`expected`; `[]` writes them).
- `Support\Redactor`: PostgreSQL's DETAIL/HINT/CONTEXT/QUERY lines go whole,
  echoed values, e-mail and IP addresses, JWTs, Bearer credentials,
  `password=`-like secrets (their names quoted or not: `{"password":"…"}`,
  `'pass' => '…'`), query strings and token-like runs become marks; at most
  4,096 characters are read - the run of characters the cut went through goes
  with the rest (half a JWT, an address or a token is one no rule knows), and a
  quoted secret the text ends inside goes to its end - and a rule that cannot
  run gives nothing of the message. A site's own names of a secret go the same
  way once it names them (`Redactor::addSecretNames()`, while it starts) -
  never a message edited by reflection.
- A day's file goes into parts past 4 MB, days past 30 are deleted, and past
  64 MB in all the oldest days go first - today is never kept out by the days
  before it; only what today alone does not fit is counted instead of written
  (`dropped` in the report). One line per fingerprint per request, and at most
  `perFingerprintDay` (100) of one kind a day, the rest counted: a scanner's
  404s fill a hundred lines, not the budget. genshin's own lines are read as
  they were (their `at` in the server's zone, their `ip` shown).
- `GET $base` (`days`, `level`, `status`, `search`) answers genshin's report -
  `{total, errors, warnings, notices, distinct, truncated, days, daily,
  statuses, groups, dropped}`, the newest 2,000 lines grouped by fingerprint;
  `GET $base/{fingerprint}` every occurrence; `DELETE $base` clears it, and
  `DELETE $base?before=YYYY-MM-DD` the UTC days before that one (`{deleted}`,
  the files gone; audited `errors.cleared`). The log's own routes are never
  logged - known by their route as they run, and by the path they are mounted
  at (group, base path and all) for what fails before a route runs.
- The client route: `POST {type?, message, page?, stack?, status?}` -> 204:
  JSON only (`415`), 8 KB at most (`413`), a message (`422`); 20 an hour from
  one sender - a signed-in person by their account, a visitor by their address
  bucket - and 500 a day from the visitors in all, as many from the signed-in
  people (`429 too_many`, its wait in `retry_after` and `Retry-After`): each
  its own budget, so a few IPv6 /64s cannot use up the reports of the people
  signed in. Each redacted again, `source: client`. The browser's side is
  `@anotoki/lib/error-report` ("The browser's errors" below).

### Audit log

```php
use Anotoki\Lib\Audit\AuditContextMiddleware;
use Anotoki\Lib\Audit\AuditLog;
use Anotoki\Lib\Audit\Http\AuditRoutes;

$audit = new AuditLog(fn (): PDO => db(), [
    'pendingFile' => storageDir() . '/logs/audit-pending.jsonl',
    // 'recordAddress' => true, 'address' => fn ($request) => requestIp($request),   the IAM keeps the address
    //    (or 'trustedProxies' => ClientAddress::CLOUDFLARE in place of 'address': ClientAddress's rule,
    //     with 'originSecret' => the zone's secret from 0.5.2)
    // 'ready' => fn (PDO $pdo) => auditMovedToLibrary($pdo),                         the IAM: once its own move ran
    // 'actorIds' => fn (string $name): array => ...,                                 the page's actor filter by name
]);
$audit->record($request, $actorId, 'user.roles_set', 'user', $id, ['roles' => $roles]);
MigrationsRoutes::admin($app, '/api/admin/migrations', $migrator, function ($request, array $result) use ($audit): void {
    $audit->flushPending();
    $audit->record($request, $actorOf($request), 'migrations.applied', null, null, ['files' => $result['applied']]);
});
AuditRoutes::admin($app, '/api/admin/audit', $audit, $context, fn (array $ids): array => namesOf($ids));
```

- `record()` never throws; inside the caller's transaction it writes behind a
  savepoint, so a failed entry never undoes the change it records. Details are
  cut to 64 KB: the texts shorter and the lists to their first items, each cut
  saying so (`_cut`); what is too much even so keeps its keys - the shape of
  every map. While the table is missing (or `ready` says not yet) entries wait
  in the pending file and go in, with their own times, at `flushPending()` -
  after every Apply - or with the next entry written outside a transaction. A
  flush reads and empties the file under the lock its writers take too (an
  entry written meanwhile waits, and lands in it after), and never inserts an
  entry that is in already: one killed after its commit puts nothing in twice.
- `recordInside($pdo, $request, $actorId, $action, ...)` (0.5.0) is the other
  way round, for a change that must not be made without its entry (the People
  page's delete, which a restore does again by it): written on `$pdo` inside
  the change's own transaction, thrown when it cannot be written - never left
  in the pending file, while the table waits it throws - and naming that
  transaction in its details (`_txid`, whatever their cut): its `created_at`
  is when the transaction began, and a backup holds the change exactly when its
  snapshot sees that transaction.
- `AuditContextMiddleware::fromRequest($audit, fn ($request) => ['actor' => ..., 'session' => ...])`
  (inside the site's sign-in check) or `begin()`/`end()` hold who acts, so deep
  code records with `recordHere()`; `withScope('characters', 42, fn () => ...)`
  files every entry meanwhile under what it belongs to (genshin's
  `withAuditScope()`).
- `page()` (the IAM's filters - `actor` by id or name, `action` by its
  beginning, `target_type` - plus `target_id`, `scope_type`, `scope_id`,
  `from`, `to`), `facets()`, `countSince()`, `latest()`; `scrubPeople($ids)` -
  the IAM's rules: no address on a person's own entries or on the entries about
  them nobody else did, their names gone from the details about them.
- `GET $base` answers the IAM's `{total, page, pageSize: 50, items}` (its
  `AuditEntry` plus `actor_session`, `scope_type`, `scope_id`); `GET $base/facets`.
- The modules record their own actions through it (or any `AuditRecorder`):
  `errors.cleared`, `backup.created|deleted|downloaded|restored|restore_failed`,
  `feedback.status_changed|deleted`, `file.uploaded|moved|renamed|deleted|restored|trash_emptied`,
  `folder.created`.
- An entry that cannot be written is said to `onError` in a fixed sentence and
  a code (the SQLSTATE, or the failure's class) - never the entry, its details
  or its address, nor the database's words, which quote values.

### Feedback

```php
use Anotoki\Lib\Feedback\Feedback;
use Anotoki\Lib\Feedback\Http\FeedbackRoutes;

$feedback = new Feedback(fn (): PDO => db(), [
    'storage' => storageDir(),                       // required: the limits' count, the installation's key, the prune's stamp
    'sections' => ['Characters', 'Weapons'],         // the site's places, optional
    'fields' => ['steps' => 5000, 'expected' => 2000], // the site's extra fields, into details
    'defer' => afterResponse(...),                   // the prune after the answer
    // 'kinds' => ['bug', 'idea', 'other'], 'limits' => ['perHour' => 5, 'perDay' => 20, 'allPerDay' => 300],
    // 'keep' => ['spam' => 30, 'resolved' => 365], 'honeypot' => 'website', 'notify' => fn (array $message) => ...
]);
FeedbackRoutes::public($app, '/api/feedback', $feedback, $context, fn ($request): ?array => optionalSender($request));
FeedbackRoutes::admin($app, '/api/admin/feedback', $feedback, $context, $audit, fn (array $ids): array => namesOf($ids));
```

- The form: identity from the token only (`anonymous` drops the id and the
  address); the page as a path; the reply address only as the form sends it
  (the form writes the account's in, for the person to keep or change; the
  server never adds it), with none of `? & = % #`, a space or a control
  character (a mail link would read them as more than an address: `?bcc=`); a
  filled honeypot gets a kept message's answer word for word and keeps
  nothing; 64 KB a body; `201 {ok: true}` - no id. No mail: the panel's badge
  reads `GET $base/unread`.
- Nothing of where a message came from is kept with it - no address, no hash of
  one (the owner's decision). The limits count apart, in
  `storage/cache/anotoki-feedback-limits.json` (`0600`) under its lock: a
  signed-in sender by their account, a visitor by their address bucket - a
  keyed HMAC of each, and when - `perHour` and `perDay` for each, and
  `allPerDay` for the visitors together and, apart, for the people signed in
  together: a few IPv6 /64s cannot use up the messages of the people signed in,
  nor many accounts a day's worth each (0.5.0). A message "without my name" is
  counted as a visitor's, by its address bucket: nothing in the count ties it
  to the account. Never in the database, so never in a backup, each time gone a
  day later. A count that cannot be kept answers `503 storage_unavailable` - a
  failure the error log writes, where `feature_unavailable` (no table yet) is a
  state it leaves out.
- The admin side: `GET $base` (`kind`, `status`, `section`, `search`, `page`),
  `/facets`, `/unread`, `GET $base/{id}` (opening a new one makes it read),
  `PUT $base/{id}/status`, `DELETE $base/{id}` (both audited; a delete with
  the message's `created_at` beside its id, 0.5.0: a restore deletes it again
  by both, `People::replay()` - with the site's `AuditLog`, written inside the
  delete's own transaction, `Feedback::deleteWithEntry()`: no delete without
  it, `503 feature_unavailable` while the audit log's table waits).
  `forgetPeople($ids)` for an anonymized account; `prune()` daily.

### Backups

```php
use Anotoki\Lib\Backups\Backups;
use Anotoki\Lib\Backups\Copy;
use Anotoki\Lib\Backups\Http\BackupsRoutes;

$backups = new Backups(fn (): PDO => Copy::connect($dsn, $user, $password), storageDir() . '/backups', [
    'site' => 'survey',
    'migrator' => fn (PDO $pdo): Migrator => siteMigrator($pdo),   // the site's sets (the library's first), table and hooks
    'lock' => SITE_MIGRATION_LOCK,                                 // its Migrator's advisory lock, when it names its own
    'audit' => $audit,
    'defer' => afterResponse(...),
    'onError' => fn (Throwable $e) => $errorLog->record($e),
    ...$people->restoreHooks(delete: $deleteHook),                 // beforeCommit, afterRestore: what a restore does again ("People")
    // the IAM: 'encryption' => Keys::derive(iamMasterKey(), 'iam-backup'), 'restore' => false
    // 'afterRestore' => fn (array $manifest, PDO $pdo) => sweepAnonymizedAccountsSince($manifest['snapshot_at'] ?? $manifest['created_at']),
]);
$backups->dailyIfDue();                                                        // on requests: once a UTC day, after the answer
$migratorOptions['beforeApply'] = $backups->beforeApplyHook($siteOwnHook);    // a backup before every update
BackupsRoutes::admin($app, '/api/admin/backups', $backups, $context);
BackupsRoutes::file($app, '/api/admin/backups', $backups, $context);          // DELIBERATELY outside the admin check: the ticket
// the IAM may: BackupsRoutes::file($sessionGroup, ..., samePerson: true)    // inside its session check, for the ticket's person alone
```

- No pg_dump: COPY through PDO (`Pdo\Pgsql::copyToFile()` on PHP 8.4+ -
  open the connection with `Copy::connect()`; `PDO::pgsqlCopyToFile()` before,
  deprecated by 8.5), every table in one `REPEATABLE READ, READ ONLY`
  transaction, parents first (a circle of keys that cannot be deferred is
  refused by name), with the list and the SQL of the applied migrations. One
  backup or Apply at a time (a lock file and the migrations' advisory lock).
- COPY writes each table into a spool of its own in the run's working folder
  (`.tmp-<id>/`, `0700`, files `0600`) through the library's stream wrapper -
  pdo_pgsql opens COPY's file with PHP's streams - and a restore reads them
  back the same way. Where the site's backups are encrypted (the IAM's), a
  spool seals the rows in AES-256-GCM chunks as COPY hands them over, under a
  key that lives only in memory: the rows are never in the clear on the disk,
  not even a table at a time, and memory stays at a chunk. A working folder a
  timeout or a kill left behind goes once nothing in it changed for an hour
  (with the next backup or restore, and the daily run); the file explorer does
  the same with an upload left on its way in.
- The two files of known names - `.before-migration.json` (a hash of the
  pending files, and when their backup was made) and
  `cache/anotoki-backup-daily.json` - name no backup: a host that ignores
  `.htaccess` would hand both out. A site that encrypts reads no backup in the
  clear (one put there is refused), and `BackupsCommand` runs on the command
  line alone.
- `<id>.anotoki-backup` (`YYYYMMDD-HHMMSSZ-<kind>-<32 hex>`): `ANOTOKI-BACKUP 1`,
  a JSON header, then a gzip'd ustar of `manifest.json`, `schema/<set>/<file>.sql`
  and `data/NNN-<table>.copy` (COPY's text format) - opened anywhere by
  `tail -n +3 x.anotoki-backup | tar -xz` - or, with `encryption`, that body in
  1 MB AES-256-GCM chunks bound to the header, their index and the last one's
  flag (another key, a changed byte, swapped chunks, a cut tail: refused). A
  sidecar `<id>.anotoki-backup.json` feeds the list; a file without one is
  listed from its header.
- Only a backup made here is restored here (0.5.0): an encrypted one, under the
  site's key (its cipher is its seal: it carries no other), or a plain one whose
  sidecar carries this installation's seal - an HMAC of its id and SHA-256 under
  a key of its own derived from `storage/anotoki-lib.key`. The seal vouches for
  those two alone, so a restore hashes the very bytes it reads (the file opened
  once) and refuses any other, whatever else the sidecar says. A file dropped
  into the folder, or changed since, is refused (`422 archive_invalid`) and
  restored offline (`--extract`); one without a seal lists `sealed: false`. A
  plain backup made before 0.5.0 has none: offline too. What the seal stops is
  a file put into `storage/backups` by somebody who cannot replace the
  installation's key: a copy from another installation, a file changed or
  damaged since, whatever writes into the backups folder but never reaches the
  key (the file explorer reaches neither: it refuses a root that holds
  `anotoki-lib.key`, or is, holds or is inside the storage folder). Not
  somebody who can write the whole storage folder: deleting
  `storage/anotoki-lib.key` and making it again are a folder's operations - no
  read of the old key needed - and with a key of their own they seal what they
  like (as they can put a key set of their own in place of the IAM's, kept in
  the same folder, which tokens are checked with). The key stays there on
  purpose: one kept in the database would be lost with it - every restore
  refused in the very disaster a backup is for - and the survey, Piano Academy
  and Japanese Academy keep no secret outside `storage/`. Without that key (it
  can be neither read nor made) a plain backup is still made, unsealed, and the
  site's `onError` is told. Every file of the backups is the site's own user's
  alone (`0600`) from the moment it is made, every folder `0700`, as every
  folder the tools guard and the installation's key. Where PHP runs as the web
  server's own user (not the FTP account's, as under PHP-FPM or suEXEC), those
  modes keep the FTP account out of `storage/backups` too: to take a backup off
  the server for a restore offline, download it from the Backups page, or have
  the host's panel (or `chmod` from PHP's own user) open the folder first.
- Kinds and keeping: `daily` (14), `before-migration` (5, once per list of
  pending files; a backup that fails stops the Apply), `before-restore` (5),
  `manual` (until deleted; `stale` after 90 days). Only files of these names
  are ever deleted: whatever else is in the folder stays.
- Restore in place, all or nothing, only where the live database's applied
  migrations and columns equal the backup's (`409 schema_differs`): a
  `before-restore` backup first, then one transaction (locks within 5 s, user
  triggers off, one TRUNCATE, COPY back, sequences set, deferred keys checked,
  triggers on, rows counted, then the site's `beforeCommit` - what must be done
  again before anybody sees the rows: `People::replay()`, the deletes done
  since - and only then the commit). Whatever fails, the hook too, rolls it back
  whole (`500 restore_failed`, "nothing of it stayed"); a hook that ended the
  restore's transaction itself is said apart ("may have stayed"), the
  before-restore backup named. Nobody reads the restored rows, nor backs them
  up, before the hook ran: the tables stay locked, and the folder's lock holds
  every other backup off. A restore that waits more than a minute between two
  of its statements is ended by PostgreSQL
  (`idle_in_transaction_session_timeout`, set for its transaction alone) and
  rolled back with its connection - "the database ended its connection",
  nothing of it stayed: the restore itself never waits so long (COPY reads its
  spools inside its own statement), only a hook that waits on something else
  does - on another connection, which waits on the restore's own locks while
  every request to those tables waits behind both (no deadlock is seen: the
  restore's connection is idle). A hook does all its work on the PDO it is
  given. The site's `afterRestore` runs once it committed - what cannot be
  inside it, the scrub that asks the IAM - and what it throws is the answer's
  `after_restore_error`. A backup's manifest says when its snapshot was taken
  by the database's own clock (`snapshot_at`, 0.5.0) - the one the audit log is
  written by - beside PHP's `created_at`, and the transactions it sees
  (`txid_snapshot`, for `People::replay()`). A sequence never goes
  back: it goes on from the later of the backup's value and today's, so an id
  handed out since - one the kept audit log names - is never handed out again.
  Never restored: the bookkeeping table, the audit log and `keep`. `restore:
  false` refuses it (the IAM, until an `afterRestore` that ends every session
  and token and deletes again the accounts deleted since has been reviewed).
- Downloads: `POST $base/{id}/ticket` (the proof, audited) answers `{url,
  expires_in}` - the url where the request came through, group and base path
  and all; `ticketSeconds`, 60 - the page navigates to it; the ticket is 32
  random bytes, kept as its hash with who it was issued to (`by`), good once,
  for one backup, for its seconds; the file comes as an attachment,
  `no-store`, `nosniff`, `no-referrer`. A `HEAD` (a link checker, a mail
  scanner) looks at the ticket without spending it: only the `GET` does.
- `GET $base` `{backups, status}` (each backup's maker by name where the
  context names people, `created_by_name`; space, last daily, encryption,
  whether a restore can run here), `GET $base/proof` `{kind}`, `POST $base`,
  `GET|DELETE $base/{id}`, `GET $base/{id}/preview` (the rows a restore would
  take away), `POST $base/{id}/restore` `{confirm: the id's last 6, password?}`.
- The command line - `Backups\Cli\BackupsCommand` in a site's `backup.php`
  (never uploaded): `--list`, `--create`, `--inspect=<id>` (its manifest
  alone, read from the archive's first entry: nothing written to the disk),
  `--extract=<id> <folder>` (decrypted and checked, with the order to load it
  into another database: the schema files, then `psql \copy`), `--restore=<id>
  --confirm=<last 6>`.

### File explorer

```php
use Anotoki\Lib\Files\FileExplorer;
use Anotoki\Lib\Files\FileRoot;
use Anotoki\Lib\Files\Http\FilesRoutes;

$explorer = new FileExplorer([
    new FileRoot('assets', _assetsRoot(), label: 'Assets', write: true, extensions: UPLOAD_ALLOWED_EXTENSIONS, maxBytes: 50 * 1048576, snapshot: true),
], [
    'storage' => storageDir(),
    'afterWrite' => fn (string $root, string $op, string $path, ?string $from) => keepTheCatalogueInStep($op, $path, $from),
]);
FilesRoutes::admin($app, '/api/admin/files', $explorer, $context, $audit);
FilesRoutes::raw($app, '/api/admin/files/raw', $explorer, $context);          // DELIBERATELY outside the admin check: signed links
```

- Roots: the site's data, never its code or its secrets - a root that holds the
  site's `*.local.php`, a `vendor/` or `anotoki-lib.key` (in itself or a folder
  of it), or that is, holds or is inside the storage folder, is refused when the
  site builds it.
- Paths (`PathGuard`): relative, `/`-separated, NFC; no NUL or control
  characters, `\`, `:`, leading `/`, `.`/`..`, dot-names, trailing dots or
  spaces, Windows' device names whatever their extension (`CON`, `nul.png`,
  `COM1.txt`, `LPT9`, ...); every existing part must be no link and resolve to
  itself inside the root's real path plus a separator (symbolic links, Windows
  junctions, short names and a sibling `assets-old/` all fail).
- Names and bytes (`UploadPolicy`, genshin's rules): one extension per name,
  from the root's list, every other dot a dash; a name with any script,
  server-configuration or browser-markup part refused (`shell.php.png`,
  `.htaccess`, `.user.ini`, `x.svg.png`); the bytes must be what the extension
  says (an MP4, M4A or AVIF's first box a real `ftyp` box's size); no file
  begins with markup after a byte order mark and white space, no text carries
  `<` before a letter, `!` or `?` in its first kilobyte, no picture or sound a
  page's tags there (its XMP metadata stays); a renamed file's new extension is
  checked the same way.
- The guard: a written root gets an `.htaccess` before its first write -
  `RemoveHandler` and `RemoveType` for the script extensions, `AddType` for
  every extension the explorer takes (text as `text/plain`), and nosniff inside
  `<IfModule mod_headers.c>`; never `php_flag`, `Options` or `Require` (each can
  answer 500 for every file of the folder where AllowOverride does not allow
  it) - never over the site's own.
- `GET $base/roots`; `GET $base/{root}` (`path`, `search`, `page`, `mode=new`);
  `POST $base/{root}/rescan`, `/upload` (multipart: `path`, `replace`, the
  files), `/folder`, `/move`; `DELETE $base/{root}?path=` (to
  `storage/trash/<root>/`); `GET|DELETE $base/{root}/trash`,
  `POST $base/{root}/trash/restore`. Previews: links signed for five minutes;
  inline only raster pictures, sound, video and text up to 64 KB, everything
  else (SVG among it) a download; CSP `sandbox`, `nosniff`, same-origin,
  `no-store`, byte ranges. A link alone reads one file of a root - no session
  asked, nothing written - and is signed under the installation's key unless
  the explorer is given `key:`: whoever can write the storage folder can make
  that key their own and sign links (as they can sign in as anybody through the
  IAM's key set kept there). A site with a secret outside `storage/` passes
  `'key' => Keys::derive($itsSecret, 'file-preview')`.

### The pages (Angular)

```ts
// app.config.ts
provideAnotokiAdmin(() => {
  const auth = inject(AnotokiAuth);                         // the factory runs in an injection context
  return {
    isAdmin: () => auth.isAdmin(),                          // anybody else is told whose the page is, and nothing is asked
    signInAgain: () => auth.signIn({ prompt: 'login' }),    // the sites' proof before a backup is made, downloaded, deleted or restored
    personLink: (id) => ['/admin/people', id],              // a person the logs name, as a link (optional)
    migrationsRoute: '/admin/migrations',                   // offered while a tool's table waits for an update
    // apiBase: '/api/admin', paths: { files: '/api/admin/explorer' }, resolveUrl: (path) => apiOrigin + path,
    // siteName: 'the IAM', failureText: (failure) => adminErrorText(failure), formatDate: (iso) => ...,
    // feedbackFields: { steps: 'Steps to reproduce' }, restoreOffText: '...',
  };
}),

// the admin panel's routes, under its ADMIN guard
{ path: 'errors', loadComponent: () => import('@anotoki/lib/admin/errors').then((m) => m.ErrorLogPageComponent) },
{ path: 'audit', loadComponent: () => import('@anotoki/lib/admin/audit').then((m) => m.AuditLogPageComponent) },
{ path: 'feedback', loadComponent: () => import('@anotoki/lib/admin/feedback').then((m) => m.FeedbackPageComponent) },
{ path: 'backups', loadComponent: () => import('@anotoki/lib/admin/backups').then((m) => m.BackupsPageComponent) },
{ path: 'files', loadComponent: () => import('@anotoki/lib/admin/files').then((m) => m.FilesPageComponent) },

// the admin navigation: the new feedback beside "Feedback"
protected readonly unread = inject(FeedbackUnread);       // void this.unread.refresh() once; the Feedback page keeps it in step
{ id: 'feedback', label: 'Feedback', path: '/admin/feedback', badge: this.unread.count() || null }
```

- Each page is drawn with the kit's pieces, in whatever frame the site gives it:
  it answers to its own width, not the window's (a side navigation beside it is
  fine), and at 390 px a table becomes a card a row, each cell saying its
  column. English, as every admin page (the translations module keeps `/admin`
  English). The site's ADMIN alone: for anybody else a page says whose it is and
  asks the server nothing. The requests go through the site's `HttpClient`, so
  its interceptors apply (the auth module's Bearer, the IAM's CSRF header); a
  refusal is read whatever the site's error body is (the library's `{code,
  message}`, the IAM's `{error, code}`) and said in the page's words, or the
  site's (`failureText`).
- **Error log**: the range (today, 7, 14 or 30 days), the level, the status and
  a search; the counts and a chart by day (its numbers in words for screen
  readers); the failures grouped by fingerprint, 20 a page, a row opening its
  details - the facts, its path and route each a search, the trace (copied in
  one press), every occurrence. "Clear" asks what goes: everything before today,
  everything older than a week, or everything.
- **Audit log**: who, the action (or a family of them, `backup.*`), what it is
  about and its id, a date range - kept in the address, so a link to a filtered
  log works; the choices the server's facets give; 50 a page; an entry opened
  shows each field before and after (genshin's `{field: {old, new}}`, `{before,
  after}` and `{from, to}` read alike), the session, the address where the site
  keeps it, the details whole. While the table waits for an update: "Apply the
  pending database update first", and the Migrations page.
- **Feedback**: by status (new, read, resolved, spam, each counted), kind,
  section and a search; a message opens in a dialog (the server marks it read):
  the visitor's words as text, never markup, the site's extra fields
  (`feedbackFields` names them), who sent it (`personLink`), where to answer,
  the page, the language, the browser; "Mark as" saves at once; Delete asks
  first. `FeedbackUnread` follows every change.
- **Backups**: how many and how much, the last daily, encryption, and why a
  backup or a restore cannot run here (the folder not writable, the server's PHP
  without COPY, restoring switched off - `restoreOffText` adds the site's
  reason); the list with each backup's kind, size, tables and maker (by name
  where the context's `names` gives one; a backup not made here says why its
  Restore is off). Back up
  now, Download (a single-use link, opened by the page) and Delete are each
  behind the site's proof: the IAM's password, asked in a dialog that says a
  wrong one and too many; the sites' recent sign-in, whose refusal offers "Sign
  in again" (`signInAgain`). Restore says what it would take away (the rows now
  and after), wants the backup's last six characters typed and the proof, and
  waits 30 seconds before it can be pressed; a restore whose answer was lost (a
  proxy's 502, 504 or 524, the network) is said to be maybe still running, and
  the list is read again.
- **File explorer**: the roots as tabs, the folder as a path of links, a search,
  a grid or a list; uploading by the button or by dropping files, with a
  progress bar - what the root does not take (its extensions, its size) refused
  before it is sent, the server's refusals said file by file; a new folder;
  rename and move; delete into the trash after a question, with an Undo, and
  the trash (put back, empty); a preview from the signed link - a picture, sound
  or video in its own element, text as text, everything else a download.

### The visitors' feedback form (Angular)

```ts
// app.config.ts - beside the translations module; it registers the form's words (anotoki.feedback.*)
provideAnotokiFeedback(() => {
  const i18n = inject(TranslationService), auth = inject(AnotokiAuth);
  return {
    language: () => i18n.language(),                       // the form's words, and the message kept with it ('en' and 'sk' built in)
    translate: (key) => i18n.libraryWord(key),             // an owner's rewording in the site's database
    sections: () => [{ value: 'Characters', label: i18n.t('nav.characters') }],
    fields: () => [{ name: 'steps', label: i18n.t('feedback.steps'), kinds: ['bug'], max: 5000 }],
    signedIn: () => auth.signedIn(),                       // "send it without my name" is offered
    email: () => auth.user()?.email ?? null,               // written in until they type their own
    // endpoint: '/api/feedback', kinds: () => [...], subjectRequiredFor: ['bug'], words: { de: {...} }, honeypot: 'website',
  };
}),

// where it is opened - loaded then, so no first load carries the kit's fields
const { FeedbackFormComponent } = await import('@anotoki/lib/feedback/form');
// <anotoki-feedback-form [heading]="null" closable (sent)="..." (closed)="close()" />
```

- The kinds, a section, a subject, the message, the site's extra fields for the
  kind; checked as the server checks them before it is sent (a message, the
  lengths, an address) and every refusal said kindly, in the visitor's language:
  too many (within the hour, or today, from `retry_after`), not taking messages
  now (`503`), too long (the field and its most), the network. The robots' field
  (`honeypot`) is off the screen, out of the tab order and hidden from screen
  readers. Sent, the thanks take the focus, with "Send another".
- Its words: the site's database's (`translate`), then the site's own (`words`),
  then the built-in English and informal Slovak (`feedbackWords()`); a language
  with none of them reads English, and the form says `lang="en"`.

### The browser's errors (Angular, 0.5.0)

The owner's decision of 2026-10-11: every site's error log holds its pages'
errors too.

```ts
// app.config.ts - every site, beside its HttpClient
provideHttpClient(withInterceptors([...theSitesOwn])),
provideBrowserGlobalErrorListeners(),      // Angular's default since 20: window's errors and promises nobody caught too
provideAnotokiErrorReport(),               // { endpoint: '/api/client-errors', perPage: 5, report: (error) => true }
```

- `AnotokiErrorReporter`, the app's `ErrorHandler`: every error goes to the
  console as Angular's own has it, and is reported to `ErrorLogRoutes::client()`
  through the app's `HttpClient` - the site's interceptors add what its writes
  carry (the IAM's `X-IAM-Request`), never a way around them. The request
  carries `ANOTOKI_ERROR_REPORT` in its context: an interceptor skips its error
  handling for it (no sign-in redirect for a report) - never its headers, which
  are what let the report in.
- Scrubbed before it leaves the page (`describeError()`, `scrubText()`): the
  page's path alone, a segment that looks like an address or a code replaced;
  never a query or a fragment (a frame keeps its line and column); no value the
  page keeps in localStorage, sessionStorage or a cookie (`storedValues()`); no
  token, `password=`-like secret or address. A value thrown that is no Error is
  named by its kind, never written out. A message, a frame and the path are cut
  to what a report keeps before they are scrubbed, so a megabyte of them costs
  no more than a line - and lose the run the cut went through (the path, its
  segment): half a token, an address or a stored value is one no rule knows,
  and a quoted secret the text ends inside goes to its end. The server redacts
  it all again, by the same rules.
- At most `perPage` reports a page load, each error once; never an HTTP failure
  (the server writes its own, and a visitor's network is not the site's), never
  an error raised while a report is made, never the report's own failure; a
  `429` ends reporting for the page load. Sent after the error is handled: it
  never blocks the page, and never throws.

### What else a site adds

- **Its gate's open paths** (a site that blocks while an update waits - every
  site but the IAM): the error log and the backups need no table, and a backup
  just before Apply is the point - restore stays closed:
  `[...ErrorLogRoutes::openPaths(), ...BackupsRoutes::openPaths(), '~^/api/admin/migrations(/file|/apply)?$~D']`
  (`'~^/api/admin/errors(/[0-9a-f]{12})?$~D'`,
  `'~^/api/admin/backups(/proof|/[A-Za-z0-9-]{20,80}(/(ticket|file|preview))?)?$~D'`).
  Their ADMIN check must judge by the token alone, as the migrations routes'.
- **The runner after the answer**: a site with one passes it as `defer`; the IAM,
  which has none, takes `Support\AfterResponse` - `AfterResponse::shared()->defer(...)`
  while answering, `->run()` after `$app->run()` (PHP-FPM and LiteSpeed close the
  connection first; each piece on its own, a failure said in the log or to the
  site's `$onError`; work a piece queues runs too). The same runner carries the
  people's scrub (`PeopleUpkeep`); one that is never run goes at the end of the
  request all the same.
- `Support\Keys`: `derive($secret, $purpose)` (HKDF-SHA256, `anotoki-lib/<purpose>/v1`);
  `installation($storage)` - 32 random bytes in `storage/anotoki-lib.key` for a
  site without a secret, its folder guarded; made once, written whole and the
  site's own user's alone from its creation (`tempnam()`), then linked into
  place (`link()`: of two processes making it at one moment, one key stays and
  both read it). Without `link()` (a host's `disable_functions`), the name is
  claimed with an empty file only one process can make (`fopen('x')`), and only
  the claimant renames its key over it - the others wait while it is empty, and
  a claim its maker left goes once it is 10 seconds old. It keys the error
  log's client hashes, the feedback limits' buckets, the file explorer's
  preview links and the backups' seal, and is as safe as the storage folder,
  no safer: whoever can write that folder can delete the key and make one of
  their own - as they can the IAM's key set kept there, which tokens are
  checked with. It keeps out whoever cannot.
- **genshin** moves its `audit_logs` and `feedback` rows into the library's
  tables in its own migrations (gated, the old tables dropped by a later one) and
  drops `ip` and `submitter_hash` in the move; **the IAM** moves `audit_log`
  (017, keeping ids) with `AuditLog`'s `ready` holding entries back until 017
  ran.

## Working on the library

```
# PHP (from the repository root; on this family's Windows machines `php8`)
php composer.phar install
ANOTOKI_LIB_TEST_DB_CONFIG=../anotoki-iam/backend/config/database.local.php vendor/bin/phpunit
#   or ANOTOKI_LIB_TEST_PGHOST / _PGPORT / _PGUSER / _PGPASSWORD; the user needs CREATEDB:
#   each test case makes a database anotoki_lib_test_<random> and drops it; the auth tests
#   start the stand-in IAM under PHP's built-in server on a free local port (needs openssl, curl)
#   with the same PHP binary: give a PHP whose extensions come from its own php.ini through
#   PHPRC=<its folder>, never -c (a child process does not get -c, and its server would have none).
#   CI runs the tests on PHP 8.2 too: write no newer syntax, and run them on 8.2 before a release.

# Angular (from angular/)
bun install
bun run build           # ng build lib -> angular/dist/lib, then scripts/check-package.mjs: each entry point's
                        # size (gzipped code; limits for the eager ones) and import boundaries; what a site's
                        # build keeps of an entry point nothing is used of (nothing); no bundle naming innerHTML,
                        # outerHTML, insertAdjacentHTML or bypassSecurityTrust, comments too (the IAM's deploy
                        # check refuses such a release); and seven first loads - the gate, the bar, the words, a
                        # button, the sign-in, the badge, the words + feedback - beside lazy pages that use the
                        # rest (no file of theirs, under a limit), each file through Angular's own linker and the
                        # CLI's esbuild
bun run test            # ng test lib --watch=false (Vitest, jsdom)
bun run showcase        # projects/showcase on http://localhost:4320: every piece and the bar, from the source
                        # (?theme=dark, ?lang=sk, ?account=1 for a refused theme, ?frame=88rem for the frame's
                        # maxWidth; /migrations, /translations, /languages (?editor=1: no delete or import),
                        # /people (?people=unready, ?people=down), the admin tools' /admin/errors, /audit,
                        # /feedback, /backups, /files and the visitors' /feedback against an imagined server -
                        # ?visitor=1 for anybody but the ADMIN, ?iam=1 for the IAM's backups, ?proof=password for
                        # a password as the proof ("secret"), ?signedin=1 for the form; /sign-in, the sign-in
                        # failure's screen (?code=state_mismatch, ..., unavailable); never published)
bun run build:showcase  # the same, built into angular/dist/showcase
bun run words           # the Angular half's library words from php/resources/library-words.json (--check: stale?)
```

CI (`.github/workflows/ci.yml`) runs the PHP tests on 8.2 and 8.4 against
PostgreSQL 16, builds the Composer archive and checks what it holds
(`sh .github/scripts/check-archive.sh` does the same locally), checks that the
Angular half's library words are the JSON's (`bun run words --check`), and builds
(with the package check) and tests the Angular library.

## Releasing

1. Bump the version in `angular/projects/lib/package.json` and
   `ANOTOKI_LIB_VERSION` (`angular/projects/lib/src/public-api.ts`; a test keeps
   them equal), and add it to `CHANGELOG.md`.
2. Commit, `git tag vX.Y.Z`, push the commit and the tag.
3. The release workflow builds and tests the library, refuses a tag that is not
   `v` + that version, packs it and attaches `anotoki-lib-X.Y.Z.tgz` to a GitHub
   Release of the tag.
4. Each site updates its constraint (`composer.json`) and the tarball's URL
   (`package.json`).
