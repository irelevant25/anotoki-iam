-- anotoki_people 001 - where the scrub of the IAM's deleted accounts stands, and the ids it scrubbed
--
-- When the IAM deletes an account, thirty days after its owner asked, every anotoki site blanks its own
-- copies of that person's name and keeps the rest of its data, nobody's now. Each site learns the ids
-- from the IAM (GET /api/public/anonymized-accounts) and scrubs its own people - the rows of its users
-- table, which the library reads and writes through the site's mapping and never makes or changes the
-- shape of. Each site adds its own users.anonymized_at in a migration of its own, before this set runs;
-- this file names no table of a site's.
--
-- Two tables of the library's own:
--   anotoki_people_sync        one row: how far the scrub has read the IAM's list (since, inclusive),
--                              when it last asked, when a run last went through every id it was given,
--                              why the last run stopped, and how many of this site's people it scrubbed
--   anotoki_people_anonymized  every id the IAM listed, whether this site knew the person or not: the
--                              site's own list of the ids it scrubbed. The IAM's decision on backups
--                              relies on such lists, and recording a person never makes a row for an id
--                              in it again
--
-- Released with anotoki-lib 0.4.0, and never edited once released: a change is a new file.

CREATE TABLE IF NOT EXISTS anotoki_people_sync (
    id          SMALLINT     NOT NULL DEFAULT 1,
    since       TIMESTAMPTZ,                        -- the newest anonymized_at handled; asked again from it (inclusive)
    checked_at  TIMESTAMPTZ,                        -- when the IAM was last asked
    ok_at       TIMESTAMPTZ,                        -- when a run last went through every id it was given
    error       TEXT,                               -- why the last run stopped (the People page shows it); NULL after a good run
    scrubbed    INTEGER      NOT NULL DEFAULT 0,    -- rows of this site's people scrubbed, all told
    CONSTRAINT anotoki_people_sync_pkey PRIMARY KEY (id),
    CONSTRAINT anotoki_people_sync_one_row CHECK (id = 1)
);

INSERT INTO anotoki_people_sync (id) VALUES (1) ON CONFLICT (id) DO NOTHING;

CREATE TABLE IF NOT EXISTS anotoki_people_anonymized (
    sub            BIGINT       NOT NULL,           -- the IAM's id of the account
    anonymized_at  TIMESTAMPTZ  NOT NULL,           -- the IAM's time, whole seconds
    scrubbed_at    TIMESTAMPTZ  NOT NULL DEFAULT now(),
    had_row        BOOLEAN      NOT NULL,           -- whether this site knew the person when the id came
    CONSTRAINT anotoki_people_anonymized_pkey PRIMARY KEY (sub),
    CONSTRAINT anotoki_people_anonymized_sub_check CHECK (sub > 0)
);

-- The two tables in the shape the library reads and writes, whoever made them first.
DO $$
DECLARE
    problems TEXT[] := ARRAY[]::TEXT[];
    want RECORD;
    have TEXT;
BEGIN
    FOR want IN
        SELECT * FROM (VALUES
            ('anotoki_people_sync', 'id', 'smallint'),
            ('anotoki_people_sync', 'since', 'timestamp with time zone'),
            ('anotoki_people_sync', 'checked_at', 'timestamp with time zone'),
            ('anotoki_people_sync', 'ok_at', 'timestamp with time zone'),
            ('anotoki_people_sync', 'error', 'text'),
            ('anotoki_people_sync', 'scrubbed', 'integer'),
            ('anotoki_people_anonymized', 'sub', 'bigint'),
            ('anotoki_people_anonymized', 'anonymized_at', 'timestamp with time zone'),
            ('anotoki_people_anonymized', 'scrubbed_at', 'timestamp with time zone'),
            ('anotoki_people_anonymized', 'had_row', 'boolean')
        ) AS w(table_name, column_name, type_name)
    LOOP
        SELECT format_type(a.atttypid, NULL) INTO have
          FROM pg_attribute a
         WHERE a.attrelid = format('%I.%I', current_schema(), want.table_name)::regclass
           AND a.attname = want.column_name
           AND a.attnum > 0
           AND NOT a.attisdropped;
        IF have IS NULL THEN
            problems := problems || format('%s has no column %s', want.table_name, want.column_name);
        ELSIF have <> want.type_name THEN
            problems := problems || format('%s.%s is %s, where it must be %s', want.table_name, want.column_name, have, want.type_name);
        END IF;
    END LOOP;

    IF NOT EXISTS (SELECT 1 FROM anotoki_people_sync WHERE id = 1) THEN
        problems := problems || 'anotoki_people_sync has no row';
    END IF;

    IF array_length(problems, 1) > 0 THEN
        RAISE EXCEPTION 'anotoki_people 001: the tables are not in the shape the library needs: %', array_to_string(problems, '; ');
    END IF;
END $$;

-- end of 001
