<?php

declare(strict_types=1);

namespace Daems\Application\Project\LikeProjectComment;

use Daems\Domain\Project\ProjectRepositoryInterface;

final class LikeProjectComment
{
    public function __construct(private readonly ProjectRepositoryInterface $projects) {}

    public function execute(LikeProjectCommentInput $input): void
    {
        $this->projects->incrementCommentLikes($input->commentId);
    }
}
