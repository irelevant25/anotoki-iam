-----------------------------------------------------------
-- THE AUTHENTICATOR'S PAUSE
--
-- The owner's decision of 2026-10-13 (docs/decisions.md): reaching the
-- authenticator step means the first step of signing in was passed - the
-- password, as a rule. After five wrong codes from an account's authenticator
-- app - counted for the account, whatever address they come from, within a
-- rolling fifteen minutes, and counted again from nought after a right code or
-- a completed sign-in - the authenticator step pauses for that account for
-- fifteen minutes from the fifth. Meanwhile every way in that takes an
-- authenticator code refuses one without checking it (guessing gains
-- nothing), the sign-in page offers a code by email in its place - after the
-- right password, never without it - and the account's owner is mailed, once
-- for the pause. Recovery codes and a remembered browser work as before: they
-- are no six digits to guess.
--
--    - users.totp_paused_until: when the pause ends - null, or a time gone by,
--      while there is none.
--    - users.totp_count_from: wrong codes before it are not counted - a right
--      code, a completed sign-in and the pause's own start move it on.
--
-- The wrong codes are the sign-in attempts the limiter keeps already
-- (login_attempts, outcome bad_totp, with the account's id), counted through
-- its index on (user_id, created_at): no table of its own. Refused unchecked
-- during the pause, an attempt is kept as totp_paused, which no pause counts.
-- The codes by email of a pause are counted the same way (bad_email_code,
-- from the pause's start): after five wrong ones a code by email stands in no
-- more until the pause ends (kept as email_code_paused). A sign-in finished
-- with one is a session of its own method, password_email.
--
-- The words of the sign-in page's pause, of that session's method on the
-- account page, and of the mail to the owner, in English and Slovak
-- (informal, "ty"), in 006's form: every insert steps aside for a row that is
-- already there.
-----------------------------------------------------------

ALTER TABLE users ADD COLUMN IF NOT EXISTS totp_paused_until TIMESTAMPTZ;

ALTER TABLE users ADD COLUMN IF NOT EXISTS totp_count_from TIMESTAMPTZ;

-----------------------------------------------------------
-- THE WORDS
-----------------------------------------------------------

INSERT INTO translation_keys (name, description) VALUES
    ('error.authenticatorPaused', 'Errors, by the contract''s codes: authenticator codes are paused for the account after five wrong ones (authenticator_paused) - on the account page and the password reset page, which say it in this one sentence. No placeholders.'),
    ('login.paused.title', 'Sign in: the heading of the step shown while codes from the authenticator app are paused for the account, after five wrong ones. No placeholders.'),
    ('login.paused.text', 'Sign in, the pause''s step: under the heading. {time} is when the pause ends, a time of day in the reader''s own way of writing it. Placeholders: {time}.'),
    ('login.paused.emailLead', 'Sign in, the pause''s step, after a password: above the button that has a sign-in code emailed in place of the authenticator''s. No placeholders.'),
    ('login.paused.sendCode', 'Sign in, the pause''s step: the button that has a sign-in code emailed in place of the authenticator''s. No placeholders.'),
    ('login.paused.codeSent', 'Sign in, the pause''s step: once the code is asked for, above the field it is typed into. {email} is the address it goes to. Placeholders: {email}.'),
    ('login.paused.passwordFirst', 'Sign in, the pause''s step, for a sign-in begun another way (a mailed code, Google): a code by email stands in only after the password. No placeholders.'),
    ('login.paused.emailSpent', 'Sign in, the pause''s step, after a password: in place of the button that has a code emailed, once five codes by email were wrong in this pause - a code by email then waits for the pause''s end too. No placeholders.'),
    ('sessions.method.password_email', 'Account: sessions. A sign-in with the password and, while the authenticator''s codes were paused, a code emailed in their place. No placeholders.'),
    ('mail.authenticatorPaused.subject', 'The mail sent to an account''s owner when its authenticator codes are paused, after five wrong ones: its subject. One line. No placeholders.'),
    ('mail.authenticatorPaused.greeting', 'The authenticator paused mail: its first line. {username} is the account''s name. Placeholders: {username}.'),
    ('mail.authenticatorPaused.intro', 'The authenticator paused mail: what happened, above the time of the fifth wrong code and the browser it came from, which the server puts on lines of their own under it. No placeholders.'),
    ('mail.authenticatorPaused.ignore', 'The authenticator paused mail: what to do, above the link to the account page''s security, which the server puts on a line of its own under it. No placeholders.')
ON CONFLICT (name) DO NOTHING;

INSERT INTO translations (key_name, language_code, value)
SELECT v.key_name, 'en', v.value FROM (VALUES
    ('error.authenticatorPaused', 'Too many wrong codes, so codes from your authenticator app are paused for 15 minutes. Try again after that.'),
    ('login.paused.title', 'Codes are paused'),
    ('login.paused.text', 'After five wrong codes, codes from your authenticator app are paused until {time}.'),
    ('login.paused.emailLead', 'Meanwhile you can sign in with a code we email you instead.'),
    ('login.paused.sendCode', 'Email me a code'),
    ('login.paused.codeSent', 'A six-digit code is on its way to {email}. It is good for ten minutes.'),
    ('login.paused.passwordFirst', 'To sign in meanwhile, start over with your password: then we can email you a code instead.'),
    ('login.paused.emailSpent', 'Five codes by email were wrong as well, so a code by email waits until then too. A recovery code still works.'),
    ('sessions.method.password_email', 'Password and emailed code'),
    ('mail.authenticatorPaused.subject', 'Wrong codes for your anotoki account'),
    ('mail.authenticatorPaused.greeting', 'Hello {username},'),
    ('mail.authenticatorPaused.intro', 'Somebody got past the first step of signing in to your anotoki account - your password, a code we emailed you, or Google - and then typed five wrong codes from your authenticator app. So its codes are paused for 15 minutes. The fifth try:'),
    ('mail.authenticatorPaused.ignore', 'If it was you, there is nothing to do: sign in after the pause, or with a code we email you. If it was not, somebody knows your password or can get into your email: change your password now on your account page:')
) AS v(key_name, value)
ON CONFLICT (key_name, language_code) DO NOTHING;

-- Slovak, informal.
INSERT INTO translations (key_name, language_code, value)
SELECT v.key_name, 'sk', v.value FROM (VALUES
    ('error.authenticatorPaused', 'Priveľa nesprávnych kódov, preto sú kódy z overovacej aplikácie na 15 minút pozastavené. Potom to skús znova.'),
    ('login.paused.title', 'Kódy sú pozastavené'),
    ('login.paused.text', 'Po piatich nesprávnych kódoch sú kódy z overovacej aplikácie pozastavené do {time}.'),
    ('login.paused.emailLead', 'Zatiaľ sa môžeš prihlásiť kódom, ktorý ti pošleme e-mailom.'),
    ('login.paused.sendCode', 'Pošlite mi kód e-mailom'),
    ('login.paused.codeSent', 'Na adresu {email} posielame šesťmiestny kód. Platí desať minút.'),
    ('login.paused.passwordFirst', 'Ak sa chceš zatiaľ prihlásiť, začni odznova so svojím heslom: potom ti môžeme poslať kód e-mailom.'),
    ('login.paused.emailSpent', 'Aj päť kódov z e-mailu bolo nesprávnych, takže aj kód e-mailom počká dovtedy. Záložný kód stále funguje.'),
    ('sessions.method.password_email', 'Heslo a kód z e-mailu'),
    ('mail.authenticatorPaused.subject', 'Nesprávne kódy k tvojmu účtu anotoki'),
    ('mail.authenticatorPaused.greeting', 'Ahoj {username},'),
    ('mail.authenticatorPaused.intro', 'Niekto prešiel prvým krokom prihlásenia do tvojho účtu anotoki - tvojím heslom, kódom, ktorý sme ti poslali e-mailom, alebo cez Google - a potom zadal päť nesprávnych kódov z overovacej aplikácie. Preto sú jej kódy na 15 minút pozastavené. Piaty pokus:'),
    ('mail.authenticatorPaused.ignore', 'Ak si to bol ty, nemusíš nič robiť: prihlás sa, keď pozastavenie skončí, alebo kódom, ktorý ti pošleme e-mailom. Ak nie, niekto pozná tvoje heslo alebo sa dostane do tvojho e-mailu: hneď si heslo zmeň na stránke svojho účtu:')
) AS v(key_name, value)
ON CONFLICT (key_name, language_code) DO NOTHING;

-----------------------------------------------------------
-- PROVE IT
--
-- What this file made: the two columns, with no account paused or counted
-- from yet, and the keys it writes in English and in Slovak. Only these
-- keys: the admin panel may blank a Slovak string elsewhere on purpose.
-----------------------------------------------------------

DO $$
DECLARE
    missing TEXT;
BEGIN
    IF (SELECT count(*) FROM pg_attribute
         WHERE attrelid = 'users'::regclass AND NOT attisdropped
           AND attname IN ('totp_paused_until', 'totp_count_from')) <> 2 THEN
        RAISE EXCEPTION 'users should have totp_paused_until and totp_count_from';
    END IF;
    IF EXISTS (SELECT 1 FROM users WHERE totp_paused_until IS NOT NULL OR totp_count_from IS NOT NULL) THEN
        RAISE EXCEPTION 'no account should be paused, or counted from, before this file';
    END IF;

    SELECT string_agg(wanted.name, ', ' ORDER BY wanted.name) INTO missing
      FROM (VALUES ('error.authenticatorPaused'), ('login.paused.title'), ('login.paused.text'), ('login.paused.emailLead'),
                   ('login.paused.sendCode'), ('login.paused.codeSent'), ('login.paused.passwordFirst'), ('login.paused.emailSpent'),
                   ('sessions.method.password_email'), ('mail.authenticatorPaused.subject'), ('mail.authenticatorPaused.greeting'), ('mail.authenticatorPaused.intro'),
                   ('mail.authenticatorPaused.ignore')) AS wanted(name)
     WHERE NOT EXISTS (SELECT 1 FROM translations t WHERE t.key_name = wanted.name AND t.language_code = 'en')
        OR NOT EXISTS (SELECT 1 FROM translations t WHERE t.key_name = wanted.name AND t.language_code = 'sk');
    IF missing IS NOT NULL THEN
        RAISE EXCEPTION 'the authenticator pause''s keys without an English and a Slovak string: %', missing;
    END IF;
END $$;
