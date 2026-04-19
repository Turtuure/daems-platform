<?php

declare(strict_types=1);

namespace Daems\Application\Forum\IncrementTopicView;

use Daems\Domain\Forum\ForumRepositoryInterface;

final class IncrementTopicView
{
    public function __construct(
        private readonly ForumRepositoryInterface $forum,
    ) {}

    public function execute(IncrementTopicViewInput $input): void
    {
        $topic = $this->forum->findTopicBySlugForTenant($input->topicSlug, $input->tenantId);

        if ($topic !== null) {
            $this->forum->incrementTopicViews($topic->id()->value());
        }
    }
}
