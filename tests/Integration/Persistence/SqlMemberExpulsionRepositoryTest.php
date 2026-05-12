<?php
declare(strict_types=1);

namespace Daems\Tests\Integration\Persistence;

use Daems\Domain\Membership\MemberExpulsion;
use Daems\Domain\Membership\MemberExpulsionId;
use Daems\Domain\Membership\MemberExpulsionStatus;
use Daems\Domain\Tenant\TenantId;
use Daems\Domain\User\UserId;
use Daems\Infrastructure\Adapter\Persistence\Sql\SqlMemberExpulsionRepository;
use Daems\Tests\Integration\MigrationTestCase;

final class SqlMemberExpulsionRepositoryTest extends MigrationTestCase
{
    private string $tenantId = '01958000-0000-7000-8000-aaaaaaaaaaaa';
    private string $proposer = '01958000-0000-7000-8000-bbbbbbbbbb01';
    private string $target   = '01958000-0000-7000-8000-bbbbbbbbbb02';

    protected function setUp(): void
    {
        parent::setUp();
        $this->runMigrationsUpTo(88);
        $this->pdo->exec("INSERT INTO tenants (id, slug, name) VALUES ('{$this->tenantId}', 'tt', 'TT')");
        $this->pdo->exec("INSERT INTO users (id, name, email, date_of_birth, is_platform_admin) VALUES
            ('{$this->proposer}', 'p', 'p@test', '1990-01-01', 0),
            ('{$this->target}',   't', 't@test', '1990-01-01', 0)");
    }

    public function test_save_find_list_filter_by_status(): void
    {
        $repo = new SqlMemberExpulsionRepository($this->pdo);
        $id = MemberExpulsionId::fromString('01958000-0000-7000-8000-ddddddddddd1');
        $repo->save(new MemberExpulsion(
            id: $id,
            tenantId: TenantId::fromString($this->tenantId),
            targetUserId: UserId::fromString($this->target),
            proposedByUserId: UserId::fromString($this->proposer),
            reason: 'because',
            hearingDeadlineAt: new \DateTimeImmutable('2026-06-01'),
            statementText: null,
            statementReceivedAt: null,
            decisionId: null,
            decidedAt: null,
            expelledAt: null,
            appealFiledAt: null,
            appealText: null,
            status: MemberExpulsionStatus::Hearing,
            createdAt: new \DateTimeImmutable('2026-05-12'),
        ));

        $found = $repo->find($id);
        $this->assertNotNull($found);
        $this->assertSame('because', $found->reason);

        $this->assertCount(1, $repo->listForTenant(TenantId::fromString($this->tenantId)));
        $this->assertCount(1, $repo->listForTenant(TenantId::fromString($this->tenantId), MemberExpulsionStatus::Hearing));
        $this->assertCount(0, $repo->listForTenant(TenantId::fromString($this->tenantId), MemberExpulsionStatus::Expelled));
    }
}
