<?php

declare(strict_types=1);

namespace Daems\Application\Insight\UpdateInsight;

use Daems\Domain\Insight\InsightId;
use Daems\Domain\Tenant\TenantId;

final class UpdateInsightInput
{
    /** @param string[] $tags */
    public function __construct(
        public readonly InsightId $insightId,
        public readonly TenantId $tenantId,
        public readonly string $slug,
        public readonly string $title,
        public readonly string $category,
        public readonly string $categoryLabel,
        public readonly bool $featured,
        public readonly string $publishedDate,
        public readonly string $author,
        public readonly string $excerpt,
        public readonly ?string $heroImage,
        public readonly array $tags,
        public readonly string $content,
    ) {}
}
