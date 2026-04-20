<?php

declare(strict_types=1);

namespace Daems\Domain\Forum;

use Daems\Domain\Tenant\TenantId;

interface ForumRepositoryInterface
{
    /** @return ForumCategory[] with topicCount and postCount */
    public function findAllCategoriesForTenant(TenantId $tenantId): array;

    public function findCategoryBySlugForTenant(string $slug, TenantId $tenantId): ?ForumCategory;

    public function findCategoryByIdForTenant(string $id, TenantId $tenantId): ?ForumCategory;

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

    public function setTopicPinnedForTenant(string $topicId, TenantId $tenantId, bool $pinned): void;

    public function setTopicLockedForTenant(string $topicId, TenantId $tenantId, bool $locked): void;

    public function deleteTopicForTenant(string $topicId, TenantId $tenantId): void;

    public function deletePostForTenant(string $postId, TenantId $tenantId): void;

    public function updatePostContentForTenant(string $postId, TenantId $tenantId, string $content, string $editedAt): void;

    public function findPostByIdForTenant(string $postId, TenantId $tenantId): ?ForumPost;

    public function findTopicByIdForTenant(string $topicId, TenantId $tenantId): ?ForumTopic;

    /**
     * @param array<string, mixed> $filters
     * @return ForumTopic[]
     */
    public function listRecentTopicsForTenant(TenantId $tenantId, int $limit, array $filters): array;

    /**
     * @param array<string, mixed> $filters
     * @return ForumPost[]
     */
    public function listRecentPostsForTenant(TenantId $tenantId, int $limit, array $filters): array;

    public function countTopicsInCategoryForTenant(string $categoryId, TenantId $tenantId): int;

    public function updateCategoryForTenant(ForumCategory $category): void;

    public function deleteCategoryForTenant(string $categoryId, TenantId $tenantId): void;
}
