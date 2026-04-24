-- Migration 061: add first_post_search_text to forum_topics + FULLTEXT index.
-- first_post_search_text is synced by SqlForumRepository::savePost() when
-- the post being saved is the first-by-sort_order post in its topic.

ALTER TABLE forum_topics
  ADD COLUMN first_post_search_text MEDIUMTEXT NULL,
  ADD FULLTEXT INDEX ft_title_body (title, first_post_search_text);
