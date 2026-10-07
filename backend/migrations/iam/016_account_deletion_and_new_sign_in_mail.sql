-----------------------------------------------------------
-- AN ACCOUNT DELETED ON REQUEST, AND A MAIL FOR A NEW BROWSER
--
-- Two decisions of the owner, both of 2026-10-07 (docs/decisions.md).
--
-- 1. Deleting an account (GDPR). The owner's words: the account's data "will
--    not be deleted immediately but after 30 days", and the sites "keep
--    everything but just change the account to anonymous one so no one will
--    know whose data it is". The person asks on the account page and can
--    change their mind for those 30 days: they sign in and press Cancel.
--
--    - users.deletion_requested_at, deletion_due_at: when the person asked,
--      and when the account goes - thirty days later. Both or neither (the
--      CHECK below). While they are set and the account is not anonymized,
--      the IAM's own sign-in still works, so the person can cancel - and
--      nothing at all is issued to any site (applicationAccessRefusal()).
--    - users.anonymized_at: when it went. The row stays - its id is the `sub`
--      every site keys its data on, and ids are never reused - with a
--      placeholder name and an undeliverable address, and nothing of the
--      person is left in it or tied to it (api/account_deletion.php says
--      table by table). The sites read the ids and times, nothing else, at
--      GET /api/public/anonymized-accounts, and scrub their own copies. A
--      second CHECK keeps such a row what it is whatever writes to it - a
--      request that read the account a moment before it went included:
--      switched off, unconfirmed, with no password, no password sign-in and no
--      two-factor.
--    - A new application gives MEMBER to every account but an anonymized
--      one (011's trigger, made again with that one condition): such an
--      account holds no role anywhere, and gets none later.
--
-- 2. A mail for a sign-in from a new browser. The owner's words: "Not based
--    on login to the project but iam in general so there will be no
--    duplicate emails" - only for a browser or device the account has not
--    signed in from before, and never for a site's silent use of a sign-in.
--
--    - user_sign_in_devices: the browsers an account has signed in from. The
--      browser holds a random secret in a long-lived cookie; the row holds
--      the SHA-256 of the account's id and that secret (so one browser is a
--      different row for every account, and the table links no two accounts),
--      when it was first and last seen, a few words of its user agent
--      ("Firefox · Windows"), and when the account was mailed about it - five
--      such mails an hour at most. It opens nothing: it only decides whether
--      a sign-in is worth a mail.
--
-- The words of the account page's deletion, the notice while it is pending,
-- the refusal page, and the four mails - asked, cancelled, deleted, a new
-- browser - in English and Slovak (informal, "ty"), in 006's form: every
-- insert steps aside for a row that is already there.
-----------------------------------------------------------

ALTER TABLE users ADD COLUMN IF NOT EXISTS deletion_requested_at TIMESTAMPTZ;

ALTER TABLE users ADD COLUMN IF NOT EXISTS deletion_due_at TIMESTAMPTZ;

ALTER TABLE users ADD COLUMN IF NOT EXISTS anonymized_at TIMESTAMPTZ;

-- Asked and due together, or neither; anonymized only after being asked.
-- Dropped and added again, so a second run is the same as the first.
ALTER TABLE users DROP CONSTRAINT IF EXISTS users_deletion_check;

ALTER TABLE users ADD CONSTRAINT users_deletion_check CHECK (
    (deletion_requested_at IS NULL) = (deletion_due_at IS NULL)
    AND (anonymized_at IS NULL OR deletion_due_at IS NOT NULL)
);

-- An anonymized row stays one: no way in can be written back onto it.
ALTER TABLE users DROP CONSTRAINT IF EXISTS users_anonymized_check;

ALTER TABLE users ADD CONSTRAINT users_anonymized_check CHECK (
    anonymized_at IS NULL
    OR (disabled AND password IS NULL AND totp_secret IS NULL AND NOT totp_enabled AND NOT email_confirmed AND NOT password_login_enabled)
);

-- The accounts housekeeping looks for: few, so a small index.
CREATE INDEX IF NOT EXISTS users_deletion_due ON users (deletion_due_at) WHERE deletion_due_at IS NOT NULL AND anonymized_at IS NULL;

-- The public list of anonymized ids, in its own order.
CREATE INDEX IF NOT EXISTS users_anonymized ON users (anonymized_at, id) WHERE anonymized_at IS NOT NULL;

CREATE TABLE IF NOT EXISTS user_sign_in_devices (
    id            SERIAL         PRIMARY KEY,
    user_id       INT            NOT NULL REFERENCES users(id),
    token_hash    CHAR(64)       NOT NULL,
    user_agent    VARCHAR(100),
    created_at    TIMESTAMPTZ    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_seen_at  TIMESTAMPTZ    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    mailed_at     TIMESTAMPTZ
);

CREATE UNIQUE INDEX IF NOT EXISTS user_sign_in_devices_token ON user_sign_in_devices (token_hash);

CREATE INDEX IF NOT EXISTS user_sign_in_devices_user ON user_sign_in_devices (user_id);

-- 011's roles_for_application(), with one condition more: an anonymized
-- account is given nothing by an application made after it went.
CREATE OR REPLACE FUNCTION roles_for_application() RETURNS TRIGGER AS $$
BEGIN
    PERFORM pg_advisory_xact_lock(4260918);
    INSERT INTO application_roles (application_id, name, description)
    SELECT NEW.id, role.name, role.description
      FROM (VALUES ('ADMIN', 'Everything in the site''s admin panel'),
                   ('EDITOR', 'Edits the site''s content in its admin panel'),
                   ('MEMBER', 'Uses the site')) AS role (name, description)
     WHERE role.name = 'MEMBER' OR NOT NEW.system
    ON CONFLICT (application_id, name) DO NOTHING;
    INSERT INTO user_roles (user_id, role_id)
    SELECT u.id, r.id FROM users u JOIN application_roles r ON r.application_id = NEW.id AND r.name = 'MEMBER'
     WHERE u.anonymized_at IS NULL
    ON CONFLICT DO NOTHING;
    RETURN NULL;
END
$$ LANGUAGE plpgsql;

-----------------------------------------------------------
-- THE WORDS
-----------------------------------------------------------

INSERT INTO translation_keys (name, description) VALUES
    ('deletion.title', 'Account page: the heading of the part where the person asks for their account to be deleted, and of the dialog that asks them to confirm it. No placeholders.'),
    ('deletion.lead', 'Account page: what deleting the account means, said before the person asks - thirty days, nothing signed in to meanwhile, then gone; the sites keep what was made on them, under no name. No placeholders.'),
    ('deletion.passwordLead', 'Account page, the dialog that asks for the deletion: above the password field (labelled with the security page''s "Current password"), for an account that signs in with a password. No placeholders.'),
    ('deletion.freshSignIn', 'Account page, the dialog that asks for the deletion: for an account that signs in without a password - and whenever the answer is reauthentication_required - a sign-in of the last 15 minutes is the proof. No placeholders.'),
    ('deletion.submit', 'Account page: the button that asks for the account to be deleted, and the dialog''s button that confirms it. No placeholders.'),
    ('deletion.requested', 'Account page: said once the deletion is asked for. {date} is the day the account is deleted. Placeholders: {date}.'),
    ('deletion.lastAdmin', 'Account page: the deletion refused because the account is the last administrator of the IAM (last_admin). No placeholders.'),
    ('deletion.pendingTitle', 'Account pages: the heading of the notice shown while the account''s deletion is pending. {date} is the day the account is deleted. Placeholders: {date}.'),
    ('deletion.pendingText', 'Account pages: under that heading - the account signs in to no site meanwhile, and the deletion can be cancelled. No placeholders.'),
    ('deletion.cancel', 'Account pages: the button that cancels a pending deletion. No placeholders.'),
    ('deletion.cancelled', 'Account pages: said once the deletion is cancelled. Every site signed the person out when it was asked for, so they sign in there again. No placeholders.'),
    ('error.accountDeletionPending', 'Errors, by the contract''s codes: the account''s deletion is pending (account_deletion_pending), and nothing signs it in to a site until it is cancelled. No placeholders.'),
    ('errorPage.deletionPendingTitle', 'Error page: its heading for account_deletion_pending - a site asked to sign in an account whose deletion is pending. No placeholders.'),
    ('errorPage.deletionPendingText', 'Error page: under that heading. The page''s button goes to the account page, where the deletion is cancelled. No placeholders.'),
    ('sessions.ended.account_deletion', 'Account: sessions. How a session ended: the account''s deletion was asked for from another browser. No placeholders.'),
    ('mail.deletionRequested.subject', 'The mail sent when a person asks for their account to be deleted: its subject. One line. No placeholders.'),
    ('mail.deletionRequested.greeting', 'The deletion asked mail: its first line. {username} is the account''s name. Placeholders: {username}.'),
    ('mail.deletionRequested.intro', 'The deletion asked mail: the paragraph above the link. {date} is the day the account is deleted. The server puts the account page''s link on a line of its own under it - a mail''s words never carry an address. Placeholders: {date}.'),
    ('mail.deletionRequested.ignore', 'The deletion asked mail: the last paragraph, for somebody who did not ask. No placeholders.'),
    ('mail.deletionCancelled.subject', 'The mail sent when a pending deletion is cancelled: its subject. One line. No placeholders.'),
    ('mail.deletionCancelled.greeting', 'The deletion cancelled mail: its first line. {username} is the account''s name. Placeholders: {username}.'),
    ('mail.deletionCancelled.intro', 'The deletion cancelled mail: the paragraph above the link. The server puts the account page''s link on a line of its own under it. No placeholders.'),
    ('mail.deletionCancelled.ignore', 'The deletion cancelled mail: the last paragraph, for somebody who did not cancel it. No placeholders.'),
    ('mail.deleted.subject', 'The mail sent when the account is deleted, thirty days after it was asked for - the last one to that address: its subject. One line. No placeholders.'),
    ('mail.deleted.greeting', 'The account deleted mail: its first line. {username} is the account''s name, the last time it is used. Placeholders: {username}.'),
    ('mail.deleted.intro', 'The account deleted mail: what is gone, and what the sites keep. There is no link in this mail. No placeholders.'),
    ('mail.deleted.closing', 'The account deleted mail: its last paragraph. No placeholders.'),
    ('mail.newSignIn.subject', 'The mail sent when the account signs in at the IAM from a browser or device it has not signed in from before: its subject. One line. No placeholders.'),
    ('mail.newSignIn.greeting', 'The new sign-in mail: its first line. {username} is the account''s name. Placeholders: {username}.'),
    ('mail.newSignIn.intro', 'The new sign-in mail: the paragraph above the time of the sign-in and the browser, which the server puts on lines of their own under it. No placeholders.'),
    ('mail.newSignIn.ignore', 'The new sign-in mail: the paragraph above the link to the page of every place the account is signed in, which the server puts on a line of its own under it. No placeholders.')
ON CONFLICT (name) DO NOTHING;

INSERT INTO translations (key_name, language_code, value)
SELECT v.key_name, 'en', v.value FROM (VALUES
    ('deletion.title', 'Delete your account'),
    ('deletion.lead', 'Your account is deleted 30 days after you ask. From that moment it signs you in to no anotoki site; after the 30 days your name, your address, your password and your sign-ins are gone for good. The anotoki sites keep what you made on them, under no name.'),
    ('deletion.passwordLead', 'Type your password to confirm it is you.'),
    ('deletion.freshSignIn', 'To confirm it is you, sign in again first - then come back here within 15 minutes.'),
    ('deletion.submit', 'Delete my account'),
    ('deletion.requested', 'Your account will be deleted on {date}. We have emailed you how to keep it.'),
    ('deletion.lastAdmin', 'You are the only administrator of the IAM. Make somebody else an administrator before you delete your account.'),
    ('deletion.pendingTitle', 'Your account will be deleted on {date}'),
    ('deletion.pendingText', 'Until then it signs you in to no anotoki site. Changed your mind? Cancel the deletion and your account stays.'),
    ('deletion.cancel', 'Cancel the deletion'),
    ('deletion.cancelled', 'The deletion is cancelled. Sign in to the anotoki sites again to use them.'),
    ('error.accountDeletionPending', 'This account is being deleted. To use it again, cancel the deletion on your account page.'),
    ('errorPage.deletionPendingTitle', 'Your account is being deleted'),
    ('errorPage.deletionPendingText', 'You asked for it to be deleted, so it signs in to no anotoki site any more. To keep it, go to your account and cancel the deletion.'),
    ('sessions.ended.account_deletion', 'Ended by the request to delete the account'),
    ('mail.deletionRequested.subject', 'Your anotoki account will be deleted'),
    ('mail.deletionRequested.greeting', 'Hello {username},'),
    ('mail.deletionRequested.intro', 'You asked for your anotoki account to be deleted, and it will be on {date}. Until then it signs you in to no anotoki site. To keep it, sign in on your account page and cancel the deletion:'),
    ('mail.deletionRequested.ignore', 'If you did not ask for this, somebody else has been signed in to your account: cancel the deletion there, then change your password.'),
    ('mail.deletionCancelled.subject', 'Your anotoki account will not be deleted'),
    ('mail.deletionCancelled.greeting', 'Hello {username},'),
    ('mail.deletionCancelled.intro', 'The deletion of your anotoki account was cancelled, and it stays as it is. Sign in to the anotoki sites again to use them. Your account page:'),
    ('mail.deletionCancelled.ignore', 'If you did not cancel it, somebody else has been signed in to your account: change your password, and ask for the deletion again if you still want it.'),
    ('mail.deleted.subject', 'Your anotoki account is deleted'),
    ('mail.deleted.greeting', 'Hello {username},'),
    ('mail.deleted.intro', 'As you asked, your anotoki account is deleted: its name, its address, its password and its sign-ins are gone. The anotoki sites keep what was made on them, under no name.'),
    ('mail.deleted.closing', 'This is the last email we send to this address. Thank you for having been with us.'),
    ('mail.newSignIn.subject', 'New sign-in to your anotoki account'),
    ('mail.newSignIn.greeting', 'Hello {username},'),
    ('mail.newSignIn.intro', 'Your anotoki account was just signed in to from a browser or device it has not been used on before:'),
    ('mail.newSignIn.ignore', 'If it was you, there is nothing to do. If it was not, change your password now, and end that sign-in on the page that shows every place your account is signed in:')
) AS v(key_name, value)
ON CONFLICT (key_name, language_code) DO NOTHING;

-- Slovak, informal.
INSERT INTO translations (key_name, language_code, value)
SELECT v.key_name, 'sk', v.value FROM (VALUES
    ('deletion.title', 'Zmazanie účtu'),
    ('deletion.lead', 'Účet sa zmaže 30 dní po tom, čo o to požiadaš. Od tej chvíle ťa neprihlási na žiadnu stránku anotoki; po 30 dňoch navždy zmizne tvoje meno, adresa, heslo aj prihlásenia. Stránky anotoki si to, čo si na nich vytvoril, ponechajú bez mena.'),
    ('deletion.passwordLead', 'Napíš svoje heslo, aby sme vedeli, že si to ty.'),
    ('deletion.freshSignIn', 'Aby sme vedeli, že si to ty, najprv sa znova prihlás - a potom sa sem do 15 minút vráť.'),
    ('deletion.submit', 'Zmazať môj účet'),
    ('deletion.requested', 'Tvoj účet sa zmaže {date}. Poslali sme ti e-mail, ako si ho môžeš ponechať.'),
    ('deletion.lastAdmin', 'Si jediný správca IAM. Kým zmažeš svoj účet, urob správcom niekoho iného.'),
    ('deletion.pendingTitle', 'Tvoj účet sa zmaže {date}'),
    ('deletion.pendingText', 'Dovtedy ťa neprihlási na žiadnu stránku anotoki. Rozmyslel si sa? Zruš zmazanie a účet ti zostane.'),
    ('deletion.cancel', 'Zrušiť zmazanie'),
    ('deletion.cancelled', 'Zmazanie je zrušené. Ak chceš používať stránky anotoki, prihlás sa na nich znova.'),
    ('error.accountDeletionPending', 'Tento účet sa maže. Ak ho chceš znova používať, zruš zmazanie na stránke svojho účtu.'),
    ('errorPage.deletionPendingTitle', 'Tvoj účet sa maže'),
    ('errorPage.deletionPendingText', 'Požiadal si o jeho zmazanie, preto ťa už neprihlási na žiadnu stránku anotoki. Ak si ho chceš ponechať, prejdi na svoj účet a zmazanie zruš.'),
    ('sessions.ended.account_deletion', 'Ukončené žiadosťou o zmazanie účtu'),
    ('mail.deletionRequested.subject', 'Tvoj účet anotoki sa zmaže'),
    ('mail.deletionRequested.greeting', 'Ahoj {username},'),
    ('mail.deletionRequested.intro', 'Požiadal si o zmazanie svojho účtu anotoki a zmaže sa {date}. Dovtedy ťa neprihlási na žiadnu stránku anotoki. Ak si ho chceš ponechať, prihlás sa na stránke svojho účtu a zmazanie zruš:'),
    ('mail.deletionRequested.ignore', 'Ak si o to nežiadal, do tvojho účtu sa prihlásil niekto iný: zmazanie tam zruš a potom si zmeň heslo.'),
    ('mail.deletionCancelled.subject', 'Tvoj účet anotoki sa nezmaže'),
    ('mail.deletionCancelled.greeting', 'Ahoj {username},'),
    ('mail.deletionCancelled.intro', 'Zmazanie tvojho účtu anotoki bolo zrušené a účet zostáva tak, ako je. Ak chceš používať stránky anotoki, prihlás sa na nich znova. Stránka tvojho účtu:'),
    ('mail.deletionCancelled.ignore', 'Ak si to nezrušil ty, do tvojho účtu sa prihlásil niekto iný: zmeň si heslo, a ak účet stále chceš zmazať, požiadaj o to znova.'),
    ('mail.deleted.subject', 'Tvoj účet anotoki je zmazaný'),
    ('mail.deleted.greeting', 'Ahoj {username},'),
    ('mail.deleted.intro', 'Ako si požiadal, tvoj účet anotoki je zmazaný: jeho meno, adresa, heslo aj prihlásenia sú preč. Stránky anotoki si to, čo sa na nich vytvorilo, ponechajú bez mena.'),
    ('mail.deleted.closing', 'Toto je posledný e-mail, ktorý na túto adresu posielame. Ďakujeme, že si bol s nami.'),
    ('mail.newSignIn.subject', 'Nové prihlásenie do tvojho účtu anotoki'),
    ('mail.newSignIn.greeting', 'Ahoj {username},'),
    ('mail.newSignIn.intro', 'Do tvojho účtu anotoki sa práve prihlásil prehliadač alebo zariadenie, na ktorom si ho ešte nepoužil:'),
    ('mail.newSignIn.ignore', 'Ak si to bol ty, nemusíš nič robiť. Ak nie, hneď si zmeň heslo a toto prihlásenie ukonči na stránke so všetkými miestami, kde je tvoj účet prihlásený:')
) AS v(key_name, value)
ON CONFLICT (key_name, language_code) DO NOTHING;

-----------------------------------------------------------
-- PROVE IT
--
-- What this file made: the three columns and their two checks, nobody asked
-- for or anonymized yet, the table of browsers, empty; a new application giving
-- MEMBER to every account but an anonymized one - tried and taken back, as
-- 011's probe is; and the keys this file writes in English and in Slovak.
-- Only these keys: the admin panel may blank a Slovak string elsewhere on
-- purpose.
-----------------------------------------------------------

DO $$
DECLARE
    missing TEXT;
    tag TEXT := md5(random()::text || clock_timestamp()::text);
    probe_gone INT;
    given_to_one INT;
    given_to_gone INT;
    made TEXT;
BEGIN
    IF (SELECT count(*) FROM pg_attribute
         WHERE attrelid = 'users'::regclass AND NOT attisdropped
           AND attname IN ('deletion_requested_at', 'deletion_due_at', 'anonymized_at')) <> 3 THEN
        RAISE EXCEPTION 'users should have deletion_requested_at, deletion_due_at and anonymized_at';
    END IF;
    IF (SELECT count(*) FROM pg_constraint
         WHERE conrelid = 'users'::regclass AND contype = 'c' AND convalidated
           AND conname IN ('users_deletion_check', 'users_anonymized_check')) <> 2 THEN
        RAISE EXCEPTION 'users is missing its check on the deletion''s columns, or on what an anonymized row may hold';
    END IF;
    IF EXISTS (SELECT 1 FROM users WHERE deletion_requested_at IS NOT NULL OR deletion_due_at IS NOT NULL OR anonymized_at IS NOT NULL) THEN
        RAISE EXCEPTION 'nobody should have asked for a deletion, or been anonymized, before this file';
    END IF;
    IF to_regclass('public.user_sign_in_devices') IS NULL OR EXISTS (SELECT 1 FROM user_sign_in_devices) THEN
        RAISE EXCEPTION 'user_sign_in_devices should be there, and empty';
    END IF;

    -- A new application, an account and an anonymized one: what each is
    -- given there. The inner block's own exception undoes all three.
    BEGIN
        INSERT INTO users (username, email) VALUES ('migration-016-probe-' || tag, 'migration-016-probe-' || tag || '@probe.invalid');
        INSERT INTO users (username, email) VALUES ('migration-016-gone-' || tag, 'migration-016-gone-' || tag || '@probe.invalid')
            RETURNING id INTO probe_gone;
        UPDATE users SET deletion_requested_at = CURRENT_TIMESTAMP, deletion_due_at = CURRENT_TIMESTAMP, anonymized_at = CURRENT_TIMESTAMP,
                         disabled = TRUE, password_login_enabled = FALSE
         WHERE id = probe_gone;
        DELETE FROM user_roles WHERE user_id = probe_gone;
        INSERT INTO applications (client_id, name) VALUES ('migration-016-probe', 'Probe');
        SELECT count(*) INTO given_to_one FROM user_roles ur JOIN application_roles r ON r.id = ur.role_id JOIN applications a ON a.id = r.application_id
         WHERE a.client_id = 'migration-016-probe' AND r.name = 'MEMBER'
           AND ur.user_id = (SELECT id FROM users WHERE username = 'migration-016-probe-' || tag);
        SELECT count(*) INTO given_to_gone FROM user_roles WHERE user_id = probe_gone;
        RAISE EXCEPTION USING ERRCODE = 'P0016', MESSAGE = given_to_one || ' ' || given_to_gone;
    EXCEPTION WHEN SQLSTATE 'P0016' THEN
        made := SQLERRM;
    END;
    IF made IS DISTINCT FROM '1 0' THEN
        RAISE EXCEPTION 'a new application should give MEMBER to an account and nothing to an anonymized one; it gave (an account, the anonymized one): %', made;
    END IF;
    IF EXISTS (SELECT 1 FROM applications WHERE client_id = 'migration-016-probe') OR EXISTS (SELECT 1 FROM users WHERE username LIKE 'migration-016-%') THEN
        RAISE EXCEPTION 'the probe should not be left behind';
    END IF;

    SELECT string_agg(wanted.name, ', ' ORDER BY wanted.name) INTO missing
      FROM (VALUES ('deletion.title'), ('deletion.lead'), ('deletion.passwordLead'), ('deletion.freshSignIn'), ('deletion.submit'),
                   ('deletion.requested'), ('deletion.lastAdmin'), ('deletion.pendingTitle'), ('deletion.pendingText'),
                   ('deletion.cancel'), ('deletion.cancelled'), ('error.accountDeletionPending'),
                   ('errorPage.deletionPendingTitle'), ('errorPage.deletionPendingText'), ('sessions.ended.account_deletion'),
                   ('mail.deletionRequested.subject'), ('mail.deletionRequested.greeting'), ('mail.deletionRequested.intro'), ('mail.deletionRequested.ignore'),
                   ('mail.deletionCancelled.subject'), ('mail.deletionCancelled.greeting'), ('mail.deletionCancelled.intro'), ('mail.deletionCancelled.ignore'),
                   ('mail.deleted.subject'), ('mail.deleted.greeting'), ('mail.deleted.intro'), ('mail.deleted.closing'),
                   ('mail.newSignIn.subject'), ('mail.newSignIn.greeting'), ('mail.newSignIn.intro'), ('mail.newSignIn.ignore')) AS wanted(name)
     WHERE NOT EXISTS (SELECT 1 FROM translations t WHERE t.key_name = wanted.name AND t.language_code = 'en')
        OR NOT EXISTS (SELECT 1 FROM translations t WHERE t.key_name = wanted.name AND t.language_code = 'sk');
    IF missing IS NOT NULL THEN
        RAISE EXCEPTION 'the deletion''s and the new sign-in''s keys without an English and a Slovak string: %', missing;
    END IF;
END $$;
