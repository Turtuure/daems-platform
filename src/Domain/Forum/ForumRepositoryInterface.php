<?php

declare(strict_types=1);

namespace Daems\Domain\Forum;

use Daems\Domain\Tenant\TenantId;

interface ForumRepositoryInterface
{
    /** @return ForumCategory[] with topicCount and postCount */
    public function findAllCategoriesForTenant(TenantId $tenantId): array;

    public function findCategoryBySlugForTenant(string $slug, TenantId $tenantId): ?ForumCategory;

    /** @return ForumTopic[] ordered by pinned DESC, last_activity_at DESC */
    public function findTopicsByCategory(string $categoryId): array;

    public function findTopicBySlugForTenant(string $slug, TenantId $tenantId): ?ForumTopic;

    /** @return ForumPost[] ordered by sort_order ASC */
    public function findPostsByTopic(string $topicId): array;

    public function findCategorySlugById(string $categoryId): string;

    public function saveCategory(ForumCategory $category): void;

    public function saveTopic(ForumTopic $topic): void;

    public function savePost(ForumPost $post): void;

    public function recordTopicReply(string $topicId, string $lastActivityAt, string $lastActivityBy): void;

    public function incrementTopicViews(string $topicId): void;

    public function incrementPostLikes(string $postId): void;

    /** @return array{total:int, posts:array} */
    public function findPostsByUserId(string $userId, int $limit = 5): array;
}
