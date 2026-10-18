-- anotoki_admin 001 - the audit log: what was done on the site, by whom, to what
--
-- The anotoki library's own table (anotoki/lib, php/migrations/admin), one shape on every site: the
-- IAM's audit_log - an action's name, what it was done to, free details, and the requester's address
-- where the site keeps one (the IAM) - with the genshin site's two additions: the session, and what the
-- target belongs to (scope: a character's whole history, not only the row of one of its talents).
-- Written by Anotoki\Lib\Audit\AuditLog (it never throws, and writes behind a savepoint inside the
-- caller's transaction) and read by the admin panel's Audit page.
--
-- No foreign key: the sites key people by the IAM's sub and the IAM by users.id, and a key into a site's
-- table would keep a backup's restore from leaving this table as it is (a restore keeps the audit log,
-- with its own entry in it). Kept forever, as both sites keep theirs; an anonymized account's address and
-- names are scrubbed from it (AuditLog::scrubPeople()).
--
-- The library's set anotoki_admin runs FIRST, before the site's own sets: the sites' own migrations move
-- their old rows in here (the IAM's audit_log, genshin's audit_logs). Nothing but the library makes a table
-- named anotoki_*, so this file never meets one of a site's.
--
-- Released with anotoki-lib 0.4.0, and never edited once released: a change is a new file.

CREATE TABLE IF NOT EXISTS anotoki_audit_log (
    id             BIGSERIAL     NOT NULL,
    created_at     TIMESTAMPTZ   NOT NULL DEFAULT CURRENT_TIMESTAMP,
    actor_id       BIGINT,                 -- who: the site's person (the IAM's users.id; the sites: the IAM's sub); NULL: the system
    actor_session  VARCHAR(64),            -- the session it was done in (the IAM's sid on the sites, sessions.id on the IAM); optional
    action         VARCHAR(64)   NOT NULL, -- what: area.verb, small letters ("user.roles_set", "backup.restored")
    target_type    VARCHAR(64),            -- to what: "user", "backup", a table's name
    target_id      VARCHAR(100),
    scope_type     VARCHAR(64),            -- what the target belongs to (genshin: the entity a child row is part of)
    scope_id       VARCHAR(100),
    details        JSONB,                  -- what changed: free, the site's; at most 64 KB as the library writes it
    ip             VARCHAR(45),            -- the requester's address, only where the site keeps it (the IAM), from its trusted proxy
    CONSTRAINT anotoki_audit_log_pkey PRIMARY KEY (id),
    CONSTRAINT anotoki_audit_log_action_check CHECK (action ~ '^[a-z][a-z0-9_]*(\.[a-z0-9_]+)+$')
);

-- A table of that name made some other way would pass CREATE TABLE IF NOT EXISTS unseen: the columns the
-- library writes must be there, of these types.
DO $$
DECLARE
    problems TEXT[] := ARRAY[]::TEXT[];
    want RECORD;
    have TEXT;
BEGIN
    FOR want IN
        SELECT * FROM (VALUES
            ('id', 'bigint'), ('created_at', 'timestamp with time zone'), ('actor_id', 'bigint'),
            ('actor_session', 'character varying'), ('action', 'character varying'), ('target_type', 'character varying'),
            ('target_id', 'character varying'), ('scope_type', 'character varying'), ('scope_id', 'character varying'),
            ('details', 'jsonb'), ('ip', 'character varying')
        ) AS w(column_name, type_name)
    LOOP
        SELECT format_type(a.atttypid, NULL) INTO have
          FROM pg_attribute a
         WHERE a.attrelid = format('%I.%I', current_schema(), 'anotoki_audit_log')::regclass
           AND a.attname = want.column_name AND a.attnum > 0 AND NOT a.attisdropped;
        IF have IS NULL THEN
            problems := problems || format('it has no column %s', want.column_name);
        ELSIF have <> want.type_name THEN
            problems := problems || format('%s is %s, where it must be %s', want.column_name, have, want.type_name);
        END IF;
    END LOOP;
    IF array_length(problems, 1) > 0 THEN
        RAISE EXCEPTION 'anotoki_admin 001: the table anotoki_audit_log is not the library''s: %.', array_to_string(problems, '; ');
    END IF;
END $$;

-- The page reads the newest first, a person's history, one target's, one scope's, and actions by their
-- beginning ("account." finds every account.*).
CREATE INDEX IF NOT EXISTS anotoki_audit_log_created_idx ON anotoki_audit_log (created_at DESC, id DESC);
CREATE INDEX IF NOT EXISTS anotoki_audit_log_actor_idx ON anotoki_audit_log (actor_id, created_at DESC);
CREATE INDEX IF NOT EXISTS anotoki_audit_log_target_idx ON anotoki_audit_log (target_type, target_id);
CREATE INDEX IF NOT EXISTS anotoki_audit_log_scope_idx ON anotoki_audit_log (scope_type, scope_id) WHERE scope_type IS NOT NULL;
CREATE INDEX IF NOT EXISTS anotoki_audit_log_action_idx ON anotoki_audit_log (action text_pattern_ops);

-- end of 001
