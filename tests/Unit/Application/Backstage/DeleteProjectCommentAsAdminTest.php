<?php

declare(strict_types=1);

namespace Daems\Tests\Unit\Application\Backstage;

use Daems\Application\Backstage\DeleteProjectCommentAsAdmin\DeleteProjectCommentAsAdmin;
use Daems\Application\Backstage\DeleteProjectCommentAsAdmin\DeleteProjectCommentAsAdminInput;
use Daems\Domain\Auth\ActingUser;
use Daems\Domain\Auth\ForbiddenException;
use Daems\Domain\Project\Project;
use Daems\Domain\Project\ProjectComment;
use Daems\Domain\Project\ProjectCommentId;
use Daems\Domain\Project\ProjectId;
use Daems\Domain\Shared\IdGeneratorInterface;
use Daems\Domain\Tenant\TenantId;
use Daems\Domain\Tenant\UserTenantRole;
use Daems\Domain\User\UserId;
use Daems\Tests\Support\Fake\InMemoryProjectCommentModerationAuditRepository;
use Daems\Tests\Support\Fake\InMemoryProjectRepository;
use Daems\Tests\Support\FrozenClock;
use PHPUnit\Framework\TestCase;

final class DeleteProjectCommentAsAdminTest extends TestCase
{
    private const TENANT       = '01958000-0000-7000-8000-000000000001';
    private const PROJECT_ID   = '01959900-0000-7000-8000-000000000010';
    private const COMMENT_ID   = '01959900-0000-7000-8000-0000000000c1';
    private const ADMIN_USER   = '01958000-0000-7000-8000-000000000020';
    private const MEMBER_USER  = '01958000-0000-7000-8000-000000000021';
    private const AUDIT_ID     = '01959900-0000-7000-8000-0000000000a1';

    private function admin(): ActingUser
    {
        return new ActingUser(
            id: UserId::fromString(self::ADMIN_USER),
            email: 'admin@x',
            isPlatformAdmin: false,
            activeTenant: TenantId::fromString(self::TENANT),
            roleInActiveTenant: UserTenantRole::Admin,
        );
    }

    private function nonAdmin(): ActingUser
    {
        return new ActingUser(
            id: UserId::fromString(self::MEMBER_USER),
            email: 'member@x',
            isPlatformAdmin: false,
            activeTenant: TenantId::fromString(self::TENANT),
            roleInActiveTenant: UserTenantRole::Member,
        );
    }

    private function ids(string $fixed = self::AUDIT_ID): IdGeneratorInterface
    {
        return new class($fixed) implements IdGeneratorInterface {
            public function __construct(private readonly string $id) {}
            public function generate(): string { return $this->id; }
        };
    }

    private function seedProject(InMemoryProjectRepository $repo): void
    {
        $repo->save(new Project(
            ProjectId::fromString(self::PROJECT_ID),
            TenantId::fromString(self::TENANT),
            'garden',
            'Community Garden',
            'environment',
            'bi-folder',
            'Summary for the project goes here',
            'Description for the project goes here too',
            'active',
            0,
            null,
            false,
            '2026-04-01 10:00:00',
        ));
    }

    private function seedComment(InMemoryProjectRepository $repo, string $id = self::COMMENT_ID): void
    {
        $repo->saveComment(new ProjectComment(
            ProjectCommentId::fromString($id),
            self::PROJECT_ID,
            self::MEMBER_USER,
            'Alice',
            'AL',
            '#abc',
            'Hello',
            0,
            '2026-04-18 09:00:00',
        ));
    }

    public function test_rejects_non_admin(): void
    {
        $this->expectException(ForbiddenException::class);

        $projects = new InMemoryProjectRepository();
        $audit = new InMemoryProjectCommentModerationAuditRepository();

        $sut = new DeleteProjectCommentAsAdmin(
            $projects,
            $audit,
            FrozenClock::at('2026-04-20 12:00:00'),
            $this->ids(),
        );
        $sut->execute(new DeleteProjectCommentAsAdminInput(
            $this->nonAdmin(),
            self::PROJECT_ID,
            self::COMMENT_ID,
            null,
        ));
    }

    public function test_admin_deletes_existing_comment_and_writes_audit(): void
    {
        $projects = new InMemoryProjectRepository();
        $audit    = new InMemoryProjectCommentModerationAuditRepository();
        $this->seedProject($projects);
        $this->seedComment($projects);

        self::assertCount(1, $projects->comments);

        $sut = new DeleteProjectCommentAsAdmin(
            $projects,
            $audit,
            FrozenClock::at('2026-04-20 12:00:00'),
            $this->ids(),
        );
        $sut->execute(new DeleteProjectCommentAsAdminInput(
            $this->admin(),
            self::PROJECT_ID,
            self::COMMENT_ID,
            'off-topic',
        ));

        self::assertSame([], $projects->comments);
        self::assertCount(1, $audit->rows);
        $row = $audit->rows[0];
        self::assertSame(self::AUDIT_ID, $row->id);
        self::assertTrue($row->tenantId->equals(TenantId::fromString(self::TENANT)));
        self::assertSame(self::PROJECT_ID, $row->projectId);
        self::assertSame(self::COMMENT_ID, $row->commentId);
        self::assertSame('deleted', $row->action);
        self::assertSame('off-topic', $row->reason);
        self::assertSame(self::ADMIN_USER, $row->performedBy);
        self::assertSame('2026-04-20 12:00:00', $row->createdAt->format('Y-m-d H:i:s'));
    }

    public function test_idempotent_when_comment_missing_still_writes_audit(): void
    {
        $projects = new InMemoryProjectRepository();
        $audit    = new InMemoryProjectCommentModerationAuditRepository();
        $this->seedProject($projects);
        // NOTE: no comment seeded.

        $sut = new DeleteProjectCommentAsAdmin(
            $projects,
            $audit,
            FrozenClock::at('2026-04-20 12:00:00'),
            $this->ids(),
        );
        $sut->execute(new DeleteProjectCommentAsAdminInput(
            $this->admin(),
            self::PROJECT_ID,
            self::COMMENT_ID,
            null,
        ));

        self::assertSame([], $projects->comments);
        self::assertCount(1, $audit->rows);
        self::assertSame(self::COMMENT_ID, $audit->rows[0]->commentId);
        self::assertNull($audit->rows[0]->reason);
    }

    public function test_audit_uses_generated_id_and_acting_admin(): void
    {
        $projects = new InMemoryProjectRepository();
        $audit    = new InMemoryProjectCommentModerationAuditRepository();
        $this->seedProject($projects);
        $this->seedComment($projects);

        $sut = new DeleteProjectCommentAsAdmin(
            $projects,
            $audit,
            FrozenClock::at('2026-04-20 12:00:00'),
            $this->ids('01959900-0000-7000-8000-0000000000ff'),
        );
        $sut->execute(new DeleteProjectCommentAsAdminInput(
            $this->admin(),
            self::PROJECT_ID,
            self::COMMENT_ID,
            'spam',
        ));

        self::assertCount(1, $audit->rows);
        self::assertSame('01959900-0000-7000-8000-0000000000ff', $audit->rows[0]->id);
        self::assertSame(self::ADMIN_USER, $audit->rows[0]->performedBy);
    }
}
