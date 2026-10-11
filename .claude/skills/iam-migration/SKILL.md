---
name: iam-migration
description: >
  Write and apply a PostgreSQL migration for the IAM in the house style. Use for
  "add a table/column", "new migration", "change the schema", "seed an
  application", "backfill". The migrations are the schema - there is no other
  description of it.
---

# Write an IAM migration

`backend/migrations/iam/NNN_name.sql`, the next free number, a short snake_case
name. The engine - the anotoki family's, anotoki/lib's `Migrator`, wired in
`backend/api/migration_runner.php` (`iamMigrator()`) - applies each file in one
transaction and records it: the library's admin set first (`anotoki_admin`:
the admin tools' tables - a file here may move rows into them, as 018 moved
the audit log, since they are there before it), then the IAM's files, then the
library's translations set (`anotoki_translations`, which a file here never
depends on, and which never depends on one here); the same engine runs from the CLI, from `setup.php`
(every time it saves) and from the admin panel's Migrations page, one apply at
a time, so a file must work unattended on a host with no shell. The IAM keeps
answering while a file waits (it does not block, unlike the other sites), so
code that reads what a migration makes checks for it first (`*Ready()`).

## House style

- **A header comment** in prose: what changes and why, the way the existing
  files open. Not a restatement of the SQL.
- **TIMESTAMPTZ** for every time, `DEFAULT CURRENT_TIMESTAMP`; connections run in
  UTC.
- **Secrets**: a column holding something that opens an account stores its
  SHA-256 (`CHAR(64)`); a column holding something that must be read back (a TOTP
  secret, a private key) stores `encryptSecret()` output (`TEXT`, `v1:` prefix).
  Say which in the comment.
- **Closed sets are CHECKs** on the column (`CHECK (kind IN ('a', 'b'))`), not
  lookup tables or ENUMs.
- **Case-insensitive uniqueness** by `CREATE UNIQUE INDEX … ON t (lower(col))`
  where people type the value.
- **`IF NOT EXISTS` / `ON CONFLICT DO NOTHING`** so a file is safe to reason about.
- **A `DO $$ … $$` gate** at the end of any migration that changes or seeds data,
  raising an exception when the result is not what it should be.
- **Never edit an applied migration** - write the next one.
- **Seeded applications carry development addresses** - the port table
  (`{frontend}/auth/callback`, `{frontend}/`, the origin; see `README.md`) - and
  never a production one. A real host's install (`setup.php`) takes every
  loopback address of the seeded sites out and registers the production ones it
  is given; a new first-party site seeded here goes into `SETUP_SITES` in
  `setup.php` and `DEV_CLIENTS` in `clients.php` too, and the installer's tests.
- **A seeding migration reaches installed IAMs too** - through the Migrations
  page, one of them live - where no install follows to take a loopback address
  out. So it adds the development addresses only while the
  IAM's own `iam` entry has its home on this computer (the installer sets that
  home to the IAM's address), and leaves an entry of the same client id that is
  there already as it is: model it on `005_survey_application.sql`. On a live
  IAM the admin panel gives the site its https address.
- **A first-party site has ADMIN and EDITOR** (`007_every_site_for_every_account.sql`,
  the owner's decision; MEMBER beside them since 010, below), and the IAM's
  administrators are given ADMIN in every application that has one (a grant at
  install, import and in 007 - not a rule kept afterwards): a
  migration that seeds a site gives its ADMIN to the IAM's administrators
  there (005's last insert); one that changes roles or access touches only what
  is there - a site deleted in the admin panel stays deleted, a role
  reworded there keeps its words (compare against the words the earlier
  migration wrote, as 007 does). A migration that changes who holds a role is
  a permission decision: the owner says so first, and `docs/decisions.md`
  records it.
- **MEMBER is every account's, in every application** (`010_sign_in_per_site.sql`):
  an application a migration inserts gets MEMBER, and every account it, from
  010's trigger - never insert or grant MEMBER by hand, never take it from
  people in a migration. A seeding migration sets the site's `login_required`
  and, if it must, which roles may sign in (`may_sign_in`). Grants inserted
  next to the triggers need `ON CONFLICT DO NOTHING`. Test helpers:
  `buildIamThrough()` / `applyMigrationsThrough()` (`tests/lib.php`) build a
  database up to a file and no further - a migration's own test asserts the
  state its file leaves, not what 010 makes of it.
- **Every site has the same roles, ADMIN, EDITOR and MEMBER**
  (`011_public_clients_static_roles.sql`; the IAM's entry ADMIN and MEMBER):
  011's trigger makes all three with an application, in generic words, and
  gives none of ADMIN / EDITOR to anybody. A seeding migration that wants its
  site's own words rewords them after the application's insert (the trigger
  has run by then, so an `INSERT ... ON CONFLICT DO NOTHING` of the roles, as
  005 does, changes nothing): `UPDATE ... WHERE description = <the trigger's
  words>`, which leaves a rewording of the owner's alone. Never add a
  fourth role: the admin panel cannot remove one. Every application is a
  public client: never seed `type` or a secret.
- **An anonymized account's row stays one** (`users_anonymized_check`, 016):
  switched off, unconfirmed, with no password, no password sign-in, no TOTP
  secret and no two-factor. A migration that updates those columns
  (`password`, `password_login_enabled`, `totp_secret`, `totp_enabled`,
  `email_confirmed`, `disabled`) on every row fails on the anonymized ones -
  it must exclude them: `WHERE anonymized_at IS NULL`.
- **Test it on a database shaped like an installed one**, not only on a fresh
  one: build the migrations before it, add what an owner would have (an
  administrator, grants, edits made in the admin panel), then apply -
  `tests/survey-application.test.php` (005) and `tests/every-site.test.php`
  (007) are the pattern.

## Strings

The pages' and mails' words are rows (`languages`, `translation_keys`,
`translations`, from `006_languages_and_translations.sql`); the admin panel
changes strings, never keys. The code that keeps them is the anotoki
family's library's (anotoki-lib's `Translations`, the IAM's configuration in
`api/translations.php`), and the library writes words of its own into the same
tables, under `anotoki.`, with its migration set `anotoki_translations`, which
runs after the IAM's files.

- **A new page string** is its key and English in
  `frontend/src/app/core/i18n/en.ts` plus a migration that inserts the key (with
  a description: where it shows, what its `{placeholders}` stand for), its
  English - the same text as `en.ts` - and its Slovak, informal ("ty"), as 006
  writes it. There is no `sk.ts`. A new mail string is a `MAIL_KEYS` entry and a
  `MAIL_FALLBACK` English in `api/mail.php` plus the same migration rows.
  `tests/translations.test.php` holds `en.ts`, `MAIL_FALLBACK` and the seeded
  English together, and `php translations.php --status` names a key a page uses
  that no migration wrote. It also counts what the migrations leave (after
  018 and the library's sets: 625 keys - 483 of the pages, 48 of the mails, 94
  of the library's - 1250 strings): a migration that adds or removes keys
  moves those numbers, and `tests/top-bar-strings.test.php` is the pattern for
  testing one on an installed IAM. A migration test that builds a database
  "up to" its file and applies what is pending applies every later file too
  - the library's set included - so assert on its own file
  (`applyMigrationsThrough()`, or `['applied'][0]`), not on the list.
- **`anotoki.` is the library's namespace.** Never write a key there; the
  library's 002 writes them and their descriptions. 017 moved the IAM's
  `lang.*` there once, and that is the pattern for moving another word the
  library takes over: copy every string of the old key, with `updated_at` and
  `updated_by`, under the library's key (`ON CONFLICT DO NOTHING`), delete the
  old key, and prove in the gate that every string arrived
  (`tests/strings-through-the-library.test.php` is its test). To word a
  library string the IAM's own way, pre-empt it: `INSERT ... ON CONFLICT DO
  NOTHING` (wins on a fresh database, where this file runs first), then
  `UPDATE ... WHERE value = '<the library's words>'` (wins on an installed one).
- **No key ends in `.one`, `.few` or `.other`**: the family's key scan reads
  that as a form of a plural family, and the IAM has none (017 renamed the two
  that did to `.unknown`).
- **`ON CONFLICT DO NOTHING` never overwrites**: a string reworded in the
  panel is the owner's and a re-seed steps aside for it. A rewording that must
  reach live IAMs is an `UPDATE` of that row, and says in the header that it
  replaces whatever the panel made of it (the owner exports first -
  `docs/operations.md`) - or, kinder, an `UPDATE ... WHERE value = '<the
  earlier migration's words>'`, which leaves the owner's rewording alone
  (`009_admin_panel.sql`, tested by `tests/admin-panel-strings.test.php`).
  A removed key is a `DELETE FROM translation_keys`; its strings go with it.
  A renamed key is an `UPDATE translation_keys SET name = ...`: its strings
  follow by the foreign key's `ON UPDATE CASCADE`.
- **The gate names its own keys.** Prove that every key this file writes has
  its English and Slovak row. Do not copy 006's form ("every key has en and sk,
  total = 456"), and never count keys outside the IAM's own namespaces
  (`WHERE name NOT LIKE 'anotoki.%'`): the panel deletes a Slovak string by
  blanking it and makes no keys, and the library adds its own, so a later gate
  that checks every key, or a total, fails on a live IAM for something nobody
  did wrong.
- **One string on a line**, an apostrophe written twice and nothing else
  escaped: the house's form, which the statement splitter (anotoki/lib's
  `Splitter`) and a reader both take at a glance. A mail string is one line, no
  address, no character that turns the text's direction and no C1 control,
  within its length and its bytes (`IamMailRules` in `api/translations.php` is
  the rule; a migration is not checked by it, so keep to it by hand - the
  catalogue test holds every stored string to it). A migration writes only the
  base columns of the three tables (`translation_keys (name, description)`,
  `translations (key_name, language_code, value)`, `languages (code, name,
  native_name, enabled, sort_order)`), and keeps whatever it needs for its
  gate in a table of its own that it drops at its end - never a temporary
  table, which needs the TEMP privilege no other IAM migration needs (017).

## Apply and check

```bash
cd backend
php migration.php --dry-run
php migration.php
php migration.php --status
php tests/run.php          # the tests build their databases from the same files
```

If the change affects an answer, update `docs/api.md`, the frontend's
`core/api/models.ts`, and the tests in the same change.
