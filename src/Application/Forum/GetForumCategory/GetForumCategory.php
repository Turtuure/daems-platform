<?php

declare(strict_types=1);

namespace Daems\Application\Forum\GetForumCategory;

use Daems\Domain\Forum\ForumTopic;
use Daems\Domain\Forum\ForumRepositoryInterface;

final class GetForumCategory
{
    public function __construct(
        private readonly ForumRepositoryInterface $forum,
    ) {}

    public function execute(GetForumCategoryInput $input): GetForumCategoryOutput
    {
        $category = $this->forum->findCategoryBySlugForTenant($input->slug, $input->tenantId);

        if ($category === null) {
            return new GetForumCategoryOutput(null);
        }

        $topics = $this->forum->findTopicsByCategory($category->id()->value());

        return new GetForumCategoryOutput([
            'category' => [
                'id'          => $category->id()->value(),
                'slug'        => $category->slug(),
                'name'        => $category->name(),
                'icon'        => $category->icon(),
                'description' => $category->description(),
            ],
            'topics' => array_map(fn(ForumTopic $t) => [
                'id'               => $t->id()->value(),
                'slug'             => $t->slug(),
                'title'            => $t->title(),
                'author_name'      => $t->authorName(),
                'avatar_initials'  => $t->avatarInitials(),
                'avatar_color'     => $t->avatarColor(),
                'pinned'           => $t->pinned(),
                'reply_count'      => $t->replyCount(),
                'view_count'       => $t->viewCount(),
                'last_activity_at' => $t->lastActivityAt(),
                'last_activity_by' => $t->lastActivityBy(),
                'created_at'       => $t->createdAt(),
            ], $topics),
        ]);
    }
}
