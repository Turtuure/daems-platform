<?php

declare(strict_types=1);

namespace Daems\Tests\Unit\Application\Project;

use Daems\Application\Project\UpdateProject\UpdateProject;
use Daems\Application\Project\UpdateProject\UpdateProjectInput;
use Daems\Domain\Auth\ActingUser;
use Daems\Domain\Auth\ForbiddenException;
use Daems\Domain\Project\Project;
use Daems\Domain\Project\ProjectId;
use Daems\Domain\Project\ProjectRepositoryInterface;
use Daems\Domain\Tenant\TenantId;
use Daems\Domain\Tenant\UserTenantRole;
use Daems\Domain\User\UserId;
use PHPUnit\Framework\TestCase;

final class UpdateProjectTest extends TestCase
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

    private function project(?UserId $ownerId = null): Project
    {
        return new Project(
            ProjectId::generate(),
            'my-project',
            'Title',
            'cat',
            'icon',
            'summary',
            'desc',
            'active',
            0,
            $ownerId,
        );
    }

    private function input(ActingUser $acting): UpdateProjectInput
    {
        return new UpdateProjectInput($acting, 'my-project', 'New Title', 'cat', 'icon', 's', 'd', 'active');
    }

    public function testOwnerCanUpdate(): void
    {
        $ownerId = UserId::generate();
        $project = $this->project($ownerId);

        $repo = $this->createMock(ProjectRepositoryInterface::class);
        $repo->method('findBySlug')->willReturn($project);
        $repo->expects($this->once())->method('save');

        (new UpdateProject($repo))->execute($this->input($this->acting($ownerId)));
    }

    public function testNonOwnerForbidden(): void
    {
        $this->expectException(ForbiddenException::class);
        $project = $this->project(UserId::generate());

        $repo = $this->createMock(ProjectRepositoryInterface::class);
        $repo->method('findBySlug')->willReturn($project);

        (new UpdateProject($repo))->execute($this->input($this->acting(UserId::generate())));
    }

    public function testAdminCanUpdateAnyProject(): void
    {
        $project = $this->project(UserId::generate());

        $repo = $this->createMock(ProjectRepositoryInterface::class);
        $repo->method('findBySlug')->willReturn($project);
        $repo->expects($this->once())->method('save');

        (new UpdateProject($repo))->execute($this->input($this->acting(UserId::generate(), 'admin')));
    }

    public function testLegacyNullOwnerForbiddenForNonAdmin(): void
    {
        $this->expectException(ForbiddenException::class);
        $project = $this->project(null);

        $repo = $this->createMock(ProjectRepositoryInterface::class);
        $repo->method('findBySlug')->willReturn($project);

        (new UpdateProject($repo))->execute($this->input($this->acting(UserId::generate())));
    }

    public function testLegacyNullOwnerAllowedForAdmin(): void
    {
        $project = $this->project(null);

        $repo = $this->createMock(ProjectRepositoryInterface::class);
        $repo->method('findBySlug')->willReturn($project);
        $repo->expects($this->once())->method('save');

        (new UpdateProject($repo))->execute($this->input($this->acting(UserId::generate(), 'admin')));
    }

    public function testProjectNotFoundReturnsError(): void
    {
        $repo = $this->createMock(ProjectRepositoryInterface::class);
        $repo->method('findBySlug')->willReturn(null);

        $out = (new UpdateProject($repo))->execute($this->input($this->acting(UserId::generate(), 'admin')));
        $this->assertFalse($out->success);
        $this->assertNotNull($out->error);
    }
}
