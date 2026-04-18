<?php

declare(strict_types=1);

namespace Daems\Tests\Unit\Domain\Forum;

use Daems\Domain\Forum\ForumTopic;
use Daems\Domain\Forum\ForumTopicId;
use PHPUnit\Framework\TestCase;

final class ForumTopicTest extends TestCase
{
    private function makeTopic(array $overrides = []): ForumTopic
    {
        return new ForumTopic(
            $overrides['id']             ?? ForumTopicId::generate(),
            $overrides['categoryId']     ?? 'cat-uuid-0001',
            $overrides['userId']         ?? 'user-uuid-0001',
            $overrides['slug']           ?? 'hello-world-abc123',
            $overrides['title']          ?? 'Hello World',
            $overrides['authorName']     ?? 'Jane Doe',
            $overrides['avatarInitials'] ?? 'JD',
            $overrides['avatarColor']    ?? '#ff5733',
            $overrides['pinned']         ?? false,
            $overrides['replyCount']     ?? 0,
            $overrides['viewCount']      ?? 0,
            $overrides['lastActivityAt'] ?? '2025-01-01 12:00:00',
            $overrides['lastActivityBy'] ?? 'Jane Doe',
            $overrides['createdAt']      ?? '2025-01-01 12:00:00',
        );
    }

    public function testGettersReturnConstructorValues(): void
    {
        $id    = ForumTopicId::generate();
        $topic = $this->makeTopic(['id' => $id, 'title' => 'My Topic', 'slug' => 'my-topic-xyz']);

        $this->assertSame($id, $topic->id());
        $this->assertSame('My Topic', $topic->title());
        $this->assertSame('my-topic-xyz', $topic->slug());
    }

    public function testPinnedFlagIsStored(): void
    {
        $pinned   = $this->makeTopic(['pinned' => true]);
        $unpinned = $this->makeTopic(['pinned' => false]);

        $this->assertTrue($pinned->pinned());
        $this->assertFalse($unpinned->pinned());
    }

    public function testCountersAreStoredCorrectly(): void
    {
        $topic = $this->makeTopic(['replyCount' => 5, 'viewCount' => 42]);

        $this->assertSame(5, $topic->replyCount());
        $this->assertSame(42, $topic->viewCount());
    }

    public function testNullableUserIdIsAccepted(): void
    {
        $topic = new ForumTopic(
            ForumTopicId::generate(),
            'cat-uuid-0001',
            null,
            'hello-world-abc123',
            'Hello World',
            'Guest',
            'GU',
            null,
            false,
            0,
            0,
            '2025-01-01 12:00:00',
            'Guest',
            '2025-01-01 12:00:00',
        );

        $this->assertNull($topic->userId());
    }

    public function testNullableAvatarColorIsAccepted(): void
    {
        $topic = new ForumTopic(
            ForumTopicId::generate(),
            'cat-uuid-0001',
            'user-uuid-0001',
            'hello-world-abc123',
            'Hello World',
            'Jane Doe',
            'JD',
            null,
            false,
            0,
            0,
            '2025-01-01 12:00:00',
            'Jane Doe',
            '2025-01-01 12:00:00',
        );

        $this->assertNull($topic->avatarColor());
    }
}
