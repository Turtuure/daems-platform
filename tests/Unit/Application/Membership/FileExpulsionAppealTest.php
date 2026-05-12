<?php
declare(strict_types=1);

namespace Daems\Tests\Unit\Application\Membership;

use Daems\Application\Membership\FileExpulsionAppeal;
use Daems\Domain\Governance\Exception\NotABoardMember;
use Daems\Domain\Membership\Exception\AppealAlreadyFiled;
use Daems\Domain\Membership\MemberExpulsion;
use Daems\Domain\Membership\MemberExpulsionId;
use Daems\Domain\Membership\MemberExpulsionStatus;
use Daems\Domain\Tenant\TenantId;
use Daems\Domain\User\UserId;
use Daems\Tests\Support\Fake\InMemoryMemberExpulsionRepository;
use PHPUnit\Framework\TestCase;

final class FileExpulsionAppealTest extends TestCase
{
    private function expelled(MemberExpulsionId $id, UserId $target, ?\DateTimeImmutable $appealFiledAt = null): MemberExpulsion
    {
        return new MemberExpulsion(
            id: $id, tenantId: TenantId::generate(),
            targetUserId: $target, proposedByUserId: UserId::generate(),
            reason: 'reason',
            hearingDeadlineAt: new \DateTimeImmutable('2026-05-26'),
            statementText: null, statementReceivedAt: null,
            decisionId: null, decidedAt: new \DateTimeImmutable('2026-05-28'),
            expelledAt: new \DateTimeImmutable('2026-05-28'),
            appealFiledAt: $appealFiledAt,
            appealText: $appealFiledAt !== null ? 'earlier appeal' : null,
            status: MemberExpulsionStatus::Expelled,
            createdAt: new \DateTimeImmutable('2026-05-12'),
        );
    }

    public function test_rejects_when_not_target_user(): void
    {
        $expulsions = new InMemoryMemberExpulsionRepository();
        $target = UserId::generate();
        $id = MemberExpulsionId::generate();
        $expulsions->save($this->expelled($id, $target));

        $uc = new FileExpulsionAppeal($expulsions);
        $this->expectException(NotABoardMember::class);
        $uc->execute($id, UserId::generate(), 'I want to appeal', new \DateTimeImmutable('2026-06-01'));
    }

    public function test_rejects_when_already_filed(): void
    {
        $expulsions = new InMemoryMemberExpulsionRepository();
        $target = UserId::generate();
        $id = MemberExpulsionId::generate();
        $expulsions->save($this->expelled($id, $target, appealFiledAt: new \DateTimeImmutable('2026-05-29')));

        $uc = new FileExpulsionAppeal($expulsions);
        $this->expectException(AppealAlreadyFiled::class);
        $uc->execute($id, $target, 'second appeal', new \DateTimeImmutable('2026-06-01'));
    }

    public function test_happy_path_stores_appeal(): void
    {
        $expulsions = new InMemoryMemberExpulsionRepository();
        $target = UserId::generate();
        $id = MemberExpulsionId::generate();
        $expulsions->save($this->expelled($id, $target));

        $uc = new FileExpulsionAppeal($expulsions);
        $uc->execute($id, $target, '  I want to appeal because…  ', new \DateTimeImmutable('2026-06-01 12:00:00'));

        $e = $expulsions->find($id);
        $this->assertNotNull($e);
        $this->assertSame(MemberExpulsionStatus::Appealed, $e->status);
        $this->assertSame('I want to appeal because…', $e->appealText);
        $this->assertSame('2026-06-01 12:00:00', $e->appealFiledAt?->format('Y-m-d H:i:s'));
    }
}
