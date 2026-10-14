-----------------------------------------------------------
-- GOOGLE ONLY ON A PRESS
--
-- The owner's decision of 2026-10-14: Google is contacted only when somebody
-- presses the button. Until now the sign-in, sign-up and account pages
-- fetched Google's script as they opened, to draw Google's own button, so
-- every visitor's browser contacted Google while Google sign-in was switched
-- on. Now the pages draw the IAM's own button (frontend ui/google-button, in
-- Google's branding, the "G" drawn by the page), and only a press on it
-- fetches Google's script and draws Google's own button in its place - whose
-- press opens Google's account chooser. Six page keys say that button's
-- words, what a screen reader is told and the line shown when Google cannot
-- be reached: their English (en.ts has the same) and their Slovak (informal,
-- "ty"), in 006's form - every insert steps aside for a row that is already
-- there.
--
-- And the Privacy page's sentence about Google, 020's legal.google.role,
-- which said that the button is loaded from Google wherever it is drawn: it
-- now says that only a press loads anything from Google. An UPDATE of 020's
-- own words only, and only where no administrator saved the string
-- (updated_by): one saved on the Translations page is the owner's choice -
-- reworded, or put back to 020's very words on purpose - and stays, with its
-- writer; a string the owner blanked has no row to update. The key's
-- description, which only a migration writes, follows where it is 020's.
-----------------------------------------------------------

INSERT INTO translation_keys (name, description) VALUES
    ('google.signIn', 'Signing in with Google: the words on the IAM''s own button on the sign-in page, drawn before anything is loaded from Google; a press on it brings Google''s own button into its place. Google''s branding allows these words ("Sign in with Google"). No placeholders.'),
    ('google.signUp', 'Signing in with Google: the words on the IAM''s own button on the sign-up page, drawn before anything is loaded from Google; a press on it brings Google''s own button into its place. Google''s branding allows these words ("Sign up with Google"). No placeholders.'),
    ('google.continue', 'Signing in with Google: the words on the IAM''s own button where an account that has no password signs in, and on the account page''s Security to connect Google - drawn before anything is loaded from Google; a press on it brings Google''s own button into its place. Google''s branding allows these words ("Continue with Google"). No placeholders.'),
    ('google.frame', 'Signing in with Google: what a screen reader calls the frame that holds Google''s own button after a press on the IAM''s, when the focus moves onto it (the next Tab is Google''s button). Not shown. No placeholders.'),
    ('google.ready', 'Signing in with Google: said to a screen reader, not shown, once Google''s own button is drawn after a press on the IAM''s - a press on Google''s button then chooses the Google account. No placeholders.'),
    ('google.failed', 'Signing in with Google: the line under the IAM''s button when a press on it could not load Google''s script (blocked, offline). Everything else on the page works, and another press tries again. No placeholders.')
ON CONFLICT (name) DO NOTHING;

INSERT INTO translations (key_name, language_code, value)
SELECT v.key_name, 'en', v.value FROM (VALUES
    ('google.signIn', 'Sign in with Google'),
    ('google.signUp', 'Sign up with Google'),
    ('google.continue', 'Continue with Google'),
    ('google.frame', 'Google’s own button'),
    ('google.ready', 'Google’s own button is ready: press it to choose your Google account.'),
    ('google.failed', 'Google could not be reached: this browser may be blocking it, or you are offline.')
) AS v(key_name, value)
ON CONFLICT (key_name, language_code) DO NOTHING;

-- Slovak, informal.
INSERT INTO translations (key_name, language_code, value)
SELECT v.key_name, 'sk', v.value FROM (VALUES
    ('google.signIn', 'Prihlásiť sa cez Google'),
    ('google.signUp', 'Zaregistrovať sa cez Google'),
    ('google.continue', 'Pokračovať cez Google'),
    ('google.frame', 'Tlačidlo od Googlu'),
    ('google.ready', 'Tlačidlo od Googlu je pripravené: stlač ho a vyber si účet Google.'),
    ('google.failed', 'Nepodarilo sa spojiť s Googlom: tento prehliadač ho možno blokuje alebo nie si pripojený k internetu.')
) AS v(key_name, value)
ON CONFLICT (key_name, language_code) DO NOTHING;

-- The Privacy page's sentence about Google: 020's words, and only those, reworded - where no administrator saved the string.
UPDATE translations SET value = 'Only when you press "Sign in with Google", "Sign up with Google" or "Continue with Google" - on the sign-in and sign-up pages, and on the account page while Google is not connected - does your browser load anything from Google: Google’s own button, so Google sees your IP address and its own cookies. Only if you then choose your Google account does Google tell the anotoki account your Google account’s id, e-mail address and name. What Google itself keeps, its own privacy policy says. A company based in the USA: data may be handled outside the EU.', updated_at = CURRENT_TIMESTAMP
 WHERE key_name = 'legal.google.role' AND language_code = 'en' AND value = 'Draws its "Sign in with Google" button on the sign-in and sign-up pages, and on the account page while Google is not connected, whenever signing in with Google is switched on: your browser loads the button from Google, so Google sees your IP address and its own cookies. Only if you choose to sign in with Google does Google tell the anotoki account your Google account’s id, e-mail address and name. What Google itself keeps, its own privacy policy says. A company based in the USA: data may be handled outside the EU.' AND updated_by IS NULL;

-- Slovak, informal.
UPDATE translations SET value = 'Až keď stlačíš „Prihlásiť sa cez Google“, „Zaregistrovať sa cez Google“ alebo „Pokračovať cez Google“ - na stránkach prihlásenia a registrácie a na stránke účtu, kým Google nie je pripojený -, načíta tvoj prehliadač niečo od Googlu: jeho vlastné tlačidlo, takže Google vidí tvoju IP adresu a svoje vlastné cookies. Len ak si potom vyberieš svoj účet Google, oznámi Google anotoki účtu identifikátor, e-mailovú adresu a meno tvojho účtu Google. Čo uchováva samotný Google, opisujú jeho vlastné zásady ochrany súkromia. Spoločnosť so sídlom v USA: údaje sa môžu spracúvať aj mimo EÚ.', updated_at = CURRENT_TIMESTAMP
 WHERE key_name = 'legal.google.role' AND language_code = 'sk' AND value = 'Na stránkach prihlásenia a registrácie a na stránke účtu, kým Google nie je pripojený, zobrazuje svoje tlačidlo „Prihlásiť sa cez Google“, ak je prihlasovanie cez Google zapnuté: prehliadač ho načíta od Googlu, takže Google vidí tvoju IP adresu a svoje vlastné cookies. Len ak sa rozhodneš prihlásiť cez Google, oznámi Google anotoki účtu identifikátor, e-mailovú adresu a meno tvojho účtu Google. Čo uchováva samotný Google, opisujú jeho vlastné zásady ochrany súkromia. Spoločnosť so sídlom v USA: údaje sa môžu spracúvať aj mimo EÚ.' AND updated_by IS NULL;

-- Its description, which only a migration writes: 020's, and only that.
UPDATE translation_keys SET description = 'Privacy page, who else handles data - Google, in the IAM''s own words over the library''s: nothing is loaded from Google until somebody presses the IAM''s own button (the sign-in and sign-up pages, the account page while Google is not connected), and that press loads Google''s own button. The IAM''s button says its own words (google.signIn, google.signUp, google.continue). No placeholders.'
 WHERE name = 'legal.google.role' AND description = 'Privacy page, who else handles data - Google, in the IAM''s own words over the library''s: its button is loaded from Google wherever it is drawn (the sign-in and sign-up pages, the account page while Google is not connected), whenever signing in with Google is switched on. The button''s own words are Google''s ("Sign in with Google"). No placeholders.';

-----------------------------------------------------------
-- PROVE IT
--
-- Every key this file writes has its English and its Slovak string. Only
-- these keys: the admin panel may blank a Slovak string elsewhere on
-- purpose, and the library adds keys of its own. And the Privacy page no
-- longer holds 020's sentence about Google in either language where nobody
-- saved it, nor its key 020's description: a string an administrator saved
-- is theirs - in 020's own words too -, one reworded holds other words, and
-- a blanked one none: all pass.
-----------------------------------------------------------

DO $$
DECLARE
    missing TEXT;
    left_over TEXT;
BEGIN
    SELECT string_agg(wanted.name, ', ' ORDER BY wanted.name) INTO missing
      FROM (VALUES ('google.signIn'), ('google.signUp'), ('google.continue'), ('google.frame'), ('google.ready'), ('google.failed')) AS wanted(name)
     WHERE NOT EXISTS (SELECT 1 FROM translations t WHERE t.key_name = wanted.name AND t.language_code = 'en')
        OR NOT EXISTS (SELECT 1 FROM translations t WHERE t.key_name = wanted.name AND t.language_code = 'sk');
    IF missing IS NOT NULL THEN
        RAISE EXCEPTION 'the Google button''s keys without an English and a Slovak string: %', missing;
    END IF;

    SELECT string_agg(t.language_code, ', ' ORDER BY t.language_code) INTO left_over
      FROM translations t
     WHERE t.key_name = 'legal.google.role' AND t.updated_by IS NULL
       AND ((t.language_code = 'en' AND t.value = 'Draws its "Sign in with Google" button on the sign-in and sign-up pages, and on the account page while Google is not connected, whenever signing in with Google is switched on: your browser loads the button from Google, so Google sees your IP address and its own cookies. Only if you choose to sign in with Google does Google tell the anotoki account your Google account’s id, e-mail address and name. What Google itself keeps, its own privacy policy says. A company based in the USA: data may be handled outside the EU.')
         OR (t.language_code = 'sk' AND t.value = 'Na stránkach prihlásenia a registrácie a na stránke účtu, kým Google nie je pripojený, zobrazuje svoje tlačidlo „Prihlásiť sa cez Google“, ak je prihlasovanie cez Google zapnuté: prehliadač ho načíta od Googlu, takže Google vidí tvoju IP adresu a svoje vlastné cookies. Len ak sa rozhodneš prihlásiť cez Google, oznámi Google anotoki účtu identifikátor, e-mailovú adresu a meno tvojho účtu Google. Čo uchováva samotný Google, opisujú jeho vlastné zásady ochrany súkromia. Spoločnosť so sídlom v USA: údaje sa môžu spracúvať aj mimo EÚ.'));
    IF left_over IS NOT NULL THEN
        RAISE EXCEPTION 'the Privacy page still says Google''s button is loaded wherever it is drawn: %', left_over;
    END IF;

    IF EXISTS (SELECT 1 FROM translation_keys WHERE name = 'legal.google.role' AND description LIKE '%its button is loaded from Google wherever it is drawn%') THEN
        RAISE EXCEPTION 'legal.google.role is still described as a button loaded from Google wherever it is drawn';
    END IF;
END $$;
