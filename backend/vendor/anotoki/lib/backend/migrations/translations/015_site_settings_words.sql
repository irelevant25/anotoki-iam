-- anotoki_translations 015 - the words of 0.11.0's site settings: the closed sign while the site is closed for
-- maintenance (anotoki.maintenance), the announcement bar and the notice for the site's administrators
-- (anotoki.siteSettings), and the Privacy page's line for what the announcement bar keeps (anotoki.legal).
--
-- The site settings (the genshin site's, for every anotoki site - the owner's request of 2026-10-25): "Getting in"
-- closes the site for maintenance, and the family's closed sign then says so (@anotoki/lib/migrations/maintenance) -
-- the administrators' own message where they wrote one, else the built-in sentence here - with a way in for them at
-- /staff; "Announcement" draws a bar on every page (@anotoki/lib/site-settings), its kind said to a screen reader
-- before it; "Page access" switches pages off or keeps them for some readers, and an administrator on such a page -
-- or on the site while it is closed - is told so. Each piece reads its words itself, the site's string first. A
-- browser keeps which announcement its reader hid: the Privacy page's storage line for it is here too. anotoki-lib's
-- backend/resources/library-words.json holds them - the Angular half's built-in words are made from the same file,
-- and a test of the library holds this file to it.
--
-- As in 002: a key's description is the library's, and is written again whenever a library migration carries it;
-- a key's strings are the site's once it has any - written here only for a library key that has no string at all.
-- So a site that took a key over before this file ran keeps its words.
--
-- Released with anotoki-lib 0.11.0, and never edited once released: a change is a new file.

INSERT INTO translation_keys (name, description) VALUES
    ('anotoki.maintenance.title', 'The anotoki library''s closed sign - the page shown in place of the site while its administrators have closed it for maintenance (the site settings'' "Getting in"), to everybody but the site''s ADMIN: its heading. The Slovak addresses nobody - no ty, no Vy - so that it fits every site. No placeholders.'),
    ('anotoki.maintenance.text', 'The anotoki library''s closed sign: the sentence under its heading when the administrators wrote no message of their own (theirs is said instead, in the reader''s language or else in English). The page asks again by itself and reloads once the site is open. No placeholders.'),
    ('anotoki.maintenance.staffLead', 'The anotoki library''s closed sign, at the address its administrators sign in at (/staff): the line above its Sign in button (anotoki.maintenance.signIn). The sign-in is the anotoki account''s, as everywhere; the site lets its administrators in and nobody else. No placeholders.'),
    ('anotoki.maintenance.signIn', 'The anotoki library''s closed sign, at the address its administrators sign in at: the button that starts the anotoki account''s sign-in. Short. No placeholders.'),
    ('anotoki.siteSettings.announcement', 'The anotoki library''s announcement bar - the line across the top of every page that the site''s administrators write in the site settings: its name to a screen reader (a region of the page). The Slovak addresses nobody - no ty, no Vy - so that it fits every site. No placeholders.'),
    ('anotoki.siteSettings.levelInfo', 'The anotoki library''s announcement bar: what a screen reader says before an announcement of the kind "information" (drawn in the information colour) - one word, followed by the announcement itself. No placeholders.'),
    ('anotoki.siteSettings.levelWarning', 'The anotoki library''s announcement bar: what a screen reader says before an announcement of the kind "warning" - one word, followed by the announcement itself. No placeholders.'),
    ('anotoki.siteSettings.levelDanger', 'The anotoki library''s announcement bar: what a screen reader says before an announcement of the kind "something is wrong" (drawn in the danger colour) - one word, followed by the announcement itself. No placeholders.'),
    ('anotoki.siteSettings.hide', 'The anotoki library''s announcement bar: the button that hides the announcement - it stays hidden in this browser until its words change. Short: it stands at the bar''s end. No placeholders.'),
    ('anotoki.siteSettings.hideName', 'The anotoki library''s announcement bar: what a screen reader says for the Hide button (anotoki.siteSettings.hide) - starting with that button''s own words, so a person who speaks to the computer names the button by what it shows (WCAG 2.5.3, Label in Name). No placeholders.'),
    ('anotoki.siteSettings.maintenanceOnTitle', 'The anotoki library''s notice for the site''s administrators above a page, while they have closed the site for maintenance: its heading. Only an administrator sees the site then - everybody else, the maintenance page. The Slovak addresses nobody. No placeholders.'),
    ('anotoki.siteSettings.maintenanceOnText', 'The anotoki library''s notice for the site''s administrators while the site is closed for maintenance: the sentence under its heading, saying why the site shows for this reader. No placeholders.'),
    ('anotoki.siteSettings.pageOffTitle', 'The anotoki library''s notice for the site''s administrators above a page that the site settings'' Page access switched off: its heading. Only an administrator sees such a page; nobody else ever sees this notice. No placeholders.'),
    ('anotoki.siteSettings.pageOffText', 'The anotoki library''s notice for the site''s administrators above a page that is switched off: the sentence under its heading. No placeholders.'),
    ('anotoki.siteSettings.pageLimitedTitle', 'The anotoki library''s notice for the site''s administrators above a page that the site settings'' Page access shows to some readers alone (people signed in, editors, administrators): its heading. No placeholders.'),
    ('anotoki.siteSettings.pageLimitedText', 'The anotoki library''s notice for the site''s administrators above a page shown to some readers alone: the sentence under its heading. {audience}: who the page is shown to, in the words of anotoki.siteSettings.audienceUser, audienceEditor or audienceAdmin - in Slovak in the dative, as the sentence takes it. Placeholders: {audience}.'),
    ('anotoki.siteSettings.audienceUser', 'The anotoki library''s notice for the site''s administrators: who a page is shown to, when it is people signed in - the {audience} of anotoki.siteSettings.pageLimitedText (in Slovak the dative). No placeholders.'),
    ('anotoki.siteSettings.audienceEditor', 'The anotoki library''s notice for the site''s administrators: who a page is shown to, when it is editors and administrators - the {audience} of anotoki.siteSettings.pageLimitedText. No placeholders.'),
    ('anotoki.siteSettings.audienceAdmin', 'The anotoki library''s notice for the site''s administrators: who a page is shown to, when it is administrators alone - the {audience} of anotoki.siteSettings.pageLimitedText. No placeholders.'),
    ('anotoki.legal.storageAnnouncementPurpose', 'The anotoki library''s Privacy page: what the stored announcement is - the browser keeps which announcement its reader hid (a mark of its words, never the words), so that it stays hidden until they change. No placeholders.')
ON CONFLICT (name) DO UPDATE SET description = EXCLUDED.description;

-- One statement for every language: the rows it writes do not hide each other from the test of "no
-- string at all", which sees the table as it was before the statement began.
INSERT INTO translations (key_name, language_code, value)
SELECT v.key_name, v.language_code, v.value
  FROM (VALUES
    ('anotoki.maintenance.title', 'en', 'Back shortly'),
    ('anotoki.maintenance.title', 'sk', 'Hneď sme späť'),
    ('anotoki.maintenance.text', 'en', 'The site is closed for a moment while something is being fixed. This page reloads by itself once it is back.'),
    ('anotoki.maintenance.text', 'sk', 'Stránka je na chvíľu zatvorená, kým niečo opravujeme. Táto stránka sa obnoví sama, keď bude späť.'),
    ('anotoki.maintenance.staffLead', 'en', 'An administrator can sign in here and use the site while it is closed.'),
    ('anotoki.maintenance.staffLead', 'sk', 'Administrátor sa tu môže prihlásiť a používať stránku, aj keď je zatvorená.'),
    ('anotoki.maintenance.signIn', 'en', 'Sign in'),
    ('anotoki.maintenance.signIn', 'sk', 'Prihlásiť sa'),
    ('anotoki.siteSettings.announcement', 'en', 'Announcement'),
    ('anotoki.siteSettings.announcement', 'sk', 'Oznámenie'),
    ('anotoki.siteSettings.levelInfo', 'en', 'Information'),
    ('anotoki.siteSettings.levelInfo', 'sk', 'Informácia'),
    ('anotoki.siteSettings.levelWarning', 'en', 'Warning'),
    ('anotoki.siteSettings.levelWarning', 'sk', 'Upozornenie'),
    ('anotoki.siteSettings.levelDanger', 'en', 'Important'),
    ('anotoki.siteSettings.levelDanger', 'sk', 'Dôležité'),
    ('anotoki.siteSettings.hide', 'en', 'Hide'),
    ('anotoki.siteSettings.hide', 'sk', 'Skryť'),
    ('anotoki.siteSettings.hideName', 'en', 'Hide the announcement'),
    ('anotoki.siteSettings.hideName', 'sk', 'Skryť oznámenie'),
    ('anotoki.siteSettings.maintenanceOnTitle', 'en', 'The site is closed for maintenance'),
    ('anotoki.siteSettings.maintenanceOnTitle', 'sk', 'Stránka je zatvorená pre údržbu'),
    ('anotoki.siteSettings.maintenanceOnText', 'en', 'Everybody else sees the maintenance page. It shows here because this account is an administrator''s.'),
    ('anotoki.siteSettings.maintenanceOnText', 'sk', 'Všetci ostatní vidia stránku o údržbe. Tu sa zobrazuje, lebo tento účet patrí administrátorovi.'),
    ('anotoki.siteSettings.pageOffTitle', 'en', 'This page is switched off'),
    ('anotoki.siteSettings.pageOffTitle', 'sk', 'Táto stránka je vypnutá'),
    ('anotoki.siteSettings.pageOffText', 'en', 'Only administrators see it: everybody else gets a page that is not there.'),
    ('anotoki.siteSettings.pageOffText', 'sk', 'Vidia ju len administrátori: všetci ostatní dostanú stránku, ktorá neexistuje.'),
    ('anotoki.siteSettings.pageLimitedTitle', 'en', 'This page is not for everybody'),
    ('anotoki.siteSettings.pageLimitedTitle', 'sk', 'Táto stránka nie je pre každého'),
    ('anotoki.siteSettings.pageLimitedText', 'en', 'It is shown to {audience}. It shows here because this account is an administrator''s.'),
    ('anotoki.siteSettings.pageLimitedText', 'sk', 'Zobrazuje sa {audience}. Tu sa zobrazuje, lebo tento účet patrí administrátorovi.'),
    ('anotoki.siteSettings.audienceUser', 'en', 'people who are signed in'),
    ('anotoki.siteSettings.audienceUser', 'sk', 'prihláseným'),
    ('anotoki.siteSettings.audienceEditor', 'en', 'editors and administrators'),
    ('anotoki.siteSettings.audienceEditor', 'sk', 'editorom a administrátorom'),
    ('anotoki.siteSettings.audienceAdmin', 'en', 'administrators only'),
    ('anotoki.siteSettings.audienceAdmin', 'sk', 'len administrátorom'),
    ('anotoki.legal.storageAnnouncementPurpose', 'en', 'Which announcement you hid, so that it stays hidden until its words change.'),
    ('anotoki.legal.storageAnnouncementPurpose', 'sk', 'Ktoré oznámenie bolo skryté, aby zostalo skryté, kým sa jeho text nezmení.')
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
        ('anotoki.maintenance.title'),
        ('anotoki.maintenance.text'),
        ('anotoki.maintenance.staffLead'),
        ('anotoki.maintenance.signIn'),
        ('anotoki.siteSettings.announcement'),
        ('anotoki.siteSettings.levelInfo'),
        ('anotoki.siteSettings.levelWarning'),
        ('anotoki.siteSettings.levelDanger'),
        ('anotoki.siteSettings.hide'),
        ('anotoki.siteSettings.hideName'),
        ('anotoki.siteSettings.maintenanceOnTitle'),
        ('anotoki.siteSettings.maintenanceOnText'),
        ('anotoki.siteSettings.pageOffTitle'),
        ('anotoki.siteSettings.pageOffText'),
        ('anotoki.siteSettings.pageLimitedTitle'),
        ('anotoki.siteSettings.pageLimitedText'),
        ('anotoki.siteSettings.audienceUser'),
        ('anotoki.siteSettings.audienceEditor'),
        ('anotoki.siteSettings.audienceAdmin'),
        ('anotoki.legal.storageAnnouncementPurpose')
      ) AS k(name)
     WHERE NOT EXISTS (SELECT 1 FROM translation_keys tk WHERE tk.name = k.name)
        OR NOT EXISTS (SELECT 1 FROM translations t WHERE t.key_name = k.name AND t.language_code = 'en');
    IF missing IS NOT NULL THEN
        RAISE EXCEPTION 'anotoki_translations 015: the library''s keys without an English string: %. A site that takes a library key over gives it its English.', missing;
    END IF;
END $$;

-- end of 015
