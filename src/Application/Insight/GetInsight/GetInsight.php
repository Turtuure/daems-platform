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
        $insight = $this->insights->findBySlug($input->slug);

        if ($insight === null) {
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
