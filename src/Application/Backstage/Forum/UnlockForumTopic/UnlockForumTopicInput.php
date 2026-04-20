<?php

declare(strict_types=1);

namespace Daems\Application\Backstage\Forum\UnlockForumTopic;

use Daems\Domain\Auth\ActingUser;

final class UnlockForumTopicInput
{
    public function __construct(
        public readonly ActingUser $acting,
        public readonly string $topicId,
    ) {}
}
