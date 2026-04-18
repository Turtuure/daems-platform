<?php

declare(strict_types=1);

namespace Daems\Application\Forum\GetForumThread;

final class GetForumThreadInput
{
    public function __construct(
        public readonly string $topicSlug,
    ) {}
}
