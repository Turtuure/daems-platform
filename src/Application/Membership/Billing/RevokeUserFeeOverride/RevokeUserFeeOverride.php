<?php
declare(strict_types=1);

namespace Daems\Application\Membership\Billing\RevokeUserFeeOverride;

use Daems\Domain\Auth\ForbiddenException;
use Daems\Domain\Membership\Billing\UserFeeOverrideRepositoryInterface;
use Daems\Domain\Shared\Clock;

final class RevokeUserFeeOverride
{
    public function __construct(
        private readonly UserFeeOverrideRepositoryInterface $repo,
        private readonly Clock                              $clock,
    ) {}

    public function handle(RevokeUserFeeOverrideInput $in): void
    {
        $override = $this->repo->findById($in->overrideId);
        if ($override === null) {
            throw new \DomainException("Override not found: {$in->overrideId->value()}");
        }
        if (!$in->actor->isAdminIn($override->tenantId()) && !$in->actor->isPlatformAdmin) {
            throw new ForbiddenException('Only admins can revoke fee overrides');
        }
        $override->revoke($in->actor->id, $this->clock->now());
        $this->repo->save($override);
    }
}
