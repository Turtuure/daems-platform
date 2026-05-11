<?php
declare(strict_types=1);

namespace Tests\Unit\Frontend\Dashboard;

use Daems\Domain\Dashboard\MinRole;
use Daems\Frontend\Dashboard\DefaultLayouts;
use PHPUnit\Framework\TestCase;

final class DefaultLayoutsTest extends TestCase
{
    public function test_admin_default_has_expected_widgets(): void
    {
        $layout = DefaultLayouts::for(MinRole::Admin);
        // 8 baseline + 1 added in MembershipCore 0.6a (members_by_tier_kpi).
        self::assertCount(9, $layout);
        self::assertSame('core.members_kpi', $layout[0]->widgetId());
        $ids = array_map(fn($e) => $e->widgetId(), $layout);
        self::assertContains('members.members_by_tier_kpi', $ids);
    }

    public function test_moderator_default_has_forum_focus(): void
    {
        $layout = DefaultLayouts::for(MinRole::Moderator);
        $ids = array_map(fn($e) => $e->widgetId(), $layout);
        self::assertContains('forum.reports_kpi', $ids);
        self::assertContains('forum.reports_queue', $ids);
    }

    public function test_gsa_default_has_platform_widgets(): void
    {
        $layout = DefaultLayouts::for(MinRole::Gsa);
        $ids = array_map(fn($e) => $e->widgetId(), $layout);
        self::assertContains('platform.tenant_status_grid', $ids);
        self::assertContains('platform.tenants_kpi', $ids);
    }
}
