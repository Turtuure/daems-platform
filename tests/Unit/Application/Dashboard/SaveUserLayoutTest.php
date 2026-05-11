<?php
declare(strict_types=1);

namespace Daems\Tests\Unit\Application\Dashboard;

use Daems\Application\Dashboard\SaveUserLayout\SaveUserLayout;
use Daems\Application\Dashboard\SaveUserLayout\SaveUserLayoutInput;
use Daems\Domain\Dashboard\Exception\InvalidLayout;
use Daems\Domain\Dashboard\MinRole;
use Daems\Domain\Dashboard\WidgetRegistry;
use Daems\Domain\Tenant\TenantId;
use Daems\Domain\User\UserId;
use Daems\Infrastructure\Dashboard\InMemoryUserDashboardRepository;
use PHPUnit\Framework\TestCase;
use Daems\Tests\Unit\Application\Dashboard\Support\FakeWidget;

final class SaveUserLayoutTest extends TestCase
{
    public function test_saves_valid_layout(): void
    {
        [$useCase, $repo] = $this->fixtures();
        $userId = UserId::generate();
        $tenantId = TenantId::generate();

        $useCase->execute(new SaveUserLayoutInput(
            $userId, $tenantId, MinRole::Admin, ['events'],
            [['widget_id' => 'core.members_kpi', 'span' => 1]],
        ));

        self::assertNotNull($repo->findFor($userId, $tenantId));
    }

    public function test_rejects_unknown_widget(): void
    {
        [$useCase] = $this->fixtures();
        $this->expectException(InvalidLayout::class);
        $useCase->execute(new SaveUserLayoutInput(
            UserId::generate(), TenantId::generate(), MinRole::Admin, [],
            [['widget_id' => 'core.nope', 'span' => 1]],
        ));
    }

    public function test_rejects_widget_above_role(): void
    {
        [$useCase] = $this->fixtures();
        $this->expectException(InvalidLayout::class);
        $useCase->execute(new SaveUserLayoutInput(
            UserId::generate(), TenantId::generate(), MinRole::Admin, [],
            [['widget_id' => 'platform.tenants_kpi', 'span' => 1]],
        ));
    }

    public function test_rejects_widget_for_disabled_module(): void
    {
        [$useCase] = $this->fixtures();
        $this->expectException(InvalidLayout::class);
        $useCase->execute(new SaveUserLayoutInput(
            UserId::generate(), TenantId::generate(), MinRole::Admin, [],
            [['widget_id' => 'events.events_kpi', 'span' => 1]],
        ));
    }

    public function test_rejects_span_out_of_range(): void
    {
        [$useCase] = $this->fixtures();
        $this->expectException(InvalidLayout::class);
        $useCase->execute(new SaveUserLayoutInput(
            UserId::generate(), TenantId::generate(), MinRole::Admin, [],
            [['widget_id' => 'core.members_kpi', 'span' => 5]],
        ));
    }

    public function test_rejects_duplicate_widget_id(): void
    {
        [$useCase] = $this->fixtures();
        $this->expectException(InvalidLayout::class);
        $useCase->execute(new SaveUserLayoutInput(
            UserId::generate(), TenantId::generate(), MinRole::Admin, [],
            [
                ['widget_id' => 'core.members_kpi', 'span' => 1],
                ['widget_id' => 'core.members_kpi', 'span' => 1],
            ],
        ));
    }

    public function test_rejects_malformed_entry(): void
    {
        [$useCase] = $this->fixtures();
        $this->expectException(InvalidLayout::class);
        $useCase->execute(new SaveUserLayoutInput(
            UserId::generate(), TenantId::generate(), MinRole::Admin, [],
            [['span' => 1]],
        ));
    }

    /** @return array{0: SaveUserLayout, 1: InMemoryUserDashboardRepository} */
    private function fixtures(): array
    {
        $r = new WidgetRegistry();
        $r->register(new FakeWidget('core.members_kpi',     null,       MinRole::Admin));
        $r->register(new FakeWidget('events.events_kpi',    'events',   MinRole::Admin));
        $r->register(new FakeWidget('platform.tenants_kpi', 'platform', MinRole::Gsa));
        $repo = new InMemoryUserDashboardRepository();
        return [new SaveUserLayout($r, $repo, new \DateTimeImmutable('2026-05-10 12:00:00')), $repo];
    }
}
