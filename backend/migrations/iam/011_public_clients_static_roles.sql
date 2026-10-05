-----------------------------------------------------------
-- PUBLIC CLIENTS ONLY, THE SAME ROLES IN EVERY APPLICATION
--
-- The owner's words (2026-10-05): "Remove Landing page settings in
-- applications of iam admin console. Also, delete an option for adding a new
-- role in applications of iam admin console. All roles are static. Also
-- remove Signing in Type option in applications of iam admin console. It will
-- be public only."
--
-- - Public clients only. Every application names itself at the token and
--   revocation endpoints and proves each sign-in with PKCE; none has a client
--   secret any more, and a request carrying one is refused. Every application
--   becomes public and its secret hash is cleared. (No application on the
--   owner's IAM was confidential when this was written. One on another IAM
--   must use PKCE from now on, as every anotoki site already does.)
-- - The same roles everywhere. Every site has ADMIN, EDITOR and MEMBER; the
--   IAM's own entry ADMIN and MEMBER. The admin panel no longer adds, removes,
--   renames or rewords a role - it says only whether each may sign in. A new
--   application gets all three from the database (the trigger below replaces
--   010's, which made MEMBER alone), in the family's generic words. An
--   application that lacks ADMIN or EDITOR here gets it, in those words,
--   held by nobody: adding a role gives it to nobody (who holds a role is the
--   owner's decision, Users -> the person -> Roles). A role somebody added
--   before (an owner's READER, 010's INVITED) stays, with its holders, and is
--   shown read-only. A role reworded in the admin panel keeps its words.
-- - No landing page settings: the admin panel no longer shows or saves
--   show_on_landing and sort_order; the public list is every application
--   that is switched on (the code reads it so - nothing here).
--
-- The type, secret_hash and show_on_landing columns stay, unread; a later
-- migration drops them, with 010's access. sort_order stays for good: it is
-- the public list's order, only no longer set in the admin panel. They are
-- NOT a way back: as with 010, once this file is applied no build from
-- before it goes on the host again. Fix forward, with a new build.
-----------------------------------------------------------

UPDATE applications SET type = 'public', secret_hash = NULL
 WHERE type <> 'public' OR secret_hash IS NOT NULL;

-- A new application's roles: ADMIN, EDITOR and MEMBER for a site (MEMBER
-- alone for a system entry, which never comes about after 002), and MEMBER
-- for every account - with 010's lock, so an account made at the same moment
-- still meets it.
CREATE OR REPLACE FUNCTION roles_for_application() RETURNS TRIGGER AS $$
BEGIN
    PERFORM pg_advisory_xact_lock(4260918);
    INSERT INTO application_roles (application_id, name, description)
    SELECT NEW.id, role.name, role.description
      FROM (VALUES ('ADMIN', 'Everything in the site''s admin panel'),
                   ('EDITOR', 'Edits the site''s content in its admin panel'),
                   ('MEMBER', 'Uses the site')) AS role (name, description)
     WHERE role.name = 'MEMBER' OR NOT NEW.system
    ON CONFLICT (application_id, name) DO NOTHING;
    INSERT INTO user_roles (user_id, role_id)
    SELECT u.id, r.id FROM users u JOIN application_roles r ON r.application_id = NEW.id AND r.name = 'MEMBER'
    ON CONFLICT DO NOTHING;
    RETURN NULL;
END
$$ LANGUAGE plpgsql;

CREATE OR REPLACE TRIGGER trg_applications_member_role
    AFTER INSERT ON applications
    FOR EACH ROW EXECUTE FUNCTION roles_for_application();

DROP FUNCTION IF EXISTS member_role_for_application();

-- ADMIN and EDITOR where an application has none.
INSERT INTO application_roles (application_id, name, description)
SELECT a.id, role.name, role.description
  FROM applications a
 CROSS JOIN (VALUES ('ADMIN', 'Everything in the site''s admin panel'),
                    ('EDITOR', 'Edits the site''s content in its admin panel')) AS role (name, description)
 WHERE a.system = FALSE
ON CONFLICT (application_id, name) DO NOTHING;

-----------------------------------------------------------
-- PROVE IT
-----------------------------------------------------------

DO $$
DECLARE
    bad TEXT;
    probe INT;
    made TEXT;
BEGIN
    SELECT string_agg(client_id, ', ' ORDER BY client_id) INTO bad
      FROM applications WHERE type <> 'public' OR secret_hash IS NOT NULL;
    IF bad IS NOT NULL THEN
        RAISE EXCEPTION 'every application should be a public client without a secret: %', bad;
    END IF;

    SELECT string_agg(a.client_id, ', ' ORDER BY a.client_id) INTO bad
      FROM applications a
     WHERE (SELECT count(*) FROM application_roles r
             WHERE r.application_id = a.id
               AND r.name IN ('ADMIN', 'EDITOR', 'MEMBER')
               AND (r.name <> 'EDITOR' OR NOT a.system)) <> CASE WHEN a.system THEN 2 ELSE 3 END;
    IF bad IS NOT NULL THEN
        RAISE EXCEPTION 'every site should have ADMIN, EDITOR and MEMBER, the IAM ADMIN and MEMBER: %', bad;
    END IF;

    -- What a new application gets, tried and taken back: the inner block's
    -- own exception undoes the row, its roles and its grants.
    BEGIN
        INSERT INTO applications (client_id, name) VALUES ('migration-011-probe', 'Probe') RETURNING id INTO probe;
        SELECT string_agg(name, ' ' ORDER BY name) INTO made FROM application_roles WHERE application_id = probe;
        RAISE EXCEPTION USING ERRCODE = 'P0011', MESSAGE = COALESCE(made, '');
    EXCEPTION WHEN SQLSTATE 'P0011' THEN
        made := SQLERRM;
    END;
    IF made IS DISTINCT FROM 'ADMIN EDITOR MEMBER' THEN
        RAISE EXCEPTION 'a new application should get ADMIN, EDITOR and MEMBER; it got: %', made;
    END IF;
    IF EXISTS (SELECT 1 FROM applications WHERE client_id = 'migration-011-probe') THEN
        RAISE EXCEPTION 'the probe application should not be left behind';
    END IF;
END $$;
