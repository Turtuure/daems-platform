<?php

declare(strict_types=1);

namespace Daems\Domain\Forum;

use Daems\Domain\Tenant\TenantId;

final class ForumPost
{
    public function __construct(
        private readonly ForumPostId $id,
        private readonly TenantId $tenantId,
        private readonly string $topicId,
        private readonly ?string $userId,
        private readonly string $authorName,
        private readonly string $avatarInitials,
        private readonly ?string $avatarColor,
        private readonly string $role,
        private readonly string $roleClass,
        private readonly string $joinedText,
        private readonly string $content,
        private readonly int $likes,
        private readonly string $createdAt,
        private readonly int $sortOrder,
    ) {}

    public function id(): ForumPostId { return $this->id; }
    public function tenantId(): TenantId { return $this->tenantId; }
    public function topicId(): string { return $this->topicId; }
    public function userId(): ?string { return $this->userId; }
    public function authorName(): string { return $this->authorName; }
    public function avatarInitials(): string { return $this->avatarInitials; }
    public function avatarColor(): ?string { return $this->avatarColor; }
    public function role(): string { return $this->role; }
    public function roleClass(): string { return $this->roleClass; }
    public function joinedText(): string { return $this->joinedText; }
    public function content(): string { return $this->content; }
    public function likes(): int { return $this->likes; }
    public function createdAt(): string { return $this->createdAt; }
    public function sortOrder(): int { return $this->sortOrder; }
}
