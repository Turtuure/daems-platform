<?php
declare(strict_types=1);

namespace Daems\Tests\Integration\Persistence;

use Daems\Domain\Governance\Board;
use Daems\Domain\Governance\BoardId;
use Daems\Domain\Governance\BoardMember;
use Daems\Domain\Governance\BoardMemberId;
use Daems\Domain\Governance\BoardMemberRole;
use Daems\Domain\Tenant\TenantId;
use Daems\Domain\User\UserId;
use Daems\Infrastructure\Adapter\Persistence\Sql\SqlBoardMemberRepository;
use Daems\Infrastructure\Adapter\Persistence\Sql\SqlBoardRepository;
use Daems\Tests\Integration\MigrationTestCase;

final class SqlBoardMemberRepositoryTest extends MigrationTestCase
{
    private string $boardId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->runMigrationsUpTo(88);

        $this->pdo->exec("INSERT INTO tenants (id, slug, name) VALUES ('01958000-0000-7000-8000-aaaaaaaaaaaa', 'tt', 'TT')");
        $this->pdo->exec("INSERT INTO users (id, name, email, date_of_birth, is_platform_admin)
                          VALUES ('01958000-0000-7000-8000-bbbbbbbbbbbb', 'gsa', 'gsa@test', '1990-01-01', 1)");
        $this->pdo->exec("INSERT INTO users (id, name, email, date_of_birth, is_platform_admin)
                          VALUES ('01958000-0000-7000-8000-bbbbbbbbbb01', 'u1', 'u1@test', '1990-01-01', 0)");

        $boards = new SqlBoardRepository($this->pdo);
        $this->boardId = '01958000-0000-7000-8000-cccccccccccc';
        $boards->save(new Board(
            id:                   BoardId::fromString($this->boardId),
            tenantId:             TenantId::fromString('01958000-0000-7000-8000-aaaaaaaaaaaa'),
            bootstrappedByUserId: UserId::fromString('01958000-0000-7000-8000-bbbbbbbbbbbb'),
            bootstrappedAt:       new \DateTimeImmutable('2026-05-12'),
            createdAt:            new \DateTimeImmutable('2026-05-12'),
        ));
    }

    public function test_save_find_list(): void
    {
        $repo = new SqlBoardMemberRepository($this->pdo);
        $member = new BoardMember(
            id:              BoardMemberId::fromString('01958000-0000-7000-8000-dddddddddddd'),
            boardId:         BoardId::fromString($this->boardId),
            userId:          UserId::fromString('01958000-0000-7000-8000-bbbbbbbbbb01'),
            role:            BoardMemberRole::Chair,
            termStartedAt:   new \DateTimeImmutable('2026-01-01'),
            termEndsAt:      new \DateTimeImmutable('2028-01-01'),
            termEndedAt:     null,
            termEndedReason: null,
        );
        $repo->save($member);

        $found = $repo->find(BoardMemberId::fromString('01958000-0000-7000-8000-dddddddddddd'));
        $this->assertNotNull($found);
        $this->assertSame('chair', $found->role->value);

        $list = $repo->listForBoard(BoardId::fromString($this->boardId));
        $this->assertCount(1, $list);

        $active = $repo->listActiveForBoard(BoardId::fromString($this->boardId), new \DateTimeImmutable('2026-06-01'));
        $this->assertCount(1, $active);

        $inactive = $repo->listActiveForBoard(BoardId::fromString($this->boardId), new \DateTimeImmutable('2030-06-01'));
        $this->assertCount(0, $inactive);
    }
}
