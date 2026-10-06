-----------------------------------------------------------
-- ANNOUNCEMENTS: NEWS AN ADMINISTRATOR MAILS TO THE PEOPLE OF THE SITES
--
-- The owner's words (2026-10-06): "Sending an email to all user with a
-- specific role (I can select more and like that can send email to pretty
-- much everyone)", from a predefined, pretty template whose text is filled
-- in or changed, "also with option to send an email to a specific user",
-- from a chosen address ("support@anotoki.eu", "noreply@anotoki.eu ...
-- predefined with an option to setup a new"), and "those sended emails
-- stored so I can check what and when I have send in the past". Asked, the
-- owner chose: a version of the words per language, each person getting
-- their own; an unsubscribe link in every announcement and a switch on the
-- account page; and links to the anotoki sites only. Nothing is ever sent by
-- itself: an administrator writes it and presses Send (docs/decisions.md,
-- 2026-10-06).
--
-- - users.news_mail: whether the account gets announcements - the account
--   page's switch, and what the link at the end of every announcement
--   switches off. On for every account, as one made before this file never
--   had the choice; every announcement says how to stop them.
-- - mail_senders: the addresses an administrator added in the admin panel,
--   beside the installation's own (config/mail.local.php's noreply and
--   support). The code holds them to the mail domain.
-- - announcements: what was sent - its words in each language, the template
--   and the site it was about, the button's address, the sender as it was,
--   who it was meant for (roles, people, or everybody), who sent it, when,
--   and whether it is still sending, sent, or stopped.
-- - announcement_recipients: one row per person it went to, with the
--   address and language it went in and how it went: the page sends in
--   batches (the host has no cron, and a request has half a minute), so a
--   row is pending until its mail is out.
--
-- The words: three templates' starting points (a new version of a site, a
-- new site, a survey about one) and the two lines every announcement ends
-- with, as mail strings an administrator rewords on the Translations page;
-- and the pages' words for the account page's switch and the page the
-- unsubscribe link opens. English and Slovak (informal, "ty"), in 006's
-- form: every insert steps aside for a row that is already there.
-----------------------------------------------------------

ALTER TABLE users ADD COLUMN IF NOT EXISTS news_mail BOOLEAN NOT NULL DEFAULT TRUE;

CREATE TABLE IF NOT EXISTS mail_senders (
    id          SERIAL          PRIMARY KEY,
    address     VARCHAR(255)    NOT NULL,
    name        VARCHAR(100)    NOT NULL,
    created_by  INT             REFERENCES users(id) ON DELETE SET NULL,
    created_at  TIMESTAMPTZ     NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE UNIQUE INDEX IF NOT EXISTS mail_senders_address ON mail_senders (lower(address));

CREATE TABLE IF NOT EXISTS announcements (
    id                  SERIAL          PRIMARY KEY,
    template            VARCHAR(16)     NOT NULL CHECK (template IN ('newVersion', 'newRelease', 'newSurvey', 'blank')),
    application_id      INT             REFERENCES applications(id) ON DELETE SET NULL,
    application_name    VARCHAR(100),
    sender_address      VARCHAR(255)    NOT NULL,
    sender_name         VARCHAR(100)    NOT NULL,
    link                VARCHAR(500),
    content             JSONB           NOT NULL,
    audience            JSONB           NOT NULL,
    status              VARCHAR(16)     NOT NULL DEFAULT 'sending' CHECK (status IN ('sending', 'sent', 'stopped')),
    created_by          INT             REFERENCES users(id) ON DELETE SET NULL,
    created_at          TIMESTAMPTZ     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    finished_at         TIMESTAMPTZ
);

CREATE INDEX IF NOT EXISTS announcements_created ON announcements (created_at DESC);

CREATE TABLE IF NOT EXISTS announcement_recipients (
    announcement_id     INT             NOT NULL REFERENCES announcements(id) ON DELETE CASCADE,
    user_id             INT             NOT NULL REFERENCES users(id),
    email               VARCHAR(255)    NOT NULL,
    language            VARCHAR(10)     NOT NULL,
    status              VARCHAR(16)     NOT NULL DEFAULT 'pending' CHECK (status IN ('pending', 'sent', 'failed', 'skipped')),
    sent_at             TIMESTAMPTZ,
    PRIMARY KEY (announcement_id, user_id)
);

CREATE INDEX IF NOT EXISTS announcement_recipients_status ON announcement_recipients (announcement_id, status);

-----------------------------------------------------------
-- THE WORDS
-----------------------------------------------------------

INSERT INTO translation_keys (name, description) VALUES
    ('mail.newVersion.subject', 'Announcements, the "New version" template: its starting subject, which the administrator changes before sending. One line. {project} is the site''s name. Placeholders: {project}.'),
    ('mail.newVersion.heading', 'Announcements, the "New version" template: the starting big heading at the top of the mail. One line. {project} is the site''s name. Placeholders: {project}.'),
    ('mail.newVersion.body', 'Announcements, the "New version" template: the starting text under the heading. One line here; the administrator may add paragraphs. {project} is the site''s name. Placeholders: {project}.'),
    ('mail.newVersion.button', 'Announcements, the "New version" template: the starting label of the button that leads to the site. {project} is the site''s name. Placeholders: {project}.'),
    ('mail.newRelease.subject', 'Announcements, the "New release" template (a new anotoki site): its starting subject. One line. {project} is the new site''s name. Placeholders: {project}.'),
    ('mail.newRelease.heading', 'Announcements, the "New release" template: the starting big heading. One line. {project} is the new site''s name. Placeholders: {project}.'),
    ('mail.newRelease.body', 'Announcements, the "New release" template: the starting text under the heading. {project} is the new site''s name. Placeholders: {project}.'),
    ('mail.newRelease.button', 'Announcements, the "New release" template: the starting label of the button that leads to the new site. {project} is its name. Placeholders: {project}.'),
    ('mail.newSurvey.subject', 'Announcements, the "New survey" template (a survey about a site, on the survey site): its starting subject. One line. {project} is the site the survey is about. Placeholders: {project}.'),
    ('mail.newSurvey.heading', 'Announcements, the "New survey" template: the starting big heading. One line. {project} is the site the survey is about. Placeholders: {project}.'),
    ('mail.newSurvey.body', 'Announcements, the "New survey" template: the starting text under the heading. {project} is the site the survey is about. Placeholders: {project}.'),
    ('mail.newSurvey.button', 'Announcements, the "New survey" template: the starting label of the button that opens the survey. No placeholders.'),
    ('mail.news.footer', 'Every announcement: the first line at its very end, saying why the person gets it. No placeholders.'),
    ('mail.news.stop', 'Every announcement: the line under it that is the link to stop the news (in the plain-text version the address follows it on a line of its own). No placeholders.'),
    ('overview.emails', 'Account: overview. The heading of the card with the news switch. No placeholders.'),
    ('overview.newsMail', 'Account: overview. The switch that says whether the account gets the announcements administrators send. No placeholders.'),
    ('overview.newsMailHint', 'Account: overview. Under the news switch: what the news is, and that mail about the account comes regardless. No placeholders.'),
    ('overview.newsMailOn', 'Account: overview. Said once the news is switched on. No placeholders.'),
    ('overview.newsMailOff', 'Account: overview. Said once the news is switched off. No placeholders.'),
    ('unsubscribe.pageTitle', 'The page the link at the end of every announcement opens: its title in the browser tab, and its heading when the link carries nothing. No placeholders.'),
    ('unsubscribe.title', 'Unsubscribe page: the question it asks before anything is switched off. No placeholders.'),
    ('unsubscribe.text', 'Unsubscribe page: what stopping the news means, and what still comes. No placeholders.'),
    ('unsubscribe.submit', 'Unsubscribe page: the button that switches the news off. No placeholders.'),
    ('unsubscribe.doneTitle', 'Unsubscribe page: the heading once the news is switched off. No placeholders.'),
    ('unsubscribe.doneText', 'Unsubscribe page: under that heading - and how to have the news again. No placeholders.'),
    ('unsubscribe.failedText', 'Unsubscribe page: under "That link did not work", when the link is not one of ours. No placeholders.'),
    ('unsubscribe.missingText', 'Unsubscribe page: when it is opened with no link at all. No placeholders.')
ON CONFLICT (name) DO NOTHING;

INSERT INTO translations (key_name, language_code, value)
SELECT v.key_name, 'en', v.value FROM (VALUES
    ('mail.newVersion.subject', 'A new version of {project}'),
    ('mail.newVersion.heading', '{project} has a new version'),
    ('mail.newVersion.body', 'A new version of {project} is out. Come and see what is new - your anotoki account signs you in as always.'),
    ('mail.newVersion.button', 'Open {project}'),
    ('mail.newRelease.subject', 'New on anotoki: {project}'),
    ('mail.newRelease.heading', 'Meet {project}'),
    ('mail.newRelease.body', '{project} is a new anotoki site, and your anotoki account already works there - no new sign-up needed.'),
    ('mail.newRelease.button', 'Try {project}'),
    ('mail.newSurvey.subject', 'A new survey about {project}'),
    ('mail.newSurvey.heading', 'What do you think of {project}?'),
    ('mail.newSurvey.body', 'We have put together a short survey about {project}. It takes a few minutes, and your answers shape what comes next.'),
    ('mail.newSurvey.button', 'Take the survey'),
    ('mail.news.footer', 'You get this email because you have an anotoki account.'),
    ('mail.news.stop', 'Stop the news from anotoki sites'),
    ('overview.emails', 'Emails'),
    ('overview.newsMail', 'News from anotoki sites'),
    ('overview.newsMailHint', 'Now and then an email about a new version, a new site or a survey. Emails about your account come either way.'),
    ('overview.newsMailOn', 'You will get the news from anotoki sites.'),
    ('overview.newsMailOff', 'You will get no more news from anotoki sites.'),
    ('unsubscribe.pageTitle', 'Stop the news'),
    ('unsubscribe.title', 'Stop the news from anotoki sites?'),
    ('unsubscribe.text', 'You will get no more emails about new versions, new sites or surveys. Emails about your account - confirmations, sign-in codes, password resets - still come.'),
    ('unsubscribe.submit', 'Stop the news'),
    ('unsubscribe.doneTitle', 'The news is stopped'),
    ('unsubscribe.doneText', 'You will get no more news from anotoki sites. To have it again, switch it on on your account page.'),
    ('unsubscribe.failedText', 'It is not a link from one of our emails, or only part of it was copied.'),
    ('unsubscribe.missingText', 'This page is where the link at the end of our news emails lands, and there was no link in it.')
) AS v(key_name, value)
ON CONFLICT (key_name, language_code) DO NOTHING;

-- Slovak, informal.
INSERT INTO translations (key_name, language_code, value)
SELECT v.key_name, 'sk', v.value FROM (VALUES
    ('mail.newVersion.subject', 'Nová verzia: {project}'),
    ('mail.newVersion.heading', '{project} má novú verziu'),
    ('mail.newVersion.body', 'Vyšla nová verzia stránky {project}. Príď sa pozrieť, čo je nové - tvoj účet anotoki ťa prihlási ako vždy.'),
    ('mail.newVersion.button', 'Otvoriť {project}'),
    ('mail.newRelease.subject', 'Novinka na anotoki: {project}'),
    ('mail.newRelease.heading', 'Predstavujeme {project}'),
    ('mail.newRelease.body', '{project} je nová stránka anotoki a tvoj účet anotoki v nej už funguje - netreba sa nikde znova registrovať.'),
    ('mail.newRelease.button', 'Vyskúšať {project}'),
    ('mail.newSurvey.subject', 'Nový prieskum: {project}'),
    ('mail.newSurvey.heading', 'Čo si myslíš o {project}?'),
    ('mail.newSurvey.body', 'Pripravili sme krátky prieskum o {project}. Zaberie len pár minút a tvoje odpovede ovplyvnia, čo príde ďalej.'),
    ('mail.newSurvey.button', 'Vyplniť prieskum'),
    ('mail.news.footer', 'Túto správu dostávaš, pretože máš účet anotoki.'),
    ('mail.news.stop', 'Zastaviť novinky zo stránok anotoki'),
    ('overview.emails', 'E-maily'),
    ('overview.newsMail', 'Novinky zo stránok anotoki'),
    ('overview.newsMailHint', 'Občas e-mail o novej verzii, novej stránke alebo prieskume. Správy o tvojom účte prídu tak či tak.'),
    ('overview.newsMailOn', 'Novinky zo stránok anotoki ti budú chodiť.'),
    ('overview.newsMailOff', 'Novinky zo stránok anotoki ti už chodiť nebudú.'),
    ('unsubscribe.pageTitle', 'Zastaviť novinky'),
    ('unsubscribe.title', 'Zastaviť novinky zo stránok anotoki?'),
    ('unsubscribe.text', 'Už ti nebudú chodiť e-maily o nových verziách, nových stránkach ani prieskumoch. Správy o tvojom účte - potvrdenia, prihlasovacie kódy, obnovenie hesla - chodiť budú.'),
    ('unsubscribe.submit', 'Zastaviť novinky'),
    ('unsubscribe.doneTitle', 'Novinky sú zastavené'),
    ('unsubscribe.doneText', 'Novinky zo stránok anotoki ti už chodiť nebudú. Ak ich chceš znova, zapni si ich na stránke svojho účtu.'),
    ('unsubscribe.failedText', 'Nie je to odkaz z niektorého z našich e-mailov, alebo sa skopírovala len jeho časť.'),
    ('unsubscribe.missingText', 'Na túto stránku vedie odkaz na konci našich e-mailov s novinkami a žiadny v nej nebol.')
) AS v(key_name, value)
ON CONFLICT (key_name, language_code) DO NOTHING;

-----------------------------------------------------------
-- PROVE IT
--
-- What this file made: the switch on for every account, nothing sent and
-- no sender added, and the keys it writes in English and in Slovak. Only
-- these keys: the admin panel may blank a Slovak string elsewhere on purpose.
-----------------------------------------------------------

DO $$
DECLARE
    missing TEXT;
BEGIN
    IF EXISTS (SELECT 1 FROM users WHERE NOT news_mail) THEN
        RAISE EXCEPTION 'every account there was before this file should get the news';
    END IF;

    IF EXISTS (SELECT 1 FROM announcements) OR EXISTS (SELECT 1 FROM announcement_recipients) OR EXISTS (SELECT 1 FROM mail_senders) THEN
        RAISE EXCEPTION 'nothing should be sent yet, and no sender added';
    END IF;

    SELECT string_agg(wanted.name, ', ' ORDER BY wanted.name) INTO missing
      FROM (VALUES ('mail.newVersion.subject'), ('mail.newVersion.heading'), ('mail.newVersion.body'), ('mail.newVersion.button'),
                   ('mail.newRelease.subject'), ('mail.newRelease.heading'), ('mail.newRelease.body'), ('mail.newRelease.button'),
                   ('mail.newSurvey.subject'), ('mail.newSurvey.heading'), ('mail.newSurvey.body'), ('mail.newSurvey.button'),
                   ('mail.news.footer'), ('mail.news.stop'),
                   ('overview.emails'), ('overview.newsMail'), ('overview.newsMailHint'), ('overview.newsMailOn'), ('overview.newsMailOff'),
                   ('unsubscribe.pageTitle'), ('unsubscribe.title'), ('unsubscribe.text'), ('unsubscribe.submit'),
                   ('unsubscribe.doneTitle'), ('unsubscribe.doneText'), ('unsubscribe.failedText'), ('unsubscribe.missingText')) AS wanted(name)
     WHERE NOT EXISTS (SELECT 1 FROM translations t WHERE t.key_name = wanted.name AND t.language_code = 'en')
        OR NOT EXISTS (SELECT 1 FROM translations t WHERE t.key_name = wanted.name AND t.language_code = 'sk');
    IF missing IS NOT NULL THEN
        RAISE EXCEPTION 'the announcements'' keys without an English and a Slovak string: %', missing;
    END IF;
END $$;
