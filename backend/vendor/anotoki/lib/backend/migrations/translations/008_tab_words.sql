-- anotoki_translations 008 - the words of the kit's tabs of 0.6.0, under the anotoki library's namespace
-- anotoki.tabs.
--
-- What the kit's tabs and link tabs (@anotoki/lib/ui/tabs, which reads its words itself - the site's string first)
-- show where they do not fit their line and scroll sideways: the tooltips of the two small arrows at their edges,
-- "Earlier tabs" and "More tabs" - seen by a mouse alone, never heard (the keyboard and a screen reader reach every
-- tab itself). anotoki-lib's php/resources/library-words.json holds them - the Angular half's built-in words are made
-- from the same file, and a test of the library holds this file to it.
--
-- As in 002: a key's description is the library's, and is written again whenever a library migration carries it;
-- a key's strings are the site's once it has any - written here only for a library key that has no string at all.
-- So a site that took a key over before this file ran keeps its words.
--
-- Released with anotoki-lib 0.6.0, and never edited once released: a change is a new file.

INSERT INTO translation_keys (name, description) VALUES
    ('anotoki.tabs.scrollBack', 'The anotoki library''s tabs and link tabs, where they do not fit their line and scroll sideways: the tooltip of the small arrow at their start that brings the tabs before those in view - seen by a mouse alone, never heard (the keyboard and a screen reader reach every tab itself). No placeholders.'),
    ('anotoki.tabs.scrollForward', 'The anotoki library''s tabs and link tabs, where they do not fit their line and scroll sideways: the tooltip of the small arrow at their end that brings the tabs after those in view - seen by a mouse alone, never heard (the keyboard and a screen reader reach every tab itself). No placeholders.')
ON CONFLICT (name) DO UPDATE SET description = EXCLUDED.description;

-- One statement for every language: the rows it writes do not hide each other from the test of "no
-- string at all", which sees the table as it was before the statement began.
INSERT INTO translations (key_name, language_code, value)
SELECT v.key_name, v.language_code, v.value
  FROM (VALUES
    ('anotoki.tabs.scrollBack', 'en', 'Earlier tabs'),
    ('anotoki.tabs.scrollBack', 'sk', 'Predchádzajúce karty'),
    ('anotoki.tabs.scrollForward', 'en', 'More tabs'),
    ('anotoki.tabs.scrollForward', 'sk', 'Ďalšie karty')
  ) AS v(key_name, language_code, value)
  JOIN languages l ON l.code = v.language_code
 WHERE NOT EXISTS (SELECT 1 FROM translations t WHERE t.key_name = v.key_name)
ON CONFLICT (key_name, language_code) DO NOTHING;

-- The keys of this file, each with an English string: Slovak is not asked for - a site that took a key over may
-- have left it blank on purpose.
DO $$
DECLARE
    missing TEXT;
BEGIN
    SELECT string_agg(k.name, ', ' ORDER BY k.name) INTO missing
      FROM (VALUES
        ('anotoki.tabs.scrollBack'),
        ('anotoki.tabs.scrollForward')
      ) AS k(name)
     WHERE NOT EXISTS (SELECT 1 FROM translation_keys tk WHERE tk.name = k.name)
        OR NOT EXISTS (SELECT 1 FROM translations t WHERE t.key_name = k.name AND t.language_code = 'en');
    IF missing IS NOT NULL THEN
        RAISE EXCEPTION 'anotoki_translations 008: the library''s keys without an English string: %. A site that takes a library key over gives it its English.', missing;
    END IF;
END $$;

-- end of 008
