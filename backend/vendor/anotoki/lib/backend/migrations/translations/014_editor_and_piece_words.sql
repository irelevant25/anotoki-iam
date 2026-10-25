-- anotoki_translations 014 - the words of the kit's pieces of 0.11.0, under the anotoki library's namespaces
-- anotoki.dates., anotoki.numberField., anotoki.multiselect., anotoki.audioPlayer. and anotoki.htmlEditor., and the file
-- picker's folder (anotoki.filePicker.chooseFolder, anotoki.filePicker.folder).
--
-- What the kit's pieces of 0.11.0 say, each an entry point of @anotoki/lib of its own that reads its words itself (the
-- site's string first): the dates' (@anotoki/lib/ui/dates) - "just now", "in a moment", a date as a phrase; how long ago
-- in minutes, hours and days is the browser's (Intl), in the page's language and its plurals -, the number field's
-- (@anotoki/lib/ui/number-field) - what it says under itself of a number it does not take -, the multiselect's
-- (@anotoki/lib/ui/multiselect) - "and 3 more", "None chosen", Clear -, the audio player's (@anotoki/lib/ui/audio-player)
-- - Play, Pause, Stop, where the sound is -, the file picker's when it takes a folder (@anotoki/lib/ui/file-picker), and
-- the HTML editor's (@anotoki/lib/ui/html-editor) - its own buttons, its sizes, its colours, its source and preview, its
-- link's dialog. anotoki-lib's backend/resources/library-words.json holds them - the Angular half's built-in words are
-- made from the same file, and a test of the library holds this file to it.
--
-- As in 002: a key's description is the library's, and is written again whenever a library migration carries it;
-- a key's strings are the site's once it has any - written here only for a library key that has no string at all.
-- So a site that took a key over before this file ran keeps its words.
--
-- Released with anotoki-lib 0.11.0, and never edited once released: a change is a new file.

INSERT INTO translation_keys (name, description) VALUES
    ('anotoki.filePicker.chooseFolder', 'The anotoki library''s file picker, where the site takes a whole folder: the button''s words, which open the device''s folder chooser. No placeholders.'),
    ('anotoki.filePicker.folder', 'The anotoki library''s file picker, where the site takes a whole folder: the line that names the folder chosen, under the picker. {name}: the folder''s name; {count}: how many files it holds, its folders'' included, written the page''s way. Placeholders: {name}, {count}.'),
    ('anotoki.dates.justNow', 'The anotoki library''s dates (how long ago a moment was, or how soon it comes, as a site writes it beside a comment, a sign-in, a save): said of a moment less than a minute ago. A lower-case phrase that stands inside a sentence ("Saved just now"). No placeholders.'),
    ('anotoki.dates.soon', 'The anotoki library''s dates: said of a moment less than a minute from now (a review that is due, a quiz that opens). A lower-case phrase that stands inside a sentence. No placeholders.'),
    ('anotoki.dates.onDate', 'The anotoki library''s dates: a date as a phrase that stands inside a sentence, where the library writes the date in place of how long ago - a week away or more ("You sent your answers on 16 Oct 2026"). {date}: the date, written the reader''s way. Slovak needs no preposition. Placeholders: {date}.'),
    ('anotoki.numberField.invalid', 'The anotoki library''s number field (a number typed, a dot or a comma before its decimals): said under the field when what was typed is no number. {example}: a number written the way the field takes them ("12", "1.5" or "1,5"). Placeholders: {example}.'),
    ('anotoki.numberField.whole', 'The anotoki library''s number field: said under the field when the number typed has decimals and the field takes whole numbers alone. No placeholders.'),
    ('anotoki.numberField.step', 'The anotoki library''s number field: said under the field when the number typed is not one of the field''s steps - more decimals than it takes (steps of 0.01), or not a multiple of its step (steps of 5). {step}: the step, written the page''s way. Placeholders: {step}.'),
    ('anotoki.numberField.tooSmall', 'The anotoki library''s number field: said under the field when the number typed is below the smallest it takes. {min}: that smallest number, written the page''s way. Placeholders: {min}.'),
    ('anotoki.numberField.tooLarge', 'The anotoki library''s number field: said under the field when the number typed is above the largest it takes. {max}: that largest number, written the page''s way. Placeholders: {max}.'),
    ('anotoki.multiselect.more', 'The anotoki library''s multiselect (a filter''s button that says what is chosen, opening a list of checkboxes): said on its button after the first chosen names when more are chosen ("Furina, Neuvillette and 3 more"). {count}: how many more, written the page''s way. Placeholders: {count}.'),
    ('anotoki.multiselect.noneChosen', 'The anotoki library''s multiselect: what its button says while nothing is chosen and the site gives no words of its own (a filter''s "Any"). No placeholders.'),
    ('anotoki.multiselect.clear', 'The anotoki library''s multiselect: the button under its checkboxes that unticks every one of them. No placeholders.'),
    ('anotoki.multiselect.empty', 'The anotoki library''s multiselect: said in its list when there is nothing in it to choose. No placeholders.'),
    ('anotoki.audioPlayer.player', 'The anotoki library''s audio player (a sound''s play and pause button, where it is in it, and how long it is): the name a screen reader hears for the player where the site gives it none (the file''s name). No placeholders.'),
    ('anotoki.audioPlayer.play', 'The anotoki library''s audio player: the name and the tooltip of its button while the sound is not playing. No placeholders.'),
    ('anotoki.audioPlayer.pause', 'The anotoki library''s audio player: the name and the tooltip of its button while the sound plays. No placeholders.'),
    ('anotoki.audioPlayer.stop', 'The anotoki library''s audio player: the name and the tooltip of its button that stops the sound and goes back to its start. No placeholders.'),
    ('anotoki.audioPlayer.position', 'The anotoki library''s audio player: the name a screen reader hears for the slider that says, and moves, where the sound is. No placeholders.'),
    ('anotoki.audioPlayer.positionText', 'The anotoki library''s audio player: what a screen reader hears of the slider''s value. {elapsed}: how far into the sound it is; {total}: how long the sound is - both minutes and seconds ("1:05"). Placeholders: {elapsed}, {total}.'),
    ('anotoki.audioPlayer.failed', 'The anotoki library''s audio player: said in its place when the sound could not be loaded or played. No placeholders.'),
    ('anotoki.htmlEditor.toolbar', 'The anotoki library''s HTML editor (a text with bold, lists, links, sizes and colours, as a site''s admin panel writes a guide or a survey''s question): the name a screen reader hears for its row of buttons. {field}: the editor''s own label, as the page shows it. Placeholders: {field}.'),
    ('anotoki.htmlEditor.heading', 'The anotoki library''s HTML editor: the name and the tooltip of a button that makes the line a heading, or a heading a line again. {level}: the heading''s level (2 the largest, 4 the smallest). Placeholders: {level}.'),
    ('anotoki.htmlEditor.highlight', 'The anotoki library''s HTML editor: the name and the tooltip of the button that marks the selected words as with a highlighter, or takes the mark away. No placeholders.'),
    ('anotoki.htmlEditor.size', 'The anotoki library''s HTML editor: the name of the menu of text sizes. No placeholders.'),
    ('anotoki.htmlEditor.sizeChosen', 'The anotoki library''s HTML editor: the name a screen reader hears for the button that opens the menu of text sizes. {size}: the size chosen, in the menu''s words ("Normal"). Placeholders: {size}.'),
    ('anotoki.htmlEditor.size1', 'The anotoki library''s HTML editor: the smallest of the seven text sizes in its menu. No placeholders.'),
    ('anotoki.htmlEditor.size2', 'The anotoki library''s HTML editor: the second of the seven text sizes in its menu. No placeholders.'),
    ('anotoki.htmlEditor.size3', 'The anotoki library''s HTML editor: the third of the seven text sizes in its menu - the text''s own. No placeholders.'),
    ('anotoki.htmlEditor.size4', 'The anotoki library''s HTML editor: the fourth of the seven text sizes in its menu. No placeholders.'),
    ('anotoki.htmlEditor.size5', 'The anotoki library''s HTML editor: the fifth of the seven text sizes in its menu. No placeholders.'),
    ('anotoki.htmlEditor.size6', 'The anotoki library''s HTML editor: the sixth of the seven text sizes in its menu. No placeholders.'),
    ('anotoki.htmlEditor.size7', 'The anotoki library''s HTML editor: the largest of the seven text sizes in its menu. No placeholders.'),
    ('anotoki.htmlEditor.font', 'The anotoki library''s HTML editor: the name of the menu of fonts (the fonts'' own names are never words). No placeholders.'),
    ('anotoki.htmlEditor.fontChosen', 'The anotoki library''s HTML editor: the name a screen reader hears for the button that opens the menu of fonts. {font}: the font chosen, in its own name ("Arial"). Placeholders: {font}.'),
    ('anotoki.htmlEditor.textColor', 'The anotoki library''s HTML editor: the name and the tooltip of the button that opens a choice of the selected words'' colour, and that choice''s heading. No placeholders.'),
    ('anotoki.htmlEditor.highlightColor', 'The anotoki library''s HTML editor: the name and the tooltip of the button that opens a choice of the colour behind the selected words, and that choice''s heading. No placeholders.'),
    ('anotoki.htmlEditor.apply', 'The anotoki library''s HTML editor: the button that gives the selected words the colour chosen. No placeholders.'),
    ('anotoki.htmlEditor.noColor', 'The anotoki library''s HTML editor: the button that takes a colour off the selected words, so that they have the text''s own. No placeholders.'),
    ('anotoki.htmlEditor.source', 'The anotoki library''s HTML editor: the name and the tooltip of the button that shows the text as its HTML, to write it so, and back. No placeholders.'),
    ('anotoki.htmlEditor.sourceField', 'The anotoki library''s HTML editor: the name a screen reader hears for the field that holds the text as its HTML. {field}: the editor''s own label, as the page shows it. Placeholders: {field}.'),
    ('anotoki.htmlEditor.preview', 'The anotoki library''s HTML editor: the name and the tooltip of the button that shows and hides the text as the site will show it, beside the editor - and that pane''s heading. No placeholders.'),
    ('anotoki.htmlEditor.linkAdd', 'The anotoki library''s HTML editor: the heading of the dialog that asks for a link''s address, where the selected words are no link yet. No placeholders.'),
    ('anotoki.htmlEditor.linkChange', 'The anotoki library''s HTML editor: the heading of the dialog that asks for a link''s address where the words are a link already, and its button that changes the address. No placeholders.'),
    ('anotoki.htmlEditor.linkAddress', 'The anotoki library''s HTML editor: the label of the field in the link''s dialog where the address is written. No placeholders.'),
    ('anotoki.htmlEditor.linkHint', 'The anotoki library''s HTML editor: the line under the link''s address that says what a link may point at. Keep https://… and mailto:… as they are. No placeholders.'),
    ('anotoki.htmlEditor.linkInvalid', 'The anotoki library''s HTML editor: said under the link''s address when it cannot be a link of a text (javascript:, a file, another scheme) - nothing is done. Keep https://… and mailto:… as they are. No placeholders.'),
    ('anotoki.htmlEditor.linkSubmit', 'The anotoki library''s HTML editor: the button of the link''s dialog that makes the selected words a link. No placeholders.'),
    ('anotoki.htmlEditor.linkRemove', 'The anotoki library''s HTML editor: the button of the link''s dialog that makes a link plain words again. No placeholders.')
ON CONFLICT (name) DO UPDATE SET description = EXCLUDED.description;

-- One statement for every language: the rows it writes do not hide each other from the test of "no
-- string at all", which sees the table as it was before the statement began.
INSERT INTO translations (key_name, language_code, value)
SELECT v.key_name, v.language_code, v.value
  FROM (VALUES
    ('anotoki.filePicker.chooseFolder', 'en', 'Choose a folder'),
    ('anotoki.filePicker.chooseFolder', 'sk', 'Vybrať priečinok'),
    ('anotoki.filePicker.folder', 'en', '{name} (files: {count})'),
    ('anotoki.filePicker.folder', 'sk', '{name} (súbory: {count})'),
    ('anotoki.dates.justNow', 'en', 'just now'),
    ('anotoki.dates.justNow', 'sk', 'práve teraz'),
    ('anotoki.dates.soon', 'en', 'in a moment'),
    ('anotoki.dates.soon', 'sk', 'o chvíľu'),
    ('anotoki.dates.onDate', 'en', 'on {date}'),
    ('anotoki.dates.onDate', 'sk', '{date}'),
    ('anotoki.numberField.invalid', 'en', 'Write a number, like {example}.'),
    ('anotoki.numberField.invalid', 'sk', 'Číslo treba zadať v tvare {example}.'),
    ('anotoki.numberField.whole', 'en', 'Write a whole number.'),
    ('anotoki.numberField.whole', 'sk', 'Treba zadať celé číslo.'),
    ('anotoki.numberField.step', 'en', 'Write a number in steps of {step}.'),
    ('anotoki.numberField.step', 'sk', 'Číslo treba zadať v krokoch po {step}.'),
    ('anotoki.numberField.tooSmall', 'en', 'The smallest number is {min}.'),
    ('anotoki.numberField.tooSmall', 'sk', 'Najmenšie možné číslo je {min}.'),
    ('anotoki.numberField.tooLarge', 'en', 'The largest number is {max}.'),
    ('anotoki.numberField.tooLarge', 'sk', 'Najväčšie možné číslo je {max}.'),
    ('anotoki.multiselect.more', 'en', 'and {count} more'),
    ('anotoki.multiselect.more', 'sk', 'a ďalšie ({count})'),
    ('anotoki.multiselect.noneChosen', 'en', 'None chosen'),
    ('anotoki.multiselect.noneChosen', 'sk', 'Nič nie je vybrané'),
    ('anotoki.multiselect.clear', 'en', 'Clear'),
    ('anotoki.multiselect.clear', 'sk', 'Zrušiť výber'),
    ('anotoki.multiselect.empty', 'en', 'Nothing to choose from'),
    ('anotoki.multiselect.empty', 'sk', 'Nie je z čoho vyberať'),
    ('anotoki.audioPlayer.player', 'en', 'Audio player'),
    ('anotoki.audioPlayer.player', 'sk', 'Prehrávač zvuku'),
    ('anotoki.audioPlayer.play', 'en', 'Play'),
    ('anotoki.audioPlayer.play', 'sk', 'Prehrať'),
    ('anotoki.audioPlayer.pause', 'en', 'Pause'),
    ('anotoki.audioPlayer.pause', 'sk', 'Pozastaviť'),
    ('anotoki.audioPlayer.stop', 'en', 'Stop'),
    ('anotoki.audioPlayer.stop', 'sk', 'Zastaviť'),
    ('anotoki.audioPlayer.position', 'en', 'Position'),
    ('anotoki.audioPlayer.position', 'sk', 'Pozícia'),
    ('anotoki.audioPlayer.positionText', 'en', '{elapsed} of {total}'),
    ('anotoki.audioPlayer.positionText', 'sk', '{elapsed} z {total}'),
    ('anotoki.audioPlayer.failed', 'en', 'This file could not be played.'),
    ('anotoki.audioPlayer.failed', 'sk', 'Tento súbor sa nepodarilo prehrať.'),
    ('anotoki.htmlEditor.toolbar', 'en', '{field}: formatting'),
    ('anotoki.htmlEditor.toolbar', 'sk', '{field}: formátovanie'),
    ('anotoki.htmlEditor.heading', 'en', 'Heading {level}'),
    ('anotoki.htmlEditor.heading', 'sk', 'Nadpis {level}'),
    ('anotoki.htmlEditor.highlight', 'en', 'Highlight'),
    ('anotoki.htmlEditor.highlight', 'sk', 'Zvýraznenie'),
    ('anotoki.htmlEditor.size', 'en', 'Text size'),
    ('anotoki.htmlEditor.size', 'sk', 'Veľkosť písma'),
    ('anotoki.htmlEditor.sizeChosen', 'en', 'Text size: {size}'),
    ('anotoki.htmlEditor.sizeChosen', 'sk', 'Veľkosť písma: {size}'),
    ('anotoki.htmlEditor.size1', 'en', 'Very small'),
    ('anotoki.htmlEditor.size1', 'sk', 'Veľmi malé'),
    ('anotoki.htmlEditor.size2', 'en', 'Small'),
    ('anotoki.htmlEditor.size2', 'sk', 'Malé'),
    ('anotoki.htmlEditor.size3', 'en', 'Normal'),
    ('anotoki.htmlEditor.size3', 'sk', 'Normálne'),
    ('anotoki.htmlEditor.size4', 'en', 'Large'),
    ('anotoki.htmlEditor.size4', 'sk', 'Veľké'),
    ('anotoki.htmlEditor.size5', 'en', 'Very large'),
    ('anotoki.htmlEditor.size5', 'sk', 'Veľmi veľké'),
    ('anotoki.htmlEditor.size6', 'en', 'Huge'),
    ('anotoki.htmlEditor.size6', 'sk', 'Obrovské'),
    ('anotoki.htmlEditor.size7', 'en', 'Largest'),
    ('anotoki.htmlEditor.size7', 'sk', 'Najväčšie'),
    ('anotoki.htmlEditor.font', 'en', 'Font'),
    ('anotoki.htmlEditor.font', 'sk', 'Písmo'),
    ('anotoki.htmlEditor.fontChosen', 'en', 'Font: {font}'),
    ('anotoki.htmlEditor.fontChosen', 'sk', 'Písmo: {font}'),
    ('anotoki.htmlEditor.textColor', 'en', 'Text colour'),
    ('anotoki.htmlEditor.textColor', 'sk', 'Farba textu'),
    ('anotoki.htmlEditor.highlightColor', 'en', 'Highlight colour'),
    ('anotoki.htmlEditor.highlightColor', 'sk', 'Farba zvýraznenia'),
    ('anotoki.htmlEditor.apply', 'en', 'Apply'),
    ('anotoki.htmlEditor.apply', 'sk', 'Použiť'),
    ('anotoki.htmlEditor.noColor', 'en', 'No colour'),
    ('anotoki.htmlEditor.noColor', 'sk', 'Bez farby'),
    ('anotoki.htmlEditor.source', 'en', 'HTML source'),
    ('anotoki.htmlEditor.source', 'sk', 'Zdrojový kód HTML'),
    ('anotoki.htmlEditor.sourceField', 'en', '{field}: HTML source'),
    ('anotoki.htmlEditor.sourceField', 'sk', '{field}: zdrojový kód HTML'),
    ('anotoki.htmlEditor.preview', 'en', 'Preview'),
    ('anotoki.htmlEditor.preview', 'sk', 'Ukážka'),
    ('anotoki.htmlEditor.linkAdd', 'en', 'Add a link'),
    ('anotoki.htmlEditor.linkAdd', 'sk', 'Pridať odkaz'),
    ('anotoki.htmlEditor.linkChange', 'en', 'Change the link'),
    ('anotoki.htmlEditor.linkChange', 'sk', 'Zmeniť odkaz'),
    ('anotoki.htmlEditor.linkAddress', 'en', 'Address'),
    ('anotoki.htmlEditor.linkAddress', 'sk', 'Adresa'),
    ('anotoki.htmlEditor.linkHint', 'en', 'A web address (https://…) or a mail address (mailto:…).'),
    ('anotoki.htmlEditor.linkHint', 'sk', 'Webová adresa (https://…) alebo e-mailová adresa (mailto:…).'),
    ('anotoki.htmlEditor.linkInvalid', 'en', 'That cannot be a link: write a web address (https://…) or a mail address (mailto:…).'),
    ('anotoki.htmlEditor.linkInvalid', 'sk', 'Toto nemôže byť odkaz: treba zadať webovú adresu (https://…) alebo e-mailovú adresu (mailto:…).'),
    ('anotoki.htmlEditor.linkSubmit', 'en', 'Add the link'),
    ('anotoki.htmlEditor.linkSubmit', 'sk', 'Pridať odkaz'),
    ('anotoki.htmlEditor.linkRemove', 'en', 'Remove the link'),
    ('anotoki.htmlEditor.linkRemove', 'sk', 'Odstrániť odkaz')
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
        ('anotoki.filePicker.chooseFolder'),
        ('anotoki.filePicker.folder'),
        ('anotoki.dates.justNow'),
        ('anotoki.dates.soon'),
        ('anotoki.dates.onDate'),
        ('anotoki.numberField.invalid'),
        ('anotoki.numberField.whole'),
        ('anotoki.numberField.step'),
        ('anotoki.numberField.tooSmall'),
        ('anotoki.numberField.tooLarge'),
        ('anotoki.multiselect.more'),
        ('anotoki.multiselect.noneChosen'),
        ('anotoki.multiselect.clear'),
        ('anotoki.multiselect.empty'),
        ('anotoki.audioPlayer.player'),
        ('anotoki.audioPlayer.play'),
        ('anotoki.audioPlayer.pause'),
        ('anotoki.audioPlayer.stop'),
        ('anotoki.audioPlayer.position'),
        ('anotoki.audioPlayer.positionText'),
        ('anotoki.audioPlayer.failed'),
        ('anotoki.htmlEditor.toolbar'),
        ('anotoki.htmlEditor.heading'),
        ('anotoki.htmlEditor.highlight'),
        ('anotoki.htmlEditor.size'),
        ('anotoki.htmlEditor.sizeChosen'),
        ('anotoki.htmlEditor.size1'),
        ('anotoki.htmlEditor.size2'),
        ('anotoki.htmlEditor.size3'),
        ('anotoki.htmlEditor.size4'),
        ('anotoki.htmlEditor.size5'),
        ('anotoki.htmlEditor.size6'),
        ('anotoki.htmlEditor.size7'),
        ('anotoki.htmlEditor.font'),
        ('anotoki.htmlEditor.fontChosen'),
        ('anotoki.htmlEditor.textColor'),
        ('anotoki.htmlEditor.highlightColor'),
        ('anotoki.htmlEditor.apply'),
        ('anotoki.htmlEditor.noColor'),
        ('anotoki.htmlEditor.source'),
        ('anotoki.htmlEditor.sourceField'),
        ('anotoki.htmlEditor.preview'),
        ('anotoki.htmlEditor.linkAdd'),
        ('anotoki.htmlEditor.linkChange'),
        ('anotoki.htmlEditor.linkAddress'),
        ('anotoki.htmlEditor.linkHint'),
        ('anotoki.htmlEditor.linkInvalid'),
        ('anotoki.htmlEditor.linkSubmit'),
        ('anotoki.htmlEditor.linkRemove')
      ) AS k(name)
     WHERE NOT EXISTS (SELECT 1 FROM translation_keys tk WHERE tk.name = k.name)
        OR NOT EXISTS (SELECT 1 FROM translations t WHERE t.key_name = k.name AND t.language_code = 'en');
    IF missing IS NOT NULL THEN
        RAISE EXCEPTION 'anotoki_translations 014: the library''s keys without an English string: %. A site that takes a library key over gives it its English.', missing;
    END IF;
END $$;

-- end of 014
