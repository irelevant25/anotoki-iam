-- anotoki_translations 006 - the top bar's word for the number beside a link, under the anotoki library's namespace
-- anotoki.topbar.
--
-- What a screen reader hears after the name of one of the site's links that has a number beside it
-- (@anotoki/lib/shell, <anotoki-top-bar>: the new feedback beside "Feedback" in an admin panel - "Feedback, 3 new"),
-- where the site does not word what its number counts itself; the number alone stays beside the link, as it was. The
-- kit's side navigation (@anotoki/lib/ui/side-nav, the same release) says a link's number in the same words.
-- anotoki-lib's php/resources/library-words.json holds it - the Angular half's built-in words are made from the same
-- file, and a test of the library holds this file to it.
--
-- As in 002: a key's description is the library's, and is written again whenever a library migration carries it;
-- a key's strings are the site's once it has any - written here only for a library key that has no string at all.
-- So a site that took the key over before this file ran keeps its words.
--
-- Released with anotoki-lib 0.6.0, and never edited once released: a change is a new file.

INSERT INTO translation_keys (name, description) VALUES
    ('anotoki.topbar.linkCount', 'The anotoki library''s top bar and side navigation: what a screen reader hears after the name of one of the site''s links that has a number beside it - the new feedback in an admin panel: "Feedback, 3 new" - where the site does not word what its number counts itself. Heard, never seen: the number alone stays beside the link. {count}: the number. Placeholders: {count}.')
ON CONFLICT (name) DO UPDATE SET description = EXCLUDED.description;

-- One statement for every language: the rows it writes do not hide each other from the test of "no
-- string at all", which sees the table as it was before the statement began.
INSERT INTO translations (key_name, language_code, value)
SELECT v.key_name, v.language_code, v.value
  FROM (VALUES
    ('anotoki.topbar.linkCount', 'en', '{count} new'),
    ('anotoki.topbar.linkCount', 'sk', 'nové: {count}')
  ) AS v(key_name, language_code, value)
  JOIN languages l ON l.code = v.language_code
 WHERE NOT EXISTS (SELECT 1 FROM translations t WHERE t.key_name = v.key_name)
ON CONFLICT (key_name, language_code) DO NOTHING;

-- The key of this file, with an English string: Slovak is not asked for - a site that took the key over may
-- have left it blank on purpose.
DO $$
DECLARE
    missing TEXT;
BEGIN
    SELECT string_agg(k.name, ', ' ORDER BY k.name) INTO missing
      FROM (VALUES
        ('anotoki.topbar.linkCount')
      ) AS k(name)
     WHERE NOT EXISTS (SELECT 1 FROM translation_keys tk WHERE tk.name = k.name)
        OR NOT EXISTS (SELECT 1 FROM translations t WHERE t.key_name = k.name AND t.language_code = 'en');
    IF missing IS NOT NULL THEN
        RAISE EXCEPTION 'anotoki_translations 006: the library''s keys without an English string: %. A site that takes a library key over gives it its English.', missing;
    END IF;
END $$;

-- end of 006
