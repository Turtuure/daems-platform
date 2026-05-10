<?php
declare(strict_types=1);

namespace Daems\Application\Dashboard\ListCatalog;

use Daems\Domain\Dashboard\LayoutEntry;
use Daems\Domain\Dashboard\MinRole;
use Daems\Domain\Dashboard\WidgetRegistry;

final class ListCatalog
{
    public function __construct(
        private readonly WidgetRegistry $registry,
    ) {}

    /**
     * @param list<string> $enabledModules
     * @param list<LayoutEntry> $currentLayout
     * @return list<CatalogItem>
     */
    public function execute(MinRole $userRole, array $enabledModules, array $currentLayout): array
    {
        $inLayoutIds = array_flip(array_map(fn($e) => $e->widgetId(), $currentLayout));
        $items = [];
        foreach ($this->registry->filterFor($userRole, $enabledModules) as $w) {
            $items[] = new CatalogItem(
                widgetId:       $w->id(),
                labelKey:       $w->labelKey(),
                descriptionKey: $w->descriptionKey(),
                category:       $w->category()->value,
                defaultSpan:    $w->defaultSpan()->value(),
                module:         $w->module(),
                inLayout:       isset($inLayoutIds[$w->id()]),
                lockedReason:   null,
            );
        }
        return $items;
    }
}
