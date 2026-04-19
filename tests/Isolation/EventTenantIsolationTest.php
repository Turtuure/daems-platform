<?php

declare(strict_types=1);

namespace Daems\Tests\Isolation;

use Daems\Domain\Event\EventId;
use Daems\Infrastructure\Adapter\Persistence\Sql\SqlEventRepository;
use Daems\Infrastructure\Framework\Database\Connection;

final class EventTenantIsolationTest extends IsolationTestCase
{
    private SqlEventRepository $repo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repo = new SqlEventRepository(new Connection([
            'host'     => getenv('TEST_DB_HOST') ?: '127.0.0.1',
            'port'     => getenv('TEST_DB_PORT') ?: '3306',
            'database' => getenv('TEST_DB_NAME') ?: 'daems_db_test',
            'username' => getenv('TEST_DB_USER') ?: 'root',
            'password' => getenv('TEST_DB_PASS') ?: 'salasana',
        ]));
    }

    private function seedEvent(string $tenantSlug, string $slug, string $title): void
    {
        $stmt = $this->pdo()->prepare(
            "INSERT INTO events (id, tenant_id, slug, title, type, event_date)
             VALUES (?, (SELECT id FROM tenants WHERE slug = ?), ?, ?, 'upcoming', '2026-06-01')"
        );
        $stmt->execute([EventId::generate()->value(), $tenantSlug, $slug, $title]);
    }

    public function test_list_isolates_by_tenant(): void
    {
        $this->seedEvent('sahegroup', 'sg-event', 'SG Event');
        $this->seedEvent('daems', 'd-event', 'D Event');

        $results = $this->repo->listForTenant($this->tenantId('daems'));

        $titles = array_map(static fn($e): string => $e->title(), $results);
        self::assertContains('D Event', $titles);
        self::assertNotContains('SG Event', $titles);
    }

    public function test_repository_has_no_legacy_non_scoped_methods(): void
    {
        self::assertFalse(method_exists(SqlEventRepository::class, 'findAll'));
        self::assertFalse(method_exists(SqlEventRepository::class, 'findBySlug'));
        self::assertTrue(method_exists(SqlEventRepository::class, 'listForTenant'));
        self::assertTrue(method_exists(SqlEventRepository::class, 'findBySlugForTenant'));
    }

    public function test_find_by_slug_requires_matching_tenant(): void
    {
        $this->seedEvent('sahegroup', 'shared-slug', 'Sahe Event');

        self::assertNull($this->repo->findBySlugForTenant('shared-slug', $this->tenantId('daems')));
        self::assertNotNull($this->repo->findBySlugForTenant('shared-slug', $this->tenantId('sahegroup')));
    }
}
