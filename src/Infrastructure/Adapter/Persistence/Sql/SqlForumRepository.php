<?php

declare(strict_types=1);

namespace Daems\Infrastructure\Adapter\Persistence\Sql;

use Daems\Domain\Forum\ForumCategory;
use Daems\Domain\Forum\ForumCategoryId;
use Daems\Domain\Forum\ForumPost;
use Daems\Domain\Forum\ForumPostId;
use Daems\Domain\Forum\ForumRepositoryInterface;
use Daems\Domain\Forum\ForumTopic;
use Daems\Domain\Forum\ForumTopicId;
use Daems\Domain\Tenant\TenantId;
use Daems\Infrastructure\Framework\Database\Connection;

final class SqlForumRepository implements ForumRepositoryInterface
{
    public function __construct(private readonly Connection $db) {}

    public function findAllCategoriesForTenant(TenantId $tenantId): array
    {
        $rows = $this->db->query(
            'SELECT c.*, COUNT(DISTINCT t.id) AS topic_count, COUNT(DISTINCT p.id) AS post_count
             FROM forum_categories c
             LEFT JOIN forum_topics t ON t.category_id = c.id
             LEFT JOIN forum_posts p ON p.topic_id = t.id
             WHERE c.tenant_id = ?
             GROUP BY c.id
             ORDER BY c.sort_order ASC',
            [$tenantId->value()],
        );

        return array_map($this->hydrateCategory(...), $rows);
    }

    public function findCategoryBySlugForTenant(string $slug, TenantId $tenantId): ?ForumCategory
    {
        $row = $this->db->queryOne(
            'SELECT c.*, COUNT(DISTINCT t.id) AS topic_count, COUNT(DISTINCT p.id) AS post_count
             FROM forum_categories c
             LEFT JOIN forum_topics t ON t.category_id = c.id
             LEFT JOIN forum_posts p ON p.topic_id = t.id
             WHERE c.slug = ? AND c.tenant_id = ?
             GROUP BY c.id',
            [$slug, $tenantId->value()],
        );

        return $row !== null ? $this->hydrateCategory($row) : null;
    }

    public function findCategorySlugById(string $categoryId): string
    {
        $row = $this->db->queryOne(
            'SELECT slug FROM forum_categories WHERE id = ?',
            [$categoryId],
        );

        $slug = $row['slug'] ?? null;
        return is_string($slug) ? $slug : '';
    }

    public function findTopicsByCategory(string $categoryId): array
    {
        $rows = $this->db->query(
            'SELECT * FROM forum_topics WHERE category_id = ? ORDER BY pinned DESC, last_activity_at DESC',
            [$categoryId],
        );

        return array_map($this->hydrateTopic(...), $rows);
    }

    public function findTopicBySlugForTenant(string $slug, TenantId $tenantId): ?ForumTopic
    {
        $row = $this->db->queryOne(
            'SELECT * FROM forum_topics WHERE slug = ? AND tenant_id = ?',
            [$slug, $tenantId->value()],
        );

        return $row !== null ? $this->hydrateTopic($row) : null;
    }

    public function findPostsByTopic(string $topicId): array
    {
        $rows = $this->db->query(
            'SELECT * FROM forum_posts WHERE topic_id = ? ORDER BY sort_order ASC, created_at ASC',
            [$topicId],
        );

        return array_map($this->hydratePost(...), $rows);
    }

    public function saveCategory(ForumCategory $category): void
    {
        $this->db->execute(
            'INSERT INTO forum_categories (id, tenant_id, slug, name, icon, description, sort_order)
             VALUES (?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                name        = VALUES(name),
                icon        = VALUES(icon),
                description = VALUES(description),
                sort_order  = VALUES(sort_order)',
            [
                $category->id()->value(),
                $category->tenantId()->value(),
                $category->slug(),
                $category->name(),
                $category->icon(),
                $category->description(),
                $category->sortOrder(),
            ],
        );
    }

    public function saveTopic(ForumTopic $topic): void
    {
        $this->db->execute(
            'INSERT INTO forum_topics
                (id, tenant_id, category_id, user_id, slug, title, author_name, avatar_initials, avatar_color,
                 pinned, reply_count, view_count, last_activity_at, last_activity_by, created_at, locked)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                title            = VALUES(title),
                author_name      = VALUES(author_name),
                avatar_initials  = VALUES(avatar_initials),
                avatar_color     = VALUES(avatar_color),
                pinned           = VALUES(pinned),
                locked           = VALUES(locked),
                reply_count      = VALUES(reply_count),
                view_count       = VALUES(view_count),
                last_activity_at = VALUES(last_activity_at),
                last_activity_by = VALUES(last_activity_by)',
            [
                $topic->id()->value(),
                $topic->tenantId()->value(),
                $topic->categoryId(),
                $topic->userId(),
                $topic->slug(),
                $topic->title(),
                $topic->authorName(),
                $topic->avatarInitials(),
                $topic->avatarColor(),
                $topic->pinned() ? 1 : 0,
                $topic->replyCount(),
                $topic->viewCount(),
                $topic->lastActivityAt(),
                $topic->lastActivityBy(),
                $topic->createdAt(),
                $topic->locked() ? 1 : 0,
            ],
        );
    }

    public function savePost(ForumPost $post): void
    {
        $this->db->execute(
            'INSERT INTO forum_posts
                (id, tenant_id, topic_id, user_id, author_name, avatar_initials, avatar_color,
                 role, role_class, joined_text, content, likes, created_at, sort_order, edited_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                content    = VALUES(content),
                likes      = VALUES(likes),
                edited_at  = VALUES(edited_at)',
            [
                $post->id()->value(),
                $post->tenantId()->value(),
                $post->topicId(),
                $post->userId(),
                $post->authorName(),
                $post->avatarInitials(),
                $post->avatarColor(),
                $post->role(),
                $post->roleClass(),
                $post->joinedText(),
                $post->content(),
                $post->likes(),
                $post->createdAt(),
                $post->sortOrder(),
                $post->editedAt(),
            ],
        );
    }

    public function recordTopicReply(string $topicId, string $lastActivityAt, string $lastActivityBy): void
    {
        $this->db->execute(
            'UPDATE forum_topics
             SET reply_count      = reply_count + 1,
                 last_activity_at = ?,
                 last_activity_by = ?
             WHERE id = ?',
            [$lastActivityAt, $lastActivityBy, $topicId],
        );
    }

    public function incrementTopicViews(string $topicId): void
    {
        $this->db->execute(
            'UPDATE forum_topics SET view_count = view_count + 1 WHERE id = ?',
            [$topicId],
        );
    }

    public function incrementPostLikes(string $postId): void
    {
        $this->db->execute(
            'UPDATE forum_posts SET likes = likes + 1 WHERE id = ?',
            [$postId],
        );
    }

    public function setTopicPinnedForTenant(string $topicId, TenantId $tenantId, bool $pinned): void
    {
        $this->db->execute(
            'UPDATE forum_topics SET pinned = ? WHERE id = ? AND tenant_id = ?',
            [$pinned ? 1 : 0, $topicId, $tenantId->value()],
        );
    }

    public function setTopicLockedForTenant(string $topicId, TenantId $tenantId, bool $locked): void
    {
        $this->db->execute(
            'UPDATE forum_topics SET locked = ? WHERE id = ? AND tenant_id = ?',
            [$locked ? 1 : 0, $topicId, $tenantId->value()],
        );
    }

    public function deleteTopicForTenant(string $topicId, TenantId $tenantId): void
    {
        // FK cascade on forum_posts is not set; delete posts first explicitly.
        $this->db->execute(
            'DELETE FROM forum_posts WHERE topic_id = ? AND tenant_id = ?',
            [$topicId, $tenantId->value()],
        );
        $this->db->execute(
            'DELETE FROM forum_topics WHERE id = ? AND tenant_id = ?',
            [$topicId, $tenantId->value()],
        );
    }

    public function deletePostForTenant(string $postId, TenantId $tenantId): void
    {
        $this->db->execute(
            'DELETE FROM forum_posts WHERE id = ? AND tenant_id = ?',
            [$postId, $tenantId->value()],
        );
    }

    public function updatePostContentForTenant(string $postId, TenantId $tenantId, string $content, string $editedAt): void
    {
        $this->db->execute(
            'UPDATE forum_posts SET content = ?, edited_at = ? WHERE id = ? AND tenant_id = ?',
            [$content, $editedAt, $postId, $tenantId->value()],
        );
    }

    public function findPostByIdForTenant(string $postId, TenantId $tenantId): ?ForumPost
    {
        $row = $this->db->queryOne(
            'SELECT * FROM forum_posts WHERE id = ? AND tenant_id = ?',
            [$postId, $tenantId->value()],
        );
        return $row !== null ? $this->hydratePost($row) : null;
    }

    public function findTopicByIdForTenant(string $topicId, TenantId $tenantId): ?ForumTopic
    {
        $row = $this->db->queryOne(
            'SELECT * FROM forum_topics WHERE id = ? AND tenant_id = ?',
            [$topicId, $tenantId->value()],
        );
        return $row !== null ? $this->hydrateTopic($row) : null;
    }

    public function listRecentTopicsForTenant(TenantId $tenantId, int $limit, array $filters): array
    {
        $sql = 'SELECT * FROM forum_topics WHERE tenant_id = ?';
        $args = [$tenantId->value()];

        if (!empty($filters['category_id']) && is_string($filters['category_id'])) {
            $sql .= ' AND category_id = ?';
            $args[] = $filters['category_id'];
        }
        if (!empty($filters['pinned_only'])) {
            $sql .= ' AND pinned = 1';
        }
        if (!empty($filters['locked_only'])) {
            $sql .= ' AND locked = 1';
        }
        if (!empty($filters['q']) && is_string($filters['q'])) {
            $sql .= ' AND title LIKE ?';
            $args[] = '%' . $filters['q'] . '%';
        }
        $sql .= ' ORDER BY created_at DESC LIMIT ?';
        $args[] = $limit;

        return array_map($this->hydrateTopic(...), $this->db->query($sql, $args));
    }

    public function listRecentPostsForTenant(TenantId $tenantId, int $limit, array $filters): array
    {
        $sql = 'SELECT * FROM forum_posts WHERE tenant_id = ?';
        $args = [$tenantId->value()];
        if (!empty($filters['topic_id']) && is_string($filters['topic_id'])) {
            $sql .= ' AND topic_id = ?';
            $args[] = $filters['topic_id'];
        }
        if (!empty($filters['q']) && is_string($filters['q'])) {
            $sql .= ' AND content LIKE ?';
            $args[] = '%' . $filters['q'] . '%';
        }
        $sql .= ' ORDER BY created_at DESC LIMIT ?';
        $args[] = $limit;

        return array_map($this->hydratePost(...), $this->db->query($sql, $args));
    }

    public function countTopicsInCategoryForTenant(string $categoryId, TenantId $tenantId): int
    {
        $row = $this->db->queryOne(
            'SELECT COUNT(*) AS c FROM forum_topics WHERE category_id = ? AND tenant_id = ?',
            [$categoryId, $tenantId->value()],
        );
        $c = $row['c'] ?? 0;
        return is_numeric($c) ? (int) $c : 0;
    }

    public function updateCategoryForTenant(ForumCategory $category): void
    {
        $this->db->execute(
            'UPDATE forum_categories
                SET slug = ?, name = ?, icon = ?, description = ?, sort_order = ?
              WHERE id = ? AND tenant_id = ?',
            [
                $category->slug(),
                $category->name(),
                $category->icon(),
                $category->description(),
                $category->sortOrder(),
                $category->id()->value(),
                $category->tenantId()->value(),
            ],
        );
    }

    public function deleteCategoryForTenant(string $categoryId, TenantId $tenantId): void
    {
        $this->db->execute(
            'DELETE FROM forum_categories WHERE id = ? AND tenant_id = ?',
            [$categoryId, $tenantId->value()],
        );
    }

    /** @param array<string, mixed> $row */
    private function hydrateCategory(array $row): ForumCategory
    {
        return new ForumCategory(
            ForumCategoryId::fromString(self::str($row, 'id')),
            TenantId::fromString(self::str($row, 'tenant_id')),
            self::str($row, 'slug'),
            self::str($row, 'name'),
            self::str($row, 'icon'),
            self::str($row, 'description'),
            self::intOf($row, 'sort_order'),
            self::intOf($row, 'topic_count'),
            self::intOf($row, 'post_count'),
        );
    }

    /** @param array<string, mixed> $row */
    private function hydrateTopic(array $row): ForumTopic
    {
        return new ForumTopic(
            ForumTopicId::fromString(self::str($row, 'id')),
            TenantId::fromString(self::str($row, 'tenant_id')),
            self::str($row, 'category_id'),
            self::strOrNull($row, 'user_id'),
            self::str($row, 'slug'),
            self::str($row, 'title'),
            self::str($row, 'author_name'),
            self::str($row, 'avatar_initials'),
            self::strOrNull($row, 'avatar_color'),
            (bool) ($row['pinned'] ?? false),
            self::intOf($row, 'reply_count'),
            self::intOf($row, 'view_count'),
            self::str($row, 'last_activity_at'),
            self::str($row, 'last_activity_by'),
            self::str($row, 'created_at'),
            (bool) ($row['locked'] ?? false),
        );
    }

    public function findPostsByUserId(string $userId, int $limit = 5): array
    {
        $countRow = $this->db->queryOne(
            'SELECT COUNT(*) AS cnt FROM forum_posts WHERE user_id = ?',
            [$userId],
        );
        $cnt   = $countRow['cnt'] ?? null;
        $total = is_int($cnt) ? $cnt : (is_string($cnt) && is_numeric($cnt) ? (int) $cnt : 0);

        $rows = $this->db->query(
            'SELECT fp.id, fp.content, fp.created_at,
                    ft.slug AS topic_slug, ft.title AS thread,
                    fc.slug AS cat_slug, fc.name AS category
             FROM forum_posts fp
             JOIN forum_topics ft ON ft.id = fp.topic_id
             JOIN forum_categories fc ON fc.id = ft.category_id
             WHERE fp.user_id = ?
             ORDER BY fp.created_at DESC
             LIMIT ?',
            [$userId, $limit],
        );

        $posts = array_map(static function (array $r): array {
            $content   = is_string($r['content'] ?? null) ? (string) $r['content'] : '';
            $createdAt = is_string($r['created_at'] ?? null) ? (string) $r['created_at'] : '';
            return [
                'slug'     => is_string($r['topic_slug'] ?? null) ? $r['topic_slug'] : '',
                'cat_slug' => is_string($r['cat_slug'] ?? null) ? $r['cat_slug'] : '',
                'thread'   => is_string($r['thread'] ?? null) ? $r['thread'] : '',
                'category' => is_string($r['category'] ?? null) ? $r['category'] : '',
                'excerpt'  => mb_strimwidth($content, 0, 120, '…'),
                'date'     => date('M j, Y', strtotime($createdAt) ?: 0),
            ];
        }, $rows);

        return ['total' => $total, 'posts' => $posts];
    }

    /** @param array<string, mixed> $row */
    private function hydratePost(array $row): ForumPost
    {
        return new ForumPost(
            ForumPostId::fromString(self::str($row, 'id')),
            TenantId::fromString(self::str($row, 'tenant_id')),
            self::str($row, 'topic_id'),
            self::strOrNull($row, 'user_id'),
            self::str($row, 'author_name'),
            self::str($row, 'avatar_initials'),
            self::strOrNull($row, 'avatar_color'),
            self::str($row, 'role'),
            self::str($row, 'role_class'),
            self::str($row, 'joined_text'),
            self::str($row, 'content'),
            self::intOf($row, 'likes'),
            self::str($row, 'created_at'),
            self::intOf($row, 'sort_order'),
            self::strOrNull($row, 'edited_at'),
        );
    }

    /** @param array<string, mixed> $row */
    private static function str(array $row, string $key): string
    {
        $v = $row[$key] ?? null;
        if (is_string($v)) {
            return $v;
        }
        throw new \DomainException("Missing or non-string column: {$key}");
    }

    /** @param array<string, mixed> $row */
    private static function strOrNull(array $row, string $key): ?string
    {
        $v = $row[$key] ?? null;
        return is_string($v) ? $v : null;
    }

    /** @param array<string, mixed> $row */
    private static function intOf(array $row, string $key): int
    {
        $v = $row[$key] ?? null;
        if (is_int($v)) {
            return $v;
        }
        if (is_string($v) && is_numeric($v)) {
            return (int) $v;
        }
        return 0;
    }
}
