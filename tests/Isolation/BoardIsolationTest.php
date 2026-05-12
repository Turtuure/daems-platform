<?php
declare(strict_types=1);

namespace Daems\Tests\Isolation;

use Daems\Domain\Governance\Board;
use Daems\Domain\Governance\BoardId;
use Daems\Domain\Governance\BoardMember;
use Daems\Domain\Governance\BoardMemberId;
use Daems\Domain\Governance\BoardMemberRole;
use Daems\Domain\User\UserId;
use Daems\Infrastructure\Adapter\Persistence\Sql\SqlBoardMemberRepository;
use Daems\Infrastructure\Adapter\Persistence\Sql\SqlBoardRepository;

final class BoardIsolationTest extends IsolationTestCase
{
    public function test_tenant_a_board_invisible_to_tenant_b_lookup(): void
    {
        $daems = $this->tenantId('daems');
        $sahe  = $this->tenantId('sahegroup');
        $gsa   = $this->seedUser('01958000-0000-7000-8000-eeeeeeeeeeee', 'gsa@test', true);

        $repo = new SqlBoardRepository($this->pdo);
        $repo->save(new Board(
            id:                   BoardId::fromString('01958000-0000-7000-8000-cccccccccc01'),
            tenantId:             $daems,
            bootstrappedByUserId: $gsa,
            bootstrappedAt:       new \DateTimeImmutable('2026-05-12'),
            createdAt:            new \DateTimeImmutable('2026-05-12'),
        ));

        $this->assertNotNull($repo->findForTenant($daems));
        $this->assertNull($repo->findForTenant($sahe));
    }

    public function test_board_members_listed_only_for_their_board(): void
    {
        $daems = $this->tenantId('daems');
        $sahe  = $this->tenantId('sahegroup');
        $gsa   = $this->seedUser('01958000-0000-7000-8000-eeeeeeeeeeee', 'gsa@test', true);
        $u1    = $this->seedUser('01958000-0000-7000-8000-ffffffffff01', 'u1@test');
        $u2    = $this->seedUser('01958000-0000-7000-8000-ffffffffff02', 'u2@test');

        $boards  = new SqlBoardRepository($this->pdo);
        $members = new SqlBoardMemberRepository($this->pdo);

        $boardA = BoardId::fromString('01958000-0000-7000-8000-ccccccccccaa');
        $boardB = BoardId::fromString('01958000-0000-7000-8000-ccccccccccbb');
        $boards->save(new Board($boardA, $daems, $gsa, new \DateTimeImmutable('2026-05-12'), new \DateTimeImmutable('2026-05-12')));
        $boards->save(new Board($boardB, $sahe,  $gsa, new \DateTimeImmutable('2026-05-12'), new \DateTimeImmutable('2026-05-12')));

        $members->save(new BoardMember(
            id:              BoardMemberId::generate(),
            boardId:         $boardA,
            userId:          $u1,
            role:            BoardMemberRole::Chair,
            termStartedAt:   new \DateTimeImmutable('2026-01-01'),
            termEndsAt:      new \DateTimeImmutable('2028-01-01'),
            termEndedAt:     null,
            termEndedReason: null,
        ));
        $members->save(new BoardMember(
            id:              BoardMemberId::generate(),
            boardId:         $boardB,
            userId:          $u2,
            role:            BoardMemberRole::Chair,
            termStartedAt:   new \DateTimeImmutable('2026-01-01'),
            termEndsAt:      new \DateTimeImmutable('2028-01-01'),
            termEndedAt:     null,
            termEndedReason: null,
        ));

        $this->assertCount(1, $members->listForBoard($boardA));
        $this->assertCount(1, $members->listForBoard($boardB));
        $this->assertSame($u1->value(), $members->listForBoard($boardA)[0]->userId->value());
    }
}
