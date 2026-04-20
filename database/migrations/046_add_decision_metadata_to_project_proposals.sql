ALTER TABLE project_proposals
    ADD COLUMN decided_at    DATETIME NULL,
    ADD COLUMN decided_by    CHAR(36) NULL,
    ADD COLUMN decision_note TEXT     NULL;
