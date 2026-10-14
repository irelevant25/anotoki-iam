-- anotoki_translations 009 - the words of the kit's input pieces of 0.6.0, under the anotoki library's namespaces
-- anotoki.dateField., anotoki.colorField., anotoki.filePicker., anotoki.combobox. and anotoki.richText.
--
-- What the kit's input pieces say, each an entry point of @anotoki/lib of its own that reads its words itself (the
-- site's string first): the date field's (@anotoki/lib/ui/date-field) - the name of its calendar's button and of the
-- calendar, the month buttons, and what it says under itself when what was typed is no date or one outside the dates
-- it takes -, the colour field's (@anotoki/lib/ui/color-field) - what a screen reader hears for its colour box and its
-- suggested colours, and what it says of a code that is no colour -, the file picker's (@anotoki/lib/ui/file-picker) -
-- its button and its drop area, why a file was not taken, a file's remove button, what was chosen -, the combobox's
-- (@anotoki/lib/ui/combobox) - "No matches", how many more match, the name of the chosen ones - and the rich-text
-- toolbar's (@anotoki/lib/ui/rich-text) - its name and its buttons'. Month and day names are never words here: the
-- browser writes them (Intl). anotoki-lib's php/resources/library-words.json holds them - the Angular half's built-in
-- words are made from the same file, and a test of the library holds this file to it.
--
-- As in 002: a key's description is the library's, and is written again whenever a library migration carries it;
-- a key's strings are the site's once it has any - written here only for a library key that has no string at all.
-- So a site that took a key over before this file ran keeps its words.
--
-- Released with anotoki-lib 0.6.0, and never edited once released: a change is a new file.

INSERT INTO translation_keys (name, description) VALUES
    ('anotoki.dateField.choose', 'The anotoki library''s date field (a date typed, or chosen in a calendar that opens beside it): the name of the small button inside the field that opens the calendar - and its tooltip - and the name of the calendar it opens. {field}: the field''s own label ("Birthday", "Opens"), as the page shows it, so that two date fields on a page have two buttons a screen reader tells apart. Placeholders: {field}.'),
    ('anotoki.dateField.previousMonth', 'The anotoki library''s date field: the name and the tooltip of the calendar''s button that shows the month before. No placeholders.'),
    ('anotoki.dateField.nextMonth', 'The anotoki library''s date field: the name and the tooltip of the calendar''s button that shows the month after. No placeholders.'),
    ('anotoki.dateField.invalid', 'The anotoki library''s date field: said under the field when what was typed is no date it can read ("32.13.", "tomorrow"). {example}: a date written the way the field takes them - 31 December, in the order and with the separators of the reader''s date format ("31.12.2026", "12/31/2026"; "31.12." for a day and a month without a year). Placeholders: {example}.'),
    ('anotoki.dateField.tooEarly', 'The anotoki library''s date field: said under the field when the date typed is before the earliest one the field takes. {date}: that earliest date, written the way the field writes dates. Placeholders: {date}.'),
    ('anotoki.dateField.tooLate', 'The anotoki library''s date field: said under the field when the date typed is after the latest one the field takes. {date}: that latest date, written the way the field writes dates. Placeholders: {date}.'),
    ('anotoki.colorField.picker', 'The anotoki library''s colour field (a colour box that opens the device''s own colour picker, and a field for the colour''s code beside it): what a screen reader hears after the field''s label for the colour box - "Text colour, pick from the palette". Heard, never seen. No placeholders.'),
    ('anotoki.colorField.swatches', 'The anotoki library''s colour field: what a screen reader hears after the field''s label for the row of suggested colours under it, each a colour with its name - "Text colour, suggested colours". Heard, never seen. No placeholders.'),
    ('anotoki.colorField.invalid', 'The anotoki library''s colour field: said under the field when the colour''s code typed is not one it can read. A code is # and six digits 0-9 or letters a-f: keep "#1f5fbf" as it is. No placeholders.'),
    ('anotoki.filePicker.chooseFile', 'The anotoki library''s file picker (a button that opens the device''s file chooser, and an area a file may be dropped on): the button''s words where one file is taken. No placeholders.'),
    ('anotoki.filePicker.chooseFiles', 'The anotoki library''s file picker: the button''s words where several files are taken. No placeholders.'),
    ('anotoki.filePicker.dropFile', 'The anotoki library''s file picker: the words in the area a file may be dropped on (with a mouse), beside the button, where one file is taken. Not shown on a touch screen. No placeholders.'),
    ('anotoki.filePicker.dropFiles', 'The anotoki library''s file picker: the words in the area files may be dropped on (with a mouse), beside the button, where several files are taken. Not shown on a touch screen. No placeholders.'),
    ('anotoki.filePicker.tooLarge', 'The anotoki library''s file picker: said when a file chosen or dropped is larger than the site takes, and it was not taken. {name}: the file''s name; {size}: its size; {max}: the largest size taken - both written the page''s way ("20 MB"). Placeholders: {name}, {size}, {max}.'),
    ('anotoki.filePicker.wrongType', 'The anotoki library''s file picker: said when a file chosen or dropped is not of a kind the site takes, and it was not taken. {name}: the file''s name; {types}: the kinds taken, as the site gives them (".json", "image/*"). Placeholders: {name}, {types}.'),
    ('anotoki.filePicker.oneOnly', 'The anotoki library''s file picker: said when several files are dropped where one alone is taken - none of them was taken. No placeholders.'),
    ('anotoki.filePicker.remove', 'The anotoki library''s file picker: what a screen reader hears for the small button beside a chosen file that takes it away again, and its tooltip - the file''s name in it, so a list of buttons says which. {name}: the file''s name. Placeholders: {name}.'),
    ('anotoki.filePicker.chosen', 'The anotoki library''s file picker: what a screen reader is told once files were chosen or dropped (the list under the picker shows them). Heard, never seen. {names}: the names of the files taken, with commas between them. Placeholders: {names}.'),
    ('anotoki.combobox.noMatches', 'The anotoki library''s combobox (a field that lists what matches what is typed, to choose one or several - characters with their portraits): said in the list when nothing matches what was typed, and told to a screen reader. No placeholders.'),
    ('anotoki.combobox.more', 'The anotoki library''s combobox: said under the list when more match than it shows at once, and told to a screen reader. {count}: how many more match, written the page''s way. Placeholders: {count}.'),
    ('anotoki.combobox.chosen', 'The anotoki library''s combobox, where several may be chosen: the name a screen reader hears for the list of the chosen ones over the field (each with a button that takes it away). {field}: the field''s own label, as the page shows it. Heard, never seen. Placeholders: {field}.'),
    ('anotoki.richText.toolbar', 'The anotoki library''s rich-text toolbar (the row of buttons over a text editor that make words bold, a list, a link): its name, where the site gives none. No placeholders.'),
    ('anotoki.richText.bold', 'The anotoki library''s rich-text toolbar: the name and the tooltip of the button that makes the words bold. No placeholders.'),
    ('anotoki.richText.italic', 'The anotoki library''s rich-text toolbar: the name and the tooltip of the button that makes the words italic. No placeholders.'),
    ('anotoki.richText.underline', 'The anotoki library''s rich-text toolbar: the name and the tooltip of the button that underlines the words. No placeholders.'),
    ('anotoki.richText.strike', 'The anotoki library''s rich-text toolbar: the name and the tooltip of the button that strikes the words through. No placeholders.'),
    ('anotoki.richText.bulletList', 'The anotoki library''s rich-text toolbar: the name and the tooltip of the button that makes the lines a bulleted list. No placeholders.'),
    ('anotoki.richText.orderedList', 'The anotoki library''s rich-text toolbar: the name and the tooltip of the button that makes the lines a numbered list. No placeholders.'),
    ('anotoki.richText.indent', 'The anotoki library''s rich-text toolbar: the name and the tooltip of the button that indents the lines more. No placeholders.'),
    ('anotoki.richText.outdent', 'The anotoki library''s rich-text toolbar: the name and the tooltip of the button that indents the lines less. No placeholders.'),
    ('anotoki.richText.link', 'The anotoki library''s rich-text toolbar: the name and the tooltip of the button that makes the words a link (the site asks for its address). No placeholders.'),
    ('anotoki.richText.clear', 'The anotoki library''s rich-text toolbar: the name and the tooltip of the button that takes the words'' formatting away (bold, italic, a colour). No placeholders.'),
    ('anotoki.richText.undo', 'The anotoki library''s rich-text toolbar: the name and the tooltip of the button that undoes the last change. No placeholders.'),
    ('anotoki.richText.redo', 'The anotoki library''s rich-text toolbar: the name and the tooltip of the button that does again what was undone. No placeholders.')
ON CONFLICT (name) DO UPDATE SET description = EXCLUDED.description;

-- One statement for every language: the rows it writes do not hide each other from the test of "no
-- string at all", which sees the table as it was before the statement began.
INSERT INTO translations (key_name, language_code, value)
SELECT v.key_name, v.language_code, v.value
  FROM (VALUES
    ('anotoki.dateField.choose', 'en', 'Choose a date: {field}'),
    ('anotoki.dateField.choose', 'sk', 'Vybrať dátum: {field}'),
    ('anotoki.dateField.previousMonth', 'en', 'Previous month'),
    ('anotoki.dateField.previousMonth', 'sk', 'Predchádzajúci mesiac'),
    ('anotoki.dateField.nextMonth', 'en', 'Next month'),
    ('anotoki.dateField.nextMonth', 'sk', 'Nasledujúci mesiac'),
    ('anotoki.dateField.invalid', 'en', 'Write the date like {example}.'),
    ('anotoki.dateField.invalid', 'sk', 'Dátum treba zadať v tvare {example}.'),
    ('anotoki.dateField.tooEarly', 'en', 'The earliest date is {date}.'),
    ('anotoki.dateField.tooEarly', 'sk', 'Najskorší možný dátum je {date}.'),
    ('anotoki.dateField.tooLate', 'en', 'The latest date is {date}.'),
    ('anotoki.dateField.tooLate', 'sk', 'Najneskorší možný dátum je {date}.'),
    ('anotoki.colorField.picker', 'en', 'Pick from the palette'),
    ('anotoki.colorField.picker', 'sk', 'Vybrať z palety'),
    ('anotoki.colorField.swatches', 'en', 'Suggested colours'),
    ('anotoki.colorField.swatches', 'sk', 'Navrhované farby'),
    ('anotoki.colorField.invalid', 'en', 'Write the colour as # and six digits or letters a–f, like #1f5fbf.'),
    ('anotoki.colorField.invalid', 'sk', 'Farbu treba zadať ako # a šesť číslic alebo písmen a – f, napríklad #1f5fbf.'),
    ('anotoki.filePicker.chooseFile', 'en', 'Choose a file'),
    ('anotoki.filePicker.chooseFile', 'sk', 'Vybrať súbor'),
    ('anotoki.filePicker.chooseFiles', 'en', 'Choose files'),
    ('anotoki.filePicker.chooseFiles', 'sk', 'Vybrať súbory'),
    ('anotoki.filePicker.dropFile', 'en', 'Drop a file here'),
    ('anotoki.filePicker.dropFile', 'sk', 'Súbor sem stačí pretiahnuť'),
    ('anotoki.filePicker.dropFiles', 'en', 'Drop files here'),
    ('anotoki.filePicker.dropFiles', 'sk', 'Súbory sem stačí pretiahnuť'),
    ('anotoki.filePicker.tooLarge', 'en', '{name} is too large: {size}, at most {max}.'),
    ('anotoki.filePicker.tooLarge', 'sk', 'Súbor {name} je príliš veľký: {size}, najviac {max}.'),
    ('anotoki.filePicker.wrongType', 'en', '{name} is not a kind of file taken here ({types}).'),
    ('anotoki.filePicker.wrongType', 'sk', 'Súbor {name} nie je z povolených typov ({types}).'),
    ('anotoki.filePicker.oneOnly', 'en', 'Only one file can be chosen here.'),
    ('anotoki.filePicker.oneOnly', 'sk', 'Tu sa dá vybrať iba jeden súbor.'),
    ('anotoki.filePicker.remove', 'en', 'Remove: {name}'),
    ('anotoki.filePicker.remove', 'sk', 'Odstrániť: {name}'),
    ('anotoki.filePicker.chosen', 'en', 'Chosen: {names}'),
    ('anotoki.filePicker.chosen', 'sk', 'Vybrané: {names}'),
    ('anotoki.combobox.noMatches', 'en', 'No matches'),
    ('anotoki.combobox.noMatches', 'sk', 'Nič sa nenašlo'),
    ('anotoki.combobox.more', 'en', '{count} more: keep typing to narrow the list.'),
    ('anotoki.combobox.more', 'sk', 'Ďalších možností: {count} – zoznam zúži ďalšie písanie.'),
    ('anotoki.combobox.chosen', 'en', '{field}: chosen'),
    ('anotoki.combobox.chosen', 'sk', '{field}: vybrané'),
    ('anotoki.richText.toolbar', 'en', 'Formatting'),
    ('anotoki.richText.toolbar', 'sk', 'Formátovanie'),
    ('anotoki.richText.bold', 'en', 'Bold'),
    ('anotoki.richText.bold', 'sk', 'Tučné'),
    ('anotoki.richText.italic', 'en', 'Italic'),
    ('anotoki.richText.italic', 'sk', 'Kurzíva'),
    ('anotoki.richText.underline', 'en', 'Underline'),
    ('anotoki.richText.underline', 'sk', 'Podčiarknuté'),
    ('anotoki.richText.strike', 'en', 'Strikethrough'),
    ('anotoki.richText.strike', 'sk', 'Prečiarknuté'),
    ('anotoki.richText.bulletList', 'en', 'Bulleted list'),
    ('anotoki.richText.bulletList', 'sk', 'Odrážky'),
    ('anotoki.richText.orderedList', 'en', 'Numbered list'),
    ('anotoki.richText.orderedList', 'sk', 'Číslovaný zoznam'),
    ('anotoki.richText.indent', 'en', 'Indent more'),
    ('anotoki.richText.indent', 'sk', 'Zväčšiť odsadenie'),
    ('anotoki.richText.outdent', 'en', 'Indent less'),
    ('anotoki.richText.outdent', 'sk', 'Zmenšiť odsadenie'),
    ('anotoki.richText.link', 'en', 'Link'),
    ('anotoki.richText.link', 'sk', 'Odkaz'),
    ('anotoki.richText.clear', 'en', 'Clear formatting'),
    ('anotoki.richText.clear', 'sk', 'Zrušiť formátovanie'),
    ('anotoki.richText.undo', 'en', 'Undo'),
    ('anotoki.richText.undo', 'sk', 'Späť'),
    ('anotoki.richText.redo', 'en', 'Redo'),
    ('anotoki.richText.redo', 'sk', 'Znova')
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
        ('anotoki.dateField.choose'),
        ('anotoki.dateField.previousMonth'),
        ('anotoki.dateField.nextMonth'),
        ('anotoki.dateField.invalid'),
        ('anotoki.dateField.tooEarly'),
        ('anotoki.dateField.tooLate'),
        ('anotoki.colorField.picker'),
        ('anotoki.colorField.swatches'),
        ('anotoki.colorField.invalid'),
        ('anotoki.filePicker.chooseFile'),
        ('anotoki.filePicker.chooseFiles'),
        ('anotoki.filePicker.dropFile'),
        ('anotoki.filePicker.dropFiles'),
        ('anotoki.filePicker.tooLarge'),
        ('anotoki.filePicker.wrongType'),
        ('anotoki.filePicker.oneOnly'),
        ('anotoki.filePicker.remove'),
        ('anotoki.filePicker.chosen'),
        ('anotoki.combobox.noMatches'),
        ('anotoki.combobox.more'),
        ('anotoki.combobox.chosen'),
        ('anotoki.richText.toolbar'),
        ('anotoki.richText.bold'),
        ('anotoki.richText.italic'),
        ('anotoki.richText.underline'),
        ('anotoki.richText.strike'),
        ('anotoki.richText.bulletList'),
        ('anotoki.richText.orderedList'),
        ('anotoki.richText.indent'),
        ('anotoki.richText.outdent'),
        ('anotoki.richText.link'),
        ('anotoki.richText.clear'),
        ('anotoki.richText.undo'),
        ('anotoki.richText.redo')
      ) AS k(name)
     WHERE NOT EXISTS (SELECT 1 FROM translation_keys tk WHERE tk.name = k.name)
        OR NOT EXISTS (SELECT 1 FROM translations t WHERE t.key_name = k.name AND t.language_code = 'en');
    IF missing IS NOT NULL THEN
        RAISE EXCEPTION 'anotoki_translations 009: the library''s keys without an English string: %. A site that takes a library key over gives it its English.', missing;
    END IF;
END $$;

-- end of 009
