<?php

declare(strict_types=1);

namespace Daems\Tests\Unit\Application\Backstage;

use Daems\Application\Backstage\SetProjectFeatured\SetProjectFeatured;
use Daems\Application\Backstage\SetProjectFeatured\SetProjectFeaturedInput;
use Daems\Domain\Auth\ActingUser;
use Daems\Domain\Auth\ForbiddenException;
use Daems\Domain\Project\Project;
use Daems\Domain\Project\ProjectId;
use Daems\Domain\Shared\NotFoundException;
use Daems\Domain\Tenant\TenantId;
use Daems\Domain\Tenant\UserTenantRole;
use Daems\Domain\User\UserId;
use Daems\Tests\Support\Fake\InMemoryProjectRepository;
use PHPUnit\Framework\TestCase;

final class SetProjectFeaturedTest extends TestCase
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

    private function seedProject(InMemoryProjectRepository $repo, bool $featured = false, string $tenantId = self::TENANT): void
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
            'active',
            0,
            null,
            $featured,
            '2026-04-01 10:00:00',
        ));
    }

    public function test_rejects_non_admin(): void
    {
        $this->expectException(ForbiddenException::class);
        $repo = new InMemoryProjectRepository();
        $this->seedProject($repo);
        (new SetProjectFeatured($repo))->execute(new SetProjectFeaturedInput(
            $this->acting(false, null),
            self::PROJECT_ID,
            true,
        ));
    }

    public function test_404_if_project_not_in_tenant(): void
    {
        $this->expectException(NotFoundException::class);
        $repo = new InMemoryProjectRepository();
        $this->seedProject($repo, false, self::OTHER_TENANT);
        (new SetProjectFeatured($repo))->execute(new SetProjectFeaturedInput(
            $this->acting(),
            self::PROJECT_ID,
            true,
        ));
    }

    public function test_404_if_project_does_not_exist(): void
    {
        $this->expectException(NotFoundException::class);
        $repo = new InMemoryProjectRepository();
        (new SetProjectFeatured($repo))->execute(new SetProjectFeaturedInput(
            $this->acting(),
            self::PROJECT_ID,
            true,
        ));
    }

    public function test_set_featured_true(): void
    {
        $repo = new InMemoryProjectRepository();
        $this->seedProject($repo, false);

        (new SetProjectFeatured($repo))->execute(new SetProjectFeaturedInput(
            $this->acting(),
            self::PROJECT_ID,
            true,
        ));

        $project = $repo->findByIdForTenant(self::PROJECT_ID, TenantId::fromString(self::TENANT));
        self::assertNotNull($project);
        self::assertTrue($project->featured());
    }

    public function test_set_featured_false(): void
    {
        $repo = new InMemoryProjectRepository();
        $this->seedProject($repo, true);

        (new SetProjectFeatured($repo))->execute(new SetProjectFeaturedInput(
            $this->acting(),
            self::PROJECT_ID,
            false,
        ));

        $project = $repo->findByIdForTenant(self::PROJECT_ID, TenantId::fromString(self::TENANT));
        self::assertNotNull($project);
        self::assertFalse($project->featured());
    }
}
