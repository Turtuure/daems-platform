<?php

declare(strict_types=1);

namespace Daems\Application\Forum\CreateForumPost;

final class CreateForumPostInput
{
    public function __construct(
        public readonly string $topicSlug,
        public readonly string $content,
        public readonly ?string $userId,
        public readonly string $authorName,
        public readonly string $avatarInitials,
        public readonly ?string $avatarColor,
        public readonly string $role,
        public readonly string $roleClass,
        public readonly string $joinedText,
    ) {}
}
