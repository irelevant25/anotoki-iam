-----------------------------------------------------------
-- SIGN-IN PER SITE: MEMBER FOR EVERYONE, ROLES THAT MAY SIGN IN
--
-- The owner's words (2026-10-05): "I would like to be able to setup if log in
-- is required for a specific project and also what roles can log in to a
-- specific project. The default role for users should be member. Every user
-- needs to have a role."
--
-- This takes back part of 007, which deleted MEMBER from the sites. It is a
-- permission change the owner decided (docs/decisions.md, 2026-10-05):
--
-- - MEMBER ("Uses the site") again, in every application - the sites, any
--   the owner registered, and the IAM's own entry - and every account holds
--   it in every one of them. The database keeps that true from now on: a new
--   account is given MEMBER everywhere, and a new application gets MEMBER
--   and gives it to every account (the two triggers below). Both triggers take
--   the same transaction-scoped advisory lock first, so an account and an
--   application made at the same moment still meet: the later one waits for
--   the other to commit and then sees it. An administrator may take MEMBER
--   from a person in one application - that is how somebody is blocked from
--   one site.
-- - Each role says whether it may sign in (application_roles.may_sign_in). A
--   sign-in to a site - authorize, and every refresh - needs at least one role
--   there that may. The IAM's own pages are not asked: its Settings ->
--   Signing in switch stays their only gate.
-- - Each application says whether its site requires signing in
--   (applications.login_required), which the sites read from the IAM's public
--   contract: on, the site sends everybody to sign in; off, it lets visitors
--   in. What each site does today: genshin and the build analyzer welcome
--   visitors, the survey, Piano and Japanese Academy require signing in. A
--   new application requires it.
--
-- Nothing changes for anybody on the day it runs. The old rule was 'access':
-- 'everyone' let in any account, 'members' only one holding a role there.
-- An 'everyone' application lets in every account still: every role may
-- sign in, MEMBER included, and everybody holds MEMBER. A non-system
-- 'members' application (none of the sites since 007, but one the owner set
-- so, or made so, in the admin panel) lets in what it did: its MEMBER may not
-- sign in, its other roles may. A MEMBER somebody held there was a way in, so
-- it is renamed INVITED ("Was MEMBER before 2026-10-05"; INVITED_2 and so on
-- if that name is taken), keeping its holders and its way in, and the
-- application gets a fresh MEMBER beside it. A 'members' application left
-- with no role that may sign in let nobody in, and is switched off, which
-- says the same honestly (the admin panel will not switch it on again until
-- a role there may sign in).
--
-- The access column stays, read only while this file is pending (the rule
-- before it, for the requests between the upload and Apply) and by nothing
-- once it is applied. A later migration drops it once both IAMs (this
-- computer's and the host's) run 010. It is NOT a way back: once this file
-- is applied, the build before it must never go on the host again. That
-- build reads access, and with every account holding MEMBER everywhere it
-- would let every account into a members-only application and let back in
-- anybody blocked by having MEMBER taken away; it ignores may_sign_in. No
-- change to the data can make it fail closed. Fix forward, with a new
-- build. A role description reworded in the admin panel keeps its words:
-- an existing MEMBER is left as it is, apart from may_sign_in.
-----------------------------------------------------------

ALTER TABLE application_roles ADD COLUMN IF NOT EXISTS may_sign_in BOOLEAN NOT NULL DEFAULT TRUE;

ALTER TABLE applications ADD COLUMN IF NOT EXISTS login_required BOOLEAN NOT NULL DEFAULT TRUE;

-- Visitors are welcome on genshin and the build analyzer, as today.
UPDATE applications SET login_required = FALSE
 WHERE client_id IN ('genshin', 'build-analyzer') AND login_required;

-- A MEMBER somebody holds in a 'members' application was a way in: it keeps
-- being one under another name, with its holders and its id.
DO $$
DECLARE
    held RECORD;
    candidate TEXT;
    n INT;
BEGIN
    FOR held IN
        SELECT r.id AS role_id, r.application_id
          FROM application_roles r JOIN applications a ON a.id = r.application_id
         WHERE r.name = 'MEMBER' AND a.access = 'members' AND a.system = FALSE
           AND EXISTS (SELECT 1 FROM user_roles ur WHERE ur.role_id = r.id)
    LOOP
        candidate := 'INVITED';
        n := 1;
        WHILE EXISTS (SELECT 1 FROM application_roles x WHERE x.application_id = held.application_id AND x.name = candidate) LOOP
            n := n + 1;
            candidate := 'INVITED_' || n;
        END LOOP;
        UPDATE application_roles SET name = candidate, description = 'Was MEMBER before 2026-10-05' WHERE id = held.role_id;
    END LOOP;
END $$;

-- MEMBER in every application.
INSERT INTO application_roles (application_id, name, description)
SELECT a.id, 'MEMBER', 'Uses the site' FROM applications a
ON CONFLICT (application_id, name) DO NOTHING;

-- In a 'members' application only its other roles let somebody in, as before.
UPDATE application_roles r SET may_sign_in = FALSE
  FROM applications a
 WHERE a.id = r.application_id AND r.name = 'MEMBER' AND a.access = 'members' AND a.system = FALSE;

-- A 'members' application with no role that may sign in let nobody in.
UPDATE applications a SET enabled = FALSE
 WHERE a.access = 'members' AND a.system = FALSE AND a.enabled
   AND NOT EXISTS (SELECT 1 FROM application_roles r WHERE r.application_id = a.id AND r.may_sign_in);

-- Every account holds MEMBER everywhere - one switched off too: harmless, and
-- switching it on again needs nothing more.
INSERT INTO user_roles (user_id, role_id)
SELECT u.id, r.id FROM users u CROSS JOIN application_roles r
 WHERE r.name = 'MEMBER'
ON CONFLICT DO NOTHING;

-----------------------------------------------------------
-- THE INVARIANT, KEPT BY THE DATABASE
--
-- Every way an account is made (registration, Google, the installer's and
-- create-admin.php's administrator, the legacy import, the admin panel's
-- "Create user") and every way an application is made (the admin panel, a
-- later seeding migration) passes through these, so none can forget MEMBER.
-- 4260918 is the IAM's MEMBER lock (4260917 is lockIamAdmins()'s).
-----------------------------------------------------------

CREATE OR REPLACE FUNCTION grant_member_everywhere() RETURNS TRIGGER AS $$
BEGIN
    PERFORM pg_advisory_xact_lock(4260918);
    INSERT INTO user_roles (user_id, role_id)
    SELECT NEW.id, r.id FROM application_roles r WHERE r.name = 'MEMBER'
    ON CONFLICT DO NOTHING;
    RETURN NULL;
END
$$ LANGUAGE plpgsql;

CREATE OR REPLACE TRIGGER trg_users_member_everywhere
    AFTER INSERT ON users
    FOR EACH ROW EXECUTE FUNCTION grant_member_everywhere();

CREATE OR REPLACE FUNCTION member_role_for_application() RETURNS TRIGGER AS $$
BEGIN
    PERFORM pg_advisory_xact_lock(4260918);
    INSERT INTO application_roles (application_id, name, description) VALUES (NEW.id, 'MEMBER', 'Uses the site')
    ON CONFLICT (application_id, name) DO NOTHING;
    INSERT INTO user_roles (user_id, role_id)
    SELECT u.id, r.id FROM users u JOIN application_roles r ON r.application_id = NEW.id AND r.name = 'MEMBER'
    ON CONFLICT DO NOTHING;
    RETURN NULL;
END
$$ LANGUAGE plpgsql;

CREATE OR REPLACE TRIGGER trg_applications_member_role
    AFTER INSERT ON applications
    FOR EACH ROW EXECUTE FUNCTION member_role_for_application();

-----------------------------------------------------------
-- PROVE IT
--
-- Only what this file made true is checked, on rows that exist: a site
-- deleted in the admin panel stays deleted and is not missed.
-----------------------------------------------------------

DO $$
DECLARE
    bad TEXT;
BEGIN
    SELECT string_agg(a.client_id, ', ' ORDER BY a.client_id) INTO bad
      FROM applications a
     WHERE NOT EXISTS (SELECT 1 FROM application_roles r WHERE r.application_id = a.id AND r.name = 'MEMBER');
    IF bad IS NOT NULL THEN
        RAISE EXCEPTION 'every application should have a MEMBER role; these have none: %', bad;
    END IF;

    IF EXISTS (SELECT 1 FROM users u CROSS JOIN application_roles r
                WHERE r.name = 'MEMBER'
                  AND NOT EXISTS (SELECT 1 FROM user_roles ur WHERE ur.user_id = u.id AND ur.role_id = r.id)) THEN
        RAISE EXCEPTION 'every account should hold MEMBER in every application';
    END IF;

    SELECT string_agg(client_id, ', ' ORDER BY client_id) INTO bad
      FROM applications
     WHERE (client_id IN ('genshin', 'build-analyzer') AND login_required)
        OR (client_id IN ('survey', 'piano-academy', 'japanese-academy') AND NOT login_required);
    IF bad IS NOT NULL THEN
        RAISE EXCEPTION 'genshin and the build analyzer should welcome visitors and the other sites require signing in; wrong: %', bad;
    END IF;

    SELECT string_agg(a.client_id || ' ' || r.name, ', ' ORDER BY a.client_id, r.name) INTO bad
      FROM application_roles r JOIN applications a ON a.id = r.application_id
     WHERE NOT r.may_sign_in
       AND NOT (r.name = 'MEMBER' AND a.access = 'members' AND a.system = FALSE);
    IF bad IS NOT NULL THEN
        RAISE EXCEPTION 'only the MEMBER of a members-only application should be kept from signing in; also kept: %', bad;
    END IF;

    SELECT string_agg(a.client_id, ', ' ORDER BY a.client_id) INTO bad
      FROM applications a JOIN application_roles r ON r.application_id = a.id AND r.name = 'MEMBER'
     WHERE a.access = 'members' AND a.system = FALSE AND r.may_sign_in;
    IF bad IS NOT NULL THEN
        RAISE EXCEPTION 'a members-only application would let every account in: %', bad;
    END IF;

    SELECT string_agg(a.client_id, ', ' ORDER BY a.client_id) INTO bad
      FROM applications a
     WHERE a.enabled AND a.system = FALSE
       AND NOT EXISTS (SELECT 1 FROM application_roles r WHERE r.application_id = a.id AND r.may_sign_in);
    IF bad IS NOT NULL THEN
        RAISE EXCEPTION 'an application that is switched on should have a role that may sign in: %', bad;
    END IF;

    IF (SELECT count(*) FROM pg_trigger
         WHERE NOT tgisinternal
           AND ((tgname = 'trg_users_member_everywhere' AND tgrelid = 'users'::regclass)
             OR (tgname = 'trg_applications_member_role' AND tgrelid = 'applications'::regclass))) <> 2 THEN
        RAISE EXCEPTION 'both MEMBER triggers should be there';
    END IF;
END $$;
