-- anotoki_translations 011 - three sentences of the Privacy page made true on every site
--
-- The site passes of 0.6.0 found three of the library's sentences true of some sites only:
--   anotoki.legal.privacyAccountRecord  said a site "does not keep your account's e-mail address", but a site that
--                                       takes feedback keeps the reply address the form fills in from the account
--                                       (until the account is deleted: the scrub takes it out, as it does the name);
--   anotoki.legal.backupsKept           said "the daily copies for {daily} days", but the library keeps the
--                                       {daily} newest by count, and makes a daily copy only on a day the site is
--                                       used;
--   anotoki.legal.storageSignInPurpose  said "when the site last signed you in by itself", which a site that never
--                                       signs anybody in without a click (the survey: no sign-in hint) does not do.
-- This file rewords the six strings, each only while it is still 005's word for word and nobody saved it
-- (updated_by IS NULL): a site's own wording, and an administrator's deliberate save of 005's words, stay. The
-- descriptions are the library's and are written again (backupsKept's: by count). Each row is written over several
-- lines, as the words files never write theirs: this file writes no key, it rewords six strings (the library's test
-- of the words files counts a row of strings a line). anotoki-lib's php/resources/library-words.json holds the new
-- words - the Angular half's built-in words are made from the same file, and a test of the library holds the
-- database's words to it.
--
-- Released with anotoki-lib 0.6.2, and never edited once released: a change is a new file.

INSERT INTO translation_keys (name, description) VALUES
    ('anotoki.legal.backupsKept',
     'The anotoki library''s Privacy page: how long the backups stay - kept by count, not by age. {daily}: how many daily copies are kept (14; a daily copy is made only on a day the site is used). {beforeUpdate}: how many copies made before an update are kept (5). {beforeRestore}: how many made before a restore (5). Placeholders: {daily}, {beforeUpdate}, {beforeRestore}.')
ON CONFLICT (name) DO UPDATE SET description = EXCLUDED.description;

UPDATE translations t
   SET value = v.new_value,
       updated_at = CURRENT_TIMESTAMP
  FROM (VALUES
    ('anotoki.legal.backupsKept', 'en',
     'The daily copies for {daily} days; the {beforeUpdate} newest made before an update and the {beforeRestore} newest made before a restore; a copy made by hand until an administrator deletes it. What is deleted on the site stays in the copies made before, until they go.',
     'The {daily} newest daily copies (a daily copy is made on a day the site is used); the {beforeUpdate} newest made before an update and the {beforeRestore} newest made before a restore; a copy made by hand until an administrator deletes it. What is deleted on the site stays in the copies made before, until they go.'),
    ('anotoki.legal.backupsKept', 'sk',
     'Denné kópie {daily} dní; {beforeUpdate} najnovších kópií pred aktualizáciou a {beforeRestore} najnovších pred obnovením; ručne vytvorená kópia, kým ju správca nezmaže. Čo sa na stránke zmaže, zostáva v skôr vytvorených kópiách, kým nezaniknú.',
     '{daily} najnovších denných kópií (denná kópia vzniká v deň, keď sa stránka používa); {beforeUpdate} najnovších kópií pred aktualizáciou a {beforeRestore} najnovších pred obnovením; ručne vytvorená kópia, kým ju správca nezmaže. Čo sa na stránke zmaže, zostáva v skôr vytvorených kópiách, kým nezaniknú.'),
    ('anotoki.legal.privacyAccountRecord', 'en',
     '{site} never sees your password, and it does not keep your account''s e-mail address. It keeps its own record of you - your account''s id, the name the account gives, and what you do here - as listed above. When your anotoki account is deleted, {site} takes your name out of what it keeps; what you did here stays, without your name.',
     '{site} never sees your password, and it keeps your account''s e-mail address only as the reply address of a feedback message you send, if you leave it in the form (on a site that takes feedback). It keeps its own record of you - your account''s id, the name the account gives, and what you do here - as listed above. When your anotoki account is deleted, {site} takes your name and any reply address out of what it keeps; what you did here stays, without them.'),
    ('anotoki.legal.privacyAccountRecord', 'sk',
     'Stránka {site} heslo nikdy nevidí a e-mailovú adresu účtu si neukladá. Vedie si vlastný záznam - identifikátor účtu, meno, ktoré účet poskytne, a to, čo sa na stránke robí - ako je uvedené vyššie. Po zmazaní účtu anotoki stránka {site} z uchovávaných údajov odstráni meno; to, čo sa na nej urobilo, zostane, ale bez mena.',
     'Stránka {site} heslo nikdy nevidí a e-mailovú adresu účtu si ukladá len ako adresu na odpoveď pri správe so spätnou väzbou, ak sa vo formulári ponechá (na stránkach, ktoré spätnú väzbu prijímajú). Vedie si vlastný záznam - identifikátor účtu, meno, ktoré účet poskytne, a to, čo sa na stránke robí - ako je uvedené vyššie. Po zmazaní účtu anotoki stránka {site} z uchovávaných údajov odstráni meno aj adresu na odpoveď; to, čo sa na nej urobilo, zostane, ale bez nich.'),
    ('anotoki.legal.storageSignInPurpose', 'en',
     'Your sign-in to this site - the keys the anotoki account gave it - and small notes the sign-in needs (where it started, when the site last signed you in by itself).',
     'Your sign-in to this site - the keys the anotoki account gave it - and small notes the sign-in needs (where it started, and on a site that signs you in without a click, when it last did).'),
    ('anotoki.legal.storageSignInPurpose', 'sk',
     'Prihlásenie do tejto stránky - kľúče, ktoré jej vydal účet anotoki - a drobné poznámky, ktoré prihlásenie potrebuje (odkiaľ sa začalo, kedy stránka naposledy prihlásila sama).',
     'Prihlásenie do tejto stránky - kľúče, ktoré jej vydal účet anotoki - a drobné poznámky, ktoré prihlásenie potrebuje (odkiaľ sa začalo a - na stránke, ktorá prihlasuje bez kliknutia - kedy to naposledy urobila).')
  ) AS v(key_name, language_code, old_value, new_value)
 WHERE t.key_name = v.key_name
   AND t.language_code = v.language_code
   AND t.value = v.old_value
   AND t.updated_by IS NULL;

-- The three keys of this file, each with an English string, and none of their strings that nobody saved still in
-- 005's words: a site's own words, and an administrator's save, stand whatever they say; 005's do not.
DO $$
DECLARE
    missing TEXT;
    stale TEXT;
BEGIN
    SELECT string_agg(k.name, ', ' ORDER BY k.name) INTO missing
      FROM (VALUES
        ('anotoki.legal.backupsKept'),
        ('anotoki.legal.privacyAccountRecord'),
        ('anotoki.legal.storageSignInPurpose')
      ) AS k(name)
     WHERE NOT EXISTS (SELECT 1 FROM translation_keys tk WHERE tk.name = k.name)
        OR NOT EXISTS (SELECT 1 FROM translations t WHERE t.key_name = k.name AND t.language_code = 'en');
    IF missing IS NOT NULL THEN
        RAISE EXCEPTION 'anotoki_translations 011: the library''s keys without an English string: %. A site that takes a library key over gives it its English.', missing;
    END IF;

    SELECT string_agg(t.key_name || ' (' || t.language_code || ')', ', ' ORDER BY t.key_name, t.language_code) INTO stale
      FROM translations t
      JOIN (VALUES
        ('anotoki.legal.backupsKept', 'en',
         'The daily copies for {daily} days; the {beforeUpdate} newest made before an update and the {beforeRestore} newest made before a restore; a copy made by hand until an administrator deletes it. What is deleted on the site stays in the copies made before, until they go.'),
        ('anotoki.legal.backupsKept', 'sk',
         'Denné kópie {daily} dní; {beforeUpdate} najnovších kópií pred aktualizáciou a {beforeRestore} najnovších pred obnovením; ručne vytvorená kópia, kým ju správca nezmaže. Čo sa na stránke zmaže, zostáva v skôr vytvorených kópiách, kým nezaniknú.'),
        ('anotoki.legal.privacyAccountRecord', 'en',
         '{site} never sees your password, and it does not keep your account''s e-mail address. It keeps its own record of you - your account''s id, the name the account gives, and what you do here - as listed above. When your anotoki account is deleted, {site} takes your name out of what it keeps; what you did here stays, without your name.'),
        ('anotoki.legal.privacyAccountRecord', 'sk',
         'Stránka {site} heslo nikdy nevidí a e-mailovú adresu účtu si neukladá. Vedie si vlastný záznam - identifikátor účtu, meno, ktoré účet poskytne, a to, čo sa na stránke robí - ako je uvedené vyššie. Po zmazaní účtu anotoki stránka {site} z uchovávaných údajov odstráni meno; to, čo sa na nej urobilo, zostane, ale bez mena.'),
        ('anotoki.legal.storageSignInPurpose', 'en',
         'Your sign-in to this site - the keys the anotoki account gave it - and small notes the sign-in needs (where it started, when the site last signed you in by itself).'),
        ('anotoki.legal.storageSignInPurpose', 'sk',
         'Prihlásenie do tejto stránky - kľúče, ktoré jej vydal účet anotoki - a drobné poznámky, ktoré prihlásenie potrebuje (odkiaľ sa začalo, kedy stránka naposledy prihlásila sama).')
      ) AS old(key_name, language_code, value)
        ON old.key_name = t.key_name AND old.language_code = t.language_code AND old.value = t.value
     WHERE t.updated_by IS NULL;
    IF stale IS NOT NULL THEN
        RAISE EXCEPTION 'anotoki_translations 011: still in 005''s words: %.', stale;
    END IF;
END $$;

-- end of 011
