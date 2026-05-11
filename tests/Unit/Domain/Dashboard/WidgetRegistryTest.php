<?php
declare(strict_types=1);

namespace Tests\Unit\Domain\Dashboard;

use Daems\Domain\Dashboard\Exception\UnknownWidget;
use Daems\Domain\Dashboard\Exception\WidgetAlreadyRegistered;
use Daems\Domain\Dashboard\MinRole;
use Daems\Domain\Dashboard\Widget;
use Daems\Domain\Dashboard\WidgetCategory;
use Daems\Domain\Dashboard\WidgetRegistry;
use Daems\Domain\Dashboard\WidgetSpan;
use Daems\Domain\Tenant\TenantId;
use Daems\Domain\User\User;
use PHPUnit\Framework\TestCase;

final class WidgetRegistryTest extends TestCase
{
    public function test_register_and_find(): void
    {
        $r = new WidgetRegistry();
        $w = $this->fakeWidget('core.x', null, MinRole::Admin);
        $r->register($w);
        self::assertSame($w, $r->find('core.x'));
    }

    public function test_duplicate_id_throws(): void
    {
        $r = new WidgetRegistry();
        $r->register($this->fakeWidget('core.x', null, MinRole::Admin));
        $this->expectException(WidgetAlreadyRegistered::class);
        $r->register($this->fakeWidget('core.x', null, MinRole::Admin));
    }

    public function test_find_unknown_throws(): void
    {
        $this->expectException(UnknownWidget::class);
        (new WidgetRegistry())->find('core.nope');
    }

    public function test_filter_drops_widgets_above_role(): void
    {
        $r = new WidgetRegistry();
        $r->register($this->fakeWidget('core.a', null,       MinRole::Admin));
        $r->register($this->fakeWidget('plat.b', 'platform', MinRole::Gsa));
        $filtered = $r->filterFor(MinRole::Admin, ['events']);
        self::assertCount(1, $filtered);
        self::assertSame('core.a', $filtered[0]->id());
    }

    public function test_filter_drops_widgets_for_disabled_modules(): void
    {
        $r = new WidgetRegistry();
        $r->register($this->fakeWidget('core.a',   null,     MinRole::Admin));
        $r->register($this->fakeWidget('events.k', 'events', MinRole::Admin));
        $r->register($this->fakeWidget('forum.k',  'forum',  MinRole::Admin));
        $filtered = $r->filterFor(MinRole::Admin, ['events']);
        $ids = array_map(fn(Widget $w) => $w->id(), $filtered);
        sort($ids);
        self::assertSame(['core.a', 'events.k'], $ids);
    }

    public function test_filter_keeps_platform_widgets_for_gsa(): void
    {
        $r = new WidgetRegistry();
        $r->register($this->fakeWidget('plat.a', 'platform', MinRole::Gsa));
        $filtered = $r->filterFor(MinRole::Gsa, []);
        self::assertCount(1, $filtered);
    }

    private function fakeWidget(string $id, ?string $module, MinRole $minRole): Widget
    {
        return new class($id, $module, $minRole) extends Widget {
            public function __construct(
                private readonly string $id,
                private readonly ?string $module,
                private readonly MinRole $minRole,
            ) {}
            public function id(): string                  { return $this->id; }
            public function category(): WidgetCategory    { return WidgetCategory::Numbers; }
            public function defaultSpan(): WidgetSpan     { return WidgetSpan::of(1); }
            public function minRole(): MinRole            { return $this->minRole; }
            public function module(): ?string             { return $this->module; }
            public function labelKey(): string            { return 'x'; }
            public function descriptionKey(): string      { return 'y'; }
            public function render(TenantId $t, User $u): string { return ''; }
            public function data(TenantId $t): array      { return []; }
        };
    }
}
