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
