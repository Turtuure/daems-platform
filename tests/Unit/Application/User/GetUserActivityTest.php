<?php

declare(strict_types=1);

namespace Daems\Tests\Unit\Application\User;

use Daems\Application\User\GetUserActivity\GetUserActivity;
use Daems\Application\User\GetUserActivity\GetUserActivityInput;
use Daems\Domain\Auth\ActingUser;
use Daems\Domain\Auth\ForbiddenException;
use Daems\Domain\Forum\ForumRepositoryInterface;
use Daems\Domain\Tenant\TenantId;
use Daems\Domain\Tenant\UserTenantRole;
use Daems\Domain\User\UserId;
use DaemsModule\Events\Domain\EventRepositoryInterface;
use PHPUnit\Framework\TestCase;

final class GetUserActivityTest extends TestCase
{
    // TEMP: PR 2 Task 17/18 will supply real tenant context.
    private function acting(UserId $id, string $role = 'registered'): ActingUser
    {
        $tenantRole = UserTenantRole::tryFrom($role) ?? UserTenantRole::Registered;
        return new ActingUser(
            id:                 $id,
            email:              'test@daems.fi',
            isPlatformAdmin:    false,
            activeTenant:       TenantId::fromString('01958000-0000-7000-8000-000000000001'),
            roleInActiveTenant: $tenantRole,
        );
    }

    private function uc(): GetUserActivity
    {
        $forum = $this->createStub(ForumRepositoryInterface::class);
        $forum->method('findPostsByUserId')->willReturn(['posts' => [], 'total' => 0]);
        $events = $this->createStub(EventRepositoryInterface::class);
        $events->method('findRegistrationsByUserId')->willReturn([]);
        return new GetUserActivity($forum, $events);
    }

    public function testSelfReturnsData(): void
    {
        $id = UserId::generate();
        $out = $this->uc()->execute(new GetUserActivityInput($this->acting($id), $id->value()));
        $this->assertSame(0, $out->data['forum_posts']);
    }

    public function testAdminCanViewAnyone(): void
    {
        $out = $this->uc()->execute(new GetUserActivityInput($this->acting(UserId::generate(), 'admin'), UserId::generate()->value()));
        $this->assertSame(0, $out->data['forum_posts']);
    }

    public function testOtherUserForbidden(): void
    {
        $this->expectException(ForbiddenException::class);
        $this->uc()->execute(new GetUserActivityInput($this->acting(UserId::generate()), UserId::generate()->value()));
    }
}
