<?php
declare(strict_types=1);

namespace Daems\Tests\Unit\Infrastructure\Dashboard\CoreWidgets;

use Daems\Domain\Admin\AdminStats;
use Daems\Domain\Admin\AdminStatsRepositoryInterface;
use Daems\Domain\Dashboard\MinRole;
use Daems\Domain\Dashboard\WidgetCategory;
use Daems\Domain\Tenant\TenantId;
use Daems\Infrastructure\Dashboard\CoreWidgets\MembersByTierKpiWidget;
use PHPUnit\Framework\TestCase;

final class MembersByTierKpiWidgetTest extends TestCase
{
    public function test_metadata(): void
    {
        $w = new MembersByTierKpiWidget($this->fakeRepo());
        self::assertSame('members.members_by_tier_kpi', $w->id());
        self::assertSame(WidgetCategory::Numbers, $w->category());
        self::assertSame(2, $w->defaultSpan()->value());
        self::assertSame(MinRole::Admin, $w->minRole());
        self::assertNull($w->module());
    }

    public function test_data_returns_four_tier_counts(): void
    {
        $w = new MembersByTierKpiWidget($this->fakeRepo());
        $d = $w->data(TenantId::generate());

        self::assertSame(4, $d['supporting']);
        self::assertSame(12, $d['basic']);
        self::assertSame(3, $d['full']);
        self::assertSame(0, $d['honorary']);
    }

    public function test_render_contains_tier_counts(): void
    {
        $w = new MembersByTierKpiWidget($this->fakeRepo());
        $html = $w->render(TenantId::generate(), $this->fakeUser());
        self::assertNotSame('', $html);
        self::assertStringContainsString('>4<', $html);
        self::assertStringContainsString('>12<', $html);
        self::assertStringContainsString('>3<', $html);
    }

    private function fakeRepo(): AdminStatsRepositoryInterface
    {
        return new class implements AdminStatsRepositoryInterface {
            public function getStatsForTenant(TenantId $tenantId): AdminStats
            {
                throw new \RuntimeException('not used');
            }

            /** @return array{ labels: string[], series: int[] } */
            public function getMemberGrowthForTenant(string $period, TenantId $tenantId): array
            {
                return ['labels' => [], 'series' => []];
            }

            /** @return array{supporting:int, basic:int, full:int, honorary:int} */
            public function getMembersByTier(TenantId $tenantId): array
            {
                return ['supporting' => 4, 'basic' => 12, 'full' => 3, 'honorary' => 0];
            }
        };
    }

    private function fakeUser(): \Daems\Domain\User\User
    {
        $class = new \ReflectionClass(\Daems\Domain\User\User::class);
        return $class->newInstanceWithoutConstructor();
    }
}
