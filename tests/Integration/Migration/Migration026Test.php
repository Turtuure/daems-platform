<?php

declare(strict_types=1);

namespace Daems\Tests\Integration\Migration;

use Daems\Tests\Integration\MigrationTestCase;

final class Migration026Test extends MigrationTestCase
{
    public function testTenantIdColumnAddedWithFk(): void
    {
        $this->runMigrationsUpTo(25);
        $this->pdo()->exec(
            "INSERT INTO insights (id, slug, title, category, category_label, published_date, author, excerpt, content) VALUES ('01958000-0000-7000-8000-00000000c002', 'test-insight', 'Test Insight', 'news', 'News', '2026-04-19', 'Author', 'Excerpt', 'Content')"
        );

        $this->runMigration('026_add_tenant_id_to_insights.sql');

        $this->assertContains('tenant_id', $this->columnsOf('insights'));
        $this->assertContains('fk_insights_tenant', $this->foreignKeysOf('insights'));

        $stmt = $this->pdo()->query("SELECT tenant_id FROM insights WHERE id = '01958000-0000-7000-8000-00000000c002'");
        $this->assertNotFalse($stmt);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        $this->assertIsArray($row);

        $stmt2 = $this->pdo()->query("SELECT id FROM tenants WHERE slug='daems'");
        $this->assertNotFalse($stmt2);
        $daemsId = (string) $stmt2->fetchColumn();
        $this->assertSame($daemsId, $row['tenant_id']);
    }

    public function testTenantIdIsNotNullAfterMigration(): void
    {
        $this->runMigrationsUpTo(25);
        $this->runMigration('026_add_tenant_id_to_insights.sql');

        $stmt = $this->pdo()->query("SHOW COLUMNS FROM insights LIKE 'tenant_id'");
        $this->assertNotFalse($stmt);
        $col = $stmt->fetch(\PDO::FETCH_ASSOC);
        $this->assertIsArray($col);
        $this->assertSame('NO', $col['Null']);
    }
}
