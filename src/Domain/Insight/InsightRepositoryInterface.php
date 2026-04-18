<?php

declare(strict_types=1);

namespace Daems\Domain\Insight;

interface InsightRepositoryInterface
{
    /** @return Insight[] */
    public function findAll(?string $category = null): array;

    public function findBySlug(string $slug): ?Insight;

    public function save(Insight $insight): void;
}
