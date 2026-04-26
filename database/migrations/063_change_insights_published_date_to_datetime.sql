-- Promote insights.published_date from DATE to DATETIME so editors can
-- schedule a precise publish moment (e.g. 2026-04-26 09:00:00) instead
-- of just a calendar day. Existing rows are auto-cast to 00:00:00.
ALTER TABLE insights MODIFY published_date DATETIME NULL;
