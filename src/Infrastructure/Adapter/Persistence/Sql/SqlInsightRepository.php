<?php

declare(strict_types=1);

namespace Daems\Infrastructure\Adapter\Persistence\Sql;

use Daems\Domain\Insight\Insight;
use Daems\Domain\Insight\InsightId;
use Daems\Domain\Insight\InsightRepositoryInterface;
use Daems\Infrastructure\Framework\Database\Connection;

final class SqlInsightRepository implements InsightRepositoryInterface
{
    public function __construct(private readonly Connection $db) {}

    public function findAll(?string $category = null): array
    {
        if ($category !== null) {
            $rows = $this->db->query(
                'SELECT * FROM insights WHERE category = ? ORDER BY published_date DESC',
                [$category],
            );
        } else {
            $rows = $this->db->query('SELECT * FROM insights ORDER BY published_date DESC');
        }

        return array_map($this->hydrate(...), $rows);
    }

    public function findBySlug(string $slug): ?Insight
    {
        $row = $this->db->queryOne(
            'SELECT * FROM insights WHERE slug = ?',
            [$slug],
        );

        return $row !== null ? $this->hydrate($row) : null;
    }

    public function save(Insight $insight): void
    {
        $this->db->execute(
            'INSERT INTO insights
                (id, slug, title, category, category_label, featured, published_date,
                 author, reading_time, excerpt, hero_image, tags_json, content)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
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
                content        = VALUES(content)',
            [
                $insight->id()->value(),
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
            ],
        );
    }

    private function hydrate(array $row): Insight
    {
        $tagsRaw  = $row['tags_json'] ?? null;
        $tagsJson = is_string($tagsRaw) ? $tagsRaw : '[]';
        $tags     = json_decode($tagsJson, true);
        $tags     = is_array($tags) ? $tags : [];

        return new Insight(
            InsightId::fromString(self::str($row, 'id')),
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
}
