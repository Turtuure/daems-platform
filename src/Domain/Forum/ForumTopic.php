<?php

declare(strict_types=1);

namespace Daems\Domain\Forum;

use Daems\Domain\Tenant\TenantId;

final class ForumTopic
{
    public function __construct(
        private readonly ForumTopicId $id,
        private readonly TenantId $tenantId,
        private readonly string $categoryId,
        private readonly ?string $userId,
        private readonly string $slug,
        private readonly string $title,
        private readonly string $authorName,
        private readonly string $avatarInitials,
        private readonly ?string $avatarColor,
        private readonly bool $pinned,
        private readonly int $replyCount,
        private readonly int $viewCount,
        private readonly string $lastActivityAt,
        private readonly string $lastActivityBy,
        private readonly string $createdAt,
    ) {}

    public function id(): ForumTopicId { return $this->id; }
    public function tenantId(): TenantId { return $this->tenantId; }
    public function categoryId(): string { return $this->categoryId; }
    public function userId(): ?string { return $this->userId; }
    public function slug(): string { return $this->slug; }
    public function title(): string { return $this->title; }
    public function authorName(): string { return $this->authorName; }
    public function avatarInitials(): string { return $this->avatarInitials; }
    public function avatarColor(): ?string { return $this->avatarColor; }
    public function pinned(): bool { return $this->pinned; }
    public function replyCount(): int { return $this->replyCount; }
    public function viewCount(): int { return $this->viewCount; }
    public function lastActivityAt(): string { return $this->lastActivityAt; }
    public function lastActivityBy(): string { return $this->lastActivityBy; }
    public function createdAt(): string { return $this->createdAt; }
}
