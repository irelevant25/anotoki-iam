-- anotoki_admin 002 - feedback: what visitors tell the site, and what its administrators did about it
--
-- The anotoki library's own table (anotoki/lib, php/migrations/admin), one shape on every site that takes
-- feedback (genshin, Piano Academy, Japanese Academy, the build analyzer). Written by the public form
-- through Anotoki\Lib\Feedback\Feedback::submit() and read on the admin panel's Feedback page.
--
-- What it keeps of the sender, and no more: the address they chose to give (none when they send it
-- without their name), their account's id while they are signed in (the IAM's sub - a name is looked up
-- when the page shows it, so an anonymized account shows nobody), the page as a path (never its query),
-- the language and the browser's name. Never where it came from: no network address and no submitter
-- hash (the owner's decision) - the limits count a keyed hash of the address bucket apart, in a file of
-- the site's storage that keeps a day of them (Feedback::submit()), never here.
--
-- Kept (the owner's numbers of 2026-10-07, each site may change them): spam 30 days, resolved 365 days;
-- the rest until somebody deletes it (Feedback::prune()). A site's extra fields go into details.
--
-- Released with anotoki-lib 0.4.0, and never edited once released: a change is a new file.

CREATE TABLE IF NOT EXISTS anotoki_feedback (
    id             BIGSERIAL     NOT NULL,
    created_at     TIMESTAMPTZ   NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at     TIMESTAMPTZ   NOT NULL DEFAULT CURRENT_TIMESTAMP,
    kind           VARCHAR(32)   NOT NULL,                 -- the site's list: bug, idea, other by default
    status         VARCHAR(16)   NOT NULL DEFAULT 'new',
    section        VARCHAR(100),                           -- the site's list of places it is about; optional
    subject        VARCHAR(200),
    message        TEXT          NOT NULL,
    details        JSONB         NOT NULL DEFAULT '{}',    -- the site's extra fields (genshin: steps, expected, actual, ...)
    contact_email  VARCHAR(254),                           -- where the sender may be answered, if they said
    user_id        BIGINT,                                 -- the signed-in sender (the IAM's sub); NULL: anonymous
    page           VARCHAR(300),                           -- the page it was sent from: a path, never a query
    language       VARCHAR(10),
    user_agent     VARCHAR(300),
    handled_by     BIGINT,                                 -- the administrator who last changed its status
    handled_at     TIMESTAMPTZ,
    CONSTRAINT anotoki_feedback_pkey PRIMARY KEY (id),
    CONSTRAINT anotoki_feedback_kind_check CHECK (kind ~ '^[a-z][a-z0-9_]{0,31}$'),
    CONSTRAINT anotoki_feedback_status_check CHECK (status IN ('new', 'read', 'resolved', 'spam')),
    CONSTRAINT anotoki_feedback_message_check CHECK (length(message) BETWEEN 1 AND 10000),
    CONSTRAINT anotoki_feedback_details_check CHECK (jsonb_typeof(details) = 'object')
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
            ('id', 'bigint'), ('created_at', 'timestamp with time zone'), ('updated_at', 'timestamp with time zone'),
            ('kind', 'character varying'), ('status', 'character varying'), ('section', 'character varying'),
            ('subject', 'character varying'), ('message', 'text'), ('details', 'jsonb'),
            ('contact_email', 'character varying'), ('user_id', 'bigint'), ('page', 'character varying'),
            ('language', 'character varying'), ('user_agent', 'character varying'),
            ('handled_by', 'bigint'), ('handled_at', 'timestamp with time zone')
        ) AS w(column_name, type_name)
    LOOP
        SELECT format_type(a.atttypid, NULL) INTO have
          FROM pg_attribute a
         WHERE a.attrelid = format('%I.%I', current_schema(), 'anotoki_feedback')::regclass
           AND a.attname = want.column_name AND a.attnum > 0 AND NOT a.attisdropped;
        IF have IS NULL THEN
            problems := problems || format('it has no column %s', want.column_name);
        ELSIF have <> want.type_name THEN
            problems := problems || format('%s is %s, where it must be %s', want.column_name, have, want.type_name);
        END IF;
    END LOOP;
    IF array_length(problems, 1) > 0 THEN
        RAISE EXCEPTION 'anotoki_admin 002: the table anotoki_feedback is not the library''s: %.', array_to_string(problems, '; ');
    END IF;
END $$;

-- The page lists by status and newest first.
CREATE INDEX IF NOT EXISTS anotoki_feedback_status_idx ON anotoki_feedback (status, created_at DESC);
CREATE INDEX IF NOT EXISTS anotoki_feedback_created_idx ON anotoki_feedback (created_at DESC);

-- end of 002
