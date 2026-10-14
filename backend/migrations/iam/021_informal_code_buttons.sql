-----------------------------------------------------------
-- TWO SLOVAK BUTTONS IN THE INFORMAL "TY"
--
-- The IAM speaks Slovak informally ("ty"), as its strings have since 006, but
-- two buttons of the sign-in page asked for a code by email in the formal
-- plural: 006's login.useCode ("Radšej mi pošlite kód e-mailom") and 019's
-- login.paused.sendCode ("Pošlite mi kód e-mailom"), the same request on the
-- same page. They become "Radšej mi pošli kód e-mailom" and "Pošli mi kód
-- e-mailom" (round 3; no other Slovak string of the IAM's is formal).
--
-- Both files are applied on installed IAMs, so this is an UPDATE - but only of
-- a string that still says what its file wrote, and that no administrator
-- saved (updated_by). One saved on the admin panel's Translations page is the
-- owner's choice - reworded, or put back to its file's very words on purpose
-- - and stays, with its writer; so does one the owner blanked there (the
-- panel deletes it: there is no row to update). English is not touched, and
-- no key is added or taken away.
-----------------------------------------------------------

UPDATE translations SET value = 'Radšej mi pošli kód e-mailom', updated_at = CURRENT_TIMESTAMP
 WHERE language_code = 'sk' AND key_name = 'login.useCode' AND value = 'Radšej mi pošlite kód e-mailom' AND updated_by IS NULL;

UPDATE translations SET value = 'Pošli mi kód e-mailom', updated_at = CURRENT_TIMESTAMP
 WHERE language_code = 'sk' AND key_name = 'login.paused.sendCode' AND value = 'Pošlite mi kód e-mailom' AND updated_by IS NULL;

-----------------------------------------------------------
-- PROVE IT
--
-- Neither key still holds its formal words where nobody saved it. A string
-- an administrator saved is theirs - in its file's own words too -, one
-- reworded holds other words, and a blanked one none: all pass.
-----------------------------------------------------------

DO $$
DECLARE
    left_over TEXT;
BEGIN
    SELECT string_agg(t.key_name, ', ' ORDER BY t.key_name) INTO left_over
      FROM translations t
     WHERE t.language_code = 'sk' AND t.updated_by IS NULL
       AND ((t.key_name = 'login.useCode' AND t.value = 'Radšej mi pošlite kód e-mailom')
         OR (t.key_name = 'login.paused.sendCode' AND t.value = 'Pošlite mi kód e-mailom'));
    IF left_over IS NOT NULL THEN
        RAISE EXCEPTION 'still formal in Slovak: %', left_over;
    END IF;
END $$;
