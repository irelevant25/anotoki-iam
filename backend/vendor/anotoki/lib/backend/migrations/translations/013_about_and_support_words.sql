-- anotoki_translations 013 - the words of 0.8.0: the About and Support pages, under the anotoki library's
-- namespaces anotoki.about and anotoki.support; the footer links' About and Support; the feedback form's Clear.
--
-- The About page (@anotoki/lib/legal/about) and the Support page (@anotoki/lib/legal/support) are the same on every
-- anotoki site, in the owner's own words: where the name anotoki comes from, who makes the projects, each project;
-- and where they can be supported (Ko-fi). Each page reads its words itself - the site's string first. The footer's
-- links (<anotoki-legal-links>, in the family's footer) begin with About and Support now, before Terms, Privacy and
-- Contact; the feedback form has a Clear button, named to a screen reader as what it empties. Every address the
-- pages link to is the library's code, never a string. anotoki-lib's backend/resources/library-words.json holds them -
-- the Angular half's built-in words are made from the same file, and a test of the library holds this file to it.
--
-- As in 002: a key's description is the library's, and is written again whenever a library migration carries it;
-- a key's strings are the site's once it has any - written here only for a library key that has no string at all.
-- So a site that took a key over before this file ran keeps its words. The footer links' group holds five links now,
-- so its description says so; its words stay as they are.
--
-- Released with anotoki-lib 0.8.0, and never edited once released: a change is a new file.

INSERT INTO translation_keys (name, description) VALUES
    ('anotoki.feedback.clear', 'The anotoki library''s feedback form: the button at the left of its row of buttons that empties every field. What was typed is kept otherwise - the dialog closed and opened again - until the message is sent or the page is loaded afresh. Short: it stands in one row with Cancel and Send. No placeholders.'),
    ('anotoki.feedback.clearName', 'The anotoki library''s feedback form: what a screen reader says for the Clear button (anotoki.feedback.clear) - starting with that button''s own words, so a person who speaks to the computer names the button by what it shows (WCAG 2.5.3, Label in Name). No placeholders.'),
    ('anotoki.legalLinks.about', 'The anotoki library''s footer links: the link to the About page - the same on every anotoki site: where the name anotoki comes from, who makes the projects, and each project. Short: it stands first, beside Support, Terms, Privacy and Contact. No placeholders.'),
    ('anotoki.legalLinks.support', 'The anotoki library''s footer links: the link to the Support page, where the anotoki projects can be supported on Ko-fi. Short: it stands beside About, Terms, Privacy and Contact. In Slovak a verb ("Podporiť"), never the noun. No placeholders.'),
    ('anotoki.about.title', 'The anotoki library''s About page - the same on every anotoki site, in the owner''s own words: its heading. anotoki is the family''s name, in small letters, never translated. The page''s Slovak addresses nobody - no ty, no Vy - so that it fits every site; the owner speaks of himself, in the first person. No placeholders.'),
    ('anotoki.about.nameTitle', 'The anotoki library''s About page: the heading of the section on where the name anotoki comes from. No placeholders.'),
    ('anotoki.about.nameText', 'The anotoki library''s About page: where the name comes from, in the owner''s words. {word}: the Japanese あの時, drawn in Japanese; {reading}: how it is read, ano toki, drawn in italics - each where the sentence needs it. Genshin Impact, the Build Analyzer, Japanese Academy and Piano Academy are the projects'' names. Placeholders: {word}, {reading}.'),
    ('anotoki.about.authorTitle', 'The anotoki library''s About page: the heading of the section on who makes the projects. No placeholders.'),
    ('anotoki.about.authorText', 'The anotoki library''s About page: who makes the projects, and how, in the owner''s words. Claude Code is a product''s name, never translated. No placeholders.'),
    ('anotoki.about.projectsTitle', 'The anotoki library''s About page: the heading of the list of the anotoki projects - each its name, a link to its site, and what it is. No placeholders.'),
    ('anotoki.about.japaneseName', 'The anotoki library''s About page: the name of the project at japanese.anotoki.eu, a link to it. A name, the same in every language. No placeholders.'),
    ('anotoki.about.japaneseText', 'The anotoki library''s About page: what Japanese Academy is, and why it was made, in the owner''s words. Duolingo and WaniKani are other apps'' names; the JLPT is the Japanese-Language Proficiency Test. No placeholders.'),
    ('anotoki.about.pianoName', 'The anotoki library''s About page: the name of the project at piano.anotoki.eu, a link to it. A name, the same in every language. No placeholders.'),
    ('anotoki.about.pianoText', 'The anotoki library''s About page: what Piano Academy is, in the owner''s words. MIDI is how an electronic instrument talks to a computer. No placeholders.'),
    ('anotoki.about.genshinName', 'The anotoki library''s About page: the name of the project at genshin.anotoki.eu, a link to it - the game''s name, never translated. No placeholders.'),
    ('anotoki.about.genshinText', 'The anotoki library''s About page: what the Genshin Impact project is, in the owner''s words. No placeholders.'),
    ('anotoki.about.builderName', 'The anotoki library''s About page: the name of the project at builder.anotoki.eu, a link to it. A name, the same in every language. No placeholders.'),
    ('anotoki.about.builderText', 'The anotoki library''s About page: what the Build Analyzer is, and why it was made, in the owner''s words. Genshin Impact and Honkai: Star Rail are games'' names, never translated. No placeholders.'),
    ('anotoki.about.surveyName', 'The anotoki library''s About page: the name of the project at survey.anotoki.eu, a link to it - the surveys, in the page''s language. No placeholders.'),
    ('anotoki.about.surveyText', 'The anotoki library''s About page: what the surveys are for, in the owner''s words. No placeholders.'),
    ('anotoki.about.fanLine', 'The anotoki library''s About page: the line under the Genshin Impact project and under the Build Analyzer, smaller - the fan clause, short. HoYoverse is the games'' publisher, a name never translated. No placeholders.'),
    ('anotoki.about.closing', 'The anotoki library''s About page: its last line. {support}: the link to the Support page, in the words of anotoki.about.closingLink - where the sentence needs it. Placeholders: {support}.'),
    ('anotoki.about.closingLink', 'The anotoki library''s About page: the words of the link to the Support page in its last line (the {support} of anotoki.about.closing). No placeholders.'),
    ('anotoki.support.title', 'The anotoki library''s Support page - the same on every anotoki site, in the owner''s own words: its heading. anotoki is the family''s name, in small letters, never translated. The page''s Slovak addresses nobody - no ty, no Vy - so that it fits every site; the owner speaks of himself, in the first person. No placeholders.'),
    ('anotoki.support.text', 'The anotoki library''s Support page: why and where the projects can be supported, in the owner''s words. Ko-fi is the service''s name, never translated. No placeholders.'),
    ('anotoki.support.button', 'The anotoki library''s Support page: the button that opens the owner''s page on Ko-fi, in a new tab. Ko-fi is the service''s name, never translated. No placeholders.'),
    ('anotoki.support.thanks', 'The anotoki library''s Support page: the owner''s thanks, the last line, under the button. No placeholders.')
ON CONFLICT (name) DO UPDATE SET description = EXCLUDED.description;

-- One statement for every language: the rows it writes do not hide each other from the test of "no
-- string at all", which sees the table as it was before the statement began.
INSERT INTO translations (key_name, language_code, value)
SELECT v.key_name, v.language_code, v.value
  FROM (VALUES
    ('anotoki.feedback.clear', 'en', 'Clear'),
    ('anotoki.feedback.clear', 'sk', 'Vymazať'),
    ('anotoki.feedback.clearName', 'en', 'Clear the form'),
    ('anotoki.feedback.clearName', 'sk', 'Vymazať formulár'),
    ('anotoki.legalLinks.about', 'en', 'About'),
    ('anotoki.legalLinks.about', 'sk', 'O projekte'),
    ('anotoki.legalLinks.support', 'en', 'Support'),
    ('anotoki.legalLinks.support', 'sk', 'Podporiť'),
    ('anotoki.about.title', 'en', 'About anotoki'),
    ('anotoki.about.title', 'sk', 'O projekte anotoki'),
    ('anotoki.about.nameTitle', 'en', 'The name'),
    ('anotoki.about.nameTitle', 'sk', 'Názov'),
    ('anotoki.about.nameText', 'en', 'anotoki comes from the Japanese {word} ({reading}): "back then" – that time you look back on with a smile. I chose it for the nostalgic feeling I wanted the Genshin Impact project to carry, and the name stayed with everything that grew from it: the Build Analyzer, Japanese Academy and Piano Academy.'),
    ('anotoki.about.nameText', 'sk', 'anotoki pochádza z japonského {word} ({reading}): „vtedy" – čas, na ktorý sa spomína s úsmevom. Zvolil som ho pre nostalgický pocit, ktorý mal mať projekt Genshin Impact, a názov zostal všetkému, čo z neho vyrástlo: Build Analyzeru, Japanese Academy a Piano Academy.'),
    ('anotoki.about.authorTitle', 'en', 'Made by one person'),
    ('anotoki.about.authorTitle', 'sk', 'Robí to jeden človek'),
    ('anotoki.about.authorText', 'en', 'Every anotoki project is designed, built and run by me alone. I worked on the Genshin Impact project for almost two years and expected another year before it was done. Once Claude Code became part of the development, it was finished in a couple of months – and the other projects followed.'),
    ('anotoki.about.authorText', 'sk', 'Každý projekt anotoki navrhujem, tvorím a prevádzkujem sám. Na projekte Genshin Impact som pracoval takmer dva roky a čakal som, že dokončiť ho potrvá ešte rok. Keď sa súčasťou vývoja stal Claude Code, bol hotový za pár mesiacov – a ďalšie projekty nasledovali.'),
    ('anotoki.about.projectsTitle', 'en', 'The projects'),
    ('anotoki.about.projectsTitle', 'sk', 'Projekty'),
    ('anotoki.about.japaneseName', 'en', 'Japanese Academy'),
    ('anotoki.about.japaneseName', 'sk', 'Japanese Academy'),
    ('anotoki.about.japaneseText', 'en', 'I wanted the daily habit of Duolingo and the careful kanji teaching of WaniKani in one place, but aimed at the JLPT exams. Kanji and vocabulary come in lessons, return in reviews just before you would forget them, and can be practised without limits; an exam of more than 11,000 questions, with listening, shows how much has stuck.'),
    ('anotoki.about.japaneseText', 'sk', 'Chcel som na jednom mieste spojiť každodenný návyk z Duolinga a starostlivé učenie kandži z WaniKani, no so zameraním na skúšky JLPT. Kandži a slovná zásoba prichádzajú v lekciách, vracajú sa v opakovaniach tesne predtým, než by sa zabudli, a dajú sa precvičovať bez obmedzenia; test s viac ako 11 000 otázkami aj s počúvaním ukáže, koľko sa toho naozaj naučilo.'),
    ('anotoki.about.pianoName', 'en', 'Piano Academy'),
    ('anotoki.about.pianoName', 'sk', 'Piano Academy'),
    ('anotoki.about.pianoText', 'en', 'Something made for the piano itself: a simple learning path from the very first notes, in the browser with nothing to install. Plug a digital piano or MIDI keyboard into the computer, and the page shows the music, listens to what you play and keeps your progress.'),
    ('anotoki.about.pianoText', 'sk', 'Niečo priamo pre klavír: jednoduchá cesta učenia od úplne prvých nôt, v prehliadači a bez inštalovania. Stačí pripojiť digitálny klavír alebo MIDI klávesnicu k počítaču – stránka ukáže noty, počúva, čo sa hrá, a ukladá pokrok.'),
    ('anotoki.about.genshinName', 'en', 'Genshin Impact'),
    ('anotoki.about.genshinName', 'sk', 'Genshin Impact'),
    ('anotoki.about.genshinText', 'en', 'Simple databases of characters, weapons, materials and banners with filters that are easy to use, plus a daily quiz, more quizzes and small games – without the wall of ads that crowds most fan sites.'),
    ('anotoki.about.genshinText', 'sk', 'Jednoduché databázy postáv, zbraní, materiálov a bannerov s filtrami, ktoré sa ľahko používajú, k tomu denný kvíz, ďalšie kvízy a malé hry – bez záplavy reklám, ktorá zahlcuje väčšinu fanúšikovských stránok.'),
    ('anotoki.about.builderName', 'en', 'Build Analyzer'),
    ('anotoki.about.builderName', 'sk', 'Build Analyzer'),
    ('anotoki.about.builderText', 'en', 'I wanted to import my own characters'' current builds automatically instead of typing them in, and then see where to go next: suggestions drawn from community builds and clear target stats for every character, for Genshin Impact and Honkai: Star Rail.'),
    ('anotoki.about.builderText', 'sk', 'Chcel som svoje aktuálne buildy postáv importovať automaticky namiesto ich prepisovania a potom vidieť, kam ďalej: návrhy z buildov komunity a jasné cieľové štatistiky pre každú postavu, pre Genshin Impact aj Honkai: Star Rail.'),
    ('anotoki.about.surveyName', 'en', 'Surveys'),
    ('anotoki.about.surveyName', 'sk', 'Prieskumy'),
    ('anotoki.about.surveyText', 'en', 'A place to ask the people who use these projects what they think, in one coordinated way – like the surveys games run at the end of a patch – so that what comes next is shaped by real feedback.'),
    ('anotoki.about.surveyText', 'sk', 'Miesto, kde sa dá jednotne opýtať ľudí, ktorí tieto projekty používajú, čo si myslia – ako prieskumy, ktoré hry robia na konci patchu – aby to, čo príde ďalej, vychádzalo zo skutočnej spätnej väzby.'),
    ('anotoki.about.fanLine', 'en', 'A fan-made project, not affiliated with or endorsed by HoYoverse.'),
    ('anotoki.about.fanLine', 'sk', 'Fanúšikovský projekt, nie je prepojený so spoločnosťou HoYoverse ani ňou schválený.'),
    ('anotoki.about.closing', 'en', 'anotoki is free to use. If it helps you or you simply enjoy it, you can {support}.'),
    ('anotoki.about.closing', 'sk', 'anotoki je zadarmo. Ak pomáha alebo jednoducho teší, dá sa {support}.'),
    ('anotoki.about.closingLink', 'en', 'support it'),
    ('anotoki.about.closingLink', 'sk', 'podporiť'),
    ('anotoki.support.title', 'en', 'Support anotoki'),
    ('anotoki.support.title', 'sk', 'Podporiť anotoki'),
    ('anotoki.support.text', 'en', 'anotoki is made and run by me alone, in my free time, and every project here is free to use. If something here helps you or you simply enjoy it, you can support its development on Ko-fi – every coffee helps pay for the hosting and the time new features take.'),
    ('anotoki.support.text', 'sk', 'anotoki tvorím a prevádzkujem sám, vo voľnom čase, a každý projekt tu je zadarmo. Ak niečo z toho pomáha alebo jednoducho teší, vývoj sa dá podporiť na Ko-fi – každá káva pomôže zaplatiť hosting aj čas, ktorý nové funkcie zaberú.'),
    ('anotoki.support.button', 'en', 'Support on Ko-fi'),
    ('anotoki.support.button', 'sk', 'Podporiť na Ko-fi'),
    ('anotoki.support.thanks', 'en', 'Thank you!'),
    ('anotoki.support.thanks', 'sk', 'Ďakujem!')
  ) AS v(key_name, language_code, value)
  JOIN languages l ON l.code = v.language_code
 WHERE NOT EXISTS (SELECT 1 FROM translations t WHERE t.key_name = v.key_name)
ON CONFLICT (key_name, language_code) DO NOTHING;

-- The footer links' group: About and Support stand in it now, beside the legal pages - its description says so
-- (the library's, as above); its strings stay the site's.
UPDATE translation_keys
   SET description = 'The anotoki library''s footer links - About, Support and the legal pages (Terms, Privacy, Contact): the name of their group, which a screen reader announces. The links'' Slovak addresses nobody - no ty, no Vy - so that it fits every site, the informal ones and the formal survey alike. No placeholders.'
 WHERE name = 'anotoki.legalLinks.label';

-- The keys of this file, each with an English string: Slovak is not asked for - a site that took a key over may
-- have left it blank on purpose.
DO $$
DECLARE
    missing TEXT;
BEGIN
    SELECT string_agg(k.name, ', ' ORDER BY k.name) INTO missing
      FROM (VALUES
        ('anotoki.feedback.clear'),
        ('anotoki.feedback.clearName'),
        ('anotoki.legalLinks.about'),
        ('anotoki.legalLinks.support'),
        ('anotoki.about.title'),
        ('anotoki.about.nameTitle'),
        ('anotoki.about.nameText'),
        ('anotoki.about.authorTitle'),
        ('anotoki.about.authorText'),
        ('anotoki.about.projectsTitle'),
        ('anotoki.about.japaneseName'),
        ('anotoki.about.japaneseText'),
        ('anotoki.about.pianoName'),
        ('anotoki.about.pianoText'),
        ('anotoki.about.genshinName'),
        ('anotoki.about.genshinText'),
        ('anotoki.about.builderName'),
        ('anotoki.about.builderText'),
        ('anotoki.about.surveyName'),
        ('anotoki.about.surveyText'),
        ('anotoki.about.fanLine'),
        ('anotoki.about.closing'),
        ('anotoki.about.closingLink'),
        ('anotoki.support.title'),
        ('anotoki.support.text'),
        ('anotoki.support.button'),
        ('anotoki.support.thanks')
      ) AS k(name)
     WHERE NOT EXISTS (SELECT 1 FROM translation_keys tk WHERE tk.name = k.name)
        OR NOT EXISTS (SELECT 1 FROM translations t WHERE t.key_name = k.name AND t.language_code = 'en');
    IF missing IS NOT NULL THEN
        RAISE EXCEPTION 'anotoki_translations 013: the library''s keys without an English string: %. A site that takes a library key over gives it its English.', missing;
    END IF;
END $$;

-- end of 013
