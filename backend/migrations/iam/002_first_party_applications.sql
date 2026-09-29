-----------------------------------------------------------
-- THE ANOTOKI SITES
--
-- The applications that exist on day one, registered with their development
-- addresses. Every anotoki project runs its dev servers on the same fixed
-- ports, and every one lands its sign-in on /auth/callback:
--
--   iam                http://localhost:4300   these pages; not an OIDC client
--   genshin            http://localhost:4200
--   japanese-academy   http://localhost:4400
--   piano-academy      http://localhost:4500
--   build-analyzer     http://localhost:4600
--
-- Defaults are development, so a database built from these files works on
-- the machine it was built on. A real host gets its sites' production
-- addresses from setup.php, which asks for each one and takes the loopback
-- addresses out as it installs - on a live IAM a localhost redirect URI would
-- hand a code to whatever happens to be listening on the visitor's own
-- machine - or later from the admin console's Applications page.
--
-- The IAM itself comes first. It is not a site anybody signs in to through
-- OIDC - its own pages use its own cookie - but it owns the ADMIN role that
-- makes somebody an administrator of this installation.
--
-- genshin is open to every account, as the site always was. The study apps and
-- the analyzer start as 'members': they hold people's own progress, and who
-- else gets in is the owner's decision to make here, not a default.
-----------------------------------------------------------

INSERT INTO applications (client_id, name, description, home_url, type, access, show_on_landing, sort_order, system) VALUES
    ('iam', 'anotoki account', 'Sign-in, accounts and access for every anotoki site.', 'http://localhost:4300', 'public', 'members', FALSE, 0, TRUE),
    ('genshin', 'Genshin Impact', 'Characters, weapons, artifacts, enemies and daily quizzes.', 'http://localhost:4200', 'public', 'everyone', TRUE, 10, FALSE),
    ('japanese-academy', 'Japanese Academy', 'Kanji reviews and a beginner Japanese exam.', 'http://localhost:4400', 'public', 'members', TRUE, 20, FALSE),
    ('piano-academy', 'Piano Academy', 'Learn to read and play the piano with a MIDI keyboard.', 'http://localhost:4500', 'public', 'members', TRUE, 30, FALSE),
    ('build-analyzer', 'Build Analyzer', 'Import your Genshin Impact or Star Rail account and see what to improve.', 'http://localhost:4600', 'public', 'members', TRUE, 40, FALSE)
ON CONFLICT (client_id) DO NOTHING;

INSERT INTO application_redirect_uris (application_id, kind, uri)
SELECT a.id, v.kind, v.uri
  FROM (VALUES
        ('genshin', 'login', 'http://localhost:4200/auth/callback'),
        ('genshin', 'logout', 'http://localhost:4200/'),
        ('japanese-academy', 'login', 'http://localhost:4400/auth/callback'),
        ('japanese-academy', 'logout', 'http://localhost:4400/'),
        ('piano-academy', 'login', 'http://localhost:4500/auth/callback'),
        ('piano-academy', 'logout', 'http://localhost:4500/'),
        ('build-analyzer', 'login', 'http://localhost:4600/auth/callback'),
        ('build-analyzer', 'logout', 'http://localhost:4600/')
       ) AS v(client_id, kind, uri)
  JOIN applications a ON a.client_id = v.client_id
ON CONFLICT DO NOTHING;

INSERT INTO application_origins (application_id, origin)
SELECT a.id, v.origin
  FROM (VALUES
        ('genshin', 'http://localhost:4200'),
        ('japanese-academy', 'http://localhost:4400'),
        ('piano-academy', 'http://localhost:4500'),
        ('build-analyzer', 'http://localhost:4600')
       ) AS v(client_id, origin)
  JOIN applications a ON a.client_id = v.client_id
ON CONFLICT DO NOTHING;

-- genshin keeps the two roles it has always had; a signed-in account with
-- neither is an ordinary player. The members-only apps have MEMBER for "may use
-- it" and ADMIN for whatever each one reserves for its owner.
INSERT INTO application_roles (application_id, name, description)
SELECT a.id, v.name, v.description
  FROM (VALUES
        ('iam', 'ADMIN', 'Administers this IAM: accounts, applications, roles, keys and switches'),
        ('genshin', 'ADMIN', 'Everything in the admin panel, including the system pages'),
        ('genshin', 'EDITOR', 'Edits game content; may read the system pages'),
        ('japanese-academy', 'ADMIN', 'The owner'),
        ('japanese-academy', 'MEMBER', 'May use the app'),
        ('piano-academy', 'ADMIN', 'The owner'),
        ('piano-academy', 'MEMBER', 'May use the app'),
        ('build-analyzer', 'ADMIN', 'Runs the build and catalog syncs and switches sources on and off'),
        ('build-analyzer', 'MEMBER', 'May import accounts and see their analysis')
       ) AS v(client_id, name, description)
  JOIN applications a ON a.client_id = v.client_id
ON CONFLICT DO NOTHING;

DO $$
BEGIN
    IF (SELECT count(*) FROM applications WHERE client_id IN ('iam', 'genshin', 'japanese-academy', 'piano-academy', 'build-analyzer')) <> 5 THEN
        RAISE EXCEPTION 'expected the five first-party applications';
    END IF;
    IF NOT EXISTS (SELECT 1 FROM application_roles r JOIN applications a ON a.id = r.application_id
                    WHERE a.client_id = 'iam' AND r.name = 'ADMIN') THEN
        RAISE EXCEPTION 'the IAM has no ADMIN role, so nobody could administer it';
    END IF;
    IF (SELECT count(*) FROM applications a
         WHERE a.client_id IN ('genshin', 'japanese-academy', 'piano-academy', 'build-analyzer')
           AND EXISTS (SELECT 1 FROM application_redirect_uris u WHERE u.application_id = a.id AND u.kind = 'login' AND u.uri = a.home_url || '/auth/callback')
           AND EXISTS (SELECT 1 FROM application_redirect_uris u WHERE u.application_id = a.id AND u.kind = 'logout' AND u.uri = a.home_url || '/')
           AND EXISTS (SELECT 1 FROM application_origins o WHERE o.application_id = a.id AND o.origin = a.home_url)) <> 4 THEN
        RAISE EXCEPTION 'every site should arrive with its sign-in callback, its landing after sign-out and its origin';
    END IF;
END $$;
