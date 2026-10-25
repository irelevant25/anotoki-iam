-----------------------------------------------------------
-- TWO LEFTOVERS DROPPED: APPLICATIONS.ACCESS AND AUDIT_LOG_BEFORE_018
--
-- The owner's yes of 2026-10-25. Two things were kept until "a later
-- migration drops it, once both IAMs run" the file that left them over - and
-- both IAMs, this computer's and the host's, have run 010 and 018 since:
--
-- - applications.access, the rule before 010 ('everyone', or 'members': a
--   role there held), read only while 010 was pending - the code's
--   signInRulesReady(), which this release takes away with every reader of
--   the column. Who may sign in has been each role's may_sign_in since 010,
--   on both IAMs, and stays so: nothing that decides a sign-in changes here.
-- - audit_log_before_018, the IAM's own audit log as 018 left it once every
--   entry, with its id, was in the library's anotoki_audit_log - a move 018
--   proved in its gate. Unread since, and scrubbed of a deleted person like
--   the log itself; this release's anonymization no longer scrubs it. Every
--   event in it is in anotoki_audit_log: dropping it loses none.
--
-- 011's leftovers - type, secret_hash, show_on_landing - stay, unread, for a
-- migration of their own.
--
-- A build from before this file still runs on a database it has run on: once
-- 010 and 018 are applied that build reads neither. So the way back is open,
-- but for what is gone: the copy is not there to bring back.
--
-- Before anything goes, the gate asks that 010 and 018 are what they say -
-- each role's may_sign_in and each application's login_required, 010's two
-- triggers that give every account MEMBER everywhere; the library's audit
-- table, and the old table's name gone. On a database without them this file
-- would drop the one thing it still reads, so it stops instead, naming what
-- is missing. After the drops, it proves both gone.
-----------------------------------------------------------

DO $$
DECLARE
    missing TEXT[] := ARRAY[]::TEXT[];
BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_attribute WHERE attrelid = to_regclass('application_roles') AND attname = 'may_sign_in' AND NOT attisdropped) THEN
        missing := missing || 'application_roles.may_sign_in (010)';
    END IF;
    IF NOT EXISTS (SELECT 1 FROM pg_attribute WHERE attrelid = to_regclass('applications') AND attname = 'login_required' AND NOT attisdropped) THEN
        missing := missing || 'applications.login_required (010)';
    END IF;
    IF NOT EXISTS (SELECT 1 FROM pg_trigger WHERE tgrelid = to_regclass('users') AND tgname = 'trg_users_member_everywhere' AND NOT tgisinternal) THEN
        missing := missing || 'the trigger trg_users_member_everywhere on users (010)';
    END IF;
    IF NOT EXISTS (SELECT 1 FROM pg_trigger WHERE tgrelid = to_regclass('applications') AND tgname = 'trg_applications_member_role' AND NOT tgisinternal) THEN
        missing := missing || 'the trigger trg_applications_member_role on applications (010, 011)';
    END IF;
    IF to_regclass('anotoki_audit_log') IS NULL THEN
        missing := missing || 'the library''s audit table anotoki_audit_log (anotoki_admin/001, 018)';
    END IF;
    IF to_regclass('audit_log') IS NOT NULL THEN
        missing := missing || 'the move of audit_log into anotoki_audit_log (018: the old name is still there)';
    END IF;
    IF array_length(missing, 1) IS NOT NULL THEN
        RAISE EXCEPTION 'this file drops what an IAM without 010 and 018 still reads, and this database lacks: %', array_to_string(missing, '; ');
    END IF;
END $$;

ALTER TABLE applications DROP COLUMN IF EXISTS access;

DROP TABLE IF EXISTS audit_log_before_018;

DO $$
BEGIN
    IF EXISTS (SELECT 1 FROM pg_attribute WHERE attrelid = to_regclass('applications') AND attname = 'access' AND NOT attisdropped) THEN
        RAISE EXCEPTION 'applications.access should be gone';
    END IF;
    IF to_regclass('audit_log_before_018') IS NOT NULL THEN
        RAISE EXCEPTION 'audit_log_before_018 should be gone';
    END IF;
    IF NOT EXISTS (SELECT 1 FROM pg_attribute WHERE attrelid = to_regclass('application_roles') AND attname = 'may_sign_in' AND NOT attisdropped)
       OR NOT EXISTS (SELECT 1 FROM pg_attribute WHERE attrelid = to_regclass('applications') AND attname = 'login_required' AND NOT attisdropped)
       OR to_regclass('anotoki_audit_log') IS NULL THEN
        RAISE EXCEPTION '010''s columns and the library''s audit table should still be there';
    END IF;
END $$;
