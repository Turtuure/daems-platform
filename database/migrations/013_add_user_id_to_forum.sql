ALTER TABLE forum_posts
    ADD COLUMN user_id CHAR(36) COLLATE utf8mb4_unicode_ci NULL DEFAULT NULL AFTER topic_id;

ALTER TABLE forum_topics
    ADD COLUMN user_id CHAR(36) COLLATE utf8mb4_unicode_ci NULL DEFAULT NULL AFTER category_id;
