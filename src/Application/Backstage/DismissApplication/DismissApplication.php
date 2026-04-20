<?php

declare(strict_types=1);

namespace Daems\Application\Backstage\DismissApplication;

use Daems\Domain\Auth\ForbiddenException;
use Daems\Domain\Dismissal\AdminApplicationDismissal;
use Daems\Domain\Dismissal\AdminApplicationDismissalRepositoryInterface;
use Daems\Domain\Shared\Clock;
use Daems\Domain\Shared\IdGeneratorInterface;
use Daems\Domain\Shared\ValidationException;

final class DismissApplication
{
    public function __construct(
        private readonly AdminApplicationDismissalRepositoryInterface $repo,
        private readonly Clock $clock,
        private readonly IdGeneratorInterface $ids,
    ) {}

    public function execute(DismissApplicationInput $input): void
    {
        $tenantId = $input->acting->activeTenant;

        if (!$input->acting->isAdminIn($tenantId)) {
            throw new ForbiddenException('not_tenant_admin');
        }

        if (!in_array($input->appType, ['member', 'supporter'], true)) {
            throw new ValidationException(['app_type' => 'invalid_value']);
        }

        $this->repo->save(new AdminApplicationDismissal(
            $this->ids->generate(),
            $input->acting->id->value(),
            $input->appId,
            $input->appType,
            $this->clock->now(),
        ));
    }
}
