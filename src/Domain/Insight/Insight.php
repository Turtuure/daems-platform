<?php

declare(strict_types=1);

namespace Daems\Domain\Insight;

final class Insight
{
    public function __construct(
        private readonly InsightId $id,
        private readonly string $slug,
        private readonly string $title,
        private readonly string $category,
        private readonly string $categoryLabel,
        private readonly bool $featured,
        private readonly string $date,
        private readonly string $author,
        private readonly int $readingTime,
        private readonly string $excerpt,
        private readonly ?string $heroImage,
        private readonly array $tags,
        private readonly string $content,
    ) {}

    public function id(): InsightId { return $this->id; }
    public function slug(): string { return $this->slug; }
    public function title(): string { return $this->title; }
    public function category(): string { return $this->category; }
    public function categoryLabel(): string { return $this->categoryLabel; }
    public function featured(): bool { return $this->featured; }
    public function date(): string { return $this->date; }
    public function author(): string { return $this->author; }
    public function readingTime(): int { return $this->readingTime; }
    public function excerpt(): string { return $this->excerpt; }
    public function heroImage(): ?string { return $this->heroImage; }
    public function tags(): array { return $this->tags; }
    public function content(): string { return $this->content; }
}
