<?php
declare(strict_types=1);

namespace Daems\Domain\Dashboard;

final class LayoutEntry
{
    public function __construct(
        private readonly string $widgetId,
        private readonly WidgetSpan $span,
    ) {}

    public function widgetId(): string { return $this->widgetId; }
    public function span(): WidgetSpan { return $this->span; }

    /** @return array{widget_id: string, span: int} */
    public function toArray(): array
    {
        return ['widget_id' => $this->widgetId, 'span' => $this->span->value()];
    }
}
