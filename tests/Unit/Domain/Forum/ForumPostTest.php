<?php

declare(strict_types=1);

namespace Daems\Tests\Unit\Domain\Forum;

use Daems\Domain\Forum\ForumPost;
use Daems\Domain\Forum\ForumPostId;
use PHPUnit\Framework\TestCase;

final class ForumPostTest extends TestCase
{
    private function makePost(array $overrides = []): ForumPost
    {
        return new ForumPost(
            $overrides['id']             ?? ForumPostId::generate(),
            $overrides['topicId']        ?? 'topic-uuid-0001',
            $overrides['userId']         ?? 'user-uuid-0001',
            $overrides['authorName']     ?? 'Jane Doe',
            $overrides['avatarInitials'] ?? 'JD',
            $overrides['avatarColor']    ?? '#ff5733',
            $overrides['role']           ?? 'Member',
            $overrides['roleClass']      ?? 'member',
            $overrides['joinedText']     ?? 'Joined Jan 2024',
            $overrides['content']        ?? 'Post body text',
            $overrides['likes']          ?? 0,
            $overrides['createdAt']      ?? '2025-01-01 12:00:00',
            $overrides['sortOrder']      ?? 1,
        );
    }

    public function testGettersReturnConstructorValues(): void
    {
        $id   = ForumPostId::generate();
        $post = $this->makePost(['id' => $id, 'content' => 'Hello there', 'topicId' => 'tid-001']);

        $this->assertSame($id, $post->id());
        $this->assertSame('Hello there', $post->content());
        $this->assertSame('tid-001', $post->topicId());
    }

    public function testLikesAndSortOrderAreStored(): void
    {
        $post = $this->makePost(['likes' => 7, 'sortOrder' => 3]);

        $this->assertSame(7, $post->likes());
        $this->assertSame(3, $post->sortOrder());
    }

    public function testNullableUserIdIsAccepted(): void
    {
        $post = new ForumPost(
            ForumPostId::generate(),
            'topic-uuid-0001',
            null,
            'Guest',
            'GU',
            null,
            'Guest',
            'guest',
            'Joined unknown',
            'Post body',
            0,
            '2025-01-01 12:00:00',
            1,
        );

        $this->assertNull($post->userId());
    }

    public function testNullableAvatarColorIsAccepted(): void
    {
        $post = new ForumPost(
            ForumPostId::generate(),
            'topic-uuid-0001',
            'user-uuid-0001',
            'Jane Doe',
            'JD',
            null,
            'Member',
            'member',
            'Joined Jan 2024',
            'Post body',
            0,
            '2025-01-01 12:00:00',
            1,
        );

        $this->assertNull($post->avatarColor());
    }

    public function testRoleFieldsAreStored(): void
    {
        $post = $this->makePost(['role' => 'Admin', 'roleClass' => 'admin']);

        $this->assertSame('Admin', $post->role());
        $this->assertSame('admin', $post->roleClass());
    }
}
