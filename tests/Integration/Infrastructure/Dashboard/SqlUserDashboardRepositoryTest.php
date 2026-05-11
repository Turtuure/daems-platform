<?php
declare(strict_types=1);

namespace Daems\Tests\Integration\Infrastructure\Dashboard;

use Daems\Domain\Dashboard\LayoutEntry;
use Daems\Domain\Dashboard\UserDashboard;
use Daems\Domain\Dashboard\WidgetSpan;
use Daems\Domain\Tenant\TenantId;
use Daems\Domain\User\UserId;
use Daems\Infrastructure\Dashboard\SqlUserDashboardRepository;
use Daems\Tests\Integration\MigrationTestCase;

final class SqlUserDashboardRepositoryTest extends MigrationTestCase
{
    private SqlUserDashboardRepository $repo;
    private UserId $userId;
    private TenantId $tenantId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->runMigrationsUpTo(73);

        $this->repo = new SqlUserDashboardRepository($this->pdo());

        $this->tenantId = TenantId::generate();
        $this->pdo()->prepare(
            'INSERT INTO tenants (id, slug, name, created_at) VALUES (?, ?, ?, NOW())'
        )->execute([$this->tenantId->value(), 'dash-test', 'DashTest']);

        $this->userId = UserId::generate();
        $this->pdo()->prepare(
            'INSERT INTO users (id, name, email, password_hash, date_of_birth) VALUES (?, ?, ?, ?, ?)'
        )->execute([
            $this->userId->value(),
            'Dash Tester',
            'dash@test.example',
            password_hash('x', PASSWORD_BCRYPT),
            '1990-01-01',
        ]);
    }

    public function test_save_and_find(): void
    {
        $dash = new UserDashboard(
            $this->userId,
            $this->tenantId,
            [
                new LayoutEntry('core.members_kpi',   WidgetSpan::of(1)),
                new LayoutEntry('core.activity_feed', WidgetSpan::of(2)),
            ],
            new \DateTimeImmutable('2026-05-10 12:00:00'),
        );

        $this->repo->save($dash);
        $loaded = $this->repo->findFor($this->userId, $this->tenantId);

        self::assertNotNull($loaded);
        self::assertCount(2, $loaded->layout());
        self::assertSame('core.members_kpi', $loaded->layout()[0]->widgetId());
        self::assertSame(2, $loaded->layout()[1]->span()->value());
    }

    public function test_save_upserts_existing(): void
    {
        $this->repo->save(new UserDashboard(
            $this->userId, $this->tenantId,
            [new LayoutEntry('core.members_kpi', WidgetSpan::of(1))],
            new \DateTimeImmutable('2026-05-10 12:00:00'),
        ));
        $this->repo->save(new UserDashboard(
            $this->userId, $this->tenantId,
            [new LayoutEntry('core.activity_feed', WidgetSpan::of(2))],
            new \DateTimeImmutable('2026-05-10 13:00:00'),
        ));

        $loaded = $this->repo->findFor($this->userId, $this->tenantId);
        self::assertNotNull($loaded);
        self::assertCount(1, $loaded->layout());
        self::assertSame('core.activity_feed', $loaded->layout()[0]->widgetId());
    }

    public function test_delete_removes_row(): void
    {
        $this->repo->save(new UserDashboard(
            $this->userId, $this->tenantId,
            [new LayoutEntry('core.members_kpi', WidgetSpan::of(1))],
            new \DateTimeImmutable(),
        ));

        $this->repo->delete($this->userId, $this->tenantId);
        self::assertNull($this->repo->findFor($this->userId, $this->tenantId));
    }
}
