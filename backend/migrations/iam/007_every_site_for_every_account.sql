-----------------------------------------------------------
-- EVERY SITE FOR EVERY ACCOUNT - ADMIN AND EDITOR IN EACH
--
-- The owner's decisions for the anotoki family (2026-10-01): every
-- first-party site is open to every account - each site decides for itself
-- what a visitor or a signed-in person may do there, and none asks for a
-- MEMBER role any more. Every site has ADMIN and EDITOR. Where a site has no
-- split of its own the split is one: EDITOR rewords the site's strings (the
-- build analyzer's also runs the build and catalog syncs); ADMIN does all
-- that, and adds and deletes languages, applies database updates, switches
-- sources on and off and deletes a person's data. Genshin and the survey keep
-- the roles and the words they have.
--
-- The IAM's administrators of the moment are given ADMIN in every
-- application that has one: the sites are theirs, and they could give it to
-- themselves in the admin console anyway (005 did the same for the survey).
-- An administrator made later gets the same from setup.php and
-- create-admin.php.
--
-- A site deleted in the admin console stays deleted, and a role description
-- reworded there keeps its words; an ADMIN or EDITOR of a first-party site
-- deleted there comes back, since the sites ask for both.
-----------------------------------------------------------

UPDATE applications
   SET access = 'everyone'
 WHERE client_id IN ('genshin', 'japanese-academy', 'piano-academy', 'build-analyzer', 'survey')
   AND access <> 'everyone';

-- No site asks for MEMBER any more. What was granted of it goes with it
-- (user_roles.role_id cascades).
DELETE FROM application_roles r
 USING applications a
 WHERE a.id = r.application_id
   AND a.client_id IN ('japanese-academy', 'piano-academy', 'build-analyzer')
   AND r.name = 'MEMBER';

-- ADMIN and EDITOR on every first-party site: EDITOR is new on the three that
-- had MEMBER, and either one deleted by hand in the admin console comes back
-- (in the words it would have had), so the gate below never stops on it.
INSERT INTO application_roles (application_id, name, description)
SELECT a.id, v.name, v.description
  FROM applications a
  JOIN (VALUES
        ('genshin', 'ADMIN', 'Everything in the admin panel, including the system pages'),
        ('genshin', 'EDITOR', 'Edits game content; may read the system pages'),
        ('survey', 'ADMIN', 'Everything an editor does, and deletes surveys and answers'),
        ('survey', 'EDITOR', 'Writes surveys, opens and closes them, and reads the answers'),
        ('japanese-academy', 'ADMIN',
         'Everything an editor does, and adds and deletes languages, applies database updates and deletes a person''s data'),
        ('japanese-academy', 'EDITOR', 'Rewords the app''s strings'),
        ('piano-academy', 'ADMIN',
         'Everything an editor does, and adds and deletes languages, applies database updates and deletes a person''s data'),
        ('piano-academy', 'EDITOR', 'Rewords the app''s strings'),
        ('build-analyzer', 'ADMIN',
         'Everything an editor does, and adds and deletes languages, applies database updates, switches sources on and off and deletes a person''s data'),
        ('build-analyzer', 'EDITOR', 'Rewords the site''s strings and runs the build and catalog syncs')
       ) AS v(client_id, name, description) ON v.client_id = a.client_id
ON CONFLICT DO NOTHING;

-- ADMIN in the owner's split - only over the words 002 gave it.
UPDATE application_roles r
   SET description = v.description
  FROM applications a,
       (VALUES
        ('japanese-academy', 'The owner',
         'Everything an editor does, and adds and deletes languages, applies database updates and deletes a person''s data'),
        ('piano-academy', 'The owner',
         'Everything an editor does, and adds and deletes languages, applies database updates and deletes a person''s data'),
        ('build-analyzer', 'Runs the build and catalog syncs and switches sources on and off',
         'Everything an editor does, and adds and deletes languages, applies database updates, switches sources on and off and deletes a person''s data')
       ) AS v(client_id, was, description)
 WHERE a.id = r.application_id
   AND a.client_id = v.client_id
   AND r.name = 'ADMIN'
   AND r.description IS NOT DISTINCT FROM v.was;

INSERT INTO user_roles (user_id, role_id)
SELECT DISTINCT ur.user_id, r.id
  FROM user_roles ur
  JOIN application_roles ir ON ir.id = ur.role_id AND ir.name = 'ADMIN'
  JOIN applications i ON i.id = ir.application_id AND i.client_id = 'iam'
 CROSS JOIN application_roles r
 WHERE r.name = 'ADMIN'
ON CONFLICT DO NOTHING;

DO $$
BEGIN
    IF EXISTS (SELECT 1 FROM applications
                WHERE client_id IN ('genshin', 'japanese-academy', 'piano-academy', 'build-analyzer', 'survey')
                  AND access <> 'everyone') THEN
        RAISE EXCEPTION 'every first-party site should be open to every account';
    END IF;
    IF EXISTS (SELECT 1 FROM application_roles r JOIN applications a ON a.id = r.application_id
                WHERE a.client_id IN ('japanese-academy', 'piano-academy', 'build-analyzer') AND r.name = 'MEMBER') THEN
        RAISE EXCEPTION 'no site asks for MEMBER any more, so it should be gone';
    END IF;
    IF EXISTS (SELECT 1 FROM applications a
                WHERE a.client_id IN ('genshin', 'japanese-academy', 'piano-academy', 'build-analyzer', 'survey')
                  AND (SELECT count(*) FROM application_roles r
                        WHERE r.application_id = a.id AND r.name IN ('ADMIN', 'EDITOR')) <> 2) THEN
        RAISE EXCEPTION 'every first-party site should have the roles ADMIN and EDITOR';
    END IF;
    IF EXISTS (SELECT 1 FROM user_roles ur
                 JOIN application_roles ir ON ir.id = ur.role_id AND ir.name = 'ADMIN'
                 JOIN applications i ON i.id = ir.application_id AND i.client_id = 'iam'
                 CROSS JOIN application_roles r
                WHERE r.name = 'ADMIN'
                  AND NOT EXISTS (SELECT 1 FROM user_roles x WHERE x.user_id = ur.user_id AND x.role_id = r.id)) THEN
        RAISE EXCEPTION 'every administrator of the IAM should hold ADMIN in every application that has one';
    END IF;
END $$;
