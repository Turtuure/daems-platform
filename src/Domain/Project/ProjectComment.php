<?php

declare(strict_types=1);

namespace Daems\Domain\Project;

final class ProjectComment
{
    public function __construct(
        private readonly ProjectCommentId $id,
        private readonly string $projectId,
        private readonly string $userId,
        private readonly string $authorName,
        private readonly string $avatarInitials,
        private readonly string $avatarColor,
        private readonly string $content,
        private readonly int $likes,
        private readonly string $createdAt,
    ) {}

    public function id(): ProjectCommentId { return $this->id; }
    public function projectId(): string { return $this->projectId; }
    public function userId(): string { return $this->userId; }
    public function authorName(): string { return $this->authorName; }
    public function avatarInitials(): string { return $this->avatarInitials; }
    public function avatarColor(): string { return $this->avatarColor; }
    public function content(): string { return $this->content; }
    public function likes(): int { return $this->likes; }
    public function createdAt(): string { return $this->createdAt; }
}
