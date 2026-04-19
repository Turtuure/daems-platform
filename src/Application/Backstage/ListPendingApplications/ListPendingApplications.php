<?php

declare(strict_types=1);

namespace Daems\Application\Backstage\ListPendingApplications;

use Daems\Domain\Auth\ForbiddenException;
use Daems\Domain\Backstage\PendingApplication;
use Daems\Domain\Membership\MemberApplication;
use Daems\Domain\Membership\MemberApplicationRepositoryInterface;
use Daems\Domain\Membership\SupporterApplication;
use Daems\Domain\Membership\SupporterApplicationRepositoryInterface;

final class ListPendingApplications
{
    public function __construct(
        private readonly MemberApplicationRepositoryInterface $memberApps,
        private readonly SupporterApplicationRepositoryInterface $supporterApps,
    ) {}

    public function execute(ListPendingApplicationsInput $input): ListPendingApplicationsOutput
    {
        $tenantId = $input->acting->activeTenant;

        if (!$input->acting->isAdminIn($tenantId)) {
            throw new ForbiddenException('not_tenant_admin');
        }

        $memberApps    = $this->memberApps->listPendingForTenant($tenantId, $input->limit);
        $supporterApps = $this->supporterApps->listPendingForTenant($tenantId, $input->limit);

        $memberOut = array_map(static fn (MemberApplication $a): PendingApplication => new PendingApplication(
            id:          $a->id()->value(),
            type:        'member',
            displayName: $a->name(),
            email:       $a->email(),
            submittedAt: '',
            motivation:  $a->motivation(),
        ), $memberApps);

        $supporterOut = array_map(static fn (SupporterApplication $a): PendingApplication => new PendingApplication(
            id:          $a->id()->value(),
            type:        'supporter',
            displayName: $a->orgName(),
            email:       $a->email(),
            submittedAt: '',
            motivation:  $a->motivation(),
        ), $supporterApps);

        return new ListPendingApplicationsOutput(member: $memberOut, supporter: $supporterOut);
    }
}
