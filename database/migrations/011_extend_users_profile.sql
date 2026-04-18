ALTER TABLE users
    ADD COLUMN country          VARCHAR(100) NOT NULL DEFAULT '' AFTER role,
    ADD COLUMN address_street   VARCHAR(200) NOT NULL DEFAULT '' AFTER country,
    ADD COLUMN address_zip      VARCHAR(20)  NOT NULL DEFAULT '' AFTER address_street,
    ADD COLUMN address_city     VARCHAR(100) NOT NULL DEFAULT '' AFTER address_zip,
    ADD COLUMN address_country  VARCHAR(100) NOT NULL DEFAULT '' AFTER address_city,
    ADD COLUMN membership_type  VARCHAR(30)  NOT NULL DEFAULT 'individual' AFTER address_country,
    ADD COLUMN membership_status VARCHAR(30) NOT NULL DEFAULT 'active' AFTER membership_type,
    ADD COLUMN member_number    VARCHAR(30)  NULL DEFAULT NULL AFTER membership_status;
