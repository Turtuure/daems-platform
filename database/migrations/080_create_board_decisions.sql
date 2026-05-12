-- 080_create_board_decisions.sql
-- Lifecycle row for every board decision. Typed payload columns instead of JSON
-- so PHPStan level 9 stays happy and indexing on payload_target_user_id works.

CREATE TABLE IF NOT EXISTS board_decisions (
    id                          CHAR(36)     NOT NULL,
    board_id                    CHAR(36)     NOT NULL,
    decision_type               ENUM(
        'approve_basic','invite_full','expel',
        'award_subtier','revoke_subtier','subtier_crud',
        'remove_board_member','delegate_authority','revoke_delegation'
    ) NOT NULL,
    threshold                   ENUM('unanimous','majority') NOT NULL,
    mode                        ENUM('async','sync') NOT NULL,
    vote_visibility             ENUM('visible','anonymous') NOT NULL,
    status                      ENUM('pending','passed','rejected','expired','withdrawn') NOT NULL DEFAULT 'pending',
    proposed_by_user_id         CHAR(36)     NOT NULL,
    proposed_at                 DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expires_at                  DATETIME     NOT NULL,
    resolved_at                 DATETIME     NULL,
    meeting_reference           VARCHAR(255) NULL,
    withdrawal_reason           TEXT         NULL,
    via_delegation              TINYINT(1)   NOT NULL DEFAULT 0,
    delegation_id               CHAR(36)     NULL,

    payload_target_user_id      CHAR(36)     NULL,
    payload_application_id      CHAR(36)     NULL,
    payload_sub_tier_slug       VARCHAR(30)  NULL,
    payload_sub_tier_name       VARCHAR(80)  NULL,
    payload_sub_tier_rank       INT          NULL,
    payload_sub_tier_applies_to ENUM('SUPPORTING','BASIC') NULL,
    payload_sub_tier_operation  ENUM('create','update','delete') NULL,
    payload_board_member_id     CHAR(36)     NULL,
    payload_delegation_type     VARCHAR(40)  NULL,
    payload_delegated_to_role   VARCHAR(40)  NULL,
    payload_delegation_revoke_id CHAR(36)    NULL,
    payload_reason              TEXT         NULL,

    PRIMARY KEY (id),
    KEY idx_board_status (board_id, status),
    KEY idx_type (decision_type),
    KEY idx_expires (status, expires_at),
    CONSTRAINT fk_bd_board FOREIGN KEY (board_id) REFERENCES boards(id) ON DELETE CASCADE,
    CONSTRAINT fk_bd_proposer FOREIGN KEY (proposed_by_user_id) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
