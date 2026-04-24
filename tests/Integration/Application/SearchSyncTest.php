<?php
declare(strict_types=1);

namespace Daems\Tests\Integration\Application;

use Daems\Domain\Shared\ValueObject\Uuid7;
use Daems\Infrastructure\Adapter\Persistence\Sql\SqlInsightRepository;
use Daems\Infrastructure\Framework\Database\Connection;
use Daems\Tests\Integration\MigrationTestCase;

final class SearchSyncTest extends MigrationTestCase
{
    private Connection $conn;
    private string $tenantId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->runMigrationsUpTo(61);
        $this->conn = new Connection([
            'host' => getenv('TEST_DB_HOST') ?: '127.0.0.1',
            'port' => getenv('TEST_DB_PORT') ?: '3306',
            'database' => getenv('TEST_DB_NAME') ?: 'daems_db_test',
            'username' => getenv('TEST_DB_USER') ?: 'root',
            'password' => getenv('TEST_DB_PASS') ?: 'salasana',
        ]);
        $this->tenantId = Uuid7::generate()->value();
        $this->pdo()->prepare('INSERT INTO tenants (id, slug, name, created_at) VALUES (?,?,?,NOW())')
            ->execute([$this->tenantId, 'daems-sync', 'Daems Sync']);
    }

    public function test_insight_save_populates_search_text_from_stripped_content(): void
    {
        $repo = new SqlInsightRepository($this->conn);
        $insightId = \Daems\Domain\Insight\InsightId::generate();
        $id = $insightId->value();
        $insight = new \Daems\Domain\Insight\Insight(
            id: $insightId,
            tenantId: \Daems\Domain\Tenant\TenantId::fromString($this->tenantId),
            slug: 'sync-check-' . substr($id, 0, 8),
            title: 'Sync Check',
            category: 'tech',
            categoryLabel: 'Tech',
            featured: false,
            date: '2026-04-24',
            author: 'Sam',
            readingTime: 3,
            excerpt: 'x',
            heroImage: null,
            tags: [],
            content: '<p>plain text here and <strong>bold bits</strong></p>',
        );
        $repo->save($insight);

        $row = $this->pdo()->query("SELECT search_text FROM insights WHERE id = '{$id}'")
            ->fetch(\PDO::FETCH_ASSOC);
        self::assertStringContainsString('plain text here', (string) $row['search_text']);
        self::assertStringNotContainsString('<strong>', (string) $row['search_text']);
    }

    public function test_forum_first_post_save_updates_topic_first_post_search_text(): void
    {
        $pdo = $this->pdo();
        $catId   = Uuid7::generate()->value();
        $topicId = Uuid7::generate()->value();
        $pdo->prepare('INSERT INTO forum_categories (id, tenant_id, slug, name, icon, description, sort_order) VALUES (?,?,?,?,?,?,?)')
            ->execute([$catId, $this->tenantId, 'gen', 'Gen', 'chat', '', 1]);
        $pdo->prepare("INSERT INTO forum_topics
            (id, tenant_id, category_id, slug, title, author_name, avatar_initials, avatar_color,
             last_activity_at, last_activity_by, created_at)
            VALUES (?,?,?,?,?,?,?,?,NOW(),'anon',NOW())")
            ->execute([$topicId, $this->tenantId, $catId, 'first-sync', 'Hi', 'anon', 'AN', 'blue']);

        $repo = new \Daems\Infrastructure\Adapter\Persistence\Sql\SqlForumRepository($this->conn);
        $firstPost = new \Daems\Domain\Forum\ForumPost(
            id: \Daems\Domain\Forum\ForumPostId::generate(),
            tenantId: \Daems\Domain\Tenant\TenantId::fromString($this->tenantId),
            topicId: $topicId,
            userId: null,
            authorName: 'anon',
            avatarInitials: 'AN',
            avatarColor: 'blue',
            role: '',
            roleClass: '',
            joinedText: '',
            content: 'Climate action starts here',
            likes: 0,
            createdAt: date('Y-m-d H:i:s'),
            sortOrder: 0,
        );
        $repo->savePost($firstPost);

        $row = $pdo->query("SELECT first_post_search_text FROM forum_topics WHERE id = '{$topicId}'")
            ->fetch(\PDO::FETCH_ASSOC);
        self::assertSame('Climate action starts here', (string) $row['first_post_search_text']);
    }
}
