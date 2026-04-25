-- Allow insights.published_date to be NULL so the backstage editor can
-- save unpublished drafts. Existing rows are unaffected (the column was
-- NOT NULL with no DEFAULT, so every row already has a real date).
ALTER TABLE insights MODIFY published_date DATE NULL;
