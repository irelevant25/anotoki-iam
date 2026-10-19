-----------------------------------------------------------
-- WHAT'S NEW
--
-- The owner's decision of 2026-10-19: the IAM gets the row the family's
-- footer has on the other anotoki sites - its version, What's new and
-- Feedback - starting at version 1.0.0. The version is the newest entry of
-- the frontend's public/changelog.json; What's new is the IAM's own dialog
-- (frontend ui/changelog-dialog), which lists every entry of that file,
-- newest first, with what each changed for the people who use it - the
-- sentences are the file's, in English and an informal Slovak. Six page keys
-- are the dialog's own words around them: its heading, a version's name, the
-- mark beside the newest, and the headings of what a version added, put
-- right and changed. Their English (en.ts has the same) and their Slovak
-- (none of the six speaks to the reader), in 006's form - every insert steps
-- aside for a row that is already there.
--
-- The footer's own words (the version, What's new, Feedback), the feedback
-- form's and the Privacy page's of what a feedback message keeps are the
-- anotoki library's (anotoki.footer.*, anotoki.feedback.*,
-- anotoki.legal.feedback*, its translations set's 012, 004 and 005): no key
-- of them here.
-----------------------------------------------------------

INSERT INTO translation_keys (name, description) VALUES
    ('whatsNew.title', 'What''s new: the heading of the dialog that lists the anotoki account''s versions, newest first, and what each changed for the people who use it (opened from What''s new at the foot of every page). No placeholders.'),
    ('whatsNew.version', 'What''s new: a version''s name, above what it changed. {version}: its number, such as 1.0.0 - keep it as it is. Placeholders: {version}.'),
    ('whatsNew.latest', 'What''s new: the mark beside the newest version - the one the anotoki account runs now. Short. No placeholders.'),
    ('whatsNew.added', 'What''s new: the heading of a version''s list of what it added - something people can do now that they could not before. No placeholders.'),
    ('whatsNew.fixed', 'What''s new: the heading of a version''s list of what it put right. No placeholders.'),
    ('whatsNew.updated', 'What''s new: the heading of a version''s list of what works or looks otherwise now. No placeholders.')
ON CONFLICT (name) DO NOTHING;

INSERT INTO translations (key_name, language_code, value)
SELECT v.key_name, 'en', v.value FROM (VALUES
    ('whatsNew.title', 'What''s new'),
    ('whatsNew.version', 'Version {version}'),
    ('whatsNew.latest', 'Latest'),
    ('whatsNew.added', 'Added'),
    ('whatsNew.fixed', 'Fixed'),
    ('whatsNew.updated', 'Changed')
) AS v(key_name, value)
ON CONFLICT (key_name, language_code) DO NOTHING;

-- Slovak.
INSERT INTO translations (key_name, language_code, value)
SELECT v.key_name, 'sk', v.value FROM (VALUES
    ('whatsNew.title', 'Čo je nové'),
    ('whatsNew.version', 'Verzia {version}'),
    ('whatsNew.latest', 'Najnovšia'),
    ('whatsNew.added', 'Pridané'),
    ('whatsNew.fixed', 'Opravené'),
    ('whatsNew.updated', 'Zmenené')
) AS v(key_name, value)
ON CONFLICT (key_name, language_code) DO NOTHING;

-----------------------------------------------------------
-- PROVE IT
--
-- Every key this file writes has its English and its Slovak string. Only
-- these keys: the admin panel may blank a Slovak string elsewhere on
-- purpose, and the library adds keys of its own.
-----------------------------------------------------------

DO $$
DECLARE
    missing TEXT;
BEGIN
    SELECT string_agg(wanted.name, ', ' ORDER BY wanted.name) INTO missing
      FROM (VALUES ('whatsNew.title'), ('whatsNew.version'), ('whatsNew.latest'), ('whatsNew.added'), ('whatsNew.fixed'), ('whatsNew.updated')) AS wanted(name)
     WHERE NOT EXISTS (SELECT 1 FROM translation_keys k WHERE k.name = wanted.name)
        OR NOT EXISTS (SELECT 1 FROM translations t WHERE t.key_name = wanted.name AND t.language_code = 'en')
        OR NOT EXISTS (SELECT 1 FROM translations t WHERE t.key_name = wanted.name AND t.language_code = 'sk');
    IF missing IS NOT NULL THEN
        RAISE EXCEPTION 'What''s new''s keys without an English and a Slovak string: %', missing;
    END IF;
END $$;
