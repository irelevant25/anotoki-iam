-----------------------------------------------------------
-- THE TOP BAR'S WORDS
--
-- The account pages and the admin console take the anotoki family's one top
-- bar (frontend layouts/top-bar): the brand, the account pages as links, the
-- language, the theme and the person's menu in one row, folding into one menu
-- button on a phone. Three strings are new: that menu button, the role in the
-- person's menu, and the language button's name, which says the language it
-- shows and the code written on it ("Language: Slovenčina (SK)"): a name holds
-- what the button says, so a voice command naming it finds it (WCAG 2.5.3).
--
-- One is gone: the person's menu had "Account" for the account page. The
-- account pages are the bar's own links now, so nothing says it any more; the
-- key goes, and its strings with it (a key nobody uses is refused by
-- tests/translations.test.php).
--
-- Additive otherwise, in 006's form: every insert steps aside for a row that
-- is already there, so nothing reworded in the admin console is written over.
-- Slovak speaks to the reader informally ("ty"), as the IAM always has.
-----------------------------------------------------------

INSERT INTO translation_keys (name, description) VALUES
    ('shell.menu', 'The top bar on a phone: the menu button that holds the account pages and the person''s menu, and that menu''s name. No placeholders.'),
    ('shell.roleAdmin', 'The top bar: the role under the person''s name in their menu, for an administrator of the IAM. No placeholders.'),
    ('lang.button', 'The top bar: the language button''s name for a screen reader; it shows only the code. {name} is the language on the page, as its own speakers write it ("Slovenčina"); {code} is the code the button shows ("SK"), which the name must keep, so that a voice command naming the button finds it. Placeholders: {name}, {code}.')
ON CONFLICT (name) DO NOTHING;

INSERT INTO translations (key_name, language_code, value)
SELECT v.key_name, 'en', v.value FROM (VALUES
    ('shell.menu', 'Menu'),
    ('shell.roleAdmin', 'Administrator'),
    ('lang.button', 'Language: {name} ({code})')
) AS v(key_name, value)
ON CONFLICT (key_name, language_code) DO NOTHING;

-- Slovak, informal.
INSERT INTO translations (key_name, language_code, value)
SELECT v.key_name, 'sk', v.value FROM (VALUES
    ('shell.menu', 'Ponuka'),
    ('shell.roleAdmin', 'Administrátor'),
    ('lang.button', 'Jazyk: {name} ({code})')
) AS v(key_name, value)
ON CONFLICT (key_name, language_code) DO NOTHING;

-- Its strings go with it (translations.key_name ... ON DELETE CASCADE).
DELETE FROM translation_keys WHERE name = 'shell.account';

-----------------------------------------------------------
-- PROVE IT
--
-- The keys this file writes read in English and in Slovak, and the key it
-- takes away is gone with its strings. Only these keys: the console may
-- blank a Slovak string elsewhere on purpose, and that is no failure here.
-----------------------------------------------------------

DO $$
DECLARE
    missing TEXT;
BEGIN
    SELECT string_agg(wanted.name, ', ' ORDER BY wanted.name) INTO missing
      FROM (VALUES ('shell.menu'), ('shell.roleAdmin'), ('lang.button')) AS wanted(name)
     WHERE NOT EXISTS (SELECT 1 FROM translations t WHERE t.key_name = wanted.name AND t.language_code = 'en')
        OR NOT EXISTS (SELECT 1 FROM translations t WHERE t.key_name = wanted.name AND t.language_code = 'sk');
    IF missing IS NOT NULL THEN
        RAISE EXCEPTION 'the top bar''s keys without an English and a Slovak string: %', missing;
    END IF;

    IF EXISTS (SELECT 1 FROM translation_keys WHERE name = 'shell.account')
       OR EXISTS (SELECT 1 FROM translations WHERE key_name = 'shell.account') THEN
        RAISE EXCEPTION 'shell.account should be gone, with its strings';
    END IF;
END $$;
