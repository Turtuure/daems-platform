-- 083_create_member_expulsions.sql
-- Rich aggregate for the bylaws § 4 expulsion process: hearing → statement →
-- decision → expelled → optional appeal.

CREATE TABLE IF NOT EXISTS member_expulsions (
    id                       CHAR(36)  NOT NULL,
    tenant_id                CHAR(36)  NOT NULL,
    target_user_id           CHAR(36)  NOT NULL,
    proposed_by_user_id      CHAR(36)  NOT NULL,
    reason                   TEXT      NOT NULL,
    hearing_deadline_at      DATETIME  NOT NULL,
    statement_text           TEXT      NULL,
    statement_received_at    DATETIME  NULL,
    decision_id              CHAR(36)  NULL,
    decided_at               DATETIME  NULL,
    expelled_at              DATETIME  NULL,
    appeal_filed_at          DATETIME  NULL,
    appeal_text              TEXT      NULL,
    status                   ENUM('hearing','awaiting_vote','expelled','rejected','appealed') NOT NULL DEFAULT 'hearing',
    created_at               DATETIME  NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_tenant_status (tenant_id, status),
    KEY idx_target (target_user_id),
    CONSTRAINT fk_exp_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    CONSTRAINT fk_exp_user FOREIGN KEY (target_user_id) REFERENCES users(id) ON DELETE RESTRICT,
    CONSTRAINT fk_exp_decision FOREIGN KEY (decision_id) REFERENCES board_decisions(id) ON DELETE RESTRICT
) ENGINE=InnoDB CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
