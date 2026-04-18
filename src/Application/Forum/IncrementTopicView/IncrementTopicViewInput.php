<?php

declare(strict_types=1);

namespace Daems\Application\Forum\IncrementTopicView;

final class IncrementTopicViewInput
{
    public function __construct(
        public readonly string $topicSlug,
    ) {}
}
