<?php

declare(strict_types=1);

namespace Daems\Tests\Isolation;

use Daems\Application\Backstage\Projects\ListProjectsStats\ListProjectsStats;
use Daems\Application\Backstage\Projects\ListProjectsStats\ListProjectsStatsInput;
use Daems\Domain\Shared\ValueObject\Uuid7;
use Daems\Domain\Tenant\TenantId;
use Daems\Infrastructure\Adapter\Persistence\Sql\SqlProjectProposalRepository;
use Daems\Infrastructure\Adapter\Persistence\Sql\SqlProjectRepository;
use Daems\Infrastructure\Framework\Database\Connection;

final class ProjectsStatsTenantIsolationTest extends IsolationTestCase
{
    private ListProjectsStats $usecase;

    protected function setUp(): void
    {
        parent::setUp();

        $conn = new Connection([
            'host'     => getenv('TEST_DB_HOST') ?: '127.0.0.1',
            'port'     => getenv('TEST_DB_PORT') ?: '3306',
            'database' => getenv('TEST_DB_NAME') ?: 'daems_db_test',
            'username' => getenv('TEST_DB_USER') ?: 'root',
            'password' => getenv('TEST_DB_PASS') ?: 'salasana',
        ]);

        $this->usecase = new ListProjectsStats(
            new SqlProjectRepository($conn),
            new SqlProjectProposalRepository($conn),
        );
    }

    /**
     * Insert one projects row + companion projects_i18n row.
     * After migration 054, translated columns (title/summary/description) live
     * solely in projects_i18n.
     */
    private function seedProject(
        TenantId $tenantId,
        string $status,
        bool $featured,
    ): string {
        $id   = Uuid7::generate()->value();
        $slug = 'prj-' . substr(str_replace('-', '', $id), 0, 24);

        $this->pdo()->prepare(
            'INSERT INTO projects
                (id, tenant_id, slug, category, icon, status, featured, sort_order, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, 0, NOW())'
        )->execute([
            $id,
            $tenantId->value(),
            $slug,
            'general',
            'bi-folder',
            $status,
            $featured ? 1 : 0,
        ]);

        $this->pdo()->prepare(
            'INSERT INTO projects_i18n (project_id, locale, title, summary, description)
             VALUES (?, ?, ?, ?, ?)'
        )->execute([
            $id,
            'fi_FI',
            'Project ' . substr($id, 0, 8),
            'Summary ' . substr($id, 0, 8),
            'Description ' . substr($id, 0, 8),
        ]);

        return $id;
    }

    /**
     * Insert one project_proposals row (pending) + a minimal users row (FK target).
     * created_at = today (within 30d window).
     */
    private function seedPendingProposal(TenantId $tenantId): void
    {
        $id     = Uuid7::generate()->value();
        $userId = Uuid7::generate()->value();

        $this->pdo()->prepare(
            'INSERT INTO users (id, name, email, password_hash, date_of_birth, created_at)
             VALUES (?, ?, ?, ?, ?, NOW())'
        )->execute([
            $userId,
            'Proposer ' . substr($userId, 0, 6),
            'proposer-' . str_replace('-', '', $userId) . '@example.test',
            password_hash('x', PASSWORD_BCRYPT),
            '1990-01-01',
        ]);

        $this->pdo()->prepare(
            'INSERT INTO project_proposals
                (id, tenant_id, user_id, author_name, author_email, title,
                 category, summary, description, source_locale, status,
                 created_at, decided_at, decided_by, decision_note)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NULL, NULL, NULL)'
        )->execute([
            $id,
            $tenantId->value(),
            $userId,
            'Author ' . substr($id, 0, 8),
            'author-' . substr(str_replace('-', '', $id), 0, 12) . '@example.test',
            'Proposal ' . substr($id, 0, 8),
            'general',
            'A short summary for the proposal.',
            'A longer description for the proposal.',
            'fi_FI',
            'pending',
        ]);
    }

    public function test_project_stats_isolated_per_tenant_with_asymmetric_seeds(): void
    {
        // Asymmetric seeds — a leaky impl (e.g. ignoring tenant_id) cannot pass.
        //
        // daems:
        //   3 active projects (2 featured, 1 not)
        //   1 draft project
        //   2 pending project_proposals (today)
        //   → active = 3, drafts = 1, featured = 2, pending_proposals = 2
        //
        // sahegroup:
        //   1 active project (0 featured)
        //   0 drafts
        //   1 pending proposal (today)
        //   → active = 1, drafts = 0, featured = 0, pending_proposals = 1
        $daemsTenant = $this->tenantId('daems');
        $saheTenant  = $this->tenantId('sahegroup');

        // daems seeds
        $this->seedProject($daemsTenant, 'active', featured: true);
        $this->seedProject($daemsTenant, 'active', featured: true);
        $this->seedProject($daemsTenant, 'active', featured: false);
        $this->seedProject($daemsTenant, 'draft',  featured: false);
        $this->seedPendingProposal($daemsTenant);
        $this->seedPendingProposal($daemsTenant);

        // sahegroup seeds
        $this->seedProject($saheTenant, 'active', featured: false);
        $this->seedPendingProposal($saheTenant);

        $daemsAdmin = $this->makeActingUser(
            'daems',
            \Daems\Domain\Tenant\UserTenantRole::Admin,
            userId: '01958000-0000-7000-8000-0000000d0001',
            email:  'admin-daems-prj@test',
        );
        $saheAdmin = $this->makeActingUser(
            'sahegroup',
            \Daems\Domain\Tenant\UserTenantRole::Admin,
            userId: '01958000-0000-7000-8000-0000000d0002',
            email:  'admin-sahe-prj@test',
        );

        $daems = $this->usecase->execute(
            new ListProjectsStatsInput(acting: $daemsAdmin, tenantId: $daemsTenant),
        )->stats;
        $sahe = $this->usecase->execute(
            new ListProjectsStatsInput(acting: $saheAdmin, tenantId: $saheTenant),
        )->stats;

        // daems
        self::assertSame(3, $daems['active']['value']);
        self::assertSame(1, $daems['drafts']['value']);
        self::assertSame(2, $daems['featured']['value']);
        self::assertSame(2, $daems['pending_proposals']['value']);

        // sahegroup — must NOT see daems' rows.
        self::assertSame(1, $sahe['active']['value']);
        self::assertSame(0, $sahe['drafts']['value']);
        self::assertSame(0, $sahe['featured']['value']);
        self::assertSame(1, $sahe['pending_proposals']['value']);
    }
}
