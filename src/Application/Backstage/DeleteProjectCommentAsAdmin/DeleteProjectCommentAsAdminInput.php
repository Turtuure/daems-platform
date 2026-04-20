<?php

declare(strict_types=1);

namespace Daems\Application\Backstage\DeleteProjectCommentAsAdmin;

use Daems\Domain\Auth\ActingUser;

final class DeleteProjectCommentAsAdminInput
{
    public function __construct(
        public readonly ActingUser $acting,
        public readonly string $projectId,
        public readonly string $commentId,
        public readonly ?string $reason,
    ) {}
}
