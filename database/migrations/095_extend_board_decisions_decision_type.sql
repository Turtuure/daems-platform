-- 095_extend_board_decisions_decision_type.sql
-- Adds 'annual_fee_schedule' to the board_decisions.decision_type enum so the
-- 0.7 MembershipBilling DraftAnnualFeeSchedule use case can route through the
-- formal hallitus-päätös flow when tenant_governance_settings.requires_formal_decision_for_fees=1.

ALTER TABLE board_decisions
    MODIFY COLUMN decision_type ENUM(
        'approve_basic','invite_full','expel',
        'award_subtier','revoke_subtier','subtier_crud',
        'remove_board_member','delegate_authority','revoke_delegation',
        'annual_fee_schedule'
    ) NOT NULL;
