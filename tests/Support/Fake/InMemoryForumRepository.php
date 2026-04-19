<?php

declare(strict_types=1);

namespace Daems\Tests\Support\Fake;

use Daems\Domain\Forum\ForumCategory;
use Daems\Domain\Forum\ForumPost;
use Daems\Domain\Forum\ForumRepositoryInterface;
use Daems\Domain\Forum\ForumTopic;
use Daems\Domain\Tenant\TenantId;

final class InMemoryForumRepository implements ForumRepositoryInterface
{
    /** @var array<string, ForumCategory> by slug */
    public array $categoriesBySlug = [];

    /** @var array<string, ForumTopic> by slug */
    public array $topicsBySlug = [];

    /** @var list<ForumPost> */
    public array $posts = [];

    public function lastPost(): ?ForumPost
    {
        return $this->posts === [] ? null : $this->posts[array_key_last($this->posts)];
    }

    public function findAllCategoriesForTenant(TenantId $tenantId): array
    {
        return array_values(array_filter(
            $this->categoriesBySlug,
            static fn(ForumCategory $c): bool => $c->tenantId()->equals($tenantId),
        ));
    }

    public function findCategoryBySlugForTenant(string $slug, TenantId $tenantId): ?ForumCategory
    {
        $c = $this->categoriesBySlug[$slug] ?? null;
        if ($c === null) {
            return null;
        }
        return $c->tenantId()->equals($tenantId) ? $c : null;
    }

    public function findTopicsByCategory(string $categoryId): array
    {
        return array_values(array_filter(
            $this->topicsBySlug,
            static fn(ForumTopic $t): bool => $t->categoryId() === $categoryId,
        ));
    }

    public function findTopicBySlugForTenant(string $slug, TenantId $tenantId): ?ForumTopic
    {
        $t = $this->topicsBySlug[$slug] ?? null;
        if ($t === null) {
            return null;
        }
        return $t->tenantId()->equals($tenantId) ? $t : null;
    }

    public function findPostsByTopic(string $topicId): array
    {
        return array_values(array_filter(
            $this->posts,
            static fn(ForumPost $p): bool => $p->topicId() === $topicId,
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
        $posts = array_values(array_filter($this->posts, static fn(ForumPost $p): bool => $p->userId() === $userId));
        return ['total' => count($posts), 'posts' => array_slice($posts, 0, $limit)];
    }
}
