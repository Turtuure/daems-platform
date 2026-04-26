<?php

declare(strict_types=1);

namespace Daems\Application\Insight\ListInsights;

use Daems\Domain\Insight\Insight;
use Daems\Domain\Insight\InsightRepositoryInterface;

final class ListInsights
{
    public function __construct(
        private readonly InsightRepositoryInterface $insights,
    ) {}

    public function execute(ListInsightsInput $input): ListInsightsOutput
    {
        $insights = $this->insights->listForTenant(
            $input->tenantId,
            $input->category,
            $input->includeUnpublished,
        );

        return new ListInsightsOutput(
            array_map(fn(Insight $i) => $this->toArray($i), $insights),
        );
    }

    private function toArray(Insight $i): array
    {
        return [
            'id'             => $i->id()->value(),
            'slug'           => $i->slug(),
            'title'          => $i->title(),
            'category'       => $i->category(),
            'category_label' => $i->categoryLabel(),
            'featured'       => $i->featured(),
            // Both keys exposed for backwards compat:
            //   'date'           — legacy, consumed by daem-society public /insights page
            //   'published_date' — canonical schema name, consumed by backstage list JS
            'date'           => $i->date(),
            'published_date' => $i->date(),
            'author'         => $i->author(),
            'reading_time'   => $i->readingTime(),
            'excerpt'        => $i->excerpt(),
            'hero_image'     => $i->heroImage(),
            'tags'           => $i->tags(),
        ];
    }
}
