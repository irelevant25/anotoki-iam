# anotoki-lib

What the anotoki sites share - the IAM, genshin, the survey site, Piano Academy,
Japanese Academy and the build analyzer - in one library with two halves and one
version:

| Half | Package | Where |
| --- | --- | --- |
| PHP 8.2+, Slim 4, PostgreSQL | `anotoki/lib` (Composer) | `composer.json`, `php/src/`, `php/migrations/`, `php/resources/` |
| Angular 21 (standalone, zoneless, signals) | `@anotoki/lib` (npm tarball) | `angular/projects/lib/` |

Version 0.2.0 holds:

- **migrations** - the engine that brings a site's database up to date, and one
  behaviour for every site while an update waits (both halves);
- **the UI kit** (Angular) - the family's styles and palette
  (`@anotoki/lib/styles`), its components (`@anotoki/lib/ui` and its entry
  points) and its top bar and frame (`@anotoki/lib/shell`).

The PHP half is unchanged since 0.1.0. Unreleased (0.3.0, CHANGELOG.md): the
engine's **library sets** - the library's own migration files beside a site's -
and **translations**, both halves: a site's words, its languages and their admin
pages, one implementation for every site (the section "Translations" below); and
the kit's core a file per piece, its class names its own ("Upgrading from 0.2.x").

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
"require": { "anotoki/lib": "^0.1" }
```

then `php composer.phar update anotoki/lib --prefer-dist` in `backend/`. Composer
takes the version from the git tag and installs GitHub's archive of it, which
holds the PHP half only - its code (`php/src`), the migration files of the
library's sets (`php/migrations`) and its resources (`php/resources`), with
`composer.json`, this README and CHANGELOG.md: `.gitattributes` is an
allow-list, and CI builds the archive and checks it
(`.github/scripts/check-archive.sh`). Where the site uploads `vendor/`,
`vendor/anotoki/lib` goes with it - kept from being served as the site's own
`migrations/` is (a deny-all `.htaccess`), since it holds SQL too. (0.2.0
changed only the Angular half: `^0.1` keeps a site's PHP half at 0.1.x, which
is the same code.)

Angular - in `frontend/package.json`:

```json
"@anotoki/lib": "https://github.com/irelevant25/anotoki-lib/releases/download/v0.2.0/anotoki-lib-0.2.0.tgz"
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
- **Library sets** (unreleased, 0.3.0): `MigrationSet::library($name, $dir)` is a
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
| `@anotoki/lib/ui/config` | `provideAnotokiUi`, `AnotokiWords`, the kit's words and the library's (`LIBRARY_WORDS`) | yes |
| `@anotoki/lib/ui/icon`, `/dom` | `<anotoki-icon>`, `AnotokiIcons` and the kit's own icons; `uniqueId`, `keepOnScreen`, `mediaQuery`, `tabbable`, `isShown` | yes (the bar) |
| `@anotoki/lib/ui/button`, `/spinner` | the button, the spinner | yes (the status page) |
| `@anotoki/lib/ui/badge`, `/alert`, `/card`, `/empty-state`, `/error-state`, `/page-header`, `/avatar`, `/segmented`, `/autofocus` | one piece each | where a first page uses it |
| `@anotoki/lib/ui/icons` | the family's other icons, data only (`iconTrash`, `iconUsers`, ...) | what the site registers |
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
`danger-contrast`, `danger-soft`, `danger-border`, `success`, `success-text`,
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
`LIBRARY_WORDS` has every one). A site words its own menu items with them too:
`words.t('topbar.account')` is "Tvoj anotoki účet" in Slovak.

**Icons** - `<anotoki-icon name="..." [size] [strokeWidth] [filled] [label]>`
(`ui/icon`, with `AnotokiIcons`, `KIT_ICONS`, `circle()`, `rect()`), decorative
unless labelled. The kit's own: `sun`, `moon`, `monitor`, `user`,
`shield`, `logIn`, `logOut`, `chevronDown`, `chevronLeft`, `chevronRight`,
`menu`, `x`, `check`, `alert`, `info`, `checkCircle`, `xCircle`, `eye`,
`eyeOff`, `copy`, `refresh`, `arrowLeft`, `externalLink`, `sparkle`, `search`,
`plus`; the rest from `@anotoki/lib/ui/icons`, registered by name (`icons`
above, or an `ANOTOKI_ICONS` multi provider on a lazy route). An unknown name
draws nothing and warns once in development.

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
(role="alert", tied by aria-describedby, aria-invalid), `required`,
`optional`, `disabled`, `hideLabel`, `autofocus`, `focus()`.
`anotoki-text-field` (`type`, `autocomplete`, `inputmode`, `placeholder`,
`readonly`, `maxlength`, `minlength`, `min`, `max`, `pattern`, `spellcheck`,
`autocapitalize`, `(blurred)`, `[fieldPrefix]` / `[fieldSuffix]`),
`anotoki-textarea` (`rows`, `readonly`, ...), `anotoki-password-field`
(`autocomplete` required, `[labelAside]`), `anotoki-select` (`options`,
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
is none; `save` - rejects when the account refuses; `themeColor`) and
`languages` (`current`, `offered`, `choose` -> true once the page reads in it,
`notSaved`, `clearNotSaved`).

- `ThemeService` - `mode`, `resolved`, `refused`, `set(mode)`,
  `dismissRefusal()`. Writes `<html data-theme>`, its color-scheme and
  `<meta name="theme-color">`; follows the device while `auto`; keeps the mode
  in localStorage (nothing for auto); shows the account's theme from its first
  moment and whenever it changes - not while its own save is out; one save at a
  time, the latest last; a refusal puts the account's theme back and sets `refused`.
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
| `shortBrandMax` | the brand short up to here (phoneMax) - the survey's admin panel: 960 |
| `width` | `wide` (84rem) or `narrow` (49rem); `--anotoki-topbar-max-width` overrides; in a frame with a side navigation, 88rem, and in one with a `maxWidth`, that |
| `phoneMark` | `{ badge?, label? }` (the button's name says the count) or `{ dot, description? }` |
| `menuNote` | `{ title, text? }` heading both menus |
| `isolateKeys` | no key pressed in the bar reaches `document` (true) |

  `(languageChanged)` tells a choice made in the bar; content `[topBarWidgets]`
  stands before the language (a widget reads `bar.phone()`); `closeMenu(returnFocus)`.
  A menu closes when the bar folds or unfolds, when the person changes and when
  the links do; in development the bar warns once when its row overflows above
  `phoneMax` (measure again).

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
  (English; for the site's ADMIN only), drawn with the kit: Pending (with
  drafts), Applied (with each file's SQL in a dialog), Missing, and "Apply
  pending" behind a question whose focus starts on Cancel.

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

## Upgrading from 0.2.x (0.3.0, unreleased)

1. **The kit's core, a piece at a time.** Nothing has to change to keep
   building: `@anotoki/lib/ui` re-exports every piece. For the smaller first
   load a site imports each piece from its own entry point - in every file its
   first page loads, best everywhere: `provideAnotokiUi`, `AnotokiWords`,
   `ANOTOKI_UI_CONFIG`, `ANOTOKI_ICONS`, `LIBRARY_WORDS`, `BUILT_IN_WORDS`,
   `fillWords` and the words' types from `@anotoki/lib/ui/config`;
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

## Translations (unreleased, 0.3.0)

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
  `{key: text}`; a blank is passed over, an unknown key refused),
  `forgetWriter($id)`, `storedRefusals()`, `keyNames()`, `keysWithout($code)`.
  None of them throws for a refusal: they answer a `Support\Refusal` (status,
  code, message, extra).
- `StringCheck::changes()` - the IAM's checks in the IAM's order, the changes
  sorted by key and language (one lock order for every save): `invalid_value`,
  `unknown_key` (+ `keys`), `unknown_language`, `fallback_required`,
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
(`angular/scripts/library-words.mjs`) generates its built-in words,
`angular/projects/lib/ui/config/src/library-words.ts` (committed; `LIBRARY_WORDS`
of `@anotoki/lib/ui/config`) - the kit reads its own from there, the status page
its `siteStatus.*`, the translations module every one - and a spec
(`library-words.spec.ts`), CI (`bun run words --check`) and the PHP half's
`LibraryWordsTest` fail when that file is stale. So a page has the library's
words while the database cannot be reached, and never shows a library key as a
key. They are in a site's first load once, in `ui/config`.

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
  built-in words of the language, then their English. `<html lang>` follows
  `effectiveLanguage`.
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
  export (the server's file and name) and import (at most 1 MiB, a byte-order
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
  accounts); delete only for a language not released - offering an export of
  its strings first, then a question naming them (and `beforeDelete`'s words),
  then what went (`afterDelete`: Japanese Academy's copy); a new language hidden
  unless asked otherwise. Both pages read again after every change
  (`reload()`), ask their questions in their own dialog (nothing in the site's
  root), say what happened in the page, and work at 390 px.
- `LocalizationDrafts` - what is typed and not saved, in sessionStorage
  (`<prefix>:localization-drafts`) with whose it is: kept through a reload and a
  sign-out, dropped when somebody else signs in; one `beforeunload` question.
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

## Working on the library

```
# PHP (from the repository root; on this family's Windows machines `php8`)
php composer.phar install
ANOTOKI_LIB_TEST_DB_CONFIG=../anotoki-iam/backend/config/database.local.php vendor/bin/phpunit
#   or ANOTOKI_LIB_TEST_PGHOST / _PGPORT / _PGUSER / _PGPASSWORD; the user needs CREATEDB:
#   each test case makes a database anotoki_lib_test_<random> and drops it

# Angular (from angular/)
bun install
bun run build           # ng build lib -> angular/dist/lib, then scripts/check-package.mjs: each entry point's
                        # size (gzipped code; limits for the eager ones) and import boundaries; what a site's
                        # build keeps of an entry point nothing is used of (nothing); and four first loads - the
                        # gate, the bar, the words, a button - beside lazy pages that use the rest (no file of
                        # theirs, under a limit), each file through Angular's own linker and the CLI's esbuild
bun run test            # ng test lib --watch=false (Vitest, jsdom)
bun run showcase        # projects/showcase on http://localhost:4320: every piece and the bar, from the source
                        # (?theme=dark, ?lang=sk, ?account=1 for a refused theme, ?frame=88rem for the frame's
                        # maxWidth; /migrations, /translations, /languages against an imagined server; never published)
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
