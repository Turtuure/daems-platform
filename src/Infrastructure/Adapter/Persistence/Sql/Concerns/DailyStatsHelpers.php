<?php

declare(strict_types=1);

namespace Daems\Infrastructure\Adapter\Persistence\Sql\Concerns;

/**
 * Shared helpers for SQL repositories that emit per-section KPI strips
 * (counts + 30-day daily sparklines).
 *
 * Two responsibilities:
 *  1. Coerce a SQL column value to int (nulls / decimal-strings / unset keys → 0).
 *  2. Zero-fill a SQL grouped-by-date result into a 30-entry BACKWARD daily
 *     series (today-29 first, today last).
 *
 * The series builder takes an already-fetched rows array (not a SQL string) so
 * each repo can fetch via its own Connection / PDO path and just hand the rows
 * to the trait. Each input row is expected to have a string 'd' (YYYY-MM-DD)
 * and an int-coercible 'n' column.
 */
trait DailyStatsHelpers
{
    /**
     * @param mixed $row
     */
    private static function asStatsInt($row, string $key): int
    {
        if (!is_array($row)) {
            return 0;
        }
        $v = $row[$key] ?? 0;
        if (is_int($v)) {
            return $v;
        }
        if (is_string($v) && is_numeric($v)) {
            return (int) $v;
        }
        return 0;
    }

    /**
     * Build a 30-entry zero-filled backward daily series (today-29 .. today)
     * from a SQL result grouped by `DATE(...)`. Each input row is expected to
     * have keys 'd' (string YYYY-MM-DD) and 'n' (int).
     *
     * @param list<array<string, mixed>> $rows
     * @return list<array{date: string, value: int}>
     */
    private static function buildDailySeries30dBackward(array $rows): array
    {
        $byDate = [];
        foreach ($rows as $r) {
            $d = isset($r['d']) && is_string($r['d']) ? $r['d'] : '';
            if ($d !== '') {
                $byDate[$d] = self::asStatsInt($r, 'n');
            }
        }
        $today = new \DateTimeImmutable('today');
        $out = [];
        for ($i = 29; $i >= 0; $i--) {
            $d = $today->modify('-' . $i . ' days')->format('Y-m-d');
            $out[] = ['date' => $d, 'value' => $byDate[$d] ?? 0];
        }
        return $out;
    }
}
