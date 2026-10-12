# anotoki IAM - frontend

The pages of the anotoki identity provider: signing in (and finishing an
application's sign-in request), registering, the mailed-link pages, signing
out, the account pages, and the admin panel. Angular 21, standalone and
zoneless, signals throughout, and no UI library but the anotoki family's own
(`@anotoki/lib`, below).

The contract with the backend is [`../docs/api.md`](../docs/api.md); where the
PHP in [`../backend/api/routes/`](../backend/api/routes/) says more, the code wins.

## Commands

```bash
bun ci                  # the packages, exactly as bun.lock lists them (npm start runs it first)
npm start               # ng serve on http://localhost:4300; /api, /.well-known and /setup.php proxied to PHP on :8100
npm run build           # production build into dist/iam/browser/
npm run build:dev       # development build (unminified, source maps)
npm run build:deploy    # production build + the web root's .htaccess (see below)
npm run watch           # development build, rebuilt on change
npm run format          # prettier over src/
npx ng test --watch=false   # the unit tests (Vitest, jsdom) - see below
```

The packages come from `bun.lock`, so bun has to be installed. `bun ci` stops
when `bun.lock` and `package.json` disagree: add or update packages with bun
(`bun add`, `bun update`), which keeps `bun.lock` in step.

One of them is the anotoki family's library, `@anotoki/lib` - the tarball of
its GitHub release, named in `package.json` (0.5.0) - which every anotoki site
draws with: `@anotoki/lib/styles` (the tokens - one palette, the IAM's -
Manrope, whose files come with the package, the base, the utilities, the
tables), the UI kit, a piece an entry point (`@anotoki/lib/ui/button`,
`/config`, `/forms`, `/dialog`, ...: always imported from its own, never
through the `@anotoki/lib/ui` barrel, which would pull every piece into the
first load), `@anotoki/lib/shell` (the theme, the language switcher, the top
bar, the frame), `@anotoki/lib/translations` (the pages' words and language
from the database; `@anotoki/lib/translations/admin` is the admin panel's
Translations and Languages pages), `@anotoki/lib/migrations` (the
not-set-up page, the admin panel's "A database update is waiting" line;
`@anotoki/lib/migrations/page` is the Migrations page) and
`@anotoki/lib/admin` (the admin tools' configuration; `@anotoki/lib/admin/errors`,
`/audit` and `/backups` are the admin panel's Error log, Audit log and Backups
pages) and `@anotoki/lib/error-report` (the pages' own errors, reported to the
backend's error log: `provideAnotokiErrorReport()` in `app.config.ts`). The
IAM takes nothing of its auth, people or feedback modules: it is the IAM. Its PHP half is the
backend's `anotoki/lib`. A new version: its release's URL in `package.json`,
then `bun install` and `npm install --package-lock-only`, so that
`package-lock.json` agrees - with the backend's half, where the version
changed it too (`../docs/operations.md`).

The unit tests run with Angular's `@angular/build:unit-test` (`angular.json`'s
`test` target, `tsconfig.spec.json`), which needs `vitest` and `jsdom` in
`devDependencies` (the survey site's versions: `vitest` ^4, `jsdom` ^28) and,
once they are, a `"test": "ng test --watch=false"` script for `npm test`.
The specs: the top bar's (`layouts/top-bar/top-bar.component.spec.ts`: the
IAM's bar on the family's, drawn for real with the family's translations -
its order, links, menu and words, the language's button in the database's
words, a language chosen there saved to the account one save at a time and
the latest last, a refusal said under the button, PHONE_BAR_MAX, Tab out of
every menu), the translations as the IAM configures them
(`core/i18n/translations.config.spec.ts`: the storage names it kept, a choice
saved through the IAM's own API while signed in and nothing sent while signed
out, the account page's choice put back when the account refuses it, the
admin pages' words handed over only as the admin panel loads), the theme as
the IAM wires it into the family's shell (`core/kit/kit.config.spec.ts`:
saved to the account one save at a time, nothing sent while signed out, a
refusal puts the account's back, and an answer that comes after Sign out
signs nobody back in), the pages' own errors through the IAM's interceptor
(`core/http/iam.interceptor.spec.ts`: the family's reporter's request carries
the CSRF header and is marked as the reporter's; a refusal of it moves no page
and clears no session; a 429 reaches the reporter as it came, which stops it;
a request of the person's own still sends an ended session to the sign-in
page), the tab's title in the family's form (`core/i18n/title.strategy.spec.ts`:
"Zabezpečenie · anotoki účet" on an account page in Slovak, "Users · Admin
panel · anotoki account" in the admin panel, renamed with the language), the
rule every save that answers with the account
keeps (`core/auth/session.service.spec.ts`: the account pages', the theme's
and the language's all go through `SessionService.setFrom()` or `refresh()`,
so an answer after Sign out signs nobody back in; only a sign-in's answer and
the probe's are the session as they are), the admin panel's "Cannot sign in" rule
(`pages/admin/users/sign-in-check.ts`, the same as the server's `blocked_in`;
`sign-in-check.spec.ts`), the family's admin tools as the IAM wires them
(`core/admin/admin-tools.config.spec.ts`: the library's Backups and Audit log
pages drawn for real behind the IAM's interceptor and session - the IAM's
ADMIN alone, a refusal in the panel's words, the CSRF header on every write,
the password as the proof, "Sign in again" signing out and coming back, a
failed sign-out said in a toast, restoring off with the IAM's reason, a person
the log names linked to the Users page), and the
address the not-set-up page sends the browser back to - a path that stays on
this site, as the browser reads it (`core/auth/next-path.ts`'s
`sameOriginPath()`; `next-path.spec.ts`).

`npm start` needs the backend beside it, set up once through its installer
(`../DEV.md`):

```bash
cd ../backend && composer start
```

Until the backend is set up, every API call answers 503 `not_set_up` and every
page becomes `/not-set-up` - the family's status page, in the IAM's words -
which links to the installer while the IAM was never installed (on this
computer, always).

The browser only ever talks to `http://localhost:4300` - the dev server proxies
the API (`proxy.conf.json`) - so the session cookie, the `Origin` check and the
OIDC issuer (`http://localhost:4300` in development) all line up exactly as they
do in production, where everything is one origin.

## Layout

```
src/
  styles.scss              the family's styles (@anotoki/lib/styles: the tokens for both
                           themes, Manrope, the base, utilities, tables) and the
                           sign-in pages' night sky
  app/
    app.routes.ts          three lazy areas: sign-in pages, /account, /admin
    core/
      api/                 models.ts (the shapes) and one typed service per area:
                           public.api.ts, login.api.ts, account.api.ts, admin.api.ts
      http/                the interceptor: X-IAM-Request on non-GETs, 401 handling,
                           503 not_set_up to /not-set-up, errors reshaped to
                           { status, code, message } (but the migrations module's
                           own, which it reads as they come); the words' own
                           request (ANOTOKI_TRANSLATION_BUNDLE) is never a reason
                           to move the page, nor is a report of the pages' own
                           errors (ANOTOKI_ERROR_REPORT), which keeps its CSRF
                           header and gets its failure back as it came
      migrations/          the family's migrations module (@anotoki/lib/migrations)
                           wired in: who is ADMIN, the sign-in, the words, the dates.
                           No gate, no check at boot: the IAM keeps working while
                           an update waits
      admin/               the family's admin tools (@anotoki/lib/admin) wired in
                           (admin-tools.config.ts): who is ADMIN, the people the logs
                           name (the Users page), the dates, Migrations; the admin
                           panel's words for a refusal, "Sign in again" and why
                           restoring is off are handed over by its routes
                           (pages/admin/admin-tools.ts), out of the first load
      auth/                the session signal, the guard, `next` validation,
                           the remembered-device token
      i18n/                the family's translations (@anotoki/lib/translations)
                           wired in: translations.config.ts (the IAM's storage
                           names, its compiled English, the account - its language,
                           a choice saved to it -, the start, the admin pages'
                           words once the panel loads), translation.service.ts
                           (the library's service seen with the IAM's keys),
                           en.ts (the keys, the last-resort English), the typed `t`
                           pipe and `appDate` (the account's date order and clock:
                           date-patterns.ts), error texts, the page-title strategy
                           (the family's form - "Users · Admin panel · anotoki
                           account" -, which tells the translations where the
                           page is)
      kit/                 the family's UI kit and shell wired in (kit.config.ts):
                           the kit's words from the database - the IAM's own keys
                           where it has them (kit-words.ts), the library's
                           `anotoki.*` otherwise -, the icons the IAM's templates
                           name (icons.ts), the theme (the account's while signed
                           in), the languages (the translations' forShell())
      google/              Google Identity Services, loaded on demand
    ui/                    google-button, the IAM's own; the rest of the kit is the
                           family's (@anotoki/lib/ui)
    layouts/               auth-layout (the night-sky sign-in pages' own frame),
                           top-bar (the anotoki family's one bar, worded and wired
                           for the IAM - below)
    pages/
      auth/                /login /register /confirm-email /forgot-password
                           /reset-password /logout /unsubscribe /error
                           /not-set-up, and not-found
      account/             /account and its four sub-pages (the top bar's links),
                           in the family's frame (<anotoki-shell>)
      admin/               /admin, in the family's frame with the panel's side
                           navigation: users, approvals, sessions, applications,
                           announcements (the history and senders, the composer,
                           one announcement and its sending), the email log,
                           settings, keys; /admin/translations and /admin/languages
                           are the family's pages (@anotoki/lib/translations/admin),
                           worded for the IAM by localization.ts (its mails, the
                           accounts per language); /admin/audit, /admin/errors and
                           /admin/backups are the family's admin tools
                           (@anotoki/lib/admin/*: the audit log, the error log, the
                           backups - encrypted, restoring off, behind the password -,
                           the panel's part in admin-tools.ts); and /admin/migrations
                           is the family's (@anotoki/lib/migrations/page), whose
                           update line sits above them all
public/
  anotoki.png, favicon.png - and the build copies the family's theme-boot.js beside
                           them (angular.json): the stored theme before the first
                           paint, from a file (no inline script)
scripts/
  prepare-deploy.mjs       writes dist/iam/browser/.htaccess
```

## Conventions

- **Three files per component.** `x.component.ts`, `x.component.html`,
  `x.component.scss` - never an inline `template` or `styles`, even for a
  component with no styles of its own (its `.scss` is empty). Stylesheets
  shared by a group of pages are partials the component's own `.scss` `@use`s:
  `pages/auth/_auth-page.scss`, `pages/admin/_admin-list.scss`,
  `pages/admin/_admin-field.scss` (the few fields the admin panel draws by hand).
- **The family's kit, not one of our own.** Buttons, fields, dialogs, menus,
  toasts, badges, alerts, cards, tabs, the pagination, the QR code are the
  `anotoki-*` components of the kit, each imported from its own entry point
  (`@anotoki/lib/ui/button`, `ui/forms`, `ui/dialog`, `ui/toast`, ... - never
  the `@anotoki/lib/ui` barrel; what only lazy pages use, only they import).
  Their words come from the database: the IAM's own strings where it has them
  (`core/kit/kit-words.ts`), the library's `anotoki.*` ones otherwise (the
  translations' `forKit()`) - a rewording on the Translations page shows there
  too, and the admin panel reads English -, the kit's own English and informal
  Slovak where the database has none. The kit's markup is its own (BEM on its
  host class: `anotoki-top-bar__row`, `anotoki-button--primary`, ...): a spec
  or a check that reads it uses those names. An icon a template names is the
  kit's, or one `core/kit/icons.ts` registers. The IAM's own: the sign-in
  frame, the Google button, the pages.
- **Signals first.** State is `signal()` / `computed()` / `linkedSignal()`,
  inputs are `input()` / `model()`, and route and query parameters arrive as
  component inputs (`withComponentInputBinding`). Every component is `OnPush`.
- **No generated client.** The API shapes are written out in
  `core/api/models.ts`; when the backend changes, change them and let the
  compiler find every page that has to follow.
- **Words from the database, keys typed.** The sign-in and account pages read
  their strings from the database, in whatever languages the admin panel
  offers (English and Slovak to start with): one bundle,
  `GET /api/translations/{code}`, fetched before the page switches language -
  the anotoki family's `TranslationService` (`@anotoki/lib/translations`,
  configured in `core/i18n/translations.config.ts`): the family's ranking of
  the wishes (this tab's `?lang=`, a choice made in this visit, the account's
  language while signed in, this device's, the browser's, English), a choice
  saved to the account one save at a time, the cache and the start's waits.
  `TranslationKey` comes from `en.ts`, and the IAM sees the service through
  `core/i18n/translation.service.ts` with its keys typed - the IAM's and the
  library's `anotoki.*` -, so an unknown key is a compile error in a class as
  in a template (the `t` pipe); a key the bundle has no string for shows as
  the key itself, so it is seen - but never a library key, which has its
  built-in words. `en.ts`'s English is shown only while no bundle is in memory
  (between an upload and "Apply pending", or when the server cannot be
  reached). There is no Slovak in the build. The admin panel is English only,
  written in its templates; its Translations, Languages, Error log, Audit log,
  Backups and Migrations pages are the family's.
- **The top bar** (`layouts/top-bar`) is the anotoki family's one bar -
  `<anotoki-top-bar>` from `@anotoki/lib/shell`, whose order, menus, keys and
  fold are the family's - worded and wired here. One row: the brand ("anotoki
  account"; in the admin panel the family's "anotoki account · admin panel",
  on a phone "account" with "admin panel" under it), the account pages as
  links, the language (a 40 px button with a menu; not in the admin panel,
  which is English), the theme, the person's menu (name, email,
  "Administrator"; the admin panel for the IAM's administrators, or "Back to
  your account" from it; Sign out). The family's "Your anotoki account" entry is left out: the
  account pages are the bar's own links. A language chosen there is saved to
  the account too, as the overview's select does - by the family's
  translations, one save at a time, the latest choice last, so quick choices
  cannot leave the account on another language than the page; a refusal
  leaves the page in the chosen language for the visit and says so under the
  button. The language button shows the code ("SK") and is named with it
  ("Language: Slovenčina (SK)", WCAG 2.5.3: the words on a button are part of
  its name - `anotoki.language.button`, reworded on the Translations page like
  any other). Up to `PHONE_BAR_MAX`
  (1040 px - measured with the English and the Slovak labels, plus a desktop
  scrollbar) the links and the person's menu fold into one menu button; above
  it only the person's name gives way (hidden up to 1240 px). Both menus: focus
  in on open, arrow keys, Home / End, Escape and an outside click close them,
  and Tab / Shift+Tab from any item close the menu with the focus on its
  button (`preventDefault()` - left to the browser, the focus would fall to
  `<body>`). The admin panel keeps its own side navigation under it (the
  family's frame, `<anotoki-shell [nav]>` in `pages/admin/admin-layout`, a
  strip of links on a narrow screen).
- **Theming** is custom properties only: the family's `--anotoki-*` tokens,
  written for both themes by `styles.scss` (`anotoki.root()` - the IAM's
  palette is the family's), and the night sky's own. Components never contain
  a raw colour that works in one theme only. Signed in, the theme is the
  account's, which every anotoki site follows (migration 015): the family's
  `ThemeService` shows it and saves a choice to it (`core/kit/kit.config.ts`),
  one save at a time, the latest choice last - a refusal puts the account's
  back, and the switch says so; signed out, it is the device's. Either way the
  last one shown is in `localStorage` (`anotoki-iam:theme`), for the family's
  `theme-boot.js`, which `index.html` loads with that key.

## Deploying

`npm run build:deploy` produces `dist/iam/browser/`: upload its contents into
the web root, next to the backend's `api/`. The `.htaccess` it writes:

- passes `/api/…` through to the backend untouched;
- refuses `/config`, `/migrations`, `/storage` and `/vendor` (403), in any
  case of letters - the backend's own folders: the master key, the backups
  (every hash and encrypted secret), the logs - before anything else, as each
  one's own `.htaccess` does;
- rewrites `/.well-known/openid-configuration` and `/.well-known/jwks.json` to
  `api/index.php` (the front controller routes on the original path);
- answers every other path that is not a real file with `index.html`;
- sets `X-Frame-Options: DENY`, `X-Content-Type-Options: nosniff`,
  `Referrer-Policy: strict-origin-when-cross-origin` and a
  Content-Security-Policy that allows this origin plus Google Identity Services
  and the family's visitor counter (`https://umami.anotoki.eu`, Umami), and
  nothing inline.

The policy is why `angular.json` turns off critical-CSS inlining in the
production build: its stylesheet loader is an inline `onload` handler, which
the policy would block and leave the page unstyled. The deploy script refuses a
build whose `index.html` has an inline script or handler.

It also refuses one whose `@anotoki/lib` could draw a string as HTML. The
database's strings, which any administrator rewords, reach the family's
components as well as the IAM's own, and the backend's "a string is text,
never HTML" scan (`php translations.php --status`, the library's
`KeyScanner`) reads only `src/app`.
So the script reads the library's bundles
(`node_modules/@anotoki/lib/fesm2022/*.mjs`) and the boot script copied beside
`index.html` (`assets/theme-boot.js`), and stops at `innerHTML`,
`outerHTML`, `setHTMLUnsafe`, `insertAdjacentHTML`, `bypassSecurityTrust…`,
`srcdoc`, `document.write`, `DOMParser`, `parseFromString`,
`parseHTMLUnsafe` or `createContextualFragment` - every way a string becomes
markup -, in any
spelling and in a comment too, naming the file and line. Angular's own
sanitizer is not in those files. The fix is a release of the library, never a
way around the check.
