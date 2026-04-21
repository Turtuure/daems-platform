<?php
declare(strict_types=1);

namespace Daems\Tests\Isolation;

use Daems\Domain\Event\Event;
use Daems\Domain\Event\EventId;
use Daems\Domain\Locale\SupportedLocale;
use Daems\Domain\Locale\TranslationMap;
use Daems\Infrastructure\Adapter\Persistence\Sql\SqlEventRepository;
use Daems\Infrastructure\Framework\Database\Connection;

final class EventsI18nTenantIsolationTest extends IsolationTestCase
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

    public function test_tenant_b_cannot_read_tenant_a_event_translations(): void
    {
        $tenantA = $this->tenantId('daems');
        $tenantB = $this->tenantId('sahegroup');

        $id = EventId::generate();
        $event = new Event(
            $id,
            $tenantA,
            'cross-tenant-test-' . substr($id->value(), 0, 8),
            'Secret',
            'upcoming',
            '2026-06-15',
            null,
            null,
            false,
            null,
            null,
            [],
            'published',
            new TranslationMap([
                'fi_FI' => ['title' => 'Secret', 'location' => null, 'description' => null],
            ]),
        );
        $this->repo->save($event);

        $this->assertNull($this->repo->findByIdForTenant($id->value(), $tenantB));
        $this->assertEmpty($this->repo->listForTenant($tenantB));
    }

    public function test_tenant_b_cannot_write_translation_to_tenant_a_event(): void
    {
        $tenantA = $this->tenantId('daems');
        $tenantB = $this->tenantId('sahegroup');

        $id = EventId::generate();
        $this->repo->save(new Event(
            $id,
            $tenantA,
            'secret-' . substr($id->value(), 0, 8),
            'Secret',
            'upcoming',
            '2026-06-15',
            null,
            null,
            false,
            null,
            null,
            [],
            'published',
            new TranslationMap([
                'fi_FI' => ['title' => 'S', 'location' => null, 'description' => null],
            ]),
        ));

        $this->expectException(\DomainException::class);
        $this->repo->saveTranslation(
            $tenantB,
            $id->value(),
            SupportedLocale::fromString('en_GB'),
            ['title' => 'hack', 'location' => null, 'description' => null],
        );
    }
}
