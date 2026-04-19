<?php

declare(strict_types=1);

namespace Daems\Tests\Integration\Migration;

use Daems\Tests\Integration\MigrationTestCase;

final class Migration030Test extends MigrationTestCase
{
    public function testTenantIdColumnAddedToForumCategories(): void
    {
        $this->runMigrationsUpTo(29);
        $this->pdo()->exec(
            "INSERT INTO forum_categories (id, slug, name, description) VALUES ('01958000-0000-7000-8000-00000000c010', 'general', 'General', 'General discussion')"
        );

        $this->runMigration('030_add_tenant_id_to_forum_tables.sql');

        $this->assertContains('tenant_id', $this->columnsOf('forum_categories'));
        $this->assertContains('fk_forum_categories_tenant', $this->foreignKeysOf('forum_categories'));

        $stmt = $this->pdo()->query("SELECT tenant_id FROM forum_categories WHERE id = '01958000-0000-7000-8000-00000000c010'");
        $this->assertNotFalse($stmt);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        $this->assertIsArray($row);

        $stmt2 = $this->pdo()->query("SELECT id FROM tenants WHERE slug='daems'");
        $this->assertNotFalse($stmt2);
        $daemsId = (string) $stmt2->fetchColumn();
        $this->assertSame($daemsId, $row['tenant_id']);
    }

    public function testTenantIdColumnAddedToForumTopics(): void
    {
        $this->runMigrationsUpTo(29);
        $this->pdo()->exec(
            "INSERT INTO forum_categories (id, slug, name, description) VALUES ('01958000-0000-7000-8000-00000000c010', 'general', 'General', 'General discussion')"
        );
        $this->pdo()->exec(
            "INSERT INTO forum_topics (id, category_id, slug, title, author_name, last_activity_at, created_at) VALUES ('01958000-0000-7000-8000-00000000c011', '01958000-0000-7000-8000-00000000c010', 'first-topic', 'First Topic', 'Author', '2026-04-19 10:00:00', '2026-04-19 10:00:00')"
        );

        $this->runMigration('030_add_tenant_id_to_forum_tables.sql');

        $this->assertContains('tenant_id', $this->columnsOf('forum_topics'));
        $this->assertContains('fk_forum_topics_tenant', $this->foreignKeysOf('forum_topics'));

        $stmt = $this->pdo()->query("SELECT tenant_id FROM forum_topics WHERE id = '01958000-0000-7000-8000-00000000c011'");
        $this->assertNotFalse($stmt);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        $this->assertIsArray($row);

        $stmt2 = $this->pdo()->query("SELECT id FROM tenants WHERE slug='daems'");
        $this->assertNotFalse($stmt2);
        $daemsId = (string) $stmt2->fetchColumn();
        $this->assertSame($daemsId, $row['tenant_id']);
    }

    public function testTenantIdColumnAddedToForumPosts(): void
    {
        $this->runMigrationsUpTo(29);
        $this->pdo()->exec(
            "INSERT INTO forum_categories (id, slug, name, description) VALUES ('01958000-0000-7000-8000-00000000c010', 'general', 'General', 'General discussion')"
        );
        $this->pdo()->exec(
            "INSERT INTO forum_topics (id, category_id, slug, title, author_name, last_activity_at, created_at) VALUES ('01958000-0000-7000-8000-00000000c011', '01958000-0000-7000-8000-00000000c010', 'first-topic', 'First Topic', 'Author', '2026-04-19 10:00:00', '2026-04-19 10:00:00')"
        );
        $this->pdo()->exec(
            "INSERT INTO forum_posts (id, topic_id, author_name, content, created_at) VALUES ('01958000-0000-7000-8000-00000000c012', '01958000-0000-7000-8000-00000000c011', 'Author', 'Post content', '2026-04-19 10:00:00')"
        );

        $this->runMigration('030_add_tenant_id_to_forum_tables.sql');

        $this->assertContains('tenant_id', $this->columnsOf('forum_posts'));
        $this->assertContains('fk_forum_posts_tenant', $this->foreignKeysOf('forum_posts'));

        $stmt = $this->pdo()->query("SELECT tenant_id FROM forum_posts WHERE id = '01958000-0000-7000-8000-00000000c012'");
        $this->assertNotFalse($stmt);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        $this->assertIsArray($row);

        $stmt2 = $this->pdo()->query("SELECT id FROM tenants WHERE slug='daems'");
        $this->assertNotFalse($stmt2);
        $daemsId = (string) $stmt2->fetchColumn();
        $this->assertSame($daemsId, $row['tenant_id']);
    }

    public function testTenantIdIsNotNullInAllForumTablesAfterMigration(): void
    {
        $this->runMigrationsUpTo(29);
        $this->runMigration('030_add_tenant_id_to_forum_tables.sql');

        foreach (['forum_categories', 'forum_topics', 'forum_posts'] as $table) {
            $stmt = $this->pdo()->query("SHOW COLUMNS FROM {$table} LIKE 'tenant_id'");
            $this->assertNotFalse($stmt);
            $col = $stmt->fetch(\PDO::FETCH_ASSOC);
            $this->assertIsArray($col, "Expected column metadata for {$table}");
            $this->assertSame('NO', $col['Null'], "tenant_id should be NOT NULL in {$table}");
        }
    }
}
