<?php

declare(strict_types=1);

namespace Daems\Application\Backstage\DecideApplication;

use Daems\Domain\Auth\ForbiddenException;
use Daems\Domain\Membership\MemberApplicationRepositoryInterface;
use Daems\Domain\Membership\SupporterApplicationRepositoryInterface;
use Daems\Domain\Shared\Clock;
use Daems\Domain\Shared\NotFoundException;
use Daems\Domain\Shared\ValidationException;

final class DecideApplication
{
    public function __construct(
        private readonly MemberApplicationRepositoryInterface $memberApps,
        private readonly SupporterApplicationRepositoryInterface $supporterApps,
        private readonly Clock $clock,
    ) {}

    public function execute(DecideApplicationInput $input): DecideApplicationOutput
    {
        $tenantId = $input->acting->activeTenant;

        if (!$input->acting->isAdminIn($tenantId)) {
            throw new ForbiddenException('not_tenant_admin');
        }

        if (!in_array($input->decision, ['approved', 'rejected'], true)) {
            throw new ValidationException(['decision' => 'invalid_value']);
        }

        $now = $this->clock->now();

        if ($input->type === 'member') {
            $app = $this->memberApps->findByIdForTenant($input->id, $tenantId)
                ?? throw new NotFoundException('application_not_found');
            $this->memberApps->recordDecision($input->id, $tenantId, $input->decision, $input->acting->id, $input->note, $now);
            return new DecideApplicationOutput(true);
        }

        if ($input->type === 'supporter') {
            $app = $this->supporterApps->findByIdForTenant($input->id, $tenantId)
                ?? throw new NotFoundException('application_not_found');
            $this->supporterApps->recordDecision($input->id, $tenantId, $input->decision, $input->acting->id, $input->note, $now);
            return new DecideApplicationOutput(true);
        }

        throw new ValidationException(['type' => 'invalid_value']);
    }
}
