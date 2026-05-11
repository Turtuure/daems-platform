-- 082_create_board_delegations.sql
-- Standing delegations granted by a DelegateAuthority decision. Whitelist of types.

CREATE TABLE IF NOT EXISTS board_delegations (
    id                   CHAR(36)     NOT NULL,
    tenant_id            CHAR(36)     NOT NULL,
    decision_type        ENUM('approve_basic','invite_full','award_subtier') NOT NULL,
    delegated_to_role    ENUM('admin') NOT NULL,
    source_decision_id   CHAR(36)     NOT NULL,
    valid_from           DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    revoked_at           DATETIME     NULL,
    PRIMARY KEY (id),
    KEY idx_active (tenant_id, decision_type, delegated_to_role, revoked_at),
    CONSTRAINT fk_deleg_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    CONSTRAINT fk_deleg_source FOREIGN KEY (source_decision_id) REFERENCES board_decisions(id) ON DELETE RESTRICT
) ENGINE=InnoDB CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
