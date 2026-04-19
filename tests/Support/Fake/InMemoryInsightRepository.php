<?php

declare(strict_types=1);

namespace Daems\Tests\Support\Fake;

use Daems\Domain\Insight\Insight;
use Daems\Domain\Insight\InsightRepositoryInterface;

final class InMemoryInsightRepository implements InsightRepositoryInterface
{
    /** @var array<string, Insight> */
    public array $bySlug = [];

    public function findAll(?string $category = null): array
    {
        return array_values($this->bySlug);
    }

    public function findBySlug(string $slug): ?Insight
    {
        return $this->bySlug[$slug] ?? null;
    }

    public function save(Insight $insight): void
    {
        $this->bySlug[$insight->slug()] = $insight;
    }
}
