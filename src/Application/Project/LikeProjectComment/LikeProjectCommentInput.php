<?php

declare(strict_types=1);

namespace Daems\Application\Project\LikeProjectComment;

final class LikeProjectCommentInput
{
    public function __construct(public readonly string $commentId) {}
}
