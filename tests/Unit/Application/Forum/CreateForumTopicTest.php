<?php

declare(strict_types=1);

namespace Daems\Tests\Unit\Application\Forum;

use Daems\Application\Forum\CreateForumTopic\CreateForumTopic;
use Daems\Application\Forum\CreateForumTopic\CreateForumTopicInput;
use Daems\Domain\Auth\ActingUser;
use Daems\Domain\Forum\ForumCategory;
use Daems\Domain\Forum\ForumCategoryId;
use Daems\Domain\Forum\ForumPost;
use Daems\Domain\Forum\ForumRepositoryInterface;
use Daems\Domain\Tenant\TenantId;
use Daems\Domain\Tenant\UserTenantRole;
use Daems\Domain\User\User;
use Daems\Domain\User\UserId;
use Daems\Tests\Support\Fake\InMemoryUserRepository;
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

    private function users(string $name = 'Jane Doe'): InMemoryUserRepository
    {
        $repo = new InMemoryUserRepository();
        $repo->save(new User(
            UserId::fromString($this->actingId),
            $name,
            'jane@x.com',
            password_hash('p', PASSWORD_BCRYPT),
            '1990-01-01',
            '', '', '', '', '',
            'individual',
            'active',
            null,
            '2024-01-15 12:00:00',
        ));
        return $repo;
    }

    private string $actingId;

    protected function setUp(): void
    {
        $this->actingId = UserId::generate()->value();
    }

    private function acting(string $role = 'registered'): ActingUser
    {
        // TEMP: PR 2 Task 17/18 will supply real tenant context.
        $tenantRole = UserTenantRole::tryFrom($role) ?? UserTenantRole::Registered;
        return new ActingUser(
            id:                 UserId::fromString($this->actingId),
            email:              'test@daems.fi',
            isPlatformAdmin:    false,
            activeTenant:       TenantId::fromString('01958000-0000-7000-8000-000000000001'),
            roleInActiveTenant: $tenantRole,
        );
    }

    private function input(array $overrides = []): CreateForumTopicInput
    {
        return new CreateForumTopicInput(
            $overrides['acting']       ?? $this->acting(),
            $overrides['categorySlug'] ?? 'general',
            $overrides['title']        ?? 'My First Topic',
            $overrides['content']      ?? 'This is the opening post.',
        );
    }

    public function testReturnsSlugOnSuccess(): void
    {
        $repo = $this->createMock(ForumRepositoryInterface::class);
        $repo->method('findCategoryBySlug')->willReturn($this->makeCategory());

        $out = (new CreateForumTopic($repo, $this->users()))->execute($this->input());

        $this->assertNull($out->error);
        $this->assertNotNull($out->topicSlug);
    }

    public function testReturnsErrorWhenCategoryNotFound(): void
    {
        $repo = $this->createMock(ForumRepositoryInterface::class);
        $repo->method('findCategoryBySlug')->willReturn(null);
        $repo->expects($this->never())->method('saveTopic');
        $repo->expects($this->never())->method('savePost');

        $out = (new CreateForumTopic($repo, $this->users()))
            ->execute($this->input(['categorySlug' => 'nonexistent']));

        $this->assertNull($out->topicSlug);
        $this->assertNotNull($out->error);
    }

    public function testSavesTopicAndPost(): void
    {
        $repo = $this->createMock(ForumRepositoryInterface::class);
        $repo->method('findCategoryBySlug')->willReturn($this->makeCategory());
        $repo->expects($this->once())->method('saveTopic');
        $repo->expects($this->once())->method('savePost');

        (new CreateForumTopic($repo, $this->users()))->execute($this->input());
    }

    public function testSlugIsDerivedFromTitle(): void
    {
        $repo = $this->createMock(ForumRepositoryInterface::class);
        $repo->method('findCategoryBySlug')->willReturn($this->makeCategory());

        $out = (new CreateForumTopic($repo, $this->users()))
            ->execute($this->input(['title' => 'Hello World Test']));

        $this->assertStringContainsString('hello-world-test', (string) $out->topicSlug);
    }

    public function testNonAdminCannotPostAsAdministrator(): void
    {
        $capturedPost = null;
        $repo = $this->createMock(ForumRepositoryInterface::class);
        $repo->method('findCategoryBySlug')->willReturn($this->makeCategory());
        $repo->method('savePost')->willReturnCallback(
            function (ForumPost $p) use (&$capturedPost): void {
                $capturedPost = $p;
            },
        );

        (new CreateForumTopic($repo, $this->users()))
            ->execute($this->input());

        $this->assertNotNull($capturedPost);
        $this->assertNotSame('Administrator', $capturedPost->role());
        $this->assertNotSame('role-admin', $capturedPost->roleClass());
    }

    public function testAdminUserBadgeIsAdministrator(): void
    {
        $capturedPost = null;
        $repo = $this->createMock(ForumRepositoryInterface::class);
        $repo->method('findCategoryBySlug')->willReturn($this->makeCategory());
        $repo->method('savePost')->willReturnCallback(
            function (ForumPost $p) use (&$capturedPost): void {
                $capturedPost = $p;
            },
        );

        (new CreateForumTopic($repo, $this->users()))
            ->execute($this->input(['acting' => $this->acting('admin')]));

        $this->assertNotNull($capturedPost);
        $this->assertSame('Administrator', $capturedPost->role());
        $this->assertSame('role-admin', $capturedPost->roleClass());
    }
}
