# anotoki-lib

What the anotoki sites share - the IAM, genshin, the survey site, Piano Academy,
Japanese Academy and the build analyzer - in one library with two halves and one
version:

| Half | Package | Where |
| --- | --- | --- |
| PHP 8.2+, Slim 4, PostgreSQL | `anotoki/lib` (Composer) | `composer.json`, `php/src/` |
| Angular 21 (standalone, zoneless, signals) | `@anotoki/lib` (npm tarball) | `angular/projects/lib/` |

Version 0.1.0 holds one module: **migrations** - the engine that brings a
site's database up to date, and one behaviour for every site while an update
waits.

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
leaves out the Angular workspace, the tests and the workflows
(`.gitattributes`). Where the site uploads `vendor/`, `vendor/anotoki/lib` goes
with it.

Angular - in `frontend/package.json`:

```json
"@anotoki/lib": "https://github.com/irelevant25/anotoki-lib/releases/download/v0.1.0/anotoki-lib-0.1.0.tgz"
```

then `bun install` in `frontend/` (and `npm install` too where the site keeps a
`package-lock.json`, so both lock files agree). Peer dependencies:
`@angular/core`, `@angular/common`, `@angular/router` ^21.2 and `rxjs` ^7.8.

**Updating** to a new version: PHP - change the constraint where the new
version needs it (0.x: a minor version may break - read CHANGELOG.md), then the
same `composer update anotoki/lib --prefer-dist`; Angular - the new release's URL
in `package.json`, then `bun install`. Update both halves together.

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
    '~^/api/translations/[a-z-]+$~',        // ...or a regular expression when it starts with '~'
    '/api/health',
    '~^/api/admin/migrations(/.*)?$~',
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
  the table has one (required with more than one set), `applied_at` a TIMESTAMP
  holding UTC or a TIMESTAMPTZ (answered as ISO 8601 UTC, `…Z`). A missing table
  is created as `(id SERIAL PRIMARY KEY, filename VARCHAR(255) NOT NULL,
  applied_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, UNIQUE (filename))`, with
  `folder VARCHAR(64) NOT NULL` and `UNIQUE (folder, filename)` for more than one
  set. A table it cannot read (no file column) counts as nothing applied until
  `prepare` adopts it.
- `Splitter::split($sql)` - the statements of a file: a `;` ends one only outside
  comments, strings (`'…'`, `E'…'`), quoted names and dollar-quoted blocks.
- `MigrationsRoutes::admin` answers `GET $base` (`Migrator::status()`),
  `GET $base/file?set=&name=` (the SQL, or 404) and `POST $base/apply` (200 with
  `applied`, `failed`, `error`, `note` - a failed file is still a 200 - or 409
  `busy`). The site's admin check must not need tables a pending migration makes.

## The Angular half - `@anotoki/lib/migrations`

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
<!-- the app's template: the gate around the router outlet, and around nothing else -->
<anotoki-site-gate><router-outlet /></anotoki-site-gate>
```

- `SiteStatus` - `state` (`'unknown' | 'ready' | 'update-pending' | 'not-set-up' |
  'unavailable'`), `installed`, `blocked`, `check()`, `report(code, installed?)`,
  `markReady()`.
- `siteStatusInterceptor` - a 503 whose body's `code` is `update_pending` or
  `not_set_up` tells `SiteStatus` (the request still fails).
- `<anotoki-site-gate>` - its content while the site is ready (or not asked
  yet), or for the ADMIN on the Migrations route while an update waits;
  `<anotoki-site-status>` otherwise.
- `<anotoki-site-status>` - the page of the section above.
- `<anotoki-update-banner>` - the IAM's one line for its administrators.
- `<anotoki-migrations-page>` - the admin page (English; for the site's ADMIN
  only): Pending (with drafts), Applied (with each file's SQL), Missing, and
  "Apply pending".

A site that does not block while an update waits (the IAM, `blockWhilePending:
false`) puts **no** gate around its outlet and does not ask at boot: the status
path does say `update_pending` there, and the gate would close the site to
everybody. It uses the interceptor, `<anotoki-update-banner>` in its admin panel
(which asks the server itself once an ADMIN is there), the Migrations page, and
`<anotoki-site-status>` where it shows its "not set up" page.

Theming: the components use these custom properties, each with a readable
fallback built on `light-dark()`; a site maps its own tokens onto them, for
light and dark: `--anotoki-bg`, `--anotoki-surface`, `--anotoki-text`,
`--anotoki-muted`, `--anotoki-border`, `--anotoki-primary`,
`--anotoki-primary-contrast`, `--anotoki-danger`, `--anotoki-danger-contrast`,
`--anotoki-success`, `--anotoki-warning`, `--anotoki-radius`,
`--anotoki-font-mono`.

## Working on the library

```
# PHP (from the repository root; on this family's Windows machines `php8`)
php composer.phar install
ANOTOKI_LIB_TEST_DB_CONFIG=../anotoki-iam/backend/config/database.local.php vendor/bin/phpunit
#   or ANOTOKI_LIB_TEST_PGHOST / _PGPORT / _PGUSER / _PGPASSWORD; the user needs CREATEDB:
#   each test case makes a database anotoki_lib_test_<random> and drops it

# Angular (from angular/)
bun install
bun run build        # ng build lib -> angular/dist/lib
bun run test         # ng test lib --watch=false (Vitest, jsdom)
```

CI (`.github/workflows/ci.yml`) runs the PHP tests on 8.2 and 8.4 against
PostgreSQL 16, and builds and tests the Angular library.

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
