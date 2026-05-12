<?php
declare(strict_types=1);

namespace Daems\Tests\Unit\Application\Membership;

use Daems\Application\Membership\SubmitExpulsionStatement;
use Daems\Domain\Governance\Exception\NotABoardMember;
use Daems\Domain\Membership\MemberExpulsion;
use Daems\Domain\Membership\MemberExpulsionId;
use Daems\Domain\Membership\MemberExpulsionStatus;
use Daems\Domain\Tenant\TenantId;
use Daems\Domain\User\UserId;
use Daems\Tests\Support\Fake\InMemoryMemberExpulsionRepository;
use PHPUnit\Framework\TestCase;

final class SubmitExpulsionStatementTest extends TestCase
{
    public function test_rejects_when_not_target_user(): void
    {
        $expulsions = new InMemoryMemberExpulsionRepository();
        $target = UserId::generate();
        $id = MemberExpulsionId::generate();
        $expulsions->save(new MemberExpulsion(
            id: $id,
            tenantId: TenantId::generate(),
            targetUserId: $target,
            proposedByUserId: UserId::generate(),
            reason: 'reason',
            hearingDeadlineAt: new \DateTimeImmutable('2026-06-01'),
            statementText: null, statementReceivedAt: null,
            decisionId: null, decidedAt: null, expelledAt: null,
            appealFiledAt: null, appealText: null,
            status: MemberExpulsionStatus::Hearing,
            createdAt: new \DateTimeImmutable('2026-05-12'),
        ));

        $uc = new SubmitExpulsionStatement($expulsions);
        $this->expectException(NotABoardMember::class);
        $uc->execute($id, UserId::generate(), 'My statement', new \DateTimeImmutable('2026-05-20'));
    }

    public function test_rejects_after_hearing_deadline(): void
    {
        $expulsions = new InMemoryMemberExpulsionRepository();
        $target = UserId::generate();
        $id = MemberExpulsionId::generate();
        $expulsions->save(new MemberExpulsion(
            id: $id,
            tenantId: TenantId::generate(),
            targetUserId: $target,
            proposedByUserId: UserId::generate(),
            reason: 'reason',
            hearingDeadlineAt: new \DateTimeImmutable('2026-05-15'),
            statementText: null, statementReceivedAt: null,
            decisionId: null, decidedAt: null, expelledAt: null,
            appealFiledAt: null, appealText: null,
            status: MemberExpulsionStatus::Hearing,
            createdAt: new \DateTimeImmutable('2026-05-01'),
        ));

        $uc = new SubmitExpulsionStatement($expulsions);
        $this->expectException(\DomainException::class);
        $uc->execute($id, $target, 'late statement', new \DateTimeImmutable('2026-05-20'));
    }

    public function test_happy_path_stores_statement(): void
    {
        $expulsions = new InMemoryMemberExpulsionRepository();
        $target = UserId::generate();
        $id = MemberExpulsionId::generate();
        $expulsions->save(new MemberExpulsion(
            id: $id,
            tenantId: TenantId::generate(),
            targetUserId: $target,
            proposedByUserId: UserId::generate(),
            reason: 'reason',
            hearingDeadlineAt: new \DateTimeImmutable('2026-06-01'),
            statementText: null, statementReceivedAt: null,
            decisionId: null, decidedAt: null, expelledAt: null,
            appealFiledAt: null, appealText: null,
            status: MemberExpulsionStatus::Hearing,
            createdAt: new \DateTimeImmutable('2026-05-12'),
        ));

        $uc = new SubmitExpulsionStatement($expulsions);
        $uc->execute($id, $target, '  I disagree because…  ', new \DateTimeImmutable('2026-05-20 10:00:00'));

        $e = $expulsions->find($id);
        $this->assertNotNull($e);
        $this->assertSame('I disagree because…', $e->statementText); // trimmed
        $this->assertSame('2026-05-20 10:00:00', $e->statementReceivedAt?->format('Y-m-d H:i:s'));
    }
}
