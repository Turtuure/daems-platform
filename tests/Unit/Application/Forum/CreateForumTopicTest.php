<?php

declare(strict_types=1);

namespace Daems\Tests\Unit\Application\Forum;

use Daems\Application\Forum\CreateForumTopic\CreateForumTopic;
use Daems\Application\Forum\CreateForumTopic\CreateForumTopicInput;
use Daems\Domain\Forum\ForumCategory;
use Daems\Domain\Forum\ForumCategoryId;
use Daems\Domain\Forum\ForumRepositoryInterface;
use PHPUnit\Framework\TestCase;

final class CreateForumTopicTest extends TestCase
{
    private function makeCategory(): ForumCategory
    {
        return new ForumCategory(
            ForumCategoryId::generate(),
            'general',
            'General Discussion',
            'chat',
            'Talk about anything',
            1,
        );
    }

    private function makeInput(array $overrides = []): CreateForumTopicInput
    {
        return new CreateForumTopicInput(
            $overrides['categorySlug']    ?? 'general',
            $overrides['title']           ?? 'My First Topic',
            $overrides['content']         ?? 'This is the opening post.',
            $overrides['userId']          ?? 'user-uuid-0001',
            $overrides['authorName']      ?? 'Jane Doe',
            $overrides['avatarInitials']  ?? 'JD',
            $overrides['avatarColor']     ?? '#ff5733',
            $overrides['role']            ?? 'Member',
            $overrides['roleClass']       ?? 'member',
            $overrides['joinedText']      ?? 'Joined Jan 2024',
        );
    }

    public function testReturnsSlugOnSuccess(): void
    {
        $category = $this->makeCategory();

        $repo = $this->createMock(ForumRepositoryInterface::class);
        $repo->method('findCategoryBySlug')->willReturn($category);

        $out = (new CreateForumTopic($repo))->execute($this->makeInput());

        $this->assertNull($out->error);
        $this->assertNotNull($out->topicSlug);
    }

    public function testReturnsErrorWhenCategoryNotFound(): void
    {
        $repo = $this->createMock(ForumRepositoryInterface::class);
        $repo->method('findCategoryBySlug')->willReturn(null);
        $repo->expects($this->never())->method('saveTopic');
        $repo->expects($this->never())->method('savePost');

        $out = (new CreateForumTopic($repo))->execute($this->makeInput(['categorySlug' => 'nonexistent']));

        $this->assertNull($out->topicSlug);
        $this->assertNotNull($out->error);
    }

    public function testSavesTopicAndPost(): void
    {
        $category = $this->makeCategory();

        $repo = $this->createMock(ForumRepositoryInterface::class);
        $repo->method('findCategoryBySlug')->willReturn($category);
        $repo->expects($this->once())->method('saveTopic');
        $repo->expects($this->once())->method('savePost');

        (new CreateForumTopic($repo))->execute($this->makeInput());
    }

    public function testSlugIsDerivedFromTitle(): void
    {
        $category = $this->makeCategory();

        $repo = $this->createMock(ForumRepositoryInterface::class);
        $repo->method('findCategoryBySlug')->willReturn($category);

        $out = (new CreateForumTopic($repo))->execute($this->makeInput(['title' => 'Hello World Test']));

        $this->assertStringContainsString('hello-world-test', $out->topicSlug);
    }

    public function testNullUserIdIsAccepted(): void
    {
        $category = $this->makeCategory();

        $repo = $this->createMock(ForumRepositoryInterface::class);
        $repo->method('findCategoryBySlug')->willReturn($category);

        $out = (new CreateForumTopic($repo))->execute($this->makeInput(['userId' => null]));

        $this->assertNull($out->error);
        $this->assertNotNull($out->topicSlug);
    }
}
