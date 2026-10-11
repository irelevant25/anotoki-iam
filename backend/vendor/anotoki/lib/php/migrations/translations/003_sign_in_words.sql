-- anotoki_translations 003 - the words of signing in, under the anotoki library's namespace anotoki.signIn.
--
-- What the auth module's sign-in page says (@anotoki/lib/auth, <anotoki-auth-callback>: the site's address
-- the anotoki account sends the reader back to; <anotoki-sign-in-failure>, the screen of a sign-in that
-- came back without one) - "Signing you in…", the screen's heading, why a sign-in came back without one,
-- and its two buttons. anotoki-lib's php/resources/library-words.json holds them - the Angular half's
-- built-in words are made from the same file, and a test of the library holds this file to it.
--
-- As in 002: a key's description is the library's, and is written again whenever a library migration
-- carries it; a key's strings are the site's once it has any - written here only for a library key that
-- has no string at all. So a site that took a key over before this file ran keeps its words.
--
-- Released with anotoki-lib 0.4.0, and never edited once released: a change is a new file.

INSERT INTO translation_keys (name, description) VALUES
    ('anotoki.signIn.signingIn', 'The anotoki library''s sign-in page (the site''s address the anotoki account sends the reader back to): what it says for the moment the sign-in is being finished, before the page the reader was on comes back. No placeholders.'),
    ('anotoki.signIn.failedTitle', 'The anotoki library''s sign-in page: the heading over why a sign-in came back without one, could not start, or cannot be offered right now - the reason under it, then the buttons to try again and to go back. No placeholders.'),
    ('anotoki.signIn.notCompleted', 'The anotoki library''s sign-in page: said when the reader came back from their anotoki account without signing in - they went back from its page, or the account was turned away. Under it, the buttons to try again and to go back. No placeholders.'),
    ('anotoki.signIn.expired', 'The anotoki library''s sign-in page: said when a sign-in came back that did not start on this page or took too long (an old tab, a link opened twice). No placeholders.'),
    ('anotoki.signIn.storageUnavailable', 'The anotoki library''s sign-in page: said when the browser does not let the site keep anything (its storage blocked or switched off), so a sign-in cannot even start. No placeholders.'),
    ('anotoki.signIn.unavailable', 'The anotoki library''s sign-in page: said when the site cannot offer signing in right now (its server did not say where the anotoki accounts are, or they do not know the site). Under it, only the button to try again. No placeholders.'),
    ('anotoki.signIn.failed', 'The anotoki library''s sign-in page: said when a sign-in came back with any other failure. {code}: the failure''s short code ("server_error"), shown as it is, for whoever helps the reader. Placeholders: {code}.'),
    ('anotoki.signIn.retry', 'The anotoki library''s sign-in page: the button under what went wrong that starts the sign-in again. No placeholders.'),
    ('anotoki.signIn.back', 'The anotoki library''s sign-in page: the button under what went wrong that goes back to the page the sign-in started on, without signing in. No placeholders.')
ON CONFLICT (name) DO UPDATE SET description = EXCLUDED.description;

-- One statement for every language: the rows it writes do not hide each other from the test of "no
-- string at all", which sees the table as it was before the statement began.
INSERT INTO translations (key_name, language_code, value)
SELECT v.key_name, v.language_code, v.value
  FROM (VALUES
    ('anotoki.signIn.signingIn', 'en', 'Signing you in…'),
    ('anotoki.signIn.signingIn', 'sk', 'Prihlasujeme ťa…'),
    ('anotoki.signIn.failedTitle', 'en', 'Signing in did not work'),
    ('anotoki.signIn.failedTitle', 'sk', 'Prihlásenie sa nepodarilo'),
    ('anotoki.signIn.notCompleted', 'en', 'The sign-in was not completed.'),
    ('anotoki.signIn.notCompleted', 'sk', 'Prihlásenie sa nedokončilo.'),
    ('anotoki.signIn.expired', 'en', 'The sign-in took too long or did not start on this page. Try again.'),
    ('anotoki.signIn.expired', 'sk', 'Prihlásenie trvalo príliš dlho alebo sa nezačalo na tejto stránke. Skús to znova.'),
    ('anotoki.signIn.storageUnavailable', 'en', 'This browser does not let the site remember a sign-in. Allow the site''s storage, then try again.'),
    ('anotoki.signIn.storageUnavailable', 'sk', 'Tento prehliadač nedovolí stránke zapamätať si prihlásenie. Povoľ jej ukladanie údajov a skús to znova.'),
    ('anotoki.signIn.unavailable', 'en', 'Signing in is not available right now. Try again in a few minutes.'),
    ('anotoki.signIn.unavailable', 'sk', 'Prihlásenie teraz nie je dostupné. Skús to znova o pár minút.'),
    ('anotoki.signIn.failed', 'en', 'The sign-in failed ({code}).'),
    ('anotoki.signIn.failed', 'sk', 'Prihlásenie zlyhalo ({code}).'),
    ('anotoki.signIn.retry', 'en', 'Try again'),
    ('anotoki.signIn.retry', 'sk', 'Skúsiť znova'),
    ('anotoki.signIn.back', 'en', 'Back'),
    ('anotoki.signIn.back', 'sk', 'Späť')
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
        ('anotoki.signIn.signingIn'),
        ('anotoki.signIn.failedTitle'),
        ('anotoki.signIn.notCompleted'),
        ('anotoki.signIn.expired'),
        ('anotoki.signIn.storageUnavailable'),
        ('anotoki.signIn.unavailable'),
        ('anotoki.signIn.failed'),
        ('anotoki.signIn.retry'),
        ('anotoki.signIn.back')
      ) AS k(name)
     WHERE NOT EXISTS (SELECT 1 FROM translation_keys tk WHERE tk.name = k.name)
        OR NOT EXISTS (SELECT 1 FROM translations t WHERE t.key_name = k.name AND t.language_code = 'en');
    IF missing IS NOT NULL THEN
        RAISE EXCEPTION 'anotoki_translations 003: the library''s keys without an English string: %. A site that takes a library key over gives it its English.', missing;
    END IF;
END $$;

-- end of 003
