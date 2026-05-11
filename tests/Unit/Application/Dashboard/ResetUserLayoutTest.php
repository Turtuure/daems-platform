<?php
declare(strict_types=1);

namespace Daems\Tests\Unit\Application\Dashboard;

use Daems\Application\Dashboard\ResetUserLayout\ResetUserLayout;
use Daems\Domain\Dashboard\LayoutEntry;
use Daems\Domain\Dashboard\UserDashboard;
use Daems\Domain\Dashboard\WidgetSpan;
use Daems\Domain\Tenant\TenantId;
use Daems\Domain\User\UserId;
use Daems\Infrastructure\Dashboard\InMemoryUserDashboardRepository;
use PHPUnit\Framework\TestCase;

final class ResetUserLayoutTest extends TestCase
{
    public function test_deletes_existing_row(): void
    {
        $repo = new InMemoryUserDashboardRepository();
        $userId = UserId::generate();
        $tenantId = TenantId::generate();
        $repo->save(new UserDashboard(
            $userId, $tenantId,
            [new LayoutEntry('core.x', WidgetSpan::of(1))],
            new \DateTimeImmutable(),
        ));

        (new ResetUserLayout($repo))->execute($userId, $tenantId);

        self::assertNull($repo->findFor($userId, $tenantId));
    }

    public function test_idempotent_when_no_row(): void
    {
        $repo = new InMemoryUserDashboardRepository();
        (new ResetUserLayout($repo))->execute(UserId::generate(), TenantId::generate());
        self::assertTrue(true);
    }
}
