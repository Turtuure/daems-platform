<?php

declare(strict_types=1);

namespace Daems\Tests\Unit\Application\Backstage;

use Daems\Application\Backstage\ChangeProjectStatus\ChangeProjectStatus;
use Daems\Application\Backstage\ChangeProjectStatus\ChangeProjectStatusInput;
use Daems\Domain\Auth\ActingUser;
use Daems\Domain\Auth\ForbiddenException;
use Daems\Domain\Project\Project;
use Daems\Domain\Project\ProjectId;
use Daems\Domain\Shared\NotFoundException;
use Daems\Domain\Shared\ValidationException;
use Daems\Domain\Tenant\TenantId;
use Daems\Domain\Tenant\UserTenantRole;
use Daems\Domain\User\UserId;
use Daems\Tests\Support\Fake\InMemoryProjectRepository;
use PHPUnit\Framework\TestCase;

final class ChangeProjectStatusTest extends TestCase
{
    private const TENANT = '01958000-0000-7000-8000-000000000001';
    private const OTHER_TENANT = '01958000-0000-7000-8000-000000000002';
    private const PROJECT_ID = '01959900-0000-7000-8000-000000000001';

    private function acting(bool $platformAdmin = true, ?UserTenantRole $role = UserTenantRole::Admin): ActingUser
    {
        return new ActingUser(
            id: UserId::generate(),
            email: 'admin@x',
            isPlatformAdmin: $platformAdmin,
            activeTenant: TenantId::fromString(self::TENANT),
            roleInActiveTenant: $role,
        );
    }

    private function seedProject(InMemoryProjectRepository $repo, string $status = 'draft', string $tenantId = self::TENANT): void
    {
        $repo->save(new Project(
            ProjectId::fromString(self::PROJECT_ID),
            TenantId::fromString($tenantId),
            'slug-one',
            'Original Title',
            'social',
            'bi-folder',
            'Summary text long enough',
            'Description with enough characters here',
            $status,
            0,
            null,
            false,
            '2026-04-01 10:00:00',
        ));
    }

    public function test_rejects_non_admin(): void
    {
        $this->expectException(ForbiddenException::class);
        $repo = new InMemoryProjectRepository();
        $this->seedProject($repo);
        (new ChangeProjectStatus($repo))->execute(new ChangeProjectStatusInput(
            $this->acting(false, null),
            self::PROJECT_ID,
            'active',
        ));
    }

    public function test_rejects_invalid_status(): void
    {
        $this->expectException(ValidationException::class);
        $repo = new InMemoryProjectRepository();
        $this->seedProject($repo);
        (new ChangeProjectStatus($repo))->execute(new ChangeProjectStatusInput(
            $this->acting(),
            self::PROJECT_ID,
            'published', // not allowed for projects
        ));
    }

    public function test_404_if_project_not_in_tenant(): void
    {
        $this->expectException(NotFoundException::class);
        $repo = new InMemoryProjectRepository();
        $this->seedProject($repo, 'draft', self::OTHER_TENANT);
        (new ChangeProjectStatus($repo))->execute(new ChangeProjectStatusInput(
            $this->acting(),
            self::PROJECT_ID,
            'active',
        ));
    }

    public function test_404_if_project_does_not_exist(): void
    {
        $this->expectException(NotFoundException::class);
        $repo = new InMemoryProjectRepository();
        (new ChangeProjectStatus($repo))->execute(new ChangeProjectStatusInput(
            $this->acting(),
            self::PROJECT_ID,
            'active',
        ));
    }

    public function test_transitions_draft_to_active(): void
    {
        $repo = new InMemoryProjectRepository();
        $this->seedProject($repo, 'draft');

        (new ChangeProjectStatus($repo))->execute(new ChangeProjectStatusInput(
            $this->acting(),
            self::PROJECT_ID,
            'active',
        ));

        $project = $repo->findByIdForTenant(self::PROJECT_ID, TenantId::fromString(self::TENANT));
        self::assertNotNull($project);
        self::assertSame('active', $project->status());
    }

    public function test_transitions_active_to_archived(): void
    {
        $repo = new InMemoryProjectRepository();
        $this->seedProject($repo, 'active');

        (new ChangeProjectStatus($repo))->execute(new ChangeProjectStatusInput(
            $this->acting(),
            self::PROJECT_ID,
            'archived',
        ));

        $project = $repo->findByIdForTenant(self::PROJECT_ID, TenantId::fromString(self::TENANT));
        self::assertNotNull($project);
        self::assertSame('archived', $project->status());
    }

    public function test_transitions_archived_to_draft(): void
    {
        $repo = new InMemoryProjectRepository();
        $this->seedProject($repo, 'archived');

        (new ChangeProjectStatus($repo))->execute(new ChangeProjectStatusInput(
            $this->acting(),
            self::PROJECT_ID,
            'draft',
        ));

        $project = $repo->findByIdForTenant(self::PROJECT_ID, TenantId::fromString(self::TENANT));
        self::assertNotNull($project);
        self::assertSame('draft', $project->status());
    }
}
