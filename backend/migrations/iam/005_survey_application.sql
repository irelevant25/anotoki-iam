-----------------------------------------------------------
-- THE SURVEY SITE
--
-- survey.anotoki.eu (repository anotoki-survey): now and then the anotoki
-- sites ask what people like and what they would change. Anybody with an
-- account may answer, so it is open to everyone; writing the surveys and
-- reading the answers is for its two roles.
--
-- Like the sites in 002 it arrives with its development address - port 4700,
-- the callback on /auth/callback - but only on a development IAM, one whose
-- own address is on this computer. An IAM already installed on a real host
-- gets no localhost address (there it would hand a code to whatever listens
-- on the visitor's own machine), and no card on the landing page until the
-- site has an address to lead to: both come from the admin console's
-- Applications page. The installer of a new IAM knows the site from its list
-- and replaces the development address the same way it does the others'.
--
-- The IAM's administrators at the time it runs administer the new site too:
-- they could grant themselves the role in the admin console anyway, and the
-- site is theirs. (A new IAM makes its first administrator after the
-- migrations; that one grants the role like any other.)
--
-- A `survey` application that is there already - made by hand in the console
-- - is left as it is, and only gets the roles it lacks.
-----------------------------------------------------------

WITH here AS (
    SELECT EXISTS (
        SELECT 1 FROM applications
         WHERE client_id = 'iam' AND home_url ~ '^http://(localhost|127\.[0-9]+\.[0-9]+\.[0-9]+|\[::1\])(:[0-9]+)?/?$'
    ) AS development
)
INSERT INTO applications (client_id, name, description, home_url, type, access, show_on_landing, sort_order, system)
SELECT 'survey', 'Surveys', 'Now and then the anotoki sites ask what you like and what you would change.',
       CASE WHEN here.development THEN 'http://localhost:4700' END,
       'public', 'everyone', here.development, 50, FALSE
  FROM here
ON CONFLICT (client_id) DO NOTHING;

-- The development addresses go wherever the development home address did.
INSERT INTO application_redirect_uris (application_id, kind, uri)
SELECT a.id, v.kind, v.uri
  FROM (VALUES ('login', 'http://localhost:4700/auth/callback'), ('logout', 'http://localhost:4700/')) AS v(kind, uri)
  JOIN applications a ON a.client_id = 'survey' AND a.home_url = 'http://localhost:4700'
ON CONFLICT DO NOTHING;

INSERT INTO application_origins (application_id, origin)
SELECT a.id, 'http://localhost:4700'
  FROM applications a
 WHERE a.client_id = 'survey' AND a.home_url = 'http://localhost:4700'
ON CONFLICT DO NOTHING;

INSERT INTO application_roles (application_id, name, description)
SELECT a.id, v.name, v.description
  FROM (VALUES
        ('ADMIN', 'Everything in the admin area, including deleting surveys and answers'),
        ('EDITOR', 'Writes surveys, opens and closes them, and reads the results')
       ) AS v(name, description)
  JOIN applications a ON a.client_id = 'survey'
ON CONFLICT DO NOTHING;

INSERT INTO user_roles (user_id, role_id)
SELECT granted.user_id, survey_admin.id
  FROM user_roles granted
  JOIN application_roles iam_admin ON iam_admin.id = granted.role_id AND iam_admin.name = 'ADMIN'
  JOIN applications iam ON iam.id = iam_admin.application_id AND iam.client_id = 'iam'
  JOIN applications survey ON survey.client_id = 'survey'
  JOIN application_roles survey_admin ON survey_admin.application_id = survey.id AND survey_admin.name = 'ADMIN'
ON CONFLICT DO NOTHING;

-- What this migration promises, and nothing it may have left to the console.
DO $$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM applications WHERE client_id = 'survey') THEN
        RAISE EXCEPTION 'the survey site should be registered';
    END IF;
    IF (SELECT count(*) FROM application_roles r JOIN applications a ON a.id = r.application_id
         WHERE a.client_id = 'survey' AND r.name IN ('ADMIN', 'EDITOR')) <> 2 THEN
        RAISE EXCEPTION 'the survey site should have its two roles, ADMIN and EDITOR';
    END IF;
    IF EXISTS (SELECT 1 FROM applications a
                WHERE a.client_id = 'survey' AND a.home_url = 'http://localhost:4700'
                  AND NOT EXISTS (SELECT 1 FROM application_redirect_uris u
                                   WHERE u.application_id = a.id AND u.kind = 'login' AND u.uri = 'http://localhost:4700/auth/callback')) THEN
        RAISE EXCEPTION 'a development survey site should arrive with its callback';
    END IF;
    IF EXISTS (SELECT 1 FROM applications iam
                WHERE iam.client_id = 'iam' AND iam.home_url !~ '^http://(localhost|127\.[0-9]+\.[0-9]+\.[0-9]+|\[::1\])(:[0-9]+)?/?$')
       AND EXISTS (SELECT 1 FROM application_redirect_uris u JOIN applications a ON a.id = u.application_id
                    WHERE a.client_id = 'survey' AND u.uri LIKE 'http://localhost:4700%') THEN
        RAISE EXCEPTION 'an IAM on a real host must not send the survey site''s codes to localhost';
    END IF;
END $$;
