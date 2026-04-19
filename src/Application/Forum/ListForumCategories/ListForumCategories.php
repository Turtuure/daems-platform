<?php

declare(strict_types=1);

namespace Daems\Application\Forum\ListForumCategories;

use Daems\Domain\Forum\ForumCategory;
use Daems\Domain\Forum\ForumRepositoryInterface;

final class ListForumCategories
{
    public function __construct(
        private readonly ForumRepositoryInterface $forum,
    ) {}

    public function execute(ListForumCategoriesInput $input): ListForumCategoriesOutput
    {
        $categories = $this->forum->findAllCategoriesForTenant($input->tenantId);

        return new ListForumCategoriesOutput(
            array_map(fn(ForumCategory $c) => [
                'id'          => $c->id()->value(),
                'slug'        => $c->slug(),
                'name'        => $c->name(),
                'icon'        => $c->icon(),
                'description' => $c->description(),
                'topic_count' => $c->topicCount(),
                'post_count'  => $c->postCount(),
            ], $categories),
        );
    }
}
