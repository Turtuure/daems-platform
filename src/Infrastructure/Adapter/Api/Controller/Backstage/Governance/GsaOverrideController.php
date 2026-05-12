<?php
declare(strict_types=1);

namespace Daems\Infrastructure\Adapter\Api\Controller\Backstage\Governance;

use Daems\Application\Audit\GsaForceApproveBasic;
use Daems\Application\Audit\GsaForceApproveBasicInput;
use Daems\Domain\Auth\ForbiddenException;
use Daems\Infrastructure\Framework\Http\Request;
use Daems\Infrastructure\Framework\Http\Response;

final class GsaOverrideController
{
    public function __construct(
        private readonly GsaForceApproveBasic $forceApproveBasic,
    ) {}

    public function forceApproveBasic(Request $req): Response
    {
        $actor = $req->requireActingUser();
        if (!$actor->isPlatformAdmin()) {
            throw new ForbiddenException('gsa_required');
        }
        $body = $req->all();
        $appId  = is_string($body['application_id'] ?? null) ? $body['application_id'] : throw new \DomainException('application_id required');
        $reason = is_string($body['reason'] ?? null) ? $body['reason'] : '';

        $id = $this->forceApproveBasic->execute(new GsaForceApproveBasicInput(
            tenantId: $actor->activeTenant,
            gsaUserId: $actor->id,
            applicationId: $appId,
            reason: $reason,
            at: new \DateTimeImmutable(),
        ));
        return Response::json(['override_id' => $id->value()], 201);
    }
}
