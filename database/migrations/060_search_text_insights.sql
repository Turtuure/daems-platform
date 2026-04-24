-- Migration 060: add search_text to insights + FULLTEXT index.
-- search_text is the stripped-tags version of content, populated by
-- SqlInsightRepository::save() at write time. Backfill with
-- a PHP one-off in step 3 below.

ALTER TABLE insights
  ADD COLUMN search_text MEDIUMTEXT NULL AFTER content,
  ADD FULLTEXT INDEX ft_title_body (title, search_text);
