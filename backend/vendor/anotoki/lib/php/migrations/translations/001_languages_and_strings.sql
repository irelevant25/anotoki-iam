-- anotoki_translations 001 - the languages, the keys and the strings: the anotoki family's one schema
--
-- Every anotoki site keeps its words in three tables, which the IAM wrote first and the other sites
-- copied: languages, translation_keys and translations. This file is the anotoki library's own
-- (anotoki/lib, php/migrations/translations), and a site runs it after its own migrations, which made
-- the tables wherever a site has them. There it makes nothing: it proves the shape the library relies on,
-- and refuses - naming every difference - a shape it cannot work with. On a site born without the tables
-- it makes them, with English and Slovak.
--
-- What the library relies on, and the first check below holds a table that is there to: the columns,
-- of compatible types; the three primary keys (its writes name them in ON CONFLICT); the two foreign keys
-- of translations, cascading on update and on delete (a language deleted takes its strings with it, a
-- key renamed takes them along); the CHECK that keeps an empty string out (no row is what reads the
-- English); no column of the site's own that must be filled and has no default (the library's writes do
-- not fill it). The last check: English there and switched on. What it leaves to each site: updated_by an
-- INT or a BIGINT, and a foreign key from it to the site's people; other tables referring to languages
-- (code); the constraints' names; columns of the site's own that have a default.
--
-- Released with anotoki-lib 0.3.0, and never edited once released: a change is a new file.

-- The words are UTF-8: a database that keeps its text otherwise cannot hold them.
DO $$
BEGIN
    IF current_setting('server_encoding') <> 'UTF8' THEN
        RAISE EXCEPTION 'anotoki_translations 001: this database keeps its text as %, and the words need UTF8. Make the database again with the encoding UTF8.', current_setting('server_encoding');
    END IF;
END $$;

-- What is there already must be in the shape the library relies on. Every difference is named, at once.
DO $$
DECLARE
    problems TEXT[] := ARRAY[]::TEXT[];
    there TEXT[];
    known TEXT[] := ARRAY[]::TEXT[];
    want RECORD;
    have RECORD;
    primary_key TEXT[];
BEGIN
    SELECT COALESCE(array_agg(c.relname::TEXT), ARRAY[]::TEXT[]) INTO there
      FROM pg_class c
      JOIN pg_namespace n ON n.oid = c.relnamespace
     WHERE n.nspname = current_schema()
       AND c.relkind IN ('r', 'p')
       AND c.relname IN ('languages', 'translation_keys', 'translations');

    -- The columns: of a type that will do, never NULL where the library reads a value, with a default
    -- where its writes give none.
    FOR want IN
        SELECT * FROM (VALUES
            ('languages', 'code', ARRAY['character varying', 'text'], TRUE, FALSE),
            ('languages', 'name', ARRAY['character varying', 'text'], TRUE, FALSE),
            ('languages', 'native_name', ARRAY['character varying', 'text'], TRUE, FALSE),
            ('languages', 'enabled', ARRAY['boolean'], TRUE, FALSE),
            ('languages', 'sort_order', ARRAY['integer'], TRUE, FALSE),
            ('translation_keys', 'name', ARRAY['character varying', 'text'], TRUE, FALSE),
            ('translation_keys', 'description', ARRAY['text', 'character varying'], FALSE, FALSE),
            ('translation_keys', 'created_at', ARRAY['timestamp with time zone'], FALSE, TRUE),
            ('translations', 'key_name', ARRAY['character varying', 'text'], TRUE, FALSE),
            ('translations', 'language_code', ARRAY['character varying', 'text'], TRUE, FALSE),
            ('translations', 'value', ARRAY['text', 'character varying'], TRUE, FALSE),
            ('translations', 'updated_at', ARRAY['timestamp with time zone'], FALSE, TRUE),
            ('translations', 'updated_by', ARRAY['integer', 'bigint'], FALSE, FALSE)
        ) AS w(table_name, column_name, types, not_null, defaulted)
    LOOP
        known := known || (want.table_name || '.' || want.column_name);
        CONTINUE WHEN NOT (want.table_name = ANY (there));

        SELECT format_type(a.atttypid, NULL) AS type_name, a.attnotnull AS not_null, a.atthasdef AS defaulted INTO have
          FROM pg_attribute a
         WHERE a.attrelid = format('%I.%I', current_schema(), want.table_name)::regclass
           AND a.attname = want.column_name
           AND a.attnum > 0
           AND NOT a.attisdropped;

        IF NOT FOUND THEN
            problems := problems || format('%s has no column %s', want.table_name, want.column_name);
        ELSIF NOT (have.type_name = ANY (want.types)) THEN
            problems := problems || format('%s.%s is %s, where it must be %s', want.table_name, want.column_name, have.type_name, array_to_string(want.types, ' or '));
        ELSIF want.not_null AND NOT have.not_null THEN
            problems := problems || format('%s.%s may be NULL, where it must be NOT NULL', want.table_name, want.column_name);
        ELSIF want.defaulted AND NOT have.defaulted THEN
            problems := problems || format('%s.%s has no default, where the library''s writes leave it to one', want.table_name, want.column_name);
        END IF;
    END LOOP;

    -- A column of the site's own that must be filled and has no default: the library's writes leave it empty.
    FOR have IN
        SELECT c.relname::TEXT AS table_name, a.attname::TEXT AS column_name
          FROM pg_attribute a
          JOIN pg_class c ON c.oid = a.attrelid
          JOIN pg_namespace n ON n.oid = c.relnamespace
         WHERE n.nspname = current_schema()
           AND c.relname IN ('languages', 'translation_keys', 'translations')
           AND c.relkind IN ('r', 'p')
           AND a.attnum > 0
           AND NOT a.attisdropped
           AND a.attnotnull
           AND NOT a.atthasdef
           AND a.attidentity = ''
         ORDER BY c.relname, a.attnum
    LOOP
        IF NOT ((have.table_name || '.' || have.column_name) = ANY (known)) THEN
            problems := problems || format('%s.%s must be filled and has no default, and the library''s writes do not fill it', have.table_name, have.column_name);
        END IF;
    END LOOP;

    -- The primary keys, which the library's writes name in ON CONFLICT.
    FOR want IN
        SELECT * FROM (VALUES
            ('languages', ARRAY['code']),
            ('translation_keys', ARRAY['name']),
            ('translations', ARRAY['key_name', 'language_code'])
        ) AS w(table_name, columns)
    LOOP
        CONTINUE WHEN NOT (want.table_name = ANY (there));

        SELECT array_agg(a.attname::TEXT ORDER BY a.attname) INTO primary_key
          FROM pg_index i
          JOIN pg_attribute a ON a.attrelid = i.indrelid AND a.attnum = ANY (i.indkey)
         WHERE i.indrelid = format('%I.%I', current_schema(), want.table_name)::regclass
           AND i.indisprimary;

        IF primary_key IS NULL THEN
            problems := problems || format('%s has no primary key, where it must be (%s)', want.table_name, array_to_string(want.columns, ', '));
        ELSIF primary_key <> (SELECT array_agg(c ORDER BY c) FROM unnest(want.columns) AS c) THEN
            problems := problems || format('the primary key of %s is (%s), where it must be (%s)', want.table_name, array_to_string(primary_key, ', '), array_to_string(want.columns, ', '));
        END IF;
    END LOOP;

    IF 'translations' = ANY (there) THEN
        -- The two foreign keys, cascading both ways.
        FOR want IN
            SELECT * FROM (VALUES
                ('key_name', 'translation_keys', 'name'),
                ('language_code', 'languages', 'code')
            ) AS w(column_name, target, target_column)
        LOOP
            IF NOT EXISTS (
                SELECT 1
                  FROM pg_constraint c
                 WHERE c.contype = 'f'
                   AND c.conrelid = format('%I.%I', current_schema(), 'translations')::regclass
                   AND c.confrelid = to_regclass(format('%I.%I', current_schema(), want.target))
                   AND c.conkey = ARRAY[(SELECT a.attnum FROM pg_attribute a WHERE a.attrelid = c.conrelid AND a.attname = want.column_name)]
                   AND c.confkey = ARRAY[(SELECT a.attnum FROM pg_attribute a WHERE a.attrelid = c.confrelid AND a.attname = want.target_column)]
                   AND c.confupdtype = 'c'
                   AND c.confdeltype = 'c'
            ) THEN
                problems := problems || format('translations.%s has no foreign key to %s (%s) ON UPDATE CASCADE ON DELETE CASCADE', want.column_name, want.target, want.target_column);
            END IF;
        END LOOP;

        -- An empty string is no row: no row is what reads the English.
        IF NOT EXISTS (
            SELECT 1
              FROM pg_constraint c
             WHERE c.contype = 'c'
               AND c.conrelid = format('%I.%I', current_schema(), 'translations')::regclass
               AND c.conkey = ARRAY[(SELECT a.attnum FROM pg_attribute a WHERE a.attrelid = c.conrelid AND a.attname = 'value')]
               AND pg_get_constraintdef(c.oid) LIKE '%<> ''''%'
        ) THEN
            problems := problems || 'translations has no CHECK (value <> '''') - an empty string must be no row'::TEXT;
        END IF;
    END IF;

    IF array_length(problems, 1) > 0 THEN
        RAISE EXCEPTION 'anotoki_translations 001: the tables of the words are not in the shape the anotoki library needs: %. A site whose tables have another shape moves them into this one in a migration of its own first (anotoki-lib''s README, "Translations").', array_to_string(problems, '; ');
    END IF;
END $$;

CREATE TABLE IF NOT EXISTS languages (
    code         VARCHAR(10)   NOT NULL,
    name         VARCHAR(50)   NOT NULL,              -- in English: "Slovak"
    native_name  VARCHAR(50)   NOT NULL,              -- as its speakers write it: "Slovenčina"
    enabled      BOOLEAN       NOT NULL DEFAULT TRUE, -- offered to readers
    sort_order   INTEGER       NOT NULL DEFAULT 0,    -- lowest first; then by name, then by code
    CONSTRAINT languages_pkey PRIMARY KEY (code),
    CONSTRAINT languages_code_check CHECK (code ~ '^[a-z]{2}(-[a-z]{2})?$')
);

CREATE TABLE IF NOT EXISTS translation_keys (
    name         VARCHAR(200)  NOT NULL,              -- namespace.segment[.segment]; written by migrations only
    description  TEXT,                                -- for the translator: the code's, never the admin pages'
    created_at   TIMESTAMPTZ   NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT translation_keys_pkey PRIMARY KEY (name)
);

CREATE TABLE IF NOT EXISTS translations (
    key_name       VARCHAR(200)  NOT NULL,
    language_code  VARCHAR(10)   NOT NULL,
    value          TEXT          NOT NULL,            -- no row: the key reads in English
    updated_at     TIMESTAMPTZ   NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_by     BIGINT,                            -- the anotoki account (sub) that saved it; NULL: a migration or the command line
    CONSTRAINT translations_pkey PRIMARY KEY (key_name, language_code),
    CONSTRAINT translations_key_name_foreign FOREIGN KEY (key_name)
        REFERENCES translation_keys (name) ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT translations_language_code_foreign FOREIGN KEY (language_code)
        REFERENCES languages (code) ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT translations_value_check CHECK (value <> '')
);

-- English, which every other language falls back to, and Slovak: the library writes its own words in both.
INSERT INTO languages (code, name, native_name, sort_order) VALUES
    ('en', 'English', 'English', 1),
    ('sk', 'Slovak', 'Slovenčina', 2)
ON CONFLICT (code) DO NOTHING;

DO $$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM languages WHERE code = 'en' AND enabled) THEN
        RAISE EXCEPTION 'anotoki_translations 001: English (en) must be there and switched on: every other language falls back to it.';
    END IF;
END $$;

-- end of 001
