-----------------------------------------------------------
-- THE IAM'S WORDS, THROUGH THE ANOTOKI LIBRARY
--
-- The languages, the strings and their admin pages are the anotoki family's
-- library's now (anotoki-lib 0.3.0: Anotoki\Lib\Translations on the server,
-- @anotoki/lib/translations in the pages) - one implementation for every
-- site, built from the IAM's own. The tables stay as 006 made them, in the
-- shape the library's own first file (anotoki_translations/001) proves, and
-- every string in them stays where it is. Four keys change, nothing else.
--
-- 1. The language switcher is the library's, and it says its two words under
--    the library's keys: lang.label becomes anotoki.language.label
--    ("Language") and lang.button anotoki.language.button ("Language: {name}
--    ({code})"). Every string of the two old keys - in every language,
--    reworded or not, with who wrote it and when - is copied under the new
--    key, then the old key goes, its strings with it. The library's second
--    file (anotoki_translations/002, applied after this one: the IAM's set
--    runs first) writes a library key's strings only where the key has none
--    at all. So it finds these and leaves them as they are: a rewording of the
--    owner's stays the owner's, and a Slovak string the owner blanked stays
--    blank (no row - Slovak reads the English there, as it did).
--
-- 2. Two keys are renamed: sessions.method.other becomes
--    sessions.method.unknown, and sessions.ended.other
--    sessions.ended.unknown - what the account page's Sessions list says for
--    a way of signing in, or a reason a session ended, that the page does not
--    know. The family's key scan reads a key that ends in .one, .few or .other
--    as a form of a plural family, and these two are none; no other key of
--    the IAM ends so. The strings follow the new name by the foreign key's ON
--    UPDATE CASCADE (006, which the library's 001 requires too): rewordings,
--    blanks, writers and dates exactly as they were.
--
-- Nothing is reworded. The two library keys' descriptions are the library's:
-- its 002 writes them over the stand-in below.
-----------------------------------------------------------

-- What the four keys hold before anything moves - for the moves, and for the
-- gate, which proves every one of these strings arrived. A table of this
-- file's own, dropped at its end, after the gate: the file runs in one
-- transaction, so a gate that fails takes the table back with everything else
-- and leaves nothing behind. Not a temporary table: that needs the TEMP
-- privilege, which no other IAM migration needs and a host may have revoked.
CREATE TABLE iam_017_moved_words AS
SELECT m.old_key, m.new_key, t.language_code, t.value, t.updated_at, t.updated_by
  FROM (VALUES
      ('lang.label', 'anotoki.language.label'),
      ('lang.button', 'anotoki.language.button'),
      ('sessions.method.other', 'sessions.method.unknown'),
      ('sessions.ended.other', 'sessions.ended.unknown')
  ) AS m(old_key, new_key)
  JOIN translations t ON t.key_name = m.old_key;

-- The library's two keys, ahead of the library's own file.
INSERT INTO translation_keys (name, description) VALUES
    ('anotoki.language.label', 'The anotoki library''s language switcher: the library''s own migration (anotoki_translations/002) describes it.'),
    ('anotoki.language.button', 'The anotoki library''s language switcher: the library''s own migration (anotoki_translations/002) describes it.')
ON CONFLICT (name) DO NOTHING;

-- Every string of the old keys under the library's, with who wrote it and when.
INSERT INTO translations (key_name, language_code, value, updated_at, updated_by)
SELECT w.new_key, w.language_code, w.value, w.updated_at, w.updated_by
  FROM iam_017_moved_words w
 WHERE w.new_key LIKE 'anotoki.%'
ON CONFLICT (key_name, language_code) DO NOTHING;

-- The old keys go; their strings with them (translations.key_name ... ON DELETE CASCADE).
DELETE FROM translation_keys WHERE name IN ('lang.label', 'lang.button');

-- Renamed; their strings follow (translations.key_name ... ON UPDATE CASCADE).
UPDATE translation_keys SET name = 'sessions.method.unknown' WHERE name = 'sessions.method.other';

UPDATE translation_keys SET name = 'sessions.ended.unknown' WHERE name = 'sessions.ended.other';

-----------------------------------------------------------
-- PROVE IT
--
-- Only these four keys: every string they had is under its new key, word for
-- word, with the same writer and the same time; the old names are gone with
-- their strings; each new key is there with its English. Slovak is not asked
-- for - the owner may have blanked it on purpose, and that blank moved too.
-----------------------------------------------------------

DO $$
DECLARE
    lost TEXT;
    left_over TEXT;
    missing TEXT;
BEGIN
    SELECT string_agg(w.new_key || ' (' || w.language_code || ')', ', ' ORDER BY w.new_key, w.language_code) INTO lost
      FROM iam_017_moved_words w
     WHERE NOT EXISTS (SELECT 1 FROM translations t
                        WHERE t.key_name = w.new_key
                          AND t.language_code = w.language_code
                          AND t.value = w.value
                          AND t.updated_at = w.updated_at
                          AND t.updated_by IS NOT DISTINCT FROM w.updated_by);
    IF lost IS NOT NULL THEN
        RAISE EXCEPTION 'strings that did not arrive under their new keys as they were: %', lost;
    END IF;

    SELECT string_agg(old.name, ', ' ORDER BY old.name) INTO left_over
      FROM (VALUES ('lang.label'), ('lang.button'), ('sessions.method.other'), ('sessions.ended.other')) AS old(name)
     WHERE EXISTS (SELECT 1 FROM translation_keys k WHERE k.name = old.name)
        OR EXISTS (SELECT 1 FROM translations t WHERE t.key_name = old.name);
    IF left_over IS NOT NULL THEN
        RAISE EXCEPTION 'keys that should be gone, with their strings: %', left_over;
    END IF;

    SELECT string_agg(wanted.name, ', ' ORDER BY wanted.name) INTO missing
      FROM (VALUES ('anotoki.language.label'), ('anotoki.language.button'), ('sessions.method.unknown'), ('sessions.ended.unknown')) AS wanted(name)
     WHERE NOT EXISTS (SELECT 1 FROM translation_keys k WHERE k.name = wanted.name)
        OR NOT EXISTS (SELECT 1 FROM translations t WHERE t.key_name = wanted.name AND t.language_code = 'en');
    IF missing IS NOT NULL THEN
        RAISE EXCEPTION 'the moved and renamed keys without an English string: %', missing;
    END IF;
END $$;

-- Proved: the snapshot has done its work.
DROP TABLE iam_017_moved_words;
