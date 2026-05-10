<?php
declare(strict_types=1);

namespace Daems\Infrastructure\Dashboard;

final class WidgetRenderer
{
    public static function kpi(int $value, float $change = 0.0): string
    {
        $changeStr = $change >= 0
            ? '+' . number_format($change, 1)
            : number_format($change, 1);

        return '<div class="card card--metric">'
            . '<div class="card__body">'
            . '<div class="metric-value">' . $value . '</div>'
            . '<div class="metric-change">' . $changeStr . '%</div>'
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
