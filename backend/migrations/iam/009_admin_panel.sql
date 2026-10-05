-----------------------------------------------------------
-- "ADMIN PANEL"
--
-- Every anotoki site calls its admin area the same thing now: the admin panel
-- (the owner's decision, 2026-10-04). Two of the IAM's strings name it: the
-- entry in the person's menu that opens it (shell.admin) and the card on the
-- account overview that leads there (overview.adminTitle). 006 wrote both as
-- "Admin console", and in Slovak "Administrácia"; they become "Admin panel"
-- and "Administrátorský panel".
--
-- 006 is applied on installed IAMs, so this is an UPDATE - but only of a
-- string that still says what 006 wrote. One reworded in the admin panel's
-- Translations page is the owner's, and stays; so does a Slovak string the
-- owner blanked there (the panel deletes it: there is no row to update).
-- No key is added or taken away.
-----------------------------------------------------------

UPDATE translations SET value = 'Admin panel', updated_at = CURRENT_TIMESTAMP
 WHERE language_code = 'en' AND key_name IN ('shell.admin', 'overview.adminTitle') AND value = 'Admin console';

-- Slovak, informal ("ty") like the rest of the IAM - though a name has no verb to show it.
UPDATE translations SET value = 'Administrátorský panel', updated_at = CURRENT_TIMESTAMP
 WHERE language_code = 'sk' AND key_name IN ('shell.admin', 'overview.adminTitle') AND value = 'Administrácia';

-----------------------------------------------------------
-- PROVE IT
--
-- Neither key still holds 006's words in either language. A string reworded
-- in the admin panel holds other words, and a blanked one none: both pass.
-----------------------------------------------------------

DO $$
DECLARE
    left_over TEXT;
BEGIN
    SELECT string_agg(t.key_name || ' (' || t.language_code || ')', ', ' ORDER BY t.key_name, t.language_code) INTO left_over
      FROM translations t
     WHERE t.key_name IN ('shell.admin', 'overview.adminTitle')
       AND ((t.language_code = 'en' AND t.value = 'Admin console') OR (t.language_code = 'sk' AND t.value = 'Administrácia'));
    IF left_over IS NOT NULL THEN
        RAISE EXCEPTION 'still the admin console: %', left_over;
    END IF;
END $$;
