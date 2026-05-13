<?php
declare(strict_types=1);

namespace Daems\Tests\Integration\Cron;

use Daems\Application\Membership\Billing\Cron\GenerateAnniversaryInvoicesCommand;
use Daems\Application\Membership\Billing\GenerateAnniversaryInvoice\GenerateAnniversaryInvoice;
use Daems\Domain\Membership\Billing\AnnualFeeSchedule;
use Daems\Domain\Membership\Billing\AnnualFeeScheduleId;
use Daems\Domain\Membership\Billing\AnnualFeeScheduleStatus;
use Daems\Domain\Membership\MembershipType;
use Daems\Domain\Tenant\TenantId;
use Daems\Domain\User\UserId;
use Daems\Infrastructure\Adapter\Persistence\Sql\SqlAnnualFeeScheduleRepository;
use Daems\Infrastructure\Adapter\Persistence\Sql\SqlMemberFeeInvoiceRepository;
use Daems\Infrastructure\Adapter\Persistence\Sql\SqlTenantGovernanceSettingsRepository;
use Daems\Infrastructure\Adapter\Persistence\Sql\SqlUserRepository;
use Daems\Infrastructure\Console\CronLogger;
use Daems\Infrastructure\Console\LockManager;
use Daems\Infrastructure\Framework\Database\Connection;
use Daems\Tests\Integration\MigrationTestCase;
use Daems\Tests\Support\Fake\InMemoryUserFeeOverrideRepository;
use Daems\Tests\Support\FrozenClock;
use DateTimeImmutable;

final class GenerateAnniversaryInvoicesCommandTest extends MigrationTestCase
{
    private Connection $conn;

    protected function setUp(): void
    {
        parent::setUp();
        $this->runMigrationsUpTo(95);
        $this->conn = new Connection([
            'host'     => getenv('TEST_DB_HOST') ?: '127.0.0.1',
            'port'     => getenv('TEST_DB_PORT') ?: '3306',
            'database' => getenv('TEST_DB_NAME') ?: 'daems_db_test',
            'username' => getenv('TEST_DB_USER') ?: 'root',
            'password' => getenv('TEST_DB_PASS') ?: 'salasana',
        ]);
    }

    public function test_creates_invoices_for_users_whose_anniversary_is_today(): void
    {
        $tenantId = $this->tenantIdBySlug('daems');
        $userMatch  = $this->seedUser($tenantId, 'match@daems.fi',  anniversary: '2024-07-15', type: 'BASIC');
        $userOff    = $this->seedUser($tenantId, 'off@daems.fi',    anniversary: '2024-07-14', type: 'BASIC');
        $userHonor  = $this->seedUser($tenantId, 'honor@daems.fi',  anniversary: '2024-07-15', type: 'HONORARY');
        $userPaused = $this->seedUser($tenantId, 'paused@daems.fi', anniversary: '2024-07-15', type: 'BASIC', status: 'paused');
        $userTooNew = $this->seedUser($tenantId, 'newbie@daems.fi', anniversary: '2026-07-15', type: 'BASIC');

        $this->seedActiveSchedule($tenantId, 2026, MembershipType::Basic, 5000);

        $command = $this->makeCommand(today: new DateTimeImmutable('2026-07-15T02:00:00'));
        $exit = $command->execute(['tenant' => 'daems']);

        $this->assertSame(0, $exit);
        $this->assertNotNull($this->findInvoice($tenantId, $userMatch, 2026));
        $this->assertNull($this->findInvoice($tenantId, $userOff, 2026));
        $this->assertNull($this->findInvoice($tenantId, $userHonor, 2026));
        $this->assertNull($this->findInvoice($tenantId, $userPaused, 2026));
        $this->assertNull($this->findInvoice($tenantId, $userTooNew, 2026));
    }

    public function test_idempotent_second_run_creates_nothing(): void
    {
        $tenantId = $this->tenantIdBySlug('daems');
        $this->seedUser($tenantId, 'match@daems.fi', anniversary: '2024-07-15', type: 'BASIC');
        $this->seedActiveSchedule($tenantId, 2026, MembershipType::Basic, 5000);

        $command = $this->makeCommand(today: new DateTimeImmutable('2026-07-15T02:00:00'));
        $command->execute(['tenant' => 'daems']);
        $countAfterFirst = $this->countInvoices($tenantId, 2026);

        $command->execute(['tenant' => 'daems']);
        $countAfterSecond = $this->countInvoices($tenantId, 2026);

        $this->assertSame(1, $countAfterFirst);
        $this->assertSame($countAfterFirst, $countAfterSecond);
    }

    private function tenantIdBySlug(string $slug): TenantId
    {
        $stmt = $this->pdo()->prepare('SELECT id FROM tenants WHERE slug = ?');
        $stmt->execute([$slug]);
        return TenantId::fromString((string) $stmt->fetchColumn());
    }

    private function seedUser(TenantId $tenantId, string $email, string $anniversary, string $type, string $status = 'active'): UserId
    {
        $id = '01958000-0000-7000-9000-' . substr(md5($email), 0, 12);
        $this->pdo()->prepare(
            'INSERT INTO users (id, name, email, password_hash, date_of_birth, is_platform_admin, membership_type, membership_status, membership_started_at)
             VALUES (?, ?, ?, NULL, ?, 0, ?, ?, ?)'
        )->execute([
            $id, 'User-' . substr($email, 0, 5), $email, '1990-01-01', $type, $status, $anniversary . ' 00:00:00',
        ]);

        $this->pdo()->prepare(
            "INSERT INTO user_tenants (user_id, tenant_id, role, joined_at)
             VALUES (?, ?, 'member', ?)"
        )->execute([$id, $tenantId->value(), $anniversary . ' 00:00:00']);

        return UserId::fromString($id);
    }

    private function seedActiveSchedule(TenantId $tenantId, int $year, MembershipType $type, int $amountCents): void
    {
        $repo = new SqlAnnualFeeScheduleRepository($this->pdo());
        $repo->save(new AnnualFeeSchedule(
            id:           AnnualFeeScheduleId::generate(),
            tenantId:     $tenantId,
            year:         $year,
            feeType:      $type,
            amountCents:  $amountCents,
            currency:     'EUR',
            status:       AnnualFeeScheduleStatus::Active,
            decisionId:   null,
            activatedAt:  new DateTimeImmutable('2025-12-01'),
            activatedBy:  null,
            supersededAt: null,
            createdAt:    new DateTimeImmutable('2025-12-01'),
            createdBy:    null,
        ));
    }

    /** @return array<string,mixed>|null */
    private function findInvoice(TenantId $tenantId, UserId $userId, int $year): ?array
    {
        $stmt = $this->pdo()->prepare('SELECT * FROM member_fee_invoices WHERE tenant_id = ? AND user_id = ? AND year = ?');
        $stmt->execute([$tenantId->value(), $userId->value(), $year]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    private function countInvoices(TenantId $tenantId, int $year): int
    {
        $stmt = $this->pdo()->prepare('SELECT COUNT(*) FROM member_fee_invoices WHERE tenant_id = ? AND year = ?');
        $stmt->execute([$tenantId->value(), $year]);
        return (int) $stmt->fetchColumn();
    }

    private function makeCommand(DateTimeImmutable $today): GenerateAnniversaryInvoicesCommand
    {
        $clock = new FrozenClock($today);
        return new GenerateAnniversaryInvoicesCommand(
            pdo:         $this->pdo(),
            useCase:     new GenerateAnniversaryInvoice(
                users:     new SqlUserRepository($this->conn),
                schedules: new SqlAnnualFeeScheduleRepository($this->pdo()),
                overrides: new InMemoryUserFeeOverrideRepository(),
                invoices:  new SqlMemberFeeInvoiceRepository($this->pdo()),
                settings:  new SqlTenantGovernanceSettingsRepository($this->pdo()),
                clock:     $clock,
            ),
            lockManager: new LockManager(sys_get_temp_dir()),
            logger:      new CronLogger(sys_get_temp_dir(), 'membership:generate-anniversary-invoices', $today),
            clock:       $clock,
        );
    }
}
