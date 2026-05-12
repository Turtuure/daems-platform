-- 081_create_board_decision_votes.sql
-- One row per (decision, board_member). Re-vote allowed via UPSERT in the repo;
-- UNIQUE constraint enforces it.

CREATE TABLE IF NOT EXISTS board_decision_votes (
    id                CHAR(36)  NOT NULL,
    decision_id       CHAR(36)  NOT NULL,
    board_member_id   CHAR(36)  NOT NULL,
    vote              ENUM('yes','no','abstain') NOT NULL,
    cast_at           DATETIME  NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_vote (decision_id, board_member_id),
    KEY idx_decision (decision_id),
    CONSTRAINT fk_v_decision FOREIGN KEY (decision_id) REFERENCES board_decisions(id) ON DELETE CASCADE,
    CONSTRAINT fk_v_member FOREIGN KEY (board_member_id) REFERENCES board_members(id) ON DELETE RESTRICT
) ENGINE=InnoDB CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
