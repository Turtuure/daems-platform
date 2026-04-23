<?php

declare(strict_types=1);

namespace Daems\Tests\Isolation;

use Daems\Application\Backstage\AdminUpdateProject\AdminUpdateProject;
use Daems\Application\Backstage\AdminUpdateProject\AdminUpdateProjectInput;
use Daems\Application\Backstage\ApproveProjectProposal\ApproveProjectProposal;
use Daems\Application\Backstage\ApproveProjectProposal\ApproveProjectProposalInput;
use Daems\Application\Backstage\ChangeProjectStatus\ChangeProjectStatus;
use Daems\Application\Backstage\ChangeProjectStatus\ChangeProjectStatusInput;
use Daems\Application\Backstage\DeleteProjectCommentAsAdmin\DeleteProjectCommentAsAdmin;
use Daems\Application\Backstage\DeleteProjectCommentAsAdmin\DeleteProjectCommentAsAdminInput;
use Daems\Application\Backstage\ListProjectsForAdmin\ListProjectsForAdmin;
use Daems\Application\Backstage\ListProjectsForAdmin\ListProjectsForAdminInput;
use Daems\Application\Backstage\SetProjectFeatured\SetProjectFeatured;
use Daems\Application\Backstage\SetProjectFeatured\SetProjectFeaturedInput;
use Daems\Domain\Shared\Clock;
use Daems\Domain\Shared\IdGeneratorInterface;
use Daems\Domain\Shared\NotFoundException;
use Daems\Domain\Shared\ValueObject\Uuid7;
use Daems\Domain\Tenant\UserTenantRole;
use Daems\Infrastructure\Adapter\Persistence\Sql\PdoTransactionManager;
use Daems\Infrastructure\Adapter\Persistence\Sql\SqlAdminApplicationDismissalRepository;
use Daems\Infrastructure\Adapter\Persistence\Sql\SqlProjectCommentModerationAuditRepository;
use Daems\Infrastructure\Adapter\Persistence\Sql\SqlProjectProposalRepository;
use Daems\Infrastructure\Adapter\Persistence\Sql\SqlProjectRepository;
use Daems\Infrastructure\Framework\Database\Connection;
use DateTimeImmutable;

final class ProjectsAdminTenantIsolationTest extends IsolationTestCase
{
    private Connection $conn;
    private SqlProjectRepository $projects;
    private SqlProjectProposalRepository $proposals;
    private SqlProjectCommentModerationAuditRepository $audit;
    private SqlAdminApplicationDismissalRepository $dismissals;

    protected function setUp(): void
    {
        parent::setUp();
        $this->conn = new Connection([
            'host'     => getenv('TEST_DB_HOST') ?: '127.0.0.1',
            'port'     => getenv('TEST_DB_PORT') ?: '3306',
            'database' => getenv('TEST_DB_NAME') ?: 'daems_db_test',
            'username' => getenv('TEST_DB_USER') ?: 'root',
            'password' => getenv('TEST_DB_PASS') ?: 'salasana',
        ]);
        $this->projects   = new SqlProjectRepository($this->conn);
        $this->proposals  = new SqlProjectProposalRepository($this->conn);
        $this->audit      = new SqlProjectCommentModerationAuditRepository($this->conn);
        $this->dismissals = new SqlAdminApplicationDismissalRepository($this->conn->pdo());
    }

    private function seedProject(string $tenantSlug, string $slug, string $title, string $status = 'active'): string
    {
        $id = Uuid7::generate()->value();
        $this->pdo()->prepare(
            "INSERT INTO projects (id, tenant_id, slug, category, icon, status, sort_order, featured)
             VALUES (?, (SELECT id FROM tenants WHERE slug = ?), ?, 'community', 'bi-folder', ?, 0, 0)"
        )->execute([$id, $tenantSlug, $slug, $status]);
        $this->pdo()->prepare(
            "INSERT INTO projects_i18n (project_id, locale, title, summary, description)
             VALUES (?, 'fi_FI', ?, 'sum', 'desc text long enough')"
        )->execute([$id, $title]);
        return $id;
    }

    private function seedProposal(string $tenantSlug, string $userId, string $title): string
    {
        $id = Uuid7::generate()->value();
        $this->pdo()->prepare(
            "INSERT INTO project_proposals (id, tenant_id, user_id, author_name, author_email, title, category, summary, description, status, created_at)
             VALUES (?, (SELECT id FROM tenants WHERE slug = ?), ?, 'Author', 'a@x.com', ?, 'community', 'Summary long enough.', 'Description long enough for validation.', 'pending', NOW())"
        )->execute([$id, $tenantSlug, $userId, $title]);
        return $id;
    }

    private function seedComment(string $tenantSlug, string $projectId, string $userId): string
    {
        $id = Uuid7::generate()->value();
        $this->pdo()->prepare(
            "INSERT INTO project_comments
                (id, tenant_id, project_id, user_id, author_name, avatar_initials, avatar_color, content, likes, created_at)
             VALUES (?, (SELECT id FROM tenants WHERE slug = ?), ?, ?, 'Cmt Author', 'CA', '#abc', 'comment text', 0, NOW())"
        )->execute([$id, $tenantSlug, $projectId, $userId]);
        return $id;
    }

    private function seedUserInTenant(string $tenantSlug): string
    {
        $uid = Uuid7::generate()->value();
        $this->pdo()->prepare(
            'INSERT INTO users (id, name, email, password_hash, date_of_birth, is_platform_admin)
             VALUES (?, ?, ?, ?, ?, 0)'
        )->execute([
            $uid, 'Some User', 'user-' . substr($uid, 0, 8) . '@test.com',
            'x', '1990-01-01',
        ]);
        $this->pdo()->prepare(
            'INSERT INTO user_tenants (user_id, tenant_id, role, joined_at)
             VALUES (?, (SELECT id FROM tenants WHERE slug = ?), ?, NOW())'
        )->execute([$uid, $tenantSlug, 'registered']);
        return $uid;
    }

    private function clock(): Clock
    {
        $now = new DateTimeImmutable('2026-04-20T12:00:00Z');
        return new class ($now) implements Clock {
            public function __construct(private readonly DateTimeImmutable $at) {}
            public function now(): DateTimeImmutable { return $this->at; }
        };
    }

    private function ids(): IdGeneratorInterface
    {
        return new class implements IdGeneratorInterface {
            public function generate(): string
            {
                return Uuid7::generate()->value();
            }
        };
    }

    public function test_admin_a_list_does_not_include_tenant_b_projects(): void
    {
        $this->seedProject('daems',     'daems-proj-a', 'Daems Project A');
        $this->seedProject('sahegroup', 'sahe-proj-b',  'Sahe Project B');

        $adminA = $this->makeActingUser(
            'daems', UserTenantRole::Admin, false,
            '01958000-0000-7000-8000-ad0000da0001',
            'admin-a-proj-list@test',
        );

        $uc  = new ListProjectsForAdmin($this->projects);
        $out = $uc->execute(new ListProjectsForAdminInput($adminA, null, null, null, null));

        $titles = array_column($out->items, 'title');
        self::assertContains('Daems Project A', $titles);
        self::assertNotContains('Sahe Project B', $titles);
    }

    public function test_admin_a_cannot_update_tenant_b_project(): void
    {
        $saheProjectId = $this->seedProject('sahegroup', 'sahe-upd', 'Sahe Upd Project');

        $adminA = $this->makeActingUser(
            'daems', UserTenantRole::Admin, false,
            '01958000-0000-7000-8000-ad0000da0002',
            'admin-a-proj-upd@test',
        );

        $uc = new AdminUpdateProject($this->projects);

        $this->expectException(NotFoundException::class);
        $this->expectExceptionMessage('project_not_found');
        $uc->execute(new AdminUpdateProjectInput(
            $adminA, $saheProjectId,
            'Malicious Title Rewrite', null, null, null, null, null,
        ));
    }

    public function test_admin_a_cannot_change_status_of_tenant_b_project(): void
    {
        $saheProjectId = $this->seedProject('sahegroup', 'sahe-stat', 'Sahe Status Project');

        $adminA = $this->makeActingUser(
            'daems', UserTenantRole::Admin, false,
            '01958000-0000-7000-8000-ad0000da0003',
            'admin-a-proj-stat@test',
        );

        $uc = new ChangeProjectStatus($this->projects);

        $this->expectException(NotFoundException::class);
        $this->expectExceptionMessage('project_not_found');
        $uc->execute(new ChangeProjectStatusInput($adminA, $saheProjectId, 'archived'));
    }

    public function test_admin_a_cannot_set_featured_on_tenant_b_project(): void
    {
        $saheProjectId = $this->seedProject('sahegroup', 'sahe-feat', 'Sahe Featured Project');

        $adminA = $this->makeActingUser(
            'daems', UserTenantRole::Admin, false,
            '01958000-0000-7000-8000-ad0000da0004',
            'admin-a-proj-feat@test',
        );

        $uc = new SetProjectFeatured($this->projects);

        $this->expectException(NotFoundException::class);
        $this->expectExceptionMessage('project_not_found');
        $uc->execute(new SetProjectFeaturedInput($adminA, $saheProjectId, true));
    }

    public function test_admin_a_cannot_approve_tenant_b_proposal(): void
    {
        $saheUser       = $this->seedUserInTenant('sahegroup');
        $saheProposalId = $this->seedProposal('sahegroup', $saheUser, 'Sahe Proposal');

        $adminA = $this->makeActingUser(
            'daems', UserTenantRole::Admin, false,
            '01958000-0000-7000-8000-ad0000da0005',
            'admin-a-prop-appr@test',
        );

        $uc = new ApproveProjectProposal(
            $this->proposals, $this->projects, $this->dismissals,
            new PdoTransactionManager($this->conn->pdo()),
            $this->clock(), $this->ids(),
        );

        $this->expectException(NotFoundException::class);
        $this->expectExceptionMessage('proposal_not_found');
        $uc->execute(new ApproveProjectProposalInput($adminA, $saheProposalId, 'approved'));
    }

    public function test_admin_a_delete_comment_in_tenant_b_does_not_delete_or_audit_tenant_b(): void
    {
        $saheUser      = $this->seedUserInTenant('sahegroup');
        $saheProjectId = $this->seedProject('sahegroup', 'sahe-cmt', 'Sahe Comment Project');
        $saheCommentId = $this->seedComment('sahegroup', $saheProjectId, $saheUser);

        $saheTenantId = $this->tenantId('sahegroup');

        $adminA = $this->makeActingUser(
            'daems', UserTenantRole::Admin, false,
            '01958000-0000-7000-8000-ad0000da0006',
            'admin-a-cmt-del@test',
        );

        $uc = new DeleteProjectCommentAsAdmin(
            $this->projects, $this->audit, $this->clock(), $this->ids(),
        );
        $uc->execute(new DeleteProjectCommentAsAdminInput(
            $adminA, $saheProjectId, $saheCommentId, 'trying to cross tenant',
        ));

        // The comment row must still exist in tenant B.
        $stmt = $this->pdo()->prepare('SELECT COUNT(*) FROM project_comments WHERE id = ?');
        $stmt->execute([$saheCommentId]);
        self::assertSame(1, (int) $stmt->fetchColumn(), 'tenant-B comment must not be deleted by admin-A');

        // No audit row must exist referencing the tenant-B resources under tenant-B's id.
        $auditCheck = $this->pdo()->prepare(
            'SELECT COUNT(*) FROM project_comment_moderation_audit
             WHERE tenant_id = ? AND comment_id = ?'
        );
        $auditCheck->execute([$saheTenantId->value(), $saheCommentId]);
        self::assertSame(0, (int) $auditCheck->fetchColumn(), 'no audit row must land under tenant B for a cross-tenant attempt');
    }
}
