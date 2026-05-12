<?php
declare(strict_types=1);

namespace Daems\Infrastructure\Adapter\Api\Controller\Backstage\Governance;

use Daems\Application\Governance\BootstrapBoard;
use Daems\Application\Governance\BootstrapBoardInput;
use Daems\Domain\Auth\ForbiddenException;
use Daems\Domain\Governance\BoardMemberRepositoryInterface;
use Daems\Domain\Governance\BoardRepositoryInterface;
use Daems\Infrastructure\Framework\Http\Request;
use Daems\Infrastructure\Framework\Http\Response;

final class BoardController
{
    public function __construct(
        private readonly BoardRepositoryInterface $boards,
        private readonly BoardMemberRepositoryInterface $members,
        private readonly BootstrapBoard $bootstrap,
    ) {}

    public function index(Request $req): Response
    {
        $actor = $req->requireActingUser();
        $board = $this->boards->findForTenant($actor->activeTenant);
        if ($board === null) {
            return Response::json(['board' => null, 'members' => []]);
        }
        $list = $this->members->listForBoard($board->id);
        return Response::json([
            'board'   => [
                'id'                      => $board->id->value(),
                'bootstrapped_by_user_id' => $board->bootstrappedByUserId->value(),
                'bootstrapped_at'         => $board->bootstrappedAt->format(\DateTimeInterface::ATOM),
            ],
            'members' => array_map(static fn($m) => [
                'id'                => $m->id->value(),
                'user_id'           => $m->userId->value(),
                'role'              => $m->role->value,
                'term_started_at'   => $m->termStartedAt->format(\DateTimeInterface::ATOM),
                'term_ends_at'      => $m->termEndsAt->format(\DateTimeInterface::ATOM),
                'term_ended_at'     => $m->termEndedAt?->format(\DateTimeInterface::ATOM),
                'term_ended_reason' => $m->termEndedReason?->value,
            ], $list),
        ]);
    }

    public function bootstrap(Request $req): Response
    {
        $actor = $req->requireActingUser();
        if (!$actor->isPlatformAdmin()) {
            throw new ForbiddenException('gsa_required');
        }
        $body = $req->all();
        $rawMembersRaw = $body['members'] ?? null;
        $rawMembers = is_array($rawMembersRaw) ? $rawMembersRaw : [];

        // Normalize each row to the exact shape BootstrapBoardInput expects
        $members = [];
        foreach ($rawMembers as $row) {
            if (!is_array($row)) continue;
            $members[] = [
                'user_id'         => is_string($row['user_id']         ?? null) ? $row['user_id']         : '',
                'role'            => is_string($row['role']            ?? null) ? $row['role']            : 'member',
                'term_started_at' => is_string($row['term_started_at'] ?? null) ? $row['term_started_at'] : '',
                'term_ends_at'    => is_string($row['term_ends_at']    ?? null) ? $row['term_ends_at']    : '',
            ];
        }

        $boardId = $this->bootstrap->execute(new BootstrapBoardInput(
            tenantId:  $actor->activeTenant,
            gsaUserId: $actor->id,
            members:   $members,
            at:        new \DateTimeImmutable(),
        ));
        return Response::json(['board_id' => $boardId->value()], 201);
    }
}
