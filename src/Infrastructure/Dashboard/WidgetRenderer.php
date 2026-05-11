<?php
declare(strict_types=1);

namespace Daems\Infrastructure\Dashboard;

final class WidgetRenderer
{
    /**
     * Render a KPI metric card.
     *
     * Optional parameters opt-in via PHP 8 named arguments. When $label is
     * non-empty we render the full metric-card shape (icon + label + value
     * + change indicator + sparkline target div), matching the legacy
     * /backstage/pages/partials/metric-card.php. When $label is empty we
     * render the minimal value-only card (used by stub widgets).
     *
     * Sparkline rendering is opt-in via $sparklineId + $sparklineData:
     *   - $sparklineData is JSON-serialised into a data-spark attribute
     *     on the sparkline div.
     *   - dashboard-sparklines.js scans the DOM for [data-spark] elements
     *     and renders an ApexCharts area sparkline in each.
     *
     * @param string         $color         Icon color variant: blue|green|amber|purple|red|cyan
     * @param string         $iconSvg       Inline SVG path content (no <svg> wrapper)
     * @param list<int>|null $sparklineData Series points for the sparkline; null hides it
     */
    public static function kpi(
        int $value,
        float $change = 0.0,
        string $label = '',
        string $color = 'blue',
        string $iconSvg = '',
        ?string $sparklineId = null,
        ?array $sparklineData = null,
    ): string {
        $changeDir = $change > 0 ? 'up' : ($change < 0 ? 'down' : 'neutral');
        $changeSign = $change > 0 ? '+' : '';

        if ($label === '') {
            $changeStr = $changeSign . number_format($change, 1);
            return '<div class="card card--metric">'
                . '<div class="card__body">'
                . '<div class="metric-value">' . number_format($value) . '</div>'
                . '<div class="metric-change">' . $changeStr . '%</div>'
                . '</div></div>';
        }

        $changeArrow = match ($changeDir) {
            'up'   => '<polyline points="18 15 12 9 6 15"/>',
            'down' => '<polyline points="6 9 12 15 18 9"/>',
            default => '',
        };

        $iconBlock = '';
        if ($iconSvg !== '') {
            $iconBlock = '<div class="metric-icon metric-icon--' . htmlspecialchars($color, ENT_QUOTES, 'UTF-8') . '" aria-hidden="true">'
                . '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true">'
                . $iconSvg
                . '</svg></div>';
        }

        $sparkBlock = '';
        if ($sparklineId !== null) {
            $sparkAttr = '';
            if ($sparklineData !== null && $sparklineData !== []) {
                $json = json_encode($sparklineData);
                if ($json !== false) {
                    $sparkAttr = ' data-spark="' . htmlspecialchars($json, ENT_QUOTES, 'UTF-8') . '"'
                        . ' data-spark-color="' . htmlspecialchars($color, ENT_QUOTES, 'UTF-8') . '"';
                }
            }
            $sparkBlock = '<div id="' . htmlspecialchars($sparklineId, ENT_QUOTES, 'UTF-8') . '"'
                . ' class="metric-spark"'
                . $sparkAttr
                . '></div>';
        }

        return '<div class="card card--metric">'
            . '<div class="card__body">'
            . '<div class="flex items-center gap-3 mb-3">'
            . $iconBlock
            . '<p class="metric-label color-secondary">' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</p>'
            . '</div>'
            . '<div class="flex items-end gap-3">'
            . '<p class="metric-value">' . number_format($value) . '</p>'
            . '<span class="metric-change metric-change--' . $changeDir . '">'
            . '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">'
            . $changeArrow
            . '</svg>'
            . $changeSign . number_format(abs($change), 1) . '%'
            . '</span>'
            . '</div>'
            . $sparkBlock
            . '</div></div>';
    }

    /**
     * @param list<string> $items
     */
    public static function list(string $title, array $items): string
    {
        $li = '';
        foreach ($items as $it) {
            $li .= '<li>' . htmlspecialchars($it, ENT_QUOTES, 'UTF-8') . '</li>';
        }
        if ($li === '') {
            $li = '<li class="empty">—</li>';
        }

        return '<div class="card">'
            . '<div class="card__body">'
            . '<p class="card__title">' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</p>'
            . '<ul class="dashboard-list">' . $li . '</ul>'
            . '</div></div>';
    }

    public static function chart(string $title, string $chartId): string
    {
        return '<div class="card">'
            . '<div class="card__body">'
            . '<p class="card__title">' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</p>'
            . '<div id="' . htmlspecialchars($chartId, ENT_QUOTES, 'UTF-8') . '" '
            . 'class="chart-container" style="min-height:200px;"></div>'
            . '</div></div>';
    }
}
