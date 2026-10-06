-----------------------------------------------------------
-- THE PERSON'S DISPLAY PREFERENCES, KEPT BY THE IAM FOR EVERY SITE
--
-- The owner (2026-10-06): the appearance, dates, clock and language settings
-- the genshin site kept of its own "should be in the iam only and apply for
-- every project". Asked, the owner chose that a signed-in person may change
-- them anywhere - a site's own control saves to the IAM
-- (POST /api/oidc/preferences) - and one theme for every site and every area,
-- the admin panels included. It reverses "Preferences stay with the sites"
-- (docs/decisions.md, 2026-09-27).
--
-- The language was the account's already (users.language, the `locale`
-- claim). This adds the other three, which every site reads from the
-- `preferences` claim:
--
-- - users.theme: auto (the device's own light or dark), light or dark. Auto
--   for every account there is: the IAM's pages kept the theme per device
--   until now, and no account ever chose one.
-- - users.date_format: how a date is written - dmy_dot (6.10.2026),
--   dmy_slash (06/10/2026), mdy_slash (10/06/2026), ymd_dash (2026-10-06) -
--   or NULL, automatic: as each site writes dates by the page's language.
-- - users.time_format: the clock, 24 or 12 hours, or NULL: automatic.
--
-- The vocabulary is the genshin site's own (its users/029), which kept them
-- per site until now.
--
-- The words: the account page's two new choices, and the two lines that said
-- the language and the theme were this page's and this device's - reworded
-- only where they still hold 006's words, so a rewording of the owner's in
-- the admin panel stays. English and Slovak (informal, "ty").
-----------------------------------------------------------

ALTER TABLE users ADD COLUMN IF NOT EXISTS theme VARCHAR(5) NOT NULL DEFAULT 'auto';

ALTER TABLE users ADD COLUMN IF NOT EXISTS date_format VARCHAR(10);

ALTER TABLE users ADD COLUMN IF NOT EXISTS time_format VARCHAR(2);

-- Dropped and added again, so a second run is the same as the first.
ALTER TABLE users DROP CONSTRAINT IF EXISTS users_theme_check;

ALTER TABLE users ADD CONSTRAINT users_theme_check CHECK (theme IN ('auto', 'light', 'dark'));

ALTER TABLE users DROP CONSTRAINT IF EXISTS users_date_format_check;

ALTER TABLE users ADD CONSTRAINT users_date_format_check CHECK (date_format IS NULL OR date_format IN ('dmy_dot', 'dmy_slash', 'mdy_slash', 'ymd_dash'));

ALTER TABLE users DROP CONSTRAINT IF EXISTS users_time_format_check;

ALTER TABLE users ADD CONSTRAINT users_time_format_check CHECK (time_format IS NULL OR time_format IN ('24', '12'));

-----------------------------------------------------------
-- THE WORDS
-----------------------------------------------------------

INSERT INTO translation_keys (name, description) VALUES
    ('overview.dateFormat', 'Account: overview. The chooser of how dates are written on every site - its options are today''s date written each way. No placeholders.'),
    ('overview.timeFormat', 'Account: overview. The chooser of the clock, 24 or 12 hours, on every site. No placeholders.'),
    ('overview.formatAutomatic', 'Account: overview. The first option of both choosers: each site writes dates and times as it does by the page''s language. No placeholders.'),
    ('overview.clock24', 'Account: overview. The 24-hour option of the clock chooser; the page puts an example after it. No placeholders.'),
    ('overview.clock12', 'Account: overview. The 12-hour option of the clock chooser; the page puts an example after it. No placeholders.'),
    ('overview.formatsHint', 'Account: overview. Under the dates and clock choosers. No placeholders.'),
    ('overview.preferencesSaved', 'Account: overview. Said once a change of the theme, the dates or the clock is saved. No placeholders.')
ON CONFLICT (name) DO NOTHING;

INSERT INTO translations (key_name, language_code, value)
SELECT v.key_name, 'en', v.value FROM (VALUES
    ('overview.dateFormat', 'Dates'),
    ('overview.timeFormat', 'Clock'),
    ('overview.formatAutomatic', 'Automatic'),
    ('overview.clock24', '24-hour'),
    ('overview.clock12', '12-hour'),
    ('overview.formatsHint', 'How every anotoki site where you use your account writes dates and times.'),
    ('overview.preferencesSaved', 'Saved - every anotoki site follows it.')
) AS v(key_name, value)
ON CONFLICT (key_name, language_code) DO NOTHING;

-- Slovak, informal.
INSERT INTO translations (key_name, language_code, value)
SELECT v.key_name, 'sk', v.value FROM (VALUES
    ('overview.dateFormat', 'Dátumy'),
    ('overview.timeFormat', 'Hodiny'),
    ('overview.formatAutomatic', 'Automaticky'),
    ('overview.clock24', '24-hodinové'),
    ('overview.clock12', '12-hodinové'),
    ('overview.formatsHint', 'Ako píše dátumy a čas každá stránka anotoki, kde používaš svoj účet.'),
    ('overview.preferencesSaved', 'Uložené - riadi sa tým každá stránka anotoki.')
) AS v(key_name, value)
ON CONFLICT (key_name, language_code) DO NOTHING;

-- The theme is the account's now, not the device's; and the language reaches
-- every site. Only where 006's words are still there.
UPDATE translations SET value = 'Every anotoki site where you use your account follows it.', updated_at = CURRENT_TIMESTAMP, updated_by = NULL
 WHERE key_name = 'overview.appearanceHint' AND language_code = 'en' AND value = 'Remembered on this device.';

UPDATE translations SET value = 'Riadi sa ním každá stránka anotoki, kde používaš svoj účet.', updated_at = CURRENT_TIMESTAMP, updated_by = NULL
 WHERE key_name = 'overview.appearanceHint' AND language_code = 'sk' AND value = 'Pamätá sa v tomto zariadení.';

UPDATE translations SET value = 'Used on every anotoki site where you use your account, and in the emails we send you.', updated_at = CURRENT_TIMESTAMP, updated_by = NULL
 WHERE key_name = 'overview.languageHint' AND language_code = 'en' AND value = 'Used on these pages and in the emails we send you.';

UPDATE translations SET value = 'Na každej stránke anotoki, kde používaš svoj účet, a v e-mailoch, ktoré ti posielame.', updated_at = CURRENT_TIMESTAMP, updated_by = NULL
 WHERE key_name = 'overview.languageHint' AND language_code = 'sk' AND value = 'Na týchto stránkach a v e-mailoch, ktoré ti posielame.';

-----------------------------------------------------------
-- PROVE IT
--
-- The three columns in the shape the code reads, auto for every account and
-- nothing chosen yet; and the keys this file writes in English and Slovak.
-- Only these keys: the admin panel may blank a Slovak string elsewhere on
-- purpose, and a reworded line may be the owner's.
-----------------------------------------------------------

DO $$
DECLARE
    missing TEXT;
BEGIN
    IF EXISTS (SELECT 1 FROM users WHERE theme <> 'auto' OR date_format IS NOT NULL OR time_format IS NOT NULL) THEN
        RAISE EXCEPTION 'every account there was before this file should start on auto, with no date format or clock chosen';
    END IF;
    IF (SELECT count(*) FROM pg_constraint
         WHERE conrelid = 'users'::regclass AND contype = 'c' AND convalidated
           AND conname IN ('users_theme_check', 'users_date_format_check', 'users_time_format_check')) <> 3 THEN
        RAISE EXCEPTION 'users is missing a check on theme, date_format or time_format';
    END IF;

    SELECT string_agg(wanted.name, ', ' ORDER BY wanted.name) INTO missing
      FROM (VALUES ('overview.dateFormat'), ('overview.timeFormat'), ('overview.formatAutomatic'), ('overview.clock24'),
                   ('overview.clock12'), ('overview.formatsHint'), ('overview.preferencesSaved')) AS wanted(name)
     WHERE NOT EXISTS (SELECT 1 FROM translations t WHERE t.key_name = wanted.name AND t.language_code = 'en')
        OR NOT EXISTS (SELECT 1 FROM translations t WHERE t.key_name = wanted.name AND t.language_code = 'sk');
    IF missing IS NOT NULL THEN
        RAISE EXCEPTION 'the account preferences'' keys without an English and a Slovak string: %', missing;
    END IF;
END $$;
