<?php

declare(strict_types=1);

namespace Daems\Tests\Support;

use Daems\Domain\Forum\ForumCategory;
use Daems\Domain\Forum\ForumCategoryId;
use Daems\Domain\Forum\ForumPost;
use Daems\Domain\Forum\ForumPostId;
use Daems\Domain\Forum\ForumTopic;
use Daems\Domain\Forum\ForumTopicId;
use Daems\Domain\Tenant\TenantId;
use Daems\Tests\Support\Fake\InMemoryForumRepository;

/**
 * Test helpers for seeding forum fixtures into an InMemoryForumRepository.
 *
 * All id arguments MUST be UUID7-compliant strings — the underlying value objects
 * validate, and non-UUID7 fixtures will throw InvalidArgumentException.
 */
final class ForumSeed
{
    /** Default well-known UUID7 fixtures, kept stable so tests can share references. */
    public const DEFAULT_CATEGORY_ID = '01958000-0000-7000-8000-00000000c001';
    public const DEFAULT_TOPIC_ID    = '01958000-0000-7000-8000-000000010001';
    public const DEFAULT_POST_ID     = '01958000-0000-7000-8000-0000000a0001';
    public const DEFAULT_USER_ID     = '01958000-0000-7000-8000-0000000b0001';

    public static function seedCategory(
        InMemoryForumRepository $forum,
        TenantId $tenantId,
        string $categoryId = self::DEFAULT_CATEGORY_ID,
        string $slug = 'general',
        string $name = 'General',
    ): ForumCategory {
        $cat = new ForumCategory(
            ForumCategoryId::fromString($categoryId),
            $tenantId,
            $slug,
            $name,
            'chat',
            'Talk about anything',
            1,
        );
        $forum->saveCategory($cat);
        return $cat;
    }

    public static function seedTopic(
        InMemoryForumRepository $forum,
        TenantId $tenantId,
        string $topicId = self::DEFAULT_TOPIC_ID,
        string $slug = 'topic-one',
        string $title = 'Topic Title',
        bool $pinned = false,
        bool $locked = false,
        ?string $categoryId = null,
        ?string $userId = self::DEFAULT_USER_ID,
    ): ForumTopic {
        if ($categoryId === null) {
            if (! self::findCategoryById($forum, $tenantId, self::DEFAULT_CATEGORY_ID)) {
                self::seedCategory($forum, $tenantId);
            }
            $categoryId = self::DEFAULT_CATEGORY_ID;
        }

        $topic = new ForumTopic(
            ForumTopicId::fromString($topicId),
            $tenantId,
            $categoryId,
            $userId,
            $slug,
            $title,
            'Jane Doe',
            'JD',
            '#ff5733',
            $pinned,
            0,
            0,
            '2025-01-01 12:00:00',
            $userId ?? 'unknown',
            '2025-01-01 12:00:00',
            $locked,
        );
        $forum->saveTopic($topic);
        return $topic;
    }

    public static function seedPost(
        InMemoryForumRepository $forum,
        TenantId $tenantId,
        string $postId = self::DEFAULT_POST_ID,
        ?string $topicId = null,
        string $content = 'dummy content',
        ?string $userId = self::DEFAULT_USER_ID,
    ): ForumPost {
        if ($topicId === null) {
            if (! self::findTopicById($forum, $tenantId, self::DEFAULT_TOPIC_ID)) {
                self::seedTopic($forum, $tenantId);
            }
            $topicId = self::DEFAULT_TOPIC_ID;
        }

        $post = new ForumPost(
            ForumPostId::fromString($postId),
            $tenantId,
            $topicId,
            $userId,
            'Jane Doe',
            'JD',
            '#ff5733',
            'Member',
            'member',
            'Joined Jan 2024',
            $content,
            0,
            '2025-01-01 12:00:00',
            1,
        );
        $forum->savePost($post);
        return $post;
    }

    /**
     * @param array<int, array{0: string, 1?: string}> $posts list of [postId, ?content]
     */
    public static function seedTopicWithPosts(
        InMemoryForumRepository $forum,
        TenantId $tenantId,
        string $topicId,
        array $posts,
    ): void {
        self::seedTopic($forum, $tenantId, $topicId);
        foreach ($posts as $entry) {
            $postId  = $entry[0];
            $content = $entry[1] ?? 'dummy content';
            self::seedPost($forum, $tenantId, $postId, $topicId, $content);
        }
    }

    private static function findCategoryById(
        InMemoryForumRepository $forum,
        TenantId $tenantId,
        string $categoryId,
    ): bool {
        foreach ($forum->findAllCategoriesForTenant($tenantId) as $c) {
            if ($c->id()->value() === strtolower($categoryId)) {
                return true;
            }
        }
        return false;
    }

    private static function findTopicById(
        InMemoryForumRepository $forum,
        TenantId $tenantId,
        string $topicId,
    ): bool {
        return $forum->findTopicByIdForTenant($topicId, $tenantId) !== null;
    }
}
