<?php
declare(strict_types=1);

namespace Daems\Infrastructure\Adapter\Api\Controller\Backstage\Governance;

use Daems\Domain\Governance\BoardDelegationRepositoryInterface;
use Daems\Infrastructure\Framework\Http\Request;
use Daems\Infrastructure\Framework\Http\Response;

final class DelegationController
{
    public function __construct(
        private readonly BoardDelegationRepositoryInterface $delegations,
    ) {}

    public function index(Request $req): Response
    {
        $actor = $req->requireActingUser();
        $now = new \DateTimeImmutable();
        $rows = [];
        foreach ($this->delegations->listActive($actor->activeTenant, $now) as $d) {
            $rows[] = [
                'id'                  => $d->id->value(),
                'decision_type'       => $d->decisionType->value,
                'delegated_to_role'   => $d->delegatedToRole->value,
                'source_decision_id'  => $d->sourceDecisionId->value(),
                'valid_from'          => $d->validFrom->format(\DateTimeInterface::ATOM),
            ];
        }
        return Response::json(['data' => $rows]);
    }
}
