-----------------------------------------------------------
-- NEW ACCOUNTS WAIT FOR AN ADMINISTRATOR'S APPROVAL - WHEN SWITCHED ON
--
-- The owner's words (2026-10-06): "I would like to be able to turn on/off
-- manual approvement of registration", and then: "registration and accounts
-- are shared across every projects so the global settings will be enough".
-- One switch for the whole IAM, then, as an account is one for every anotoki
-- site (docs/decisions.md, 2026-10-06).
--
-- Settings -> "New accounts need approval" (registration_approval), off on
-- the day this runs, so nothing changes for anybody until it is turned on.
-- On: an account made by the registration form or by signing up with Google
-- waits (users.approved FALSE) until an administrator of the IAM approves it,
-- and signs in nowhere meanwhile - the IAM's own pages included. Its address
-- is still confirmed by the mailed link. Accounts an administrator makes (the
-- admin panel, the installer, create-admin.php, the legacy import) never
-- wait: the column's default is TRUE, and only the two ways a person signs
-- themselves up ever write FALSE. Every account there is today is approved.
--
-- Switching it off lets in everybody still waiting (the code does that with
-- the switch, and audits how many). Declining an account switches it off:
-- nothing is deleted, as nothing ever is in the IAM.
--
-- A mail comes with it, sent when an account is approved and its address is
-- confirmed: the sign-in page's link, put in by the code on a line of its own
-- as every mail's link is. Its words are strings, as every mail's (006).
--
-- And the pages' words, in English and Slovak (informal, "ty"), in 006's
-- form: every insert steps aside for a row that is already there.
-----------------------------------------------------------

INSERT INTO settings (name, group_name, type, value, sort_order) VALUES
    ('registration_approval', 'access', 'boolean', 'false', 25)
ON CONFLICT (name) DO NOTHING;

ALTER TABLE users ADD COLUMN IF NOT EXISTS approved BOOLEAN NOT NULL DEFAULT TRUE;

-----------------------------------------------------------
-- THE WORDS
-----------------------------------------------------------

INSERT INTO translation_keys (name, description) VALUES
    ('error.approvalPending', 'Errors, by the contract''s codes: the account waits for an administrator''s approval (approval_pending) - shown where a page has no words of its own for it. No placeholders.'),
    ('common.approvalWait', 'The sign-in pages, when an account waits for an administrator''s approval (Settings -> New accounts need approval): under "Waiting for approval" on the sign-in page, and under "Your address is confirmed" or "Password changed" when the confirmation or reset link of such an account is opened. No placeholders.'),
    ('login.pending.title', 'Sign in: the heading of the page that says the account waits for an administrator''s approval. No placeholders.'),
    ('register.approvalNotice', 'Register: a notice above the form while every new account waits for an administrator''s approval (Settings -> New accounts need approval). No placeholders.'),
    ('register.approvalNote', 'Register: under "Check your inbox" when the new account waits for an administrator''s approval. No placeholders.'),
    ('mail.approved.subject', 'The account approved mail (sent when an administrator approves an account that waited, to a confirmed address): its subject. One line. No placeholders.'),
    ('mail.approved.greeting', 'The account approved mail: its first line. {username} is the account''s name. Placeholders: {username}.'),
    ('mail.approved.intro', 'The account approved mail: the paragraph above the link. The server puts the sign-in page''s link on a line of its own under it - a mail''s words never carry an address. No placeholders.')
ON CONFLICT (name) DO NOTHING;

INSERT INTO translations (key_name, language_code, value)
SELECT v.key_name, 'en', v.value FROM (VALUES
    ('error.approvalPending', 'This account is waiting for an administrator’s approval.'),
    ('common.approvalWait', 'An administrator has to approve your account before you can sign in. We will email you once they have.'),
    ('login.pending.title', 'Waiting for approval'),
    ('register.approvalNotice', 'An administrator approves every new account. Once you have confirmed your address, we will email you when yours is approved.'),
    ('register.approvalNote', 'An administrator also approves every new account - we will email you once yours is approved.'),
    ('mail.approved.subject', 'Your anotoki account is approved'),
    ('mail.approved.greeting', 'Hello {username},'),
    ('mail.approved.intro', 'An administrator has approved your anotoki account. You can sign in now, the same way you signed up:')
) AS v(key_name, value)
ON CONFLICT (key_name, language_code) DO NOTHING;

-- Slovak, informal.
INSERT INTO translations (key_name, language_code, value)
SELECT v.key_name, 'sk', v.value FROM (VALUES
    ('error.approvalPending', 'Tento účet čaká na schválenie správcom.'),
    ('common.approvalWait', 'Kým sa budeš môcť prihlásiť, tvoj účet musí schváliť správca. Keď ho schváli, pošleme ti správu.'),
    ('login.pending.title', 'Čaká sa na schválenie'),
    ('register.approvalNotice', 'Každý nový účet schvaľuje správca. Keď potvrdíš adresu, pošleme ti správu, len čo schváli ten tvoj.'),
    ('register.approvalNote', 'Každý nový účet ešte schvaľuje správca - len čo schváli ten tvoj, pošleme ti správu.'),
    ('mail.approved.subject', 'Tvoj účet anotoki je schválený'),
    ('mail.approved.greeting', 'Ahoj {username},'),
    ('mail.approved.intro', 'Správca schválil tvoj účet anotoki. Prihlásiť sa môžeš hneď, tak ako pri registrácii:')
) AS v(key_name, value)
ON CONFLICT (key_name, language_code) DO NOTHING;

-----------------------------------------------------------
-- PROVE IT
--
-- Only what this file made true: the switch there and off, nobody waiting,
-- and the keys it writes in English and in Slovak. Only these keys: the admin
-- panel may blank a Slovak string elsewhere on purpose.
-----------------------------------------------------------

DO $$
DECLARE
    missing TEXT;
BEGIN
    IF NOT EXISTS (SELECT 1 FROM settings WHERE name = 'registration_approval' AND type = 'boolean' AND group_name = 'access') THEN
        RAISE EXCEPTION 'the registration_approval switch should be among the settings';
    END IF;

    IF EXISTS (SELECT 1 FROM users WHERE NOT approved) THEN
        RAISE EXCEPTION 'every account there was before this file should be approved';
    END IF;

    SELECT string_agg(wanted.name, ', ' ORDER BY wanted.name) INTO missing
      FROM (VALUES ('error.approvalPending'), ('common.approvalWait'), ('login.pending.title'),
                   ('register.approvalNotice'), ('register.approvalNote'),
                   ('mail.approved.subject'), ('mail.approved.greeting'), ('mail.approved.intro')) AS wanted(name)
     WHERE NOT EXISTS (SELECT 1 FROM translations t WHERE t.key_name = wanted.name AND t.language_code = 'en')
        OR NOT EXISTS (SELECT 1 FROM translations t WHERE t.key_name = wanted.name AND t.language_code = 'sk');
    IF missing IS NOT NULL THEN
        RAISE EXCEPTION 'the approval keys without an English and a Slovak string: %', missing;
    END IF;
END $$;
