-----------------------------------------------------------
-- AN AUTHENTICATOR CODE WORKS ONCE
--
-- A code is valid for its 30-second step and one either side of it, so for
-- about ninety seconds. Without a memory of what was accepted, a code read
-- over somebody's shoulder, or caught on its way, could be used again within
-- that time. `totp_last_step` is the step of the last code accepted for the
-- account; a code from that step or an earlier one is refused, and it is set
-- by a conditional UPDATE, so of two requests carrying the same code only one
-- gets in. Recovery codes were single-use already.
-----------------------------------------------------------

ALTER TABLE users ADD COLUMN IF NOT EXISTS totp_last_step BIGINT;

-----------------------------------------------------------
-- A SPENT REFRESH TOKEN HAS ONE SIBLING AT MOST
--
-- A rotating refresh token used again within the leeway (a minute) is taken
-- for another tab of the same browser that lost the race, and gets a successor
-- of its own rather than a sign-out. Every such use used to get one, so a copy
-- replayed inside that minute started a chain of its own that reuse detection
-- never saw. `sibling_issued_at` records the one sibling a spent token may
-- have; a further use is reuse, and ends the family.
-----------------------------------------------------------

ALTER TABLE refresh_tokens ADD COLUMN IF NOT EXISTS sibling_issued_at TIMESTAMPTZ;

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns
         WHERE table_name = 'users' AND column_name = 'totp_last_step' AND data_type = 'bigint'
    ) THEN
        RAISE EXCEPTION 'users.totp_last_step should exist as BIGINT';
    END IF;
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns
         WHERE table_name = 'refresh_tokens' AND column_name = 'sibling_issued_at'
    ) THEN
        RAISE EXCEPTION 'refresh_tokens.sibling_issued_at should exist';
    END IF;
END $$;
