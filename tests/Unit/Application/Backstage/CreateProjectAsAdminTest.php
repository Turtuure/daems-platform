<?php

declare(strict_types=1);

namespace Daems\Tests\Unit\Application\Backstage;

use Daems\Application\Backstage\CreateProjectAsAdmin\CreateProjectAsAdmin;
use Daems\Application\Backstage\CreateProjectAsAdmin\CreateProjectAsAdminInput;
use Daems\Domain\Auth\ActingUser;
use Daems\Domain\Auth\ForbiddenException;
use Daems\Domain\Project\Project;
use Daems\Domain\Project\ProjectId;
use Daems\Domain\Shared\IdGeneratorInterface;
use Daems\Domain\Shared\ValidationException;
use Daems\Domain\Tenant\TenantId;
use Daems\Domain\Tenant\UserTenantRole;
use Daems\Domain\User\UserId;
use Daems\Tests\Support\Fake\InMemoryProjectRepository;
use PHPUnit\Framework\TestCase;

final class CreateProjectAsAdminTest extends TestCase
{
    private const TENANT = '01958000-0000-7000-8000-000000000001';

    private function acting(
        bool $platformAdmin = true,
        ?UserTenantRole $role = UserTenantRole::Admin,
        ?UserId $id = null,
    ): ActingUser {
        return new ActingUser(
            id: $id ?? UserId::generate(),
            email: 'admin@x',
            isPlatformAdmin: $platformAdmin,
            activeTenant: TenantId::fromString(self::TENANT),
            roleInActiveTenant: $role,
        );
    }

    private function ids(string $fixed = '01959900-0000-7000-8000-000000000001'): IdGeneratorInterface
    {
        return new class($fixed) implements IdGeneratorInterface {
            private int $counter = 0;
            public function __construct(private readonly string $base) {}

            public function generate(): string
            {
                $this->counter++;
                if ($this->counter === 1) {
                    return $this->base;
                }
                $suffix = str_pad((string) $this->counter, 2, '0', STR_PAD_LEFT);
                return substr($this->base, 0, -2) . $suffix;
            }
        };
    }

    private function validInput(?ActingUser $acting = null, ?string $ownerId = null): CreateProjectAsAdminInput
    {
        return new CreateProjectAsAdminInput(
            acting: $acting ?? $this->acting(),
            title: 'Community Garden Initiative',
            category: 'social',
            icon: 'bi-tree',
            summary: 'A summary that is long enough',
            description: 'A description with at least twenty characters total',
            ownerId: $ownerId,
        );
    }

    public function test_rejects_non_admin(): void
    {
        $this->expectException(ForbiddenException::class);
        $repo = new InMemoryProjectRepository();
        (new CreateProjectAsAdmin($repo, $this->ids()))->execute(
            $this->validInput(acting: $this->acting(false, null)),
        );
    }

    public function test_rejects_short_title(): void
    {
        $this->expectException(ValidationException::class);
        $repo = new InMemoryProjectRepository();
        (new CreateProjectAsAdmin($repo, $this->ids()))->execute(
            new CreateProjectAsAdminInput(
                $this->acting(),
                'Hi',
                'social',
                null,
                'Summary long enough here',
                'A description with at least twenty characters total',
                null,
            ),
        );
    }

    public function test_rejects_too_long_title(): void
    {
        $this->expectException(ValidationException::class);
        $repo = new InMemoryProjectRepository();
        (new CreateProjectAsAdmin($repo, $this->ids()))->execute(
            new CreateProjectAsAdminInput(
                $this->acting(),
                str_repeat('x', 201),
                'social',
                null,
                'Summary long enough here',
                'A description with at least twenty characters total',
                null,
            ),
        );
    }

    public function test_rejects_empty_category(): void
    {
        $this->expectException(ValidationException::class);
        $repo = new InMemoryProjectRepository();
        (new CreateProjectAsAdmin($repo, $this->ids()))->execute(
            new CreateProjectAsAdminInput(
                $this->acting(),
                'Valid title here',
                '',
                null,
                'Summary long enough here',
                'A description with at least twenty characters total',
                null,
            ),
        );
    }

    public function test_rejects_short_summary(): void
    {
        $this->expectException(ValidationException::class);
        $repo = new InMemoryProjectRepository();
        (new CreateProjectAsAdmin($repo, $this->ids()))->execute(
            new CreateProjectAsAdminInput(
                $this->acting(),
                'Valid title here',
                'social',
                null,
                'too short',
                'A description with at least twenty characters total',
                null,
            ),
        );
    }

    public function test_rejects_short_description(): void
    {
        $this->expectException(ValidationException::class);
        $repo = new InMemoryProjectRepository();
        (new CreateProjectAsAdmin($repo, $this->ids()))->execute(
            new CreateProjectAsAdminInput(
                $this->acting(),
                'Valid title here',
                'social',
                null,
                'Summary long enough here',
                'too short',
                null,
            ),
        );
    }

    public function test_slug_auto_generated_from_title(): void
    {
        $repo = new InMemoryProjectRepository();
        $out = (new CreateProjectAsAdmin($repo, $this->ids()))->execute($this->validInput());
        self::assertSame('community-garden-initiative', $out->slug);
    }

    public function test_slug_collision_appends_suffix(): void
    {
        $repo = new InMemoryProjectRepository();
        // Seed existing project with the same slug
        $repo->save(new Project(
            ProjectId::fromString('01959900-0000-7000-8000-0000000000aa'),
            TenantId::fromString(self::TENANT),
            'community-garden-initiative',
            'Community Garden Initiative',
            'social',
            'bi-tree',
            'A summary that is long enough',
            'A description with at least twenty characters total',
            'active',
            0,
            null,
            false,
            '2026-04-01 10:00:00',
        ));

        $uc = new CreateProjectAsAdmin($repo, $this->ids());
        $out = $uc->execute($this->validInput());
        self::assertNotSame('community-garden-initiative', $out->slug);
        self::assertStringStartsWith('community-garden-initiative-', $out->slug);
    }

    public function test_defaults_status_draft_and_featured_false(): void
    {
        $repo = new InMemoryProjectRepository();
        $out = (new CreateProjectAsAdmin($repo, $this->ids()))->execute($this->validInput());
        $project = $repo->findByIdForTenant($out->id, TenantId::fromString(self::TENANT));
        self::assertNotNull($project);
        self::assertSame('draft', $project->status());
        self::assertFalse($project->featured());
    }

    public function test_owner_id_falls_back_to_acting_when_null(): void
    {
        $repo = new InMemoryProjectRepository();
        $actingUserId = UserId::fromString('01958000-0000-7000-8000-000000000aaa');
        $out = (new CreateProjectAsAdmin($repo, $this->ids()))->execute(
            $this->validInput(acting: $this->acting(id: $actingUserId), ownerId: null),
        );
        $project = $repo->findByIdForTenant($out->id, TenantId::fromString(self::TENANT));
        self::assertNotNull($project);
        $owner = $project->ownerId();
        self::assertNotNull($owner);
        self::assertSame($actingUserId->value(), $owner->value());
    }

    public function test_owner_id_uses_provided_value(): void
    {
        $repo = new InMemoryProjectRepository();
        $actingUserId = UserId::fromString('01958000-0000-7000-8000-000000000aaa');
        $otherOwnerId = '01958000-0000-7000-8000-000000000bbb';
        $out = (new CreateProjectAsAdmin($repo, $this->ids()))->execute(
            $this->validInput(acting: $this->acting(id: $actingUserId), ownerId: $otherOwnerId),
        );
        $project = $repo->findByIdForTenant($out->id, TenantId::fromString(self::TENANT));
        self::assertNotNull($project);
        $owner = $project->ownerId();
        self::assertNotNull($owner);
        self::assertSame($otherOwnerId, $owner->value());
    }

    public function test_empty_owner_id_string_falls_back_to_acting(): void
    {
        $repo = new InMemoryProjectRepository();
        $actingUserId = UserId::fromString('01958000-0000-7000-8000-000000000aaa');
        $out = (new CreateProjectAsAdmin($repo, $this->ids()))->execute(
            $this->validInput(acting: $this->acting(id: $actingUserId), ownerId: ''),
        );
        $project = $repo->findByIdForTenant($out->id, TenantId::fromString(self::TENANT));
        self::assertNotNull($project);
        $owner = $project->ownerId();
        self::assertNotNull($owner);
        self::assertSame($actingUserId->value(), $owner->value());
    }

    public function test_default_icon_when_null_or_empty(): void
    {
        $repo = new InMemoryProjectRepository();
        $out = (new CreateProjectAsAdmin($repo, $this->ids()))->execute(
            new CreateProjectAsAdminInput(
                $this->acting(),
                'Valid title here',
                'social',
                null,
                'Summary long enough here',
                'A description with at least twenty characters total',
                null,
            ),
        );
        $project = $repo->findByIdForTenant($out->id, TenantId::fromString(self::TENANT));
        self::assertNotNull($project);
        self::assertSame('bi-folder', $project->icon());
    }

    public function test_output_toArray_shape(): void
    {
        $repo = new InMemoryProjectRepository();
        $out = (new CreateProjectAsAdmin($repo, $this->ids()))->execute($this->validInput());
        $arr = $out->toArray();
        self::assertSame(['id' => $out->id, 'slug' => $out->slug], $arr);
    }
}
