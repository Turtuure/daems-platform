<?php

declare(strict_types=1);

namespace Daems\Domain\Project;

use Daems\Domain\User\UserId;

final class Project
{
    public function __construct(
        private readonly ProjectId $id,
        private readonly string $slug,
        private readonly string $title,
        private readonly string $category,
        private readonly string $icon,
        private readonly string $summary,
        private readonly string $description,
        private readonly string $status,
        private readonly int $sortOrder,
        private readonly ?UserId $ownerId = null,
    ) {}

    public function id(): ProjectId { return $this->id; }
    public function slug(): string { return $this->slug; }
    public function title(): string { return $this->title; }
    public function category(): string { return $this->category; }
    public function icon(): string { return $this->icon; }
    public function summary(): string { return $this->summary; }
    public function description(): string { return $this->description; }
    public function status(): string { return $this->status; }
    public function sortOrder(): int { return $this->sortOrder; }
    public function ownerId(): ?UserId { return $this->ownerId; }
}
