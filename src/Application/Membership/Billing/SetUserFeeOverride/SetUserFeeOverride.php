<?php
declare(strict_types=1);

namespace Daems\Application\Membership\Billing\SetUserFeeOverride;

use Daems\Domain\Auth\ForbiddenException;
use Daems\Domain\Membership\Billing\UserFeeOverride;
use Daems\Domain\Membership\Billing\UserFeeOverrideId;
use Daems\Domain\Membership\Billing\UserFeeOverrideRepositoryInterface;
use Daems\Domain\Membership\MembershipType;
use Daems\Domain\Shared\Clock;

final class SetUserFeeOverride
{
    public function __construct(
        private readonly UserFeeOverrideRepositoryInterface $repo,
        private readonly Clock                              $clock,
    ) {}

    public function handle(SetUserFeeOverrideInput $in): string
    {
        if (!$in->actor->isAdminIn($in->tenantId) && !$in->actor->isPlatformAdmin) {
            throw new ForbiddenException('Only admins can set fee overrides');
        }

        $override = new UserFeeOverride(
            id:                  UserFeeOverrideId::generate(),
            tenantId:            $in->tenantId,
            userId:              $in->userId,
            feeType:             MembershipType::from($in->feeType),
            overrideAmountCents: $in->overrideAmountCents,
            validFrom:           $in->validFrom,
            validUntil:          $in->validUntil,
            reason:              $in->reason,
            decisionId:          $in->decisionId,
            createdBy:           $in->actor->id,
            createdAt:           $this->clock->now(),
            revokedAt:           null,
            revokedBy:           null,
        );
        $this->repo->save($override);
        return $override->id()->value();
    }
}
