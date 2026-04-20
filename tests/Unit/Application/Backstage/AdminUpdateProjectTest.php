<?php

declare(strict_types=1);

namespace Daems\Tests\Unit\Application\Backstage;

use Daems\Application\Backstage\AdminUpdateProject\AdminUpdateProject;
use Daems\Application\Backstage\AdminUpdateProject\AdminUpdateProjectInput;
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

final class AdminUpdateProjectTest extends TestCase
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

    private function seedProject(InMemoryProjectRepository $repo, string $tenantId = self::TENANT): void
    {
        $repo->save(new Project(
            ProjectId::fromString(self::PROJECT_ID),
            TenantId::fromString($tenantId),
            'slug-one',
            'Original Title',
            'social',
            'bi-folder',
            'Original summary long enough',
            'Original description with enough characters here',
            'active',
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
        (new AdminUpdateProject($repo))->execute(new AdminUpdateProjectInput(
            $this->acting(false, null),
            self::PROJECT_ID,
            'New Title', null, null, null, null, null,
        ));
    }

    public function test_404_if_project_not_in_tenant(): void
    {
        $this->expectException(NotFoundException::class);
        $repo = new InMemoryProjectRepository();
        // Project only exists in OTHER tenant
        $this->seedProject($repo, self::OTHER_TENANT);
        (new AdminUpdateProject($repo))->execute(new AdminUpdateProjectInput(
            $this->acting(),
            self::PROJECT_ID,
            'New Title', null, null, null, null, null,
        ));
    }

    public function test_404_if_project_does_not_exist(): void
    {
        $this->expectException(NotFoundException::class);
        $repo = new InMemoryProjectRepository();
        (new AdminUpdateProject($repo))->execute(new AdminUpdateProjectInput(
            $this->acting(),
            self::PROJECT_ID,
            'New Title', null, null, null, null, null,
        ));
    }

    public function test_partial_update_null_means_unchanged(): void
    {
        $repo = new InMemoryProjectRepository();
        $this->seedProject($repo);

        $out = (new AdminUpdateProject($repo))->execute(new AdminUpdateProjectInput(
            $this->acting(),
            self::PROJECT_ID,
            'Updated Title', // title only
            null, null, null, null, null,
        ));

        self::assertSame(self::PROJECT_ID, $out->id);
        self::assertSame(['id' => self::PROJECT_ID, 'updated' => true], $out->toArray());

        $project = $repo->findByIdForTenant(self::PROJECT_ID, TenantId::fromString(self::TENANT));
        self::assertNotNull($project);
        self::assertSame('Updated Title', $project->title());
        // Unchanged:
        self::assertSame('social', $project->category());
        self::assertSame('bi-folder', $project->icon());
        self::assertSame('Original summary long enough', $project->summary());
        self::assertSame('Original description with enough characters here', $project->description());
        self::assertSame(0, $project->sortOrder());
        // Status + featured untouched:
        self::assertSame('active', $project->status());
        self::assertFalse($project->featured());
    }

    public function test_updates_all_provided_fields(): void
    {
        $repo = new InMemoryProjectRepository();
        $this->seedProject($repo);

        (new AdminUpdateProject($repo))->execute(new AdminUpdateProjectInput(
            $this->acting(),
            self::PROJECT_ID,
            'New Title',
            'tech',
            'bi-star',
            'Updated summary long enough here',
            'Updated description with enough characters for validation',
            5,
        ));

        $project = $repo->findByIdForTenant(self::PROJECT_ID, TenantId::fromString(self::TENANT));
        self::assertNotNull($project);
        self::assertSame('New Title', $project->title());
        self::assertSame('tech', $project->category());
        self::assertSame('bi-star', $project->icon());
        self::assertSame('Updated summary long enough here', $project->summary());
        self::assertSame('Updated description with enough characters for validation', $project->description());
        self::assertSame(5, $project->sortOrder());
    }

    public function test_validation_errors_on_short_title(): void
    {
        $this->expectException(ValidationException::class);
        $repo = new InMemoryProjectRepository();
        $this->seedProject($repo);
        (new AdminUpdateProject($repo))->execute(new AdminUpdateProjectInput(
            $this->acting(),
            self::PROJECT_ID,
            'Hi', null, null, null, null, null,
        ));
    }

    public function test_validation_errors_on_too_long_title(): void
    {
        $this->expectException(ValidationException::class);
        $repo = new InMemoryProjectRepository();
        $this->seedProject($repo);
        (new AdminUpdateProject($repo))->execute(new AdminUpdateProjectInput(
            $this->acting(),
            self::PROJECT_ID,
            str_repeat('x', 201), null, null, null, null, null,
        ));
    }

    public function test_validation_errors_on_empty_category(): void
    {
        $this->expectException(ValidationException::class);
        $repo = new InMemoryProjectRepository();
        $this->seedProject($repo);
        (new AdminUpdateProject($repo))->execute(new AdminUpdateProjectInput(
            $this->acting(),
            self::PROJECT_ID,
            null, '', null, null, null, null,
        ));
    }

    public function test_validation_errors_on_short_summary(): void
    {
        $this->expectException(ValidationException::class);
        $repo = new InMemoryProjectRepository();
        $this->seedProject($repo);
        (new AdminUpdateProject($repo))->execute(new AdminUpdateProjectInput(
            $this->acting(),
            self::PROJECT_ID,
            null, null, null, 'too short', null, null,
        ));
    }

    public function test_validation_errors_on_short_description(): void
    {
        $this->expectException(ValidationException::class);
        $repo = new InMemoryProjectRepository();
        $this->seedProject($repo);
        (new AdminUpdateProject($repo))->execute(new AdminUpdateProjectInput(
            $this->acting(),
            self::PROJECT_ID,
            null, null, null, null, 'too short', null,
        ));
    }

    public function test_does_not_touch_status_or_featured(): void
    {
        $repo = new InMemoryProjectRepository();
        // Seed project with featured = true, status = draft
        $repo->save(new Project(
            ProjectId::fromString(self::PROJECT_ID),
            TenantId::fromString(self::TENANT),
            'slug-one',
            'Original Title',
            'social',
            'bi-folder',
            'Original summary long enough',
            'Original description with enough characters here',
            'draft',
            0,
            null,
            true,
            '2026-04-01 10:00:00',
        ));

        (new AdminUpdateProject($repo))->execute(new AdminUpdateProjectInput(
            $this->acting(),
            self::PROJECT_ID,
            'New Title', 'tech', 'bi-star', null, null, null,
        ));

        $project = $repo->findByIdForTenant(self::PROJECT_ID, TenantId::fromString(self::TENANT));
        self::assertNotNull($project);
        self::assertSame('New Title', $project->title());
        // Status stays draft, featured stays true:
        self::assertSame('draft', $project->status());
        self::assertTrue($project->featured());
    }

    public function test_no_fields_provided_returns_success_but_no_change(): void
    {
        $repo = new InMemoryProjectRepository();
        $this->seedProject($repo);

        $out = (new AdminUpdateProject($repo))->execute(new AdminUpdateProjectInput(
            $this->acting(),
            self::PROJECT_ID,
            null, null, null, null, null, null,
        ));

        self::assertSame(self::PROJECT_ID, $out->id);
        $project = $repo->findByIdForTenant(self::PROJECT_ID, TenantId::fromString(self::TENANT));
        self::assertNotNull($project);
        self::assertSame('Original Title', $project->title());
    }
}
