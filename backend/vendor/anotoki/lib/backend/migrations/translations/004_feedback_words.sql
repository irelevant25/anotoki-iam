-- anotoki_translations 004 - the words of the feedback form, under the anotoki library's namespace anotoki.feedback.
--
-- What the feedback form says (@anotoki/lib/feedback, <anotoki-feedback-form>: the form a site's visitors
-- send it a message with) - its title and fields, the kinds of message, its thanks, and what it says of a
-- message it could not send. anotoki-lib's php/resources/library-words.json holds them - the Angular half's
-- built-in words are made from the same file, and a test of the library holds this file to it.
--
-- As in 002: a key's description is the library's, and is written again whenever a library migration
-- carries it; a key's strings are the site's once it has any - written here only for a library key that
-- has no string at all. So a site that took a key over before this file ran keeps its words.
--
-- Released with anotoki-lib 0.4.0, and never edited once released: a change is a new file.

INSERT INTO translation_keys (name, description) VALUES
    ('anotoki.feedback.title', 'The anotoki library''s feedback form (the form a site''s visitors send it a message with): its title, and the name of the dialog a site opens it in. No placeholders.'),
    ('anotoki.feedback.lead', 'The anotoki library''s feedback form: the sentence under its title. No placeholders.'),
    ('anotoki.feedback.kind', 'The anotoki library''s feedback form: the question over the kinds of message (something does not work, an idea, something else). No placeholders.'),
    ('anotoki.feedback.kindBug', 'The anotoki library''s feedback form: the kind of message about something on the site that does not work. No placeholders.'),
    ('anotoki.feedback.kindIdea', 'The anotoki library''s feedback form: the kind of message with an idea for the site. No placeholders.'),
    ('anotoki.feedback.kindOther', 'The anotoki library''s feedback form: the kind of message that is neither (a question, a thank-you). No placeholders.'),
    ('anotoki.feedback.section', 'The anotoki library''s feedback form: the label of the list of the site''s places the message can be about. No placeholders.'),
    ('anotoki.feedback.sectionNone', 'The anotoki library''s feedback form: the first choice of the list of the site''s places, for a message about none of them. No placeholders.'),
    ('anotoki.feedback.subject', 'The anotoki library''s feedback form: the label of the message''s subject. No placeholders.'),
    ('anotoki.feedback.message', 'The anotoki library''s feedback form: the label of the message itself. No placeholders.'),
    ('anotoki.feedback.messageHint', 'The anotoki library''s feedback form: the hint under the message. No placeholders.'),
    ('anotoki.feedback.email', 'The anotoki library''s feedback form: the label of the sender''s e-mail address, which they may leave empty. No placeholders.'),
    ('anotoki.feedback.emailHint', 'The anotoki library''s feedback form: the hint under the e-mail address. No placeholders.'),
    ('anotoki.feedback.anonymous', 'The anotoki library''s feedback form: the box a signed-in sender ticks to send the message without their name. No placeholders.'),
    ('anotoki.feedback.anonymousHint', 'The anotoki library''s feedback form: the hint under the box that sends a message without the sender''s name. No placeholders.'),
    ('anotoki.feedback.send', 'The anotoki library''s feedback form: the button that sends the message. No placeholders.'),
    ('anotoki.feedback.sending', 'The anotoki library''s feedback form: the button while the message is on its way. No placeholders.'),
    ('anotoki.feedback.thanks', 'The anotoki library''s feedback form: the title said once the message came. No placeholders.'),
    ('anotoki.feedback.thanksText', 'The anotoki library''s feedback form: the sentence under the thanks. No placeholders.'),
    ('anotoki.feedback.another', 'The anotoki library''s feedback form: the button under the thanks that opens an empty form again. No placeholders.'),
    ('anotoki.feedback.messageRequired', 'The anotoki library''s feedback form: said beside the message when it was sent empty. No placeholders.'),
    ('anotoki.feedback.subjectRequired', 'The anotoki library''s feedback form: said beside the subject when a message of a kind that needs one was sent without it. No placeholders.'),
    ('anotoki.feedback.tooLong', 'The anotoki library''s feedback form: said beside a field that is longer than the site takes. {max}: how many characters it takes. Placeholders: {max}.'),
    ('anotoki.feedback.emailInvalid', 'The anotoki library''s feedback form: said beside an e-mail address that is not one. No placeholders.'),
    ('anotoki.feedback.tooManyHour', 'The anotoki library''s feedback form: said when one sender sent many messages within an hour; the message is not taken. No placeholders.'),
    ('anotoki.feedback.tooManyDay', 'The anotoki library''s feedback form: said when one sender, or everybody, sent many messages today; the message is not taken. No placeholders.'),
    ('anotoki.feedback.unavailable', 'The anotoki library''s feedback form: said when the site cannot take messages right now (its database waits for an update). No placeholders.'),
    ('anotoki.feedback.signInRequired', 'The anotoki library''s feedback form: said on a site that takes messages from signed-in people alone, to somebody who is not. No placeholders.'),
    ('anotoki.feedback.failed', 'The anotoki library''s feedback form: said when a message could not be sent for any other reason; what was written stays in the form. No placeholders.'),
    ('anotoki.feedback.network', 'The anotoki library''s feedback form: said when the site could not be reached at all. No placeholders.'),
    ('anotoki.feedback.honeypot', 'The anotoki library''s feedback form: the label of a field people never see or reach (robots fill it in, and their messages are dropped) - for a screen reader that finds it anyway. No placeholders.')
ON CONFLICT (name) DO UPDATE SET description = EXCLUDED.description;

-- One statement for every language: the rows it writes do not hide each other from the test of "no
-- string at all", which sees the table as it was before the statement began.
INSERT INTO translations (key_name, language_code, value)
SELECT v.key_name, v.language_code, v.value
  FROM (VALUES
    ('anotoki.feedback.title', 'en', 'Send feedback'),
    ('anotoki.feedback.title', 'sk', 'Pošli nám spätnú väzbu'),
    ('anotoki.feedback.lead', 'en', 'Something broken, an idea, a question? Tell us - every message is read.'),
    ('anotoki.feedback.lead', 'sk', 'Niečo nefunguje, máš nápad alebo otázku? Napíš nám - čítame každú správu.'),
    ('anotoki.feedback.kind', 'en', 'What is it about?'),
    ('anotoki.feedback.kind', 'sk', 'O čo ide?'),
    ('anotoki.feedback.kindBug', 'en', 'Something does not work'),
    ('anotoki.feedback.kindBug', 'sk', 'Niečo nefunguje'),
    ('anotoki.feedback.kindIdea', 'en', 'An idea'),
    ('anotoki.feedback.kindIdea', 'sk', 'Nápad'),
    ('anotoki.feedback.kindOther', 'en', 'Something else'),
    ('anotoki.feedback.kindOther', 'sk', 'Niečo iné'),
    ('anotoki.feedback.section', 'en', 'Where on the site?'),
    ('anotoki.feedback.section', 'sk', 'Kde na stránke?'),
    ('anotoki.feedback.sectionNone', 'en', 'Nowhere in particular'),
    ('anotoki.feedback.sectionNone', 'sk', 'Nikde konkrétne'),
    ('anotoki.feedback.subject', 'en', 'Subject'),
    ('anotoki.feedback.subject', 'sk', 'Predmet'),
    ('anotoki.feedback.message', 'en', 'Your message'),
    ('anotoki.feedback.message', 'sk', 'Tvoja správa'),
    ('anotoki.feedback.messageHint', 'en', 'What happened and what should have happened - or what would be better.'),
    ('anotoki.feedback.messageHint', 'sk', 'Čo sa stalo a čo sa malo stať - alebo čo by bolo lepšie.'),
    ('anotoki.feedback.email', 'en', 'Your e-mail address'),
    ('anotoki.feedback.email', 'sk', 'Tvoja e-mailová adresa'),
    ('anotoki.feedback.emailHint', 'en', 'Only if you would like an answer. It is used for nothing else.'),
    ('anotoki.feedback.emailHint', 'sk', 'Len ak chceš odpoveď. Na nič iné ju nepoužijeme.'),
    ('anotoki.feedback.anonymous', 'en', 'Send it without my name'),
    ('anotoki.feedback.anonymous', 'sk', 'Poslať bez môjho mena'),
    ('anotoki.feedback.anonymousHint', 'en', 'Neither your account nor your address goes with it.'),
    ('anotoki.feedback.anonymousHint', 'sk', 'Nepôjde s ňou ani tvoj účet, ani tvoja adresa.'),
    ('anotoki.feedback.send', 'en', 'Send'),
    ('anotoki.feedback.send', 'sk', 'Poslať'),
    ('anotoki.feedback.sending', 'en', 'Sending…'),
    ('anotoki.feedback.sending', 'sk', 'Posielam…'),
    ('anotoki.feedback.thanks', 'en', 'Thank you!'),
    ('anotoki.feedback.thanks', 'sk', 'Ďakujeme!'),
    ('anotoki.feedback.thanksText', 'en', 'Your message is on its way to us.'),
    ('anotoki.feedback.thanksText', 'sk', 'Tvoja správa je na ceste k nám.'),
    ('anotoki.feedback.another', 'en', 'Send another'),
    ('anotoki.feedback.another', 'sk', 'Poslať ďalšiu'),
    ('anotoki.feedback.messageRequired', 'en', 'Write a message first.'),
    ('anotoki.feedback.messageRequired', 'sk', 'Najprv napíš správu.'),
    ('anotoki.feedback.subjectRequired', 'en', 'Give it a subject.'),
    ('anotoki.feedback.subjectRequired', 'sk', 'Doplň predmet.'),
    ('anotoki.feedback.tooLong', 'en', 'This is longer than {max} characters.'),
    ('anotoki.feedback.tooLong', 'sk', 'Toto je dlhšie ako {max} znakov.'),
    ('anotoki.feedback.emailInvalid', 'en', 'That does not look like an e-mail address.'),
    ('anotoki.feedback.emailInvalid', 'sk', 'Toto nevyzerá ako e-mailová adresa.'),
    ('anotoki.feedback.tooManyHour', 'en', 'Thank you - but that is a lot of messages in a short while. Try again in an hour.'),
    ('anotoki.feedback.tooManyHour', 'sk', 'Ďakujeme - no to je veľa správ za krátky čas. Skús to znova o hodinu.'),
    ('anotoki.feedback.tooManyDay', 'en', 'Thank you - but there have been a lot of messages today. Try again tomorrow.'),
    ('anotoki.feedback.tooManyDay', 'sk', 'Ďakujeme - no dnes prišlo veľa správ. Skús to znova zajtra.'),
    ('anotoki.feedback.unavailable', 'en', 'Messages cannot be sent right now. Try again in a few minutes.'),
    ('anotoki.feedback.unavailable', 'sk', 'Správy teraz nemôžeš poslať. Skús to znova o pár minút.'),
    ('anotoki.feedback.signInRequired', 'en', 'Sign in to send a message.'),
    ('anotoki.feedback.signInRequired', 'sk', 'Na poslanie správy sa prihlás.'),
    ('anotoki.feedback.failed', 'en', 'The message could not be sent. Try again - what you wrote is still here.'),
    ('anotoki.feedback.failed', 'sk', 'Správu sa nepodarilo poslať. Skús to znova - tvoj text tu stále je.'),
    ('anotoki.feedback.network', 'en', 'The site could not be reached. Check the connection, then try again.'),
    ('anotoki.feedback.network', 'sk', 'Stránka nie je dostupná. Skontroluj pripojenie a skús to znova.'),
    ('anotoki.feedback.honeypot', 'en', 'Leave this field empty'),
    ('anotoki.feedback.honeypot', 'sk', 'Toto pole nechaj prázdne')
  ) AS v(key_name, language_code, value)
  JOIN languages l ON l.code = v.language_code
 WHERE NOT EXISTS (SELECT 1 FROM translations t WHERE t.key_name = v.key_name)
ON CONFLICT (key_name, language_code) DO NOTHING;

-- Every key of this file, with an English string: Slovak is not asked for - a site that took a key over
-- may have left it blank on purpose.
DO $$
DECLARE
    missing TEXT;
BEGIN
    SELECT string_agg(k.name, ', ' ORDER BY k.name) INTO missing
      FROM (VALUES
        ('anotoki.feedback.title'),
        ('anotoki.feedback.lead'),
        ('anotoki.feedback.kind'),
        ('anotoki.feedback.kindBug'),
        ('anotoki.feedback.kindIdea'),
        ('anotoki.feedback.kindOther'),
        ('anotoki.feedback.section'),
        ('anotoki.feedback.sectionNone'),
        ('anotoki.feedback.subject'),
        ('anotoki.feedback.message'),
        ('anotoki.feedback.messageHint'),
        ('anotoki.feedback.email'),
        ('anotoki.feedback.emailHint'),
        ('anotoki.feedback.anonymous'),
        ('anotoki.feedback.anonymousHint'),
        ('anotoki.feedback.send'),
        ('anotoki.feedback.sending'),
        ('anotoki.feedback.thanks'),
        ('anotoki.feedback.thanksText'),
        ('anotoki.feedback.another'),
        ('anotoki.feedback.messageRequired'),
        ('anotoki.feedback.subjectRequired'),
        ('anotoki.feedback.tooLong'),
        ('anotoki.feedback.emailInvalid'),
        ('anotoki.feedback.tooManyHour'),
        ('anotoki.feedback.tooManyDay'),
        ('anotoki.feedback.unavailable'),
        ('anotoki.feedback.signInRequired'),
        ('anotoki.feedback.failed'),
        ('anotoki.feedback.network'),
        ('anotoki.feedback.honeypot')
      ) AS k(name)
     WHERE NOT EXISTS (SELECT 1 FROM translation_keys tk WHERE tk.name = k.name)
        OR NOT EXISTS (SELECT 1 FROM translations t WHERE t.key_name = k.name AND t.language_code = 'en');
    IF missing IS NOT NULL THEN
        RAISE EXCEPTION 'anotoki_translations 004: the library''s keys without an English string: %. A site that takes a library key over gives it its English.', missing;
    END IF;
END $$;

-- end of 004
