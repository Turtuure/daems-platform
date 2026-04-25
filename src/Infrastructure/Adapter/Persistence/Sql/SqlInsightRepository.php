<?php

declare(strict_types=1);

namespace Daems\Infrastructure\Adapter\Persistence\Sql;

use Daems\Domain\Insight\Insight;
use Daems\Domain\Insight\InsightId;
use Daems\Domain\Insight\InsightRepositoryInterface;
use Daems\Domain\Tenant\TenantId;
use Daems\Infrastructure\Framework\Database\Connection;

final class SqlInsightRepository implements InsightRepositoryInterface
{
    public function __construct(private readonly Connection $db) {}

    public function listForTenant(TenantId $tenantId, ?string $category = null, bool $includeUnpublished = false): array
    {
        $where  = 'tenant_id = ?';
        $params = [$tenantId->value()];
        if ($category !== null) {
            $where .= ' AND category = ?';
            $params[] = $category;
        }
        if (!$includeUnpublished) {
            $where .= ' AND published_date <= CURDATE()';
        }
        $rows = $this->db->query(
            "SELECT * FROM insights WHERE {$where} ORDER BY published_date DESC",
            $params,
        );
        return array_map($this->hydrate(...), $rows);
    }

    public function findBySlugForTenant(string $slug, TenantId $tenantId): ?Insight
    {
        $row = $this->db->queryOne(
            'SELECT * FROM insights WHERE slug = ? AND tenant_id = ?',
            [$slug, $tenantId->value()],
        );

        return $row !== null ? $this->hydrate($row) : null;
    }

    public function findByIdForTenant(InsightId $id, TenantId $tenantId): ?Insight
    {
        $row = $this->db->queryOne(
            'SELECT * FROM insights WHERE id = ? AND tenant_id = ? LIMIT 1',
            [$id->value(), $tenantId->value()],
        );
        return $row === null ? null : $this->hydrate($row);
    }

    public function delete(InsightId $id, TenantId $tenantId): void
    {
        $this->db->execute(
            'DELETE FROM insights WHERE id = ? AND tenant_id = ?',
            [$id->value(), $tenantId->value()],
        );
    }

    public function save(Insight $insight): void
    {
        $searchText = trim((string) preg_replace('/\s+/', ' ', strip_tags($insight->content())));

        $this->db->execute(
            'INSERT INTO insights
                (id, tenant_id, slug, title, category, category_label, featured, published_date,
                 author, reading_time, excerpt, hero_image, tags_json, content, search_text)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                title          = VALUES(title),
                category       = VALUES(category),
                category_label = VALUES(category_label),
                featured       = VALUES(featured),
                published_date = VALUES(published_date),
                author         = VALUES(author),
                reading_time   = VALUES(reading_time),
                excerpt        = VALUES(excerpt),
                hero_image     = VALUES(hero_image),
                tags_json      = VALUES(tags_json),
                content        = VALUES(content),
                search_text    = VALUES(search_text)',
            [
                $insight->id()->value(),
                $insight->tenantId()->value(),
                $insight->slug(),
                $insight->title(),
                $insight->category(),
                $insight->categoryLabel(),
                $insight->featured() ? 1 : 0,
                $insight->date(),
                $insight->author(),
                $insight->readingTime(),
                $insight->excerpt(),
                $insight->heroImage(),
                json_encode($insight->tags()),
                $insight->content(),
                $searchText,
            ],
        );
    }

    /**
     * @return array{
     *   published: array{value: int, sparkline: list<array{date: string, value: int}>},
     *   scheduled: array{value: int, sparkline: list<array{date: string, value: int}>},
     *   featured:  array{value: int, sparkline: list<array{date: string, value: int}>}
     * }
     */
    public function statsForTenant(TenantId $tenantId): array
    {
        $today = (new \DateTimeImmutable('today'))->format('Y-m-d');

        // Build zero-filled date templates: 30 entries each
        $publishedDays = [];
        $scheduledDays = [];
        for ($i = 29; $i >= 0; $i--) {
            $publishedDays[(new \DateTimeImmutable("-{$i} days"))->format('Y-m-d')] = 0;
        }
        for ($i = 1; $i <= 30; $i++) {
            $scheduledDays[(new \DateTimeImmutable("+{$i} days"))->format('Y-m-d')] = 0;
        }
        $featuredDays = $publishedDays; // same key range as published

        // Aggregate sparkline counts per published_date + featured flag
        $sql = <<<SQL
            SELECT
                published_date,
                featured,
                COUNT(*) AS cnt
            FROM insights
            WHERE tenant_id = ?
              AND (
                   (published_date BETWEEN DATE_SUB(?, INTERVAL 29 DAY) AND ?)
                OR (published_date BETWEEN DATE_ADD(?, INTERVAL 1 DAY) AND DATE_ADD(?, INTERVAL 30 DAY))
              )
            GROUP BY published_date, featured
        SQL;
        $rows = $this->db->query(
            $sql,
            [$tenantId->value(), $today, $today, $today, $today],
        );

        foreach ($rows as $row) {
            $date     = self::str($row, 'published_date');
            $featured = self::intCol($row, 'featured') === 1;
            $cnt      = self::intCol($row, 'cnt');

            if ($date <= $today) {
                if (isset($publishedDays[$date])) {
                    $publishedDays[$date] += $cnt;
                }
                if ($featured && isset($featuredDays[$date])) {
                    $featuredDays[$date] += $cnt;
                }
            } else {
                if (isset($scheduledDays[$date])) {
                    $scheduledDays[$date] += $cnt;
                }
            }
        }

        // Accurate totals (full history, not limited to 30-day window)
        $totals = $this->db->query(
            'SELECT
                SUM(CASE WHEN published_date <= ? THEN 1 ELSE 0 END) AS published,
                SUM(CASE WHEN published_date >  ? THEN 1 ELSE 0 END) AS scheduled,
                SUM(CASE WHEN published_date <= ? AND featured = 1 THEN 1 ELSE 0 END) AS featured
             FROM insights
             WHERE tenant_id = ?',
            [$today, $today, $today, $tenantId->value()],
        );
        $totalRow = $totals[0] ?? [];

        return [
            'published' => [
                'value'     => self::nullableIntCol($totalRow, 'published'),
                'sparkline' => self::seriesFromMap($publishedDays),
            ],
            'scheduled' => [
                'value'     => self::nullableIntCol($totalRow, 'scheduled'),
                'sparkline' => self::seriesFromMap($scheduledDays),
            ],
            'featured' => [
                'value'     => self::nullableIntCol($totalRow, 'featured'),
                'sparkline' => self::seriesFromMap($featuredDays),
            ],
        ];
    }

    /**
     * @param array<string, int> $map
     * @return list<array{date: string, value: int}>
     */
    private static function seriesFromMap(array $map): array
    {
        $out = [];
        foreach ($map as $date => $value) {
            $out[] = ['date' => $date, 'value' => $value];
        }
        return $out;
    }

    /** @param array<string, mixed> $row */
    private function hydrate(array $row): Insight
    {
        $tagsRaw  = $row['tags_json'] ?? null;
        $tagsJson = is_string($tagsRaw) ? $tagsRaw : '[]';
        $tags     = json_decode($tagsJson, true);
        $tags     = is_array($tags) ? $tags : [];

        return new Insight(
            InsightId::fromString(self::str($row, 'id')),
            TenantId::fromString(self::str($row, 'tenant_id')),
            self::str($row, 'slug'),
            self::str($row, 'title'),
            self::str($row, 'category'),
            self::str($row, 'category_label'),
            (bool) ($row['featured'] ?? false),
            self::str($row, 'published_date'),
            self::str($row, 'author'),
            self::intCol($row, 'reading_time'),
            self::str($row, 'excerpt'),
            self::strOrNull($row, 'hero_image'),
            $tags,
            self::str($row, 'content'),
        );
    }

    /** @param array<string, mixed> $row */
    private static function str(array $row, string $key): string
    {
        $v = $row[$key] ?? null;
        if (is_string($v)) {
            return $v;
        }
        throw new \DomainException("Missing or non-string column: {$key}");
    }

    /** @param array<string, mixed> $row */
    private static function strOrNull(array $row, string $key): ?string
    {
        $v = $row[$key] ?? null;
        return is_string($v) ? $v : null;
    }

    /** @param array<string, mixed> $row */
    private static function intCol(array $row, string $key): int
    {
        $v = $row[$key] ?? null;
        if (is_int($v)) {
            return $v;
        }
        if (is_string($v) && is_numeric($v)) {
            return (int) $v;
        }
        throw new \DomainException("Missing or non-int column: {$key}");
    }

    /** @param array<string, mixed> $row */
    private static function nullableIntCol(array $row, string $key): int
    {
        $v = $row[$key] ?? null;
        if ($v === null) {
            return 0;
        }
        if (is_int($v)) {
            return $v;
        }
        if (is_string($v) && is_numeric($v)) {
            return (int) $v;
        }
        return 0;
    }
}
