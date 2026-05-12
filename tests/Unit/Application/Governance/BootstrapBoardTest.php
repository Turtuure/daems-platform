<?php
declare(strict_types=1);

namespace Daems\Tests\Unit\Application\Governance;

use Daems\Application\Governance\BootstrapBoard;
use Daems\Application\Governance\BootstrapBoardInput;
use Daems\Domain\Governance\Board;
use Daems\Domain\Governance\BoardId;
use Daems\Domain\Governance\BoardMemberRole;
use Daems\Domain\Governance\Exception\BoardAlreadyBootstrapped;
use Daems\Domain\Governance\Exception\BoardCandidateNotFull;
use Daems\Domain\Governance\Exception\InvalidBootstrapRoster;
use Daems\Domain\Tenant\TenantId;
use Daems\Domain\User\UserId;
use Daems\Tests\Support\Fake\InMemoryBoardMemberRepository;
use Daems\Tests\Support\Fake\InMemoryBoardRepository;
use PHPUnit\Framework\TestCase;

final class BootstrapBoardTest extends TestCase
{
    /**
     * @param array<string, array{membership_type:string, membership_status:string}> $map
     * @return callable(UserId):array{membership_type:string, membership_status:string}
     */
    private function userMembershipLookup(array $map): callable
    {
        return static function (UserId $id) use ($map): array {
            return $map[$id->value()] ?? ['membership_type' => 'BASIC', 'membership_status' => 'active'];
        };
    }

    public function test_rejects_when_board_already_exists(): void
    {
        $boards  = new InMemoryBoardRepository();
        $members = new InMemoryBoardMemberRepository();
        $tenant  = TenantId::generate();
        $boards->save(new Board(
            id: BoardId::generate(),
            tenantId: $tenant,
            bootstrappedByUserId: UserId::generate(),
            bootstrappedAt: new \DateTimeImmutable('2026-04-01'),
            createdAt:      new \DateTimeImmutable('2026-04-01'),
        ));

        $uc = new BootstrapBoard($boards, $members, $this->userMembershipLookup([]));
        $this->expectException(BoardAlreadyBootstrapped::class);
        $uc->execute(new BootstrapBoardInput(
            tenantId: $tenant,
            gsaUserId: UserId::generate(),
            members: [['user_id' => UserId::generate()->value(), 'role' => 'chair', 'term_started_at' => '2026-05-12', 'term_ends_at' => '2028-05-12']],
            at: new \DateTimeImmutable('2026-05-12'),
        ));
    }

    public function test_rejects_size_outside_1_to_5(): void
    {
        $uc = new BootstrapBoard(new InMemoryBoardRepository(), new InMemoryBoardMemberRepository(), $this->userMembershipLookup([]));
        $this->expectException(InvalidBootstrapRoster::class);
        $uc->execute(new BootstrapBoardInput(
            tenantId: TenantId::generate(),
            gsaUserId: UserId::generate(),
            members: [],
            at: new \DateTimeImmutable('2026-05-12'),
        ));
    }

    public function test_rejects_when_not_exactly_one_chair(): void
    {
        $u1 = UserId::generate()->value();
        $u2 = UserId::generate()->value();
        $uc = new BootstrapBoard(
            new InMemoryBoardRepository(),
            new InMemoryBoardMemberRepository(),
            $this->userMembershipLookup([
                $u1 => ['membership_type' => 'FULL', 'membership_status' => 'active'],
                $u2 => ['membership_type' => 'FULL', 'membership_status' => 'active'],
            ]),
        );
        $this->expectException(InvalidBootstrapRoster::class);
        $uc->execute(new BootstrapBoardInput(
            tenantId: TenantId::generate(),
            gsaUserId: UserId::generate(),
            members: [
                ['user_id' => $u1, 'role' => 'chair', 'term_started_at' => '2026-05-12', 'term_ends_at' => '2028-05-12'],
                ['user_id' => $u2, 'role' => 'chair', 'term_started_at' => '2026-05-12', 'term_ends_at' => '2028-05-12'],
            ],
            at: new \DateTimeImmutable('2026-05-12'),
        ));
    }

    public function test_rejects_when_candidate_not_full(): void
    {
        $u1 = UserId::generate()->value();
        $uc = new BootstrapBoard(
            new InMemoryBoardRepository(),
            new InMemoryBoardMemberRepository(),
            $this->userMembershipLookup([
                $u1 => ['membership_type' => 'BASIC', 'membership_status' => 'active'],
            ]),
        );
        $this->expectException(BoardCandidateNotFull::class);
        $uc->execute(new BootstrapBoardInput(
            tenantId: TenantId::generate(),
            gsaUserId: UserId::generate(),
            members: [
                ['user_id' => $u1, 'role' => 'chair', 'term_started_at' => '2026-05-12', 'term_ends_at' => '2028-05-12'],
            ],
            at: new \DateTimeImmutable('2026-05-12'),
        ));
    }

    public function test_happy_path_creates_board_and_members(): void
    {
        $boards  = new InMemoryBoardRepository();
        $members = new InMemoryBoardMemberRepository();
        $u1 = UserId::generate()->value();
        $u2 = UserId::generate()->value();
        $u3 = UserId::generate()->value();
        $uc = new BootstrapBoard($boards, $members,
            $this->userMembershipLookup([
                $u1 => ['membership_type' => 'FULL', 'membership_status' => 'active'],
                $u2 => ['membership_type' => 'FULL', 'membership_status' => 'active'],
                $u3 => ['membership_type' => 'FULL', 'membership_status' => 'active'],
            ]));

        $tenant = TenantId::generate();
        $gsa    = UserId::generate();
        $uc->execute(new BootstrapBoardInput(
            tenantId: $tenant,
            gsaUserId: $gsa,
            members: [
                ['user_id' => $u1, 'role' => 'chair',  'term_started_at' => '2026-05-12', 'term_ends_at' => '2028-05-12'],
                ['user_id' => $u2, 'role' => 'member', 'term_started_at' => '2026-05-12', 'term_ends_at' => '2028-05-12'],
                ['user_id' => $u3, 'role' => 'member', 'term_started_at' => '2026-05-12', 'term_ends_at' => '2030-05-12'],
            ],
            at: new \DateTimeImmutable('2026-05-12'),
        ));

        $board = $boards->findForTenant($tenant);
        $this->assertNotNull($board);
        $list = $members->listForBoard($board->id);
        $this->assertCount(3, $list);
        $chair = array_values(array_filter($list, fn($m) => $m->role === BoardMemberRole::Chair));
        $this->assertCount(1, $chair);
    }
}
