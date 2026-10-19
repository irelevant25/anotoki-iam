-----------------------------------------------------------
-- CONFIRMATION LINKS SPENT WITH THE ADDRESS; A GOOGLE TOKEN GOOD ONCE
--
-- The security review of 2026-10-19 found three older holes in how a sign-in
-- is proved; the owner: "Fix all three". The code carries the fixes
-- (docs/decisions.md); this file carries what they need of the database, and
-- the words the confirmation page needs. The review of that change (d0e34fb)
-- tightened them before this file reached any live IAM, and so did the review
-- of that review's change (632a464); this file says so where it matters.
--
-- 1. A confirmation link left over - "send it again" mails a second, and the
--    first is never opened - stayed good for its 48 hours after the address
--    was confirmed by the other, and opened a session by the mailbox alone:
--    on an account that switched two-factor on meanwhile too, without the
--    code, in a session that said two factors had been asked. The code now
--    spends every confirmation link of an account once its address is
--    confirmed, by any way, when an administrator changes the address and
--    when two-factor is switched on; a link of an address confirmed already
--    opens no session; and a link notes the address it was mailed to (a
--    SHA-256 of it in its context) and confirms only that one. Here, the links
--    an address confirmed still holds from before this file - mailed by a
--    build from before it - are spent too, so that none is left anywhere; and
--    so is every live link that notes no address: mailed by a build from
--    before the note, it may have gone to an address its account has left
--    since (a build from before this file moved an address and spent
--    nothing), and nothing could tell. Somebody in the middle of signing up
--    presses "send it again". A link that notes its address, of an address
--    that waits, stays: it is what confirms it.
--
--    And the sessions such a link may have opened already: a session says
--    `mfa` - and every token minted under it `amr` email, otp, mfa - for as
--    long as it lives, up to 30 days. The live sessions of the confirmation
--    and reset links (`email_link`) that say `mfa` on an account with
--    two-factor on end here, with the refresh tokens issued under them, as
--    the IAM ends a session for a security change (`security_change`). A
--    reset's own session among them had its code asked: its owner signs in
--    once more, and nothing else is lost.
--
-- 2. A Google id token was good for the hour Google signs it for, as often as
--    it came. The code now refuses one more than five minutes old, and one
--    used here before: google_id_tokens_used remembers each, by the SHA-256 of
--    what Google signed (CHAR(64); nothing of the token itself), until the
--    token runs out; housekeeping sweeps the rest. From the moment the IAM
--    knows the account a token is for - the one the Google sign-in finds it
--    names, the one the account page connects it to - the token is held for
--    that account (`held_for_user`) - while it waits for a second factor or a
--    password, or is refused before anything changed - and then spent (NULL)
--    the moment it changes something: a session opened, an account made or
--    claimed, an identity attached or connected. Held, it is refused to
--    everything but that account: left free, a token waiting was anybody's who
--    saw it, to connect on the account page to an account of their own (the
--    reviews of d0e34fb and 632a464). The hold names the account until
--    housekeeping sweeps the row - its first run after the token ran out, with
--    traffic -; an account deleted meanwhile leaves it spent, and so does a
--    row of `users` deleted outright (ON DELETE SET NULL - the IAM itself never
--    deletes one). Before this file is applied a token is held to its five
--    minutes alone.
--
-- 3. The confirmation page sent the link as soon as it opened, and a link of
--    somebody else's account signed this browser in to it, ending the session
--    it had. The page now asks for a press first, and the server never puts a
--    confirmed account's session in place of another account's this browser
--    has. Seven page keys are its new words: the line above the button and the
--    button; the line above the code field, for an account with two-factor
--    on; the line under "Your address is confirmed" when the link signed
--    nobody in, and when this browser stays signed in to another account,
--    with its button; and, when the link signed in, which account that is -
--    so somebody who pressed a stranger's link sees whose account it is
--    before a site's waiting sign-in takes it. Their English (en.ts has the
--    same) and an informal Slovak ("ty"), in 006's form - every insert steps
--    aside for a row that is already there.
-----------------------------------------------------------

CREATE TABLE IF NOT EXISTS google_id_tokens_used (
    token_hash      CHAR(64)        PRIMARY KEY,
    expires_at      TIMESTAMPTZ     NOT NULL,
    held_for_user   INT             REFERENCES users(id) ON DELETE SET NULL
);

-- The engine never runs a recorded file again. A database that ran this
-- file's first version (d0e34fb's - never a live IAM's) gets the hold by
-- deleting this file's row from `migrations` and applying again: every
-- statement here is safe to run twice.
ALTER TABLE google_id_tokens_used ADD COLUMN IF NOT EXISTS held_for_user INT REFERENCES users(id) ON DELETE SET NULL;

-- The links an address confirmed still holds from before, and every live link
-- that notes no address: spent.
UPDATE user_tokens SET consumed_at = CURRENT_TIMESTAMP
 WHERE purpose = 'email_confirm' AND consumed_at IS NULL
   AND (user_id IN (SELECT id FROM users WHERE email_confirmed) OR context IS NULL OR context ->> 'address' IS NULL);

-- The live sessions a link opened saying `mfa` on an account with two-factor
-- on, and the refresh tokens issued under them: ended for a security change.
WITH ended AS (
    UPDATE sessions s SET revoked_at = CURRENT_TIMESTAMP, revoked_reason = 'security_change'
      FROM users u
     WHERE u.id = s.user_id AND u.totp_enabled
       AND s.method = 'email_link' AND s.mfa
       AND s.revoked_at IS NULL AND s.expires_at > CURRENT_TIMESTAMP AND s.absolute_expires_at > CURRENT_TIMESTAMP
    RETURNING s.id
)
UPDATE refresh_tokens SET revoked_at = CURRENT_TIMESTAMP, revoked_reason = 'security_change'
 WHERE kind = 'rotating' AND revoked_at IS NULL AND session_id IN (SELECT id FROM ended);

INSERT INTO translation_keys (name, description) VALUES
    ('confirm.readyText', 'Confirm an address: the line above the button on the page a confirmation link opens. Nothing is confirmed, and nobody signed in, until the button is pressed. No placeholders.'),
    ('confirm.submit', 'Confirm an address: the button that confirms the address - and signs in, unless this browser is signed in to another account. No placeholders.'),
    ('confirm.totpText', 'Confirm an address: the line above the code field, when the account has two-factor authentication on - the link alone does not sign in to it, as a password reset''s does not. No placeholders.'),
    ('confirm.signInText', 'Confirm an address: under "Your address is confirmed" when the link signed nobody in - the address was confirmed before (an older link, or one used already), or the account''s own sign-in is needed -, above the Sign in button. No placeholders.'),
    ('confirm.otherAccountText', 'Confirm an address: under "Your address is confirmed" when this browser was signed in to another account, which stays signed in - opening the link signs nobody out. No placeholders.'),
    ('confirm.signOutAndIn', 'Confirm an address: the button under confirm.otherAccountText, which signs this browser out of the other account and opens the sign-in page. No placeholders.'),
    ('confirm.signedInAs', 'Confirm an address: under "Your address is confirmed" when the link signed this browser in - the account it is signed in to now, so that somebody who pressed a stranger''s link sees whose account it is. {name}: the account''s username; {email}: its address - keep both. Placeholders: {name}, {email}.')
ON CONFLICT (name) DO NOTHING;

INSERT INTO translations (key_name, language_code, value)
SELECT v.key_name, 'en', v.value FROM (VALUES
    ('confirm.readyText', 'Press the button to confirm your email address.'),
    ('confirm.submit', 'Confirm my address'),
    ('confirm.totpText', 'Two-factor authentication is on for this account, so signing in with this link needs it too: the code your authenticator app is showing, or one of your recovery codes.'),
    ('confirm.signInText', 'Sign in to use your account.'),
    ('confirm.otherAccountText', 'This browser is signed in to another account, and stays so. To use the account whose address you have just confirmed, sign out and sign in to it.'),
    ('confirm.signOutAndIn', 'Sign out and sign in'),
    ('confirm.signedInAs', 'Signed in as {name} ({email}).')
) AS v(key_name, value)
ON CONFLICT (key_name, language_code) DO NOTHING;

-- Slovak, informal.
INSERT INTO translations (key_name, language_code, value)
SELECT v.key_name, 'sk', v.value FROM (VALUES
    ('confirm.readyText', 'Stlač tlačidlo a potvrď svoju e-mailovú adresu.'),
    ('confirm.submit', 'Potvrdiť adresu'),
    ('confirm.totpText', 'Tento účet má zapnuté dvojfaktorové overenie, a to platí aj pri prihlásení týmto odkazom: zadaj kód, ktorý ukazuje tvoja overovacia aplikácia, alebo jeden z obnovovacích kódov.'),
    ('confirm.signInText', 'Na používanie účtu sa prihlás.'),
    ('confirm.otherAccountText', 'Tento prehliadač je prihlásený k inému účtu a tak aj ostane. Ak chceš používať účet s práve potvrdenou adresou, odhlás sa a prihlás sa doň.'),
    ('confirm.signOutAndIn', 'Odhlásiť sa a prihlásiť'),
    ('confirm.signedInAs', 'Prihlásený účet: {name} ({email}).')
) AS v(key_name, value)
ON CONFLICT (key_name, language_code) DO NOTHING;

-----------------------------------------------------------
-- PROVE IT
--
-- The table is there with its hold; no confirmation link of an address
-- confirmed, nor one that notes no address, is left unspent; no live session a
-- link opened says `mfa` on an account with two-factor on; and every key this
-- file writes has its English and its Slovak string. Only these keys: the
-- admin panel may blank a Slovak string elsewhere on purpose, and the library
-- adds keys of its own.
-----------------------------------------------------------

DO $$
DECLARE
    missing TEXT;
    left_live INT;
    left_unnoted INT;
    still_mfa INT;
BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_attribute WHERE attrelid = to_regclass('google_id_tokens_used') AND attname = 'held_for_user' AND NOT attisdropped) THEN
        RAISE EXCEPTION 'google_id_tokens_used was not made with its hold (held_for_user)';
    END IF;

    SELECT count(*) INTO left_live
      FROM user_tokens t JOIN users u ON u.id = t.user_id
     WHERE t.purpose = 'email_confirm' AND t.consumed_at IS NULL AND u.email_confirmed;
    IF left_live > 0 THEN
        RAISE EXCEPTION 'a confirmation link of an address confirmed is still unspent: % of them', left_live;
    END IF;

    SELECT count(*) INTO left_unnoted
      FROM user_tokens
     WHERE purpose = 'email_confirm' AND consumed_at IS NULL AND (context IS NULL OR context ->> 'address' IS NULL);
    IF left_unnoted > 0 THEN
        RAISE EXCEPTION 'a confirmation link that notes no address is still unspent: % of them', left_unnoted;
    END IF;

    SELECT count(*) INTO still_mfa
      FROM sessions s JOIN users u ON u.id = s.user_id
     WHERE u.totp_enabled AND s.method = 'email_link' AND s.mfa
       AND s.revoked_at IS NULL AND s.expires_at > CURRENT_TIMESTAMP AND s.absolute_expires_at > CURRENT_TIMESTAMP;
    IF still_mfa > 0 THEN
        RAISE EXCEPTION 'a session a link opened still says mfa on an account with two-factor on: % of them', still_mfa;
    END IF;

    SELECT string_agg(wanted.name, ', ' ORDER BY wanted.name) INTO missing
      FROM (VALUES ('confirm.readyText'), ('confirm.submit'), ('confirm.totpText'), ('confirm.signInText'), ('confirm.otherAccountText'), ('confirm.signOutAndIn'), ('confirm.signedInAs')) AS wanted(name)
     WHERE NOT EXISTS (SELECT 1 FROM translation_keys k WHERE k.name = wanted.name)
        OR NOT EXISTS (SELECT 1 FROM translations t WHERE t.key_name = wanted.name AND t.language_code = 'en')
        OR NOT EXISTS (SELECT 1 FROM translations t WHERE t.key_name = wanted.name AND t.language_code = 'sk');
    IF missing IS NOT NULL THEN
        RAISE EXCEPTION 'the confirmation page''s keys without an English and a Slovak string: %', missing;
    END IF;
END $$;
