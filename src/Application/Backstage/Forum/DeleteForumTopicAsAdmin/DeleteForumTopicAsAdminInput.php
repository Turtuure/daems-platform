<?php

declare(strict_types=1);

namespace Daems\Application\Backstage\Forum\DeleteForumTopicAsAdmin;

use Daems\Domain\Auth\ActingUser;

final class DeleteForumTopicAsAdminInput
{
    public function __construct(
        public readonly ActingUser $acting,
        public readonly string $topicId,
    ) {}
}
