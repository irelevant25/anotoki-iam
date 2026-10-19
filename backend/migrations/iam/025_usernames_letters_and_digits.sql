-----------------------------------------------------------
-- USERNAMES: LETTERS AND DIGITS
--
-- The owner's decision of 2026-10-19, closing Low 1 of that day's security
-- review: a username is letters and digits only - a-z, A-Z, 0-9 - and at most
-- 100 of them, so that none looks like an email address ("From
-- jan@example.com" on the admin panel's Feedback page) or turns the direction
-- of the text (U+202E). The rule is the code's (usernameRefusal(),
-- backend/api/helpers.php); this file gives the pages their words for it.
--
-- error.invalidUsername - 006's "That username cannot be used." - says what is
-- allowed now, in English and in an informal Slovak ("ty"). This file is
-- applied on installed IAMs, so that is an UPDATE - but only of a string that
-- still says what 006 wrote and that no administrator saved (updated_by): one
-- saved on the admin panel's Translations page is the owner's choice -
-- reworded, or put back to 006's very words on purpose - and stays, with its
-- writer; so does one the owner blanked there (the panel deletes it: there is
-- no row to update).
--
-- A new page key, common.usernameHint, is the line under the username field
-- on the register page and on the account page: what a username may have.
-- Its English (en.ts has the same) and its Slovak, in 006's form - each
-- insert steps aside for a row that is already there.
--
-- And register.usernameHint goes: "What you would like to be called.", the
-- register page's line under the field, asked for a name as people write
-- theirs - spaces and accents included -, which the rule now refuses. The
-- page shows common.usernameHint in its place, and nothing shows the old key,
-- so it goes with its strings (translations.key_name's ON DELETE CASCADE) - a
-- rewording of the owner's among them. Once this file is applied, no build
-- from before it goes on the host again: it would show register.usernameHint
-- as a key.
-----------------------------------------------------------

INSERT INTO translation_keys (name, description) VALUES
    ('common.usernameHint', 'Under the username field, on the register page and on the account page where the username is changed: what a username may have - letters and digits only, a-z, A-Z and 0-9, no space, dot or accent (the server''s rule since 2026-10-19). No placeholders.')
ON CONFLICT (name) DO NOTHING;

INSERT INTO translations (key_name, language_code, value)
SELECT v.key_name, 'en', v.value FROM (VALUES
    ('common.usernameHint', 'Letters and digits only (a–z, A–Z, 0–9).')
) AS v(key_name, value)
ON CONFLICT (key_name, language_code) DO NOTHING;

-- Slovak, informal.
INSERT INTO translations (key_name, language_code, value)
SELECT v.key_name, 'sk', v.value FROM (VALUES
    ('common.usernameHint', 'Len písmená bez diakritiky a číslice (a–z, A–Z, 0–9).')
) AS v(key_name, value)
ON CONFLICT (key_name, language_code) DO NOTHING;

-- What the pages say of a username the rule refuses: 006's words, and only those, reworded - where no administrator saved the string.
UPDATE translations SET value = 'Use only letters and digits (a–z, A–Z, 0–9), at most 100.', updated_at = CURRENT_TIMESTAMP
 WHERE key_name = 'error.invalidUsername' AND language_code = 'en' AND value = 'That username cannot be used.' AND updated_by IS NULL;

-- Slovak, informal.
UPDATE translations SET value = 'Použi len písmená bez diakritiky a číslice (a–z, A–Z, 0–9), najviac 100.', updated_at = CURRENT_TIMESTAMP
 WHERE key_name = 'error.invalidUsername' AND language_code = 'sk' AND value = 'Toto používateľské meno sa nedá použiť.' AND updated_by IS NULL;

-- The register page's old line under the field, with its strings.
DELETE FROM translation_keys WHERE name = 'register.usernameHint';

-----------------------------------------------------------
-- PROVE IT
--
-- The new key has its English and its Slovak string. Only this key: the
-- admin panel may blank a Slovak string elsewhere on purpose, and the library
-- adds keys of its own. error.invalidUsername no longer says 006's words in
-- either language where nobody saved it: a string an administrator saved is
-- theirs - in 006's own words too -, one reworded holds other words, and a
-- blanked one none: all pass. And register.usernameHint is gone.
-----------------------------------------------------------

DO $$
DECLARE
    missing TEXT;
    left_over TEXT;
BEGIN
    SELECT string_agg(wanted.name, ', ' ORDER BY wanted.name) INTO missing
      FROM (VALUES ('common.usernameHint')) AS wanted(name)
     WHERE NOT EXISTS (SELECT 1 FROM translation_keys k WHERE k.name = wanted.name)
        OR NOT EXISTS (SELECT 1 FROM translations t WHERE t.key_name = wanted.name AND t.language_code = 'en')
        OR NOT EXISTS (SELECT 1 FROM translations t WHERE t.key_name = wanted.name AND t.language_code = 'sk');
    IF missing IS NOT NULL THEN
        RAISE EXCEPTION 'the username''s hint without an English and a Slovak string: %', missing;
    END IF;

    SELECT string_agg(t.language_code, ', ' ORDER BY t.language_code) INTO left_over
      FROM translations t
     WHERE t.key_name = 'error.invalidUsername' AND t.updated_by IS NULL
       AND ((t.language_code = 'en' AND t.value = 'That username cannot be used.')
         OR (t.language_code = 'sk' AND t.value = 'Toto používateľské meno sa nedá použiť.'));
    IF left_over IS NOT NULL THEN
        RAISE EXCEPTION 'error.invalidUsername still does not say what a username may have: %', left_over;
    END IF;

    IF EXISTS (SELECT 1 FROM translation_keys WHERE name = 'register.usernameHint') THEN
        RAISE EXCEPTION 'register.usernameHint is still there';
    END IF;
END $$;
