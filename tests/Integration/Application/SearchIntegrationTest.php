<?php
declare(strict_types=1);

namespace Daems\Tests\Integration\Application;

use Daems\Domain\Shared\ValueObject\Uuid7;
use Daems\Infrastructure\Adapter\Persistence\Sql\SqlSearchRepository;
use Daems\Infrastructure\Framework\Database\Connection;
use Daems\Tests\Integration\MigrationTestCase;

final class SearchIntegrationTest extends MigrationTestCase
{
    private Connection $conn;
    private string $tenantId;
    private string $otherTenantId;
    private string $adminUserId;

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
        $this->otherTenantId = Uuid7::generate()->value();
        $this->adminUserId = Uuid7::generate()->value();

        $pdo = $this->pdo();
        $pdo->prepare('INSERT INTO tenants (id, slug, name, created_at) VALUES (?,?,?,NOW())')
            ->execute([$this->tenantId, 'daems-st', 'Daems ST']);
        $pdo->prepare('INSERT INTO tenants (id, slug, name, created_at) VALUES (?,?,?,NOW())')
            ->execute([$this->otherTenantId, 'sahegroup-st', 'SaheGroup ST']);

        $this->seedEvents();
    }

    private function seedEvents(): void
    {
        $pdo = $this->pdo();
        $e1 = Uuid7::generate()->value();
        $pdo->prepare('INSERT INTO events (id, tenant_id, slug, type, status, event_date, created_at) VALUES (?,?,?,?,?,?,NOW())')
            ->execute([$e1, $this->tenantId, 'summer-2026', 'upcoming', 'published', '2026-06-01']);
        $pdo->prepare('INSERT INTO events_i18n (event_id, locale, title, location, description) VALUES (?,?,?,?,?)')
            ->execute([$e1, 'fi_FI', 'Kesätapaaminen 2026', 'Helsinki', 'Vuoden tärkein tapahtuma']);
        $pdo->prepare('INSERT INTO events_i18n (event_id, locale, title, location, description) VALUES (?,?,?,?,?)')
            ->execute([$e1, 'en_GB', 'Summer Meetup 2026', 'Helsinki', 'The highlight of the year']);

        $e2 = Uuid7::generate()->value();
        $pdo->prepare('INSERT INTO events (id, tenant_id, slug, type, status, event_date, created_at) VALUES (?,?,?,?,?,?,NOW())')
            ->execute([$e2, $this->tenantId, 'draft-summer', 'upcoming', 'draft', '2026-07-01']);
        $pdo->prepare('INSERT INTO events_i18n (event_id, locale, title, location, description) VALUES (?,?,?,?,?)')
            ->execute([$e2, 'fi_FI', 'Luonnos kesällä', '', '']);

        $e3 = Uuid7::generate()->value();
        $pdo->prepare('INSERT INTO events (id, tenant_id, slug, type, status, event_date, created_at) VALUES (?,?,?,?,?,?,NOW())')
            ->execute([$e3, $this->otherTenantId, 'sahegroup-summer', 'upcoming', 'published', '2026-06-15']);
        $pdo->prepare('INSERT INTO events_i18n (event_id, locale, title, location, description) VALUES (?,?,?,?,?)')
            ->execute([$e3, 'fi_FI', 'Summer Dar es Salaam', 'Dar es Salaam', 'SaheGroup event']);
    }

    public function test_finds_event_in_current_locale(): void
    {
        $repo = new SqlSearchRepository($this->conn);
        $hits = $repo->search($this->tenantId, 'kesätapaaminen', 'event', false, false, 5, 'fi_FI');
        self::assertCount(1, $hits);
        self::assertSame('Kesätapaaminen 2026', $hits[0]->title);
        self::assertNull($hits[0]->localeCode);
    }

    public function test_tenant_scope_isolates_results(): void
    {
        $repo = new SqlSearchRepository($this->conn);
        $hits = $repo->search($this->tenantId, 'Dar es Salaam', 'event', false, false, 5, 'fi_FI');
        self::assertCount(0, $hits, 'Other-tenant events must not leak');
    }

    public function test_excludes_draft_events_for_public_but_returns_for_admin(): void
    {
        $repo = new SqlSearchRepository($this->conn);
        $public = $repo->search($this->tenantId, 'Luonnos', 'event', false, false, 5, 'fi_FI');
        self::assertCount(0, $public);

        $admin = $repo->search($this->tenantId, 'Luonnos', 'event', true, true, 5, 'fi_FI');
        self::assertCount(1, $admin);
        self::assertSame('draft', $admin[0]->status);
    }
}
