<?php
declare(strict_types=1);

namespace Daems\Tests\Integration\Application;

use Daems\Application\Insight\CreateInsight\CreateInsight;
use Daems\Application\Insight\CreateInsight\CreateInsightInput;
use Daems\Application\Insight\DeleteInsight\DeleteInsight;
use Daems\Application\Insight\DeleteInsight\DeleteInsightInput;
use Daems\Application\Insight\UpdateInsight\UpdateInsight;
use Daems\Application\Insight\UpdateInsight\UpdateInsightInput;
use Daems\Domain\Insight\InsightId;
use Daems\Domain\Shared\NotFoundException;
use Daems\Domain\Shared\ValueObject\Uuid7;
use Daems\Domain\Tenant\TenantId;
use Daems\Infrastructure\Adapter\Persistence\Sql\SqlInsightRepository;
use Daems\Infrastructure\Framework\Database\Connection;
use Daems\Tests\Integration\MigrationTestCase;

final class BackstageInsightsIntegrationTest extends MigrationTestCase
{
    private Connection $conn;
    private TenantId $tenantA;
    private TenantId $tenantB;

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

        $this->tenantA = TenantId::fromString(Uuid7::generate()->value());
        $this->tenantB = TenantId::fromString(Uuid7::generate()->value());
        $this->pdo()->prepare('INSERT INTO tenants (id, slug, name, created_at) VALUES (?,?,?,NOW())')
            ->execute([$this->tenantA->value(), 'daems-iba', 'Daems IBA']);
        $this->pdo()->prepare('INSERT INTO tenants (id, slug, name, created_at) VALUES (?,?,?,NOW())')
            ->execute([$this->tenantB->value(), 'sahegroup-iba', 'SaheGroup IBA']);
    }

    public function test_full_lifecycle(): void
    {
        $repo   = new SqlInsightRepository($this->conn);
        $create = new CreateInsight($repo);
        $update = new UpdateInsight($repo);
        $delete = new DeleteInsight($repo);

        // CREATE
        $out = $create->execute(new CreateInsightInput(
            tenantId: $this->tenantA,
            slug: 'lifecycle-test',
            title: 'Lifecycle',
            category: 'tech',
            categoryLabel: 'Tech',
            featured: false,
            publishedDate: '2026-05-01',
            author: 'Sam',
            excerpt: 'Teaser',
            heroImage: null,
            tags: ['a', 'b'],
            content: '<p>' . str_repeat('word ', 400) . '</p>',
        ));
        $id = $out->insight->id();
        self::assertSame(2, $out->insight->readingTime(), '400 words → ceil(400/200) = 2 min');

        // Confirm in DB (search_text synced from global-search milestone hook)
        $row = $this->pdo()->query("SELECT title, reading_time, search_text FROM insights WHERE id = '{$id->value()}'")
            ->fetch(\PDO::FETCH_ASSOC);
        self::assertSame('Lifecycle', $row['title']);
        self::assertSame(2, (int) $row['reading_time']);
        self::assertStringContainsString('word', (string) $row['search_text']);

        // UPDATE
        $update->execute(new UpdateInsightInput(
            insightId: $id,
            tenantId: $this->tenantA,
            slug: 'lifecycle-test',
            title: 'Lifecycle Updated',
            category: 'tech',
            categoryLabel: 'Tech',
            featured: true,
            publishedDate: '2026-05-02',
            author: 'Sam',
            excerpt: 'Teaser v2',
            heroImage: null,
            tags: ['a'],
            content: '<p>short</p>',
        ));

        $row = $this->pdo()->query("SELECT title, featured FROM insights WHERE id = '{$id->value()}'")
            ->fetch(\PDO::FETCH_ASSOC);
        self::assertSame('Lifecycle Updated', $row['title']);
        self::assertSame(1, (int) $row['featured']);

        // DELETE
        $delete->execute(new DeleteInsightInput($id, $this->tenantA));
        $count = (int) $this->pdo()->query("SELECT COUNT(*) FROM insights WHERE id = '{$id->value()}'")->fetchColumn();
        self::assertSame(0, $count);
    }

    public function test_update_cross_tenant_throws_not_found(): void
    {
        $repo   = new SqlInsightRepository($this->conn);
        $create = new CreateInsight($repo);
        $update = new UpdateInsight($repo);

        $out = $create->execute(new CreateInsightInput(
            tenantId: $this->tenantA,
            slug: 'cross-tenant',
            title: 'Hello',
            category: 'tech',
            categoryLabel: 'Tech',
            featured: false,
            publishedDate: '2026-05-01',
            author: 'Sam',
            excerpt: 'x',
            heroImage: null,
            tags: [],
            content: 'body',
        ));

        $this->expectException(NotFoundException::class);
        $update->execute(new UpdateInsightInput(
            insightId: $out->insight->id(),
            tenantId: $this->tenantB,   // wrong tenant
            slug: 'cross-tenant',
            title: 'Evil',
            category: 'tech',
            categoryLabel: 'Tech',
            featured: false,
            publishedDate: '2026-05-01',
            author: 'Sam',
            excerpt: 'x',
            heroImage: null,
            tags: [],
            content: 'body',
        ));
    }

    public function test_delete_cross_tenant_throws_not_found(): void
    {
        $repo   = new SqlInsightRepository($this->conn);
        $create = new CreateInsight($repo);
        $delete = new DeleteInsight($repo);

        $out = $create->execute(new CreateInsightInput(
            tenantId: $this->tenantA,
            slug: 'cross-tenant-delete',
            title: 'Hello',
            category: 'tech',
            categoryLabel: 'Tech',
            featured: false,
            publishedDate: '2026-05-01',
            author: 'Sam',
            excerpt: 'x',
            heroImage: null,
            tags: [],
            content: 'body',
        ));

        $this->expectException(NotFoundException::class);
        $delete->execute(new DeleteInsightInput($out->insight->id(), $this->tenantB));
    }
}
