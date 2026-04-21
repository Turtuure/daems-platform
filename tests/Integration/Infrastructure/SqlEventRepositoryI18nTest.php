<?php
declare(strict_types=1);

namespace Daems\Tests\Integration\Infrastructure;

use Daems\Domain\Event\Event;
use Daems\Domain\Event\EventId;
use Daems\Domain\Locale\SupportedLocale;
use Daems\Domain\Locale\TranslationMap;
use Daems\Domain\Tenant\TenantId;
use Daems\Infrastructure\Adapter\Persistence\Sql\SqlEventRepository;
use Daems\Infrastructure\Framework\Database\Connection;
use Daems\Tests\Integration\MigrationTestCase;

final class SqlEventRepositoryI18nTest extends MigrationTestCase
{
    private SqlEventRepository $repo;

    private TenantId $tenantId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->runMigrationsUpTo(53);
        $this->repo = new SqlEventRepository(new Connection([
            'host'     => getenv('TEST_DB_HOST') ?: '127.0.0.1',
            'port'     => getenv('TEST_DB_PORT') ?: '3306',
            'database' => getenv('TEST_DB_NAME') ?: 'daems_db_test',
            'username' => getenv('TEST_DB_USER') ?: 'root',
            'password' => getenv('TEST_DB_PASS') ?: 'salasana',
        ]));
        $this->tenantId = $this->resolveDaemsTenantId();
    }

    private function resolveDaemsTenantId(): TenantId
    {
        $stmt = $this->pdo()->prepare('SELECT id FROM tenants WHERE slug = ?');
        $stmt->execute(['daems']);
        $id = $stmt->fetchColumn();
        if (!is_string($id)) {
            throw new \RuntimeException('daems tenant missing after migrations');
        }
        return TenantId::fromString($id);
    }

    public function testSaveAndFindWithFullTranslations(): void
    {
        $eventId = EventId::generate();
        $translations = new TranslationMap([
            'fi_FI' => ['title' => 'Kokous', 'location' => 'Hki', 'description' => 'Kuvaus'],
            'en_GB' => ['title' => 'Meeting', 'location' => 'Helsinki', 'description' => 'Desc'],
        ]);
        $event = new Event(
            $eventId,
            $this->tenantId,
            'test-meet-' . substr($eventId->value(), 0, 8),
            'Kokous',
            'upcoming',
            '2026-06-15',
            '18:00',
            'Hki',
            false,
            'Kuvaus',
            null,
            [],
            'published',
            $translations,
        );
        $this->repo->save($event);

        $loaded = $this->repo->findByIdForTenant($eventId->value(), $this->tenantId);
        $this->assertNotNull($loaded);
        $view = $loaded->view(SupportedLocale::fromString('fi_FI'), SupportedLocale::contentFallback());
        $this->assertSame('Kokous', $view->field('title'));
        $this->assertFalse($view->isFallback('title'));

        $viewEn = $loaded->view(SupportedLocale::fromString('en_GB'), SupportedLocale::contentFallback());
        $this->assertSame('Meeting', $viewEn->field('title'));
        $this->assertFalse($viewEn->isFallback('title'));
    }

    public function testFindReturnsFallbackViewWhenRequestedLocaleMissing(): void
    {
        $eventId = EventId::generate();
        $translations = new TranslationMap([
            'en_GB' => ['title' => 'Meeting', 'location' => 'Helsinki', 'description' => 'Desc'],
        ]);
        $event = new Event(
            $eventId,
            $this->tenantId,
            'test-fb-' . substr($eventId->value(), 0, 8),
            'Meeting',
            'upcoming',
            '2026-06-15',
            null,
            'Helsinki',
            false,
            'Desc',
            null,
            [],
            'published',
            $translations,
        );
        $this->repo->save($event);

        $loaded = $this->repo->findByIdForTenant($eventId->value(), $this->tenantId);
        $this->assertNotNull($loaded);
        $view = $loaded->view(SupportedLocale::fromString('fi_FI'), SupportedLocale::contentFallback());
        $this->assertSame('Meeting', $view->field('title'));
        $this->assertTrue($view->isFallback('title'));
    }

    public function testSaveTranslationUpserts(): void
    {
        $eventId = EventId::generate();
        $initial = new TranslationMap([
            'fi_FI' => ['title' => 'Alku', 'location' => null, 'description' => null],
        ]);
        $event = new Event(
            $eventId,
            $this->tenantId,
            'test-upsert-' . substr($eventId->value(), 0, 8),
            'Alku',
            'upcoming',
            '2026-06-15',
            null,
            null,
            false,
            null,
            null,
            [],
            'published',
            $initial,
        );
        $this->repo->save($event);

        $this->repo->saveTranslation(
            $this->tenantId,
            $eventId->value(),
            SupportedLocale::fromString('fi_FI'),
            ['title' => 'Päivitetty', 'location' => 'Hki', 'description' => 'Uusi'],
        );
        $this->repo->saveTranslation(
            $this->tenantId,
            $eventId->value(),
            SupportedLocale::fromString('sw_TZ'),
            ['title' => 'Mkutano', 'location' => null, 'description' => null],
        );

        $loaded = $this->repo->findByIdForTenant($eventId->value(), $this->tenantId);
        $this->assertNotNull($loaded);
        $map = $loaded->translations();
        $fi = $map->rowFor(SupportedLocale::fromString('fi_FI'));
        $this->assertIsArray($fi);
        $this->assertSame('Päivitetty', $fi['title']);
        $sw = $map->rowFor(SupportedLocale::fromString('sw_TZ'));
        $this->assertIsArray($sw);
        $this->assertSame('Mkutano', $sw['title']);
    }
}
