<?php

declare(strict_types=1);

namespace Daems\Domain\Forum;

final class ForumCategory
{
    public function __construct(
        private readonly ForumCategoryId $id,
        private readonly string $slug,
        private readonly string $name,
        private readonly string $icon,
        private readonly string $description,
        private readonly int $sortOrder,
        private readonly int $topicCount = 0,
        private readonly int $postCount = 0,
    ) {}

    public function id(): ForumCategoryId { return $this->id; }
    public function slug(): string { return $this->slug; }
    public function name(): string { return $this->name; }
    public function icon(): string { return $this->icon; }
    public function description(): string { return $this->description; }
    public function sortOrder(): int { return $this->sortOrder; }
    public function topicCount(): int { return $this->topicCount; }
    public function postCount(): int { return $this->postCount; }
}
