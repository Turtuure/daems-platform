<?php

declare(strict_types=1);

namespace Daems\Application\Backstage\Forum\EditForumPostAsAdmin;

use Daems\Domain\Auth\ActingUser;

final class EditForumPostAsAdminInput
{
    public function __construct(
        public readonly ActingUser $acting,
        public readonly string $postId,
        public readonly string $newContent,
        public readonly ?string $note = null,
    ) {}
}
