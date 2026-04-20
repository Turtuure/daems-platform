<?php

declare(strict_types=1);

namespace Daems\Application\Backstage\Forum\LockForumTopic;

use Daems\Domain\Auth\ActingUser;

final class LockForumTopicInput
{
    public function __construct(
        public readonly ActingUser $acting,
        public readonly string $topicId,
    ) {}
}
