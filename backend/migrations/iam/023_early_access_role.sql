-----------------------------------------------------------
-- EARLY ACCESS: A FOURTH ROLE IN EVERY SITE
--
-- The owner's words (2026-10-19): "Need to add another role like: Early
-- access. With same permissions as Member. The idea is, that when I will have
-- a new project and will allow specific people to participate in the Early
-- access or beta version or for testing purposes, I need to separate them
-- from members so they will have access there without making them admins or
-- editors. This apply for every website so far except the iam of course."
--
-- It changes 011's "the same roles everywhere" - three of them until now -
-- (the owner's decision, docs/decisions.md, 2026-10-19): every site has
-- ADMIN, EDITOR, EARLY_ACCESS and MEMBER from now on; the IAM's own entry
-- keeps ADMIN and MEMBER.
--
-- - EARLY_ACCESS ("Uses the site while it lets in only chosen people (a beta,
--   a test); given by an administrator") in every site there is - the
--   first-party ones and any the owner made in the admin panel, switched on
--   or off. It may sign in, and nobody holds it: an administrator of the IAM
--   gives it to the chosen people (Users -> the person -> Roles), as ADMIN and
--   EDITOR are given, and takes it back the same way. For a beta the owner
--   unticks the site's MEMBER (Applications -> the site -> Roles) and gives
--   the testers EARLY_ACCESS: they get in, and an account holding only the
--   MEMBER everybody holds does not. It is in the token's roles like any
--   role; a site treats it as it treats MEMBER - an ordinary signed-in
--   person - and it opens no admin area, which stays ADMIN's and EDITOR's.
-- - A site made later gets all four from the database: 016's
--   roles_for_application(), made again with the fourth. A system entry - the
--   IAM's own, which never comes about after 002 - still gets MEMBER alone.
--   MEMBER stays every account's; EARLY_ACCESS, like ADMIN and EDITOR, is
--   nobody's until it is given.
-- - Nothing else changes. No role is renamed, reworded, ticked or unticked,
--   nobody is given a role or loses one, and a site that has an EARLY_ACCESS
--   already keeps it as it is - its words, its tick, its holders. So who gets
--   in where is what it was: on the owner's IAM, where Japanese Academy, Piano
--   Academy and the build analyzer let only ADMIN in (2026-10-06), each gains
--   an EARLY_ACCESS that may sign in and that nobody holds, and lets in
--   exactly whom it did.
-- - Like every role since 011 it is never removed from a site, renamed or
--   reworded in the admin panel; a site that wants no early access gives it
--   to nobody, or unticks it.
--
-- It adds no strings: the admin panel is English, written in its templates.
-- A build from before this file runs on a database with it as before - every
-- rule reads the roles from the database -, and the build that brings it
-- gives an application made in the admin panel before Apply the fourth role
-- itself (routes/admin.php, SITE_ROLES).
-----------------------------------------------------------

-- The sites that get it: every one that is not a system entry and has none
-- yet - for the insert, and for the gate, which proves what this file wrote
-- and nothing else. A table of this file's own, dropped at its end, after the
-- gate (as 017's): the file runs in one transaction, so a gate that fails
-- takes it back with everything else and leaves nothing behind. Not a
-- temporary table: that needs the TEMP privilege, which no IAM migration
-- needs and a host may have revoked.
CREATE TABLE iam_023_early_access AS
SELECT a.id AS application_id, a.client_id
  FROM applications a
 WHERE a.system = FALSE
   AND NOT EXISTS (SELECT 1 FROM application_roles r WHERE r.application_id = a.id AND r.name = 'EARLY_ACCESS');

INSERT INTO application_roles (application_id, name, description, may_sign_in)
SELECT w.application_id, 'EARLY_ACCESS', 'Uses the site while it lets in only chosen people (a beta, a test); given by an administrator', TRUE
  FROM iam_023_early_access w
ON CONFLICT (application_id, name) DO NOTHING;

-- 016's roles_for_application(), with the fourth role: a new site gets ADMIN,
-- EDITOR, EARLY_ACCESS and MEMBER, a system entry MEMBER alone, and every
-- account but an anonymized one MEMBER there - nobody anything else. The
-- trigger that calls it (trg_applications_member_role, 010's name) stays as
-- it is, and so does its lock (4260918, the MEMBER triggers').
CREATE OR REPLACE FUNCTION roles_for_application() RETURNS TRIGGER AS $$
BEGIN
    PERFORM pg_advisory_xact_lock(4260918);
    INSERT INTO application_roles (application_id, name, description)
    SELECT NEW.id, role.name, role.description
      FROM (VALUES ('ADMIN', 'Everything in the site''s admin panel'),
                   ('EDITOR', 'Edits the site''s content in its admin panel'),
                   ('EARLY_ACCESS', 'Uses the site while it lets in only chosen people (a beta, a test); given by an administrator'),
                   ('MEMBER', 'Uses the site')) AS role (name, description)
     WHERE role.name = 'MEMBER' OR NOT NEW.system
    ON CONFLICT (application_id, name) DO NOTHING;
    INSERT INTO user_roles (user_id, role_id)
    SELECT u.id, r.id FROM users u JOIN application_roles r ON r.application_id = NEW.id AND r.name = 'MEMBER'
     WHERE u.anonymized_at IS NULL
    ON CONFLICT DO NOTHING;
    RETURN NULL;
END
$$ LANGUAGE plpgsql;

-----------------------------------------------------------
-- PROVE IT
--
-- What this file made true, by name: every site has an EARLY_ACCESS; each one
-- this file wrote is in the family's words, may sign in and is held by
-- nobody; and a new site gets the four roles, EARLY_ACCESS held by nobody,
-- while a new system entry gets MEMBER alone - tried and taken back, as 011's
-- probe is. An EARLY_ACCESS that was there before is not asked about: it is
-- the owner's, as it was.
-----------------------------------------------------------

DO $$
DECLARE
    bad TEXT;
    probe INT;
    probe_system INT;
    made TEXT;
    made_system TEXT;
    given INT;
BEGIN
    SELECT string_agg(a.client_id, ', ' ORDER BY a.client_id) INTO bad
      FROM applications a
     WHERE a.system = FALSE
       AND NOT EXISTS (SELECT 1 FROM application_roles r WHERE r.application_id = a.id AND r.name = 'EARLY_ACCESS');
    IF bad IS NOT NULL THEN
        RAISE EXCEPTION 'every site should have an EARLY_ACCESS role; these have none: %', bad;
    END IF;

    SELECT string_agg(w.client_id, ', ' ORDER BY w.client_id) INTO bad
      FROM iam_023_early_access w
      JOIN application_roles r ON r.application_id = w.application_id AND r.name = 'EARLY_ACCESS'
     WHERE r.description IS DISTINCT FROM 'Uses the site while it lets in only chosen people (a beta, a test); given by an administrator'
        OR NOT r.may_sign_in
        OR EXISTS (SELECT 1 FROM user_roles ur WHERE ur.role_id = r.id);
    IF bad IS NOT NULL THEN
        RAISE EXCEPTION 'the EARLY_ACCESS this file gave these sites should be in the family''s words, allowed to sign in and held by nobody: %', bad;
    END IF;

    -- What a new site and a new system entry get, tried and taken back: the
    -- inner block's own exception undoes both rows, their roles and grants.
    BEGIN
        INSERT INTO applications (client_id, name) VALUES ('migration-023-probe', 'Probe') RETURNING id INTO probe;
        INSERT INTO applications (client_id, name, system) VALUES ('migration-023-system', 'Probe', TRUE) RETURNING id INTO probe_system;
        SELECT string_agg(name, ' ' ORDER BY name) INTO made FROM application_roles WHERE application_id = probe;
        SELECT string_agg(name, ' ' ORDER BY name) INTO made_system FROM application_roles WHERE application_id = probe_system;
        SELECT count(*) INTO given FROM user_roles ur JOIN application_roles r ON r.id = ur.role_id
         WHERE r.application_id = probe AND r.name <> 'MEMBER';
        RAISE EXCEPTION USING ERRCODE = 'P0023', MESSAGE = COALESCE(made, '') || ' / ' || COALESCE(made_system, '') || ' / ' || given;
    EXCEPTION WHEN SQLSTATE 'P0023' THEN
        made := SQLERRM;
    END;
    IF made IS DISTINCT FROM 'ADMIN EARLY_ACCESS EDITOR MEMBER / MEMBER / 0' THEN
        RAISE EXCEPTION 'a new site should get ADMIN, EARLY_ACCESS, EDITOR and MEMBER, held by nobody but MEMBER, and a new system entry MEMBER alone; they got (the site / the system entry / grants beyond MEMBER): %', made;
    END IF;
    IF EXISTS (SELECT 1 FROM applications WHERE client_id IN ('migration-023-probe', 'migration-023-system')) THEN
        RAISE EXCEPTION 'the probe should not be left behind';
    END IF;
END $$;

-- Proved: the list has done its work.
DROP TABLE iam_023_early_access;
