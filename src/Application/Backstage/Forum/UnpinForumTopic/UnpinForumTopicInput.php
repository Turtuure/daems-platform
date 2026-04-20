<?php

declare(strict_types=1);

namespace Daems\Application\Backstage\Forum\UnpinForumTopic;

use Daems\Domain\Auth\ActingUser;

final class UnpinForumTopicInput
{
    public function __construct(
        public readonly ActingUser $acting,
        public readonly string $topicId,
    ) {}
}
