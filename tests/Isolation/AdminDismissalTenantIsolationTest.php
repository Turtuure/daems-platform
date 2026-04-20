<?php

declare(strict_types=1);

namespace Daems\Tests\Isolation;

use Daems\Application\Backstage\DismissApplication\DismissApplication;
use Daems\Application\Backstage\DismissApplication\DismissApplicationInput;
use Daems\Application\Backstage\ListPendingApplications\ListPendingApplicationsForAdmin;
use Daems\Application\Backstage\ListPendingApplications\ListPendingApplicationsForAdminInput;
use Daems\Domain\Auth\ActingUser;
use Daems\Domain\Shared\ValueObject\Uuid7;
use Daems\Domain\Tenant\UserTenantRole;
use Daems\Infrastructure\Adapter\Persistence\Sql\SqlAdminApplicationDismissalRepository;
use Daems\Infrastructure\Adapter\Persistence\Sql\SqlMemberApplicationRepository;
use Daems\Infrastructure\Adapter\Persistence\Sql\SqlProjectProposalRepository;
use Daems\Infrastructure\Adapter\Persistence\Sql\SqlSupporterApplicationRepository;
use Daems\Infrastructure\Framework\Clock\SystemClock;
use Daems\Infrastructure\Framework\Database\Connection;

/**
 * Verifies that admin A's dismissals do not affect admin B's pending-count view
 * even when both admins operate under different tenants.
 */
final class AdminDismissalTenantIsolationTest extends IsolationTestCase
{
    private Connection $conn;

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
    }

    private function buildDismissApplication(): DismissApplication
    {
        $pdo = $this->conn->pdo();
        return new DismissApplication(
            new SqlAdminApplicationDismissalRepository($pdo),
            new SystemClock(),
            new class implements \Daems\Domain\Shared\IdGeneratorInterface {
                public function generate(): string { return Uuid7::generate()->value(); }
            },
        );
    }

    private function buildListPendingForAdmin(): ListPendingApplicationsForAdmin
    {
        return new ListPendingApplicationsForAdmin(
            new SqlMemberApplicationRepository($this->conn),
            new SqlSupporterApplicationRepository($this->conn),
            new SqlAdminApplicationDismissalRepository($this->conn->pdo()),
            new SqlProjectProposalRepository($this->conn),
        );
    }

    private function seedPendingApp(string $tenantSlug, string $name): string
    {
        $id = Uuid7::generate()->value();
        $tenantId = $this->tenantId($tenantSlug)->value();
        $this->pdo()->prepare(
            "INSERT INTO member_applications (id, tenant_id, name, email, date_of_birth, motivation, status)
             VALUES (?, ?, ?, ?, '1990-01-01', 'motive', 'pending')"
        )->execute([$id, $tenantId, $name, strtolower($name) . '@x.com']);
        return $id;
    }

    public function test_admin_a_dismissal_does_not_affect_admin_b_pending_view(): void
    {
        // Seed two pending apps — one per tenant
        $daemsAppId = $this->seedPendingApp('daems', 'Daems Applicant');
        $saheAppId  = $this->seedPendingApp('sahegroup', 'Sahe Applicant');

        // Create admin A (daems) and admin B (sahegroup)
        $adminA = $this->makeActingUser(
            'daems',
            UserTenantRole::Admin,
            false,
            '01958000-0000-7000-8000-000000000aa1',
            'adminA@daems.test',
        );
        $adminB = $this->makeActingUser(
            'sahegroup',
            UserTenantRole::Admin,
            false,
            '01958000-0000-7000-8000-000000000bb2',
            'adminB@sahe.test',
        );

        $dismiss = $this->buildDismissApplication();
        $listForAdmin = $this->buildListPendingForAdmin();

        // Admin A dismisses the daems application (from their view)
        $dismiss->execute(new DismissApplicationInput($adminA, $daemsAppId, 'member'));

        // Admin A's list should now be empty (dismissed)
        $outA = $listForAdmin->execute(new ListPendingApplicationsForAdminInput($adminA));
        self::assertSame(0, $outA->total, 'Admin A should see 0 pending after dismissal');

        // Admin B's list should still contain the sahegroup application
        $outB = $listForAdmin->execute(new ListPendingApplicationsForAdminInput($adminB));
        self::assertSame(1, $outB->total, 'Admin B should still see their pending application');

        $visibleIds = array_column($outB->items, 'id');
        self::assertContains($saheAppId, $visibleIds, 'Admin B should see the sahegroup app');
        self::assertNotContains($daemsAppId, $visibleIds, 'Admin B should NOT see the daems app (wrong tenant)');
    }
}
