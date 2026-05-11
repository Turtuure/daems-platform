-- 084_create_member_sub_tier_awards.sql
-- Audit + active-marker for sub-tier honors awarded by board decision.
-- revoked_at NULL means the award is currently in effect.

CREATE TABLE IF NOT EXISTS member_sub_tier_awards (
    id                    CHAR(36)     NOT NULL,
    tenant_id             CHAR(36)     NOT NULL,
    user_id               CHAR(36)     NOT NULL,
    sub_tier_slug         VARCHAR(30)  NOT NULL,
    decision_id           CHAR(36)     NOT NULL,
    awarded_at            DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    revoked_at            DATETIME     NULL,
    revoke_decision_id    CHAR(36)     NULL,
    PRIMARY KEY (id),
    KEY idx_user_active (user_id, revoked_at),
    KEY idx_tenant (tenant_id),
    CONSTRAINT fk_award_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    CONSTRAINT fk_award_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE RESTRICT,
    CONSTRAINT fk_award_decision FOREIGN KEY (decision_id) REFERENCES board_decisions(id) ON DELETE RESTRICT
) ENGINE=InnoDB CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
