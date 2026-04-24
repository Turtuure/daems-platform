<?php
declare(strict_types=1);

namespace Daems\Domain\Search;

final class SearchHit
{
    public function __construct(
        public readonly string $entityType,
        public readonly string $entityId,
        public readonly string $title,
        public readonly string $snippet,
        public readonly string $url,
        public readonly ?string $localeCode,
        public readonly string $status,
        public readonly ?string $publishedAt,
        public readonly float $relevance,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'entity_type' => $this->entityType,
            'entity_id' => $this->entityId,
            'title' => $this->title,
            'snippet' => $this->snippet,
            'url' => $this->url,
            'locale_code' => $this->localeCode,
            'status' => $this->status,
            'published_at' => $this->publishedAt,
            'relevance' => $this->relevance,
        ];
    }
}
