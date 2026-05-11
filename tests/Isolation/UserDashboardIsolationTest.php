<?php

declare(strict_types=1);

namespace Daems\Tests\Isolation;

use Daems\Domain\Dashboard\LayoutEntry;
use Daems\Domain\Dashboard\UserDashboard;
use Daems\Domain\Dashboard\WidgetSpan;
use Daems\Domain\Shared\ValueObject\Uuid7;
use Daems\Infrastructure\Dashboard\SqlUserDashboardRepository;

final class UserDashboardIsolationTest extends IsolationTestCase
{
    private SqlUserDashboardRepository $repo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repo = new SqlUserDashboardRepository($this->pdo());
    }

    public function test_user_layout_in_tenant_a_invisible_to_tenant_b(): void
    {
        $userId  = $this->seedUser(Uuid7::generate()->value(), 'admin@example.com');
        $tenantA = $this->tenantId('daems');
        $tenantB = $this->tenantId('sahegroup');

        $this->repo->save(new UserDashboard(
            $userId,
            $tenantA,
            [new LayoutEntry('core.members_kpi', WidgetSpan::of(1))],
            new \DateTimeImmutable(),
        ));

        self::assertNotNull($this->repo->findFor($userId, $tenantA));
        self::assertNull(
            $this->repo->findFor($userId, $tenantB),
            'tenant-A layout must not leak to tenant-B',
        );
    }

    public function test_delete_only_affects_target_tenant(): void
    {
        $userId  = $this->seedUser(Uuid7::generate()->value(), 'admin@example.com');
        $tenantA = $this->tenantId('daems');
        $tenantB = $this->tenantId('sahegroup');

        $this->repo->save(new UserDashboard(
            $userId,
            $tenantA,
            [new LayoutEntry('core.members_kpi', WidgetSpan::of(1))],
            new \DateTimeImmutable(),
        ));
        $this->repo->save(new UserDashboard(
            $userId,
            $tenantB,
            [new LayoutEntry('core.activity_feed', WidgetSpan::of(2))],
            new \DateTimeImmutable(),
        ));

        $this->repo->delete($userId, $tenantA);

        self::assertNull($this->repo->findFor($userId, $tenantA));
        self::assertNotNull(
            $this->repo->findFor($userId, $tenantB),
            'delete on tenant-A must not affect tenant-B',
        );
    }
}
