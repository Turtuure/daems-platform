-- Project participants
CREATE TABLE IF NOT EXISTS project_participants (
    id         CHAR(36)     COLLATE utf8mb4_unicode_ci NOT NULL PRIMARY KEY,
    project_id CHAR(36)     COLLATE utf8mb4_unicode_ci NOT NULL,
    user_id    CHAR(36)     COLLATE utf8mb4_unicode_ci NOT NULL,
    joined_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_project_user (project_id, user_id),
    CONSTRAINT fk_pp_project FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
    CONSTRAINT fk_pp_user    FOREIGN KEY (user_id)    REFERENCES users(id)    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Project comments
CREATE TABLE IF NOT EXISTS project_comments (
    id               CHAR(36)     COLLATE utf8mb4_unicode_ci NOT NULL PRIMARY KEY,
    project_id       CHAR(36)     COLLATE utf8mb4_unicode_ci NOT NULL,
    user_id          CHAR(36)     COLLATE utf8mb4_unicode_ci NOT NULL,
    author_name      VARCHAR(100) NOT NULL,
    avatar_initials  VARCHAR(4)   NOT NULL DEFAULT '',
    avatar_color     VARCHAR(20)  NOT NULL DEFAULT '',
    content          TEXT         NOT NULL,
    likes            INT UNSIGNED NOT NULL DEFAULT 0,
    created_at       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_pc_project FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
    CONSTRAINT fk_pc_user    FOREIGN KEY (user_id)    REFERENCES users(id)    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Project updates (mini-blog)
CREATE TABLE IF NOT EXISTS project_updates (
    id          CHAR(36)     COLLATE utf8mb4_unicode_ci NOT NULL PRIMARY KEY,
    project_id  CHAR(36)     COLLATE utf8mb4_unicode_ci NOT NULL,
    title       VARCHAR(200) NOT NULL,
    content     TEXT         NOT NULL,
    author_name VARCHAR(100) NOT NULL,
    created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_pu_project FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
