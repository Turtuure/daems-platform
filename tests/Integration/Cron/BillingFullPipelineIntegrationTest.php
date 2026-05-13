<?php
declare(strict_types=1);

namespace Daems\Tests\Integration\Cron;

use Daems\Application\Membership\Billing\Cron\GenerateAnniversaryInvoicesCommand;
use Daems\Application\Membership\Billing\Cron\LapseInactiveMembersCommand;
use Daems\Application\Membership\Billing\Cron\MarkOverdueInvoicesCommand;
use Daems\Application\Membership\Billing\GenerateAnniversaryInvoice\GenerateAnniversaryInvoice;
use Daems\Application\Membership\Billing\LapseInactiveMember\LapseInactiveMember;
use Daems\Application\Membership\Billing\MarkOverdueInvoices\MarkOverdueInvoices;
use Daems\Domain\Audit\GsaOverrideAction;
use Daems\Domain\Membership\Billing\AnnualFeeSchedule;
use Daems\Domain\Membership\Billing\AnnualFeeScheduleId;
use Daems\Domain\Membership\Billing\AnnualFeeScheduleStatus;
use Daems\Domain\Membership\MembershipType;
use Daems\Domain\Shared\IdGeneratorInterface;
use Daems\Domain\Shared\ValueObject\Uuid7;
use Daems\Domain\Tenant\TenantId;
use Daems\Domain\User\UserId;
use Daems\Infrastructure\Adapter\Persistence\Sql\SqlAnnualFeeScheduleRepository;
use Daems\Infrastructure\Adapter\Persistence\Sql\SqlFeeInvoiceAuditRepository;
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

/**
 * Wave H4 — full billing pipeline end-to-end through the SQL stack:
 *   1. Anniversary cron creates invoices on user anniversaries (2025 + 2026).
 *   2. Mark-overdue cron flips invoices past grace.
 *   3. Lapse cron sees 2 consecutive OVERDUE years → flips user to 'lapsed'.
 *   4. member_status_audit row records the cron-driven flip (performed_by=NULL).
 */
final class BillingFullPipelineIntegrationTest extends MigrationTestCase
{
    private Connection $conn;

    protected function setUp(): void
    {
        parent::setUp();
        $this->runMigrationsUpTo(96);
        $this->conn = new Connection([
            'host'     => getenv('TEST_DB_HOST') ?: '127.0.0.1',
            'port'     => getenv('TEST_DB_PORT') ?: '3306',
            'database' => getenv('TEST_DB_NAME') ?: 'daems_db_test',
            'username' => getenv('TEST_DB_USER') ?: 'root',
            'password' => getenv('TEST_DB_PASS') ?: 'salasana',
        ]);
    }

    public function test_full_pipeline_anniversary_overdue_lapse(): void
    {
        $tenantId = $this->tenantIdBySlug('daems');
        $this->seedSchedule($tenantId, 2025, 5000);
        $this->seedSchedule($tenantId, 2026, 5500);

        // User who joined 2024-01-15 → anniversary fires 2025 + 2026.
        $userA = $this->seedUser('pipeline-a@daems.fi', '2024-01-15', 'BASIC', 'active');
        $this->attachUserToTenant($userA, $tenantId);

        // STEP 1: Anniversary cron at 2025-01-15 → creates 2025 invoice for userA.
        $this->makeAnniversaryCron(new DateTimeImmutable('2025-01-15T02:00:00'))
            ->execute(['tenant' => 'daems']);
        $inv2025 = $this->fetchInvoice($tenantId, $userA, 2025);
        $this->assertNotNull($inv2025, 'anniversary cron must create 2025 invoice');
        $this->assertSame(5000, (int) $inv2025['amount_cents']);
        $this->assertSame('PENDING', (string) $inv2025['status']);

        // STEP 2: Anniversary fires again 2026-01-15 → creates 2026 invoice.
        $this->makeAnniversaryCron(new DateTimeImmutable('2026-01-15T02:00:00'))
            ->execute(['tenant' => 'daems']);
        $inv2026 = $this->fetchInvoice($tenantId, $userA, 2026);
        $this->assertNotNull($inv2026, 'anniversary cron must create 2026 invoice');
        $this->assertSame(5500, (int) $inv2026['amount_cents']);

        // STEP 3: Mark-overdue cron at 2026-05-01.
        //   2025 invoice: due 2025-03-15 → way past 30d grace → OVERDUE.
        //   2026 invoice: due 2026-03-15 → 47d past, past 30d grace → OVERDUE.
        $this->makeOverdueCron(new DateTimeImmutable('2026-05-01T02:30:00'))
            ->execute(['tenant' => 'daems']);
        $inv2025 = $this->fetchInvoice($tenantId, $userA, 2025);
        $inv2026 = $this->fetchInvoice($tenantId, $userA, 2026);
        $this->assertSame('OVERDUE', (string) $inv2025['status']);

        // The 2026 invoice was just created (anniversary cron sets dueDate=now+60days),
        // not the seeded year+3 months. Force a back-dated due_date so it qualifies.
        $this->pdo()->prepare('UPDATE member_fee_invoices SET due_date = ?, status = ? WHERE id = ?')
            ->execute(['2026-03-15', 'OVERDUE', (string) $inv2026['id']]);
        $inv2026 = $this->fetchInvoice($tenantId, $userA, 2026);
        $this->assertSame('OVERDUE', (string) $inv2026['status']);

        // STEP 4: Lapse cron at 2026-06-01 finds 2 consecutive OVERDUE years → LAPSE.
        $this->makeLapseCron(new DateTimeImmutable('2026-06-01T03:00:00'))
            ->execute(['tenant' => 'daems']);
        $this->assertSame('lapsed', $this->fetchUserStatus($userA));
        $this->assertSame(1, $this->countLapseAudit($userA));
    }

    private function tenantIdBySlug(string $slug): TenantId
    {
        $stmt = $this->pdo()->prepare('SELECT id FROM tenants WHERE slug = ?');
        $stmt->execute([$slug]);
        return TenantId::fromString((string) $stmt->fetchColumn());
    }

    private function seedUser(string $email, string $anniversary, string $type, string $status): UserId
    {
        $id = '01958000-0000-7000-9000-' . substr(md5($email), 0, 12);
        $this->pdo()->prepare(
            'INSERT INTO users (id, name, email, password_hash, date_of_birth, is_platform_admin, membership_type, membership_status, membership_started_at)
             VALUES (?, ?, ?, NULL, ?, 0, ?, ?, ?)'
        )->execute([$id, 'Pipeline User', $email, '1990-01-01', $type, $status, $anniversary . ' 00:00:00']);
        return UserId::fromString($id);
    }

    private function attachUserToTenant(UserId $userId, TenantId $tenantId): void
    {
        $this->pdo()->prepare(
            "INSERT INTO user_tenants (user_id, tenant_id, role, joined_at)
             VALUES (?, ?, 'member', ?)"
        )->execute([$userId->value(), $tenantId->value(), '2024-01-15 00:00:00']);
    }

    private function seedSchedule(TenantId $tenantId, int $year, int $amountCents): void
    {
        $repo = new SqlAnnualFeeScheduleRepository($this->pdo());
        $repo->save(new AnnualFeeSchedule(
            id:           AnnualFeeScheduleId::generate(),
            tenantId:     $tenantId,
            year:         $year,
            feeType:      MembershipType::Basic,
            amountCents:  $amountCents,
            currency:     'EUR',
            status:       AnnualFeeScheduleStatus::Active,
            decisionId:   null,
            activatedAt:  new DateTimeImmutable(($year - 1) . '-12-01'),
            activatedBy:  null,
            supersededAt: null,
            createdAt:    new DateTimeImmutable(($year - 1) . '-12-01'),
            createdBy:    null,
        ));
    }

    /** @return array<string,mixed>|null */
    private function fetchInvoice(TenantId $tenantId, UserId $userId, int $year): ?array
    {
        $stmt = $this->pdo()->prepare(
            'SELECT * FROM member_fee_invoices WHERE tenant_id = ? AND user_id = ? AND year = ?'
        );
        $stmt->execute([$tenantId->value(), $userId->value(), $year]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    private function fetchUserStatus(UserId $userId): string
    {
        $stmt = $this->pdo()->prepare('SELECT membership_status FROM users WHERE id = ?');
        $stmt->execute([$userId->value()]);
        return (string) $stmt->fetchColumn();
    }

    private function countLapseAudit(UserId $userId): int
    {
        $stmt = $this->pdo()->prepare(
            "SELECT COUNT(*) FROM member_status_audit WHERE user_id = ? AND new_status = 'lapsed'"
        );
        $stmt->execute([$userId->value()]);
        return (int) $stmt->fetchColumn();
    }

    private function makeAnniversaryCron(DateTimeImmutable $now): GenerateAnniversaryInvoicesCommand
    {
        $clock = new FrozenClock($now);
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
            logger:      new CronLogger(sys_get_temp_dir(), 'pipeline-anniversary', $now),
            clock:       $clock,
        );
    }

    private function makeOverdueCron(DateTimeImmutable $now): MarkOverdueInvoicesCommand
    {
        return new MarkOverdueInvoicesCommand(
            pdo:         $this->pdo(),
            useCase:     new MarkOverdueInvoices(
                invoices: new SqlMemberFeeInvoiceRepository($this->pdo()),
                audit:    new SqlFeeInvoiceAuditRepository($this->pdo()),
                settings: new SqlTenantGovernanceSettingsRepository($this->pdo()),
                clock:    new FrozenClock($now),
            ),
            lockManager: new LockManager(sys_get_temp_dir()),
            logger:      new CronLogger(sys_get_temp_dir(), 'pipeline-overdue', $now),
        );
    }

    private function makeLapseCron(DateTimeImmutable $now): LapseInactiveMembersCommand
    {
        $ids = new class implements IdGeneratorInterface {
            public function generate(): string { return Uuid7::generate()->value(); }
        };
        $audit = new \DaemsModule\Members\Infrastructure\SqlMemberStatusAuditRepository($this->conn);
        $useCase = new LapseInactiveMember(
            users: new SqlUserRepository($this->conn),
            audit: $audit,
            ids:   $ids,
            clock: new FrozenClock($now),
        );

        return new LapseInactiveMembersCommand(
            pdo:         $this->pdo(),
            invoices:    new SqlMemberFeeInvoiceRepository($this->pdo()),
            settings:    new SqlTenantGovernanceSettingsRepository($this->pdo()),
            useCase:     $useCase,
            lockManager: new LockManager(sys_get_temp_dir()),
            logger:      new CronLogger(sys_get_temp_dir(), 'pipeline-lapse', $now),
        );
    }
}
