-----------------------------------------------------------
-- THE LEFTOVERS DROPPED: APPLICATIONS.ACCESS, TYPE, SECRET_HASH AND
-- SHOW_ON_LANDING, AND AUDIT_LOG_BEFORE_018
--
-- The owner's yes of 2026-10-25, and their answer of the same day for 011's
-- three columns. Each was kept until "a later migration drops it, once both
-- IAMs run" the file that left it over - and both IAMs, this computer's and
-- the host's, have run 010, 011 and 018 since:
--
-- - applications.access, the rule before 010 ('everyone', or 'members': a
--   role there held), read only while 010 was pending - the code's
--   signInRulesReady(), which this release takes away with every reader of
--   the column. Who may sign in has been each role's may_sign_in since 010,
--   on both IAMs, and stays so: nothing that decides a sign-in changes here.
-- - applications.type, secret_hash and show_on_landing, which 011 left
--   unread when every client became public and the landing page settings
--   went. Nothing has read them since: a request to the token or revocation
--   endpoint that carries a client secret is refused by what it carries,
--   before any row is read; authorize asks every client for PKCE, whatever
--   its row; the admin panel refuses a body's `type` other than public by
--   the body, and ignores a `show_on_landing`; the public list is every site
--   switched on, in sort_order - which stays. A value left in one of them (a
--   row edited by hand) is read by nothing, and goes with it.
-- - audit_log_before_018, the IAM's own audit log as 018 left it once every
--   entry, with its id, was in the library's anotoki_audit_log - a move 018
--   proved in its gate. Unread since, and scrubbed of a deleted person like
--   the log itself until this file drops it - a deletion that falls due
--   between the upload and this Apply too, so the backup the Apply makes
--   first keeps nobody deleted. Every event in it is in anotoki_audit_log:
--   dropping it loses none.
--
-- A build from before this file still runs on a database it has run on: once
-- 010, 011 and 018 are applied, that build reads none of them (its
-- `php clients.php` listing alone looked at `type`, only to print it). So the
-- way back is open, but for what is gone: the copy is not there to bring back.
--
-- Before anything goes, the gate asks that 010 and 018 are what they say -
-- each role's may_sign_in and each application's login_required, 010's two
-- triggers that give every account MEMBER everywhere; the library's audit
-- table, and the old table's name gone. On a database without them this file
-- would drop the one thing it still reads, so it stops instead, naming all
-- that is missing. After the drops, it proves all five gone and what stays
-- still there.
-----------------------------------------------------------

DO $$
DECLARE
    missing TEXT[] := ARRAY[]::TEXT[];
BEGIN
    -- array_append, never `missing || '...'`: an untyped literal beside an
    -- array is read as an array literal, and the gate would stop on its own
    -- words ("malformed array literal") instead of naming what is missing.
    IF NOT EXISTS (SELECT 1 FROM pg_attribute WHERE attrelid = to_regclass('application_roles') AND attname = 'may_sign_in' AND NOT attisdropped) THEN
        missing := array_append(missing, 'application_roles.may_sign_in (010)');
    END IF;
    IF NOT EXISTS (SELECT 1 FROM pg_attribute WHERE attrelid = to_regclass('applications') AND attname = 'login_required' AND NOT attisdropped) THEN
        missing := array_append(missing, 'applications.login_required (010)');
    END IF;
    IF NOT EXISTS (SELECT 1 FROM pg_trigger WHERE tgrelid = to_regclass('users') AND tgname = 'trg_users_member_everywhere' AND NOT tgisinternal) THEN
        missing := array_append(missing, 'the trigger trg_users_member_everywhere on users (010)');
    END IF;
    IF NOT EXISTS (SELECT 1 FROM pg_trigger WHERE tgrelid = to_regclass('applications') AND tgname = 'trg_applications_member_role' AND NOT tgisinternal) THEN
        missing := array_append(missing, 'the trigger trg_applications_member_role on applications (010, 011)');
    END IF;
    IF to_regclass('anotoki_audit_log') IS NULL THEN
        missing := array_append(missing, 'the library''s audit table anotoki_audit_log (anotoki_admin/001, 018)');
    END IF;
    IF to_regclass('audit_log') IS NOT NULL THEN
        missing := array_append(missing, 'the move of audit_log into anotoki_audit_log (018: the old name is still there)');
    END IF;
    IF array_length(missing, 1) IS NOT NULL THEN
        RAISE EXCEPTION 'this file drops what an IAM without 010 and 018 still reads, and this database lacks: %', array_to_string(missing, '; ');
    END IF;
END $$;

ALTER TABLE applications
    DROP COLUMN IF EXISTS access,
    DROP COLUMN IF EXISTS type,
    DROP COLUMN IF EXISTS secret_hash,
    DROP COLUMN IF EXISTS show_on_landing;

DROP TABLE IF EXISTS audit_log_before_018;

DO $$
DECLARE
    still TEXT[] := ARRAY[]::TEXT[];
    leftover TEXT;
BEGIN
    FOREACH leftover IN ARRAY ARRAY['access', 'type', 'secret_hash', 'show_on_landing'] LOOP
        IF EXISTS (SELECT 1 FROM pg_attribute WHERE attrelid = to_regclass('applications') AND attname = leftover AND NOT attisdropped) THEN
            still := array_append(still, 'applications.' || leftover);
        END IF;
    END LOOP;
    IF to_regclass('audit_log_before_018') IS NOT NULL THEN
        still := array_append(still, 'audit_log_before_018');
    END IF;
    IF array_length(still, 1) IS NOT NULL THEN
        RAISE EXCEPTION 'these should be gone: %', array_to_string(still, ', ');
    END IF;
    IF NOT EXISTS (SELECT 1 FROM pg_attribute WHERE attrelid = to_regclass('application_roles') AND attname = 'may_sign_in' AND NOT attisdropped)
       OR NOT EXISTS (SELECT 1 FROM pg_attribute WHERE attrelid = to_regclass('applications') AND attname = 'login_required' AND NOT attisdropped)
       OR NOT EXISTS (SELECT 1 FROM pg_attribute WHERE attrelid = to_regclass('applications') AND attname = 'sort_order' AND NOT attisdropped)
       OR to_regclass('anotoki_audit_log') IS NULL THEN
        RAISE EXCEPTION '010''s columns, the public list''s sort_order and the library''s audit table should still be there';
    END IF;
END $$;
