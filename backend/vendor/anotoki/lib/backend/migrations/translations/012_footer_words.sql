-- anotoki_translations 012 - the words of the family's footer of 0.6.3, under the anotoki library's namespace
-- anotoki.footer.
--
-- What the footer (@anotoki/lib/footer, <anotoki-site-footer>, which reads its words itself - the site's string first)
-- says at the foot of every page of a site and of its admin panel: "Version {version}", "What's new" and "Send
-- feedback" in its first row - the last the heading of the feedback form's dialog too -, and the fan clause on a site
-- that asks for it (the genshin site, the build analyzer). Terms · Privacy · Contact under the row are the legal
-- links' words (005). anotoki-lib's php/resources/library-words.json holds them - the Angular half's built-in words are
-- made from the same file, and a test of the library holds this file to it.
--
-- As in 002: a key's description is the library's, and is written again whenever a library migration carries it;
-- a key's strings are the site's once it has any - written here only for a library key that has no string at all.
-- So a site that took a key over before this file ran keeps its words.
--
-- Released with anotoki-lib 0.6.3, and never edited once released: a change is a new file.

INSERT INTO translation_keys (name, description) VALUES
    ('anotoki.footer.version', 'The anotoki library''s footer (the foot of every page of a site, and of its admin panel): the version of the site the page runs, first in its row - before What''s new and Send feedback. The footer''s Slovak addresses nobody - no ty, no Vy - so that it fits every site. {version}: the version number, shown as it is ("0.3", "1.2.0"). Placeholders: {version}.'),
    ('anotoki.footer.whatsNew', 'The anotoki library''s footer: the button beside the version that opens the site''s own What''s new - the notes of each of its releases, in a dialog of the site''s. Short: it stands in one row with the version and Send feedback. No placeholders.'),
    ('anotoki.footer.feedback', 'The anotoki library''s footer, on a site that takes feedback: the button that opens the feedback form (the anotoki library''s form, in a dialog), and the heading of that dialog. Short: it stands in one row with the version and What''s new. No placeholders.'),
    ('anotoki.footer.fanHoyoverse', 'The anotoki library''s footer, on a site that asks for it (the genshin site, the build analyzer): the fan clause, the last line under Terms · Privacy · Contact. Terms and Contact say it longer (anotoki.legal.fanHoyoverse). HoYoverse is the games'' publisher, a name never translated. No placeholders.')
ON CONFLICT (name) DO UPDATE SET description = EXCLUDED.description;

-- One statement for every language: the rows it writes do not hide each other from the test of "no
-- string at all", which sees the table as it was before the statement began.
INSERT INTO translations (key_name, language_code, value)
SELECT v.key_name, v.language_code, v.value
  FROM (VALUES
    ('anotoki.footer.version', 'en', 'Version {version}'),
    ('anotoki.footer.version', 'sk', 'Verzia {version}'),
    ('anotoki.footer.whatsNew', 'en', 'What''s new'),
    ('anotoki.footer.whatsNew', 'sk', 'Čo je nové'),
    ('anotoki.footer.feedback', 'en', 'Feedback'),
    ('anotoki.footer.feedback', 'sk', 'Spätná väzba'),
    ('anotoki.footer.fanHoyoverse', 'en', 'This site is a fan-made project and is not affiliated with or endorsed by HoYoverse.'),
    ('anotoki.footer.fanHoyoverse', 'sk', 'Táto stránka je fanúšikovský projekt a nie je prepojená so spoločnosťou HoYoverse ani ňou schválená.')
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
        ('anotoki.footer.version'),
        ('anotoki.footer.whatsNew'),
        ('anotoki.footer.feedback'),
        ('anotoki.footer.fanHoyoverse')
      ) AS k(name)
     WHERE NOT EXISTS (SELECT 1 FROM translation_keys tk WHERE tk.name = k.name)
        OR NOT EXISTS (SELECT 1 FROM translations t WHERE t.key_name = k.name AND t.language_code = 'en');
    IF missing IS NOT NULL THEN
        RAISE EXCEPTION 'anotoki_translations 012: the library''s keys without an English string: %. A site that takes a library key over gives it its English.', missing;
    END IF;
END $$;

-- end of 012
