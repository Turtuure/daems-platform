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
use Daems\Infrastructure\Framework\Database\Connection;

final class SqlForumRepository implements ForumRepositoryInterface
{
    public function __construct(private readonly Connection $db) {}

    public function findAllCategories(): array
    {
        $rows = $this->db->query(
            'SELECT c.*, COUNT(DISTINCT t.id) AS topic_count, COUNT(DISTINCT p.id) AS post_count
             FROM forum_categories c
             LEFT JOIN forum_topics t ON t.category_id = c.id
             LEFT JOIN forum_posts p ON p.topic_id = t.id
             GROUP BY c.id
             ORDER BY c.sort_order ASC',
        );

        return array_map($this->hydrateCategory(...), $rows);
    }

    public function findCategoryBySlug(string $slug): ?ForumCategory
    {
        $row = $this->db->queryOne(
            'SELECT c.*, COUNT(DISTINCT t.id) AS topic_count, COUNT(DISTINCT p.id) AS post_count
             FROM forum_categories c
             LEFT JOIN forum_topics t ON t.category_id = c.id
             LEFT JOIN forum_posts p ON p.topic_id = t.id
             WHERE c.slug = ?
             GROUP BY c.id',
            [$slug],
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

    public function findTopicBySlug(string $slug): ?ForumTopic
    {
        $row = $this->db->queryOne(
            'SELECT * FROM forum_topics WHERE slug = ?',
            [$slug],
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
            'INSERT INTO forum_categories (id, slug, name, icon, description, sort_order)
             VALUES (?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                name        = VALUES(name),
                icon        = VALUES(icon),
                description = VALUES(description),
                sort_order  = VALUES(sort_order)',
            [
                $category->id()->value(),
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
                (id, category_id, user_id, slug, title, author_name, avatar_initials, avatar_color,
                 pinned, reply_count, view_count, last_activity_at, last_activity_by, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                title            = VALUES(title),
                author_name      = VALUES(author_name),
                avatar_initials  = VALUES(avatar_initials),
                avatar_color     = VALUES(avatar_color),
                pinned           = VALUES(pinned),
                reply_count      = VALUES(reply_count),
                view_count       = VALUES(view_count),
                last_activity_at = VALUES(last_activity_at),
                last_activity_by = VALUES(last_activity_by)',
            [
                $topic->id()->value(),
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
            ],
        );
    }

    public function savePost(ForumPost $post): void
    {
        $this->db->execute(
            'INSERT INTO forum_posts
                (id, topic_id, user_id, author_name, avatar_initials, avatar_color,
                 role, role_class, joined_text, content, likes, created_at, sort_order)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                content    = VALUES(content),
                likes      = VALUES(likes)',
            [
                $post->id()->value(),
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

    private function hydrateCategory(array $row): ForumCategory
    {
        return new ForumCategory(
            ForumCategoryId::fromString($row['id']),
            $row['slug'],
            $row['name'],
            $row['icon'],
            $row['description'],
            (int) $row['sort_order'],
            (int) ($row['topic_count'] ?? 0),
            (int) ($row['post_count'] ?? 0),
        );
    }

    private function hydrateTopic(array $row): ForumTopic
    {
        return new ForumTopic(
            ForumTopicId::fromString($row['id']),
            $row['category_id'],
            $row['user_id'] ?? null,
            $row['slug'],
            $row['title'],
            $row['author_name'],
            $row['avatar_initials'],
            $row['avatar_color'] ?? null,
            (bool) $row['pinned'],
            (int) $row['reply_count'],
            (int) $row['view_count'],
            $row['last_activity_at'],
            $row['last_activity_by'],
            $row['created_at'],
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

    private function hydratePost(array $row): ForumPost
    {
        return new ForumPost(
            ForumPostId::fromString($row['id']),
            $row['topic_id'],
            $row['user_id'] ?? null,
            $row['author_name'],
            $row['avatar_initials'],
            $row['avatar_color'] ?? null,
            $row['role'],
            $row['role_class'],
            $row['joined_text'],
            $row['content'],
            (int) $row['likes'],
            $row['created_at'],
            (int) $row['sort_order'],
        );
    }
}
