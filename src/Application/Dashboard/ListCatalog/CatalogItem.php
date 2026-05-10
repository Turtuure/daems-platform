<?php
declare(strict_types=1);

namespace Daems\Application\Dashboard\ListCatalog;

final class CatalogItem
{
    public function __construct(
        public readonly string $widgetId,
        public readonly string $labelKey,
        public readonly string $descriptionKey,
        public readonly string $category,
        public readonly int $defaultSpan,
        public readonly ?string $module,
        public readonly bool $inLayout,
        public readonly ?string $lockedReason,
    ) {}

    /** @return array{widget_id: string, label_key: string, description_key: string, category: string, default_span: int, module: ?string, in_layout: bool, locked_reason: ?string} */
    public function toArray(): array
    {
        return [
            'widget_id'       => $this->widgetId,
            'label_key'       => $this->labelKey,
            'description_key' => $this->descriptionKey,
            'category'        => $this->category,
            'default_span'    => $this->defaultSpan,
            'module'          => $this->module,
            'in_layout'       => $this->inLayout,
            'locked_reason'   => $this->lockedReason,
        ];
    }
}
