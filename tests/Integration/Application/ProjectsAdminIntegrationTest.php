<?php

declare(strict_types=1);

namespace Daems\Tests\Integration\Application;

use Daems\Application\Backstage\ApproveProjectProposal\ApproveProjectProposal;
use Daems\Application\Backstage\ApproveProjectProposal\ApproveProjectProposalInput;
use Daems\Application\Backstage\ListProjectsForAdmin\ListProjectsForAdmin;
use Daems\Application\Backstage\ListProjectsForAdmin\ListProjectsForAdminInput;
use Daems\Application\Backstage\SetProjectFeatured\SetProjectFeatured;
use Daems\Application\Backstage\SetProjectFeatured\SetProjectFeaturedInput;
use Daems\Application\Project\ListProjects\ListProjects;
use Daems\Application\Project\ListProjects\ListProjectsInput;
use Daems\Domain\Auth\ActingUser;
use Daems\Domain\Shared\Clock;
use Daems\Domain\Shared\IdGeneratorInterface;
use Daems\Domain\Shared\ValueObject\Uuid7;
use Daems\Domain\Tenant\TenantId;
use Daems\Domain\Tenant\UserTenantRole;
use Daems\Domain\User\UserId;
use Daems\Infrastructure\Adapter\Persistence\Sql\PdoTransactionManager;
use Daems\Infrastructure\Adapter\Persistence\Sql\SqlAdminApplicationDismissalRepository;
use Daems\Infrastructure\Adapter\Persistence\Sql\SqlProjectProposalRepository;
use Daems\Infrastructure\Adapter\Persistence\Sql\SqlProjectRepository;
use Daems\Infrastructure\Framework\Database\Connection;
use Daems\Tests\Integration\MigrationTestCase;
use DateTimeImmutable;

final class ProjectsAdminIntegrationTest extends MigrationTestCase
{
    private Connection $conn;
    private string $tenantId;
    private string $adminId;
    private string $proposalUserId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->runMigrationsUpTo(56);

        $this->conn = new Connection([
            'host'     => getenv('TEST_DB_HOST') ?: '127.0.0.1',
            'port'     => getenv('TEST_DB_PORT') ?: '3306',
            'database' => getenv('TEST_DB_NAME') ?: 'daems_db_test',
            'username' => getenv('TEST_DB_USER') ?: 'root',
            'password' => getenv('TEST_DB_PASS') ?: 'salasana',
        ]);

        $this->tenantId = Uuid7::generate()->value();
        $this->pdo()->prepare(
            'INSERT INTO tenants (id, slug, name, created_at) VALUES (?, ?, ?, NOW())'
        )->execute([$this->tenantId, 'proj-int', 'Projects Integration Test']);

        $this->adminId = Uuid7::generate()->value();
        $this->pdo()->prepare(
            'INSERT INTO users (id, name, email, password_hash, date_of_birth, is_platform_admin)
             VALUES (?, ?, ?, ?, ?, 0)'
        )->execute([
            $this->adminId, 'Admin User', 'admin-proj@test.com',
            password_hash('pass1234', PASSWORD_BCRYPT), '1980-01-01',
        ]);
        $this->pdo()->prepare(
            'INSERT INTO user_tenants (user_id, tenant_id, role, joined_at) VALUES (?, ?, ?, NOW())'
        )->execute([$this->adminId, $this->tenantId, 'admin']);

        $this->proposalUserId = Uuid7::generate()->value();
        $this->pdo()->prepare(
            'INSERT INTO users (id, name, email, password_hash, date_of_birth, is_platform_admin)
             VALUES (?, ?, ?, ?, ?, 0)'
        )->execute([
            $this->proposalUserId, 'Proposer Name', 'proposer@test.com',
            password_hash('pass1234', PASSWORD_BCRYPT), '1990-01-01',
        ]);
    }

    private function actingAdmin(): ActingUser
    {
        return new ActingUser(
            id:                 UserId::fromString($this->adminId),
            email:              'admin-proj@test.com',
            isPlatformAdmin:    false,
            activeTenant:       TenantId::fromString($this->tenantId),
            roleInActiveTenant: UserTenantRole::Admin,
        );
    }

    private function makeIds(): IdGeneratorInterface
    {
        return new class implements IdGeneratorInterface {
            public function generate(): string
            {
                return Uuid7::generate()->value();
            }
        };
    }

    private function makeClock(string $iso = '2026-04-20T12:00:00Z'): Clock
    {
        $at = new DateTimeImmutable($iso);
        return new class ($at) implements Clock {
            public function __construct(private readonly DateTimeImmutable $at) {}
            public function now(): DateTimeImmutable { return $this->at; }
        };
    }

    private function seedProposal(string $title, string $status = 'pending'): string
    {
        $proposalId = Uuid7::generate()->value();
        $this->pdo()->prepare(
            "INSERT INTO project_proposals
                (id, tenant_id, user_id, author_name, author_email, title, category, summary, description, status, created_at)
             VALUES (?, ?, ?, ?, ?, ?, 'community', 'Valid summary long enough.', 'Valid description long enough for validation to pass.', ?, '2026-04-20 10:00:00')"
        )->execute([
            $proposalId, $this->tenantId, $this->proposalUserId,
            'Proposer Name', 'proposer@test.com', $title, $status,
        ]);
        return $proposalId;
    }

    private function seedProjectRow(
        string $slug,
        string $title,
        string $status = 'active',
        bool $featured = false,
        string $createdAt = '2026-04-20 10:00:00',
    ): string {
        $id = Uuid7::generate()->value();
        $this->pdo()->prepare(
            "INSERT INTO projects
                (id, tenant_id, slug, title, category, icon, summary, description, status, sort_order, featured, created_at)
             VALUES (?, ?, ?, ?, 'community', 'bi-folder', 'short summary', 'longer description for test', ?, 0, ?, ?)"
        )->execute([$id, $this->tenantId, $slug, $title, $status, $featured ? 1 : 0, $createdAt]);
        return $id;
    }

    public function test_proposal_approve_creates_project_and_clears_dismissals(): void
    {
        $proposalId = $this->seedProposal('Integration Approve Flow');

        // Seed a dismissal row so we can assert it gets deleted.
        $this->pdo()->prepare(
            "INSERT INTO admin_application_dismissals (id, admin_id, app_id, app_type, dismissed_at)
             VALUES (?, ?, ?, 'project_proposal', NOW())"
        )->execute([Uuid7::generate()->value(), $this->adminId, $proposalId]);

        $proposalRepo    = new SqlProjectProposalRepository($this->conn);
        $projectRepo     = new SqlProjectRepository($this->conn);
        $dismissalRepo   = new SqlAdminApplicationDismissalRepository($this->conn->pdo());
        $tx              = new PdoTransactionManager($this->conn->pdo());

        $uc = new ApproveProjectProposal(
            $proposalRepo, $projectRepo, $dismissalRepo, $tx,
            $this->makeClock('2026-04-20T12:00:00Z'), $this->makeIds(),
        );

        $out = $uc->execute(new ApproveProjectProposalInput(
            $this->actingAdmin(), $proposalId, 'Looks good, approved.',
        ));

        // Project row exists, status=draft, owner_id = proposer.user_id
        $row = $this->pdo()->prepare('SELECT * FROM projects WHERE id = ?');
        $row->execute([$out->projectId]);
        $project = $row->fetch(\PDO::FETCH_ASSOC);
        self::assertIsArray($project);
        self::assertSame('draft', $project['status']);
        self::assertSame($this->proposalUserId, $project['owner_id']);
        self::assertSame('Integration Approve Flow', $project['title']);
        self::assertSame('community', $project['category']);
        self::assertSame($out->slug, $project['slug']);

        // Proposal row now approved with decision metadata
        $pRow = $this->pdo()->prepare('SELECT status, decided_at, decided_by, decision_note FROM project_proposals WHERE id = ?');
        $pRow->execute([$proposalId]);
        $proposal = $pRow->fetch(\PDO::FETCH_ASSOC);
        self::assertIsArray($proposal);
        self::assertSame('approved', $proposal['status']);
        self::assertNotEmpty($proposal['decided_at']);
        self::assertSame($this->adminId, $proposal['decided_by']);
        self::assertSame('Looks good, approved.', $proposal['decision_note']);

        // Dismissal row for this proposal was removed
        $dRow = $this->pdo()->prepare('SELECT COUNT(*) FROM admin_application_dismissals WHERE app_id = ?');
        $dRow->execute([$proposalId]);
        self::assertSame(0, (int) $dRow->fetchColumn());
    }

    public function test_admin_list_sees_drafts_but_public_list_does_not(): void
    {
        $this->seedProjectRow('draft-p',    'Draft Project',    'draft');
        $this->seedProjectRow('active-p',   'Active Project',   'active');
        $this->seedProjectRow('archived-p', 'Archived Project', 'archived');

        $projectRepo = new SqlProjectRepository($this->conn);
        $adminUc = new ListProjectsForAdmin($projectRepo);
        $adminOut = $adminUc->execute(new ListProjectsForAdminInput(
            $this->actingAdmin(), null, null, null, null,
        ));

        $adminTitles = array_column($adminOut->items, 'title');
        self::assertContains('Draft Project',    $adminTitles);
        self::assertContains('Active Project',   $adminTitles);
        self::assertContains('Archived Project', $adminTitles);
        self::assertCount(3, $adminOut->items);

        $publicUc  = new ListProjects($projectRepo);
        $publicOut = $publicUc->execute(new ListProjectsInput(
            TenantId::fromString($this->tenantId),
        ));

        $publicTitles = array_column($publicOut->projects, 'title');
        self::assertContains('Active Project', $publicTitles);
        self::assertNotContains('Draft Project', $publicTitles);
        self::assertNotContains('Archived Project', $publicTitles);
    }

    public function test_featured_projects_surface_first_in_public_list(): void
    {
        // Non-featured created LATER (would otherwise come first by created_at DESC).
        $this->seedProjectRow('plain-p',    'Plain Project',    'active', false, '2026-04-20 12:00:00');
        $this->seedProjectRow('featured-p', 'Featured Project', 'active', false, '2026-04-20 10:00:00');

        // Now mark one featured via the use case path.
        $projectRepo = new SqlProjectRepository($this->conn);
        $featuredRow = $this->pdo()->prepare('SELECT id FROM projects WHERE slug = ?');
        $featuredRow->execute(['featured-p']);
        $featuredId = (string) $featuredRow->fetchColumn();

        $setFeaturedUc = new SetProjectFeatured($projectRepo);
        $setFeaturedUc->execute(new SetProjectFeaturedInput(
            $this->actingAdmin(), $featuredId, true,
        ));

        $publicUc  = new ListProjects($projectRepo);
        $publicOut = $publicUc->execute(new ListProjectsInput(
            TenantId::fromString($this->tenantId),
        ));

        self::assertGreaterThanOrEqual(2, count($publicOut->projects));
        self::assertSame('Featured Project', $publicOut->projects[0]['title'], 'featured project must surface first');
        self::assertSame('Plain Project',    $publicOut->projects[1]['title']);
    }
}
