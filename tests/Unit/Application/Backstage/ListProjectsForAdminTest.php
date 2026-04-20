<?php

declare(strict_types=1);

namespace Daems\Tests\Unit\Application\Backstage;

use Daems\Application\Backstage\ListProjectsForAdmin\ListProjectsForAdmin;
use Daems\Application\Backstage\ListProjectsForAdmin\ListProjectsForAdminInput;
use Daems\Domain\Auth\ActingUser;
use Daems\Domain\Auth\ForbiddenException;
use Daems\Domain\Project\Project;
use Daems\Domain\Project\ProjectComment;
use Daems\Domain\Project\ProjectCommentId;
use Daems\Domain\Project\ProjectId;
use Daems\Domain\Project\ProjectParticipant;
use Daems\Domain\Project\ProjectParticipantId;
use Daems\Domain\Tenant\TenantId;
use Daems\Domain\Tenant\UserTenantRole;
use Daems\Domain\User\UserId;
use Daems\Tests\Support\Fake\InMemoryProjectRepository;
use PHPUnit\Framework\TestCase;

final class ListProjectsForAdminTest extends TestCase
{
    private const TENANT = '01958000-0000-7000-8000-000000000001';
    private const OTHER_TENANT = '01958000-0000-7000-8000-000000000002';

    private function acting(bool $platformAdmin, ?UserTenantRole $role = UserTenantRole::Admin): ActingUser
    {
        return new ActingUser(
            id: UserId::generate(),
            email: 'admin@x',
            isPlatformAdmin: $platformAdmin,
            activeTenant: TenantId::fromString(self::TENANT),
            roleInActiveTenant: $role,
        );
    }

    public function test_rejects_non_admin(): void
    {
        $this->expectException(ForbiddenException::class);
        $repo = new InMemoryProjectRepository();
        (new ListProjectsForAdmin($repo))->execute(
            new ListProjectsForAdminInput(
                $this->acting(platformAdmin: false, role: null),
                null, null, null, null,
            ),
        );
    }

    public function test_returns_all_statuses_for_own_tenant(): void
    {
        $repo = new InMemoryProjectRepository();
        $repo->save($this->makeProject('01', 'slug-draft', 'Draft one', 'social', 'draft'));
        $repo->save($this->makeProject('02', 'slug-active', 'Active one', 'social', 'active'));
        $repo->save($this->makeProject('03', 'slug-arch', 'Arch one', 'tech', 'archived'));
        $repo->save($this->makeOtherTenantProject('04', 'slug-other', 'Other tenant', 'social', 'active'));

        $out = (new ListProjectsForAdmin($repo))->execute(
            new ListProjectsForAdminInput($this->acting(true), null, null, null, null),
        );

        self::assertCount(3, $out->items);
        $titles = array_column($out->items, 'title');
        self::assertContains('Draft one', $titles);
        self::assertContains('Active one', $titles);
        self::assertContains('Arch one', $titles);
        self::assertNotContains('Other tenant', $titles);
        self::assertSame(3, $out->toArray()['total']);
    }

    public function test_filters_by_status(): void
    {
        $repo = new InMemoryProjectRepository();
        $repo->save($this->makeProject('01', 'a', 'A', 'social', 'draft'));
        $repo->save($this->makeProject('02', 'b', 'B', 'social', 'active'));

        $out = (new ListProjectsForAdmin($repo))->execute(
            new ListProjectsForAdminInput($this->acting(true), 'draft', null, null, null),
        );
        self::assertCount(1, $out->items);
        self::assertSame('A', $out->items[0]['title']);
    }

    public function test_filters_by_category(): void
    {
        $repo = new InMemoryProjectRepository();
        $repo->save($this->makeProject('01', 'a', 'A', 'social', 'active'));
        $repo->save($this->makeProject('02', 'b', 'B', 'tech', 'active'));

        $out = (new ListProjectsForAdmin($repo))->execute(
            new ListProjectsForAdminInput($this->acting(true), null, 'tech', null, null),
        );
        self::assertCount(1, $out->items);
        self::assertSame('B', $out->items[0]['title']);
    }

    public function test_filters_by_featured_only(): void
    {
        $repo = new InMemoryProjectRepository();
        $repo->save($this->makeProject('01', 'a', 'A', 'social', 'active', featured: false));
        $repo->save($this->makeProject('02', 'b', 'B', 'social', 'active', featured: true));

        $out = (new ListProjectsForAdmin($repo))->execute(
            new ListProjectsForAdminInput($this->acting(true), null, null, true, null),
        );
        self::assertCount(1, $out->items);
        self::assertSame('B', $out->items[0]['title']);
        self::assertTrue($out->items[0]['featured']);
    }

    public function test_filters_by_q_search(): void
    {
        $repo = new InMemoryProjectRepository();
        $repo->save($this->makeProject('01', 'alpha', 'Alpha Initiative', 'social', 'active', summary: 'learning'));
        $repo->save($this->makeProject('02', 'beta', 'Beta Project', 'social', 'active', summary: 'something else'));

        $out = (new ListProjectsForAdmin($repo))->execute(
            new ListProjectsForAdminInput($this->acting(true), null, null, null, 'alpha'),
        );
        self::assertCount(1, $out->items);
        self::assertSame('Alpha Initiative', $out->items[0]['title']);
    }

    public function test_empty_string_filters_are_ignored(): void
    {
        $repo = new InMemoryProjectRepository();
        $repo->save($this->makeProject('01', 'a', 'A', 'social', 'draft'));
        $repo->save($this->makeProject('02', 'b', 'B', 'tech', 'active'));

        $out = (new ListProjectsForAdmin($repo))->execute(
            new ListProjectsForAdminInput($this->acting(true), '', '', false, ''),
        );
        self::assertCount(2, $out->items);
    }

    public function test_output_includes_counts_and_fields(): void
    {
        $repo = new InMemoryProjectRepository();
        $projectId = '01959900-0000-7000-8000-000000000099';
        $ownerId = '01958000-0000-7000-8000-000000000aaa';
        $repo->save(new Project(
            ProjectId::fromString($projectId),
            TenantId::fromString(self::TENANT),
            'slug-full',
            'Full Project',
            'social',
            'bi-star',
            'A project summary with enough text',
            'A detailed description that is long enough',
            'active',
            0,
            UserId::fromString($ownerId),
            true,
            '2026-04-01 10:00:00',
        ));
        $repo->addParticipant(new ProjectParticipant(
            ProjectParticipantId::fromString('01959902-0000-7000-8000-000000000001'),
            $projectId,
            '01958000-0000-7000-8000-000000000bbb',
            '2026-04-02 10:00:00',
        ));
        $repo->addParticipant(new ProjectParticipant(
            ProjectParticipantId::fromString('01959902-0000-7000-8000-000000000002'),
            $projectId,
            '01958000-0000-7000-8000-000000000ccc',
            '2026-04-03 10:00:00',
        ));
        $repo->saveComment(new ProjectComment(
            ProjectCommentId::fromString('01959901-0000-7000-8000-000000000001'),
            $projectId,
            '01958000-0000-7000-8000-000000000bbb',
            'Commenter',
            'CO',
            '#abcdef',
            'Nice work',
            0,
            '2026-04-02 10:00:00',
        ));

        $out = (new ListProjectsForAdmin($repo))->execute(
            new ListProjectsForAdminInput($this->acting(true), null, null, null, null),
        );
        self::assertCount(1, $out->items);
        $item = $out->items[0];
        self::assertSame($projectId, $item['id']);
        self::assertSame('slug-full', $item['slug']);
        self::assertSame('Full Project', $item['title']);
        self::assertSame('social', $item['category']);
        self::assertSame('active', $item['status']);
        self::assertTrue($item['featured']);
        self::assertSame($ownerId, $item['owner_id']);
        self::assertSame(2, $item['participants_count']);
        self::assertSame(1, $item['comments_count']);
        self::assertSame('2026-04-01 10:00:00', $item['created_at']);
    }

    public function test_owner_id_null_when_no_owner(): void
    {
        $repo = new InMemoryProjectRepository();
        $projectId = '01959900-0000-7000-8000-000000000100';
        $repo->save(new Project(
            ProjectId::fromString($projectId),
            TenantId::fromString(self::TENANT),
            'slug-noowner',
            'No Owner',
            'social',
            'bi-folder',
            'Summary text that is long enough',
            'A detailed description that is long enough',
            'active',
            0,
            null,
            false,
            '2026-04-01 10:00:00',
        ));

        $out = (new ListProjectsForAdmin($repo))->execute(
            new ListProjectsForAdminInput($this->acting(true), null, null, null, null),
        );
        self::assertCount(1, $out->items);
        self::assertNull($out->items[0]['owner_id']);
    }

    private function makeProject(
        string $idSuffix,
        string $slug,
        string $title,
        string $category,
        string $status,
        bool $featured = false,
        string $summary = 'A project summary with enough text',
    ): Project {
        return new Project(
            ProjectId::fromString('01959900-0000-7000-8000-0000000000' . $idSuffix),
            TenantId::fromString(self::TENANT),
            $slug,
            $title,
            $category,
            'bi-folder',
            $summary,
            'A detailed description that is long enough',
            $status,
            0,
            null,
            $featured,
            '2026-04-01 10:00:00',
        );
    }

    private function makeOtherTenantProject(string $idSuffix, string $slug, string $title, string $category, string $status): Project
    {
        return new Project(
            ProjectId::fromString('01959900-0000-7000-8000-0000000001' . $idSuffix),
            TenantId::fromString(self::OTHER_TENANT),
            $slug,
            $title,
            $category,
            'bi-folder',
            'A project summary with enough text',
            'A detailed description that is long enough',
            $status,
            0,
            null,
            false,
            '2026-04-01 10:00:00',
        );
    }
}
