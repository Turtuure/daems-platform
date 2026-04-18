<?php

declare(strict_types=1);

namespace Daems\Application\Project\AddProjectComment;

final class AddProjectCommentInput
{
    public function __construct(
        public readonly string $slug,
        public readonly string $userId,
        public readonly string $authorName,
        public readonly string $avatarInitials,
        public readonly string $avatarColor,
        public readonly string $content,
    ) {}
}
