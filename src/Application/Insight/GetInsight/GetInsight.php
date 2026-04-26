<?php

declare(strict_types=1);

namespace Daems\Application\Insight\GetInsight;

use Daems\Domain\Insight\InsightRepositoryInterface;

final class GetInsight
{
    public function __construct(
        private readonly InsightRepositoryInterface $insights,
    ) {}

    public function execute(GetInsightInput $input): GetInsightOutput
    {
        $insight = $this->insights->findBySlugForTenant($input->slug, $input->tenantId);

        if ($insight === null) {
            return new GetInsightOutput(null);
        }

        // Public read: hide drafts (no publish datetime) and not-yet-published
        // (publish datetime in the future). Backstage uses InsightRepository
        // directly via findByIdForTenant so this guard doesn't affect editing.
        // Compares ISO datetime strings: '2026-04-26 09:00:00' ordering is
        // correct as long as both sides use the same Y-m-d H:i:s format.
        $publishDate = $insight->date();
        if ($publishDate === null || $publishDate > date('Y-m-d H:i:s')) {
            return new GetInsightOutput(null);
        }

        return new GetInsightOutput([
            'id'             => $insight->id()->value(),
            'slug'           => $insight->slug(),
            'title'          => $insight->title(),
            'category'       => $insight->category(),
            'category_label' => $insight->categoryLabel(),
            'featured'       => $insight->featured(),
            'date'           => $insight->date(),
            'author'         => $insight->author(),
            'reading_time'   => $insight->readingTime(),
            'excerpt'        => $insight->excerpt(),
            'hero_image'     => $insight->heroImage(),
            'tags'           => $insight->tags(),
            'content'        => $insight->content(),
        ]);
    }
}
