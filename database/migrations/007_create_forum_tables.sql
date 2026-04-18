CREATE TABLE IF NOT EXISTS forum_categories (
    id          CHAR(36)     NOT NULL,
    slug        VARCHAR(120) NOT NULL,
    name        VARCHAR(120) NOT NULL,
    icon        VARCHAR(60)  NOT NULL DEFAULT '',
    description TEXT         NOT NULL,
    sort_order  SMALLINT     NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    UNIQUE KEY uq_forum_categories_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS forum_topics (
    id               CHAR(36)     NOT NULL,
    category_id      CHAR(36)     NOT NULL,
    slug             VARCHAR(160) NOT NULL,
    title            VARCHAR(255) NOT NULL,
    author_name      VARCHAR(120) NOT NULL,
    avatar_initials  VARCHAR(4)   NOT NULL DEFAULT '',
    avatar_color     VARCHAR(20)  NULL,
    pinned           TINYINT(1)   NOT NULL DEFAULT 0,
    reply_count      INT          NOT NULL DEFAULT 0,
    view_count       INT          NOT NULL DEFAULT 0,
    last_activity_at DATETIME     NOT NULL,
    last_activity_by VARCHAR(120) NOT NULL DEFAULT '',
    created_at       DATETIME     NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_forum_topics_slug (slug),
    KEY idx_forum_topics_category (category_id),
    CONSTRAINT fk_forum_topics_category FOREIGN KEY (category_id) REFERENCES forum_categories (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS forum_posts (
    id              CHAR(36)     NOT NULL,
    topic_id        CHAR(36)     NOT NULL,
    author_name     VARCHAR(120) NOT NULL,
    avatar_initials VARCHAR(4)   NOT NULL DEFAULT '',
    avatar_color    VARCHAR(20)  NULL,
    role            VARCHAR(60)  NOT NULL DEFAULT '',
    role_class      VARCHAR(60)  NOT NULL DEFAULT '',
    joined_text     VARCHAR(60)  NOT NULL DEFAULT '',
    content         TEXT         NOT NULL,
    likes           INT          NOT NULL DEFAULT 0,
    created_at      DATETIME     NOT NULL,
    sort_order      INT          NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    KEY idx_forum_posts_topic (topic_id),
    CONSTRAINT fk_forum_posts_topic FOREIGN KEY (topic_id) REFERENCES forum_topics (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
