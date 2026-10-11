-----------------------------------------------------------
-- THE AUDIT LOG, INTO THE ANOTOKI LIBRARY'S TABLE
--
-- The admin panel's tools are the anotoki family's library's now (anotoki-lib
-- 0.4: the error log, the audit log and backups - one page each, the same on
-- every anotoki site). The audit log is kept in the library's table,
-- anotoki_audit_log, which the library's own set made before this file runs
-- (anotoki_admin/001 - its set runs first, before the IAM's): the IAM's
-- columns, renamed nowhere, and three the IAM leaves empty (actor_session,
-- scope_type, scope_id). From now on the IAM's audit() writes there, through
-- the library's AuditLog (api/audit.php).
--
-- This file moves every row of the IAM's own audit_log into it, each with its
-- id, its time, who, what, to what, its details and its address exactly as
-- they were - "who gave this person ADMIN" stays answerable, and a link to an
-- entry by its id still finds it. The table's sequence carries on past the
-- highest id, so no entry made afterwards takes an old one's number.
--
-- The old table is then renamed audit_log_before_018 and kept, unread, as it
-- was: a later migration drops it once both IAMs run this one. Until then the
-- anonymization of a deleted account scrubs it like the log itself
-- (scrubAuditLog()). Its name gone is what tells the IAM the move has run
-- (auditMovedToLibrary()): until then - an upload waits for Apply, and the IAM
-- keeps answering - audit() writes no entry into either table; each waits in
-- storage/logs/audit-pending.jsonl and goes in after this file, with its own
-- time. So nothing writes into audit_log while it moves, and the gate below
-- proves a move nothing raced.
--
-- No build from before this file goes on the host once it has run: its
-- audit() would write into a table that is not there (an entry lost), its
-- Audit page would fail, and its anonymization would stop on the old name.
-----------------------------------------------------------

-- The library's table is there (its set ran first), and holds nothing yet:
-- nothing writes into it before this file (the entries wait in their file).
-- Should either not be so, nothing below is the move it means to be.
DO $$
DECLARE
    unfit TEXT;
BEGIN
    IF to_regclass('anotoki_audit_log') IS NULL THEN
        RAISE EXCEPTION 'the library''s audit table anotoki_audit_log is not there: anotoki_admin/001 must run before this file';
    END IF;
    IF EXISTS (SELECT 1 FROM anotoki_audit_log) THEN
        RAISE EXCEPTION 'anotoki_audit_log holds entries already, where this file moves the IAM''s audit log in with its ids: nothing may write into it before 018';
    END IF;

    -- The library takes an action named area.verb in small letters; every one
    -- the IAM ever wrote is. One written by hand that is not would stop the
    -- move below anyway - said here by name.
    SELECT string_agg(DISTINCT action, ', ') INTO unfit
      FROM audit_log WHERE action !~ '^[a-z][a-z0-9_]*(\.[a-z0-9_]+)+$';
    IF unfit IS NOT NULL THEN
        RAISE EXCEPTION 'audit entries whose action the library''s table does not take (area.verb, small letters): %', unfit;
    END IF;
END $$;

-- Every row, as it was, with its id.
INSERT INTO anotoki_audit_log (id, created_at, actor_id, action, target_type, target_id, details, ip)
SELECT id, created_at, actor_id, action, target_type, target_id, details, ip
  FROM audit_log
 ORDER BY id;

-- The next entry takes the number after the highest; a fresh IAM's table starts at 1.
SELECT setval(pg_get_serial_sequence('anotoki_audit_log', 'id'),
              GREATEST((SELECT COALESCE(max(id), 0) FROM anotoki_audit_log), 1),
              (SELECT count(*) > 0 FROM anotoki_audit_log));

-----------------------------------------------------------
-- PROVE IT
--
-- As many rows, each one in the new table exactly as it was in the old - id,
-- time, actor, action, target, details, address - and nothing else; the new
-- columns empty on all of them; the sequence past the highest id.
-----------------------------------------------------------

DO $$
DECLARE
    before_count BIGINT;
    after_count BIGINT;
    differing BIGINT;
    next_id BIGINT;
BEGIN
    SELECT count(*) INTO before_count FROM audit_log;
    SELECT count(*) INTO after_count FROM anotoki_audit_log;
    IF before_count <> after_count THEN
        RAISE EXCEPTION 'the audit log had % entries, and % arrived', before_count, after_count;
    END IF;

    SELECT count(*) INTO differing FROM (
        (SELECT id, created_at, actor_id::BIGINT, action::TEXT, target_type::TEXT, target_id::TEXT, details, ip::TEXT FROM audit_log
         EXCEPT ALL
         SELECT id, created_at, actor_id, action::TEXT, target_type::TEXT, target_id::TEXT, details, ip::TEXT FROM anotoki_audit_log)
        UNION ALL
        (SELECT id, created_at, actor_id, action::TEXT, target_type::TEXT, target_id::TEXT, details, ip::TEXT FROM anotoki_audit_log
         EXCEPT ALL
         SELECT id, created_at, actor_id::BIGINT, action::TEXT, target_type::TEXT, target_id::TEXT, details, ip::TEXT FROM audit_log)
    ) AS changed;
    IF differing > 0 THEN
        RAISE EXCEPTION 'audit entries that did not arrive as they were: % differ', differing;
    END IF;

    IF EXISTS (SELECT 1 FROM anotoki_audit_log WHERE actor_session IS NOT NULL OR scope_type IS NOT NULL OR scope_id IS NOT NULL) THEN
        RAISE EXCEPTION 'moved audit entries with a session or a scope the IAM never wrote';
    END IF;

    SELECT nextval(pg_get_serial_sequence('anotoki_audit_log', 'id')) INTO next_id;
    IF next_id <= (SELECT COALESCE(max(id), 0) FROM anotoki_audit_log) THEN
        RAISE EXCEPTION 'the audit log''s next number, %, is one an entry has', next_id;
    END IF;
    -- Taken back: the number nextval() just used is the next entry's.
    PERFORM setval(pg_get_serial_sequence('anotoki_audit_log', 'id'),
                   GREATEST((SELECT COALESCE(max(id), 0) FROM anotoki_audit_log), 1),
                   (SELECT count(*) > 0 FROM anotoki_audit_log));
END $$;

-- Moved and proved: the old table stays, unread, under a name nothing writes to.
ALTER TABLE audit_log RENAME TO audit_log_before_018;

DO $$
BEGIN
    IF to_regclass('audit_log') IS NOT NULL OR to_regclass('audit_log_before_018') IS NULL THEN
        RAISE EXCEPTION 'the old audit log is not under its kept name';
    END IF;
END $$;
