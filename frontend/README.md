# anotoki IAM - frontend

The pages of the anotoki identity provider: signing in (and finishing an
application's sign-in request), registering, the mailed-link pages, signing
out, the account pages, and the admin console. Angular 21, standalone and
zoneless, signals throughout, no UI library.

The contract with the backend is [`../docs/api.md`](../docs/api.md); where the
PHP in [`../backend/api/routes/`](../backend/api/routes/) says more, the code wins.

## Commands

```bash
npm install
npm start               # ng serve on http://localhost:4300; /api, /.well-known and /setup.php proxied to PHP on :8100
npm run build           # production build into dist/iam/browser/
npm run build:dev       # development build (unminified, source maps)
npm run build:deploy    # production build + the web root's .htaccess (see below)
npm run watch           # development build, rebuilt on change
npm run format          # prettier over src/
```

`npm start` needs the backend beside it, set up once through its installer
(`../DEVELOPING.md`):

```bash
cd ../backend && php -S localhost:8100 router.php
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
      i18n/                en.ts / sk.ts, the `t` and `appDate` pipes, error texts,
                           the page-title strategy
      theme/               light / dark / automatic
      google/              Google Identity Services, loaded on demand
    ui/                    the UI kit (buttons, fields, dialog, toasts, QR code, …)
    layouts/               the night-sky sign-in layout and the account/admin shell
    pages/
      auth/                /login /register /confirm-email /forgot-password
                           /reset-password /logout /error /not-set-up, and not-found
      account/             /account and its four sub-pages
      admin/               /admin: users, sessions, applications, logs, settings,
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
- **Two languages, typed.** The sign-in and account pages are English and
  Slovak. `TranslationKey` comes from `en.ts`, and `sk.ts` is typed as a record
  of exactly those keys, so a missing translation is a compile error. The admin
  console is English only and does not use the dictionaries.
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
  and nothing inline.

The policy is why `angular.json` turns off critical-CSS inlining in the
production build: its stylesheet loader is an inline `onload` handler, which
the policy would block and leave the page unstyled. The deploy script refuses a
build whose `index.html` has an inline script or handler.
