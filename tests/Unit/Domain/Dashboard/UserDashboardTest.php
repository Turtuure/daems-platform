<?php
declare(strict_types=1);

namespace Tests\Unit\Domain\Dashboard;

use Daems\Domain\Dashboard\LayoutEntry;
use Daems\Domain\Dashboard\UserDashboard;
use Daems\Domain\Dashboard\WidgetSpan;
use Daems\Domain\Tenant\TenantId;
use Daems\Domain\User\UserId;
use PHPUnit\Framework\TestCase;

final class UserDashboardTest extends TestCase
{
    public function test_construction_and_layout_access(): void
    {
        $userId   = UserId::generate();
        $tenantId = TenantId::generate();
        $layout = [
            new LayoutEntry('core.members_kpi', WidgetSpan::of(1)),
            new LayoutEntry('core.member_growth_chart', WidgetSpan::of(3)),
        ];
        $dash = new UserDashboard($userId, $tenantId, $layout, new \DateTimeImmutable());

        self::assertSame($userId, $dash->userId());
        self::assertSame($tenantId, $dash->tenantId());
        self::assertCount(2, $dash->layout());
        self::assertSame('core.members_kpi', $dash->layout()[0]->widgetId());
    }

    public function test_layout_entry_to_array(): void
    {
        $e = new LayoutEntry('core.x', WidgetSpan::of(2));
        self::assertSame(['widget_id' => 'core.x', 'span' => 2], $e->toArray());
    }
}
