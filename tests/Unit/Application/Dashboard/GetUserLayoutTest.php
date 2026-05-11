<?php
declare(strict_types=1);

namespace Daems\Tests\Unit\Application\Dashboard;

use Daems\Application\Dashboard\GetUserLayout\GetUserLayout;
use Daems\Domain\Dashboard\LayoutEntry;
use Daems\Domain\Dashboard\MinRole;
use Daems\Domain\Dashboard\UserDashboard;
use Daems\Domain\Dashboard\WidgetRegistry;
use Daems\Domain\Dashboard\WidgetSpan;
use Daems\Domain\Tenant\TenantId;
use Daems\Domain\User\UserId;
use Daems\Infrastructure\Dashboard\InMemoryUserDashboardRepository;
use PHPUnit\Framework\TestCase;
use Daems\Tests\Unit\Application\Dashboard\Support\FakeWidget;

final class GetUserLayoutTest extends TestCase
{
    public function test_returns_role_default_when_no_saved_row(): void
    {
        [$registry, $repo] = $this->fixtures();
        $useCase = new GetUserLayout($registry, $repo);

        $output = $useCase->execute(UserId::generate(), TenantId::generate(), MinRole::Admin, ['events', 'projects']);

        self::assertTrue($output->isDefault());
        self::assertGreaterThan(0, count($output->layout()));
    }

    public function test_returns_saved_layout_when_row_exists(): void
    {
        [$registry, $repo] = $this->fixtures();
        $userId = UserId::generate();
        $tenantId = TenantId::generate();

        $repo->save(new UserDashboard(
            $userId, $tenantId,
            [new LayoutEntry('core.members_kpi', WidgetSpan::of(1))],
            new \DateTimeImmutable(),
        ));

        $useCase = new GetUserLayout($registry, $repo);
        $output = $useCase->execute($userId, $tenantId, MinRole::Admin, ['events', 'projects']);

        self::assertFalse($output->isDefault());
        self::assertCount(1, $output->layout());
    }

    public function test_filters_out_widgets_for_disabled_modules(): void
    {
        [$registry, $repo] = $this->fixtures();
        $userId = UserId::generate();
        $tenantId = TenantId::generate();

        $repo->save(new UserDashboard(
            $userId, $tenantId,
            [
                new LayoutEntry('core.members_kpi', WidgetSpan::of(1)),
                new LayoutEntry('events.events_kpi', WidgetSpan::of(1)),
            ],
            new \DateTimeImmutable(),
        ));

        $useCase = new GetUserLayout($registry, $repo);
        $output = $useCase->execute($userId, $tenantId, MinRole::Admin, []);

        self::assertCount(1, $output->layout());
        self::assertSame('core.members_kpi', $output->layout()[0]->widgetId());
    }

    public function test_filters_out_widgets_above_user_role(): void
    {
        [$registry, $repo] = $this->fixtures();
        $userId = UserId::generate();
        $tenantId = TenantId::generate();

        $repo->save(new UserDashboard(
            $userId, $tenantId,
            [
                new LayoutEntry('core.members_kpi', WidgetSpan::of(1)),
                new LayoutEntry('platform.tenants_kpi', WidgetSpan::of(1)),
            ],
            new \DateTimeImmutable(),
        ));

        $useCase = new GetUserLayout($registry, $repo);
        $output = $useCase->execute($userId, $tenantId, MinRole::Admin, []);

        self::assertCount(1, $output->layout());
        self::assertSame('core.members_kpi', $output->layout()[0]->widgetId());
    }

    /** @return array{0: WidgetRegistry, 1: InMemoryUserDashboardRepository} */
    private function fixtures(): array
    {
        $r = new WidgetRegistry();
        $r->register(new FakeWidget('core.members_kpi',         null,       MinRole::Admin));
        $r->register(new FakeWidget('core.applications_kpi',    null,       MinRole::Admin));
        $r->register(new FakeWidget('core.member_growth_chart', null,       MinRole::Admin, 3));
        $r->register(new FakeWidget('core.quick_actions',       null,       MinRole::Admin));
        $r->register(new FakeWidget('core.pending_apps_list',   null,       MinRole::Admin, 2));
        $r->register(new FakeWidget('core.activity_feed',       null,       MinRole::Admin, 2));
        $r->register(new FakeWidget('events.events_kpi',        'events',   MinRole::Admin));
        $r->register(new FakeWidget('projects.projects_kpi',    'projects', MinRole::Admin));
        $r->register(new FakeWidget('platform.tenants_kpi',     'platform', MinRole::Gsa));

        return [$r, new InMemoryUserDashboardRepository()];
    }
}
