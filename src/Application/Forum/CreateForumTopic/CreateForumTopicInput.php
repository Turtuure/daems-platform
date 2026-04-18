<?php

declare(strict_types=1);

namespace Daems\Application\Forum\CreateForumTopic;

final class CreateForumTopicInput
{
    public function __construct(
        public readonly string $categorySlug,
        public readonly string $title,
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
