ALTER TABLE admin_application_dismissals
    MODIFY COLUMN app_type ENUM('member','supporter','project_proposal','forum_report') NOT NULL;
