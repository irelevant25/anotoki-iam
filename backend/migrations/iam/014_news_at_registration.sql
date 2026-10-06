-----------------------------------------------------------
-- THE NEWS, OFFERED AT REGISTRATION
--
-- The owner (2026-10-06), after the security review of the announcements
-- (013) noted that a person signing up was never asked about them: "add [an]
-- offer checkbox in registration for sign-up for announcements". The
-- registration form now offers the news from the sites in a box of its own,
-- unticked: an account made there gets announcements only when the box is
-- ticked, and one made by signing up with Google from that page as its box
-- says. Accounts a person makes elsewhere were never offered it and get none
-- until they switch it on on the account page; accounts an administrator
-- makes, the import brings, and every account there was before keep it on,
-- with the link at the end of every announcement to stop it (decisions.md).
--
-- No schema: users.news_mail is 013's. The words of the box and the line
-- under it, in English and Slovak (informal, "ty"), in 006's form: every
-- insert steps aside for a row that is already there.
-----------------------------------------------------------

INSERT INTO translation_keys (name, description) VALUES
    ('register.newsMail', 'Register page: the box that asks for the announcements administrators send - the news from the anotoki sites. Unticked until the person ticks it. No placeholders.'),
    ('register.newsMailHint', 'Register page: under the news box - what the news is, and that it can be stopped. No placeholders.')
ON CONFLICT (name) DO NOTHING;

INSERT INTO translations (key_name, language_code, value)
SELECT v.key_name, 'en', v.value FROM (VALUES
    ('register.newsMail', 'Send me news from anotoki sites'),
    ('register.newsMailHint', 'Now and then an email about a new version, a new site or a survey. You can stop them at any time.')
) AS v(key_name, value)
ON CONFLICT (key_name, language_code) DO NOTHING;

-- Slovak, informal.
INSERT INTO translations (key_name, language_code, value)
SELECT v.key_name, 'sk', v.value FROM (VALUES
    ('register.newsMail', 'Chcem dostávať novinky zo stránok anotoki'),
    ('register.newsMailHint', 'Občas e-mail o novej verzii, novej stránke alebo prieskume. Odhlásiť sa môžeš kedykoľvek.')
) AS v(key_name, value)
ON CONFLICT (key_name, language_code) DO NOTHING;

-----------------------------------------------------------
-- PROVE IT
--
-- Both keys this file writes have their English and their Slovak string.
-- Only these keys: the admin panel may blank a Slovak string elsewhere on
-- purpose.
-----------------------------------------------------------

DO $$
DECLARE
    missing TEXT;
BEGIN
    SELECT string_agg(wanted.name, ', ' ORDER BY wanted.name) INTO missing
      FROM (VALUES ('register.newsMail'), ('register.newsMailHint')) AS wanted(name)
     WHERE NOT EXISTS (SELECT 1 FROM translations t WHERE t.key_name = wanted.name AND t.language_code = 'en')
        OR NOT EXISTS (SELECT 1 FROM translations t WHERE t.key_name = wanted.name AND t.language_code = 'sk');
    IF missing IS NOT NULL THEN
        RAISE EXCEPTION 'the registration''s news keys without an English and a Slovak string: %', missing;
    END IF;
END $$;
