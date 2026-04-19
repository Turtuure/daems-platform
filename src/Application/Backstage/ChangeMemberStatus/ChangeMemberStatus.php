<?php

declare(strict_types=1);

namespace Daems\Application\Backstage\ChangeMemberStatus;

use Daems\Domain\Auth\ForbiddenException;
use Daems\Domain\Backstage\MemberDirectoryRepositoryInterface;
use Daems\Domain\Shared\Clock;
use Daems\Domain\Shared\ValidationException;
use Daems\Domain\User\UserId;

final class ChangeMemberStatus
{
    private const ALLOWED_STATUS = ['active', 'inactive', 'suspended', 'cancelled'];

    public function __construct(
        private readonly MemberDirectoryRepositoryInterface $directory,
        private readonly Clock $clock,
    ) {}

    public function execute(ChangeMemberStatusInput $input): ChangeMemberStatusOutput
    {
        if (!$input->acting->isPlatformAdmin) {
            throw new ForbiddenException('gsa_only');
        }

        if (!in_array($input->newStatus, self::ALLOWED_STATUS, true)) {
            throw new ValidationException(['status' => 'invalid_value']);
        }

        if (trim($input->reason) === '') {
            throw new ValidationException(['reason' => 'required']);
        }

        $this->directory->changeStatus(
            UserId::fromString($input->memberId),
            $input->acting->activeTenant,
            $input->newStatus,
            $input->reason,
            $input->acting->id,
            $this->clock->now(),
        );

        return new ChangeMemberStatusOutput(true);
    }
}
