<?php

declare(strict_types=1);

namespace Daems\Tests\Support\Fake;

use Daems\Domain\Forum\ForumCategory;
use Daems\Domain\Forum\ForumPost;
use Daems\Domain\Forum\ForumRepositoryInterface;
use Daems\Domain\Forum\ForumTopic;

final class InMemoryForumRepository implements ForumRepositoryInterface
{
    /** @var array<string, ForumCategory> by slug */
    public array $categoriesBySlug = [];

    /** @var array<string, ForumTopic> by slug */
    public array $topicsBySlug = [];

    /** @var list<ForumPost> */
    public array $posts = [];

    public function findAllCategories(): array
    {
        return array_values($this->categoriesBySlug);
    }

    public function findCategoryBySlug(string $slug): ?ForumCategory
    {
        return $this->categoriesBySlug[$slug] ?? null;
    }

    public function findTopicsByCategory(string $categoryId): array
    {
        return array_values(array_filter(
            $this->topicsBySlug,
            fn(ForumTopic $t) => $t->categoryId() === $categoryId,
        ));
    }

    public function findTopicBySlug(string $slug): ?ForumTopic
    {
        return $this->topicsBySlug[$slug] ?? null;
    }

    public function findPostsByTopic(string $topicId): array
    {
        return array_values(array_filter(
            $this->posts,
            fn(ForumPost $p) => $p->topicId() === $topicId,
        ));
    }

    public function findCategorySlugById(string $categoryId): string
    {
        foreach ($this->categoriesBySlug as $slug => $cat) {
            if ($cat->id()->value() === $categoryId) {
                return $slug;
            }
        }
        return '';
    }

    public function saveCategory(ForumCategory $category): void
    {
        $this->categoriesBySlug[$category->slug()] = $category;
    }

    public function saveTopic(ForumTopic $topic): void
    {
        $this->topicsBySlug[$topic->slug()] = $topic;
    }

    public function savePost(ForumPost $post): void
    {
        $this->posts[] = $post;
    }

    public function recordTopicReply(string $topicId, string $lastActivityAt, string $lastActivityBy): void
    {
        // noop
    }

    public function incrementTopicViews(string $topicId): void
    {
        // noop
    }

    public function incrementPostLikes(string $postId): void
    {
        // noop
    }

    public function findPostsByUserId(string $userId, int $limit = 5): array
    {
        $posts = array_values(array_filter($this->posts, fn(ForumPost $p) => $p->userId() === $userId));
        return ['total' => count($posts), 'posts' => array_slice($posts, 0, $limit)];
    }
}
