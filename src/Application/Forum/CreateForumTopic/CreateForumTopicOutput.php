<?php

declare(strict_types=1);

namespace Daems\Application\Forum\CreateForumTopic;

final class CreateForumTopicOutput
{
    public function __construct(
        public readonly ?string $topicSlug,
        public readonly ?string $error = null,
    ) {}
}
