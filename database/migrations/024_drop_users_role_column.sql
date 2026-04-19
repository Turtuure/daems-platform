-- Migration 024: drop users.role column (role now lives in user_tenants per tenant)
-- Apply only AFTER all code has been updated to read user_tenants.role instead of users.role.

ALTER TABLE users DROP COLUMN role;
