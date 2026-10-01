-----------------------------------------------------------
-- THE SURVEY SITE
--
-- survey.anotoki.eu: surveys people take in steps, an editor for them, and
-- the results. It signs in as `survey`, lands on /auth/callback like every
-- anotoki site, and takes the next pair of ports in the table:
--
--   survey             http://localhost:4700   (its API on :8500)
--
-- Open to every account: anybody signed in may take a survey. EDITOR writes
-- surveys and reads the answers; ADMIN may also delete surveys and answers,
-- which cannot be undone.
--
-- The IAM's administrators of the moment are given its ADMIN - they could give
-- it to themselves in the admin console anyway, and the site is theirs. A
-- fresh installation has no accounts yet when its migrations run, so there it
-- is granted in the admin console like any other role.
--
-- Unlike 002, this file also reaches IAMs that are installed already, one of
-- them live. Where the IAM itself is served from decides the rest - its own
-- entry's home, which the installer sets to its address. On this computer the
-- site gets its development addresses and a card on the landing page, as 002
-- gave the others; anywhere else it gets neither. On a live IAM a localhost
-- redirect URI would hand a code to whatever happens to be listening on the
-- visitor's own machine, and a card with no address would lead nowhere: there
-- the admin console gives the site its https address. A fresh database counts
-- as this computer (002 has just seeded the IAM at localhost:4300), and a real
-- host's install then takes the loopback addresses out and puts the site's
-- own in their place (SETUP_SITES in setup.php), as it does for the others.
--
-- An IAM that knows `survey` already - made by hand in the admin console -
-- keeps what it has; only what is missing is added.
-----------------------------------------------------------

INSERT INTO applications (client_id, name, description, home_url, type, access, show_on_landing, sort_order, system)
SELECT 'survey', 'Surveys', 'Answer surveys, one step at a time.',
       CASE WHEN here.on_this_computer THEN 'http://localhost:4700' END,
       'public', 'everyone', here.on_this_computer, 50, FALSE
  FROM (SELECT EXISTS (SELECT 1 FROM applications
                        WHERE client_id = 'iam'
                          AND home_url ~* '^https?://(localhost|127\.0\.0\.1|\[::1\])(:[0-9]+)?(/.*)?$') AS on_this_computer) AS here
ON CONFLICT (client_id) DO NOTHING;

INSERT INTO application_roles (application_id, name, description)
SELECT a.id, v.name, v.description
  FROM applications a,
       (VALUES
        ('ADMIN', 'Everything an editor does, and deletes surveys and answers'),
        ('EDITOR', 'Writes surveys, opens and closes them, and reads the answers')
       ) AS v(name, description)
 WHERE a.client_id = 'survey'
ON CONFLICT DO NOTHING;

-- The development addresses, only while the IAM itself is on this computer.
INSERT INTO application_redirect_uris (application_id, kind, uri)
SELECT a.id, v.kind, v.uri
  FROM applications a,
       (VALUES
        ('login', 'http://localhost:4700/auth/callback'),
        ('logout', 'http://localhost:4700/')
       ) AS v(kind, uri)
 WHERE a.client_id = 'survey'
   AND EXISTS (SELECT 1 FROM applications i
                WHERE i.client_id = 'iam'
                  AND i.home_url ~* '^https?://(localhost|127\.0\.0\.1|\[::1\])(:[0-9]+)?(/.*)?$')
ON CONFLICT DO NOTHING;

INSERT INTO application_origins (application_id, origin)
SELECT a.id, 'http://localhost:4700'
  FROM applications a
 WHERE a.client_id = 'survey'
   AND EXISTS (SELECT 1 FROM applications i
                WHERE i.client_id = 'iam'
                  AND i.home_url ~* '^https?://(localhost|127\.0\.0\.1|\[::1\])(:[0-9]+)?(/.*)?$')
ON CONFLICT DO NOTHING;

INSERT INTO user_roles (user_id, role_id)
SELECT DISTINCT ur.user_id, survey_admin.id
  FROM user_roles ur
  JOIN application_roles r ON r.id = ur.role_id AND r.name = 'ADMIN'
  JOIN applications i ON i.id = r.application_id AND i.client_id = 'iam'
 CROSS JOIN (SELECT sr.id FROM application_roles sr
               JOIN applications s ON s.id = sr.application_id
              WHERE s.client_id = 'survey' AND sr.name = 'ADMIN') AS survey_admin
ON CONFLICT DO NOTHING;

DO $$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM applications WHERE client_id = 'survey') THEN
        RAISE EXCEPTION 'expected the application survey';
    END IF;
    IF (SELECT count(*) FROM application_roles r JOIN applications a ON a.id = r.application_id
         WHERE a.client_id = 'survey' AND r.name IN ('ADMIN', 'EDITOR')) <> 2 THEN
        RAISE EXCEPTION 'survey should have the roles ADMIN and EDITOR: the site asks for both';
    END IF;
    IF EXISTS (SELECT 1 FROM user_roles ur
                 JOIN application_roles r ON r.id = ur.role_id AND r.name = 'ADMIN'
                 JOIN applications i ON i.id = r.application_id AND i.client_id = 'iam'
                WHERE NOT EXISTS (SELECT 1 FROM user_roles sur
                                    JOIN application_roles sr ON sr.id = sur.role_id AND sr.name = 'ADMIN'
                                    JOIN applications s ON s.id = sr.application_id AND s.client_id = 'survey'
                                   WHERE sur.user_id = ur.user_id)) THEN
        RAISE EXCEPTION 'every administrator of the IAM should hold ADMIN in survey';
    END IF;
    IF EXISTS (SELECT 1 FROM applications
                WHERE client_id = 'iam'
                  AND home_url ~* '^https?://(localhost|127\.0\.0\.1|\[::1\])(:[0-9]+)?(/.*)?$')
       AND NOT (EXISTS (SELECT 1 FROM application_redirect_uris u JOIN applications a ON a.id = u.application_id
                         WHERE a.client_id = 'survey' AND u.kind = 'login' AND u.uri = 'http://localhost:4700/auth/callback')
            AND EXISTS (SELECT 1 FROM application_redirect_uris u JOIN applications a ON a.id = u.application_id
                         WHERE a.client_id = 'survey' AND u.kind = 'logout' AND u.uri = 'http://localhost:4700/')
            AND EXISTS (SELECT 1 FROM application_origins o JOIN applications a ON a.id = o.application_id
                         WHERE a.client_id = 'survey' AND o.origin = 'http://localhost:4700')) THEN
        RAISE EXCEPTION 'on an IAM on this computer survey should arrive with its sign-in callback, its landing after sign-out and its origin';
    END IF;
END $$;
