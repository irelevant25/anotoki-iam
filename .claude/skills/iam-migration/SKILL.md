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
name. The engine (`backend/api/migration_runner.php`) applies each file in one
transaction and records it; the same engine runs from the CLI, from `setup.php`
(every time it saves) and from the admin console's Migrations page, so a file
must work unattended on a host with no shell.

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
  out. So it adds the development addresses and the landing card only while the
  IAM's own `iam` entry has its home on this computer (the installer sets that
  home to the IAM's address), and leaves an entry of the same client id that is
  there already as it is: model it on `005_survey_application.sql`. On a live
  IAM the admin console gives the site its https address.

## Strings

The pages' and mails' words are rows (`languages`, `translation_keys`,
`translations`, from `006_languages_and_translations.sql`); the admin console
changes strings, never keys.

- **A new page string** is its key and English in
  `frontend/src/app/core/i18n/en.ts` plus a migration that inserts the key (with
  a description: where it shows, what its `{placeholders}` stand for), its
  English - the same text as `en.ts` - and its Slovak, informal ("ty"), as 006
  writes it. There is no `sk.ts`. A new mail string is a `MAIL_KEYS` entry and a
  `MAIL_FALLBACK` English in `api/mail.php` plus the same migration rows.
  `tests/translations.test.php` holds `en.ts`, `MAIL_FALLBACK` and the seeded
  English together, and `php translations.php --status` names a key a page uses
  that no migration wrote.
- **`ON CONFLICT DO NOTHING` never overwrites**: a string reworded in the
  console is the owner's and a re-seed steps aside for it. A rewording that must
  reach live IAMs is an `UPDATE` of that row, and says in the header that it
  replaces whatever the console made of it (the owner exports first -
  `docs/operations.md`). A removed key is a `DELETE FROM translation_keys`; its
  strings go with it.
- **The gate names its own keys.** Prove that every key this file writes has
  its English and Slovak row. Do not copy 006's form ("every key has en and sk,
  total = 456"): the console deletes a Slovak string by blanking it and makes no
  keys, so a later gate that checks every key, or a total, fails on a live IAM
  for something the owner did on purpose.
- **One string on a line**, an apostrophe written twice and nothing else
  escaped: that is all the statement splitter (`api/migration_runner.php`)
  knows of a string. A mail string is one line, no address, within its length
  (`translationsCheck()` is the rule; a migration is not checked by it, so keep
  to it by hand).

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
