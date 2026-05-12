<?php
declare(strict_types=1);

namespace Daems\Infrastructure\Adapter\Api\Controller\Backstage\Governance;

use Daems\Domain\Tenant\TenantId;
use Daems\Infrastructure\Framework\Http\Request;
use Daems\Infrastructure\Framework\Http\Response;

final class EligibilityController
{
    /** @param callable(TenantId, \DateTimeImmutable):list<array{id:string, name:string, member_number:?string, membership_started_at:string, months_since_join:int}> $eligibleUsersLookup */
    public function __construct(private $eligibleUsersLookup) {}

    public function fullMembership(Request $req): Response
    {
        $actor = $req->requireActingUser();
        $lookup = $this->eligibleUsersLookup;
        $rows = $lookup($actor->activeTenant, new \DateTimeImmutable());
        return Response::json(['data' => $rows]);
    }
}
