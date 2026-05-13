<?php
declare(strict_types=1);

namespace Daems\Tests\Integration\Cron;

use Daems\Application\Membership\Billing\Cron\LapseInactiveMembersCommand;
use Daems\Application\Membership\Billing\LapseInactiveMember\LapseInactiveMember;
use Daems\Domain\Membership\Billing\MemberFeeInvoiceId;
use Daems\Domain\Shared\IdGeneratorInterface;
use Daems\Domain\Shared\ValueObject\Uuid7;
use Daems\Domain\Tenant\TenantId;
use Daems\Domain\User\UserId;
use Daems\Infrastructure\Adapter\Persistence\Sql\SqlMemberFeeInvoiceRepository;
use Daems\Infrastructure\Adapter\Persistence\Sql\SqlTenantGovernanceSettingsRepository;
use Daems\Infrastructure\Adapter\Persistence\Sql\SqlUserRepository;
use Daems\Infrastructure\Console\CronLogger;
use Daems\Infrastructure\Console\LockManager;
use Daems\Infrastructure\Framework\Database\Connection;
use Daems\Tests\Integration\MigrationTestCase;
use Daems\Tests\Support\FrozenClock;
use DateTimeImmutable;

final class LapseInactiveMembersCommandTest extends MigrationTestCase
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

    public function test_lapses_user_with_two_consecutive_overdue_years(): void
    {
        $tenantId = $this->tenantIdBySlug('daems');
        $userId = $this->seedUser('lapse@daems.fi', status: 'active');
        $this->seedOverdueInvoice($tenantId, $userId, 2025);
        $this->seedOverdueInvoice($tenantId, $userId, 2026);

        $command = $this->makeCommand(new DateTimeImmutable('2027-01-15T03:00:00'));
        $this->assertSame(0, $command->execute(['tenant' => 'daems']));

        $this->assertSame('lapsed', $this->statusOf($userId));

        $stmt = $this->pdo()->prepare(
            "SELECT COUNT(*) FROM member_status_audit
             WHERE user_id = ? AND new_status = 'lapsed' AND reason LIKE '%2v maksamatta%'"
        );
        $stmt->execute([$userId->value()]);
        $this->assertSame(1, (int) $stmt->fetchColumn());
    }

    public function test_dry_run_does_not_modify(): void
    {
        $tenantId = $this->tenantIdBySlug('daems');
        $userId = $this->seedUser('dry@daems.fi', status: 'active');
        $this->seedOverdueInvoice($tenantId, $userId, 2025);
        $this->seedOverdueInvoice($tenantId, $userId, 2026);

        $command = $this->makeCommand(new DateTimeImmutable('2027-01-15T03:00:00'));
        $this->assertSame(0, $command->execute(['tenant' => 'daems', 'dry-run' => true]));

        $this->assertSame('active', $this->statusOf($userId));
    }

    public function test_one_overdue_year_does_not_lapse(): void
    {
        $tenantId = $this->tenantIdBySlug('daems');
        $userId = $this->seedUser('one@daems.fi', status: 'active');
        $this->seedOverdueInvoice($tenantId, $userId, 2026);

        $command = $this->makeCommand(new DateTimeImmutable('2027-01-15T03:00:00'));
        $command->execute(['tenant' => 'daems']);

        $this->assertSame('active', $this->statusOf($userId));
    }

    public function test_lapse_check_disabled_skips_tenant(): void
    {
        $tenantId = $this->tenantIdBySlug('daems');
        // Seed/overwrite governance settings with lapse_check_enabled=0
        $this->pdo()->prepare(
            "INSERT INTO tenant_governance_settings
                (tenant_id, expulsion_hearing_days, decision_expiration_days,
                 requires_formal_decision_for_fees, default_due_days_from_anniversary,
                 overdue_grace_days, lapse_check_enabled)
             VALUES (?, 14, 60, 0, 60, 30, 0)
             ON DUPLICATE KEY UPDATE lapse_check_enabled = 0"
        )->execute([$tenantId->value()]);

        $userId = $this->seedUser('disabled@daems.fi', status: 'active');
        $this->seedOverdueInvoice($tenantId, $userId, 2025);
        $this->seedOverdueInvoice($tenantId, $userId, 2026);

        $command = $this->makeCommand(new DateTimeImmutable('2027-01-15T03:00:00'));
        $command->execute(['tenant' => 'daems']);

        $this->assertSame('active', $this->statusOf($userId));
    }

    private function tenantIdBySlug(string $slug): TenantId
    {
        $stmt = $this->pdo()->prepare('SELECT id FROM tenants WHERE slug = ?');
        $stmt->execute([$slug]);
        return TenantId::fromString((string) $stmt->fetchColumn());
    }

    private function seedUser(string $email, string $status): UserId
    {
        $id = '01958000-0000-7000-9000-' . substr(md5($email), 0, 12);
        $this->pdo()->prepare(
            'INSERT INTO users (id, name, email, password_hash, date_of_birth, is_platform_admin, membership_type, membership_status, membership_started_at)
             VALUES (?, ?, ?, NULL, ?, 0, ?, ?, ?)'
        )->execute([$id, 'U', $email, '1990-01-01', 'BASIC', $status, '2024-01-15 00:00:00']);
        return UserId::fromString($id);
    }

    private function seedOverdueInvoice(TenantId $tenantId, UserId $userId, int $year): void
    {
        $this->pdo()->prepare(
            "INSERT INTO member_fee_invoices
                (id, tenant_id, user_id, year, fee_type, anniversary_date, amount_cents, currency, due_date, status, created_at)
             VALUES (?, ?, ?, ?, 'BASIC', ?, 5000, 'EUR', ?, 'OVERDUE', NOW())"
        )->execute([
            MemberFeeInvoiceId::generate()->value(),
            $tenantId->value(),
            $userId->value(),
            $year,
            "{$year}-01-15",
            "{$year}-03-15",
        ]);
    }

    private function statusOf(UserId $userId): string
    {
        $stmt = $this->pdo()->prepare('SELECT membership_status FROM users WHERE id = ?');
        $stmt->execute([$userId->value()]);
        return (string) $stmt->fetchColumn();
    }

    private function makeCommand(DateTimeImmutable $now): LapseInactiveMembersCommand
    {
        $pdo = $this->pdo();
        $clock = new FrozenClock($now);
        $ids = new class implements IdGeneratorInterface {
            public function generate(): string
            {
                return Uuid7::generate()->value();
            }
        };

        // Members module's SqlMemberStatusAuditRepository lives in
        // /c:/laragon/www/modules/members/backend/src/Infrastructure — autoloaded
        // by the module registry's PSR-4 prefix DaemsModule\Members.
        /** @var \Daems\Domain\Membership\MemberStatusAuditRepositoryInterface $audit */
        $audit = new \DaemsModule\Members\Infrastructure\SqlMemberStatusAuditRepository($this->conn);

        $users = new SqlUserRepository($this->conn);
        $useCase = new LapseInactiveMember($users, $audit, $ids, $clock);

        return new LapseInactiveMembersCommand(
            pdo:         $pdo,
            invoices:    new SqlMemberFeeInvoiceRepository($pdo),
            settings:    new SqlTenantGovernanceSettingsRepository($pdo),
            useCase:     $useCase,
            lockManager: new LockManager(sys_get_temp_dir()),
            logger:      new CronLogger(sys_get_temp_dir(), 'membership:lapse-inactive-members', $now),
        );
    }
}
