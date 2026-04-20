<?php

declare(strict_types=1);

namespace Daems\Tests\Unit\Application\Backstage;

use Daems\Application\Backstage\ListProjectCommentsForAdmin\ListProjectCommentsForAdmin;
use Daems\Application\Backstage\ListProjectCommentsForAdmin\ListProjectCommentsForAdminInput;
use Daems\Domain\Auth\ActingUser;
use Daems\Domain\Auth\ForbiddenException;
use Daems\Domain\Project\Project;
use Daems\Domain\Project\ProjectComment;
use Daems\Domain\Project\ProjectCommentId;
use Daems\Domain\Project\ProjectId;
use Daems\Domain\Tenant\TenantId;
use Daems\Domain\Tenant\UserTenantRole;
use Daems\Domain\User\UserId;
use Daems\Tests\Support\Fake\InMemoryProjectRepository;
use PHPUnit\Framework\TestCase;

final class ListProjectCommentsForAdminTest extends TestCase
{
    private const TENANT       = '01958000-0000-7000-8000-000000000001';
    private const PROJECT_ID   = '01959900-0000-7000-8000-000000000010';
    private const ADMIN_USER   = '01958000-0000-7000-8000-000000000020';
    private const MEMBER_USER  = '01958000-0000-7000-8000-000000000021';

    private function acting(UserTenantRole $role = UserTenantRole::Admin): ActingUser
    {
        return new ActingUser(
            id: UserId::fromString(self::ADMIN_USER),
            email: 'admin@x',
            isPlatformAdmin: false,
            activeTenant: TenantId::fromString(self::TENANT),
            roleInActiveTenant: $role,
        );
    }

    private function seedProject(InMemoryProjectRepository $repo, string $title = 'Garden'): Project
    {
        $project = new Project(
            ProjectId::fromString(self::PROJECT_ID),
            TenantId::fromString(self::TENANT),
            'garden',
            $title,
            'environment',
            'bi-folder',
            'Summary for the project goes here',
            'Description for the project goes here too',
            'active',
            0,
            null,
            false,
            '2026-04-01 10:00:00',
        );
        $repo->save($project);
        return $project;
    }

    private function seedComment(
        InMemoryProjectRepository $repo,
        string $id,
        string $author = 'Alice',
        string $content = 'Nice project',
        string $createdAt = '2026-04-18 09:00:00',
    ): void {
        $repo->saveComment(new ProjectComment(
            ProjectCommentId::fromString($id),
            self::PROJECT_ID,
            self::MEMBER_USER,
            $author,
            'AL',
            '#abc',
            $content,
            0,
            $createdAt,
        ));
    }

    public function test_rejects_non_admin(): void
    {
        $this->expectException(ForbiddenException::class);

        $projects = new InMemoryProjectRepository();
        $sut = new ListProjectCommentsForAdmin($projects);
        $sut->execute(new ListProjectCommentsForAdminInput(
            new ActingUser(
                id: UserId::fromString(self::MEMBER_USER),
                email: 'member@x',
                isPlatformAdmin: false,
                activeTenant: TenantId::fromString(self::TENANT),
                roleInActiveTenant: UserTenantRole::Member,
            ),
        ));
    }

    public function test_admin_gets_recent_comments_for_tenant(): void
    {
        $projects = new InMemoryProjectRepository();
        $this->seedProject($projects, 'Community Garden');
        $this->seedComment(
            $projects,
            '01959900-0000-7000-8000-0000000000c1',
            'Alice',
            'First comment',
            '2026-04-18 09:00:00',
        );
        $this->seedComment(
            $projects,
            '01959900-0000-7000-8000-0000000000c2',
            'Bob',
            'Second comment',
            '2026-04-19 09:00:00',
        );

        $sut = new ListProjectCommentsForAdmin($projects);
        $out = $sut->execute(new ListProjectCommentsForAdminInput($this->acting()));

        self::assertCount(2, $out->items);
        // Newest first
        self::assertSame('01959900-0000-7000-8000-0000000000c2', $out->items[0]['comment_id']);
        self::assertSame(self::PROJECT_ID, $out->items[0]['project_id']);
        self::assertSame('Community Garden', $out->items[0]['project_title']);
        self::assertSame('Bob', $out->items[0]['author_name']);
        self::assertSame('Second comment', $out->items[0]['content']);
        self::assertSame('2026-04-19 09:00:00', $out->items[0]['created_at']);

        // toArray shape
        self::assertSame(
            ['items', 'total'],
            array_keys($out->toArray()),
        );
        self::assertSame(2, $out->toArray()['total']);
    }

    public function test_limit_is_applied(): void
    {
        $projects = new InMemoryProjectRepository();
        $this->seedProject($projects);
        $this->seedComment($projects, '01959900-0000-7000-8000-0000000000c1', 'Alice', 'a', '2026-04-18 09:00:00');
        $this->seedComment($projects, '01959900-0000-7000-8000-0000000000c2', 'Bob',   'b', '2026-04-19 09:00:00');
        $this->seedComment($projects, '01959900-0000-7000-8000-0000000000c3', 'Carol', 'c', '2026-04-20 09:00:00');

        $sut = new ListProjectCommentsForAdmin($projects);
        $out = $sut->execute(new ListProjectCommentsForAdminInput($this->acting(), limit: 2));

        self::assertCount(2, $out->items);
        // Newest two, newest first
        self::assertSame('01959900-0000-7000-8000-0000000000c3', $out->items[0]['comment_id']);
        self::assertSame('01959900-0000-7000-8000-0000000000c2', $out->items[1]['comment_id']);
    }

    public function test_empty_when_no_comments(): void
    {
        $projects = new InMemoryProjectRepository();
        $this->seedProject($projects);

        $sut = new ListProjectCommentsForAdmin($projects);
        $out = $sut->execute(new ListProjectCommentsForAdminInput($this->acting()));

        self::assertSame([], $out->items);
        self::assertSame(0, $out->toArray()['total']);
    }
}
