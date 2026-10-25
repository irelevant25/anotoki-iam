-- anotoki_admin 003 - site settings: the switches an administrator throws without a deploy, and who sees each page
--
-- The anotoki library's own tables (anotoki/lib, backend/migrations/admin), one shape on every site - the genshin
-- site's site_settings and site_routes made general (the owner's request of 2026-10-25). Written and read by
-- Anotoki\Lib\SiteSettings\SiteSettings: the admin panel's Site settings page, the site's gate and its public
-- settings. A site offers the parts it chooses (the IAM: the announcement alone).
--
-- anotoki_site_settings: a row per switch - "Getting in" (maintenance_mode, maintenance_message) and "Announcement"
-- (announcement_enabled, announcement_level, announcement_message) - its value as JSON (true or false, a level's
-- name, a message per language), when it last changed and by whom. A switch with no row is at its default (off,
-- information, nothing written): the state every site is in before anybody touches a switch, so this file seeds
-- nothing. The value is checked on its way in, and read back defensively: a value nothing understands is the
-- default, never a site that will not start.
--
-- anotoki_page_access: "Page access" - a row per page path as the site's router declares it (a ':name' segment
-- stands for any one segment), who sees it (visibility: PUBLIC anybody, USER signed in, EDITOR editors and
-- administrators, ADMIN administrators only), whether it is switched off for everybody but the administrators
-- (blocked), and the API paths refused with it (api_paths, each matched from its start; none: the page alone). A
-- page with no row is anybody's. The administrators are never refused anything here.
--
-- No foreign key: the sites key people by the IAM's sub and the IAM by users.id (updated_by), and a key into a
-- site's table would keep a backup's restore from leaving these tables as they are. A site copies its own rows
-- across in a migration of its own, after this file (genshin's site_settings and site_routes: README.md).
--
-- The library's set anotoki_admin runs FIRST, before the site's own sets. Nothing but the library makes a table
-- named anotoki_*, so this file never meets one of a site's.
--
-- Released with anotoki-lib 0.11.0, and never edited once released: a change is a new file.

CREATE TABLE IF NOT EXISTS anotoki_site_settings (
    name        VARCHAR(64)   NOT NULL,                 -- the switch: maintenance_mode, announcement_level, ...
    value       JSONB         NOT NULL,                 -- true or false, a level's name, {"en": "...", "sk": "..."}
    updated_at  TIMESTAMPTZ   DEFAULT CURRENT_TIMESTAMP,
    updated_by  BIGINT,                                 -- who changed it last (the IAM's sub on the sites); NULL: nobody here
    CONSTRAINT anotoki_site_settings_pkey PRIMARY KEY (name),
    CONSTRAINT anotoki_site_settings_name_check CHECK (name ~ '^[a-z][a-z0-9_]{0,63}$')
);

CREATE TABLE IF NOT EXISTS anotoki_page_access (
    id          BIGSERIAL     NOT NULL,
    path        VARCHAR(255)  NOT NULL,                 -- as the router declares it: '/', '/quizzes', '/database/characters/:id'
    visibility  VARCHAR(16)   NOT NULL DEFAULT 'ADMIN', -- the least a reader must be: PUBLIC, USER, EDITOR, ADMIN
    blocked     BOOLEAN       NOT NULL DEFAULT FALSE,   -- switched off for everybody but the administrators
    api_paths   TEXT[]        NOT NULL DEFAULT '{}',    -- '/api/quiz' refuses /api/quizzes and /api/quiz/stats alike
    sort_order  INTEGER       NOT NULL DEFAULT 0,
    updated_at  TIMESTAMPTZ   DEFAULT CURRENT_TIMESTAMP,
    updated_by  BIGINT,
    CONSTRAINT anotoki_page_access_pkey PRIMARY KEY (id),
    CONSTRAINT anotoki_page_access_path_key UNIQUE (path),
    CONSTRAINT anotoki_page_access_path_check CHECK (path ~ '^/'),
    CONSTRAINT anotoki_page_access_visibility_check CHECK (visibility IN ('PUBLIC', 'USER', 'EDITOR', 'ADMIN')),
    CONSTRAINT anotoki_page_access_api_paths_check CHECK (cardinality(api_paths) <= 20)
);

-- A table of either name made some other way would pass CREATE TABLE IF NOT EXISTS unseen: the columns the library
-- writes must be there, of these types.
DO $$
DECLARE
    problems TEXT[] := ARRAY[]::TEXT[];
    want RECORD;
    have TEXT;
BEGIN
    FOR want IN
        SELECT * FROM (VALUES
            ('anotoki_site_settings', 'name', 'character varying'), ('anotoki_site_settings', 'value', 'jsonb'),
            ('anotoki_site_settings', 'updated_at', 'timestamp with time zone'), ('anotoki_site_settings', 'updated_by', 'bigint'),
            ('anotoki_page_access', 'id', 'bigint'), ('anotoki_page_access', 'path', 'character varying'),
            ('anotoki_page_access', 'visibility', 'character varying'), ('anotoki_page_access', 'blocked', 'boolean'),
            ('anotoki_page_access', 'api_paths', 'text[]'), ('anotoki_page_access', 'sort_order', 'integer'),
            ('anotoki_page_access', 'updated_at', 'timestamp with time zone'), ('anotoki_page_access', 'updated_by', 'bigint')
        ) AS w(table_name, column_name, type_name)
    LOOP
        SELECT format_type(a.atttypid, NULL) INTO have
          FROM pg_attribute a
         WHERE a.attrelid = format('%I.%I', current_schema(), want.table_name)::regclass
           AND a.attname = want.column_name AND a.attnum > 0 AND NOT a.attisdropped;
        IF have IS NULL THEN
            problems := problems || format('%s has no column %s', want.table_name, want.column_name);
        ELSIF have <> want.type_name THEN
            problems := problems || format('%s.%s is %s, where it must be %s', want.table_name, want.column_name, have, want.type_name);
        END IF;
    END LOOP;
    IF array_length(problems, 1) > 0 THEN
        RAISE EXCEPTION 'anotoki_admin 003: the site settings'' tables are not the library''s: %.', array_to_string(problems, '; ');
    END IF;
END $$;

-- end of 003
