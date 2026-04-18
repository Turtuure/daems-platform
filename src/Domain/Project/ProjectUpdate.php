<?php

declare(strict_types=1);

namespace Daems\Domain\Project;

final class ProjectUpdate
{
    public function __construct(
        private readonly ProjectUpdateId $id,
        private readonly string $projectId,
        private readonly string $title,
        private readonly string $content,
        private readonly string $authorName,
        private readonly string $createdAt,
    ) {}

    public function id(): ProjectUpdateId { return $this->id; }
    public function projectId(): string { return $this->projectId; }
    public function title(): string { return $this->title; }
    public function content(): string { return $this->content; }
    public function authorName(): string { return $this->authorName; }
    public function createdAt(): string { return $this->createdAt; }
}
