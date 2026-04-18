<?php

declare(strict_types=1);

namespace Daems\Application\Forum\CreateForumPost;

use Daems\Domain\Auth\ActingUser;

final class CreateForumPostInput
{
    public function __construct(
        public readonly ActingUser $acting,
        public readonly string $topicSlug,
        public readonly string $content,
    ) {}
}
