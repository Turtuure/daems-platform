<?php

declare(strict_types=1);

namespace Daems\Application\Forum\LikeForumPost;

use Daems\Domain\Forum\ForumRepositoryInterface;

final class LikeForumPost
{
    public function __construct(
        private readonly ForumRepositoryInterface $forum,
    ) {}

    public function execute(LikeForumPostInput $input): void
    {
        $this->forum->incrementPostLikes($input->postId);
    }
}
