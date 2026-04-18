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
        return new Insight(
            InsightId::fromString($row['id']),
            $row['slug'],
            $row['title'],
            $row['category'],
            $row['category_label'],
            (bool) $row['featured'],
            $row['published_date'],
            $row['author'],
            (int) $row['reading_time'],
            $row['excerpt'],
            $row['hero_image'],
            json_decode($row['tags_json'] ?? '[]', true) ?: [],
            $row['content'],
        );
    }
}
