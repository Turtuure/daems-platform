<?php
declare(strict_types=1);

namespace Daems\Tests\Integration\Cron;

use Daems\Application\Membership\Billing\Cron\MarkOverdueInvoicesCommand;
use Daems\Application\Membership\Billing\MarkOverdueInvoices\MarkOverdueInvoices;
use Daems\Domain\Membership\Billing\MemberFeeInvoice;
use Daems\Domain\Membership\Billing\MemberFeeInvoiceId;
use Daems\Domain\Membership\Billing\MemberFeeInvoiceStatus;
use Daems\Domain\Membership\MembershipType;
use Daems\Domain\Tenant\TenantId;
use Daems\Domain\User\UserId;
use Daems\Infrastructure\Adapter\Persistence\Sql\SqlFeeInvoiceAuditRepository;
use Daems\Infrastructure\Adapter\Persistence\Sql\SqlMemberFeeInvoiceRepository;
use Daems\Infrastructure\Adapter\Persistence\Sql\SqlTenantGovernanceSettingsRepository;
use Daems\Infrastructure\Console\CronLogger;
use Daems\Infrastructure\Console\LockManager;
use Daems\Tests\Integration\MigrationTestCase;
use Daems\Tests\Support\FrozenClock;
use DateTimeImmutable;

final class MarkOverdueInvoicesCommandTest extends MigrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->runMigrationsUpTo(95);
    }

    public function test_flags_past_grace_skips_within_grace(): void
    {
        $tenantId = $this->tenantIdBySlug('daems');
        $userA = $this->seedUser('flag@daems.fi');
        $userB = $this->seedUser('keep@daems.fi');
        $repo = new SqlMemberFeeInvoiceRepository($this->pdo());

        // userA: past grace (due 2026-01-01)
        $flagMe = $this->invoice($tenantId, $userA, 2025, new DateTimeImmutable('2026-01-01'));
        $repo->save($flagMe);
        // userB: within grace (due 2026-10-10, cutoff 2026-10-15 -30 = 09-15)
        $keepMe = $this->invoice($tenantId, $userB, 2026, new DateTimeImmutable('2026-10-10'));
        $repo->save($keepMe);

        $command = $this->makeCommand(new DateTimeImmutable('2026-10-15T03:00:00'));
        $exit = $command->execute(['tenant' => 'daems']);
        $this->assertSame(0, $exit);

        $this->assertSame(MemberFeeInvoiceStatus::Overdue, $repo->findById($flagMe->id())?->status());
        $this->assertSame(MemberFeeInvoiceStatus::Pending, $repo->findById($keepMe->id())?->status());
    }

    public function test_second_run_is_idempotent(): void
    {
        $tenantId = $this->tenantIdBySlug('daems');
        $user = $this->seedUser('idempotent@daems.fi');
        $repo = new SqlMemberFeeInvoiceRepository($this->pdo());

        $past = $this->invoice($tenantId, $user, 2025, new DateTimeImmutable('2026-01-01'));
        $repo->save($past);

        $command = $this->makeCommand(new DateTimeImmutable('2026-10-15T03:00:00'));
        $command->execute(['tenant' => 'daems']);
        $this->assertSame(MemberFeeInvoiceStatus::Overdue, $repo->findById($past->id())?->status());

        // Second run should not re-flag (findOverdueCandidates only returns Pending).
        $auditCountBefore = (int) $this->pdo()->query('SELECT COUNT(*) FROM member_fee_invoice_audit')->fetchColumn();
        $command->execute(['tenant' => 'daems']);
        $auditCountAfter = (int) $this->pdo()->query('SELECT COUNT(*) FROM member_fee_invoice_audit')->fetchColumn();
        $this->assertSame($auditCountBefore, $auditCountAfter);
    }

    private function tenantIdBySlug(string $slug): TenantId
    {
        $stmt = $this->pdo()->prepare('SELECT id FROM tenants WHERE slug = ?');
        $stmt->execute([$slug]);
        return TenantId::fromString((string) $stmt->fetchColumn());
    }

    private function seedUser(string $email): UserId
    {
        $id = '01958000-0000-7000-9000-' . substr(md5($email), 0, 12);
        $this->pdo()->prepare(
            'INSERT INTO users (id, name, email, password_hash, date_of_birth, created_at)
             VALUES (?, ?, ?, NULL, ?, ?)'
        )->execute([$id, 'User-' . substr($email, 0, 5), $email, '1990-01-01', '2026-01-01 00:00:00']);
        return UserId::fromString($id);
    }

    private function invoice(TenantId $tenantId, UserId $userId, int $year, DateTimeImmutable $due): MemberFeeInvoice
    {
        return new MemberFeeInvoice(
            id:                  MemberFeeInvoiceId::generate(),
            tenantId:            $tenantId,
            userId:              $userId,
            year:                $year,
            feeType:             MembershipType::Basic,
            anniversaryDate:     $due->modify('-60 days'),
            amountCents:         5000,
            originalAmountCents: null,
            currency:            'EUR',
            dueDate:             $due,
            status:              MemberFeeInvoiceStatus::Pending,
            payment:             null,
            waivedAt:            null,
            waivedBy:            null,
            waiveReason:         null,
            overrideId:          null,
            createdAt:           $due->modify('-60 days'),
        );
    }

    private function makeCommand(DateTimeImmutable $now): MarkOverdueInvoicesCommand
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
            logger:      new CronLogger(sys_get_temp_dir(), 'membership:mark-overdue-invoices', $now),
        );
    }
}
