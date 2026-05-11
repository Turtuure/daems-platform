-- 079_create_board_members.sql
-- Board members across history. term_ended_at IS NULL for active seats.

CREATE TABLE IF NOT EXISTS board_members (
    id                  CHAR(36)     NOT NULL,
    board_id            CHAR(36)     NOT NULL,
    user_id             CHAR(36)     NOT NULL,
    role                ENUM('chair','member') NOT NULL,
    term_started_at     DATETIME     NOT NULL,
    term_ends_at        DATETIME     NOT NULL,
    term_ended_at       DATETIME     NULL,
    term_ended_reason   ENUM('resigned','removed','lost_full_status','term_expired') NULL,
    created_at          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_board (board_id),
    KEY idx_user (user_id),
    KEY idx_active (board_id, term_ended_at, term_ends_at),
    CONSTRAINT fk_bm_board FOREIGN KEY (board_id) REFERENCES boards(id) ON DELETE CASCADE,
    CONSTRAINT fk_bm_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
