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
its GitHub release, named in `package.json` (0.2.0) - which every anotoki site
draws with: `@anotoki/lib/styles` (the tokens - one palette, the IAM's -
Manrope, whose files come with the package, the base, the utilities, the
tables), `@anotoki/lib/ui` and its entry points (the UI kit: buttons, fields,
dialogs, menus, toasts, ...), `@anotoki/lib/shell` (the theme, the language
switcher, the top bar, the frame) and `@anotoki/lib/migrations` (the
not-set-up page, the admin panel's "A database update is waiting" line;
`@anotoki/lib/migrations/page` is the Migrations page). Its PHP half is the
backend's `anotoki/lib`. A new version: its release's URL in `package.json`,
then `bun install` and `npm install --package-lock-only`, so that
`package-lock.json` agrees - with the backend's half, where the version
changed it too (`../docs/operations.md`).

The unit tests run with Angular's `@angular/build:unit-test` (`angular.json`'s
`test` target, `tsconfig.spec.json`), which needs `vitest` and `jsdom` in
`devDependencies` (the survey site's versions: `vitest` ^4, `jsdom` ^28) and,
once they are, a `"test": "ng test --watch=false"` script for `npm test`.
The specs: the top bar's (`layouts/top-bar/top-bar.component.spec.ts`: the
IAM's bar on the family's, drawn for real - its order, links, menu and words,
the language saved to the account, PHONE_BAR_MAX, Tab out of every menu), the
theme as the IAM wires it into the family's shell
(`core/kit/kit.config.spec.ts`: saved to the account one save at a time,
nothing sent while signed out, a refusal puts the account's back, and an
answer that comes after Sign out signs nobody back in), the rule every save
that answers with the account keeps (`core/auth/session.service.spec.ts`: the
account pages', the theme's and the bar's language all go through
`SessionService.setFrom()` or `refresh()`, so an answer after Sign out signs
nobody back in; only a sign-in's answer and the probe's are the session as
they are), the admin panel's "Cannot sign in" rule
(`pages/admin/users/sign-in-check.ts`, the same as the server's `blocked_in`;
`sign-in-check.spec.ts`), and the
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
                           own, which it reads as they come)
      migrations/          the family's migrations module (@anotoki/lib/migrations)
                           wired in: who is ADMIN, the sign-in, the words, the dates.
                           No gate, no check at boot: the IAM keeps working while
                           an update waits
      auth/                the session signal, the guard, `next` validation,
                           the remembered-device token
      i18n/                translation.service.ts (the language, its bundle from
                           the database), en.ts (the keys, the last-resort English),
                           localization-drafts.service.ts, the `t` and `appDate`
                           pipes (the account's date order and clock: date-patterns.ts),
                           error texts, the page-title strategy
      kit/                 the family's UI kit and shell wired in (kit.config.ts):
                           the kit's words from the IAM's strings (kit-words.ts),
                           the icons the IAM's templates name (icons.ts), the
                           theme (the account's while signed in), the languages
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
                           one announcement and its sending), logs,
                           settings, translations and languages (localization/),
                           keys; /admin/migrations is the family's page
                           (@anotoki/lib/migrations/page), and its update line
                           sits above them all
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
  `anotoki-*` components of `@anotoki/lib/ui` and its entry points (`ui/forms`,
  `ui/dialog`, `ui/toast`, ... - what only lazy pages use, only they import).
  Their words are the IAM's strings where it has them (`core/kit/kit-words.ts`,
  the kit's `lookup`: a rewording on the Translations page shows there too,
  and the admin panel reads English), the kit's own English and informal
  Slovak otherwise. An icon a template names is the kit's, or one
  `core/kit/icons.ts` registers. The IAM's own: the sign-in frame, the Google
  button, the pages.
- **Signals first.** State is `signal()` / `computed()` / `linkedSignal()`,
  inputs are `input()` / `model()`, and route and query parameters arrive as
  component inputs (`withComponentInputBinding`). Every component is `OnPush`.
- **No generated client.** The API shapes are written out in
  `core/api/models.ts`; when the backend changes, change them and let the
  compiler find every page that has to follow.
- **Words from the database, keys typed.** The sign-in and account pages read
  their strings from the database, in whatever languages the admin panel
  offers (English and Slovak to start with): one bundle,
  `GET /api/translations/{code}`, fetched before the page switches language.
  `TranslationKey` comes from `en.ts`, so an unknown key is a compile error;
  a key the bundle has no string for shows as the key itself, so it is seen.
  `en.ts`'s English is shown only while no bundle is in memory (between an
  upload and "Apply pending", or when the server cannot be reached). There is no
  Slovak in the build. The admin panel is English only, written in its
  templates.
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
  the account too, as the overview's select does - one save at a time, the
  latest choice last, so quick choices cannot leave the account on another
  language than the page (the family's `latestChoiceSaver`). The language
  button shows the code ("SK") and is
  named with it ("Language: Slovenčina (SK)", WCAG 2.5.3: the words on a
  button are part of its name). Up to `PHONE_BAR_MAX`
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
never HTML" scan (`backend/tests/translation_keys.php`) reads only `src/app`.
So the script reads the library's bundles
(`node_modules/@anotoki/lib/fesm2022/*.mjs`) and stops at `innerHTML`,
`outerHTML`, `insertAdjacentHTML` or `bypassSecurityTrust…`, in any spelling
and in a comment too, naming the file and line. Angular's own sanitizer is not
in those files. The fix is a release of the library, never a way around the
check.
