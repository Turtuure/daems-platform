<?php

declare(strict_types=1);

namespace Daems\Application\Forum\LikeForumPost;

final class LikeForumPostInput
{
    public function __construct(
        public readonly string $postId,
    ) {}
}
