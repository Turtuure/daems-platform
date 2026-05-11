<?php
declare(strict_types=1);

namespace Daems\Tests\Unit\Application\Dashboard;

use Daems\Application\Dashboard\ListCatalog\ListCatalog;
use Daems\Domain\Dashboard\LayoutEntry;
use Daems\Domain\Dashboard\MinRole;
use Daems\Domain\Dashboard\WidgetRegistry;
use Daems\Domain\Dashboard\WidgetSpan;
use PHPUnit\Framework\TestCase;
use Daems\Tests\Unit\Application\Dashboard\Support\FakeWidget;

final class ListCatalogTest extends TestCase
{
    public function test_lists_widgets_for_user_role_and_modules(): void
    {
        $r = new WidgetRegistry();
        $r->register(new FakeWidget('core.a',   null,       MinRole::Admin));
        $r->register(new FakeWidget('events.x', 'events',   MinRole::Admin));
        $r->register(new FakeWidget('forum.y',  'forum',    MinRole::Admin));

        $items = (new ListCatalog($r))->execute(MinRole::Admin, ['events'], []);

        $ids = array_map(fn($i) => $i->widgetId, $items);
        sort($ids);
        self::assertSame(['core.a', 'events.x'], $ids);
    }

    public function test_marks_widgets_already_in_layout(): void
    {
        $r = new WidgetRegistry();
        $r->register(new FakeWidget('core.a', null, MinRole::Admin));
        $r->register(new FakeWidget('core.b', null, MinRole::Admin));

        $items = (new ListCatalog($r))->execute(
            MinRole::Admin,
            [],
            [new LayoutEntry('core.a', WidgetSpan::of(1))],
        );

        $byId = [];
        foreach ($items as $i) { $byId[$i->widgetId] = $i; }
        self::assertTrue($byId['core.a']->inLayout);
        self::assertFalse($byId['core.b']->inLayout);
    }
}
