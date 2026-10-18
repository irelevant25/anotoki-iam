-- anotoki_translations 010 - the Privacy page's two sentences on where the backups are, made true where an
-- administrator keeps a downloaded copy
--
-- 005 said the copies "stay on the site's own server". An administrator may download one from the Backups page and
-- keep it elsewhere - the IAM's own operations guide asks its owner to keep one off the server -, so the sentence said
-- less than the truth (the IAM's site pass of 0.6.0 found it). This file rewords anotoki.legal.backupsWherePlain and
-- anotoki.legal.backupsWhereEncrypted in English and Slovak, each string only while it is still 005's word for word:
-- a site that reworded either on its Translations page keeps its words. The keys' descriptions stay as 005 wrote them.
-- Each row is written over two lines, as the words files never write theirs: this file writes no key's strings, it
-- rewords four (the library's test of the words files counts a row of strings a line).
-- anotoki-lib's php/resources/library-words.json holds the new words - the Angular half's built-in words are made
-- from the same file, and a test of the library holds the database's words to it.
--
-- Released with anotoki-lib 0.6.1, and never edited once released: a change is a new file.

UPDATE translations t
   SET value = v.new_value,
       updated_at = CURRENT_TIMESTAMP
  FROM (VALUES
    ('anotoki.legal.backupsWherePlain', 'en',
     'The copies stay on the site''s own server, out of public reach.',
     'The copies are kept on the site''s server, out of public reach; an administrator may also keep a downloaded copy elsewhere.'),
    ('anotoki.legal.backupsWherePlain', 'sk',
     'Kópie zostávajú na vlastnom serveri stránky, mimo verejného prístupu.',
     'Kópie sa uchovávajú na serveri stránky, mimo verejného prístupu; správca si môže stiahnutú kópiu uložiť aj inde.'),
    ('anotoki.legal.backupsWhereEncrypted', 'en',
     'The copies stay on the site''s own server, out of public reach, and are encrypted.',
     'The copies are kept on the site''s server, out of public reach, and are encrypted; an administrator may also keep a downloaded copy, encrypted the same way, elsewhere.'),
    ('anotoki.legal.backupsWhereEncrypted', 'sk',
     'Kópie zostávajú na vlastnom serveri stránky, mimo verejného prístupu, a sú šifrované.',
     'Kópie sa uchovávajú na serveri stránky, mimo verejného prístupu, a sú šifrované; správca si môže stiahnutú kópiu, rovnako šifrovanú, uložiť aj inde.')
  ) AS v(key_name, language_code, old_value, new_value)
 WHERE t.key_name = v.key_name
   AND t.language_code = v.language_code
   AND t.value = v.old_value;

-- The two keys of this file, each with an English string, and no string of theirs still 005's word for word: a
-- site's own words stand, whatever they say; 005's do not.
DO $$
DECLARE
    missing TEXT;
    stale TEXT;
BEGIN
    SELECT string_agg(k.name, ', ' ORDER BY k.name) INTO missing
      FROM (VALUES
        ('anotoki.legal.backupsWherePlain'),
        ('anotoki.legal.backupsWhereEncrypted')
      ) AS k(name)
     WHERE NOT EXISTS (SELECT 1 FROM translation_keys tk WHERE tk.name = k.name)
        OR NOT EXISTS (SELECT 1 FROM translations t WHERE t.key_name = k.name AND t.language_code = 'en');
    IF missing IS NOT NULL THEN
        RAISE EXCEPTION 'anotoki_translations 010: the library''s keys without an English string: %. A site that takes a library key over gives it its English.', missing;
    END IF;

    SELECT string_agg(t.key_name || ' (' || t.language_code || ')', ', ' ORDER BY t.key_name, t.language_code) INTO stale
      FROM translations t
      JOIN (VALUES
        ('anotoki.legal.backupsWherePlain', 'en',
         'The copies stay on the site''s own server, out of public reach.'),
        ('anotoki.legal.backupsWherePlain', 'sk',
         'Kópie zostávajú na vlastnom serveri stránky, mimo verejného prístupu.'),
        ('anotoki.legal.backupsWhereEncrypted', 'en',
         'The copies stay on the site''s own server, out of public reach, and are encrypted.'),
        ('anotoki.legal.backupsWhereEncrypted', 'sk',
         'Kópie zostávajú na vlastnom serveri stránky, mimo verejného prístupu, a sú šifrované.')
      ) AS old(key_name, language_code, value)
        ON old.key_name = t.key_name AND old.language_code = t.language_code AND old.value = t.value;
    IF stale IS NOT NULL THEN
        RAISE EXCEPTION 'anotoki_translations 010: still in 005''s words: %.', stale;
    END IF;
END $$;

-- end of 010
