<?php

declare(strict_types=1);

namespace Daems\Tests\Integration\Migration;

use Daems\Tests\Integration\MigrationTestCase;

final class Migration031Test extends MigrationTestCase
{
    private function seedPrerequisites(): void
    {
        $this->pdo()->exec(
            "INSERT INTO users (id, name, email, password_hash, date_of_birth) VALUES ('01958000-0000-7000-8000-00000000u001', 'Test User', 'user@example.com', 'hash', '1990-01-01')"
        );
        $daemsId = (string) $this->pdo()->query("SELECT id FROM tenants WHERE slug='daems'")->fetchColumn();
        $this->pdo()->exec(
            "INSERT INTO projects (id, tenant_id, slug, title, category, summary, description) VALUES ('01958000-0000-7000-8000-00000000p001', '{$daemsId}', 'test-proj', 'Test Project', 'tech', 'Summary', 'Description')"
        );
    }

    public function testTenantIdColumnAddedToProjectComments(): void
    {
        $this->runMigrationsUpTo(30);
        $this->seedPrerequisites();
        $this->pdo()->exec(
            "INSERT INTO project_comments (id, project_id, user_id, author_name, content) VALUES ('01958000-0000-7000-8000-00000000c020', '01958000-0000-7000-8000-00000000p001', '01958000-0000-7000-8000-00000000u001', 'Author', 'Comment content')"
        );

        $this->runMigration('031_add_tenant_id_to_project_extras.sql');

        $this->assertContains('tenant_id', $this->columnsOf('project_comments'));
        $this->assertContains('fk_project_comments_tenant', $this->foreignKeysOf('project_comments'));

        $stmt = $this->pdo()->query("SELECT tenant_id FROM project_comments WHERE id = '01958000-0000-7000-8000-00000000c020'");
        $this->assertNotFalse($stmt);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        $this->assertIsArray($row);

        $stmt2 = $this->pdo()->query("SELECT id FROM tenants WHERE slug='daems'");
        $this->assertNotFalse($stmt2);
        $daemsId = (string) $stmt2->fetchColumn();
        $this->assertSame($daemsId, $row['tenant_id']);
    }

    public function testTenantIdColumnAddedToProjectUpdates(): void
    {
        $this->runMigrationsUpTo(30);
        $this->seedPrerequisites();
        $this->pdo()->exec(
            "INSERT INTO project_updates (id, project_id, title, content, author_name) VALUES ('01958000-0000-7000-8000-00000000c021', '01958000-0000-7000-8000-00000000p001', 'Update Title', 'Update content', 'Author')"
        );

        $this->runMigration('031_add_tenant_id_to_project_extras.sql');

        $this->assertContains('tenant_id', $this->columnsOf('project_updates'));
        $this->assertContains('fk_project_updates_tenant', $this->foreignKeysOf('project_updates'));

        $stmt = $this->pdo()->query("SELECT tenant_id FROM project_updates WHERE id = '01958000-0000-7000-8000-00000000c021'");
        $this->assertNotFalse($stmt);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        $this->assertIsArray($row);

        $stmt2 = $this->pdo()->query("SELECT id FROM tenants WHERE slug='daems'");
        $this->assertNotFalse($stmt2);
        $daemsId = (string) $stmt2->fetchColumn();
        $this->assertSame($daemsId, $row['tenant_id']);
    }

    public function testTenantIdColumnAddedToProjectParticipants(): void
    {
        $this->runMigrationsUpTo(30);
        $this->seedPrerequisites();
        $this->pdo()->exec(
            "INSERT INTO project_participants (id, project_id, user_id) VALUES ('01958000-0000-7000-8000-00000000c022', '01958000-0000-7000-8000-00000000p001', '01958000-0000-7000-8000-00000000u001')"
        );

        $this->runMigration('031_add_tenant_id_to_project_extras.sql');

        $this->assertContains('tenant_id', $this->columnsOf('project_participants'));
        $this->assertContains('fk_project_participants_tenant', $this->foreignKeysOf('project_participants'));

        $stmt = $this->pdo()->query("SELECT tenant_id FROM project_participants WHERE id = '01958000-0000-7000-8000-00000000c022'");
        $this->assertNotFalse($stmt);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        $this->assertIsArray($row);

        $stmt2 = $this->pdo()->query("SELECT id FROM tenants WHERE slug='daems'");
        $this->assertNotFalse($stmt2);
        $daemsId = (string) $stmt2->fetchColumn();
        $this->assertSame($daemsId, $row['tenant_id']);
    }

    public function testTenantIdColumnAddedToProjectProposals(): void
    {
        $this->runMigrationsUpTo(30);
        $this->seedPrerequisites();
        $this->pdo()->exec(
            "INSERT INTO project_proposals (id, user_id, author_name, author_email, title, category, summary, description) VALUES ('01958000-0000-7000-8000-00000000c023', '01958000-0000-7000-8000-00000000u001', 'Author', 'author@example.com', 'Proposal Title', 'tech', 'Summary here', 'Full description')"
        );

        $this->runMigration('031_add_tenant_id_to_project_extras.sql');

        $this->assertContains('tenant_id', $this->columnsOf('project_proposals'));
        $this->assertContains('fk_project_proposals_tenant', $this->foreignKeysOf('project_proposals'));

        $stmt = $this->pdo()->query("SELECT tenant_id FROM project_proposals WHERE id = '01958000-0000-7000-8000-00000000c023'");
        $this->assertNotFalse($stmt);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        $this->assertIsArray($row);

        $stmt2 = $this->pdo()->query("SELECT id FROM tenants WHERE slug='daems'");
        $this->assertNotFalse($stmt2);
        $daemsId = (string) $stmt2->fetchColumn();
        $this->assertSame($daemsId, $row['tenant_id']);
    }

    public function testTenantIdIsNotNullInAllProjectExtrasAfterMigration(): void
    {
        $this->runMigrationsUpTo(30);
        $this->runMigration('031_add_tenant_id_to_project_extras.sql');

        foreach (['project_comments', 'project_updates', 'project_participants', 'project_proposals'] as $table) {
            $stmt = $this->pdo()->query("SHOW COLUMNS FROM {$table} LIKE 'tenant_id'");
            $this->assertNotFalse($stmt);
            $col = $stmt->fetch(\PDO::FETCH_ASSOC);
            $this->assertIsArray($col, "Expected column metadata for {$table}");
            $this->assertSame('NO', $col['Null'], "tenant_id should be NOT NULL in {$table}");
        }
    }
}
