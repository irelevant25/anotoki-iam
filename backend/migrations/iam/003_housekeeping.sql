-----------------------------------------------------------
-- WHAT MAY BE SWEPT AWAY
--
-- Some rows are only ever worth anything for minutes: an authorization request
-- lives half an hour, a code sixty seconds, and every refresh leaves the token
-- it replaced behind - about 144 a day for one person using one site all day.
-- The host has no cron, so api/housekeeping.php sweeps them from the busy
-- endpoints, at most once an hour.
--
-- A refresh token points at its successor (`replaced_by`), so sweeping an old
-- chain would trip over the foreign key the moment a successor went before its
-- predecessor. SET NULL lets any row of a chain go without the rest.
-----------------------------------------------------------

ALTER TABLE refresh_tokens DROP CONSTRAINT IF EXISTS refresh_tokens_replaced_by_fkey;
ALTER TABLE refresh_tokens ADD CONSTRAINT refresh_tokens_replaced_by_fkey
    FOREIGN KEY (replaced_by) REFERENCES refresh_tokens(id) ON DELETE SET NULL;

CREATE INDEX IF NOT EXISTS auth_codes_expires ON auth_codes (expires_at);
CREATE INDEX IF NOT EXISTS refresh_tokens_expires ON refresh_tokens (expires_at);
CREATE INDEX IF NOT EXISTS user_tokens_expires ON user_tokens (expires_at);

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_constraint
         WHERE conname = 'refresh_tokens_replaced_by_fkey' AND confdeltype = 'n'
    ) THEN
        RAISE EXCEPTION 'refresh_tokens.replaced_by should be ON DELETE SET NULL';
    END IF;
END $$;
