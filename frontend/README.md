# anotoki IAM - frontend

The pages of the anotoki identity provider: signing in (and finishing an
application's sign-in request), registering, the mailed-link pages, signing
out, the account pages, and the admin panel. Angular 21, standalone and
zoneless, signals throughout, no UI library.

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

The unit tests run with Angular's `@angular/build:unit-test` (`angular.json`'s
`test` target, `tsconfig.spec.json`), which needs `vitest` and `jsdom` in
`devDependencies` (the survey site's versions: `vitest` ^4, `jsdom` ^28) and,
once they are, a `"test": "ng test --watch=false"` script for `npm test`.
The specs: the top bar's (`layouts/top-bar/top-bar.component.spec.ts`), and
the admin panel's "Cannot sign in" rule (`pages/admin/users/sign-in-check.ts`,
the same as the server's `blocked_in`; `sign-in-check.spec.ts`).

`npm start` needs the backend beside it, set up once through its installer
(`../DEV.md`):

```bash
cd ../backend && composer start
```

Until the backend is set up, every API call answers 503 `not_set_up` and every
page becomes `/not-set-up`, which links to the installer.

The browser only ever talks to `http://localhost:4300` - the dev server proxies
the API (`proxy.conf.json`) - so the session cookie, the `Origin` check and the
OIDC issuer (`http://localhost:4300` in development) all line up exactly as they
do in production, where everything is one origin.

## Layout

```
src/
  styles.scss              every colour token, for both themes; base element styles
  app/
    app.routes.ts          three lazy areas: sign-in pages, /account, /admin
    core/
      api/                 models.ts (the shapes) and one typed service per area:
                           public.api.ts, login.api.ts, account.api.ts, admin.api.ts
      http/                the interceptor: X-IAM-Request on non-GETs, 401 handling,
                           503 not_set_up to /not-set-up, errors reshaped to
                           { status, code, message }
      auth/                the session signal, the guard, `next` validation,
                           the remembered-device token
      i18n/                translation.service.ts (the language, its bundle from
                           the database), en.ts (the keys, the last-resort English),
                           localization-drafts.service.ts, the `t` and `appDate`
                           pipes, error texts, the page-title strategy
      theme/               light / dark / automatic
      google/              Google Identity Services, loaded on demand
    ui/                    the UI kit (buttons, fields, dialog, toasts, QR code, …)
    layouts/               auth-layout (the night-sky sign-in pages' own frame),
                           shell (the account pages and the admin panel: the
                           top bar, and the panel's side navigation),
                           top-bar (the anotoki family's one bar, below)
    pages/
      auth/                /login /register /confirm-email /forgot-password
                           /reset-password /logout /error /not-set-up, and not-found
      account/             /account and its four sub-pages (the top bar's links)
      admin/               /admin: users, approvals, sessions, applications, logs,
                           settings, translations and languages (localization/),
                           keys, migrations
public/
  anotoki.png, favicon.png
  theme-boot.js            applies the stored theme before first paint (no inline scripts)
scripts/
  prepare-deploy.mjs       writes dist/iam/browser/.htaccess
```

## Conventions

- **Three files per component.** `x.component.ts`, `x.component.html`,
  `x.component.scss` - never an inline `template` or `styles`, even for a
  component with no styles of its own (its `.scss` is empty). Stylesheets
  shared by a group of pages are partials the component's own `.scss` `@use`s:
  `pages/auth/_auth-page.scss`, `pages/admin/_admin-list.scss`.
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
- **The top bar** (`layouts/top-bar`) is the anotoki family's one bar - the
  survey site's `layouts/top-bar` is the reference, and the family's spec says
  what each site changes. One row: the brand ("anotoki account", or "anotoki
  admin"), the account pages as links, the language (a 40 px button with a
  menu - `app-language-switcher [compact]`; not in the admin panel, which is
  English), the theme, the person's menu (name, email, "Administrator"; the
  admin panel for the IAM's administrators, or "Back to your account" from
  it; Sign out). The family's "Your anotoki account" entry is left out: the
  account pages are the bar's own links. A language chosen there is saved to
  the account too, as the overview's select does - one save at a time, the
  latest choice last, so quick choices cannot leave the account on another
  language than the page. The language button shows the code ("SK") and is
  named with it ("Language: Slovenčina (SK)", WCAG 2.5.3: the words on a
  button are part of its name). Up to `PHONE_BAR_MAX`
  (1040 px - measured with the English and the Slovak labels, plus a desktop
  scrollbar) the links and the person's menu fold into one menu button; above
  it only the person's name gives way (hidden up to 1240 px). Both menus: focus
  in on open, arrow keys, Home / End, Escape and an outside click close them,
  and Tab / Shift+Tab from any item close the menu with the focus on its
  button (`preventDefault()` - left to the browser, the focus would fall to
  `<body>`). The admin panel keeps its own side navigation under it
  (`layouts/shell`, a strip of links on a narrow screen).
- **Theming** is custom properties only: both palettes in `styles.scss` define
  the same names. Components never contain a raw colour that works in one
  theme only.

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
