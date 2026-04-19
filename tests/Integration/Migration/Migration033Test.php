<?php

declare(strict_types=1);

namespace Daems\Tests\Integration\Migration;

use Daems\Tests\Integration\MigrationTestCase;

final class Migration033Test extends MigrationTestCase
{
    public function testTenantIdColumnAddedWithFk(): void
    {
        $this->runMigrationsUpTo(32);

        $daemsId = (string) $this->pdo()->query("SELECT id FROM tenants WHERE slug='daems'")->fetchColumn();
        $this->pdo()->exec(
            "INSERT INTO users (id, name, email, password_hash, date_of_birth) VALUES ('01958000-0000-7000-8000-00000000u003', 'Admin User', 'admin@example.com', 'hash', '1985-06-15')"
        );
        $this->pdo()->exec(
            "INSERT INTO member_applications (id, tenant_id, name, email, date_of_birth, motivation) VALUES ('01958000-0000-7000-8000-00000000a001', '{$daemsId}', 'Applicant', 'applicant@example.com', '1995-03-10', 'Motivation text')"
        );

        $this->runMigration('033_add_tenant_id_to_member_register_audit.sql');

        $this->assertContains('tenant_id', $this->columnsOf('member_register_audit'));
        $this->assertContains('fk_member_register_audit_tenant', $this->foreignKeysOf('member_register_audit'));

        $this->pdo()->exec(
            "INSERT INTO member_register_audit (id, tenant_id, application_id, action, performed_by, created_at) VALUES ('01958000-0000-7000-8000-00000000c040', '{$daemsId}', '01958000-0000-7000-8000-00000000a001', 'approved', '01958000-0000-7000-8000-00000000u003', '2026-04-19 10:00:00')"
        );

        $stmt = $this->pdo()->query("SELECT tenant_id FROM member_register_audit WHERE id = '01958000-0000-7000-8000-00000000c040'");
        $this->assertNotFalse($stmt);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        $this->assertIsArray($row);
        $this->assertSame($daemsId, $row['tenant_id']);
    }

    public function testTenantIdIsNotNullAfterMigration(): void
    {
        $this->runMigrationsUpTo(32);
        $this->runMigration('033_add_tenant_id_to_member_register_audit.sql');

        $stmt = $this->pdo()->query("SHOW COLUMNS FROM member_register_audit LIKE 'tenant_id'");
        $this->assertNotFalse($stmt);
        $col = $stmt->fetch(\PDO::FETCH_ASSOC);
        $this->assertIsArray($col);
        $this->assertSame('NO', $col['Null']);
    }
}
