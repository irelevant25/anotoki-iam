-- anotoki_translations 007 - the words of the kit's pieces of 0.6.0, under the anotoki library's namespaces
-- anotoki.chips., anotoki.progress. and anotoki.stepper.
--
-- What the kit's new pieces say, each an entry point of @anotoki/lib of its own that reads its words itself (the
-- site's string first): the name of a removable chip's button ("Remove: Furina" - @anotoki/lib/ui/chips,
-- <anotoki-chip-list>), a progress bar's count ("3 of 10" - @anotoki/lib/ui/progress), and what a screen reader hears
-- of a stepper's or progress dots' steps ("Step 2 of 4", "done" - @anotoki/lib/ui/stepper). anotoki-lib's
-- php/resources/library-words.json holds them - the Angular half's built-in words are made from the same file, and a
-- test of the library holds this file to it.
--
-- As in 002: a key's description is the library's, and is written again whenever a library migration carries it;
-- a key's strings are the site's once it has any - written here only for a library key that has no string at all.
-- So a site that took a key over before this file ran keeps its words.
--
-- Released with anotoki-lib 0.6.0, and never edited once released: a change is a new file.

INSERT INTO translation_keys (name, description) VALUES
    ('anotoki.chips.remove', 'The anotoki library''s removable chips (the things a person chose or added, each with a small button that takes it away): what a screen reader hears for that button, and its tooltip - the chip''s own words in it, so a list of buttons says which. {name}: the chip''s words ("Furina", an answer the learner added), as they are shown. Placeholders: {name}.'),
    ('anotoki.progress.count', 'The anotoki library''s progress bar, counting things done: the words beside the bar, and what a screen reader hears for its value - "3 of 10". {value}: how many are done; {max}: how many there are - both numbers written the page''s way. Placeholders: {value}, {max}.'),
    ('anotoki.stepper.stepOf', 'The anotoki library''s stepper and progress dots (the steps of a wizard, of a lesson): what a screen reader hears before the current step''s name - "Step 2 of 4: Import". Heard, never seen. {current}: the step''s number; {total}: how many steps there are. Placeholders: {current}, {total}.'),
    ('anotoki.stepper.done', 'The anotoki library''s stepper and progress dots: what a screen reader hears after the name of a step that is done - "Choose the game, done" (a check mark says it to the eye). Heard, never seen. No placeholders.')
ON CONFLICT (name) DO UPDATE SET description = EXCLUDED.description;

-- One statement for every language: the rows it writes do not hide each other from the test of "no
-- string at all", which sees the table as it was before the statement began.
INSERT INTO translations (key_name, language_code, value)
SELECT v.key_name, v.language_code, v.value
  FROM (VALUES
    ('anotoki.chips.remove', 'en', 'Remove: {name}'),
    ('anotoki.chips.remove', 'sk', 'Odstrániť: {name}'),
    ('anotoki.progress.count', 'en', '{value} of {max}'),
    ('anotoki.progress.count', 'sk', '{value} z {max}'),
    ('anotoki.stepper.stepOf', 'en', 'Step {current} of {total}'),
    ('anotoki.stepper.stepOf', 'sk', 'Krok {current} z {total}'),
    ('anotoki.stepper.done', 'en', 'done'),
    ('anotoki.stepper.done', 'sk', 'hotovo')
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
        ('anotoki.chips.remove'),
        ('anotoki.progress.count'),
        ('anotoki.stepper.stepOf'),
        ('anotoki.stepper.done')
      ) AS k(name)
     WHERE NOT EXISTS (SELECT 1 FROM translation_keys tk WHERE tk.name = k.name)
        OR NOT EXISTS (SELECT 1 FROM translations t WHERE t.key_name = k.name AND t.language_code = 'en');
    IF missing IS NOT NULL THEN
        RAISE EXCEPTION 'anotoki_translations 007: the library''s keys without an English string: %. A site that takes a library key over gives it its English.', missing;
    END IF;
END $$;

-- end of 007
