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

    public function findCategoryByIdForTenant(string $id, TenantId $tenantId): ?ForumCategory
    {
        foreach ($this->categoriesBySlug as $c) {
            if ($c->id()->value() === $id && $c->tenantId()->equals($tenantId)) {
                return $c;
            }
        }
        return null;
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

    public function setTopicPinnedForTenant(string $topicId, TenantId $tenantId, bool $pinned): void
    {
        foreach ($this->topicsBySlug as $slug => $t) {
            if ($t->id()->value() === $topicId && $t->tenantId()->equals($tenantId)) {
                $this->topicsBySlug[$slug] = new ForumTopic(
                    $t->id(),
                    $t->tenantId(),
                    $t->categoryId(),
                    $t->userId(),
                    $t->slug(),
                    $t->title(),
                    $t->authorName(),
                    $t->avatarInitials(),
                    $t->avatarColor(),
                    $pinned,
                    $t->replyCount(),
                    $t->viewCount(),
                    $t->lastActivityAt(),
                    $t->lastActivityBy(),
                    $t->createdAt(),
                    $t->locked(),
                );
                return;
            }
        }
    }

    public function setTopicLockedForTenant(string $topicId, TenantId $tenantId, bool $locked): void
    {
        foreach ($this->topicsBySlug as $slug => $t) {
            if ($t->id()->value() === $topicId && $t->tenantId()->equals($tenantId)) {
                $this->topicsBySlug[$slug] = new ForumTopic(
                    $t->id(),
                    $t->tenantId(),
                    $t->categoryId(),
                    $t->userId(),
                    $t->slug(),
                    $t->title(),
                    $t->authorName(),
                    $t->avatarInitials(),
                    $t->avatarColor(),
                    $t->pinned(),
                    $t->replyCount(),
                    $t->viewCount(),
                    $t->lastActivityAt(),
                    $t->lastActivityBy(),
                    $t->createdAt(),
                    $locked,
                );
                return;
            }
        }
    }

    public function deleteTopicForTenant(string $topicId, TenantId $tenantId): void
    {
        foreach ($this->topicsBySlug as $slug => $t) {
            if ($t->id()->value() === $topicId && $t->tenantId()->equals($tenantId)) {
                unset($this->topicsBySlug[$slug]);
                break;
            }
        }
        $this->posts = array_values(array_filter(
            $this->posts,
            static fn(ForumPost $p): bool => !($p->topicId() === $topicId && $p->tenantId()->equals($tenantId)),
        ));
    }

    public function deletePostForTenant(string $postId, TenantId $tenantId): void
    {
        $this->posts = array_values(array_filter(
            $this->posts,
            static fn(ForumPost $p): bool => !($p->id()->value() === $postId && $p->tenantId()->equals($tenantId)),
        ));
    }

    public function updatePostContentForTenant(string $postId, TenantId $tenantId, string $content, string $editedAt): void
    {
        foreach ($this->posts as $i => $p) {
            if ($p->id()->value() === $postId && $p->tenantId()->equals($tenantId)) {
                $this->posts[$i] = new ForumPost(
                    $p->id(),
                    $p->tenantId(),
                    $p->topicId(),
                    $p->userId(),
                    $p->authorName(),
                    $p->avatarInitials(),
                    $p->avatarColor(),
                    $p->role(),
                    $p->roleClass(),
                    $p->joinedText(),
                    $content,
                    $p->likes(),
                    $p->createdAt(),
                    $p->sortOrder(),
                    $editedAt,
                );
                return;
            }
        }
    }

    public function findPostByIdForTenant(string $postId, TenantId $tenantId): ?ForumPost
    {
        foreach ($this->posts as $p) {
            if ($p->id()->value() === $postId && $p->tenantId()->equals($tenantId)) {
                return $p;
            }
        }
        return null;
    }

    public function findTopicByIdForTenant(string $topicId, TenantId $tenantId): ?ForumTopic
    {
        foreach ($this->topicsBySlug as $t) {
            if ($t->id()->value() === $topicId && $t->tenantId()->equals($tenantId)) {
                return $t;
            }
        }
        return null;
    }

    public function listRecentTopicsForTenant(TenantId $tenantId, int $limit, array $filters): array
    {
        $categoryId = (!empty($filters['category_id']) && is_string($filters['category_id'])) ? $filters['category_id'] : null;
        $pinnedOnly = !empty($filters['pinned_only']);
        $lockedOnly = !empty($filters['locked_only']);
        $q = (!empty($filters['q']) && is_string($filters['q'])) ? $filters['q'] : null;

        $topics = array_values(array_filter(
            $this->topicsBySlug,
            static function (ForumTopic $t) use ($tenantId, $categoryId, $pinnedOnly, $lockedOnly, $q): bool {
                if (!$t->tenantId()->equals($tenantId)) {
                    return false;
                }
                if ($categoryId !== null && $t->categoryId() !== $categoryId) {
                    return false;
                }
                if ($pinnedOnly && !$t->pinned()) {
                    return false;
                }
                if ($lockedOnly && !$t->locked()) {
                    return false;
                }
                if ($q !== null && stripos($t->title(), $q) === false) {
                    return false;
                }
                return true;
            },
        ));

        usort($topics, static fn(ForumTopic $a, ForumTopic $b): int => strcmp($b->createdAt(), $a->createdAt()));

        return array_slice($topics, 0, $limit);
    }

    public function listRecentPostsForTenant(TenantId $tenantId, int $limit, array $filters): array
    {
        $topicId = (!empty($filters['topic_id']) && is_string($filters['topic_id'])) ? $filters['topic_id'] : null;
        $q = (!empty($filters['q']) && is_string($filters['q'])) ? $filters['q'] : null;

        $posts = array_values(array_filter(
            $this->posts,
            static function (ForumPost $p) use ($tenantId, $topicId, $q): bool {
                if (!$p->tenantId()->equals($tenantId)) {
                    return false;
                }
                if ($topicId !== null && $p->topicId() !== $topicId) {
                    return false;
                }
                if ($q !== null && stripos($p->content(), $q) === false) {
                    return false;
                }
                return true;
            },
        ));

        usort($posts, static fn(ForumPost $a, ForumPost $b): int => strcmp($b->createdAt(), $a->createdAt()));

        return array_slice($posts, 0, $limit);
    }

    public function countTopicsInCategoryForTenant(string $categoryId, TenantId $tenantId): int
    {
        $count = 0;
        foreach ($this->topicsBySlug as $t) {
            if ($t->categoryId() === $categoryId && $t->tenantId()->equals($tenantId)) {
                $count++;
            }
        }
        return $count;
    }

    public function updateCategoryForTenant(ForumCategory $category): void
    {
        foreach ($this->categoriesBySlug as $slug => $c) {
            if ($c->id()->value() === $category->id()->value() && $c->tenantId()->equals($category->tenantId())) {
                unset($this->categoriesBySlug[$slug]);
                break;
            }
        }
        $this->categoriesBySlug[$category->slug()] = $category;
    }

    public function deleteCategoryForTenant(string $categoryId, TenantId $tenantId): void
    {
        foreach ($this->categoriesBySlug as $slug => $c) {
            if ($c->id()->value() === $categoryId && $c->tenantId()->equals($tenantId)) {
                unset($this->categoriesBySlug[$slug]);
                return;
            }
        }
    }

    public function countTopicsForTenant(TenantId $tenantId): int { return 0; }

    public function dailyNewTopicsForTenant(TenantId $tenantId): array
    {
        $out = []; $base = new \DateTimeImmutable('today');
        for ($i = 29; $i >= 0; $i--) {
            $out[] = ['date' => $base->modify("-{$i} days")->format('Y-m-d'), 'value' => 0];
        }
        return $out;
    }

    public function countCategoriesForTenant(TenantId $tenantId): int { return 0; }
}
